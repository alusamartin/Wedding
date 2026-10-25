<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checklist progress (admin/include/Checklist.php, v2.30) — the calculation the
 * couple's checklist page and their dashboard BOTH render.
 *
 * What is under test, and why each part matters:
 *
 *  1. NOTHING IS GUESSED. Overdue needs a wedding date to be late against: a
 *     wedding with no date has zero overdue items, not all of them (which is
 *     what a `false - offset` comparison used to say). Once the wedding has
 *     passed, nothing is overdue either — the list becomes a keepsake.
 *  2. ONE CALCULATION. total/done/pct/next/overdue come from the same rows in
 *     the same order the page renders, so a dashboard bar cannot disagree with
 *     the page it links to.
 *  3. THE BOOKED VENDORS BECOME TASKS. Checklist::ensure_from_option() writes
 *     one "confirm this vendor" line per proposal item, in its own phase,
 *     three weeks out — and running it again (or with a vendor that no longer
 *     resolves) never duplicates a line.
 *  4. READING IS FREE. progress() writes nothing, so a dashboard render can
 *     never seed, tick or reset a couple's work.
 *
 * Integration ('integration') — the scratch `wpms_test` database. Every row the
 * class writes is tagged by id watermark and deleted in tearDown, so the seeded
 * weddings are left exactly as they were found.
 */
final class ChecklistProgressTest extends TestCase
{
    private const BOOKING = 201;  // a seeded wedding
    private const VENDOR_ID = 200; // an approved vendor with a name

    private static ?Database $db = null;
    private static int $watermark = 0;
    private static string $weddingDate = '';

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
            self::$watermark = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblchecklist');
            self::$weddingDate = (string) $db->fetchValue(
                'SELECT wedding_date FROM tblweddingbook WHERE booking_id = :b',
                [':b' => self::BOOKING]
            );
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null || self::$weddingDate === '') {
            self::markTestSkipped('live database with the seeded wedding is unavailable');
        }
        // Every test starts from the wedding's checklist as the class found it:
        // rows above the watermark can only have been written after setUp ran.
        $this->clearProbeRows();
    }

    protected function tearDown(): void
    {
        $this->clearProbeRows();
    }

    private function clearProbeRows(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            self::$db->execute(
                'DELETE FROM tblchecklist WHERE booking_id = :b AND id > :i',
                [':b' => self::BOOKING, ':i' => self::$watermark]
            );
        } catch (Throwable $e) {
            // the next `composer test:db` rebuilds the scratch database
        }
    }

    // ------------------------------------------------------------------
    // 1 + 2. The calculation
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testProgressCountsDonePctAndTheNextOpenItemInOrder(): void
    {
        $this->seedTemplate();

        $fresh = Checklist::progress(self::BOOKING, self::$weddingDate);
        self::assertSame(count(Checklist::kenyan_template()), $fresh['total']);
        self::assertSame(0, $fresh['done']);
        self::assertSame(0, $fresh['pct']);
        self::assertTrue($fresh['seeded']);
        self::assertNotNull($fresh['next'], 'an untouched checklist still names what comes first');
        self::assertSame(
            (string) Checklist::find_for_booking(self::BOOKING)[0]->title,
            (string) $fresh['next']->title,
            'the next item is the first open one in template order'
        );

        // Tick the first three items the way the couple does.
        $items = Checklist::find_for_booking(self::BOOKING);
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue(Checklist::toggle((int) $items[$i]->id, self::BOOKING));
        }

        $after = Checklist::progress(self::BOOKING, self::$weddingDate);
        self::assertSame(3, $after['done']);
        self::assertSame((int) round(3 / $after['total'] * 100), $after['pct']);
        self::assertSame(
            (string) $items[3]->title,
            (string) $after['next']->title,
            'a ticked item is no longer "up next"'
        );
    }

    #[Group('integration')]
    public function testNoWeddingDateMeansNothingCanBeOverdue(): void
    {
        $this->seedTemplate();

        $undated = Checklist::progress(self::BOOKING, '');
        self::assertSame(0, $undated['overdue'], 'without a date there is no deadline to miss');
        self::assertFalse($undated['wedding_past']);

        // A wedding three weeks away: the 365-day "agree the budget" task is
        // long past its target and still open, so it IS overdue.
        $soon = date('Y-m-d', strtotime('+21 days'));
        $dated = Checklist::progress(self::BOOKING, $soon);
        self::assertGreaterThan(0, $dated['overdue'], 'a dated wedding does have overdue items');
        self::assertSame($dated['overdue'], $this->countOverdueByHand($soon));

        // And nothing nags once the wedding has happened.
        $past = Checklist::progress(self::BOOKING, date('Y-m-d', strtotime('-5 days')));
        self::assertSame(0, $past['overdue']);
        self::assertTrue($past['wedding_past']);
    }

    #[Group('integration')]
    public function testProgressIsReadOnly(): void
    {
        $before = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b',
            [':b' => self::BOOKING]
        );
        Checklist::progress(self::BOOKING, self::$weddingDate);
        Checklist::progress(self::BOOKING, self::$weddingDate);
        self::assertSame(
            $before,
            (int) self::$db->fetchValue(
                'SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b',
                [':b' => self::BOOKING]
            ),
            'reading progress must never seed or change a checklist'
        );
    }

    // ------------------------------------------------------------------
    // 3. The milestones the chosen option adds
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testOneConfirmationTaskPerBookedVendorIsSeededOnce(): void
    {
        $this->seedTemplate();
        $vendor = Vendor::find_by_id(self::VENDOR_ID);
        self::assertNotNull($vendor, 'the fixture vendor must exist');

        $items = [
            (object) ['vendor_id' => self::VENDOR_ID, 'label' => 'Classic buffet × 120 guests'],
            (object) ['vendor_id' => self::VENDOR_ID, 'label' => 'Late-night snacks'],
            (object) ['vendor_id' => 999999, 'label' => 'A vendor that no longer exists'],
        ];
        $written = Checklist::ensure_from_option(self::BOOKING, $items);
        self::assertSame(2, $written, 'two real lines, and the unresolvable vendor is skipped');

        $rows = array_values(array_filter(
            Checklist::find_for_booking(self::BOOKING),
            static fn ($row) => (string) $row->phase === Checklist::VENDOR_PHASE
        ));
        self::assertCount(2, $rows);
        self::assertStringContainsString((string) $vendor->business_name, (string) $rows[0]->title);
        self::assertStringContainsString('Classic buffet', (string) $rows[0]->title);
        self::assertSame(Checklist::VENDOR_CONFIRM_DAYS, (int) $rows[0]->due_offset_days);
        self::assertSame(0, (int) $rows[0]->is_done, 'seeded open, never ticked on the couple\'s behalf');
        $lastTemplatePosition = count(Checklist::kenyan_template()) - 1;
        foreach ($rows as $row) {
            self::assertGreaterThan(
                $lastTemplatePosition,
                (int) $row->position,
                'the vendor lines come after the Kenyan template, not in the middle of it'
            );
        }

        // Re-locking, or the template arriving later, must not duplicate a line.
        self::assertSame(0, Checklist::ensure_from_option(self::BOOKING, $items));
        self::assertCount(2, array_filter(
            Checklist::find_for_booking(self::BOOKING),
            static fn ($row) => (string) $row->phase === Checklist::VENDOR_PHASE
        ));
    }

    // ------------------------------------------------------------------

    /** Seed the Kenyan template (idempotent; the rows are cleaned up after). */
    private function seedTemplate(): void
    {
        Checklist::ensure_seeded(self::BOOKING);
    }

    /** The overdue rule, computed independently, for the assertion above. */
    private function countOverdueByHand(string $weddingDate): int
    {
        $today = strtotime('today');
        $wedding = strtotime($weddingDate);
        $count = 0;
        foreach (Checklist::find_for_booking(self::BOOKING) as $item) {
            if ((int) $item->is_done === 1) {
                continue;
            }
            if ($today > $wedding - ((int) $item->due_offset_days * 86400)) {
                $count++;
            }
        }
        return $count;
    }
}
