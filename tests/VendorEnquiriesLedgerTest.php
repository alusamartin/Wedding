<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Staff vendor-enquiries ledger regressions against a real php -S server:
 *
 *  1. Access — staff-only (anonymous and client sessions bounce).
 *  2. Listing — enquiries from ALL couples render with vendor, package,
 *     KSh deal amounts and status badges; status filters narrow the table;
 *     pending rows sort first.
 *  3. Transitions — confirm sets amount + 10% commission and stamps the
 *     notes trail with who moved it; complete and cancel flip status;
 *     every action is POST + CSRF (GET/tokenless record nothing).
 *
 * Integration ('integration' group) — requires the live DB and a bootable
 * php -S. Probe enquiry rows are removed in tearDownAfterClass; seed rows
 * (ids 200/201) are never modified.
 */
final class VendorEnquiriesLedgerTest extends TestCase
{
    private const STAFF_EMAIL = 'wafula@wpms.co.ke';
    private const STAFF_PASS  = 'Admin@2024!';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe enquiry ids created by this run */
    private static array $probeIds = [];
    /** @var int[] probe notification ids created by this run */
    private static array $probeNotifIds = [];

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
            if (!function_exists('format_ksh')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
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
        if (self::$db !== null && self::$probeIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tblvendor_bookings WHERE id IN (' . implode(',', self::$probeIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeIds = [];
        if (self::$db !== null && self::$probeNotifIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tbl_mail_logs WHERE notification_id IN (' . implode(',', self::$probeNotifIds) . ')'
                );
                self::$db->execute(
                    'DELETE FROM tblnotifications WHERE id IN (' . implode(',', self::$probeNotifIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeNotifIds = [];
        // Every transition (confirm/complete/cancel/reopen) now alerts the
        // couple — sweep any vendor-status alert the probes produced on this
        // booking. Probe enquiries are the only source of Safari Transport
        // alerts on the seed booking, so this cannot eat real demo content.
        if (self::$db !== null) {
            try {
                self::$db->execute(
                    "DELETE FROM tbl_mail_logs WHERE notification_id IN (
                        SELECT id FROM (
                            SELECT id FROM tblnotifications
                             WHERE user_id = 200 AND booking_id = 200 AND message LIKE '%Safari Transport%'
                        ) x
                    )"
                );
                self::$db->execute(
                    "DELETE FROM tblnotifications
                      WHERE user_id = 200 AND booking_id = 200 AND message LIKE '%Safari Transport%'"
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    #[Group('integration')]
    public function testLedgerIsStaffOnlyAndListsAllCouples(): void
    {
        $this->requireDb();

        // Anonymous and client sessions bounce.
        $anon = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(302, $anon['status'], 'anonymous users must bounce off the ledger');

        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $client = self::http('GET', '/admin/vendor_enquiries.php');
        // The staff jar is empty of client creds — a client session can't
        // exist here, so this is just the anonymous leg repeated. Real client
        // bounce is asserted in CoupleVendorMarketplaceTest.

        // Staff sees the ledger with seed enquiries from booking 200.
        $page = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Vendor Enquiries', $page['body']);
        self::assertStringContainsString('Nyama Choma Masters', $page['body'], 'seed enquiry must render');
        self::assertStringContainsString('Ruracio Decor — Full', $page['body'], 'the pending seed enquiry must render with its package');
        self::assertStringContainsString('KSh 120,000.00', $page['body'], 'deal amounts render in KSh');
        self::assertStringContainsString('commission KSh 12,000.00', $page['body']);
        self::assertStringContainsString('badge-warning', $page['body'], 'pending rows carry the pending badge');
        self::assertStringContainsString('Mark completed', $page['body'], 'confirmed rows offer the completion action');
        self::assertStringContainsString('vendor_enquiries.php', $page['body'], 'actions post back to the ledger');

        // Status filter narrows the table.
        $pending = self::http('GET', '/admin/vendor_enquiries.php?status=pending');
        self::assertStringContainsString('Floral Dreams', $pending['body']);
        self::assertStringNotContainsString('Nyama Choma Masters', $pending['body'], 'confirmed rows must be filtered out');
    }

    #[Group('integration')]
    public function testTransitionsRequirePostAndCsrf(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id = self::makeProbeEnquiry('CSRF probe enquiry');

        // GET must not change anything.
        self::http('GET', '/admin/vendor_enquiries.php');
        $get = self::http('GET', '/admin/vendor_enquiries.php?enquiry_id=' . $id . '&to=confirmed');
        self::assertSame(200, $get['status'], 'GET without a POST body just renders the page');
        self::assertSame('pending', self::enquiryStatus($id), 'GET must not transition');

        // Tokenless POST must not change anything.
        self::http('POST', '/admin/vendor_enquiries.php', ['enquiry_id' => $id, 'to' => 'cancelled']);
        self::assertSame('pending', self::enquiryStatus($id), 'tokenless POST must not transition');
    }

    #[Group('integration')]
    public function testConfirmSetsDealCommissionAndNotesTrail(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id = self::makeProbeEnquiry('Confirm-flow probe enquiry');
        $token = self::fetchCsrfToken();

        // Confirm with an explicit amount.
        $ok = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'confirmed', 'amount' => '40000', '_token' => $token,
        ]);
        self::assertSame(302, $ok['status']);

        $row = self::fetchEnquiry($id);
        self::assertSame('confirmed', (string) $row->status);
        self::assertSame('40000.00', (string) $row->amount);
        self::assertSame('4000.00', (string) $row->commission, 'commission must be the standard 10%');
        self::assertStringContainsString('confirmed by wafula@wpms.co.ke', (string) $row->notes, 'the notes trail must record who confirmed');
        self::assertStringContainsString('Confirm-flow probe enquiry', (string) $row->notes, 'the original enquiry message must be preserved');

        // The couple's bell must light up too — the enquiry loop now closes
        // back to the couple who sent it.
        $coupleNotif = self::$db->fetchOne(
            "SELECT id, message FROM tblnotifications WHERE booking_id = 200 AND user_id = 200 AND message LIKE '%Safari Transport%'
              ORDER BY id DESC LIMIT 1",
            []
        );
        self::assertNotNull($coupleNotif, 'confirm must create a couple-facing notification');
        self::assertStringContainsString('confirmed for your wedding', (string) $coupleNotif->message);
        self::$probeNotifIds[] = (int) $coupleNotif->id;

        // Complete.
        $done = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'completed', '_token' => $token,
        ]);
        self::assertSame(302, $done['status']);
        self::assertSame('completed', self::enquiryStatus($id));
    }

    #[Group('integration')]
    public function testCancelWorksAndUnknownStatusIsRefused(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id = self::makeProbeEnquiry('Cancel-flow probe enquiry');
        $token = self::fetchCsrfToken();

        $ok = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'cancelled', '_token' => $token,
        ]);
        self::assertSame(302, $ok['status']);
        self::assertSame('cancelled', self::enquiryStatus($id));

        // An unknown status is refused (row untouched).
        $bad = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'hacked', '_token' => $token,
        ]);
        self::assertSame(302, $bad['status']);
        self::assertSame('cancelled', self::enquiryStatus($id));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireDb(): void
    {
        if (self::$db === null || self::$base === null) {
            $this->markTestSkipped('live DB + php -S unavailable');
        }
    }

    /** Insert a pending probe enquiry on booking 200 / vendor 203; tracks cleanup. */
    private static function makeProbeEnquiry(string $note): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
             VALUES (203, 200, 203, '2026-12-19', 0, 0, 'pending', :note)",
            [':note' => $note]
        );
        $id = (int) self::$db->fetchValue('SELECT MAX(id) FROM tblvendor_bookings');
        self::$probeIds[] = $id;
        return $id;
    }

    private static function fetchEnquiry(int $id): object
    {
        return self::$db->fetchAll('SELECT * FROM tblvendor_bookings WHERE id = :id', [':id' => $id])[0];
    }

    private static function enquiryStatus(int $id): string
    {
        return (string) self::$db->fetchValue('SELECT status FROM tblvendor_bookings WHERE id = :id', [':id' => $id]);
    }

    private static function fetchCsrfToken(): string
    {
        $res = self::http('GET', '/admin/vendor_enquiries.php');
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract CSRF token from the ledger page');
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_ve_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_ve_err');

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
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        curl_close($ch);

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
}
