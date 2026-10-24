<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * v2.21 — the vendor card numbers are real.
 *
 *  A. Vendor::recompute_stats() derives total_bookings and rating from the
 *     deals themselves (the writer audit sweep D was missing for tblvendors
 *     .rating / .total_bookings).
 *  B. A couple rates a COMPLETED deal once, through admin/client_vendor_rate.php
 *     (POST + CSRF, couple-scoped), and the vendor's rating becomes the mean.
 *     Pending deals, foreign bookings, tokenless posts and out-of-range scores
 *     are all refused, and a second rating changes nothing.
 *
 * Integration ('integration' group) — requires the live DB and a bootable
 * php -S. Vendor 200's counters are snapshotted and restored, and every probe
 * deal is deleted, so seed state is left exactly as it was found.
 */
final class VendorStatsTest extends TestCase
{
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const BOOKING_ID   = 200;
    private const VENDOR_ID    = 200;

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] */
    private static array $probeIds = [];
    private static ?array $vendorSnapshot = null;

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
            $row = self::$db->fetchAll(
                'SELECT rating, total_bookings FROM tblvendors WHERE id = :id LIMIT 1',
                [':id' => self::VENDOR_ID]
            );
            if ($row !== []) {
                self::$vendorSnapshot = [
                    'rating'         => (string) $row[0]->rating,
                    'total_bookings' => (int) $row[0]->total_bookings,
                ];
            }
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                if (self::$probeIds !== []) {
                    self::$db->execute(
                        'DELETE FROM tblvendor_bookings WHERE id IN (' . implode(',', self::$probeIds) . ')'
                    );
                }
                if (self::$vendorSnapshot !== null) {
                    $stmt = self::$db->prepare('UPDATE tblvendors SET rating = :r, total_bookings = :tb WHERE id = :id');
                    $stmt->execute([
                        ':r'  => self::$vendorSnapshot['rating'],
                        ':tb' => self::$vendorSnapshot['total_bookings'],
                        ':id' => self::VENDOR_ID,
                    ]);
                }
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeIds = [];
        self::$vendorSnapshot = null;

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    #[Group('integration')]
    public function testRecomputeStatsDerivesBothCountersFromTheDeals(): void
    {
        $this->requireDb();

        // A controlled vendor to compute against, so the assertion is exact.
        $vendors = self::$db->fetchAll('SELECT id FROM tblvendors ORDER BY id ASC LIMIT 1');
        if ($vendors === []) {
            $this->markTestSkipped('no vendor in the seed');
        }
        $vid = (int) $vendors[0]->id;

        // Blow the counters out of sync, then prove recompute restores them.
        self::$db->execute("UPDATE tblvendors SET rating = 0, total_bookings = 999 WHERE id = {$vid}");
        Vendor::recompute_stats($vid);

        $expectedBookings = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblvendor_bookings WHERE vendor_id = :id AND status IN ('confirmed', 'completed')",
            [':id' => $vid]
        );
        $expectedRating = round((float) self::$db->fetchValue(
            'SELECT AVG(rating) FROM tblvendor_bookings WHERE vendor_id = :id AND rating IS NOT NULL',
            [':id' => $vid]
        ), 1);

        $vendor = self::$db->fetchAll('SELECT rating, total_bookings FROM tblvendors WHERE id = :id', [':id' => $vid])[0];
        self::assertSame($expectedBookings, (int) $vendor->total_bookings, 'total_bookings must be the count of confirmed/completed deals');
        self::assertSame($expectedRating, round((float) $vendor->rating, 1), 'rating must be the mean of the couple ratings');

        // An unknown vendor id is a no-op, never a crash.
        Vendor::recompute_stats(0);
        Vendor::recompute_stats(-5);
        $this->addToAssertionCount(1);
    }

    #[Group('integration')]
    public function testCoupleRatesCompletedDealOnceAndVendorMeanUpdates(): void
    {
        $this->requireDb();
        self::loginCouple();

        $dealId = self::insertProbe('completed');

        // The completed deal is ratable, and the page offers the form.
        $page = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertStringContainsString('client_vendor_rate.php', $page['body'], 'a completed deal must offer a rating form');

        $token = self::fetchCsrfToken();
        $ok = self::http('POST', '/admin/client_vendor_rate.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'enquiry_id' => $dealId,
            'rating'     => 4,
            '_token'     => $token,
        ]);
        self::assertSame(302, $ok['status'], 'a valid rating must redirect');

        $row = self::deal($dealId);
        self::assertSame(4, (int) $row->rating, 'the rating must be stored on the deal');
        self::assertNotNull($row->rated_at, 'rated_at must be stamped');

        self::assertVendorCountersMatchDeals();

        // Rating the same deal again changes nothing (once-only guard).
        self::http('POST', '/admin/client_vendor_rate.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'enquiry_id' => $dealId,
            'rating'     => 1,
            '_token'     => $token,
        ]);
        self::assertSame(4, (int) self::deal($dealId)->rating, 'a second rating must not overwrite the first');
    }

    #[Group('integration')]
    public function testRatingIsGuarded(): void
    {
        $this->requireDb();
        self::loginCouple();

        $completed = self::insertProbe('completed');
        $pending   = self::insertProbe('pending');
        $token     = self::fetchCsrfToken();

        // Out-of-range score refused.
        self::http('POST', '/admin/client_vendor_rate.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'enquiry_id' => $completed, 'rating' => 6, '_token' => $token,
        ]);
        self::assertNull(self::deal($completed)->rating, 'a 6-star score must be refused');

        // A pending deal is not yet ratable.
        self::http('POST', '/admin/client_vendor_rate.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'enquiry_id' => $pending, 'rating' => 5, '_token' => $token,
        ]);
        self::assertNull(self::deal($pending)->rating, 'a pending deal must not be ratable');

        // Tokenless is refused.
        self::http('POST', '/admin/client_vendor_rate.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'enquiry_id' => $completed, 'rating' => 5,
        ]);
        self::assertNull(self::deal($completed)->rating, 'a tokenless post must not rate');

        // A deal belonging to another wedding is bounced.
        $foreign = self::http('POST', '/admin/client_vendor_rate.php?booking_id=201&user_id=201', [
            'enquiry_id' => $completed, 'rating' => 5, '_token' => $token,
        ]);
        self::assertSame(302, $foreign['status']);
        self::assertNull(self::deal($completed)->rating, 'a foreign booking must not rate');

        // GET is refused.
        $get = self::http('GET', '/admin/client_vendor_rate.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $get['status'], 'rating must be POST-only');
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

    /** Insert a probe deal for booking 200 / vendor 200 and remember its id. */
    private static function insertProbe(string $status): int
    {
        $note = 'VendorStatsTest probe ' . bin2hex(random_bytes(4));
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, status, amount, notes, created_at)
             VALUES (:v, :b, :s, 0, :n, NOW())",
            [':v' => self::VENDOR_ID, ':b' => self::BOOKING_ID, ':s' => $status, ':n' => $note]
        );
        $id = (int) self::$db->fetchValue(
            'SELECT id FROM tblvendor_bookings WHERE notes = :n ORDER BY id DESC LIMIT 1',
            [':n' => $note]
        );
        self::$probeIds[] = $id;
        return $id;
    }

    private static function deal(int $id): object
    {
        $rows = self::$db->fetchAll('SELECT rating, rated_at FROM tblvendor_bookings WHERE id = :id', [':id' => $id]);
        self::assertNotEmpty($rows, 'probe deal vanished');
        return $rows[0];
    }

    private static function assertVendorCountersMatchDeals(): void
    {
        $expectedBookings = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblvendor_bookings WHERE vendor_id = :id AND status IN ('confirmed', 'completed')",
            [':id' => self::VENDOR_ID]
        );
        $expectedRating = round((float) self::$db->fetchValue(
            'SELECT AVG(rating) FROM tblvendor_bookings WHERE vendor_id = :id AND rating IS NOT NULL',
            [':id' => self::VENDOR_ID]
        ), 1);
        $vendor = self::$db->fetchAll('SELECT rating, total_bookings FROM tblvendors WHERE id = :id', [':id' => self::VENDOR_ID])[0];
        self::assertSame($expectedBookings, (int) $vendor->total_bookings, 'the writer must have refreshed total_bookings');
        self::assertSame($expectedRating, round((float) $vendor->rating, 1), 'the writer must have refreshed rating');
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
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vs_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vs_err');

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
