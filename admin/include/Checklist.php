<?php

/**
 * Kenyan wedding planning checklist.
 *
 * Each booking gets a standard set of Kenyan milestones (ruracio before the
 * church booking, banns/21-day registrar notice, committee, final headcount…)
 * seeded on first open of the couple's checklist page. Items are scoped to a
 * booking and toggled by the couple (or staff assisting them).
 */
class Checklist extends DB_Object
{
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
        global $db;
        foreach (self::kenyan_template() as $i => $item) {
            $sql = "SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :bid AND title = :title";
            $stmt = $db->prepare($sql);
            $stmt->execute([':bid' => $booking_id, ':title' => $item['title']]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $ins = "INSERT INTO tblchecklist (booking_id, phase, title, due_offset_days, position)
                    VALUES (:bid, :phase, :title, :days, :pos)";
            $stmt = $db->prepare($ins);
            $stmt->execute([
                ':bid'   => $booking_id,
                ':phase' => $item['phase'],
                ':title' => $item['title'],
                ':days'  => $item['days'],
                ':pos'   => $i,
            ]);
        }
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
