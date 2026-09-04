<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Public sign-up form guards (index.php + sign_up.php) against a real php -S
 * server:
 *
 *  1. Both forms embed native client-side validation — every field required,
 *     email/phone typed, password minlength 8, plus the wedding-date guard —
 *     so invalid submissions are blocked in the browser instead of bouncing
 *     silently server-side.
 *  2. A tokenless sign-up POST is rejected (302 back to the form) and never
 *     creates an account.
 *
 * Integration ('integration' group) — requires a bootable php -S and (for the
 * DB assertion) a live Database. Skipped cleanly when unavailable.
 */
final class SignupFormValidationTest extends TestCase
{
    /** @var array{0: string, 1: array<string, string>} cases: page => [page, markers] */
    public const CASES = [
        'index.php'    => [
            'index.php',
            [
                'id="user_firstname" required',
                'id="user_lastname"',
                'type="email" class="form-control" name="user_email" id="user_email" placeholder="youremailaddress@mail.com" required',
                'id="user_phone" placeholder="Contact Number (e.g. 0712 345 678)" required',
                'autocomplete="new-password" minlength="8" required',
                'id="wedding_date" required',
                'Please pick your wedding date from the calendar (MM/DD/YYYY).',
            ],
        ],
        'sign_up.php'  => [
            'sign_up.php',
            [
                'id="user_firstname" required',
                'id="user_lastname"',
                'type="email" class="form-control" name="user_email" id="user_email" placeholder="youremailaddress@mail.com" required',
                'id="user_phone" placeholder="Contact Number (e.g. 0712 345 678)" required',
                'autocomplete="new-password" minlength="8" required',
                'id="wedding_date" required',
                'Please pick your wedding date from the calendar (MM/DD/YYYY).',
            ],
        ],
    ];

    private static ?Database $db = null;
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
            $db = self::$db = new Database();
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

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_sf_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_sf_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 41000 + random_int(0, 8999);
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
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    /**
     * Minimal curl-free HTTP client; redirects are NOT followed.
     *
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
        }
        $body   = $post === [] ? '' : http_build_query($post);
        $header = 'Host: 127.0.0.1' . "\r\n"
            . $cookieHeader
            . ($post !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close";

        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => $header,
            'content'         => $body,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 10,
        ]]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);
        $headers  = $http_response_header ?? [];

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

    private function requireServer(): void
    {
        if (self::$base === null) {
            self::markTestSkipped('php -S server unavailable — sign-up form guard tests skipped');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        $cases = [];
        foreach (array_keys(self::CASES) as $page) {
            $cases[$page] = [$page];
        }
        return $cases;
    }

    #[Group('integration')]
    #[DataProvider('pages')]
    public function testSignupFormEmbedsNativeClientSideGuards(string $page): void
    {
        $this->requireServer();

        $res = self::http('GET', '/' . $page);
        self::assertSame(200, $res['status'], $page . ' must render');

        // The actual sign-up card, not the login modal elsewhere in the DOM.
        $formStart = strpos($res['body'], 'form class="bgact"');
        self::assertNotFalse($formStart, $page . ' must contain the sign-up form');
        $form = substr($res['body'], $formStart);

        foreach (self::CASES[$page][1] as $marker) {
            self::assertStringContainsString($marker, $form, $page . ' sign-up form must carry: ' . $marker);
        }
        // All six inputs must be required regardless of attribute ordering.
        self::assertGreaterThanOrEqual(6, substr_count($form, ' required'), $page . ' sign-up form must mark every field required');
        // The date guard script must run on this page.
        self::assertStringContainsString('Please pick your wedding date from the calendar (MM/DD/YYYY).', $res['body'], $page . ' must load the wedding-date guard message');
        self::assertStringContainsString('dateIsValid(val)', $res['body'], $page . ' must load the wedding-date guard logic');
    }

    #[Group('integration')]
    public function testTokenlessSignupPostIsRejectedWithoutCreatingAnAccount(): void
    {
        $this->requireServer();

        $email = 'csrfnone@example.com';
        $post  = self::http('POST', '/sign_up.php', [
            'register'       => 'Sign Up',
            'user_firstname' => 'Csrf',
            'user_lastname'  => 'None',
            'user_email'     => $email,
            'user_phone'     => '0712000999',
            'user_password'  => 'CsrfNone123!',
            'wedding_date'   => date('m/d/Y', strtotime('+300 days')),
        ]);
        self::assertSame(302, $post['status'], 'tokenless POST must be rejected (no CSRF token posted)');
        self::assertStringContainsString('sign_up.php', $post['location'] ?? '', 'tokenless POST must bounce back to the sign-up page');

        if (self::$db === null) {
            self::markTestSkipped('DB unavailable — skipping no-account-created assertion');
        }
        try {
            $count = (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tblaccounts WHERE user_email = :e',
                [':e' => $email]
            );
        } catch (Throwable $e) {
            self::markTestSkipped('DB query failed — skipping no-account-created assertion');
            return;
        }
        self::assertSame(0, $count, 'a rejected sign-up POST must never create an account');
    }
}
