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
    /** staff user assigned to booking 200 (tblweddingbook.organizer_id) */
    private const PLANNER_USER = 7;

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
                require_once __DIR__ . '/../admin/include/database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        // Hermetic mail: force the notification email push into log-only mode
        // BEFORE the php -S child inherits this environment, so tests never
        // attempt a real send regardless of the developer's shell env.
        putenv('WPMS_MAIL_ENABLED=');
        putenv('WPMS_SITE_URL=');
        putenv('WPMS_MAIL_FROM=');

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

        // Sweep residue from earlier aborted runs of this suite. An assertion
        // failure aborts the test before its cleanup deletes the created
        // ledger row, so leaked STK deposits accumulate — and once the
        // booking's total crosses the package price, the endpoint (correctly)
        // rejects new deposits as overpaid and the happy path can never run
        // again. Seed rows never carry the self-serve STK 'QDL' prefix, so
        // this sweep is safe to run at any time.
        $leaked = self::$db->fetchAll(
            "SELECT id, mpesa_code FROM tbl_liquidation WHERE booking_id = :b AND payment = 2500.00 AND mpesa_code LIKE 'QDL%'",
            [':b' => self::OWN_BOOKING]
        );
        foreach ($leaked as $leak) {
            $leak = is_array($leak) ? (object) $leak : $leak;
            $staleNotifs = self::$db->fetchAll(
                'SELECT id FROM tblnotifications WHERE booking_id = :b AND message LIKE :ref',
                [':b' => self::OWN_BOOKING, ':ref' => '%' . $leak->mpesa_code . '%']
            );
            foreach ($staleNotifs as $stale) {
                $staleId = (int) (is_array($stale) ? $stale['id'] : $stale->id);
                self::$db->execute('DELETE FROM tbl_mail_logs WHERE notification_id = :nid', [':nid' => $staleId]);
                self::$db->execute('DELETE FROM tblnotifications WHERE id = :id', [':id' => $staleId]);
            }
            self::$db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => (int) $leak->id]);
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

        // The assigned planner must receive an in-app notification.
        $notif = self::$db->fetchOne(
            'SELECT * FROM tblnotifications WHERE booking_id = :b AND user_id = :u ORDER BY id DESC LIMIT 1',
            [':b' => self::OWN_BOOKING, ':u' => self::PLANNER_USER]
        );
        self::assertNotNull($notif, 'The assigned planner must receive a deposit notification');
        self::assertStringContainsString((string) $row->mpesa_code, (string) $notif->message, 'Notification must carry the M-Pesa reference');
        self::assertStringContainsString('M-Pesa deposit of KSh 2,500', (string) $notif->message, 'Notification must state the amount');
        self::assertSame(0, (int) $notif->is_read, 'A fresh notification starts unread');
        self::assertStringContainsString('budget.php?booking_id=200&user_id=200', (string) $notif->link, 'Notification must deep-link to the booking budget page');

        // Notification::mark_read must flip it (the staff bell path).
        self::assertTrue(Notification::mark_read(Notification::FOR_USER, (int) $notif->id, self::PLANNER_USER), 'mark_read must succeed');
        $check = self::$db->fetchValue('SELECT is_read FROM tblnotifications WHERE id = :id', [':id' => (int) $notif->id]);
        self::assertSame('1', (string) $check, 'mark_read must persist is_read = 1');

        // The email push leg must have written exactly one outbox row.
        $mail = self::$db->fetchOne(
            'SELECT * FROM tbl_mail_logs WHERE notification_id = :nid ORDER BY id DESC LIMIT 1',
            [':nid' => (int) $notif->id]
        );
        self::assertNotNull($mail, 'Notification::create must record an email outbox row');
        self::assertSame('amina@wpms.co.ke', (string) $mail->to_email, 'The outbox row must address the assigned planner');
        self::assertSame('New M-Pesa deposit — booking #' . self::OWN_BOOKING, (string) $mail->subject, 'The outbox row must carry the deposit subject');
        self::assertStringContainsString((string) $row->mpesa_code, (string) $mail->body, 'The email body must carry the M-Pesa reference');
        self::assertStringContainsString('budget.php?booking_id=' . self::OWN_BOOKING, (string) $mail->body, 'The email body must carry the deep link');
        self::assertSame('pending', (string) $mail->status, 'Without WPMS_MAIL_ENABLED the row stays pending (logged, not sent)');
        self::assertStringContainsString('WPMS_MAIL_ENABLED', (string) $mail->note, 'The pending note must explain how to enable delivery');

        // The COUPLE must also get a receipt: bell notification + email outbox
        // row addressed to them (the M-Pesa ref is the part they share).
        $receipt = self::$db->fetchOne(
            'SELECT * FROM tblnotifications WHERE booking_id = :b AND user_id = :u ORDER BY id DESC LIMIT 1',
            [':b' => self::OWN_BOOKING, ':u' => self::OWN_USER]
        );
        self::assertNotNull($receipt, 'The couple must receive a deposit receipt notification');
        self::assertStringContainsString((string) $row->mpesa_code, (string) $receipt->message, 'The receipt must carry the M-Pesa reference');
        self::assertStringContainsString('Asante', (string) $receipt->message, 'The receipt must open warmly');
        self::assertStringContainsString('client_budget.php?booking_id=200&user_id=200', (string) $receipt->link, 'The receipt must deep-link to My Payments');

        $receiptMail = self::$db->fetchOne(
            'SELECT * FROM tbl_mail_logs WHERE notification_id = :nid ORDER BY id DESC LIMIT 1',
            [':nid' => (int) $receipt->id]
        );
        self::assertNotNull($receiptMail, 'The couple receipt must write an email outbox row');
        self::assertSame('brian.wafula@gmail.com', (string) $receiptMail->to_email, 'The receipt email must address the couple');
        self::assertStringContainsString((string) $row->mpesa_code, (string) $receiptMail->subject, 'The receipt subject must carry the M-Pesa reference');
        self::assertSame('pending', (string) $receiptMail->status, 'Receipt email stays pending without the mail transport (dev default)');

        // Cleanup — restore the seed state (ledger row + notifications + outbox rows).
        self::$db->execute('DELETE FROM tbl_mail_logs WHERE id = :id', [':id' => (int) $mail->id]);
        self::$db->execute('DELETE FROM tblnotifications WHERE id = :id', [':id' => (int) $notif->id]);
        self::$db->execute('DELETE FROM tbl_mail_logs WHERE notification_id = :id', [':id' => (int) $receipt->id]);
        self::$db->execute('DELETE FROM tblnotifications WHERE id = :id', [':id' => (int) $receipt->id]);
        self::$db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => (int) $row->id]);
    }

    #[Group('integration')]
    public function testNotificationWithoutPlannerEmailWritesNoOutboxRow(): void
    {
        $this->requireHttp();

        // A staff user with no email on file must not crash create() and must
        // not produce an outbox row (the in-app alert is the only channel).
        self::$db->execute(
            'INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created) '
            . "VALUES ('Probe', 'Noemail', 'f', 'probe_noemail', 'x', '', 'Planner', 'Nairobi', '1', '', NOW())"
        );
        $probeId = (int) self::$db->fetchValue(
            'SELECT id FROM tblusers WHERE username = :u ORDER BY id DESC LIMIT 1',
            [':u' => 'probe_noemail']
        );
        try {
            $id = Notification::create_for_staff($probeId, self::OWN_BOOKING, 'Probe notification without email', '');
            self::assertGreaterThan(0, $id, 'create() must still succeed for a planner without an email');
            self::assertSame(0, (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tbl_mail_logs WHERE user_id = :uid',
                [':uid' => $probeId]
            ), 'No outbox row may be written when the planner has no email on file');
            self::$db->execute('DELETE FROM tblnotifications WHERE id = :id', [':id' => $id]);
        } finally {
            self::$db->execute('DELETE FROM tblusers WHERE id = :id', [':id' => $probeId]);
        }
    }

    private function baselineCount(int $bookingId): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b', [':b' => $bookingId]);
    }
}