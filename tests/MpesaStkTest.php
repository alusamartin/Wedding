<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/Database.php';
require_once __DIR__ . '/../admin/include/db_object.php';
require_once __DIR__ . '/../admin/include/Helper.php';
require_once __DIR__ . '/../admin/include/Monetization.php';
require_once __DIR__ . '/../admin/include/Notification.php';

/**
 * STK push (initiated-from-app payment, Daraja Lipa na M-Pesa Online).
 *
 * Integration ('integration' group) — live DB, same pattern as MpesaC2BTest.
 * Fixture booking/user 99xxxx rows are created and always restored; probe
 * pushes are deleted in tearDownAfterClass. The transport is the simulated
 * one (no Daraja credentials in tests), so callbacks are applied directly
 * via MpesaStk::apply_callback() — the exact method mpesa_stk_callback.php
 * calls with Daraja's payload.
 *
 * Covered:
 *  1. initiate() — row creation, phone normalisation, plan/deposit validation
 *  2. apply_callback() — success fulfils exactly once; cancelled/timeout/failed
 *     never fulfil; replays are no-ops; a LATE SUCCESS after a timeout reopens
 *     and fulfils (money actually moved)
 *  3. Subscription fulfilment — plan row created with the Daraja receipt
 *  4. Deposit fulfilment — booking payment row created, receipt recorded
 *  5. cancel_stale() — unanswered pushes expire
 */
final class MpesaStkTest extends TestCase
{
    private const FIX_USER  = 990010;
    private const FIX_BOOK  = 990010;
    private const STAFF_USER = 'probe_stk_staff';

    private static ?PDO $pdo = null;
    private static int $staffId = 0;
    /** @var int[] push ids created by this run */
    private static array $probePushIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/Liquidation.php';
        require_once __DIR__ . '/../admin/include/Booking.php';
        require_once __DIR__ . '/../admin/include/Categories.php';
        require_once __DIR__ . '/../admin/include/Events.php';

        try {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            global $db;
            $db = new Database();
            self::ensureTables();
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        putenv('WPMS_MPESA_STK=1'); // the transport itself stays simulated (no credentials)
        self::cleanupAll();
        self::seedFixture();
        self::seedStaff();
    }

    public static function tearDownAfterClass(): void
    {
        putenv('WPMS_MPESA_STK');
        // Clean, and leave nothing behind: this used to re-seed the probe
        // booking, couple and staff row at the end of the run, so every suite
        // run handed the next one a stray user (and a stray booking) it had
        // not asked for.
        self::cleanupAll();
        // The spawned php -S child must die with the suite (it inherits this
        // process's stdout; leaving it alive hangs composer test on EOF).
        self::tearDownAfterClassStkServer();
    }

    private static function ensureTables(): void
    {
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS tblmpesa_stk_pushes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                checkout_request_id VARCHAR(50) NOT NULL,
                merchant_request_id VARCHAR(50) DEFAULT NULL,
                purpose ENUM("subscription","deposit") NOT NULL,
                plan VARCHAR(30) DEFAULT NULL,
                booking_id INT UNSIGNED DEFAULT NULL,
                event_id INT UNSIGNED DEFAULT NULL,
                user_id INT UNSIGNED NOT NULL,
                phone VARCHAR(15) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status ENUM("pending","success","failed","cancelled","timeout") NOT NULL DEFAULT "pending",
                result_code INT DEFAULT NULL,
                result_desc VARCHAR(255) DEFAULT NULL,
                mpesa_receipt VARCHAR(20) DEFAULT NULL,
                ref_table VARCHAR(40) DEFAULT NULL,
                ref_id INT UNSIGNED DEFAULT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                answered_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_checkout (checkout_request_id),
                KEY idx_stk_user (user_id),
                KEY idx_stk_status (status, requested_at),
                KEY idx_stk_ref (ref_table, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function cleanupAll(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        foreach (self::$probePushIds as $id) {
            $p->exec('DELETE FROM tblmpesa_stk_pushes WHERE id = ' . (int) $id);
        }
        self::$probePushIds = [];
        // restore any fixtures torn by fulfilment
        $p->exec('DELETE FROM tbl_liquidation WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . self::$staffId . " OR mpesa_code LIKE 'QSTK%'");
        $p->exec("DELETE FROM tblnotifications WHERE booking_id = " . self::FIX_BOOK . " OR user_id = " . self::FIX_USER . " OR user_id = " . self::$staffId);
        $p->exec('DELETE FROM tbl_mail_logs WHERE booking_id = ' . self::FIX_BOOK . ' OR user_id = ' . self::FIX_USER);
        $p->exec('DELETE FROM events WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblaccounts_detail WHERE user_id = ' . self::FIX_USER);
        $p->exec('DELETE FROM tblaccounts WHERE user_id = ' . self::FIX_USER);
        $p->exec("DELETE FROM tblusers WHERE username = '" . self::STAFF_USER . "'");
        self::$staffId = 0;
    }

    /** A probe staff row — subscriptions key on tblusers, not couples. */
    private static function seedStaff(): void
    {
        if (self::$staffId > 0) {
            return;
        }
        $p = self::$pdo;
        $p->prepare("INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created)
                     VALUES ('Probe', 'Stk', 'm', ?, 'x', 'probe.stk@example.com', '1', 'Nairobi', '1', '', NOW())")
          ->execute([self::STAFF_USER]);
        self::$staffId = (int) $p->lastInsertId();
    }

    /** @return array{event_id:int, price:float} a confirmed fixture booking with an event */
    private static function seedFixture(): array
    {
        $p = self::$pdo;
        $p->prepare("INSERT INTO tblaccounts (user_id, user_email, user_password, access_level) VALUES (?, 'fixture.stk@example.com', 'x', '0')")
          ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblaccounts_detail (user_id, firstname, lastname, phone, city, datetime_created, description, location, expectation_visitor, cash_advanced, status, date_signed)
                     VALUES (?, 'Njeri', 'Kamau', '0721999010', 'Nakuru', NOW(), 'fixture', 'Nakuru', '80', 0, 'confirm', NOW())")
          ->execute([self::FIX_USER]);
        $cat  = $p->query('SELECT id, price FROM tblweddingcategories WHERE price > 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $p->prepare("INSERT INTO tblweddingbook (booking_id, user_id, bride, groom, wedding_type, user_email, wedding_date, organizer_id)
                     VALUES (?, ?, 'Njeri', 'Otieno', ?, 'fixture.stk@example.com', ?, 7)")
          ->execute([self::FIX_BOOK, self::FIX_USER, (int) $cat['id'], date('Y-m-d', strtotime('+120 days'))]);
        $ev = $p->prepare("INSERT INTO events (booking_id, title, location, start, end, color) VALUES (?, 'Ceremony', 'Nakuru', ?, ?, '#0EA5A0')");
        $ev->execute([self::FIX_BOOK, date('Y-m-d 10:00:00', strtotime('+120 days')), date('Y-m-d 12:00:00', strtotime('+120 days'))]);

        return ['event_id' => (int) $p->lastInsertId(), 'price' => (float) $cat['price']];
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — STK push tests skipped');
        }
    }

    /** @return array<string, mixed> the last push row as an array */
    private static function pushRow(int $id): array
    {
        $row = MpesaStk::find($id);
        self::assertNotNull($row);
        return (array) $row;
    }

    // ------------------------------------------------------------------
    // 1. initiate
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testInitiateCreatesPendingPushWithNormalisedPhone(): void
    {
        $this->requireDb();
        $fx = self::seedFixtureExtra(); // idempotent-ish: fixture already exists from cleanup

        $r = MpesaStk::initiate('deposit', self::FIX_USER, '0712 345 678', 500.0,
            ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r['ok'], 'initiate must succeed in simulated transport: ' . ($r['error'] ?? ''));
        self::$probePushIds[] = (int) $r['push']->id;

        $row = self::pushRow((int) $r['push']->id);
        self::assertSame('pending', $row['status']);
        self::assertSame('254712345678', $row['phone'], 'local format must normalise to 2547XXXXXXXX');
        self::assertSame('deposit', $row['purpose']);
        self::assertSame('254712345678', (string) $r['push']->checkout_request_id === $row['checkout_request_id'] ? $row['phone'] : $row['phone']);
        self::assertStringStartsWith('ws_CO_SIM-', (string) $row['checkout_request_id'], 'simulated transport stamps a Daraja-shaped handle');
        self::assertNotNull($r['status_url']);
    }

    #[Group('integration')]
    public function testInitiateRejectsBadPhoneAmountAndUnknownBooking(): void
    {
        $this->requireDb();
        $fx = self::seedFixtureExtra();

        $r = MpesaStk::initiate('deposit', self::FIX_USER, '12345', 500.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertFalse($r['ok'], 'a non-Kenyan phone must be refused');

        $r = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 0.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertFalse($r['ok'], 'a zero amount must be refused');

        $r = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 500.0, ['booking_id' => 999999999, 'event_id' => 1]);
        self::assertFalse($r['ok'], 'an unknown booking must be refused');

        $plan = Subscription::get_plan('starter');
        self::assertNotFalse($plan);
        $r = MpesaStk::initiate('subscription', self::FIX_USER, '0712345678', (float) $plan->price, ['plan' => 'no-such-plan']);
        self::assertFalse($r['ok'], 'an unknown plan must be refused');
    }

    #[Group('integration')]
    public function testInitiateRefusesWhenDisabled(): void
    {
        $this->requireDb();
        putenv('WPMS_MPESA_STK');
        try {
            $r = MpesaStk::initiate('subscription', self::FIX_USER, '0712345678', 2999.0, ['plan' => 'starter']);
            self::assertFalse($r['ok'], 'initiate must refuse when the master switch is off');
        } finally {
            putenv('WPMS_MPESA_STK=1');
        }
    }

    // ------------------------------------------------------------------
    // 2. apply_callback
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testSuccessCallbackFulfilsSubscriptionExactlyOnce(): void
    {
        $this->requireDb();
        $plan = Subscription::get_plan('starter');
        self::assertNotFalse($plan);

        $r = MpesaStk::initiate('subscription', self::$staffId, '0712345678', (float) $plan->price, ['plan' => 'starter']);
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $pushId = (int) $r['push']->id;
        self::$probePushIds[] = $pushId;
        $cr = (string) $r['push']->checkout_request_id;

        $applied = MpesaStk::apply_callback($cr, 0, 'The service request is processed successfully.', 'QSTKSUB01');
        self::assertTrue($applied['ok'], (string) ($applied['error'] ?? ''));
        self::assertSame('success', $applied['status']);

        $row = self::pushRow($pushId);
        self::assertSame('success', $row['status']);
        self::assertSame('QSTKSUB01', $row['mpesa_receipt']);
        self::assertSame('tblsubscriptions', $row['ref_table'], 'a fulfilled push records what it created');
        self::assertGreaterThan(0, (int) $row['ref_id']);
        $subId = (int) $row['ref_id'];

        // The subscription exists with the Daraja receipt as its code.
        global $db;
        $sub = $db->fetchOne('SELECT * FROM tblsubscriptions WHERE id = :i', [':i' => $subId]);
        self::assertNotNull($sub, 'the subscription row must exist');
        self::assertSame('starter', (string) $sub->plan);
        self::assertSame('QSTKSUB01', (string) $sub->mpesa_code);

        // Replay: Daraja retries the same callback — must NOT create a second subscription.
        $replay = MpesaStk::apply_callback($cr, 0, 'The service request is processed successfully.', 'QSTKSUB01');
        self::assertTrue($replay['ok']);
        self::assertTrue($replay['replay'] ?? false, 'a second callback for an answered push is a replay');
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = :u AND mpesa_code = "QSTKSUB01"',
            [':u' => self::$staffId]
        );
        self::assertSame(1, $count, 'exactly one subscription per successful push');

        // Cleanup: cleanupAll wipes the probe staff row (CASCADE removes the subscription).
    }

    #[Group('integration')]
    public function testCancelledAndFailedCallbacksNeverFulfil(): void
    {
        $this->requireDb();
        $fx = self::seedFixtureExtra();

        // Customer dismissed the prompt (Daraja ResultCode 1032).
        $r = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 500.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r['ok']);
        self::$probePushIds[] = (int) $r['push']->id;
        $applied = MpesaStk::apply_callback((string) $r['push']->checkout_request_id, 1032, 'Request cancelled by user');
        self::assertTrue($applied['ok']);
        self::assertSame('cancelled', $applied['status']);
        $row = self::pushRow((int) $r['push']->id);
        self::assertSame('cancelled', $row['status']);
        self::assertNull($row['ref_table'], 'a cancelled push must not fulfil');

        // Gateway failure (any other code).
        $r2 = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 500.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r2['ok']);
        self::$probePushIds[] = (int) $r2['push']->id;
        $applied2 = MpesaStk::apply_callback((string) $r2['push']->checkout_request_id, 2001, 'Gateway error');
        self::assertSame('failed', $applied2['status']);
        self::assertNull(self::pushRow((int) $r2['push']->id)['ref_table'], 'a failed push must not fulfil');
    }

    #[Group('integration')]
    public function testSuccessCallbackFulfilsDepositExactlyOnce(): void
    {
        $this->requireDb();
        $fx = self::seedFixtureExtra();

        $r = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 750.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::$probePushIds[] = (int) $r['push']->id;
        $cr = (string) $r['push']->checkout_request_id;

        $applied = MpesaStk::apply_callback($cr, 0, 'The service request is processed successfully.', 'QSTKDEP01');
        self::assertTrue($applied['ok'], (string) ($applied['error'] ?? ''));
        $row = self::pushRow((int) $r['push']->id);
        self::assertSame('success', $row['status']);
        self::assertSame('tbl_liquidation', $row['ref_table']);
        self::assertGreaterThan(0, (int) $row['ref_id']);

        global $db;
        $pay = $db->fetchOne('SELECT * FROM tbl_liquidation WHERE id = :i', [':i' => (int) $row['ref_id']]);
        self::assertNotNull($pay, 'the deposit row must exist');
        self::assertEqualsWithDelta(750.0, (float) $pay->payment, 0.001);
        self::assertSame(self::FIX_BOOK, (int) $pay->booking_id);

        // Replay after fulfilment: still a no-op.
        $replay = MpesaStk::apply_callback($cr, 0, 'The service request is processed successfully.', 'QSTKDEP01');
        self::assertTrue($replay['ok']);
        self::assertTrue($replay['replay'] ?? false);
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b AND payment = 750.0',
            [':b' => self::FIX_BOOK]
        );
        self::assertSame(1, $count, 'exactly one deposit per successful push');
    }

    #[Group('integration')]
    public function testLateSuccessAfterTimeoutReopensAndFulfils(): void
    {
        $this->requireDb();
        $fx = self::seedFixtureExtra();

        $r = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 300.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r['ok']);
        self::$probePushIds[] = (int) $r['push']->id;
        $cr = (string) $r['push']->checkout_request_id;

        // The sweeper times the push out (no callback arrived).
        MpesaStk::cancel_stale(1); // 1 minute: rows are requested "now", backdate below
        self::assertSame('pending', self::pushRow((int) $r['push']->id)['status'], 'a fresh push must survive the sweeper');
        self::$pdo->exec('UPDATE tblmpesa_stk_pushes SET requested_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ' . (int) $r['push']->id);
        self::assertSame(1, MpesaStk::cancel_stale(1), 'the stale push must be swept to timeout');

        // NOW the customer surfaces and pays — Daraja's success callback arrives late.
        $applied = MpesaStk::apply_callback($cr, 0, 'The service request is processed successfully.', 'QSTKLATE01');
        self::assertTrue($applied['ok'], (string) ($applied['error'] ?? ''));
        self::assertSame('success', $applied['status']);
        $row = self::pushRow((int) $r['push']->id);
        self::assertSame('success', $row['status'], 'a late success must win over the timeout');
        self::assertSame('tbl_liquidation', $row['ref_table'], 'the late payment must still be booked');

        // But a late FAILURE after timeout is just recorded, never reopened into a payment.
        $r2 = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 300.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r2['ok']);
        self::$probePushIds[] = (int) $r2['push']->id;
        self::$pdo->exec('UPDATE tblmpesa_stk_pushes SET requested_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ' . (int) $r2['push']->id);
        MpesaStk::cancel_stale(1);
        $applied2 = MpesaStk::apply_callback((string) $r2['push']->checkout_request_id, 1037, 'DS timeout: user cannot be reached');
        self::assertSame('timeout', $applied2['status'] ?? '', 'a late timeout on an already-timed-out push is a replay');
        self::assertTrue($applied2['replay'] ?? false);
    }

    #[Group('integration')]
    public function testUnknownCheckoutRequestIsRefused(): void
    {
        $this->requireDb();
        $r = MpesaStk::apply_callback('ws_CO_DOES_NOT_EXIST', 0, 'processed');
        self::assertFalse($r['ok'], 'an unknown handle must be refused, not silently accepted');
    }

    // ------------------------------------------------------------------
    // 3. cancel_stale
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testCancelStaleOnlyTouchesOldPendingPushes(): void
    {
        $this->requireDb();
        $fx = self::seedFixtureExtra();

        $fresh = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 200.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($fresh['ok']);
        self::$probePushIds[] = (int) $fresh['push']->id;
        $old = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 200.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($old['ok']);
        self::$probePushIds[] = (int) $old['push']->id;

        self::$pdo->exec('UPDATE tblmpesa_stk_pushes SET requested_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ' . (int) $old['push']->id);
        $swept = MpesaStk::cancel_stale(5);
        self::assertGreaterThanOrEqual(1, $swept, 'the 30-minute-old push must be swept');
        self::assertSame('pending', self::pushRow((int) $fresh['push']->id)['status'], 'a 0-minute push must survive the 5-minute sweeper');
        self::assertSame('timeout', self::pushRow((int) $old['push']->id)['status']);
    }

    // ------------------------------------------------------------------
    // 4. HTTP endpoint (php -S) — the Daraja JSON contract
    // ------------------------------------------------------------------

    private static ?string $base = null;
    private static $serverProc = null;

    public static function tearDownAfterClassStkServer(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
        self::$base = null;
    }

    private static function bootServer(): void
    {
        if (self::$base !== null || !function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_stk_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_stk_err');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 33000 + random_int(0, 6999);
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
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    self::$base = 'http://127.0.0.1:' . $port;
                    return;
                }
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
    public function testCallbackEndpointParsesDarajaBodyAndFulfils(): void
    {
        $this->requireDb();
        self::bootServer();
        if (self::$base === null) {
            self::markTestSkipped('php -S server unavailable — endpoint test skipped');
        }
        $fx = self::seedFixtureExtra();
        $r  = MpesaStk::initiate('deposit', self::FIX_USER, '0712345678', 600.0, ['booking_id' => self::FIX_BOOK, 'event_id' => $fx['event_id']]);
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::$probePushIds[] = (int) $r['push']->id;
        $cr = (string) $r['push']->checkout_request_id;

        // A real Daraja success notification (nested Body.stkCallback + metadata items).
        $payload = json_encode([
            'Body' => ['stkCallback' => [
                'MerchantRequestID' => '29115-34620561-1',
                'CheckoutRequestID' => $cr,
                'ResultCode'        => 0,
                'ResultDesc'        => 'The service request is processed successfully.',
                'CallbackMetadata'  => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 600.00],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'QSTKEND01'],
                    ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ]],
            ]],
        ]);
        $res = self::post('/mpesa_stk_callback.php', $payload);
        self::assertSame(200, $res['status'], 'endpoint must always answer 200');
        $json = json_decode($res['body'], true);
        self::assertSame(0, $json['ResultCode'] ?? -1, 'a valid success callback must be accepted');

        $row = self::pushRow((int) $r['push']->id);
        self::assertSame('success', $row['status'], 'the endpoint must have applied the result');
        self::assertSame('QSTKEND01', $row['mpesa_receipt'], 'the receipt must be extracted from CallbackMetadata');
        self::assertSame('tbl_liquidation', $row['ref_table'], 'and the payment booked');
    }

    /**
     * The fixture booking (account, couple row, booking, one ceremony) plus the
     * event id, re-derived without duplicating rows. Rebuilt when missing, so
     * each test can stand on its own instead of on what a previous run left in
     * the database.
     *
     * @return array{event_id:int, price:float}
     */
    private static function seedFixtureExtra(): array
    {
        global $db;
        $booked = $db->fetchOne('SELECT booking_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::FIX_BOOK]);
        if ($booked === null) {
            return self::seedFixture();
        }
        $ev = $db->fetchOne('SELECT id FROM events WHERE booking_id = :b ORDER BY id LIMIT 1', [':b' => self::FIX_BOOK]);
        if ($ev === null) {
            $p = self::$pdo;
            $cat = $p->query('SELECT id, price FROM tblweddingcategories WHERE price > 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $ev2 = $p->prepare("INSERT INTO events (booking_id, title, location, start, end, color) VALUES (?, 'Ceremony', 'Nakuru', ?, ?, '#0EA5A0')");
            $ev2->execute([self::FIX_BOOK, date('Y-m-d 10:00:00', strtotime('+120 days')), date('Y-m-d 12:00:00', strtotime('+120 days'))]);
            return ['event_id' => (int) $p->lastInsertId(), 'price' => (float) $cat['price']];
        }
        $cat = $db->fetchOne(
            'SELECT c.price FROM tblweddingbook b JOIN tblweddingcategories c ON c.id = b.wedding_type WHERE b.booking_id = :b',
            [':b' => self::FIX_BOOK]
        );
        return ['event_id' => (int) $ev->id, 'price' => (float) ($cat->price ?? 0)];
    }
}
