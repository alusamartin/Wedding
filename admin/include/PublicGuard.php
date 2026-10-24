<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.14 — One shared abuse guard for the anonymous doors (PublicGuard)
 *
 * The application has exactly three places where a visitor with no session can
 * make the database WRITE something a person will then be shown or emailed:
 *
 *   rsvp.php?g=<token>        POST  one guest's answer becomes a bell and an
 *                                   email for the couple's planner
 *                                   (Notification::create_for_staff → one
 *                                   tblnotifications row + one tbl_mail_logs row)
 *   site.php?slug=<slug>      GET   a page view becomes an UPDATE on
 *                                   tblwedding_websites.views
 *   mpesa_c2b.php             POST  a Daraja notification becomes a
 *   mpesa_stk_callback.php    POST  tblmpesa_callback_log row, and a fulfilled
 *                                   push becomes a receipt bell + email
 *
 * Every one of them authorises its caller — a 32-hex RSVP token, a published
 * slug, the optional Daraja shared secret — and none of them is rate-limited:
 * a loop over one guest's invite link is a loop over one planner's inbox, and a
 * loop over a Daraja URL is a loop over the forensic log. This class is the
 * counter behind all three, shared rather than reimplemented, so there is ONE
 * policy to read, ONE table to prune and ONE kill switch to flip.
 *
 * WHAT IT COUNTS. Two rows per attempt, both keyed by the IP the web server
 * observed (REMOTE_ADDR — see clientIp()):
 *
 *   (kind, subject, ip)  the TARGET: this caller's attempts on this token /
 *                        slug / callback endpoint — the row that stops one
 *                        link being hammered from one connection;
 *   (kind, '', ip)       the CALLER: everything this IP has attempted at this
 *                        door, whatever it aimed at — the row that stops a
 *                        flood spraying many subjects.
 *
 * `attempt()` refuses when EITHER row is full and returns the seconds to wait
 * (0 = allowed). A refusal means the caller writes NOTHING: no answer, no bell,
 * no email, no view, no log row. That is the whole point — the guard's own
 * counter is the only row a flood can still touch, and there are at most two of
 * them per (subject, caller), not per request (v2.14 keeps `window_started_at` +
 * `attempts` as a tumbling window, so ten thousand iterations churn a counter
 * instead of growing a table).
 *
 * WHAT IT DELIBERATELY DOES NOT DO. There is no global per-token cap, because a
 * real wedding's invitations land the same afternoon: a hundred guests answering
 * one couple's links is not an attack, and a cap shared across every host would
 * refuse them at precisely the moment the feature matters. What is bounded is
 * amplification — how much one connection (and one connection per target) can
 * turn into rows. Slugs are capped per visitor for the same reason: the page must
 * still render for a guest whose connection is shared with the whole committee.
 *
 * LIMITS (per rolling window, constants below so the policy is one place):
 *
 *   door      per target           per caller        window
 *   rsvp      10 per token/IP      20 per IP         1 hour
 *   site      10 per slug/IP       120 per IP        1 hour
 *   callback  60 per endpoint/IP   480 per IP        1 hour
 *
 * These are dampers for amplification, not locks on a feature, so they sit well
 * above honest use: a guest who answers, changes their mind and answers again
 * (and the verification walkers, which answer three times on one link) stays
 * inside the RSVP ceiling, a couple's site shared on WhatsApp gives each visitor
 * their own counter, and the callback ceilings are high on purpose — Daraja's
 * retries and a genuinely busy hour must never be refused, and a genuine call
 * that IS refused is never silently lost: both endpoints log it with the
 * identifiers needed to reconcile it before answering 200, which is their HTTP
 * contract with Safaricom.
 *
 * KILL SWITCH: WPMS_LOGIN_THROTTLE_OFF (1/true/yes/on) disables the guard, the
 * same switch LoginThrottle and SignupGuard read — one flag for every counter in
 * the product, and the reason the test suite's deliberate floods are unaffected.
 *
 * FAIL OPEN. These are public doors serving pages people are standing in front of
 * (a guest RSVP, a wedding site, a payment callback). A missing table, a lost
 * connection or a create race is logged and the caller is ALLOWED: an unavailable
 * counter must never take the door down or turn a real payment into a silent
 * drop. The guard is a damper, not an authorisation check — nothing here grants
 * access, and nothing here is the only thing standing between a caller and data.
 *
 * Storage: migrations/v2.14_public_abuse_guard.sql. All time arithmetic happens
 * in SQL against the DB session timezone (Africa/Nairobi), never in PHP — same
 * discipline as LoginThrottle and SignupGuard.
 */
class PublicGuard
{
    /** The public RSVP page — one POST answers one invitation. */
    public const RSVP = 'rsvp';

    /** The public wedding site — one GET counts one view. */
    public const SITE = 'site';

    /** The two M-Pesa callbacks — one POST is logged, and may fulfil a push. */
    public const CALLBACK = 'callback';

    /**
     * The MCP endpoint (mcp.php): one call = one tool.
     *
     * An assistant is bursty by nature — a single question can be a dozen calls
     * while it pages a guest list and reads a budget — so a ceiling copied from
     * the RSVP door would break it on the first real use. What the door is for is
     * the OTHER failure mode: an agent in a loop, or a leaked token being ground
     * through the catalogue. Hence a cap that no conversation reaches and no
     * runaway survives for long.
     *
     * The subject is the token's HASH, not the token: a capability must never sit
     * in a counter table, exactly as the RSVP and site doors treat their slugs.
     * The per-IP row is deliberately the looser of the two — a whole office
     * behind one NAT address shares it.
     */
    public const MCP = 'mcp';

    /**
     * Every door the guard knows, with its ceilings and window. An unknown kind
     * is a programming error (there is no default ceiling that could be right
     * for an unspecified door), so attempt() throws rather than guessing.
     *
     * @var array<string, array{subject:int, ip:int, window:int}>
     */
    public const LIMITS = [
        self::RSVP     => ['subject' => 10,   'ip' => 20,   'window' => 3600],
        self::SITE     => ['subject' => 10,   'ip' => 120,  'window' => 3600],
        self::CALLBACK => ['subject' => 60,   'ip' => 480,  'window' => 3600],
        self::MCP      => ['subject' => 240,  'ip' => 1200, 'window' => 3600],
    ];

    /** Rows untouched for this long are deleted by prune(). */
    public const PRUNE_AFTER_DAYS = 7;

    /** Longest subject the table stores (tokens are 64 hex chars). */
    public const SUBJECT_MAX = 96;

    /**
     * Is the guard active? WPMS_LOGIN_THROTTLE_OFF (1/true/yes/on) disables it —
     * the shared switch this project already documents for LoginThrottle and
     * SignupGuard, re-read on every call so it can be toggled at runtime.
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
     * The caller's IP: REMOTE_ADDR only — the one address the web server itself
     * observed, which a client cannot forge. (X-Forwarded-For is deliberately not
     * trusted: an attacker rotates it per request to evade a per-IP cap, whereas
     * behind a real reverse proxy the guard is disabled at the proxy or by the
     * kill switch. Same reasoning as LoginThrottle::clientIp().)
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Count one attempt at a door and say whether it may go ahead.
     *
     * @param string $kind    self::RSVP | self::SITE | self::CALLBACK
     * @param string $subject what is being protected: the RSVP token (hashed by
     *                        the caller — never the raw capability), the slug,
     *                        the callback endpoint. '' counts the caller only.
     * @return int seconds the caller must wait (0 = allowed, go ahead)
     * @throws InvalidArgumentException on an unknown door
     */
    public static function attempt(string $kind, string $subject = ''): int
    {
        $limits = self::limits($kind);          // outside the try: a bad call is a bug, not an outage
        if (!self::enabled()) {
            return 0;
        }
        $ip      = self::clientIp();
        $subject = self::normaliseSubject($subject);

        try {
            global $db;

            /*
             * The CALLER row is checked first, and a refusal returns before the
             * target row is even read. That order matters under a spray flood:
             * a caller already over its ceiling must not be able to grow the
             * table by aiming at a new subject each time, so the refused attempt
             * leaves the table exactly as it found it — two rows per flood, not
             * one per target.
             */
            $wait = self::hit($db, $kind, '', $ip, $limits['ip'], $limits['window']);
            if ($wait === 0 && $subject !== '') {
                $wait = self::hit($db, $kind, $subject, $ip, $limits['subject'], $limits['window']);
            }
            if ($wait > 0) {
                // Only the flood path pays for the prune: a page view must not.
                self::prune();
            }
            return $wait;
        } catch (Throwable $e) {
            error_log('[WPMS][PublicGuard] ' . $kind . ' counter unavailable, letting the request through: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * What attempt() would answer right now, WITHOUT counting anything. For a
     * page that wants to say "come back in N minutes" before a guest types an
     * answer, and for tests that assert the ceiling without moving it.
     *
     * @return int seconds of cooldown (0 = the next attempt is allowed)
     */
    public static function secondsRemaining(string $kind, string $subject = ''): int
    {
        $limits = self::limits($kind);
        if (!self::enabled()) {
            return 0;
        }
        $ip      = self::clientIp();
        $subject = self::normaliseSubject($subject);

        try {
            global $db;
            $wait = 0;
            if ($subject !== '') {
                $wait = self::peek($db, $kind, $subject, $ip, $limits['subject'], $limits['window']);
            }
            return max($wait, self::peek($db, $kind, '', $ip, $limits['ip'], $limits['window']));
        } catch (Throwable $e) {
            error_log('[WPMS][PublicGuard] ' . $kind . ' peek failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The hash an RSVP token is counted under. Tokens are capabilities: they
     * must not sit in a counter table (a database or log leak would hand out
     * invitation links), so the call site passes the hash, and this exists so
     * every caller hashes the same way.
     */
    public static function subjectHash(string $value): string
    {
        return hash('sha256', trim($value));
    }

    /**
     * Delete counters untouched for PRUNE_AFTER_DAYS — indexed, on a table that
     * holds a handful of live rows. Called on every refusal (the flood path) and
     * available to a cron.
     */
    public static function prune(): int
    {
        try {
            global $db;
            $stmt = $db->execute(
                'DELETE FROM tblpublic_throttle WHERE updated_at < NOW() - INTERVAL :days DAY',
                [':days' => self::PRUNE_AFTER_DAYS]
            );
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[WPMS][PublicGuard] prune failed: ' . $e->getMessage());
            return 0;
        }
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * The ceilings for one door.
     *
     * @return array{subject:int, ip:int, window:int}
     * @throws InvalidArgumentException
     */
    private static function limits(string $kind): array
    {
        if (!isset(self::LIMITS[$kind])) {
            throw new InvalidArgumentException(
                "PublicGuard: '" . $kind . "' is not a door this guard knows — pass self::RSVP, self::SITE or self::CALLBACK"
            );
        }
        return self::LIMITS[$kind];
    }

    /** Subjects are capped at the column width; nothing else is normalised. */
    private static function normaliseSubject(string $subject): string
    {
        return mb_strcut(trim($subject), 0, self::SUBJECT_MAX);
    }

    /**
     * Count one row and return the wait it implies. See the class docblock for
     * why the window is tumbling: a reset row is cheaper than a row per request,
     * and the worst case it allows (a burst straddling two windows) is bounded by
     * double the ceiling for that caller, once.
     *
     * A create race (two requests opening the same window at the same moment)
     * throws a duplicate-entry error, which the caller's catch turns into a
     * fail-open: both requests proceed and the miss is logged.
     */
    private static function hit(Database $db, string $kind, string $subject, string $ip, int $cap, int $window): int
    {
        $row = self::row($db, $kind, $subject, $ip);

        if ($row === null) {
            $db->execute(
                'INSERT INTO tblpublic_throttle (kind, subject, ip, window_started_at, attempts, blocked_count)
                 VALUES (:k, :s, :i, NOW(), 1, 0)',
                [':k' => $kind, ':s' => $subject, ':i' => $ip]
            );
            return 0;
        }

        $age      = max(0, (int) $row->age);
        $attempts = (int) $row->attempts;

        if ($age >= $window) {
            // The window has passed: this attempt opens the next one.
            $db->execute(
                'UPDATE tblpublic_throttle
                    SET attempts = 1, blocked_count = 0, window_started_at = NOW(), updated_at = NOW()
                  WHERE kind = :k AND subject = :s AND ip = :i',
                [':k' => $kind, ':s' => $subject, ':i' => $ip]
            );
            return 0;
        }

        if ($attempts >= $cap) {
            // Refused. The attempt is counted as BLOCKED, not as an attempt: the
            // counter must not let a flood push its own window further out.
            $db->execute(
                'UPDATE tblpublic_throttle
                    SET blocked_count = blocked_count + 1, updated_at = NOW()
                  WHERE kind = :k AND subject = :s AND ip = :i',
                [':k' => $kind, ':s' => $subject, ':i' => $ip]
            );
            return max(1, $window - $age);
        }

        $db->execute(
            'UPDATE tblpublic_throttle SET attempts = attempts + 1, updated_at = NOW()
              WHERE kind = :k AND subject = :s AND ip = :i',
            [':k' => $kind, ':s' => $subject, ':i' => $ip]
        );
        return 0;
    }

    /** The wait a row implies, with nothing written. */
    private static function peek(Database $db, string $kind, string $subject, string $ip, int $cap, int $window): int
    {
        $row = self::row($db, $kind, $subject, $ip);
        if ($row === null) {
            return 0;
        }
        $age = max(0, (int) $row->age);
        if ($age >= $window || (int) $row->attempts < $cap) {
            return 0;
        }
        return max(1, $window - $age);
    }

    /** The counter row for one (door, subject, caller), with its window age. */
    private static function row(Database $db, string $kind, string $subject, string $ip): ?object
    {
        return $db->fetchOne(
            'SELECT attempts, blocked_count, TIMESTAMPDIFF(SECOND, window_started_at, NOW()) AS age
               FROM tblpublic_throttle
              WHERE kind = :k AND subject = :s AND ip = :i',
            [':k' => $kind, ':s' => $subject, ':i' => $ip]
        );
    }
}
