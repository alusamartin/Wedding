<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Self-serve M-Pesa deposit flow (admin/client_deposit.php) against a real
 * php -S server:
 *
 *  1. IDOR gate — a foreign booking bounces to client.php before any insert.
 *  2. CSRF — a tokenless POST is rejected and creates no ledger row.
 *  3. Render — the owning couple sees the deposit form + CSRF field.
 *  4. Happy path — a valid deposit creates a cash-neutral ledger row with a
 *     unique 10-char STK-style reference that shows up on My Payments, and
 *     only the M-Pesa (not cash) totals move.
 *
 * Uses the seeded couple brian.wafula@gmail.com (user 200) whose booking 200
 * has seeded ceremonies (the ledger links every deposit to a real event);
 * faith's booking 201 is the foreign booking. Fixture rows are always
 * removed, so the seed state is preserved after a run.
 */
final class ClientDepositFlowTest extends TestCase
{
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const OWN_BOOKING  = 200;
    private const OWN_USER     = 200;
    private const FOREIGN_BOOK = 201;

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

        // Required seed facts: ownership, and at least one ceremony for booking 200.
        try {
            $own = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id = :b AND user_id = :u', [':b' => self::OWN_BOOKING, ':u' => self::OWN_USER]);
            $for = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id = :b', [':b' => self::FOREIGN_BOOK]);
            $ev  = (int) self::$db->fetchValue('SELECT COUNT(*) FROM events WHERE booking_id = :b', [':b' => self::OWN_BOOKING]);
            $has = (bool) self::$db->fetchValue('SELECT COUNT(*) FROM tblaccounts WHERE user_email = :e', [':e' => self::COUPLE_EMAIL]);
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if ($own < 1 || $for < 1 || $ev < 1 || !$has) {
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
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_mp_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_mp_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 32000 + random_int(0, 8999);
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
            self::markTestSkipped('php -S server / v2.1 seed couple unavailable — deposit flow tests skipped');
        }
    }

    private function depositUrl(int $booking = self::OWN_BOOKING, int $user = self::OWN_USER): string
    {
        return '/admin/client_deposit.php?booking_id=' . $booking . '&user_id=' . $user;
    }

    #[Group('integration')]
    public function testForeignBookingIsBouncedBeforeAnyInsert(): void
    {
        $this->requireHttp();

        $res = self::http('POST', $this->depositUrl(self::FOREIGN_BOOK, self::OWN_USER), [
            'amount'   => '2500',
            'event_id' => '200',
            'phone'    => '0712345678',
            'submit'   => '1',
        ]);
        self::assertSame(302, $res['status'], 'Foreign booking must be bounced');
        self::assertStringContainsString('client.php', $res['location'] ?? '', 'Foreign booking must redirect to the client dashboard');

        // No row may exist for the foreign booking from this attempt.
        $n = self::$db->fetchValue('SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b', [':b' => self::FOREIGN_BOOK]);
        self::assertSame((int) $n, $this->baselineCount(self::FOREIGN_BOOK), 'No deposit row may be inserted for a foreign booking');
    }

    #[Group('integration')]
    public function testDepositWithoutCsrfIsRejected(): void
    {
        $this->requireHttp();

        $before = $this->baselineCount(self::OWN_BOOKING);

        $res = self::http('POST', $this->depositUrl(), [
            'amount'   => '2500',
            'event_id' => '200',
            'phone'    => '0712345678',
            'submit'   => '1',
        ]);
        self::assertSame(302, $res['status'], 'Tokenless POST must be bounced');
        self::assertStringContainsString('client_deposit.php', $res['location'] ?? '', 'Tokenless POST must return to the deposit page');
        self::assertSame($before, $this->baselineCount(self::OWN_BOOKING), 'No ledger row may be created without a valid CSRF token');
    }

    #[Group('integration')]
    public function testDepositPageRendersForTheOwningCouple(): void
    {
        $this->requireHttp();

        $res = self::http('GET', $this->depositUrl());
        self::assertSame(200, $res['status'], 'Deposit page must render for the owning couple');
        self::assertStringContainsString('name="_token"', $res['body'], 'Deposit form must carry a CSRF token');
        self::assertStringContainsString('M-Pesa', $res['body'], 'Deposit page must mention M-Pesa');
        self::assertStringContainsString('Balance Due', $res['body'], 'The balance-due stat must render');
        self::assertStringContainsString('WED-200', $res['body'], 'The Paybill account reference must render');
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $res['body'], 'Deposit page must not leak PHP issues (' . $marker . ')');
        }
    }

    #[Group('integration')]
    public function testValidDepositCreatesCashNeutralRowWithStkReference(): void
    {
        $this->requireHttp();

        $mpesaBefore = (float) (Liquidation::getTotalsByMethod(self::OWN_BOOKING)['mpesa_total'] ?? 0);
        $cashBefore  = (float) (Liquidation::getTotalsByMethod(self::OWN_BOOKING)['cash_total'] ?? 0);
        $countBefore = $this->baselineCount(self::OWN_BOOKING);

        // Fresh CSRF token from the rendered form.
        $page = self::http('GET', $this->depositUrl());
        if (!preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::fail('Deposit form did not expose a CSRF token');
        }

        $res = self::http('POST', $this->depositUrl(), [
            '_token'   => html_entity_decode($m[1], ENT_QUOTES),
            'amount'   => '2500',
            'event_id' => '200', // seeded ceremony of booking 200
            'phone'    => '0712345678',
            'submit'   => '1',
        ]);
        self::assertSame(302, $res['status'], 'Valid deposit must redirect');
        self::assertStringContainsString('client_budget.php', $res['location'] ?? '', 'Valid deposit must land on My Payments');

        // The ledger row must exist, be M-Pesa-only, and carry a valid unique reference.
        $rows = self::$db->fetchAll(
            'SELECT * FROM tbl_liquidation WHERE booking_id = :b AND payment = 2500.00 ORDER BY id DESC LIMIT 1',
            [':b' => self::OWN_BOOKING]
        );
        self::assertNotEmpty($rows, 'A ledger row for the deposit must exist');
        $row = is_array($rows[0]) ? (object) $rows[0] : $rows[0];
        self::assertSame(0.0, (float) $row->cash, 'M-Pesa deposit must be cash-neutral');
        self::assertSame(0.0, (float) $row->credit, 'M-Pesa deposit must not create credit');
        self::assertMatchesRegularExpression('/^QDL[A-Z0-9]{7}$/', (string) $row->mpesa_code, 'Reference must be a 10-char STK-style code');
        self::assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tbl_liquidation WHERE mpesa_code = :c AND id <> :id',
            [':c' => $row->mpesa_code, ':id' => (int) $row->id]
        ), 'Reference must be unique in the ledger');

        // Totals: only M-Pesa moves; cash untouched (cash neutrality).
        $mpesaAfter = (float) (Liquidation::getTotalsByMethod(self::OWN_BOOKING)['mpesa_total'] ?? 0);
        $cashAfter  = (float) (Liquidation::getTotalsByMethod(self::OWN_BOOKING)['cash_total'] ?? 0);
        self::assertEqualsWithDelta($mpesaBefore + 2500.0, $mpesaAfter, 0.001, 'Deposit must add to the M-Pesa total');
        self::assertEqualsWithDelta($cashBefore, $cashAfter, 0.001, 'Deposit must not touch the cash total');
        self::assertSame($countBefore + 1, $this->baselineCount(self::OWN_BOOKING), 'Exactly one ledger row added');

        // The reference must be visible on My Payments.
        $payments = self::http('GET', '/admin/client_budget.php?booking_id=' . self::OWN_BOOKING . '&user_id=' . self::OWN_USER);
        self::assertSame(200, $payments['status']);
        self::assertStringContainsString((string) $row->mpesa_code, $payments['body'], 'The STK reference must appear on My Payments');

        // Cleanup — restore the seed state.
        self::$db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => (int) $row->id]);
    }

    private function baselineCount(int $bookingId): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b', [':b' => $bookingId]);
    }
}