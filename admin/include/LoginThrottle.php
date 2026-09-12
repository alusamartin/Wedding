<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.1 — Built-in login rate limiting (brute-force throttle)
 *
 * Backs login.php: repeated failed sign-ins for one (email, IP) pair lock that
 * pair out for a cool-down window, and a wider per-IP cap catches distributed
 * guessing across many accounts from one host. Success clears the pair; old
 * rows are pruned so the table stays tiny. This closes the roadmap item that
 * previously delegated throttling to .htaccess/fail2ban — it now works with
 * plain Apache/XAMPP, no server modules required.
 *
 * Storage: tbllogin_throttle (see migrations/v2.1_phase1_hardening.sql).
 * One row per (email, IP) pair, UPSERT-incremented on each failure so the
 * updated_at timestamp is the lockout clock. All time arithmetic happens in
 * SQL against the DB session timezone (Africa/Nairobi), never in PHP, so the
 * window can't drift with PHP/DB clock or TZ mismatches.
 *
 * Kill switch: set WPMS_LOGIN_THROTTLE_OFF=1 in the environment to disable
 * every throttle operation (shared test suites, maintenance). The class
 * re-reads the flag on each call, so it can be toggled at runtime.
 */
class LoginThrottle
{
    /** Failed attempts on one (email, IP) pair before that pair locks out. */
    public const MAX_FAILURES = 5;

    /** Recent failures summed across ALL accounts from one IP before the IP locks. */
    public const MAX_FAILURES_PER_IP = 20;

    /** How long a tripped throttle stays locked (seconds). */
    public const LOCKOUT_SECONDS = 900; // 15 minutes

    /** Rows untouched for this long are deleted by prune(). */
    public const PRUNE_AFTER_DAYS = 7;

    /**
     * Is throttling active? WPMS_LOGIN_THROTTLE_OFF (1/true/yes/on) disables it.
     */
    public static function enabled(): bool
    {
        $off = getenv('WPMS_LOGIN_THROTTLE_OFF');
        if ($off === false || $off === '') {
            return true;
        }
        return !in_array(strtolower($off), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The client IP to key throttle rows on. Uses REMOTE_ADDR — the one
     * address the web server itself observed, which a client cannot forge.
     * (X-Forwarded-For is deliberately NOT trusted here: an attacker rotates
     * it per request to evade per-IP caps, whereas behind a real reverse
     * proxy you'd set the kill switch or terminate at the proxy instead.)
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Seconds until this pair (or its IP) may attempt a sign-in again.
     * 0 = not blocked. Checks the per-pair lock first, then the per-IP cap
     * (recent failures summed across all accounts seen from that IP).
     */
    public static function secondsRemaining(string $email, ?string $ip = null): int
    {
        if (!self::enabled()) {
            return 0;
        }
        global $db;
        $email = mb_strtolower(trim($email));
        $ip = $ip ?? self::clientIp();
        if ($email === '') {
            return 0;
        }

        // Per-pair lock: enough recent failures and the clock hasn't run out.
        $row = $db->fetchOne(
            'SELECT fail_count, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_seconds
             FROM tbllogin_throttle
             WHERE throttle_email = :e AND throttle_ip = :i',
            [':e' => $email, ':i' => $ip]
        );
        if ($row !== null
            && (int) $row->fail_count >= self::MAX_FAILURES
            && (int) $row->age_seconds < self::LOCKOUT_SECONDS
        ) {
            return self::LOCKOUT_SECONDS - (int) $row->age_seconds;
        }

        // Per-IP cap: any account hammered from this IP recently blocks
        // further attempts from it, even for untouched accounts.
        $recent = (int) $db->fetchValue(
            'SELECT COALESCE(SUM(fail_count), 0)
             FROM tbllogin_throttle
             WHERE throttle_ip = :i
               AND fail_count > 0
               AND updated_at >= NOW() - INTERVAL :win SECOND',
            [':i' => $ip, ':win' => self::LOCKOUT_SECONDS]
        );
        if ($recent >= self::MAX_FAILURES_PER_IP) {
            return self::LOCKOUT_SECONDS; // full window; exact remainder is unknowable across pairs
        }

        return 0;
    }

    /** Alias for readability at call sites. */
    public static function isBlocked(string $email, ?string $ip = null): bool
    {
        return self::secondsRemaining($email, $ip) > 0;
    }

    /**
     * Record one failed sign-in. UPSERT: creates the pair row or increments
     * it, refreshing updated_at so each failure restarts the lockout clock.
     */
    public static function recordFailure(string $email, ?string $ip = null): void
    {
        if (!self::enabled()) {
            return;
        }
        global $db;
        $email = mb_strtolower(trim($email));
        $ip = $ip ?? self::clientIp();
        if ($email === '') {
            return;
        }
        $db->execute(
            'INSERT INTO tbllogin_throttle (throttle_email, throttle_ip, fail_count, updated_at)
             VALUES (:e, :i, 1, NOW())
             ON DUPLICATE KEY UPDATE fail_count = fail_count + 1, updated_at = NOW()',
            [':e' => $email, ':i' => $ip]
        );
        self::prune();
    }

    /**
     * Clear the pair after a successful sign-in — legit users who mistyped a
     * few times aren't haunted by stale counters.
     */
    public static function clear(string $email, ?string $ip = null): void
    {
        if (!self::enabled()) {
            return;
        }
        global $db;
        $email = mb_strtolower(trim($email));
        $ip = $ip ?? self::clientIp();
        if ($email === '') {
            return;
        }
        $db->execute(
            'DELETE FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i',
            [':e' => $email, ':i' => $ip]
        );
    }

    /**
     * Delete rows untouched for PRUNE_AFTER_DAYS. Runs on every recorded
     * failure (indexed delete on a tiny table) so stale counters never pile up.
     */
    public static function prune(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        global $db;
        $stmt = $db->execute(
            'DELETE FROM tbllogin_throttle WHERE updated_at < NOW() - INTERVAL :days DAY',
            [':days' => self::PRUNE_AFTER_DAYS]
        );
        return $stmt->rowCount();
    }
}
