<?php
declare(strict_types=1);

/**
 * Does the headcount plan still fit inside what was sold?
 *
 * WHY THIS EXISTS
 * A vendor package carries a guest capacity (tblvendor_packages.capacity — the
 * "up to 250 pax" on a catering package, a venue's seat count, a vehicle's
 * seats), and a deal records the package it was sold on
 * (tblvendor_bookings.package_id). The couple sets the per-function headcounts
 * on the venue sheet (sheet_venue.php → admin/event_booking_save.php). Nothing
 * connected the two: a couple could plan 400 guests for a reception catered by
 * a 250-pax package and every page, bell and brief would agree the day was
 * ready, until the caterer ran out of food. This is the check between them.
 *
 * WHAT IS COMPARED, AND WHY ONE FUNCTION AT A TIME
 * The plan is per function and a package covers what its deal covers
 * (tblvendor_bookings.features_id — NULL means the whole wedding, the same
 * scope the brief uses), so a function is compared against the capacity of the
 * package on each deal that covers it: the count that matters is the crowd that
 * actually gathers, and the sheet's own note says the same guest attends several
 * functions. Adding the day's functions together would invent a crowd larger
 * than the wedding and warn about a limit nobody is near. A whole-wedding deal
 * is therefore judged function by function too.
 *
 * WHERE THE NUMBERS COME FROM
 *   - capacity:  tblvendor_packages.capacity, > 0. NULL/0 is "no limit stated",
 *                and a deal with no package chosen (package_id NULL) states no
 *                limit either — neither is guessed at.
 *   - the deal:  confirmed or completed (an enquiry is not an assignment), the
 *                same rule the headcount brief uses.
 *   - the plan:  family + individual for one function (tbleventbooking), the
 *                number the venue and the caterers work from.
 *
 * WHO IS TOLD lives in include/HeadcountBrief.php, which owns the bell, the
 * column-fitting and the recipient rules; this class owns the FACTS and the
 * wording, so both are testable on their own.
 *
 * THE WAY OUT OF A BREACH — upgrades()
 * A warning that says only "this no longer fits" leaves the planner with the
 * same phone call they would have made anyway, and there was no way to change a
 * deal's package anywhere in the application (a deal's package was fixed when it
 * was recorded), so the vendor would have been warned and nothing could have
 * been done about it. The same vendor's own packages are therefore read for the
 * next one up that covers the crowd — the smallest capacity that fits, cheapest
 * first when two are equal — with what it costs against the package already
 * sold. The planner re-prices the deal onto it in one step (the re-price control
 * on the deal in admin/vendor_enquiries.php). When the vendor has nothing that
 * fits, that is said plainly instead of being left blank: either their biggest
 * package (still short, so the planner knows the ceiling) or "no larger package
 * on file — ask them for a quote", which is the honest answer and the one that
 * turns into a phone call rather than a dead end.
 *
 * THE OTHER DIRECTION — WHEN THE DEAL IS RECORDED (deal_position())
 * Everything above judges the plan against what is already sold, and it runs
 * when the NUMBERS move (the venue sheet's save). The same mismatch begins on
 * the other side of the wedding: a couple picks a 150-pax package for a
 * reception they have planned for 300, staff confirm it, a vendor accepts it,
 * and the first word anyone hears about it is the next headcount save — which
 * may be weeks later, or never. So the check is made at every door where a deal
 * is recorded, and each door states it where the person who opened it is
 * standing: the couple's enquiry (client_vendor_enquiry.php, where the package
 * is chosen but nothing is sold yet, so the copy says "the package you asked X
 * for" — requested_clause()), the staff ledger's confirm (admin/vendor_enquiries.php),
 * the vendor accepting it on their own dashboard (admin/vendor_home.php), and a
 * confirmed deal's headcount scope being widened onto functions it does not
 * cover (the same ledger). deal_position() is the single deal's slice of
 * breaches(), read whatever the deal's STATUS is — the enquiry is exactly the
 * deal that limits() does not count yet, and the question asked there is "would
 * this fit?", not "is this a breach?".
 *
 * BEFORE THE BREACH — headroom(), watch()
 * Everything above is about a limit that has already been passed, and it always
 * speaks after the fact: a save that widens the breach, a deal recorded on a
 * package that cannot hold the crowd. A planner cannot act on those early — the
 * caterer has been warned, the deal is made — so the third question is the one
 * nobody was asking: which weddings are CLOSE? A reception planned for 240
 * against a 250-pax package is not a breach, and it may never become one; it is
 * the list a planner works from a month out, when a bigger package is still a
 * choice rather than a rescue. headroom() answers it for one wedding (the
 * positions within a share of the limit, tightest first, sharing the same
 * limits(), covers() and plan_totals() every other answer is built from, so
 * "close" and "over" can never disagree); watch() turns it into the cross-
 * wedding list a page can print, with the weddings already OVER a limit counted
 * alongside so an empty watch list cannot be read as "nothing to do"; and
 * watch_count() is the same question as a single row, for the navigation badge
 * that makes the list discoverable on every page. Nothing here sends anything:
 * a bell per wobble of a guest count is noise, and the two announced events are
 * the ones above, which are facts rather than forecasts.
 *
 * A SAVE THAT FALLS BACK UNDER THE LIMIT IS NOT ANNOUNCED: a message per
 * wobble of the guest count is noise, and the venue sheet prints the current
 * position (over or not) beside the numbers themselves — which is where a
 * planner looks before they pick up the phone. The same restraint applies at
 * the recording doors: a deal recorded ON a package that covers the crowd says
 * nothing at all (no bell, no clause), and re-saving a scope that has not
 * moved is not a change.
 */
class PackageCapacity
{
    /**
     * How close to a limit counts as close when a caller does not say: a tenth of
     * the package's own capacity, so a 250-pax package is watched from 225 up and
     * a 33-seat matatu from 30. Named here because four methods default to it and
     * the badge, the page and the tests have to be watching the same width.
     */
    private const DEFAULT_SHARE = 0.10;

    /**
     * The width the headroom view watches at when nobody has said otherwise —
     * the one public reading of DEFAULT_SHARE, for callers that must tell "no
     * width chosen" apart from "a width was chosen": the page persists a
     * planner's choice (UserPrefs), and a page that hard-coded its own ten per
     * cent would keep opening at a width this class no longer considers the
     * default.
     */
    public static function default_share(): float
    {
        return self::DEFAULT_SHARE;
    }

    /**
     * The booked packages that state a guest limit, on one wedding.
     *
     * `scope` reads the same way it does for the brief: 'wedding' for a deal
     * booked for the day (features_id NULL), 'function' for one pinned to a
     * function of this booking, 'missing' for one pinned to a function that is
     * no longer on the calendar (it covers nothing, so it can never be
     * outgrown).
     *
     * @return array<int, array{deal_id:int,vendor_id:int,business_name:string,category:string,
     *                          user_id:int,features_id:int,scope:string,function_title:string,
     *                          package_id:int,package_title:string,capacity:int,package_price:float}>
     */
    public static function limits(int $booking_id): array
    {
        global $db;

        if ($booking_id <= 0) {
            return [];
        }

        $rows = $db->fetchAll(
            "SELECT vb.id AS deal_id, vb.features_id,
                    v.id AS vendor_id, v.user_id, v.business_name, v.category,
                    p.id AS package_id, p.title AS package_title, p.capacity, p.price AS package_price,
                    e.id AS function_id, e.title AS function_title
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
               JOIN tblvendor_packages p ON p.id = vb.package_id
          LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
              WHERE vb.booking_id = :b
                AND vb.status IN ('confirmed', 'completed')
                AND p.capacity > 0
           ORDER BY vb.id",
            [':b' => $booking_id]
        );

        $out = [];
        foreach ($rows as $r) {
            $fid = $r->features_id !== null ? (int) $r->features_id : 0;
            $out[] = [
                'deal_id'        => (int) $r->deal_id,
                'vendor_id'      => (int) $r->vendor_id,
                'business_name'  => trim((string) $r->business_name),
                'category'       => (string) $r->category,
                'user_id'        => (int) ($r->user_id ?? 0),
                'features_id'    => $fid,
                'scope'          => $fid === 0 ? 'wedding' : ($r->function_id === null ? 'missing' : 'function'),
                'function_title' => $r->function_title !== null ? trim((string) $r->function_title) : '',
                'package_id'     => (int) $r->package_id,
                'package_title'  => trim((string) $r->package_title),
                'capacity'       => (int) $r->capacity,
                'package_price'  => (float) ($r->package_price ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The functions whose planned headcount no longer fits a package booked on
     * them.
     *
     * @param array<int, array{features_id:int,title?:string,total:int,was?:?int}> $functions
     *        the functions to judge — the ones that just moved (a save), or the
     *        whole plan (the sheet, which prints the position as it stands)
     * @return array<int, array{features_id:int,title:string,total:int,was:?int,
     *                          deals:array<int, array<string,mixed>>}>
     *        one entry per function over a limit, with the deals it is over. A
     *        function planned for 0 is never over anything: it is not planned.
     */
    public static function breaches(int $booking_id, array $functions): array
    {
        return self::judge(self::limits($booking_id), $functions);
    }

    /**
     * ONE deal against the plan, whatever the deal's STATUS is — the same
     * question breaches() answers, asked about a deal that is being recorded
     * rather than about what the plan just did.
     *
     * A deal is recorded (or re-priced, or re-scoped) long before anyone moves a
     * headcount, so the deal is read by id and judged against the plan as it
     * stands: a package too small for a function planned today will be too small
     * on the wedding day, and the planner can fix it while the deal is still
     * being negotiated. `limits()` deliberately counts only confirmed and
     * completed deals, and this does NOT widen that: a pending enquiry is still
     * not an assignment anywhere in the application. What it does is judge the
     * one row the caller has just written, whatever it says, so the person
     * writing it hears "this does not cover the day" at the moment they can still
     * choose differently — and the caller decides whether that is a warning (a
     * confirmed deal) or a note (an enquiry; see requested_clause()).
     *
     * Empty when the deal fits, states no limit (no package, or a package with no
     * capacity), covers nothing on this wedding, or the functions it covers are
     * unplanned. Exactly breaches()'s shape, holding one breach of one deal, so
     * every reader of a breach (the clauses, the way out, the page line) works on
     * it unchanged.
     *
     * @return array<int, array{features_id:int,title:string,total:int,was:?int,
     *                          deals:array<int, array<string,mixed>>}>
     */
    public static function deal_position(int $booking_id, int $deal_id): array
    {
        $deal = self::deal($booking_id, $deal_id);
        if ($deal === null || (int) $deal['capacity'] <= 0) {
            return [];
        }
        return self::judge([$deal], self::plan_totals($booking_id));
    }

    /**
     * The plan as breaches() and deal_position() take it: every function on the
     * wedding's calendar with the crowd it is planned for right now.
     *
     * BookingEvent::plan_for() is the venue sheet's own source, so the numbers a
     * page prints and the numbers a check judges are the same numbers by
     * construction — a function with no plan row is 0 and is over nothing.
     *
     * @return array<int, array{features_id:int,title:string,total:int}>
     */
    public static function plan_totals(int $booking_id): array
    {
        if ($booking_id <= 0) {
            return [];
        }
        $out = [];
        foreach (BookingEvent::plan_for($booking_id) as $fn) {
            $out[] = [
                'features_id' => (int) $fn->features_id,
                'title'       => (string) $fn->title,
                'total'       => (int) $fn->family + (int) $fn->individual,
            ];
        }
        return $out;
    }

    /**
     * ONE deal row in limits()'s shape — and, unlike limits(), whatever its
     * status: the reader is asking about this row, not about what is sold on the
     * wedding. Null when the id is not a deal on this booking, so another
     * wedding's deal can never be judged here (the same cross-wedding guard the
     * headcount save makes).
     *
     * @return array<string,mixed>|null
     */
    public static function deal(int $booking_id, int $deal_id): ?array
    {
        global $db;

        if ($booking_id <= 0 || $deal_id <= 0) {
            return null;
        }

        $row = $db->fetchOne(
            "SELECT vb.id AS deal_id, vb.features_id, vb.status,
                    v.id AS vendor_id, v.user_id, v.business_name, v.category,
                    p.id AS package_id, p.title AS package_title, p.capacity, p.price AS package_price,
                    e.id AS function_id, e.title AS function_title
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
               JOIN tblvendor_packages p ON p.id = vb.package_id
          LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
              WHERE vb.id = :d AND vb.booking_id = :b
              LIMIT 1",
            [':d' => $deal_id, ':b' => $booking_id]
        );
        if (!$row) {
            return null;
        }

        $fid = $row->features_id !== null ? (int) $row->features_id : 0;
        return [
            'deal_id'        => (int) $row->deal_id,
            'vendor_id'      => (int) $row->vendor_id,
            'business_name'  => trim((string) $row->business_name),
            'category'       => (string) $row->category,
            'user_id'        => (int) ($row->user_id ?? 0),
            'status'         => (string) $row->status,
            'features_id'    => $fid,
            'scope'          => $fid === 0 ? 'wedding' : ($row->function_id === null ? 'missing' : 'function'),
            'function_title' => $row->function_title !== null ? trim((string) $row->function_title) : '',
            'package_id'     => (int) $row->package_id,
            'package_title'  => trim((string) $row->package_title),
            'capacity'       => (int) $row->capacity,
            'package_price'  => (float) ($row->package_price ?? 0),
        ];
    }

    /**
     * The positions that are CLOSE to a limit: the crowd a package is nearly out
     * of, without being over it.
     *
     * "Close" is a share of the PACKAGE's own limit rather than a head count, so
     * the answer means the same thing on a 33-seat matatu and a 400-seat hall:
     * the margin (capacity - planned) is within `$share` of the capacity. A
     * function planned for exactly the limit is the tightest position there is and
     * is included; a function planned ABOVE it is not this list's business (it is
     * a breach, announced and printed by breaches()), and neither is an unplanned
     * function, a deal with no package, or a package that states no capacity,
     * because none of them states a limit to run out of.
     *
     * Ordered tightest first — by how much of the limit is used, then by the raw
     * margin — because the first row is the one to act on.
     *
     * @param float $share fraction of a package's capacity that counts as tight
     *                     (0.10 = 250 pax is watched from 225 up)
     * @return array<int, array{deal_id:int,vendor_id:int,business_name:string,category:string,
     *                          user_id:int,package_id:int,package_title:string,capacity:int,
     *                          features_id:int,function_title:string,total:int,margin:int,share:float}>
     */
    public static function headroom(int $booking_id, float $share = self::DEFAULT_SHARE): array
    {
        return self::close_to(self::limits($booking_id), self::plan_totals($booking_id), $share);
    }

    /**
     * The same answer across every wedding that has a package sold on it — the
     * list the headroom page prints (admin/headroom.php).
     *
     * One row per wedding, holding its close positions (tightest first) and the
     * count of positions ALREADY over a limit there. A wedding is listed when it
     * has either, because a page that quietly dropped the over ones would read as
     * "nothing to do here" for a wedding that needs a phone call most; the two
     * numbers stay separate so the reader can tell drift from damage.
     *
     * The candidates are the weddings with at least one confirmed or completed
     * deal on a package that states a capacity — every wedding this check can say
     * anything about — and each of those is then judged against its own plan
     * (one plan read per wedding: the crowd is per function and only
     * tbleventbooking holds it). `$limit` bounds the page, tightest first, so a
     * large office still gets a screen rather than a scroll.
     *
     * @return array<int, array{booking_id:int,couple_user_id:int,couple:string,wedding_date:string,
     *                          organizer_id:int,positions:array<int,array<string,mixed>>,
     *                          count:int,over:int,tightest:array<string,mixed>}>
     */
    public static function watch(float $share = self::DEFAULT_SHARE, int $limit = 100): array
    {
        global $db;

        $share = self::sanitise_share($share);
        $limit = max(1, min(500, $limit));

        $rows = $db->fetchAll(
            "SELECT vb.booking_id, b.user_id AS couple_user_id, b.bride, b.groom, b.wedding_date, b.organizer_id
               FROM tblvendor_bookings vb
               JOIN tblvendor_packages p ON p.id = vb.package_id
               JOIN tblweddingbook b ON b.booking_id = vb.booking_id
              WHERE vb.status IN ('confirmed', 'completed') AND p.capacity > 0
           GROUP BY vb.booking_id, b.user_id, b.bride, b.groom, b.wedding_date, b.organizer_id
           ORDER BY b.wedding_date ASC, vb.booking_id ASC"
        );
        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $booking_id = (int) $row->booking_id;
            $limits     = self::limits($booking_id);
            $plan       = self::plan_totals($booking_id);
            $positions  = self::close_to($limits, $plan, $share);
            $over       = self::judge($limits, $plan);
            if ($positions === [] && $over === []) {
                continue;
            }
            $out[] = [
                'booking_id'    => $booking_id,
                'couple_user_id' => (int) $row->couple_user_id,
                'couple'        => trim(($row->bride ?? '') . ' & ' . ($row->groom ?? ''), ' &'),
                'wedding_date'  => (string) ($row->wedding_date ?? ''),
                'organizer_id'  => (int) ($row->organizer_id ?? 0),
                'positions'     => $positions,
                'count'         => count($positions),
                'over'          => count($over),
                'tightest'      => $positions !== [] ? $positions[0] : null,
            ];
        }

        // Tightest first, then the wedding closest in the calendar.
        usort($out, static function (array $a, array $b): int {
            $left  = [$a['tightest']['share'] ?? 1.0, $a['tightest']['margin'] ?? PHP_INT_MAX];
            $right = [$b['tightest']['share'] ?? 1.0, $b['tightest']['margin'] ?? PHP_INT_MAX];
            return $left <=> $right ?: [$a['wedding_date'], $a['booking_id']] <=> [$b['wedding_date'], $b['booking_id']];
        });

        return array_slice($out, 0, $limit);
    }

    /**
     * How many WEDDINGS have something on this list — one row, one query, for the
     * navigation badge (include/sidebar.php), which is rendered on every staff
     * page and must not walk every wedding to draw a number.
     *
     * The SQL is the same rule as close_to() written for the database rather than
     * in PHP: a deal that is confirmed or completed, on a package with a capacity,
     * covering the plan row being counted (a scope of NULL means the whole wedding;
     * a scope pinned to a function that is gone matches no plan row, which is
     * exactly what covers() says about it), a planned crowd that is neither zero
     * nor over the limit, and a margin inside the share of that package's own
     * capacity. The two must agree — a badge that counts something the page does
     * not list (or misses what it does) is worse than no badge — which is why the
     * `share` and the ceiling both come from this class and not from the caller.
     */
    public static function watch_count(float $share = self::DEFAULT_SHARE): int
    {
        global $db;

        $share = self::sanitise_share($share);
        return (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT vb.booking_id)
               FROM tblvendor_bookings vb
               JOIN tblvendor_packages p ON p.id = vb.package_id
               JOIN tbleventbooking eb ON eb.booking_id = vb.booking_id
                                     AND (vb.features_id IS NULL OR vb.features_id = eb.features_id)
              WHERE vb.status IN ('confirmed', 'completed')
                AND p.capacity > 0
                AND (eb.family + eb.individual) > 0
                AND (eb.family + eb.individual) <= p.capacity
                AND p.capacity - (eb.family + eb.individual) <= CEILING(p.capacity * :share)",
            [':share' => $share]
        );
    }

    /**
     * How many weddings are ALREADY over a booked package's limit — the number the
     * headroom page prints beside its own list, so a reader never mistakes "no
     * drift" for "nothing wrong". The same single-query shape as watch_count(),
     * with the comparison the other way to BOTH sides of the boundary.
     */
    public static function watch_over_count(): int
    {
        global $db;
        return (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT vb.booking_id)
               FROM tblvendor_bookings vb
               JOIN tblvendor_packages p ON p.id = vb.package_id
               JOIN tbleventbooking eb ON eb.booking_id = vb.booking_id
                                     AND (vb.features_id IS NULL OR vb.features_id = eb.features_id)
              WHERE vb.status IN ('confirmed', 'completed')
                AND p.capacity > 0
                AND (eb.family + eb.individual) > 0
                AND (eb.family + eb.individual) > p.capacity"
        );
    }

    /**
     * The share a caller asked for, narrowed to the ones this feature offers
     * (shares()) — anything else is the default. Percentages are a page's unit
     * (`?within=20`); a fraction is the arithmetic's (a margin as a share of the
     * capacity), and this is the one seam between them, so no caller has to do
     * the division twice or guess what 0.1 means.
     */
    public static function sanitise_share(mixed $asked): float
    {
        $value = (float) $asked;
        foreach (self::shares() as $fraction) {
            if (abs($value - $fraction) < 0.0001) {
                return $fraction;
            }
        }
        return self::DEFAULT_SHARE;
    }

    /**
     * The widths a planner may watch at, as PERCENTAGE => fraction. Three, not a
     * free number: five per cent is the day itself, ten is the working list, and
     * twenty is planning ahead — and a filter that accepts anything is a filter
     * nobody can compare a screenshot of.
     *
     * @return array<int,float>
     */
    public static function shares(): array
    {
        return [5 => 0.05, 10 => 0.10, 20 => 0.20];
    }

    /**
     * Which of these functions are over which of these limits — the one loop,
     * shared by the whole-wedding position (breaches) and the single deal being
     * recorded (deal_position), so the two can never disagree about what "over"
     * means.
     *
     * @param array<int, array<string,mixed>> $limits
     * @param array<int, array{features_id:int,title?:string,total:int,was?:?int}> $functions
     * @return array<int, array<string,mixed>>
     */
    private static function judge(array $limits, array $functions): array
    {
        if ($limits === []) {
            return [];
        }

        $out = [];
        foreach ($functions as $fn) {
            $fid   = (int) ($fn['features_id'] ?? 0);
            $total = (int) ($fn['total'] ?? 0);
            if ($fid <= 0 || $total <= 0) {
                continue;
            }

            $deals = [];
            foreach ($limits as $deal) {
                if (!self::covers($deal, $fid) || $total <= $deal['capacity']) {
                    continue;
                }
                $deal['over_by'] = $total - $deal['capacity'];
                $deals[] = $deal;
            }
            if ($deals === []) {
                continue;
            }

            $title = trim((string) ($fn['title'] ?? ''));
            $out[] = [
                'features_id' => $fid,
                'title'       => $title !== '' ? $title : 'A function',
                'total'       => $total,
                'was'         => array_key_exists('was', $fn) && $fn['was'] !== null ? (int) $fn['was'] : null,
                'deals'       => $deals,
            ];
        }
        return $out;
    }

    /**
     * One breach as the PLANNER reads it: which function, planned for how many,
     * over the package whose vendor now has to be called.
     *
     * $with_change says whether the count it moved FROM is quoted. On the sheet
     * it is (the reader is looking at the numbers, and "254 (was 253)" is the
     * fact that explains the warning). In the BELL it is not, and that is a
     * budget decision rather than a taste one: the brief's own line already says
     * "After-Party — family 253 → 254", so the parenthesis is the same fact twice,
     * and tblnotifications.message is varchar(255) — on the shipped seed the
     * eleven characters it costs are exactly what the way out of the breach (the
     * next package up with its price difference, see upgrades()) needs to fit
     * beside the warning instead of being dropped from it. See planner_brief in
     * clauses(): the bell's copy, without it.
     */
    public static function planner_clause(array $breach, bool $with_change = true): string
    {
        $phrases = [];
        foreach (($breach['deals'] ?? []) as $deal) {
            $who = self::vendor_name($deal);
            $phrases[] = 'the package ' . ($who !== '' ? $who . ' is' : 'the vendor is') . ' booked on ('
                . self::package_label($deal) . ')';
        }
        return self::head($breach, $with_change) . ' — over ' . self::join($phrases) . '.';
    }

    /**
     * The same position for a package that has only been REQUESTED: the deal is
     * an enquiry (status 'pending'), nothing is sold, and the reader is the person
     * who picked the package — so the sentence says which side of the deal they
     * are standing on. "the package Nyama Choma Masters is booked on" would be
     * false here (nobody is booked yet) and would read as an accusation of a deal
     * that does not exist; "the package you asked Nyama Choma Masters for" is the
     * same fact told honestly, and it is the moment the choice is still free — the
     * marketplace is one page back, and the vendor has not been told anything yet.
     *
     * Used by the enquiry door in admin/client_vendor_enquiry.php, for the couple
     * who chose the package AND for the planner who has to negotiate it (they see
     * the same clause in their bell). The way out rides with it (way_out()), so
     * the reader is told which of the vendor's packages would cover the crowd
     * rather than left to guess.
     */
    public static function requested_clause(array $breach): string
    {
        $phrases = [];
        foreach (($breach['deals'] ?? []) as $deal) {
            $who = self::vendor_name($deal);
            $phrases[] = 'the package you asked ' . ($who !== '' ? $who . ' for' : 'for')
                . ' (' . self::package_label($deal) . ')';
        }
        return self::head($breach, false) . ' — over ' . self::join($phrases) . '.';
    }

    /**
     * The SHORT form of the requested position, for a message that is about
     * something else: the planner's enquiry bell, which already says the enquiry
     * exists (admin/client_vendor_enquiry.php).
     *
     * One clause — the biggest crowd the package would have to serve, which is the
     * number any bigger package has to cover and therefore the one the planner
     * negotiates against — and no way out. Both cuts are the column's, not taste:
     * HeadcountBrief::append_note() drops a note whole the moment it does not fit,
     * and at the enquiry door the planner's bell is already carrying the couple,
     * the vendor and the package ("Brian & Linda enquired with X (package "Y")…"),
     * so a two-function clause pair plus a suggestion never fits it. The fact
     * rides; the suggestion stays on the page the couple just left and on every
     * later bell, where there is room for it.
     *
     * Empty when nothing is over — the caller then appends nothing at all.
     *
     * @param array<int, array<string,mixed>> $breaches PackageCapacity::deal_position()
     */
    public static function requested_summary(array $breaches): string
    {
        $worst = null;
        foreach ($breaches as $breach) {
            if ($worst === null || (int) ($breach['total'] ?? 0) > (int) ($worst['total'] ?? 0)) {
                $worst = $breach;
            }
        }
        return $worst === null ? '' : self::requested_clause($worst);
    }

    /**
     * The requested position in one line, for the page that records the enquiry:
     * one clause per function the package cannot hold, then the way out.
     *
     * Every breach, not just the first: a request with no scope covers the whole
     * wedding, so a 10-pax package asked for a wedding with a 60-guest ceremony
     * and a 120-guest reception breaches BOTH — and the couple can only choose
     * differently if they are told which of their functions the package falls
     * short of. (The sheet's notice() states them the same way, joined.)
     *
     * No "Over capacity:" lead: nothing is booked yet, so the sentence is about a
     * choice, not a breach.
     *
     * @param array<int, array<string,mixed>> $breaches PackageCapacity::deal_position()
     */
    public static function requested_position(array $breaches): string
    {
        $sentences = [];
        foreach ($breaches as $breach) {
            $sentences[] = self::requested_clause($breach);
        }
        if ($sentences === []) {
            return '';
        }
        $text = implode(' ', $sentences);
        $way  = self::way_out($breaches);
        return $way !== '' ? $text . ' ' . $way : $text;
    }

    /**
     * The same breach as the VENDOR who holds these deals reads it: their own
     * package, and no need to be told their own name.
     *
     * @param array<int, array<string,mixed>> $deals the breaching deals of ONE account
     */
    public static function vendor_clause(array $breach, array $deals): string
    {
        $labels = [];
        foreach ($deals as $deal) {
            $labels[] = self::package_label($deal);
        }
        return self::head($breach) . ' — over the package'
            . (count($deals) === 1 ? '' : 's') . ' you are booked on (' . self::join($labels) . ').';
    }

    /**
     * The way out: for every deal a breach is over, the same vendor's next
     * package up that covers the crowd, and what it costs on top.
     *
     * One entry per DEAL, holding the largest crowd that deal has to serve (a
     * package covers its deal's functions, so when a whole-wedding deal is over
     * on two functions the package has to cover the bigger one). Only the
     * vendor's ACTIVE packages are offered: an inactive one cannot be sold, and
     * the package already on the deal is never re-offered.
     *
     * `fits` is the suggestion; when it is null, `ceiling` says why (the vendor's
     * biggest package, still short) or is null itself (nothing on file at all).
     *
     * @param array<int, array<string,mixed>> $breaches PackageCapacity::breaches()
     * @return array<int, array{deal_id:int,vendor_id:int,business_name:string,required:int,
     *                          sold_capacity:int,
     *                          fits:?array{package_id:int,title:string,capacity:int,price:float,more:float},
     *                          ceiling:?array{package_id:int,title:string,capacity:int,price:float}}>
     */
    public static function upgrades(array $breaches): array
    {
        // The crowd each deal must cover: its biggest breaching function.
        $required = [];
        $deals    = [];
        foreach ($breaches as $breach) {
            $total = (int) ($breach['total'] ?? 0);
            foreach (($breach['deals'] ?? []) as $deal) {
                $id = (int) ($deal['deal_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $deals[$id]    = $deal;
                $required[$id] = max($required[$id] ?? 0, $total);
            }
        }
        if ($deals === []) {
            return [];
        }

        $out = [];
        foreach ($deals as $id => $deal) {
            $need  = (int) $required[$id];
            $sold  = (int) ($deal['package_id'] ?? 0);
            $price = (float) ($deal['package_price'] ?? 0);

            $fits    = null;
            $biggest = null;
            foreach (self::active_packages((int) ($deal['vendor_id'] ?? 0)) as $package) {
                if ((int) $package['package_id'] === $sold) {
                    continue; // never the package that just proved too small
                }
                if ($biggest === null || (int) $package['capacity'] > (int) $biggest['capacity']) {
                    $biggest = $package;
                }
                if ((int) $package['capacity'] < $need) {
                    continue;
                }
                // The smallest capacity that fits; cheapest when two are equal.
                if ($fits === null
                    || (int) $package['capacity'] < (int) $fits['capacity']
                    || ((int) $package['capacity'] === (int) $fits['capacity'] && (float) $package['price'] < (float) $fits['price'])) {
                    $fits = $package;
                }
            }

            $out[$id] = [
                'deal_id'       => $id,
                'vendor_id'     => (int) ($deal['vendor_id'] ?? 0),
                'business_name' => self::vendor_name($deal),
                'required'      => $need,
                // What is on the deal now: without it, "their biggest is X" would
                // name a package smaller than the one already sold as if it were
                // an improvement.
                'sold_capacity' => (int) ($deal['capacity'] ?? 0),
                'fits'          => $fits === null ? null : $fits + [
                    'more'      => round((float) $fits['price'] - $price, 2),
                    'was_price' => $price,
                ],
                'ceiling'       => $biggest,
            ];
        }
        return $out;
    }

    /**
     * One upgrade as a reader sees it — the planner's line in their bell and on
     * the sheet. Facts only, so it is testable without a database.
     *
     * @param array<string,mixed> $upgrade one entry of upgrades()
     */
    public static function upgrade_sentence(array $upgrade): string
    {
        $fits = $upgrade['fits'] ?? null;
        if (is_array($fits)) {
            $money = self::money_clause(
                (float) ($fits['more'] ?? 0),
                (float) ($fits['price'] ?? 0),
                (float) ($fits['was_price'] ?? 0)
            );
            return 'Next package up: ' . self::package_label($fits) . ($money !== '' ? ' — ' . $money : '') . '.';
        }

        $need    = (int) ($upgrade['required'] ?? 0);
        $sold    = (int) ($upgrade['sold_capacity'] ?? 0);
        $who     = trim((string) ($upgrade['business_name'] ?? ''));
        $ceiling = $upgrade['ceiling'] ?? null;

        // A bigger package is only worth naming when it IS bigger: on the shipped
        // seed the caterer's 250-pax package is their largest, so the answer is
        // "nothing bigger on file", not "their biggest is the smaller one".
        if (is_array($ceiling) && (int) ($ceiling['capacity'] ?? 0) > $sold) {
            return 'Nothing of theirs covers ' . $need . ' — their biggest is ' . self::package_label($ceiling) . '.';
        }

        return 'No bigger package on file — ask ' . ($who !== '' ? $who : 'the vendor') . ' for a quote for ' . $need . '.';
    }

    /**
     * The vendor's active packages, cheapest first — the pool a re-price may
     * choose from AND the pool an upgrade is suggested from. Only ever this
     * vendor's: a deal cannot be moved onto another vendor's package.
     *
     * @return array<int, array{package_id:int,title:string,capacity:int,price:float}>
     */
    public static function active_packages(int $vendor_id): array
    {
        global $db;
        if ($vendor_id <= 0) {
            return [];
        }
        $rows = $db->fetchAll(
            'SELECT id, title, capacity, price FROM tblvendor_packages
              WHERE vendor_id = :v AND is_active = 1
           ORDER BY capacity ASC, price ASC, id ASC',
            [':v' => $vendor_id]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'package_id' => (int) $r->id,
                'title'      => trim((string) $r->title),
                'capacity'   => (int) ($r->capacity ?? 0),
                'price'      => (float) ($r->price ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The positions inside a share of their limit, out of limits and a plan that
     * were already read — the loop behind both headroom() (one wedding, read
     * here) and watch() (every wedding, read once each), so the page and the
     * badge can never be computing two different things.
     *
     * @param array<int, array<string,mixed>> $limits  PackageCapacity::limits()
     * @param array<int, array{features_id:int,title:string,total:int}> $plan PackageCapacity::plan_totals()
     * @return array<int, array<string,mixed>> tightest first
     */
    private static function close_to(array $limits, array $plan, float $share): array
    {
        if ($limits === [] || $plan === []) {
            return [];
        }
        $share = self::sanitise_share($share);

        $out = [];
        foreach ($plan as $fn) {
            $fid   = (int) ($fn['features_id'] ?? 0);
            $total = (int) ($fn['total'] ?? 0);
            if ($fid <= 0 || $total <= 0) {
                continue;
            }
            foreach ($limits as $deal) {
                $capacity = (int) ($deal['capacity'] ?? 0);
                // Over the limit is breaches()' answer, not this one; and only the
                // functions this deal covers are its crowd.
                if ($capacity <= 0 || !self::covers($deal, $fid) || $total > $capacity) {
                    continue;
                }
                $margin = $capacity - $total;
                if ($margin > self::allowance($capacity, $share)) {
                    continue;
                }
                $out[] = [
                    'deal_id'        => (int) ($deal['deal_id'] ?? 0),
                    'vendor_id'      => (int) ($deal['vendor_id'] ?? 0),
                    'business_name'  => (string) ($deal['business_name'] ?? ''),
                    'category'       => (string) ($deal['category'] ?? ''),
                    'user_id'        => (int) ($deal['user_id'] ?? 0),
                    'package_id'     => (int) ($deal['package_id'] ?? 0),
                    'package_title'  => (string) ($deal['package_title'] ?? ''),
                    'capacity'       => $capacity,
                    'features_id'    => $fid,
                    'function_title' => trim((string) ($fn['title'] ?? '')),
                    'total'          => $total,
                    'margin'         => $margin,
                    // How much of the limit is still free: the number that means the
                    // same thing on a matatu and on a hall.
                    'share'          => $capacity > 0 ? $margin / $capacity : 1.0,
                ];
            }
        }

        usort($out, static function (array $a, array $b): int {
            return [$a['share'], $a['margin'], $a['features_id'], $a['deal_id']]
                <=> [$b['share'], $b['margin'], $b['features_id'], $b['deal_id']];
        });

        return $out;
    }

    /** How many guests inside a package's own capacity counts as close. */
    private static function allowance(int $capacity, float $share): int
    {
        return max(0, (int) ceil($capacity * self::sanitise_share($share)));
    }

    /**
     * One close position as the headroom page reads it — the drift stated in the
     * same shape as the breach (planner_clause()), because it is the same fact one
     * step earlier: which function, planned for how many, against which package.
     *
     * @param array<string,mixed> $position one entry of headroom()
     */
    public static function headroom_sentence(array $position): string
    {
        $margin = (int) ($position['margin'] ?? 0);
        $who    = self::vendor_name($position);
        $booked = 'the package ' . ($who !== '' ? $who . ' is' : 'the vendor is') . ' booked on ('
            . self::package_label($position) . ')';
        $head   = trim((string) ($position['function_title'] ?? '')) !== ''
            ? trim((string) $position['function_title'])
            : 'A function';
        $head  .= ' is planned for ' . (int) ($position['total'] ?? 0);

        // Exactly full is the tightest position there is, and "within 0 guests of"
        // is not a sentence. The limit is the fact, so it is named.
        if ($margin === 0) {
            return $head . ' — exactly the limit of ' . $booked . '.';
        }
        return $head . ' — within ' . $margin . ($margin === 1 ? ' guest' : ' guests') . ' of ' . $booked . '.';
    }

    /**
     * The breach list turned into the copy one message per recipient needs.
     *
     * Grouped per ACCOUNT, not per listing: an account can hold several listings
     * on the wedding (the seed's vendor login owns a caterer and a transport
     * company) and a bell per listing per breach is noise nobody can act on. One
     * entry per account, holding the sentences about ITS OWN packages — which is
     * why the clause names the package and not the person reading it.
     *
     * Where the copy goes, and who receives it, is decide-in-HeadcountBrief.php's
     * job (announce() folds these into the brief a couple's save sends;
     * announce_capacity() sends them on any save). This method only decides the
     * words.
     *
     * @param array<int, array<string,mixed>> $breaches PackageCapacity::breaches()
     * @return array{vendors:array<int,array{text:string,names:array<int,string>,category:string}>,
     *               planner:string,
     *               planner_brief:string,
     *               planner_upgrade:string,
     *               features:array<int,int>,
     *               unsent:array<int,array{name:string,category:string,why:string}>}
     *         `planner` is the warning as the SHEET reads it; `planner_brief` is
     *         the same warning for the BELL, which does not quote the count the
     *         function moved from — the brief's own line says that, and the room
     *         it frees is what lets `planner_upgrade` fit beside the warning
     *         (planner_clause() has the measurement). `planner_upgrade` is the
     *         way out (upgrades()), kept apart from the warning itself so a
     *         message that runs out of room drops the suggestion before the fact.
     *         `features` are the ids of the functions these breaches are about, so
     *         a caller that ALSO lists the moved functions can print each one once
     *         (see HeadcountBrief::announce(): the warning states the function and
     *         the count it now holds, so its moved line is the same fact twice —
     *         and on a varchar(255) column the room that frees is the room the way
     *         out rides in). `unsent` names the breaching vendors with no portal
     *         account: the planner is the only channel left.
     */
    public static function clauses(array $breaches): array
    {
        $vendors = [];
        $planner = [];
        $brief   = [];
        $unsent  = [];

        foreach ($breaches as $breach) {
            $planner[] = self::planner_clause($breach);
            $brief[]   = self::planner_clause($breach, false);

            $per_account = [];
            foreach (($breach['deals'] ?? []) as $deal) {
                $uid = (int) ($deal['user_id'] ?? 0);
                if ($uid <= 0) {
                    $name = self::vendor_name($deal);
                    if ($name !== '' && !isset($unsent[$name])) {
                        $unsent[$name] = ['name' => $name, 'category' => (string) ($deal['category'] ?? ''), 'why' => 'no_portal'];
                    }
                    continue;
                }
                $per_account[$uid][] = $deal;
            }

            foreach ($per_account as $uid => $deals) {
                if (!isset($vendors[$uid])) {
                    $vendors[$uid] = ['lines' => [], 'names' => [], 'category' => (string) ($deals[0]['category'] ?? '')];
                }
                $vendors[$uid]['lines'][] = self::vendor_clause($breach, $deals);
                foreach ($deals as $deal) {
                    $name = self::vendor_name($deal);
                    if ($name !== '' && !in_array($name, $vendors[$uid]['names'], true)) {
                        $vendors[$uid]['names'][] = $name;
                    }
                }
            }
        }

        $out = [
            'vendors'         => [],
            'planner'         => implode(' ', $planner),
            'planner_brief'   => implode(' ', $brief),
            'planner_upgrade' => '',
            'features'        => [],
            'unsent'          => array_values($unsent),
        ];
        foreach ($breaches as $breach) {
            $fid = (int) ($breach['features_id'] ?? 0);
            if ($fid > 0 && !in_array($fid, $out['features'], true)) {
                $out['features'][] = $fid;
            }
        }
        foreach ($vendors as $uid => $acct) {
            $out['vendors'][$uid] = [
                'text'     => implode(' ', $acct['lines']),
                'names'    => $acct['names'],
                'category' => $acct['category'],
            ];
        }

        // The way out of each breach, for the planner. Its own string, in
        // length order, because it is the part the bell may have to drop.
        $out['planner_upgrade'] = self::way_out($breaches);

        return $out;
    }

    /**
     * The way out of every breach in one string — the sentences upgrades()
     * produces, joined. Its own method because three readers need exactly this:
     * the planner's bell (clauses(), where a message that runs out of room drops
     * it before the warning), the sheet (which prints it as its own line under the
     * warning) and the page that just recorded the deal (position()). Empty when
     * there is nothing to suggest — the caller then says nothing rather than
     * printing a blank line.
     *
     * @param array<int, array<string,mixed>> $breaches
     */
    public static function way_out(array $breaches): string
    {
        $sentences = [];
        foreach (self::upgrades($breaches) as $upgrade) {
            $sentences[] = self::upgrade_sentence($upgrade);
        }
        return implode(' ', $sentences);
    }

    /**
     * The whole position in ONE line, for the page that just recorded the deal:
     * the warning, the way out of it, and (when the caller has it) the sentence
     * about who was warned.
     *
     * One line here, three lines on the sheet: this is read in a dismissable
     * confirmation by the person who just pressed the button, who has one more
     * decision to make (`Re-price` is on the row above) and no room for a section.
     * The copy is still the sheet's — notice() and way_out() — so the same fact is
     * never phrased twice in the application.
     */
    public static function position(array $breaches, string $told = ''): string
    {
        $text = self::notice($breaches);
        if ($text === '') {
            return '';
        }
        $way = self::way_out($breaches);
        if ($way !== '') {
            $text .= ' ' . $way;
        }
        return $told !== '' ? $text . ' ' . $told : $text;
    }

    /**
     * What the page says where the numbers are — the sheet, and the save that
     * made the change. `$told` is the sentence about who was warned, built by
     * whoever did the sending; without it the line states the position only.
     *
     * @param array<int, array<string,mixed>> $breaches
     */
    public static function notice(array $breaches, string $told = ''): string
    {
        $sentences = [];
        foreach ($breaches as $breach) {
            $sentences[] = self::planner_clause($breach);
        }
        if ($sentences === []) {
            return '';
        }
        $text = 'Over capacity: ' . implode(' ', $sentences);
        return $told !== '' ? $text . ' ' . $told : $text;
    }

    /** Does this deal's scope cover that function? */
    private static function covers(array $deal, int $features_id): bool
    {
        $scope = (string) ($deal['scope'] ?? 'wedding');
        if ($scope === 'missing') {
            return false;
        }
        if ($scope === 'wedding') {
            return true;
        }
        return (int) ($deal['features_id'] ?? 0) === $features_id;
    }

    /**
     * How a package reads in a sentence. A title that already states the limit
     * ("Choma Premium — 250 pax", "Banquet 400") is the whole label: printing the
     * number again reads as two different limits ("… 250 pax, up to 250 pax").
     *
     * Public because this is the ONE place that rule lives: the warning, the way
     * out and the re-price picker on the staff ledger all name packages, and a
     * picker that spelled the number out again would be the same duplicate in a
     * select box (vendor_enquiries.php). Takes `title` and `capacity` — from a
     * deal row, a package row or an upgrades() entry.
     */
    public static function package_label(array $deal): string
    {
        $capacity = (int) ($deal['capacity'] ?? 0);
        $title    = trim((string) ($deal['title'] ?? $deal['package_title'] ?? ''));
        if ($title === '') {
            return 'up to ' . $capacity . ' pax';
        }
        if ($capacity > 0 && preg_match('/(?<!\d)' . $capacity . '(?!\d)/', $title) === 1) {
            return $title;
        }
        return $title . ' — up to ' . $capacity . ' pax';
    }

    /**
     * What a bigger package costs against the one already sold: "KSh 60,000
     * more", "KSh 5,000 less", "the same price".
     *
     * Unstated prices are not guesses: with both on file the difference is the
     * fact; with only the new one, that price is the fact (there is no baseline
     * to compare against); with neither, the sentence names the package and says
     * no money at all — "KSh 0 more" would read as a price.
     */
    private static function money_clause(float $more, float $new, float $sold): string
    {
        if ($new <= 0 || $sold <= 0) {
            return $new > 0 ? format_ksh($new) : '';
        }
        if ($more === 0.0) {
            return 'the same price';
        }
        return format_ksh(abs($more)) . ($more > 0 ? ' more' : ' less');
    }

    /**
     * The function, the number, and the count it moved from when that is known
     * and asked for (see planner_clause() for why the bell does not ask).
     */
    private static function head(array $breach, bool $with_change = true): string
    {
        $was = $breach['was'] ?? null;
        $total = (int) ($breach['total'] ?? 0);
        return (string) ($breach['title'] ?? 'A function') . ' is planned for ' . $total
            . ($with_change && $was !== null && (int) $was !== $total ? ' (was ' . (int) $was . ')' : '');
    }

    private static function vendor_name(array $deal): string
    {
        return trim((string) ($deal['business_name'] ?? ''));
    }

    /** "A", "A and B", "A, B and C" — the house style for a short list. */
    private static function join(array $items): string
    {
        $items = array_values(array_filter(array_map(
            static fn($i): string => trim((string) $i),
            $items
        ), static fn(string $i): bool => $i !== ''));

        if ($items === []) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
