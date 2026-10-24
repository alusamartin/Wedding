<?php
declare(strict_types=1);

/**
 * VendorAvailability — is this vendor already committed to another wedding on
 * the day this deal claims?
 *
 * WHY THIS EXISTS
 * `tblvendor_bookings` records what was agreed, not whether the vendor can
 * physically be there. The same caterer, photographer or MC could be confirmed
 * for two weddings on one Saturday, and every screen would read as booked: the
 * coverage board showed green cells, the couple was SMS'd "confirmed", and the
 * collision surfaced on the morning of the wedding — if at all. This is the
 * check that asks the vendor's OTHER rows the question the row in front of you
 * cannot: what else is this vendor doing that day?
 *
 * WHICH DAY A DEAL OCCUPIES
 * A deal states its own date when one was typed (tblvendor_bookings.event_date,
 * required on the staff marketplace form and offered to couples). When it does
 * not, the date is the FUNCTION's, because a vendor booked for the reception
 * works on the reception's day — the same per-function reading HeadcountBrief
 * and PackageCapacity use for the `features_id` scope. Only with neither is the
 * wedding's own date used, which is what a whole-wedding deal means. Dates are
 * compared as calendar days, not instants: two functions on one day are a
 * collision even hours apart, and that is deliberate — the planner is the one
 * who knows whether the vendor has a second team, and the flag is what lets
 * them ask. A deal whose date cannot be read at all is treated as unknown and
 * never produces a clash; guessing a date would be worse than silence.
 *
 * WHAT COUNTS AS A COMMITMENT
 * confirmed or completed, in another wedding. A pending enquiry is not an
 * assignment (the same rule the coverage board and the headcount brief use),
 * a cancelled one is history, and the deal being judged is obviously not its
 * own clash. Two deals for the same vendor on the SAME wedding are not a clash
 * either: a caterer booked for both the Ruracio and the reception is one
 * wedding's supplier working one day, not a double-booking.
 *
 * WHERE IT IS ASKED, AND HOW HARD IT PUSHES
 * The flag belongs where a deal is created or accepted, not on a report nobody
 * opens before the wedding:
 *
 *   admin/client_vendor_enquiry.php  the couple's enquiry — warned on the
 *                                    confirmation and in the planner's bell; the
 *                                    enquiry is still recorded (a couple's
 *                                    request is data, and staff negotiate)
 *   admin/vendors.php                the planner's marketplace inquiry — the
 *                                    warning is written into the deal's notes so
 *                                    it survives the toast
 *   admin/vendor_enquiries.php       the planner's CONFIRM — the one gate: a
 *                                    clash must be acknowledged with a tick, and
 *                                    the acknowledgement (with the other wedding)
 *                                    is recorded in the notes trail
 *   admin/vendor_home.php            the vendor accepting an enquiry — flagged
 *                                    on the page and to the planner, never
 *                                    blocked: the vendor knows their own capacity
 *                                    (a studio with two photographers can cover
 *                                    two weddings) and this platform should not
 *                                    pretend to know better
 *
 * READ-ONLY. Nothing in this class writes; the note and the tick live on the
 * pages that own those transitions.
 */
final class VendorAvailability
{
    /** A deal in one of these states means the vendor is already committed. */
    public const COMMITTED_STATUSES = ['confirmed', 'completed'];

    /**
     * The calendar day a commitment occupies, as Y-m-d; '' when none can be read.
     *
     * Precedence is deliberate — the deal's own date, then the function's, then
     * the wedding's — because each is a more specific statement than the next.
     *
     * @param string|null $event_date     tblvendor_bookings.event_date
     * @param string|null $function_start events.start for the deal's features_id
     * @param string|null $wedding_date   tblweddingbook.wedding_date (varchar)
     */
    public static function day(?string $event_date, ?string $function_start = null, ?string $wedding_date = null): string
    {
        foreach ([$event_date, $function_start, $wedding_date] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || str_starts_with($candidate, '0000-00-00')) {
                continue;
            }
            $ts = strtotime($candidate);
            if ($ts === false) {
                continue;
            }
            return date('Y-m-d', $ts);
        }
        return '';
    }

    /**
     * The day one stored deal occupies — the same precedence as day(), resolved
     * from the deal itself (its own date, or its function's, or its wedding's).
     *
     * Used by the pages that have a deal id and nothing else in hand (the
     * planner's confirm gate, the vendor's accept), so the resolution rule is
     * asked in one place rather than re-typed beside each form.
     */
    public static function deal_day(int $deal_id): string
    {
        if ($deal_id <= 0) {
            return '';
        }
        global $db;
        $row = $db->fetchOne(
            'SELECT vb.event_date, b.wedding_date, e.start AS function_start
               FROM tblvendor_bookings vb
               JOIN tblweddingbook b ON b.booking_id = vb.booking_id
          LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
              WHERE vb.id = :id LIMIT 1',
            [':id' => $deal_id]
        );
        if (!$row) {
            return '';
        }
        return self::day($row->event_date ?? null, $row->function_start ?? null, $row->wedding_date ?? null);
    }

    /**
     * Every committed deal of these vendors, indexed for a same-day lookup.
     *
     * One query for the whole page: the ledger lists every deal in the system
     * and the vendor dashboard lists every deal a vendor owns, so a query per
     * row is exactly the shape that must not be written here.
     *
     * @param array<int|string, mixed> $vendor_ids
     * @return array<int, array<string, object[]>> vendor_id => Y-m-d => rows
     *         (rows carry deal_id, booking_id, status, day, couple, function_title)
     */
    public static function commitments(array $vendor_ids): array
    {
        $scope = bound_id_list($vendor_ids);
        if ($scope['ids'] === []) {
            return [];
        }

        global $db;
        $rows = $db->execute(
            "SELECT vb.id AS deal_id, vb.vendor_id, vb.booking_id, vb.status,
                    vb.event_date, b.bride, b.groom, b.wedding_date,
                    e.title AS function_title, e.start AS function_start
               FROM tblvendor_bookings vb
               JOIN tblweddingbook b ON b.booking_id = vb.booking_id
          LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
              WHERE vb.status IN ('confirmed', 'completed')
                AND vb.vendor_id IN ({$scope['sql']})
           ORDER BY vb.id",
            $scope['params']
        )->fetchAll(PDO::FETCH_OBJ);

        $out = [];
        foreach ($rows as $row) {
            $day = self::day($row->event_date ?? null, $row->function_start ?? null, $row->wedding_date ?? null);
            if ($day === '') {
                continue; // a deal nobody can place in time cannot collide with anything
            }
            $couple = trim(trim((string) ($row->bride ?? '')) . ' & ' . trim((string) ($row->groom ?? '')), ' &');
            $out[(int) $row->vendor_id][$day][] = (object) [
                'deal_id'        => (int) $row->deal_id,
                'booking_id'     => (int) $row->booking_id,
                'status'         => (string) $row->status,
                'day'            => $day,
                'couple'         => $couple !== '' ? $couple : 'another wedding',
                'function_title' => trim((string) ($row->function_title ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Drop the rows that belong to the deal being judged.
     *
     * Both ids are needed: a wedding is excluded as a whole (two deals on one
     * wedding are not a clash), and the deal itself is excluded even when the
     * caller is looking at it from the other side.
     *
     * @param object[] $rows
     * @return object[]
     */
    public static function excluding(array $rows, int $booking_id, int $deal_id = 0): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if ((int) $row->booking_id === $booking_id) {
                continue;
            }
            if ($deal_id > 0 && (int) $row->deal_id === $deal_id) {
                continue;
            }
            $kept[] = $row;
        }
        return $kept;
    }

    /**
     * The other weddings this vendor is committed to on one day.
     *
     * @return object[] rows as commitments() returns them, or [] when the day is free
     */
    public static function clashes(int $vendor_id, string $day, int $ignore_booking_id = 0, int $ignore_deal_id = 0): array
    {
        if ($vendor_id <= 0 || $day === '') {
            return [];
        }
        $all = self::commitments([$vendor_id]);
        return self::excluding($all[$vendor_id][$day] ?? [], $ignore_booking_id, $ignore_deal_id);
    }

    /** One clash per wedding — a vendor with two deals at one wedding collided once. */
    private static function per_wedding(array $clashes): array
    {
        $out = [];
        foreach ($clashes as $clash) {
            $out[(int) $clash->booking_id] ??= $clash;
        }
        return array_values($out);
    }

    /** "Sat 19 Dec 2026", spelled the same way the rest of the back office does. */
    public static function day_label(string $day): string
    {
        $ts = strtotime($day);
        return $ts === false ? $day : date('D j M Y', $ts);
    }

    /**
     * The sentence a page prints when the vendor is already spoken for.
     *
     * @param object[] $clashes rows from clashes()
     */
    public static function notice(array $clashes, string $vendor_name = ''): string
    {
        $clashes = self::per_wedding($clashes);
        if ($clashes === []) {
            return '';
        }

        $first  = $clashes[0];
        $who    = $vendor_name !== '' ? $vendor_name : 'This vendor';
        $where  = self::day_label($first->day)
            . ($first->function_title !== '' ? ' (' . $first->function_title . ')' : '');
        $verb   = $first->status === 'completed' ? 'already delivered for' : 'already confirmed for';
        $text   = $who . ' is ' . $verb . ' ' . $first->couple . ' on ' . $where . '.';

        $others = count($clashes) - 1;
        if ($others === 1) {
            $text .= ' They are also committed to one more wedding that day.';
        } elseif ($others > 1) {
            $text .= ' They are also committed to ' . $others . ' more weddings that day.';
        }
        return $text;
    }

    /**
     * The vendor's own copy, read on their dashboard where "this vendor" is the
     * person reading: "You are also booked for Linda & Brian on Sat 19 Dec 2026."
     *
     * @param object[] $clashes rows from clashes()
     */
    public static function self_notice(array $clashes): string
    {
        $clashes = self::per_wedding($clashes);
        if ($clashes === []) {
            return '';
        }
        $first  = $clashes[0];
        $text   = 'You are also booked for ' . $first->couple . ' on ' . self::day_label($first->day)
            . ($first->function_title !== '' ? ' (' . $first->function_title . ')' : '') . '.';
        $others = count($clashes) - 1;
        if ($others === 1) {
            $text .= ' One more wedding that day.';
        } elseif ($others > 1) {
            $text .= ' ' . $others . ' more weddings that day.';
        }
        return $text;
    }

    /**
     * The short version for a bell or a notes line, where 255 characters is the
     * whole column. Deliberately leads with the fact.
     *
     * @param object[] $clashes rows from clashes()
     */
    public static function alert(array $clashes): string
    {
        $clashes = self::per_wedding($clashes);
        if ($clashes === []) {
            return '';
        }
        $first = $clashes[0];
        $text  = 'Already committed to ' . $first->couple . ' on ' . self::day_label($first->day)
            . ($first->function_title !== '' ? ' (' . $first->function_title . ')' : '') . '.';
        $others = count($clashes) - 1;
        if ($others > 0) {
            $text .= ' Also ' . ($others === 1 ? 'one more wedding' : $others . ' more weddings') . ' that day.';
        }
        return $text;
    }
}
