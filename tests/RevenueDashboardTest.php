<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/Monetization.php';

/**
 * Revenue dashboard (admin/revenue.php + Revenue class).
 *
 * Integration ('integration' group) — requires the live seeded DB and a
 * bootable php -S server, like the other page tests. Probe revenue rows are
 * tagged '[rev-test]' in the message/notes fields and always removed in
 * tearDownAfterClass, so the seeded demo ledger is never touched.
 *
 * Covered:
 *  1. Access gate — anonymous visitors bounce; couples bounce.
 *  2. Page renders — lifetime cards, month table (12 rows), drill-downs,
 *     and probe rows appear with the seeded M-Pesa codes.
 *  3. Revenue::monthly_totals / lifetime_totals agree with direct SQL,
 *     including a probe subscription/commission/SMS in the current month.
 */
final class RevenueDashboardTest extends TestCase
{
    private const STAFF_EMAIL  = 'wafula@wpms.co.ke';
    private const STAFF_PASS   = 'Admin@2024!';
    private const CLIENT_EMAIL = 'brian.wafula@gmail.com';
    private const CLIENT_PASS  = 'Test@1234';
    private const TAG          = '[rev-test]';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var array<string, int> */
    private static array $probe = ['subscription' => 0, 'commission' => 0, 'sms' => 0];

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
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            foreach (self::$probe as $kind => $id) {
                if ($id <= 0) {
                    continue;
                }
                try {
                    self::$db->execute(match ($kind) {
                        'subscription' => 'DELETE FROM tblsubscriptions WHERE id = :id',
                        'commission'   => 'DELETE FROM tblvendor_bookings WHERE id = :id',
                        'sms'          => 'DELETE FROM tblsms_logs WHERE id = :id',
                    }, [':id' => $id]);
                } catch (Throwable $e) {
                    // leave state untouched on failure
                }
            }
        }
        self::$probe = ['subscription' => 0, 'commission' => 0, 'sms' => 0];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ------------------------------------------------------------------
    // Server boot + HTTP client (stream-based, same pattern as the
    // StaffNotificationsPageTest client — no curl dependency)
    // ------------------------------------------------------------------

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_rev_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_rev_err');

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
                $probeBody = @file_get_contents(self::$base . '/index.php', false, $ctx);
                if ($probeBody === false) {
                    proc_terminate(self::$serverProc);
                    proc_close(self::$serverProc);
                    self::$serverProc = null;
                    self::$base = null;
                    continue;
                }
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    /**
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string, content_type:string, disposition:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
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
        $contentType = '';
        $disposition = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Content-Type:') === 0) {
                $contentType = trim(substr($h, 13));
            } elseif (stripos($h, 'Content-Disposition:') === 0) {
                $disposition = trim(substr($h, 20));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response,
                'content_type' => $contentType, 'disposition' => $disposition];
    }

    private static function login(string $email, string $pass): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / live DB unavailable — revenue dashboard tests skipped');
        }
    }

    // ------------------------------------------------------------------
    // Probe revenue rows (current month, tagged for cleanup)
    // ------------------------------------------------------------------

    private static function seedProbes(): void
    {
        global $db;
        if (self::$probe['subscription'] === 0) {
            $stmt = $db->prepare(
                "INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status)
                 VALUES (5, 'professional', 7777.00, 'QGHREVTEST1', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active')"
            );
            $stmt->execute();
            self::$probe['subscription'] = (int) $db->the_insert_id();
        }
        if (self::$probe['commission'] === 0) {
            $stmt = $db->prepare(
                "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
                 VALUES (200, 200, NULL, NULL, 60000.00, 1234.00, 'confirmed', :notes)"
            );
            $stmt->execute([':notes' => self::TAG . ' revenue probe']);
            self::$probe['commission'] = (int) $db->the_insert_id();
        }
        if (self::$probe['sms'] === 0) {
            $stmt = $db->prepare(
                "INSERT INTO tblsms_logs (user_id, booking_id, phone, message, cost, status, sent_at)
                 VALUES (5, 200, '254700000099', :msg, 4.00, 'sent', NOW())"
            );
            $stmt->execute([':msg' => self::TAG . ' revenue probe SMS']);
            self::$probe['sms'] = (int) $db->the_insert_id();
        }
    }

    // ------------------------------------------------------------------
    // Unit-ish tests (live DB, no HTTP)
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testMonthlyTotalsMatchesDirectSql(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — revenue aggregation tests skipped');
        }
        global $db;
        self::seedProbes();

        $expected = (float) $db->fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM tblsubscriptions WHERE status IN ('active','expired')
             AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"
        );
        $rows  = Revenue::monthly_totals();
        $curYm = date('Y-m');
        self::assertArrayHasKey($curYm, $rows, 'the current month must always be on the axis');
        self::assertEqualsWithDelta($expected, $rows[$curYm]['subscriptions'], 0.01);

        // Every stream sums to a non-negative number and total = sum of parts.
        foreach ($rows as $ym => $row) {
            self::assertGreaterThanOrEqual(0.0, $row['subscriptions']);
            self::assertGreaterThanOrEqual(0.0, $row['commissions']);
            self::assertGreaterThanOrEqual(0.0, $row['sms']);
            self::assertEqualsWithDelta(
                $row['subscriptions'] + $row['commissions'] + $row['sms'],
                $row['total'],
                0.001,
                "total for {$ym} must equal the sum of its streams"
            );
        }
        self::assertCount(12, $rows, 'the axis must be a continuous 12-month window');
    }

    #[Group('integration')]
    public function testLifetimeTotalsIncludeProbes(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — revenue aggregation tests skipped');
        }
        self::seedProbes();
        $t = Revenue::lifetime_totals();
        self::assertGreaterThan(0.0, $t['commissions'], 'probe commission must lift the total');
        self::assertGreaterThan(0.0, $t['subscriptions']);
        self::assertGreaterThan(0.0, $t['sms']);
        self::assertEqualsWithDelta($t['subscriptions'] + $t['commissions'] + $t['sms'], $t['total'], 0.01);
    }

    #[Group('integration')]
    public function testStreamRowsIncludeProbesAndJoinNames(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — revenue stream tests skipped');
        }
        self::seedProbes();

        $subs = Revenue::stream_rows('subscriptions');
        self::assertNotEmpty($subs);
        $found = false;
        foreach ($subs as $row) {
            if ((int) $row->id === self::$probe['subscription']) {
                $found = true;
                self::assertSame('professional', $row->plan);
                self::assertSame('QGHREVTEST1', $row->mpesa_code);
            }
        }
        self::assertTrue($found, 'probe subscription must appear in the drill-down');

        $sms = Revenue::stream_rows('sms');
        $found = false;
        foreach ($sms as $row) {
            if ((int) $row->id === self::$probe['sms']) {
                $found = true;
                self::assertStringContainsString(self::TAG, $row->message);
            }
        }
        self::assertTrue($found, 'probe SMS must appear in the drill-down');
    }

    // ------------------------------------------------------------------
    // Page tests (HTTP)
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRevenuePageIsStaffOnly(): void
    {
        $this->requireHttp();

        // Anonymous visitors bounce to the site root.
        self::$jar = [];
        $res = self::http('GET', '/admin/revenue.php');
        self::assertSame(302, $res['status'], 'anonymous visitors must bounce off the revenue page');
        self::assertStringStartsWith('../', $res['location'] ?? '', 'the bounce must stay on-site');

        // Signed-in couples bounce too — the platform ledger is staff-only.
        self::login(self::CLIENT_EMAIL, self::CLIENT_PASS);
        self::$jar = array_intersect_key(self::$jar, self::$jar); // keep cookies
        $res = self::http('GET', '/admin/revenue.php');
        self::assertSame(302, $res['status'], 'clients must bounce off the revenue page');
        self::assertStringStartsWith('../', $res['location'] ?? '', 'client bounce must stay on-site');

        // Restore staff session for the remaining tests.
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $ok = self::http('GET', '/admin/revenue.php');
        self::assertSame(200, $ok['status'], 'staff must reach the revenue page (200)');
    }

    #[Group('integration')]
    public function testRevenuePageRendersTotalsMonthTableAndDrilldowns(): void
    {
        $this->requireHttp();
        self::seedProbes();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        if (self::$jar === []) {
            self::markTestSkipped('staff login unavailable — seeded credentials missing');
        }

        $body = self::http('GET', '/admin/revenue.php')['body'];
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $body, 'revenue page must not leak PHP issues');
        }
        self::assertStringContainsString('Revenue', $body);
        self::assertStringContainsString('Lifetime revenue', $body);
        self::assertStringContainsString('Vendor commissions', $body);
        self::assertStringContainsString('Month by month', $body);
        self::assertStringContainsString(date('F Y'), $body, 'the current month must be labelled');

        // 12 month rows: the current month is the only one with the 'now' badge.
        self::assertSame(1, substr_count($body, 'font-size:9px;">now</span>'), 'exactly one month row is the current month');

        // Seeded demo subscription data renders in its drill-down, with the probe.
        $subs = self::http('GET', '/admin/revenue.php?stream=subscriptions')['body'];
        self::assertStringContainsString('QGH1000001', $subs, 'seeded M-Pesa subscription codes must render in the drill-down');
        self::assertStringContainsString('QGHREVTEST1', $subs, 'probe subscription must render in the drill-down');

        $bad = self::http('GET', '/admin/revenue.php?stream=bogus')['body'];
        self::assertStringNotContainsString('Fatal error:', $bad, 'unknown streams must render the info panel, not crash');

        $comm = self::http('GET', '/admin/revenue.php?stream=commissions')['body'];
        self::assertStringContainsString('Vendor commissions', $comm, 'commission drill-down must render');
        self::assertStringContainsString('Nyama Choma Masters', $comm, 'seeded vendor deals must render');

        $sms = self::http('GET', '/admin/revenue.php?stream=sms')['body'];
        self::assertStringContainsString(self::TAG, $sms, 'probe SMS must render in the drill-down');
    }

    /**
     * First CSV line (BOM stripped) as parsed cells — fputcsv quotes fields
     * containing spaces, so raw-string header matching can never hold.
     *
     * @return list<string>
     */
    private static function headerCells(string $body): array
    {
        $firstLine = strtok(ltrim($body, "\xEF\xBB\xBF \t\r\n"), "\r\n");
        return $firstLine === false ? [] : array_map(
            static fn ($cell): string => (string) $cell,
            str_getcsv($firstLine)
        );
    }

    #[Group('integration')]
    public function testCsvExportStreamsEachStreamWithHeadersAndBom(): void
    {
        $this->requireHttp();
        self::seedProbes();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // ---- subscriptions export ---------------------------------------
        $res = self::http('GET', '/admin/revenue.php?stream=subscriptions&export=1');
        self::assertSame(200, $res['status']);
        self::assertStringContainsString('text/csv', $res['content_type'] ?? '', 'export must be served as text/csv');
        self::assertStringContainsString('attachment; filename="everafter-subscriptions-', $res['disposition'] ?? '', 'export must carry a download filename');
        self::assertStringStartsWith("\xEF\xBB\xBF", $res['body'], 'CSV must start with a UTF-8 BOM for Excel');
        self::assertSame(['Planner', 'Email', 'Plan', 'Amount (KSh)', 'M-Pesa code', 'Start', 'Expiry', 'Status'],
            self::headerCells($res['body']), 'header row must be present (exact, in order)');
        self::assertStringContainsString('QGHREVTEST1', $res['body'], 'probe subscription must be exported');

        // Parseable: header + at least one data row, same column count each.
        $lines = array_values(array_filter(explode("\n", trim($res['body']))));
        self::assertGreaterThanOrEqual(2, count($lines), 'export must contain data rows, not only a header');
        $cols = count(str_getcsv($lines[0]));
        self::assertSame(8, $cols, 'subscriptions export must have 8 columns');
        foreach (array_slice($lines, 1) as $line) {
            self::assertSame($cols, count(str_getcsv($line)), 'every row must match the header column count');
        }

        // ---- commissions + sms exports render with their own headers ------
        // fputcsv quotes header fields containing spaces, so headers are
        // asserted as parsed cells (order-exact), not raw line text.
        $comm = self::http('GET', '/admin/revenue.php?stream=commissions&export=1');
        self::assertSame(['Vendor', 'Couple', 'Deal (KSh)', 'Commission (KSh)', 'Event date', 'Status'],
            self::headerCells($comm['body']), 'commissions header row must be exact');

        $sms = self::http('GET', '/admin/revenue.php?stream=sms&export=1');
        self::assertSame(['Sent at', 'To', 'Message', 'Sent by', 'Cost (KSh)'],
            self::headerCells($sms['body']), 'sms header row must be exact');
        self::assertStringContainsString(self::TAG, $sms['body'], 'probe SMS must be exported');
    }

    #[Group('integration')]
    public function testCsvExportIsStaffOnlyAndRejectsUnknownStreams(): void
    {
        $this->requireHttp();

        // Anonymous export bounces off the page gate.
        self::$jar = [];
        $res = self::http('GET', '/admin/revenue.php?stream=sms&export=1');
        self::assertSame(302, $res['status'], 'anonymous users must not download exports');

        // Client (tblaccounts) bounce too.
        self::login(self::CLIENT_EMAIL, self::CLIENT_PASS);
        $res = self::http('GET', '/admin/revenue.php?stream=sms&export=1');
        self::assertSame(302, $res['status'], 'clients must not download exports');

        // Staff with an unknown stream: falls through to the normal HTML page.
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $bad = self::http('GET', '/admin/revenue.php?stream=bogus&export=1');
        self::assertSame(200, $bad['status']);
        self::assertStringContainsString('text/html', $bad['content_type'] ?? '', 'unknown stream must render the page, not an empty CSV');
    }

    #[Group('integration')]
    public function testCsvCellNeutralizesFormulaInjection(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — csv_cell tests skipped');
        }
        self::assertSame("'=cmd|' /C calc'!A0", csv_cell("=cmd|' /C calc'!A0"), 'formula prefix must be neutralized');
        self::assertSame("'+1", csv_cell('+1'));
        self::assertSame("'@SUM", csv_cell('@SUM'));
        self::assertSame("'- payment", csv_cell('- payment'));
        self::assertSame('KSh 4,500 normal', csv_cell('KSh 4,500 normal'), 'ordinary text must pass through untouched');
        self::assertSame("line1\nline2", csv_cell("line1\nline2"), 'internal newlines are quoted by fputcsv, not stripped');
        // Control characters are stripped; the remaining text survives.
        self::assertSame('bad', csv_cell("\0\u{0007}bad"), 'control characters must be stripped');
    }
}
