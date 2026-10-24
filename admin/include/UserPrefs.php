<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.12 — per-account preferences (tbluser_prefs).
 *
 * One row per (account, key), read and written for the signed-in user only.
 * The first preference is the capacity headroom view's width
 * (admin/headroom.php): the weddings running close to a booked package's guest
 * limit, watched at 5%, 10% or 20%. A planner's choice on Monday is the one
 * they want on Friday, and re-picking it on every visit is exactly the small
 * friction that makes a page stop being opened.
 *
 * The operator's insight thresholds and muted findings live here too
 * (OpsPulse): a rule that cries wolf about capacity at 10% is a rule the
 * operator stops reading, so the window and the mute are theirs to set. The
 * validation stays in OpsPulse — this class only stores the two values and
 * refuses unknown keys, exactly as it does for the headroom width.
 *
 * WHY A TABLE, AND WHY NOT THE SESSION. A session preference dies with the
 * session — that is the whole difference between "remembered" and "remembered
 * until the tab closes" (Helper's vendor-listing switcher is the session
 * precedent, and its own docblock says the value is per-visit). tblusers is the
 * auth table, read on every login, and the next preference must not be another
 * ALTER on it. So: tbluser_prefs, installable on its own
 * (migrations/v2.12_user_preferences.sql).
 *
 * THE THREE RULES THIS READER OWNS.
 *
 *   1. A MISSING TABLE IS NOT AN ERROR. A database that has not run v2.12 — a
 *      live site mid-upgrade, a scratch build from an older dump — must still
 *      serve every page. available() answers once per request, every read falls
 *      back to the caller's default, and every write refuses rather than
 *      throwing. The page says it cannot remember the width instead of pretending.
 *
 *   2. A STORED VALUE IS A HINT, NEVER A POLICY. headroom_percent() validates
 *      what it reads against PackageCapacity::shares() and returns null for
 *      anything else, so a row edited by hand, or left behind by an older
 *      build, reads as the default rather than widening a page into a query
 *      nobody asked for. remember_headroom() refuses an off-menu width for the
 *      same reason: a crafted POST must not be able to store one.
 *
 *   3. ONLY THE OWNER'S ROW IS TOUCHED, and the id comes from the session, never
 *      from the request. There is no foreign key on user_id (migrations/v2.12
 *      explains why): a preference must never be able to block deleting an
 *      account, and an orphan row is unreachable rather than dangerous.
 */
final class UserPrefs
{
    /** The headroom view's width, as a PERCENTAGE — the unit the page speaks. */
    public const HEADROOM_WIDTH = 'headroom_width';

    /** OpsPulse thresholds, as JSON (validated by OpsPulse before storing). */
    public const OPS_THRESHOLDS = 'ops_thresholds';

    /** OpsPulse's muted rule codes, as a bitmask (validated by OpsPulse). */
    public const OPS_MUTED = 'ops_muted';

    /**
     * The ISO week ('2026-W43') the weekly business pulse was last handed to
     * this account's outbox. Written by BusinessPulse after a send is accepted
     * (delivered, or queued while the transport is off), so a daily cron cannot
     * email the same owner twice in one week. See BusinessPulse::mark_sent().
     */
    public const PULSE_WEEK = 'pulse_week';

    /**
     * The keys this class will read or write. A typo or an unknown key is
     * refused rather than created: this table is a preference store, not a
     * scratch pad, and a silently-created key is a bug nobody sees.
     */
    private const KEYS = [self::HEADROOM_WIDTH, self::OPS_THRESHOLDS, self::OPS_MUTED, self::PULSE_WEEK];

    /** Is tbluser_prefs installed? Cached for the request (one query, once). */
    private static ?bool $installed = null;

    /**
     * Is the v2.12 table present? Answers from information_schema (no SHOW, no
     * swallowed connection error) and remembers the answer for this request.
     */
    public static function available(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }

        global $db;
        if (!($db instanceof Database)) {
            return self::$installed = false;
        }
        try {
            $found = $db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = :t',
                [':t' => 'tbluser_prefs']
            );
        } catch (Throwable $e) {
            // A database that cannot answer this cannot store a preference
            // either; the page carries on with its default.
            return self::$installed = false;
        }

        return self::$installed = (int) $found === 1;
    }

    /** The stored value for one account and key, or $default. */
    public static function get(int $user_id, string $key, string $default = ''): string
    {
        if ($user_id <= 0 || !in_array($key, self::KEYS, true) || !self::available()) {
            return $default;
        }

        global $db;
        try {
            $value = $db->fetchValue(
                'SELECT pref_value FROM tbluser_prefs WHERE user_id = :u AND pref_key = :k LIMIT 1',
                [':u' => $user_id, ':k' => $key]
            );
        } catch (Throwable $e) {
            return $default;
        }

        return $value === null ? $default : (string) $value;
    }

    /**
     * Store one value for one account. False when nothing was written: no such
     * key, no signed-in account, no table, or the write failed — the caller
     * reports that rather than claiming a preference was saved.
     */
    public static function remember(int $user_id, string $key, string $value): bool
    {
        if ($user_id <= 0 || !in_array($key, self::KEYS, true) || !self::available()) {
            return false;
        }

        global $db;
        try {
            $db->execute(
                'INSERT INTO tbluser_prefs (user_id, pref_key, pref_value)
                 VALUES (:u, :k, :v)
                 ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)',
                [':u' => $user_id, ':k' => $key, ':v' => $value]
            );
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /** Drop one account's stored value. False when nothing was written. */
    public static function forget(int $user_id, string $key): bool
    {
        if ($user_id <= 0 || !in_array($key, self::KEYS, true) || !self::available()) {
            return false;
        }

        global $db;
        try {
            $db->execute(
                'DELETE FROM tbluser_prefs WHERE user_id = :u AND pref_key = :k',
                [':u' => $user_id, ':k' => $key]
            );
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * The width this planner watches the headroom view at, or null when they
     * have never chosen one (or their stored one is not a width this feature
     * offers, which is rule 2 above: the default, not a wider query).
     */
    public static function headroom_percent(int $user_id): ?int
    {
        $stored = self::get($user_id, self::HEADROOM_WIDTH, '');
        if ($stored === '' || !ctype_digit($stored)) {
            return null;
        }
        $percent = (int) $stored;

        return array_key_exists($percent, PackageCapacity::shares()) ? $percent : null;
    }

    /**
     * Remember one of the widths the headroom view offers. An off-menu
     * percentage is refused (false) rather than clamped — a caller offering
     * something the page cannot render has a bug, and storing it would hide it.
     */
    public static function remember_headroom(int $user_id, int $percent): bool
    {
        if (!array_key_exists($percent, PackageCapacity::shares())) {
            return false;
        }

        return self::remember($user_id, self::HEADROOM_WIDTH, (string) $percent);
    }
}
