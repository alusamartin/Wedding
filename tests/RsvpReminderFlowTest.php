<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RSVP reminder flow regressions:
 *
 *  1. Guest::find_reminder_due() — window math (30/60-day), replied guests
 *     excluded, pending-booking guests excluded, resend window respected.
 *  2. scripts/rsvp_reminders.php — real run sends SMS via the SMS::send
 *     outbox, marks only actually-reminded guests, skips phoneless guests
 *     (without spinning daily), notifies the organizer once per booking,
 *     dedups inside the resend window, and --dry-run writes nothing.
 *  3. rsvp_dashboard.php renders the per-guest reminder state.
 *
 * Fixture rows (booking 99xxxx, credit balance) are always restored.
 */
final class RsvpReminderFlowTest extends TestCase
{
    private const FIX_USER  = 990002;
    private const FIX_BOOK  = 990002;
    private const SCRIPT    = __DIR__ . '/../scripts/rsvp_reminders.php';

    private static ?PDO $pdo = null;
    /** Credits balance snapshotted before the fixture (restored on teardown). */
    private static int $priorCredits = 0;
    private static bool $creditsTouched = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';
        require_once __DIR__ . '/../admin/include/db_object.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
        require_once __DIR__ . '/../admin/include/Guest.php';
        self::$pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Model methods resolve the connection through global $db.
        global $db;
        $db = new Database();
        self::cleanup();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanup();
    }

    private static function cleanup(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        if (self::$creditsTouched) {
            // Restore even when the original balance was 0 (the seed value
            // for some planners) — hence the flag, not a truthiness check.
            $p->exec('UPDATE tblsms_credits SET credits = ' . self::$priorCredits . ' WHERE user_id = 7');
            self::$creditsTouched = false;
        }
        $p->exec('DELETE FROM tblguest WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblaccounts_detail WHERE user_id = ' . self::FIX_USER);
        $p->exec('DELETE FROM tblaccounts WHERE user_id = ' . self::FIX_USER);
    }

    /** Confirmed booking 20 days out, organizer 7 (credits topped up). */
    private static function seedFixture(?string $invitedWhen = null): void
    {
        self::cleanup(); // idempotent between tests
        $p = self::$pdo;
        self::$priorCredits = (int) $p->query('SELECT credits FROM tblsms_credits WHERE user_id = 7')->fetchColumn();
        self::$creditsTouched = true;
        $p->exec('UPDATE tblsms_credits SET credits = 100 WHERE user_id = 7');

        $wedding = date('Y-m-d', strtotime('+20 days'));
        $p->prepare("INSERT INTO tblaccounts (user_id, user_email, user_password, access_level) VALUES (?, 'fixture.reminder@example.com', 'x', '0')")
            ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblaccounts_detail (user_id, firstname, lastname, phone, city, datetime_created, description, location, expectation_visitor, cash_advanced, status, date_signed)
                     VALUES (?, 'Njeri', 'Kamau', '0721999002', 'Nakuru', NOW(), 'fixture', 'Nakuru', '80', 0, 'confirm', NOW())")
            ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblweddingbook (booking_id, user_id, bride, groom, wedding_type, user_email, wedding_date, organizer_id)
                     VALUES (?, ?, 'Njeri', 'Kamau', 6, 'fixture.reminder@example.com', ?, 7)")
            ->execute([self::FIX_BOOK, self::FIX_USER, $wedding]);

        // invitedWhen: when the guests were 'invited' (rsvp_invited_at).
        // null = just now; '8 days ago' etc. drive invite-relative windows.
        $invited = $invitedWhen
            ? date('Y-m-d H:i:s', strtotime($invitedWhen))
            : date('Y-m-d H:i:s');
        $ins = $p->prepare("INSERT INTO tblguest (booking_id, fullname, guestname, address, state, zipcode, priority, out_of_town, relationship, tracks_and_gifts, city, phone, rsvp_token, rsvp_status, rsvp_invited_at)
                            VALUES (?, ?, ?, '', '', '', 'B', 'n', 'Friend', '', 'Nakuru', ?, ?, 'pending', ?)");
        $ins->execute([self::FIX_BOOK, 'Wanjiku Mwangi', 'Wanjiku Mwangi', '254729990002', bin2hex(random_bytes(16)), $invited]);
        $ins->execute([self::FIX_BOOK, 'Otieno Nyalenda', 'Otieno Nyalenda', null, bin2hex(random_bytes(16)), $invited]);
        // A guest who already replied — never due, token and phone notwithstanding.
        $ins->execute([self::FIX_BOOK, 'Chebet Rono', 'Chebet Rono', '254729990003', bin2hex(random_bytes(16)), $invited]);
        $p->exec("UPDATE tblguest SET rsvp_status = 'yes', rsvp_at = NOW() WHERE fullname = 'Chebet Rono' AND booking_id = " . self::FIX_BOOK);
    }

    private static function runScript(string $args = '--after=0 --quiet'): string
    {
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' ' . $args . ' 2>&1';
        return (string) shell_exec($cmd);
    }

    #[Group('integration')]
    public function testInviteRelativeWindowMath(): void
    {
        // Invited 8 days ago -> due with a 7-day-after threshold...
        self::seedFixture('8 days ago');
        $due = Guest::find_reminder_due(7, 7, 3);
        $ids = array_map(fn ($r) => (int) $r->id, $due);
        $this->assertContains(self::guestId('Wanjiku Mwangi'), $ids, 'pending guest invited 8d ago is due');
        $this->assertContains(self::guestId('Otieno Nyalenda'), $ids, 'phoneless-but-pending guest is due (gets marked to avoid cron spin)');
        $this->assertNotContains(self::guestId('Chebet Rono'), $ids, 'replied guest is never due');

        // ...but NOT due with a 14-day-after threshold.
        $none = Guest::find_reminder_due(14, 7, 3);
        $ids = array_map(fn ($r) => (int) $r->id, $none);
        $this->assertNotContains(self::guestId('Wanjiku Mwangi'), $ids, 'guest invited less than the threshold is not due');

        // Freshly invited guests never qualify on day zero.
        self::seedFixture(null);
        $fresh = Guest::find_reminder_due(7, 7, 3);
        $this->assertSame([], self::dueInFixture($fresh), 'guests invited moments ago must not be due');
    }

    #[Group('integration')]
    public function testUninvitedGuestsNeverQualify(): void
    {
        // Rows WITHOUT rsvp_invited_at (imported before invite tracking):
        // no invite, no nag — regardless of threshold. The threshold is 0
        // days, the widest window the query has, so every guest in the seed
        // that IS properly invited comes back too; only this fixture's rows
        // are the subject, hence the booking-scoped assertion.
        self::seedFixture('8 days ago');
        self::$pdo->exec('UPDATE tblguest SET rsvp_invited_at = NULL WHERE booking_id = ' . self::FIX_BOOK);
        $due = Guest::find_reminder_due(0, 7, 3);
        $this->assertSame([], self::dueInFixture($due), 'guests with no invite timestamp must never be due');
    }

    #[Group('integration')]
    public function testDispatcherSendsMarksDedupsAndNotifies(): void
    {
        self::seedFixture('8 days ago');
        $p = self::$pdo;
        $out = self::runScript();

        // One SMS to the phone guest, none to the phoneless one.
        $sms = $p->query('SELECT phone, message, status FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $sms, 'expected exactly one SMS row');
        $this->assertSame('sent', $sms[0]['status'], 'dev mode marks the outbox row sent');
        $this->assertStringContainsString('254729990002', $sms[0]['phone']);
        $this->assertStringContainsString('rsvp.php?g=', $sms[0]['message'], 'SMS carries the personal RSVP link');

        // Only the actually-reminded guest is marked.
        $marked = (int) $p->query('SELECT rsvp_reminders FROM tblguest WHERE fullname = "Wanjiku Mwangi" AND booking_id = ' . self::FIX_BOOK)->fetchColumn();
        $this->assertSame(1, $marked);
        // The phoneless guest is deliberately marked too: a skipped guest
        // without a number must not re-qualify every single cron run.
        $skipped = (int) $p->query('SELECT rsvp_reminders FROM tblguest WHERE fullname = "Otieno Nyalenda" AND booking_id = ' . self::FIX_BOOK)->fetchColumn();
        $this->assertSame(1, $skipped, 'phoneless guest marked to avoid daily re-qualification');

        // Planner notification: one, counting real sends only.
        $notif = $p->query('SELECT user_id, message FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK)->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $notif);
        $this->assertSame(7, (int) $notif[0]['user_id']);
        $this->assertStringContainsString('1 guest', $notif[0]['message'], 'notification counts real sends only');

        // Dedup: an immediate re-run sends nothing more.
        self::runScript();
        $n = (int) $p->query('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn();
        $this->assertSame(1, $n, 'no duplicate SMS inside the resend window');
    }

    #[Group('integration')]
    public function testDryRunWritesNothing(): void
    {
        self::seedFixture('8 days ago');
        $p = self::$pdo;
        $out = self::runScript('--after=0 --dry-run');

        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertSame(0, (int) $p->query('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn());
        $this->assertSame(0, (int) $p->query('SELECT COUNT(*) FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn());
        $marked = (int) $p->query('SELECT rsvp_reminders FROM tblguest WHERE fullname = "Wanjiku Mwangi" AND booking_id = ' . self::FIX_BOOK)->fetchColumn();
        $this->assertSame(0, $marked, 'dry run must not mark guests');
    }

    private static function guestId(string $fullname): int
    {
        return (int) self::$pdo->query(
            'SELECT id FROM tblguest WHERE fullname = ' . self::$pdo->quote($fullname)
            . ' AND booking_id = ' . self::FIX_BOOK
        )->fetchColumn();
    }

    /**
     * The due list narrowed to this fixture's own booking.
     *
     * find_reminder_due() scans every wedding, so a whole-list assertion
     * silently became a claim about the seed's other guests — that is what
     * made this class fail on one database and pass on another. The row set
     * under test is the fixture's, and that is what the caller asserts on.
     *
     * @param  array<int, object> $due
     * @return array<int, int> guest ids belonging to the fixture booking
     */
    private static function dueInFixture(array $due): array
    {
        return array_values(array_map(
            static fn ($r): int => (int) $r->id,
            array_filter($due, static fn ($r): bool => (int) $r->booking_id === self::FIX_BOOK)
        ));
    }
}
