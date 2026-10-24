<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Subscription paywall regression.
 *
 * The paywall keeps a lapsed planner out of the back office. Three ways a
 * future refactor could silently reopen it, each locked down below:
 *
 *  1. Deep-linking around it. A staff session may open ANY couple's booking
 *     (that is how previews work), so the client*.php pages, the printable
 *     sheets and the booking-scoped staff pages all carry the gate too —
 *     otherwise a lapsed planner reads the whole CRM by URL while the
 *     dashboard bounces him.
 *  2. Locking out the operator. A super admin (access_level 2) whose own plan
 *     lapsed must still reach every page, or nobody is left who can renew
 *     anyone's plan — and login routing must agree with the gate, or he is
 *     greeted by the paywall on every sign-in.
 *  3. Billing people who are not billed. Couples and vendors must never see
 *     the paywall; a vendor in particular carries a staff-row (tblusers)
 *     subscription of his own, and an EXPIRED one must not gate him.
 *
 * Page taxonomy matters for the assertions: a "standalone" page renders with no
 * parameters, while a "booking-scoped" page sends a bare hit back to client.php
 * through its own missing-booking guard — so for those, "allowed through the
 * gate" means 200 WITH a real booking. Verified against the v2.1 seed, where
 * booking 200 (brian.wafula@gmail.com) is a real wedding.
 *
 * Fixtures from the v2.1 KENYA_ONLY seed (see
 * testSeedFixtureStillHasTheShapesThisGateIsTestedAgainst):
 *   amina@wpms.co.ke        id 7,   access_level 1 — professional plan ACTIVE
 *   otieno@wpms.co.ke       id 101, access_level 1 — no subscription → locked
 *   abdi@wpms.co.ke         id 103, access_level 2 — no subscription, but the
 *                                  operator, so is_super_admin() must exempt him
 *   kamau@nyamachoma.co.ke  id 8,   access_level 0 — vendor, EXPIRED starter
 *                                  subscription: the exact shape that must not
 *                                  be gated (vendors earn commission, not SaaS)
 *   brian.wafula@gmail.com  id/booking 200 — couple
 *
 * Integration ('integration' group) — needs a live DB with that seed and a
 * bootable php -S. Skipped cleanly when either is unavailable.
 */
final class SubscriptionPaywallTest extends TestCase
{
    private const ACTIVE_PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const LOCKED_PLANNER_EMAIL = 'otieno@wpms.co.ke';
    private const SUPER_ADMIN_EMAIL    = 'abdi@wpms.co.ke';
    private const VENDOR_EMAIL         = 'kamau@nyamachoma.co.ke';
    private const COUPLE_EMAIL         = 'brian.wafula@gmail.com';

    private const ACTIVE_PLANNER_PASS  = 'Test@1234';
    private const LOCKED_PLANNER_PASS  = 'Test@1234';
    private const SUPER_ADMIN_PASS     = 'Admin@2024!';
    private const VENDOR_PASS          = 'Vendor@2024';
    private const COUPLE_PASS          = 'Test@1234';

    private const COUPLE_BOOKING = 200; // brian.wafula@gmail.com owns booking 200

    /** Staff-only pages that render standalone (no booking parameter). */
    public const STAFF_STANDALONE = [
        'dashboard.php',
        'users.php',
        'revenue.php',
        'gallery.php',
        'sms_center.php',
        'budget.php',
        'service_list.php',
        'mpesa_ledger.php',
        'login_activity.php',
        'task_all_calendar.php',
    ];

    /**
     * Couple-shared pages that render standalone FOR STAFF, with no booking
     * parameter. A couple needs its own ids for most of these — see
     * COUPLE_STANDALONE / COUPLE_BOOKING_PAGES below, because the pair
     * (booking_id, user_id) is checked against the session for a client.
     */
    /*
     * Pages a couple shares that resolve THEIR WHOLE STATE from the session, so
     * they render with no query string at all. client_budget.php and
     * client_guests.php are deliberately NOT here: both take booking_id AND
     * user_id, every link into them in the application carries both, and asking
     * for them bare was only ever a staff fall-through that rendered the page
     * against `$booking === false`. The shared ownership rule
     * (booking_visible_to_session(), admin/include/Helper.php) refuses a booking
     * that does not resolve — for staff too, the same way events.php and
     * client_edit.php have always redirected — so a real booking is supplied
     * for them in BOOKING_SCOPED instead.
     */
    public const SHARED_STANDALONE = [
        'client.php',
        'notifications.php',
    ];

    /** Couple pages that render with no parameters at all. */
    public const COUPLE_STANDALONE = ['client.php', 'notifications.php'];

    /** Couple pages that need the couple's OWN booking_id/user_id pair. */
    public const COUPLE_BOOKING_PAGES = [
        'client_budget.php',
        'client_guests.php',
        'client_checklist.php',
        'client_vendors.php',
        'client_website.php',
        'sheet_guests.php',
        'sheet_venue.php',
    ];

    /**
     * Booking-scoped pages. These are the deep-link surface: a staff session
     * can open ANY couple's booking here, so the paywall has to sit above the
     * booking-parameter logic. Bare, they bounce to client.php by design; with
     * a real booking they render 200 for an allowed role.
     */
    public const BOOKING_SCOPED = [
        'events.php',
        'rsvp_dashboard.php',
        'client_budget.php',
        'client_checklist.php',
        'client_guests.php',
        'client_vendors.php',
        'client_website.php',
        'sheet_guests.php',
        'sheet_venue.php',
    ];

    /** Gated like the rest, but renders its own 404 when the receipt id is unknown. */
    public const GATED_WITHOUT_BARE_200 = ['receipt.php'];

    private static ?Database $db = null;
    private static ?string $base = null;
    private static ?int $activePlannerId = null;
    private static ?int $lockedPlannerId = null;
    private static ?int $superAdminId = null;
    private static ?int $vendorId = null;
    private static ?int $coupleId = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

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

        self::bootServer();
        if (self::$db === null || self::$base === null) {
            return; // tests will skip
        }

        try {
            self::$activePlannerId = self::idByEmail('tblusers', 'id', 'email', self::ACTIVE_PLANNER_EMAIL);
            self::$lockedPlannerId = self::idByEmail('tblusers', 'id', 'email', self::LOCKED_PLANNER_EMAIL);
            self::$superAdminId    = self::idByEmail('tblusers', 'id', 'email', self::SUPER_ADMIN_EMAIL);
            self::$vendorId        = self::idByEmail('tblusers', 'id', 'email', self::VENDOR_EMAIL);
            self::$coupleId        = self::idByEmail('tblaccounts', 'user_id', 'user_email', self::COUPLE_EMAIL);
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }

        // All five fixtures must exist AND still carry the documented password,
        // otherwise a login would fail for a reason unrelated to the paywall.
        foreach ([
            [self::$activePlannerId, self::ACTIVE_PLANNER_PASS],
            [self::$lockedPlannerId, self::LOCKED_PLANNER_PASS],
            [self::$superAdminId,    self::SUPER_ADMIN_PASS],
            [self::$vendorId,        self::VENDOR_PASS],
        ] as [$id, $pass]) {
            if ($id === null || !self::passwordMatches('tblusers', 'password', $id, $pass)) {
                self::$base = null;
                return;
            }
        }
        if (self::$coupleId === null || !self::passwordMatches('tblaccounts', 'user_password', self::$coupleId, self::COUPLE_PASS)) {
            self::$base = null;
            return;
        }

        // Prove a login actually works before asserting on the redirects.
        $probe = self::loginFresh(self::ACTIVE_PLANNER_EMAIL, self::ACTIVE_PLANNER_PASS);
        if ($probe['status'] !== 302 || stripos($probe['location'], 'admin/') === false) {
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

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve a fixture id. The id column differs per table (tblusers.id vs
     * tblaccounts.user_id) and asking for the wrong one THROWS — which the
     * caller's catch would turn into a silent skip, i.e. a green suite that
     * tests nothing. So the caller names the column explicitly.
     */
    private static function idByEmail(string $table, string $idColumn, string $emailColumn, string $email): ?int
    {
        $id = self::$db->fetchValue("SELECT {$idColumn} FROM {$table} WHERE {$emailColumn} = :e LIMIT 1", [':e' => $email]);
        return ($id === false || $id === null) ? null : (int) $id;
    }

    private static function passwordMatches(string $table, string $column, int $id, string $plain): bool
    {
        $idCol = $table === 'tblusers' ? 'id' : 'user_id';
        $hash = (string) self::$db->fetchValue("SELECT {$column} FROM {$table} WHERE {$idCol} = :i", [':i' => $id]);
        return $hash !== '' && password_verify($plain, $hash);
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_pw_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_pw_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 41000 + random_int(0, 8999);
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
     * Minimal curl-free HTTP client; redirects are NOT followed.
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

    /**
     * Sign in on a FRESH session — login.php bounces anyone already signed in,
     * so every leg has to start from an empty jar.
     *
     * @return array{status:int, location:string, body:string}
     */
    private static function loginFresh(string $email, string $pass): array
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::fail('could not load /login.php for a fresh sign-in');
        }
        return self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / v2.1 seed fixtures unavailable — paywall tests skipped');
        }
    }

    /** Is this response the paywall redirect (as opposed to any other bounce)? */
    private static function isPaywallRedirect(array $res): bool
    {
        return $res['status'] === 302 && stripos($res['location'], 'subscription.php') !== false;
    }

    private static function realBookingQuery(): string
    {
        return '?booking_id=' . self::COUPLE_BOOKING . '&user_id=' . self::COUPLE_BOOKING . '&id=1';
    }

    // ------------------------------------------------------------- providers

    /** @return array<string, array{0: string}> */
    public static function staffStandalonePages(): array
    {
        $out = [];
        foreach (self::STAFF_STANDALONE as $p) {
            $out[$p] = [$p];
        }
        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function allGatedBarePages(): array
    {
        $out = [];
        foreach (array_merge(self::STAFF_STANDALONE, self::SHARED_STANDALONE, self::BOOKING_SCOPED, self::GATED_WITHOUT_BARE_200) as $p) {
            $out[$p] = [$p];
        }
        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function bookingScopedPages(): array
    {
        $out = [];
        foreach (self::BOOKING_SCOPED as $p) {
            $out[$p] = [$p];
        }
        return $out;
    }

    // ---------------------------------------------------------------- fixture

    /**
     * The whole point of this class is that these five session shapes exist.
     * If a seed edit turns the locked planner into a paid one (or the operator
     * into one with a live plan), every assertion below would pass for the
     * wrong reason — so fail loudly here instead.
     */
    #[Group('integration')]
    public function testSeedFixtureStillHasTheShapesThisGateIsTestedAgainst(): void
    {
        $this->requireHttp();

        self::assertSame('active', Subscription::state(self::$activePlannerId)['state'], 'amina must have a live plan (positive control)');
        self::assertSame('locked', Subscription::state(self::$lockedPlannerId)['state'], 'otieno must have NO plan (the locked subject)');
        self::assertSame('locked', Subscription::state(self::$superAdminId)['state'], 'abdi must have NO plan, so only is_super_admin() can save him');

        $levels = [
            'active planner' => [self::$activePlannerId, 1],
            'locked planner' => [self::$lockedPlannerId, 1],
            'super admin'    => [self::$superAdminId, 2],
            'vendor'         => [self::$vendorId, 0],
        ];
        foreach ($levels as $label => [$id, $expected]) {
            self::assertSame(
                $expected,
                (int) self::$db->fetchValue('SELECT access_level FROM tblusers WHERE id = :i', [':i' => $id]),
                $label . ' must keep access_level ' . $expected
            );
        }

        // The vendor's OWN staff-row subscription is expired — the exact shape
        // that must not gate him.
        self::assertNotSame(
            'active',
            Subscription::state(self::$vendorId)['state'],
            'the vendor fixture must carry a lapsed subscription, or the "vendors are never gated" leg proves nothing'
        );

        // The couple used for the deep-link legs must own the booking.
        self::assertSame(
            self::$coupleId,
            (int) self::$db->fetchValue('SELECT user_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::COUPLE_BOOKING]),
            'booking ' . self::COUPLE_BOOKING . ' must belong to the couple fixture, or the deep-link legs are meaningless'
        );
    }

    // ------------------------------------------------------------- the paywall

    #[Group('integration')]
    #[DataProvider('staffStandalonePages')]
    public function testLockedPlannerIsDivertedFromEveryStaffPage(string $page): void
    {
        $this->requireHttp();
        $login = self::loginFresh(self::LOCKED_PLANNER_EMAIL, self::LOCKED_PLANNER_PASS);
        self::assertSame(302, $login['status']);
        self::assertStringContainsString('subscription.php', $login['location'], 'a lapsed planner lands on the pay screen, not the dashboard');

        $res = self::http('GET', '/admin/' . $page);
        self::assertTrue(self::isPaywallRedirect($res), $page . ' must divert a lapsed planner to subscription.php (got ' . $res['status'] . ' ' . $res['location'] . ')');
        self::assertStringNotContainsString('Fatal error', $res['body']);
    }

    #[Group('integration')]
    #[DataProvider('allGatedBarePages')]
    public function testLockedPlannerIsDivertedFromEverySharedPageToo(string $page): void
    {
        $this->requireHttp();
        self::loginFresh(self::LOCKED_PLANNER_EMAIL, self::LOCKED_PLANNER_PASS);

        // Bare first — the gate must sit ABOVE each page's own parameter
        // checks, or the bounce would depend on which guard runs first.
        $bare = self::http('GET', '/admin/' . $page);
        self::assertTrue(self::isPaywallRedirect($bare), $page . ' (bare URL) must be gated for a lapsed planner');
    }

    /**
     * The deep-link leak, in full: a lapsed planner aiming a real booking id at
     * a page a couple shares must still be turned away — and the redirect must
     * not carry the couple's data in its body.
     */
    #[Group('integration')]
    #[DataProvider('bookingScopedPages')]
    public function testLockedPlannerCannotDeepLinkIntoACouplesBooking(string $page): void
    {
        $this->requireHttp();
        self::loginFresh(self::LOCKED_PLANNER_EMAIL, self::LOCKED_PLANNER_PASS);

        $deep = self::http('GET', '/admin/' . $page . self::realBookingQuery());
        self::assertTrue(self::isPaywallRedirect($deep), $page . ' with a REAL booking id must be gated for a lapsed planner');
        self::assertStringNotContainsString('Brian', $deep['body'], $page . ' must not leak the couple\'s data in the redirect body');
        self::assertStringNotContainsString('Wafula', $deep['body'], $page . ' must not leak the couple\'s data in the redirect body');
    }

    #[Group('integration')]
    public function testLockedPlannerKeepsThePaymentScreen(): void
    {
        $this->requireHttp();
        self::loginFresh(self::LOCKED_PLANNER_EMAIL, self::LOCKED_PLANNER_PASS);

        foreach (['subscription.php', 'stk_status.php'] as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(200, $res['status'], $page . ' is where a lapsed planner pays — it must never bounce him');
            foreach (['Fatal error:', 'Parse error:', 'Warning:'] as $marker) {
                self::assertStringNotContainsString($marker, $res['body'], $page . ' must render cleanly (' . $marker . ')');
            }
        }
    }

    #[Group('integration')]
    public function testSuperAdminIsNeverLockedOutByHisOwnLapsedPlan(): void
    {
        $this->requireHttp();
        $this->assertRoleReachesEverything(self::SUPER_ADMIN_EMAIL, self::SUPER_ADMIN_PASS, true);
    }

    #[Group('integration')]
    public function testActivePlannerIsNotGated(): void
    {
        $this->requireHttp();
        $this->assertRoleReachesEverything(self::ACTIVE_PLANNER_EMAIL, self::ACTIVE_PLANNER_PASS, false);
    }

    /**
     * Shared by the two "allowed" legs: everything the gate protects must be
     * reachable, standalone pages as 200 and booking-scoped ones as 200 once a
     * real booking is supplied.
     */
    private function assertRoleReachesEverything(string $email, string $pass, bool $isOperator): void
    {
        $label = $isOperator ? 'the operator' : 'a paying planner';
        $login = self::loginFresh($email, $pass);
        self::assertSame(302, $login['status']);
        self::assertStringContainsString('admin/dashboard.php', $login['location'], $label . ' must land on the dashboard');
        self::assertFalse(self::isPaywallRedirect($login), $label . ' must never be greeted by the paywall');

        foreach (self::STAFF_STANDALONE as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(200, $res['status'], $label . ' must reach ' . $page . ($isOperator ? ' even with no plan of his own' : ''));
            self::assertFalse(self::isPaywallRedirect($res));
        }
        foreach (self::SHARED_STANDALONE as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(200, $res['status'], $label . ' must reach ' . $page);
        }
        foreach (self::BOOKING_SCOPED as $page) {
            $res = self::http('GET', '/admin/' . $page . self::realBookingQuery());
            self::assertSame(200, $res['status'], $label . ' must reach ' . $page . ' with a real booking');
            self::assertFalse(self::isPaywallRedirect($res));
        }
        foreach (self::GATED_WITHOUT_BARE_200 as $page) {
            // No receipt id exists for the seed, so this renders its own 404 —
            // what matters is that the GATE let it through.
            $res = self::http('GET', '/admin/' . $page);
            self::assertFalse(self::isPaywallRedirect($res), $page . ' must never paywall ' . $label);
        }
    }

    #[Group('integration')]
    public function testCoupleIsNeverShownThePaywall(): void
    {
        $this->requireHttp();

        $login = self::loginFresh(self::COUPLE_EMAIL, self::COUPLE_PASS);
        self::assertStringContainsString('admin/client.php', $login['location'], 'a couple still lands on their own dashboard');

        foreach (self::COUPLE_STANDALONE as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(200, $res['status'], 'a couple must keep ' . $page);
            self::assertFalse(self::isPaywallRedirect($res), 'couples are never billed — ' . $page . ' must not show the paywall');
        }
        foreach (self::COUPLE_BOOKING_PAGES as $page) {
            $res = self::http('GET', '/admin/' . $page . '?booking_id=' . self::$coupleId . '&user_id=' . self::$coupleId);
            self::assertSame(200, $res['status'], 'a couple must keep ' . $page . ' for their own wedding');
            self::assertFalse(self::isPaywallRedirect($res), 'couples are never billed — ' . $page . ' must not show the paywall');
        }

        // Staff-only pages still bounce a couple, but to the public site: a
        // subscription redirect here would mean the couple was treated as staff.
        foreach (['dashboard.php', 'users.php', 'client_edit.php'] as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(302, $res['status'], $page . ' must still bounce a couple');
            self::assertFalse(self::isPaywallRedirect($res), $page . ' must bounce a couple to the site, not to the pay screen');
        }
    }

    #[Group('integration')]
    public function testVendorIsNeverGatedDespiteALapsedStaffSubscription(): void
    {
        $this->requireHttp();

        $login = self::loginFresh(self::VENDOR_EMAIL, self::VENDOR_PASS);
        self::assertSame(302, $login['status']);
        self::assertStringContainsString('vendor_', $login['location'], 'a vendor is routed to their vendor home');
        self::assertFalse(self::isPaywallRedirect($login), 'vendors are paid by commission — never sent to the pay screen');

        foreach (['vendor_home.php', 'vendor_profile.php', 'vendor_packages.php', 'vendors.php', 'notifications.php'] as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(200, $res['status'], 'a vendor must keep ' . $page);
            self::assertFalse(self::isPaywallRedirect($res), $page . ' must not paywall a vendor');
        }

        // The vendor-scope fixes: client.php hands vendors their own home, and
        // the planner back office stays shut.
        $client = self::http('GET', '/admin/client.php');
        self::assertSame(302, $client['status']);
        self::assertStringContainsString('vendor_home.php', $client['location'], 'a vendor opening client.php is sent to their own vendor home');

        foreach (['dashboard.php', 'users.php', 'revenue.php'] as $page) {
            $res = self::http('GET', '/admin/' . $page);
            self::assertSame(302, $res['status'], $page . ' must stay shut to a vendor');
            self::assertFalse(self::isPaywallRedirect($res), 'a vendor is not a staff member — the paywall is not the right bounce');
        }
    }

    // ------------------------------------------------- the predicate itself

    /**
     * The gate is one boolean expression. Pin it directly so the *semantics*
     * are guarded even if a page's guard is deleted by a refactor: it must be
     * exactly "staff AND not the operator AND hard-locked".
     */
    #[Group('integration')]
    public function testPaywallPredicateSemantics(): void
    {
        $this->requireHttp();
        $saved = $_SESSION ?? [];
        try {
            $cases = [
                // [session, expected, why]
                [['id' => self::$lockedPlannerId, 'privilege_level' => 1], true, 'locked planner is the only locked-out shape'],
                [['id' => self::$lockedPlannerId, 'privilege_level' => 2], false, 'operator exemption applies to the SAME user id'],
                [['id' => self::$superAdminId, 'privilege_level' => 2], false, 'the seeded operator (no plan) is never locked out'],
                [['id' => self::$activePlannerId, 'privilege_level' => 1], false, 'a paying planner is never locked out'],
                [['id' => self::$vendorId, 'privilege_level' => 0, 'account_type' => 'staff'], false, 'a vendor has a lapsed staff subscription but is not staff'],
                [['id' => self::$coupleId, 'privilege_level' => 0, 'account_type' => 'client'], false, 'a couple is not staff'],
                [[], false, 'no session is not staff'],
            ];
            foreach ($cases as [$session, $expected, $why]) {
                $_SESSION = $session;
                self::assertSame($expected, staff_paywall_locked(), $why);
            }
        } finally {
            $_SESSION = $saved;
        }
    }

    /**
     * The helpers a page gate calls must resolve with NOTHING but composer's
     * autoloader — no init.php. init.php is loaded only for web requests, while
     * Helper.php is in composer's files autoload, so a predicate defined in
     * init.php works in a browser and then fatals with "Call to undefined
     * function" for the first cron script or test that touches it. That is the
     * exact bug that reached production once (is_staff) and again (is_super_admin).
     *
     * This runs OUT OF PROCESS on purpose: five test classes do include
     * init.php, so an in-process check would be satisfied by whichever of them
     * ran first.
     */
    #[Group('integration')]
    public function testGateHelpersNeedNoWebBootstrap(): void
    {
        $this->requireHttp();
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable');
        }
        $root = realpath(__DIR__ . '/..');
        $file = tempnam(sys_get_temp_dir(), 'wpms_gate') . '.php';
        $snippet = "<?php\n"
            . "require '" . $root . "/vendor/autoload.php';\n"
            . "require '" . $root . "/admin/include/config.php';\n"
            . "if (!class_exists('Database', false)) { require '" . $root . "/admin/include/Database.php'; }\n"
            . "if (!class_exists('DB_Object', false)) { require '" . $root . "/admin/include/db_object.php'; }\n"
            . "global \$db; \$db = new Database();\n"
            . "\$out = [];\n"
            . "\$_SESSION = ['id' => " . (int) self::$lockedPlannerId . ", 'privilege_level' => 1];\n"
            . "\$out['locked_planner'] = staff_paywall_locked();\n"
            . "\$_SESSION = ['id' => " . (int) self::$superAdminId . ", 'privilege_level' => 2];\n"
            . "\$out['super_admin'] = staff_paywall_locked();\n"
            . "\$_SESSION = ['id' => " . (int) self::$activePlannerId . ", 'privilege_level' => 1];\n"
            . "\$out['active_planner'] = staff_paywall_locked();\n"
            . "echo json_encode(\$out);\n";
        file_put_contents($file, $snippet);

        $proc = proc_open([PHP_BINARY, $file], [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        self::assertIsResource($proc, 'could not run the out-of-process bootstrap probe');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($file);

        self::assertStringNotContainsString('undefined function', strtolower((string) $stderr), 'a gate helper reached for a function that only init.php defines: ' . $stderr);
        self::assertStringNotContainsString('fatal error', strtolower((string) $stdout . $stderr), 'the gate helper fataled with only composer\'s autoloader loaded');
        self::assertSame(0, $exit, 'the bootstrap probe exited non-zero: ' . $stderr);

        $out = json_decode(trim((string) $stdout), true);
        self::assertIsArray($out, 'probe produced no JSON: ' . $stdout . ' / ' . $stderr);
        self::assertTrue($out['locked_planner'], 'a locked planner must read as locked without init.php');
        self::assertFalse($out['super_admin'], 'the operator must be exempt without init.php');
        self::assertFalse($out['active_planner'], 'a paying planner must not be locked without init.php');
    }
}
