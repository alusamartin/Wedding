<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proposal (admin/include/Proposal.php, v2.30) — a registration becomes a priced
 * wedding the couple chooses from, then a confirmed plan.
 *
 * What is under test, and why each part matters:
 *
 *  1. The BRIEF survives. The couple's guests, budget, venue, style and words
 *     are what the planner prices against; a missing budget stays NULL ("not
 *     stated" and "nothing" are different answers) and a "preferred planner"
 *     that is not a planner is ignored rather than stored.
 *  2. The HANDSHAKE is visible. Acknowledging tells the couple (bell + queued
 *     email), because their first question after registering is "did anyone
 *     see this?".
 *  3. THREE is the promise. Options cannot be sent below three priced ones —
 *     one option is not a choice — and an option with nothing in it is not
 *     priced.
 *  4. MONEY is quoted, not invented. A package is its price, a per-guest service
 *     is price × the guest list, a custom arrangement needs the agreed amount,
 *     and another vendor's (or a hidden) service cannot be bought through this
 *     door.
 *  5. The COUPLE decides. Choosing is theirs, only after options are sent; a
 *     tweak or a decline is recorded in their words and the planner is told.
 *  6. LOCK is all-or-nothing. It re-checks approval, listings and availability,
 *     creates one pending deal per line through the vendor plan's own door (so
 *     vendors are notified), and confirms the wedding. A vendor already booked
 *     that day BLOCKS until the planner acknowledges the clash; a line that can
 *     no longer be bought rolls the whole thing back — no half-confirmed
 *     wedding, and no confirmed wedding either.
 *
 * Rows this test writes are removed after EVERY test (the proposal cascades to
 * its options and items), and booking 201's account status AND organizer are
 * restored to the seed's own values, so the suite runs on the scratch
 * `wpms_test` database without leaving a confirmed or unassigned wedding behind
 * for the next file (`VendorAvailabilityTest` reads this same wedding).
 */
final class ProposalFlowTest extends TestCase
{
    private const BOOKING = 201;   // a seeded wedding with an empty vendor ledger
    private const PLANNER = 7;     // amina@wpms.co.ke — a verified planner account

    private static ?Database $db = null;
    private static int $vendorId = 0;      // approved, has an account (catering)
    private static int $otherVendorId = 0; // approved, different category (decor)
    private static int $packageId = 0;
    private static int $packagePrice = 0;
    private static int $otherPackageId = 0;
    private static int $otherPackagePrice = 0;
    private static string $weddingDate = '';
    private static string $coupleStatusBefore = '';
    private static int $organizerBefore = 0;
    private static int $dealWatermark = 0;
    private static int $serviceWatermark = 0;
    private static int $notificationWatermark = 0;
    private static int $checklistWatermark = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
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
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            global $db;
            $db = self::$db = new Database();

            self::$dealWatermark         = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_bookings');
            self::$serviceWatermark      = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_services');
            self::$notificationWatermark = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications');
            self::$checklistWatermark    = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblchecklist');

            self::$weddingDate = (string) $db->fetchValue(
                'SELECT wedding_date FROM tblweddingbook WHERE booking_id = :b',
                [':b' => self::BOOKING]
            );
            $booking = $db->fetchOne('SELECT user_id, organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::BOOKING]);
            if ($booking !== null) {
                // The seed's own organizer, put back after every test. Zeroing it
                // instead would leave the fixture unassigned for whatever suite
                // runs next — VendorAvailabilityTest reads exactly this wedding.
                self::$organizerBefore = (int) $booking->organizer_id;
                self::$coupleStatusBefore = (string) $db->fetchValue(
                    'SELECT status FROM tblaccounts_detail WHERE user_id = :u',
                    [':u' => (int) $booking->user_id]
                );
                // The fixture wedding must start UNconfirmed, or "lock confirms
                // it" could never be false and the assertion would prove nothing.
                // tearDown puts the seed's own value back.
                $db->execute(
                    "UPDATE tblaccounts_detail SET status = 'pending' WHERE user_id = :u",
                    [':u' => (int) $booking->user_id]
                );
            }

            $approved = $db->fetchAll(
                'SELECT id, category FROM tblvendors WHERE is_approved = 1 AND user_id > 0 ORDER BY id ASC LIMIT 2'
            );
            if (count($approved) >= 2) {
                self::$vendorId = (int) $approved[0]->id;
                self::$otherVendorId = (int) $approved[1]->id;
            }
            $first = $db->fetchOne(
                'SELECT id, price FROM tblvendor_packages WHERE vendor_id = :v AND price > 0 ORDER BY price ASC LIMIT 1',
                [':v' => self::$vendorId]
            );
            if ($first !== null) {
                self::$packageId = (int) $first->id;
                self::$packagePrice = (int) round((float) $first->price);
            }
            $second = $db->fetchOne(
                'SELECT id, price FROM tblvendor_packages WHERE vendor_id = :v AND price > 0 ORDER BY price ASC LIMIT 1',
                [':v' => self::$otherVendorId]
            );
            if ($second !== null) {
                self::$otherPackageId = (int) $second->id;
                self::$otherPackagePrice = (int) round((float) $second->price);
            }
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null || self::$vendorId <= 0 || self::$packageId <= 0 || self::$otherPackageId <= 0) {
            self::markTestSkipped('live database with two approved vendors, their packages and a seeded wedding is unavailable');
        }
        if (!Proposal::installed()) {
            self::fail('tblproposals is missing — run `composer test:db` (the build imports migrations/v2.30_proposals.sql)');
        }
        putenv('WPMS_PROPOSAL_FLOW');  // default: on
    }

    protected function tearDown(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            self::$db->execute('DELETE FROM tblproposals WHERE booking_id = :b', [':b' => self::BOOKING]);
            self::$db->execute('DELETE FROM tblvendor_bookings WHERE id > :i', [':i' => self::$dealWatermark]);
            self::$db->execute('DELETE FROM tblvendor_services WHERE id > :i', [':i' => self::$serviceWatermark]);
            // Lock seeds the wedding's checklist (template + one line per vendor).
            self::$db->execute(
                'DELETE FROM tblchecklist WHERE booking_id = :b AND id > :i',
                [':b' => self::BOOKING, ':i' => self::$checklistWatermark]
            );
            self::$db->execute(
                'DELETE l FROM tbl_mail_logs l JOIN tblnotifications n ON n.id = l.notification_id '
                . 'WHERE n.booking_id = :b AND n.id > :i',
                [':b' => self::BOOKING, ':i' => self::$notificationWatermark]
            );
            self::$db->execute(
                'DELETE FROM tblnotifications WHERE booking_id = :b AND id > :i',
                [':b' => self::BOOKING, ':i' => self::$notificationWatermark]
            );
            // Undo the confirmation a lock test writes, so every test starts
            // from the same unconfirmed fixture (the seed's own value is put
            // back once, after the class).
            $booking = self::$db->fetchOne('SELECT user_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::BOOKING]);
            if ($booking !== null) {
                self::$db->execute(
                    "UPDATE tblaccounts_detail SET status = 'pending' WHERE user_id = :u",
                    [':u' => (int) $booking->user_id]
                );
            }
            self::$db->execute(
                'UPDATE tblweddingbook SET organizer_id = :o WHERE booking_id = :b',
                [':o' => self::$organizerBefore, ':b' => self::BOOKING]
            );
        } catch (Throwable $e) {
            // the next `composer test:db` rebuilds the scratch database
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null || self::$coupleStatusBefore === '') {
            return;
        }
        try {
            $booking = self::$db->fetchOne('SELECT user_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::BOOKING]);
            if ($booking !== null) {
                self::$db->execute(
                    'UPDATE tblaccounts_detail SET status = :s WHERE user_id = :u',
                    [':s' => self::$coupleStatusBefore, ':u' => (int) $booking->user_id]
                );
            }
        } catch (Throwable $e) {
            // the next `composer test:db` rebuilds the scratch database
        }
    }

    // ------------------------------------------------------------------
    // 1. The brief
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRegistrationBecomesAProposalWithTheBriefTheCoupleTyped(): void
    {
        $id = Proposal::create_for_booking(self::BOOKING, [
            'guests' => 120,
            'budget' => 800000,
            'venue' => 'Nakuru Gardens',
            'taste_tags' => 'garden, rustic',
            'couple_note' => 'Small ceremony',
            'preferred_planner_id' => self::PLANNER,
        ]);
        self::assertGreaterThan(0, $id);

        $proposal = Proposal::find($id);
        self::assertNotNull($proposal);
        self::assertSame('proposal', (string) $proposal->status);
        self::assertSame(120, (int) $proposal->guests);
        self::assertSame(800000.0, (float) $proposal->budget);
        self::assertSame('Nakuru Gardens', (string) $proposal->venue);
        self::assertSame('garden, rustic', (string) $proposal->taste_tags);
        self::assertSame('Small ceremony', (string) $proposal->couple_note);
        self::assertSame(self::PLANNER, (int) $proposal->planner_id, 'the planner they asked for owns it');
        self::assertSame(self::PLANNER, (int) $proposal->preferred_planner_id);

        $bell = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u AND recipient_type = 'user' AND message LIKE '%asked for you%'",
            [':b' => self::BOOKING, ':u' => self::PLANNER]
        );
        self::assertSame(1, $bell, 'the chosen planner hears about it once');

        // A second submission must not fork the wedding into two proposals.
        self::assertSame($id, Proposal::create_for_booking(self::BOOKING, ['guests' => 300]));
        self::assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblproposals WHERE booking_id = :b', [':b' => self::BOOKING]));
    }

    #[Group('integration')]
    public function testAnUnstatedBriefStaysUnstatedAndANonPlannerIsIgnored(): void
    {
        $id = Proposal::create_for_booking(self::BOOKING, [
            'guests' => 'lots',          // not a number
            'budget' => 0,               // "nothing stated", not "nothing to spend"
            'preferred_planner_id' => self::$vendorId, // a vendor, not a planner
        ]);
        $proposal = Proposal::find($id);
        self::assertNull($proposal->guests, 'a headcount that is not a number is not guessed');
        self::assertNull($proposal->budget, 'an empty budget stays NULL');
        self::assertSame(0, (int) $proposal->preferred_planner_id, 'a vendor cannot be asked for as a planner');
        self::assertSame(0, (int) $proposal->planner_id, 'and the proposal waits in the shared queue');
    }

    // ------------------------------------------------------------------
    // 2. The handshake
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAcknowledgementClaimsTheProposalAndTellsTheCouple(): void
    {
        $id = $this->proposal();
        $result = Proposal::acknowledge($id, self::PLANNER);
        self::assertTrue($result['ok'], $result['error']);

        $proposal = Proposal::find($id);
        self::assertSame('acknowledged', (string) $proposal->status);
        self::assertSame(self::PLANNER, (int) $proposal->planner_id);
        self::assertNotEmpty((string) $proposal->acknowledged_at);

        $couple = (int) $proposal->couple_id;
        $bell = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u AND recipient_type = 'account' AND message LIKE '%picked up your wedding%'",
            [':b' => self::BOOKING, ':u' => $couple]
        );
        self::assertSame(1, $bell, 'the couple is told, on their own bell');
    }

    // ------------------------------------------------------------------
    // 3. Three options, priced
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testOptionsCannotBeSentBelowThreePricedOnes(): void
    {
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);

        $one = $this->optionWithPackage($id, 'The only option');
        $second = Proposal::create_option($id, 'An empty idea', 'premium', '', self::PLANNER);
        self::assertTrue($second['ok']);

        $refused = Proposal::publish($id, self::PLANNER);
        self::assertFalse($refused['ok'], 'one priced option is not a choice');
        self::assertStringContainsString('at least 3', (string) $refused['error']);
        self::assertSame('acknowledged', (string) Proposal::find($id)->status, 'a refused publish changes nothing');

        $this->optionWithPackage($id, 'Second option');
        $this->optionWithPackage($id, 'Third option');

        $sent = Proposal::publish($id, self::PLANNER);
        self::assertTrue($sent['ok'], $sent['error']);
        self::assertSame('options', (string) Proposal::find($id)->status);
        self::assertNotEmpty((string) Proposal::find($id)->published_at);
        self::assertNotSame(0, $one);
    }

    #[Group('integration')]
    public function testPublishingAfterATweakClearsTheTweakAndTellsTheCoupleAgain(): void
    {
        $id = $this->publishedProposal();
        Proposal::request_tweak($id, 'Please make the decor cheaper');
        self::assertSame('tweak_requested', (string) Proposal::find($id)->status);

        $result = Proposal::publish($id, self::PLANNER);
        self::assertTrue($result['ok'], $result['error']);
        $proposal = Proposal::find($id);
        self::assertSame('options', (string) $proposal->status);
        self::assertSame('', (string) $proposal->tweak_note, 'the request is answered, not left hanging in the brief');
    }

    // ------------------------------------------------------------------
    // 4. Money on the lines
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAPackageIsItsPriceAndAServiceIsPriceTimesQuantity(): void
    {
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);
        $option = Proposal::create_option($id, 'Mixed option', 'recommended', '', self::PLANNER);

        $package = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'package', 'id' => self::$packageId, 'price' => (float) self::$packagePrice, 'unit' => '',
        ], null, 0.0, '', 'The package');
        self::assertTrue($package['ok'], $package['error']);
        self::assertSame((float) self::$packagePrice, (float) $package['amount']);

        $serviceId = $this->service(['pricing_unit' => 'per_guest', 'price' => 1200, 'min_qty' => 1]);
        $service = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'service', 'id' => $serviceId, 'price' => 1200.0, 'unit' => 'per_guest',
        ], null, 0.0, 'halal menu', 'Buffet');
        self::assertTrue($service['ok'], $service['error']);
        self::assertSame(3600.0, (float) $service['amount'], 'three guests on the list × 1,200');

        $option = Proposal::option($id, $option['id']);
        self::assertSame(
            (float) self::$packagePrice + 3600.0,
            (float) $option->items_total,
            'the option adds up to its lines'
        );
        self::assertSame(
            [(int) $package['item_id'], (int) $service['item_id']],
            array_map(static fn ($item) => (int) $item->id, $option->items),
            'the option holds exactly the two lines that were added, in order'
        );
    }

    #[Group('integration')]
    public function testAnotherVendorsServiceAHiddenOneAndAnUnpricedCustomLineAreRefused(): void
    {
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);
        $option = Proposal::create_option($id, 'Refusals', 'essential', '', self::PLANNER);

        $foreign = $this->service(['vendor_id' => self::$otherVendorId, 'price' => 5000]);
        $refused = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'service', 'id' => $foreign, 'price' => 5000.0, 'unit' => 'flat',
        ], null, 0.0, '', '');
        self::assertFalse($refused['ok'], 'a vendor cannot be sold another vendor\'s service');

        $hidden = $this->service(['is_active' => 0]);
        $refusedHidden = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'service', 'id' => $hidden, 'price' => 5000.0, 'unit' => 'flat',
        ], null, 0.0, '', '');
        self::assertFalse($refusedHidden['ok'], 'a hidden service is not for sale');
        self::assertStringContainsString('no longer listed', (string) $refusedHidden['error']);

        $custom = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'custom', 'id' => 0, 'price' => 0.0, 'unit' => '',
        ], null, 0.0, '', 'Negotiated setup');
        self::assertFalse($custom['ok'], 'a line with no amount cannot be totalled');

        $accepted = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'custom', 'id' => 0, 'price' => 0.0, 'unit' => '',
        ], null, 25000.0, '', 'Negotiated setup');
        self::assertTrue($accepted['ok'], $accepted['error']);
        self::assertSame(25000.0, (float) $accepted['amount']);
    }

    // ------------------------------------------------------------------
    // 5. The couple decides
    // ------------------------------------------------------------------

    /**
     * v3.1: a line can buy a LEVEL of the vendor's offer, not just the offer.
     * The amount comes from that tier's price (through the same quote path, so
     * the guest list still multiplies it), and the line records which level was
     * quoted — a couple reading their options can see they were offered Gold,
     * not guess it from the figure.
     */
    #[Group('integration')]
    public function testAProposalLinePricedAtATierUsesThatTiersPriceAndRecordsIt(): void
    {
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);
        $option = Proposal::create_option($id, 'Tiered option', 'recommended', '', self::PLANNER);

        $serviceId = $this->service(['pricing_unit' => 'per_guest', 'price' => 1200, 'min_qty' => 1]);
        $saved = VendorTier::save_service($serviceId, self::$vendorId, [
            'tier_price' => ['silver' => '1200', 'gold' => '1500', 'platinum' => '1900'],
        ]);
        self::assertTrue($saved['ok'], $saved['error']);

        $line = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'service', 'id' => $serviceId, 'price' => 1200.0, 'unit' => 'per_guest', 'tier' => 'gold',
        ], null, 0.0, '', 'Buffet');
        self::assertTrue($line['ok'], $line['error']);
        self::assertSame(4500.0, (float) $line['amount'], 'three guests at the Gold price, not at Silver');
        self::assertSame(
            'gold',
            (string) self::$db->fetchValue('SELECT tier FROM tblproposal_items WHERE id = :i', [':i' => (int) $line['item_id']]),
            'the line remembers the level it was quoted at'
        );

        // A tier the vendor has not priced is refused, never silently charged
        // at Silver while the couple is told they bought Platinum.
        $unpriced = Proposal::add_item($id, $option['id'], self::$vendorId, [
            'kind' => 'package', 'id' => self::$packageId, 'price' => (float) self::$packagePrice, 'unit' => '', 'tier' => 'platinum',
        ], null, 0.0, '', '');
        self::assertFalse($unpriced['ok']);
        self::assertStringContainsString('has not priced', (string) $unpriced['error']);
    }

    #[Group('integration')]
    public function testTheCoupleChoosesOnlyAfterTheOptionsAreSentAndThePlannerIsTold(): void
    {
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);
        $first = $this->optionWithPackage($id, 'Option A');
        $this->optionWithPackage($id, 'Option B');
        $this->optionWithPackage($id, 'Option C');

        self::assertFalse(Proposal::choose($id, $first)['ok'], 'there is nothing to choose before the options are sent');

        Proposal::publish($id, self::PLANNER);
        $result = Proposal::choose($id, $first);
        self::assertTrue($result['ok'], $result['error']);

        $proposal = Proposal::find($id);
        self::assertSame('chosen', (string) $proposal->status);
        self::assertSame($first, (int) $proposal->chosen_option_id);
        self::assertSame(1, (int) Proposal::option($id, $first)->is_chosen);
        self::assertSame(1, (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u AND message LIKE '%The couple chose%'",
            [':b' => self::BOOKING, ':u' => self::PLANNER]
        ));
    }

    #[Group('integration')]
    public function testATweakAndADeclineAreRecordedInTheCouplesWordsAndToldToThePlanner(): void
    {
        $id = $this->publishedProposal();

        $tweak = Proposal::request_tweak($id, 'Can the cake be smaller?');
        self::assertTrue($tweak['ok'], $tweak['error']);
        $proposal = Proposal::find($id);
        self::assertSame('tweak_requested', (string) $proposal->status);
        self::assertSame('Can the cake be smaller?', (string) $proposal->tweak_note);

        self::assertFalse(Proposal::request_tweak($id, '   ')['ok'], 'a tweak with no words is not a request');

        $decline = Proposal::decline($id, 'We are postponing the wedding');
        self::assertTrue($decline['ok'], $decline['error']);
        $proposal = Proposal::find($id);
        self::assertSame('declined', (string) $proposal->status);
        self::assertSame('We are postponing the wedding', (string) $proposal->decline_note);
        self::assertFalse(Proposal::decline($id, 'again')['ok'], 'a closed proposal stays closed');

        $bells = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u AND (message LIKE '%asked for a change%' OR message LIKE '%declined their proposal%')",
            [':b' => self::BOOKING, ':u' => self::PLANNER]
        );
        self::assertSame(2, $bells, 'the planner hears both, once each');
    }

    // ------------------------------------------------------------------
    // 6. Lock
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testLockCreatesOneDealPerLineAndConfirmsTheWedding(): void
    {
        $id = $this->chosenProposal();
        $option = Proposal::option($id, (int) Proposal::find($id)->chosen_option_id);
        self::assertNotNull($option);

        $result = Proposal::lock($id, self::PLANNER);
        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(1, $result['deals']);

        $proposal = Proposal::find($id);
        self::assertSame('locked', (string) $proposal->status);
        self::assertNotEmpty((string) $proposal->locked_at);

        $deal = self::$db->fetchOne('SELECT * FROM tblvendor_bookings WHERE booking_id = :b', [':b' => self::BOOKING]);
        self::assertNotNull($deal, 'the chosen option became a real deal');
        self::assertSame(self::$vendorId, (int) $deal->vendor_id);
        self::assertSame(self::$packageId, (int) $deal->package_id);
        self::assertSame('pending', (string) $deal->status, 'confirming the plan is an enquiry to the vendor, not a booking on their behalf');
        self::assertSame((float) self::$packagePrice, (float) $deal->amount);

        $item = $option->items[0];
        self::assertSame((int) $deal->id, (int) self::$db->fetchValue(
            'SELECT deal_id FROM tblproposal_items WHERE id = :i',
            [':i' => (int) $item->id]
        ), 'the line remembers the deal it became');

        $coupleStatus = (string) self::$db->fetchValue(
            'SELECT status FROM tblaccounts_detail WHERE user_id = :u',
            [':u' => (int) $proposal->couple_id]
        );
        self::assertSame('confirm', $coupleStatus, 'the wedding is confirmed, as client_assign.php writes it');

        $vendorBell = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND message LIKE '%wedding plan%'",
            [':b' => self::BOOKING]
        );
        self::assertSame(1, $vendorBell, 'the vendor is notified like any other enquiry');

        /*
         * The wedding's checklist stops being generic at LOCK: the Kenyan
         * template arrives, plus one "confirm this vendor" line per booked
         * vendor — because one "confirm every vendor" tick cannot stand for
         * six suppliers. The couple's dashboard reads the same calculation.
         */
        $template = count(Checklist::kenyan_template());
        $vendorTasks = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b AND phase = :p',
            [':b' => self::BOOKING, ':p' => Checklist::VENDOR_PHASE]
        );
        self::assertSame(count($option->items), $vendorTasks, 'one confirmation task per booked vendor');
        $progress = Checklist::progress(self::BOOKING, self::$weddingDate);
        self::assertSame($template + count($option->items), $progress['total'], 'template plus the booked vendors');
        self::assertSame(0, $progress['done'], 'nothing is ticked on the couple\'s behalf');
        self::assertNotNull($progress['next'], 'the very next thing to do is named');

        self::assertFalse(Proposal::lock($id, self::PLANNER)['ok'], 'a locked proposal cannot be locked twice');
    }

    #[Group('integration')]
    public function testLockRefusesADoubleBookedVendorUntilThePlannerAcknowledgesIt(): void
    {
        $id = $this->chosenProposal();

        // The same vendor, already confirmed on another wedding that day.
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, service_id, service_qty, event_date, amount, commission, status, notes, created_at)
             VALUES (:v, 200, NULL, NULL, NULL, :d, 100000, 0, 'confirmed', 'clash probe', NOW())",
            [':v' => self::$vendorId, ':d' => self::$weddingDate]
        );

        $blocked = Proposal::lock($id, self::PLANNER);
        self::assertFalse($blocked['ok'], 'a double-booked vendor blocks the confirmation');
        self::assertStringContainsString('Tick the box', (string) $blocked['error']);
        self::assertSame('chosen', (string) Proposal::find($id)->status, 'a refused lock changes nothing');
        self::assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b',
            [':b' => self::BOOKING]
        ));

        $forced = Proposal::lock($id, self::PLANNER, true);
        self::assertTrue($forced['ok'], $forced['error']);
        self::assertSame('locked', (string) Proposal::find($id)->status);
    }

    #[Group('integration')]
    public function testLockRollsBackWhenALineCanNoLongerBeBought(): void
    {
        // The chosen option is built from a SERVICE here, because a service is
        // the thing a vendor can hide after the couple has chosen it.
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);
        $serviceId = $this->service(['price' => 7000, 'pricing_unit' => 'flat']);
        $chosen = Proposal::create_option($id, 'The service option', 'essential', '', self::PLANNER);
        $added = Proposal::add_item($id, (int) $chosen['id'], self::$vendorId, [
            'kind' => 'service', 'id' => $serviceId, 'price' => 7000.0, 'unit' => 'flat',
        ], null, 0.0, '', 'The lights');
        self::assertTrue($added['ok'], $added['error']);
        $this->optionWithPackage($id, 'Second option');
        $this->optionWithPackage($id, 'Third option');
        self::assertTrue(Proposal::publish($id, self::PLANNER)['ok']);
        self::assertTrue(Proposal::choose($id, (int) $chosen['id'])['ok']);

        // The vendor hides the service after the couple chose it.
        self::$db->execute('UPDATE tblvendor_services SET is_active = 0 WHERE id = :i', [':i' => $serviceId]);

        $result = Proposal::lock($id, self::PLANNER);
        self::assertFalse($result['ok'], 'a line that is no longer for sale must stop the lock');
        self::assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b',
            [':b' => self::BOOKING]
        ), 'nothing was written');
        self::assertSame('chosen', (string) Proposal::find($id)->status, 'and the proposal is still the couple\'s choice');
        self::assertSame('pending', (string) self::$db->fetchValue(
            'SELECT status FROM tblaccounts_detail WHERE user_id = (SELECT user_id FROM tblweddingbook WHERE booking_id = :b)',
            [':b' => self::BOOKING]
        ), 'the wedding is not confirmed by a failed lock');
    }

    // ------------------------------------------------------------------
    // 7. The switch
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheWholeFlowCanBeSwitchedOff(): void
    {
        self::assertTrue(Proposal::enabled(), 'on by default');
        putenv('WPMS_PROPOSAL_FLOW=0');
        self::assertFalse(Proposal::enabled(), 'the environment switch turns it off');
        putenv('WPMS_PROPOSAL_FLOW=1');
        self::assertTrue(Proposal::enabled(), 'and back on');
        putenv('WPMS_PROPOSAL_FLOW');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function proposal(): int
    {
        $id = Proposal::create_for_booking(self::BOOKING, ['guests' => 3, 'budget' => 500000]);
        self::assertGreaterThan(0, $id, 'a proposal could not be created');
        return $id;
    }

    /** One option holding one seeded package. */
    private function optionWithPackage(int $proposal_id, string $title): int
    {
        $option = Proposal::create_option($proposal_id, $title, 'recommended', '', self::PLANNER);
        self::assertTrue($option['ok'], $option['error']);
        $item = Proposal::add_item($proposal_id, (int) $option['id'], self::$vendorId, [
            'kind' => 'package',
            'id' => self::$packageId,
            'price' => (float) self::$packagePrice,
            'unit' => '',
        ], null, 0.0, '', 'Package');
        self::assertTrue($item['ok'], $item['error']);
        return (int) $option['id'];
    }

    private function publishedProposal(): int
    {
        $id = $this->proposal();
        Proposal::acknowledge($id, self::PLANNER);
        $this->optionWithPackage($id, 'Option A');
        $this->optionWithPackage($id, 'Option B');
        $this->optionWithPackage($id, 'Option C');
        $published = Proposal::publish($id, self::PLANNER);
        self::assertTrue($published['ok'], $published['error']);
        return $id;
    }

    /** A published proposal the couple has chosen, ready to lock. */
    private function chosenProposal(): int
    {
        $id = $this->publishedProposal();
        $chosen = (int) self::$db->fetchValue(
            'SELECT id FROM tblproposal_options WHERE proposal_id = :p ORDER BY id ASC LIMIT 1',
            [':p' => $id]
        );
        $result = Proposal::choose($id, $chosen);
        self::assertTrue($result['ok'], $result['error']);
        return $id;
    }

    /** An active service on the approved vendor, cleaned up with the class. */
    private function service(array $extra = []): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_services
                (vendor_id, catalog_id, title, description, pricing_unit, price, min_qty, max_qty,
                 lead_time_days, includes, tags, is_active)
             VALUES (:v, NULL, :t, '', :unit, :price, :min, NULL, NULL, '', '', :active)",
            [
                ':v' => (int) ($extra['vendor_id'] ?? self::$vendorId),
                ':t' => (string) ($extra['title'] ?? 'Proposal test service'),
                ':unit' => (string) ($extra['pricing_unit'] ?? 'flat'),
                ':price' => (float) ($extra['price'] ?? 1000),
                ':min' => $extra['min_qty'] ?? null,
                ':active' => (int) ($extra['is_active'] ?? 1),
            ]
        );
        return (int) self::$db->fetchValue('SELECT MAX(id) FROM tblvendor_services');
    }
}
