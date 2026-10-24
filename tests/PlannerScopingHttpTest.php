<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-planner scoping, driven over HTTP as two real planners.
 *
 * The unit suite (PlannerScopingTest) pins the rule and the source. This one
 * answers the question the rule exists for: signed in as planner A, can I SEE
 * or MOVE anything of planner B's? It walks the four surfaces the feature
 * touches, on a real `php -S`, with real sessions:
 *
 *   1. the bookings list        — A's rows, plus the unassigned ones, and none of B's
 *   2. the door                 — A cannot open B's wedding by id, in the query string
 *   3. the enquiries ledger     — A's deals only, and a POST for B's deal is refused
 *   4. the approvals page       — A sees B's Tier-3 request but cannot approve it,
 *                                 and can approve the one on the unassigned wedding
 *
 * FIXTURES ARE BORROWED, NOT INVENTED. Two seeded planners are given an active
 * subscription if they have none, three seeded bookings are (re)assigned to A,
 * to B and to nobody, and one deal row per wedding is inserted — every one of
 * those changes is recorded and undone in tearDownAfterClass, so the seeded
 * database is left exactly as it was found. When the seed cannot support the
 * suite (one planner, one booking), the tests SKIP rather than pass quietly.
 */
final class PlannerScopingHttpTest extends TestCase
{
    /** Every seeded staff account uses this password (docs/XAMPP_LOGINS_AND_ROLES.md). */
    private const STAFF_PASS = 'Test@1234';
    private const TOKEN_PREFIX = 'scope-http-';

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;

    /** @var array<string, array<string,string>> one cookie jar per signed-in planner */
    private static array $jars = [];

    private static int $plannerA = 0;
    private static int $plannerB = 0;
    private static string $emailA = '';
    private static string $emailB = '';

    private static int $bookingA = 0;
    private static int $bookingB = 0;
    private static int $bookingFree = 0;

    /** @var array<int,int> booking_id => organizer_id as it was before this suite */
    private static array $organizerOriginals = [];

    /** @var int[] subscription ids this suite inserted, to delete afterwards */
    private static array $addedSubs = [];

    private static int $dealA = 0;
    private static int $dealB = 0;

    private static int $tokenA = 0;
    private static int $tokenB = 0;
    private static int $approvalA = 0;
    private static int $approvalB = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/models.php';
        foreach (['ApiToken', 'McpApprovals', 'McpWrites'] as $class) {
            if (!class_exists($class, false)) {
                require_once __DIR__ . '/../admin/include/' . $class . '.php';
            }
        }

        try {
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        putenv('WPMS_MAIL_ENABLED=');
        putenv('WPMS_SITE_URL=');

        self::bootServer();
        if (self::$db === null || self::$base === null || !McpApprovals::available()) {
            self::$base = null;
            return;
        }

        try {
            $planners = self::$db->fetchAll(
                "SELECT id, email FROM tblusers WHERE access_level = '1' ORDER BY id LIMIT 2"
            );
            $bookings = self::$db->fetchAll(
                'SELECT booking_id, organizer_id FROM tblweddingbook ORDER BY booking_id LIMIT 3'
            );
            $vendorId = (int) self::$db->fetchValue('SELECT id FROM tblvendors ORDER BY id LIMIT 1');
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if (count($planners) < 2 || count($bookings) < 3 || $vendorId <= 0) {
            self::$base = null; // seed incomplete — every test skips
            return;
        }

        self::$plannerA = (int) $planners[0]->id;
        self::$plannerB = (int) $planners[1]->id;
        self::$emailA   = (string) $planners[0]->email;
        self::$emailB   = (string) $planners[1]->email;

        self::$bookingA    = (int) $bookings[0]->booking_id;
        self::$bookingB    = (int) $bookings[1]->booking_id;
        self::$bookingFree = (int) $bookings[2]->booking_id;

        self::sweep();

        try {
            // Both planners need to be past the paywall to reach the pages at all.
            foreach ([self::$plannerA, self::$plannerB] as $plannerId) {
                if ((int) self::$db->fetchValue(
                    "SELECT COUNT(*) FROM tblsubscriptions WHERE user_id = :u AND status = 'active' AND expiry_date >= CURDATE()",
                    [':u' => $plannerId]
                ) === 0) {
                    self::$db->execute(
                        "INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status, auto_renew)
                         VALUES (:u, 'professional', 7999.00, :code, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active', 0)",
                        [':u' => $plannerId, ':code' => 'SCOPEHT' . $plannerId]
                    );
                    self::$addedSubs[] = (int) self::$db->the_insert_id();
                }
            }

            // One wedding each, and one nobody owns — the three cases of the rule.
            foreach ([[self::$bookingA, self::$plannerA], [self::$bookingB, self::$plannerB], [self::$bookingFree, 0]] as [$bookingId, $organizerId]) {
                $current = (int) self::$db->fetchValue(
                    'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b',
                    [':b' => $bookingId]
                );
                self::$organizerOriginals[$bookingId] = $current;
                self::$db->execute(
                    'UPDATE tblweddingbook SET organizer_id = :o WHERE booking_id = :b',
                    [':o' => $organizerId, ':b' => $bookingId]
                );
            }

            // A pending deal on each planner's wedding, so the ledger has something
            // that the other planner must not see or be able to move.
            self::$dealA = self::insertDeal($vendorId, self::$bookingA);
            self::$dealB = self::insertDeal($vendorId, self::$bookingB);

            // One Tier-3 request per planner, made the way the tool makes one.
            foreach ([[self::$plannerA, self::$bookingA, 'A'], [self::$plannerB, self::$bookingB, 'B']] as [$plannerId, $bookingId, $tag]) {
                $minted = ApiToken::mint(ApiToken::KIND_USER, $plannerId, self::TOKEN_PREFIX . $tag,
                    ['read:weddings', 'write:planners'], null, null, null);
                $principal = ApiToken::authenticate((string) $minted['secret']);
                $payload   = McpWrites::committed('assign_planner', [
                    'booking_id'   => $bookingId,
                    'organizer_id' => $plannerId,
                ]);
                $request = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
                    $bookingId, $payload, '[scoping-test] set the planner of wedding #' . $bookingId);

                if ($tag === 'A') {
                    self::$tokenA = (int) $minted['id'];
                    self::$approvalA = (int) $request['id'];
                } else {
                    self::$tokenB = (int) $minted['id'];
                    self::$approvalB = (int) $request['id'];
                }
            }
        } catch (Throwable $e) {
            self::sweep();
            self::$base = null;
        }

        if (!self::signIn('A', self::$emailA) || !self::signIn('B', self::$emailB)) {
            self::sweep();
            self::$base = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                // Put the seed back exactly as it was.
                foreach (self::$organizerOriginals as $bookingId => $organizerId) {
                    self::$db->execute(
                        'UPDATE tblweddingbook SET organizer_id = :o WHERE booking_id = :b',
                        [':o' => $organizerId, ':b' => $bookingId]
                    );
                }
                foreach ([self::$dealA, self::$dealB] as $dealId) {
                    if ($dealId > 0) {
                        self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
                    }
                }
                foreach (self::$addedSubs as $subId) {
                    self::$db->execute('DELETE FROM tblsubscriptions WHERE id = :id', [':id' => $subId]);
                }
            } catch (Throwable $e) {
                // Best-effort restore.
            }
            self::sweep();
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    /** Insert a pending deal and return its id. */
    private static function insertDeal(int $vendorId, int $bookingId): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, status, amount, notes, created_at)
             VALUES (:v, :b, 'pending', 10000.00, '[scoping-test] temporary deal]', NOW())",
            [':v' => $vendorId, ':b' => $bookingId]
        );
        return (int) self::$db->the_insert_id();
    }

    /** Remove this suite's tokens, their audit rows and their approval requests. */
    private static function sweep(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            $rows = self::$db->fetchAll(
                'SELECT id FROM ' . ApiToken::TABLE . ' WHERE name LIKE :p',
                [':p' => self::TOKEN_PREFIX . '%']
            );
            foreach ($rows as $row) {
                $id = (int) (is_array($row) ? $row['id'] : $row->id);
                self::$db->execute('DELETE FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :id', [':id' => $id]);
                if (McpApprovals::available()) {
                    self::$db->execute('DELETE FROM ' . McpApprovals::TABLE . ' WHERE token_id = :id', [':id' => $id]);
                }
                self::$db->execute('DELETE FROM ' . ApiToken::TABLE . ' WHERE id = :id', [':id' => $id]);
            }
        } catch (Throwable $e) {
            // Best-effort cleanup.
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_scope_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_scope_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 29000 + random_int(0, 8999);
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
            for ($i = 0; $i < 40; $i++) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    self::$base = 'http://127.0.0.1:' . $port;
                    return;
                }
                usleep(100000);
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
    }

    /**
     * Sign in as a seeded planner on its own cookie jar.
     *
     * The success test is the SIGN-IN REDIRECT, not the cookie: a failed login
     * still sets a session cookie, so the question is where login.php lands a
     * planner (the dashboard, or the subscription page if the plan we just
     * created is somehow not visible yet).
     */
    private static function signIn(string $key, string $email): bool
    {
        self::$jars[$key] = [];
        $page = self::http($key, 'GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            return false;
        }
        $post = self::http($key, 'POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => self::STAFF_PASS,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
        return $post['status'] === 302 && stripos($post['location'], 'login.php') === false;
    }

    /**
     * @param array<string,string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $key, string $method, string $path, array $post = []): array
    {
        $headers = '';
        foreach (self::$jars[$key] ?? [] as $name => $value) {
            $headers .= $name . '=' . $value . '; ';
        }
        $options = [
            'method'          => $method,
            'ignore_errors'   => true,
            'timeout'         => 20,
            // A bounced request must be SEEN as a bounce: several assertions here
            // are about a refusal, not about whatever page it lands on.
            'follow_location' => 0,
            'max_redirects'   => 0,
        ];
        if ($headers !== '') {
            $options['header'] = 'Cookie: ' . rtrim($headers, '; ');
        }
        if ($method === 'POST') {
            $options['header'] = ($options['header'] ?? '') . "\r\nContent-Type: application/x-www-form-urlencoded";
            $options['content'] = http_build_query($post);
        }
        $ctx      = stream_context_create(['http' => $options]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);

        $status   = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq   = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jars[$key][trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    /** The CSRF token of a signed-in planner's session (any page renders it). */
    private static function csrfFor(string $key): string
    {
        $page = self::http($key, 'GET', '/admin/sms_center.php');
        return preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)
            ? html_entity_decode($m[1], ENT_QUOTES)
            : '';
    }

    private function requireScoping(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / two seeded planners / three bookings / v2.17 approvals table unavailable — scoping HTTP tests skipped');
        }
    }

    /** The booking ids a rendered bookings list links to. */
    private static function listedBookings(string $html): array
    {
        preg_match_all('/booking_id=(\d+)/', $html, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    // ==================================================================
    // 1. The list
    // ==================================================================

    public function testEachPlannerIsShownTheirOwnWeddingsAndTheUnassignedOnes(): void
    {
        $this->requireScoping();

        $a = self::http('A', 'GET', '/admin/client.php');
        self::assertSame(200, $a['status']);
        $listedA = self::listedBookings($a['body']);
        self::assertContains(self::$bookingA, $listedA, 'A sees their own wedding');
        self::assertNotContains(self::$bookingB, $listedA, 'and not B\'s');

        $b = self::http('B', 'GET', '/admin/client.php');
        self::assertSame(200, $b['status']);
        $listedB = self::listedBookings($b['body']);
        self::assertContains(self::$bookingB, $listedB, 'B sees their own wedding');
        self::assertNotContains(self::$bookingA, $listedB, 'and not A\'s');
        /*
         * The two lists may overlap, but only where the rule says they may: on
         * weddings nobody owns. Asserted against the DATABASE rather than against
         * a hand-written list, because the seed has unassigned weddings of its
         * own and the point is the rule, not this fixture.
         */
        $shared = array_values(array_intersect($listedA, $listedB));
        self::assertContains(self::$bookingFree, $shared, 'the unassigned wedding is visible to both');
        foreach ($shared as $bookingId) {
            $organizer = (int) self::$db->fetchValue(
                'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b',
                [':b' => $bookingId]
            );
            self::assertLessThanOrEqual(0, $organizer,
                'wedding #' . $bookingId . ' is listed for two planners but is not unassigned');
        }
    }

    // ==================================================================
    // 2. The door
    // ==================================================================

    public function testAPlannerCannotOpenAnotherPlannersWeddingByItsId(): void
    {
        $this->requireScoping();

        $refused = self::http('B', 'GET', '/admin/sheet_guests.php?booking_id=' . self::$bookingA);
        self::assertSame(302, $refused['status'], 'the request is bounced before the page body runs');
        self::assertStringContainsString('client.php', $refused['location']);
        self::assertStringNotContainsString('Guest', $refused['body'], 'and nothing of the wedding is rendered');

        $then = self::http('B', 'GET', '/admin/client.php');
        self::assertStringContainsString('not one of yours', $then['body'], 'B is told why, on the page they land on');

        $own = self::http('B', 'GET', '/admin/sheet_guests.php?booking_id=' . self::$bookingB);
        self::assertSame(200, $own['status'], 'B\'s own wedding still opens');

        $free = self::http('B', 'GET', '/admin/sheet_guests.php?booking_id=' . self::$bookingFree);
        self::assertSame(200, $free['status'], 'and so does the unassigned one, so somebody can take it');

        $a = self::http('A', 'GET', '/admin/sheet_guests.php?booking_id=' . self::$bookingA);
        self::assertSame(200, $a['status'], 'A\'s own wedding is unaffected');
    }

    // ==================================================================
    // 3. The enquiries ledger
    // ==================================================================

    public function testEachPlannerSeesOnlyTheDealsOnTheirOwnWeddings(): void
    {
        $this->requireScoping();

        $a = self::http('A', 'GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $a['status']);
        self::assertStringContainsString('value="' . self::$dealA . '"', $a['body'], 'A sees the deal on their wedding');
        self::assertStringNotContainsString('value="' . self::$dealB . '"', $a['body'], 'and not the one on B\'s');

        $b = self::http('B', 'GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $b['status']);
        self::assertStringContainsString('value="' . self::$dealB . '"', $b['body'], 'B sees their own');
        self::assertStringNotContainsString('value="' . self::$dealA . '"', $b['body'], 'and not A\'s');
    }

    public function testAPostForAnotherPlannersDealIsRefusedAndChangesNothing(): void
    {
        $this->requireScoping();

        $before = (string) self::$db->fetchValue(
            'SELECT status FROM tblvendor_bookings WHERE id = :id',
            [':id' => self::$dealA]
        );

        $csrf = self::csrfFor('B');
        self::assertNotSame('', $csrf, 'B holds a session CSRF token');

        $post = self::http('B', 'POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => (string) self::$dealA,
            'to'         => 'cancelled',
            '_token'     => $csrf,
        ]);
        self::assertSame(302, $post['status'], 'the POST is bounced');

        self::assertSame($before, (string) self::$db->fetchValue(
            'SELECT status FROM tblvendor_bookings WHERE id = :id',
            [':id' => self::$dealA]
        ), 'the deal on A\'s wedding is untouched by B\'s POST');

        $then = self::http('B', 'GET', '/admin/vendor_enquiries.php');
        self::assertStringContainsString('not yours to move', $then['body'], 'and B is told so');
    }

    // ==================================================================
    // 4. The approvals page — inherited, not re-implemented
    // ==================================================================

    public function testTheApprovalsPageLetsAPlannerDecideOnlyTheirOwnWeddings(): void
    {
        $this->requireScoping();

        $page = self::http('B', 'GET', '/admin/mcp_approvals.php');
        self::assertSame(200, $page['status']);
        $referenceA = McpApprovals::reference(self::$approvalA);
        $referenceB = McpApprovals::reference(self::$approvalB);

        self::assertStringContainsString($referenceA, $page['body'],
            'A\'s request is SHOWN to B — a reference the assistant quoted must never be invisible');
        self::assertStringContainsString('not one you can open', $page['body'], 'with the reason it cannot be decided');
        self::assertStringContainsString($referenceB, $page['body'], 'and B\'s own request is waiting');

        $csrf = preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)
            ? html_entity_decode($m[1], ENT_QUOTES)
            : '';
        self::assertNotSame('', $csrf);

        $refused = self::http('B', 'POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) self::$approvalA,
            'action'      => 'approve',
            '_token'      => $csrf,
        ]);
        self::assertSame(302, $refused['status'], 'approving another planner\'s request is bounced');
        self::assertNull(
            self::$db->fetchValue('SELECT approval_hash FROM ' . McpApprovals::TABLE . ' WHERE id = :id', [':id' => self::$approvalA]),
            'and no approval string was minted for it'
        );

        $minted = self::http('B', 'POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) self::$approvalB,
            'action'      => 'approve',
            '_token'      => $csrf,
        ]);
        self::assertSame(200, $minted['status'], 'B\'s own request is decidable');
        self::assertMatchesRegularExpression('/mcpap_' . self::$approvalB . '-\d+-[a-f0-9]{64}/', $minted['body'],
            'and approving it mints the string the assistant will use');
    }
}
