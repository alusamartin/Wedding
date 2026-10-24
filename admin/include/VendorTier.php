<?php

/**
 * Three levels of the same offer — Silver, Gold, Platinum — with a price,
 * what each level includes and what it leaves out (v3.1).
 *
 * WHY THIS EXISTS
 * A vendor does not sell "catering at KSh 1,200": they sell a working buffet,
 * one with a live grill, and one with imported crockery and two chefs. With a
 * single price column, a planner comparing two caterers compared the cheapest
 * tier of one with the best tier of the other and called the difference a
 * bargain. The tiers put the comparison back on the same level, and the
 * excludes column is where the half of the deal couples usually discover late
 * is finally written down.
 *
 * THE ONE RULE THAT KEEPS EVERYTHING ELSE WORKING
 * `tblvendor_services.price` / `tblvendor_packages.price` STAY the Silver price.
 * Saving tiers writes Silver back into that column, so the marketplace cards,
 * VendorFit's arithmetic, the coverage board, the vendor plan and the proposal
 * builder — all of which read a single price — keep working and now mean "entry
 * level". Nothing has to know tiers exist to stay correct; the surfaces that
 * DO know just show the other two.
 *
 * Ownership is checked here, not by the caller: a vendor can only price their
 * own service or package, and a wrong id is refused rather than written.
 */
class VendorTier
{
    /** Cheapest first — the order every page renders them in. */
    public const TIERS = ['silver', 'gold', 'platinum'];

    /** The tier whose price is mirrored onto the base row. */
    public const BASE = 'silver';

    /** Longest inclusions/exclusions we store, per tier. */
    public const MAX_TEXT = 500;

    /** A price above this is a typo, not an offer. */
    public const MAX_PRICE = 100000000;

    /** @var array<string, string> */
    private const LABELS = [
        'silver'   => 'Silver',
        'gold'     => 'Gold',
        'platinum' => 'Platinum',
    ];

    // ------------------------------------------------------------------
    // Names
    // ------------------------------------------------------------------

    /** @return array<string, string> tier => human label, cheapest first */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /** "Silver" — or '' when no tier was chosen (a custom line). */
    public static function label(?string $tier): string
    {
        $tier = strtolower(trim((string) $tier));
        return self::LABELS[$tier] ?? '';
    }

    /** Is $tier one of the three? ('silver' for anything unrecognised.) */
    public static function normalise_tier(?string $tier): ?string
    {
        $tier = strtolower(trim((string) $tier));
        return in_array($tier, self::TIERS, true) ? $tier : null;
    }

    /**
     * Are migrations/v3.1_vendor_service_tiers.sql's tables there? (One probe
     * per request.) The extra column on tblproposal_items is probed separately
     * by Proposal, so an install with the tables but not the column still shows
     * tiers on the marketplaces.
     */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }
        global $db;
        try {
            $db->fetchValue('SELECT COUNT(*) FROM tblvendor_service_tiers');
            $db->fetchValue('SELECT COUNT(*) FROM tblvendor_package_tiers');
            return $installed = true;
        } catch (Throwable $e) {
            return $installed = false;
        }
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * One service's tiers, keyed by tier, cheapest first. Tiers the vendor has
     * not filled are simply absent from the array.
     *
     * @return array<string, object>
     */
    public static function for_service(int $service_id): array
    {
        return self::rows('service_id', $service_id);
    }

    /**
     * One package's tiers, keyed by tier.
     *
     * @return array<string, object>
     */
    public static function for_package(int $package_id): array
    {
        return self::rows('package_id', $package_id);
    }

    /**
     * Tiers for MANY services in one query, for the marketplace pages that
     * render a dozen cards at a time. Ids with no tiers are absent from the map.
     *
     * @param int[] $service_ids
     * @return array<int, array<string, object>>
     */
    public static function map_for_services(array $service_ids): array
    {
        return self::map('tblvendor_service_tiers', 'service_id', $service_ids);
    }

    /**
     * @param int[] $package_ids
     * @return array<int, array<string, object>>
     */
    public static function map_for_packages(array $package_ids): array
    {
        return self::map('tblvendor_package_tiers', 'package_id', $package_ids);
    }

    /**
     * The price of one tier, falling back to the row's own price when the tier
     * is unknown or unpriced. Never guesses a tier: an unset tier costs what the
     * vendor entered as their price, which is the Silver level by construction.
     */
    public static function price(array $tiers, ?string $tier, float $base): float
    {
        $tier = self::normalise_tier($tier);
        if ($tier === null || !isset($tiers[$tier]) || !is_object($tiers[$tier])) {
            return $base;
        }
        return (float) $tiers[$tier]->price;
    }

    /**
     * "Silver KSh 1,200 · Gold KSh 1,500 · Platinum KSh 1,900 / guest" — the
     * line a card shows under an offering. '' when the vendor has not priced
     * tiers, so an existing single-price row renders exactly as it did before.
     *
     * $suffix is appended once (e.g. " / guest", " flat") rather than to every
     * figure, because the unit belongs to the service, not to each level.
     */
    public static function line(array $tiers, string $suffix = ''): string
    {
        if ($tiers === []) {
            return '';
        }
        $parts = [];
        foreach (self::TIERS as $tier) {
            if (!isset($tiers[$tier]) || !is_object($tiers[$tier])) {
                continue;
            }
            $parts[] = self::LABELS[$tier] . ' ' . self::money((float) $tiers[$tier]->price);
        }
        return $parts === [] ? '' : implode(' · ', $parts) . $suffix;
    }

    /**
     * The tier as a short chip label for a quote line: "Gold".
     *
     * Kept here rather than in each page so a proposal line, a deal note and a
     * marketplace card cannot spell the same tier three ways.
     */
    public static function chip(?string $tier): string
    {
        return self::label($tier);
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Save one service's three tiers (or clear them by posting all prices blank).
     *
     * Reads `tier_price[silver|gold|platinum]`, `tier_includes[…]` and
     * `tier_excludes[…]` from the posted form. The base row's price is set to
     * Silver in the SAME transaction, so the "from" price every other page reads
     * can never disagree with the tier the vendor just saved.
     *
     * @return array{ok:bool, error:string}
     */
    public static function save_service(int $service_id, int $vendor_id, array $posted): array
    {
        if (!self::installed()) {
            return ['ok' => false, 'error' => 'Tiered pricing is not installed — apply migrations/v3.1_vendor_service_tiers.sql first.'];
        }
        if (VendorService::find_owned($service_id, $vendor_id) === null) {
            return ['ok' => false, 'error' => 'That service is not yours.'];
        }
        return self::write('tblvendor_service_tiers', 'service_id', $service_id, 'tblvendor_services', $posted);
    }

    /**
     * Save one package's three tiers (or clear them).
     *
     * @return array{ok:bool, error:string}
     */
    public static function save_package(int $package_id, int $vendor_id, array $posted): array
    {
        if (!self::installed()) {
            return ['ok' => false, 'error' => 'Tiered pricing is not installed — apply migrations/v3.1_vendor_service_tiers.sql first.'];
        }
        if (Vendor::package_of($vendor_id, $package_id) === null) {
            return ['ok' => false, 'error' => 'That package is not yours.'];
        }
        return self::write('tblvendor_package_tiers', 'package_id', $package_id, 'tblvendor_packages', $posted);
    }

    /**
     * Put Silver back on the base row after a plain edit rewrote `price`.
     *
     * The vendor's normal edit form still carries a single price field (every
     * existing row has one), so saving it would otherwise silently break the
     * mirror and leave the card showing a "from" price that is no longer the
     * cheapest tier. Does nothing when no tiers are set.
     */
    public static function sync_base(string $kind, int $id): void
    {
        $kind  = $kind === 'package' ? 'package' : 'service';
        $tiers = $kind === 'service' ? self::for_service($id) : self::for_package($id);
        if (!isset($tiers[self::BASE])) {
            return;
        }
        global $db;
        $table = $kind === 'service' ? 'tblvendor_services' : 'tblvendor_packages';
        try {
            $db->execute(
                "UPDATE {$table} SET price = :p WHERE id = :id",
                [':p' => (float) $tiers[self::BASE]->price, ':id' => $id]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][VendorTier] base sync failed: ' . $e->getMessage());
        }
    }

    /**
     * The three rows of the tier editor, as `<tr>` markup, for the two vendor
     * pages. One shape in one place: Silver/Gold/Platinum can never be spelled
     * differently on the services page and the packages page.
     *
     * Prices are posted as `tier_price[silver]` etc. — VendorTier::normalise()
     * reads exactly these names.
     */
    public static function editor_rows(array $tiers): string
    {
        $rows = '';
        foreach (self::LABELS as $slug => $label) {
            $row = isset($tiers[$slug]) && is_object($tiers[$slug]) ? $tiers[$slug] : null;
            $rows .= '<tr>'
                . '<td style="white-space:nowrap;font-weight:600;font-size:12px;">' . htmlspecialchars($label)
                . ($slug === self::BASE ? ' <span class="text-muted" style="font-weight:400;">(base)</span>' : '') . '</td>'
                . '<td><input type="number" step="0.01" min="1" class="form-control form-control-sm mono"'
                . ' name="tier_price[' . $slug . ']" value="'
                . ($row !== null ? htmlspecialchars((string) $row->price) : '') . '"></td>'
                . '<td><input type="text" maxlength="' . self::MAX_TEXT . '" class="form-control form-control-sm"'
                . ' name="tier_includes[' . $slug . ']" placeholder="e.g. staff, crockery, setup" value="'
                . ($row !== null ? htmlspecialchars((string) $row->includes) : '') . '"></td>'
                . '<td><input type="text" maxlength="' . self::MAX_TEXT . '" class="form-control form-control-sm"'
                . ' name="tier_excludes[' . $slug . ']" placeholder="e.g. travel outside Nairobi" value="'
                . ($row !== null ? htmlspecialchars((string) $row->excludes) : '') . '"></td>'
                . '</tr>';
        }
        return $rows;
    }

    /**
     * Delete one service's tiers without touching its base price — used when a
     * vendor goes back to a single price.
     *
     * @return array{ok:bool, error:string}
     */
    public static function clear_service(int $service_id, int $vendor_id): array
    {
        if (VendorService::find_owned($service_id, $vendor_id) === null) {
            return ['ok' => false, 'error' => 'That service is not yours.'];
        }
        return self::write('tblvendor_service_tiers', 'service_id', $service_id, 'tblvendor_services', []);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * The parse half of a save: posted arrays → clean rows, cheapest first.
     *
     * Prices arrive as strings from a number input ("1,200" is also tolerated
     * because a paste is normal), and blank means "not offered". A price of 0
     * is treated as blank: no vendor sells a tier for nothing, and refusing it
     * as "blank" gives a better message than "must be more than Silver".
     *
     * @return array<string, array{price:?float, includes:string, excludes:string}>
     */
    public static function normalise(array $posted): array
    {
        $prices   = is_array($posted['tier_price'] ?? null) ? $posted['tier_price'] : [];
        $includes = is_array($posted['tier_includes'] ?? null) ? $posted['tier_includes'] : [];
        $excludes = is_array($posted['tier_excludes'] ?? null) ? $posted['tier_excludes'] : [];

        $clean = [];
        foreach (self::TIERS as $tier) {
            $raw   = (string) ($prices[$tier] ?? '');
            $raw   = str_replace([',', ' '], '', $raw);
            $price = is_numeric($raw) && (float) $raw > 0 ? (float) $raw : null;
            $clean[$tier] = [
                'price'    => $price,
                'includes' => self::text((string) ($includes[$tier] ?? '')),
                'excludes' => self::text((string) ($excludes[$tier] ?? '')),
            ];
        }
        return $clean;
    }

    /**
     * What is wrong with a normalised set of tiers — '' when nothing is.
     *
     * The rules are the ones that make a three-tier offer comparable rather
     * than decorative:
     *   - all three or none: a two-tier offer cannot be lined up against
     *     another vendor's three;
     *   - every tier costs something, and each costs more than the one below
     *     (Silver < Gold < Platinum), because "Gold" that is cheaper than
     *     "Silver" is how a couple gets talked into paying more for less.
     *
     * Inclusions/exclusions stay optional: the vendor may not have decided yet,
     * and a missing sentence is not a pricing error.
     *
     * @param array<string, array{price:?float, includes:string, excludes:string}> $clean
     */
    public static function validate(array $clean): string
    {
        $priced = [];
        foreach ($clean as $tier => $row) {
            if (($row['price'] ?? null) !== null) {
                $priced[$tier] = (float) $row['price'];
            }
        }
        if ($priced === []) {
            return ''; // clearing the tiers is a valid choice
        }
        $missing = array_diff(self::TIERS, array_keys($priced));
        if ($missing !== []) {
            return 'Set a price for all three tiers, or leave them all blank — a '
                . count($priced) . '-tier offer cannot be compared with another vendor\'s three.';
        }
        foreach ($priced as $tier => $price) {
            if ($price > self::MAX_PRICE) {
                return self::LABELS[$tier] . ' is priced above ' . self::money(self::MAX_PRICE) . ' — check the figure.';
            }
        }
        if ($priced['silver'] >= $priced['gold']) {
            return 'Gold must cost more than Silver — otherwise "Gold" is the same offer with a better name.';
        }
        if ($priced['gold'] >= $priced['platinum']) {
            return 'Platinum must cost more than Gold — otherwise there is nothing to step up to.';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<string, object> */
    private static function rows(string $column, int $id): array
    {
        if ($id <= 0 || !self::installed()) {
            return [];
        }
        global $db;
        $table = $column === 'service_id' ? 'tblvendor_service_tiers' : 'tblvendor_package_tiers';
        $rows  = $db->fetchAll(
            "SELECT tier, price, includes, excludes FROM {$table} WHERE {$column} = :id",
            [':id' => $id]
        );
        $map = [];
        foreach ($rows as $row) {
            $tier = (string) $row->tier;
            if (in_array($tier, self::TIERS, true)) {
                $map[$tier] = $row;
            }
        }
        return self::sorted($map);
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, object>>
     */
    private static function map(string $table, string $column, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !self::installed()) {
            return [];
        }
        global $db;
        // Ids are cast to int above, so the IN list cannot carry anything but
        // numbers — the query is still built rather than bound because MySQL
        // cannot bind a variable-length list.
        $rows = $db->fetchAll(
            "SELECT {$column} AS offer_id, tier, price, includes, excludes
               FROM {$table} WHERE {$column} IN (" . implode(',', $ids) . ')'
        );
        $out = [];
        foreach ($rows as $row) {
            $tier = (string) $row->tier;
            if (!in_array($tier, self::TIERS, true)) {
                continue;
            }
            $out[(int) $row->offer_id][$tier] = $row;
        }
        foreach ($out as $id => $tiers) {
            $out[$id] = self::sorted($tiers);
        }
        return $out;
    }

    /**
     * @param array<string, object> $map
     * @return array<string, object>
     */
    private static function sorted(array $map): array
    {
        $out = [];
        foreach (self::TIERS as $tier) {
            if (isset($map[$tier])) {
                $out[$tier] = $map[$tier];
            }
        }
        return $out;
    }

    /**
     * Shared save: validate, replace the tier rows, and mirror Silver onto the
     * base row — all in one transaction, so a half-saved offer cannot exist.
     *
     * Deleting before inserting (rather than upserting) keeps this simple and
     * is safe here because nothing references a tier row by id: money is
     * snapshotted on the proposal line, not on the tier.
     *
     * @return array{ok:bool, error:string}
     */
    private static function write(string $table, string $column, int $offer_id, string $base_table, array $posted): array
    {
        $clean = self::normalise($posted);
        $error = self::validate($clean);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $db->execute("DELETE FROM {$table} WHERE {$column} = :id", [':id' => $offer_id]);
            $base = null;
            foreach (self::TIERS as $tier) {
                $row = $clean[$tier];
                if (($row['price'] ?? null) === null) {
                    continue;
                }
                $db->execute(
                    "INSERT INTO {$table} ({$column}, tier, price, includes, excludes)
                     VALUES (:id, :tier, :price, :inc, :exc)",
                    [
                        ':id'    => $offer_id,
                        ':tier'  => $tier,
                        ':price' => $row['price'],
                        ':inc'   => $row['includes'],
                        ':exc'   => $row['excludes'],
                    ]
                );
                if ($tier === self::BASE) {
                    $base = (float) $row['price'];
                }
            }
            if ($base !== null) {
                $db->execute(
                    "UPDATE {$base_table} SET price = :p WHERE id = :id",
                    [':p' => $base, ':id' => $offer_id]
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
            error_log('[WPMS][VendorTier] save failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The tiers could not be saved. Nothing was changed — try again.'];
        }
    }

    private static function text(string $value): string
    {
        return mb_substr(trim($value), 0, self::MAX_TEXT);
    }

    private static function money(float $amount): string
    {
        return function_exists('format_ksh')
            ? format_ksh($amount)
            : 'KSh ' . number_format($amount, 0);
    }
}
