<?php
declare(strict_types=1);

/**
 * VendorCoverage — which vendor services each wedding function actually needs,
 * and whether the wedding has one.
 *
 * THE QUESTION THIS ANSWERS
 * A planner's supplier work is not "which vendors exist" (the marketplace) and
 * not "what did we agree" (Vendor Enquiries); it is "what is still missing for
 * THIS wedding". The board in admin/vendor_coverage.php draws that as a matrix:
 * one row per calendar function, one column per vendor category, and in every
 * cell the deal (or the hole). This class owns the two halves that would
 * otherwise be page-local: the category vocabulary, and the per-function
 * statement of what the function needs.
 *
 * REQUIREMENTS ARE INTENT, NOT AGREEMENTS
 * `tblvendor_bookings` is the only record of what was sold; nothing here writes
 * to it. A function's required list is stored in `tblevent_vendor_needs`
 * (migrations/v2.24_event_vendor_needs.sql), one row per (wedding, function):
 *
 *   no row          the planner has not edited this function yet — the board
 *                   starts it from ALL TEN categories, so an existing wedding
 *                   does not lose half its matrix on upgrade and a hole is
 *                   never hidden by an empty default
 *   stored list     exactly the categories marked required, in canonical order
 *   stored ''       "this function needs no vendor service" — deliberately
 *                   distinct from no row, so unticking everything is a fact
 *                   the board remembers
 *
 * `event_id` 0 stands for the wedding as a whole, the row the board renders when
 * a wedding has no calendar functions yet. Requirements are per function, not
 * per deal and not per vendor: a caterer scoped to the reception covers the
 * reception's catering requirement, and a whole-wedding deal covers every row
 * (the same `features_id` semantic HeadcountBrief and PackageCapacity use).
 *
 * A GET NEVER WRITES. The only writer is save(), called from an explicit POST
 * with the session's CSRF token by the page's required-services editor.
 *
 * DEGRADING WHEN THE MIGRATION IS ABSENT
 * available() probes the table once per request. A database that has not run
 * v2.24 still renders the board — every category counts as required, exactly as
 * it did before this feature existed — and the page says why. Nothing here
 * throws.
 */
final class VendorCoverage
{
    /**
     * The ten values `tblvendors.category` carries, in the order the board
     * draws them: slug => [human label, Material Design icon suffix].
     *
     * @var array<string, array{0:string, 1:string}>
     */
    public const CATEGORIES = [
        'catering'    => ['Catering', 'food'],
        'decor'       => ['Decor', 'flower'],
        'photography' => ['Photography', 'camera'],
        'music'       => ['Music', 'music'],
        'transport'   => ['Transport', 'bus'],
        'mc'          => ['MC', 'microphone'],
        'venue'       => ['Venue', 'home'],
        'cakes'       => ['Cakes', 'cake'],
        'flowers'     => ['Flowers', 'flower-tulip'],
        'other'       => ['Other', 'briefcase'],
    ];

    /** @var array<string, array{0:string, 1:string}> */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /** @return string[] Every category slug, in board order. */
    public static function slugs(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /** Is migrations/v2.24_event_vendor_needs.sql installed? (One probe per request.) */
    public static function available(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        global $db;
        try {
            $db->fetchValue('SELECT COUNT(*) FROM tblevent_vendor_needs');
            return $available = true;
        } catch (Throwable $e) {
            return $available = false;
        }
    }

    /**
     * Every stored requirement row for one wedding.
     *
     * @return array<int, string[]> event_id (0 = whole wedding) => required slugs
     */
    public static function stored(int $booking_id): array
    {
        if ($booking_id <= 0 || !self::available()) {
            return [];
        }

        global $db;
        $rows = $db->fetchAll(
            'SELECT event_id, categories FROM tblevent_vendor_needs WHERE booking_id = :b',
            [':b' => $booking_id]
        );

        $stored = [];
        foreach ($rows as $row) {
            $stored[(int) $row->event_id] = self::normalise(explode(',', (string) $row->categories));
        }
        return $stored;
    }

    /**
     * Has the planner ever saved this function's list? False means the board is
     * showing the all-ten starting point, not a decision.
     *
     * @param array<int, string[]>|null $stored VendorCoverage::stored() when the caller already has it
     */
    public static function configured(int $booking_id, int $event_id, ?array $stored = null): bool
    {
        $stored ??= self::stored($booking_id);
        return array_key_exists($event_id, $stored);
    }

    /**
     * The categories this function requires. Never-saved functions start at all
     * ten — see the class comment for why the default is inclusive.
     *
     * @param array<int, string[]>|null $stored VendorCoverage::stored() when the caller already has it
     * @return string[]
     */
    public static function required(int $booking_id, int $event_id, ?array $stored = null): array
    {
        $stored ??= self::stored($booking_id);
        return $stored[$event_id] ?? self::slugs();
    }

    /**
     * Keep only real categories, drop duplicates, and return them in board
     * order — so a stored list round-trips to the same string whatever order
     * the checkboxes were posted in.
     *
     * @param array<int|string, mixed> $slugs
     * @return string[]
     */
    public static function normalise(array $slugs): array
    {
        $wanted = [];
        foreach ($slugs as $slug) {
            $wanted[(string) $slug] = true;
        }
        return array_values(array_filter(
            self::slugs(),
            static fn (string $slug): bool => isset($wanted[$slug])
        ));
    }

    /**
     * Replace one function's list. The only writer in this class; the caller is
     * responsible for the CSRF check, the booking scope and the event's
     * ownership (all three live on the page, next to the form they guard).
     *
     * @param array<int|string, mixed> $slugs
     * @return bool false when the requirements table is not installed
     */
    public static function save(int $booking_id, int $event_id, array $slugs, int $user_id): bool
    {
        if ($booking_id <= 0 || $event_id < 0 || !self::available()) {
            return false;
        }

        global $db;
        $db->execute(
            'INSERT INTO tblevent_vendor_needs (booking_id, event_id, categories, updated_by)
             VALUES (:b, :e, :c, :u)
             ON DUPLICATE KEY UPDATE categories = VALUES(categories), updated_by = VALUES(updated_by)',
            [
                ':b' => $booking_id,
                ':e' => $event_id,
                ':c' => implode(',', self::normalise($slugs)),
                ':u' => $user_id,
            ]
        );
        return true;
    }

    /**
     * How covered one cell is.
     *
     * Confirmed/completed beat pending; a cancelled deal is history, not cover,
     * so it never lifts a cell out of "missing".
     *
     * @param object[] $rows deals in one (function, category) cell
     * @return string booked|pending|missing
     */
    public static function state(array $rows): string
    {
        $pending = false;
        foreach ($rows as $row) {
            if (in_array((string) $row->status, ['confirmed', 'completed'], true)) {
                return 'booked';
            }
            if ((string) $row->status === 'pending') {
                $pending = true;
            }
        }
        return $pending ? 'pending' : 'missing';
    }

    /** Human label for a state, for the screen-reader text inside a cell. */
    public static function state_label(string $state): string
    {
        return match ($state) {
            'booked'  => 'Booked',
            'pending' => 'Pending',
            default   => 'Missing',
        };
    }
}
