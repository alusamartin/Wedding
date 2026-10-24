<?php
declare(strict_types=1);

/**
 * VendorPlan — one wedding's vendors, what each was chosen for, and what the
 * whole thing costs.
 *
 * THE QUESTION THIS ANSWERS
 * The coverage board says what a wedding still needs; the enquiry ledger says
 * what has been asked of each vendor; the budget page records what has been
 * PAID. None of them answers the question a couple asks first and a planner has
 * to answer from memory: "which vendors are doing this wedding, and what is the
 * total?" This class builds that answer from the deals themselves.
 *
 * WHY IT IS A VIEW, NOT A TABLE
 * `tblvendor_bookings` already is "this wedding chose this vendor": vendor,
 * wedding, offering, amount, status, function. A second plan table would be a
 * second truth, and the first confirmed deal would make the two disagree about
 * the same money. So nothing here is stored: `build()` reads the deals and
 * groups them by vendor category, and the only new storage (v2.29) is the
 * offering a deal was made for — `service_id` + `service_qty` — so the plan can
 * say "Classic buffet × 180 guests", not just a number.
 *
 * THE THREE NUMBERS, KEPT APART ON PURPOSE
 *   planned    pending deals — an enquiry has been sent, nobody has agreed yet
 *   committed  confirmed or completed deals — the vendor is doing the wedding
 *   dropped    cancelled deals, counted but never added (a cancelled deal is
 *              history, not cost)
 * The total is planned + committed. Collapsing the first two into one figure
 * would let an unanswered enquiry read as a cost the couple has taken on.
 *
 * MONEY IS NEVER RECOMPUTED BEHIND THE PLANNER
 * A deal's `amount` is what was entered: an estimate when planning, the agreed
 * figure when confirming. Where a service's CURRENT price × the stored quantity
 * would produce a different number, the row says "their price is now KSh X"
 * (and only for pending deals — a confirmed amount is an agreement, not an
 * arithmetic mistake). Nothing rewrites the deal.
 *
 * ADDING A VENDOR IS SENDING THE ENQUIRY
 * `add()` creates the pending deal the rest of the product already understands
 * (the vendor sees it on their ledger, the planner confirms it with an amount,
 * the couple reads it on their plan) and notifies the vendor account, exactly as
 * a marketplace enquiry does. It never invents a price: an offering with no
 * published price needs the planner to type the amount agreed, and a per-hour or
 * per-km service needs the hours or the distance before any total is possible.
 *
 * DEGRADING WHEN v2.29 IS ABSENT
 * `installed()` probes for `service_id`. Without it a plan still builds from
 * package deals and legacy rows (offering shown as the package or "custom
 * arrangement"), and `add()` refuses only the service kind, saying which
 * migration is missing. Nothing throws.
 */
final class VendorPlan
{
    /** The kinds of offering a deal can be made for. */
    public const KINDS = ['service', 'package', 'custom'];

    /** Where a category's money stands. */
    public const STATES = [
        'over'    => ['Over the cap', 'danger', 'cash-remove'],
        'covered' => ['Committed', 'success', 'check-decagram'],
        'pending' => ['Awaiting reply', 'warning', 'clock-outline'],
        'gap'     => ['No vendor yet', 'secondary', 'account-search-outline'],
        'none'    => ['Not planned', 'light', ''],
    ];

    /** @return array<string, array{0:string, 1:string, 2:string}> */
    public static function states(): array
    {
        return self::STATES;
    }

    /** Is migrations/v2.29_vendor_plan.sql installed? (One probe per request.) */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }

        global $db;
        try {
            $db->fetchValue('SELECT service_id FROM tblvendor_bookings LIMIT 1');
            return $installed = true;
        } catch (Throwable $e) {
            return $installed = false;
        }
    }

    // ------------------------------------------------------------------
    // The plan
    // ------------------------------------------------------------------

    /**
     * Every deal on one wedding, grouped by vendor category and totalled.
     *
     * @return array{rows:array<int, object>, categories:array<string, array<string, mixed>>,
     *               totals:array{planned:float, committed:float, total:float, caps:float,
     *                             cancelled:float, dropped:int, unpriced:int, deals:int,
     *                             pending:int, confirmed:int, covered:int, gaps:int},
     *               guests:int}
     */
    public static function build(int $booking_id): array
    {
        $categories = [];
        foreach (VendorCoverage::categories() as $slug => [$label, $icon]) {
            $categories[$slug] = [
                'category'  => $slug,
                'label'     => $label,
                'icon'      => $icon,
                'cap'       => null,
                'floor'     => null,
                'planned'   => 0.0,
                'committed' => 0.0,
                'total'     => 0.0,
                'state'     => 'none',
                'rows'      => [],
            ];
        }

        if ($booking_id <= 0) {
            return ['rows' => [], 'categories' => $categories, 'totals' => self::zero_totals(), 'guests' => 0];
        }

        $prefs  = VendorFit::preferences($booking_id);
        $guests = VendorFit::guests($booking_id);
        foreach ($prefs as $slug => $pref) {
            if (!isset($categories[$slug])) {
                continue;
            }
            if ($pref->budget_max !== null && (float) $pref->budget_max > 0) {
                $categories[$slug]['cap'] = (float) $pref->budget_max;
            }
            if ($pref->budget_min !== null && (float) $pref->budget_min > 0) {
                $categories[$slug]['floor'] = (float) $pref->budget_min;
            }
        }

        global $db;
        // The offering columns only exist from v2.29, and this page is on the
        // couple's dashboard: naming a column a database does not have would turn
        // a missing migration into a white screen on a live wedding. Without it
        // the same rows are read with the offering NULL, so packages and agreed
        // amounts still build (see the class docblock).
        if (self::installed()) {
            $offering_cols = 'vb.service_id, vb.service_qty, s.title AS service_title, s.pricing_unit, '
                . 's.price AS service_price, s.min_qty, s.max_qty, s.includes, s.tags,';
            $offering_join = 'LEFT JOIN tblvendor_services s ON s.id = vb.service_id ';
        } else {
            $offering_cols = 'NULL AS service_id, NULL AS service_qty, NULL AS service_title, NULL AS pricing_unit, '
                . 'NULL AS service_price, NULL AS min_qty, NULL AS max_qty, NULL AS includes, NULL AS tags,';
            $offering_join = '';
        }

        $rows = $db->fetchAll(
            'SELECT vb.id, vb.vendor_id, vb.booking_id, vb.package_id, '
            . $offering_cols . ' '
            . 'vb.features_id, vb.event_date, vb.amount, vb.status, vb.created_at, '
            . 'v.business_name, v.category, v.phone AS vendor_phone, '
            . 'p.title AS package_title, p.price AS package_price, p.capacity AS package_capacity, '
            . 'e.title AS function_title '
            . 'FROM tblvendor_bookings vb '
            . 'JOIN tblvendors v ON v.id = vb.vendor_id '
            . 'LEFT JOIN tblvendor_packages p ON p.id = vb.package_id '
            . $offering_join
            . 'LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id '
            . 'WHERE vb.booking_id = :b '
            . "ORDER BY v.category ASC, FIELD(vb.status, 'confirmed', 'completed', 'pending', 'cancelled'), vb.id ASC",
            [':b' => $booking_id]
        );

        $totals = self::zero_totals();
        $totals['guests'] = $guests;

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $category = isset($categories[(string) $row->category]) ? (string) $row->category : 'other';
            $amount = (float) $row->amount;

            $row->offering_kind  = $row->service_id !== null ? 'service' : ($row->package_id !== null ? 'package' : 'custom');
            $row->offering_label = self::offering_label($row);
            $row->unit           = (string) ($row->pricing_unit ?? '');
            $row->qty            = $row->service_qty !== null ? (int) $row->service_qty : null;
            $row->unit_price     = $row->service_price !== null ? (float) $row->service_price : null;
            $row->price_moved    = false;
            $row->price_now      = null;

            if ($row->offering_kind === 'service'
                && $row->qty !== null && $row->qty > 0
                && $row->unit_price !== null && $row->unit_price > 0
                && $status === 'pending'
                && abs(($row->unit_price * $row->qty) - $amount) >= 0.5
            ) {
                $row->price_moved = true;
                $row->price_now   = round($row->unit_price * $row->qty, 2);
            }

            // A pending deal with no amount yet is an enquiry, not a cost: it is
            // counted so the page can say "still unpriced", and contributes 0.
            $row->unpriced = $status === 'pending' && $amount <= 0;

            $row->money_line = self::money_line($row);

            if ($status === 'cancelled') {
                $totals['cancelled'] += $amount;
                $totals['dropped']++;
            } elseif ($status === 'pending') {
                $totals['planned'] += $amount;
                $totals['pending']++;
                if ($row->unpriced) {
                    $totals['unpriced']++;
                }
                $categories[$category]['planned'] += $amount;
            } else {
                $totals['committed'] += $amount;
                $totals['confirmed']++;
                $categories[$category]['committed'] += $amount;
            }

            $totals['deals']++;
            $categories[$category]['rows'][] = $row;
        }

        foreach ($categories as $slug => $data) {
            $data['total'] = $data['planned'] + $data['committed'];
            $rowsHere = $data['rows'];

            if ($data['cap'] !== null && $data['total'] > $data['cap']) {
                $data['state'] = 'over';
            } elseif ($data['committed'] > 0) {
                $data['state'] = 'covered';
            } elseif ($data['planned'] > 0) {
                $data['state'] = 'pending';
            } elseif ($data['cap'] !== null) {
                $data['state'] = 'gap'; // budgeted, but nobody chosen
            } else {
                $data['state'] = 'none';
            }

            $data['remaining'] = $data['cap'] !== null ? $data['cap'] - $data['total'] : null;
            $data['live_rows'] = count($rowsHere);

            if ($data['state'] === 'covered') {
                $totals['covered']++;
            }
            if ($rowsHere === [] && $data['cap'] !== null) {
                $totals['gaps']++;
            }
            $categories[$slug] = $data;
        }

        $totals['total'] = $totals['planned'] + $totals['committed'];
        $totals['caps']  = array_sum(array_map(
            static fn (array $c): float => (float) ($c['cap'] ?? 0),
            $categories
        ));

        return [
            'rows'       => $rows,
            'categories' => $categories,
            'totals'     => $totals,
            'guests'     => $guests,
        ];
    }

    // ------------------------------------------------------------------
    // Choosing an offering
    // ------------------------------------------------------------------

    /**
     * Everything an approved vendor currently sells, shaped for the plan's
     * picker: one row per active service or package, in category order. Vendors
     * with nothing published are returned with `offerings => []` so the planner
     * can still plan them as a custom arrangement (an amount to agree).
     *
     * @return array<int, array{vendor_id:int, vendor:string, category:string,
     *                          category_label:string, offerings:array<int, array<string, mixed>>}>
     */
    public static function offerings(): array
    {
        global $db;

        $vendors = $db->fetchAll(
            'SELECT id, business_name, category FROM tblvendors WHERE is_approved = 1 ORDER BY category ASC, business_name ASC'
        );
        if ($vendors === []) {
            return [];
        }

        $services = [];
        if (VendorService::installed()) {
            $serviceRows = $db->fetchAll(
                'SELECT id, vendor_id, title, pricing_unit, price, min_qty, max_qty, tags
                   FROM tblvendor_services
                  WHERE is_active = 1
                  ORDER BY price ASC, id ASC'
            );
            // Silver/Gold/Platinum per offering (v3.1), one query for the whole
            // list. The builder lists each priced tier as its own choice, so a
            // planner picks "the Gold buffet" rather than picking a service and
            // then guessing which level the total came from.
            $tiersByService = VendorTier::map_for_services(array_map(
                static fn (object $s): int => (int) $s->id,
                $serviceRows
            ));
            foreach ($serviceRows as $service) {
                $services[(int) $service->vendor_id][] = [
                    'kind'       => 'service',
                    'id'         => (int) $service->id,
                    'label'      => (string) $service->title,
                    'price'      => (float) $service->price,
                    'unit'       => (string) $service->pricing_unit,
                    'price_line' => VendorService::price_line($service),
                    'min_qty'    => $service->min_qty !== null ? (int) $service->min_qty : null,
                    'max_qty'    => $service->max_qty !== null ? (int) $service->max_qty : null,
                    'tiers'      => $tiersByService[(int) $service->id] ?? [],
                ];
            }
        }

        $packages = [];
        $packageRows = $db->fetchAll(
            'SELECT id, vendor_id, title, price, capacity
               FROM tblvendor_packages
              WHERE (is_active IS NULL OR is_active = 1)
              ORDER BY price ASC, id ASC'
        );
        $tiersByPackage = VendorTier::map_for_packages(array_map(
            static fn (object $p): int => (int) $p->id,
            $packageRows
        ));
        foreach ($packageRows as $package) {
            $capacity = $package->capacity !== null ? (int) $package->capacity : 0;
            $packages[(int) $package->vendor_id][] = [
                'kind'       => 'package',
                'id'         => (int) $package->id,
                'label'      => (string) $package->title,
                'price'      => (float) $package->price,
                'unit'       => '',
                'price_line' => format_ksh((float) $package->price) . ($capacity > 0 ? ' · up to ' . $capacity . ' guests' : ''),
                'min_qty'    => null,
                'max_qty'    => $capacity > 0 ? $capacity : null,
                'tiers'      => $tiersByPackage[(int) $package->id] ?? [],
            ];
        }

        $grouped = [];
        foreach ($vendors as $vendor) {
            $id = (int) $vendor->id;
            $category = isset(VendorCoverage::categories()[(string) $vendor->category])
                ? (string) $vendor->category : 'other';
            $grouped[$category][] = [
                'vendor_id'      => $id,
                'vendor'         => (string) $vendor->business_name,
                'category'       => $category,
                'category_label' => VendorCoverage::categories()[$category][0],
                'offerings'      => array_merge($packages[$id] ?? [], $services[$id] ?? []),
            ];
        }

        // Keep the board's category order, so the picker reads like the plan.
        $ordered = [];
        foreach (VendorCoverage::slugs() as $slug) {
            foreach ($grouped[$slug] ?? [] as $group) {
                $ordered[] = $group;
            }
        }
        return $ordered;
    }

    /**
     * What one offering would cost this wedding at a given quantity, and the
     * quantity that would be used. Nothing is written.
     *
     * The quantity rules are the same ones VendorFit::estimate() applies, with
     * one difference that matters when a human is choosing: every unit may be
     * given an explicit quantity ("6 hours", "40 km"), because the planner knows
     * the hours and the distance even though the model does not.
     *
     * @param array{kind:string, id:int, price:float, unit:string} $offering
     * @return array{ok:bool, error:string, amount:?float, qty:?int, unit:string,
     *               note:string, needs_qty:bool, max_qty:?int}
     */
    public static function quote(int $booking_id, array $offering, ?int $qty): array
    {
        $result = ['ok' => true, 'error' => '', 'amount' => null, 'qty' => null,
                   'unit' => (string) ($offering['unit'] ?? ''), 'note' => '',
                   'needs_qty' => false, 'max_qty' => null];

        $kind  = (string) ($offering['kind'] ?? '');
        $price = (float) ($offering['price'] ?? 0);
        if ($price <= 0) {
            return ['ok' => false, 'error' => 'This offering has no published price — enter the amount you agreed.'] + $result;
        }
        if ($kind === 'package') {
            return ['amount' => round($price, 2), 'qty' => null,
                    'note' => 'the package price'] + $result;
        }

        $unit = (string) ($offering['unit'] ?? 'flat');
        $min  = (int) ($offering['min_qty'] ?? 0);
        $max  = (int) ($offering['max_qty'] ?? 0);
        $guests = VendorFit::guests($booking_id);

        $used = $qty !== null && $qty > 0 ? $qty : null;
        $note = '';
        switch ($unit) {
            case 'flat':
                $used = 1;
                break;
            case 'per_guest':
                if ($used === null) {
                    $used = $guests > 0 ? $guests : ($min > 0 ? $min : null);
                }
                break;
            case 'per_day':
                $used = $used ?? max(1, $min);
                break;
            case 'per_item':
            case 'per_staff':
                $used = $used ?? ($min > 0 ? $min : 1);
                break;
            default: // per_hour, per_km — a human must say how many
                if ($used === null) {
                    return [
                        'ok'        => false,
                        'error'     => 'Enter the ' . (VendorService::units()[$unit][1] ?? 'quantity')
                            . ' you need — this service is quoted per '
                            . (VendorService::units()[$unit][1] ?? 'unit') . ', so a total needs the number.',
                        'needs_qty' => true,
                    ] + $result;
                }
                break;
        }

        if ($used === null) {
            return ['ok' => false, 'error' => 'Add guests to the wedding (or enter a quantity) before planning a per-guest service.', 'needs_qty' => true] + $result;
        }
        if ($min > 0 && $used < $min) {
            $note = 'their minimum is ' . $min . ' — using ' . $min . ' for the estimate';
            $used = $min;
        }
        if ($max > 0 && $used > $max) {
            $note = trim($note . ' their listing says up to ' . $max . ' — confirm they can cover ' . $used);
        }

        $unitLabel = VendorService::units()[$unit][1] ?? 'unit';
        return [
            'amount'    => round($price * $used, 2),
            'qty'       => $used,
            'note'      => $note !== '' ? $note : format_ksh($price) . ' × ' . $used . ' ' . $unitLabel
                . ($used === 1 ? '' : 's'),
            'needs_qty' => false,
            'max_qty'   => $max > 0 ? $max : null,
        ] + $result;
    }

    /**
     * Put a vendor on the wedding's plan: creates the pending deal (the same row
     * a marketplace enquiry creates), records which offering it is for, and
     * tells the vendor account. One transaction — a plan row with no deal would
     * be money with no ledger entry.
     *
     * @param string $kind       service|package|custom (a vendor with no offering)
     * @param int    $offering_id service or package id; 0 for custom
     * @param float  $amount     an agreed figure; 0/negative means "use the quote"
     * @return array{ok:bool, error:string, deal_id:int, amount:float, qty:?int, label:string,
     *               warning:string, unpriced:bool}
     */
    public static function add(
        int $booking_id,
        int $vendor_id,
        string $kind,
        int $offering_id,
        ?int $qty,
        float $amount,
        string $note,
        int $user_id
    ): array {
        $fail = static fn (string $error): array => [
            'ok' => false, 'error' => $error, 'deal_id' => 0, 'amount' => 0.0,
            'qty' => null, 'label' => '', 'warning' => '', 'unpriced' => false,
        ];

        if ($booking_id <= 0 || !self::booking_exists($booking_id)) {
            return $fail('That wedding no longer exists.');
        }
        $vendor = Vendor::find_by_id($vendor_id);
        if (!$vendor || (int) $vendor->is_approved !== 1) {
            return $fail('That vendor is not available. Approve them first, or choose another.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            return $fail('Choose what this vendor is being planned for.');
        }

        $offering = ['kind' => $kind, 'id' => 0, 'price' => 0.0, 'unit' => 'flat'];
        $label = 'Custom arrangement';
        if ($kind === 'service') {
            if (!self::installed()) {
                return $fail('Planning a specific service needs migrations/v2.29_vendor_plan.sql — or plan the vendor with an agreed amount instead.');
            }
            $service = VendorService::find_owned($offering_id, $vendor_id);
            if ($service === null || (int) $service->is_active !== 1) {
                return $fail('That service is no longer listed by this vendor.');
            }
            $offering = [
                'kind' => 'service', 'id' => (int) $service->id,
                'price' => (float) $service->price, 'unit' => (string) $service->pricing_unit,
                'min_qty' => $service->min_qty, 'max_qty' => $service->max_qty,
            ];
            $label = (string) $service->title;
        } elseif ($kind === 'package') {
            $package = self::package_for_vendor($offering_id, $vendor_id);
            if ($package === null) {
                return $fail('That package is no longer listed by this vendor.');
            }
            $offering = ['kind' => 'package', 'id' => (int) $package->id, 'price' => (float) $package->price, 'unit' => ''];
            $label = (string) $package->title;
        }

        $quote = $kind === 'custom'
            ? ['ok' => true, 'error' => '', 'amount' => null, 'qty' => null, 'unit' => '', 'note' => '', 'needs_qty' => false]
            : self::quote($booking_id, $offering, $qty);

        $final = $amount > 0 ? round($amount, 2) : (float) ($quote['amount'] ?? 0);
        if ($final <= 0) {
            return $fail($quote['error'] !== ''
                ? $quote['error']
                : 'Enter the amount agreed with this vendor — a plan line without a price cannot be totalled.');
        }
        $used_qty = $kind === 'service' ? ($quote['qty'] ?? null) : null;

        // Availability, written into the deal's notes as well as returned, so
        // the warning is still there when the planner comes back to confirm.
        $booking = Booking::find_by_booking_id($booking_id);
        $clash = VendorAvailability::clashes(
            $vendor_id,
            VendorAvailability::day(null, null, (string) ($booking->wedding_date ?? '')),
            $booking_id
        );

        $stamp = '[' . date('j M Y') . ' — planned by ' . ($_SESSION['user_email'] ?? 'staff') . '.]';
        $log = $stamp . "\n" . $label
            . ($used_qty !== null ? ' × ' . $used_qty . ' ' . (VendorService::units()[$offering['unit']][1] ?? 'unit') : '')
            . ' — ' . format_ksh($final) . "\n"
            . ($clash !== [] ? '[' . date('j M Y') . ' — WARNING: ' . VendorAvailability::alert($clash) . "]\n" : '')
            . ($note !== '' ? trim(strip_tags($note)) . "\n" : '');

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            if ($kind === 'service' && self::installed()) {
                $db->execute(
                    "INSERT INTO tblvendor_bookings
                        (vendor_id, booking_id, package_id, service_id, service_qty, event_date, amount, commission, status, notes, created_at)
                     VALUES (:v, :b, NULL, :sid, :qty, NULL, :amt, 0, 'pending', :n, NOW())",
                    [
                        ':v'   => $vendor_id, ':b' => $booking_id,
                        ':sid' => $offering_id, ':qty' => $used_qty,
                        ':amt' => $final, ':n' => $log,
                    ]
                );
            } elseif ($kind === 'package') {
                $db->execute(
                    "INSERT INTO tblvendor_bookings
                        (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes, created_at)
                     VALUES (:v, :b, :pid, NULL, :amt, 0, 'pending', :n, NOW())",
                    [':v' => $vendor_id, ':b' => $booking_id, ':pid' => $offering_id, ':amt' => $final, ':n' => $log]
                );
            } else {
                $db->execute(
                    "INSERT INTO tblvendor_bookings
                        (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes, created_at)
                     VALUES (:v, :b, NULL, NULL, :amt, 0, 'pending', :n, NOW())",
                    [':v' => $vendor_id, ':b' => $booking_id, ':amt' => $final, ':n' => $log]
                );
            }
            $deal_id = (int) $db->lastInsertId();

            if ((int) $vendor->user_id > 0) {
                Notification::create_for_vendor(
                    (int) $vendor->user_id,
                    $booking_id,
                    'A planner added ' . $vendor->business_name . ' to a wedding plan: ' . $label . '.',
                    'vendor_enquiries.php'
                );
            }

            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][VendorPlan] ' . $e->getMessage());
            return $fail('The vendor could not be added to the plan. Nothing was saved.');
        }

        return [
            'ok' => true, 'error' => '', 'deal_id' => $deal_id, 'amount' => $final,
            'qty' => $used_qty, 'label' => $label,
            'warning' => $clash !== [] ? VendorAvailability::notice($clash, (string) $vendor->business_name) : '',
            'unpriced' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Formatting
    // ------------------------------------------------------------------

    /**
     * The costing sentence for one deal: "Classic buffet × 180 guests — KSh
     * 216,000", or the package/agreed form when there is no service behind it.
     */
    public static function money_line(object $row): string
    {
        $amount = format_ksh((float) $row->amount);
        $unit = VendorService::units()[(string) ($row->pricing_unit ?? '')][1] ?? '';
        if (($row->offering_kind ?? '') === 'service' && $row->service_qty !== null && $unit !== '') {
            return format_ksh((float) $row->service_price) . ' × ' . (int) $row->service_qty . ' '
                . $unit . ((int) $row->service_qty === 1 ? '' : 's') . ' = ' . $amount;
        }
        return $amount;
    }

    /** What the deal bought, in words: a service, a package, or an agreed arrangement. */
    public static function offering_label(object $row): string
    {
        if (($row->service_id ?? null) !== null && ($row->service_title ?? '') !== '') {
            return (string) $row->service_title;
        }
        if (($row->package_id ?? null) !== null && ($row->package_title ?? '') !== '') {
            return (string) $row->package_title;
        }
        return 'Custom arrangement';
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

    /** @return array{planned:float, committed:float, total:float, caps:float, cancelled:float,
     *                dropped:int, unpriced:int, deals:int, pending:int, confirmed:int,
     *                covered:int, gaps:int, guests:int} */
    private static function zero_totals(): array
    {
        return [
            'planned' => 0.0, 'committed' => 0.0, 'total' => 0.0, 'caps' => 0.0, 'cancelled' => 0.0,
            'dropped' => 0, 'unpriced' => 0, 'deals' => 0, 'pending' => 0, 'confirmed' => 0,
            'covered' => 0, 'gaps' => 0, 'guests' => 0,
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

    private static function package_for_vendor(int $package_id, int $vendor_id): ?object
    {
        if ($package_id <= 0 || $vendor_id <= 0) {
            return null;
        }
        global $db;
        return $db->fetchOne(
            'SELECT * FROM tblvendor_packages WHERE id = :p AND vendor_id = :v LIMIT 1',
            [':p' => $package_id, ':v' => $vendor_id]
        );
    }
}
