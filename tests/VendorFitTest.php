<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VendorFit (admin/include/VendorFit.php, v2.28) — the couple's half of the
 * vendor comparison.
 *
 * What is under test, and why:
 *
 *  1. Estimates — only a quantity that can be justified is multiplied. Flat is
 *     the price, per guest is the guest list (or the vendor's minimum when the
 *     couple is smaller than it), per item/per staff is the vendor's minimum,
 *     per day is one day, and per hour/per km is NOT guessed. An invented total
 *     is the one thing a budget feature must never produce.
 *  2. The judgement — over budget beats taste, then off-taste, floor, quote,
 *     partial, fits. Every state is exercised because each is a sentence the
 *     couple will read on a card.
 *  3. The couple's stored brief — one row per (wedding, category), upserted,
 *     cleared by an empty form, and refused for a bad category, a floor above
 *     the cap, or a wedding that does not exist.
 *  4. Splitting one total — the caps must add up to exactly what the couple
 *     has, a category left out keeps its cap, and a floor the couple set
 *     outranks the arithmetic instead of being written below.
 *  5. The vendor ranking — the best service is the least severe state, a
 *     service is judged against ITS OWN category's budget, and the count of
 *     over-budget services is reported.
 *
 * Model/DB integration ('integration'): the storage tests need the live schema;
 * the judgement tests are pure and would pass without a database. Rows this
 * test writes are removed in tearDown.
 */
final class VendorFitTest extends TestCase
{
    private static ?Database $db = null;
    private static int $bookingId = 0;

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
            global $db;
            $db = self::$db = new Database();
            self::$bookingId = (int) $db->fetchValue('SELECT booking_id FROM tblweddingbook ORDER BY booking_id ASC LIMIT 1');
            if (self::$bookingId <= 0) {
                self::$db = null;
            }
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live database with a booking is unavailable');
        }
        if (!VendorFit::installed()) {
            self::fail('tblvendor_preferences is missing — run `composer test:db` (the build imports migrations/v2.28_vendor_preferences.sql)');
        }
    }

    protected function tearDown(): void
    {
        if (self::$db !== null && self::$bookingId > 0) {
            try {
                self::$db->execute('DELETE FROM tblvendor_preferences WHERE booking_id = :b', [':b' => self::$bookingId]);
            } catch (Throwable $e) {
                // the next clean run rebuilds the scratch database
            }
        }
    }

    // ------------------------------------------------------------------
    // 1. Estimates
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testFlatAndPerGuestEstimates(): void
    {
        $flat = VendorFit::estimate($this->svc(['pricing_unit' => 'flat', 'price' => 50000]), 180);
        self::assertSame(50000.0, $flat['amount']);
        self::assertSame('flat fee of KSh 50,000', $flat['note']);

        $guest = VendorFit::estimate($this->svc(['pricing_unit' => 'per_guest', 'price' => 1200]), 180);
        self::assertSame(216000.0, $guest['amount']);
        self::assertSame('KSh 1,200 × 180 guests', $guest['note']);

        // Below the vendor's stated minimum, the minimum is the real floor.
        $floor = VendorFit::estimate($this->svc(['pricing_unit' => 'per_guest', 'price' => 1200, 'min_qty' => 100]), 8);
        self::assertSame(120000.0, $floor['amount']);
        self::assertSame('KSh 1,200 × their 100-guest minimum', $floor['note']);
    }

    #[Group('integration')]
    public function testPerDayPerItemAndPerStaffEstimates(): void
    {
        $day = VendorFit::estimate($this->svc(['pricing_unit' => 'per_day', 'price' => 45000]), 100);
        self::assertSame(45000.0, $day['amount']);
        self::assertSame('KSh 45,000 × 1 day', $day['note']);

        $dayTwo = VendorFit::estimate($this->svc(['pricing_unit' => 'per_day', 'price' => 45000, 'min_qty' => 2]), 0);
        self::assertSame(90000.0, $dayTwo['amount'], 'a stated minimum of two days is two days of cost');

        $item = VendorFit::estimate($this->svc(['pricing_unit' => 'per_item', 'price' => 250, 'min_qty' => 12]), 100);
        self::assertSame(3000.0, $item['amount']);
        self::assertSame('KSh 250 × 12 items (their minimum)', $item['note']);

        $staff = VendorFit::estimate($this->svc(['pricing_unit' => 'per_staff', 'price' => 3000, 'min_qty' => 1]), 100);
        self::assertSame(3000.0, $staff['amount']);
        self::assertSame('KSh 3,000 × 1 staff member (their minimum)', $staff['note']);
    }

    #[Group('integration')]
    public function testUnknownQuantitiesAreNeverGuessed(): void
    {
        $hour = VendorFit::estimate($this->svc(['pricing_unit' => 'per_hour', 'price' => 5000]), 180);
        self::assertNull($hour['amount'], 'hours are not known here — a total would be invented');
        self::assertStringContainsString('quote', mb_strtolower($hour['note']));

        $km = VendorFit::estimate($this->svc(['pricing_unit' => 'per_km', 'price' => 120]), 180);
        self::assertNull($km['amount']);

        $item = VendorFit::estimate($this->svc(['pricing_unit' => 'per_item', 'price' => 250]), 180);
        self::assertNull($item['amount'], 'a per-item price without a quantity has no honest total');

        $guestNoList = VendorFit::estimate($this->svc(['pricing_unit' => 'per_guest', 'price' => 1200]), 0);
        self::assertNull($guestNoList['amount'], 'an empty guest list cannot be multiplied');

        $free = VendorFit::estimate($this->svc(['pricing_unit' => 'flat', 'price' => 0]), 180);
        self::assertNull($free['amount']);
    }

    // ------------------------------------------------------------------
    // 2. The judgement
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testOverBudgetBeatsATasteMatch(): void
    {
        $pref = $this->pref(['budget_max' => 100000, 'taste_tags' => 'rustic']);
        $fit = VendorFit::assess($this->svc(['pricing_unit' => 'per_guest', 'price' => 1200, 'tags' => 'rustic,halal']), $pref, 180);

        self::assertSame('over', $fit['state']);
        self::assertSame('Over budget', $fit['label']);
        self::assertContains('rustic', $fit['matched_tags']);
        self::assertStringContainsString('KSh 116,000 over your KSh 100,000 cap', implode(' ', $fit['reasons']));
    }

    #[Group('integration')]
    public function testFitsWhenBudgetAndEveryTasteMatch(): void
    {
        $pref = $this->pref(['budget_max' => 250000, 'taste_tags' => 'rustic, halal']);
        $fit = VendorFit::assess(
            $this->svc(['pricing_unit' => 'per_guest', 'price' => 1200, 'tags' => 'rustic,halal,outdoor', 'includes' => 'staff, crockery']),
            $pref,
            180
        );

        self::assertSame('fits', $fit['state']);
        self::assertSame('Fits your brief', $fit['label']);
        self::assertSame(['rustic', 'halal'], $fit['matched_tags']);
        self::assertSame([], $fit['missing_tags']);
        $reasons = implode(' ', $fit['reasons']);
        self::assertStringContainsString('KSh 216,000 of your KSh 250,000 budget', $reasons);
        self::assertStringContainsString('Ticks every taste you asked for', $reasons);
        self::assertStringContainsString('Includes staff, crockery', $reasons);
    }

    #[Group('integration')]
    public function testSomeTasteMissingIsPartial(): void
    {
        $pref = $this->pref(['budget_max' => 250000, 'taste_tags' => 'rustic,halal']);
        $fit = VendorFit::assess($this->svc(['pricing_unit' => 'flat', 'price' => 50000, 'tags' => 'rustic']), $pref, 100);

        self::assertSame('partial', $fit['state']);
        self::assertSame(['halal'], $fit['missing_tags']);
        self::assertStringContainsString('still missing halal', implode(' ', $fit['reasons']));
    }

    #[Group('integration')]
    public function testNoWantedTasteAtAllIsOffTaste(): void
    {
        $pref = $this->pref(['budget_max' => 500000, 'taste_tags' => 'halal']);
        $fit = VendorFit::assess($this->svc(['pricing_unit' => 'flat', 'price' => 50000, 'tags' => 'rustic,outdoor']), $pref, 100);

        self::assertSame('off_taste', $fit['state']);
        self::assertStringContainsString('none of halal', implode(' ', $fit['reasons']));
    }

    #[Group('integration')]
    public function testBelowTheFloorIsInformational(): void
    {
        $pref = $this->pref(['budget_min' => 200000, 'budget_max' => 400000]);
        $fit = VendorFit::assess($this->svc(['pricing_unit' => 'per_guest', 'price' => 500]), $pref, 180);

        self::assertSame('below', $fit['state']);
        self::assertSame('Below your floor', $fit['label']);
        self::assertStringContainsString('under the KSh 200,000 floor', implode(' ', $fit['reasons']));
    }

    #[Group('integration')]
    public function testQuoteNeededWhenTheUnitHasNoQuantity(): void
    {
        $pref = $this->pref(['budget_max' => 250000]);
        $fit = VendorFit::assess($this->svc(['pricing_unit' => 'per_hour', 'price' => 5000]), $pref, 180);

        self::assertSame('quote', $fit['state']);
        self::assertSame('Quote needed', $fit['label']);
        self::assertNull($fit['estimate']);
        self::assertStringContainsString('ask them for a total', implode(' ', $fit['reasons']));
    }

    #[Group('integration')]
    public function testNoPreferenceDrawsNothing(): void
    {
        $fit = VendorFit::assess($this->svc(['price' => 50000]), null, 100);
        self::assertSame('unset', $fit['state']);
        self::assertSame('', $fit['label']);
        self::assertSame([], $fit['reasons']);
    }

    // ------------------------------------------------------------------
    // 3. The stored brief
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testSaveReadUpdateAndClearOneCategory(): void
    {
        $saved = VendorFit::save(self::$bookingId, 'catering', [
            'taste_tags' => ' Rustic, rustic, HALAL, , outdoor ',
            'budget_min' => '100000',
            'budget_max' => '300000',
            'notes'      => ' must be halal, no pork ',
        ], 7);
        self::assertTrue($saved['ok'], $saved['error']);

        $pref = VendorFit::preference(self::$bookingId, 'catering');
        self::assertNotNull($pref);
        self::assertSame('rustic,halal,outdoor', (string) $pref->taste_tags, 'tags are normalised, deduplicated and capped');
        self::assertEqualsWithDelta(100000.0, (float) $pref->budget_min, 0.01);
        self::assertEqualsWithDelta(300000.0, (float) $pref->budget_max, 0.01);
        self::assertSame('must be halal, no pork', (string) $pref->notes);

        // A second save updates the same row rather than stacking one.
        VendorFit::save(self::$bookingId, 'catering', ['budget_max' => '250000'], 7);
        $again = VendorFit::preferences(self::$bookingId);
        self::assertCount(1, $again);
        self::assertEqualsWithDelta(250000.0, (float) $again['catering']->budget_max, 0.01);
        self::assertNull($again['catering']->budget_min, 'a blank floor is cleared, not kept');
        self::assertSame('', (string) $again['catering']->taste_tags, 'a blank tag field is cleared too');

        // An empty form clears the row: changed-mind == never-said.
        $cleared = VendorFit::save(self::$bookingId, 'catering', ['taste_tags' => '', 'budget_max' => ''], 7);
        self::assertTrue($cleared['ok']);
        self::assertTrue($cleared['cleared']);
        self::assertNull(VendorFit::preference(self::$bookingId, 'catering'));
    }

    #[Group('integration')]
    public function testSaveRefusesBadCategoryBadWindowAndUnknownWedding(): void
    {
        $badCategory = VendorFit::save(self::$bookingId, 'spaceship', ['budget_max' => '1000'], 7);
        self::assertFalse($badCategory['ok']);

        $backwards = VendorFit::save(self::$bookingId, 'decor', ['budget_min' => '50000', 'budget_max' => '10000'], 7);
        self::assertFalse($backwards['ok']);
        self::assertStringContainsString('floor cannot be above the cap', $backwards['error']);

        $ghost = VendorFit::save(999999, 'decor', ['budget_max' => '1000'], 7);
        self::assertFalse($ghost['ok']);
        self::assertStringContainsString('wedding', $ghost['error']);
    }

    #[Group('integration')]
    public function testAllocationSumsOnlyTheCapsAndCountsWhatWasStated(): void
    {
        VendorFit::save(self::$bookingId, 'catering', ['budget_max' => '250000'], 7);
        VendorFit::save(self::$bookingId, 'decor', ['taste_tags' => 'rustic'], 7);
        VendorFit::save(self::$bookingId, 'flowers', ['budget_min' => '20000', 'budget_max' => '60000'], 7);

        $allocation = VendorFit::allocation(self::$bookingId);
        self::assertSame(3, $allocation['stated']);
        self::assertSame(2, $allocation['capped']);
        self::assertEqualsWithDelta(310000.0, $allocation['allocated'], 0.01);
    }

    #[Group('integration')]
    public function testGuestCountIsTheCouplesOwnList(): void
    {
        $expected = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblguest WHERE booking_id = :b',
            [':b' => self::$bookingId]
        );
        self::assertSame($expected, VendorFit::guests(self::$bookingId));
        self::assertSame(0, VendorFit::guests(0));
    }

    #[Group('integration')]
    public function testSummaryReadsAsABrief(): void
    {
        $pref = $this->pref(['budget_max' => 250000, 'taste_tags' => 'rustic,halal', 'notes' => 'no pork']);
        self::assertSame('KSh 250,000 · rustic, halal', VendorFit::summary($pref));

        $tasteOnly = $this->pref(['taste_tags' => 'outdoor']);
        self::assertSame('outdoor', VendorFit::summary($tasteOnly));

        $noteOnly = $this->pref(['notes' => 'must be halal']);
        self::assertSame('must be halal', VendorFit::summary($noteOnly));

        self::assertSame('', VendorFit::summary(null));
    }

    // ------------------------------------------------------------------
    // 4. Splitting one total across the categories
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheStartingSharesCoverEveryCategoryAndAddUpToHundred(): void
    {
        $shares = VendorFit::shares();
        self::assertSame(VendorCoverage::slugs(), array_keys($shares),
            'every category the marketplace can show needs a starting share, in board order');
        self::assertSame(100, array_sum($shares), 'the starting shares are percentages of one total');
        foreach ($shares as $slug => $share) {
            self::assertGreaterThan(0, $share, $slug . ' must start above zero or it would read as "left out"');
        }
    }

    #[Group('integration')]
    public function testPlanSplitPlacesTheWholeTotalInWholeBlocks(): void
    {
        $plan = VendorFit::plan_split(300000, VendorFit::shares());
        self::assertSame('', $plan['error']);
        self::assertSame(10, $plan['active']);
        self::assertEqualsWithDelta(300000.0, $plan['planned'], 0.01, 'the caps must add up to what the couple has');
        self::assertEqualsWithDelta(0.0, $plan['unplaced'], 0.01);

        foreach ($plan['rows'] as $row) {
            self::assertNotNull($row['amount']);
            self::assertSame(0.0, fmod((float) $row['amount'], (float) VendorFit::SPLIT_STEP),
                $row['category'] . ' must land on a whole block, not a measured-looking number');
        }

        $byCategory = [];
        foreach ($plan['rows'] as $row) {
            $byCategory[$row['category']] = $row;
        }
        self::assertEqualsWithDelta(78000.0, (float) $byCategory['catering']['amount'], 0.01, '26% of 300,000');
        self::assertEqualsWithDelta(66000.0, (float) $byCategory['venue']['amount'], 0.01, '22% of 300,000');
        self::assertSame('set', $byCategory['catering']['status']);
        self::assertTrue($byCategory['catering']['changed']);
    }

    #[Group('integration')]
    public function testPlanSplitReportsTheShillingsItCannotPlace(): void
    {
        // A package price is rarely a round number; the leftover must be named,
        // not smeared across ten categories to make the sum look right.
        $plan = VendorFit::plan_split(287654, VendorFit::shares());
        self::assertSame('', $plan['error']);
        self::assertEqualsWithDelta(287500.0, $plan['planned'], 0.01);
        self::assertEqualsWithDelta(154.0, $plan['unplaced'], 0.01);
        self::assertLessThanOrEqual(287654.0, $plan['planned'], 'a split must never place more than the couple has');
    }

    #[Group('integration')]
    public function testPlanSplitLeavesOutACategoryWithoutTouchingItsCap(): void
    {
        $shares = VendorFit::shares();
        unset($shares['mc']); // the couple already has a friend doing it
        $prefs = [
            'mc'       => $this->pref(['service_category' => 'mc', 'budget_max' => 25000]),
            'catering' => $this->pref(['budget_max' => 1000]),
        ];

        $plan = VendorFit::plan_split(300000, $shares, $prefs);
        self::assertSame(9, $plan['active']);

        $byCategory = [];
        foreach ($plan['rows'] as $row) {
            $byCategory[$row['category']] = $row;
        }

        self::assertNull($byCategory['mc']['amount'], 'a zero share means "not part of this split"');
        self::assertSame('skipped', $byCategory['mc']['status']);
        self::assertFalse($byCategory['mc']['changed'], 'leaving a category out must not rewrite or delete its cap');
        self::assertEqualsWithDelta(25000.0, (float) $byCategory['mc']['current'], 0.01);

        // The money left out of the split is spread over the categories that
        // stayed in, so the whole total still lands.
        self::assertEqualsWithDelta(300000.0, $plan['planned'], 0.01);
        self::assertEqualsWithDelta(325000.0, $plan['resulting'], 0.01, 'the untouched cap still counts towards the total');
    }

    #[Group('integration')]
    public function testPlanSplitLiftsACategoryToTheCouplesOwnFloor(): void
    {
        // They said catering will not cost less than 150,000; a 10% share of
        // 100,000 would be 10,000. Writing that would contradict them — and
        // save() would refuse floor > cap anyway.
        $plan = VendorFit::plan_split(100000, ['catering' => 100], [
            'catering' => $this->pref(['budget_min' => 150000]),
        ]);
        $row = $plan['rows'][0];
        self::assertSame('catering', $row['category']);
        self::assertSame('lifted', $row['status']);
        self::assertEqualsWithDelta(150000.0, (float) $row['amount'], 0.01);
        self::assertSame(1, $plan['lifted']);
        self::assertEqualsWithDelta(-50000.0, $plan['unplaced'], 0.01, 'a floor above the budget must be reported as an overshoot');
    }

    #[Group('integration')]
    public function testPlanSplitDoesNotRewriteACapThatAlreadyMatches(): void
    {
        $plan = VendorFit::plan_split(300000, ['catering' => 100], [
            'catering' => $this->pref(['budget_max' => 300000]),
        ]);
        $row = $plan['rows'][0];
        self::assertSame('same', $row['status']);
        self::assertFalse($row['changed']);
        self::assertEqualsWithDelta(300000.0, (float) $row['amount'], 0.01);
    }

    #[Group('integration')]
    public function testPlanSplitRefusesAnEmptyOrTooSmallTotal(): void
    {
        $empty = VendorFit::plan_split(0, VendorFit::shares());
        self::assertNotSame('', $empty['error']);
        self::assertSame(0.0, $empty['planned']);

        $noShares = VendorFit::plan_split(300000, array_fill_keys(VendorCoverage::slugs(), 0));
        self::assertStringContainsString('at least one category', $noShares['error']);

        $tiny = VendorFit::plan_split(1000, VendorFit::shares());
        self::assertStringContainsString('too small', $tiny['error']);
    }

    #[Group('integration')]
    public function testNormaliseWeightsKeepsOnlyRealPositiveShares(): void
    {
        self::assertSame([
            'catering' => 26.0,
            'music'    => 100.0,
        ], VendorFit::normalise_weights([
            'catering' => '26',
            'decor'    => '0',
            'mc'       => '-5',
            'flowers'  => 'abc',
            'music'    => 250,        // clamped to a whole 100%
            'spaceship' => 10,        // not a vendor category
        ]));
    }

    #[Group('integration')]
    public function testApplySplitWritesCapsAndKeepsEveryTasteAndFloor(): void
    {
        $saved = VendorFit::save(self::$bookingId, 'catering', [
            'taste_tags' => 'rustic, halal',
            'budget_min' => '60000',
            'budget_max' => '100000',
            'notes'      => 'no pork',
        ], 7);
        self::assertTrue($saved['ok'], $saved['error']);

        $result = VendorFit::apply_split(self::$bookingId, 300000, VendorFit::shares(), 7);
        self::assertTrue($result['ok'], $result['error']);
        self::assertGreaterThan(0, $result['written']);

        $prefs = VendorFit::preferences(self::$bookingId);
        $catering = $prefs['catering'];
        self::assertEqualsWithDelta(78000.0, (float) $catering->budget_max, 0.01, 'the cap follows the split');
        self::assertEqualsWithDelta(60000.0, (float) $catering->budget_min, 0.01, 'the floor is the couple\'s, not the splitter\'s');
        self::assertSame('rustic,halal', (string) $catering->taste_tags, 'a split must never erase a taste');
        self::assertSame('no pork', (string) $catering->notes);

        // Every active category now has a cap, and they add up to the total.
        self::assertCount(count(VendorCoverage::slugs()), $prefs, 'one row per category, and no more');
        self::assertEqualsWithDelta(300000.0, VendorFit::allocation(self::$bookingId)['allocated'], 0.01);

        // Applying the same split again is quiet: nothing to change.
        $again = VendorFit::apply_split(self::$bookingId, 300000, VendorFit::shares(), 7);
        self::assertTrue($again['ok'], $again['error']);
        self::assertSame(0, $again['written']);
    }

    #[Group('integration')]
    public function testApplySplitRefusesAnUnknownWeddingAndABadTotal(): void
    {
        $ghost = VendorFit::apply_split(999999, 300000, VendorFit::shares(), 7);
        self::assertFalse($ghost['ok']);
        self::assertStringContainsString('wedding', $ghost['error']);

        $zero = VendorFit::apply_split(self::$bookingId, 0, VendorFit::shares(), 7);
        self::assertFalse($zero['ok']);
        self::assertSame([], VendorFit::preferences(self::$bookingId), 'a refused split must write nothing');
    }

    // ------------------------------------------------------------------
    // 5. Ranking a vendor's services
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testVendorFitPicksTheBestServiceAndCountsTheOverBudgetOnes(): void
    {
        $prefs = ['catering' => $this->pref(['budget_max' => 250000, 'taste_tags' => 'rustic'])];
        $fit = VendorFit::vendor_fit([
            $this->svc(['id' => 1, 'title' => 'Platinum', 'pricing_unit' => 'flat', 'price' => 900000, 'tags' => 'rustic']),
            $this->svc(['id' => 2, 'title' => 'Classic', 'pricing_unit' => 'flat', 'price' => 90000, 'tags' => 'rustic']),
            $this->svc(['id' => 3, 'title' => 'Budget', 'pricing_unit' => 'flat', 'price' => 40000, 'tags' => 'rustic']),
        ], $prefs, 100, 'catering');

        self::assertSame('fits', $fit['state']);
        self::assertSame(3, $fit['service_id'], 'the cheapest fitting service is the one named');
        self::assertSame(1, $fit['over'], 'the over-budget service is counted, not hidden');
        self::assertSame(3, $fit['services']);
    }

    #[Group('integration')]
    public function testAServiceIsJudgedAgainstItsOwnCategoryBudget(): void
    {
        $prefs = [
            'catering'  => $this->pref(['budget_max' => 250000]),
            'transport' => $this->pref(['budget_max' => 20000]),
        ];
        // A caterer who also sells transport: this service belongs to transport,
        // so it is over the transport cap even though catering has room.
        $fit = VendorFit::vendor_fit([
            $this->svc(['id' => 7, 'title' => 'Guest shuttle', 'pricing_unit' => 'flat', 'price' => 45000, 'catalog_category' => 'transport']),
        ], $prefs, 100, 'catering');

        self::assertSame('over', $fit['state']);
        self::assertSame(1, $fit['over']);
    }

    #[Group('integration')]
    public function testVendorWithoutServicesOrPreferenceSaysNothing(): void
    {
        $empty = VendorFit::vendor_fit([], [], 100, 'catering');
        self::assertSame('unset', $empty['state']);
        self::assertSame(0, $empty['services']);

        $unjudged = VendorFit::vendor_fit([$this->svc(['price' => 50000])], [], 100, 'catering');
        self::assertSame('unset', $unjudged['state'], 'no stated preference means no badge');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A service row as the model sees it, without a database. */
    private function svc(array $overrides): object
    {
        return (object) array_merge([
            'id'              => 1,
            'title'           => 'Probe service',
            'pricing_unit'    => 'flat',
            'price'           => '1000',
            'min_qty'         => null,
            'max_qty'         => null,
            'tags'            => '',
            'includes'        => '',
            'catalog_category' => null,
        ], $overrides);
    }

    /** A saved-shape preference row, without a database. */
    private function pref(array $overrides): object
    {
        return (object) array_merge([
            'id'               => 1,
            'booking_id'       => self::$bookingId,
            'service_category' => 'catering',
            'taste_tags'       => '',
            'budget_min'       => null,
            'budget_max'       => null,
            'notes'            => '',
        ], $overrides);
    }
}
