<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Subscription auto-renewal + grace + SMS dunning regressions.
 *
 * Integration ('integration' group) — live DB, same pattern as
 * DeadlineSmsFlowTest / MpesaStkTest. A probe staff row + probe subscription
 * rows are created and always restored.
 *
 * Covered:
 *  1. state() — active / grace (within window) / locked (past window,
 *     grace off, never paid)
 *  2. login gate semantics — grace users are NOT diverted, locked users are
 *     (via subscription_state_for_session on a seeded session)
 *  3. renewal clone → extend_renewal extends the SAME row +30d from its own
 *     expiry, flips auto_renew back to 1, exactly once (replay-safe)
 *  4. STK fulfilment routes to extend_renewal when a promise exists (no
 *     second live row) and to create_subscription when none does
 *  5. dunning_targets + script run — one text per band, marker semantics,
 *     grace text on day 3, auto-renew-off planners still texted
 *  6. sweep_pending_renewals clears dead promises, keeps live ones
 */
final class SubscriptionRenewalFlowTest extends TestCase
{
    private const FIX_STAFF   = 'probe_renew_staff';
    private const PROBE_EMAIL = 'probe.renew@example.com';
    private const SCRIPT      = __DIR__ . '/../scripts/subscription_renewals.php';

    private static ?PDO $pdo = null;
    private static int $staffId = 0;
    /** @var int[] subscription ids created by this run */
    private static array $subIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';
        require_once __DIR__ . '/../admin/include/db_object.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
        require_once __DIR__ . '/../admin/include/Monetization.php';
        if (!class_exists('Notification', false)) {
            require_once __DIR__ . '/../admin/include/Notification.php';
        }
        try {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::ensureColumns();
            global $db;
            $db = new Database();
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        putenv('WPMS_SUB_AUTO_RENEW=1');
        putenv('WPMS_SUB_GRACE=1');
        putenv('WPMS_MPESA_STK=1'); // transport stays simulated (no credentials)
        self::cleanup();
    }

    public static function tearDownAfterClass(): void
    {
        putenv('WPMS_SUB_AUTO_RENEW');
        putenv('WPMS_SUB_GRACE');
        putenv('WPMS_MPESA_STK');
        putenv('WPMS_SUB_GRACE_DAYS');
        self::cleanup();
    }

    /** Create the migration columns when the SQL file has not been applied yet. */
    private static function ensureColumns(): void
    {
        $p = self::$pdo;
        $cols = $p->query("SHOW COLUMNS FROM tblsubscriptions LIKE 'auto_renew'")->fetchAll();
        if ($cols === []) {
            $p->exec('ALTER TABLE tblsubscriptions ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 1 AFTER status');
            $p->exec('ALTER TABLE tblsubscriptions ADD COLUMN renewal_sms_band INT UNSIGNED DEFAULT NULL AFTER auto_renew');
        }
        $u = $p->query("SHOW COLUMNS FROM tblusers LIKE 'phone'")->fetchAll();
        if ($u === []) {
            $p->exec('ALTER TABLE tblusers ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email');
        }
    }

    private static function cleanup(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        $p->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . self::$staffId . " OR mpesa_code LIKE 'QREN%' OR mpesa_code LIKE 'PENDING-QREN%'");
        $p->exec('DELETE FROM tblmpesa_stk_pushes WHERE user_id = ' . self::$staffId . " OR phone = '0721999077'");
        $p->exec('DELETE FROM tblnotifications WHERE user_id = ' . self::$staffId);
        $p->exec("DELETE FROM tblsms_logs WHERE phone IN ('0721999077', '254721999077', '+254721999077')");
        $p->exec("DELETE FROM tblusers WHERE username = '" . self::FIX_STAFF . "'");
        self::$staffId = 0;
        self::$subIds = [];
    }

    /** Wipe only the subscription rows (staff row + phone stay put). */
    private static function clearSubs(): void
    {
        if (self::$pdo === null || self::$staffId === 0) {
            return;
        }
        self::$pdo->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . self::$staffId);
        self::$pdo->exec('DELETE FROM tblmpesa_stk_pushes WHERE user_id = ' . self::$staffId);
        self::$pdo->exec('DELETE FROM tblnotifications WHERE user_id = ' . self::$staffId);
        self::$pdo->exec("DELETE FROM tblsms_logs WHERE phone IN ('0721999077', '254721999077', '+254721999077')");
        self::$subIds = [];
    }

    /** Probe staff row with a phone + credits, seeded fresh per test. */
    private static function seedStaff(?string $phone = '0721999077'): int
    {
        if (self::$staffId > 0) {
            return self::$staffId;
        }
        $p = self::$pdo;
        $p->prepare("INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created, phone)
                     VALUES ('Probe', 'Renew', 'f', ?, 'x', ?, '1', 'Nairobi', '1', '', NOW(), ?)")
          ->execute([self::FIX_STAFF, self::PROBE_EMAIL, $phone]);
        self::$staffId = (int) $p->lastInsertId();
        $p->prepare('INSERT INTO tblsms_credits (user_id, credits, total_used) VALUES (?, 100, 0)
                     ON DUPLICATE KEY UPDATE credits = 100')
          ->execute([self::$staffId]);
        return self::$staffId;
    }

    /** Insert a subscription row directly; returns the row id. */
    private static function seedSub(string $status, string $expiry, int $autoRenew = 1, ?string $code = null, string $plan = 'starter'): int
    {
        $uid = self::seedStaff();
        $p = self::$pdo;
        $p->prepare('INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status, auto_renew, created_at)
                     VALUES (?, ?, 2999.00, ?, DATE_SUB(?, INTERVAL 30 DAY), ?, ?, ?, NOW())')
           ->execute([$uid, $plan, $code ?? ('QREN' . strtoupper(bin2hex(random_bytes(3)))), $expiry, $expiry, $status, $autoRenew]);
        $id = (int) $p->lastInsertId();
        self::$subIds[] = $id;
        return $id;
    }

    private static function runScript(string $args = ''): string
    {
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' ' . $args . ' 2>&1';
        return (string) shell_exec($cmd);
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — subscription renewal tests skipped');
        }
    }

    // ------------------------------------------------------------------
    // 1. state()
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testStateActiveInsideGraceAndLocked(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        self::clearSubs();

        // Active: expiry in the future.
        self::seedSub('active', date('Y-m-d', strtotime('+10 days')));
        $s = Subscription::state($uid);
        self::assertSame('active', $s['state']);
        self::assertSame(10, $s['days_left']);

        // Grace: newest row lapsed 2 days ago, grace = 7.
        self::clearSubs();
        putenv('WPMS_SUB_GRACE_DAYS=7');
        self::seedSub('active', date('Y-m-d', strtotime('-2 days')));
        $s = Subscription::state($uid);
        self::assertSame('grace', $s['state']);
        self::assertSame(5, $s['grace_days_left']);

        // Locked: grace expired.
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('-8 days')));
        self::assertSame('locked', Subscription::state($uid)['state']);

        // Locked: grace feature off entirely.
        self::clearSubs();
        putenv('WPMS_SUB_GRACE=');
        self::seedSub('active', date('Y-m-d', strtotime('-1 day')));
        self::assertSame('locked', Subscription::state($uid)['state']);
        putenv('WPMS_SUB_GRACE=1');

        // Locked: never paid at all.
        self::clearSubs();
        self::assertSame('locked', Subscription::state($uid)['state']);
        self::clearSubs();
    }

    #[Group('integration')]
    public function testUnpaidRenewalPromiseKeepsGraceState(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        putenv('WPMS_SUB_GRACE_DAYS=7');
        self::clearSubs();

        // The lapsed original (its clone was deleted ⇒ a paid row + promise)
        self::seedSub('active', date('Y-m-d', strtotime('-9 days')));
        // The pending promise (auto_renew = 2, expiry still in the past).
        self::seedSub('pending_renewal', date('Y-m-d', strtotime('-1 day')), 2);

        $s = Subscription::state($uid);
        self::assertSame('grace', $s['state'], 'a pending renewal promise must keep the staff member in grace');
        self::clearSubs();
    }

    // ------------------------------------------------------------------
    // 2. Gate semantics (subscription_state_for_session on a fake session)
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testGateDivertsLockedButNotGrace(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        putenv('WPMS_SUB_GRACE_DAYS=7');
        self::clearSubs();

        // Active staff → 'active' (dashboard).
        self::seedSub('active', date('Y-m-d', strtotime('+10 days')));
        $_SESSION = ['id' => $uid];
        self::assertSame('active', subscription_state_for_session());

        // Grace staff → 'grace' (dashboard with banner, not the pay wall).
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('-2 days')));
        $_SESSION = ['id' => $uid];
        self::assertSame('grace', subscription_state_for_session());

        // Locked staff → 'locked' (login/require_staff_subscribed divert to subscription.php).
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('-8 days')));
        $_SESSION = ['id' => $uid];
        self::assertSame('locked', subscription_state_for_session());

        $_SESSION = [];
        self::clearSubs();
    }

    // ------------------------------------------------------------------
    // 3. Clone + extend (the renewal core)
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRenewalCloneExtendsSameRowFromOwnExpiry(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        self::clearSubs();

        // Active row expiring in 2 days (inside the renewal window).
        $subId = self::seedSub('active', date('Y-m-d', strtotime('+2 days')));
        $clone = Subscription::create_renewal_clone($uid, 'starter', 2999.0);
        self::assertNotNull($clone, 'the promise must be created');
        self::$subIds[] = (int) $clone->id;
        self::assertSame('pending_renewal', (string) $clone->status);
        self::assertSame(2, (int) $clone->auto_renew);
        // The promise starts where the old one ends — no gap, no overlap.
        self::assertSame(date('Y-m-d', strtotime('+2 days')), (string) $clone->start_date);
        self::assertSame(date('Y-m-d', strtotime('+32 days')), (string) $clone->expiry_date);

        // Pay it: the PROMISE becomes the live row, extended +30d FROM ITS
        // OWN EXPIRY (2d + 30d = 32d); the original is superseded (expired).
        self::assertTrue(Subscription::extend_renewal($uid, 'starter', 2999.0, 'QRENEXT01'));
        $old = Subscription::sub_by_id($subId);
        self::assertSame('expired', (string) $old->status, 'the superseded original must be marked expired');
        $paid = Subscription::sub_by_id((int) $clone->id);
        self::assertSame('active', (string) $paid->status);
        self::assertSame(1, (int) $paid->auto_renew, 'a paid promise flips back to a normal row');
        self::assertSame('QRENEXT01', (string) $paid->mpesa_code);
        self::assertSame(date('Y-m-d', strtotime('+32 days')), (string) $paid->expiry_date);

        // Exactly once: a second callback for the same renewal is refused.
        self::assertFalse(Subscription::extend_renewal($uid, 'starter', 2999.0, 'QRENEXT02'));
        self::clearSubs();
    }

    #[Group('integration')]
    public function testExtendFailsWithoutPendingPromise(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('+10 days')));
        self::assertFalse(
            Subscription::extend_renewal($uid, 'starter', 2999.0, 'QRENONE01'),
            'a paid callback with no promise must not create/extend anything'
        );
        self::clearSubs();
    }

    // ------------------------------------------------------------------
    // 4. STK fulfilment routes through the renewal path
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testStkFulfilmentRenewsInsteadOfStackingRows(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        self::clearSubs();

        $subId = self::seedSub('active', date('Y-m-d', strtotime('+2 days')));
        $clone = Subscription::create_renewal_clone($uid, 'starter', 2999.0);
        self::$subIds[] = (int) $clone->id;

        // The push reports success (simulated transport — direct apply, the
        // exact method mpesa_stk_callback.php calls).
        $applied = MpesaStk::apply_callback('ws_CO_SIM-' . strtoupper(bin2hex(random_bytes(8))), 0, 'x', 'QRENSTK01');
        self::assertFalse($applied['ok'], 'an unknown checkout id must be refused');

        // Real flow: initiate a push for this user+plan (creates its own row),
        // then answer it. fulfil() must extend the pending clone, not stack a
        // second live subscription.
        $init = MpesaStk::initiate('subscription', $uid, '0721999077', 2999.0, ['plan' => 'starter']);
        self::assertTrue($init['ok'], (string) ($init['error'] ?? ''));
        $applied = MpesaStk::apply_callback((string) $init['push']->checkout_request_id, 0, 'ok', 'QRENSTK02');
        self::assertTrue($applied['ok'], (string) ($applied['error'] ?? ''));

        // No second live row: exactly one active subscription for the user —
        // the renewed promise. The original (+2d) row is superseded (expired).
        global $db;
        $live = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = :u AND status = 'active'",
            [':u' => $uid]
        );
        self::assertSame(1, $live, 'renewal must extend in place, never stack a second live row');
        $old = Subscription::sub_by_id($subId);
        self::assertSame('expired', (string) $old->status, 'the superseded original must be marked expired');
        $renewed = $db->fetchOne(
            "SELECT * FROM tblsubscriptions WHERE user_id = :u AND status = 'active' LIMIT 1",
            [':u' => $uid]
        );
        self::assertSame('QRENSTK02', (string) $renewed->mpesa_code, 'the renewal receipt lands on the renewed row');
        self::assertSame(1, (int) $renewed->auto_renew, 'a fulfilled promise flips back to a normal row');
        // +30 days from the promise's own start (the parent's expiry), not from today.
        self::assertSame(date('Y-m-d', strtotime('+32 days')), (string) $renewed->expiry_date);
        self::clearSubs();
    }

    // ------------------------------------------------------------------
    // 5. Dunning: targets, bands, marker, script output
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testDunningBandsTextOnceAndGraceTextsOnDay3(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        putenv('WPMS_SUB_GRACE_DAYS=7');
        putenv('WPMS_SUB_AUTO_RENEW='); // isolate LEG 2 — no STK prompts in this test
        self::clearSubs();

        // Band 3: expiry in 3 days → text; second run silent.
        self::seedSub('active', date('Y-m-d', strtotime('+3 days')));
        $targets = Subscription::dunning_targets([7, 3, 0]);
        self::assertCount(1, $targets, 'the probe staff member must be a band-3 target');
        self::assertSame('active', $targets[0]['state']);

        $out = self::runScript('--limit=20');
        self::assertStringContainsString('texted user ' . $uid, $out);
        self::assertSame(
            1,
            (int) self::$pdo->query("SELECT COUNT(*) FROM tblsms_logs WHERE phone = '254721999077'")->fetchColumn(),
            'exactly one text on disk (the outbox stores the E.164 form)'
        );
        // Marker stamped with the smallest band (native int under PHP 8 mysqlnd).
        $rows = array_map('intval', self::$pdo->query('SELECT renewal_sms_band FROM tblsubscriptions WHERE user_id = ' . $uid)->fetchAll(PDO::FETCH_COLUMN));
        self::assertContains(3, $rows, 'the band marker must be stamped');

        $out = self::runScript('--limit=20');
        self::assertStringContainsString('texted: 0', $out, 'the same band must never text twice');

        // Grace: the row lapses into day 3 of the 7-day grace (band 0 marker
        // is smaller than 3, so the grace text fires on the next run).
        self::clearSubs();
        self::$pdo->exec("DELETE FROM tblsms_logs WHERE phone IN ('0721999077', '254721999077', '+254721999077')");
        self::seedSub('active', date('Y-m-d', strtotime('-4 days'))); // 7 - 4 = 3 grace days left
        $targets = Subscription::dunning_targets([7, 3, 0]);
        self::assertCount(1, $targets);
        self::assertSame('grace', $targets[0]['state']);
        self::assertSame(3, $targets[0]['grace_days_left']);

        $out = self::runScript('--limit=20');
        self::assertStringContainsString('texted user ' . $uid, $out);
        $msgs = self::$pdo->query("SELECT message FROM tblsms_logs WHERE phone = '254721999077'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $msgs);
        self::assertStringContainsString('lapsed', (string) $msgs[0], 'the grace text must say the account lapsed');
        self::clearSubs();
        putenv('WPMS_SUB_AUTO_RENEW=1');
    }

    #[Group('integration')]
    public function testAutoRenewOffPlannerStillGetsReminder(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        putenv('WPMS_SUB_AUTO_RENEW='); // isolate LEG 2
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('+7 days')), 0);

        $targets = Subscription::dunning_targets([7, 3, 0]);
        self::assertCount(1, $targets, 'manual-renew planners must be reminded too');

        $out = self::runScript('--limit=20');
        self::assertStringContainsString('texted user ' . $uid, $out);
        self::clearSubs();
        putenv('WPMS_SUB_AUTO_RENEW=1');
    }

    #[Group('integration')]
    public function testDryRunWritesNothing(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        putenv('WPMS_SUB_AUTO_RENEW='); // isolate LEG 2
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('+3 days')));

        $out = self::runScript('--dry-run --limit=20');
        self::assertStringContainsString('[dry-run]', $out);
        self::assertSame(
            0,
            (int) self::$pdo->query("SELECT COUNT(*) FROM tblsms_logs WHERE phone = '254721999077'")->fetchColumn(),
            'dry run must not send'
        );
        $band = self::$pdo->query('SELECT renewal_sms_band FROM tblsubscriptions WHERE user_id = ' . $uid)->fetchColumn();
        self::assertNull($band, 'dry run must not stamp the marker');
        self::clearSubs();
        putenv('WPMS_SUB_AUTO_RENEW=1');
    }

    // ------------------------------------------------------------------
    // 6. Auto-renew candidates + promise sweep
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAutoRenewCandidatesWindowAndPromiseSuppression(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();

        // Inside the 3-day window → candidate.
        self::clearSubs();
        self::seedSub('active', date('Y-m-d', strtotime('+2 days')));
        $cands = Subscription::auto_renew_candidates(3);
        self::assertCount(1, $cands);

        // A pending promise suppresses the candidate (one prompt at a time).
        self::seedSub('pending_renewal', date('Y-m-d', strtotime('+2 days')), 2);
        self::assertCount(0, Subscription::auto_renew_candidates(3));
        self::clearSubs();

        // Outside the window → no candidate.
        self::seedSub('active', date('Y-m-d', strtotime('+10 days')));
        self::assertCount(0, Subscription::auto_renew_candidates(3));
        self::clearSubs();

        // auto_renew = 0 → no candidate.
        self::seedSub('active', date('Y-m-d', strtotime('+2 days')), 0);
        self::assertCount(0, Subscription::auto_renew_candidates(3));
        self::clearSubs();
    }

    #[Group('integration')]
    public function testSweepClearsDeadPromisesAndKeepsLiveOnes(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        self::clearSubs();

        // Scenario 1 — dead promise: older than a day, no pending push behind it.
        $dead = self::seedSub('pending_renewal', date('Y-m-d', strtotime('-1 day')), 2);
        self::$pdo->exec('UPDATE tblsubscriptions SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ' . $dead);

        $swept = Subscription::sweep_pending_renewals();
        self::assertGreaterThanOrEqual(1, $swept);
        self::assertNull(Subscription::sub_by_id($dead), 'a dead promise must be swept');

        // Scenario 2 — live promise: a pending push was requested after the
        // clone (the planner is mid-PIN-entry on stk_status.php).
        self::clearSubs();
        $live = self::seedSub('pending_renewal', date('Y-m-d', strtotime('-1 day')), 2);
        self::$pdo->exec('UPDATE tblsubscriptions SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ' . $live);
        self::$pdo->prepare('INSERT INTO tblmpesa_stk_pushes (checkout_request_id, purpose, plan, user_id, phone, amount, status, requested_at)
                             VALUES (?, "subscription", "starter", ?, "0721999077", 2999.00, "pending", DATE_SUB(NOW(), INTERVAL 1 HOUR))')
             ->execute(['ws_CO_SIM-QRENSWEEP', $uid]);
        $pushId = (int) self::$pdo->lastInsertId();

        Subscription::sweep_pending_renewals();
        self::assertNotNull(Subscription::sub_by_id($live), 'a promise with a live push behind it must survive the sweep');

        self::$pdo->exec('DELETE FROM tblmpesa_stk_pushes WHERE id = ' . $pushId);
        self::clearSubs();
    }

    // ------------------------------------------------------------------
    // 7. Toggle + phone
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAutoRenewToggleAndStaffPhone(): void
    {
        $this->requireDb();
        $uid = self::seedStaff();
        self::clearSubs();
        $subId = self::seedSub('active', date('Y-m-d', strtotime('+10 days')));

        self::assertTrue(Subscription::set_auto_renew($uid, false));
        self::assertSame(0, (int) Subscription::sub_by_id($subId)->auto_renew);
        self::assertTrue(Subscription::set_auto_renew($uid, true));
        self::assertSame(1, (int) Subscription::sub_by_id($subId)->auto_renew);

        self::assertTrue(Subscription::set_staff_phone($uid, '0733444555'));
        self::assertSame('0733444555', Subscription::staff_phone($uid));
        self::clearSubs();
    }
}
