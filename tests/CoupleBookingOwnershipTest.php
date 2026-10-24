<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Booking-ownership sweep — EVERY couple-facing page and write endpoint.
 *
 * CouplePagesAccessTest proves the IDOR gate on three read pages. This class is
 * the sweep over the whole surface, because the interesting failure is the page
 * nobody listed: the couple portal is nine pages plus nine write endpoints, each
 * re-implementing the same two-condition check, and nothing noticed when a sheet
 * rendered a request-supplied user_id into the form it emitted.
 *
 * Three properties, on every page:
 *
 *  1. READ — a signed-in couple is bounced (302 to client.php) from every
 *     foreign booking_id/user_id combination, in both directions: someone
 *     else's booking with their own user id, and their own booking with someone
 *     else's user id. The pages that legitimately serve the couple's own booking
 *     must render 200 for it.
 *  2. WRITE — every couple write endpoint is aimed at the foreign booking with a
 *     VALID CSRF token, and the foreign booking's rows must be byte-identical
 *     afterwards. A status code alone proves nothing: the failure mode is a
 *     write that lands, so the database is compared before and after.
 *  3. ECHO — a page must never render an id the session did not prove it owns.
 *     sheet_venue.php?booking_id=<own>&user_id=<someone else> used to serve a
 *     headcount form addressed to that other account; the regression asserts the
 *     rendered ids are the booking's owner, on the read and on the write side.
 *
 * The fixtures are the app's own: two couples are created through
 * register_client() (accounts + account details + booking, bcrypt password, one
 * transaction — the same path the public sign-up form uses), one is signed in,
 * and both are removed again in tearDownAfterClass. Nothing here reads a seeded
 * record, so the class runs against any database the application can use, and
 * the fixture sweep has nothing foreign to complain about.
 *
 * Integration ('integration') group — needs a live DB and a bootable php -S.
 */
final class CoupleBookingOwnershipTest extends TestCase
{
    private const PROBE_PASS = 'Ownership@2026!';

    /** Marker on every fixture row, so cleanup can never touch seeded data. */
    private const GUEST_MARK = 'OWNERSHIP-PROBE guest';
    private const ITEM_MARK  = 'OWNERSHIP-PROBE item';
    private const EVENT_MARK = 'OWNERSHIP-PROBE event';
    private const SITE_SLUG  = 'ownership-probe-site';
    /** Email prefix of the throwaway tblusers fixture (a vendor account). */
    private const VENDOR_MARK = 'ownership-probe-vendor-';

    /**
     * Couple pages that take booking_id AND user_id and insist both are the
     * session's: a mismatch in EITHER direction is a bounce. The param is
     * redundant here (the booking already is the session's), which is exactly why
     * it is checked — a link carrying someone else's ids is a bug whether or not
     * it happens to be harmless.
     */
    public const COUPLE_PAGES = [
        'client_budget.php',
        'client_checklist.php',
        'client_deposit.php',
        'client_guests.php',
        'client_manage_account_details.php',
        'client_vendors.php',
        'client_website.php',
    ];

    /**
     * The printable sheets resolve the booking alone (sheet_boot) and must, since
     * staff open a couple's sheet with that couple's user id. Authorization is the
     * booking row's owner; the user_id is an address, so the rule they are held
     * to is: bounce a booking the session does not own, and never render an id
     * the booking does not name.
     */
    public const SHEET_PAGES = [
        'sheet_guests.php',
        'sheet_venue.php',
    ];

    /** The sheet that used to echo the query-string user_id into its form. */
    private const SHEET_PAGE = 'sheet_venue.php';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** Why the sweep cannot run, so a skip is never a silent shrug. */
    private static string $skipReason = '';
    /** @var array<string, string> */
    private static array $jar = [];
    private static string $csrf = '';

    // The two couples this class creates and owns.
    private static int $ownerBooking   = 0;
    private static int $ownerUser      = 0;
    private static int $foreignBooking = 0;
    private static int $foreignUser    = 0;
    private static string $ownerEmail   = '';
    private static string $foreignEmail = '';

    // Probe rows on those bookings.
    private static int $foreignGuestId = 0;
    private static int $foreignItemId  = 0;
    private static int $foreignEventId = 0;
    private static int $ownEventId     = 0;
    private static int $ownItemId      = 0;
    private static int $controlGuestsBefore = 0;

    // The vendor row this class trades for the owner couple's account id, and
    // the login name it signs in with. Both are cleaned up again.
    private static int $collidingVendorId = 0;
    private static string $collidingVendorEmail = '';

    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            foreach (['Accounts', 'Account_Details', 'Booking', 'Events'] as $class) {
                if (!class_exists($class, false)) {
                    $file = __DIR__ . '/../admin/include/' . $class . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            }
            if (!function_exists('register_client')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
            self::$skipReason = 'database unreachable: ' . $e->getMessage();
            return;
        }

        self::bootServer();
        if (self::$base === null) {
            self::$skipReason = self::$skipReason !== '' ? self::$skipReason : 'php -S server could not be booted';
            return;
        }

        if (!self::makeCouples()) {
            return;
        }
        if (!self::login()) {
            self::$skipReason = 'the probe couple could not sign in';
            self::$base = null;
            return;
        }

        // A CSRF token is session-wide; any couple page that renders a form has one.
        self::$csrf = self::token('/admin/client_guests.php?booking_id=' . self::$ownerBooking . '&user_id=' . self::$ownerUser);
        if (self::$csrf === '') {
            self::$skipReason = 'no CSRF token on the couple guest page (not rendered)';
            self::$base = null;
            return;
        }

        self::makeProbeRows();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanUp();
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ---------------------------------------------------------------- harness

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_own_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_own_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 32000 + random_int(0, 7999);
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
     * Minimal curl-free HTTP client; redirects are NOT followed, so a bounce can
     * be told apart from a render. The request line must NOT go in 'header'.
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
            'timeout'         => 15,
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

    private static function login(): bool
    {
        $post = self::loginAs(self::$ownerEmail, self::PROBE_PASS);
        return $post['status'] === 302 && stripos($post['location'] ?? '', 'client') !== false;
    }

    /**
     * Sign in as ANY account on a FRESH session and return the login response
     * (login.php auto-redirects an already signed-in session, so the jar is
     * emptied first). The caller decides what the correct landing page is: a
     * couple lands in the portal, a vendor on their own home page.
     *
     * @return array{status:int, location:string, body:string}
     */
    private static function loginAs(string $email, string $password): array
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            return ['status' => 0, 'location' => '', 'body' => ''];
        }
        return self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $password,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function token(string $path): string
    {
        $body = self::http('GET', $path)['body'];
        return preg_match('/name="_token" value="([^"]+)"/', $body, $m)
            ? html_entity_decode($m[1], ENT_QUOTES)
            : '';
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('ownership sweep unavailable — ' . (self::$skipReason !== '' ? self::$skipReason : 'unknown'));
        }
    }

    // --------------------------------------------------------------- fixtures

    /**
     * Create the two couples through the application's own sign-up path, so the
     * rows this class asserts about are rows this class wrote.
     */
    private static function makeCouples(): bool
    {
        $stamp = date('ymdHis') . random_int(1000, 9999);
        self::$ownerEmail   = 'ownership-probe-owner-' . $stamp . '@example.test';
        self::$foreignEmail = 'ownership-probe-foreign-' . $stamp . '@example.test';

        foreach ([
            [self::$ownerEmail, '0700000001', 'Ownership', 'Owner'],
            [self::$foreignEmail, '0700000002', 'Ownership', 'Foreign'],
        ] as [$email, $phone, $first, $last]) {
            $created = false;
            // The date must be free: check_wedding_date refolves a collision, so retry.
            for ($attempt = 0; $attempt < 8 && !$created; $attempt++) {
                $date = date('Y-m-d', strtotime('+' . random_int(300, 2000) . ' days'));
                $res = register_client($first, $last, $email, $phone, self::PROBE_PASS, $date, 6);
                $created = !empty($res['ok']);
            }
            if (!$created) {
                self::$skipReason = 'register_client() could not create the probe couple (' . ($res['error'] ?? 'unknown') . ')';
                self::$base = null;
                return false;
            }
        }

        try {
            self::$ownerUser = (int) self::$db->fetchValue(
                'SELECT user_id FROM tblaccounts WHERE user_email = :e',
                [':e' => self::$ownerEmail]
            );
            self::$foreignUser = (int) self::$db->fetchValue(
                'SELECT user_id FROM tblaccounts WHERE user_email = :e',
                [':e' => self::$foreignEmail]
            );
            self::$ownerBooking = (int) self::$db->fetchValue(
                'SELECT booking_id FROM tblweddingbook WHERE user_id = :u ORDER BY booking_id DESC LIMIT 1',
                [':u' => self::$ownerUser]
            );
            self::$foreignBooking = (int) self::$db->fetchValue(
                'SELECT booking_id FROM tblweddingbook WHERE user_id = :u ORDER BY booking_id DESC LIMIT 1',
                [':u' => self::$foreignUser]
            );
        } catch (Throwable $e) {
            self::$skipReason = 'could not resolve the probe couples: ' . $e->getMessage();
            self::$base = null;
            return false;
        }

        if (self::$ownerUser < 1 || self::$foreignUser < 1 || self::$ownerBooking < 1 || self::$foreignBooking < 1) {
            self::$skipReason = 'the probe couples were created but could not be read back';
            self::$base = null;
            return false;
        }
        return true;
    }

    /** Rows on both bookings, so a leak has something real to leak. */
    private static function makeProbeRows(): void
    {
        try {
            self::$db->execute(
                'INSERT INTO tblguest (booking_id, fullname, guestname, address, state, zipcode, priority,
                                       out_of_town, relationship, tracks_and_gifts, city, rsvp_status, rsvp_token)
                 VALUES (:b, :f, :g, :a, :s, :z, :p, :o, :r, :t, :c, :st, :tok)',
                [':b' => self::$foreignBooking, ':f' => self::GUEST_MARK, ':g' => 'Probe', ':a' => '', ':s' => '',
                 ':z' => '', ':p' => 'D', ':o' => 'n', ':r' => 'regular', ':t' => '', ':c' => 'Nairobi',
                 ':st' => 'pending', ':tok' => bin2hex(random_bytes(16))]
            );
            self::$foreignGuestId = (int) self::$db->lastInsertId();

            self::$db->execute(
                'INSERT INTO tblchecklist (booking_id, phase, title, position, is_done) VALUES (:b, :p, :t, :pos, 0)',
                [':b' => self::$foreignBooking, ':p' => 'probe', ':t' => self::ITEM_MARK, ':pos' => 99]
            );
            self::$foreignItemId = (int) self::$db->lastInsertId();

            $start = date('Y-m-d H:i:s', strtotime('+1 day'));
            self::$db->execute(
                'INSERT INTO events (booking_id, title, location, color, start) VALUES (:b, :t, :l, :c, :s)',
                [':b' => self::$foreignBooking, ':t' => self::EVENT_MARK . ' foreign', ':l' => 'Probe venue',
                 ':c' => '#000000', ':s' => $start]
            );
            self::$foreignEventId = (int) self::$db->lastInsertId();

            self::$db->execute(
                'INSERT INTO tblwedding_websites (booking_id, slug, title, venue, wedding_date, is_published, views)
                 VALUES (:b, :s, :t, :v, :d, 0, 0)',
                [':b' => self::$foreignBooking, ':s' => self::SITE_SLUG . '-' . self::$foreignBooking,
                 ':t' => self::EVENT_MARK, ':v' => 'Probe venue', ':d' => date('Y-m-d', strtotime('+60 days'))]
            );

            // The couple's own booking needs one function, otherwise the venue
            // sheet renders no headcount editor and the echo regression would
            // pass vacuously.
            self::$db->execute(
                'INSERT INTO events (booking_id, title, location, color, start) VALUES (:b, :t, :l, :c, :s)',
                [':b' => self::$ownerBooking, ':t' => self::EVENT_MARK . ' own', ':l' => 'Probe venue',
                 ':c' => '#065F46', ':s' => $start]
            );
            self::$ownEventId = (int) self::$db->lastInsertId();

            // …one checklist item and one website on the couple's OWN booking
            // too, so the vendor leg's write probes have a real row that must
            // survive a refused write (a probe aimed at a nonexistent row would
            // pass vacuously). Both are removed by marker in cleanUp().
            self::$db->execute(
                'INSERT INTO tblchecklist (booking_id, phase, title, position, is_done) VALUES (:b, :p, :t, :pos, 0)',
                [':b' => self::$ownerBooking, ':p' => 'probe', ':t' => self::ITEM_MARK . ' own', ':pos' => 98]
            );
            self::$ownItemId = (int) self::$db->lastInsertId();

            self::$db->execute(
                'INSERT INTO tblwedding_websites (booking_id, slug, title, venue, wedding_date, is_published, views)
                 VALUES (:b, :s, :t, :v, :d, 0, 0)',
                [':b' => self::$ownerBooking, ':s' => self::SITE_SLUG . '-own', ':t' => self::EVENT_MARK,
                 ':v' => 'Probe venue', ':d' => date('Y-m-d', strtotime('+60 days'))]
            );
        } catch (Throwable $e) {
            self::$skipReason = 'probe rows could not be created: ' . $e->getMessage();
            self::$base = null; // skip rather than half-run
        }
    }

    /**
     * Manufacture the collision on purpose: a tblusers row whose id is the owner
     * couple's tblaccounts id, with the probe password so it can walk through the
     * ordinary login door.
     *
     * Insert-only: if that number is already a staff/vendor row in tblusers the
     * sweep skips instead of touching somebody's account, and the insert is
     * undone by cleanUpVendor() either way.
     */
    private static function makeCollidingVendor(): bool
    {
        self::$collidingVendorId = 0;
        self::$collidingVendorEmail = self::VENDOR_MARK . self::$ownerUser . '@example.test';
        try {
            $taken = (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tblusers WHERE id = :i',
                [':i' => self::$ownerUser]
            );
            if ($taken > 0) {
                self::$skipReason = 'id ' . self::$ownerUser . ' is already a tblusers row';
                return false;
            }
            self::$db->execute(
                'INSERT INTO tblusers (id, firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created, phone, email_verified)
                 VALUES (:id, :fn, :ln, :g, :un, :pw, :em, :des, :ad, :lvl, :pic, NOW(), :ph, 1)',
                [
                    ':id' => self::$ownerUser, ':fn' => 'Probe', ':ln' => 'Vendor', ':g' => 'f',
                    ':un' => 'ownership_probe_vendor_' . self::$ownerUser,
                    ':pw' => password_hash(self::PROBE_PASS, PASSWORD_DEFAULT),
                    ':em' => self::$collidingVendorEmail, ':des' => 'Vendor', ':ad' => '',
                    ':lvl' => '0', ':pic' => '', ':ph' => '0700000003',
                ]
            );
        } catch (Throwable $e) {
            self::$skipReason = 'the colliding vendor row could not be created: ' . $e->getMessage();
            return false;
        }
        self::$collidingVendorId = self::$ownerUser;
        return true;
    }

    /**
     * Remove the throwaway vendor row. Never addressed by id alone: the email
     * marker must match too, so a seeded staff account that happens to hold that
     * number can never be deleted by this class.
     */
    private static function cleanUpVendor(): void
    {
        if (self::$db === null || self::$collidingVendorId < 1) {
            return;
        }
        try {
            self::$db->execute(
                'DELETE FROM tblusers WHERE id = :id AND email = :em',
                [':id' => self::$collidingVendorId, ':em' => self::$collidingVendorEmail]
            );
        } catch (Throwable $e) {
            fwrite(STDERR, '[CoupleBookingOwnershipTest] vendor cleanup failed: ' . $e->getMessage() . "\n");
        }
        self::$collidingVendorId = 0;
    }

    /**
     * Delete every row this class created.
     *
     * Each statement stands alone — one failing call used to abort the rest and
     * leave rows behind for the next run to trip over — and each is keyed by a
     * marker this class owns or by an id it created, never by a booking alone.
     */
    private static function cleanUp(): void
    {
        if (self::$db === null) {
            return;
        }
        $statements = [
            // The one row this class adds to tblusers (a marker match is required,
            // so a real staff account on the same number is never touched).
            'the colliding vendor' => ['DELETE FROM tblusers WHERE email LIKE :m', [':m' => self::VENDOR_MARK . '%']],
            'headcount rows' => ['DELETE FROM tbleventbooking WHERE booking_id IN (:a, :b)', [
                ':a' => self::$ownerBooking, ':b' => self::$foreignBooking,
            ]],
            'guests'    => ['DELETE FROM tblguest WHERE fullname LIKE :m', [':m' => self::GUEST_MARK . '%']],
            'items'     => ['DELETE FROM tblchecklist WHERE title LIKE :m', [':m' => self::ITEM_MARK . '%']],
            'events'    => ['DELETE FROM events WHERE title LIKE :m', [':m' => self::EVENT_MARK . '%']],
            'websites'  => ['DELETE FROM tblwedding_websites WHERE slug LIKE :m', [':m' => self::SITE_SLUG . '%']],
            'notifications' => ['DELETE FROM tblnotifications WHERE booking_id IN (:a, :b)', [
                ':a' => self::$ownerBooking, ':b' => self::$foreignBooking,
            ]],
            'the owner couple' => [
                'DELETE FROM tblaccounts WHERE user_id IN (:a, :b)', [':a' => self::$ownerUser, ':b' => self::$foreignUser],
            ],
            'their details' => [
                'DELETE FROM tblaccounts_detail WHERE user_id IN (:a, :b)', [':a' => self::$ownerUser, ':b' => self::$foreignUser],
            ],
            'their bookings' => [
                // Distinct placeholders for each use: a named parameter may not
                // appear twice in one statement under native prepares (HY093).
                'DELETE FROM tblweddingbook WHERE user_id IN (:u1, :u2) OR booking_id IN (:b1, :b2)',
                [':u1' => self::$ownerUser, ':u2' => self::$foreignUser,
                 ':b1' => self::$ownerBooking, ':b2' => self::$foreignBooking],
            ],
        ];
        foreach ($statements as $what => [$sql, $params]) {
            try {
                self::$db->execute($sql, $params);
            } catch (Throwable $e) {
                fwrite(STDERR, '[CoupleBookingOwnershipTest] cleanup of ' . $what . ' failed: ' . $e->getMessage() . "\n");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function foreignState(): array
    {
        return self::bookingState(self::$foreignBooking, self::$foreignUser);
    }

    /**
     * One booking's observable state, for a before/after comparison around a
     * refused write. Parameterized because two different attacks aim at rows:
     * a foreign COUPLE (foreignState) and a VENDOR session whose tblusers id
     * equals the owner's tblaccounts id.
     *
     * @return array<string, mixed>
     */
    private static function bookingState(int $b, int $ownerUser): array
    {
        $one = static fn(string $sql, array $p = []) => self::$db->fetchValue($sql, $p);
        return [
            'guest_names'       => (string) $one('SELECT COALESCE(GROUP_CONCAT(fullname ORDER BY id), "") FROM tblguest WHERE booking_id = :b', [':b' => $b]),
            'guest_count'       => (int) $one('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $b]),
            'checklist_sum'     => (int) $one('SELECT COALESCE(SUM(is_done), 0) FROM tblchecklist WHERE booking_id = :b', [':b' => $b]),
            'checklist_count'   => (int) $one('SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b', [':b' => $b]),
            'website_published' => (int) $one('SELECT COALESCE(MAX(is_published), -1) FROM tblwedding_websites WHERE booking_id = :b', [':b' => $b]),
            'headcount_rows'    => (int) $one('SELECT COUNT(*) FROM tbleventbooking WHERE booking_id = :b', [':b' => $b]),
            'installment_rows'  => (int) $one('SELECT COUNT(*) FROM tbl_installment_plans WHERE booking_id = :b', [':b' => $b]),
            'enquiry_rows'      => (int) $one('SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b', [':b' => $b]),
            'liquidation_rows'  => (int) $one('SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b', [':b' => $b]),
            'booking_package'   => (int) $one('SELECT wedding_type FROM tblweddingbook WHERE booking_id = :b', [':b' => $b]),
            'booking_date'      => (string) $one('SELECT wedding_date FROM tblweddingbook WHERE booking_id = :b', [':b' => $b]),
            'owner_firstname'   => (string) $one('SELECT firstname FROM tblaccounts_detail WHERE user_id = :u', [':u' => $ownerUser]),
            'owner_phone'       => (string) $one('SELECT phone FROM tblaccounts_detail WHERE user_id = :u', [':u' => $ownerUser]),
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function couplePages(): array
    {
        $cases = [];
        foreach (self::COUPLE_PAGES as $page) {
            $cases[$page] = [$page];
        }
        return $cases;
    }

    /** @return array<string, array{0: string}> */
    public static function sheetPages(): array
    {
        $cases = [];
        foreach (self::SHEET_PAGES as $page) {
            $cases[$page] = [$page];
        }
        return $cases;
    }

    /** Every couple-facing page, for the "renders for its owner" leg. */
    public static function allCouplePages(): array
    {
        $cases = [];
        foreach ([...self::COUPLE_PAGES, ...self::SHEET_PAGES] as $page) {
            $cases[$page] = [$page];
        }
        return $cases;
    }

    // ------------------------------------------------------------------ tests

    #[Group('integration')]
    #[DataProvider('couplePages')]
    public function testForeignBookingIsBouncedInEveryParamCombination(string $page): void
    {
        $this->requireHttp();

        foreach ([
            'foreign booking + foreign user' => self::$foreignBooking . '&user_id=' . self::$foreignUser,
            'foreign booking + own user'     => self::$foreignBooking . '&user_id=' . self::$ownerUser,
            'own booking + foreign user'     => self::$ownerBooking . '&user_id=' . self::$foreignUser,
        ] as $label => $pair) {
            $res = self::http('GET', '/admin/' . $page . '?booking_id=' . $pair);
            self::assertSame(302, $res['status'], $page . " must bounce ({$label})");
            self::assertStringContainsString('client.php', $res['location'] ?? '', $page . " must bounce to the client dashboard ({$label})");
        }
    }

    /**
     * The sheets: a booking the session does not own bounces whatever user id
     * accompanies it, the couple's own renders — and, with someone else's id in
     * the query string, the page must not repeat it back (the defect this sweep
     * was written for).
     */
    #[Group('integration')]
    #[DataProvider('sheetPages')]
    public function testSheetsBounceAForeignBookingAndNeverEchoAnotherId(string $page): void
    {
        $this->requireHttp();

        foreach ([self::$foreignUser, self::$ownerUser] as $userId) {
            $res = self::http('GET', '/admin/' . $page . '?booking_id=' . self::$foreignBooking . '&user_id=' . $userId);
            self::assertSame(302, $res['status'], $page . ' must bounce a booking the session does not own (user ' . $userId . ')');
            self::assertStringContainsString('client.php', $res['location'] ?? '', $page . ' must bounce to the client dashboard');
        }

        $own = self::http('GET', '/admin/' . $page . '?booking_id=' . self::$ownerBooking . '&user_id=' . self::$ownerUser);
        self::assertSame(200, $own['status'], $page . ' must render for the owning couple');
        self::assertStringNotContainsString('user_id=' . self::$foreignUser, $own['body'], $page . ' must not carry another account id in its links');

        $forged = self::http('GET', '/admin/' . $page . '?booking_id=' . self::$ownerBooking . '&user_id=' . self::$foreignUser);
        self::assertSame(200, $forged['status'], $page . ' still serves the owner their own sheet');
        self::assertStringNotContainsString('user_id=' . self::$foreignUser, $forged['body'], $page . ' must ignore the unverified user_id it was handed');
        self::assertStringNotContainsString('value="' . self::$foreignUser . '"', $forged['body'], $page . ' must not put another account into a form field');
    }

    #[Group('integration')]
    #[DataProvider('allCouplePages')]
    public function testOwnBookingRendersForTheCouple(string $page): void
    {
        $this->requireHttp();

        $res = self::http('GET', '/admin/' . $page . '?booking_id=' . self::$ownerBooking . '&user_id=' . self::$ownerUser);
        self::assertSame(200, $res['status'], $page . ' must render for the owning couple');
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $res['body'], $page . ' must not leak PHP issues (' . $marker . ')');
        }
    }

    /**
     * The heart of the sweep: every write endpoint, aimed at the foreign booking
     * with a valid CSRF token, must bounce AND leave the database untouched.
     */
    #[Group('integration')]
    public function testEveryCoupleWriteEndpointRefusesAForeignBooking(): void
    {
        $this->requireHttp();
        self::assertNotSame('', self::$csrf, 'the sweep needs a session CSRF token');

        $before = self::foreignState();
        $eventDate = date('Y-m-d', strtotime('+30 days'));

        $foreign = 'booking_id=' . self::$foreignBooking . '&user_id=' . self::$foreignUser;
        $mixed   = 'booking_id=' . self::$foreignBooking . '&user_id=' . self::$ownerUser;

        $probes = [
            'guest add'          => ['/admin/client_guest_save.php?' . $foreign, ['save_guest' => '1', 'guest_id' => '0', 'fullname' => self::GUEST_MARK . ' added', 'guestname' => 'Hacked']],
            'guest edit'         => ['/admin/client_guest_save.php?' . $foreign, ['save_guest' => '1', 'guest_id' => (string) self::$foreignGuestId, 'fullname' => self::GUEST_MARK . ' edited', 'guestname' => 'Hacked']],
            'guest delete'       => ['/admin/client_guest_save.php?' . $foreign, ['delete_guest' => '1', 'guest_id' => (string) self::$foreignGuestId]],
            'checklist toggle'   => ['/admin/client_checklist_toggle.php?' . $foreign, ['item_id' => (string) self::$foreignItemId]],
            'installment plan'   => ['/admin/client_installment_plan.php?' . $foreign, ['months' => '6']],
            'installment cancel' => ['/admin/client_installment_plan.php?' . $foreign, ['cancel' => '1']],
            'website publish'    => ['/admin/client_website.php?' . $foreign, ['toggle_publish' => '1']],
            'headcounts'         => ['/admin/event_booking_save.php', ['save_headcounts' => '1', 'booking_id' => (string) self::$foreignBooking, 'user_id' => (string) self::$foreignUser, 'family' => [(string) self::$foreignEventId => '999'], 'individual' => [(string) self::$foreignEventId => '999']]],
            'vendor enquiry'     => ['/admin/client_vendor_enquiry.php?' . $foreign, ['send_enquiry' => '1', 'vendor_id' => '200', 'package_id' => '200', 'event_date' => $eventDate, 'message' => 'OWNERSHIP-PROBE']],
            'mpesa deposit'      => ['/admin/client_deposit.php?' . $foreign, ['stk_pay' => '1', 'event_id' => (string) self::$foreignEventId, 'amount' => '1000', 'phone' => '254700000000']],
            'guest add (mixed)'  => ['/admin/client_guest_save.php?' . $mixed, ['save_guest' => '1', 'guest_id' => '0', 'fullname' => self::GUEST_MARK . ' mixed', 'guestname' => 'Hacked']],
            'website (mixed)'    => ['/admin/client_website.php?' . $mixed, ['toggle_publish' => '1']],
            'headcounts (mixed)' => ['/admin/event_booking_save.php', ['save_headcounts' => '1', 'booking_id' => (string) self::$foreignBooking, 'user_id' => (string) self::$ownerUser, 'family' => [(string) self::$foreignEventId => '999'], 'individual' => [(string) self::$foreignEventId => '999']]],
        ];

        foreach ($probes as $label => [$path, $data]) {
            $res = self::http('POST', $path, $data + ['_token' => self::$csrf]);
            self::assertSame(302, $res['status'], 'foreign ' . $label . ' must be refused');
            self::assertStringContainsString('client.php', $res['location'] ?? '', 'foreign ' . $label . ' must bounce to the client dashboard');
        }

        self::assertSame(
            $before,
            self::foreignState(),
            'no refused write may change a single value on the foreign booking'
        );
    }

    /** The refusals above are only meaningful if the same endpoints do write for the owner. */
    #[Group('integration')]
    public function testOwnBookingWritesStillLand(): void
    {
        $this->requireHttp();

        self::$controlGuestsBefore = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND fullname = :f',
            [':b' => self::$ownerBooking, ':f' => self::GUEST_MARK . ' control']
        );

        $own = 'booking_id=' . self::$ownerBooking . '&user_id=' . self::$ownerUser;
        $res = self::http('POST', '/admin/client_guest_save.php?' . $own, [
            '_token' => self::$csrf, 'save_guest' => '1', 'guest_id' => '0',
            'fullname' => self::GUEST_MARK . ' control', 'guestname' => 'Control',
        ]);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('client_guests.php', $res['location'] ?? '', 'the couple is returned to their own guest list');

        $saved = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND fullname = :f',
            [':b' => self::$ownerBooking, ':f' => self::GUEST_MARK . ' control']
        );
        self::assertSame(self::$controlGuestsBefore + 1, $saved, 'the control write must add exactly one row to the couple own booking');
    }

    /**
     * sheet_venue.php used to render whatever user_id the query string carried: a
     * couple's own booking with someone else's id produced a form addressed to
     * that account. Nothing could be written through it (the writer checks the
     * booking), but the page must not emit an id it has not proved.
     */
    #[Group('integration')]
    public function testVenueSheetNeverEchoesAForeignUserId(): void
    {
        $this->requireHttp();

        $res = self::http('GET', '/admin/' . self::SHEET_PAGE . '?booking_id=' . self::$ownerBooking . '&user_id=' . self::$foreignUser);
        self::assertSame(200, $res['status'], 'the couple may open their own sheet');
        self::assertStringNotContainsString(
            'name="user_id" value="' . self::$foreignUser . '"',
            $res['body'],
            'the sheet must not address its form to an account the session does not own'
        );
        self::assertStringContainsString(
            'name="user_id" value="' . self::$ownerUser . '"',
            $res['body'],
            'the headcount form carries the booking owner'
        );
    }

    /** The write side of the same rule: a forged posted user_id must not survive into the redirect. */
    #[Group('integration')]
    public function testHeadcountSaveIgnoresAForgedUserId(): void
    {
        $this->requireHttp();

        $res = self::http('POST', '/admin/event_booking_save.php', [
            '_token' => self::$csrf, 'save_headcounts' => '1',
            'booking_id' => (string) self::$ownerBooking, 'user_id' => (string) self::$foreignUser,
            'family' => [(string) self::$ownEventId => '12'],
            'individual' => [(string) self::$ownEventId => '34'],
        ]);
        self::assertSame(302, $res['status'], 'the couple may plan their own wedding');
        self::assertStringNotContainsString('user_id=' . self::$foreignUser, $res['location'] ?? '', 'the redirect must not carry another account id');
        self::assertStringContainsString('user_id=' . self::$ownerUser, $res['location'] ?? '', 'the redirect carries the booking owner');

        $row = self::$db->fetchOne(
            'SELECT booking_id, family, individual FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
            [':b' => self::$ownerBooking, ':f' => self::$ownEventId]
        );
        self::assertNotNull($row, 'the plan lands on the couple own booking');
        self::assertSame(self::$ownerBooking, (int) $row->booking_id);
    }

    /**
     * THE CROSS-TABLE HOLE (fixed). Couples live in tblaccounts (primary key
     * user_id); planners, admins and vendors live in tblusers (primary key id) —
     * two independent auto-increment sequences whose numbers look alike. A VENDOR
     * is a tblusers row with access_level 0, so is_staff() is FALSE for it, and
     * every couple page decided ownership with
     * `!is_staff() && $booking->user_id === $_SESSION['id']`: a vendor whose id
     * equalled a couple's account id was served that couple's whole portal
     * (measured before the fix: HTTP 200 with the guest list, payments, checklist,
     * deposit page, account details, wedding page and both printable sheets).
     *
     * The class manufactures that collision — a throwaway tblusers row carrying
     * the owner couple's account id — signs in as it through the ordinary login
     * door, and asserts the vendor is refused on every page and every write
     * endpoint, changes nothing on the booking, and that the owner's own session
     * still works afterwards (the jar is restored in the finally block).
     */
    #[Group('integration')]
    public function testAVendorWhoseIdEqualsACoupleAccountIsRefusedEverywhere(): void
    {
        $this->requireHttp();
        if (!self::makeCollidingVendor()) {
            self::markTestSkipped('colliding-vendor leg unavailable — ' . self::$skipReason);
        }

        $before = self::bookingState(self::$ownerBooking, self::$ownerUser);
        try {
            $login = self::loginAs(self::$collidingVendorEmail, self::PROBE_PASS);
            self::assertSame(302, $login['status'], 'the throwaway vendor must be able to sign in');
            self::assertStringContainsString('vendor', (string) ($login['location'] ?? ''), 'and lands on a vendor page, never the couple portal');

            // 1. Every couple page and sheet: bounced, with no couple data in the body.
            foreach ([...self::COUPLE_PAGES, ...self::SHEET_PAGES] as $page) {
                $res = self::http('GET', '/admin/' . $page . '?booking_id=' . self::$ownerBooking . '&user_id=' . self::$ownerUser);
                self::assertSame(302, $res['status'], $page . ' must refuse a vendor whose id equals the couple account id');
                self::assertStringContainsString('client.php', $res['location'] ?? '', $page . ' must bounce to the client dashboard');
                self::assertStringNotContainsString(self::$ownerEmail, $res['body'], $page . ' must not render the couple to a vendor');
            }

            // 2. Every write endpoint aimed at that booking: refused, nothing written.
            //    A failed CSRF alone would not satisfy these assertions — it
            //    redirects to the page it came from, never to client.php.
            $own = 'booking_id=' . self::$ownerBooking . '&user_id=' . self::$ownerUser;
            $probes = [
                'guest add'          => ['/admin/client_guest_save.php?' . $own, ['save_guest' => '1', 'guest_id' => '0', 'fullname' => self::GUEST_MARK . ' vendor', 'guestname' => 'Hacked']],
                'guest delete'       => ['/admin/client_guest_save.php?' . $own, ['delete_guest' => '1', 'guest_id' => (string) self::$foreignGuestId]],
                'checklist toggle'   => ['/admin/client_checklist_toggle.php?' . $own, ['item_id' => (string) self::$ownItemId]],
                'installment plan'   => ['/admin/client_installment_plan.php?' . $own, ['months' => '6']],
                'installment cancel' => ['/admin/client_installment_plan.php?' . $own, ['cancel' => '1']],
                'website publish'    => ['/admin/client_website.php?' . $own, ['toggle_publish' => '1']],
                'headcounts'         => ['/admin/event_booking_save.php', ['save_headcounts' => '1', 'booking_id' => (string) self::$ownerBooking, 'user_id' => (string) self::$ownerUser, 'family' => [(string) self::$ownEventId => '999'], 'individual' => [(string) self::$ownEventId => '999']]],
                'vendor enquiry'     => ['/admin/client_vendor_enquiry.php?' . $own, ['send_enquiry' => '1', 'vendor_id' => '200', 'package_id' => '200', 'event_date' => date('Y-m-d', strtotime('+30 days')), 'message' => 'OWNERSHIP-PROBE vendor']],
                'mpesa deposit'      => ['/admin/client_deposit.php?' . $own, ['stk_pay' => '1', 'event_id' => (string) self::$ownEventId, 'amount' => '1000', 'phone' => '254700000000']],
            ];
            foreach ($probes as $label => [$path, $data]) {
                $res = self::http('POST', $path, $data + ['_token' => self::$csrf]);
                self::assertSame(302, $res['status'], 'a vendor must not ' . $label . ' on a couple booking');
                self::assertStringContainsString('client.php', $res['location'] ?? '', 'a vendor must bounce on ' . $label);
            }
            self::assertSame(
                $before,
                self::bookingState(self::$ownerBooking, self::$ownerUser),
                'no write refused from a colliding vendor session may reach the booking'
            );
        } finally {
            self::cleanUpVendor();
            // The rest of the class runs as the couple: put their session back.
            self::login();
        }
    }
}
