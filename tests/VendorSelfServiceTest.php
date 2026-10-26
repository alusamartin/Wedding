<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/DealNote.php';

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
    /** @var int[] probe tblvendor_services ids created by this run */
    private static array $probeServiceIds = [];

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
            foreach ([
                'tblvendor_bookings' => self::$probeBookingIds,
                'tblvendor_packages' => self::$probePackageIds,
                'tblvendor_services' => self::$probeServiceIds,
            ] as $table => $ids) {
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
        self::$probeServiceIds = [];

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

    /**
     * Can the page ACTION this booking — does it render the hidden
     * booking_row_id field for it?
     *
     * The own-row assertion used to match the id as a whole number anywhere in
     * the body. That is not an ownership check: the body is ~20 KB of HTML and
     * an id can legitimately collide with a number from another table. It did:
     * auto-increment ids are per table, so after a rebuild a probe booking id
     * (203) equalled the id of a second vendor listing the account may switch
     * to, which the listing switcher prints as an option value. The page was
     * scoped correctly; the assertion was not.
     *
     * The precise signal is the form control a transition posts through: a row
     * the page may change carries name="booking_row_id" value="<id>". A real
     * leak still fails loudly, because a leaked row prints that field too.
     */
    private static function bodyNamesRowId(int $id, string $body): bool
    {
        return preg_match('/name="booking_row_id" value="' . $id . '"/', $body) === 1;
    }

    /** Seed an active probe SERVICE row for the given vendor (v2.27). */
    private static function seedService(int $vendorId): int
    {
        global $db;
        $db->execute(
            "INSERT INTO tblvendor_services (vendor_id, title, pricing_unit, price, is_active)
             VALUES (:vid, :title, 'flat', 7500.00, 1)",
            [':vid' => $vendorId, ':title' => self::TAG . ' foreign service']
        );
        $id = (int) $db->lastInsertId();
        self::$probeServiceIds[] = $id;
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
        // The bounce body must be EMPTY: redirect_to() terminates execution, so
        // gated pages never leak their HTML into the redirect response (the
        // pre-audit bug: the full admin page rode along in the 302 body).
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        foreach (['vendor_home.php', 'vendor_profile.php', 'vendor_packages.php', 'vendor_services.php'] as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(302, $res['status'], "staff must bounce off {$page}");
            self::assertStringStartsWith('../', $res['location'] ?? '', "staff bounce from {$page} must stay on-site");
            self::assertSame('', trim($res['body']), "gated page {$page} must not leak HTML in the redirect body");
        }

        // Anonymous bounce.
        self::$jar = [];
        $res = self::http('GET', '/admin/vendor_home.php');
        self::assertSame(302, $res['status'], 'anonymous visitors must bounce off vendor pages');
        self::assertSame('', trim($res['body']), 'anonymous bounce must not leak HTML in the redirect body');

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
        self::assertTrue(self::bodyNamesRowId($ownId, $body), 'the vendor must see their own probe booking (id ' . $ownId . ')');
        self::assertFalse(self::bodyNamesRowId($foreignId, $body), 'the vendor must NOT see another vendor\'s booking (id ' . $foreignId . ')');
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

    /**
     * v3.3 — a decline is not a dead end.
     *
     * The reason a vendor types when they say no travels: it is stored on the
     * deal, read back by the vendor, shown to the planner on the row they work
     * from, shown to the couple on their own enquiries list, and belled to the
     * couple rather than trusting a best-effort SMS. Every HTML surface escapes
     * it — it is a sentence somebody typed, never markup.
     */
    #[Group('integration')]
    public function testVendorDeclineReasonReachesCoupleAndPlanner(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $ownId = self::seedBooking(self::$vendorAId);
        $token = self::fetchCsrf('/admin/vendor_home.php');

        $res = self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $ownId,
            'to'             => 'cancelled',
            'status_note'    => "Already booked that Saturday — try our sister studio <b>Bashiri</b>\n\ncall 0722 000 000",
        ]);
        self::assertSame(302, $res['status']);

        $row = $db->fetchOne('SELECT * FROM tblvendor_bookings WHERE id = :id', [':id' => $ownId]);
        self::assertSame('cancelled', (string) $row->status, 'the decline still moves the deal');
        self::assertSame(
            'Already booked that Saturday — try our sister studio Bashiri call 0722 000 000',
            (string) $row->status_note,
            'the stored reason is one flat line with tags stripped'
        );
        self::assertSame('vendor', (string) $row->status_note_by, 'the words are attributed to the vendor');
        self::assertStringContainsString('reason:', (string) $row->notes, 'the notes trail keeps what was said');

        // The vendor reads their own words back on the ledger.
        self::assertStringContainsString('You said:', self::http('GET', '/admin/vendor_home.php')['body']);

        // The planner reads them on the row they work from.
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $ledger = self::http('GET', '/admin/vendor_enquiries.php?status=cancelled')['body'];
        self::assertStringContainsString('Vendor said:', $ledger, 'the planner sees the reason, not just the badge');
        self::assertStringContainsString('try our sister studio', $ledger);
        self::assertStringNotContainsString('<b>Bashiri</b>', $ledger, 'the reason is escaped, never markup');

        /*
         * And so does the couple, on their own enquiries list. The wedding and
         * account ids are READ OFF the seeded booking this probe hangs from,
         * never typed: a test that names a seeded record it does not create is
         * a fixture it does not own, and sweep F6 says so out loud.
         */
        $owner = $db->fetchOne(
            'SELECT vb.booking_id, b.user_id FROM tblvendor_bookings vb'
            . ' JOIN tblweddingbook b ON b.booking_id = vb.booking_id WHERE vb.id = :id',
            [':id' => $ownId]
        );
        self::login(self::CLIENT_EMAIL, self::CLIENT_PASS);
        $couple = self::http(
            'GET',
            '/admin/client_vendors.php?booking_id=' . (int) $owner->booking_id . '&user_id=' . (int) $owner->user_id
        )['body'];
        self::assertStringContainsString('try our sister studio', $couple, 'the couple reads why, in the vendor\'s words');
        self::assertStringContainsString('said:', $couple);
        self::assertStringNotContainsString('<b>Bashiri</b>', $couple, 'the couple\'s list escapes it too');

        // The bell as well as the phone — the SMS is best-effort, this is not.
        $notified = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND message LIKE '%declined your enquiry%'",
            [':b' => (int) $owner->booking_id]
        );
        self::assertGreaterThan(0, $notified, 'a decline with a reason must reach the couple in-app');
    }

    /**
     * The column is the answer that is CURRENT, not a history table: any other
     * answer clears it, so "already booked that day" can never sit beside a
     * reopened deal and send the couple back to a closed date.
     */
    #[Group('integration')]
    public function testAnyOtherAnswerClearsTheStoredDeclineReason(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $ownId = self::seedBooking(self::$vendorAId);
        $token = self::fetchCsrf('/admin/vendor_home.php');

        self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $ownId,
            'to'             => 'cancelled',
            'status_note'    => 'Fully booked that weekend',
        ]);
        self::assertSame(
            'Fully booked that weekend',
            (string) $db->fetchValue('SELECT status_note FROM tblvendor_bookings WHERE id = :id', [':id' => $ownId])
        );

        // Re-opening the deal (the vendor changed their mind about the date).
        $token = self::fetchCsrf('/admin/vendor_home.php');
        self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $ownId,
            'to'             => 'pending',
        ]);

        $row = $db->fetchOne('SELECT * FROM tblvendor_bookings WHERE id = :id', [':id' => $ownId]);
        self::assertSame('pending', (string) $row->status);
        self::assertNull($row->status_note, 'a reopened deal must not keep the old reason');
        // The audit trail still remembers it — clearing the column is not forgetting.
        self::assertStringContainsString('Fully booked that weekend', (string) $row->notes);

        // A decline with no reason is silence, not an empty sentence.
        $token = self::fetchCsrf('/admin/vendor_home.php');
        self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $ownId,
            'to'             => 'cancelled',
        ]);
        self::assertNull(
            $db->fetchValue('SELECT status_note FROM tblvendor_bookings WHERE id = :id', [':id' => $ownId]),
            'no reason is stored as NULL, not as an empty string'
        );
    }

    /** A courtesy input is not a place to paste an email. */
    #[Group('integration')]
    public function testAnOverlongDeclineReasonIsCutToTheStoredLength(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $ownId = self::seedBooking(self::$vendorAId);
        $token = self::fetchCsrf('/admin/vendor_home.php');

        self::http('POST', '/admin/vendor_home.php', [
            '_token'         => $token,
            'booking_row_id' => (string) $ownId,
            'to'             => 'cancelled',
            'status_note'    => str_repeat('our calendar is full ', 40),
        ]);

        $stored = (string) $db->fetchValue('SELECT status_note FROM tblvendor_bookings WHERE id = :id', [':id' => $ownId]);
        self::assertLessThanOrEqual(DealNote::MAX, mb_strlen($stored), 'the stored reason fits the column and the card');
        self::assertStringEndsWith('…', $stored, 'a cut reason says so');
    }

    /**
     * One byte in the wrong encoding must not swallow the sentence.
     *
     * `preg_replace('/\s+/u', …)` returns NULL on invalid UTF-8, and the whole
     * reason used to collapse to '' because of it — the vendor pressed Decline
     * with their words in the box and the couple were told nothing (found by
     * posting from a terminal whose em dash was bytes 0x97, not UTF-8).
     */
    public function testADeclineReasonInTheWrongEncodingKeepsItsWords(): void
    {
        $cleaned = DealNote::clean("Already booked \x97 try our sister studio");
        self::assertNotSame('', $cleaned, 'the reason must survive one bad byte');
        self::assertStringContainsString('try our sister studio', $cleaned, 'and keep the rest of the sentence');
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
    public function testVendorServicePageScopesEveryMutationToTheSignedInVendor(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);

        $foreign = self::seedService(self::$vendorBId);
        $token   = self::fetchCsrf('/admin/vendor_services.php');

        // Another vendor's service cannot be deleted, edited or toggled through
        // this page — the model re-proves vendor_id in each statement, so a
        // posted id is just a number.
        self::http('POST', '/admin/vendor_services.php', [
            '_token' => $token, 'action' => 'delete', 'service_id' => (string) $foreign,
        ]);
        self::http('POST', '/admin/vendor_services.php', [
            '_token' => $token, 'action' => 'edit', 'service_id' => (string) $foreign,
            'title' => self::TAG . ' hijacked', 'price' => '1', 'unit' => 'flat',
        ]);
        self::http('POST', '/admin/vendor_services.php', [
            '_token' => $token, 'action' => 'toggle', 'service_id' => (string) $foreign,
        ]);
        $foreignRow = $db->fetchOne('SELECT * FROM tblvendor_services WHERE id = :id', [':id' => $foreign]);
        self::assertNotNull($foreignRow, 'a foreign delete must be refused');
        self::assertSame(self::TAG . ' foreign service', (string) $foreignRow->title, 'a foreign edit must be refused');
        self::assertEqualsWithDelta(7500.0, (float) $foreignRow->price, 0.01, 'a foreign edit changed no price');
        self::assertSame(1, (int) $foreignRow->is_active, 'a foreign toggle must be refused');

        // Own service: the custom form stores it with its unit, so the price is
        // comparable, and the page prints the labelled line back.
        self::http('POST', '/admin/vendor_services.php', [
            '_token'   => $token,
            'action'   => 'add_custom',
            'title'    => self::TAG . ' page service',
            'unit'     => 'per_guest',
            'price'    => '1500',
            'min_qty'  => '100',
            'includes' => 'staff, serving dishes',
            'tags'     => 'Rustic, halal',
        ]);
        $ownId = (int) $db->fetchValue(
            'SELECT id FROM tblvendor_services WHERE vendor_id = :v AND title = :t ORDER BY id DESC LIMIT 1',
            [':v' => self::$vendorAId, ':t' => self::TAG . ' page service']
        );
        self::assertGreaterThan(0, $ownId, 'own service add must succeed');
        self::$probeServiceIds[] = $ownId;

        $own = $db->fetchOne('SELECT * FROM tblvendor_services WHERE id = :id', [':id' => $ownId]);
        self::assertSame('per_guest', (string) $own->pricing_unit);
        self::assertNull($own->catalog_id, 'a custom service is not linked to the vocabulary');
        self::assertSame('rustic,halal', (string) $own->tags, 'taste tags are normalised on the way in');

        $body = self::http('GET', '/admin/vendor_services.php')['body'];
        self::assertStringContainsString('My Services', $body);
        self::assertStringContainsString('KSh 1,500 / guest (min 100 guests)', $body,
            'the page must show the price with its unit — that is what makes it comparable');
        self::assertStringNotContainsString(self::TAG . ' foreign service', $body,
            'another vendor\'s service must not appear on this page');

        // Own toggle works and is reported.
        self::http('POST', '/admin/vendor_services.php', [
            '_token' => $token, 'action' => 'toggle', 'service_id' => (string) $ownId,
        ]);
        self::assertSame(0, (int) $db->fetchValue('SELECT is_active FROM tblvendor_services WHERE id = :id', [':id' => $ownId]),
            'toggle must hide the vendor\'s own service');
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
