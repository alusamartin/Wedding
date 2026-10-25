<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tiered pricing (admin/include/VendorTier.php, v3.1) — Silver / Gold /
 * Platinum on a service or a package, with what each level includes and leaves
 * out.
 *
 * What is under test, and why each part matters:
 *
 *  1. THREE OR NONE. A two-tier offer cannot be lined up against another
 *     vendor's three, so the save is refused rather than silently stored.
 *  2. SILVER < GOLD < PLATINUM. "Gold" that costs less than "Silver" is how a
 *     couple is talked into paying more for less.
 *  3. THE MIRROR HOLDS. `price` on the service/package row IS the Silver price:
 *     every marketplace card, fit calculation and plan line reads that one
 *     number, so saving tiers must write it — and a later plain edit must not
 *     be able to drift it away from the cheapest level.
 *  4. IT IS THE VENDOR'S OWN OFFER. Pricing someone else's service or package
 *     is refused, and nothing is written.
 *
 * Integration ('integration') — the scratch `wpms_test` database. Each test
 * creates its own service (and package where needed) and deletes it afterwards;
 * the tier rows cascade with it, and the seeded vendors' prices are never
 * touched.
 */
final class VendorTierTest extends TestCase
{
    private static ?Database $db = null;
    private static int $vendorId = 0;
    private static int $otherVendorId = 0;
    private static int $serviceWatermark = 0;
    private static int $packageWatermark = 0;

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

            $approved = $db->fetchAll(
                'SELECT id FROM tblvendors WHERE is_approved = 1 AND user_id > 0 ORDER BY id ASC LIMIT 2'
            );
            if (count($approved) >= 2) {
                self::$vendorId = (int) $approved[0]->id;
                self::$otherVendorId = (int) $approved[1]->id;
            }
            self::$serviceWatermark = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_services');
            self::$packageWatermark = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_packages');
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null || self::$vendorId <= 0) {
            self::markTestSkipped('live database with two approved vendors is unavailable');
        }
        if (!VendorTier::installed()) {
            self::fail('tblvendor_service_tiers is missing — run `composer test:db` (the build imports migrations/v3.1_vendor_service_tiers.sql)');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------
    // 1 + 2. The rules
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testSavingThreeTiersStoresThemAndMakesSilverTheBasePrice(): void
    {
        $service = $this->service(1200.0);

        $result = VendorTier::save_service($service, self::$vendorId, [
            'tier_price'    => ['silver' => '1200', 'gold' => '1,500', 'platinum' => '1900'],
            'tier_includes' => ['silver' => 'Buffet, servers', 'gold' => 'Live grill', 'platinum' => 'Imported crockery'],
            'tier_excludes' => ['silver' => 'Transport', 'gold' => '', 'platinum' => ''],
        ]);
        self::assertTrue($result['ok'], $result['error']);

        $tiers = VendorTier::for_service($service);
        self::assertSame(VendorTier::TIERS, array_keys($tiers), 'cheapest first, always');
        self::assertSame(1500.0, (float) $tiers['gold']->price);
        self::assertSame('Live grill', (string) $tiers['gold']->includes);
        self::assertSame('Transport', (string) $tiers['silver']->excludes);

        self::assertSame(
            1200.0,
            (float) self::$db->fetchValue('SELECT price FROM tblvendor_services WHERE id = :i', [':i' => $service]),
            'the row price IS the Silver price — everything that reads one number depends on it'
        );
        self::assertStringContainsString('Gold', VendorTier::line($tiers));
        self::assertStringContainsString('Platinum', VendorTier::line($tiers));
    }

    #[Group('integration')]
    public function testATwoTierOrOutOfOrderOfferIsRefusedAndNothingIsWritten(): void
    {
        $service = $this->service(1200.0);

        $two = VendorTier::save_service($service, self::$vendorId, [
            'tier_price' => ['silver' => '1200', 'gold' => '1500'],
        ]);
        self::assertFalse($two['ok'], 'two tiers cannot be compared with another vendor\'s three');
        self::assertStringContainsString('all three', $two['error']);

        $flat = VendorTier::save_service($service, self::$vendorId, [
            'tier_price' => ['silver' => '1500', 'gold' => '1500', 'platinum' => '1900'],
        ]);
        self::assertFalse($flat['ok']);
        self::assertStringContainsString('Gold must cost more than Silver', $flat['error']);

        $inverted = VendorTier::save_service($service, self::$vendorId, [
            'tier_price' => ['silver' => '1200', 'gold' => '1900', 'platinum' => '1800'],
        ]);
        self::assertFalse($inverted['ok']);
        self::assertStringContainsString('Platinum must cost more than Gold', $inverted['error']);

        self::assertSame([], VendorTier::for_service($service), 'a refused save writes nothing');
        self::assertSame(
            1200.0,
            (float) self::$db->fetchValue('SELECT price FROM tblvendor_services WHERE id = :i', [':i' => $service])
        );
    }

    #[Group('integration')]
    public function testBlankingEveryPriceClearsTheTiersAndLeavesThePrice(): void
    {
        $service = $this->service(1200.0);
        VendorTier::save_service($service, self::$vendorId, [
            'tier_price' => ['silver' => '1200', 'gold' => '1500', 'platinum' => '1900'],
        ]);

        $cleared = VendorTier::save_service($service, self::$vendorId, [
            'tier_price' => ['silver' => '', 'gold' => '', 'platinum' => ''],
        ]);
        self::assertTrue($cleared['ok'], $cleared['error']);
        self::assertSame([], VendorTier::for_service($service), 'going back to one price is allowed');
        self::assertSame(
            1200.0,
            (float) self::$db->fetchValue('SELECT price FROM tblvendor_services WHERE id = :i', [':i' => $service]),
            'clearing tiers does not delete the price the vendor had'
        );
    }

    // ------------------------------------------------------------------
    // 3. The mirror
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAPlainEditCannotDriftTheBasePriceAwayFromSilver(): void
    {
        $service = $this->service(1200.0);
        VendorTier::save_service($service, self::$vendorId, [
            'tier_price' => ['silver' => '1200', 'gold' => '1500', 'platinum' => '1900'],
        ]);

        // The vendor edits the row's own price field (which every old form has).
        $updated = VendorService::update($service, self::$vendorId, [
            'title' => 'Tier probe buffet',
            'unit'  => 'per_guest',
            'price' => '5000',
        ]);
        self::assertTrue($updated['ok'], $updated['error']);

        VendorTier::sync_base('service', $service);
        self::assertSame(
            1200.0,
            (float) self::$db->fetchValue('SELECT price FROM tblvendor_services WHERE id = :i', [':i' => $service]),
            'Silver wins — the card must keep quoting the cheapest level'
        );
    }

    #[Group('integration')]
    public function testPriceFallsBackToTheBaseWhenTheTierIsUnknown(): void
    {
        $tiers = ['gold' => (object) ['tier' => 'gold', 'price' => 1500.0, 'includes' => '', 'excludes' => '']];
        self::assertSame(1500.0, VendorTier::price($tiers, 'gold', 1200.0));
        self::assertSame(1200.0, VendorTier::price($tiers, 'platinum', 1200.0), 'an unpriced tier is not invented');
        self::assertSame(1200.0, VendorTier::price($tiers, 'nonsense', 1200.0));
        self::assertSame(1200.0, VendorTier::price([], null, 1200.0));
    }

    // ------------------------------------------------------------------
    // 4. Ownership
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAnotherVendorsOfferCannotBePriced(): void
    {
        $service = $this->service(1200.0);

        $refused = VendorTier::save_service($service, self::$otherVendorId, [
            'tier_price' => ['silver' => '1200', 'gold' => '1500', 'platinum' => '1900'],
        ]);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('not yours', $refused['error']);
        self::assertSame([], VendorTier::for_service($service));
    }

    #[Group('integration')]
    public function testPackageTiersAreSavedAndOwnershipIsProved(): void
    {
        $package = $this->package(180000.0);

        $result = VendorTier::save_package($package, self::$vendorId, [
            'tier_price'    => ['silver' => '180000', 'gold' => '240000', 'platinum' => '310000'],
            'tier_includes' => ['silver' => 'Catering + decor', 'gold' => '+ photo booth', 'platinum' => '+ live band'],
        ]);
        self::assertTrue($result['ok'], $result['error']);
        self::assertCount(3, VendorTier::for_package($package));
        self::assertSame(
            180000.0,
            (float) self::$db->fetchValue('SELECT price FROM tblvendor_packages WHERE id = :i', [':i' => $package])
        );

        $refused = VendorTier::save_package($package, self::$otherVendorId, [
            'tier_price' => ['silver' => '1', 'gold' => '2', 'platinum' => '3'],
        ]);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('not yours', $refused['error']);
    }

    // ------------------------------------------------------------------

    private function service(float $price): int
    {
        $result = VendorService::create(self::$vendorId, [
            'title'  => 'Tier probe buffet',
            'unit'   => 'per_guest',
            'price'  => (string) $price,
            'min_qty' => '1',
        ]);
        self::assertTrue($result['ok'], $result['error']);
        return (int) $result['id'];
    }

    private function package(float $price): int
    {
        self::$db->execute(
            'INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
             VALUES (:v, :t, :d, :p, 200, 8, 1)',
            [
                ':v' => self::$vendorId,
                ':t' => 'Tier probe package',
                ':d' => 'Created by VendorTierTest',
                ':p' => $price,
            ]
        );
        return (int) self::$db->lastInsertId();
    }

    private function cleanup(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            self::$db->execute('DELETE FROM tblvendor_services WHERE id > :i', [':i' => self::$serviceWatermark]);
            self::$db->execute('DELETE FROM tblvendor_packages WHERE id > :i', [':i' => self::$packageWatermark]);
        } catch (Throwable $e) {
            // the next `composer test:db` rebuilds the scratch database
        }
    }
}
