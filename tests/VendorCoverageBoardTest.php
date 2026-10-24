<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendor coverage board (admin/vendor_coverage.php) against a real php -S server:
 *
 *  1. Access — staff only. An anonymous visitor and a couple are both bounced;
 *     a planner opens their own wedding.
 *  2. The matrix — every calendar function of the wedding is a row, every
 *     vendor category is a column, and a whole-wedding deal (features_id NULL)
 *     appears in every row with the state its status earns: confirmed →
 *     booked, pending → pending, nothing → missing. Each covered cell links to
 *     the deal on the ledger; each required hole links to the marketplace with
 *     this wedding preselected.
 *  3. Requirements (v2.24) — a function that has never been edited starts from
 *     all ten categories, so an existing wedding does not lose its matrix. Saving
 *     a list narrows the board: only those cells are booked/pending/missing, the
 *     counts follow them, and a deal in a category the planner unticked stays
 *     visible but muted instead of being counted as cover. An empty list is a
 *     stored decision, not "unconfigured".
 *  4. Refusals — a bad CSRF token writes nothing, and a posted row that is not
 *     this wedding's function is ignored. A wedding with no calendar functions
 *     is judged as one "Whole wedding" row stored under event_id 0.
 *
 * Integration ('integration') — needs the live test DB and a bootable php -S.
 * The class writes only its own probe events and the requirement rows it saves,
 * and removes both afterwards.
 */
final class VendorCoverageBoardTest extends TestCase
{
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';
    private const COUPLE_EMAIL  = 'brian.wafula@gmail.com';
    private const COUPLE_PASS   = 'Test@1234';

    private const BOOKING_WITH_DEALS    = 200; // Linda & Brian, two seed deals
    private const BOOKING_WITHOUT_DEALS = 201; // Faith & James, same planner
    private const BOOKING_NO_FUNCTIONS  = 202; // Akinyi & Otieno, no calendar rows

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;
    /** @var array<string, string> */
    private static array $jar = [];
    /** @var int[] events this class writes on the seeded weddings, removed afterwards */
    private static array $probeEventIds = [];
    /** @var array<string, array{0:int,1:int}> "booking:event" => requirement rows this class wrote */
    private static array $writtenNeeds = [];
    /** @var int[] vendor deals this class wrote to create a same-day clash */
    private static array $probeDealIds = [];

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
            $seeded = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b',
                [':b' => self::BOOKING_WITH_DEALS]
            );
            if ($seeded === 0) {
                self::$db = null; // not the seed this test describes
            } else {
                // v2.24 — without the table the board is read-only by design.
                $db->fetchValue('SELECT COUNT(*) FROM tblevent_vendor_needs');
            }
        } catch (Throwable $e) {
            self::$db = null;
        }

        /*
         * Own the two weddings this class writes about by adding a calendar
         * event to each. A test that claims a seeded id without writing any row
         * keyed by it is exactly the fixture-sweep bug class
         * (tests/FixtureSweepTest.php). The probe rows carry no deals, so they
         * cannot change any coverage state below, and are removed afterwards.
         */
        if (self::$db !== null) {
            try {
                self::$db->execute(
                    'INSERT INTO events (booking_id, title, location, date_created, color, start, end)
                     VALUES (:b, :title, :loc, NOW(), :color, :start, :end)',
                    [':b' => 200, ':title' => '[coverage-probe] Pre-wedding meeting', ':loc' => 'Nairobi',
                     ':color' => '#065F46', ':start' => '2026-11-20 10:00:00', ':end' => '2026-11-20 11:00:00']
                );
                self::$probeEventIds[] = (int) self::$db->the_insert_id();

                self::$db->execute(
                    'INSERT INTO events (booking_id, title, location, date_created, color, start, end)
                     VALUES (:b, :title, :loc, NOW(), :color, :start, :end)',
                    [':b' => 201, ':title' => '[coverage-probe] Pre-wedding meeting', ':loc' => 'Nairobi',
                     ':color' => '#065F46', ':start' => '2026-11-01 10:00:00', ':end' => '2026-11-01 11:00:00']
                );
                self::$probeEventIds[] = (int) self::$db->the_insert_id();
            } catch (Throwable $e) {
                self::$db = null;
                self::$probeEventIds = [];
            }
        }

        self::bootServer();
    }

    protected function tearDown(): void
    {
        self::forgetWrittenNeeds();
    }

    public static function tearDownAfterClass(): void
    {
        self::forgetWrittenNeeds();
        if (self::$db !== null) {
            foreach (self::$probeEventIds as $eventId) {
                try {
                    self::$db->execute('DELETE FROM events WHERE id = :id', [':id' => $eventId]);
                } catch (Throwable $e) {
                    // leave state untouched on failure
                }
            }
            foreach (self::$probeDealIds as $dealId) {
                try {
                    self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
                } catch (Throwable $e) {
                    // leave state untouched on failure
                }
            }
        }
        self::$probeEventIds = [];
        self::$probeDealIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    #[Group('integration')]
    public function testAnonymousVisitorAndCoupleAreRefused(): void
    {
        $this->requireDb();

        self::$jar = [];
        $anon = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertSame(302, $anon['status'], 'the board is not public');

        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
        $couple = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertSame(302, $couple['status'], 'a couple must not read the planner\'s supplier board');

        // The editor is behind the same door — refusing the page is not enough
        // if the write endpoint were open.
        $couplePost = self::http('POST', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS, [
            'coverage_needs' => '1',
            'rows'           => [self::$probeEventIds[0] ?? 0],
        ]);
        self::assertSame(302, $couplePost['status'], 'a couple must not save requirements either');
    }

    #[Group('integration')]
    public function testUnconfiguredFunctionsStartFromAllTenServices(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        $page = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertSame(200, $page['status']);
        $body = $page['body'];

        self::assertStringContainsString('Vendor Coverage', $body);
        self::assertStringContainsString('Linda Wafula', $body);
        self::assertStringContainsString('no saved list yet', $body, 'the all-ten starting point must be stated');

        // Every calendar function of THIS wedding is a row.
        foreach (self::$db->fetchAll(
            'SELECT title FROM events WHERE booking_id = :b',
            [':b' => self::BOOKING_WITH_DEALS]
        ) as $event) {
            self::assertStringContainsString(htmlspecialchars((string) $event->title), $body, 'each function must be a row');
        }

        $board = self::boardRegion($body);

        // The seed's confirmed caterer and pending decorator, both whole-wedding
        // deals, so both appear in every function row.
        self::assertStringContainsString('Nyama Choma Masters', $board);
        self::assertStringContainsString('Floral Dreams', $board);
        self::assertStringContainsString('vendor_enquiries.php?deal=200#deal-200', $board, 'a covered cell links to its deal');

        self::assertStringContainsString('cv-cell--booked', $board);
        self::assertStringContainsString('cv-cell--pending', $board);
        self::assertStringContainsString('cv-cell--missing', $board);

        // Missing cells route the planner to the marketplace for this wedding.
        self::assertStringContainsString('vendors.php?category=photography&amp;booking_id=200', $board);
        self::assertStringContainsString('Find', $board);

        // All ten categories are required until the planner says otherwise, so
        // the counts are functions × ten, not a guess.
        $events = self::eventCount(self::BOOKING_WITH_DEALS);
        $deals  = self::dealsByState(self::BOOKING_WITH_DEALS);
        self::assertStringContainsString(
            '<strong>' . ($events * $deals['booked']) . ' of ' . ($events * 10) . '</strong> required services booked',
            $body
        );
        $dealCount = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b',
            [':b' => self::BOOKING_WITH_DEALS]
        );
        self::assertStringContainsString('<h6>Deals on this wedding</h6><div class="value">' . $dealCount . '</div>', $body);

        // No vendor is committed twice on these seeded dates, so the at-risk
        // card reads zero and stays grey: it is a warning only when it has a
        // number, and the booked total is never quietly reduced by it.
        self::assertMatchesRegularExpression(
            '/<h6>Double-booked cover<\/h6>\s*<div class="value">0<\/div>/',
            $body
        );
        self::assertStringNotContainsString('stat-card is-risk', $body);
    }

    #[Group('integration')]
    public function testEditingSavesTheListAndNarrowsTheBoard(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        $probe = self::$probeEventIds[0];
        self::assertGreaterThan(0, $probe);

        // Post the two in reverse canonical order: the stored row must still be
        // canonical, so a later render cannot depend on checkbox order.
        $res = self::postNeeds(self::BOOKING_WITH_DEALS, $probe, ['photography', 'catering']);
        self::assertSame(302, $res['status'], 'a save must redirect (POST/redirect/GET)');
        self::assertStringContainsString('vendor_coverage.php', $res['location'] ?? '');

        self::assertSame(
            'catering,photography',
            (string) self::$db->fetchValue(
                'SELECT categories FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = :e',
                [':b' => self::BOOKING_WITH_DEALS, ':e' => $probe]
            ),
            'the saved list must be canonical, not whatever order was posted'
        );

        $page = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertSame(200, $page['status']);
        $body  = $page['body'];
        $board = self::boardRegion($body);

        // The probe row now has two judged cells and eight "not required" ones.
        $row = self::rowRegion($board, '[coverage-probe] Pre-wedding meeting');
        self::assertStringContainsString('cv-cell--booked', $row);
        self::assertStringContainsString('cv-cell--missing', $row);
        self::assertStringContainsString('cv-cell--extra', $row);
        self::assertStringNotContainsString('cv-cell--pending', $row, 'the pending decor deal must not count as cover here');
        self::assertSame(8, substr_count($row, 'cv-cell--extra'));
        self::assertStringNotContainsString('Find', self::cellRegion($row, 'decor'), 'a service that is not needed is not a hole');
        self::assertStringContainsString('vendors.php?category=photography&amp;booking_id=200', $row, 'a required hole still offers Find');

        // The whole-wedding decor deal stays visible — muted — rather than
        // disappearing because the planner unticked the category.
        self::assertStringContainsString('Floral Dreams', $row);
        self::assertStringContainsString('cv-chip is-extra', $row);

        // Counts follow requirements: the five untouched seed functions keep
        // their ten each, the probe contributes two.
        $events = self::eventCount(self::BOOKING_WITH_DEALS);
        $deals  = self::dealsByState(self::BOOKING_WITH_DEALS);
        $booked = ($events - 1) * $deals['booked'] + 1; // probe: catering is the booked one
        $pending = ($events - 1) * $deals['pending'];
        self::assertSame(1, $deals['booked'], 'seed shape changed: booking 200 should have one confirmed category');
        self::assertSame(1, $deals['pending'], 'seed shape changed: booking 200 should have one pending category');
        self::assertStringContainsString(
            '<strong>' . $booked . ' of ' . (($events - 1) * 10 + 2) . '</strong> required services booked',
            $body
        );
        if ($pending > 0) {
            self::assertStringContainsString('· ' . $pending . ' awaiting a vendor\'s answer', $body);
        }

        // An empty list is a saved decision — the row keeps saying so after a
        // reload, instead of falling back to all ten.
        $empty = self::postNeeds(self::BOOKING_WITH_DEALS, $probe, []);
        self::assertSame(302, $empty['status']);
        self::assertSame(
            '',
            (string) self::$db->fetchValue(
                'SELECT categories FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = :e',
                [':b' => self::BOOKING_WITH_DEALS, ':e' => $probe]
            ),
            'unticking everything must be stored as an empty list'
        );

        $reloaded = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        $row = self::rowRegion(self::boardRegion($reloaded['body']), '[coverage-probe] Pre-wedding meeting');
        self::assertStringContainsString('no vendor services needed', $row);
        self::assertSame(10, substr_count($row, 'cv-cell--extra'));
        self::assertStringContainsString(
            '<strong>' . (($events - 1) * $deals['booked']) . ' of ' . (($events - 1) * 10) . '</strong> required services booked',
            $reloaded['body']
        );
    }

    #[Group('integration')]
    public function testPostedRowsAreScopedToThisWeddingAndNeedCsrf(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        $probe = self::$probeEventIds[0];
        $foreignEvent = self::$probeEventIds[1];

        // A valid token but a function of ANOTHER wedding: the page may only
        // write rows for the booking it resolved, so nothing is stored.
        $foreign = self::postNeeds(self::BOOKING_WITH_DEALS, $foreignEvent, ['catering']);
        self::assertSame(302, $foreign['status']);
        self::assertSame(
            0,
            (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = :e',
                [':b' => self::BOOKING_WITH_DEALS, ':e' => $foreignEvent]
            ),
            'an event id is not an authorization'
        );
        self::assertSame(
            0,
            (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = :e',
                [':b' => self::BOOKING_WITHOUT_DEALS, ':e' => $foreignEvent]
            ),
            'the row must not be written under its real wedding either'
        );

        // No CSRF token: nothing is written and the planner is told why.
        $noToken = self::http('POST', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS, [
            'coverage_needs' => '1',
            'rows'           => [$probe],
            'needs'          => [$probe => ['catering']],
        ]);
        self::assertSame(302, $noToken['status']);
        self::assertSame(
            0,
            (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = :e',
                [':b' => self::BOOKING_WITH_DEALS, ':e' => $probe]
            ),
            'a POST without the session token must write nothing'
        );

        $notice = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertStringContainsString('Invalid security token', $notice['body']);
    }

    #[Group('integration')]
    public function testBoardIsScopedToItsOwnWedding(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        $page = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITHOUT_DEALS);
        self::assertSame(200, $page['status']);
        $board = self::boardRegion($page['body']);

        $deals = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b',
            [':b' => self::BOOKING_WITHOUT_DEALS]
        );
        if ($deals === 0) {
            self::assertStringNotContainsString('Nyama Choma Masters', $board, 'another wedding\'s vendor must not appear');
            self::assertStringNotContainsString('cv-chip ', $board);
            self::assertStringNotContainsString('cv-cell--booked', $board);
            self::assertStringNotContainsString('cv-cell--pending', $board);
            // Every function × ten, all missing (nothing has been saved here).
            $events = self::eventCount(self::BOOKING_WITHOUT_DEALS);
            self::assertSame($events * 10, substr_count($board, 'cv-cell--missing'));
        }

        // Both weddings belong to the same planner, so this is scoping by
        // booking, not by permission.
        self::assertStringContainsString('Faith Njeri', $page['body']);
    }

    #[Group('integration')]
    public function testWeddingWithoutFunctionsIsJudgedAsOneRow(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        self::assertSame(0, self::eventCount(self::BOOKING_NO_FUNCTIONS), 'seed changed: booking 202 should have no functions');

        // Fixture ownership: write the record this test asserts about, with a
        // literal booking id the fixture sweep can see. The save through the
        // page below is an HTTP call, which a statement-window scan cannot
        // attribute. The page then overwrites this row with the edited list.
        self::$db->execute(
            'INSERT INTO tblevent_vendor_needs (booking_id, event_id, categories, updated_by)
             VALUES (:b, :e, :c, 0)
             ON DUPLICATE KEY UPDATE categories = VALUES(categories)',
            [
                ':b' => 202,
                ':e' => 0,
                ':c' => 'catering,decor,photography,music,transport,mc,venue,cakes,flowers,other',
            ]
        );
        self::$writtenNeeds[self::BOOKING_NO_FUNCTIONS . ':0'] = [self::BOOKING_NO_FUNCTIONS, 0];

        $page = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_NO_FUNCTIONS);
        self::assertSame(200, $page['status']);
        $body  = $page['body'];
        $board = self::boardRegion($body);
        self::assertStringContainsString('Whole wedding', $board);
        self::assertStringContainsString('no functions on the calendar', $board);
        self::assertSame(10, substr_count($board, 'cv-cell--missing'), 'an unedited whole-wedding row starts from all ten');
        self::assertStringContainsString('<strong>0 of 10</strong> required services booked', $body);

        $res = self::postNeeds(self::BOOKING_NO_FUNCTIONS, 0, ['music', 'mc']);
        self::assertSame(302, $res['status']);
        self::$writtenNeeds[self::BOOKING_NO_FUNCTIONS . ':0'] = [self::BOOKING_NO_FUNCTIONS, 0];

        self::assertSame(
            'music,mc',
            (string) self::$db->fetchValue(
                'SELECT categories FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = 0',
                [':b' => self::BOOKING_NO_FUNCTIONS]
            )
        );

        $reloaded = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_NO_FUNCTIONS);
        $board = self::boardRegion($reloaded['body']);
        self::assertSame(2, substr_count($board, 'cv-cell--missing'), 'only the two required services are judged');
        self::assertSame(8, substr_count($board, 'cv-cell--extra'));
        self::assertStringContainsString('<strong>0 of 2</strong> required services booked', $reloaded['body']);
        self::assertStringContainsString('vendors.php?category=music&amp;booking_id=202', $board);
    }

    #[Group('integration')]
    public function testMissingBookingBouncesInsteadOfRendering(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        // Status only: a nonexistent id is a refusal probe, and asserting
        // anything about the rendered page would be a claim about a record
        // that does not exist.
        $unknown = self::http('GET', '/admin/vendor_coverage.php?booking_id=999999');
        self::assertSame(302, $unknown['status'], 'an unknown booking must bounce');

        $noId = self::http('GET', '/admin/vendor_coverage.php');
        self::assertSame(302, $noId['status'], 'a missing booking id must bounce');
    }

    #[Group('integration')]
    public function testWeddingEntryPointsLinkToTheBoard(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        $overview = self::http('GET', '/admin/client_manage_account_details.php?booking_id=200');
        self::assertSame(200, $overview['status']);
        // `$links` is concatenated, so the href carries a literal ampersand.
        self::assertStringContainsString('vendor_coverage.php?booking_id=200', $overview['body']);

        $ledger = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $ledger['status']);
        self::assertStringContainsString('vendor_coverage.php?booking_id=200', $ledger['body']);

        // The marketplace preselects the wedding the board sent it.
        $market = self::http('GET', '/admin/vendors.php?booking_id=200');
        self::assertSame(200, $market['status']);
        self::assertStringContainsString('<option value="200" selected>', $market['body']);
    }

    /**
     * v2.25 — a booked cell whose only vendor is working another wedding that
     * day is marked as such: red chip, red cell, and a list above the board.
     * The deal still counts as booked (it exists and is what the planner must
     * act on); what changes is that the board no longer pretends the function is
     * covered.
     */
    #[Group('integration')]
    public function testABookedCellOnADoubleBookedVendorIsMarkedAsNotRealCover(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        self::confirmVendor200Elsewhere();

        $page = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertSame(200, $page['status']);
        $board = self::boardRegion($page['body']);

        // The caterer covers every row of this wedding, so each row's catering
        // cell is at risk while they are committed elsewhere that day.
        self::assertStringContainsString('cv-cell--risk', $board);
        self::assertStringContainsString('cv-chip is-booked is-clash', $board);
        self::assertStringContainsString('DOUBLE BOOKED', $board, 'the chip must say why on hover');

        // The warning above the board names the function, the vendor and the deal.
        self::assertStringContainsString('also committed to another wedding that day', $page['body']);
        self::assertStringContainsString('Review the deal', $page['body']);
        self::assertStringContainsString('booked on a vendor who is also booked elsewhere that day', $page['body']);

        // The headline card counts the same risky cells the board marks, and does
        // not quietly lower the booked total — both numbers are true at once.
        self::assertMatchesRegularExpression(
            '/Double-booked cover<\/h6>\s*<div class="value">(\d+)<\/div>/',
            $page['body'],
            'the summary must carry a double-booked cover count'
        );
        preg_match('/Double-booked cover<\/h6>\s*<div class="value">(\d+)<\/div>/', $page['body'], $card);
        $risk_cells = substr_count($board, 'cv-cell--risk');
        self::assertGreaterThan(0, $risk_cells, 'the board must mark at least one risky cell');
        self::assertSame($risk_cells, (int) $card[1], 'the headline count must match the marked cells');
        self::assertStringContainsString('stat-card is-risk', $page['body'], 'a nonzero count must read as a warning');
        self::assertMatchesRegularExpression(
            '/<div class="note">of \d+ booked/',
            $page['body'],
            'the card must say how many booked services the count comes from'
        );

        // The catering cell of one row is flagged…
        $reception = self::rowRegion($board, 'Reception');
        self::assertStringContainsString('is-clash', self::cellRegion($reception, 'catering'));

        // …while a pending enquiry is not cover, so it carries no mark.
        self::assertStringNotContainsString('is-clash', self::cellRegion($reception, 'decor'));

        // The card is the door to the problem: it opens this board filtered to
        // the functions carrying the clash, landing on the warning that names them.
        self::assertStringContainsString('class="stat-card is-risk is-link"', $page['body']);
        self::assertStringContainsString('risk=1#coverage-risk', $page['body']);
        self::assertStringContainsString('id="coverage-risk"', $page['body'], 'the link needs a target to land on');
    }

    /**
     * v2.25 — the at-risk card narrows the board to the functions that actually
     * rest on a double-booked vendor, says so, and is reversible. The headline
     * counts keep describing the whole wedding, not the view.
     */
    #[Group('integration')]
    public function testTheDoubleBookedCardFiltersTheBoardToAffectedFunctions(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);

        self::confirmVendor200Elsewhere();

        $events = self::$db->fetchAll(
            'SELECT id, title FROM events WHERE booking_id = :b ORDER BY start, id',
            [':b' => self::BOOKING_WITH_DEALS]
        );
        self::assertGreaterThan(1, count($events), 'the filter needs at least two functions to hide one');

        // Only the first function asks for catering, so only its booked cell is
        // risky: the others need nothing and have no cover to lose.
        foreach ($events as $i => $event) {
            self::postNeeds(self::BOOKING_WITH_DEALS, (int) $event->id, $i === 0 ? ['catering'] : []);
        }
        $risky     = (string) $events[0]->title;
        $untouched = (string) $events[1]->title;

        $full = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS);
        self::assertSame(200, $full['status']);
        $full_board = self::boardRegion($full['body']);
        self::assertStringContainsString(htmlspecialchars($risky), $full_board);
        self::assertStringContainsString(htmlspecialchars($untouched), $full_board, 'unfiltered, every function is a row');

        $filtered = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITH_DEALS . '&risk=1');
        self::assertSame(200, $filtered['status']);
        $board = self::boardRegion($filtered['body']);

        // Only the risky function's row survives, and the filter explains itself.
        self::assertStringContainsString(htmlspecialchars($risky), $board);
        self::assertStringNotContainsString(htmlspecialchars($untouched), $board, 'the filter must hide unaffected functions');
        self::assertMatchesRegularExpression(
            '/Showing <strong>1<\/strong> of ' . self::eventCount(self::BOOKING_WITH_DEALS) . ' function/',
            $filtered['body']
        );
        self::assertStringContainsString('The counts above still describe the whole wedding', $filtered['body']);
        self::assertStringContainsString('Show all functions', $filtered['body'], 'the filter must be reversible');
        self::assertStringContainsString('id="coverage-risk"', $filtered['body']);

        // A stale filtered link with nothing left to hide falls back to the full
        // board instead of rendering an empty table.
        $quiet = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . self::BOOKING_WITHOUT_DEALS . '&risk=1');
        self::assertSame(200, $quiet['status']);
        self::assertStringContainsString('Nothing is double-booked on this wedding any more', $quiet['body']);
        $quiet_board = self::boardRegion($quiet['body']);
        foreach (self::$db->fetchAll(
            'SELECT title FROM events WHERE booking_id = :b',
            [':b' => self::BOOKING_WITHOUT_DEALS]
        ) as $event) {
            self::assertStringContainsString(htmlspecialchars((string) $event->title), $quiet_board);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Confirm vendor 200 for a seeded wedding OTHER than booking 200 on the same
     * day as their existing confirmed deal, so every booked cell they cover on
     * booking 200 is a clash. The row is owned by $probeDealIds and removed in
     * tearDownAfterClass.
     */
    private static function confirmVendor200Elsewhere(): void
    {
        $busy = self::$db->fetchOne(
            "SELECT id, event_date FROM tblvendor_bookings
              WHERE vendor_id = 200 AND status = 'confirmed' ORDER BY id LIMIT 1"
        );
        self::assertNotNull($busy, 'seed changed: vendor 200 has no confirmed deal');
        $day = date('Y-m-d', strtotime((string) $busy->event_date));

        self::$db->execute(
            "INSERT INTO tblvendor_bookings
                (vendor_id, booking_id, package_id, features_id, event_date, amount, commission, status, notes)
             VALUES (200, 203, NULL, NULL, :d, 90000, 9000, 'confirmed', '[coverage-availability-probe]')",
            [':d' => $day]
        );
        self::$probeDealIds[] = (int) self::$db->the_insert_id();
    }

    /** Save a list through the page's own editor (GET the token, POST it). */
    private static function postNeeds(int $bookingId, int $eventId, array $slugs): array
    {
        $edit = self::http('GET', '/admin/vendor_coverage.php?booking_id=' . $bookingId . '&edit=1');
        if (!preg_match('/name="_token" value="([^"]+)"/', $edit['body'], $m)) {
            self::fail('the editor did not render a CSRF token');
        }
        $post = [
            'coverage_needs' => '1',
            'rows'           => [$eventId],
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
        ];
        if ($slugs !== []) {
            $post['needs'] = [$eventId => $slugs];
        }
        $res = self::http('POST', '/admin/vendor_coverage.php?booking_id=' . $bookingId . '&user_id=' . self::ownerOf($bookingId), $post);
        if ($res['status'] === 302 && str_contains((string) ($res['location'] ?? ''), 'vendor_coverage.php')) {
            // Keyed by pair, not booking: one test can save several functions of
            // the same wedding and must clean up every row it wrote.
            self::$writtenNeeds[$bookingId . ':' . $eventId] = [$bookingId, $eventId];
        }
        return $res;
    }

    private static function ownerOf(int $bookingId): int
    {
        return (int) self::$db->fetchValue(
            'SELECT user_id FROM tblweddingbook WHERE booking_id = :b',
            [':b' => $bookingId]
        );
    }

    private static function forgetWrittenNeeds(): void
    {
        if (self::$db === null) {
            self::$writtenNeeds = [];
            return;
        }
        foreach (self::$writtenNeeds as [$bookingId, $eventId]) {
            try {
                self::$db->execute(
                    'DELETE FROM tblevent_vendor_needs WHERE booking_id = :b AND event_id = :e',
                    [':b' => $bookingId, ':e' => $eventId]
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$writtenNeeds = [];
    }

    private static function eventCount(int $bookingId): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM events WHERE booking_id = :b',
            [':b' => $bookingId]
        );
    }

    /**
     * Distinct categories the wedding's deals cover, by the state their status
     * earns. Whole-wedding deals cover every function, so one booked category
     * is one booked cell in every row.
     *
     * @return array{booked:int, pending:int}
     */
    private static function dealsByState(int $bookingId): array
    {
        $rows = self::$db->fetchAll(
            'SELECT v.category, vb.status
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
              WHERE vb.booking_id = :b',
            [':b' => $bookingId]
        );
        $booked  = [];
        $pending = [];
        foreach ($rows as $row) {
            $category = (string) $row->category;
            if (in_array((string) $row->status, ['confirmed', 'completed'], true)) {
                $booked[$category] = true;
            } elseif ((string) $row->status === 'pending') {
                $pending[$category] = true;
            }
        }
        return ['booked' => count($booked), 'pending' => count(array_diff_key($pending, $booked))];
    }

    /** The board table only, so sidebar content cannot satisfy an assertion. */
    private static function boardRegion(string $body): string
    {
        if (preg_match('#<table class="table table-sm table-bordered cv-board.*?</table>#s', $body, $m)) {
            return $m[0];
        }
        self::fail('coverage board table not found in the response');
    }

    /** One function's <tr>, located by its escaped title. */
    private static function rowRegion(string $board, string $title): string
    {
        $quoted = preg_quote(htmlspecialchars($title), '#');
        if (preg_match('#<tr>\s*<th scope="row">.*?' . $quoted . '.*?</tr>#s', $board, $m)) {
            return $m[0];
        }
        self::fail('function row not found: ' . $title);
    }

    /** One <td> of a row, located by its column's "Find a <label>" link or its index. */
    private static function cellRegion(string $row, string $category): string
    {
        // Columns are in board order: Catering, Decor, Photography, … — so the
        // nth <td> is the nth category.
        $slugs = ['catering', 'decor', 'photography', 'music', 'transport', 'mc', 'venue', 'cakes', 'flowers', 'other'];
        $index = array_search($category, $slugs, true);
        self::assertNotFalse($index, 'unknown category in the test');
        if (preg_match_all('#<td class="cv-cell[^"]*">.*?</td>#s', $row, $m)) {
            return $m[0][(int) $index] ?? '';
        }
        self::fail('cell not found: ' . $category);
    }

    private function requireDb(): void
    {
        if (self::$db === null || self::$base === null) {
            $this->markTestSkipped('live DB + php -S unavailable');
        }
    }

    private static function login(string $email, string $password): void
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

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vc_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vc_err');

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
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }
        return ['status' => $status, 'body' => (string) $response, 'location' => self::header($headers, 'Location')];
    }

    /** @param string[] $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (stripos($h, $name . ':') === 0) {
                return trim(substr($h, strlen($name) + 1));
            }
        }
        return null;
    }
}
