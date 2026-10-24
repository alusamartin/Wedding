<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/Monetization.php';
require_once __DIR__ . '/../admin/include/BookingEvent.php';
require_once __DIR__ . '/../admin/include/Notification.php';
require_once __DIR__ . '/../admin/include/HeadcountBrief.php';
require_once __DIR__ . '/../admin/include/PackageCapacity.php';

/**
 * Venue headcount plan — sheet_venue.php + admin/event_booking_save.php.
 *
 * Integration ('integration' group): live seeded DB + a bootable php -S, like
 * the other page tests. Every row this suite writes is deleted (and every row it
 * could touch is snapshotted and restored) in tearDownAfterClass, so the seeded
 * plan — booking 200: Church Wedding 10/50, Reception 20/100 — is left exactly
 * as it was found.
 *
 * Why this exists: the venue sheet printed family/individual planned headcounts
 * per function from tbleventbooking, and nothing in the application could write
 * them. admin/include/BookingEvent.php declared every field as a property but did
 * not extend DB_Object, so it had no create()/update() at all, and no screen
 * offered the fields — a brief for the venue and the caterers, frozen at whatever
 * the seed dump contained. The sheet also resolved those rows against
 * tblpostwedding (the public "real weddings" blog), so the shipped data made it
 * print OTHER couples' blog posts as this wedding's functions.
 *
 * Covered:
 *  1. The sheet lists the wedding's own functions with their counts — planned
 *     and unplanned — and never a blog row.
 *  2. Staff plan a function, then change it: one row per function, and the sheet,
 *     the totals and the messages follow.
 *  3. A couple sets the plan for their own wedding; another couple is refused.
 *  4. Crafted and malformed input is refused: a function that belongs to another
 *     wedding, a non-numeric count, and a request with no CSRF token.
 *  5. The brief the numbers are: a COUPLE's save notifies the assigned planner
 *     and the booked venue contact, names only the functions whose numbers
 *     moved, and says so on the sheet; a save that moves nothing and a staff
 *     edit notify nobody; a venue with no portal account is reported to the
 *     planner as something to pass on by hand.
 *  6. The rest of the vendors the numbers are for (v2.10): a caterer or
 *     decorator whose confirmed deal covers a moved function is briefed with
 *     that function's numbers and nothing else, one account hears once however
 *     many listings it holds, a booked vendor nobody can reach is named to the
 *     planner, and the scope that decides all of it is set on the deal in the
 *     staff ledger — with another wedding's function refused — and shown back to
 *     the vendor on their own dashboard.
 *  7. The capacity check (v2.11): a function planned past the guest capacity of
 *     the package a vendor is booked on warns that vendor and the planner — in
 *     the SAME message as a couple's change, and on its own (with the planner)
 *     when it was a staff save, which briefs nobody about the numbers. The
 *     wording is unit-tested, and so are the three ways a deal must stay out of
 *     it: no package chosen, still pending, and a scope that does not reach the
 *     function.
 *  8. The capacity headroom view (v2.12) and the width it remembers: the row a
 *     drifting wedding gets (function, count, package and limit, plus the venue
 *     sheet), the width filter narrowing a fixture that is inside 10% and
 *     outside 5%, the badge counting exactly what the page lists, the copy as
 *     units — and the WIDTH PREFERENCE: an account that never chose one opens at
 *     the default, choosing one stores it for that account only (POST + CSRF),
 *     a later visit opens there, an explicit `?within=` changes the view without
 *     writing, an off-menu or tokenless POST writes nothing, a stored value the
 *     feature does not offer reads as the default, and forgetting it is the
 *     other half of the same POST.
 *
 * The brief's recipients need a venue on file, and the seed ships no venue
 * listing at all (its four vendors are catering/decor/photography/transport),
 * so this suite books one — against the seeded vendor ACCOUNT, never the
 * planner — and deletes it, and the notifications it caused, in
 * tearDownAfterClass.
 */
final class VenueHeadcountPlanTest extends TestCase
{
    private const STAFF_EMAIL  = 'wafula@wpms.co.ke';
    private const STAFF_PASS   = 'Admin@2024!';
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';   // owns booking 200
    private const COUPLE_PASS  = 'Test@1234';
    private const OTHER_EMAIL  = 'faith.njeri@yahoo.com';    // owns booking 201
    private const OTHER_PASS   = 'Test@1234';
    /**
     * A SECOND planner with a live plan, for the per-account preference tests:
     * one planner's saved width must never become another's.
     */
    private const OTHER_STAFF_EMAIL = 'amina@wpms.co.ke';
    private const OTHER_STAFF_PASS  = 'Test@1234';
    /** The vendor login that owns this suite's scoped listing (user 102). */
    private const VENDOR_EMAIL = 'chebet@floral.co.ke';
    private const VENDOR_PASS  = 'Vendor@2024';
    private const BOOKING      = 200;
    private const OTHER_BOOKING = 201;

    /** Functions of booking 200 (from the seed) and their expected seeded counts. */
    private const PLANNED = [201 => [10, 50], 202 => [20, 100]];
    /** A function of booking 200 the seed leaves unplanned — where this suite writes. */
    private const UNPLANNED = 203;
    /** A function of ANOTHER booking (201), used to prove the cross-wedding guard. */
    private const FOREIGN_FUNCTION = 205;

    /** The vendor account that owns this suite's venue fixture (not the planner). */
    private const VENUE_USER = 8;
    private const VENUE_NAME = 'Karen Gardens — The Venue (headcount probe)';

    /**
     * The account that owns this suite's SCOPED fixture — the seeded decor
     * owner, so each recipient's bell is counted apart from the venue's account
     * (user 8) and the planner's (user 7).
     */
    private const SCOPE_USER = 102;
    private const SCOPE_NAME = 'Scope Probe Décor (headcount probe)';
    /** The one function the scoped fixture's deal covers (seeded Church Wedding). */
    private const SCOPED_FUNCTION = 201;

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string,string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var array<int, array{booking_id:int, wedding_id:int, features_id:int, family:int, individual:int}> rows as found */
    private static array $snapshot = [];
    /** @var array<int, array<string, mixed>> this wedding's deals as setUpBeforeClass found them */
    private static array $dealSnapshot = [];
    /** The venue listing this suite books on the wedding (0 when it could not be made). */
    private static int $venueVendorId = 0;
    private static int $venueBookingId = 0;
    /** The scoped listing + deal this suite books on the wedding. */
    private static int $scopedVendorId = 0;
    private static int $scopedBookingId = 0;


    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        if (!function_exists('format_ksh')) {
            require_once __DIR__ . '/../admin/include/Helper.php'; // the capacity copy reads prices
        }
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            global $db;
            $db = self::$db = new Database();
            foreach ($db->fetchAll('SELECT id, booking_id, wedding_id, features_id, family, individual FROM tbleventbooking') as $row) {
                self::$snapshot[(int) $row->id] = [
                    'booking_id'  => (int) $row->booking_id,
                    'wedding_id'  => (int) $row->wedding_id,
                    'features_id' => (int) $row->features_id,
                    'family'      => (int) $row->family,
                    'individual'  => (int) $row->individual,
                ];
            }

            /*
             * A venue contact to brief. The venue category is what makes a
             * vendor the one a headcount brief goes to, and the seed has no
             * such listing, so this suite creates one: a confirmed booking of a
             * venue-category vendor on the wedding under test. Its account is
             * the seeded vendor login (user 8) rather than the planner (user 7)
             * so the two briefs are counted apart — and it is deleted again in
             * tearDownAfterClass, so nothing downstream sees it.
             */
            if ((int) $db->fetchValue('SELECT COUNT(*) FROM tblusers WHERE id = :u', [':u' => self::VENUE_USER]) === 1) {
                $db->execute(
                    "INSERT INTO tblvendors (user_id, business_name, category, description, is_approved)
                     VALUES (:u, :n, 'venue', 'Fixture for tests/VenueHeadcountPlanTest', 1)",
                    [':u' => self::VENUE_USER, ':n' => self::VENUE_NAME]
                );
                self::$venueVendorId = (int) $db->fetchValue('SELECT MAX(id) FROM tblvendors');
                $db->execute(
                    "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
                     VALUES (:v, :b, NULL, NULL, 0, 0, 'confirmed', 'Fixture for tests/VenueHeadcountPlanTest')",
                    [':v' => self::$venueVendorId, ':b' => self::BOOKING]
                );
                self::$venueBookingId = (int) $db->fetchValue('SELECT MAX(id) FROM tblvendor_bookings');
            }

            /*
             * A vendor whose deal names ONE function. The brief has to reach the
             * caterer, and it also has to leave alone the vendor the change has
             * nothing to do with — the decorator booked for the church service
             * must not be woken up because the after-party moved. That answer is
             * the deal's own scope (tblvendor_bookings.features_id, v2.10), so
             * this suite books a decorator whose deal covers the seeded Church
             * Wedding only.
             */
            if ((int) $db->fetchValue('SELECT COUNT(*) FROM tblusers WHERE id = :u', [':u' => self::SCOPE_USER]) === 1) {
                $db->execute(
                    "INSERT INTO tblvendors (user_id, business_name, category, description, is_approved)
                     VALUES (:u, :n, 'decor', 'Fixture for tests/VenueHeadcountPlanTest', 1)",
                    [':u' => self::SCOPE_USER, ':n' => self::SCOPE_NAME]
                );
                self::$scopedVendorId = (int) $db->fetchValue('SELECT MAX(id) FROM tblvendors');
                $db->execute(
                    "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, features_id, event_date, amount, commission, status, notes)
                     VALUES (:v, :b, NULL, :f, NULL, 0, 0, 'confirmed', 'Fixture for tests/VenueHeadcountPlanTest')",
                    [':v' => self::$scopedVendorId, ':b' => self::BOOKING, ':f' => self::SCOPED_FUNCTION]
                );
                self::$scopedBookingId = (int) $db->fetchValue('SELECT MAX(id) FROM tblvendor_bookings');
            }

            /*
             * What those deals look like before any test touches them. Several
             * tests here write to a deal — the ledger sets its scope, a capacity
             * probe attaches a package, an accept flips its status — and each one
             * then asserts something that depends on the OTHERS having left alone,
             * so the baseline has to be restorable per method.
             */
            foreach ($db->fetchAll('SELECT * FROM tblvendor_bookings WHERE booking_id = :b', [':b' => self::BOOKING]) as $row) {
                self::$dealSnapshot[(int) $row->id] = (array) $row;
            }
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    /**
     * Put the plan table back exactly as it was found: rows this suite created
     * are deleted, rows it changed are restored. A test that asserts a write
     * must not leave the scratch database perturbed for every later suite.
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::resetPlanTable();

            /*
             * The headcount briefs this suite's own couple saves caused, then the
             * venue listing itself. A sweep over the class's own two weddings
             * rather than a list of ids: a couple save notifies as a side effect of
             * the features under test, so a test that dies part-way through its
             * assertions must still leave no alert behind in the bell — exactly
             * what a tracked-id list cannot promise (this suite arms the recipient
             * of a live edit, and P9 audits residue for a reason).
             */
            try {
                self::$db->execute(
                    'DELETE FROM tbl_mail_logs WHERE notification_id IN (
                         SELECT id FROM (
                             SELECT id FROM tblnotifications
                              WHERE booking_id IN (:b, :o) AND message LIKE :needle
                         ) x)',
                    [':b' => self::BOOKING, ':o' => self::OTHER_BOOKING, ':needle' => '%headcount%']
                );
                self::$db->execute(
                    'DELETE FROM tblnotifications WHERE booking_id IN (:b, :o) AND message LIKE :needle',
                    [':b' => self::BOOKING, ':o' => self::OTHER_BOOKING, ':needle' => '%headcount%']
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
            try {
                if (self::$venueBookingId > 0) {
                    self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => self::$venueBookingId]);
                }
                if (self::$venueVendorId > 0) {
                    self::$db->execute('DELETE FROM tblvendors WHERE id = :i', [':i' => self::$venueVendorId]);
                }
                if (self::$scopedBookingId > 0) {
                    self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => self::$scopedBookingId]);
                }
                if (self::$scopedVendorId > 0) {
                    self::$db->execute('DELETE FROM tblvendors WHERE id = :i', [':i' => self::$scopedVendorId]);
                }
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$snapshot = [];
        self::$venueVendorId = 0;
        self::$venueBookingId = 0;
        self::$scopedVendorId = 0;
        self::$scopedBookingId = 0;
        self::$jar = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    /**
     * Put the plan table back before AND after every test method.
     *
     * Restoring only at the end of the class is enough for the scratch database
     * but not for the tests: several of them assert the seeded plan (Church
     * Wedding 10/50, "3 of 5 functions planned", the Planned total) and others
     * deliberately write a crowd in to prove a capacity warning. While PHPUnit
     * fixes the order of methods that works by luck; the moment the order is
     * shuffled — or a method runs on its own — the writes land in between the
     * reads and the numbers stop meaning anything. Resetting around each method
     * makes the seeded values a fixture again rather than an ordering accident.
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::resetFixtures();
    }

    protected function tearDown(): void
    {
        self::resetFixtures();
        parent::tearDown();
    }

    /** Every row this class's tests write to, back to how setUpBeforeClass left it. */
    private static function resetFixtures(): void
    {
        self::resetPlanTable();
        self::resetDeals();
        self::resetBriefs();
    }

    /**
     * The fixture deals: the scope the ledger test edits (its last POST stores the
     * whole wedding as NULL), the package a capacity probe attaches, the status an
     * accept flips. Deals a method created and did not remove are dropped too.
     */
    private static function resetDeals(): void
    {
        if (self::$db === null || self::$dealSnapshot === []) {
            return;
        }
        try {
            foreach (self::$db->fetchAll('SELECT id FROM tblvendor_bookings WHERE booking_id = :b', [':b' => self::BOOKING]) as $row) {
                $id = (int) $row->id;
                if (!array_key_exists($id, self::$dealSnapshot)) {
                    self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :id', [':id' => $id]);
                }
            }
            foreach (self::$dealSnapshot as $id => $want) {
                self::$db->execute(
                    'UPDATE tblvendor_bookings SET status = :s, package_id = :p, features_id = :f,
                            event_date = :e, amount = :a, commission = :c WHERE id = :id',
                    [
                        ':s' => $want['status'], ':p' => $want['package_id'], ':f' => $want['features_id'],
                        ':e' => $want['event_date'], ':a' => $want['amount'], ':c' => $want['commission'],
                        ':id' => $id,
                    ]
                );
            }
        } catch (Throwable $e) {
            // leave state untouched on failure
        }
    }

    /** The headcount briefs a save leaves in a bell (this class's own rows only). */
    private static function resetBriefs(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            self::$db->execute(
                'DELETE FROM tbl_mail_logs WHERE notification_id IN (
                     SELECT id FROM (
                         SELECT id FROM tblnotifications
                          WHERE booking_id IN (:b, :o) AND message LIKE :needle
                     ) x)',
                [':b' => self::BOOKING, ':o' => self::OTHER_BOOKING, ':needle' => '%headcount%']
            );
            self::$db->execute(
                'DELETE FROM tblnotifications WHERE booking_id IN (:b, :o) AND message LIKE :needle',
                [':b' => self::BOOKING, ':o' => self::OTHER_BOOKING, ':needle' => '%headcount%']
            );
        } catch (Throwable $e) {
            // leave state untouched on failure
        }
    }

    /**
     * Delete plan rows this class created and put the rest back exactly as
     * setUpBeforeClass found them.
     */
    private static function resetPlanTable(): void
    {
        if (self::$db === null || self::$snapshot === []) {
            return;
        }
        try {
            foreach (self::$db->fetchAll('SELECT id FROM tbleventbooking') as $row) {
                $id = (int) $row->id;
                if (!array_key_exists($id, self::$snapshot)) {
                    self::$db->execute('DELETE FROM tbleventbooking WHERE id = :id', [':id' => $id]);
                }
            }
            foreach (self::$snapshot as $id => $want) {
                self::$db->execute(
                    'UPDATE tbleventbooking SET booking_id = :b, wedding_id = :w, features_id = :f,
                            family = :fam, individual = :ind WHERE id = :id',
                    [
                        ':b' => $want['booking_id'], ':w' => $want['wedding_id'], ':f' => $want['features_id'],
                        ':fam' => $want['family'], ':ind' => $want['individual'], ':id' => $id,
                    ]
                );
            }
        } catch (Throwable $e) {
            // leave state untouched on failure
        }
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
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vh_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vh_err');

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

    private static function fetchCsrf(string $path): string
    {
        $res = self::http('GET', $path);
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail("could not extract CSRF token from $path");
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / live DB unavailable — venue headcount tests skipped');
        }
    }

    /** The brief's recipient tests need the venue listing this suite books. */
    private function requireVenue(): void
    {
        if (self::$venueVendorId === 0) {
            self::markTestSkipped('no venue fixture — the vendor-account user is missing from this database');
        }
    }

    /** The scoping tests need the scoped listing + deal this suite books. */
    private function requireScoped(): void
    {
        if (self::$scopedVendorId === 0 || self::$scopedBookingId === 0) {
            self::markTestSkipped('no scoped fixture — the decor-account user is missing from this database');
        }
    }

    /**
     * The headcount briefs sitting in one recipient's bell for this wedding.
     *
     * Matched on the word "headcount" so the count is this feature's own rows —
     * "changed the planned headcounts" for the planner, "Headcount change for"
     * for the venue — and not every alert the recipient happens to be holding.
     *
     * @return array<int, object>
     */
    private static function briefs(int $user_id): array
    {
        global $db;
        return $db->fetchAll(
            'SELECT id, message, link FROM tblnotifications
              WHERE user_id = :u AND booking_id = :b AND message LIKE :needle
           ORDER BY id',
            [':u' => $user_id, ':b' => self::BOOKING, ':needle' => '%headcount%']
        );
    }

    /** The function a deal's headcount brief is scoped to (null = the whole wedding). */
    private static function scopeOf(int $deal_id): ?int
    {
        global $db;
        $value = $db->fetchValue('SELECT features_id FROM tblvendor_bookings WHERE id = :i', [':i' => $deal_id]);
        return $value === null ? null : (int) $value;
    }

    /** booking 200's organizer_id — the planner every brief on this wedding goes to. */
    private static function plannerId(): int
    {
        global $db;
        return (int) $db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1',
            [':b' => self::BOOKING]
        );
    }

    private static function sheetPath(int $booking = self::BOOKING, int $user = 200): string
    {
        return '/admin/sheet_venue.php?booking_id=' . $booking . '&user_id=' . $user;
    }

    /** One row of the plan table, as written in the database ('' when absent). */
    private static function dbRow(int $booking_id, int $features_id): array
    {
        global $db;
        $rows = $db->fetchAll(
            'SELECT family, individual, wedding_id FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
            [':b' => $booking_id, ':f' => $features_id]
        );
        return array_map(static fn($r): array => [(int) $r->family, (int) $r->individual, (int) $r->wedding_id], $rows);
    }

    /** The headcount section of a rendered sheet (table only, editor excluded). */
    private static function planRegion(string $body): string
    {
        if (!preg_match('#Headcount plan per function(.+?)(<div class="s-edit">|<div class="s-section">)#s', $body, $m)) {
            return '';
        }
        return $m[1];
    }

    /**
     * The cells of the headcount table, by row. The small "meta" lines inside a
     * cell (the function's time, the "not planned yet" marker, the totals note)
     * are removed first, so a cell compares as the value a human reads.
     *
     * @return array<int, list<string>>
     */
    private static function planTable(string $body): array
    {
        $region = self::planRegion($body);
        if ($region === '') {
            return [];
        }
        preg_match_all('#<tr>(.*?)</tr>#s', $region, $rows);
        $out = [];
        foreach ($rows[1] as $tr) {
            $tr = preg_replace('#<(div|span) class="s-meta">.*?</\1>#s', '', $tr) ?? $tr;
            preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#s', $tr, $cells);
            $out[] = array_map(static fn($c): string => trim(preg_replace('/\s+/', ' ', strip_tags($c)) ?? ''), $cells[1]);
        }
        return $out;
    }

    /**
     * Every input the sheet's headcount editor renders, as a browser would send
     * it. This is the difference between testing a save and testing a guess at
     * its field names: the editor submits EVERY function it prints, so a save
     * also carries the rows the user never touched.
     */
    private static function renderedFormFields(string $body): array
    {
        if (!preg_match('#<form[^>]*action="event_booking_save\.php"[^>]*>(.*?)</form>#s', $body, $m)) {
            self::fail('the venue sheet must render the headcount editor form');
        }
        preg_match_all('#<input\b[^>]*>#i', $m[1], $ins);
        $fields = [];
        foreach ($ins[0] as $inp) {
            if (!preg_match('#name="([^"]+)"#i', $inp, $n) || !preg_match('#value="([^"]*)"#i', $inp, $v)) {
                continue;
            }
            $fields[html_entity_decode($n[1], ENT_QUOTES)] = html_entity_decode($v[1], ENT_QUOTES);
        }
        return $fields;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testSheetListsTheWeddingsOwnFunctionsWithTheirCounts(): void
    {
        $this->requireHttp();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $body = self::http('GET', self::sheetPath())['body'];
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $body, 'the sheet must not leak PHP issues');
        }

        self::assertStringContainsString('Headcount plan per function', $body);
        // Every function of booking 200, from the calendar — planned or not.
        foreach (['Church Wedding', 'Reception', 'After-Party', 'VC Meeting', 'Ruracio Ceremony'] as $title) {
            self::assertStringContainsString($title, $body, "function {$title} must be on the brief");
        }
        // The seeded plan, and the honest state of the functions nobody planned.
        self::assertSame(3, substr_count(self::planRegion($body), 'not planned yet'),
            'the three unplanned functions must say so (and only in the table)');
        $table = self::planTable($body);
        self::assertNotEmpty($table, 'the headcount table must render');

        $cells = [];
        foreach ($table as $row) {
            $cells[$row[0] ?? ''] = $row;
        }
        self::assertArrayHasKey('Church Wedding', $cells);
        self::assertSame('10', $cells['Church Wedding'][2] ?? null, 'the seeded family count must print');
        self::assertSame('50', $cells['Church Wedding'][3] ?? null, 'the seeded individual count must print');
        self::assertSame('20', $cells['Reception'][2] ?? null);
        self::assertSame('100', $cells['Reception'][3] ?? null);
        // The totals the venue actually needs (one cell spanning the first two).
        self::assertSame(['Planned total', '30', '150'], $cells['Planned total'] ?? null);

        // The regression: these rows used to be resolved against the public
        // "real weddings" blog, so the brief printed other couples' posts.
        self::assertStringNotContainsString('Beach Wedding in Diani', $body, 'a blog post must never be a function row');
        self::assertStringNotContainsString('Garden Wedding', $body);

        // The editor is offered on the page where the numbers are shown, and is
        // excluded from the printed output rather than from the screen.
        self::assertStringContainsString('name="family[' . self::UNPLANNED . ']"', $body, 'each function needs an input');
        self::assertStringContainsString('name="individual[' . self::UNPLANNED . ']"', $body);
        self::assertStringContainsString('class="s-edit"', $body);
        self::assertMatchesRegularExpression('/@media print\s*\{.{0,600}?\.s-edit.{0,300}?display:\s*none/s', $body,
            'the editor must be hidden when the sheet is printed');
    }

    #[Group('integration')]
    public function testStaffPlanAFunctionThenChangeItWithoutStackingRows(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // --- plan a function the seed leaves empty --------------------------
        $token = self::fetchCsrf(self::sheetPath());
        $res = self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::UNPLANNED => '3'],
            'individual'      => [self::UNPLANNED => '12'],
        ]);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('sheet_venue.php', $res['location'], 'the save must return to the sheet it was made on');

        $rows = self::dbRow(self::BOOKING, self::UNPLANNED);
        self::assertCount(1, $rows, 'exactly one plan row');
        self::assertSame([3, 12, 7], $rows[0], 'counts stored, and the plan tagged with the booking\'s package');

        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('Headcount plan saved', $body, 'the save must be confirmed where the user landed');
        $cells = [];
        foreach (self::planTable($body) as $row) {
            $cells[$row[0] ?? ''] = $row;
        }
        self::assertSame(['After-Party', 'Karen Gardens — Annex', '3', '12'], $cells['After-Party'] ?? null,
            'the sheet must show what was just saved (and its venue)');
        self::assertSame(['Planned total', '33', '162'], $cells['Planned total'] ?? null, 'the totals must follow');
        // Three of the five functions are planned now: the two the seed ships
        // and the one just written. The note says so, so the venue can see how
        // much of the brief is still provisional.
        self::assertStringContainsString('3 of 5 functions planned', $body);

        // --- change it: same row, new numbers -------------------------------
        $token = self::fetchCsrf(self::sheetPath());
        self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::UNPLANNED => '5'],
            'individual'      => [self::UNPLANNED => '20'],
        ]);
        $rows = self::dbRow(self::BOOKING, self::UNPLANNED);
        self::assertCount(1, $rows, 'a re-save must replace the plan, not add a second row');
        self::assertSame([5, 20, 7], $rows[0]);

        // --- and the seeded rows are untouched by any of this ---------------
        self::assertSame([[10, 50, 7]], self::dbRow(self::BOOKING, 201), 'the seeded Church Wedding plan must not move');
        self::assertSame([[20, 100, 9]], self::dbRow(self::BOOKING, 202), 'the seeded Reception plan must not move');

        // --- a save with nothing new says so, and writes nothing ------------
        $token = self::fetchCsrf(self::sheetPath());
        self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::UNPLANNED => '5'],
            'individual'      => [self::UNPLANNED => '20'],
        ]);
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('No changes to save', $body);
        self::assertCount(1, self::dbRow(self::BOOKING, self::UNPLANNED));

        // --- clearing it back to 0/0 is allowed (the row stays, at zero) ----
        $token = self::fetchCsrf(self::sheetPath());
        self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::UNPLANNED => ''],
            'individual'      => [self::UNPLANNED => ''],
        ]);
        self::assertSame([[0, 0, 7]], self::dbRow(self::BOOKING, self::UNPLANNED), 'an emptied box clears the plan');
    }

    /**
     * The editor on the sheet posts every function, not just the edited one, so
     * "save" must be an edit of the NUMBERS: a function the user did not touch
     * has to come back exactly as it was. The seeded rows carry their own package
     * tag (the Reception row holds 9 while the booking's package is 7), and a
     * re-save must not re-stamp it — that would quietly rewrite data the person
     * pressing Save never saw.
     */
    #[Group('integration')]
    public function testSavingTheWholeRenderedFormLeavesUntouchedFunctionsAlone(): void
    {
        $this->requireHttp();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $body   = self::http('GET', self::sheetPath())['body'];
        $fields = self::renderedFormFields($body);
        self::assertArrayHasKey('_token', $fields, 'the editor form must render its CSRF token');
        self::assertArrayHasKey('family[' . self::UNPLANNED . ']', $fields, 'and a box per function');

        $before201 = self::dbRow(self::BOOKING, 201);
        $before202 = self::dbRow(self::BOOKING, 202);
        self::assertSame(9, $before202[0][2] ?? null,
            'premise: the seeded Reception row carries its own package (9), not the booking\'s (7)');

        // Exactly what the browser sends: every box as rendered, one changed.
        $fields['family[' . self::UNPLANNED . ']']     = '2';
        $fields['individual[' . self::UNPLANNED . ']'] = '9';
        $res = self::http('POST', '/admin/event_booking_save.php', $fields);
        self::assertSame(302, $res['status']);
        self::assertSame([[2, 9, 7]], self::dbRow(self::BOOKING, self::UNPLANNED), 'the edited function must save');

        self::assertSame($before201, self::dbRow(self::BOOKING, 201),
            'a function the user did not touch must come back untouched');
        self::assertSame($before202, self::dbRow(self::BOOKING, 202),
            'including its package tag — a headcount save is not a re-tag');
    }

    #[Group('integration')]
    public function testCouplePlansTheirOwnWeddingAndIsRefusedAnothers(): void
    {
        $this->requireHttp();

        // --- the owning couple ----------------------------------------------
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
        $sheet = self::http('GET', self::sheetPath());
        self::assertSame(200, $sheet['status'], 'a couple must reach their own venue sheet');
        self::assertStringContainsString('Set the planned headcounts', $sheet['body'], 'and with it the editor');

        $token = self::fetchCsrf(self::sheetPath());
        $res = self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::UNPLANNED => '7'],
            'individual'      => [self::UNPLANNED => '6'],
        ]);
        self::assertSame(302, $res['status']);
        self::assertSame([[7, 6, 7]], self::dbRow(self::BOOKING, self::UNPLANNED), 'a couple must be able to plan their own functions');

        // --- another couple, same request, someone else's wedding -----------
        self::login(self::OTHER_EMAIL, self::OTHER_PASS);
        $token = self::fetchCsrf(self::sheetPath(self::OTHER_BOOKING, 201));
        $before = self::dbRow(self::BOOKING, self::UNPLANNED);
        $res = self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::UNPLANNED => '999'],
            'individual'      => [self::UNPLANNED => '999'],
        ]);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('client.php', $res['location'], 'a foreign wedding must bounce to their own dashboard');
        self::assertSame($before, self::dbRow(self::BOOKING, self::UNPLANNED), 'and must write nothing');

        // --- and they may still plan their OWN functions --------------------
        $token = self::fetchCsrf(self::sheetPath(self::OTHER_BOOKING, 201));
        self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::OTHER_BOOKING,
            'user_id'         => '201',
            'family'          => [self::FOREIGN_FUNCTION => '4'],
            'individual'      => [self::FOREIGN_FUNCTION => '18'],
        ]);
        self::assertSame([[4, 18, 6]], self::dbRow(self::OTHER_BOOKING, self::FOREIGN_FUNCTION),
            'the same function id must be writable for the wedding that owns it');
    }

    #[Group('integration')]
    public function testCraftedAndMalformedInputWritesNothing(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // 1. A function belonging to ANOTHER wedding, submitted for this one.
        //    The other wedding's own row is captured first: a refused request
        //    must leave it exactly as it was, not merely fail to add one here.
        $foreignBefore = self::dbRow(self::OTHER_BOOKING, self::FOREIGN_FUNCTION);
        $token = self::fetchCsrf(self::sheetPath());
        $res = self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [self::FOREIGN_FUNCTION => '50'],
            'individual'      => [self::FOREIGN_FUNCTION => '50'],
        ]);
        self::assertSame(302, $res['status']);
        self::assertSame([], self::dbRow(self::BOOKING, self::FOREIGN_FUNCTION),
            'another wedding\'s function must never get a plan row here');
        self::assertSame($foreignBefore, self::dbRow(self::OTHER_BOOKING, self::FOREIGN_FUNCTION),
            'and the other wedding\'s own row must be left exactly as it was');
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('refused', $body, 'the refusal must be explained, not silent');

        // 2. A count that is not a whole number: ignored, and the seeded plan
        //    is left exactly as it was.
        $token = self::fetchCsrf(self::sheetPath());
        self::http('POST', '/admin/event_booking_save.php', [
            '_token'          => $token,
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'user_id'         => '200',
            'family'          => [201 => 'abc'],
            'individual'      => [201 => '-4'],
        ]);
        self::assertSame([[10, 50, 7]], self::dbRow(self::BOOKING, 201), 'a malformed count must not wipe a plan');
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('whole number', $body);

        // 3. No CSRF token at all: refused before anything is read.
        $countBefore = (int) $db->fetchValue('SELECT COUNT(*) FROM tbleventbooking');
        $res = self::http('POST', '/admin/event_booking_save.php', [
            'save_headcounts' => '1',
            'booking_id'      => (string) self::BOOKING,
            'family'          => [self::UNPLANNED => '111'],
            'individual'      => [self::UNPLANNED => '111'],
        ]);
        self::assertSame(302, $res['status']);
        self::assertSame($countBefore, (int) $db->fetchValue('SELECT COUNT(*) FROM tbleventbooking'), 'a tokenless POST must write nothing');
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('Invalid security token', $body);

        // 4. An anonymous visitor never reaches the handler at all.
        self::$jar = [];
        $res = self::http('POST', '/admin/event_booking_save.php', [
            'save_headcounts' => '1', 'booking_id' => (string) self::BOOKING,
            'family' => [self::UNPLANNED => '111'], 'individual' => [self::UNPLANNED => '111'],
        ]);
        self::assertSame(302, $res['status']);
        self::assertStringStartsWith('../', $res['location'], 'an anonymous POST must bounce off-site');
    }

    // ------------------------------------------------------------------
    // The brief: who is told, and what they are told
    // ------------------------------------------------------------------

    /**
     * The wording, with no database or server anywhere near it. This is the text
     * the venue reads off their phone and the planner acts on, and the rule it
     * has to obey is the one the whole feature exists for: say what MOVED. A
     * function reprinted at the numbers it already had teaches the reader to
     * skim, which is how the one number that did change gets missed.
     */
    public function testTheBriefNamesOnlyTheFunctionsWhoseNumbersMoved(): void
    {
        self::assertSame([], HeadcountBrief::lines([]), 'nothing moved, nothing to say');

        $moves = [
            ['title' => 'Reception', 'from' => ['family' => 20, 'individual' => 100], 'to' => ['family' => 25, 'individual' => 100]],
            // Submitted by the form, unchanged: must not appear at all.
            ['title' => 'Church Wedding', 'from' => ['family' => 10, 'individual' => 50], 'to' => ['family' => 10, 'individual' => 50]],
            ['title' => 'After-Party', 'from' => null, 'to' => ['family' => 3, 'individual' => 12]],
            ['title' => 'VC Meeting', 'from' => ['family' => 0, 'individual' => 16], 'to' => ['family' => 0, 'individual' => 0]],
        ];
        self::assertSame([
            'Reception — family 20 → 25',
            'After-Party — now planned for 3 family / 12 individual',
            'VC Meeting — no longer planned (was 0 family / 16 individual)',
        ], HeadcountBrief::lines($moves));

        // Only the side that moved is named, and a function with no title still
        // reads as a function rather than an empty line.
        self::assertSame(
            ['Ceremony — individual 4 → 9'],
            HeadcountBrief::lines([['title' => '  ', 'from' => ['family' => 2, 'individual' => 4], 'to' => ['family' => 2, 'individual' => 9]]])
        );
    }

    /**
     * What the person who made the change is told. An edit that quietly
     * notified nobody is the dead end this release closes, so the "nobody was
     * notified" case is spelled out rather than left to be discovered.
     */
    public function testTheCoupleIsToldWhoWasActuallyReached(): void
    {
        self::assertSame(
            'Karen Gardens and your planner have been sent the changed functions.',
            HeadcountBrief::notice(['planner' => true, 'venue' => true, 'venue_name' => 'Karen Gardens'])
        );
        self::assertSame(
            'Your planner has been sent the changed functions; the venue on file has no portal account to send to.',
            HeadcountBrief::notice(['planner' => true, 'venue' => false, 'venue_name' => 'Karen Gardens', 'venue_on_file' => true])
        );
        self::assertSame(
            'Your planner has been sent the changed functions — no venue is booked on this wedding yet.',
            HeadcountBrief::notice(['planner' => true, 'venue' => false, 'venue_on_file' => false])
        );
        self::assertSame(
            'Nobody was notified — no planner or venue is assigned to this wedding yet.',
            HeadcountBrief::notice(['planner' => false, 'venue' => false])
        );

        // The vendors the same numbers are for (v2.10). The planner is named
        // last so the sentence reads the same however long the list gets.
        self::assertSame(
            'Karen Gardens, Nyama Choma Masters and your planner have been sent the changed functions.',
            HeadcountBrief::notice([
                'planner' => true, 'venue' => true, 'venue_name' => 'Karen Gardens',
                'vendors' => [['name' => 'Nyama Choma Masters', 'category' => 'catering', 'scope' => 'the whole wedding']],
            ])
        );
        // Many recipients read as a list, with the rest counted — not as prose
        // welded onto a name ("…, Safi Décor and 2 more and your planner…").
        self::assertSame(
            'Karen Gardens, Nyama Choma Masters, Safi Décor, 2 more vendors and your planner have been sent the changed functions.',
            HeadcountBrief::notice([
                'planner' => true, 'venue' => true, 'venue_name' => 'Karen Gardens',
                'vendors' => [
                    ['name' => 'Nyama Choma Masters', 'category' => 'catering'],
                    ['name' => 'Safi Décor', 'category' => 'decor'],
                    ['name' => 'Flash Photography', 'category' => 'photography'],
                    ['name' => 'Safari Transport', 'category' => 'transport'],
                ],
            ])
        );
        // A vendor and no venue is still the planner's brief reaching someone.
        self::assertSame(
            'Nyama Choma Masters and your planner have been sent the changed functions.',
            HeadcountBrief::notice([
                'planner' => true, 'venue' => false, 'venue_on_file' => false,
                'vendors' => [['name' => 'Nyama Choma Masters', 'category' => 'catering', 'scope' => 'the whole wedding']],
            ])
        );
        // And when the only booked vendor cannot be reached, the couple are told
        // that rather than told nobody was notified.
        self::assertSame(
            'Nobody could be reached: Safi Décor (decor) has no portal account — pass the numbers on by hand.',
            HeadcountBrief::notice([
                'planner' => false, 'venue' => false, 'venue_on_file' => false,
                'unsent' => [['name' => 'Safi Décor', 'category' => 'decor', 'why' => 'no_portal']],
            ])
        );
        self::assertSame(
            'Your planner has been sent the changed functions — no venue is booked on this wedding yet. Safi Décor (decor) has no portal account — pass the numbers on by hand.',
            HeadcountBrief::notice([
                'planner' => true, 'venue' => false, 'venue_on_file' => false,
                'unsent' => [['name' => 'Safi Décor', 'category' => 'decor', 'why' => 'no_portal']],
            ])
        );
        // A deal pinned to a function the couple has since deleted reaches
        // nobody, and that is exactly what the planner has to be told.
        self::assertSame(
            'Nobody could be reached: The After-Party MC (mc) is scoped to a function that is gone — check that deal.',
            HeadcountBrief::notice(['planner' => false, 'venue' => false, 'unsent' => [['name' => 'The After-Party MC', 'category' => 'mc', 'why' => 'missing_function']]])
        );
    }

    /**
     * The loop the feature is: a couple moves two numbers on the sheet, and BOTH
     * the assigned planner and the venue contact booked on the wedding hear
     * about exactly those two — while the functions they left alone are not in
     * the brief at all, even though the editor submits every box on the page.
     */
    #[Group('integration')]
    public function testACouplesHeadcountChangeBriefsThePlannerAndTheVenueContact(): void
    {
        $this->requireHttp();
        $this->requireVenue();
        global $db;

        $organizer = self::plannerId();
        self::assertGreaterThan(0, $organizer, 'premise: booking 200 has an assigned planner');
        self::assertNotSame($organizer, self::VENUE_USER, 'premise: the venue contact is not also the planner');

        /*
         * Which functions move, and which just ride along in the form. This
         * class plans functions as it goes and the editor submits every box it
         * renders, so the two are read from the database rather than assumed:
         *   - a function with no plan row yet, which the brief must report as
         *     "now planned for", and
         *   - the seeded plan, of which only the individual side moves.
         */
        $gaining = (int) $db->fetchValue(
            'SELECT e.id FROM events e
              WHERE e.booking_id = :b
                AND NOT EXISTS (SELECT 1 FROM tbleventbooking eb
                                 WHERE eb.booking_id = e.booking_id AND eb.features_id = e.id)
           ORDER BY e.id LIMIT 1',
            [':b' => self::BOOKING]
        );
        if ($gaining === 0) {
            self::markTestSkipped('every function of booking 200 already has a plan row — nothing to gain one');
        }
        $titleGaining = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $gaining]);
        $churchRows   = self::dbRow(self::BOOKING, 201);
        self::assertCount(1, $churchRows, 'premise: the seeded Church Wedding plan is on file');
        $wasChurch = $churchRows[0][1];

        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);

        // Exactly what the browser sends: every box the sheet rendered, with two
        // of them moved.
        $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
        self::assertArrayHasKey('individual[201]', $fields, 'premise: the seeded function is on the sheet');
        $fields['family[' . $gaining . ']']     = '8';
        $fields['individual[' . $gaining . ']'] = '30';
        $fields['individual[201]']              = (string) ($wasChurch + 5);

        $plannerBefore = count(self::briefs($organizer));
        $venueBefore   = count(self::briefs(self::VENUE_USER));

        $res = self::http('POST', '/admin/event_booking_save.php', $fields);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('sheet_venue.php', $res['location'], 'the save must return to the sheet');

        $planner = self::briefs($organizer);
        self::assertCount($plannerBefore + 1, $planner, 'the assigned planner must be told');
        $venue = self::briefs(self::VENUE_USER);
        self::assertCount($venueBefore + 1, $venue, 'the booked venue contact must be told');

        $plannerLast = $planner[count($planner) - 1];
        $venueLast   = $venue[count($venue) - 1];
        $plannerMsg  = (string) $plannerLast->message;
        $venueMsg    = (string) $venueLast->message;
        self::assertStringContainsString('Linda Wafula', $plannerMsg, 'the planner is told which wedding moved');
        foreach (['planner' => $plannerMsg, 'venue' => $venueMsg] as $who => $msg) {
            self::assertStringContainsString($titleGaining . ' — now planned for 8 family / 30 individual', $msg,
                "the {$who} brief must carry the function that gained a plan, with both numbers");
            self::assertStringContainsString('Church Wedding — individual ' . $wasChurch . ' → ' . ($wasChurch + 5), $msg,
                "the {$who} brief must carry the side that moved, and only that side");
        }

        // Every other function rode along in the submitted form at the numbers it
        // already had — so not one of them may appear in either brief.
        $others = $db->fetchAll(
            'SELECT id, title FROM events WHERE booking_id = :b AND id NOT IN (' . $gaining . ', 201) ORDER BY id',
            [':b' => self::BOOKING]
        );
        self::assertNotEmpty($others, 'premise: booking 200 has functions beyond the two that moved');
        foreach ($others as $other) {
            $title = trim((string) $other->title);
            if ($title === '') {
                continue;
            }
            self::assertStringNotContainsString($title, $plannerMsg,
                "the planner brief must not reprint '{$title}', which did not change");
            self::assertStringNotContainsString($title, $venueMsg,
                "the venue brief must not reprint '{$title}', which did not change");
        }
        // Each channel opens where that recipient actually works: the planner
        // has the sheet, a vendor cannot open it and has their own dashboard.
        self::assertStringContainsString('sheet_venue.php', (string) $plannerLast->link);
        self::assertSame('vendor_home.php', (string) $venueLast->link);

        // The bell and the queued email both travel from create(), so the venue
        // has the numbers even if they never open the portal.
        $mail = $db->fetchOne(
            'SELECT id, to_email, subject FROM tbl_mail_logs WHERE notification_id = :n LIMIT 1',
            [':n' => (int) $venueLast->id]
        );
        self::assertNotNull($mail, 'the venue brief must queue an email copy with the in-app row');
        self::assertStringContainsString('Headcount change', (string) $mail->subject);

        // And the couple is told who was reached, on the page they land on.
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('Headcount plan saved', $body);
        self::assertStringContainsString('and your planner have been sent the changed functions', $body);
        self::assertStringContainsString(self::VENUE_NAME, $body, 'the sighted recipient is named where the numbers are');
        self::assertStringContainsString('Venue contact', $body);
    }

    /**
     * The two saves that must stay silent. The editor submits every function, so
     * a re-save of the same numbers is the common case — and the planner works
     * this sheet all day, so their own correction must never be announced as if
     * the couple had changed their mind.
     */
    #[Group('integration')]
    public function testASaveThatMovesNothingAndAStaffEditNotifyNobody(): void
    {
        $this->requireHttp();
        $this->requireVenue();

        // --- the couple re-submit the rendered form, untouched ---------------
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
        $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
        $plannerBefore = count(self::briefs(self::plannerId()));
        $venueBefore   = count(self::briefs(self::VENUE_USER));

        $res = self::http('POST', '/admin/event_booking_save.php', $fields);
        self::assertSame(302, $res['status']);
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('No changes to save', $body, 'the couple are told nothing moved');
        self::assertCount($plannerBefore, self::briefs(self::plannerId()), 'a save that moves nothing must not alert the planner');
        self::assertCount($venueBefore, self::briefs(self::VENUE_USER), 'nor the venue');

        // --- the planner's own correction ------------------------------------
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
        $key = 'family[' . self::UNPLANNED . ']';
        $fields[$key] = (string) ((int) ($fields[$key] ?? '0') + 1);
        $res = self::http('POST', '/admin/event_booking_save.php', $fields);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('Headcount plan saved', self::http('GET', self::sheetPath())['body'],
            'premise: the staff save did write');
        self::assertCount($plannerBefore, self::briefs(self::plannerId()),
            'a planner correcting their own sheet must not be briefed on their own edit');
        self::assertCount($venueBefore, self::briefs(self::VENUE_USER),
            'and must not brief the venue on the planner\'s behalf');
    }

    /**
     * The venue nobody can send to. The listing is on the wedding, so it is the
     * recipient — but if it has no portal account there is no bell and no email
     * address, and the brief dies with the planner unless the planner is told to
     * carry it. Both halves are asserted: the planner hears, and hears why the
     * venue did not.
     */
    #[Group('integration')]
    public function testAVenueWithNoPortalAccountLeavesThePlannerToPassItOn(): void
    {
        $this->requireHttp();
        $this->requireVenue();
        global $db;

        $organizer = self::plannerId();
        $db->execute('UPDATE tblvendors SET user_id = NULL WHERE id = :i', [':i' => self::$venueVendorId]);
        try {
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
            $key = 'family[' . self::UNPLANNED . ']';
            $fields[$key] = (string) ((int) ($fields[$key] ?? '0') + 2);

            $plannerBefore = count(self::briefs($organizer));
            $venueBefore   = count(self::briefs(self::VENUE_USER));
            $res = self::http('POST', '/admin/event_booking_save.php', $fields);
            self::assertSame(302, $res['status']);

            $planner = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $planner, 'the planner is still told — they are the only channel left');
            $lastMsg = (string) $planner[count($planner) - 1]->message;
            self::assertStringContainsString('no portal account, so pass this on to them', $lastMsg,
                'and told why the venue could not be sent to');
            self::assertStringContainsString(self::VENUE_NAME, $lastMsg,
                'naming the venue the planner has to call');
            /*
             * The venue itself has no account, so nothing was sent AS the venue.
             * This account can still be a recipient in its own right: the seed's
             * caterer is a separate listing on the same login, booked for the
             * whole wedding, so it covers the moved function and is briefed
             * once — and that brief must be about its own deal, never a venue
             * alert nobody could receive.
             */
            $alsoBooked = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM tblvendor_bookings vb
                   JOIN tblvendors v ON v.id = vb.vendor_id
                  WHERE vb.booking_id = :b AND vb.status IN ('confirmed', 'completed')
                    AND v.user_id = :u AND (vb.features_id IS NULL OR vb.features_id = :f)",
                [':b' => self::BOOKING, ':u' => self::VENUE_USER, ':f' => self::UNPLANNED]
            );
            $venueBells = self::briefs(self::VENUE_USER);
            self::assertCount($venueBefore + ($alsoBooked > 0 ? 1 : 0), $venueBells,
                'the venue has no account, so nothing was sent as the venue — only the deals this login holds of its own');
            if ($alsoBooked > 0) {
                self::assertStringNotContainsString(self::VENUE_NAME, (string) $venueBells[count($venueBells) - 1]->message,
                    'the brief that login got is its own deal, not a venue alert nobody could receive');
            }

            $body = self::http('GET', self::sheetPath())['body'];
            self::assertStringContainsString('no portal account, so the brief has to be passed on by hand', $body,
                'the sheet must say so where the venue contact is listed');
            self::assertStringContainsString(self::VENUE_NAME, $body);
            self::assertStringContainsString('the venue on file has no portal account to send to', $body,
                'and the couple are told what actually happened');
        } finally {
            $db->execute('UPDATE tblvendors SET user_id = :u WHERE id = :i',
                [':u' => self::VENUE_USER, ':i' => self::$venueVendorId]);
        }
    }

    /**
     * The other vendors the numbers are for. Two functions move in one save and
     * the decorator's deal names only one of them: the caterer, the MC and the
     * photographer are all booked on this wedding, and being woken up by a
     * change to a function that is not theirs is how a vendor learns to mute the
     * alert. The venue, which hosts the whole day, still gets both.
     */
    #[Group('integration')]
    public function testAVendorHearsOnlyTheFunctionItsDealCovers(): void
    {
        $this->requireHttp();
        $this->requireVenue();
        $this->requireScoped();
        global $db;

        $organizer = self::plannerId();
        self::assertNotSame($organizer, self::SCOPE_USER, 'premise: the scoped vendor is not the planner');
        self::assertNotSame($organizer, self::VENUE_USER, 'premise: nor is the venue contact');

        // Two seeded functions move, one inside the scoped deal and one outside
        // it. Read from the database rather than assumed: earlier tests in this
        // class write to this plan table.
        $covered   = self::dbRow(self::BOOKING, self::SCOPED_FUNCTION);
        $uncovered = self::dbRow(self::BOOKING, 202); // Reception
        self::assertCount(1, $covered, 'premise: the scoped fixture covers the seeded Church Wedding plan');
        self::assertCount(1, $uncovered, 'premise: the seeded Reception plan is on file');
        $wasCovered   = (int) $covered[0][1];
        $wasUncovered = (int) $uncovered[0][1];

        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
        $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
        self::assertArrayHasKey('individual[' . self::SCOPED_FUNCTION . ']', $fields, 'premise: the scoped function is on the sheet');
        $fields['individual[' . self::SCOPED_FUNCTION . ']'] = (string) ($wasCovered + 7);
        $fields['individual[202]'] = (string) ($wasUncovered + 9);

        $scopeBefore   = count(self::briefs(self::SCOPE_USER));
        $venueBefore   = count(self::briefs(self::VENUE_USER));
        $plannerBefore = count(self::briefs($organizer));

        $res = self::http('POST', '/admin/event_booking_save.php', $fields);
        self::assertSame(302, $res['status']);

        // ---- the scoped vendor: its own function, and only that ---------------
        $scoped = self::briefs(self::SCOPE_USER);
        self::assertCount($scopeBefore + 1, $scoped, 'the vendor whose deal covers a moved function must be told');
        $scopedMsg = (string) $scoped[count($scoped) - 1]->message;
        self::assertStringContainsString(
            'Church Wedding — individual ' . $wasCovered . ' → ' . ($wasCovered + 7),
            $scopedMsg,
            'the brief must carry the numbers of the function the deal covers'
        );
        self::assertStringNotContainsString('Reception', $scopedMsg,
            'the reception also moved, and this deal does not cover it — its vendor must not be woken up by it');
        self::assertStringContainsString('Your deal covers Church Wedding', $scopedMsg,
            'and told which of the functions their deal names this brief is about');
        self::assertSame('vendor_home.php', (string) $scoped[count($scoped) - 1]->link,
            'a vendor works from their own dashboard, not the sheet');

        // ---- one alert per account, however many listings it holds ------------
        $listings = (int) $db->fetchValue('SELECT COUNT(*) FROM tblvendors WHERE user_id = :u', [':u' => self::VENUE_USER]);
        self::assertGreaterThan(1, $listings, 'premise: the venue fixture\'s login holds other listings on this wedding too');
        $venue = self::briefs(self::VENUE_USER);
        self::assertCount($venueBefore + 1, $venue,
            'one change is one alert per account, not one per listing: the venue fixture and the seeded caterer share this login');
        $venueMsg = (string) $venue[count($venue) - 1]->message;
        self::assertStringContainsString('Church Wedding — individual ' . $wasCovered . ' → ' . ($wasCovered + 7), $venueMsg);
        self::assertStringContainsString('Reception — individual ' . $wasUncovered . ' → ' . ($wasUncovered + 9), $venueMsg,
            'the venue hosts the whole day, so both moves are its business');

        // ---- the planner: the same numbers, plus who else has them -----------
        $planner = self::briefs($organizer);
        self::assertCount($plannerBefore + 1, $planner);
        $plannerMsg = (string) $planner[count($planner) - 1]->message;

        // tblnotifications.message is varchar(255): a brief that outgrew it would
        // come back cut off mid-number, which is how a vendor reads "individual
        // 62 → 6" and orders for the wrong party.
        foreach (['planner' => $plannerMsg, 'scoped vendor' => $scopedMsg, 'venue account' => $venueMsg] as $who => $message) {
            self::assertLessThanOrEqual(255, mb_strlen($message),
                "the {$who} message must fit the bell's column, not be clipped by it");
            self::assertStringEndsWith('.', $message, "and must end on a sentence, not mid-number");
        }
        self::assertStringContainsString('Also sent to ' . self::SCOPE_NAME, $plannerMsg,
            'the planner must know which vendor the same numbers went to');
        self::assertStringContainsString('Reception — individual ' . $wasUncovered . ' → ' . ($wasUncovered + 9), $plannerMsg,
            'and still gets every moved function, scoped vendors or not');

        // ---- and it is visible where the decisions are -----------------------
        $body = self::http('GET', self::sheetPath())['body'];
        self::assertStringContainsString('Vendor brief', $body, 'the sheet must name the vendors the brief goes to');
        self::assertStringContainsString(self::SCOPE_NAME, $body);
        self::assertStringContainsString('Decor · Church Wedding', $body,
            'with the function their deal covers, so a scope set wrongly is visible here');

        self::login('chebet@floral.co.ke', 'Vendor@2024');
        $dashboard = self::http('GET', '/admin/vendor_home.php')['body'];
        self::assertStringContainsString('Headcount brief: Church Wedding', $dashboard,
            'the vendor affected by that scope must be able to see it on their own ledger');
    }

    /**
     * The vendor nobody can send to. A confirmed deal with no portal account is
     * still an assignment — the numbers are that vendor's — so the planner is the
     * only channel left and is told so, with the vendor named and the reason.
     */
    #[Group('integration')]
    public function testABookedVendorNobodyCanSendToIsNamedToThePlanner(): void
    {
        $this->requireHttp();
        $this->requireVenue();
        $this->requireScoped();
        global $db;

        $organizer = self::plannerId();
        $db->execute('UPDATE tblvendors SET user_id = NULL WHERE id = :i', [':i' => self::$scopedVendorId]);
        try {
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
            $was = (int) (self::dbRow(self::BOOKING, self::SCOPED_FUNCTION)[0][1] ?? 0);
            $fields['individual[' . self::SCOPED_FUNCTION . ']'] = (string) ($was + 4);

            $plannerBefore = count(self::briefs($organizer));
            $scopeBefore   = count(self::briefs(self::SCOPE_USER));
            $res = self::http('POST', '/admin/event_booking_save.php', $fields);
            self::assertSame(302, $res['status']);

            $planner = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $planner, 'the planner is still told — they are the only channel left');
            $plannerMsg = (string) $planner[count($planner) - 1]->message;
            self::assertStringContainsString(
                self::SCOPE_NAME . ' (decor) has no portal account — pass the numbers on by hand',
                $plannerMsg,
                'and told which booked vendor they have to carry it to, and why'
            );
            self::assertCount($scopeBefore, self::briefs(self::SCOPE_USER),
                'nothing may be sent to a listing that has no account');

            $body = self::http('GET', self::sheetPath())['body'];
            self::assertStringContainsString(self::SCOPE_NAME, $body);
            self::assertStringContainsString('no portal account, so the brief has to be passed on by hand', $body,
                'the sheet must say so where the vendor brief is listed');
        } finally {
            $db->execute('UPDATE tblvendors SET user_id = :u WHERE id = :i',
                [':u' => self::SCOPE_USER, ':i' => self::$scopedVendorId]);
        }
    }

    /**
     * Where the scope comes from. A scope is what decides whether a vendor hears
     * about a change, so it has to be settable, correctable, and refused when it
     * names something that cannot be that vendor's business: another wedding's
     * function could never match a headcount change, so storing it would look set
     * and quietly reach nobody.
     */
    #[Group('integration')]
    public function testStaffScopeADealAndAnotherWeddingsFunctionIsRefused(): void
    {
        $this->requireHttp();
        $this->requireScoped();
        global $db;

        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $path = '/admin/vendor_enquiries.php';
        $body = self::http('GET', $path)['body'];
        self::assertStringContainsString('Headcount brief', $body, 'the ledger must show every deal\'s brief scope');
        self::assertStringContainsString(self::SCOPE_NAME, $body);
        self::assertStringContainsString('value="' . self::$scopedBookingId . '"', $body,
            'and offer the control that sets it on that deal');
        $token = self::fetchCsrf($path);

        // Re-scope the deal to another function of the SAME wedding.
        $res = self::http('POST', $path, [
            '_token' => $token, 'scope_deal_id' => (string) self::$scopedBookingId, 'features_id' => '202',
        ]);
        self::assertSame(302, $res['status']);
        self::assertSame(202, self::scopeOf(self::$scopedBookingId));
        self::assertStringContainsString('Headcount brief: Reception', self::http('GET', $path)['body'],
            'and read the new scope back where it was set');

        // Another wedding's function: refused, and said so.
        $res = self::http('POST', $path, [
            '_token' => $token, 'scope_deal_id' => (string) self::$scopedBookingId, 'features_id' => (string) self::FOREIGN_FUNCTION,
        ]);
        self::assertSame(302, $res['status']);
        self::assertSame(202, self::scopeOf(self::$scopedBookingId),
            'a function of another wedding must never become this deal\'s scope');
        self::assertStringContainsString('not on this wedding', self::http('GET', $path)['body'], 'the refusal must be visible');

        // Back to the whole wedding: the default, stored as NULL (not 0), which is
        // also what every deal written before v2.10 means.
        $res = self::http('POST', $path, [
            '_token' => $token, 'scope_deal_id' => (string) self::$scopedBookingId, 'features_id' => '0',
        ]);
        self::assertSame(302, $res['status']);
        self::assertNull(self::scopeOf(self::$scopedBookingId), 'the whole wedding is stored as NULL, not 0');
        self::assertStringContainsString('Headcount brief: the whole wedding', self::http('GET', $path)['body']);

        // A POST with no CSRF token writes nothing at all.
        $res = self::http('POST', $path, [
            'scope_deal_id' => (string) self::$scopedBookingId, 'features_id' => '202',
        ]);
        self::assertSame(302, $res['status']);
        self::assertNull(self::scopeOf(self::$scopedBookingId), 'a tokenless scope save must write nothing');
    }

    // ------------------------------------------------------------------
    // The capacity check: the plan outgrowing the package it was sold on
    // ------------------------------------------------------------------

    /**
     * The wording, with no database and no server in sight. Two readers, two
     * sentences from the same fact: the planner is told which vendor has to be
     * called (that is their job), the vendor only needs their own package and the
     * number that no longer fits under it.
     */
    public function testTheCapacityWarningNamesThePackageThatNoLongerCoversTheDay(): void
    {
        $deal = [
            'deal_id' => 200, 'vendor_id' => 200, 'business_name' => 'Nyama Choma Masters — Nakuru',
            'category' => 'catering', 'user_id' => 8, 'features_id' => 0, 'scope' => 'wedding',
            'function_title' => '', 'package_id' => 201, 'package_title' => 'Choma Premium — 250 pax',
            'capacity' => 250, 'over_by' => 10,
        ];
        $breach = ['features_id' => 202, 'title' => 'Reception', 'total' => 260, 'was' => 120, 'deals' => [$deal]];

        self::assertSame(
            'Reception is planned for 260 (was 120) — over the package Nyama Choma Masters — Nakuru is booked on (Choma Premium — 250 pax).',
            PackageCapacity::planner_clause($breach)
        );
        self::assertSame(
            'Reception is planned for 260 (was 120) — over the package you are booked on (Choma Premium — 250 pax).',
            PackageCapacity::vendor_clause($breach, [$deal])
        );
        self::assertSame(
            'Over capacity: ' . PackageCapacity::planner_clause($breach),
            PackageCapacity::notice([$breach]),
            'the sheet says the position and nothing about who was sent what'
        );
        self::assertSame(
            'Over capacity: ' . PackageCapacity::planner_clause($breach) . ' Nyama Choma Masters — Nakuru has been warned.',
            PackageCapacity::notice([$breach], 'Nyama Choma Masters — Nakuru has been warned.')
        );
        self::assertSame('', PackageCapacity::notice([]), 'no breach, nothing to say');

        // A function gaining its FIRST plan has no earlier count to quote.
        $first = $breach;
        $first['was'] = null;
        self::assertStringStartsWith('Reception is planned for 260 — over ', PackageCapacity::planner_clause($first));

        // A title that does not already state the limit gets it spelled out: "Full
        // Decor" alone would leave the reader to guess what it covers.
        $plain = $deal;
        $plain['package_title'] = 'Full Decor';
        $plain['capacity'] = 200;
        $plain['business_name'] = 'Safi Décor';
        self::assertSame(
            'Reception is planned for 260 (was 120) — over the package you are booked on (Full Decor — up to 200 pax).',
            PackageCapacity::vendor_clause($breach, [$plain])
        );

        // Two of the vendor's own packages on one function, and two vendors on
        // one package: either way the reader is told, and neither sentence
        // repeats a number the title already carries.
        self::assertSame(
            'Reception is planned for 260 (was 120) — over the packages you are booked on (Choma Premium — 250 pax and Full Decor — up to 200 pax).',
            PackageCapacity::vendor_clause($breach, [$deal, $plain])
        );
        self::assertSame(
            'Reception is planned for 260 (was 120) — over the package Nyama Choma Masters — Nakuru is booked on (Choma Premium — 250 pax) and the package Safi Décor is booked on (Full Decor — up to 200 pax).',
            PackageCapacity::planner_clause(['features_id' => 202, 'title' => 'Reception', 'total' => 260, 'was' => 120, 'deals' => [$deal, $plain]])
        );

        // One account, however many deals it holds, gets ONE entry: its own
        // packages, in one message. A breaching vendor nobody can reach is named
        // to the planner instead of being quietly dropped.
        $second = $breach;
        $second['features_id'] = 201;
        $second['title'] = 'Church Wedding';
        $second['total'] = 300;

        $offline = $deal;
        $offline['user_id'] = 0;
        $offline['business_name'] = 'Off-Grid Catering';
        $third = $breach;
        $third['deals'] = [$offline];

        $clauses = PackageCapacity::clauses([$breach, $second, $third]);
        self::assertSame([8], array_keys($clauses['vendors']), 'one entry per breaching ACCOUNT, not per function');
        self::assertSame(['Nyama Choma Masters — Nakuru'], $clauses['vendors'][8]['names']);
        self::assertSame('catering', $clauses['vendors'][8]['category']);
        self::assertSame(
            PackageCapacity::vendor_clause($breach, [$deal]) . ' ' . PackageCapacity::vendor_clause($second, [$deal]),
            $clauses['vendors'][8]['text'],
            'two breaching functions of one account read as two sentences in one message'
        );
        self::assertSame(
            PackageCapacity::planner_clause($breach) . ' '
                . PackageCapacity::planner_clause($second) . ' '
                . PackageCapacity::planner_clause($third),
            $clauses['planner'],
            'the planner gets every breaching function, including the vendor nobody can reach'
        );
        // The same warning for the BELL, minus the count the brief's own moved
        // line already quotes: on the shipped seed those characters are what let
        // the way out ride in the same 255-char column as the warning.
        self::assertSame(
            PackageCapacity::planner_clause($breach, false) . ' '
                . PackageCapacity::planner_clause($second, false) . ' '
                . PackageCapacity::planner_clause($third, false),
            $clauses['planner_brief']
        );
        self::assertStringContainsString('(was 120)', $clauses['planner']);
        self::assertStringNotContainsString('(was ', $clauses['planner_brief']);
        // And which functions those sentences are about, so a caller that also
        // lists the moved functions can print each one once (HeadcountBrief::
        // announce() does: the warning states the function, so its moved line goes).
        self::assertSame([202, 201], $clauses['features'], 'the breaching functions, in order, once each');
        self::assertSame(
            [['name' => 'Off-Grid Catering', 'category' => 'catering', 'why' => 'no_portal']],
            $clauses['unsent']
        );
    }

    /**
     * The loop a couple gets: a function raised past the capacity of the package
     * the caterer was booked on. The caterer hears (they ordered against a smaller
     * number) and so does the planner (the deal has to be re-priced) — in the SAME
     * message as the change, because two bells from one save is how a vendor
     * learns to stop reading the bell.
     */
    #[Group('integration')]
    public function testACouplesChangeThatOutgrowsAPackageWarnsTheVendorInTheSameBrief(): void
    {
        $this->requireHttp();
        global $db;

        $deal = self::capacityDeal();
        if ($deal === null) {
            self::markTestSkipped('no confirmed deal on booking 200 carries a package with a guest capacity');
        }
        $capacity = (int) $deal['capacity'];
        $vendor   = (int) $deal['user_id'];
        $name     = (string) $deal['business_name'];
        $package  = (string) $deal['package_title'];
        $function = (int) $deal['features_id'] > 0 ? (int) $deal['features_id'] : self::UNPLANNED;
        $title    = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);
        $organizer = self::plannerId();
        self::assertNotSame($organizer, $vendor, 'premise: the vendor whose package is outgrown is not the planner');
        self::assertSame([], PackageCapacity::breaches(self::BOOKING, self::planTotals()),
            'premise: the plan on file fits inside every package booked on this wedding');

        $before = self::dbRow(self::BOOKING, $function);
        try {
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
            self::assertArrayHasKey('family[' . $function . ']', $fields, 'premise: the function is on the sheet');
            $fields['family[' . $function . ']']     = (string) $capacity;
            $fields['individual[' . $function . ']'] = '5';

            $vendorBefore  = count(self::briefs($vendor));
            $plannerBefore = count(self::briefs($organizer));

            $res = self::http('POST', '/admin/event_booking_save.php', $fields);
            self::assertSame(302, $res['status']);
            self::assertSame([$capacity, 5], array_slice(self::dbRow(self::BOOKING, $function)[0] ?? [], 0, 2),
                'the plan must be written before it can be judged');

            // ---- the vendor: one message, the change AND the limit it broke ------
            $bells = self::briefs($vendor);
            self::assertCount($vendorBefore + 1, $bells, 'the vendor holding the outgrown package must be told — once');
            $last = $bells[count($bells) - 1];
            $msg  = (string) $last->message;
            self::assertStringContainsString('Headcount change for ', $msg, 'it is the brief, not a second alert about the same save');
            self::assertStringContainsString($title . ' is planned for ' . ($capacity + 5), $msg,
                'stating the number that no longer fits');
            self::assertStringContainsString('over the package you are booked on (' . $package . ')', $msg,
                'and the package it does not fit in');
            self::assertLessThanOrEqual(255, mb_strlen($msg), 'the message must fit the bell column, not be clipped by it');
            self::assertStringEndsWith('.', $msg, 'and must end on a sentence, not mid-number');
            self::assertNotNull(
                $db->fetchOne('SELECT id FROM tbl_mail_logs WHERE notification_id = :n LIMIT 1', [':n' => (int) $last->id]),
                'the queued email must carry it too — the vendor may never open the portal'
            );

            // ---- the planner: the same numbers, and whose package is now too small --
            $plannerBells = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $plannerBells, 'the planner must know the deal no longer covers the day');
            $plannerMsg = (string) $plannerBells[count($plannerBells) - 1]->message;
            self::assertStringContainsString('over the package ' . $name . ' is booked on (' . $package . ')', $plannerMsg,
                'naming the vendor they have to re-price, and the package they sold');

            // ---- and what the save did is said where the numbers are -------------
            $body = self::http('GET', self::sheetPath())['body'];
            self::assertStringContainsString('Headcount plan saved', $body);
            self::assertStringContainsString('Over capacity: ' . $title . ' is planned for ' . ($capacity + 5), $body,
                'the couple are told their plan outgrew a booking they made');

            // A fresh session has no flash to lean on: the sheet prints the
            // position by itself, which is what a planner reads before they call.
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $staff = self::http('GET', self::sheetPath())['body'];
            self::assertStringNotContainsString('Headcount plan saved', $staff, 'premise: this is the page, not the save message');
            foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
                self::assertStringNotContainsString($marker, $staff,
                    'printing the warning must not cost the sheet a PHP issue');
            }
            self::assertStringContainsString('Over capacity: ', self::planRegion($staff),
                'the warning must be printed with the headcount table, not only flashed once');
            self::assertStringContainsString($name, $staff);
        } finally {
            self::restorePlanRow(self::BOOKING, $function, $before);
        }
    }

    /**
     * The planner's own save. The brief stays silent on it (v2.10: the planner
     * works this sheet all day and their corrections must not echo to vendors as
     * if the couple had changed their mind) — but a package that no longer covers
     * the day is a fact about the DEAL, not about who typed the number: the caterer
     * has ordered against 250 and has to be told, and the assigned planner is told
     * unless they were the one who saved.
     */
    #[Group('integration')]
    public function testAStaffSaveThatOutgrowsAPackageWarnsTheVendorButNotTheBrief(): void
    {
        $this->requireHttp();
        global $db;

        $deal = self::capacityDeal();
        if ($deal === null) {
            self::markTestSkipped('no confirmed deal on booking 200 carries a package with a guest capacity');
        }
        $capacity = (int) $deal['capacity'];
        $vendor   = (int) $deal['user_id'];
        $name     = (string) $deal['business_name'];
        $package  = (string) $deal['package_title'];
        $function = (int) $deal['features_id'] > 0 ? (int) $deal['features_id'] : self::UNPLANNED;
        $title    = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);

        $organizer = self::plannerId();
        $saver     = (int) $db->fetchValue('SELECT id FROM tblusers WHERE email = :e LIMIT 1', [':e' => self::STAFF_EMAIL]);
        /*
         * The staff login this suite uses saves the sheet of a wedding whose
         * planner is somebody else: that is the case where the assigned planner
         * still has to hear. (The planner's own save is the case below, and it is
         * asserted directly because the notification is the same either way.)
         */
        self::assertNotSame($organizer, $saver, 'premise: another staff member saves, not the assigned planner');

        $before = self::dbRow(self::BOOKING, $function);
        try {
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
            $fields['family[' . $function . ']']     = (string) $capacity;
            $fields['individual[' . $function . ']'] = '7';

            $vendorBefore  = count(self::briefs($vendor));
            $plannerBefore = count(self::briefs($organizer));

            $res = self::http('POST', '/admin/event_booking_save.php', $fields);
            self::assertSame(302, $res['status']);

            $bells = self::briefs($vendor);
            self::assertCount($vendorBefore + 1, $bells, 'the vendor on the outgrown package must be warned');
            $msg = (string) $bells[count($bells) - 1]->message;
            self::assertStringContainsString('Headcount check — ', $msg, 'as a capacity check, not as a change');
            self::assertStringContainsString($title . ' is planned for ' . ($capacity + 7), $msg);
            self::assertStringContainsString('over the package you are booked on (' . $package . ')', $msg);
            self::assertStringNotContainsString('Headcount change for', $msg,
                'a staff save still says nothing about the numbers as a change');
            self::assertLessThanOrEqual(255, mb_strlen($msg));
            self::assertSame('vendor_home.php', (string) $bells[count($bells) - 1]->link);

            $plannerBells = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $plannerBells,
                'the assigned planner is warned too — a colleague saved, and it is the planner who re-prices the deal');
            $plannerMsg = (string) $plannerBells[count($plannerBells) - 1]->message;
            self::assertStringContainsString('over the package ' . $name . ' is booked on', $plannerMsg);
            self::assertStringContainsString('sheet_venue.php', (string) $plannerBells[count($plannerBells) - 1]->link,
                'the planner is pointed at the numbers, not at a vendor dashboard');

            // The person who saved sees what the save did, and that it landed.
            $body = self::http('GET', self::sheetPath())['body'];
            self::assertStringContainsString('Over capacity: ' . $title . ' is planned for ' . ($capacity + 7), $body);
            self::assertMatchesRegularExpression(
                '/' . preg_quote($name, '/') . ' (and your planner )?(has|have) been warned\./',
                $body,
                'including who was warned, so nobody has to assume the vendor heard'
            );
        } finally {
            self::restorePlanRow(self::BOOKING, $function, $before);
        }

        /*
         * The planner's OWN save: no bell for the person who pressed the button
         * (the page has just said it in words), and the vendor is warned anyway.
         * Called directly rather than through the page: the recipient rule, not
         * the routing, is what is being asserted.
         */
        $clauses = PackageCapacity::clauses(PackageCapacity::breaches(self::BOOKING, [[
            'features_id' => $function,
            'title'       => $title,
            'total'       => $capacity + 7,
            'was'         => max(1, $capacity - 3),
        ]]));
        self::assertNotSame([], $clauses['vendors'], 'premise: the plan is still over the package it was left at');
        $vendorBefore  = count(self::briefs($vendor));
        $plannerBefore = count(self::briefs($organizer));
        HeadcountBrief::announce_capacity(self::BOOKING, $clauses, $organizer);
        self::assertCount($plannerBefore, self::briefs($organizer),
            'the planner is not belled about the save they just made themselves');
        self::assertCount($vendorBefore + 1, self::briefs($vendor),
            'and the vendor is warned all the same — the plan does not fit, whoever typed it');
    }

    /**
     * What is NOT a breach, and the three ways a deal stays out of it: it was
     * never given a package (a limit nobody sold), it is still pending (an
     * enquiry is not an assignment), and its scope does not reach the function
     * (that vendor is not catering it). A warning about a limit that is not that
     * vendor's is how a vendor learns to ignore warnings.
     */
    #[Group('integration')]
    public function testOnlyAPackageBookedOnTheFunctionIsJudged(): void
    {
        $this->requireHttp();
        $this->requireScoped();
        global $db;

        // A package with a small limit, on the deal that covers ONE function.
        $db->execute(
            "INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
             VALUES (:v, '[capacity probe] 10 pax', 'Fixture for tests/VenueHeadcountPlanTest', 100, 10, 2, 1)",
            [':v' => self::$scopedVendorId]
        );
        $package = (int) $db->fetchValue('SELECT MAX(id) FROM tblvendor_packages');
        $dealBefore = $db->fetchOne(
            'SELECT features_id, package_id FROM tblvendor_bookings WHERE id = :i',
            [':i' => self::$scopedBookingId]
        );
        $function = self::SCOPED_FUNCTION;
        $title    = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);

        try {
            $db->execute(
                'UPDATE tblvendor_bookings SET features_id = :f, package_id = :p WHERE id = :i',
                [':f' => $function, ':p' => $package, ':i' => self::$scopedBookingId]
            );

            // (a) the function the deal covers, planned past the limit
            $over = PackageCapacity::breaches(self::BOOKING, [['features_id' => $function, 'title' => $title, 'total' => 100]]);
            self::assertCount(1, $over, 'the package booked on this function is judged');
            self::assertSame($title, $over[0]['title']);
            self::assertSame(100, $over[0]['total']);
            self::assertContains(self::$scopedBookingId, array_column($over[0]['deals'], 'deal_id'));
            self::assertSame(10, (int) $over[0]['deals'][0]['capacity']);
            self::assertSame(90, (int) $over[0]['deals'][0]['over_by'], 'and the margin it is over by is recorded');

            // (b) a function that deal does NOT cover: 100 is under the caterer's
            //     250 as well, so the whole answer must be silence.
            self::assertSame([], PackageCapacity::breaches(self::BOOKING, [['features_id' => 202, 'title' => 'Reception', 'total' => 100]]),
                'a scoped deal is not asked about a function its deal does not cover');

            // (c) unplanned is not a breach: 0 guests fit anything.
            self::assertSame([], PackageCapacity::breaches(self::BOOKING, [['features_id' => $function, 'title' => $title, 'total' => 0]]));

            // (d) a package with no limit stated is not a limit of zero.
            $db->execute('UPDATE tblvendor_bookings SET package_id = NULL WHERE id = :i', [':i' => self::$scopedBookingId]);
            self::assertSame([], PackageCapacity::breaches(self::BOOKING, [['features_id' => $function, 'title' => $title, 'total' => 100]]),
                'a deal with no package chosen states no guest capacity');
            $db->execute('UPDATE tblvendor_bookings SET package_id = :p WHERE id = :i', [':p' => $package, ':i' => self::$scopedBookingId]);

            // (e) and the deals that must never be in the answer for a big plan:
            //     the venue fixture states no limit, the scoped deal is elsewhere,
            //     and a pending enquiry is not an assignment.
            $big = PackageCapacity::breaches(self::BOOKING, [['features_id' => 202, 'title' => 'Reception', 'total' => 400]]);
            $dealIds = [];
            foreach ($big as $breach) {
                foreach ($breach['deals'] as $breachDeal) {
                    $dealIds[] = (int) $breachDeal['deal_id'];
                }
            }
            self::assertNotSame([], $dealIds, 'premise: a plan this size outgrows the catering package on file');
            self::assertNotContains(self::$venueBookingId, $dealIds, 'a deal with no package states no limit');
            self::assertNotContains(self::$scopedBookingId, $dealIds, 'nor is one whose scope does not reach this function');
            $pending = $db->fetchAll(
                "SELECT vb.id FROM tblvendor_bookings vb
                   JOIN tblvendor_packages p ON p.id = vb.package_id
                  WHERE vb.booking_id = :b AND vb.status = 'pending' AND p.capacity > 0",
                [':b' => self::BOOKING]
            );
            foreach ($pending as $row) {
                self::assertNotContains((int) $row->id, $dealIds,
                    'an enquiry is not an assignment: a pending deal is never outgrown');
            }
        } finally {
            $db->execute(
                'UPDATE tblvendor_bookings SET features_id = :f, package_id = :p WHERE id = :i',
                [
                    ':f' => $dealBefore->features_id ?? null,
                    ':p' => $dealBefore->package_id ?? null,
                    ':i' => self::$scopedBookingId,
                ]
            );
            $db->execute('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $package]);
        }
    }

    /**
     * The way out of a breach, as the planner reads it: the same vendor's next
     * package up and what it costs against the one on the deal — or, when the
     * vendor has nothing that fits, the honest answer instead of a blank.
     */
    public function testTheWayOutOfABreachNamesTheNextPackageAndWhatItCosts(): void
    {
        // $fits is null for the "nothing of theirs fits" cases, where the ceiling
        // (or its absence) is the whole answer.
        $upgrade = static function (?array $fits, array $rest = []): array {
            return $rest + [
                'deal_id'       => 200,
                'vendor_id'     => 200,
                'business_name' => 'Nyama Choma Masters — Nakuru',
                'required'      => 260,
                'sold_capacity' => 250,
                'fits'          => $fits,
                'ceiling'       => null,
            ];
        };
        $priced = static fn(int $price, float $more): array => [
            'package_id' => 400, 'title' => 'Choma Royal — 400 pax', 'capacity' => 400,
            'price' => $price, 'more' => $more, 'was_price' => 120000,
        ];

        self::assertSame(
            'Next package up: Choma Royal — 400 pax — KSh 60,000 more.',
            PackageCapacity::upgrade_sentence($upgrade($priced(180000, 60000.0)))
        );
        // A title that does not state its own limit gets it spelled out, the same
        // way the warning's own labels do. (A title that DOES carry the number —
        // "Choma Royal — 400 pax" above, or "Banquet 400" — is left alone: the
        // capacity is already in it, and printing it again reads as two limits.)
        self::assertSame(
            'Next package up: Banquet Hall — up to 400 pax — KSh 60,000 more.',
            PackageCapacity::upgrade_sentence($upgrade(['package_id' => 9, 'title' => 'Banquet Hall', 'capacity' => 400, 'price' => 180000, 'more' => 60000.0, 'was_price' => 120000]))
        );
        self::assertSame(
            'Next package up: Banquet 400 — KSh 60,000 more.',
            PackageCapacity::upgrade_sentence($upgrade(['package_id' => 9, 'title' => 'Banquet 400', 'capacity' => 400, 'price' => 180000, 'more' => 60000.0, 'was_price' => 120000]))
        );
        // The difference is the fact, in both directions, and no gap at all is
        // said as no gap.
        self::assertStringEndsWith('— the same price.', PackageCapacity::upgrade_sentence($upgrade($priced(120000, 0.0))));
        self::assertStringEndsWith('— KSh 5,000 less.', PackageCapacity::upgrade_sentence($upgrade($priced(115000, -5000.0))));
        // An unstated price is not a guessed one: no money at all when the new
        // package has none, and the plain price (no comparison) when only the new
        // one does.
        self::assertSame('Next package up: Choma Royal — 400 pax.',
            PackageCapacity::upgrade_sentence($upgrade($priced(0, -120000.0))));
        // …and the sold package carries no price to compare against (a hand-made
        // deal): the new price is stated plainly rather than as a difference.
        $noBaseline = $priced(180000, 180000.0);
        $noBaseline['was_price'] = 0;
        self::assertStringEndsWith('— KSh 180,000.',
            PackageCapacity::upgrade_sentence($upgrade($noBaseline)));

        // Nothing fits. The ceiling is only named when it IS bigger than what the
        // deal is on; otherwise the answer is that the vendor has to quote.
        self::assertSame(
            'Nothing of theirs covers 300 — their biggest is Banquet Hall — up to 400 pax.',
            PackageCapacity::upgrade_sentence($upgrade(null, [
                'required' => 300, 'fits' => null,
                'ceiling'  => ['package_id' => 9, 'title' => 'Banquet Hall', 'capacity' => 400, 'price' => 0.0],
            ]))
        );
        $quote = 'No bigger package on file — ask Nyama Choma Masters — Nakuru for a quote for 260.';
        self::assertSame($quote, PackageCapacity::upgrade_sentence($upgrade(null, [
            'fits' => null, 'ceiling' => ['package_id' => 200, 'title' => 'Choma Basic — 100 pax', 'capacity' => 100, 'price' => 45000.0],
        ])), 'a smaller package is not "their biggest"');
        self::assertSame($quote, PackageCapacity::upgrade_sentence($upgrade(null, ['fits' => null, 'ceiling' => null])));
    }

    /**
     * The whole loop, once the plan has outgrown a package: the planner is told
     * the warning AND the way out (the same vendor's next package up, with what it
     * costs), the sheet prints both where the numbers are with the one step that
     * acts on it, the vendor hears the limit but not the planner's options, and
     * the couple see the breach without being pushed at a price change their
     * planner has not framed.
     */
    #[Group('integration')]
    public function testAPlanThatOutgrowsAPackageNamesTheNextOneUpAndWhereToAct(): void
    {
        $this->requireHttp();
        global $db;

        $deal = self::capacityDeal();
        if ($deal === null) {
            self::markTestSkipped('no confirmed deal on booking 200 carries a package with a guest capacity');
        }
        $capacity = (int) $deal['capacity'];
        $vendor   = (int) $deal['user_id'];
        $function = (int) $deal['features_id'] > 0 ? (int) $deal['features_id'] : self::UNPLANNED;
        $title    = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);
        $organizer = self::plannerId();

        // The way out: a bigger, pricier package from the same vendor.
        $db->execute(
            "INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
             VALUES (:v, '[capacity probe] Banquet — 400 pax', 'Fixture for tests/VenueHeadcountPlanTest', 180000, 400, 10, 1)",
            [':v' => (int) $deal['vendor_id']]
        );
        $package = (int) $db->fetchValue('SELECT MAX(id) FROM tblvendor_packages');
        $expected = 'Next package up: [capacity probe] Banquet — 400 pax — '
            . format_ksh(180000 - (float) $deal['package_price']) . ' more.';

        $before = self::dbRow(self::BOOKING, $function);
        try {
            // ---- a couple's save: the warning AND the way out reach the bell --
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
            $fields['family[' . $function . ']']     = (string) $capacity;
            $fields['individual[' . $function . ']'] = '3';

            $plannerBefore = count(self::briefs($organizer));
            $vendorBefore  = count(self::briefs($vendor));
            $res = self::http('POST', '/admin/event_booking_save.php', $fields);
            self::assertSame(302, $res['status']);

            $bells = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $bells, 'the planner must hear the warning');
            $plannerMsg = (string) $bells[count($bells) - 1]->message;
            self::assertStringContainsString('over the package', $plannerMsg, 'the warning itself must never be dropped');
            self::assertStringContainsString($expected, $plannerMsg,
                'and the way out rides with it: the planner acts from the bell');
            self::assertLessThanOrEqual(255, mb_strlen($plannerMsg), 'nor may the bell column clip it mid-number');
            self::assertStringEndsWith('.', $plannerMsg);
            // The warning states the function and the count it now holds, so the
            // brief's own moved line for that function is the same fact a second
            // time — and two statements of it is what pushes the way out over the
            // column. Stated once, by the warning; every OTHER function still gets
            // its line.
            self::assertSame(1, substr_count($plannerMsg, $title), $plannerMsg);
            self::assertStringNotContainsString($title . ' — ', $plannerMsg,
                'the moved line for a breaching function is dropped: the warning already states it');

            $vendorBells = self::briefs($vendor);
            self::assertCount($vendorBefore + 1, $vendorBells);
            self::assertStringNotContainsString('Next package up', (string) $vendorBells[count($vendorBells) - 1]->message,
                'the next package up is the planner\'s conversation with the vendor, not the vendor\'s alert');

            // The sheet: the warning, the way out, and the one step that acts on it.
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $staff = self::http('GET', self::sheetPath())['body'];
            self::assertStringContainsString('Over capacity: ' . $title . ' is planned for ' . ($capacity + 3), $staff);
            self::assertStringContainsString($expected, $staff, 'the sheet must say what the way out costs');
            self::assertStringContainsString('vendor_enquiries.php?deal=' . (int) $deal['deal_id'], $staff,
                'and link to that deal, where the re-price lives');
            self::assertStringContainsString('Re-price this deal', $staff);

            // The couple: the breach, not the shopping list.
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $couple = self::http('GET', self::sheetPath())['body'];
            self::assertStringContainsString('Over capacity: ', $couple, 'the couple must know their plan outgrew a booking');
            self::assertStringNotContainsString('Next package up', $couple,
                'a re-price is the planner\'s negotiation, framed by them');
            self::assertStringNotContainsString('Re-price this deal', $couple);

            // ---- a staff save: no brief about the numbers, but the same warning
            //      and the same way out, because a package that no longer fits is
            //      a fact about the deal rather than about who typed it --
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $fields = self::renderedFormFields(self::http('GET', self::sheetPath())['body']);
            $fields['individual[' . $function . ']'] = '4';
            $staffBefore = count(self::briefs($organizer));
            self::http('POST', '/admin/event_booking_save.php', $fields);

            $staffBells = self::briefs($organizer);
            self::assertCount($staffBefore + 1, $staffBells, 'a colleague\'s save still warns the assigned planner');
            $staffMsg = (string) $staffBells[count($staffBells) - 1]->message;
            self::assertStringContainsString('Headcount check — ', $staffMsg);
            self::assertStringContainsString($expected, $staffMsg, 'with room to spare, the way out travels in the bell too');
            self::assertLessThanOrEqual(255, mb_strlen($staffMsg));
        } finally {
            self::restorePlanRow(self::BOOKING, $function, $before);
            $db->execute('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $package]);
        }
    }

    /**
     * The same warning at the ENQUIRY door — the earliest of the four a deal is
     * recorded at, and the only one where nothing is sold yet.
     *
     * The couple have planned 120 for their reception and ask a caterer for a
     * 10-pax package: the sentence they read has to say which side of the deal
     * they are on ("the package you asked X for" — nobody is booked yet), the way
     * out has to ride with it (they can pick a bigger one in the page they just
     * came from), and the planner's own enquiry bell has to carry the same fact —
     * the planner is who negotiates it.
     */
    #[Group('integration')]
    public function testTheCoupleIsToldAtTheEnquiryThatThePackageTheyPickedIsTooSmall(): void
    {
        $this->requireHttp();
        $this->requireScoped();
        global $db;

        $planned = [];
        foreach (PackageCapacity::plan_totals(self::BOOKING) as $fn) {
            if ($fn['total'] > 0) {
                $planned[$fn['features_id']] = $fn['total'];
            }
        }
        self::assertNotSame([], $planned, 'premise: booking 200 has a plan for a requested package to be judged against');
        $biggest = max($planned);
        $title   = (string) $db->fetchValue(
            'SELECT title FROM events WHERE id = :i', [':i' => (int) array_search($biggest, $planned, true)]
        );

        $small = self::bookProbePackage(self::$scopedVendorId, '[record probe] 10 pax', 1000.0, 10);
        $big   = self::bookProbePackage(self::$scopedVendorId, '[record probe] 500 pax', 5000.0, $biggest + 100);
        $organizer = self::plannerId();
        $notifMark = (int) $db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblnotifications');
        $dealId = 0;

        try {
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $token = self::fetchCsrf('/admin/client_vendors.php?booking_id=' . self::BOOKING . '&user_id=200');
            $posted = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING . '&user_id=200', [
                'vendor_id'  => self::$scopedVendorId,
                'package_id' => $small,
                'event_date' => '',
                'message'    => '[record probe] the package is too small for the plan',
                '_token'     => $token,
            ]);
            self::assertSame(302, $posted['status']);
            $dealId = (int) $db->fetchValue(
                'SELECT MAX(id) FROM tblvendor_bookings WHERE booking_id = :b AND vendor_id = :v',
                [':b' => self::BOOKING, ':v' => self::$scopedVendorId]
            );
            self::assertGreaterThan(0, $dealId, 'the enquiry must be recorded');

            // What the couple read on the page they land on: which package, which
            // function, and the way out (a bigger package of the same vendor).
            $flash = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING . '&user_id=200')['body'];
            self::assertStringContainsString('has been sent', $flash, 'premise: the enquiry went through');
            self::assertStringContainsString('the package you asked ' . self::SCOPE_NAME . ' for', $flash,
                'the reader is the one who asked: nothing is booked yet');
            self::assertStringContainsString($title . ' is planned for ' . $biggest, $flash,
                'and the plan it cannot serve is stated');
            // Every function the package falls short of, not just the first one on
            // the calendar: an unscoped request covers the whole wedding, and the
            // couple can only choose differently if they see which of their
            // functions it cannot hold.
            foreach ($planned as $total) {
                self::assertStringContainsString('is planned for ' . $total, $flash,
                    'every function this package is short of is named');
            }
            self::assertStringContainsString('Next package up: [record probe] 500 pax', $flash,
                'the way out is named where the choice is still free');
            self::assertStringNotContainsString('is booked on', $flash,
                'a request is not a booking — the wording must not claim one');

            // The planner, who negotiates it, hears it on the bell that already
            // says the enquiry exists (one alert per event, not two).
            $plannerBell = $db->fetchOne(
                "SELECT id, message FROM tblnotifications
                  WHERE user_id = :u AND booking_id = :b AND message LIKE :needle ORDER BY id DESC LIMIT 1",
                [':u' => $organizer, ':b' => self::BOOKING, ':needle' => '%the package you asked%']
            );
            self::assertNotNull($plannerBell, 'the planner has to hear that the package asked for is too small');
            self::assertGreaterThan($notifMark, (int) $plannerBell->id, 'on the enquiry bell, not as a second alert for one event');
            self::assertStringContainsString('enquired with ' . self::SCOPE_NAME, (string) $plannerBell->message,
                'which is the alert the page already sends them');
            // The short form: the biggest crowd, which is what any bigger package
            // has to cover. The bell it rides on already carries the couple, the
            // vendor and the package, so the full clause pair plus the way out
            // would be dropped whole by append_note().
            self::assertStringContainsString($title . ' is planned for ' . $biggest, (string) $plannerBell->message);
            self::assertStringNotContainsString('Next package up', (string) $plannerBell->message,
                'the suggestion stays on the page the couple just left');
            self::assertLessThanOrEqual(255, mb_strlen((string) $plannerBell->message),
                'appended only while it fits the bell column — never cut');
        } finally {
            if ($dealId > 0) {
                $db->execute('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $dealId]);
            }
            $db->execute('DELETE FROM tbl_mail_logs WHERE notification_id IN (SELECT id FROM (SELECT id FROM tblnotifications WHERE id > :m) x)', [':m' => $notifMark]);
            $db->execute('DELETE FROM tblnotifications WHERE id > :m', [':m' => $notifMark]);
            $db->execute('DELETE FROM tblvendor_packages WHERE id IN (:a, :b)', [':a' => $small, ':b' => $big]);
        }
    }

    /**
     * The vendor's own door: accepting an enquiry records the deal, and this is
     * the one door no planner is watching. The vendor hears where they accept it,
     * with the limit and WITHOUT the planner's options — which package covers the
     * crowd at what price is the planner's conversation with them — and the
     * planner hears both.
     */
    #[Group('integration')]
    public function testTheVendorAcceptingATooSmallPackageHearsItWhereTheyAcceptIt(): void
    {
        $this->requireHttp();
        $this->requireScoped();
        global $db;

        $deal = $db->fetchOne(
            'SELECT id, status, package_id, amount, commission, features_id FROM tblvendor_bookings WHERE id = :i',
            [':i' => self::$scopedBookingId]
        );
        self::assertNotNull($deal, 'premise: the scoped fixture deal is on file');
        // Set, not read: earlier tests in this class re-scope this fixture deal, and
        // what is under test here is one function's crowd against one package. The
        // deal's own scope is restored below.
        $function = self::SCOPED_FUNCTION;
        $planned = [];
        foreach (PackageCapacity::plan_totals(self::BOOKING) as $fn) {
            $planned[(int) $fn['features_id']] = (int) $fn['total'];
        }
        self::assertGreaterThan(10, (int) ($planned[$function] ?? 0),
            'premise: the function this deal covers is planned for more than the probe package holds');
        $title = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);

        $small = self::bookProbePackage(self::$scopedVendorId, '[record probe] 10 pax', 1000.0, 10);
        $organizer = self::plannerId();
        $plannerBefore = count(self::briefs($organizer));
        $vendorBefore  = count(self::briefs(self::SCOPE_USER));

        try {
            // The deal the vendor is about to accept: still an enquiry, scoped to
            // the one function it was made for, on a package that cannot hold the
            // crowd the couple have planned.
            $db->execute(
                "UPDATE tblvendor_bookings
                    SET status = 'pending', package_id = :p, features_id = :f, amount = 0, commission = 0
                  WHERE id = :i",
                [':p' => $small, ':f' => $function, ':i' => self::$scopedBookingId]
            );

            self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
            $token = self::fetchCsrf('/admin/vendor_home.php');
            $posted = self::http('POST', '/admin/vendor_home.php', [
                'booking_row_id' => self::$scopedBookingId,
                'to'             => 'confirmed',
                'amount'         => '1000',
                '_token'         => $token,
            ]);
            self::assertSame(302, $posted['status']);
            self::assertSame('confirmed', (string) $db->fetchValue(
                'SELECT status FROM tblvendor_bookings WHERE id = :i', [':i' => self::$scopedBookingId]
            ), 'premise: the vendor accepted the deal');

            $flash = self::http('GET', '/admin/vendor_home.php')['body'];
            self::assertStringContainsString('Booking marked', $flash);
            self::assertStringContainsString('Over capacity: ' . $title . ' is planned for ' . $planned[$function], $flash,
                'the vendor reads it on the page they just pressed');
            self::assertStringContainsString(self::SCOPE_NAME, $flash, 'and which of their packages it is');
            self::assertStringNotContainsString('Next package up', $flash,
                'the next package up is the planner\'s conversation with the vendor, not the vendor\'s own alert');
            self::assertStringNotContainsString('No bigger package on file', $flash);

            $plannerBells = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $plannerBells, 'the assigned planner hears it too');
            $plannerMsg = (string) $plannerBells[count($plannerBells) - 1]->message;
            self::assertStringContainsString('over the package', $plannerMsg);
            self::assertStringContainsString($title . ' is planned for ' . $planned[$function], $plannerMsg,
                'the warning is the floor of the message: it is never the part that is dropped');
            self::assertLessThanOrEqual(255, mb_strlen($plannerMsg));
            // With this fixture's long listing name the way out does not fit beside
            // the warning, and the bell drops the suggestion rather than the fact —
            // the priority HeadcountBrief::fit() documents; the way out reaching the
            // bell at all is asserted in VendorEnquiriesLedgerTest, where the names
            // leave room for it.
            self::assertStringNotContainsString('…', $plannerMsg, 'and it is fitted, never cut mid-number');

            $vendorBells = self::briefs(self::SCOPE_USER);
            self::assertCount($vendorBefore + 1, $vendorBells, 'and the vendor has it on file in their own ledger');
            self::assertStringContainsString('over the package you are booked on',
                (string) $vendorBells[count($vendorBells) - 1]->message);
        } finally {
            $db->execute(
                'UPDATE tblvendor_bookings SET status = :s, package_id = :p, features_id = :f, amount = :a, commission = :c WHERE id = :i',
                [
                    ':s' => (string) $deal->status,
                    ':p' => $deal->package_id,
                    ':f' => $deal->features_id === null ? null : (int) $deal->features_id,
                    ':a' => (float) $deal->amount,
                    ':c' => (float) $deal->commission,
                    ':i' => self::$scopedBookingId,
                ]
            );
            $db->execute('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $small]);
        }
    }

    /**
     * The third door: a confirmed deal whose headcount SCOPE is widened onto a
     * function its package cannot cover. No number moves and no package is
     * re-priced — one dropdown in the ledger does it — so the widened deal is
     * judged when the scope is saved, and only when the scope actually changed
     * (saving the same scope again is not a change and tells nobody).
     */
    #[Group('integration')]
    public function testWideningAConfirmedDealsScopeOntoABiggerFunctionIsWarned(): void
    {
        $this->requireHttp();
        $this->requireScoped();
        global $db;

        $deal = $db->fetchOne(
            'SELECT id, status, package_id, features_id FROM tblvendor_bookings WHERE id = :i',
            [':i' => self::$scopedBookingId]
        );
        self::assertNotNull($deal, 'premise: the scoped fixture deal is on file');
        $wasScope = $deal->features_id === null ? 0 : (int) $deal->features_id;

        $planned = [];
        foreach (PackageCapacity::plan_totals(self::BOOKING) as $fn) {
            $planned[(int) $fn['features_id']] = (int) $fn['total'];
        }
        // A function of this wedding the deal does NOT cover, planned for more
        // than a 10-pax package holds — where the scope can be widened onto.
        $target = 0;
        foreach ($planned as $fid => $total) {
            if ($fid !== $wasScope && $total > 10) {
                $target = $fid;
                break;
            }
        }
        if ($target === 0) {
            self::markTestSkipped('no other function of this wedding is planned for more than 10');
        }
        $title = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $target]);

        $small = self::bookProbePackage(self::$scopedVendorId, '[record probe] 10 pax', 1000.0, 10);
        $organizer = self::plannerId();
        $plannerBefore = count(self::briefs($organizer));

        try {
            $db->execute(
                "UPDATE tblvendor_bookings SET status = 'confirmed', package_id = :p, features_id = :f WHERE id = :i",
                [':p' => $small, ':f' => $wasScope > 0 ? $wasScope : null, ':i' => self::$scopedBookingId]
            );

            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $token = self::fetchCsrf('/admin/vendor_enquiries.php');
            $back = '/admin/vendor_enquiries.php';

            // Saving the scope it already has is not a change: nothing to say.
            $same = self::http('POST', $back, [
                'scope_deal_id' => self::$scopedBookingId,
                'features_id'   => (string) $wasScope,
                '_token'        => $token,
            ]);
            self::assertSame(302, $same['status']);
            self::assertStringNotContainsString('Over capacity', self::http('GET', $back)['body'],
                're-saving the same scope tells nobody: no fact changed');
            self::assertCount($plannerBefore, self::briefs($organizer));

            // Widening it onto a function the package cannot hold IS the fact.
            $token = self::fetchCsrf($back);
            $widened = self::http('POST', $back, [
                'scope_deal_id' => self::$scopedBookingId,
                'features_id'   => (string) $target,
                '_token'        => $token,
            ]);
            self::assertSame(302, $widened['status']);
            self::assertSame($target, (int) $db->fetchValue(
                'SELECT features_id FROM tblvendor_bookings WHERE id = :i', [':i' => self::$scopedBookingId]
            ));

            $flash = self::http('GET', $back)['body'];
            self::assertStringContainsString('now briefed on', $flash, 'premise: the scope saved');
            self::assertStringContainsString('Over capacity: ' . $title . ' is planned for ' . $planned[$target], $flash,
                'a scope that now covers a crowd the package cannot hold is a breach, and the saver is told');

            $bells = self::briefs($organizer);
            self::assertCount($plannerBefore + 1, $bells, 'and the planner hears it');
            self::assertStringContainsString('over the package', (string) $bells[count($bells) - 1]->message);
        } finally {
            $db->execute(
                'UPDATE tblvendor_bookings SET status = :s, package_id = :p, features_id = :f WHERE id = :i',
                [
                    ':s' => (string) $deal->status,
                    ':p' => $deal->package_id,
                    ':f' => $wasScope > 0 ? $wasScope : null,
                    ':i' => self::$scopedBookingId,
                ]
            );
            $db->execute('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $small]);
        }
    }

    /**
     * The copy rules the recording doors rest on, asserted without a database:
     * a request is not a booking, the page line carries the way out, and a note
     * appended to a bell this class did not write either fits or is dropped —
     * never cut mid-number.
     */
    public function testTheRecordingDoorCopySaysWhichSideOfTheDealTheReaderIsOn(): void
    {
        $breach = [
            'features_id' => 202,
            'title'       => 'Reception',
            'total'       => 300,
            'was'         => null,
            'deals'       => [[
                'deal_id'       => 200,
                // No vendor id, so the way out is read without this database's own
                // package list behind it: the copy rule under test, not the seed.
                'vendor_id'     => 0,
                'business_name' => 'Nyama Choma Masters — Nakuru',
                'user_id'       => 8,
                'status'        => 'pending',
                'scope'         => 'wedding',
                'features_id'   => 0,
                'package_id'    => 200,
                'package_title' => 'Choma Basic',
                'capacity'      => 100,
                'package_price' => 45000.0,
                'over_by'       => 200,
            ]],
        ];

        // The planner's clause (which the sheet and the couple read) and the
        // requested one say the same fact from the two sides of the deal.
        self::assertSame(
            'Reception is planned for 300 — over the package Nyama Choma Masters — Nakuru is booked on (Choma Basic — up to 100 pax).',
            PackageCapacity::planner_clause($breach)
        );
        self::assertSame(
            'Reception is planned for 300 — over the package you asked Nyama Choma Masters — Nakuru for (Choma Basic — up to 100 pax).',
            PackageCapacity::requested_clause($breach)
        );
        // No vendor name on file still reads as a sentence.
        $unnamed = $breach;
        $unnamed['deals'][0]['business_name'] = '';
        self::assertSame(
            'Reception is planned for 300 — over the package you asked for (Choma Basic — up to 100 pax).',
            PackageCapacity::requested_clause($unnamed)
        );

        // The page line: warning first, way out after it, who was told last.
        self::assertSame('', PackageCapacity::position([]), 'no breach, nothing to say');
        $line = PackageCapacity::position([$breach], 'The caterer has been warned.');
        self::assertStringStartsWith('Over capacity: Reception is planned for 300', $line);
        self::assertStringEndsWith('The caterer has been warned.', $line);
        // This vendor sells nothing bigger (100 pax is the only package), so the
        // way out is the honest short answer rather than a blank.
        self::assertStringContainsString('No bigger package on file — ask Nyama Choma Masters — Nakuru for a quote for 300.', $line);

        // A note on someone else's bell: appended while it fits the column,
        // dropped whole when it cannot. Never a cut number.
        self::assertSame('A couple enquired with X.', HeadcountBrief::append_note('A couple enquired with X.', ''));
        self::assertSame('A couple enquired with X. Heads up.',
            HeadcountBrief::append_note('A couple enquired with X.', 'Heads up.'));
        $long = str_repeat('n', 250);
        self::assertSame($long, HeadcountBrief::append_note($long, 'a note that does not fit'),
            'a note that would overflow the column is dropped, not truncated');
        self::assertSame($long . ' ok', HeadcountBrief::append_note($long, 'ok'));
    }

    /**
     * The headroom view — the one capacity surface that speaks BEFORE a plan
     * outgrows what was sold.
     *
     * Every other one of them is a warning after the fact: the breach is
     * announced to the vendor and the planner and printed on the venue sheet, and
     * by then the deal is made and what is left is a phone call under pressure. A
     * function planned eight guests inside a booked package's limit is not a
     * breach and may never become one, so nothing is announced about it — this
     * page is the only place a planner can find it, which is why the whole chain
     * is asserted here: the row states the function, the count, the package and
     * the limit it is closing on, with the venue sheet to act from, the width
     * filter really narrows (the same fixture is inside 10% and outside 5%), the
     * facts layer agrees with the SQL the navigation badge counts with — a badge
     * that disagrees with the page it links to is worse than no badge — a wedding
     * already OVER stays listed while never being called drift, the page notifies
     * nobody, and neither a couple nor a vendor may open it.
     */
    #[Group('integration')]
    public function testTheHeadroomViewListsDriftBeforeItBecomesABreach(): void
    {
        $this->requireHttp();
        $this->requireScoped();
        global $db;

        $deal = $db->fetchOne(
            'SELECT id, status, package_id, features_id FROM tblvendor_bookings WHERE id = :i',
            [':i' => self::$scopedBookingId]
        );
        self::assertNotNull($deal, 'premise: the scoped fixture deal is on file');

        $planned = [];
        foreach (PackageCapacity::plan_totals(self::BOOKING) as $fn) {
            if ((int) $fn['total'] > 0) {
                $planned[(int) $fn['features_id']] = (int) $fn['total'];
            }
        }
        self::assertNotSame([], $planned, 'premise: booking 200 has a plan to run close to a limit');
        // The biggest crowd: the function a drift warning is most likely to be
        // about, and the one this page must state first.
        $total    = max($planned);
        $function = (int) array_search($total, $planned, true);
        $title    = (string) $db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);

        /*
         * A margin inside ten per cent of the limit and OUTSIDE five: the fixture
         * has to be one the width filter can separate at all, or the narrowing
         * assertion below would pass for the wrong reason. Derived from the plan
         * rather than hard-coded, so this test means the same thing on a database
         * whose function is planned for 300 as on this one's.
         */
        $margin = 0;
        for ($m = 1; $m <= 200; $m++) {
            $cap = $total + $m;
            if ($m > (int) ceil($cap * 0.05) && $m <= (int) ceil($cap * 0.10)) {
                $margin = $m;
                break;
            }
        }
        if ($margin === 0) {
            self::markTestSkipped('no function of this wedding is planned large enough for 5% and 10% to differ');
        }
        $capacity = $total + $margin;

        $package   = self::bookProbePackage(self::$scopedVendorId, '[headroom probe] Marquee', 1000.0, $capacity);
        $notifMark = (int) $db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblnotifications');
        $sentence  = $title . ' is planned for ' . $total . ' — within ' . $margin . ' guests of the package '
            . self::SCOPE_NAME . ' is booked on ([headroom probe] Marquee — up to ' . $capacity . ' pax).';

        try {
            // A confirmed deal on the package. Nothing is announced for a deal that
            // only comes CLOSE, so this page is the only surface it can appear on.
            $db->execute(
                "UPDATE tblvendor_bookings SET status = 'confirmed', package_id = :p, features_id = :f WHERE id = :i",
                [':p' => $package, ':f' => $function, ':i' => self::$scopedBookingId]
            );

            // ---- the facts layer: the position, as the page reads it ----------
            $mine = array_values(array_filter(
                PackageCapacity::headroom(self::BOOKING, 0.10),
                static fn(array $p): bool => (int) $p['deal_id'] === self::$scopedBookingId
            ));
            self::assertCount(1, $mine, 'the close position must be found by the facts layer the page reads');
            self::assertSame($margin, $mine[0]['margin']);
            self::assertSame($total, $mine[0]['total']);
            self::assertSame($capacity, $mine[0]['capacity']);

            /*
             * This test asserts the DEFAULT tenth, so the account's remembered
             * width is cleared first (v2.12): a width saved by a manual session
             * — or by the preference test above, which cleans up after itself —
             * must not decide what these assertions see. The saved case is the
             * preference test's business.
             */
            if (UserPrefs::available()) {
                $db->execute(
                    'DELETE FROM tbluser_prefs WHERE user_id = :u AND pref_key = :k',
                    [
                        ':u' => (int) $db->fetchValue('SELECT id FROM tblusers WHERE email = :e LIMIT 1', [':e' => self::STAFF_EMAIL]),
                        ':k' => UserPrefs::HEADROOM_WIDTH,
                    ]
                );
            }

            // ---- the page: the row, the limit, and the sheet it acts from -----
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $body = self::http('GET', '/admin/headroom.php')['body'];
            self::assertStringContainsString($sentence, $body,
                'the drift must be stated in full: which function, how many, on which package, against which limit');
            self::assertStringContainsString('sheet_venue.php?booking_id=' . self::BOOKING . '&amp;user_id=200', $body,
                'with the venue sheet — where the numbers and the briefed recipients live');
            self::assertStringContainsString('Positions inside 10%', $body, 'the width the list was drawn at is stated');
            self::assertStringNotContainsString('Nothing is running close to a limit', $body);

            // ---- the badge: the page and the navigation agree, or neither is
            //      worth trusting (the number is one query on every staff page)
            $watch = PackageCapacity::watch(0.10);
            self::assertSame(count($watch), PackageCapacity::watch_count(0.10),
                'the badge must count exactly what the page lists');
            $row = null;
            foreach ($watch as $w) {
                if ((int) $w['booking_id'] === self::BOOKING) {
                    $row = $w;
                }
            }
            self::assertNotNull($row, 'the wedding must be on the watch list');
            self::assertGreaterThanOrEqual(1, (int) $row['count']);
            self::assertSame(0, (int) $row['over'], 'premise: drift is not a breach');
            $dashboard = self::http('GET', '/admin/dashboard.php')['body'];
            self::assertStringContainsString('headroom.php', $dashboard, 'and the page has to be reachable from every staff page');
            self::assertStringContainsString('Capacity Headroom', $dashboard);

            // ---- the width filter narrows the same fixture out of view --------
            $narrow = self::http('GET', '/admin/headroom.php?within=5')['body'];
            self::assertStringContainsString('Within 5%', $narrow);
            self::assertStringContainsString('Positions inside 5%', $narrow);
            self::assertStringNotContainsString($sentence, $narrow,
                'the same position is outside a 5% window: the filter runs the arithmetic, it does not decorate the page');
            self::assertSame([], array_filter(
                PackageCapacity::headroom(self::BOOKING, 0.05),
                static fn(array $p): bool => (int) $p['deal_id'] === self::$scopedBookingId
            ), 'and the facts layer says the same about it');
            self::assertStringContainsString($sentence, self::http('GET', '/admin/headroom.php?within=20')['body'],
                'widening the window keeps what the tighter one showed');
            self::assertStringContainsString($sentence, self::http('GET', '/admin/headroom.php?within=junk')['body'],
                'a hand-edited URL is a width, not an error: an unknown one opens where the page would have opened anyway');

            // ---- a breach stays listed, and is never dressed up as drift ------
            $db->execute('UPDATE tblvendor_packages SET capacity = :c WHERE id = :i', [':c' => $total - 1, ':i' => $package]);
            $over = self::http('GET', '/admin/headroom.php')['body'];
            self::assertStringContainsString('already over a booked package', $over,
                'a reader must never take "no drift" for "nothing wrong"');
            self::assertStringContainsString('1 over</span>', $over);
            self::assertStringContainsString('sheet_venue.php?booking_id=' . self::BOOKING, $over,
                'and the wedding that needs the call most is still listed rather than dropped');
            self::assertGreaterThanOrEqual(1, PackageCapacity::watch_over_count());
            self::assertSame([], array_values(array_filter(
                PackageCapacity::headroom(self::BOOKING),
                static fn(array $p): bool => (int) $p['deal_id'] === self::$scopedBookingId
            )), 'over the limit is breaches()\' answer: the drift list must not claim it');

            // ---- and none of it wakes anybody up -----------------------------
            self::assertSame($notifMark, (int) $db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblnotifications'),
                'a forecast notifies nobody: a bell per wobble of a guest count is noise');

            // ---- who may read it -------------------------------------------
            self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
            $couple = self::http('GET', '/admin/headroom.php');
            self::assertSame(302, $couple['status'],
                'which packages were sold, and how tight they are, is not the couple\'s page');
            self::assertStringNotContainsString('Capacity Headroom', $couple['body']);
            self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
            $vendor = self::http('GET', '/admin/headroom.php');
            self::assertSame(302, $vendor['status'], 'nor the vendor\'s: it is the planner\'s working list');
            self::assertStringNotContainsString('Capacity Headroom', $vendor['body']);
        } finally {
            $db->execute(
                'UPDATE tblvendor_bookings SET status = :s, package_id = :p, features_id = :f WHERE id = :i',
                [
                    ':s' => (string) $deal->status,
                    ':p' => $deal->package_id,
                    ':f' => $deal->features_id === null ? null : (int) $deal->features_id,
                    ':i' => self::$scopedBookingId,
                ]
            );
            $db->execute('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $package]);
        }
    }

    /**
     * The headroom copy and widths, asserted without a database: what "close"
     * says in words, and the one seam between the percentage a page offers and
     * the fraction the arithmetic runs on.
     */
    public function testTheHeadroomCopyNamesTheLimitAndTheWidthListIsFixed(): void
    {
        // Three widths, and only three: a filter that accepts any number is a
        // filter nobody can compare a screenshot of.
        self::assertSame([5 => 0.05, 10 => 0.10, 20 => 0.20], PackageCapacity::shares());
        self::assertSame(0.2, PackageCapacity::sanitise_share(0.2));
        self::assertSame(0.05, PackageCapacity::sanitise_share('0.05'));
        // …which is exactly why the page maps its own percentage through shares():
        // a bare 5 means five, and five is not a fraction of anything.
        self::assertSame(0.10, PackageCapacity::sanitise_share('5'));
        self::assertSame(0.10, PackageCapacity::sanitise_share(0.07), 'a width this feature does not offer is the default, not an error');
        self::assertSame(0.10, PackageCapacity::sanitise_share('junk'));
        self::assertSame(0.10, PackageCapacity::sanitise_share(null));

        $position = [
            'function_title' => 'Reception',
            'total'          => 250,
            'business_name'  => 'Nyama Choma Masters — Nakuru',
            'package_title'  => 'Choma Basic',
            'capacity'       => 250,
        ];
        // Exactly full is the tightest position there is, and "within 0 guests of"
        // is not a sentence: the limit is the fact, so it is named.
        self::assertSame(
            'Reception is planned for 250 — exactly the limit of the package Nyama Choma Masters — Nakuru is booked on (Choma Basic — up to 250 pax).',
            PackageCapacity::headroom_sentence($position + ['margin' => 0])
        );
        self::assertSame(
            'Reception is planned for 250 — within 1 guest of the package Nyama Choma Masters — Nakuru is booked on (Choma Basic — up to 250 pax).',
            PackageCapacity::headroom_sentence($position + ['margin' => 1])
        );
        self::assertSame(
            'Reception is planned for 250 — within 12 guests of the package Nyama Choma Masters — Nakuru is booked on (Choma Basic — up to 250 pax).',
            PackageCapacity::headroom_sentence($position + ['margin' => 12])
        );
        // A title that already states its own limit is left alone, an unnamed
        // vendor still reads as a sentence, and a function with no title is still
        // a function.
        self::assertSame(
            'Reception is planned for 250 — within 3 guests of the package Nyama Choma Masters — Nakuru is booked on (Banquet 250).',
            PackageCapacity::headroom_sentence([
                'function_title' => 'Reception', 'total' => 250, 'margin' => 3,
                'business_name'  => 'Nyama Choma Masters — Nakuru',
                'package_title'  => 'Banquet 250', 'capacity' => 250,
            ])
        );
        self::assertSame(
            'A function is planned for 4 — within 2 guests of the package the vendor is booked on (Choma Basic — up to 250 pax).',
            PackageCapacity::headroom_sentence([
                'function_title' => '', 'total' => 4, 'margin' => 2,
                'business_name'  => '', 'package_title' => 'Choma Basic', 'capacity' => 250,
            ])
        );
    }

    /**
     * The headroom view's width is the planner's own, remembered between visits
     * (v2.12, include/UserPrefs.php → tbluser_prefs).
     *
     * Why this is asserted rather than assumed: the page's width is the one
     * thing a planner sets and expects to find again, and every way of getting
     * it wrong is silent. A preference that dies with the session looks right
     * until the next morning; a GET that stores the width turns a shared link
     * (or a colleague's URL) into a settings change; a stored value read back
     * without validation lets a hand-edited row widen every page load into a
     * query this feature was not asked for; and a preference keyed to anything
     * but the signed-in account leaks one planner's choice into another's work.
     *
     * The resolution order is asserted too, because it is three rules in one
     * line of code: `?within=` wins for the view it names, the account's saved
     * width is what a bare visit opens at, and the feature's own default is what
     * an account that never chose gets.
     */
    #[Group('integration')]
    public function testTheHeadroomWidthIsRememberedPerPlannerAndOnlyOnPost(): void
    {
        $this->requireHttp();
        global $db;

        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed — run migrations/v2.12_user_preferences.sql');
        }

        $me = (int) $db->fetchValue('SELECT id FROM tblusers WHERE email = :e LIMIT 1', [':e' => self::STAFF_EMAIL]);
        $other = (int) $db->fetchValue('SELECT id FROM tblusers WHERE email = :e LIMIT 1', [':e' => self::OTHER_STAFF_EMAIL]);
        self::assertGreaterThan(0, $me, 'premise: the staff account is on file');
        self::assertGreaterThan(0, $other, 'premise: a second planner account is on file');

        // One reader for the stored width, so every step below states the same fact.
        $stored = static function (int $user_id) use ($db): string {
            return (string) ($db->fetchValue(
                'SELECT pref_value FROM tbluser_prefs WHERE user_id = :u AND pref_key = :k LIMIT 1',
                [':u' => $user_id, ':k' => UserPrefs::HEADROOM_WIDTH]
            ) ?? '');
        };
        // One reader for the width the page says it is drawn at.
        $opensAt = static function (string $body): int {
            return preg_match('~Positions inside (\d+)%~', $body, $m) === 1 ? (int) $m[1] : -1;
        };

        $db->execute('DELETE FROM tbluser_prefs WHERE user_id IN (:a, :b)', [':a' => $me, ':b' => $other]);

        try {
            // ---- an account that never chose a width opens at the default ----
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            $body = self::http('GET', '/admin/headroom.php')['body'];
            self::assertSame(10, $opensAt($body), 'a bare visit with nothing saved opens at the feature default');
            self::assertStringContainsString('Remember 10% as my default', $body,
                'and the control states the width it would store');
            self::assertSame('', $stored($me), 'premise: nothing was saved for this account');

            // ---- remembering one: POST + CSRF, and it is stored per account ----
            $token = self::fetchCsrf('/admin/headroom.php');
            $saved = self::http('POST', '/admin/headroom.php', [
                'headroom_default' => 'remember',
                'within'           => '20',
                '_token'           => $token,
            ]);
            self::assertSame(302, $saved['status']);
            self::assertSame('20', $stored($me), 'the chosen width must be stored for the signed-in account');

            // …and the bare page now opens there — this is the "between visits"
            // claim, which is exactly why the value is not in the session.
            $body = self::http('GET', '/admin/headroom.php')['body'];
            self::assertSame(20, $opensAt($body), 'a later visit opens at the saved width');
            self::assertStringContainsString('Opening at 20% — your saved default.', $body);
            self::assertStringContainsString('value="forget"', $body, 'with the one control that drops it');

            // ---- a GET never writes: an explicit width changes this view only --
            $explicit = self::http('GET', '/admin/headroom.php?within=5')['body'];
            self::assertSame(5, $opensAt($explicit), 'the URL still wins for the view it names');
            self::assertSame('20', $stored($me), 'and a GET must never store what it displayed');
            self::assertSame(20, $opensAt(self::http('GET', '/admin/headroom.php')['body']),
                'the saved width is untouched by looking somewhere else');

            // ---- a width the feature does not offer is refused, not clamped ---
            $token = self::fetchCsrf('/admin/headroom.php');
            self::assertSame(302, self::http('POST', '/admin/headroom.php', [
                'headroom_default' => 'remember',
                'within'           => '99',
                '_token'           => $token,
            ])['status']);
            self::assertSame('20', $stored($me), 'an off-menu width must be refused, never stored');

            // ---- and a POST without the token changes nothing at all ----------
            self::assertSame(302, self::http('POST', '/admin/headroom.php', [
                'headroom_default' => 'forget',
            ])['status']);
            self::assertSame('20', $stored($me), 'a CSRF-less POST must not write');

            // ---- another planner is unaffected -------------------------------
            self::login(self::OTHER_STAFF_EMAIL, self::OTHER_STAFF_PASS);
            $theirs = self::http('GET', '/admin/headroom.php');
            self::assertSame(200, $theirs['status'], 'premise: the second planner can open the page');
            self::assertSame(10, $opensAt($theirs['body']),
                "one planner's saved width must not become another's");
            self::assertSame('', $stored($other));
            self::assertSame('20', $stored($me), 'nor may their visit have changed mine');

            // ---- a stored value that is not a width this feature offers reads
            //      as the default rather than widening the page ----------------
            $db->execute(
                'UPDATE tbluser_prefs SET pref_value = :v WHERE user_id = :u AND pref_key = :k',
                [':v' => '99', ':u' => $me, ':k' => UserPrefs::HEADROOM_WIDTH]
            );
            self::login(self::STAFF_EMAIL, self::STAFF_PASS);
            self::assertSame(10, $opensAt(self::http('GET', '/admin/headroom.php')['body']),
                'a stored width this feature does not offer is a hint the reader rejects, not a policy');
            $db->execute(
                'UPDATE tbluser_prefs SET pref_value = :v WHERE user_id = :u AND pref_key = :k',
                [':v' => '20', ':u' => $me, ':k' => UserPrefs::HEADROOM_WIDTH]
            );

            // ---- forgetting it, which is also a POST --------------------------
            $token = self::fetchCsrf('/admin/headroom.php');
            self::assertSame(302, self::http('POST', '/admin/headroom.php', [
                'headroom_default' => 'forget',
                'within'           => '20',
                '_token'           => $token,
            ])['status']);
            self::assertSame('', $stored($me), 'forgetting must delete the row');
            $after = self::http('GET', '/admin/headroom.php')['body'];
            self::assertSame(10, $opensAt($after), 'and the page is back to its default');
            self::assertStringContainsString('Remember 10% as my default', $after);
        } finally {
            $db->execute('DELETE FROM tbluser_prefs WHERE user_id IN (:a, :b)', [':a' => $me, ':b' => $other]);
        }
    }

    /**
     * The wedding's plan as PackageCapacity::breaches() takes it: every function
     * on the calendar, the counts it holds now, and the total a package has to
     * cover.
     *
     * @return array<int, array{features_id:int,title:string,total:int}>
     */
    private static function planTotals(): array
    {
        return PackageCapacity::plan_totals(self::BOOKING);
    }

    /**
     * A probe package on one vendor, for the recording-door tests. Package (not
     * deal) writes have no DB_Object wrapper in this codebase, so this is the
     * same INSERT the suites around this one make; the caller deletes it.
     */
    private static function bookProbePackage(int $vendor_id, string $title, float $price, int $capacity): int
    {
        global $db;
        $db->execute(
            "INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
             VALUES (:v, :t, 'Fixture for tests/VenueHeadcountPlanTest', :p, :c, 10, 1)",
            [':v' => $vendor_id, ':t' => $title, ':p' => $price, ':c' => $capacity]
        );
        return (int) $db->fetchValue('SELECT MAX(id) FROM tblvendor_packages');
    }

    /**
     * A confirmed deal on this wedding whose package states a guest limit — the
     * thing a function can outgrow. The seed ships one (the caterer's package),
     * and the tests read it rather than hard-code it. Null when this database has
     * none, in which case there is nothing to warn about.
     *
     * @return array<string,mixed>|null
     */
    private static function capacityDeal(): ?array
    {
        foreach (PackageCapacity::limits(self::BOOKING) as $deal) {
            if ((int) $deal['user_id'] > 0 && (int) $deal['capacity'] > 0) {
                return $deal;
            }
        }
        return null;
    }

    /**
     * Put one plan row back exactly as this test found it (or remove it when it
     * was not there). This class's earlier tests assert the seeded numbers and the
     * totals that follow from them, and a capacity probe leaves a crowd behind.
     */
    private static function restorePlanRow(int $booking_id, int $features_id, array $before): void
    {
        global $db;
        if ($before === []) {
            $db->execute('DELETE FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                [':b' => $booking_id, ':f' => $features_id]);
            return;
        }
        $db->execute(
            'UPDATE tbleventbooking SET family = :fam, individual = :ind WHERE booking_id = :b AND features_id = :f',
            [':fam' => $before[0][0], ':ind' => $before[0][1], ':b' => $booking_id, ':f' => $features_id]
        );
    }
}
