<?php

/**
 * Kenyan wedding planning checklist.
 *
 * Each booking gets a standard set of Kenyan milestones (ruracio before the
 * church booking, banns/21-day registrar notice, committee, final headcount…)
 * seeded on first open of the couple's checklist page. Items are scoped to a
 * booking and toggled by the couple (or staff assisting them).
 *
 * Since v2.30 a wedding also gets the milestones that come from what the couple
 * chose: one "confirm this vendor in writing" line per vendor in the locked
 * proposal option (see ensure_from_option()). Those are seeded at LOCK, so the
 * couple's dashboard has real progress the moment the wedding is confirmed,
 * and progress() is the single calculation the checklist page and the
 * dashboard both read.
 */
class Checklist extends DB_Object
{
    /**
     * The phase the milestones a couple's CHOSEN PROPOSAL (v2.30) adds land
     * in. Kept apart from the Kenyan template's own phases so the couple can
     * see which tasks exist because of what they actually booked, rather than
     * because every wedding has them.
     */
    public const VENDOR_PHASE = 'Your Vendors';

    /**
     * When a booked vendor is confirmed in writing: three weeks out.
     *
     * Deliberately the same moment as the template's "Confirm every vendor in
     * writing" line — these tasks ARE that line, split per vendor, because one
     * tick cannot stand for six suppliers, and a couple chasing them needs the
     * supplier's name on the item, not "every vendor".
     */
    public const VENDOR_CONFIRM_DAYS = 21;

    protected static $db_table = "tblchecklist";
    protected static $db_table_fields = array(
        'id', 'booking_id', 'phase', 'title', 'due_offset_days',
        'position', 'is_done', 'done_at', 'created_at'
    );

    public $id;
    public $booking_id = 0;
    public $phase = '';
    public $title = '';
    public $due_offset_days = 0;
    public $position = 0;
    public $is_done = 0;
    public $done_at;
    public $created_at;

    /**
     * The Kenyan milestone template. Offsets are days BEFORE the wedding the
     * task should be complete. Grounded in how Kenyan weddings actually
     * sequence: ruracio/traditional process first, church banns (21 days'
     * written notice + three Sundays), committee/harambee logistics, then the
     * final-month detail sprint.
     *
     * @return array<int, array{phase:string, title:string, days:int}>
     */
    public static function kenyan_template(): array
    {
        return [
            // Foundation — 12+ months out
            ['phase' => 'Foundation', 'title' => 'Agree the working budget with both families (savings + harambee expectation)', 'days' => 365],
            ['phase' => 'Foundation', 'title' => 'Set the target wedding date (avoid peak-season price spikes in Aug, Nov, Dec)', 'days' => 360],
            ['phase' => 'Foundation', 'title' => 'Draft the guest list — start with must-invite family and elders', 'days' => 340],
            ['phase' => 'Foundation', 'title' => 'Form the wedding committee and hold the first meeting', 'days' => 330],
            ['phase' => 'Foundation', 'title' => 'Open a dedicated wedding M-Pesa/savings account', 'days' => 325],
            ['phase' => 'Foundation', 'title' => 'Begin the traditional process — family introduction visits (kumenya mucii)', 'days' => 320],
            ['phase' => 'Foundation', 'title' => 'Book the venue (popular venues go a year out)', 'days' => 300],

            // Bookings — 9-12 months out
            ['phase' => 'Bookings', 'title' => 'Set the ruracio / dowry ceremony date', 'days' => 270],
            ['phase' => 'Bookings', 'title' => 'Book photographer and videographer (deposit secures the date)', 'days' => 240],
            ['phase' => 'Bookings', 'title' => 'Book the caterer — confirm serving staff, crockery and per-plate terms', 'days' => 230],
            ['phase' => 'Bookings', 'title' => 'Book the MC — good ones go fast, ask your community', 'days' => 220],
            ['phase' => 'Bookings', 'title' => 'Book DJ or live band', 'days' => 210],
            ['phase' => 'Bookings', 'title' => 'Ask and confirm the bridal party', 'days' => 180],

            // Legal & church — 6-9 months out
            ['phase' => 'Legal & Church', 'title' => 'Hold the ruracio ceremony', 'days' => 180],
            ['phase' => 'Legal & Church', 'title' => 'Give notice at the church (banns read three Sundays) and the Registrar (21-day notice)', 'days' => 150],
            ['phase' => 'Legal & Church', 'title' => 'Register the marriage on eCitizen', 'days' => 140],
            ['phase' => 'Legal & Church', 'title' => 'Start pre-marriage counselling (required by most churches)', 'days' => 120],

            // Guests & details — 3-6 months out
            ['phase' => 'Guests & Details', 'title' => 'Send formal invitations — share each guest\'s RSVP link on WhatsApp', 'days' => 120],
            ['phase' => 'Guests & Details', 'title' => 'Order the wedding rings (allow 3 months for sizing)', 'days' => 90],
            ['phase' => 'Guests & Details', 'title' => 'Hold the kitchen party / bachelorette', 'days' => 45],
            ['phase' => 'Guests & Details', 'title' => 'Lock the final headcount with the caterer', 'days' => 30],

            // Final month
            ['phase' => 'Final Month', 'title' => 'Confirm every vendor in writing — times, setup, balance schedule', 'days' => 21],
            ['phase' => 'Final Month', 'title' => 'Build the seating chart from RSVPs', 'days' => 14],
            ['phase' => 'Final Month', 'title' => 'Final dress fitting', 'days' => 10],
            ['phase' => 'Final Month', 'title' => 'Prepare tip envelopes and the day emergency kit', 'days' => 7],
            ['phase' => 'Final Month', 'title' => 'Share the day-of schedule with the committee and planner', 'days' => 3],
        ];
    }

    /**
     * Seed the standard milestones for a booking on first open. Idempotent:
     * only titles not already present for the booking are inserted, so new
     * template items appear on later visits without touching couple progress.
     */
    public static function ensure_seeded(int $booking_id): void
    {
        if ($booking_id <= 0) {
            return;
        }
        foreach (self::kenyan_template() as $i => $item) {
            self::insert_if_missing($booking_id, $item['phase'], $item['title'], $item['days'], $i);
        }
    }

    /**
     * Seed the milestones that come from what the couple actually chose.
     *
     * A proposal option is a list of vendors doing specific jobs; once it is
     * locked those jobs are the wedding's supply chain, and each one needs its
     * own "confirm this in writing" task — times, setup, balance. Seeding them
     * here (rather than on first open of the checklist) means the couple's
     * dashboard shows real progress from the moment their wedding is confirmed.
     *
     * Idempotent by title, exactly like the template: locking twice, or the
     * template arriving later, never duplicates a line and never touches what
     * the couple has already ticked.
     *
     * @param object[] $items proposal items (tblproposal_items rows: vendor_id, label)
     * @return int how many rows were actually written
     */
    public static function ensure_from_option(int $booking_id, array $items): int
    {
        if ($booking_id <= 0 || $items === []) {
            return 0;
        }
        global $db;
        $position = (int) $db->fetchValue(
            'SELECT COALESCE(MAX(position), -1) FROM tblchecklist WHERE booking_id = :bid',
            [':bid' => $booking_id]
        );
        $written = 0;
        foreach ($items as $item) {
            $vendor_id = (int) ($item->vendor_id ?? 0);
            if ($vendor_id <= 0) {
                continue;
            }
            $vendor = Vendor::find_by_id($vendor_id);
            $name   = $vendor ? trim((string) $vendor->business_name) : '';
            if ($name === '') {
                continue; // a vendor row that no longer resolves teaches the couple nothing
            }
            $label = trim((string) ($item->label ?? ''));
            $title = 'Confirm ' . $name . ($label !== '' ? ' — ' . $label : '');
            $written += self::insert_if_missing(
                $booking_id,
                self::VENDOR_PHASE,
                mb_substr($title, 0, 190),
                self::VENDOR_CONFIRM_DAYS,
                ++$position
            );
        }
        return $written;
    }

    /**
     * Progress for one wedding, in ONE place, so the checklist page and the
     * couple's dashboard cannot disagree about the same checklist.
     *
     * Reads only — seeding is the checklist page's job (and the lock's), never
     * a dashboard render's. Overdue needs a wedding date to be overdue against:
     * with no date, nothing is late ("we cannot tell", not "everything is").
     * Once the wedding has passed the nagging stops; the list becomes a
     * keepsake.
     *
     * @return array{total:int, done:int, overdue:int, pct:int, next:?object, wedding_past:bool, seeded:bool}
     */
    public static function progress(int $booking_id, ?string $wedding_date = null): array
    {
        $items = $booking_id > 0 ? self::find_for_booking($booking_id) : [];
        $total = count($items);
        $done  = 0;
        $next  = null;
        foreach ($items as $item) {
            if ((int) $item->is_done === 1) {
                $done++;
                continue;
            }
            if ($next === null) {
                $next = $item;
            }
        }

        $today   = strtotime('today');
        $wedding = ($wedding_date !== null && trim($wedding_date) !== '') ? strtotime($wedding_date) : false;
        $past    = $wedding !== false && $today > $wedding;

        $overdue = 0;
        if ($wedding !== false && !$past) {
            foreach ($items as $item) {
                if ((int) $item->is_done === 1) {
                    continue;
                }
                if ($today > $wedding - ((int) $item->due_offset_days * 86400)) {
                    $overdue++;
                }
            }
        }

        return [
            'total'        => $total,
            'done'         => $done,
            'overdue'      => $overdue,
            'pct'          => $total > 0 ? (int) round($done / $total * 100) : 0,
            'next'         => $next,
            'wedding_past' => $past,
            'seeded'       => $total > 0,
        ];
    }

    /**
     * One row, only if the booking does not already have a task with that
     * title. The check-then-insert is what makes both seeders idempotent; the
     * title is the identity because the table has no natural key and a
     * UNIQUE(booking_id, title) would refuse a couple's own wording later.
     *
     * @return int 1 when the row was written, 0 when it was already there
     */
    private static function insert_if_missing(int $booking_id, string $phase, string $title, int $days, int $position): int
    {
        global $db;
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :bid AND title = :title');
        $stmt->execute([':bid' => $booking_id, ':title' => $title]);
        if ((int) $stmt->fetchColumn() > 0) {
            return 0;
        }
        $stmt = $db->prepare(
            'INSERT INTO tblchecklist (booking_id, phase, title, due_offset_days, position)
             VALUES (:bid, :phase, :title, :days, :pos)'
        );
        $stmt->execute([
            ':bid'   => $booking_id,
            ':phase' => $phase,
            ':title' => $title,
            ':days'  => $days,
            ':pos'   => $position,
        ]);
        return 1;
    }

    /** All checklist rows for a booking, in template order. */
    public static function find_for_booking(int $booking_id): array
    {
        global $db;
        $sql = "SELECT * FROM tblchecklist WHERE booking_id = :bid ORDER BY position ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => $booking_id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
            $arr[] = $row;
        }
        return $arr;
    }

    /** Toggle one item done/undone. Verifies the item belongs to the booking. */
    public static function toggle(int $item_id, int $booking_id): bool
    {
        global $db;
        $done = (int) (self::find_done($item_id, $booking_id) ? 0 : 1);
        $sql = "UPDATE tblchecklist
                   SET is_done = :done, done_at = " . ($done ? "NOW()" : "NULL") . "
                 WHERE id = :id AND booking_id = :bid";
        $stmt = $db->prepare($sql);
        return $stmt->execute([':done' => $done, ':id' => $item_id, ':bid' => $booking_id]);
    }

    private static function find_done(int $item_id, int $booking_id): ?int
    {
        global $db;
        $stmt = $db->prepare("SELECT is_done FROM tblchecklist WHERE id = :id AND booking_id = :bid");
        $stmt->execute([':id' => $item_id, ':bid' => $booking_id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
