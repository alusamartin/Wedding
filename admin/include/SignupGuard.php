<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.4 — Registration abuse protection (SignupGuard)
 *
 * planner_register.php is the one page that mints paid-product entitlements
 * (free trials) with no human in the loop, so it is gated like the login door:
 *
 * 1. Rate limiting (tblsignup_throttle): EVERY signup POST is counted —
 *    successes included. The verification step below already makes flooding
 *    successes pointless (each account stays unusable without a mailbox), so
 *    the damper here targets the flood itself: per email 3/rolling hour,
 *    per IP 10/rolling hour. When tripped, the page shows the same
 *    "come back in N minutes" backoff as the login door.
 *    Kill switch: WPMS_LOGIN_THROTTLE_OFF (same shared-test-suite semantics
 *    as LoginThrottle).
 *
 * 2. Email verification (tblplanner_verifications): register_planner()
 *    creates the account UNVERIFIED (tblusers.email_verified = 0) and WITHOUT
 *    a trial. The registration page issues a 24h single-use token (stored
 *    hashed — a DB leak must not leak usable links) and emails the verify
 *    link through the shared tbl_mail_logs outbox. verify_planner.php
 *    exchanges the token: stamps email_verified = 1, opens the 14-day trial,
 *    auto-signs-in and routes to subscription.php. Unverified staff cannot
 *    sign in — login.php sends them to the verification page, which carries
 *    a rate-limited resend form.
 *
 * Storage: migrations/v2.4_signup_abuse_guard.sql. All time arithmetic
 * happens in SQL against the DB session timezone (Africa/Nairobi), never in
 * PHP — same discipline as LoginThrottle.
 */
class SignupGuard
{
    /** Verification link validity. */
    public const TOKEN_TTL_MIN = 24 * 60;

    /** Signup POSTs allowed per rolling hour, per email / per IP. */
    public const MAX_PER_EMAIL = 3;
    public const MAX_PER_IP    = 10;

    /** Verification (re)sends allowed per rolling hour, per account. */
    public const MAX_SENDS_PER_HOUR = 5;

    /** Rows untouched for this long are deleted by prune(). */
    public const PRUNE_AFTER_DAYS = 7;

    /** Is the damper active? WPMS_LOGIN_THROTTLE_OFF disables it too. */
    public static function enabled(): bool
    {
        $off = getenv('WPMS_LOGIN_THROTTLE_OFF');
        if ($off === false || $off === '') {
            return true;
        }
        return !in_array(strtolower($off), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The client IP to key throttle rows on — REMOTE_ADDR only, the one
     * address the web server observed (never X-Forwarded-For; see
     * LoginThrottle::clientIp()).
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Seconds until another signup POST may go through. 0 = not blocked.
     * Counts ALL rows in the rolling hour — successes and failures alike —
     * for the given email AND for the IP as a whole (a fresh email from a
     * flooded IP is still blocked).
     */
    public static function secondsRemaining(string $email, ?string $ip = null): int
    {
        if (!self::enabled()) {
            return 0;
        }
        global $db;
        $email = mb_strtolower(trim($email));
        $ip    = $ip ?? self::clientIp();

        // Per-IP cap first — it dominates for distributed floods. Deliberately
        // TWO plain queries (COUNT + oldest-row wait): the one-shot
        // MAX(...) ... HAVING COUNT(*) form breaks under native prepared
        // statements on MariaDB (HY093 when rows exist), while the plain
        // SELECT shapes below are fine.
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblsignup_throttle
             WHERE ip = :i AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [':i' => $ip]
        );
        if ($count >= self::MAX_PER_IP) {
            $wait = (int) $db->fetchValue(
                'SELECT TIMESTAMPDIFF(SECOND, created_at, (NOW() + INTERVAL 1 HOUR)) FROM tblsignup_throttle
                 WHERE ip = :i AND created_at > (NOW() - INTERVAL 1 HOUR)
                 ORDER BY created_at ASC LIMIT 1',
                [':i' => $ip]
            );
            return max(1, $wait);
        }

        if ($email === '') {
            return 0;
        }
        // Per-email cap: the hour window starts at this email's 3rd-newest
        // row; when full, wait until it ages out.
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblsignup_throttle
             WHERE email = :e AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [':e' => $email]
        );
        if ($count < self::MAX_PER_EMAIL) {
            return 0;
        }
        $wait = (int) $db->fetchValue(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, (NOW() + INTERVAL 1 HOUR)) FROM tblsignup_throttle
             WHERE email = :e AND created_at > (NOW() - INTERVAL 1 HOUR)
             ORDER BY created_at DESC LIMIT 1 OFFSET ' . (self::MAX_PER_EMAIL - 1),
            [':e' => $email]
        );
        return max(1, $wait);
    }

    /** Record one signup POST. $success = the POST created an account. */
    public static function recordAttempt(string $email, bool $success = false, ?string $ip = null): void
    {
        if (!self::enabled()) {
            return;
        }
        global $db;
        $email = mb_strtolower(trim($email));
        $ip    = $ip ?? self::clientIp();
        $stmt  = $db->prepare('INSERT INTO tblsignup_throttle (email, ip, success) VALUES (:e, :i, :s)');
        $stmt->execute([
            ':e' => mb_strcut($email, 0, 100),
            ':i' => $ip,
            ':s' => $success ? 1 : 0,
        ]);
    }

    /**
     * Issue a verification token for an unverified staff account.
     * Rate-limited: MAX_SENDS_PER_HOUR rows (any state) per account in the
     * rolling hour — the resend damper.
     *
     * @return string|null the PLAIN token (for the email link) or null when
     *         the account is missing/verified or rate-limited
     */
    public static function issueVerification(int $userId): ?string
    {
        global $db;
        $stmt = $db->prepare('SELECT email_verified FROM tblusers WHERE id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);
        $verified = $stmt->fetchColumn();
        if ($verified === false || (int) $verified !== 0) {
            return null; // unknown, or already verified — nothing to issue
        }

        $recent = $db->prepare(
            'SELECT COUNT(*) FROM tblplanner_verifications
             WHERE user_id = :u AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $recent->execute([':u' => $userId]);
        if ((int) $recent->fetchColumn() >= self::MAX_SENDS_PER_HOUR) {
            return null;
        }

        $plain = bin2hex(random_bytes(32));
        $ins   = $db->prepare(
            'INSERT INTO tblplanner_verifications (user_id, token_hash, expires_at)
             VALUES (:u, :tok, (NOW() + INTERVAL ' . self::TOKEN_TTL_MIN . ' MINUTE))'
        );
        $ins->execute([':u' => $userId, ':tok' => hash('sha256', $plain)]);
        return $plain;
    }

    /**
     * Exchange a plain token for a verified account. Single-use: burns the
     * token and stamps tblusers.email_verified = 1 in one step.
     *
     * @return object|false the staff row {id, email, firstname} on success
     */
    public static function verify(string $plain): object|false
    {
        global $db;
        $plain = trim($plain);
        if ($plain === '') {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT v.id AS verification_id, v.user_id, u.email, u.firstname, u.email_verified
             FROM tblplanner_verifications v
             JOIN tblusers u ON u.id = v.user_id
             WHERE v.token_hash = :tok AND v.used_at IS NULL AND v.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':tok' => hash('sha256', $plain)]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row === false) {
            return false;
        }
        // (An already-verified account with a live token re-stamps 1 —
        // harmless idempotency; trials are never re-opened here.)
        $db->prepare('UPDATE tblplanner_verifications SET used_at = NOW() WHERE id = :i')
           ->execute([':i' => (int) $row->verification_id]);
        $db->prepare('UPDATE tblusers SET email_verified = 1 WHERE id = :u')
           ->execute([':u' => (int) $row->user_id]);
        error_log(sprintf('[WPMS][SignupGuard] planner verified user_id=%d', (int) $row->user_id));
        return $row;
    }

    /** The staff row for an email, only while still UNVERIFIED (resend form). */
    public static function unverified_by_email(string $email): ?object
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT id, firstname, email FROM tblusers WHERE email = :e AND email_verified = 0 LIMIT 1'
        );
        $stmt->execute([':e' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    /**
     * Deliver the verification email. Records to tbl_mail_logs (status
     * 'pending' when WPMS_MAIL_ENABLED is off; scripts/mail_retry.php
     * delivers later) and attempts immediate send via the shared opt-in
     * mail() path. Never throws.
     */
    public static function deliver(string $toEmail, string $firstname, string $plainToken): void
    {
        global $db;
        $link = 'verify_planner.php?token=' . urlencode($plainToken);

        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? rtrim(trim($base), '/') : '';
        $absolute = $base !== '' ? $base . '/' . ltrim($link, '/') : $link;

        $name    = trim($firstname) !== '' ? $firstname : 'there';
        $subject = 'Verify your email — start your Everafter Planner free trial';
        $body    = "Habari {$name},\n\n"
            . "Your Everafter Planner account is one click away. Open this link within "
            . round(self::TOKEN_TTL_MIN / 60) . " hours to verify your email and start your 14-day free trial:\n\n"
            . $absolute . "\n\n"
            . "If you didn't sign up, ignore this email — no account will be activated.\n\n"
            . "— Everafter Planner";

        self::log_mail($toEmail, $subject, $body, self::attempt_send($toEmail, $subject, $body));
    }

    /**
     * Welcome email — fired the moment the trial goes live (verify page).
     * Same outbox discipline as the verification email: one tbl_mail_logs
     * row, 'pending' (retryable by scripts/mail_retry.php) when the transport
     * is off. Carries the trial end date and the first three steps.
     */
    public static function send_welcome(string $toEmail, string $firstname, string $trialEndDate): void
    {
        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? rtrim(trim($base), '/') : '';
        $link = static function (string $path) use ($base): string {
            return $base !== '' ? $base . '/' . ltrim($path, '/') : $path;
        };

        $name    = trim($firstname) !== '' ? $firstname : 'there';
        $subject = 'Karibu! Your Everafter Planner trial is live';
        $body    = "Habari {$name},\n\n"
            . "Your email is verified and your Everafter Planner free trial is LIVE — every feature, nothing locked, until {$trialEndDate}.\n\n"
            . "Start here:\n"
            . "1. Add your first client — create their wedding booking (client_add.php): {$link('admin/client_add.php')}\n"
            . "2. Build their guest list and budget from the booking page\n"
            . "3. Browse vendors and send your first enquiry (vendors.php): {$link('admin/vendors.php')}\n\n"
            . "Activate a plan any time before {$trialEndDate} to keep full access (from KSh 2,999/mo, M-Pesa): {$link('admin/subscription.php')}\n\n"
            . "Karibu — we are glad you are here.\n"
            . "— Everafter Planner";

        self::log_mail($toEmail, $subject, $body, self::attempt_send($toEmail, $subject, $body));
    }

    /**
     * Trial nurture email (cron LEG 3, subscription_renewals.php).
     * $daysLeft is days until the trial ends: > 1 => the mid-trial check-in
     * with tips; 1 => the last-call email. Same outbox discipline as the
     * welcome/verification emails (pending/retryable when the transport is
     * off). Idempotency is the cron's job (marker-first band stamps).
     */
    public static function send_trial_nurture(string $toEmail, string $firstname, int $daysLeft, string $trialEndDate): void
    {
        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? rtrim(trim($base), '/') : '';
        $link = static function (string $path) use ($base): string {
            return $base !== '' ? $base . '/' . ltrim($path, '/') : $path;
        };

        $name = trim($firstname) !== '' ? $firstname : 'there';
        if ($daysLeft <= 1) {
            $subject = 'Last call — your Everafter Planner trial ends tomorrow';
            $body    = "Habari {$name},\n\n"
                . "Your Everafter Planner free trial ends TOMORROW ({$trialEndDate}).\n\n"
                . "After it ends you lose access to your clients, guests, budgets and vendors — "
                . "everything stays saved, but read-only until you activate.\n\n"
                . "Activate now (from KSh 2,999/mo via M-Pesa) and keep planning without a pause: {$link('admin/subscription.php')}\n\n"
                . "— Everafter Planner";
        } else {
            $subject = 'How is your Everafter Planner trial going?';
            $body    = "Habari {$name},\n\n"
                . "Halfway through your free trial — here is what planners do first:\n\n"
                . "1. Create a booking for a real wedding you are planning: {$link('admin/client_add.php')}\n"
                . "2. Load the guest list and set the budget — RSVP and reminders build on them\n"
                . "3. Send your first vendor enquiry and see the deal tracked in your ledger: {$link('admin/vendors.php')}\n\n"
                . "Your trial runs until {$trialEndDate}. Activate any time to keep everything: {$link('admin/subscription.php')}\n\n"
                . "— Everafter Planner";
        }
        self::log_mail($toEmail, $subject, $body, self::attempt_send($toEmail, $subject, $body));
    }

    /**
     * One delivery attempt through the opt-in mail() transport — mirrors
     * Notification::attempt_send(): 'pending' (retryable) when
     * WPMS_MAIL_ENABLED is off, 'failed' when mail() is unavailable, and
     * proper UTF-8 headers otherwise.
     *
     * @return array{0:string, 1:?string} [status, note]
     */
    private static function attempt_send(string $to, string $subject, string $body): array
    {
        if (getenv('WPMS_MAIL_ENABLED') !== '1') {
            return ['pending', 'Transport disabled (set WPMS_MAIL_ENABLED=1 to send via mail()). Logged, not delivered.'];
        }
        if (!function_exists('mail')) {
            return ['failed', 'mail() is disabled on this server.'];
        }
        $from    = getenv('WPMS_MAIL_FROM');
        $from    = is_string($from) && trim($from) !== '' ? trim($from) : 'no-reply@everafterplanner.co.ke';
        $headers = 'From: ' . $from . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8";
        $ok      = @mail($to, $subject, $body, $headers);
        return $ok
            ? ['sent', null]
            : ['failed', 'mail() returned false — is an MTA configured on the host?'];
    }

    /** Record one outbox row (tbl_mail_logs). Never throws. */
    private static function log_mail(string $toEmail, string $subject, string $body, array $send): void
    {
        global $db;
        try {
            $ins = $db->prepare(
                'INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, to_email, subject, body, status, note, attempts, last_attempt_at)
                 VALUES (NULL, 0, 0, :to, :subj, :body, :status, :note, :attempts, :last_attempt)'
            );
            $ins->execute([
                ':to'           => $toEmail,
                ':subj'         => $subject,
                ':body'         => $body,
                ':status'       => $send[0],
                ':note'         => $send[1],
                ':attempts'     => $send[0] === 'pending' ? 0 : 1,
                ':last_attempt' => $send[0] === 'pending' ? null : date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[WPMS][SignupGuard] mail log write failed: ' . $e->getMessage());
        }
    }

    /** Prune spent rows: throttle noise + dead tokens. Cheap, index-backed. */
    public static function prune(): void
    {
        global $db;
        try {
            $db->execute('DELETE FROM tblsignup_throttle WHERE created_at < (NOW() - INTERVAL ' . self::PRUNE_AFTER_DAYS . ' DAY)');
            $db->execute('DELETE FROM tblplanner_verifications WHERE expires_at < (NOW() - INTERVAL ' . self::PRUNE_AFTER_DAYS . ' DAY)');
        } catch (Throwable $e) {
            error_log('[WPMS][SignupGuard] prune failed: ' . $e->getMessage());
        }
    }

    // ==================================================================
    // Trial-funnel reporting (super-admin dashboard)
    // ==================================================================

    /**
     * Self-serve planners registered within the last $days days, with their
     * funnel stage. Self-serve accounts are identified by the 'planner_'
     * username prefix that register_planner() generates (durable: admin-
     * created staff never get it); date_created is a VARCHAR holding
     * NOW()-formatted datetimes, hence DATE(...) around it.
     *
     * @return array<array{user_id:int, name:string, email:string,
     *     signup_date:string, verified:bool, trial:bool, paid:bool,
     *     paid_plan:string, paid_since:string, expired_unpaid:bool}>
     */
    public static function funnel_signups(int $days): array
    {
        global $db;
        $rows = $db->fetchAll(
            "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
                    DATE(u.date_created) AS signup_day,
                    u.email_verified,
                    MAX(s.status = 'trial') AS has_trial,
                    MAX(s.status IN ('active','expired') AND s.plan <> 'trial' AND s.amount > 0) AS has_paid,
                    MAX(CASE WHEN s.status IN ('active','expired') AND s.plan <> 'trial' AND s.amount > 0
                        THEN s.plan ELSE NULL END) AS paid_plan,
                    MAX(CASE WHEN s.status IN ('active','expired') AND s.plan <> 'trial' AND s.amount > 0
                        THEN s.start_date ELSE NULL END) AS paid_since,
                    MAX(s.status = 'expired' AND s.plan <> 'trial' AND s.amount > 0) AS had_expired_paid
             FROM tblusers u
             LEFT JOIN tblsubscriptions s ON s.user_id = u.id
             WHERE u.username LIKE 'planner\_%'
               AND DATE(u.date_created) > (CURDATE() - INTERVAL :days DAY)
             GROUP BY u.id, u.firstname, u.lastname, u.email, u.username,
                      signup_day, u.email_verified
             ORDER BY u.id DESC"
            , [':days' => max(1, $days)]
        );

        $out = [];
        foreach ($rows as $r) {
            $paid  = (bool) $r->has_paid;
            $trial = (bool) $r->has_trial && !$paid;
            $out[] = [
                'user_id'     => (int) $r->id,
                'name'        => trim($r->firstname . ' ' . $r->lastname),
                'email'       => (string) $r->email,
                'signup_date' => (string) $r->signup_day,
                'verified'    => (int) $r->email_verified === 1,
                'trial'       => $trial,
                'paid'        => $paid,
                'paid_plan'   => (string) ($r->paid_plan ?? ''),
                'paid_since'  => (string) ($r->paid_since ?? ''),
                'expired_unpaid' => !$paid && (int) $r->email_verified === 1
                    && !$trial && (int) $r->had_expired_paid === 0
                    && $r->signup_day < date('Y-m-d'),
            ];
        }
        return $out;
    }

    /**
     * Aggregate funnel report for the super-admin dashboard: stage counts,
     * verification/conversion rates, a signups-per-day series and stage
     * counts for the selected window.
     *
     * @return array{window_days:int, signups:int, verified:int,
     *     active_trials:int, converted:int, conversion_pct:float,
     *     verified_pct:float, expired_unpaid:int, per_day:array<string,int>,
     *     plans:array<string,int>, rows:array}
     */
    public static function funnel_report(int $days): array
    {
        $days = max(1, $days);
        $rows = self::funnel_signups($days);

        $signups = count($rows);
        $verified = 0;
        $activeTrials = 0;
        $converted = 0;
        $expiredUnpaid = 0;
        $perDay = [];
        $plans = [];
        foreach ($rows as $r) {
            if ($r['verified']) $verified++;
            if ($r['trial']) $activeTrials++;
            if ($r['paid']) {
                $converted++;
                $slug = $r['paid_plan'] !== '' ? $r['paid_plan'] : 'paid';
                $plans[$slug] = ($plans[$slug] ?? 0) + 1;
            } elseif ($r['expired_unpaid']) {
                $expiredUnpaid++;
            }
            $day = $r['signup_date'];
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }
        ksort($perDay);

        return [
            'window_days'    => $days,
            'signups'        => $signups,
            'verified'       => $verified,
            'active_trials'  => $activeTrials,
            'converted'      => $converted,
            'conversion_pct' => $signups > 0 ? round($converted / $signups * 100, 1) : 0.0,
            'verified_pct'   => $signups > 0 ? round($verified / $signups * 100, 1) : 0.0,
            'expired_unpaid' => $expiredUnpaid,
            'per_day'        => $perDay,
            'plans'          => $plans,
            'rows'           => $rows,
        ];
    }
}
