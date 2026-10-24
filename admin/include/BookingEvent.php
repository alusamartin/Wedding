<?php
declare(strict_types=1);

/**
 * tbleventbooking — the planned headcount for ONE FUNCTION of ONE wedding.
 *
 * A row is keyed by (booking_id, features_id), where features_id is the id of
 * an `events` row belonging to the same booking. The venue sheet's
 * "Headcount plan per function" table is a brief handed to the venue and the
 * caterers, and the functions on that brief are the ones in the couple's
 * running order — the same `events` rows the sheet's timeline prints.
 *
 * WHY THIS CLASS CHANGED SHAPE (v2.9)
 * It used to declare $db_table, $db_table_fields and every field as a property
 * WITHOUT extending DB_Object, so it had no create()/update() at all and could
 * never persist anything. The venue sheet printed family/individual counts per
 * function that no role and no code path could write — numbers no role could
 * enter. It is a real active record now, and set_counts() is the single writer,
 * called from admin/event_booking_save.php.
 *
 * WHY THE OLD JOIN WAS WRONG, AND HOW IT IS READ NOW
 * The sheet resolved a row's function by joining tblpostwedding — the public
 * "real weddings" blog — on features_id. With the shipped rows that printed
 * OTHER couples' blog posts, title and location, as this wedding's functions
 * ("Beach Wedding in Diani — Faith & James" as a headcount row). features_id
 * has always been an `events` id: the seeded values line up with the booking's
 * own events, including their per-function package (tbleventbooking.wedding_id
 * 7 = "Church Wedding" for the Church Wedding event, 9 = "Reception Package"
 * for the Reception event). plan_for() therefore joins `events`, scoped to the
 * booking, so a plan row can never borrow another wedding's function.
 *
 * One row per (booking, function) is now a database rule, not a convention:
 * migration v2.9 adds the unique key, and set_counts() writes with ON DUPLICATE
 * KEY UPDATE so a re-save replaces the plan instead of stacking a second one.
 */
class BookingEvent extends DB_Object
{
    protected static $db_table = "tbleventbooking";
    protected static $db_table_fields = array(
        'id',
        'booking_id',
        'wedding_id',
        'features_id',
        'family',
        'individual',
    );

    public $id;
    public $booking_id;
    public $wedding_id;
    public $features_id;
    public $family;
    public $individual;

    /**
     * The headcount plan for one wedding: one row per FUNCTION on the calendar,
     * in running order, with its counts (0 when the function has no plan yet).
     *
     * Built from the function list outwards, not from the plan table inwards:
     * a function nobody has planned for must still appear (so it can be
     * planned), which a plain join on tbleventbooking would silently drop.
     *
     * If duplicate plan rows exist for one function — possible in a database
     * written before v2.9 — the oldest row wins, the same row row_for() and
     * set_counts() work with, so what the sheet prints and what an edit changes
     * are always the same row.
     *
     * @return array<int, object{features_id:int,title:string,location:string,start:string,
     *                           plan_id:int,family:int,individual:int,planned:bool}>
     */
    public static function plan_for(int $booking_id): array
    {
        global $db;

        $functions = $db->fetchAll(
            'SELECT id, title, location, start FROM events WHERE booking_id = :bid ORDER BY start, id',
            [':bid' => $booking_id]
        );
        $plans = $db->fetchAll(
            'SELECT id, features_id, family, individual FROM tbleventbooking WHERE booking_id = :bid ORDER BY id',
            [':bid' => $booking_id]
        );

        $byFunction = [];
        foreach ($plans as $p) {
            $fid = (int) $p->features_id;
            if (!isset($byFunction[$fid])) {
                $byFunction[$fid] = $p;
            }
        }

        $out = [];
        foreach ($functions as $f) {
            $p = $byFunction[(int) $f->id] ?? null;
            $out[] = (object) [
                'features_id' => (int) $f->id,
                'title'       => (string) $f->title,
                'location'    => (string) ($f->location ?? ''),
                'start'       => (string) ($f->start ?? ''),
                'plan_id'     => $p !== null ? (int) $p->id : 0,
                'family'      => $p !== null ? (int) $p->family : 0,
                'individual'  => $p !== null ? (int) $p->individual : 0,
                'planned'     => $p !== null,
            ];
        }
        return $out;
    }

    /** The plan row for one function of one wedding (null when it has none). */
    public static function row_for(int $booking_id, int $features_id): ?object
    {
        global $db;
        $row = $db->fetchOne(
            'SELECT id, booking_id, wedding_id, features_id, family, individual
               FROM tbleventbooking
              WHERE booking_id = :bid AND features_id = :fid
           ORDER BY id LIMIT 1',
            [':bid' => $booking_id, ':fid' => $features_id]
        );
        return $row ?: null;
    }

    /**
     * Is this an actual function of this wedding?
     *
     * The gate that keeps a plan row from being attached to another couple's
     * function: without it, a crafted POST could write counts against any event
     * id in the database, and no page would ever show or correct them.
     */
    public static function function_belongs_to(int $booking_id, int $features_id): bool
    {
        global $db;
        return (int) $db->fetchValue(
            'SELECT COUNT(*) FROM events WHERE id = :id AND booking_id = :bid',
            [':id' => $features_id, ':bid' => $booking_id]
        ) === 1;
    }

    /**
     * Set the planned headcounts for one function. The single writer.
     *
     * $wedding_id is written ONLY when the row is created: it is the PACKAGE the
     * plan sits under — the booking's wedding_type category — and legacy rows
     * carry their own per-function package (the seed's Church Wedding row holds
     * 7, its Reception row 9). The venue sheet prints the booking's package from
     * the booking itself, so this column is informational, and an edit is an edit
     * of the COUNT: re-saving a plan must not silently re-tag rows the user never
     * touched, which is what a form that submits every function would otherwise
     * do on every save.
     *
     * @return string 'created' | 'updated' | 'unchanged' | 'invalid' — 'invalid'
     *                when the function is not one of this booking's own.
     */
    public static function set_counts(int $booking_id, int $wedding_id, int $features_id, int $family, int $individual): string
    {
        global $db;

        if ($booking_id <= 0 || $features_id <= 0 || !self::function_belongs_to($booking_id, $features_id)) {
            return 'invalid';
        }

        $existing = self::row_for($booking_id, $features_id);

        // ON DUPLICATE KEY UPDATE against the v2.9 unique key: a re-save
        // replaces the plan, and two people saving at once still cannot leave
        // two rows behind (a check-then-insert could).
        $db->execute(
            'INSERT INTO tbleventbooking (booking_id, wedding_id, features_id, family, individual)
             VALUES (:bid, :wid, :fid, :family, :individual)
             ON DUPLICATE KEY UPDATE family = VALUES(family),
                                     individual = VALUES(individual)',
            [
                ':bid'        => $booking_id,
                ':wid'        => $wedding_id,
                ':fid'        => $features_id,
                ':family'     => max(0, $family),
                ':individual' => max(0, $individual),
            ]
        );

        if ($existing === null) {
            return 'created';
        }
        return ((int) $existing->family === $family && (int) $existing->individual === $individual)
            ? 'unchanged'
            : 'updated';
    }
}
