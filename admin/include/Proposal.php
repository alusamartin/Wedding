<?php
declare(strict_types=1);

/**
 * Proposal — a couple's registration, turned into a priced wedding they choose
 * from and a confirmed plan.
 *
 * THE GAP THIS FILLS
 * `tblweddingbook` records a wedding; `tblvendor_bookings` records what was
 * dealt; the vendor plan (v2.29) totals it. Between "a couple registered" and
 * "a vendor is booked" there was nothing: the planner priced options in a
 * spreadsheet and the couple chose in a WhatsApp group. This class is that
 * middle: one proposal per wedding, the planner's acknowledgement, at least
 * three priced options built from real vendor offerings, the couple's choice,
 * and a lock that writes the deals.
 *
 * WHY THE PROPOSAL IS NOT A BOOKING STATUS
 * `tblaccounts_detail.status` is the platform's two-state record ('pending' /
 * 'confirm') and pages, reports and tests read it as exactly that. A proposal
 * row therefore lives beside the booking: the booking stays `pending` while the
 * couple decides and becomes `confirm` only at lock() — the same state
 * `client_assign.php` has always written. Nothing that reads a booking status
 * had to change, and a wedding confirmed before v2.30 simply has no proposal.
 *
 * THE SEVEN STATES
 *   proposal          a couple has registered; nobody has picked it up
 *   acknowledged      the planner has; the couple is told
 *   options           at least three priced options have been sent
 *   tweak_requested   the couple asked for a change instead of choosing
 *   chosen            the couple picked one; waiting for the planner to lock it
 *   locked            the deals exist and the wedding is confirmed
 *   declined          the couple walked away (their words are kept)
 * Only the owner moves each step: the planner acknowledges, prices and locks;
 * the couple chooses, asks for a tweak or declines. Every transition notifies
 * the other side through Notification (bell + the platform's email outbox).
 *
 * MONEY
 * An option's `total` is the sum of its items at the moment it was saved — a
 * quote. Items carry the amount per vendor (a package price, a service price ×
 * quantity, or an agreed figure), and `lock()` copies those amounts ONCE into
 * `tblvendor_bookings`, which remains the single record of what was dealt. This
 * class never re-prices an option behind the planner: editing an item is an
 * explicit act on the builder page, and locking uses the amounts the couple was
 * shown.
 *
 * WHAT IT REFUSES
 * A plan line with no price, a vendor that is not approved or does not own the
 * service, a service that has since been hidden, an option with nothing in it,
 * publishing fewer than three options, a lock when a vendor is already booked
 * on that day (unless the planner acknowledges the clash), and any transition
 * from a state that does not allow it. Every refusal is a sentence, not an
 * exception.
 *
 * DEGRADING WHEN v2.30 IS ABSENT
 * `installed()` probes once per request. Without the tables, `enabled()` is
 * still true but every page says which migration to apply and the signup keeps
 * creating ordinary pending bookings — nothing throws.
 */
final class Proposal
{
    /**
     * slug => [label, bootstrap tone, Material Design icon]. Order is the order
     * the queue reads: the work first, then the waiting, then the closed.
     */
    public const STATUSES = [
        'proposal'        => ['New proposal', 'danger', 'email-alert-outline'],
        'acknowledged'    => ['Acknowledged', 'info', 'account-check'],
        'options'         => ['Options sent', 'warning', 'format-list-checks'],
        'tweak_requested' => ['Tweak asked', 'primary', 'pencil-outline'],
        'chosen'          => ['Option chosen', 'primary', 'hand-okay'],
        'locked'          => ['Confirmed', 'success', 'lock-check'],
        'declined'        => ['Declined', 'secondary', 'close-circle-outline'],
    ];

    /** The states a planner still has work in — the sidebar badge counts these. */
    public const OPEN_STATES = ['proposal', 'acknowledged', 'tweak_requested', 'chosen'];

    /** How many priced options a couple must be given. The product promise. */
    public const MIN_OPTIONS = 3;

    /** @return array<string, array{0:string, 1:string, 2:string}> */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /** The label a couple reads for a state (never the enum slug). */
    public static function status_label(string $status): string
    {
        return self::STATUSES[$status][0] ?? 'Proposal';
    }

    /** @return array{0:string, 1:string, 2:string} */
    public static function status_meta(string $status): array
    {
        return self::STATUSES[$status] ?? ['Proposal', 'secondary', 'file-document-outline'];
    }

    /**
     * The feature switch. `WPMS_PROPOSAL_FLOW=0` turns the whole lifecycle off
     * (signup goes back to creating an ordinary pending booking and the pages
     * say so); anything else — including unset — leaves it on, because a flow
     * nobody can find is not a flow. The migration is separate: with the tables
     * absent everything degrades to the old behaviour anyway.
     */
    public static function enabled(): bool
    {
        $flag = getenv('WPMS_PROPOSAL_FLOW');
        return $flag === false || trim((string) $flag) !== '0';
    }

    /** Are migrations/v2.30_proposals.sql's tables present? (One probe per request.) */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }

        global $db;
        try {
            $db->fetchValue('SELECT id FROM tblproposals LIMIT 1');
            return $installed = true;
        } catch (Throwable $e) {
            return $installed = false;
        }
    }

    // ==================================================================
    // CREATION AND READS
    // ==================================================================

    /**
     * Start a proposal for a freshly registered wedding. Idempotent: a booking
     * already carrying a proposal keeps it (and gets it back), so a double
     * submission cannot fork one wedding into two proposals.
     *
     * The brief is what the couple typed, stored as stated: a missing budget
     * stays NULL rather than becoming 0 ("no budget stated" and "a budget of
     * nothing" are different answers), and the preferred planner is only kept
     * when it names a real, verified planner account.
     *
     * @param array{guests?:?int, budget?:?float, venue?:string, taste_tags?:string,
     *              couple_note?:string, preferred_planner_id?:int} $brief
     * @return int The proposal id, or 0 when it could not be created.
     */
    public static function create_for_booking(int $booking_id, array $brief = []): int
    {
        if ($booking_id <= 0 || !self::installed()) {
            return 0;
        }

        $existing = self::find_by_booking($booking_id);
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $guests = isset($brief['guests']) && is_numeric($brief['guests']) && (int) $brief['guests'] > 0
            ? min(5000, (int) $brief['guests']) : null;
        $budget = isset($brief['budget']) && is_numeric($brief['budget']) && (float) $brief['budget'] > 0
            ? round((float) $brief['budget'], 2) : null;
        $preferred = (int) ($brief['preferred_planner_id'] ?? 0);
        if ($preferred > 0 && !self::is_planner($preferred)) {
            $preferred = 0;
        }

        global $db;
        try {
            $db->execute(
                "INSERT INTO tblproposals
                    (booking_id, planner_id, preferred_planner_id, status, guests, budget, venue, taste_tags, couple_note)
                 VALUES (:b, :pid, :pref, 'proposal', :g, :bud, :venue, :tags, :note)",
                [
                    ':b'     => $booking_id,
                    // A planner the couple asked for owns it from the start;
                    // without one it waits in the shared queue (planner_id 0).
                    ':pid'   => $preferred,
                    ':pref'  => $preferred,
                    ':g'     => $guests,
                    ':bud'   => $budget,
                    ':venue' => mb_substr(trim((string) ($brief['venue'] ?? '')), 0, 160),
                    ':tags'  => mb_substr(trim((string) ($brief['taste_tags'] ?? '')), 0, 255),
                    ':note'  => mb_substr(trim((string) ($brief['couple_note'] ?? '')), 0, 500),
                ]
            );
            $id = (int) $db->lastInsertId();

            if ($preferred > 0) {
                Notification::create_for_staff(
                    $preferred,
                    $booking_id,
                    'A couple asked for you: a new wedding proposal is waiting for your acknowledgement.',
                    'proposal_view.php?id=' . $id
                );
            }
            return $id;
        } catch (Throwable $e) {
            error_log('[WPMS][Proposal] create failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        global $db;
        return $db->fetchOne(
            'SELECT p.*, b.wedding_date, b.wedding_type, b.bride, b.groom, b.user_id AS couple_id,
                    b.user_email, a.firstname, a.lastname, a.phone,
                    u.firstname AS planner_first, u.lastname AS planner_last
               FROM tblproposals p
               JOIN tblweddingbook b ON b.booking_id = p.booking_id
               LEFT JOIN tblaccounts_detail a ON a.user_id = b.user_id
               LEFT JOIN tblusers u ON u.id = p.planner_id
              WHERE p.id = :i
              LIMIT 1',
            [':i' => $id]
        );
    }

    public static function find_by_booking(int $booking_id): ?object
    {
        if ($booking_id <= 0 || !self::installed()) {
            return null;
        }
        global $db;
        $row = $db->fetchOne('SELECT * FROM tblproposals WHERE booking_id = :b LIMIT 1', [':b' => $booking_id]);
        return $row ?: null;
    }

    /**
     * The planner's queue. `$planner_scope` is `planner_scope_planner_id()`:
     * 0 for an admin (sees everything), otherwise a planner's own id — who sees
     * proposals they own plus the unassigned ones waiting for someone.
     *
     * @return array<int, object>
     */
    public static function queue(?string $status = null, int $planner_scope = 0): array
    {
        if (!self::installed()) {
            return [];
        }
        global $db;

        $where = [];
        $params = [];
        if ($status !== null && isset(self::STATUSES[$status])) {
            $where[] = 'p.status = :st';
            $params[':st'] = $status;
        }
        if ($planner_scope > 0) {
            $where[] = '(p.planner_id = 0 OR p.planner_id = :pl)';
            $params[':pl'] = $planner_scope;
        }

        return $db->fetchAll(
            'SELECT p.*, b.wedding_date, b.wedding_type, b.bride, b.groom, b.user_id AS couple_id,
                    b.user_email, a.firstname, a.lastname, a.phone,
                    u.firstname AS planner_first, u.lastname AS planner_last,
                    (SELECT COUNT(*) FROM tblproposal_options o WHERE o.proposal_id = p.id) AS option_count
               FROM tblproposals p
               JOIN tblweddingbook b ON b.booking_id = p.booking_id
               LEFT JOIN tblaccounts_detail a ON a.user_id = b.user_id
               LEFT JOIN tblusers u ON u.id = p.planner_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY FIELD(p.status, ' . self::status_order_sql() . '), p.created_at ASC',
            $params
        );
    }

    /** `FIELD()` list, so the queue reads work-first in SQL rather than in PHP. */
    private static function status_order_sql(): string
    {
        return "'" . implode("','", array_keys(self::STATUSES)) . "'";
    }

    /** How many proposals still need this planner (the sidebar badge). */
    public static function open_count(int $planner_scope = 0): int
    {
        if (!self::installed()) {
            return 0;
        }
        global $db;
        $params = [];
        $where = "p.status IN ('" . implode("','", self::OPEN_STATES) . "')";
        if ($planner_scope > 0) {
            $where .= ' AND (p.planner_id = 0 OR p.planner_id = :pl)';
            $params[':pl'] = $planner_scope;
        }
        try {
            return (int) $db->fetchValue('SELECT COUNT(*) FROM tblproposals p WHERE ' . $where, $params);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Does this wedding have a proposal? Used by the couple's navigation. */
    public static function for_couple(int $booking_id, int $couple_id): ?object
    {
        $proposal = self::find_by_booking($booking_id);
        if ($proposal === null || (int) $proposal->booking_id !== $booking_id) {
            return null;
        }
        return $proposal;
    }

    // ==================================================================
    // THE PLANNER'S STEPS
    // ==================================================================

    /**
     * Pick the proposal up. The couple is told (bell + email) because their
     * first question after registering is "did anyone see this?".
     *
     * @return array{ok:bool, error:string}
     */
    public static function acknowledge(int $proposal_id, int $user_id): array
    {
        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return ['ok' => false, 'error' => 'That proposal no longer exists.'];
        }
        if (!in_array((string) $proposal->status, ['proposal', 'acknowledged'], true)) {
            return ['ok' => false, 'error' => 'This proposal has already moved on (' . self::status_label((string) $proposal->status) . ').'];
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $db->execute(
                "UPDATE tblproposals
                    SET status = 'acknowledged', planner_id = :pl, acknowledged_at = NOW(), acknowledged_by = :by
                  WHERE id = :i",
                [':pl' => $user_id, ':by' => $user_id, ':i' => $proposal_id]
            );

            Notification::create_for_couple(
                (int) $proposal->couple_id,
                (int) $proposal->booking_id,
                'Your planner has picked up your wedding and is pricing your options now — you will see them here.',
                'client_proposal.php?booking_id=' . (int) $proposal->booking_id . '&user_id=' . (int) $proposal->couple_id
            );

            if ($own) {
                $db->commit();
            }
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][Proposal] acknowledge failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The acknowledgement could not be saved. Nothing changed.'];
        }
    }

    /**
     * Create an empty option the planner then fills with vendors.
     *
     * @return array{ok:bool, error:string, id:int}
     */
    public static function create_option(int $proposal_id, string $title, string $tier, string $summary, int $user_id): array
    {
        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return ['ok' => false, 'error' => 'That proposal no longer exists.', 'id' => 0];
        }
        if (in_array((string) $proposal->status, ['locked', 'declined'], true)) {
            return ['ok' => false, 'error' => 'This proposal is closed; its options cannot change.', 'id' => 0];
        }
        $title = mb_substr(trim($title), 0, 160);
        if ($title === '') {
            return ['ok' => false, 'error' => 'Give the option a name the couple will recognise.', 'id' => 0];
        }

        global $db;
        try {
            $db->execute(
                'INSERT INTO tblproposal_options (proposal_id, title, tier, summary, total, created_by)
                 VALUES (:p, :t, :tier, :sum, 0, :by)',
                [
                    ':p' => $proposal_id,
                    ':t' => $title,
                    ':tier' => mb_substr(trim($tier) !== '' ? trim($tier) : 'recommended', 0, 40),
                    ':sum' => mb_substr(trim($summary), 0, 500),
                    ':by' => $user_id,
                ]
            );
            return ['ok' => true, 'error' => '', 'id' => (int) $db->lastInsertId()];
        } catch (Throwable $e) {
            error_log('[WPMS][Proposal] option create failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The option could not be saved.', 'id' => 0];
        }
    }

    /** @return array{ok:bool, error:string} */
    public static function update_option(int $proposal_id, int $option_id, string $title, string $tier, string $summary): array
    {
        $option = self::option($proposal_id, $option_id);
        if ($option === null) {
            return ['ok' => false, 'error' => 'That option is not part of this proposal.'];
        }
        if (trim($title) === '') {
            return ['ok' => false, 'error' => 'Give the option a name the couple will recognise.'];
        }

        global $db;
        $db->execute(
            'UPDATE tblproposal_options SET title = :t, tier = :tier, summary = :sum WHERE id = :i AND proposal_id = :p',
            [
                ':t' => mb_substr(trim($title), 0, 160),
                ':tier' => mb_substr(trim($tier) !== '' ? trim($tier) : 'recommended', 0, 40),
                ':sum' => mb_substr(trim($summary), 0, 500),
                ':i' => $option_id,
                ':p' => $proposal_id,
            ]
        );
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Put a vendor on one option. The offering is resolved against the vendor
     * itself (a planner must not buy another vendor's service through this
     * door), the amount comes from the same quote the vendor plan uses, and an
     * amount the planner typed wins — an agreed figure is not arithmetic.
     *
     * @param array{kind:string, id:int, price:float, unit:string, min_qty?:?int, max_qty?:?int} $offering
     * @return array{ok:bool, error:string, item_id:int, amount:float, label:string}
     */
    public static function add_item(
        int $proposal_id,
        int $option_id,
        int $vendor_id,
        array $offering,
        ?int $qty,
        float $amount,
        string $note,
        string $label
    ): array {
        $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'item_id' => 0, 'amount' => 0.0, 'label' => ''];

        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return $fail('That proposal no longer exists.');
        }
        if (in_array((string) $proposal->status, ['locked', 'declined'], true)) {
            return $fail('This proposal is closed; its options cannot change.');
        }
        $option = self::option($proposal_id, $option_id);
        if ($option === null) {
            return $fail('That option is not part of this proposal.');
        }

        $vendor = Vendor::find_by_id($vendor_id);
        if (!$vendor || (int) $vendor->is_approved !== 1) {
            return $fail('That vendor is not available. Approve them first, or choose another.');
        }

        $kind = (string) ($offering['kind'] ?? 'custom');
        $service_id = 0;
        $package_id = 0;
        $used_qty = null;
        $final = 0.0;
        $display = mb_substr(trim($label), 0, 160);

        /*
         * Which level of the vendor's offer this line buys (v3.1). Unknown or
         * missing means "no tier chosen", which prices at the vendor's own
         * price — the Silver level by construction. A tier that the vendor has
         * not priced is refused rather than silently charged at Silver: the
         * couple must not be quoted "Gold" and charged for the working menu.
         */
        $tier = VendorTier::normalise_tier($offering['tier'] ?? null);
        if ($kind === 'custom') {
            $tier = null; // a negotiated amount is not a level of a published offer
        }

        if ($kind === 'service') {
            $service_id = (int) ($offering['id'] ?? 0);
            $service = VendorService::find_owned($service_id, $vendor_id);
            if ($service === null || (int) $service->is_active !== 1) {
                return $fail('That service is no longer listed by this vendor.');
            }
            $tiers = $tier !== null ? VendorTier::for_service($service_id) : [];
            if ($tier !== null && !isset($tiers[$tier])) {
                return $fail('That vendor has not priced their ' . VendorTier::label($tier) . ' tier for "' . (string) $service->title . '" — choose another tier, or remove the tier.');
            }
            $price = VendorTier::price($tiers, $tier, (float) $service->price);
            $display = $display !== '' ? $display : (string) $service->title;
            $quote = VendorPlan::quote((int) $proposal->booking_id, [
                'kind' => 'service',
                'id' => $service_id,
                'price' => $price,
                'unit' => (string) $service->pricing_unit,
                'min_qty' => $service->min_qty,
                'max_qty' => $service->max_qty,
            ], $qty);
            $final = $amount > 0 ? round($amount, 2) : (float) ($quote['amount'] ?? 0);
            $used_qty = $quote['qty'] ?? null;
            if ($final <= 0) {
                return $fail($quote['error'] !== ''
                    ? (string) $quote['error']
                    : 'Enter the amount agreed with this vendor — a line without a price cannot be totalled.');
            }
        } elseif ($kind === 'package') {
            $package_id = (int) ($offering['id'] ?? 0);
            $package = self::package_for_vendor($package_id, $vendor_id);
            if ($package === null) {
                return $fail('That package is no longer listed by this vendor.');
            }
            $pkg_tiers = $tier !== null ? VendorTier::for_package($package_id) : [];
            if ($tier !== null && !isset($pkg_tiers[$tier])) {
                return $fail('That vendor has not priced their ' . VendorTier::label($tier) . ' tier for "' . (string) $package->title . '" — choose another tier, or remove the tier.');
            }
            $pkg_price = VendorTier::price($pkg_tiers, $tier, (float) $package->price);
            $display = $display !== '' ? $display : (string) $package->title;
            $final = $amount > 0 ? round($amount, 2) : round($pkg_price, 2);
            if ($final <= 0) {
                return $fail('That package has no price — enter the amount agreed.');
            }
        } else {
            $final = round($amount, 2);
            if ($final <= 0) {
                return $fail('Enter the amount agreed with this vendor for a custom arrangement.');
            }
            $display = $display !== '' ? $display : 'Custom arrangement';
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            // The tier column is optional (v3.1): an install without the
            // migration keeps building options, it just cannot record which
            // level a line was priced at.
            if (self::tiers_installed()) {
                $db->execute(
                    'INSERT INTO tblproposal_items'
                        . ' (option_id, vendor_id, kind, tier, service_id, package_id, service_qty, label, amount, note)'
                        . ' VALUES (:o, :v, :k, :tier, :sid, :pid, :qty, :label, :amt, :note)',
                    [
                        ':o' => $option_id,
                        ':v' => $vendor_id,
                        ':k' => $kind,
                        ':tier' => $tier,
                        ':sid' => $service_id > 0 ? $service_id : null,
                        ':pid' => $package_id > 0 ? $package_id : null,
                        ':qty' => $used_qty,
                        ':label' => $display,
                        ':amt' => $final,
                        ':note' => mb_substr(trim($note), 0, 255),
                    ]
                );
            } else {
                $db->execute(
                    'INSERT INTO tblproposal_items'
                        . ' (option_id, vendor_id, kind, service_id, package_id, service_qty, label, amount, note)'
                        . ' VALUES (:o, :v, :k, :sid, :pid, :qty, :label, :amt, :note)',
                    [
                        ':o' => $option_id,
                        ':v' => $vendor_id,
                        ':k' => $kind,
                        ':sid' => $service_id > 0 ? $service_id : null,
                        ':pid' => $package_id > 0 ? $package_id : null,
                        ':qty' => $used_qty,
                        ':label' => $display,
                        ':amt' => $final,
                        ':note' => mb_substr(trim($note), 0, 255),
                    ]
                );
            }
            $item_id = (int) $db->lastInsertId();
            self::recalculate($option_id);
            if ($own) {
                $db->commit();
            }
            return ['ok' => true, 'error' => '', 'item_id' => $item_id, 'amount' => $final, 'label' => $display];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][Proposal] item add failed: ' . $e->getMessage());
            return $fail('The vendor could not be added to this option. Nothing was saved.');
        }
    }

    /** @return array{ok:bool, error:string} */
    public static function remove_item(int $proposal_id, int $item_id): array
    {
        $item = self::item($proposal_id, $item_id);
        if ($item === null) {
            return ['ok' => false, 'error' => 'That line is not part of this proposal.'];
        }
        if ((int) ($item->deal_id ?? 0) > 0) {
            return ['ok' => false, 'error' => 'This line is already a real deal — cancel the deal in the vendor ledger instead.'];
        }

        global $db;
        $db->execute('DELETE FROM tblproposal_items WHERE id = :i', [':i' => $item_id]);
        self::recalculate((int) $item->option_id);
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool, error:string} */
    public static function delete_option(int $proposal_id, int $option_id): array
    {
        $option = self::option($proposal_id, $option_id);
        if ($option === null) {
            return ['ok' => false, 'error' => 'That option is not part of this proposal.'];
        }
        if ((int) ($option->is_chosen ?? 0) === 1) {
            return ['ok' => false, 'error' => 'The couple chose this option — it cannot be deleted.'];
        }

        global $db;
        $db->execute('DELETE FROM tblproposal_options WHERE id = :i AND proposal_id = :p', [':i' => $option_id, ':p' => $proposal_id]);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Send the options to the couple. Refused below three priced options: the
     * product promise is a choice, and one option is not a choice.
     *
     * @return array{ok:bool, error:string}
     */
    public static function publish(int $proposal_id, int $user_id): array
    {
        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return ['ok' => false, 'error' => 'That proposal no longer exists.'];
        }
        if (!in_array((string) $proposal->status, ['acknowledged', 'options', 'tweak_requested'], true)) {
            return ['ok' => false, 'error' => 'Options cannot be sent from ' . self::status_label((string) $proposal->status) . '.'];
        }

        $priced = 0;
        foreach (self::options($proposal_id) as $option) {
            if ((float) $option->total > 0 && $option->items !== []) {
                $priced++;
            }
        }
        if ($priced < self::MIN_OPTIONS) {
            return [
                'ok' => false,
                'error' => 'A couple must be able to choose. Add at least ' . self::MIN_OPTIONS
                    . ' priced options — ' . $priced . ' ready so far.',
            ];
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $db->execute(
                "UPDATE tblproposals
                    SET status = 'options', planner_id = CASE WHEN planner_id = 0 THEN :pl ELSE planner_id END,
                        published_at = NOW(), tweak_note = ''
                  WHERE id = :i",
                [':pl' => $user_id, ':i' => $proposal_id]
            );

            Notification::create_for_couple(
                (int) $proposal->couple_id,
                (int) $proposal->booking_id,
                $priced . ' wedding options are ready for you to choose from.',
                'client_proposal.php?booking_id=' . (int) $proposal->booking_id . '&user_id=' . (int) $proposal->couple_id
            );

            if ($own) {
                $db->commit();
            }
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][Proposal] publish failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The options could not be sent. Nothing changed.'];
        }
    }

    // ==================================================================
    // THE COUPLE'S STEPS
    // ==================================================================

    /**
     * The couple picks an option. Only a published one can be chosen, and the
     * choice is theirs — this method never locks anything; the planner locks.
     *
     * @return array{ok:bool, error:string}
     */
    public static function choose(int $proposal_id, int $option_id): array
    {
        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return ['ok' => false, 'error' => 'That proposal no longer exists.'];
        }
        if ((string) $proposal->status !== 'options' && (string) $proposal->status !== 'tweak_requested') {
            return ['ok' => false, 'error' => 'There is nothing to choose yet.'];
        }
        $option = self::option($proposal_id, $option_id);
        if ($option === null) {
            return ['ok' => false, 'error' => 'That option is not part of this proposal.'];
        }
        if ((float) $option->total <= 0) {
            return ['ok' => false, 'error' => 'That option has no price — ask your planner before choosing it.'];
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $db->execute('UPDATE tblproposal_options SET is_chosen = 0 WHERE proposal_id = :p', [':p' => $proposal_id]);
            $db->execute('UPDATE tblproposal_options SET is_chosen = 1 WHERE id = :i AND proposal_id = :p', [':i' => $option_id, ':p' => $proposal_id]);
            $db->execute(
                "UPDATE tblproposals
                    SET status = 'chosen', chosen_option_id = :o, chosen_at = NOW(), tweak_note = ''
                  WHERE id = :i",
                [':o' => $option_id, ':i' => $proposal_id]
            );

            if ((int) $proposal->planner_id > 0) {
                Notification::create_for_staff(
                    (int) $proposal->planner_id,
                    (int) $proposal->booking_id,
                    'The couple chose "' . (string) $option->title . '" — lock it in when you are ready.',
                    'proposal_view.php?id=' . $proposal_id
                );
            }

            if ($own) {
                $db->commit();
            }
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][Proposal] choose failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Your choice could not be saved. Nothing changed.'];
        }
    }

    /** @return array{ok:bool, error:string} */
    public static function request_tweak(int $proposal_id, string $note): array
    {
        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return ['ok' => false, 'error' => 'That proposal no longer exists.'];
        }
        if (!in_array((string) $proposal->status, ['options', 'tweak_requested'], true)) {
            return ['ok' => false, 'error' => 'There are no options to change yet.'];
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') {
            return ['ok' => false, 'error' => 'Tell your planner what you would like to change.'];
        }

        global $db;
        $db->execute("UPDATE tblproposals SET status = 'tweak_requested', tweak_note = :n WHERE id = :i", [':n' => $note, ':i' => $proposal_id]);

        if ((int) $proposal->planner_id > 0) {
            Notification::create_for_staff(
                (int) $proposal->planner_id,
                (int) $proposal->booking_id,
                'The couple asked for a change to their options: ' . $note,
                'proposal_view.php?id=' . $proposal_id
            );
        }
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool, error:string} */
    public static function decline(int $proposal_id, string $note): array
    {
        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return ['ok' => false, 'error' => 'That proposal no longer exists.'];
        }
        if (in_array((string) $proposal->status, ['locked', 'declined'], true)) {
            return ['ok' => false, 'error' => 'This proposal is already closed.'];
        }
        $note = mb_substr(trim($note), 0, 500);

        global $db;
        $db->execute("UPDATE tblproposals SET status = 'declined', decline_note = :n WHERE id = :i", [':n' => $note, ':i' => $proposal_id]);

        if ((int) $proposal->planner_id > 0) {
            Notification::create_for_staff(
                (int) $proposal->planner_id,
                (int) $proposal->booking_id,
                'The couple declined their proposal' . ($note !== '' ? ': ' . $note : '.'),
                'proposal_view.php?id=' . $proposal_id
            );
        }
        return ['ok' => true, 'error' => ''];
    }

    // ==================================================================
    // LOCK: THE OPTION BECOMES REAL DEALS
    // ==================================================================

    /**
     * Turn the chosen option into vendor deals and confirm the wedding.
     *
     * ORDER MATTERS, AND NOTHING HALF-HAPPENS
     *   1. Re-check every line against the vendor (still approved, service still
     *      listed) and the calendar. A clash BLOCKS unless the planner has
     *      acknowledged it in the same request — a double-booked wedding is the
     *      one failure the couple would discover on the day.
     *   2. Create the deals through `VendorPlan::add()`, the same door the
     *      planner's plan page uses, so the vendor is notified, the notes trail
     *      says who and when, and the availability warning is recorded.
     *   3. Mark the option chosen, the proposal locked, and the booking confirmed
     *      — the state `client_assign.php` writes — then tell the couple.
     * Any failure rolls the whole thing back: a locked proposal with three of
     * five vendors is worse than an unlocked one.
     *
     * @return array{ok:bool, error:string, deals:int, total:float, warnings:array<int,string>}
     */
    public static function lock(int $proposal_id, int $user_id, bool $acknowledge_clash = false): array
    {
        $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'deals' => 0, 'total' => 0.0, 'warnings' => []];

        $proposal = self::find($proposal_id);
        if ($proposal === null) {
            return $fail('That proposal no longer exists.');
        }
        if ((string) $proposal->status === 'locked') {
            return $fail('This proposal is already confirmed.');
        }
        if ((string) $proposal->status !== 'chosen') {
            return $fail('The couple has not chosen an option yet.');
        }
        $option = self::option($proposal_id, (int) $proposal->chosen_option_id);
        if ($option === null || $option->items === []) {
            return $fail('The chosen option has no vendors in it — nothing to lock.');
        }

        $booking_id = (int) $proposal->booking_id;
        $wedding_day = VendorAvailability::day(null, null, (string) ($proposal->wedding_date ?? ''));
        $clashes = [];

        // ---- 1. Re-check every line before writing anything. --------------
        foreach ($option->items as $item) {
            $vendor = Vendor::find_by_id((int) $item->vendor_id);
            if (!$vendor || (int) $vendor->is_approved !== 1) {
                return $fail((string) $item->label . ': that vendor is no longer approved.');
            }
            if ((string) $item->kind === 'service') {
                $service = VendorService::find_owned((int) $item->service_id, (int) $item->vendor_id);
                if ($service === null || (int) $service->is_active !== 1) {
                    return $fail((string) $item->label . ': that service is no longer listed by the vendor.');
                }
            }
            $found = VendorAvailability::clashes((int) $item->vendor_id, $wedding_day, $booking_id);
            if ($found !== []) {
                $clashes[] = (string) $vendor->business_name . ' — ' . VendorAvailability::alert($found);
            }
        }
        if ($clashes !== [] && !$acknowledge_clash) {
            return $fail(
                'Before this is confirmed: ' . implode(' ', $clashes)
                . ' Tick the box to confirm it anyway, or change the option.'
            );
        }

        // ---- 2. Write the deals, all inside one transaction. -------------
        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $deals = 0;
            $warnings = [];
            foreach ($option->items as $item) {
                $kind = in_array((string) $item->kind, VendorPlan::KINDS, true) ? (string) $item->kind : 'custom';
                $offering_id = $kind === 'service' ? (int) $item->service_id : ($kind === 'package' ? (int) $item->package_id : 0);
                $result = VendorPlan::add(
                    $booking_id,
                    (int) $item->vendor_id,
                    $kind,
                    $offering_id,
                    $item->service_qty !== null ? (int) $item->service_qty : null,
                    (float) $item->amount,
                    'Locked from proposal option "' . (string) $option->title . '".' . ((string) $item->note !== '' ? ' ' . (string) $item->note : ''),
                    $user_id
                );
                if (!$result['ok']) {
                    throw new RuntimeException((string) $result['error']);
                }
                $db->execute('UPDATE tblproposal_items SET deal_id = :d WHERE id = :i', [':d' => $result['deal_id'], ':i' => $item->id]);
                $deals++;
                if (($result['warning'] ?? '') !== '') {
                    $warnings[] = (string) $result['warning'];
                }
                // The package's guest limit, stated where the deal was made.
                $position = PackageCapacity::deal_position($booking_id, (int) $result['deal_id']);
                if ($position !== []) {
                    $warnings[] = PackageCapacity::notice($position, (string) $item->label);
                }
            }

            /*
             * The wedding has a plan now, so its checklist stops being generic:
             * the Kenyan template plus one "confirm this vendor" line per
             * booked vendor (Checklist::ensure_from_option reads the vendor's
             * name for itself). Seeded HERE rather than on first open so the
             * couple's dashboard shows real progress the moment they are
             * confirmed — and best-effort on purpose: a checklist is not the
             * wedding, so it must never be the reason a confirmation rolls
             * back. Both seeders are idempotent by title, so a re-run or a
             * couple who already ticked items loses nothing.
             */
            try {
                Checklist::ensure_seeded($booking_id);
                Checklist::ensure_from_option($booking_id, $option->items);
            } catch (Throwable $e) {
                error_log('[WPMS][Proposal] checklist seed failed: ' . $e->getMessage());
            }

            $db->execute(
                "UPDATE tblproposals
                    SET status = 'locked', locked_at = NOW(), locked_by = :by,
                        planner_id = CASE WHEN planner_id = 0 THEN :by2 ELSE planner_id END
                  WHERE id = :i",
                [':by' => $user_id, ':by2' => $user_id, ':i' => $proposal_id]
            );

            // The wedding is now confirmed — the same state client_assign.php
            // writes, so every page that asks "is this wedding on?" agrees.
            $couple_id = (int) $proposal->couple_id;
            $db->execute("UPDATE tblaccounts_detail SET status = 'confirm', date_signed = NOW() WHERE user_id = :u", [':u' => $couple_id]);
            $db->execute(
                'UPDATE tblweddingbook SET organizer_id = CASE WHEN organizer_id = 0 THEN :pl ELSE organizer_id END WHERE booking_id = :b',
                [':pl' => $user_id, ':b' => $booking_id]
            );

            Notification::create_for_couple(
                $couple_id,
                $booking_id,
                'Your wedding is confirmed! ' . (string) $option->title . ' — ' . format_ksh((float) $option->total)
                . ' across ' . $deals . ' vendor' . ($deals === 1 ? '' : 's') . '. Your planner will be in touch with next steps.',
                'client_vendor_plan.php?booking_id=' . $booking_id . '&user_id=' . $couple_id
            );

            if ($own) {
                $db->commit();
            }
            return ['ok' => true, 'error' => '', 'deals' => $deals, 'total' => (float) $option->total, 'warnings' => $warnings];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][Proposal] lock failed: ' . $e->getMessage());
            return $fail($e instanceof RuntimeException && $e->getMessage() !== ''
                ? $e->getMessage()
                : 'The proposal could not be confirmed. Nothing was saved — try again.');
        }
    }

    // ==================================================================
    // OPTIONS, ITEMS AND TOTALS
    // ==================================================================

    /**
     * Every option on a proposal, each carrying its items and a total summed
     * from those items. The SUM is the display truth (an option cannot show a
     * figure its lines do not add up to), while `total` on the row stays the
     * quote that was saved for the couple.
     *
     * @return array<int, object>
     */
    /**
     * Does tblproposal_items carry the v3.1 `tier` column? (One probe per
     * request.) Probed separately from VendorTier::installed() because the
     * tier TABLES and the tier COLUMN ship in the same migration but a
     * half-applied database should still be usable: prices can be read, only
     * the record of which level a line bought is unavailable.
     */
    public static function tiers_installed(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        global $db;
        try {
            $db->fetchValue('SELECT tier FROM tblproposal_items LIMIT 1');
            return $has = true;
        } catch (Throwable $e) {
            return $has = false;
        }
    }

    public static function options(int $proposal_id): array
    {
        if ($proposal_id <= 0) {
            return [];
        }
        global $db;
        $options = $db->fetchAll(
            'SELECT * FROM tblproposal_options WHERE proposal_id = :p ORDER BY total ASC, id ASC',
            [':p' => $proposal_id]
        );
        if ($options === []) {
            return [];
        }

        $ids = array_map(static fn (object $o): int => (int) $o->id, $options);
        $items = $db->fetchAll(
            'SELECT i.*, v.business_name, v.category
               FROM tblproposal_items i
               JOIN tblvendors v ON v.id = i.vendor_id
              WHERE i.option_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY i.id ASC',
            $ids
        );

        $byOption = [];
        foreach ($items as $item) {
            $byOption[(int) $item->option_id][] = $item;
        }
        foreach ($options as $option) {
            $option->items = $byOption[(int) $option->id] ?? [];
            $sum = 0.0;
            foreach ($option->items as $item) {
                $sum += (float) $item->amount;
            }
            $option->items_total = round($sum, 2);
        }
        return $options;
    }

    public static function option(int $proposal_id, int $option_id): ?object
    {
        if ($proposal_id <= 0 || $option_id <= 0) {
            return null;
        }
        foreach (self::options($proposal_id) as $option) {
            if ((int) $option->id === $option_id) {
                return $option;
            }
        }
        return null;
    }

    /** One line of an option, scoped to the proposal that owns it. */
    public static function item(int $proposal_id, int $item_id): ?object
    {
        if ($proposal_id <= 0 || $item_id <= 0) {
            return null;
        }
        global $db;
        return $db->fetchOne(
            'SELECT i.* FROM tblproposal_items i
               JOIN tblproposal_options o ON o.id = i.option_id
              WHERE i.id = :i AND o.proposal_id = :p
              LIMIT 1',
            [':i' => $item_id, ':p' => $proposal_id]
        );
    }

    /** Keep an option's stored quote equal to its lines. */
    private static function recalculate(int $option_id): void
    {
        global $db;
        $total = (float) $db->fetchValue(
            'SELECT COALESCE(SUM(amount), 0) FROM tblproposal_items WHERE option_id = :o',
            [':o' => $option_id]
        );
        $db->execute('UPDATE tblproposal_options SET total = :t WHERE id = :i', [':t' => round($total, 2), ':i' => $option_id]);
    }

    // ==================================================================
    // PRESENTATION HELPERS
    // ==================================================================

    /** The couple's name, for headings: names, then the account holder, then a neutral phrase. */
    public static function couple_name(object $proposal): string
    {
        $bride = trim((string) ($proposal->bride ?? ''));
        $groom = trim((string) ($proposal->groom ?? ''));
        if ($bride !== '' && $groom !== '') {
            return $bride . ' & ' . $groom;
        }
        if ($bride !== '' || $groom !== '') {
            return $bride !== '' ? $bride : $groom;
        }
        $first = trim((string) ($proposal->firstname ?? ''));
        $last = trim((string) ($proposal->lastname ?? ''));
        $account = trim($first . ' ' . $last);
        return $account !== '' ? $account : 'A couple';
    }

    /** The planner's own name, or '' when the proposal is unassigned. */
    public static function planner_name(object $proposal): string
    {
        $first = trim((string) ($proposal->planner_first ?? ''));
        $last = trim((string) ($proposal->planner_last ?? ''));
        return trim($first . ' ' . $last);
    }

    /**
     * The steps the couple sees, with the current one marked. Derived, never
     * stored, so it can never disagree with the status column.
     *
     * @return array<int, array{key:string, label:string, done:bool, current:bool}>
     */
    public static function steps(object $proposal): array
    {
        $status = (string) $proposal->status;
        $order = ['proposal' => 'Sent', 'acknowledged' => 'Picked up', 'options' => 'Options', 'chosen' => 'Your choice', 'locked' => 'Confirmed'];
        if ($status === 'tweak_requested') {
            $order = ['proposal' => 'Sent', 'acknowledged' => 'Picked up', 'options' => 'Options', 'tweak_requested' => 'Your change', 'chosen' => 'Your choice', 'locked' => 'Confirmed'];
        }
        $rank = array_flip(array_keys($order));
        $current = $rank[$status] ?? 0;
        $steps = [];
        $i = 0;
        foreach ($order as $key => $label) {
            $steps[] = ['key' => $key, 'label' => $label, 'done' => $i < $current, 'current' => $i === $current];
            $i++;
        }
        if ($status === 'declined') {
            $steps[] = ['key' => 'declined', 'label' => 'Declined', 'done' => false, 'current' => true];
        }
        return $steps;
    }

    /** Human phrase for how long a proposal has been waiting, e.g. "2 d 4 h". */
    public static function waiting_for(object $proposal): string
    {
        // Which clock is running? The one belonging to the current state: an
        // acknowledgement is waiting on its own stamp, published options on
        // theirs, and a proposal nobody has touched on when it arrived.
        $status = (string) $proposal->status;
        $stamp  = (string) ($proposal->created_at ?? '');
        if (in_array($status, ['options', 'chosen'], true) && (string) ($proposal->published_at ?? '') !== '') {
            $stamp = (string) $proposal->published_at;
        } elseif ($status === 'acknowledged' && (string) ($proposal->acknowledged_at ?? '') !== '') {
            $stamp = (string) $proposal->acknowledged_at;
        }
        if ($stamp === '') {
            return '';
        }
        $ts = strtotime($stamp);
        if ($ts === false) {
            return '';
        }
        $mins = max(0, (int) floor((time() - $ts) / 60));
        if ($mins < 60) {
            return $mins . ' m';
        }
        $hours = intdiv($mins, 60);
        if ($hours < 24) {
            return $hours . ' h ' . ($mins % 60) . ' m';
        }
        return intdiv($hours, 24) . ' d ' . ($hours % 24) . ' h';
    }

    /** Is this id a real, verified planner account? (One query.) */
    public static function is_planner(int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        global $db;
        try {
            return (int) $db->fetchValue(
                "SELECT COUNT(*) FROM tblusers WHERE id = :i AND access_level = '1'",
                [':i' => $user_id]
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<int, object> Verified planners, for the sign-up form's optional choice. */
    public static function planners(): array
    {
        global $db;
        try {
            return $db->fetchAll(
                "SELECT id, firstname, lastname, designation
                   FROM tblusers
                  WHERE access_level = '1' AND email_verified = 1
                  ORDER BY firstname ASC, lastname ASC"
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<int, object> Approved vendors with their offerings, for the builder's picker. */
    public static function vendor_offerings(): array
    {
        return VendorPlan::offerings();
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
