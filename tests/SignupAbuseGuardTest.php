<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * WPMS Kenya v2.4 — registration abuse protection.
 *
 * Integration ('integration' group) — live DB. Covered:
 *
 *  1. SignupGuard throttle: per-IP and per-email rolling-hour caps, attempts
 *     recorded on success AND failure, kill-switch + prune behaviour.
 *  2. Verification tokens: hashed at rest, single-use, expiring, rate-limited
 *     resend, refuse-when-verified.
 *  3. register_planner(): the account is created UNVERIFIED and WITHOUT a
 *     trial (the trial belongs to the verification, not the signup).
 *  4. SignupGuard::verify() + Subscription::activate_trial(): the funnel's
 *     activation pair — account verified, trial opened with WPMS_TRIAL_DAYS.
 *  5. HTTP: planner_register.php now redirects to the verify page, and a
 *     correct password on an unverified account cannot reach a staff page.
 */
final class SignupAbuseGuardTest extends TestCase
{
    private const FIX_EMAIL = 'probe.abuse.planner@example.com';

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
            global $db;
            $db = new Database();
        } catch (Throwable $e) {
            self::$pdo = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
        putenv('WPMS_LOGIN_THROTTLE_OFF');
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
            $p->exec('DELETE FROM tblusers WHERE id = ' . $uid);
        }
        $p->exec('DELETE FROM tblsignup_throttle');
        $p->exec("DELETE FROM tblplanner_verifications WHERE user_id = {$uid}");
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — signup guard tests skipped');
        }
        self::cleanup();
        self::$staffId = 0;
    }

    // ------------------------------------------------------------------
    // 1. The damper
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testPerIpCapCountsEveryAttemptIncludingSuccesses(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $ip = '203.0.113.77';

        for ($i = 0; $i < SignupGuard::MAX_PER_IP; $i++) {
            SignupGuard::recordAttempt("flood{$i}@example.com", true, $ip);
            if ($i < SignupGuard::MAX_PER_IP - 1) {
                self::assertSame(0, SignupGuard::secondsRemaining("fresh{$i}@example.com", $ip));
            }
        }
        // The cap is full — even a brand-new email from this IP must wait.
        $wait = SignupGuard::secondsRemaining('fresh@example.com', $ip);
        self::assertGreaterThan(0, $wait, 'per-IP cap must block a fresh email once the IP is flooded');
        self::assertLessThanOrEqual(3600, $wait);
        self::assertGreaterThan(3540, $wait, 'the window opens when the OLDEST row ages out (~1h away)');
    }

    #[Group('integration')]
    public function testPerEmailCapBlocksFourthAttemptWithinTheHour(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $ip = '203.0.113.78';
        $email = 'repeat@example.com';

        for ($i = 0; $i < SignupGuard::MAX_PER_EMAIL; $i++) {
            SignupGuard::recordAttempt($email, false, $ip);
        }
        $wait = SignupGuard::secondsRemaining($email, $ip);
        self::assertGreaterThan(0, $wait, '3 attempts/rolling hour per email — the 4th must wait');

        // A different email on the same (below-cap) IP is fine.
        self::assertSame(0, SignupGuard::secondsRemaining('other@example.com', $ip));
    }

    #[Group('integration')]
    public function testAttemptsRecordedOnSuccessAndFailure(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $ip = '203.0.113.79';
        SignupGuard::recordAttempt('ok@example.com', true, $ip);
        SignupGuard::recordAttempt('bad@example.com', false, $ip);
        $c = (int) self::$pdo->query("SELECT COUNT(*) FROM tblsignup_throttle WHERE ip = '{$ip}'")->fetchColumn();
        self::assertSame(2, $c, 'successes and failures are BOTH recorded');
        $ok = (int) self::$pdo->query("SELECT COUNT(*) FROM tblsignup_throttle WHERE ip = '{$ip}' AND success = 1")->fetchColumn();
        self::assertSame(1, $ok);
    }

    #[Group('integration')]
    public function testKillSwitchDisablesTheDamper(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=1');
        $ip = '203.0.113.80';
        for ($i = 0; $i < SignupGuard::MAX_PER_IP + 5; $i++) {
            SignupGuard::recordAttempt("x{$i}@example.com", false, $ip);
        }
        self::assertSame(0, SignupGuard::secondsRemaining('anything@example.com', $ip));
    }

    #[Group('integration')]
    public function testPruneRemovesOldRowsOnly(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        self::$pdo->exec("INSERT INTO tblsignup_throttle (email, ip, success, created_at)
                          VALUES ('old@example.com', '203.0.113.81', 0, (NOW() - INTERVAL 8 DAY))");
        self::$pdo->exec("INSERT INTO tblsignup_throttle (email, ip, success, created_at)
                          VALUES ('new@example.com', '203.0.113.81', 0, NOW())");
        SignupGuard::prune();
        $old = (int) self::$pdo->query("SELECT COUNT(*) FROM tblsignup_throttle WHERE email = 'old@example.com'")->fetchColumn();
        $new = (int) self::$pdo->query("SELECT COUNT(*) FROM tblsignup_throttle WHERE email = 'new@example.com'")->fetchColumn();
        self::assertSame(0, $old, '8-day-old rows must be pruned');
        self::assertSame(1, $new, 'fresh rows must survive');
    }

    // ------------------------------------------------------------------
    // 2. Tokens
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testIssueStoresHashNotPlainToken(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $id = $this->makeUnverifiedPlanner();
        $plain = SignupGuard::issueVerification($id);
        self::assertNotFalse($plain);
        self::assertNotNull($plain);
        self::assertSame(64, strlen($plain));
        $hashes = self::$pdo->query('SELECT token_hash FROM tblplanner_verifications')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotEmpty($hashes);
        self::assertNotContains($plain, $hashes, 'the PLAIN token must never be stored');
        self::assertContains(hash('sha256', $plain), $hashes);
    }

    #[Group('integration')]
    public function testVerifyIsSingleUseAndStampsVerified(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $id = $this->makeUnverifiedPlanner();
        $plain = SignupGuard::issueVerification($id);

        $row = SignupGuard::verify($plain);
        self::assertNotFalse($row);
        self::assertSame($id, (int) $row->user_id);
        self::assertSame(1, (int) self::$pdo->query("SELECT email_verified FROM tblusers WHERE id = {$id}")->fetchColumn());
        self::assertFalse(SignupGuard::verify($plain), 'the same token must not verify twice');
    }

    #[Group('integration')]
    public function testExpiredTokenIsRefused(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $id = $this->makeUnverifiedPlanner();
        $plain = bin2hex(random_bytes(32));
        self::$pdo->prepare('INSERT INTO tblplanner_verifications (user_id, token_hash, expires_at)
                             VALUES (?, ?, (NOW() - INTERVAL 1 MINUTE))')
            ->execute([$id, hash('sha256', $plain)]);
        self::assertFalse(SignupGuard::verify($plain), 'an expired token must be refused');
    }

    #[Group('integration')]
    public function testResendIsRateLimitedAndRefusesVerifiedAccounts(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        $id = $this->makeUnverifiedPlanner();

        for ($i = 0; $i < SignupGuard::MAX_SENDS_PER_HOUR; $i++) {
            $plain = SignupGuard::issueVerification($id);
            self::assertNotNull($plain, 'send ' . ($i + 1) . ' must be allowed');
        }
        self::assertNull(SignupGuard::issueVerification($id), 'send 6 within the hour must be rate-limited');

        // A VERIFIED account gets nothing — no reason to mail it.
        self::$pdo->exec("UPDATE tblusers SET email_verified = 1 WHERE id = {$id}");
        self::assertNull(SignupGuard::issueVerification($id));
    }

    // ------------------------------------------------------------------
    // 3. register_planner: unverified, trial-less
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRegisterPlannerCreatesUnverifiedAccountWithoutTrial(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        putenv('WPMS_TRIAL_DAYS=14');
        $r = register_planner('Asha', 'Abuse', self::FIX_EMAIL, '0722999001', 'Sup3rSecret!');
        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::$staffId = (int) $r['user_id'];
        self::assertArrayNotHasKey('trial', $r, 'no trial may exist before verification');

        $p = self::$pdo;
        self::assertSame('0', (string) $p->query("SELECT email_verified FROM tblusers WHERE id = {$r['user_id']}")->fetchColumn());
        self::assertSame(0, (int) $p->query("SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = {$r['user_id']}")->fetchColumn(),
            'the trial must NOT open at signup — only at verification');
    }

    #[Group('integration')]
    public function testVerifyThenActivateOpensTheTrial(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=');
        putenv('WPMS_TRIAL_DAYS=14');
        $r = register_planner('Asha', 'Activates', self::FIX_EMAIL, '0722999001', 'Sup3rSecret!');
        self::$staffId = (int) $r['user_id'];

        $plain = SignupGuard::issueVerification(self::$staffId);
        $row = SignupGuard::verify($plain);
        self::assertNotFalse($row);
        $trial = Subscription::activate_trial(self::$staffId);
        self::assertNotNull($trial);
        self::assertSame('trial', (string) $trial->plan);
        self::assertSame(date('Y-m-d', strtotime('+14 days')), (string) $trial->expiry_date);
        self::assertSame(1, (int) self::$pdo->query("SELECT email_verified FROM tblusers WHERE id = {$r['user_id']}")->fetchColumn(),
            'verify() must have stamped the account verified before activation');
    }

    // ------------------------------------------------------------------
    // 4. HTTP surface
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRegistrationNowLandsOnVerifyPageNotDashboard(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=1'); // shared php -S server, shared IP
        if (self::bootServer() === null) {
            self::markTestSkipped('test HTTP server unavailable');
        }
        putenv('WPMS_TRIAL_DAYS=14');
        self::$jar = [];

        $page = self::http('GET', '/planner_register.php');
        self::assertSame(200, $page['status']);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
        self::assertNotEmpty($m);

        $res = self::http('POST', '/planner_register.php', [
            'firstname'        => 'Asha',
            'lastname'         => 'Funnel',
            'email'            => self::FIX_EMAIL,
            'phone'            => '0722999001',
            'password'         => 'Sup3rSecret!',
            '_token'           => html_entity_decode($m[1], ENT_QUOTES),
            'register_planner' => '1',
        ]);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('verify_planner.php', $res['location'],
            'registration must route to the check-your-inbox page, not auto-login');
        self::assertStringContainsString('sent=1', $res['location']);

        self::$staffId = (int) self::$pdo->query(
            "SELECT id FROM tblusers WHERE email = '" . self::FIX_EMAIL . "'"
        )->fetchColumn();
        self::assertGreaterThan(0, self::$staffId);

        // The check-inbox page renders with the resend form.
        $sent = self::http('GET', '/verify_planner.php?sent=1&email=' . urlencode(self::FIX_EMAIL));
        self::assertSame(200, $sent['status']);
        self::assertStringContainsString('Resend verification link', $sent['body']);

        // Correct password + UNVERIFIED account → no session, no staff pages.
        $login = self::login(self::FIX_EMAIL, 'Sup3rSecret!');
        self::assertSame(302, $login['status']);
        self::assertStringContainsString('verify_planner.php', $login['location'],
            'an unverified planner must be sent to verification, not inside');
        $probe = self::http('GET', '/admin/dashboard.php');
        self::assertSame(302, $probe['status']);
        self::assertStringNotContainsString('dashboard.php', $probe['location'] ?? '', 'no session must bounce off the staff dashboard');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function makeUnverifiedPlanner(): int
    {
        $p = self::$pdo;
        $p->prepare("INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created, phone, email_verified)
                     VALUES ('Probe', 'Unverified', 'f', ?, ?, ?, 'Wedding Planner', '', '1', '', NOW(), '', 0)")
           ->execute([
               'unv_' . strtolower(bin2hex(random_bytes(4))),
               password_hash('Sup3rSecret!', PASSWORD_DEFAULT),
               self::FIX_EMAIL,
           ]);
        $id = (int) $p->lastInsertId();
        self::$staffId = $id;
        return $id;
    }

    private static function login(string $email, string $pass): array
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        self::assertSame(200, $page['status'], 'login page must render');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
        return self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1] ?? '', ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function bootServer(): ?string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        if (!function_exists('proc_open')) {
            return null;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_sg_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_sg_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 33000 + random_int(0, 8999);
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
                return self::$base;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        return null;
    }

    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

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
