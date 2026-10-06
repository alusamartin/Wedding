<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Trial nurture emails — cron LEG 3 (scripts/subscription_renewals.php).
 *
 * Planners on a live trial get a check-in email at the halfway point of the
 * trial and a last-call email the day before expiry (bands 7 and 1 for the
 * default 14-day trial), through the shared tbl_mail_logs outbox. One email
 * per band ever (marker-first trial_nurture_band), converted planners never
 * appear, lapsed trials are skipped.
 *
 * Integration ('integration' group) — live DB + the real cron script.
 *
 * Covered:
 *  1. trial_nurture_targets(): band matching, converted/lapsed exclusion
 *  2. send_trial_nurture(): copy + retryable outbox shape for both bands
 *  3. The cron end-to-end: one email, marker stamped, bell created, and a
 *     second run never re-emails
 *  4. WPMS_TRIAL_NURTURE off = strict no-op
 */
final class TrialNurtureEmailTest extends TestCase
{
    private const FIX_EMAIL = 'probe.nurture.planner@example.com';
    private const SCRIPT    = __DIR__ . '/../scripts/subscription_renewals.php';

    private static ?PDO $pdo = null;
    private static int $staffId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/init.php';
        try {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // ENUM members are invisible to SHOW COLUMNS parses here; just try.
            try {
                self::$pdo->exec("ALTER TABLE tblsubscriptions MODIFY plan ENUM('starter','professional','agency','trial') NOT NULL DEFAULT 'starter'");
                self::$pdo->exec("ALTER TABLE tblsubscriptions MODIFY status ENUM('active','expired','cancelled','pending_renewal','trial') NOT NULL DEFAULT 'active'");
            } catch (Throwable $ignored) {
            }
            global $db;
            $db = new Database();
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        putenv('WPMS_TRIAL_DAYS=14');
    }

    public static function tearDownAfterClass(): void
    {
        putenv('WPMS_TRIAL_NURTURE');
        putenv('WPMS_SUB_GRACE');
        putenv('WPMS_MPESA_STK');
        putenv('WPMS_TRIAL_DAYS');
        self::cleanup();
    }

    private static function cleanup(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        $uid = (int) ($p->query("SELECT id FROM tblusers WHERE email = '" . self::FIX_EMAIL . "'")->fetchColumn() ?: 0);
        if ($uid > 0) {
            $p->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblnotifications WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblsms_credits WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblusers WHERE id = ' . $uid);
            self::$staffId = 0;
        }
        // Outbox rows carry user_id = 0 — key on the probe address too.
        $p->prepare('DELETE FROM tbl_mail_logs WHERE to_email = ?')->execute([self::FIX_EMAIL]);
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — trial nurture tests skipped');
        }
        self::cleanup();
    }

    /** The probe staff row (planner_ username, phone on file) + its ids. */
    private function makeStaff(): int
    {
        $p = self::$pdo;
        $p->prepare("INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created, phone)
                     VALUES ('Nurture', 'Probe', 'f', ?, ?, ?, 'Wedding Planner', '', '1', '', NOW(), '0722999001')")
           ->execute([
               'planner_nurture_' . strtolower(bin2hex(random_bytes(4))),
               password_hash('Sup3rSecret!', PASSWORD_DEFAULT),
               self::FIX_EMAIL,
           ]);
        $id = (int) $p->lastInsertId();
        self::$staffId = $id;
        $p->prepare('INSERT INTO tblsms_credits (user_id, credits, total_used) VALUES (?, 100, 0)')->execute([$id]);
        return $id;
    }

    /** A trial row for the probe staff expiring in $days days (0 = today). */
    private function makeTrial(int $days): object
    {
        $p = self::$pdo;
        $p->prepare("INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status, auto_renew)
                     VALUES (?, 'trial', 0.00, 'TRIAL', CURDATE(), (CURDATE() + INTERVAL ? DAY), 'trial', 0)")
           ->execute([self::$staffId, $days]);
        return $p->query('SELECT * FROM tblsubscriptions WHERE user_id = ' . self::$staffId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_OBJ);
    }

    private function mailSubjects(): array
    {
        $rows = self::$pdo->prepare('SELECT subject FROM tbl_mail_logs WHERE to_email = ?');
        $rows->execute([self::FIX_EMAIL]);
        return $rows->fetchAll(PDO::FETCH_COLUMN);
    }

    /** The nurture EMAIL subject (distinct from the bell copy's "Trial last call"). */
    private const NURTURE_LAST_CALL = 'Last call — your Everafter Planner trial ends tomorrow';
    private const NURTURE_CHECK_IN  = 'How is your Everafter Planner trial going?';

    // ------------------------------------------------------------------
    // 1. Target selection
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTargetsMatchBandsAndExcludeConvertedAndLapsed(): void
    {
        $this->requireDb();

        // 8 days left: outside both bands (7 and 1 for WPMS_TRIAL_DAYS=14).
        $this->makeStaff();
        $row = $this->makeTrial(8);
        $this->assertSame([], $this->targetsFor($row), '8 days left must not match any band');

        // 7 days left: the check-in band.
        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = (CURDATE() + INTERVAL 7 DAY) WHERE id = ' . (int) $row->id);
        $t = $this->targetsFor($row);
        $this->assertCount(1, $t, '7 days left = check-in band');
        $this->assertSame(7, $t[0]['band']);

        // Converted: a paid row newer than the trial — never a target.
        Subscription::create_subscription(self::$staffId, 'starter', 2999.00, 'QNURTURE1');
        $this->assertSame([], $this->targetsFor($row), 'a converted planner must never be nurtured');
    }

    #[Group('integration')]
    public function testLastCallBandAndLapsedExclusion(): void
    {
        $this->requireDb();

        $this->makeStaff();
        $row = $this->makeTrial(1);
        $t = $this->targetsFor($row);
        $this->assertCount(1, $t, '1 day left = last-call band');
        $this->assertSame(1, $t[0]['band']);

        // Expiry today: NOT nurture (the expiry-eve SMS owns day 0).
        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = CURDATE() WHERE id = ' . (int) $row->id);
        $this->assertSame([], $this->targetsFor($row), 'expiry day belongs to the dunning leg, not nurture');

        // Lapsed: never.
        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = (CURDATE() - INTERVAL 2 DAY) WHERE id = ' . (int) $row->id);
        $this->assertSame([], $this->targetsFor($row), 'a lapsed trial must never be nurtured');
    }

    /** Narrow targets() to the probe's rows (other DB rows must not affect asserts). */
    private function targetsFor(object $row): array
    {
        $out = [];
        foreach (Subscription::trial_nurture_targets() as $t) {
            if ((int) $t['sub']->user_id === (int) $row->user_id) {
                $out[] = $t;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 2. Copy + outbox shape
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testNurtureCopyForBothBands(): void
    {
        $this->requireDb();
        putenv('WPMS_MAIL_ENABLED='); // transport off => pending rows

        SignupGuard::send_trial_nurture(self::FIX_EMAIL, 'Asha', 7, '29 Sep 2026');
        SignupGuard::send_trial_nurture(self::FIX_EMAIL, 'Asha', 1, '29 Sep 2026');

        $rows = self::$pdo->prepare('SELECT subject, body, status, attempts FROM tbl_mail_logs WHERE to_email = ? ORDER BY id ASC');
        $rows->execute([self::FIX_EMAIL]);
        $mails = $rows->fetchAll(PDO::FETCH_OBJ);
        $this->assertCount(2, $mails);

        $this->assertStringContainsStringIgnoringCase('going', (string) $mails[0]->subject, 'band 7 = check-in');
        $this->assertStringContainsString('client_add.php', (string) $mails[0]->body);
        $this->assertStringContainsString('vendors.php', (string) $mails[0]->body);
        $this->assertStringContainsString('29 Sep 2026', (string) $mails[0]->body);

        $this->assertStringContainsStringIgnoringCase('Last call', (string) $mails[1]->subject, 'band 1 = last call');
        $this->assertStringContainsString('TOMORROW', (string) $mails[1]->body);
        $this->assertStringContainsString('subscription.php', (string) $mails[1]->body);

        foreach ($mails as $m) {
            $this->assertSame('pending', (string) $m->status, 'transport off => pending, retryable');
            $this->assertSame(0, (int) $m->attempts, 'attempts = 0 so mail_retry.php picks it up');
        }
    }

    // ------------------------------------------------------------------
    // 3. The cron, end-to-end
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testCronNurturesOnceAndNeverTwice(): void
    {
        $this->requireDb();
        putenv('WPMS_TRIAL_NURTURE=1');
        putenv('WPMS_MPESA_STK=1');
        putenv('WPMS_SUB_GRACE=1');
        putenv('WPMS_MAIL_ENABLED=');

        $this->makeStaff();
        $row = $this->makeTrial(1); // last-call band

        $out = (string) shell_exec('php ' . escapeshellarg(self::SCRIPT) . ' --limit=50 2>&1');
        $this->assertStringContainsString('trial nurture email user ' . self::$staffId . ' | band 1', $out);
        $this->assertStringContainsString('nurture: on, emailed: ', $out);

        $subjects = $this->mailSubjects();
        $lastCalls = array_filter($subjects, fn($s) => (string) $s === self::NURTURE_LAST_CALL);
        $this->assertCount(1, $lastCalls, 'exactly one last-call email must be sent');
        $this->assertSame(1, (int) self::$pdo->query('SELECT trial_nurture_band FROM tblsubscriptions WHERE id = ' . (int) $row->id)->fetchColumn());

        $bells = (int) self::$pdo->query('SELECT COUNT(*) FROM tblnotifications WHERE user_id = ' . self::$staffId)->fetchColumn();
        $this->assertSame(1, $bells, 'the last-call email must also ring the bell');

        // Second run: the marker suppresses everything.
        shell_exec('php ' . escapeshellarg(self::SCRIPT) . ' --limit=50 2>&1');
        $lastCalls2 = array_filter($this->mailSubjects(), fn($s) => (string) $s === self::NURTURE_LAST_CALL);
        $this->assertCount(1, $lastCalls2, 'the second run must never re-email');
    }

    #[Group('integration')]
    public function testKillSwitchIsStrictNoOp(): void
    {
        $this->requireDb();
        putenv('WPMS_TRIAL_NURTURE='); // OFF
        putenv('WPMS_SUB_GRACE=');
        putenv('WPMS_MPESA_STK=');

        $this->makeStaff();
        $this->makeTrial(1);

        $out = (string) shell_exec('php ' . escapeshellarg(self::SCRIPT) . ' --limit=50 2>&1');
        $this->assertStringContainsString('nurture: off', $out);
        $this->assertSame([], array_values(array_filter($this->mailSubjects(), fn($s) => (string) $s === self::NURTURE_LAST_CALL)),
            'the off switch must email nothing');
    }

    protected function tearDown(): void
    {
        self::cleanup();
    }
}
