<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Couple vendor marketplace regressions against a real php -S server:
 *
 *  1. Access — the marketplace is couple-scoped (anonymous bounces, foreign
 *     bookings bounce); staff may preview it for any booking.
 *  2. Browsing — approved vendors render with KSh packages; category and
 *     county filters narrow the grid; the enquiry modal targets the scoped
 *     endpoint.
 *  3. Enquiry flow — POST + CSRF records a pending tblvendor_bookings row
 *     tied to the couple's booking, notifies the assigned planner, and
 *     rejects GET / tokenless / unknown-vendor / foreign-booking attempts.
 *
 * Integration ('integration' group) — requires the live DB and a bootable
 * php -S. The probe enquiry row and its planner notification are removed in
 * tearDownAfterClass; seed vendor rows are never modified.
 */
final class CoupleVendorMarketplaceTest extends TestCase
{
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const BOOKING_ID   = 200;
    private const PLANNER_ID   = 7; // organizer of booking 200 (seed)

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] enquiry rows created by this run */
    private static array $probeEnquiryIds = [];
    /** @var int[] notification ids created by this run */
    private static array $probeNotificationIds = [];

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
            if (!class_exists('Vendor', false)) {
                require_once __DIR__ . '/../admin/include/Monetization.php';
            }
            if (!function_exists('format_date_kd')) {
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
        if (self::$db !== null) {
            try {
                if (self::$probeEnquiryIds !== []) {
                    self::$db->execute(
                        'DELETE FROM tblvendor_bookings WHERE id IN (' . implode(',', self::$probeEnquiryIds) . ')'
                    );
                }
                if (self::$probeNotificationIds !== []) {
                    self::$db->execute(
                        'DELETE FROM tblnotifications WHERE id IN (' . implode(',', self::$probeNotificationIds) . ')'
                    );
                }
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeEnquiryIds = [];
        self::$probeNotificationIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    #[Group('integration')]
    public function testMarketplaceIsCoupleScopedAndRendersVendors(): void
    {
        $this->requireDb();

        // Anonymous bounce.
        self::$jar = [];
        $anon = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $anon['status'], 'anonymous users must bounce off the marketplace');

        // Foreign booking bounce.
        self::loginCouple();
        $foreign = self::http('GET', '/admin/client_vendors.php?booking_id=201&user_id=201');
        self::assertSame(302, $foreign['status'], 'a client must be bounced off another booking\'s marketplace');

        // The owning couple sees approved vendors with KSh packages.
        $page = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Vendor Marketplace', $page['body']);
        self::assertStringContainsString('Nyama Choma Masters', $page['body'], 'approved seed vendors must render');
        self::assertStringContainsString('KSh 45,000', $page['body'], 'package prices must render in KSh');
        self::assertStringContainsString('client_vendor_enquiry.php', $page['body'], 'enquiries must post to the scoped endpoint');

        // Category filter narrows the grid.
        $catering = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200&category=catering');
        self::assertSame(200, $catering['status']);
        self::assertStringContainsString('Nyama Choma Masters', $catering['body']);
        self::assertStringNotContainsString('Floral Dreams', $catering['body'], 'other categories must be filtered out');

        // County filter narrows the grid.
        $nakuru = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200&county=Nakuru%20County');
        self::assertSame(200, $nakuru['status']);
        self::assertStringContainsString('Nyama Choma Masters', $nakuru['body']);
        self::assertStringNotContainsString('Flash Photography KE', $nakuru['body'], 'other counties must be filtered out');

        // An impossible combination shows the designed empty state.
        $empty = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200&category=mc&county=Nakuru%20County');
        self::assertStringContainsString('No vendors here yet', $empty['body']);
    }

    #[Group('integration')]
    public function testEnquiryEndpointRejectsBadRequests(): void
    {
        $this->requireDb();
        self::loginCouple();

        // GET is rejected (redirect back, nothing recorded).
        $get = self::http('GET', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $get['status'], 'enquiry must reject GET requests');
        self::assertSame(2, self::enquiryCount(), 'GET must not record anything');

        // Tokenless POST is rejected.
        $tokenless = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'vendor_id' => 200, 'message' => 'tokenless attempt',
        ]);
        self::assertSame(302, $tokenless['status']);
        self::assertSame(2, self::enquiryCount(), 'tokenless POST must not record anything');

        // Unknown/unapproved vendor is refused even with a valid token.
        $token = self::fetchCsrfToken();
        $unknown = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'vendor_id' => 999, 'message' => 'should not land', '_token' => $token,
        ]);
        self::assertSame(302, $unknown['status']);
        self::assertSame(2, self::enquiryCount(), 'unknown vendor must not record anything');

        // Foreign booking is bounced.
        $foreign = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=201&user_id=201', [
            'vendor_id' => 200, 'message' => 'foreign attempt', '_token' => $token,
        ]);
        self::assertSame(302, $foreign['status']);
        self::assertSame(2, self::enquiryCount(), 'foreign booking must not record anything');
    }

    #[Group('integration')]
    public function testEnquiryRecordsRowAndNotifiesPlanner(): void
    {
        $this->requireDb();
        self::loginCouple();

        $token = self::fetchCsrfToken();
        $ok = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'vendor_id'   => 200,
            'package_id'  => 200, // Choma Basic — belongs to vendor 200
            'event_date'  => '2026-12-19',
            'message'     => 'Test enquiry — do you serve 120 guests in Karen?',
            '_token'      => $token,
        ]);
        self::assertSame(302, $ok['status']);

        // The enquiry row is recorded, pending, tied to the couple's booking.
        $row = self::$db->fetchAll(
            'SELECT * FROM tblvendor_bookings WHERE booking_id = :b AND vendor_id = 200 AND notes LIKE :n ORDER BY id DESC LIMIT 1',
            [':b' => self::BOOKING_ID, ':n' => '%Test enquiry%']
        );
        self::assertCount(1, $row, 'the enquiry must be recorded');
        $enquiry = $row[0];
        self::$probeEnquiryIds[] = (int) $enquiry->id;
        self::assertSame('pending', (string) $enquiry->status);
        self::assertSame(200, (int) $enquiry->package_id);
        self::assertSame('2026-12-19', (string) $enquiry->event_date);

        // The assigned planner is notified.
        $notif = self::$db->fetchAll(
            'SELECT * FROM tblnotifications WHERE user_id = :p AND booking_id = :b AND message LIKE :m ORDER BY id DESC LIMIT 1',
            [':p' => self::PLANNER_ID, ':b' => self::BOOKING_ID, ':m' => '%enquired with Nyama Choma Masters%']
        );
        self::assertCount(1, $notif, 'the planner must be notified');
        self::$probeNotificationIds[] = (int) $notif[0]->id;

        // A package id from another vendor degrades to a general enquiry
        // (still recorded, package nulled) rather than failing the send.
        $ok2 = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'vendor_id'  => 203,
            'package_id' => 200, // belongs to vendor 200, NOT 203
            'message'    => 'Test enquiry 2 — cross-vendor package guard',
            '_token'     => $token,
        ]);
        self::assertSame(302, $ok2['status']);
        $row2 = self::$db->fetchAll(
            'SELECT * FROM tblvendor_bookings WHERE booking_id = :b AND vendor_id = 203 AND notes LIKE :n ORDER BY id DESC LIMIT 1',
            [':b' => self::BOOKING_ID, ':n' => '%Test enquiry 2%']
        );
        self::assertCount(1, $row2, 'the degraded enquiry must still be recorded');
        self::$probeEnquiryIds[] = (int) $row2[0]->id;
        self::assertEmpty($row2[0]->package_id, 'foreign package id must not be stored');
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

    private static function enquiryCount(): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblvendor_bookings');
    }

    private static function fetchCsrfToken(): string
    {
        $res = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract CSRF token from the marketplace page');
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vm_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vm_err');

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

    private static function loginCouple(): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => self::COUPLE_EMAIL,
            'input_password' => self::COUPLE_PASS,
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
