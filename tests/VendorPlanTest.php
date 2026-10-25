<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VendorPlan (admin/include/VendorPlan.php, v2.29) — one wedding's vendors and
 * one total the couple can read.
 *
 * What is under test, and why each part matters:
 *
 *  1. The three figures stay apart. A pending enquiry is a question, a confirmed
 *     deal is a cost, and a cancelled one is history. If the total collapsed
 *     them, an unanswered enquiry would read as money the couple has taken on —
 *     which is the single most misleading thing a budget page can do.
 *  2. A category is judged against the couple's own cap (v2.28): over it, still
 *     covered, awaiting a reply, or budgeted with nobody chosen. That last one is
 *     the gap a planner is meant to see, not a zero that looks finished.
 *  3. A vendor who has re-priced a service is REPORTED, never applied. The
 *     amount on a deal is what a human entered; a confirmed amount is an
 *     agreement, so neither a price change nor this page may rewrite it.
 *  4. A quote needs a number it can justify. Per guest uses the guest list, flat
 *     is the price, and per hour or per km refuses without the hours or the
 *     kilometres rather than inventing a total.
 *  5. Adding a vendor is one transaction that creates the same pending deal a
 *     marketplace enquiry creates, records which offering it is for, and tells
 *     the vendor's account. An unapproved vendor, an unpriced offering and a
 *     service the vendor does not own are all refused without writing anything.
 *
 * Every row this test writes is removed after the class runs (watermarks on
 * tblvendor_bookings, tblvendor_services and tblnotifications, plus the
 * preference rows of the booking it uses). The suite therefore runs against the
 * scratch `wpms_test` database and never against the demo one.
 *
 * Rows live on booking 201 — a seeded wedding with an empty vendor ledger and a
 * guest list — so the totals under test are this test's own.
 */
final class VendorPlanTest extends TestCase
{
    /** A seeded wedding with a date, a guest list and no vendor deals. */
    private const BOOKING = 201;

    private static ?Database $db = null;
    private static int $dealWatermark = 0;
    private static int $serviceWatermark = 0;
    private static int $notificationWatermark = 0;
    private static int $vendorId = 0;       // approved, has an account
    private static int $otherVendorId = 0;  // approved, different category
    private static int $unapprovedId = 0;

    /** @var int[] Rows this class wrote, removed after every test so the totals above are the test's own. */
    private static array $deals = [];
    /** @var int[] */
    private static array $services = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
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
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            global $db;
            $db = self::$db = new Database();

            self::$dealWatermark         = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_bookings');
            self::$serviceWatermark      = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_services');
            self::$notificationWatermark = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications');

            $approved = $db->fetchAll(
                'SELECT id, category FROM tblvendors
                  WHERE is_approved = 1 AND user_id > 0
                  ORDER BY id ASC LIMIT 2'
            );
            if (count($approved) >= 2) {
                self::$vendorId      = (int) $approved[0]->id;
                self::$otherVendorId = (int) $approved[1]->id;
            }
            $unapproved = $db->fetchValue('SELECT id FROM tblvendors WHERE is_approved = 0 ORDER BY id ASC LIMIT 1');
            self::$unapprovedId = (int) ($unapproved ?? 0);
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null || self::$vendorId <= 0 || self::$otherVendorId <= 0) {
            self::markTestSkipped('live database with two approved vendor accounts is unavailable');
        }
        if (!VendorPlan::installed()) {
            self::fail('tblvendor_bookings.service_id is missing — run `composer test:db` (the build imports migrations/v2.29_vendor_plan.sql)');
        }
        if (!VendorService::installed() || !VendorFit::installed()) {
            self::fail('tblvendor_services / tblvendor_preferences are missing — run `composer test:db`');
        }
    }

    /**
     * Each test starts from an empty ledger for its wedding. Without this the
     * deals of one test would be the baseline of the next, and a total asserted
     * twice would be asserting two different things.
     */
    protected function tearDown(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            foreach (self::$deals as $id) {
                self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $id]);
            }
            foreach (self::$services as $id) {
                self::$db->execute('DELETE FROM tblvendor_services WHERE id = :i', [':i' => $id]);
            }
            self::$deals = [];
            self::$services = [];
            // The bell rows this class caused go too, AND the outbox rows behind
            // them: a notification queues an email, and pending mail left behind
            // would be swept by MailRetryTest's installation-wide retry, filling
            // its batch with rows no suite asked for.
            self::$db->execute(
                'DELETE l FROM tbl_mail_logs l JOIN tblnotifications n ON n.id = l.notification_id '
                . 'WHERE n.booking_id = :b AND n.id > :i',
                [':b' => self::BOOKING, ':i' => self::$notificationWatermark]
            );
            self::$db->execute(
                'DELETE FROM tblnotifications WHERE booking_id = :b AND id > :i',
                [':b' => self::BOOKING, ':i' => self::$notificationWatermark]
            );
            self::$db->execute('DELETE FROM tblvendor_preferences WHERE booking_id = :b', [':b' => self::BOOKING]);
        } catch (Throwable $e) {
            // leave the scratch rows; the next `composer test:db` rebuilds them
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            self::$db->execute('DELETE FROM tblvendor_bookings WHERE id > :id', [':id' => self::$dealWatermark]);
            self::$db->execute('DELETE FROM tblvendor_services WHERE id > :id', [':id' => self::$serviceWatermark]);
            self::$db->execute(
                'DELETE FROM tblnotifications WHERE id > :id AND booking_id = :b',
                [':id' => self::$notificationWatermark, ':b' => self::BOOKING]
            );
            self::$db->execute('DELETE FROM tblvendor_preferences WHERE booking_id = :b', [':b' => self::BOOKING]);
        } catch (Throwable $e) {
            // leave the scratch rows; the next `composer test:db` rebuilds them
        }
    }

    // ------------------------------------------------------------------
    // 1. The three figures
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheTotalAddsWaitingAndCommittedDealsButNeverCancelledOnes(): void
    {
        $this->deal(self::$vendorId, 'pending', 10000, ['service_note' => 'buffet enquiry']);
        $this->deal(self::$otherVendorId, 'confirmed', 20000);
        $this->deal(self::$vendorId, 'cancelled', 5000);

        $plan = VendorPlan::build(self::BOOKING);
        $totals = $plan['totals'];

        self::assertSame(10000.0, (float) $totals['planned'], 'a pending deal is waiting, and it counts as planned');
        self::assertSame(20000.0, (float) $totals['committed'], 'a confirmed deal is committed');
        self::assertSame(30000.0, (float) $totals['total'], 'the total is planned + committed');
        self::assertSame(5000.0, (float) $totals['cancelled'], 'a cancelled deal keeps its figure on the record');
        self::assertSame(1, (int) $totals['dropped']);
        self::assertSame(3, (int) $totals['deals'], 'every deal is listed, cancelled included');
        self::assertSame(1, (int) $totals['pending']);
        self::assertSame(1, (int) $totals['confirmed']);
        self::assertSame(3, (int) $plan['guests'], 'the guest list travels with the plan');

        $catering = $plan['categories']['catering'] ?? null;
        self::assertNotNull($catering, 'the catering category is always in the plan');
        self::assertSame(10000.0, (float) $catering['total'], 'the cancelled 5,000 is not catering cost');
        self::assertSame('pending', $catering['state']);
        self::assertCount(2, $catering['rows'], 'both catering deals are listed');

        $decor = $plan['categories']['decor'] ?? null;
        self::assertNotNull($decor);
        self::assertSame('covered', $decor['state'], 'a confirmed deal covers its category');
    }

    #[Group('integration')]
    public function testACategoryOverItsCapIsSaidSoAndACappedEmptyOneIsAGap(): void
    {
        $this->pref('decor', ['budget_max' => 15000]);
        $this->pref('transport', ['budget_max' => 40000]);
        $this->deal(self::$otherVendorId, 'confirmed', 20000);

        $plan = VendorPlan::build(self::BOOKING);

        $decor = $plan['categories']['decor'];
        self::assertSame('over', $decor['state'], 'spending past the cap is its own state, not "covered"');
        self::assertSame(15000.0, (float) $decor['cap']);
        self::assertSame(-5000.0, (float) $decor['remaining'], 'remaining goes negative rather than clamping at zero');

        $transport = $plan['categories']['transport'];
        self::assertSame('gap', $transport['state'], 'a cap with no vendor chosen is a gap to fill');
        self::assertCount(0, $transport['rows']);
        self::assertSame(1, (int) $plan['totals']['gaps']);

        self::assertSame(55000.0, (float) $plan['totals']['caps'], 'caps add up to what the couple stated');
    }

    // ------------------------------------------------------------------
    // 2. Price drift is reported, not applied
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testARePricedServiceIsReportedAndTheAgreedAmountStands(): void
    {
        $serviceId = $this->service(self::$vendorId, ['title' => 'Classic buffet', 'pricing_unit' => 'per_guest', 'price' => 1200, 'min_qty' => 1]);
        $this->deal(self::$vendorId, 'pending', 200000, ['service_id' => $serviceId, 'service_qty' => 180]);

        $plan = VendorPlan::build(self::BOOKING);
        $row = $plan['categories']['catering']['rows'][0];

        self::assertSame('service', $row->offering_kind);
        self::assertSame('Classic buffet', $row->offering_label);
        self::assertTrue($row->price_moved, '1,200 × 180 is not the 200,000 entered, so the change is visible');
        self::assertSame(216000.0, (float) $row->price_now);
        self::assertSame(200000.0, (float) $row->amount, 'the entered amount is never rewritten');
        self::assertStringContainsString('KSh 200,000', $row->money_line);
        self::assertStringContainsString('180 guests', $row->money_line, 'the row says what the money bought');

        // A confirmed amount is an agreement, so the same arithmetic is not
        // reported against it.
        $confirmed = $this->deal(self::$otherVendorId, 'confirmed', 200000, ['service_id' => $serviceId, 'service_qty' => 180]);
        $plan = VendorPlan::build(self::BOOKING);
        $rows = $plan['categories']['decor']['rows'];
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->price_moved, 'a confirmed deal is not "re-priced" behind the planner');
        self::assertSame(200000.0, (float) $rows[0]->amount);
        self::assertGreaterThan(0, $confirmed);
    }

    // ------------------------------------------------------------------
    // 3. Quotes
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAQuoteUsesTheGuestListAndTheVendorsMinimum(): void
    {
        $quote = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 1200, 'unit' => 'per_guest'], null);
        self::assertTrue($quote['ok']);
        self::assertSame(3600.0, (float) $quote['amount'], '3 guests on the list × 1,200');
        self::assertSame(3, (int) $quote['qty']);

        $belowMinimum = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 1200, 'unit' => 'per_guest', 'min_qty' => 100], null);
        self::assertSame(120000.0, (float) $belowMinimum['amount'], 'the vendor\'s minimum is the real floor');
        self::assertSame(100, (int) $belowMinimum['qty']);
        self::assertStringContainsString('minimum', (string) $belowMinimum['note']);

        $flat = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 45000, 'unit' => 'flat'], null);
        self::assertSame(45000.0, (float) $flat['amount'], 'a flat fee ignores the guest list');
        self::assertSame(1, (int) $flat['qty']);

        $package = VendorPlan::quote(self::BOOKING, ['kind' => 'package', 'id' => 1, 'price' => 180000, 'unit' => ''], null);
        self::assertSame(180000.0, (float) $package['amount']);
    }

    #[Group('integration')]
    public function testAPerHourServiceNeedsTheHoursBeforeAnyTotalExists(): void
    {
        $noHours = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 2500, 'unit' => 'per_hour'], null);
        self::assertFalse($noHours['ok'], 'an invented number of hours is an invented total');
        self::assertTrue($noHours['needs_qty']);
        self::assertNull($noHours['amount']);
        self::assertStringContainsString('per hour', (string) $noHours['error']);

        $fourHours = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 2500, 'unit' => 'per_hour'], 4);
        self::assertTrue($fourHours['ok']);
        self::assertSame(10000.0, (float) $fourHours['amount']);

        $perKm = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 80, 'unit' => 'per_km'], null);
        self::assertFalse($perKm['ok'], 'kilometres are the planner\'s to state, not the page\'s to guess');

        $noPrice = VendorPlan::quote(self::BOOKING, ['kind' => 'service', 'id' => 1, 'price' => 0, 'unit' => 'flat'], null);
        self::assertFalse($noPrice['ok']);
        self::assertStringContainsString('no published price', (string) $noPrice['error']);
    }

    // ------------------------------------------------------------------
    // 4. Adding a vendor
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAddingAVendorCreatesThePendingDealRecordsTheOfferingAndTellsTheVendor(): void
    {
        $serviceId = $this->service(self::$vendorId, ['title' => 'Buffet service', 'pricing_unit' => 'per_guest', 'price' => 1200, 'min_qty' => 1]);
        $vendorUserId = (int) self::$db->fetchValue(
            'SELECT user_id FROM tblvendors WHERE id = :v',
            [':v' => self::$vendorId]
        );

        $result = VendorPlan::add(self::BOOKING, self::$vendorId, 'service', $serviceId, null, 0.0, 'halal menu', 1);

        self::assertTrue($result['ok'], 'adding a vendor should succeed: ' . $result['error']);
        self::$deals[] = (int) $result['deal_id'];
        self::assertSame(3600.0, (float) $result['amount'], 'the quote is the posted price × the guest list');
        self::assertSame(3, (int) $result['qty']);
        self::assertSame('Buffet service', $result['label']);

        $deal = self::$db->fetchOne('SELECT * FROM tblvendor_bookings WHERE id = :i', [':i' => $result['deal_id']]);
        self::assertNotNull($deal);
        self::assertSame('pending', (string) $deal->status, 'planning is an enquiry, not a booking');
        self::assertSame($serviceId, (int) $deal->service_id);
        self::assertSame(3, (int) $deal->service_qty);
        self::assertSame(3600.0, (float) $deal->amount);
        self::assertStringContainsString('Buffet service', (string) $deal->notes);
        self::assertStringContainsString('planned by', (string) $deal->notes, 'the notes trail says who planned it');
        self::assertStringContainsString('halal menu', (string) $deal->notes, 'the planner\'s note travels with the deal');

        $bell = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications
              WHERE booking_id = :b AND user_id = :u AND recipient_type = 'user'
                AND message LIKE '%wedding plan%'",
            [':b' => self::BOOKING, ':u' => $vendorUserId]
        );
        self::assertSame(1, $bell, 'the vendor hears about it once, on their own account');
    }

    #[Group('integration')]
    public function testAVendorWithNothingPublishedIsPlannedWithAnAgreedAmount(): void
    {
        $refused = VendorPlan::add(self::BOOKING, self::$otherVendorId, 'custom', 0, null, 0.0, '', 1);
        self::assertFalse($refused['ok'], 'a plan line with no price cannot be totalled');
        self::assertStringContainsString('amount', (string) $refused['error']);

        $added = VendorPlan::add(self::BOOKING, self::$otherVendorId, 'custom', 0, null, 45000.0, '', 1);
        self::assertTrue($added['ok'], $added['error']);
        self::$deals[] = (int) $added['deal_id'];
        self::assertSame(45000.0, (float) $added['amount']);
        self::assertSame('Custom arrangement', $added['label']);

        $deal = self::$db->fetchOne('SELECT * FROM tblvendor_bookings WHERE id = :i', [':i' => $added['deal_id']]);
        self::assertNull($deal->package_id);
        self::assertNull($deal->service_id);
    }

    #[Group('integration')]
    public function testAnUnapprovedVendorIsNeverPlanned(): void
    {
        if (self::$unapprovedId <= 0) {
            self::markTestSkipped('no unapproved vendor in the fixture');
        }
        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b', [':b' => self::BOOKING]);

        $result = VendorPlan::add(self::BOOKING, self::$unapprovedId, 'custom', 0, null, 20000.0, '', 1);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not available', (string) $result['error']);
        $after = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblvendor_bookings WHERE booking_id = :b', [':b' => self::BOOKING]);
        self::assertSame($before, $after, 'a refused plan writes nothing');
    }

    #[Group('integration')]
    public function testAServiceTheVendorDoesNotOwnIsRefused(): void
    {
        $foreign = $this->service(self::$otherVendorId, ['title' => 'Someone else\'s lights', 'price' => 5000, 'pricing_unit' => 'flat']);

        $result = VendorPlan::add(self::BOOKING, self::$vendorId, 'service', $foreign, null, 0.0, '', 1);

        self::assertFalse($result['ok'], 'a planner must not buy another vendor\'s offering through this door');
        self::assertStringContainsString('no longer listed', (string) $result['error']);
    }

    #[Group('integration')]
    public function testThePickerListsOnlyActiveOfferingsInCategoryOrder(): void
    {
        $active   = $this->service(self::$vendorId, ['title' => 'Picker active', 'price' => 9000, 'pricing_unit' => 'flat']);
        $inactive = $this->service(self::$vendorId, ['title' => 'Picker hidden', 'price' => 100, 'pricing_unit' => 'flat', 'is_active' => 0]);

        $pick = VendorPlan::offerings();
        self::assertNotSame([], $pick, 'approved vendors are always listed, even with nothing published');

        $offerings = [];
        foreach ($pick as $group) {
            foreach ($group['offerings'] as $offering) {
                $offerings[$offering['id'] . ':' . $offering['kind']] = $offering;
            }
        }

        self::assertArrayHasKey($active . ':service', $offerings);
        self::assertArrayNotHasKey($inactive . ':service', $offerings, 'a hidden service is not for sale');
        self::assertSame('Picker active', $offerings[$active . ':service']['label']);

        // Category order is the board's order, so the picker reads like the plan.
        $seen = [];
        foreach ($pick as $group) {
            $seen[] = $group['category'];
        }
        $expected = array_values(array_filter(VendorCoverage::slugs(), static fn (string $s): bool => in_array($s, $seen, true)));
        self::assertSame($expected, array_values(array_unique($seen)), 'vendors are grouped in the board\'s category order');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function deal(int $vendorId, string $status, float $amount, array $extra = []): int
    {
        $serviceId = $extra['service_id'] ?? null;
        $qty       = $extra['service_qty'] ?? null;
        $note      = (string) ($extra['service_note'] ?? 'test deal');

        self::$db->execute(
            "INSERT INTO tblvendor_bookings
                (vendor_id, booking_id, package_id, service_id, service_qty, event_date, amount, commission, status, notes, created_at)
             VALUES (:v, :b, NULL, :sid, :qty, NULL, :amt, 0, :st, :n, NOW())",
            [
                ':v' => $vendorId, ':b' => self::BOOKING, ':sid' => $serviceId, ':qty' => $qty,
                ':amt' => $amount, ':st' => $status, ':n' => $note,
            ]
        );
        $id = (int) self::$db->fetchValue('SELECT MAX(id) FROM tblvendor_bookings WHERE booking_id = :b', [':b' => self::BOOKING]);
        self::$deals[] = $id;
        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function service(int $vendorId, array $extra): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_services
                (vendor_id, catalog_id, title, description, pricing_unit, price, min_qty, max_qty,
                 lead_time_days, includes, tags, is_active)
             VALUES (:v, NULL, :t, '', :unit, :price, :min, NULL, NULL, '', '', :active)",
            [
                ':v'      => $vendorId,
                ':t'      => (string) ($extra['title'] ?? 'Test service'),
                ':unit'    => (string) ($extra['pricing_unit'] ?? 'flat'),
                ':price'   => (float) ($extra['price'] ?? 1000),
                ':min'     => $extra['min_qty'] ?? null,
                ':active'  => (int) ($extra['is_active'] ?? 1),
            ]
        );
        $id = (int) self::$db->fetchValue('SELECT MAX(id) FROM tblvendor_services WHERE vendor_id = :v', [':v' => $vendorId]);
        self::$services[] = $id;
        return $id;
    }

    /** @param array<string, mixed> $values */
    private function pref(string $category, array $values): void
    {
        self::$db->execute(
            'INSERT INTO tblvendor_preferences (booking_id, service_category, taste_tags, budget_min, budget_max, notes, updated_by)
             VALUES (:b, :c, :tags, :min, :max, :notes, NULL)
             ON DUPLICATE KEY UPDATE taste_tags = VALUES(taste_tags), budget_min = VALUES(budget_min),
                                     budget_max = VALUES(budget_max), notes = VALUES(notes)',
            [
                ':b'    => self::BOOKING,
                ':c'    => $category,
                ':tags' => (string) ($values['taste_tags'] ?? ''),
                ':min'  => $values['budget_min'] ?? null,
                ':max'  => $values['budget_max'] ?? null,
                ':notes' => (string) ($values['notes'] ?? ''),
            ]
        );
    }
}
