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
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] enquiry rows created by this run */
    private static array $probeEnquiryIds = [];
    /** @var int[] notification ids created by this run */
    private static array $probeNotificationIds = [];
    /** @var int[] tblvendor_services rows this run created for matching */
    private static array $probeServiceIds = [];
    /** The seeded catering vendor the fit tests sell a service for. */
    private static int $fitVendorId = 0;

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
            if (!class_exists('Vendor', false)) {
                require_once __DIR__ . '/../admin/include/Monetization.php';
            }
            if (!function_exists('format_date_kd')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
            self::$fitVendorId = (int) $db->fetchValue(
                "SELECT id FROM tblvendors WHERE business_name LIKE 'Nyama Choma Masters%' LIMIT 1"
            );
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
                if (self::$probeServiceIds !== []) {
                    self::$db->execute(
                        'DELETE FROM tblvendor_services WHERE id IN (' . implode(',', self::$probeServiceIds) . ')'
                    );
                }
                // The brief is the one thing this class writes on the seeded
                // wedding; it starts empty and must end empty.
                self::$db->execute(
                    'DELETE FROM tblvendor_preferences WHERE booking_id = :b',
                    [':b' => self::BOOKING_ID]
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeEnquiryIds = [];
        self::$probeNotificationIds = [];
        self::$probeServiceIds = [];

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

        // Category filter narrows the GRID (browse cards). The "My enquiries"
        // summary panel above it intentionally always shows every enquiry the
        // couple sent — so assert on the grid's Enquire control for the
        // filtered-out vendor, not the name anywhere in the body.
        $catering = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200&category=catering');
        self::assertSame(200, $catering['status']);
        self::assertStringContainsString('Nyama Choma Masters', $catering['body']);
        self::assertStringNotContainsString('showEnquiry(201', $catering['body'], 'other categories must be filtered out of the browse grid');
        self::assertStringContainsString('My enquiries', $catering['body'], 'the couple\'s enquiry summary stays visible regardless of filters');

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

        // "Nothing was recorded" is a claim about the DELTA, not about the
        // table: tblvendor_bookings is where an enquiry lands AND where every
        // other suite's deals live, so a fixed number here only held while this
        // class happened to run against an untouched seed. The baseline is read
        // once and every rejected request must leave it alone.
        $before = self::enquiryCount();

        // GET is rejected (redirect back, nothing recorded).
        $get = self::http('GET', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $get['status'], 'enquiry must reject GET requests');
        self::assertSame($before, self::enquiryCount(), 'GET must not record anything');

        // Tokenless POST is rejected.
        $tokenless = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'vendor_id' => 200, 'message' => 'tokenless attempt',
        ]);
        self::assertSame(302, $tokenless['status']);
        self::assertSame($before, self::enquiryCount(), 'tokenless POST must not record anything');

        // Unknown/unapproved vendor is refused even with a valid token.
        $token = self::fetchCsrfToken();
        $unknown = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'vendor_id' => 999, 'message' => 'should not land', '_token' => $token,
        ]);
        self::assertSame(302, $unknown['status']);
        self::assertSame($before, self::enquiryCount(), 'unknown vendor must not record anything');

        // Foreign booking is bounced.
        $foreign = self::http('POST', '/admin/client_vendor_enquiry.php?booking_id=201&user_id=201', [
            'vendor_id' => 200, 'message' => 'foreign attempt', '_token' => $token,
        ]);
        self::assertSame(302, $foreign['status']);
        self::assertSame($before, self::enquiryCount(), 'foreign booking must not record anything');
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
    // Taste & budget (v2.28)
    // ------------------------------------------------------------------

    /**
     * The couple's half of the comparison, end to end: the editor is scoped to
     * their wedding, what they save is what is read, and the marketplace turns
     * it into an honest mark that flips when the budget changes.
     */
    #[Group('integration')]
    public function testCoupleSetsTasteAndBudgetAndTheMarketplaceMarksWhatFits(): void
    {
        $this->requireDb();
        self::loginCouple();

        // Another wedding's brief is refused before a single field is shown.
        $foreign = self::http('GET', '/admin/client_vendor_preferences.php?booking_id=201&user_id=201');
        self::assertSame(302, $foreign['status'], 'a couple must bounce off another wedding\'s brief');

        $editorPath = '/admin/client_vendor_preferences.php?booking_id=' . self::BOOKING_ID . '&user_id=200';
        $editor = self::http('GET', $editorPath);
        self::assertSame(200, $editor['status']);
        self::assertStringContainsString('Taste &amp; Budget', $editor['body']);
        self::assertStringContainsString('Catering', $editor['body'], 'every vendor category is offered');

        $token = self::csrfFrom($editorPath);
        $saved = self::http('POST', $editorPath, [
            '_token'      => $token,
            'action'      => 'save',
            'category'    => 'catering',
            'taste_tags'  => 'rustic, halal',
            'budget_min'  => '',
            'budget_max'  => '287654',
            'notes'       => 'no pork',
        ]);
        self::assertSame(302, $saved['status']);

        $pref = VendorFit::preference(self::BOOKING_ID, 'catering');
        self::assertNotNull($pref, 'saving the form must store the brief');
        self::assertEqualsWithDelta(287654.0, (float) $pref->budget_max, 0.01);
        self::assertSame('rustic,halal', (string) $pref->taste_tags);

        $this->seedFitService();

        $page = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertStringContainsString('KSh 287,654', $page['body'], 'their own brief is shown on the marketplace');
        self::assertStringContainsString('Fits your brief', $page['body'],
            'a service inside the cap with their taste tags must be marked as fitting');

        $filtered = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200&fit=1');
        self::assertStringNotContainsString('Nothing fits that brief yet', $filtered['body'],
            'the fitting vendor must survive the filter');

        // A cap below every service price: the mark flips to over budget, and
        // the filter says so instead of pretending something is close.
        $tightened = self::http('POST', $editorPath, [
            '_token'     => $token,
            'action'     => 'save',
            'category'   => 'catering',
            'taste_tags' => '',
            'budget_max' => '1000',
        ]);
        self::assertSame(302, $tightened['status']);

        $over = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertStringContainsString('Over budget', $over['body'], 'a cap below the price must read as over budget');

        $empty = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200&fit=1');
        self::assertStringContainsString('Nothing fits that brief yet', $empty['body']);
        self::assertStringContainsString('Widen the brief', $empty['body'], 'the empty state must say how to move forward');
    }

    /**
     * One total in, ten caps out — the helper that turns the package price into
     * a starting brief.
     *
     * What must hold: previewing writes nothing; applying writes caps that add
     * up to the total; the caps come from the total and the shares the page
     * posts (never from posted amounts); and a category left out keeps whatever
     * cap it already had.
     */
    #[Group('integration')]
    public function testCoupleSplitsOneTotalAcrossTheCategories(): void
    {
        $this->requireDb();
        self::loginCouple();

        self::$db->execute('DELETE FROM tblvendor_preferences WHERE booking_id = :b', [':b' => self::BOOKING_ID]);
        // A category the couple is not splitting keeps its cap.
        $kept = VendorFit::save(self::BOOKING_ID, 'mc', ['budget_max' => '25000', 'taste_tags' => 'bilingual'], 200);
        self::assertTrue($kept['ok'], $kept['error']);

        $path  = '/admin/client_vendor_preferences.php?booking_id=' . self::BOOKING_ID . '&user_id=200';
        $token = self::csrfFrom($path);

        $shares = VendorFit::shares();
        unset($shares['mc']);
        $form = ['_token' => $token, 'total' => '300000'];
        foreach ($shares as $slug => $value) {
            $form['shares[' . $slug . ']'] = (string) $value;
        }

        $editor = self::http('GET', $path);
        self::assertStringContainsString('Split one total across the categories', $editor['body']);

        // The same arithmetic the model promises, asked for here so the test
        // does not hard-code a number that belongs to the shares table.
        $plan = VendorFit::plan_split(300000, $shares);
        self::assertSame('', $plan['error']);
        $catering = 0.0;
        foreach ($plan['rows'] as $row) {
            if ($row['category'] === 'catering') {
                $catering = (float) $row['amount'];
            }
        }
        self::assertGreaterThan(0.0, $catering);

        $preview = self::http('POST', $path, ['action' => 'split_plan'] + $form);
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('This split places', $preview['body']);
        self::assertStringContainsString('KSh 300,000', $preview['body']);
        self::assertStringContainsString(VendorFit::money_text($catering), $preview['body'],
            'the preview must show the cap the arithmetic produces for catering');
        self::assertStringContainsString('kept — left out', $preview['body'], 'a zero share must be shown as left out, not cleared');
        self::assertCount(1, VendorFit::preferences(self::BOOKING_ID), 'previewing must not write a single cap');

        $applied = self::http('POST', $path, ['action' => 'split_apply'] + $form);
        self::assertSame(302, $applied['status']);

        $prefs = VendorFit::preferences(self::BOOKING_ID);
        self::assertCount(10, $prefs, 'nine split categories plus the one left out');
        self::assertEqualsWithDelta($catering, (float) $prefs['catering']->budget_max, 0.01,
            'the cap written is the cap previewed');
        self::assertEqualsWithDelta(25000.0, (float) $prefs['mc']->budget_max, 0.01, 'the left-out cap is untouched');
        self::assertSame('bilingual', (string) $prefs['mc']->taste_tags);
        self::assertSame('', (string) $prefs['catering']->taste_tags, 'a new cap carries no invented taste');

        $allocation = VendorFit::allocation(self::BOOKING_ID);
        self::assertEqualsWithDelta(325000.0, $allocation['allocated'], 0.01, '300,000 split + the 25,000 kept');

        // And the marketplace now measures against those caps.
        $this->seedFitService();
        $market = self::http('GET', '/admin/client_vendors.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertStringContainsString(VendorFit::money_text($catering), $market['body'], 'the split cap is what the card compares with');
        self::assertStringContainsString('Fits your brief', $market['body']);
    }

    /**
     * The planner's side: the same brief and the same marks, but only for a
     * wedding they may open, and the editor reachable to update it with the
     * couple rather than for them.
     */
    #[Group('integration')]
    public function testPlannerSeesTheBriefOnlyForTheWeddingTheyAreShoppingFor(): void
    {
        $this->requireDb();
        self::loginAs(self::PLANNER_EMAIL, self::PLANNER_PASS);

        $saved = VendorFit::save(self::BOOKING_ID, 'catering', [
            'taste_tags' => 'rustic',
            'budget_max' => '287654',
            'notes'      => 'must be halal',
        ], self::PLANNER_ID);
        self::assertTrue($saved['ok'], $saved['error']);
        $this->seedFitService();

        $page = self::http('GET', '/admin/vendors.php?booking_id=' . self::BOOKING_ID);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Shopping for', $page['body'], 'the planner must see whose brief they are shopping against');
        self::assertStringContainsString('KSh 287,654', $page['body']);
        self::assertStringContainsString('Fits your brief', $page['body'], 'the same mark the couple sees');

        $editor = self::http('GET', '/admin/client_vendor_preferences.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $editor['status'], 'the planner may open the couple-scoped editor for their wedding (redirect: ' . ($editor['location'] ?? '') . ')');

        // A booking id that resolves to nothing is not a brief. init.php's
        // planner-scope guard refuses the whole request first (that is the one
        // place every `?booking_id=` page inherits the rule), so the honest
        // expectation is the house refusal, not a page with the banner missing.
        $ghost = self::http('GET', '/admin/vendors.php?booking_id=999999');
        self::assertSame(302, $ghost['status']);
        self::assertStringContainsString('client.php', $ghost['location'] ?? '');
        self::assertSame('', trim($ghost['body']), 'a refused wedding must not leak a brief in the redirect body');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** One active service for the seeded caterer, tagged to match the brief. */
    private function seedFitService(): void
    {
        if (self::$fitVendorId <= 0) {
            return;
        }
        self::$db->execute(
            "INSERT INTO tblvendor_services
                (vendor_id, title, pricing_unit, price, includes, tags, is_active)
             VALUES (:v, :t, 'flat', 45000.00, 'staff, crockery', 'rustic,halal', 1)",
            [':v' => self::$fitVendorId, ':t' => '[fit probe] Classic buffet']
        );
        self::$probeServiceIds[] = (int) self::$db->lastInsertId();
    }

    /** CSRF token from any signed-in page. */
    private static function csrfFrom(string $path): string
    {
        $res = self::http('GET', $path);
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract CSRF token from ' . $path);
    }


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
        self::loginAs(self::COUPLE_EMAIL, self::COUPLE_PASS);
    }

    private static function loginAs(string $email, string $password): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $password,
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
