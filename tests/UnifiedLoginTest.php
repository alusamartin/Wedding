<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unified login regressions — one door, role-based landings:
 *
 *  1. A client (tblaccounts) POSTing valid credentials to /login.php lands
 *     on admin/client.php (their bookings).
 *  2. A staff member (tblusers) with access_level 2 (admin) lands on
 *     admin/dashboard.php.
 *  3. A planner (access_level 1) with an active subscription lands on
 *     admin/dashboard.php.
 *  4. Bad credentials bounce back to login.php — never a dashboard.
 *  5. admin/login.php is retired: a permanent 301 to the unified door.
 *
 * Uses the curl HTTP client (stream contexts stall on Windows php -S).
 */
final class UnifiedLoginTest extends TestCase
{
    private const CLIENT_EMAIL = 'brian.wafula@gmail.com';
    private const CLIENT_PASS  = 'Test@1234';
    private const ADMIN_EMAIL  = 'wafula@wpms.co.ke';
    private const ADMIN_PASS   = 'Admin@2024!';
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';

    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            global $db;
            $db = new Database();
        } catch (Throwable $e) {
            $db = null;
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

    #[Group('integration')]
    public function testClientLandsOnTheirBookings(): void
    {
        $res = self::login(self::CLIENT_EMAIL, self::CLIENT_PASS);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('admin/client.php', $res['location']);
    }

    #[Group('integration')]
    public function testAdminLandsOnDashboard(): void
    {
        $res = self::login(self::ADMIN_EMAIL, self::ADMIN_PASS);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('admin/dashboard.php', $res['location']);
        self::assertStringNotContainsString('subscription.php', $res['location'], 'seeded admin has an active subscription');
    }

    #[Group('integration')]
    public function testPlannerLandsOnDashboard(): void
    {
        $res = self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        self::assertSame(302, $res['status']);
        // Planners land on dashboard.php directly or via subscription.php on
        // first login; both are staff chrome, never the client page.
        self::assertMatchesRegularExpression('#admin/(dashboard|subscription)\.php#', $res['location']);
        self::assertStringNotContainsString('admin/client.php', $res['location'], 'staff must not land on the client page');
    }

    #[Group('integration')]
    public function testBadPasswordBouncesBackToLogin(): void
    {
        $res = self::login(self::CLIENT_EMAIL, 'DefinitelyWrong!');
        self::assertSame(302, $res['status']);
        self::assertMatchesRegularExpression('#login\.php$#', $res['location']);
    }

    #[Group('integration')]
    public function testSignedInClientBypassesBothLoginForms(): void
    {
        self::login(self::CLIENT_EMAIL, self::CLIENT_PASS);
        // Unified door: straight to the role home.
        $res = self::http('GET', '/login.php');
        self::assertSame(302, $res['status'], 'signed-in client must not see the login form');
        self::assertStringContainsString('client.php', $res['location']);
        self::assertStringNotContainsString('dashboard.php', $res['location']);
        // Retired alias: 301 to the unified door — a form still never renders.
        $alias = self::http('GET', '/admin/login.php');
        self::assertSame(301, $alias['status']);
        self::assertSame('../login.php', $alias['location']);
    }

    #[Group('integration')]
    public function testSignedInStaffBypassesBothLoginForms(): void
    {
        self::login(self::ADMIN_EMAIL, self::ADMIN_PASS);
        // Unified door: straight to the role home.
        $res = self::http('GET', '/login.php');
        self::assertSame(302, $res['status'], 'signed-in staff must not see the login form');
        self::assertMatchesRegularExpression('#(admin/)?dashboard\.php#', $res['location']);
        self::assertStringNotContainsString('client.php', $res['location']);
        // Retired alias: 301 to the unified door — a form still never renders.
        $alias = self::http('GET', '/admin/login.php');
        self::assertSame(301, $alias['status']);
        self::assertSame('../login.php', $alias['location']);
    }

    #[Group('integration')]
    public function testAdminLoginPageIsRetiredToPermanentRedirect(): void
    {
        self::$jar = []; // anonymous visitor
        $res = self::http('GET', '/admin/login.php');
        self::assertSame(301, $res['status'], 'admin/login.php must be a 301 stub');
        self::assertSame('../login.php', $res['location']);
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /** GET the unified login page for CSRF, then POST credentials. */
    private static function login(string $email, string $pass): array
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        self::assertSame(200, $page['status'], 'unified login page must render');
        self::assertMatchesRegularExpression('/name="_token" value="([^"]+)"/', $page['body']);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);

        return self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_ul_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_ul_err');

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
                self::$serverProc = null;
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
