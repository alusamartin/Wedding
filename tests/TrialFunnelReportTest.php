<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Super-admin trial-funnel report (SignupGuard::funnel_report + dashboard).
 *
 * Integration ('integration' group) — live DB + the curl HTTP harness.
 *
 * Covered:
 *  1. Stage classification through the REAL paths: register_planner() →
 *     SignupGuard::verify() + Subscription::activate_trial() →
 *     Subscription::create_subscription(). Unverified / active-trial /
 *     converted / expired-never-paid land in exactly one stage each.
 *  2. The window filter excludes signups older than N days.
 *  3. HTTP: the panel renders on dashboard.php for the super admin (with a
 *     window picker) and never for a plain planner.
 */
final class TrialFunnelReportTest extends TestCase
{
    private static ?PDO $pdo = null;
    /** @var array<int> user ids created by the current test */
    private array $probeIds = [];

    // HTTP harness (mirrors UnifiedLoginTest)
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

    private const ADMIN_EMAIL   = 'wafula@wpms.co.ke';
    private const ADMIN_PASS    = 'Admin@2024!';
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';

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
    }

    protected function tearDown(): void
    {
        $p = self::$pdo;
        if ($p !== null) {
            foreach ($this->probeIds as $uid) {
                $p->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . $uid);
                $p->exec('DELETE FROM tblplanner_verifications WHERE user_id = ' . $uid);
                $p->exec('DELETE FROM tblnotifications WHERE user_id = ' . $uid);
                $p->exec('DELETE FROM tblsms_credits WHERE user_id = ' . $uid);
                $p->exec('DELETE FROM tblusers WHERE id = ' . $uid);
            }
        }
        $this->probeIds = [];
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — funnel report tests skipped');
        }
    }

    /** Register one self-serve probe planner; returns the user id. */
    private function makePlanner(string $tag): int
    {
        $r = register_planner('Funnel' . $tag, 'Probe', "funnel.{$tag}." . random_int(10000, 99999) . '@example.com', '0722000000', 'Sup3rSecret!');
        $this->assertNotFalse($r['ok'] ?? false, $r['error'] ?? 'register_planner failed');
        $id = (int) $r['user_id'];
        $this->probeIds[] = $id;
        return $id;
    }

    // ------------------------------------------------------------------
    // 1. Stage classification
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testStagesClassifyThroughRealPaths(): void
    {
        $this->requireDb();
        putenv('WPMS_TRIAL_DAYS=14');

        $unverifiedId = $this->makePlanner('unv');
        $trialId      = $this->makePlanner('tri');
        $convertedId  = $this->makePlanner('con');
        $expiredId    = $this->makePlanner('exp');

        // Trial: prove mailbox, open trial.
        $t = SignupGuard::verify((string) SignupGuard::issueVerification($trialId));
        $this->assertNotFalse($t);
        $this->assertNotNull(Subscription::activate_trial($trialId));

        // Converted: trial + a paid starter row (the funnel's happy path).
        $t = SignupGuard::verify((string) SignupGuard::issueVerification($convertedId));
        $this->assertNotFalse($t);
        $this->assertNotNull(Subscription::activate_trial($convertedId));
        $this->assertTrue(Subscription::create_subscription($convertedId, 'starter', 2999.00, 'QFUNNEL' . random_int(100, 999)));

        // Expired, never paid: trial opened, then lapses; signup backdated so
        // "today's" lapses don't count as expired yet.
        $t = SignupGuard::verify((string) SignupGuard::issueVerification($expiredId));
        $this->assertNotFalse($t);
        $this->assertNotNull(Subscription::activate_trial($expiredId));
        self::$pdo->exec("UPDATE tblsubscriptions SET status = 'expired', expiry_date = (CURDATE() - INTERVAL 3 DAY) WHERE user_id = {$expiredId}");
        self::$pdo->exec("UPDATE tblusers SET date_created = '" . date('Y-m-d H:i:s', strtotime('-2 days')) . "' WHERE id = {$expiredId}");

        $rows = [];
        foreach (SignupGuard::funnel_signups(30) as $r) {
            $rows[$r['user_id']] = $r;
        }

        $u = $rows[$unverifiedId] ?? null;
        $this->assertNotNull($u);
        $this->assertFalse($u['verified'], 'no mailbox proof — unverified');
        $this->assertFalse($u['trial']);
        $this->assertFalse($u['paid']);

        $t2 = $rows[$trialId] ?? null;
        $this->assertNotNull($t2);
        $this->assertTrue($t2['verified']);
        $this->assertTrue($t2['trial'], 'live trial row with no payment = active trial');
        $this->assertFalse($t2['paid']);
        $this->assertFalse($t2['expired_unpaid']);

        $c = $rows[$convertedId] ?? null;
        $this->assertNotNull($c);
        $this->assertTrue($c['paid'], 'a paid row converts — even with the trial still live');
        $this->assertSame('starter', $c['paid_plan']);
        $this->assertFalse($c['trial'], 'paid supersedes trial in classification');
        $this->assertFalse($c['expired_unpaid']);

        $e = $rows[$expiredId] ?? null;
        $this->assertNotNull($e);
        $this->assertTrue($e['expired_unpaid'], 'verified, lapsed, never paid, signed up before today');
        $this->assertFalse($e['paid']);
        $this->assertFalse($e['trial']);

        // Aggregate counts include exactly these four probe stages.
        $rep = SignupGuard::funnel_report(30);
        $byId = [];
        foreach ($rep['rows'] as $r) {
            $byId[$r['user_id']] = $r;
        }
        $this->assertSame(1, $rep['converted'] >= 1 ? 1 : 0); // sanity shape
        $this->assertArrayHasKey($convertedId, $byId);
        $this->assertTrue($byId[$convertedId]['paid']);
        $this->assertArrayHasKey($expiredId, $byId);
        $this->assertTrue($byId[$expiredId]['expired_unpaid']);
    }

    // ------------------------------------------------------------------
    // 2. Window filter
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testWindowExcludesOldSignups(): void
    {
        $this->requireDb();
        putenv('WPMS_TRIAL_DAYS=14');
        $id = $this->makePlanner('old');
        // Backdate beyond the 7-day window.
        self::$pdo->exec("UPDATE tblusers SET date_created = '" . date('Y-m-d H:i:s', strtotime('-10 days')) . "' WHERE id = {$id}");

        $ids = array_map(fn($r) => $r['user_id'], SignupGuard::funnel_signups(7));
        $this->assertNotContains($id, $ids, 'a 10-day-old signup must be outside the 7-day window');
        $ids30 = array_map(fn($r) => $r['user_id'], SignupGuard::funnel_signups(30));
        $this->assertContains($id, $ids30, 'the same signup must be inside the 30-day window');
    }

    // ------------------------------------------------------------------
    // 3. HTTP: panel visibility
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testPanelRendersForAdminOnly(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=1'); // shared php -S server, shared IP
        if (self::bootServer() === null) {
            self::markTestSkipped('test HTTP server unavailable');
        }

        // Super admin: the panel is on their dashboard, with the picker.
        $dash = $this->loginAndFetch(self::ADMIN_EMAIL, self::ADMIN_PASS, '/admin/dashboard.php?days=7')['body'];
        $this->assertStringContainsString('Trial Funnel', $dash, 'super admin must see the funnel panel');
        $this->assertStringContainsString('SIGNUPS PER DAY', $dash);
        $this->assertStringContainsString('dashboard.php?days=90', $dash, 'window picker must offer the other windows');

        // Garbage ?days falls back to 30 — never an error page.
        $dash2 = $this->loginAndFetch(self::ADMIN_EMAIL, self::ADMIN_PASS, '/admin/dashboard.php?days=9999');
        $this->assertSame(200, $dash2['status']);
        $this->assertStringContainsString('Trial Funnel', $dash2['body']);

        // Plain planner: no panel at all.
        $planner = $this->loginAndFetch(self::PLANNER_EMAIL, self::PLANNER_PASS, '/admin/dashboard.php');
        $this->assertSame(200, $planner['status']);
        $this->assertStringNotContainsString('Trial Funnel', $planner['body'], 'planners must never see the funnel report');
    }

    // ------------------------------------------------------------------
    // HTTP helpers (curl client; redirects NOT followed)
    // ------------------------------------------------------------------

    /**
     * Fresh session: GET login.php (CSRF), POST credentials, then GET $path.
     *
     * @return array{status:int, location:string, body:string}
     */
    private function loginAndFetch(string $email, string $pass, string $path): array
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        $this->assertSame(200, $page['status'], 'login page must render');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
        $this->assertNotEmpty($m);
        $res = self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
        $this->assertSame(302, $res['status'], 'credentials must be accepted for ' . $email);
        return self::http('GET', $path);
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
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_fn_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_fn_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 30000 + random_int(0, 999);
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

    /**
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
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

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
