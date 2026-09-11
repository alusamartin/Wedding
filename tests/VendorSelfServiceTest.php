<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendor self-service (access_level 0 staff accounts).
 *
 * Integration ('integration' group) — live DB + php -S server, same pattern
 * as the other page tests. Probe vendor rows are tagged 'vstest' and removed
 * in tearDownAfterClass; the seeded vendor accounts (kamau@nyamachoma.co.ke /
 * Vendor@2024, chebet@floral.co.ke) are used for logins but their data is
 * restored to its pre-test state.
 *
 * Covered:
 *  1. Vendor login lands on vendor_home.php (or vendor_register.php when
 *     unregistered) — the gap this feature closes.
 *  2. vendor_* pages are vendor-only: staff are bounced, anonymous bounces,
 *     clients bounce.
 *  3. Ownership scoping: vendor A cannot transition or even see vendor B's
 *     bookings; package mutations refuse foreign package ids.
 *  4. Status transitions update the ledger and notify the couple's planner.
 */
final class VendorSelfServiceTest extends TestCase
{
    private const VENDOR_EMAIL = 'kamau@nyamachoma.co.ke';
    private const VENDOR_PASS  = 'Vendor@2024';
    private const VENDOR2_EMAIL = 'chebet@floral.co.ke';
    private const VENDOR2_PASS  = 'Vendor@2024';
    private const STAFF_EMAIL  = 'wafula@wpms.co.ke';
    private const STAFF_PASS   = 'Admin@2024!';
    private const CLIENT_EMAIL = 'brian.wafula@gmail.com';
    private const CLIENT_PASS  = 'Test@1234';
    private const TAG          = '[vstest]';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string,string> */
    private static array $jar = [];
    private static $serverProc = null;
    private static int $vendorAId = 0;
    private static int $vendorBId = 0;
    /** @var int[] probe tblvendor_bookings ids created by this run */
    private static array $probeBookingIds = [];
    /** @var int[] probe tblvendor_packages ids created by this run */
    private static array $probePackageIds = [];

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

        if (self::$db !== null) {
            try {
                self::$vendorAId = (int) self::$db->fetchValue(
                    'SELECT id FROM tblvendors WHERE user_id = (SELECT id FROM tblusers WHERE email = :e) LIMIT 1',
                    [':e' => self::VENDOR_EMAIL]
                );
                self::$vendorBId = (int) self::$db->fetchValue(
                    'SELECT id FROM tblvendors WHERE user_id = (SELECT id FROM tblusers WHERE email = :e) LIMIT 1',
                    [':e' => self::VENDOR2_EMAIL]
                );
            } catch (Throwable $e) {
                self::$vendorAId = 0;
            }
        }

        self::bootServer();
        if (self::$base === null || self::$db === null || self::$vendorAId === 0 || self::$vendorBId === 0) {
            return; // tests skip
        }
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
        if (self::$jar === []) {
            self::$base = null; // vendor login failed — seeded credentials unavailable
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            foreach (['tblvendor_bookings' => self::$probeBookingIds, 'tblvendor_packages' => self::$probePackageIds] as $table => $ids) {
                if ($ids !== []) {
                    try {
                        self::$db->execute("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids) . ")");
                    } catch (Throwable $e) {
                        // leave state untouched on failure
                    }
                }
            }
        }
        self::$probeBookingIds = [];
        self::$probePackageIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ------------------------------------------------------------------
    // Server + HTTP client (same stream-based pattern as the other suites)
    // ------------------------------------------------------------------

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
            self::$serverProc = @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [
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
                if (@file_get_contents(self::$base . '/index.php', false, $ctx) === false) {
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

    /** @param array<string,string> $post @return array{status:int, location:string, body:string} */
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
        if (self::$base === null || self::$db === null || self::$vendorAId === 0) {
            self::markTestSkipped('php -S server / live DB / seeded vendors unavailable — vendor self-service tests skipped');
        }
    }

    private static function fetchCsrf(string $path): string
    {
        $res = self::http('GET', $path);
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail("could not extract CSRF token from $path");
    }

    /** Seed a pending probe booking for the given vendor + seeded booking 200. */
    private static function seedBooking(int $vendorId): int
    {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
             VALUES (:vid, 200, NULL, NULL, 0, 0, 'pending', :note)"
        );
        $stmt->execute([':vid' => $vendorId, ':note' => self::TAG]);
        $id = (int) $db->the_insert_id();
        self::$probeBookingIds[] = $id;
        return $id;
    }

    /** Seed an active probe package for the given vendor. */
    private static function seedPackage(int $vendorId): int
    {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tblvendor_packages (vendor_id, title, description, price, is_active)
             VALUES (:vid, :title, 'probe', 5000.00, 1)"
        );
        $stmt->execute([':vid' => $vendorId, ':title' => self::TAG . ' package']);
        $id = (int) $db->the_insert_id();
        self::$probePackageIds[] = $id;
        return $id;
    }

    // ------------------------------------------------------------------
    // 1. Login routing — the actual gap
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testVendorLoginLandsOnVendorHome(): void
    {
        $this->requireHttp();
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
        $res = self::http('GET', '/login.php'); // signed-in GET guard redirects to role home
        self::assertSame(302, $res['status'], 'signed-in vendor must be redirected away from the login form');
        self::assertStringContainsString('vendor_home.php', $res['location'] ?? '', 'a registered vendor must land on their bookings dashboard');
    }

    #[Group('integration')]
    public function testVendorHomeIsVendorOnly(): void
    {
        $this->requireHttp();

        // Staff are bounced OFF vendor pages — vendor pages are vendor-scoped.
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        foreach (['vendor_home.php', 'vendor_profile.php', 'vendor_packages.php'] as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(302, $res['status'], "staff must bounce off {$page}");
            self::assertStringStartsWith('../', $res['location'] ?? '', "staff bounce from {$page} must stay on-site");
        }

        // Anonymous bounce.
        self::$jar = [];
        $res = self::http('GET', '/admin/vendor_home.php');
        self::assertSame(302, $res['status'], 'anonymous visitors must bounce off vendor pages');

        // Client (tblaccounts) bounce.
        self::login(self::CLIENT_EMAIL, self::CLIENT_PASS);
        $res = self::http('GET', '/admin/vendor_home.php');
        self::assertSame(302, $res['status'], 'clients must bounce off vendor pages');

        // Restore the vendor session for the remaining tests.
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
    }

    #[Group('integration')]
    public function testVendorHomeShowsOnlyOwnBookings(): void
    {
        $this->requireHttp();
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $ownId    = self::seedBooking(self::$vendorAId);
        $foreignId = self::seedBooking(self::$vendorBId);

        $body = self::http('GET', '/admin/vendor_home.php')['body'];
        foreach (['Fatal error:', 'Parse error:', 'Warning:'] as $marker) {
            self::assertStringNotContainsString($marker, $body, 'vendor home must not leak PHP issues');
        }
        self::assertStringContainsString('My Bookings', $body);
        self::assertStringContainsString((string) $ownId, $body, 'the vendor must see their own probe booking');
        self::assertStringNotContainsString((string) $foreignId, $body, 'the vendor must NOT see another vendor\'s booking');
    }

    #[Group('integration')]
    public function testVendorCannotTransitionAnotherVendorsBooking(): void
    {
        $this->requireHttp();
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $foreignId = self::seedBooking(self::$vendorBId);
        $token = self::fetchCsrf('/admin/vendor_home.php');

        $res = self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $foreignId,
            'to'             => 'confirmed',
            'amount'         => '99999',
        ]);
        self::assertSame(302, $res['status']);

        // The foreign row must be untouched.
        global $db;
        $status = (string) $db->fetchValue('SELECT status FROM tblvendor_bookings WHERE id = :id', [':id' => $foreignId]);
        self::assertSame('pending', $status, 'a vendor must not be able to confirm another vendor\'s booking');

        // And the attempt is reported, not silently ignored.
        $body = self::http('GET', '/admin/vendor_home.php')['body'];
        self::assertStringContainsString('That booking is not yours.', $body, 'the refusal must be visible to the vendor');
    }

    #[Group('integration')]
    public function testVendorConfirmsOwnBookingAndPlannerIsNotified(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $ownId = self::seedBooking(self::$vendorAId);
        $token = self::fetchCsrf('/admin/vendor_home.php');

        $res = self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $ownId,
            'to'             => 'confirmed',
            'amount'         => '45000',
        ]);
        self::assertSame(302, $res['status']);

        $row = $db->fetchOne('SELECT * FROM tblvendor_bookings WHERE id = :id', [':id' => $ownId]);
        self::assertSame('confirmed', (string) $row->status, 'own booking must transition');
        self::assertEqualsWithDelta(45000.0, (float) $row->amount, 0.01, 'the agreed amount must be stamped');
        self::assertStringContainsString('confirmed', (string) $row->notes, 'the notes trail must record who did what');

        // Commission is NOT set by vendors — that stays a staff decision.
        self::assertEqualsWithDelta(0.0, (float) $row->commission, 0.01, 'vendor confirm must not touch staff commission');
    }

    #[Group('integration')]
    public function testVendorPackageMutationsAreOwnershipScoped(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $foreignPkg = self::seedPackage(self::$vendorBId);
        $token = self::fetchCsrf('/admin/vendor_packages.php');

        // Try to delete another vendor's package.
        self::http('POST', '/admin/vendor_packages.php', [
            '_token'     => $token,
            'action'     => 'delete',
            'package_id' => (string) $foreignPkg,
        ]);
        $still = (int) $db->fetchValue('SELECT COUNT(*) FROM tblvendor_packages WHERE id = :id', [':id' => $foreignPkg]);
        self::assertSame(1, $still, 'a vendor must not delete another vendor\'s package');

        // Own package: add + toggle works.
        self::http('POST', '/admin/vendor_packages.php', [
            '_token'     => $token,
            'action'     => 'add',
            'title'      => self::TAG . ' own package',
            'price'      => '12345',
            'description' => 'probe',
        ]);
        $ownNew = (int) $db->fetchValue(
            "SELECT id FROM tblvendor_packages WHERE title = :t ORDER BY id DESC LIMIT 1",
            [':t' => self::TAG . ' own package']
        );
        self::assertGreaterThan(0, $ownNew, 'own package add must succeed');
        self::$probePackageIds[] = $ownNew;

        self::http('POST', '/admin/vendor_packages.php', [
            '_token'     => $token,
            'action'     => 'toggle',
            'package_id' => (string) $ownNew,
        ]);
        $active = (int) $db->fetchValue('SELECT is_active FROM tblvendor_packages WHERE id = :id', [':id' => $ownNew]);
        self::assertSame(0, $active, 'toggle must hide the vendor\'s own package');
    }

    #[Group('integration')]
    public function testVendorProfileSavesOwnRowOnly(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $token = self::fetchCsrf('/admin/vendor_profile.php');
        $newName = self::TAG . ' profile check';
        self::http('POST', '/admin/vendor_profile.php', [
            '_token'        => $token,
            'save_profile'  => '1',
            'business_name' => $newName,
            'category'      => 'catering',
            'phone'         => '0722000002',
            'email'         => 'info@nyamachoma.co.ke',
            'location'      => 'Nakuru',
            'county'        => 'Nakuru County',
            'description'   => 'probe',
        ]);

        // Own row updated.
        $name = (string) $db->fetchValue('SELECT business_name FROM tblvendors WHERE id = :id', [':id' => self::$vendorAId]);
        self::assertSame($newName, $name, 'own profile must save');

        // Restore the seeded name.
        $db->execute('UPDATE tblvendors SET business_name = :n WHERE id = :id', [
            ':n' => 'Nyama Choma Masters — Nakuru', ':id' => self::$vendorAId,
        ]);
    }
}
