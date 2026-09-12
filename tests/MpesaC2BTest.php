<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/Database.php';
require_once __DIR__ . '/../admin/include/db_object.php';
require_once __DIR__ . '/../admin/include/Helper.php';
require_once __DIR__ . '/../admin/include/Monetization.php';

/**
 * Daraja C2B callback verification (MpesaC2B + mpesa_c2b.php endpoint).
 *
 * Integration ('integration' group) — requires the live DB (like
 * DeadlineSmsFlowTest). Probe callbacks use TransIDs prefixed 'QTST' /
 * msisdn 254700000888 and are always removed in tearDownAfterClass.
 *
 * Covered:
 *  1. ingest() — full payload round-trip, junk keys refused, replay no-op
 *  2. verify() — amount tolerance (under AND over), account reference,
 *     duplicate-use refusal, case-insensitive codes
 *  3. Short-code pinning — ingest refuses other tills when WPMS_MPESA_SHORTCODE is set
 *  4. Reject-row safety — a failed attempt can never be verified against itself
 *  5. mark_consumed() — binds a callback to a subscription/payment row (and
 *     refuses to bind one twice)
 *  6. record_reject() — rejected attempts land in the review queue
 *  7. mpesa_c2b.php — accepts a real Daraja-shaped POST, rejects unknown keys,
 *     and always answers with the Daraja contract shape (ResultCode)
 */
final class MpesaC2BTest extends TestCase
{
    private static ?PDO $pdo = null;

    /** @var string[] probe TransIDs created by this run */
    private static array $probeCodes = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            global $db;
            $db = new Database();
            self::$pdo->exec(
                'CREATE TABLE IF NOT EXISTS tblmpesa_callbacks (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    trans_type ENUM("Pay Bill","Buy Goods") NOT NULL DEFAULT "Pay Bill",
                    trans_id VARCHAR(20) NOT NULL,
                    trans_time VARCHAR(20) NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    business_short_code VARCHAR(12) NOT NULL,
                    bill_ref_number VARCHAR(100) DEFAULT NULL,
                    msisdn VARCHAR(15) NOT NULL,
                    first_name VARCHAR(100) DEFAULT NULL,
                    middle_name VARCHAR(100) DEFAULT NULL,
                    last_name VARCHAR(100) DEFAULT NULL,
                    org_account_balance VARCHAR(20) DEFAULT NULL,
                    invoice_number VARCHAR(20) DEFAULT NULL,
                    verified_at DATETIME DEFAULT NULL,
                    ref_table VARCHAR(40) DEFAULT NULL,
                    ref_id INT UNSIGNED DEFAULT NULL,
                    reject_reason VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_trans (trans_type, trans_id),
                    KEY idx_verified (verified_at),
                    KEY idx_ref (ref_table, ref_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        self::cleanup();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanup();

        // The spawned php -S child must die with the suite: it inherits this
        // process's stdout, so leaving it alive makes any parent piping our
        // output (composer test, CI) wait on EOF forever.
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
        self::$base = null;
    }

    private static function cleanup(): void
    {
        if (self::$pdo === null) {
            return;
        }
        foreach (self::$probeCodes as $code) {
            self::$pdo->prepare('DELETE FROM tblmpesa_callbacks WHERE trans_id LIKE :c')
                      ->execute([':c' => $code . '%']);
        }
        self::$probeCodes = [];
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — M-Pesa C2B tests skipped');
        }
    }

    /** A valid Daraja C2B confirmation payload. */
    private static function payload(array $overrides = []): array
    {
        return array_merge([
            'TransactionType'          => 'Pay Bill',
            'TransID'                  => 'QTST' . strtoupper(bin2hex(random_bytes(3))),
            'TransTime'                => date('YmdHis'),
            'TransAmount'              => '7999.00',
            'BusinessShortCode'        => '123456',
            'BillRefNumber'            => 'WPMS',
            'InvoiceNumber'            => '',
            'OrgAccountBalance'        => '0.00',
            'ThirdPartyTransactionID'  => '',
            'MSISDN'                   => '254700000888',
            'FirstName'                => 'Test',
            'MiddleName'               => '',
            'LastName'                 => 'Probe',
        ], $overrides);
    }

    private static function ingest(array $payload): array
    {
        global $db;
        $result = MpesaC2B::ingest($payload);
        if (!empty($result['ok']) && ($payload['TransID'] ?? '') !== '') {
            self::$probeCodes[] = strtoupper((string) $payload['TransID']);
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // 1. ingest
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testIngestStoresFullDarajaPayload(): void
    {
        $this->requireDb();
        $p = self::payload();
        $r = self::ingest($p);
        self::assertTrue($r['ok'], 'valid payload must ingest: ' . ($r['error'] ?? ''));

        $row = self::$pdo->prepare('SELECT * FROM tblmpesa_callbacks WHERE trans_id = :c');
        $row->execute([':c' => $p['TransID']]);
        $stored = $row->fetch(PDO::FETCH_OBJ);
        self::assertNotFalse($stored);
        self::assertSame('7999.00', (string) $stored->amount);
        self::assertSame('WPMS', $stored->bill_ref_number);
        self::assertSame('254700000888', $stored->msisdn);
        self::assertNull($stored->verified_at, 'fresh callbacks must be pending, not verified');
    }

    #[Group('integration')]
    public function testIngestRefusesUnknownKeys(): void
    {
        $this->requireDb();
        $r = MpesaC2B::ingest(self::payload(['EvilField' => '<script>']));
        self::assertFalse($r['ok'], 'payloads with unknown keys must be refused');
    }

    #[Group('integration')]
    public function testIngestRefusesMalformedTransTime(): void
    {
        $this->requireDb();
        $r = MpesaC2B::ingest(self::payload(['TransTime' => '2026-09-10 14:30:22']));
        self::assertFalse($r['ok'], 'TransTime must be YYYYMMDDHHmmss');
    }

    #[Group('integration')]
    public function testIngestIsIdempotentOnReplay(): void
    {
        $this->requireDb();
        $p = self::payload();
        $first = self::ingest($p);
        self::assertTrue($first['ok']);
        $second = self::ingest($p); // Safaricom retries the same notification
        self::assertTrue($second['ok'], 'replay must be a no-op, not an error');
    }

    // ------------------------------------------------------------------
    // 2. verify
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testVerifyPassesOnExactMatch(): void
    {
        $this->requireDb();
        $p = self::payload(['TransAmount' => '7999.00', 'BillRefNumber' => 'WPMS']);
        self::ingest($p);
        $v = MpesaC2B::verify($p['TransID'], 7999.00, 'WPMS');
        self::assertTrue($v['verified'], 'exact match must verify: ' . $v['reason']);
        self::assertEqualsWithDelta(7999.00, $v['amount'], 0.01);
    }

    #[Group('integration')]
    public function testVerifyRejectsUnknownCode(): void
    {
        $this->requireDb();
        $v = MpesaC2B::verify('QUNKNOWN99', 7999.00, 'WPMS');
        self::assertFalse($v['verified']);
        self::assertStringContainsString('No matching M-Pesa transaction', $v['reason']);
    }

    #[Group('integration')]
    public function testVerifyRejectsUnderpaymentBeyondTolerance(): void
    {
        $this->requireDb();
        $p = self::payload(['TransAmount' => '5000.00']);
        self::ingest($p);
        $v = MpesaC2B::verify($p['TransID'], 7999.00, 'WPMS');
        self::assertFalse($v['verified'], 'KSh 5,000 payment cannot buy a KSh 7,999 plan');
    }

    #[Group('integration')]
    public function testVerifyAllowsSmallRoundingWithinTolerance(): void
    {
        $this->requireDb();
        $p = self::payload(['TransAmount' => '7999.00']);
        self::ingest($p);
        $v = MpesaC2B::verify($p['TransID'], 8000.00, '');
        self::assertTrue($v['verified'], 'KSh 1 difference (within 1% tolerance) must pass: ' . $v['reason']);
    }

    #[Group('integration')]
    public function testVerifyRejectsWrongAccountReference(): void
    {
        $this->requireDb();
        $p = self::payload(['BillRefNumber' => 'SOMETHINGELSE']);
        self::ingest($p);
        $v = MpesaC2B::verify($p['TransID'], 7999.00, 'WPMS');
        self::assertFalse($v['verified'], 'reference mismatch must fail when expected ref is set');
        // But with no expected ref (booking payments tolerate any ref), it passes.
        $v2 = MpesaC2B::verify($p['TransID'], 7999.00, '');
        self::assertTrue($v2['verified']);
    }

    #[Group('integration')]
    public function testVerifySkipsAlreadyConsumedCallbacks(): void
    {
        $this->requireDb();
        $p = self::payload();
        self::ingest($p);
        $stmt = self::$pdo->prepare('SELECT id FROM tblmpesa_callbacks WHERE trans_id = :c');
        $stmt->execute([':c' => $p['TransID']]);
        $id = (int) $stmt->fetchColumn();

        self::assertTrue(MpesaC2B::mark_consumed($id, 'tblsubscriptions', 999), 'fresh callback must be consumable');
        $v = MpesaC2B::verify($p['TransID'], 7999.00, 'WPMS');
        self::assertFalse($v['verified'], 'a consumed payment cannot verify again');
    }

    #[Group('integration')]
    public function testVerifyRejectsOverpaymentBeyondTolerance(): void
    {
        $this->requireDb();
        // A KSh 12,000 payment cannot silently buy a KSh 7,999 plan — it is
        // probably a different purchase and must be reviewed, not auto-matched.
        $p = self::payload(['TransAmount' => '12000.00']);
        self::ingest($p);
        $v = MpesaC2B::verify($p['TransID'], 7999.00, 'WPMS');
        self::assertFalse($v['verified'], 'overpayment beyond tolerance must not auto-verify');
        self::assertStringContainsString('amount or account reference does not match', $v['reason']);
    }

    #[Group('integration')]
    public function testVerifyMatchesCodesCaseInsensitively(): void
    {
        $this->requireDb();
        // Users type codes in any case; Daraja reports uppercase.
        $p = self::payload();
        self::ingest($p);
        $v = MpesaC2B::verify(strtolower($p['TransID']), 7999.00, 'wpms');
        self::assertTrue($v['verified'], 'lowercased code + reference must still match: ' . $v['reason']);
    }

    #[Group('integration')]
    public function testVerifyNeverMatchesItsOwnRejectRow(): void
    {
        $this->requireDb();
        // Regression: record_reject() stores failed attempts under the typed
        // code with verified_at NULL. verify() must exclude those rows, or a
        // user could fail a check once and pass on retry against their own
        // reject row (amount matches by construction, empty ref passes).
        $code = 'QTSTRJX' . strtoupper(bin2hex(random_bytes(2)));
        self::$probeCodes[] = $code;
        MpesaC2B::record_reject($code, 'regression probe: wrong amount', 7999.00);
        $v = MpesaC2B::verify($code, 7999.00, '');
        self::assertFalse($v['verified'], 'a reject row must never satisfy verify()');
    }

    #[Group('integration')]
    public function testIngestRefusesPinnedShortcodeMismatch(): void
    {
        $this->requireDb();
        putenv('WPMS_MPESA_SHORTCODE=123456');
        try {
            $r = MpesaC2B::ingest(self::payload(['BusinessShortCode' => '999888']));
            self::assertFalse($r['ok'], 'payments for another till must be refused at ingest');
            self::assertTrue(MpesaC2B::ingest(self::payload(['BusinessShortCode' => '123456']))['ok']);
        } finally {
            putenv('WPMS_MPESA_SHORTCODE');
        }
    }

    #[Group('integration')]
    public function testMarkConsumedReportsFailureOnSecondBind(): void
    {
        $this->requireDb();
        $p = self::payload();
        self::ingest($p);
        $stmt = self::$pdo->prepare('SELECT id FROM tblmpesa_callbacks WHERE trans_id = :c');
        $stmt->execute([':c' => $p['TransID']]);
        $id = (int) $stmt->fetchColumn();

        self::assertTrue(MpesaC2B::mark_consumed($id, 'tblsubscriptions', 1001), 'first bind must succeed');
        self::assertFalse(MpesaC2B::mark_consumed($id, 'tbl_liquidation', 2002), 'second bind of the same callback must report failure');
    }

    // ------------------------------------------------------------------
    // 3. record_reject + review queue
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRecordRejectLandsInPendingReview(): void
    {
        $this->requireDb();
        $code = 'QTSTREJ' . strtoupper(bin2hex(random_bytes(2)));
        self::$probeCodes[] = $code;
        MpesaC2B::record_reject($code, 'test reject reason', 7999.00);

        $rows = MpesaC2B::pending_review(500);
        $found = null;
        foreach ($rows as $row) {
            if ($row->trans_id === $code) {
                $found = $row;
            }
        }
        self::assertNotNull($found, 'rejected attempt must appear in the review queue');
        self::assertStringContainsString('test reject reason', (string) $found->reject_reason);
        self::assertNull($found->verified_at);
    }

    #[Group('integration')]
    public function testStatsCountProbes(): void
    {
        $this->requireDb();
        $before = MpesaC2B::stats();
        $p = self::payload();
        self::ingest($p);
        $after = MpesaC2B::stats();
        self::assertSame($before['pending'] + 1, $after['pending'], 'fresh ingest must add exactly one pending row');
    }

    // ------------------------------------------------------------------
    // 4. HTTP endpoint (php -S)
    // ------------------------------------------------------------------

    private static ?string $base = null;
    private static $serverProc = null;

    private static function bootServer(): void
    {
        if (self::$base !== null || !function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_cb_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_cb_err');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            self::$serverProc = @proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
                [0 => ['file', 'NUL', 'r'], 1 => ['file', $logOut, 'w'], 2 => ['file', $logErr, 'w']],
                $pipes,
                $root
            );
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
    }

    /** @return array{status:int, body:string} */
    private static function post(string $path, string $jsonBody): array
    {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nConnection: close",
            'content' => $jsonBody,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $body   = @file_get_contents(self::$base . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    #[Group('integration')]
    public function testCallbackEndpointAcceptsDarajaPostAndAnswersContract(): void
    {
        $this->requireDb();
        self::bootServer();
        if (self::$base === null) {
            self::markTestSkipped('php -S server unavailable — endpoint tests skipped');
        }
        $p = self::payload();
        $res = self::post('/mpesa_c2b.php', json_encode($p));
        self::assertSame(200, $res['status'], 'endpoint must always answer 200');
        $json = json_decode($res['body'], true);
        self::assertIsArray($json, 'response must be JSON');
        self::assertSame(0, $json['ResultCode'] ?? -1, 'valid payload must be accepted');
        self::assertArrayHasKey('ResultDesc', $json);

        // And the payment is immediately verifiable — the whole point.
        $v = MpesaC2B::verify($p['TransID'], 7999.00, 'WPMS');
        self::assertTrue($v['verified'], 'endpoint-ingested payment must verify');
        self::$probeCodes[] = $p['TransID'];
    }

    #[Group('integration')]
    public function testCallbackEndpointRejectsNonJsonBody(): void
    {
        $this->requireDb();
        self::bootServer();
        if (self::$base === null) {
            self::markTestSkipped('php -S server unavailable — endpoint tests skipped');
        }
        $res = self::post('/mpesa_c2b.php', 'not json at all');
        self::assertSame(200, $res['status'], 'endpoint must still answer 200 (never 500)');
        $json = json_decode($res['body'], true);
        self::assertSame(1, $json['ResultCode'] ?? -1, 'non-JSON must be rejected with ResultCode 1');
    }
}
