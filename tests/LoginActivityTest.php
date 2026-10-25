<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Admin Login Security view (admin/login_activity.php) over tbllogin_throttle.
 *
 * End-to-end through a real php -S server:
 *   - anonymous users bounce; staff see the page,
 *   - seeded throttle rows render in the right sections (locked pair, per-IP
 *     pressure, recents) with the right pills,
 *   - the unlock action (POST + CSRF) forgives exactly one pair,
 *   - tokenless POSTs change nothing.
 *
 * Integration ('integration' group) — requires the live DB. All probe rows
 * (and any side effects) are cleaned up in teardown.
 */
final class LoginActivityTest extends TestCase
{
    private const STAFF_EMAIL = 'wafula@wpms.co.ke';
    private const STAFF_PASS  = 'Admin@2024!';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var string[] probe emails created by the current test */
    private static array $probeEmails = [];
    /** @var string[] probe IPs touched by the current test */
    private static array $probeIps = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('LoginThrottle', false)) {
                require_once __DIR__ . '/../admin/include/LoginThrottle.php';
            }
            global $db;
            $db = self::$db = new Database();
            // Probe the table up front so missing-migration installs skip
            // instead of erroring inside every test.
            $db->fetchValue('SELECT COUNT(*) FROM tbllogin_throttle');
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    protected function setUp(): void
    {
        if (!self::$db instanceof Database) {
            $this->markTestSkipped('live DB unavailable');
        }
        if (self::$base === null) {
            $this->markTestSkipped('php -S unavailable (proc_open missing or port boot failed)');
        }
        self::$probeEmails = [];
        self::$probeIps = [];
    }

    protected function tearDown(): void
    {
        if (self::$db !== null && self::$probeEmails !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count(self::$probeEmails), '?'));
                $stmt = self::$db->prepare("DELETE FROM tbllogin_throttle WHERE throttle_email IN ($placeholders)");
                $stmt->execute(self::$probeEmails);
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeEmails = [];
        self::$probeIps = [];
    }

    /** Insert a throttle row exactly as recordFailure() would leave it. */
    private static function seedPair(string $email, string $ip, int $fails, ?int $minutesAgo = null): object
    {
        global $db;
        self::$probeEmails[] = $email;
        self::$probeIps[] = $ip;
        $db->execute(
            'INSERT INTO tbllogin_throttle (throttle_email, throttle_ip, fail_count, updated_at)
             VALUES (:e, :i, :f, COALESCE(:ago, NOW()))
             ON DUPLICATE KEY UPDATE fail_count = :f2, updated_at = COALESCE(:ago2, NOW())',
            [
                ':e' => $email,
                ':i' => $ip,
                ':f' => $fails,
                ':ago' => $minutesAgo === null ? null : date('Y-m-d H:i:s', time() - $minutesAgo * 60),
                ':f2' => $fails,
                ':ago2' => $minutesAgo === null ? null : date('Y-m-d H:i:s', time() - $minutesAgo * 60),
            ]
        );
        return $db->fetchOne(
            'SELECT * FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i',
            [':e' => $email, ':i' => $ip]
        );
    }

    private static function staffCsrfToken(): string
    {
        self::loginStaff();
        $page = self::http('GET', '/admin/login_activity.php');
        self::assertSame(200, $page['status'], 'staff must reach the Login Security page');
        self::assertMatchesRegularExpression('/name="_token" value="([^"]+)"/', $page['body'], 'page must carry a CSRF field');
        return html_entity_decode(self::csrfToken($page['body']), ENT_QUOTES);
    }

    private static function csrfToken(string $body): string
    {
        if (preg_match('/name="_token" value="([^"]+)"/', $body, $m)) {
            return $m[1];
        }
        self::fail('could not extract CSRF token');
    }

    #[Group('integration')]
    public function testAnonymousBouncesAndStaffSeesThePage(): void
    {
        self::$jar = []; // anonymous
        $anon = self::http('GET', '/admin/login_activity.php');
        self::assertSame(302, $anon['status'], 'anonymous users must bounce');
        self::assertSame('../', $anon['location']);

        $page = self::staffPage();
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Login Security', $page['body']);
        self::assertStringContainsString('Locked pairs now', $page['body']);
        self::assertStringContainsString('IP pressure', $page['body']);
        self::assertStringContainsString('Recent failed attempts', $page['body']);
        // The throttle switch badge must render either way.
        self::assertMatchesRegularExpression('/Throttle (ON|OFF)/', $page['body']);
    }

    #[Group('integration')]
    public function testSectionsRenderSeededRowsWithRightPills(): void
    {
        // A pair at MAX_FAILURES inside the window -> LOCKED section.
        $locked = self::seedPair('locked.probe@example.test', '203.0.113.201', LoginThrottle::MAX_FAILURES);
        // A fresh single-failure pair -> recent list only, "watching".
        self::seedPair('watch.probe@example.test', '203.0.113.202', 1);
        // Same IP as the watching pair with enough summed failures -> pressure table, below cap.
        self::seedPair('watch2.probe@example.test', '203.0.113.202', LoginThrottle::MAX_FAILURES_PER_IP - 2);

        $page = self::staffPage();

        // Locked pair surfaces in the Locked pairs section...
        self::assertStringContainsString('Locked pairs', $page['body']);
        self::assertStringContainsString('locked.probe@example.test', $page['body']);
        self::assertStringContainsString('203.0.113.201', $page['body']);
        self::assertStringContainsString('name="action" value="unlock"', $page['body'], 'locked rows must offer Unlock');
        self::assertStringContainsString((string) ceil(LoginThrottle::LOCKOUT_SECONDS / 60) . ' min', $page['body'], 'a fresh lock must show the full window');

        // Recent list: the locked pair still shows its live lock there, the
        // fresh pair shows as merely watching.
        self::assertMatchesRegularExpression('/locked\.probe@example\.test.*?locked \d+m left/s', $page['body']);
        self::assertMatchesRegularExpression('/watch\.probe@example\.test.*?watching/s', $page['body']);

        // Pressure table aggregates the shared IP.
        self::assertStringContainsString('203.0.113.202', $page['body']);
        self::assertStringContainsString('watching', $page['body']);
        self::assertStringContainsString((string) (LoginThrottle::MAX_FAILURES_PER_IP - 1) . ' / ' . LoginThrottle::MAX_FAILURES_PER_IP, $page['body'], 'pressure row must show the summed failures');

        // Recent list shows both probes with per-pair fractions.
        self::assertStringContainsString('watch.probe@example.test', $page['body']);
        self::assertStringContainsString('1 / ' . LoginThrottle::MAX_FAILURES, $page['body']);

        // Sanity: the locked email must not appear in the pressure IP list.
        self::assertStringNotContainsString('203.0.113.203', $page['body']);
    }

    #[Group('integration')]
    public function testUnlockForgivesExactlyOnePair(): void
    {
        global $db;
        self::seedPair('unlock.probe@example.test', '203.0.113.204', LoginThrottle::MAX_FAILURES);
        self::seedPair('other.probe@example.test', '203.0.113.205', LoginThrottle::MAX_FAILURES);

        $token = self::staffCsrfToken();
        $res = self::http('POST', '/admin/login_activity.php', [
            'action' => 'unlock',
            'email'  => 'unlock.probe@example.test',
            'ip'     => '203.0.113.204',
            '_token' => $token,
        ]);
        self::assertSame(302, $res['status'], 'the action must redirect after the flash');

        self::assertNull(
            $db->fetchOne('SELECT * FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i', [':e' => 'unlock.probe@example.test', ':i' => '203.0.113.204']),
            'the unlocked pair row must be gone'
        );
        self::assertNotNull(
            $db->fetchOne('SELECT * FROM tbllogin_throttle WHERE throttle_email = :e', [':e' => 'other.probe@example.test']),
            'other pairs must be untouched'
        );

        // Flash confirms the unlock.
        $flash = self::staffPage()['body'];
        self::assertStringContainsString('Pair unlocked', $flash);
        self::assertStringContainsString('unlock.probe@example.test', $flash);

        // Follow-up: unlock must work even while the pair no longer exists
        // (idempotent, no error) — POST again is safe.
        $again = self::http('POST', '/admin/login_activity.php', [
            'action' => 'unlock', 'email' => 'unlock.probe@example.test', 'ip' => '203.0.113.204', '_token' => $token,
        ]);
        self::assertSame(302, $again['status']);
    }

    #[Group('integration')]
    public function testTokenlessPostChangesNothing(): void
    {
        global $db;
        self::seedPair('guarded.probe@example.test', '203.0.113.206', LoginThrottle::MAX_FAILURES);

        // Be a signed-in staff member BEFORE the tokenless POST. Without this the
        // jar may still be empty (the anonymous test clears it), the POST bounces
        // at the subscription gate instead of the CSRF guard, and both assertions
        // below pass for the wrong reason.
        self::staffCsrfToken();

        $res = self::http('POST', '/admin/login_activity.php', [
            'action' => 'unlock',
            'email'  => 'guarded.probe@example.test',
            'ip'     => '203.0.113.206',
        ]);
        self::assertSame(302, $res['status'], 'tokenless POST must bounce to the page');

        self::assertNotNull(
            $db->fetchOne('SELECT * FROM tbllogin_throttle WHERE throttle_email = :e', [':e' => 'guarded.probe@example.test']),
            'tokenless unlock must not delete the pair'
        );
        // The rejection must reach the operator as a flash on the next page load.
        $page = self::staffPage();
        self::assertSame(200, $page['status'], 'a signed-in staff member must still reach the page');
        self::assertStringContainsString('Invalid security token', $page['body']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Staff page fetch (logs in if the jar is empty or stale). */
    private static function staffPage(): array
    {
        if (!isset(self::$jar['PHPSESSID'])) {
            self::loginStaff();
        }
        $page = self::http('GET', '/admin/login_activity.php');
        if ($page['status'] === 302) {
            self::loginStaff(); // session expired mid-test
            $page = self::http('GET', '/admin/login_activity.php');
        }
        return $page;
    }

    private static function loginStaff(): void
    {
        self::$jar = [];
        $form = self::http('GET', '/login.php');
        self::assertSame(200, $form['status'], 'login form must render');
        self::http('POST', '/login.php', [
            'input_email'    => self::STAFF_EMAIL,
            'input_password' => self::STAFF_PASS,
            '_token'         => html_entity_decode(self::csrfToken($form['body']), ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext/curl required by the test HTTP client');
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
                $location = trim(substr($h, 10));
            }
        }

        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_la_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_la_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            // Boot the server WITHOUT the suite's throttle kill switch so the
            // page's throttle-dependent rendering matches production.
            $env = [];
            foreach (getenv() as $k => $v) {
                if ($k !== 'WPMS_LOGIN_THROTTLE_OFF') {
                    $env[$k] = $v;
                }
            }
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root, $env);
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
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }
}
