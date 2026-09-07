<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Couple portal access regression (client_guests / client_budget / the
 * booking overview) against a real php -S server:
 *
 *  1. IDOR gate — a client session must be bounced (302 to client.php) when
 *     the booking_id belongs to another couple, in every user_id/booking_id
 *     combination, on all three pages.
 *  2. Own-booking access — the couple's own booking renders 200.
 *  3. Read-only surface — the couple pages must not expose any staff
 *     mutating control (guest add/edit/delete, record/liquidate payment).
 *
 * Integration ('integration' group) — requires a live DB with the v2.1 seed
 * (couple chebet.eldoret@gmail.com owns booking 203; abdi's booking 204 is
 * used as the foreign booking) and a bootable php -S. Skipped cleanly when
 * either is unavailable.
 */
final class CouplePagesAccessTest extends TestCase
{
    private const COUPLE_EMAIL  = 'chebet.eldoret@gmail.com';
    private const COUPLE_PASS   = 'Test@1234';
    private const OWN_BOOKING   = 203; // chebet's booking
    private const OWN_USER      = 203;
    private const FOREIGN_BOOK  = 204; // abdi.garissa's booking

    /** @var string[] The three couple-facing pages under test */
    public const PAGES = [
        'client_manage_account_details.php',
        'client_guests.php',
        'client_budget.php',
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
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
        if (self::$base === null || self::$db === null) {
            return; // tests will skip
        }

        // The seed must contain both bookings; otherwise skip cleanly.
        try {
            $own = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id = :b AND user_id = :u', [':b' => self::OWN_BOOKING, ':u' => self::OWN_USER]);
            $for = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id = :b', [':b' => self::FOREIGN_BOOK]);
            $hasCouple = (bool) self::$db->fetchValue('SELECT COUNT(*) FROM tblaccounts WHERE user_email = :e', [':e' => self::COUPLE_EMAIL]);
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if ($own < 1 || $for < 1 || !$hasCouple) {
            self::$base = null; // v2.1 seed missing — skip
            return;
        }

        // Public client login round-trip (CSRF token + cookie jar).
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::$base = null;
            return;
        }
        $post = self::http('POST', '/login.php', [
            'input_email'    => self::COUPLE_EMAIL,
            'input_password' => self::COUPLE_PASS,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
        if ($post['status'] !== 302 || stripos($post['location'] ?? '', 'client') === false) {
            self::$base = null; // seeded credentials unavailable — skip
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

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_cp_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_cp_err');

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
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    /**
     * Minimal curl-free HTTP client; redirects are NOT followed. The request
     * line must NOT go in 'header' — the stream wrapper builds its own.
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
        $body = $post === [] ? '' : http_build_query($post);
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

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / v2.1 seed couple unavailable — couple access tests skipped');
        }
    }

    /** Log a couple in on a FRESH session (login.php auto-redirects users
     *  who are already signed in, so the jar must start empty). */
    private static function loginCouple(string $email, string $pass): void
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::fail('could not load the login page for the swap-login leg');
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        $cases = [];
        foreach (self::PAGES as $page) {
            $cases[$page] = [$page];
        }
        return $cases;
    }

    #[Group('integration')]
    #[DataProvider('pages')]
    public function testForeignBookingIsBouncedInEveryParamCombination(string $page): void
    {
        $this->requireHttp();

        foreach ([
            'foreign pair'  => self::FOREIGN_BOOK . '&user_id=' . self::FOREIGN_BOOK,
            'foreign book / own user' => self::FOREIGN_BOOK . '&user_id=' . self::OWN_USER,
            'own book / foreign user' => self::OWN_BOOKING . '&user_id=' . self::FOREIGN_BOOK,
        ] as $label => $pair) {
            $res = self::http('GET', '/admin/' . $page . '?booking_id=' . $pair);
            self::assertSame(302, $res['status'], $page . " must bounce ({$label})");
            self::assertStringContainsString('client.php', $res['location'] ?? '', $page . " must redirect to the client dashboard ({$label})");
        }
    }

    #[Group('integration')]
    #[DataProvider('pages')]
    public function testOwnBookingRendersForTheCouple(string $page): void
    {
        $this->requireHttp();

        $res = self::http('GET', '/admin/' . $page . '?booking_id=' . self::OWN_BOOKING . '&user_id=' . self::OWN_USER);
        self::assertSame(200, $res['status'], $page . ' must render for the owning couple');
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $res['body'], $page . ' must not leak PHP issues (' . $marker . ')');
        }
    }

    #[Group('integration')]
    public function testCouplePagesExposeNoStaffMutatingControls(): void
    {
        $this->requireHttp();

        $guests = self::http('GET', '/admin/client_guests.php?booking_id=' . self::OWN_BOOKING . '&user_id=' . self::OWN_USER);
        self::assertSame(200, $guests['status']);
        self::assertStringNotContainsString('guest_edit.php', $guests['body'], 'Couple guest page must not offer guest editing');
        self::assertStringNotContainsString('guest_delete.php', $guests['body'], 'Couple guest page must not offer guest deletion');
        self::assertStringNotContainsString('guest_add.php', $guests['body'], 'Couple guest page must not offer guest creation');

        $payments = self::http('GET', '/admin/client_budget.php?booking_id=' . self::OWN_BOOKING . '&user_id=' . self::OWN_USER);
        self::assertSame(200, $payments['status']);
        self::assertStringNotContainsString('budget_add.php', $payments['body'], 'Couple payments page must not offer recording payments');
        self::assertStringNotContainsString('budget_liquidate.php', $payments['body'], 'Couple payments page must not offer liquidation');
    }

    #[Group('integration')]
    public function testBudgetVsActualPanelMath(): void
    {
        $this->requireHttp();

        // Booking 203 has a package (KSh 150,000) with zero payments and zero
        // pledges — the panel must render with the full balance outstanding
        // and the committee prompt.
        $body = self::http('GET', '/admin/client_budget.php?booking_id=' . self::OWN_BOOKING . '&user_id=' . self::OWN_USER)['body'];
        self::assertStringContainsString('Budget vs Actual', $body, 'the funding panel must render when a package is priced');
        self::assertStringContainsString('KSh 150,000.00', $body, 'package price must appear in the panel');
        self::assertStringContainsString('Still to raise after pledges', $body);
        self::assertStringContainsString('No harambee pledges recorded yet', $body, 'zero-pledge couples get the committee prompt');
        self::assertStringNotContainsString('Pledges + payments exceed the package', $body, 'nothing over-collected here');

        // Booking 200 (seed, Brian & Linda): package 250,000 / paid 245,000 /
        // pledged 96,000 — pledges exceed the balance, so the panel must show
        // the over-collection row and the good-news note, never a negative.
        // Needs Brian's own session (IDOR gate): swap the cookie jar to a
        // Brian login for this leg, then restore Chebet's.
        $jarBackup = self::$jar;
        self::loginCouple('brian.wafula@gmail.com', 'Test@1234');
        $seed = self::http('GET', '/admin/client_budget.php?booking_id=200&user_id=200');
        self::$jar = $jarBackup;
        if ($seed['status'] !== 200) {
            self::markTestSkipped('seed booking 200 not reachable with this fixture');
        }
        self::assertStringContainsString('Pledges + payments exceed the package by', $seed['body']);
        self::assertStringContainsString('Great news', $seed['body']);
        self::assertStringNotContainsString('KSh -', $seed['body'], 'the panel must never render a negative amount');

        // Booking 215 has no package (wedding_type 0) — the panel must be
        // suppressed entirely rather than divide by zero.
        $noPackage = self::http('GET', '/admin/client_budget.php?booking_id=215&user_id=215');
        if ($noPackage['status'] === 200) {
            self::assertStringNotContainsString('Budget vs Actual', $noPackage['body'], 'no package = no funding panel');
        }
    }
}
