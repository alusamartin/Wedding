<?php
declare(strict_types=1);

/**
 * VendorFit — what one couple wants from each kind of vendor, and whether a
 * given service actually fits it.
 *
 * THE QUESTION THIS ANSWERS
 * v2.27 made services comparable with each other ("per guest", "flat", "per
 * day"). It did not make them comparable with the COUPLE. A planner shopping a
 * KSh 250,000 catering budget still had to hold the number, the taste and the
 * guest count in their head while scrolling. This class owns the couple's half
 * of that: `tblvendor_preferences` (one row per wedding per category) and the
 * read-only judgement `assess()` that turns a service plus a preference into a
 * label a human can act on.
 *
 * WHAT A PREFERENCE IS
 *   taste_tags   comma-separated, the same words vendors tag services with
 *                (rustic, halal, outdoor) — deliberately free text, because a
 *                fixed taxonomy would refuse the note a couple actually wrote
 *   budget_min   optional floor ("not the cheapest option")
 *   budget_max   the cap this category must stay under
 *   notes        the couple's own words, shown to their planner
 * No row for a category means no preference stated. A row with nothing filled
 * in is cleared on save, so "no preference" is one state, not two.
 *
 * THE HONEST PART: ESTIMATES
 * A fit is only as good as the quantity behind it. `estimate()` multiplies the
 * unit price by a quantity it can actually justify:
 *   flat                the price itself
 *   per_guest           the couple's guest list, or the vendor's stated minimum
 *                       if the list is smaller (that minimum is the real floor)
 *   per_day             one day, or the vendor's stated minimum days
 *   per_item / per_staff the vendor's stated minimum quantity
 *   per_hour / per_km   nothing — the hours and the distance are not known here
 * When no quantity can be justified the fit says **quote needed** instead of
 * inventing a total. That distinction is the whole reason this is a model and
 * not a badge: a made-up number is worse than an honest "ask them".
 *
 * THE STATES, in the order they are judged
 *   over       estimate above the cap — the hard constraint, wins over taste
 *   off_taste  none of the wanted tags appear on the service
 *   below      under the couple's floor (informational, not a failure)
 *   quote      a cap is set but no quantity can be justified
 *   partial    within budget, some of the wanted tags missing
 *   fits       within budget, every wanted tag present (or none asked for)
 *   unset      this couple stated no preference for the category
 *
 * SPLITTING ONE TOTAL ACROSS THE CATEGORIES
 * Most couples arrive with a single number (what the package costs) and ten
 * blank caps. `plan_split()` divides that total into whole KSh 500 blocks with
 * the largest-remainder method so the caps add up to exactly what they have,
 * leaves out any category whose share is zero instead of deleting its cap, and
 * lifts a category to the couple's own floor when that floor is higher than the
 * arithmetic — reporting the difference rather than quietly writing a cap below
 * a number they set. `apply_split()` writes the result in one transaction,
 * preserving each category's taste tags and floor. The shares in SHARES are a
 * starting point, not measured data.
 *
 * READ-ONLY ON THE VENDOR SIDE. Nothing here writes `tblvendor_services`: a fit
 * is computed on read, so a changed budget or a re-priced service can never
 * leave a stale "fits" badge behind. The only writer is save(), called from the
 * couple's/planner's page with the session's CSRF token.
 *
 * DEGRADING WHEN THE MIGRATION IS ABSENT
 * `installed()` probes once per request; a database without v2.28 shows no
 * badges and no brief, and every existing marketplace page works exactly as it
 * did. Nothing here throws.
 */
final class VendorFit
{
    public const MAX_TAGS  = 255;
    public const MAX_NOTES = 500;

    /** How many tags a couple may store, matching what a service may carry. */
    public const MAX_TAG_COUNT = 8;

    /**
     * The judgement, in the order it is applied. slug => [label, bootstrap tone,
     * Material Design icon]. `unset` draws nothing.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    public const STATES = [
        'over'      => ['Over budget', 'danger', 'cash-remove'],
        'off_taste' => ['Off your taste', 'secondary', 'close-circle-outline'],
        'below'     => ['Below your floor', 'info', 'arrow-down-circle-outline'],
        'quote'     => ['Quote needed', 'info', 'file-question-outline'],
        'partial'   => ['Partly fits', 'warning', 'circle-half-full'],
        'fits'      => ['Fits your brief', 'success', 'check-decagram'],
        'unset'     => ['', 'light', ''],
    ];

    /**
     * Where a vendor budget usually goes, as a STARTING POINT for the split
     * helper — not a finding.
     *
     * The order is what this market spends in: catering and the venue dominate,
     * decor and the photographer come next, and a live band costs more than the
     * cake. Nothing here is measured data, which is exactly why the page calls
     * it a starting point, shows every number in shillings before anything is
     * saved, and lets the couple overwrite any share or leave a category out.
     *
     * @var array<string, int>
     */
    public const SHARES = [
        'catering'    => 26,
        'decor'       => 12,
        'photography' => 10,
        'music'       => 8,
        'transport'   => 4,
        'mc'          => 3,
        'venue'       => 22,
        'cakes'       => 4,
        'flowers'     => 6,
        'other'       => 5,
    ];

    /**
     * Caps are placed in whole blocks of this many shillings. A split that
     * produced KSh 62,347 would read like a measured price; a couple budgeting
     * by hand writes KSh 62,500. Whatever cannot be placed is reported as
     * unplaced rather than smeared across the categories.
     */
    public const SPLIT_STEP = 500;

    /** @var array<string, array{0:string, 1:string, 2:string}> */
    public static function states(): array
    {
        return self::STATES;
    }

    /** @return array<string, int> */
    public static function shares(): array
    {
        return self::SHARES;
    }

    /** Is migrations/v2.28_vendor_preferences.sql installed? (One probe per request.) */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }

        global $db;
        try {
            $db->fetchValue('SELECT COUNT(*) FROM tblvendor_preferences');
            return $installed = true;
        } catch (Throwable $e) {
            return $installed = false;
        }
    }

    // ------------------------------------------------------------------
    // The couple's brief
    // ------------------------------------------------------------------

    /**
     * Every stated preference for one wedding, keyed by category.
     *
     * @return array<string, object>
     */
    public static function preferences(int $booking_id): array
    {
        if ($booking_id <= 0 || !self::installed()) {
            return [];
        }

        global $db;
        $rows = $db->fetchAll(
            'SELECT * FROM tblvendor_preferences WHERE booking_id = :b ORDER BY service_category ASC',
            [':b' => $booking_id]
        );

        $prefs = [];
        foreach ($rows as $row) {
            $prefs[(string) $row->service_category] = $row;
        }
        return $prefs;
    }

    /** One category's preference, or null when the couple stated none. */
    public static function preference(int $booking_id, string $category): ?object
    {
        if ($booking_id <= 0 || $category === '' || !self::installed()) {
            return null;
        }

        global $db;
        return $db->fetchOne(
            'SELECT * FROM tblvendor_preferences WHERE booking_id = :b AND service_category = :c LIMIT 1',
            [':b' => $booking_id, ':c' => $category]
        );
    }

    /**
     * Save (or clear) one category's preference.
     *
     * An empty form deletes the row: "they changed their mind" must look
     * exactly like "they never said", and a 0/'' row would make every later
     * fit say `unset` anyway while pretending a preference exists.
     *
     * @param array<string, mixed> $input raw form input
     * @return array{ok:bool, error:string, cleared:bool}
     */
    public static function save(int $booking_id, string $category, array $input, int $user_id): array
    {
        if (!self::installed()) {
            return ['ok' => false, 'error' => 'Preferences are not installed yet (migrations/v2.28_vendor_preferences.sql).', 'cleared' => false];
        }
        if (!in_array($category, VendorCoverage::slugs(), true)) {
            return ['ok' => false, 'error' => 'That is not a vendor category.', 'cleared' => false];
        }
        if ($booking_id <= 0 || !self::booking_exists($booking_id)) {
            return ['ok' => false, 'error' => 'That wedding no longer exists.', 'cleared' => false];
        }

        $tags   = self::normalise_tags((string) ($input['taste_tags'] ?? ''));
        $notes  = self::text((string) ($input['notes'] ?? ''), self::MAX_NOTES);
        $min    = self::money($input['budget_min'] ?? null);
        $max    = self::money($input['budget_max'] ?? null);

        if ($min !== null && $max !== null && $min > $max) {
            return ['ok' => false, 'error' => 'The floor cannot be above the cap.', 'cleared' => false];
        }
        if ($tags === '' && $notes === '' && $min === null && $max === null) {
            return ['ok' => true, 'error' => '', 'cleared' => self::clear($booking_id, $category)];
        }

        global $db;
        $db->execute(
            'INSERT INTO tblvendor_preferences
                (booking_id, service_category, taste_tags, budget_min, budget_max, notes, updated_by)
             VALUES (:b, :c, :t, :min, :max, :n, :u)
             ON DUPLICATE KEY UPDATE
                taste_tags = VALUES(taste_tags), budget_min = VALUES(budget_min),
                budget_max = VALUES(budget_max), notes = VALUES(notes),
                updated_by = VALUES(updated_by)',
            [
                ':b'   => $booking_id,
                ':c'   => $category,
                ':t'   => $tags,
                ':min' => $min,
                ':max' => $max,
                ':n'   => $notes,
                ':u'   => $user_id > 0 ? $user_id : null,
            ]
        );

        return ['ok' => true, 'error' => '', 'cleared' => false];
    }

    /** Remove one category's preference. False when there was nothing to clear. */
    public static function clear(int $booking_id, string $category): bool
    {
        if (!self::installed() || $booking_id <= 0 || $category === '') {
            return false;
        }
        if (self::preference($booking_id, $category) === null) {
            return false;
        }

        global $db;
        $db->execute(
            'DELETE FROM tblvendor_preferences WHERE booking_id = :b AND service_category = :c',
            [':b' => $booking_id, ':c' => $category]
        );
        return true;
    }

    /**
     * The number of guests a per-guest price multiplies by: the couple's own
     * guest list. Deliberately NOT the package's capacity — that is a vendor's
     * limit, not this couple's crowd — and not the headcount plan, which is a
     * venue-sheet figure per function. The guest list is the number the couple
     * maintains and recognises.
     */
    public static function guests(int $booking_id): int
    {
        if ($booking_id <= 0) {
            return 0;
        }

        global $db;
        try {
            return (int) $db->fetchValue('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $booking_id]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * What the couple has committed across the categories they set, against the
     * package price the planner sold — the "you have allocated KSh X of KSh Y"
     * line on the editor. A cap of 0/absent contributes nothing.
     *
     * @return array{allocated:float, capped:int, stated:int}
     */
    public static function allocation(int $booking_id): array
    {
        $prefs = self::preferences($booking_id);
        $allocated = 0.0;
        $capped = 0;
        foreach ($prefs as $pref) {
            if ($pref->budget_max !== null && (float) $pref->budget_max > 0) {
                $allocated += (float) $pref->budget_max;
                $capped++;
            }
        }
        return ['allocated' => $allocated, 'capped' => $capped, 'stated' => count($prefs)];
    }

    // ------------------------------------------------------------------
    // Splitting one total across the categories
    // ------------------------------------------------------------------

    /**
     * Turn "we have KSh 295,000 for vendors" plus a set of shares into one cap
     * per category — the arithmetic half of the split helper on the editor.
     *
     * WHY THIS IS NOT JUST PERCENTAGES. Three things must be true of the answer
     * before a couple is allowed to save it:
     *
     *  1. The caps add up to what they said they have. So the total is divided
     *     into whole SPLIT_STEP blocks and apportioned with the largest-remainder
     *     method — the standard way parliaments hand out seats — which keeps the
     *     sum exact instead of letting ten roundings drift a few hundred
     *     shillings past the budget.
     *  2. A category the couple left out is NOT TOUCHED. Weight 0 means "not
     *     part of this split", never "delete my cap" — quietly removing a number
     *     somebody typed is the one destructive thing a helper could do here.
     *  3. A floor the couple set outranks the arithmetic. If their own floor is
     *     above the share, the cap becomes the floor and the row is marked
     *     "lifted": they said they will not spend less than that, and the plan
     *     says so instead of writing a cap below it (which save() would reject).
     *     When that happens the plan can no longer fit the total, and it reports
     *     the difference rather than hiding it.
     *
     * Rows that would come out identical to the cap already stored are marked
     * 'same' and are not written, so applying a split twice is quiet.
     *
     * @param float $total the money to split (package price or the couple's own)
     * @param array<string, mixed> $weights category => share (any positive numbers; 0 = leave out)
     * @param array<string, object> $prefs VendorFit::preferences(), for floors and current caps
     * @return array{error:string, total:float, active:int, planned:float, resulting:float,
     *               unplaced:float, lifted:int,
     *               rows:array<int, array{category:string, label:string, icon:string,
     *                    share:float, amount:?float, current:?float, floor:?float,
     *                    status:string, changed:bool}>}
     */
    public static function plan_split(float $total, array $weights, array $prefs = []): array
    {
        $total   = round($total, 2);
        $weights = self::normalise_weights($weights);
        $sum_w   = array_sum($weights);
        $active  = array_keys($weights);

        $error = '';
        if ($total <= 0) {
            $error = 'Enter the total you have for vendors — the package price is only a starting suggestion.';
        } elseif ($active === []) {
            $error = 'Give at least one category a share above zero.';
        } else {
            $steps_total = (int) floor($total / self::SPLIT_STEP);
            if ($steps_total < count($active)) {
                $error = self::money_text($total) . ' is too small to split across ' . count($active)
                    . ' categories at ' . self::money_text((float) self::SPLIT_STEP) . ' each.';
            }
        }

        $steps = [];
        if ($error === '') {
            // Ideal amount per category, then whole blocks, then the leftover
            // blocks to whoever was rounded down hardest.
            $ideal = [];
            $used  = 0;
            foreach ($weights as $slug => $w) {
                $ideal[$slug] = $total * $w / $sum_w;
                $steps[$slug] = (int) floor($ideal[$slug] / self::SPLIT_STEP);
                $used += $steps[$slug];
            }

            $remainder = [];
            foreach ($ideal as $slug => $amount) {
                $remainder[$slug] = ($amount / self::SPLIT_STEP) - $steps[$slug];
            }
            arsort($remainder);
            $left = (int) floor($total / self::SPLIT_STEP) - $used;
            foreach (array_keys($remainder) as $slug) {
                if ($left <= 0) {
                    break;
                }
                $steps[$slug]++;
                $left--;
            }

            // A share so small it rounded to nothing would write "no cap" for a
            // category the couple just asked to budget. Take one block from the
            // biggest instead; every active row keeps at least one.
            foreach ($active as $slug) {
                if ($steps[$slug] > 0) {
                    continue;
                }
                arsort($steps);
                foreach (array_keys($steps) as $donor) {
                    if ($steps[$donor] > 1) {
                        $steps[$donor]--;
                        $steps[$slug] = 1;
                        break;
                    }
                }
            }
        }

        $rows      = [];
        $planned   = 0.0;
        $resulting = 0.0;
        $lifted    = 0;

        foreach (VendorCoverage::categories() as $slug => [$label, $icon]) {
            $pref    = $prefs[$slug] ?? null;
            $current = ($pref !== null && $pref->budget_max !== null && (float) $pref->budget_max > 0)
                ? (float) $pref->budget_max : null;
            $floor   = ($pref !== null && $pref->budget_min !== null && (float) $pref->budget_min > 0)
                ? (float) $pref->budget_min : null;

            $is_active = $error === '' && isset($steps[$slug]);
            $amount    = $is_active ? (float) ($steps[$slug] * self::SPLIT_STEP) : null;
            $status    = 'skipped';

            if ($is_active) {
                if ($floor !== null && $floor > $amount) {
                    $amount = $floor;
                    $status = 'lifted';
                    $lifted++;
                } else {
                    $status = ($current !== null && abs($current - $amount) < 0.5) ? 'same' : 'set';
                }
                $planned += $amount;
                $resulting += $amount;
            } else {
                $resulting += (float) ($current ?? 0);
            }

            $rows[] = [
                'category' => $slug,
                'label'    => $label,
                'icon'     => $icon,
                'share'    => $is_active ? round($weights[$slug] / $sum_w * 100, 1) : 0.0,
                'amount'   => $amount,
                'current'  => $current,
                'floor'    => $floor,
                'status'   => $status,
                'changed'  => $status === 'set' || $status === 'lifted',
            ];
        }

        return [
            'error'     => $error,
            'total'     => $total,
            'active'    => count($active),
            'planned'   => round($planned, 2),
            'resulting' => round($resulting, 2),
            'unplaced'  => round($total - $planned, 2),
            'lifted'    => $lifted,
            'rows'      => $rows,
        ];
    }

    /**
     * Plan and write it: the caps become this couple's brief for every category
     * in the split, taste tags and floors untouched.
     *
     * The caller passes the total and the shares, never the amounts — the page
     * recomputes the plan on POST, so a tampered form cannot invent a budget
     * that the arithmetic above would not produce.
     *
     * One transaction: a split is one decision, and half of it would be worse
     * than none of it.
     *
     * @param array<string, mixed> $weights
     * @return array{ok:bool, error:string, written:int, lifted:int, planned:float,
     *               resulting:float, rows:array<int, mixed>}
     */
    public static function apply_split(int $booking_id, float $total, array $weights, int $user_id): array
    {
        $empty = ['ok' => false, 'error' => '', 'written' => 0, 'lifted' => 0, 'planned' => 0.0, 'resulting' => 0.0, 'rows' => []];

        if (!self::installed()) {
            return ['error' => 'Preferences are not installed yet (migrations/v2.28_vendor_preferences.sql).'] + $empty;
        }
        if ($booking_id <= 0 || !self::booking_exists($booking_id)) {
            return ['error' => 'That wedding no longer exists.'] + $empty;
        }

        $plan = self::plan_split($total, $weights, self::preferences($booking_id));
        if ($plan['error'] !== '') {
            return ['error' => $plan['error']] + $empty;
        }

        $writes = array_values(array_filter($plan['rows'], static fn (array $row): bool => $row['changed']));
        if ($writes === []) {
            return ['ok' => true, 'error' => '', 'written' => 0, 'lifted' => $plan['lifted'],
                    'planned' => $plan['planned'], 'resulting' => $plan['resulting'], 'rows' => $plan['rows']];
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            foreach ($writes as $row) {
                $db->execute(
                    'INSERT INTO tblvendor_preferences
                        (booking_id, service_category, taste_tags, budget_min, budget_max, notes, updated_by)
                     VALUES (:b, :c, \'\', NULL, :max, \'\', :u)
                     ON DUPLICATE KEY UPDATE budget_max = VALUES(budget_max), updated_by = VALUES(updated_by)',
                    [
                        ':b'   => $booking_id,
                        ':c'   => (string) $row['category'],
                        ':max' => $row['amount'],
                        ':u'   => $user_id > 0 ? $user_id : null,
                    ]
                );
            }
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'The split could not be saved; nothing was changed.',
                    'written' => 0, 'lifted' => 0, 'planned' => 0.0, 'resulting' => 0.0, 'rows' => []];
        }

        return ['ok' => true, 'error' => '', 'written' => count($writes), 'lifted' => $plan['lifted'],
                'planned' => $plan['planned'], 'resulting' => $plan['resulting'], 'rows' => $plan['rows']];
    }

    /**
     * Shares from a form: known categories only, numerically valid, zero = out.
     * Blank or junk shares are 0 (left out), never a reason to refuse the form —
     * the plan says what it could not place.
     *
     * @param array<string, mixed> $weights
     * @return array<string, float>
     */
    public static function normalise_weights(array $weights): array
    {
        $clean = [];
        foreach (VendorCoverage::slugs() as $slug) {
            $raw = $weights[$slug] ?? null;
            if (!is_numeric($raw)) {
                continue;
            }
            $value = (float) $raw;
            if ($value <= 0) {
                continue;
            }
            $clean[$slug] = min($value, 100.0);
        }
        return $clean;
    }

    /** A readable one-line brief for a category: "KSh 250,000 · rustic, halal". */
    public static function summary(?object $pref): string
    {
        if ($pref === null) {
            return '';
        }
        $bits = [];
        if ($pref->budget_max !== null && (float) $pref->budget_max > 0) {
            $bits[] = format_ksh((float) $pref->budget_max);
        }
        $tags = self::tag_list((string) ($pref->taste_tags ?? ''));
        if ($tags !== []) {
            $bits[] = implode(', ', $tags);
        }
        if ($bits === [] && trim((string) ($pref->notes ?? '')) !== '') {
            $bits[] = trim((string) $pref->notes);
        }
        return implode(' · ', $bits);
    }

    // ------------------------------------------------------------------
    // Matching one service
    // ------------------------------------------------------------------

    /**
     * The cost this service would put on the wedding, or null when the unit
     * cannot be multiplied honestly.
     *
     * @return array{amount:?float, quantity:?int, note:string}
     */
    public static function estimate(object $service, int $guests): array
    {
        $unit  = (string) ($service->pricing_unit ?? 'flat');
        $price = (float) ($service->price ?? 0);
        $min   = (int) ($service->min_qty ?? 0);

        if ($price <= 0) {
            return ['amount' => null, 'quantity' => null, 'note' => 'no price published'];
        }

        $qty = null;
        $note = '';
        switch ($unit) {
            case 'flat':
                $qty = 1;
                $note = 'flat fee';
                break;
            case 'per_guest':
                $qty = max($guests, $min);
                if ($qty <= 0) {
                    return ['amount' => null, 'quantity' => null, 'note' => 'add guests to estimate a per-guest price'];
                }
                $note = $guests > 0 && $guests >= $min
                    ? $guests . ' guest' . ($guests === 1 ? '' : 's')
                    : 'their ' . $qty . '-guest minimum';
                break;
            case 'per_day':
                $qty = max(1, $min);
                $note = $qty . ' day' . ($qty === 1 ? '' : 's');
                break;
            case 'per_item':
            case 'per_staff':
                if ($min <= 0) {
                    return ['amount' => null, 'quantity' => null, 'note' => 'quantity not stated — ask them for a total'];
                }
                $qty = $min;
                $noun = $unit === 'per_staff'
                    ? 'staff member' . ($qty === 1 ? '' : 's')
                    : 'item' . ($qty === 1 ? '' : 's');
                $note = $qty . ' ' . $noun . ' (their minimum)';
                break;
            default:
                // per_hour, per_km: the hours and the distance are the couple's
                // unknowns, not the vendor's. A guess here would be invented.
                return ['amount' => null, 'quantity' => null, 'note' => 'quoted per ' . (VendorService::units()[$unit][1] ?? 'unit') . ' — ask them for a total'];
        }

        $price_text = self::money_text($price);
        return [
            'amount'   => round($price * $qty, 2),
            'quantity' => $qty,
            'note'     => $unit === 'flat'
                ? 'flat fee of ' . $price_text
                : $price_text . ' × ' . $note,
        ];
    }

    /**
     * How one service measures against one couple's preference.
     *
     * @return array{state:string, label:string, tone:string, icon:string,
     *               estimate:?float, estimate_note:string, reasons:string[],
     *               matched_tags:string[], missing_tags:string[]}
     */
    public static function assess(object $service, ?object $pref, int $guests): array
    {
        $state = 'unset';
        $reasons = [];
        $matched = [];
        $missing = [];

        $estimate = self::estimate($service, $guests);
        $cap  = $pref !== null && $pref->budget_max !== null ? (float) $pref->budget_max : 0.0;
        $floor = $pref !== null && $pref->budget_min !== null ? (float) $pref->budget_min : 0.0;
        $wanted = $pref !== null ? self::tag_list((string) ($pref->taste_tags ?? '')) : [];
        $have   = self::tag_list((string) ($service->tags ?? ''));

        foreach ($wanted as $tag) {
            if (in_array($tag, $have, true)) {
                $matched[] = $tag;
            } else {
                $missing[] = $tag;
            }
        }

        if ($pref === null || ($cap <= 0 && $floor <= 0 && $wanted === [])) {
            return self::result('unset', $estimate, $reasons, $matched, $missing);
        }

        $amount = $estimate['amount'];
        if ($cap > 0 && $amount !== null && $amount > $cap) {
            $state = 'over';
            $reasons[] = self::money_text($amount) . ' is ' . self::money_text($amount - $cap)
                . ' over your ' . self::money_text($cap) . ' cap.';
        } elseif ($wanted !== [] && $have !== [] && $matched === []) {
            $state = 'off_taste';
            $reasons[] = 'Tagged ' . implode(', ', $have) . ' — none of ' . implode(', ', $wanted) . '.';
        } elseif ($floor > 0 && $amount !== null && $amount < $floor) {
            $state = 'below';
            $reasons[] = self::money_text($amount) . ' is under the ' . self::money_text($floor) . ' floor you set.';
        } elseif ($cap > 0 && $amount === null) {
            $state = 'quote';
            $reasons[] = ucfirst($estimate['note']) . '.';
        } elseif ($wanted !== [] && $missing !== []) {
            $state = 'partial';
            $reasons[] = 'Matches ' . implode(', ', $matched) . '; still missing ' . implode(', ', $missing) . '.';
        } else {
            $state = 'fits';
        }

        if ($amount !== null && $cap > 0 && $state !== 'over') {
            $reasons[] = self::money_text($amount) . ' of your ' . self::money_text($cap) . ' budget.';
        } elseif ($amount !== null && $cap <= 0 && $floor <= 0) {
            $reasons[] = self::money_text($amount) . ' estimated.';
        }
        if ($state === 'fits' && $wanted !== []) {
            $reasons[] = 'Ticks every taste you asked for: ' . implode(', ', $wanted) . '.';
        }
        if (trim((string) ($service->includes ?? '')) !== '') {
            $reasons[] = 'Includes ' . trim((string) $service->includes) . '.';
        }

        return self::result($state, $estimate, $reasons, $matched, $missing);
    }

    /**
     * The best-fitting service one vendor offers — what a directory card has
     * room to say. "Best" is the least severe state, then the lowest estimate;
     * a vendor with no published service returns `unset` and the card shows
     * nothing.
     *
     * Each service is judged against the preference for ITS OWN category (a
     * photographer who also sells transport is compared with the transport
     * budget, not the photography one), falling back to the vendor's category
     * for custom services that carry no catalogue category.
     *
     * @param object[] $services           that vendor's services (already active)
     * @param array<string, object> $prefs  VendorFit::preferences()
     * @param string $fallback_category     the vendor's own category
     * @return array{state:string, label:string, tone:string, icon:string,
     *               service_id:int, service_title:string, estimate:?float,
     *               estimate_note:string, reasons:string[], matched_tags:string[],
     *               missing_tags:string[], over:int, services:int}
     */
    public static function vendor_fit(array $services, array $prefs, int $guests, string $fallback_category = ''): array
    {
        $best = null;
        $over = 0;
        $rank = ['fits' => 0, 'partial' => 1, 'below' => 2, 'quote' => 3, 'off_taste' => 4, 'over' => 5, 'unset' => 6];

        foreach ($services as $service) {
            $category = (string) ($service->catalog_category ?? '');
            if ($category === '') {
                $category = $fallback_category;
            }
            $fit = self::assess($service, $prefs[$category] ?? null, $guests);
            if ($fit['state'] === 'over') {
                $over++;
            }
            if ($best === null) {
                $best = [$service, $fit];
                continue;
            }
            [$best_service, $best_fit] = $best;
            $a = $rank[$fit['state']] ?? 6;
            $b = $rank[$best_fit['state']] ?? 6;
            $better = $a < $b
                || ($a === $b && $fit['estimate'] !== null && ($best_fit['estimate'] === null || $fit['estimate'] < $best_fit['estimate']));
            if ($better) {
                $best = [$service, $fit];
            }
        }

        if ($best === null) {
            return [
                'state' => 'unset', 'label' => '', 'tone' => 'light', 'icon' => '',
                'service_id' => 0, 'service_title' => '', 'estimate' => null,
                'estimate_note' => '', 'reasons' => [], 'matched_tags' => [],
                'missing_tags' => [], 'over' => 0, 'services' => 0,
            ];
        }

        [$service, $fit] = $best;
        return $fit + [
            'service_id'    => (int) $service->id,
            'service_title' => (string) $service->title,
            'over'          => $over,
            'services'      => count($services),
        ];
    }

    // ------------------------------------------------------------------
    // Validation and formatting
    // ------------------------------------------------------------------

    /** Comma-separated taste tags → clean, lowercase, deduplicated, max 8. */
    public static function normalise_tags(string $raw): string
    {
        $seen = [];
        foreach (explode(',', $raw) as $tag) {
            $tag = mb_strtolower(trim(strip_tags($tag)));
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            if (count($seen) >= self::MAX_TAG_COUNT) {
                break;
            }
        }
        $joined = implode(',', array_keys($seen));
        return mb_strlen($joined) > self::MAX_TAGS ? mb_substr($joined, 0, self::MAX_TAGS) : $joined;
    }

    /** @return string[] */
    public static function tag_list(string $raw): array
    {
        $tags = [];
        foreach (explode(',', mb_strtolower($raw)) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /** KSh for a display sentence, with the same formatting the pages use. */
    public static function money_text(float $amount): string
    {
        return function_exists('format_ksh')
            ? format_ksh($amount)
            : 'KSh ' . number_format($amount, 0);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $estimate
     * @param string[] $reasons
     * @param string[] $matched
     * @param string[] $missing
     * @return array{state:string, label:string, tone:string, icon:string,
     *               estimate:?float, estimate_note:string, reasons:string[],
     *               matched_tags:string[], missing_tags:string[]}
     */
    private static function result(string $state, array $estimate, array $reasons, array $matched, array $missing): array
    {
        [$label, $tone, $icon] = self::STATES[$state] ?? self::STATES['unset'];
        return [
            'state'         => $state,
            'label'         => $label,
            'tone'          => $tone,
            'icon'          => $icon,
            'estimate'      => $estimate['amount'],
            'estimate_note' => (string) $estimate['note'],
            'reasons'       => array_values($reasons),
            'matched_tags'  => $matched,
            'missing_tags'  => $missing,
        ];
    }

    private static function booking_exists(int $booking_id): bool
    {
        global $db;
        return (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblweddingbook WHERE booking_id = :b',
            [':b' => $booking_id]
        ) > 0;
    }

    /** A budget field: null when blank/zero/invalid, otherwise a rounded float. */
    private static function money(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $amount = (float) $raw;
        return $amount > 0 ? round($amount, 2) : null;
    }

    private static function text(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
