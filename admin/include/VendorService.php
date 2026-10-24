<?php
declare(strict_types=1);

/**
 * VendorService — what one business actually sells, in a shape the platform can
 * compare.
 *
 * THE QUESTION THIS ANSWERS
 * "What does this caterer offer, at what price, and is it the same thing the
 * other caterer calls the same name?" Before v2.27 the answer was a free-text
 * package title and a number with no unit, so a planner shopping a KSh 250,000
 * catering budget compared a per-head quote with a flat fee and a "negotiable"
 * and learned nothing. `tblvendor_packages` was never wrong — it is a BUNDLE,
 * the thing a couple buys — but a bundle is too coarse to quote, budget or
 * compare against.
 *
 * TWO TABLES, ONE IDEA
 *   tblservice_catalog   the platform's vocabulary: one row per known service
 *                        per category, each with a MANDATORY unit. Vendors pick
 *                        from it; they cannot edit it, so "buffet", "buffet
 *                        service" and "self-service buffet" stop being three
 *                        different things.
 *   tblvendor_services   what THIS vendor sells. `catalog_id` set means it came
 *                        from the vocabulary; NULL means the vendor added their
 *                        own. Both carry a unit and a price, so a custom
 *                        "Ngurario goat feast" is comparable on the same terms
 *                        as a catalogue buffet — that is the whole point of
 *                        letting vendors add their own.
 *
 * WHAT A SERVICE IS NOT
 * It is not a package and it is not a booking. A package will be assembled FROM
 * services (the next phase); nothing here writes to `tblvendor_packages` or
 * `tblvendor_bookings`, and a service can be edited or hidden without touching
 * a deal already agreed. Prices are quoted in KSh throughout, as everywhere else
 * on the platform (format_ksh()).
 *
 * OWNERSHIP
 * Every read and write that can name another vendor's row is scoped by
 * `vendor_id` in the SQL ITSELF (find_owned/create/update/toggle/remove), not by
 * trusting the caller's id or the page's earlier lookup. A vendor account that
 * runs several listings works through the listing the session selected
 * (current_vendor_listing()), exactly as My Packages does.
 *
 * DEGRADING WHEN THE MIGRATION IS ABSENT
 * `installed()` probes once per request. A database that has not run v2.27 still
 * renders every page that mentions services — the section simply says the
 * migration is missing instead of throwing. Nothing here is fatal.
 */
final class VendorService
{
    /**
     * The units a price can be quoted in — the platform's complete vocabulary,
     * mirrored by the enum in both tables. slug => [human label, noun for the
     * quantity hint]. `flat` has no noun: a flat fee has no count.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    public const UNITS = [
        'flat'      => ['Flat fee', ''],
        'per_guest' => ['Per guest', 'guest'],
        'per_day'   => ['Per day', 'day'],
        'per_hour'  => ['Per hour', 'hour'],
        'per_item'  => ['Per item', 'item'],
        'per_km'    => ['Per km', 'km'],
        'per_staff' => ['Per staff member', 'staff member'],
    ];

    /** Upper bound on stored free text, so a paste cannot fill a TEXT column. */
    public const MAX_DESCRIPTION = 2000;
    public const MAX_INCLUDES    = 500;
    public const MAX_TAGS        = 255;
    public const MAX_TITLE       = 160;

    /** @var array<string, array{0:string, 1:string}> */
    public static function units(): array
    {
        return self::UNITS;
    }

    public static function unit_label(string $unit): string
    {
        return self::UNITS[$unit][0] ?? 'Flat fee';
    }

    /** Is migrations/v2.27_vendor_services.sql installed? (One probe per request.) */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }

        global $db;
        try {
            $db->fetchValue('SELECT COUNT(*) FROM tblvendor_services');
            $db->fetchValue('SELECT COUNT(*) FROM tblservice_catalog');
            return $installed = true;
        } catch (Throwable $e) {
            return $installed = false;
        }
    }

    // ------------------------------------------------------------------
    // The vocabulary
    // ------------------------------------------------------------------

    /**
     * Catalogue rows, optionally for one category, in the order the picker
     * draws them. Active by default: a retired catalogue item must not be
     * offered to a vendor adding a service today, while vendors who already
     * sell it keep their rows (catalog_id resolves either way).
     *
     * @return object[]
     */
    public static function catalogue(?string $category = null, bool $include_inactive = false): array
    {
        if (!self::installed()) {
            return [];
        }

        global $db;
        $where  = $include_inactive ? '' : ' AND is_active = 1';
        $params = [];
        if ($category !== null && $category !== '') {
            $where .= ' AND vendor_category = :c';
            $params[':c'] = $category;
        }

        return $db->fetchAll(
            'SELECT * FROM tblservice_catalog WHERE 1 = 1' . $where . ' ORDER BY vendor_category, sort_order, name',
            $params
        );
    }

    /**
     * The catalogue keyed by category, for a grouped picker: category => rows.
     *
     * @return array<string, object[]>
     */
    public static function catalogue_grouped(?string $category = null): array
    {
        $grouped = [];
        foreach (self::catalogue($category) as $row) {
            $grouped[(string) $row->vendor_category][] = $row;
        }
        return $grouped;
    }

    /** One catalogue item, or null when the id is unknown/retired. */
    public static function catalogue_item(int $catalog_id): ?object
    {
        if ($catalog_id <= 0 || !self::installed()) {
            return null;
        }

        global $db;
        return $db->fetchOne(
            'SELECT * FROM tblservice_catalog WHERE id = :id LIMIT 1',
            [':id' => $catalog_id]
        );
    }

    // ------------------------------------------------------------------
    // One vendor's services
    // ------------------------------------------------------------------

    /**
     * A vendor's offerings. Active only by default — the couple-facing profile
     * and the planner's read-only view must see exactly what is published.
     *
     * @return object[]
     */
    public static function for_vendor(int $vendor_id, bool $active_only = true): array
    {
        if ($vendor_id <= 0 || !self::installed()) {
            return [];
        }

        global $db;
        return $db->fetchAll(
            'SELECT s.*, c.name AS catalog_name, c.vendor_category AS catalog_category, c.default_unit AS catalog_unit
               FROM tblvendor_services s
          LEFT JOIN tblservice_catalog c ON c.id = s.catalog_id
              WHERE s.vendor_id = :v' . ($active_only ? ' AND s.is_active = 1' : '') . '
           ORDER BY COALESCE(c.vendor_category, \'\') ASC, s.price ASC, s.title ASC',
            [':v' => $vendor_id]
        );
    }

    /**
     * Services for MANY vendors at once, keyed by vendor_id — one query for a
     * directory of cards instead of one per card.
     *
     * @param int[] $vendor_ids
     * @return array<int, object[]>
     */
    public static function for_vendors(array $vendor_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vendor_ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !self::installed()) {
            return [];
        }

        global $db;
        $in = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = ':v' . $i;
            $in[] = $key;
            $params[$key] = $id;
        }

        $rows = $db->fetchAll(
            'SELECT s.*, c.vendor_category AS catalog_category
               FROM tblvendor_services s
          LEFT JOIN tblservice_catalog c ON c.id = s.catalog_id
              WHERE s.vendor_id IN (' . implode(',', $in) . ') AND s.is_active = 1
           ORDER BY s.price ASC, s.title ASC',
            $params
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->vendor_id][] = $row;
        }
        return $grouped;
    }

    /**
     * Ownership-checked fetch: the row must belong to THIS vendor, or it is
     * indistinguishable from one that does not exist.
     */
    public static function find_owned(int $service_id, int $vendor_id): ?object
    {
        if ($service_id <= 0 || $vendor_id <= 0 || !self::installed()) {
            return null;
        }

        global $db;
        return $db->fetchOne(
            'SELECT s.*, c.name AS catalog_name, c.vendor_category AS catalog_category
               FROM tblvendor_services s
          LEFT JOIN tblservice_catalog c ON c.id = s.catalog_id
              WHERE s.id = :id AND s.vendor_id = :v LIMIT 1',
            [':id' => $service_id, ':v' => $vendor_id]
        );
    }

    /**
     * Create one service for one vendor.
     *
     * @param array<string, mixed> $input raw form input
     * @return array{ok:bool, error:string, id:int}
     */
    public static function create(int $vendor_id, array $input): array
    {
        if (!self::installed()) {
            return self::fail('The services table is not installed yet (migrations/v2.27_vendor_services.sql).');
        }
        if ($vendor_id <= 0 || !self::vendor_exists($vendor_id)) {
            return self::fail('That vendor listing no longer exists.');
        }

        $clean = self::normalise_input($input);
        $error = self::validate($clean);
        if ($error !== '') {
            return self::fail($error);
        }

        global $db;
        $db->execute(
            'INSERT INTO tblvendor_services
                (vendor_id, catalog_id, title, description, pricing_unit, price,
                 min_qty, max_qty, lead_time_days, includes, tags, is_active)
             VALUES
                (:v, :cat, :title, :descr, :unit, :price,
                 :min, :max, :lead, :includes, :tags, :active)',
            [
                ':v'        => $vendor_id,
                ':cat'      => $clean['catalog_id'],
                ':title'    => $clean['title'],
                ':descr'    => $clean['description'],
                ':unit'     => $clean['unit'],
                ':price'    => $clean['price'],
                ':min'      => $clean['min_qty'],
                ':max'      => $clean['max_qty'],
                ':lead'     => $clean['lead_time_days'],
                ':includes' => $clean['includes'],
                ':tags'     => $clean['tags'],
                ':active'   => $clean['is_active'],
            ]
        );

        return ['ok' => true, 'error' => '', 'id' => (int) $db->lastInsertId()];
    }

    /**
     * Update one service. ownership is re-proved in the UPDATE's own WHERE.
     *
     * @param array<string, mixed> $input raw form input
     * @return array{ok:bool, error:string, id:int}
     */
    public static function update(int $service_id, int $vendor_id, array $input): array
    {
        if (!self::installed()) {
            return self::fail('The services table is not installed yet (migrations/v2.27_vendor_services.sql).');
        }
        $existing = self::find_owned($service_id, $vendor_id);
        if ($existing === null) {
            return self::fail('That service is not yours.');
        }

        $clean = self::normalise_input($input);
        // An edit keeps the catalogue link it was created with unless the form
        // deliberately sends another one — a custom service must not silently
        // become a catalogue item (or vice versa) on a price change.
        if (!array_key_exists('catalog_id', $input)) {
            $clean['catalog_id'] = $existing->catalog_id !== null ? (int) $existing->catalog_id : null;
        }
        $error = self::validate($clean);
        if ($error !== '') {
            return self::fail($error);
        }

        global $db;
        $db->execute(
            'UPDATE tblvendor_services
                SET catalog_id = :cat, title = :title, description = :descr,
                    pricing_unit = :unit, price = :price, min_qty = :min, max_qty = :max,
                    lead_time_days = :lead, includes = :includes, tags = :tags,
                    is_active = :active
              WHERE id = :id AND vendor_id = :v',
            [
                ':cat'      => $clean['catalog_id'],
                ':title'    => $clean['title'],
                ':descr'    => $clean['description'],
                ':unit'     => $clean['unit'],
                ':price'    => $clean['price'],
                ':min'      => $clean['min_qty'],
                ':max'      => $clean['max_qty'],
                ':lead'     => $clean['lead_time_days'],
                ':includes' => $clean['includes'],
                ':tags'     => $clean['tags'],
                ':active'   => $clean['is_active'],
                ':id'       => $service_id,
                ':v'        => $vendor_id,
            ]
        );

        return ['ok' => true, 'error' => '', 'id' => $service_id];
    }

    /**
     * Flip published/hidden. Returns the NEW state, or null when the row is not
     * this vendor's.
     */
    public static function toggle(int $service_id, int $vendor_id): ?bool
    {
        $existing = self::find_owned($service_id, $vendor_id);
        if ($existing === null) {
            return null;
        }

        global $db;
        $db->execute(
            'UPDATE tblvendor_services SET is_active = 1 - is_active WHERE id = :id AND vendor_id = :v',
            [':id' => $service_id, ':v' => $vendor_id]
        );
        return (int) $existing->is_active !== 1;
    }

    /**
     * Remove one service. Nothing references a service row yet (quotes and
     * bookings attach to BUNDLES), so a delete cannot break history; a vendor
     * who merely wants it off the market uses toggle() instead.
     */
    public static function remove(int $service_id, int $vendor_id): bool
    {
        if (self::find_owned($service_id, $vendor_id) === null) {
            return false;
        }

        global $db;
        $db->execute(
            'DELETE FROM tblvendor_services WHERE id = :id AND vendor_id = :v',
            [':id' => $service_id, ':v' => $vendor_id]
        );
        return true;
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * "KSh 1,200 / guest" — the price AND its unit, because a bare number is
     * what made packages incomparable. A quantity window is appended when the
     * vendor stated one: "(min 100)", "(up to 250)" or "(100–250)".
     */
    public static function price_line(object $service): string
    {
        // The same formatting format_ksh() uses, so a CLI job that loaded this
        // class alone still prints the number a page would.
        $price = function_exists('format_ksh')
            ? format_ksh((float) $service->price)
            : 'KSh ' . number_format((float) $service->price, 0);
        $unit  = (string) ($service->pricing_unit ?? 'flat');
        $line  = $unit === 'flat' ? $price . ' flat' : $price . ' / ' . (self::UNITS[$unit][1] ?? 'item');
        $hint  = self::quantity_hint($service);
        return $hint === '' ? $line : $line . ' (' . $hint . ')';
    }

    /**
     * The stated quantity window, in the unit's own noun: "min 100 guests",
     * "up to 250 guests", "100–250 guests". '' when the vendor stated none.
     */
    public static function quantity_hint(object $service): string
    {
        $unit = (string) ($service->pricing_unit ?? 'flat');
        $noun = trim((string) (self::UNITS[$unit][1] ?? ''));
        $min  = (int) ($service->min_qty ?? 0);
        $max  = (int) ($service->max_qty ?? 0);
        if ($min <= 0 && $max <= 0) {
            return '';
        }
        $suffix = $noun === '' ? '' : ' ' . $noun . ($max === 1 || $min === 1 ? '' : 's');

        if ($min > 0 && $max > 0) {
            return $min . '–' . $max . $suffix;
        }
        return $min > 0 ? 'min ' . $min . $suffix : 'up to ' . $max . $suffix;
    }

    /** Taste markers, split into a clean list ('' entries dropped, lowercased). */
    public static function tag_list(object|string $service): array
    {
        $raw = is_object($service) ? (string) ($service->tags ?? '') : $service;
        return array_values(array_filter(array_map(
            static fn (string $t): string => mb_strtolower(trim($t)),
            explode(',', $raw)
        ), static fn (string $t): bool => $t !== ''));
    }

    /**
     * A vendor's published breadth, for the page header and the directory card:
     * how many services, and the cheapest/dearest price. Nulls when empty.
     *
     * @return array{count:int, min:?float, max:?float}
     */
    public static function summary(int $vendor_id): array
    {
        $rows = self::for_vendor($vendor_id, true);
        if ($rows === []) {
            return ['count' => 0, 'min' => null, 'max' => null];
        }

        $prices = array_map(static fn (object $r): float => (float) $r->price, $rows);
        return ['count' => count($rows), 'min' => min($prices), 'max' => max($prices)];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Normalise raw POST/array input into the stored shape. Every value is
     * sanitised here and nowhere else, so a page and a future API cannot
     * disagree about what a clean service looks like.
     *
     * @param array<string, mixed> $input
     * @return array{catalog_id:?int, title:string, description:?string, unit:string,
     *               price:float, min_qty:?int, max_qty:?int, lead_time_days:?int,
     *               includes:string, tags:string, is_active:int}
     */
    public static function normalise_input(array $input): array
    {
        $catalog_id = (int) ($input['catalog_id'] ?? 0);
        $title      = self::text((string) ($input['title'] ?? ''), self::MAX_TITLE);
        $unit       = (string) ($input['unit'] ?? 'flat');
        if (!isset(self::UNITS[$unit])) {
            $unit = 'flat';
        }
        $description = self::text((string) ($input['description'] ?? ''), self::MAX_DESCRIPTION);
        $includes    = self::text((string) ($input['includes'] ?? ''), self::MAX_INCLUDES);
        $tags        = self::tags((string) ($input['tags'] ?? ''));

        $min    = (int) ($input['min_qty'] ?? 0);
        $max    = (int) ($input['max_qty'] ?? 0);
        $lead   = (int) ($input['lead_time_days'] ?? 0);
        $active = array_key_exists('is_active', $input)
            ? (int) ((string) $input['is_active'] === '0' ? 0 : 1)
            : 1;

        return [
            'catalog_id'     => $catalog_id > 0 ? $catalog_id : null,
            'title'          => $title,
            'description'    => $description === '' ? null : $description,
            'unit'           => $unit,
            'price'          => round((float) ($input['price'] ?? 0), 2),
            'min_qty'        => $min > 0 ? $min : null,
            'max_qty'        => $max > 0 ? $max : null,
            'lead_time_days' => $lead > 0 ? $lead : null,
            'includes'       => $includes,
            'tags'           => $tags,
            'is_active'      => $active,
        ];
    }

    /**
     * What is wrong with a normalised service, in one sentence a vendor can
     * act on. '' means it may be saved.
     *
     * @param array<string, mixed> $clean VendorService::normalise_input()
     */
    public static function validate(array $clean): string
    {
        if (trim((string) $clean['title']) === '') {
            return 'Give the service a name — it is what the couple sees.';
        }
        if (!isset(self::UNITS[(string) $clean['unit']])) {
            return 'Choose how the price is quoted (flat fee, per guest, per day…).';
        }
        if ((float) $clean['price'] <= 0) {
            return 'A price above zero is required — a service without one cannot be budgeted for.';
        }
        if ($clean['min_qty'] !== null && $clean['max_qty'] !== null && (int) $clean['min_qty'] > (int) $clean['max_qty']) {
            return 'The minimum quantity cannot be larger than the maximum.';
        }
        if ((int) ($clean['lead_time_days'] ?? 0) > 365) {
            return 'Lead time cannot be more than a year.';
        }
        if ($clean['catalog_id'] !== null && self::catalogue_item((int) $clean['catalog_id']) === null) {
            return 'That catalogue service is no longer available — pick another, or add your own.';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function vendor_exists(int $vendor_id): bool
    {
        global $db;
        return (int) $db->fetchValue('SELECT COUNT(*) FROM tblvendors WHERE id = :v', [':v' => $vendor_id]) > 0;
    }

    private static function text(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        // A paste can be longer than the column; cut on characters, not bytes.
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * Comma-separated taste markers, normalised: lowercase, trimmed, no
     * duplicates, no empty entries, stored short and joined by a comma. The
     * column is display text, not a taxonomy — a planner prices a taste in
     * words, and inventing a controlled list of "rustic" would be a lie.
     */
    private static function tags(string $raw): string
    {
        $seen = [];
        foreach (explode(',', $raw) as $tag) {
            $tag = mb_strtolower(trim(strip_tags($tag)));
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            if (count($seen) >= 8) {
                break;
            }
        }
        $joined = implode(',', array_keys($seen));
        return mb_strlen($joined) > self::MAX_TAGS ? mb_substr($joined, 0, self::MAX_TAGS) : $joined;
    }

    /** @return array{ok:bool, error:string, id:int} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'id' => 0];
    }
}
