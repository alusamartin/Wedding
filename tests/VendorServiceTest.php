<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendor services (admin/include/VendorService.php, v2.27).
 *
 * What is under test, and why each piece earns a test:
 *
 *  1. The vocabulary — the seeded catalogue exists, every row's unit is one the
 *     code knows, slugs are unique, and a retired item is not offered. This is
 *     what keeps "buffet" from becoming three different line items again.
 *  2. The stored shape — custom services keep the same unit/price/quantity
 *     fields as catalogue ones, which is the whole reason a comparison can
 *     include them.
 *  3. Ownership — every mutate re-proves vendor_id in its own WHERE. A vendor
 *     account that guesses another listing's service id must change nothing.
 *  4. The label — "KSh 1,200 / guest (100–250 guests)", because a bare number
 *     is what made packages incomparable in the first place.
 *  5. The deploy kit — the 39 rows a fresh install gets are the same craftsmen
 *     as the migration's.
 *
 * Integration ('integration'): live database, rows tagged with the probe title
 * prefix and deleted in tearDown. No HTTP server — the page that calls this
 * model is covered by VendorSelfServiceTest.
 */
final class VendorServiceTest extends TestCase
{
    private const TAG = '[vstest-service]';

    private static ?Database $db = null;
    private static int $vendorA = 0;
    private static int $vendorB = 0;

    /** @var int[] service ids this test created, removed in tearDown */
    private array $probeServiceIds = [];
    /** @var int[] catalogue rows this test created, removed in tearDown */
    private array $probeCatalogIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        // format_ksh() lives in Helper.php, which the web bootstrap loads for
        // every page. Without it price_line() cannot be asserted on its real
        // output, so the test loads it the same way models.php does.
        require_once __DIR__ . '/../admin/include/Helper.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            global $db;
            $db = self::$db = new Database();

            $ids = $db->fetchAll('SELECT id FROM tblvendors ORDER BY id ASC LIMIT 2');
            if (count($ids) < 2) {
                self::$db = null;
            } else {
                self::$vendorA = (int) $ids[0]->id;
                self::$vendorB = (int) $ids[1]->id;
            }
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live database with two vendors is unavailable');
        }
        if (!VendorService::installed()) {
            self::fail('tblvendor_services is missing — run `composer test:db` (the build imports migrations/v2.27_vendor_services.sql)');
        }
    }

    protected function tearDown(): void
    {
        if (self::$db === null) {
            return;
        }
        foreach ($this->probeServiceIds as $id) {
            try {
                self::$db->execute('DELETE FROM tblvendor_services WHERE id = :id', [':id' => $id]);
            } catch (Throwable $e) {
                // leave it; the next clean run rebuilds the scratch database
            }
        }
        foreach ($this->probeCatalogIds as $id) {
            try {
                self::$db->execute('DELETE FROM tblservice_catalog WHERE id = :id', [':id' => $id]);
            } catch (Throwable $e) {
                // as above
            }
        }
        // A failed assertion can leave a row behind; the tag makes it findable.
        try {
            self::$db->execute('DELETE FROM tblvendor_services WHERE title LIKE :t', [':t' => self::TAG . '%']);
        } catch (Throwable $e) {
            // as above
        }
        $this->probeServiceIds = [];
        $this->probeCatalogIds = [];
    }

    // ------------------------------------------------------------------
    // 1. The vocabulary
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testCatalogueIsSeededWithKnownUnitsAndUniqueSlugs(): void
    {
        $rows = VendorService::catalogue();
        self::assertGreaterThanOrEqual(30, count($rows), 'a usable vocabulary covers every vendor category');

        $slugs      = [];
        $categories = array_keys(VendorCoverage::categories());
        $units      = array_keys(VendorService::units());
        foreach ($rows as $row) {
            $slugs[] = (string) $row->slug;
            self::assertContains((string) $row->default_unit, $units,
                'catalogue row "' . $row->name . '" carries a unit the code cannot label');
            self::assertContains((string) $row->vendor_category, $categories,
                'catalogue row "' . $row->name . '" is filed under a category vendors cannot have');
        }
        self::assertSame(count($slugs), count(array_unique($slugs)), 'catalogue slugs must be unique (the seed upserts on them)');

        // Every vendor category has something to pick on day one.
        foreach ($categories as $category) {
            self::assertNotSame([], VendorService::catalogue($category),
                'category ' . $category . ' has no catalogue service at all');
        }
    }

    #[Group('integration')]
    public function testCatalogueFiltersByCategoryAndSkipsRetiredItems(): void
    {
        $catering = VendorService::catalogue('catering');
        self::assertNotSame([], $catering);
        foreach ($catering as $row) {
            self::assertSame('catering', (string) $row->vendor_category);
        }

        $slug = 'retired-probe-' . bin2hex(random_bytes(4));
        $id = $this->insertCatalogue('other', self::TAG . ' retired probe', $slug, 'flat', 0);
        $slugs = array_map(static fn (object $r): string => (string) $r->slug, VendorService::catalogue('other'));
        self::assertNotContains($slug, $slugs, 'a retired catalogue item must not be offered');

        $slugs = array_map(static fn (object $r): string => (string) $r->slug, VendorService::catalogue('other', true));
        self::assertContains($slug, $slugs, 'the admin view still sees it');

        self::assertNotNull(VendorService::catalogue_item($id), 'a vendor who already sells a retired item keeps it');
    }

    // ------------------------------------------------------------------
    // 2. The stored shape
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testCreateCustomServiceStoresNormalisedValues(): void
    {
        $result = VendorService::create(self::$vendorA, [
            'title'       => '  ' . self::TAG . ' <b>Goat feast</b>  ',
            'unit'        => 'not-a-unit',
            'price'       => '1234.567',
            'min_qty'     => '100',
            'max_qty'     => '250',
            'includes'    => '  chef, serving dishes, cleanup  ',
            'tags'        => 'Rustic, rustic, HALAL, , outdoor ',
            'description' => 'Slow-roasted goat with ugali and greens.',
        ]);
        self::assertTrue($result['ok'], $result['error']);
        $this->probeServiceIds[] = $result['id'];

        $row = VendorService::find_owned($result['id'], self::$vendorA);
        self::assertNotNull($row);
        self::assertSame(self::TAG . ' Goat feast', (string) $row->title, 'tags are stripped, surrounding space trimmed');
        self::assertSame('flat', (string) $row->pricing_unit, 'an unknown unit falls back to the only one that needs no count');
        self::assertEqualsWithDelta(1234.57, (float) $row->price, 0.001, 'price is stored to the cent');
        self::assertSame(100, (int) $row->min_qty);
        self::assertSame(250, (int) $row->max_qty);
        self::assertSame('chef, serving dishes, cleanup', (string) $row->includes);
        self::assertSame('rustic,halal,outdoor', (string) $row->tags, 'tags are lowercased, deduplicated and comma-joined');
        self::assertSame(1, (int) $row->is_active, 'a new service is published unless the form says otherwise');
        self::assertNull($row->catalog_id, 'a custom service is not fake-linked to the vocabulary');
    }

    #[Group('integration')]
    public function testCreateRejectsIncompleteOrContradictoryServices(): void
    {
        $noName = VendorService::create(self::$vendorA, ['title' => '   ', 'unit' => 'flat', 'price' => '100']);
        self::assertFalse($noName['ok']);
        self::assertStringContainsString('name', $noName['error']);

        $noPrice = VendorService::create(self::$vendorA, ['title' => self::TAG . ' no price', 'unit' => 'flat', 'price' => '0']);
        self::assertFalse($noPrice['ok']);
        self::assertStringContainsString('price above zero', $noPrice['error']);

        $backwards = VendorService::create(self::$vendorA, [
            'title' => self::TAG . ' backwards', 'unit' => 'per_guest', 'price' => '100', 'min_qty' => '300', 'max_qty' => '100',
        ]);
        self::assertFalse($backwards['ok']);
        self::assertStringContainsString('minimum quantity', $backwards['error']);

        $ghost = VendorService::create(self::$vendorA, [
            'title' => self::TAG . ' ghost', 'unit' => 'flat', 'price' => '100', 'catalog_id' => '999999',
        ]);
        self::assertFalse($ghost['ok']);
        self::assertStringContainsString('catalogue', $ghost['error']);

        $noVendor = VendorService::create(999999, ['title' => self::TAG . ' orphan', 'unit' => 'flat', 'price' => '100']);
        self::assertFalse($noVendor['ok'], 'a service cannot hang off a listing that does not exist');
    }

    #[Group('integration')]
    public function testCatalogueDerivedServiceKeepsItsVocabularyLineItem(): void
    {
        $item = VendorService::catalogue('catering')[0];
        $result = VendorService::create(self::$vendorA, [
            'catalog_id' => (string) $item->id,
            'title'      => (string) $item->name,
            'unit'       => (string) $item->default_unit,
            'price'      => '1200',
        ]);
        self::assertTrue($result['ok'], $result['error']);
        $this->probeServiceIds[] = $result['id'];

        $row = VendorService::find_owned($result['id'], self::$vendorA);
        self::assertNotNull($row);
        self::assertSame((int) $item->id, (int) $row->catalog_id);
        self::assertSame((string) $item->name, (string) $row->catalog_name, 'the join makes the vocabulary name readable without another query');
        self::assertSame((string) $item->default_unit, (string) $row->pricing_unit, 'the unit comes from the vocabulary, not the vendor');
    }

    // ------------------------------------------------------------------
    // 3. Ownership
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAnotherVendorsServiceIsInvisibleAndImmutable(): void
    {
        $ownId = $this->createService(self::$vendorA, ['price' => '5000', 'unit' => 'flat']);

        self::assertNull(VendorService::find_owned($ownId, self::$vendorB), 'vendor B must not be able to fetch A\'s row');

        $edit = VendorService::update($ownId, self::$vendorB, ['title' => self::TAG . ' hijacked', 'unit' => 'flat', 'price' => '1']);
        self::assertFalse($edit['ok'], 'a foreign edit must be refused');

        self::assertNull(VendorService::toggle($ownId, self::$vendorB), 'a foreign toggle must be refused');
        self::assertFalse(VendorService::remove($ownId, self::$vendorB), 'a foreign delete must be refused');

        $row = VendorService::find_owned($ownId, self::$vendorA);
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->is_active, 'the foreign toggle changed nothing');
        self::assertSame(self::TAG . ' service', (string) $row->title, 'the foreign edit changed nothing');
        self::assertEqualsWithDelta(5000.0, (float) $row->price, 0.01, 'the foreign edit changed no price');
    }

    // ------------------------------------------------------------------
    // 4. Lifecycle and labels
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testToggleHidesFromPublicListsAndDeleteRemovesTheRow(): void
    {
        $id = $this->createService(self::$vendorA, ['price' => '2500', 'unit' => 'per_item']);

        $nowActive = VendorService::toggle($id, self::$vendorA);
        self::assertFalse($nowActive, 'toggling a published service hides it');
        $visible = array_map(static fn (object $r): int => (int) $r->id, VendorService::for_vendor(self::$vendorA));
        self::assertNotContains($id, $visible, 'a hidden service must not appear on a public list');
        self::assertContains(
            $id,
            array_map(static fn (object $r): int => (int) $r->id, VendorService::for_vendor(self::$vendorA, false)),
            'the owner still sees it in My Services'
        );

        self::assertTrue(VendorService::toggle($id, self::$vendorA), 'toggling again republishes it');
        self::assertTrue(VendorService::remove($id, self::$vendorA));
        self::assertNull(VendorService::find_owned($id, self::$vendorA));
    }

    #[Group('integration')]
    public function testPriceLineAlwaysStatesTheUnitAndTheQuantityWindow(): void
    {
        $guest = $this->createService(self::$vendorA, [
            'title' => self::TAG . ' buffet', 'unit' => 'per_guest', 'price' => '1200', 'min_qty' => '100', 'max_qty' => '250',
        ]);
        $window = VendorService::find_owned($guest, self::$vendorA);
        self::assertSame('KSh 1,200 / guest (100–250 guests)', VendorService::price_line($window));

        $flat = $this->createService(self::$vendorA, ['unit' => 'flat', 'price' => '50000']);
        self::assertSame('KSh 50,000 flat', VendorService::price_line(VendorService::find_owned($flat, self::$vendorA)),
            'a flat fee says so — it has no unit to divide by');

        $minimum = $this->createService(self::$vendorA, ['unit' => 'per_guest', 'price' => '800', 'min_qty' => '100']);
        self::assertSame('KSh 800 / guest (min 100 guests)', VendorService::price_line(VendorService::find_owned($minimum, self::$vendorA)));

        $limit = $this->createService(self::$vendorA, ['unit' => 'per_item', 'price' => '250', 'max_qty' => '12']);
        self::assertSame('KSh 250 / item (up to 12 items)', VendorService::price_line(VendorService::find_owned($limit, self::$vendorA)));
    }

    #[Group('integration')]
    public function testForVendorsGroupsByVendorAndExcludesHiddenRows(): void
    {
        $a = $this->createService(self::$vendorA, ['price' => '1000']);
        $b = $this->createService(self::$vendorB, ['price' => '2000']);
        $hidden = $this->createService(self::$vendorB, ['price' => '3000']);
        VendorService::toggle($hidden, self::$vendorB);

        $grouped = VendorService::for_vendors([self::$vendorA, self::$vendorB, 0, self::$vendorA]);
        self::assertArrayHasKey(self::$vendorA, $grouped);
        self::assertArrayHasKey(self::$vendorB, $grouped);
        self::assertNotContains(0, array_keys($grouped), 'an empty id is not a vendor');

        $ids = static fn (array $rows): array => array_map(static fn (object $r): int => (int) $r->id, $rows);
        self::assertContains($a, $ids($grouped[self::$vendorA]));
        self::assertContains($b, $ids($grouped[self::$vendorB]));
        self::assertNotContains($hidden, $ids($grouped[self::$vendorB]), 'a hidden service is not on a directory card');
    }

    #[Group('integration')]
    public function testSummaryReportsPublishedBreadthAndPriceRange(): void
    {
        // Work on a vendor whose whole list this test owns: clear what earlier
        // tests of this run left, then state the only three rows.
        self::$db->execute('DELETE FROM tblvendor_services WHERE vendor_id = :v', [':v' => self::$vendorB]);
        $cheap = $this->createService(self::$vendorB, ['price' => '900']);
        $this->createService(self::$vendorB, ['price' => '7000']);
        VendorService::toggle($cheap, self::$vendorB);

        $summary = VendorService::summary(self::$vendorB);
        self::assertSame(1, $summary['count'], 'only published services count toward the vendor\'s breadth');
        self::assertEqualsWithDelta(7000.0, (float) $summary['min'], 0.01);
        self::assertEqualsWithDelta(7000.0, (float) $summary['max'], 0.01);

        self::assertSame(['count' => 0, 'min' => null, 'max' => null], VendorService::summary(0));
    }

    // ------------------------------------------------------------------
    // 5. The deploy kit
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testDeployKitShipsTheSameCatalogueSeed(): void
    {
        $kit = __DIR__ . '/../deploy/schema.sql';
        if (!is_file($kit)) {
            self::markTestSkipped('deploy/schema.sql is not in this checkout (the deploy kit is gitignored by design)');
        }

        $fromMigration = self::seedPairs((string) file_get_contents(__DIR__ . '/../migrations/v2.27_vendor_services.sql'));
        $fromKit       = self::seedPairs((string) file_get_contents($kit));

        self::assertNotSame([], $fromMigration, 'the migration seed could not be read — update this test with the file');
        self::assertSame($fromMigration, $fromKit,
            'deploy/schema.sql and migrations/v2.27_vendor_services.sql must ship the same service vocabulary');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function createService(int $vendorId, array $overrides = []): int
    {
        $result = VendorService::create($vendorId, array_merge([
            'title' => self::TAG . ' service',
            'unit'  => 'flat',
            'price' => '1000',
        ], $overrides));
        self::assertTrue($result['ok'], $result['error']);
        $this->probeServiceIds[] = $result['id'];
        return $result['id'];
    }

    private function insertCatalogue(string $category, string $name, string $slug, string $unit, int $active): int
    {
        self::$db->execute(
            'INSERT INTO tblservice_catalog (vendor_category, name, slug, default_unit, description, sort_order, is_active)
             VALUES (:c, :n, :s, :u, :d, 999, :a)',
            [':c' => $category, ':n' => $name, ':s' => $slug, ':u' => $unit, ':d' => 'probe', ':a' => $active]
        );
        $id = (int) self::$db->lastInsertId();
        $this->probeCatalogIds[] = $id;
        return $id;
    }

    /**
     * slug => unit pairs out of a file's catalogue INSERT, so the kit and the
     * migration can be compared without executing either.
     *
     * @return array<string, string>
     */
    private static function seedPairs(string $sql): array
    {
        if (!preg_match('/INSERT INTO `tblservice_catalog`.*?;/s', $sql, $m)) {
            return [];
        }
        preg_match_all("/\\('([a-z]+)',\\s*'[^']*',\\s*'([a-z0-9-]+)',\\s*'(per_guest|flat|per_day|per_hour|per_item|per_km|per_staff)'/", $m[0], $rows, PREG_SET_ORDER);
        $pairs = [];
        foreach ($rows as $row) {
            $pairs[$row[2]] = $row[3];
        }
        ksort($pairs);
        return $pairs;
    }
}
