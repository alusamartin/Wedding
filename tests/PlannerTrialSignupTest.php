<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Self-serve planner registration + 14-day trial regressions.
 *
 * Integration ('integration' group) — live DB + the curl HTTP harness
 * (same pattern as UnifiedLoginTest).
 *
 * Covered:
 *  1. register_planner() — validation, cross-table email uniqueness,
 *     transactional staff+trial creation, bcrypt password, phone saved
 *  2. Trial row properties — plan/status 'trial', amount 0, auto_renew 0,
 *     expiry +WPMS_TRIAL_DAYS; one trial per account ever
 *  3. state() flags the trial; routing sends a final-day trial to
 *     subscription.php
 *  4. Trials never enter auto-renew prompts or the paid band ladder
 *     (only the single expiry-eve text)
 *  5. HTTP end-to-end funnel: public form → auto-login → subscription.php
 *     (trial banner) → M-Pesa purchase → dashboard on next visit
 */
final class PlannerTrialSignupTest extends TestCase
{
    private const FIX_NAME  = 'probe_trial_planner';
    private const FIX_EMAIL = 'probe.trial.planner@example.com';
    private const SCRIPT    = __DIR__ . '/../scripts/subscription_renewals.php';

    private static ?PDO $pdo = null;
    private static int $staffId = 0;

    // --- HTTP harness state (mirrors UnifiedLoginTest) ---
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

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
        self::ensureColumns();
        global $db;
        $db = new Database();
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        self::bootServer();
        self::cleanup();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
        putenv('WPMS_TRIAL_DAYS');
        self::cleanup();
    }

    /** Apply the v2.3 columns when the SQL file has not been run yet. */
    private static function ensureColumns(): void
    {
        $p = self::$pdo;
        // ENUM members are invisible to SHOW COLUMNS parses here; just try.
        try {
            $p->exec("ALTER TABLE tblsubscriptions MODIFY plan ENUM('starter','professional','agency','trial') NOT NULL DEFAULT 'starter'");
            $p->exec("ALTER TABLE tblsubscriptions MODIFY status ENUM('active','expired','cancelled','pending_renewal','trial') NOT NULL DEFAULT 'active'");
        } catch (Throwable $ignored) {
        }
        try {
            $p->exec('ALTER TABLE tblusers MODIFY password VARCHAR(255) NOT NULL');
        } catch (Throwable $ignored) {
        }
    }

    private static function cleanup(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        $uid = (int) ($p->query("SELECT id FROM tblusers WHERE username = '" . self::FIX_NAME . "'")->fetchColumn() ?: 0);
        if ($uid > 0) {
            $p->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblmpesa_stk_pushes WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblnotifications WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tbl_mail_logs WHERE user_id = ' . $uid);
            // Welcome rows carry user_id = 0 (outbox convention) — key on
            // the probe address too.
            $p->prepare('DELETE FROM tbl_mail_logs WHERE to_email = ?')->execute([self::FIX_EMAIL]);
            $p->exec('DELETE FROM tblsms_logs WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblsms_credits WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblusers WHERE id = ' . $uid);
        }
        // Anything registered under the probe email (both account tables —
        // cross-table uniqueness is a feature under test).
        $p->exec("DELETE FROM tblusers WHERE email = '" . self::FIX_EMAIL . "'");
        $p->exec("DELETE FROM tblaccounts WHERE user_email = '" . self::FIX_EMAIL . "'");
        self::$staffId = 0;
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — planner trial tests skipped');
        }
    }

    // ------------------------------------------------------------------
    // 1. register_planner()
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRegisterPlannerValidatesInput(): void
    {
        $this->requireDb();
        $r = register_planner('', 'Doe', 'a@b.com', '', 'longenough1');
        self::assertFalse($r['ok'], 'missing first name must be refused');

        $r = register_planner('Asha', 'Doe', 'not-an-email', '', 'longenough1');
        self::assertFalse($r['ok'], 'invalid email must be refused');

        $r = register_planner('Asha', 'Doe', 'asha@example.com', '', 'short');
        self::assertFalse($r['ok'], 'short password must be refused');

        $r = register_planner('Asha', 'Doe', 'brian.wafula@gmail.com', '', 'longenough1');
        self::assertFalse($r['ok'], 'a CLIENT email must be refused (cross-table uniqueness)');
    }

    #[Group('integration')]
    public function testRegisterPlannerCreatesStaffAndTrialTransactionally(): void
    {
        $this->requireDb();
        putenv('WPMS_TRIAL_DAYS=14');
        $r = register_planner('Asha', 'Wanjiru', self::FIX_EMAIL, '0722999001', 'Sup3rSecret!');
        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertGreaterThan(0, (int) $r['user_id']);
        self::$staffId = (int) $r['user_id'];

        // Staff row: planner level, bcrypt, phone saved — and UNVERIFIED,
        // with no trial row: entitlements wait for the mailbox proof.
        $p = self::$pdo;
        $staff = $p->query('SELECT * FROM tblusers WHERE id = ' . self::$staffId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('1', (string) $staff['access_level']);
        self::assertSame('Wedding Planner', (string) $staff['designation']);
        self::assertSame('0722999001', (string) $staff['phone']);
        self::assertStringStartsWith('$2y$', (string) $staff['password'], 'password must be bcrypt');
        self::assertSame(60, strlen((string) $staff['password']), 'bcrypt hash is 60 chars — the column must fit it');
        self::assertTrue(password_verify('Sup3rSecret!', (string) $staff['password']));
        self::assertSame('0', (string) $staff['email_verified'], 'self-serve accounts start unverified');
        self::assertSame(0, (int) $p->query('SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = ' . self::$staffId)->fetchColumn(),
            'no trial may exist before the mailbox is proven');

        // Prove the mailbox (the verify page's core), then the trial opens.
        $plain = SignupGuard::issueVerification(self::$staffId);
        self::assertNotNull($plain);
        self::assertNotFalse(SignupGuard::verify($plain));
        $sub = Subscription::activate_trial(self::$staffId);
        self::assertNotNull($sub);

        // Trial row: zero-amount, auto_renew off, 14 days out.
        self::assertSame('trial', (string) $sub->plan);
        self::assertSame('trial', (string) $sub->status);
        self::assertSame('0.00', (string) $sub->amount);
        self::assertSame(0, (int) $sub->auto_renew);
        self::assertSame(date('Y-m-d', strtotime('+14 days')), (string) $sub->expiry_date);
        self::assertSame('1', (string) $p->query('SELECT email_verified FROM tblusers WHERE id = ' . self::$staffId)->fetchColumn());

        // Seed SMS credits — the dunning cron charges texts to the planner.
        $p->prepare('DELETE FROM tblsms_credits WHERE user_id = ?')->execute([self::$staffId]);
        $p->prepare('INSERT INTO tblsms_credits (user_id, credits, total_used) VALUES (?, 100, 0)')->execute([self::$staffId]);
    }

    #[Group('integration')]
    public function testOneTrialPerAccountEver(): void
    {
        $this->requireDb();
        self::assertGreaterThan(0, self::$staffId, 'previous test must have created the probe planner');
        self::assertFalse(
            Subscription::start_trial(self::$staffId),
            'a second trial must be refused while a live subscription exists'
        );
    }

    // ------------------------------------------------------------------
    // 2. state() + routing
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testStateFlagsTrialAndFinalDayRoutesToActivation(): void
    {
        $this->requireDb();
        $s = Subscription::state(self::$staffId);
        self::assertSame('active', $s['state'], 'a trial row is full access');
        self::assertTrue((bool) $s['trial'], 'state() must flag the trial');
        self::assertSame(14, $s['days_left'], 'env override WPMS_TRIAL_DAYS=14 must hold');

        // Not the final day → dashboard.
        $_SESSION = ['id' => self::$staffId];
        self::assertSame('admin/dashboard.php', login_redirect_target(true, self::$staffId, false));

        // Final day (expiry = today) → subscription.php (the funnel).
        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = CURDATE() WHERE user_id = ' . self::$staffId);
        self::assertSame('admin/subscription.php', login_redirect_target(true, self::$staffId, false));

        // Trial expired + grace off → locked → subscription.php.
        putenv('WPMS_SUB_GRACE=');
        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = ' . self::$staffId);
        self::assertSame('admin/subscription.php', login_redirect_target(true, self::$staffId, false));
        putenv('WPMS_SUB_GRACE=1');
        $_SESSION = [];
    }

    // ------------------------------------------------------------------
    // 3. Trials stay out of renewals + dunning ladders
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTrialIsNeverARenewalCandidateAndGetsOneTextOnly(): void
    {
        $this->requireDb();
        putenv('WPMS_SUB_GRACE=1'); // this test envs the cron itself
        try {
        // Reset the trial to inside the renewal window.
        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY) WHERE user_id = ' . self::$staffId);
        self::assertNull(
            Subscription::renewal_candidate(self::$staffId),
            'a trial must never be auto-renewed'
        );
        self::assertCount(
            0,
            Subscription::auto_renew_candidates(3),
            'a trial must never be prompted by the auto-renew leg'
        );

        // Dunning: only on expiry day (band 0), never at 7/3.
        $t = Subscription::dunning_targets([7, 3, 0]);
        self::assertCount(0, $t, 'a trial 2 days out must NOT be texted');

        self::$pdo->exec('UPDATE tblsubscriptions SET expiry_date = CURDATE() WHERE user_id = ' . self::$staffId);
        $t = Subscription::dunning_targets([7, 3, 0]);
        self::assertCount(1, $t, 'on expiry day the trial gets its single text');
        self::assertSame('trial', $t[0]['state']);

        $out = (string) shell_exec('php ' . escapeshellarg(self::SCRIPT) . ' --limit=20 2>&1');
        self::assertStringContainsString('(Trial) | trial', $out, 'the cron must text the trial user (plan Trial, state trial)');
        $bells = (int) self::$pdo->query(
            'SELECT COUNT(*) FROM tblnotifications WHERE user_id = ' . self::$staffId
        )->fetchColumn();
        self::assertSame(1, $bells, 'the trial reminder must create the bell notification');
        } finally {
            self::cleanup();
            putenv('WPMS_SUB_GRACE');
        }
    }

    // ------------------------------------------------------------------
    // 4. HTTP end-to-end funnel
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testPublicFunnelRegisterVerifyActivateReachDashboard(): void
    {
        $this->requireDb();
        if (self::$base === null) {
            self::markTestSkipped('test HTTP server unavailable');
        }
        putenv('WPMS_TRIAL_DAYS=14');
        self::$jar = [];

        // The public page renders with the CSRF form.
        $page = self::http('GET', '/planner_register.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Start my free trial', $page['body']);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
        self::assertNotEmpty($m, 'CSRF token must be present');

        // Register → NO auto-login: straight to the check-your-inbox page.
        $res = self::http('POST', '/planner_register.php', [
            'firstname'        => 'Asha',
            'lastname'         => 'Wanjiru',
            'email'            => self::FIX_EMAIL,
            'phone'            => '0722999001',
            'password'         => 'Sup3rSecret!',
            '_token'           => html_entity_decode($m[1], ENT_QUOTES),
            'register_planner' => '1',
        ]);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('verify_planner.php', $res['location'],
            'registration must route to verification, not auto-login');

        // The staff row exists now (cleanup knows the id) — unverified.
        self::$staffId = (int) self::$pdo->query(
            "SELECT id FROM tblusers WHERE email = '" . self::FIX_EMAIL . "'"
        )->fetchColumn();
        self::assertGreaterThan(0, self::$staffId);
        self::assertSame('0', (string) self::$pdo->query('SELECT email_verified FROM tblusers WHERE id = ' . self::$staffId)->fetchColumn());

        // Act like the mailbox: pull the plain link out of the outbox row
        // (tbl_mail_logs stores the full body — verification transport is
        // opt-in, the record is not) and click it.
        $body = (string) self::$pdo->query(
            "SELECT body FROM tbl_mail_logs WHERE to_email = '" . self::FIX_EMAIL . "' AND subject LIKE 'Verify%' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotEmpty($body, 'the verification email must be recorded in the outbox');
        preg_match('/\?token=([0-9a-f]{64})/', $body, $tm);
        self::assertNotEmpty($tm, 'the outbox body must carry the verification link');

        // Click: verify → trial starts → auto-login → activation page.
        $click = self::http('GET', '/verify_planner.php?token=' . $tm[1]);
        self::assertSame(302, $click['status']);
        self::assertSame('admin/subscription.php', $click['location'], 'verification routes to the activation page');

        // Session live, account verified, trial row opened.
        $sub = self::http('GET', '/admin/subscription.php');
        self::assertSame(200, $sub['status'], 'verification must have signed the planner in');
        self::assertStringContainsString('Free trial', $sub['body']);
        self::assertSame('1', (string) self::$pdo->query('SELECT email_verified FROM tblusers WHERE id = ' . self::$staffId)->fetchColumn());
        self::assertSame(1, (int) self::$pdo->query(
            "SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = " . self::$staffId . " AND status = 'trial'"
        )->fetchColumn());

        // A second click can never re-process the token: the planner is now
        // signed in, and the verify page bounces signed-in staff to their
        // role home before any token handling. (Strict single-use refusal
        // for a signed-out click is covered in SignupAbuseGuardTest.)
        $again = self::http('GET', '/verify_planner.php?token=' . $tm[1]);
        self::assertSame(302, $again['status'], 'a second click must never re-process the token');
        self::assertStringNotContainsString('subscription.php', $again['location'] ?? '');

        // Activation: pay for starter with an M-Pesa code (verification off
        // in tests) — the exact flow a converting trial takes.
        preg_match('/name="_token" value="([^"]+)"/', $sub['body'], $t2);
        $buy = self::http('POST', '/admin/subscription.php', [
            'plan'       => 'starter',
            'mpesa_code' => 'QTRIALBUY1',
            '_token'     => html_entity_decode($t2[1] ?? '', ENT_QUOTES),
            'purchase'   => '1',
        ]);
        self::assertSame(302, $buy['status'], 'the purchase must be accepted');
        self::assertSame('subscription.php', $buy['location']);

        // The paid row is live; the next visit routes to the dashboard.
        $active = self::$pdo->query(
            "SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = " . self::$staffId . " AND status = 'active' AND plan = 'starter'"
        )->fetchColumn();
        self::assertSame(1, (int) $active, 'the starter plan must be activated');

        $home = self::http('GET', '/login.php');
        self::assertSame(302, $home['status']);
        self::assertStringContainsString('dashboard.php', $home['location'], 'an activated planner reaches their dashboard');

        self::cleanup();
    }

    // ------------------------------------------------------------------
    // HTTP harness (mirrors UnifiedLoginTest)
    // ------------------------------------------------------------------

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_pt_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_pt_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root);
            if (!is_resource(self::$serverProc)) {
                continue;
            }
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
            }
            if ($ready) {
                self::$base = 'http://127.0.0.1:' . $port;
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
                if (@file_get_contents(self::$base . '/index.php', false, $ctx) !== false) {
                    return;
                }
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
                self::$serverProc = null;
                self::$base = null;
            } else {
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
            }
        }
        self::$base = null;
    }

    /**
     * curl HTTP client; redirects are NOT followed.
     *
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $ch = curl_init(self::$base . $path);
        $headers = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ];
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $opts[CURLOPT_HTTPHEADER] = ['Cookie: ' . implode('; ', $parts)];
        }
        if ($post !== []) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        curl_close($ch);

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }
}
