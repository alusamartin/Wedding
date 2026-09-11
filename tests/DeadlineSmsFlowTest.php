<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Payment deadline-band SMS flow regressions (scripts/deadline_sms.php):
 *
 *  1. A confirmed booking with an open balance crossing a band (30 days
 *     out) gets exactly ONE text carrying the couple's names and balance;
 *     the marker (tblweddingbook.pay_band_sms_sent) stamps the band; the
 *     couple gets a bell notification; the planner gets one too.
 *  2. Dedup: a second run at the same band sends nothing.
 *  3. Band transition: crossing into a tighter band (7 days) fires again.
 *  4. --dry-run writes nothing.
 *  5. Fully-paid bookings are never texted and the marker stays put.
 *
 * Fixture rows (booking 99xxxx) are always restored.
 */
final class DeadlineSmsFlowTest extends TestCase
{
    private const FIX_USER  = 990003;
    private const FIX_BOOK  = 990003;
    private const PROBE_CODE = 'QDLDSMS01';
    private const SCRIPT    = __DIR__ . '/../scripts/deadline_sms.php';

    private static ?PDO $pdo = null;
    /** @var array<string, mixed> snapshotted fixture state (restored on teardown) */
    private static array $snapshot = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';
        require_once __DIR__ . '/../admin/include/db_object.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
        require_once __DIR__ . '/../admin/include/Liquidation.php';
        require_once __DIR__ . '/../admin/include/Monetization.php';
        if (!class_exists('Notification', false)) {
            require_once __DIR__ . '/../admin/include/Notification.php';
        }
        if (!class_exists('SMS', false) && class_exists('Monetization')) {
            // SMS lives beside Monetization in the same file.
        }
        self::$pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
        if (isset(self::$snapshot['credits'])) {
            $p->exec('UPDATE tblsms_credits SET credits = ' . (int) self::$snapshot['credits'] . ' WHERE user_id = 7');
            self::$snapshot = [];
        }
        $p->exec('DELETE FROM tbl_liquidation WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tbl_mail_logs WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblaccounts_detail WHERE user_id = ' . self::FIX_USER);
        $p->exec('DELETE FROM tblaccounts WHERE user_id = ' . self::FIX_USER);
    }

    /**
     * Confirmed booking N days out with a priced package, a KSh 10,000 probe
     * deposit (open balance), organizer 7 with topped-up credits.
     */
    private static function seedFixture(int $daysOut, bool $fullyPaid = false): array
    {
        self::cleanup();
        $p = self::$pdo;

        self::$snapshot['credits'] = (int) $p->query('SELECT credits FROM tblsms_credits WHERE user_id = 7')->fetchColumn();
        $p->exec('UPDATE tblsms_credits SET credits = 100 WHERE user_id = 7');

        $cat = $p->query('SELECT id, price FROM tblweddingcategories WHERE price > 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertNotEmpty($cat, 'seed must contain a priced package');

        $wedding = date('Y-m-d', strtotime('+' . $daysOut . ' days'));
        $p->prepare("INSERT INTO tblaccounts (user_id, user_email, user_password, access_level) VALUES (?, 'fixture.deadline@example.com', 'x', '0')")
            ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblaccounts_detail (user_id, firstname, lastname, phone, city, datetime_created, description, location, expectation_visitor, cash_advanced, status, date_signed)
                     VALUES (?, 'Njeri', 'Kamau', '0721999003', 'Nakuru', NOW(), 'fixture', 'Nakuru', '80', 0, 'confirm', NOW())")
            ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblweddingbook (booking_id, user_id, bride, groom, wedding_type, user_email, wedding_date, organizer_id)
                     VALUES (?, ?, 'Njeri', 'Kamau', ?, 'fixture.deadline@example.com', ?, 7)")
            ->execute([self::FIX_BOOK, self::FIX_USER, (int) $cat['id'], $wedding]);

        $deposit = $fullyPaid ? (float) $cat['price'] : 10000.00;
        $p->prepare("INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified)
                     VALUES (?, ?, 0, ?, 0, 0, ?, NOW(), NOW())")
            ->execute([self::FIX_BOOK, self::FIX_USER, $deposit, self::PROBE_CODE]);

        return ['price' => (float) $cat['price'], 'wedding' => $wedding];
    }

    private static function runScript(string $args = ''): string
    {
        // Unquiet by default: --quiet suppresses the per-send lines AND the
        // summary, leaving nothing to assert on.
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' ' . $args . ' 2>&1';
        return (string) shell_exec($cmd);
    }

    #[Group('integration')]
    public function testCrossingABandTextsTheCoupleOnce(): void
    {
        $fx  = self::seedFixture(10); // inside the 30-day band, outside the 7-day one
        $bal = $fx['price'] - 10000.00;
        $balHuman = 'KSh ' . number_format($bal);

        $out = self::runScript();
        self::assertStringContainsString('texted booking ' . self::FIX_BOOK, $out, 'the couple must be texted at their true band');
        self::assertStringContainsString('band 30d', $out, '10 days out lands in the 30-day band (smallest crossed)');

        // Marker stamped with the band — the dedup guarantee.
        self::assertSame(
            30,
            (int) self::$pdo->query('SELECT pay_band_sms_sent FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn(),
            'the marker must record the smallest band texted'
        );

        // Exactly one SMS carrying names + balance, dev gateway logs it sent.
        $sms = self::$pdo->query('SELECT * FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $sms, 'exactly one text per band');
        self::assertSame('sent', (string) $sms[0]['status'], 'dev gateway logs the send as sent');
        self::assertStringContainsString('Habari Njeri & Kamau', (string) $sms[0]['message']);
        self::assertStringContainsString($balHuman, (string) $sms[0]['message'], 'the text must carry the balance');

        // Couple bell + email leg.
        $coupleNotif = self::$pdo->query(
            'SELECT * FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK . ' AND user_id = ' . self::FIX_USER
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($coupleNotif, 'the couple must get a bell notification');
        self::assertStringContainsString($balHuman, (string) $coupleNotif['message']);

        // Planner loop closed: one notification to the organizer.
        $plannerNotif = self::$pdo->query(
            "SELECT * FROM tblnotifications WHERE booking_id = " . self::FIX_BOOK . " AND user_id = 7"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($plannerNotif, 'the assigned planner must be notified');
        self::assertStringContainsString('Deadline text sent', (string) $plannerNotif['message']);
    }

    #[Group('integration')]
    public function testSecondRunAtTheSameBandSendsNothing(): void
    {
        self::seedFixture(10);
        self::runScript();

        $out = self::runScript();
        self::assertStringNotContainsString('texted booking ' . self::FIX_BOOK, $out, 'the band must never text twice');
        self::assertStringContainsString('sent: 0', $out, 'the summary must report zero sends');
        self::assertSame(
            1,
            (int) self::$pdo->query('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn(),
            'still exactly one SMS on disk'
        );
    }

    #[Group('integration')]
    public function testCrossingIntoATighterBandFiresAgain(): void
    {
        self::seedFixture(10);
        self::runScript(); // band 30 stamped

        // Wedding moves into the final week.
        self::$pdo->exec("UPDATE tblweddingbook SET wedding_date = '" . date('Y-m-d', strtotime('+5 days')) . "' WHERE booking_id = " . self::FIX_BOOK);
        $out = self::runScript();

        self::assertStringContainsString('texted booking ' . self::FIX_BOOK, $out, 'the tighter band must fire');
        self::assertStringContainsString('band 7d', $out);
        self::assertSame(
            7,
            (int) self::$pdo->query('SELECT pay_band_sms_sent FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn()
        );
        $texts = self::$pdo->query('SELECT message FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $texts, 'one text per band, two bands crossed');
        self::assertStringContainsString('kindly settle', (string) $texts[1], 'the final-week copy must carry the settle-now ask');
    }

    #[Group('integration')]
    public function testDryRunWritesNothing(): void
    {
        self::seedFixture(10);

        $out = self::runScript('--dry-run');
        self::assertStringContainsString('[dry-run] booking ' . self::FIX_BOOK, $out, 'the dry run must preview the send');
        self::assertStringContainsString('DRY RUN', $out);

        self::assertNull(
            self::$pdo->query('SELECT pay_band_sms_sent FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn(),
            'dry run must not stamp the marker'
        );
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn());
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn());
    }

    #[Group('integration')]
    public function testFullyPaidBookingsAreNeverTexted(): void
    {
        self::seedFixture(10, true); // probe deposit covers the whole package

        $out = self::runScript();
        self::assertStringNotContainsString('texted booking ' . self::FIX_BOOK, $out, 'a paid-up couple must not be texted');
        self::assertStringContainsString('paid-up: 1', $out, 'the skip must be reported as paid-up');
        self::assertNull(
            self::$pdo->query('SELECT pay_band_sms_sent FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn(),
            'the marker must stay put so a later reversal still gets future bands'
        );
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn());
    }
}
