<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * v2.21 — the staff marketplace's Send Inquiry finally records something.
 *
 * Before v2.21 admin/vendors.php flashed "Your inquiry has been sent to the
 * vendor. They will contact you shortly." and wrote nothing (sweep D's F8).
 * Now a planner picks which of their weddings the inquiry is for and a pending
 * tblvendor_bookings row is recorded against it — the same shape a couple's
 * enquiry takes — and the listing owner is notified. No booking-less inquiry.
 *
 * Integration ('integration' group) — needs the live DB and a bootable php -S.
 * The planner's own booking 200 / vendor 200 are used, and every probe row and
 * notification is deleted in tearDownAfterClass.
 */
final class VendorMarketInquiryTest extends TestCase
{
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';
    private const PLANNER_ID    = 7;   // organizer of booking 200
    private const BOOKING_ID    = 200;
    private const VENDOR_ID     = 200; // Nyama Choma Masters — approved, user_id 8
    private const VENDOR_USER   = 8;
    private const UNAPPROVED    = 202; // Flash Photography KE — is_approved 0
    private const MARKER        = 'MarketInquiryTest probe';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    private static int $notifFloor = 0;

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
            global $db;
            $db = self::$db = new Database();
            self::$notifFloor = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications');
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                self::$db->execute(
                    'DELETE FROM tblvendor_bookings WHERE booking_id = :b AND notes LIKE :n',
                    [':b' => self::BOOKING_ID, ':n' => '%' . self::MARKER . '%']
                );
                self::$db->execute(
                    'DELETE FROM tblnotifications WHERE id > :floor AND user_id = :u AND message LIKE :m',
                    [':floor' => self::$notifFloor, ':u' => self::VENDOR_USER, ':m' => 'New marketplace inquiry%']
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
    public function testStaffInquiryRecordsAPendingDealAndNotifiesTheVendor(): void
    {
        $this->requireDb();
        self::loginPlanner();

        // The marketplace offers a planner the contact form (they own weddings).
        $page = self::http('GET', '/admin/vendors.php');
        self::assertSame(200, $page['status'], 'the planner marketplace must load');
        self::assertStringContainsString('Contact Vendor', $page['body'], 'a planner with weddings sees the inquiry button');
        self::assertStringContainsString('Which wedding is this for?', $page['body'], 'the inquiry is bound to a wedding');

        $before = self::probeCount();
        $token  = self::fetchCsrfToken();

        $res = self::http('POST', '/admin/vendors.php', [
            'contact_vendor' => '1',
            'vendor_id'      => self::VENDOR_ID,
            'booking_id'     => self::BOOKING_ID,
            'event_date'     => '2026-12-19',
            'message'        => self::MARKER . ' — can you cater 150 guests?',
            '_token'         => $token,
        ]);
        self::assertSame(302, $res['status'], 'a valid inquiry must redirect');

        $rows = self::$db->fetchAll(
            'SELECT * FROM tblvendor_bookings WHERE booking_id = :b AND vendor_id = :v AND notes LIKE :n ORDER BY id DESC LIMIT 1',
            [':b' => self::BOOKING_ID, ':v' => self::VENDOR_ID, ':n' => '%' . self::MARKER . '%']
        );
        self::assertCount(1, $rows, 'the inquiry must be recorded as a deal');
        self::assertSame('pending', (string) $rows[0]->status, 'a fresh inquiry is pending');
        self::assertSame(1, self::probeCount() - $before, 'exactly one probe row was written');

        $notif = self::$db->fetchAll(
            'SELECT * FROM tblnotifications WHERE id > :floor AND user_id = :u AND message LIKE :m ORDER BY id DESC LIMIT 1',
            [':floor' => self::$notifFloor, ':u' => self::VENDOR_USER, ':m' => 'New marketplace inquiry%']
        );
        self::assertCount(1, $notif, 'the vendor listing owner must be notified');
        self::assertSame(self::BOOKING_ID, (int) $notif[0]->booking_id, 'the notification is bound to the wedding');
    }

    #[Group('integration')]
    public function testInquiryGuardsWriteNothing(): void
    {
        $this->requireDb();
        self::loginPlanner();

        $before = self::probeCount();

        // Tokenless POST is refused.
        self::http('POST', '/admin/vendors.php', [
            'contact_vendor' => '1', 'vendor_id' => self::VENDOR_ID, 'booking_id' => self::BOOKING_ID,
            'event_date' => '2026-12-19', 'message' => self::MARKER . ' tokenless',
        ]);
        self::assertSame($before, self::probeCount(), 'a tokenless inquiry must write nothing');

        $token = self::fetchCsrfToken();

        // An unapproved listing takes no inquiries.
        self::http('POST', '/admin/vendors.php', [
            'contact_vendor' => '1', 'vendor_id' => self::UNAPPROVED, 'booking_id' => self::BOOKING_ID,
            'event_date' => '2026-12-19', 'message' => self::MARKER . ' unapproved', '_token' => $token,
        ]);
        self::assertSame($before, self::probeCount(), 'an unapproved vendor must write nothing');

        // A booking the planner cannot open is refused.
        self::http('POST', '/admin/vendors.php', [
            'contact_vendor' => '1', 'vendor_id' => self::VENDOR_ID, 'booking_id' => 999999,
            'event_date' => '2026-12-19', 'message' => self::MARKER . ' unknown booking', '_token' => $token,
        ]);
        self::assertSame($before, self::probeCount(), 'an unreachable booking must write nothing');

        // An empty message is refused.
        self::http('POST', '/admin/vendors.php', [
            'contact_vendor' => '1', 'vendor_id' => self::VENDOR_ID, 'booking_id' => self::BOOKING_ID,
            'event_date' => '2026-12-19', 'message' => '', '_token' => $token,
        ]);
        self::assertSame($before, self::probeCount(), 'an empty message must write nothing');
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

    private static function probeCount(): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b AND notes LIKE :n',
            [':b' => self::BOOKING_ID, ':n' => '%' . self::MARKER . '%']
        );
    }

    private static function fetchCsrfToken(): string
    {
        $res = self::http('GET', '/admin/vendors.php');
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
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_mi_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_mi_err');

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

    private static function loginPlanner(): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => self::PLANNER_EMAIL,
            'input_password' => self::PLANNER_PASS,
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
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

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
