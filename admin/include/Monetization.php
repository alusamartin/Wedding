<?php
declare(strict_types=1);

class Subscription extends DB_Object
{
    protected static $db_table = "tblsubscriptions";
    protected static $db_table_fields = array(
        'id',
        'user_id',
        'plan',
        'amount',
        'mpesa_code',
        'start_date',
        'expiry_date',
        'status',
        'created_at'
    );

    public $id;
    public $user_id;
    public $plan;
    public $amount;
    public $mpesa_code;
    public $start_date;
    public $expiry_date;
    public $status;
    public $created_at;

    public static function get_active(mixed $user_id): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE user_id = :uid AND status = 'active' AND expiry_date >= CURDATE() ORDER BY expiry_date DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function get_all_for_user(mixed $user_id): array
    {
        global $db;
        // id DESC as the tiebreaker: renewal clones are created in the same
        // second as their parent (TIMESTAMP granularity) and must sort after.
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE user_id = :uid ORDER BY created_at DESC, id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function is_active(mixed $user_id): bool
    {
        return self::get_active($user_id) !== null;
    }

    public static function create_subscription(mixed $user_id, string $plan, mixed $amount, string $mpesa_code): bool
    {
        global $db;
        $start  = date('Y-m-d');
        $expiry = date('Y-m-d', strtotime('+30 days'));
        $sql = "INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status) VALUES (:uid, :plan, :amount, :code, :start, :expiry, 'active')";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            ':uid'    => (int) $user_id,
            ':plan'   => $plan,
            ':amount' => $amount,
            ':code'   => $mpesa_code,
            ':start'  => $start,
            ':expiry' => $expiry,
        ]);
    }

    /** Trial length in days (env: WPMS_TRIAL_DAYS, default 14). */
    public static function trial_days(): int
    {
        $d = filter_var(getenv('WPMS_TRIAL_DAYS'), FILTER_VALIDATE_INT);
        return ($d === false || $d < 1) ? 14 : $d;
    }

    /**
     * Start a self-serve trial: a zero-amount 'trial' subscription row.
     * Called once by register_planner(); a user with any live subscription
     * (trial included) is refused — one trial per account, ever.
     *
     * @return object|false|null the created row, false when one already exists
     */
    public static function start_trial(mixed $user_id): object|false|null
    {
        global $db;
        $uid = (int) $user_id;
        if (self::get_active($uid) !== null || self::get_trial($uid) !== null) {
            return false; // already has a live subscription (paid or trial)
        }
        $days   = self::trial_days();
        $expiry = date('Y-m-d', strtotime('+' . $days . ' days'));
        $stmt = $db->prepare(
            "INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status, auto_renew)
             VALUES (:uid, 'trial', 0.00, 'TRIAL', :start, :expiry, 'trial', 0)"
        );
        $ok = $stmt->execute([
            ':uid'    => $uid,
            ':start'  => date('Y-m-d'),
            ':expiry' => $expiry,
        ]);
        return $ok ? self::sub_by_id((int) $db->the_insert_id()) : null;
    }

    /**
     * Activation for the self-serve funnel: open the 14-day trial for an
     * account whose mailbox has just been proven (SignupGuard::verify). The
     * one choke point so the verify page and tests can never drift apart.
     *
     * @return object|false|null the new trial row, false when one already
     *         exists, null on insert failure
     */
    public static function activate_trial(int $user_id): object|false|null
    {
        return self::start_trial($user_id);
    }

    /** True when the given subscription row is a trial row. */
    public static function is_trial_row(mixed $sub): bool
    {
        return $sub !== null
            && strtolower((string) ($sub->plan ?? '')) === 'trial'
            && strtolower((string) ($sub->status ?? '')) === 'trial';
    }

    /** The user's trial row (plan+status 'trial'), or null. */
    public static function get_trial(mixed $user_id): ?object
    {
        global $db;
        return $db->fetchOne(
            "SELECT * FROM tblsubscriptions WHERE user_id = :u AND plan = 'trial' AND status = 'trial' ORDER BY id DESC LIMIT 1",
            [':u' => (int) $user_id]
        );
    }

    /** Grace-window length in days (env: WPMS_SUB_GRACE_DAYS, default 7). */
    public static function grace_days(): int
    {
        $d = filter_var(getenv('WPMS_SUB_GRACE_DAYS'), FILTER_VALIDATE_INT);
        return ($d === false || $d < 0) ? 7 : $d;
    }

    /** Whether the grace window (and its SMS dunning) is switched on. */
    public static function grace_enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('WPMS_SUB_GRACE'))), ['1', 'true', 'yes', 'on'], true);
    }

    /** Whether the cron auto-renewal (STK prompt before expiry) is switched on. */
    public static function auto_renew_enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('WPMS_SUB_AUTO_RENEW'))), ['1', 'true', 'yes', 'on'], true);
    }

    /** Whether the trial nurture emails (cron LEG 3) are switched on. */
    public static function trial_nurture_enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('WPMS_TRIAL_NURTURE'))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * ONE answer for every gate/banner/renderer: where is this staff member
     * relative to their subscription right now?
     *
     *  - 'active'  : an active row exists (expiry in the future)
     *  - 'grace'   : the newest row lapsed within the last N days and the
     *                newest row predates the grace feature (no renewal-clone),
     *                OR a renewal clone exists that has not paid yet
     *  - 'locked'  : no row, or the newest lapsed beyond grace
     *
     * The gate (require_staff / login_redirect_target) and every banner call
     * this so the dashboard, subscription page and redirect can never
     * disagree about the state.
     *
     * @return array{state:string, sub:?object, days_left:int, grace_days_left:int}
     */
    public static function state(mixed $user_id): array
    {
        global $db;
        $uid = (int) $user_id;
        $today = new DateTimeImmutable('today');

        $rows = self::get_all_for_user($uid);
        if ($rows === []) {
            return ['state' => 'locked', 'sub' => null, 'days_left' => 0, 'grace_days_left' => 0, 'trial' => false];
        }

        // Newest row decides. Rows are ORDER BY created_at DESC already.
        $newest = $rows[0];
        $isClone = ((int) ($newest->auto_renew ?? 1)) === 2;
        $expiry  = new DateTimeImmutable((string) $newest->expiry_date);

        if ($expiry >= $today) {
            return [
                'state'           => 'active',
                'sub'             => $newest,
                'days_left'       => (int) $today->diff($expiry)->days,
                'grace_days_left' => 0,
                'trial'           => self::is_trial_row($newest),
            ];
        }

        $lapsedDays = (int) $expiry->diff($today)->days; // days past expiry
        $inWindow = self::grace_enabled() && $lapsedDays <= self::grace_days();
        if ($inWindow || $isClone) {
            return [
                'state'           => 'grace',
                'sub'             => $newest,
                'days_left'       => 0,
                'grace_days_left' => max(0, self::grace_days() - $lapsedDays),
                'trial'           => self::is_trial_row($newest),
            ];
        }
        return ['state' => 'locked', 'sub' => null, 'days_left' => 0, 'grace_days_left' => 0, 'trial' => false];
    }

    /**
     * The next renewal candidate for one staff member, or null:
     *   - during grace: a pending renewal clone if one exists (else null —
     *     the clone is created the day after expiry by the cron)
     *   - otherwise: the active row when it is inside the renewal window
     *     (expiry within the next WPMS_SUB_RENEW_DAYS days, default 3)
     * Only auto_renew=1 rows are ever renewed.
     *
     * @return object|null row from tblsubscriptions
     */
    public static function renewal_candidate(mixed $user_id): ?object
    {
        $uid = (int) $user_id;
        $today = new DateTimeImmutable('today');
        $window = max(1, (int) (getenv('WPMS_SUB_RENEW_DAYS') ?: 3));

        $rows = self::get_all_for_user($uid);
        // Newest PAID row and newest pending promise, by id (rows are
        // created_at DESC, but ids are the tiebreaker that never lies).
        $newestPaid  = null;
        $newestClone = null;
        foreach ($rows as $row) {
            $isClone = ((int) ($row->auto_renew ?? 1)) === 2;
            if ($isClone && strtolower((string) $row->status) === 'pending_renewal') {
                if ($newestClone === null) {
                    $newestClone = $row;
                }
                continue;
            }
            if ($newestPaid === null) {
                $newestPaid = $row;
            }
        }
        // A promise newer than any payment IS the live renewal attempt —
        // even when a newer manual purchase exists ABOVE it is impossible
        // (paid row would have a higher id). Promise OLDER than a paid row
        // means the planner paid manually after the prompt died: ignore it.
        if ($newestClone !== null
            && ($newestPaid === null || (int) $newestClone->id > (int) $newestPaid->id)) {
            return $newestClone;
        }
        if ($newestPaid !== null
            && strtolower((string) $newestPaid->status) === 'active'
            && strtolower((string) $newestPaid->plan) !== 'trial' // never "renew" a trial
            && ((int) ($newestPaid->auto_renew ?? 1)) === 1) {
            $expiry = new DateTimeImmutable((string) $newestPaid->expiry_date);
            if ($expiry >= $today && (int) $expiry->diff($today)->days <= $window) {
                return $newestPaid; // active row inside the renewal window
            }
        }
        return null;
    }

    /**
     * All staff whose NEWEST subscription row is an active auto-renew row
     * inside the renewal window (cron candidates for the STK prompt).
     * Users with a pending promise are excluded — one prompt at a time.
     *
     * @return array<object> tblsubscriptions rows (newest per user)
     */
    public static function auto_renew_candidates(int $windowDays): array
    {
        global $db;
        $windowDays = max(0, $windowDays);
        return $db->fetchAll(
            "SELECT t.* FROM tblsubscriptions t
              JOIN (SELECT user_id, MAX(id) AS max_id FROM tblsubscriptions GROUP BY user_id) m
                ON m.user_id = t.user_id AND m.max_id = t.id
              WHERE t.status = 'active'
                AND t.plan != 'trial'   -- a trial is never renewed
                AND t.auto_renew = 1
                AND t.expiry_date >= CURDATE()
                AND DATEDIFF(t.expiry_date, CURDATE()) <= {$windowDays}
                AND NOT EXISTS (
                    SELECT 1 FROM tblsubscriptions c
                     WHERE c.user_id = t.user_id AND c.status = 'pending_renewal' AND c.auto_renew = 2
                )"
        );
    }

    /**
     * Delete dead promises: pending clones with no live push behind them and
     * more than a day old. Covers the prompt that timed out, the initiate
     * that failed, the planner who paid manually afterwards, and the one who
     * turned auto-renew off. Without this sweep an unpaid promise would pin
     * state() to 'grace' forever (the clone never expires forward).
     *
     * @return int rows deleted
     */
    public static function sweep_pending_renewals(): int
    {
        global $db;
        $clones = $db->fetchAll(
            "SELECT * FROM tblsubscriptions
              WHERE status = 'pending_renewal' AND auto_renew = 2
                AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $deleted = 0;
        foreach ($clones as $clone) {
            $livePush = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM tblmpesa_stk_pushes
                  WHERE user_id = :u AND purpose = 'subscription' AND status = 'pending'
                    AND requested_at >= :c",
                [':u' => (int) $clone->user_id, ':c' => (string) $clone->created_at]
            );
            if ($livePush > 0) {
                continue; // the prompt is still being answered on stk_status.php
            }
            $db->execute("DELETE FROM tblsubscriptions WHERE id = :i AND status = 'pending_renewal'",
                [':i' => (int) $clone->id]);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * Clone the newest subscription row as a PENDING renewal (the STK
     * "promise") so a crash mid-renewal never leaves the account without a
     * trace of what was attempted. The clone carries auto_renew=2, which:
     *   - keeps state() in 'grace' if expiry passes before payment lands,
     *   - is invisible to get_active() (expiry in the past).
     * Called by the cron BEFORE prompting; fulfilled by MpesaStk::fulfil()
     * via extend_renewal(), which flips the clone back to auto_renew=1.
     */
    public static function create_renewal_clone(mixed $user_id, string $plan, mixed $amount): ?object
    {
        global $db;
        $uid     = (int) $user_id;
        // Idempotent: one live promise per user — a second call (cron overlap,
        // retry) returns the existing one instead of stacking promises.
        $existing = $db->fetchOne(
            "SELECT * FROM tblsubscriptions WHERE user_id = :u AND status = 'pending_renewal' AND auto_renew = 2 ORDER BY id DESC LIMIT 1",
            [':u' => $uid]
        );
        if ($existing !== null) {
            return $existing;
        }
        // Base = the newest PAID row by id (never get_active()'s expiry
        // ordering — with any two live rows the id is the only truth).
        $base = $db->fetchOne(
            "SELECT * FROM tblsubscriptions
              WHERE user_id = :u AND NOT (status = 'pending_renewal' AND auto_renew = 2)
              ORDER BY id DESC LIMIT 1",
            [':u' => $uid]
        );
        if ($base === null) {
            return null;
        }
        $start  = date('Y-m-d', max(strtotime((string) $base->expiry_date), strtotime('today')));
        $expiry = date('Y-m-d', strtotime($start . ' +30 days'));
        $stmt = $db->prepare(
            "INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status, auto_renew)
             VALUES (:uid, :plan, :amount, :code, :start, :expiry, 'pending_renewal', 2)"
       );
        $ok = $stmt->execute([
            ':uid'    => $uid,
            ':plan'   => $plan,
            ':amount' => $amount,
            ':code'   => 'PENDING-' . strtoupper(bin2hex(random_bytes(5))),
            ':start'  => $start,
            ':expiry' => $expiry,
        ]);
        if (!$ok) {
            return null;
        }
        return self::sub_by_id((int) $db->the_insert_id());
    }

    /**
     * Turn a paid renewal into the real thing: mark the promise paid and
     * extend the SAME subscription's expiry by 30 days from its own expiry
     * (not from today — a payment that lands mid-grace must not shorten the
     * entitlement the planner already had). Exactly-once: only a row still
     * stamped pending_renewal/auto_renew=2 is extended.
     */
    public static function extend_renewal(mixed $user_id, string $plan, mixed $amount, string $mpesa_code): bool
    {
        global $db;
        $uid = (int) $user_id;
        // The promise: newest pending clone for this user+plan.
        $clone = $db->fetchOne(
            "SELECT * FROM tblsubscriptions
              WHERE user_id = :u AND plan = :p AND status = 'pending_renewal' AND auto_renew = 2
              ORDER BY id DESC LIMIT 1",
            [':u' => $uid, ':p' => $plan]
        );
        if ($clone === null) {
            return false; // nothing pending — a stale/late callback arrives here
        }
        // The promise becomes the live row…
        $stmt = $db->prepare(
            "UPDATE tblsubscriptions
                SET amount = :a, mpesa_code = :c, status = 'active', auto_renew = 1,
                    renewal_sms_band = NULL
              WHERE id = :id AND status = 'pending_renewal' AND auto_renew = 2"
        );
        $ok = $stmt->execute([
            ':a' => $amount, ':c' => $mpesa_code, ':id' => (int) $clone->id,
        ]);
        if (!$ok || $stmt->rowCount() === 0) {
            return false; // raced — another caller extended it already
        }
        // …and the row it renews is superseded: mark it expired so the user
        // always has exactly ONE live subscription (renewals extend in
        // place, never stack a second active row beside the old one).
        $db->execute(
            "UPDATE tblsubscriptions SET status = 'expired'
              WHERE user_id = :u AND plan = :p AND status = 'active' AND id < :id",
            [':u' => $uid, ':p' => $plan, ':id' => (int) $clone->id]
        );
        return true;
    }

    /** One subscription row by id, or null. (Named to avoid the DB_Object::find_by_id signature.) */
    public static function sub_by_id(int $id): ?object
    {
        global $db;
        return $db->fetchOne('SELECT * FROM tblsubscriptions WHERE id = :i', [':i' => $id]);
    }

    /** Toggle auto-renew for the staff member's CURRENT live row. */
    public static function set_auto_renew(mixed $user_id, bool $on): bool
    {
        global $db;
        $stmt = $db->prepare("UPDATE tblsubscriptions SET auto_renew = :v WHERE user_id = :u AND status = 'active'");
        return $stmt->execute([':v' => $on ? 1 : 0, ':u' => (int) $user_id]);
    }

    /**
     * Dunning targets: staff whose newest subscription row is (a) active
     * inside an SMS band, or (b) lapsed within the grace window. Newest row
     * per user only — history rows are never texted.
     *
     * @return array<object{user_id, plan, price, phone, days_left, grace_days_left, state}>
     */
    public static function dunning_targets(array $bands): array
    {
        global $db;
        $out = [];
        $today = new DateTimeImmutable('today');
        $grace = self::grace_enabled() ? self::grace_days() : 0;

        $rows = $db->fetchAll(
            "SELECT t.* FROM tblsubscriptions t
              JOIN (SELECT user_id, MAX(id) AS max_id FROM tblsubscriptions GROUP BY user_id) m
                ON m.user_id = t.user_id AND m.max_id = t.id"
        );
        foreach ($rows as $row) {
            if (((int) ($row->auto_renew ?? 1)) === 2) {
                // A pending renewal clone IS the promise — the STK leg owns
                // this user this run; the dunning text would be noise.
                continue;
            }
            $expiry = new DateTimeImmutable((string) $row->expiry_date);
            $state  = $expiry >= $today ? 'active' : 'grace';
            $daysLeft = 0;
            $graceLeft = 0;
            if ($state === 'active' && strtolower((string) $row->plan) === 'trial') {
                // Trials get ONE expiry-eve text (band 0 handled by the cron
                // with trial copy), not the full 7/3/0 band ladder.
                $daysLeft = (int) $expiry->diff($today)->days;
                if ($daysLeft !== 0 || !in_array(0, $bands, true)) {
                    continue;
                }
                $out[] = ['sub' => $row, 'state' => 'trial', 'days_left' => 0, 'grace_days_left' => 0];
                continue;
            }
            if ($state === 'active') {
                $daysLeft = (int) $expiry->diff($today)->days;
                // Manual-renew planners (auto_renew = 0) need the reminder
                // most — do not filter them out here.
                if (!in_array($daysLeft, $bands, true)) {
                    continue;
                }
            } else {
                if ($grace <= 0) {
                    continue;
                }
                $lapsed = (int) $expiry->diff($today)->days;
                if ($lapsed > $grace) {
                    continue;
                }
                $graceLeft = $grace - $lapsed;
            }
            $out[] = [
                'sub'             => $row,
                'state'           => $state,
                'days_left'       => $daysLeft,
                'grace_days_left' => $graceLeft,
            ];
        }
        return $out;
    }

    /**
     * Trial nurture-email targets (cron LEG 3): the newest row per user,
     * trial-status rows only, each matched against the nurture bands —
     * [check-in, last-call] in days-left. Defaults follow the trial length:
     * halfway point and the day before expiry (7 and 1 for WPMS_TRIAL_DAYS
     * = 14). Converted planners cannot appear: their newest row is paid.
     *
     * @param array<int,int>|null $bands override; null = derive from trial length
     * @return array<array{sub:object, days_left:int, band:int}>
     */
    public static function trial_nurture_targets(?array $bands = null): array
    {
        global $db;
        $today = new DateTimeImmutable('today');
        if ($bands === null) {
            $d = self::trial_days();
            $bands = [max(1, intdiv($d, 2)), 1];
        }
        $bands = array_values(array_unique(array_map('intval', $bands)));
        sort($bands);

        $rows = $db->fetchAll(
            "SELECT t.*, u.email AS user_email, u.firstname AS user_firstname
             FROM tblsubscriptions t
              JOIN (SELECT user_id, MAX(id) AS max_id FROM tblsubscriptions GROUP BY user_id) m
                ON m.user_id = t.user_id AND m.max_id = t.id
              JOIN tblusers u ON u.id = t.user_id"
        );
        $out = [];
        foreach ($rows as $row) {
            if (strtolower((string) $row->status) !== 'trial') {
                continue; // trials only — converted planners never appear
            }
            $expiry = new DateTimeImmutable((string) $row->expiry_date);
            if ($expiry < $today) {
                continue; // lapsed — the trial is over, no nurture
            }
            $daysLeft = (int) $expiry->diff($today)->days;
            if (!in_array($daysLeft, $bands, true)) {
                continue;
            }
            $out[] = ['sub' => $row, 'days_left' => $daysLeft, 'band' => $daysLeft];
        }
        return $out;
    }

    /** Smallest nurture band already emailed for one row (PHP_INT_MAX when none). */
    public static function sent_nurture_band(mixed $sub): int
    {
        $v = $sub->trial_nurture_band ?? null;
        return $v === null ? PHP_INT_MAX : (int) $v;
    }

    /** Stamp the smallest nurture band emailed (marker-first, like stamp_band). */
    public static function stamp_nurture_band(int $sub_id, int $band): void
    {
        global $db;
        $db->execute(
            'UPDATE tblsubscriptions SET trial_nurture_band = :b WHERE id = :i',
            [':b' => $band, ':i' => $sub_id]
        );
    }

    /** Smallest dunning band already texted for one row (PHP_INT_MAX when none). */
    public static function sent_band(mixed $sub): int
    {
        return $sub->renewal_sms_band === null ? PHP_INT_MAX : (int) $sub->renewal_sms_band;
    }

    /** Stamp the smallest dunning band texted (marker-first, like pay_band_sms_sent). */
    public static function stamp_band(int $sub_id, int $band): void
    {
        global $db;
        $db->execute('UPDATE tblsubscriptions SET renewal_sms_band = :b WHERE id = :i', [':b' => $band, ':i' => $sub_id]);
    }

    /** Phone on file for a staff member ('' when none — callers skip the text). */
    public static function staff_phone(mixed $user_id): string
    {
        global $db;
        $phone = $db->fetchValue('SELECT phone FROM tblusers WHERE id = :u', [':u' => (int) $user_id]);
        return trim((string) ($phone ?? ''));
    }

    /** Set the staff member's phone (subscription page toggle form). */
    public static function set_staff_phone(mixed $user_id, string $phone): bool
    {
        global $db;
        $stmt = $db->prepare('UPDATE tblusers SET phone = :p WHERE id = :u');
        return $stmt->execute([':p' => substr(trim($phone), 0, 20), ':u' => (int) $user_id]);
    }

    public static function get_plan(string $plan_slug): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblsubscription_plans WHERE plan_slug = :slug LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':slug' => $plan_slug]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function get_all_plans(): array
    {
        global $db;
        $sql = "SELECT * FROM tblsubscription_plans WHERE is_active = 1 ORDER BY price ASC";
        // No user input — safe direct query
        return $db->fetchAll($sql);
    }
}

/**
 * M-Pesa C2B verification (Daraja callback verification)
 *
 * When a customer pays via Paybill / Buy Goods, Safaricom pushes a validation
 * & confirmation notification to the endpoint registered on the Daraja portal
 * (mpesa_c2b.php in this project). Those payloads are stored in
 * tblmpesa_callbacks; the 10-char TransID is exactly the code users type into
 * subscription.php and budget_add.php.
 *
 * Verification gates a code by checking its callbacks:
 *   • amount tolerance  — the reported amount must match the expected within
 *                         WPMS_MPESA_TOLERANCE (default 1%, floor KSh 1)
 *   • account reference — when the expected ref is known (booking #, WPMS),
 *                         the bill_ref_number must contain it; empty DB refs
 *                         pass (payers often leave the reference blank)
 *   • duplicate use     — a callback already verified for another row is
 *                         refused (one payment cannot activate two things)
 *
 * Rejected payloads are KEPT with verified_at = NULL + a reject_reason so an
 * admin can see what was refused. The ledger is append-only (UNIQUE(trans_type,
 * trans_id)) — replays of the same Daraja payload are no-ops.
 */
class MpesaC2B
{
    /** Current Daraja C2B validation/confirmation schema. */
    private const ALLOWED_KEYS = [
        'TransactionType', 'TransID', 'TransTime', 'TransAmount', 'BusinessShortCode',
        'BillRefNumber', 'InvoiceNumber', 'OrgAccountBalance', 'ThirdPartyTransactionID',
        'MSISDN', 'FirstName', 'MiddleName', 'LastName',
    ];

    /** @return array{enabled:bool, tolerance:float} */
    public static function config(): array
    {
        return [
            'enabled'   => self::verification_enabled(),
            'tolerance' => self::tolerance(),
        ];
    }

    public static function verification_enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('WPMS_MPESA_VERIFY'))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Amount tolerance fraction: env override, default 1%. Matches are
     * additionally guarded by a ±0.005 absolute epsilon in verify(), so
     * cent-level drift never blocks a payment.
     */
    public static function tolerance(): float
    {
        $t = filter_var(getenv('WPMS_MPESA_TOLERANCE'), FILTER_VALIDATE_FLOAT);
        if ($t === false || $t < 0 || $t > 1) {
            return 0.01;
        }
        return $t;
    }

    /**
     * Validate + ingest a Daraja callback payload (from mpesa_c2b.php).
     * Append-only: unique(trans_type, trans_id) makes replays no-ops.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function ingest(array $payload): array
    {
        global $db;

        if (!self::keys_allowed($payload)) {
            return ['ok' => false, 'error' => 'Unknown payload keys — not a Daraja C2B notification'];
        }
        $transId   = strtoupper(trim((string) ($payload['TransID'] ?? '')));
        $time      = trim((string) ($payload['TransTime'] ?? ''));
        $amount    = filter_var($payload['TransAmount'] ?? null, FILTER_VALIDATE_FLOAT);
        $shortcode = trim((string) ($payload['BusinessShortCode'] ?? ''));
        $msisdn    = trim((string) ($payload['MSISDN'] ?? ''));
        if ($transId === '' || $time === '' || $amount === false || $amount <= 0 || $shortcode === '' || $msisdn === '') {
            return ['ok' => false, 'error' => 'Missing or invalid required fields (TransID, TransTime, TransAmount, BusinessShortCode, MSISDN)'];
        }
        if (!preg_match('/^[A-Z0-9]{6,20}$/', $transId)) {
            return ['ok' => false, 'error' => 'Malformed TransID'];
        }
        if (!preg_match('/^\d{14}$/', $time)) { // Daraja format YYYYMMDDHHmmss
            return ['ok' => false, 'error' => 'Malformed TransTime (expected YYYYMMDDHHmmss)'];
        }
        if (!preg_match('/^\d{6,15}$/', $msisdn)) {
            return ['ok' => false, 'error' => 'Malformed MSISDN'];
        }
        // Defense in depth: when a short code is pinned (WPMS_MPESA_SHORTCODE),
        // refuse payments reported for a different till/paybill — the endpoint
        // already answers ResultCode 1 for these, ingest() refuses them too so
        // every path into the ledger is pinned, not just HTTP.
        $expectedSC = trim((string) getenv('WPMS_MPESA_SHORTCODE'));
        if ($expectedSC !== '' && $shortcode !== $expectedSC) {
            return ['ok' => false, 'error' => 'Unexpected business short code'];
        }

        $stmt = $db->prepare(
            'INSERT INTO tblmpesa_callbacks
                (trans_type, trans_id, trans_time, amount, business_short_code, bill_ref_number,
                 msisdn, first_name, middle_name, last_name, org_account_balance, invoice_number)
             VALUES
                (:ttype, :tid, :ttime, :amount, :shortcode, :billref, :msisdn, :fname, :mname, :lname, :balance, :invoice)'
        );
        try {
            $stmt->execute([
                ':ttype'     => (($payload['TransactionType'] ?? '') === 'Buy Goods') ? 'Buy Goods' : 'Pay Bill',
                ':tid'       => $transId,
                ':ttime'     => $time,
                ':amount'    => $amount,
                ':shortcode' => substr($shortcode, 0, 12),
                ':billref'   => substr(trim((string) ($payload['BillRefNumber'] ?? '')), 0, 100) ?: null,
                ':msisdn'    => $msisdn,
                ':fname'     => substr(trim((string) ($payload['FirstName'] ?? '')), 0, 100) ?: null,
                ':mname'     => substr(trim((string) ($payload['MiddleName'] ?? '')), 0, 100) ?: null,
                ':lname'     => substr(trim((string) ($payload['LastName'] ?? '')), 0, 100) ?: null,
                ':balance'   => substr(trim((string) ($payload['OrgAccountBalance'] ?? '')), 0, 20) ?: null,
                ':invoice'   => substr(trim((string) ($payload['InvoiceNumber'] ?? '')), 0, 20) ?: null,
            ]);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['ok' => true, 'id' => 0]; // idempotent replay
            }
            error_log('[WPMS][MpesaC2B] ingest failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Storage failure'];
        }
        return ['ok' => true, 'id' => (int) $db->the_insert_id()];
    }

    /**
     * Verify a user-typed code against stored Daraja callbacks.
     *
     * Only genuine, unconsumed callbacks can verify: rows stamped by
     * record_reject() (a user-typed attempt that failed review) are excluded,
     * so a code can never be "verified" against its own failed attempt.
     *
     * @param string $code       the 10-char M-Pesa transaction code (case-insensitive)
     * @param float  $amount     expected amount (KSh)
     * @param string $accountRef expected account reference ('' = accept any non-empty ref)
     * @return array{verified:bool, reason:string, callback_id:?int, amount:float|null}
     */
    public static function verify(string $code, float $amount, string $accountRef = ''): array
    {
        global $db;
        $code = strtoupper(trim($code));

        $rows = $db->fetchAll(
            'SELECT * FROM tblmpesa_callbacks
              WHERE trans_id = :c AND verified_at IS NULL AND reject_reason IS NULL
              ORDER BY id ASC',
            [':c' => $code]
        );
        if ($rows === []) {
            return ['verified' => false, 'reason' => 'No matching M-Pesa transaction was received for this code', 'callback_id' => null, 'amount' => null];
        }

        foreach ($rows as $row) {
            // Multiple callbacks per code are possible (a Pay Bill and a Buy
            // Goods row can share a TransID) — take the first that matches.
            // Consumed and rejected rows never reach this loop.
            if ((float) $row->amount < $amount * (1 - self::tolerance()) - 0.005) {
                continue; // underpaid beyond tolerance
            }
            if ((float) $row->amount > $amount * (1 + self::tolerance()) + 0.005) {
                continue; // overpaid beyond tolerance (probably a different purchase)
            }
            $ref  = strtoupper(trim((string) $row->bill_ref_number));
            $want = strtoupper(trim($accountRef));
            if ($want !== '' && $ref !== '' && strpos($ref, $want) === false) {
                continue; // wrong account reference
            }
            return ['verified' => true, 'reason' => 'Matched M-Pesa payment of ' . format_ksh((float) $row->amount, 2), 'callback_id' => (int) $row->id, 'amount' => (float) $row->amount];
        }
        return ['verified' => false, 'reason' => 'M-Pesa payment found but amount or account reference does not match', 'callback_id' => null, 'amount' => null];
    }

    /**
     * Mark a callback as consumed by a subscription / payment record.
     */
    public static function mark_consumed(int $callback_id, string $ref_table, int $ref_id): bool
    {
        global $db;
        $stmt = $db->prepare(
            'UPDATE tblmpesa_callbacks SET verified_at = NOW(), ref_table = :t, ref_id = :r WHERE id = :id AND verified_at IS NULL'
        );
        $stmt->execute([':t' => $ref_table, ':r' => $ref_id, ':id' => $callback_id]);
        // rowCount(), not execute(): a callback already consumed must report
        // failure, not success-with-zero-rows (race-safe re-binding guard).
        return $stmt->rowCount() > 0;
    }

    /**
     * Record a failed verification attempt (kept for the admin review queue).
     */
    public static function record_reject(string $code, string $reason, float $amount): void
    {
        global $db;
        $stmt = $db->prepare(
            'INSERT INTO tblmpesa_callbacks (trans_type, trans_id, trans_time, amount, business_short_code, msisdn, reject_reason)
             VALUES (:ttype, :tid, :ttime, :amount, :shortcode, :msisdn, :reason)'
        );
        try {
            $stmt->execute([
                ':ttype'     => 'Pay Bill',
                ':tid'       => strtoupper(trim($code)),
                ':ttime'     => date('YmdHis'),
                ':amount'    => $amount,
                ':shortcode' => substr((string) (getenv('WPMS_MPESA_SHORTCODE') ?: '000000'), 0, 12),
                ':msisdn'    => '0',
                ':reason'    => substr($reason, 0, 255),
            ]);
        } catch (PDOException $e) {
            // A duplicate reject is fine — the admin queue shows the first reason.
        }
    }

    /**
     * Pending / rejected rows for the admin review queue (admin/mpesa_ledger.php).
     */
    public static function pending_review(int $limit = 100): array
    {
        global $db;
        return $db->fetchAll(
            'SELECT * FROM tblmpesa_callbacks WHERE verified_at IS NULL ORDER BY created_at DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /**
     * @return array{verified:int, pending:int, rejected:int, total_ksh:float}
     */
    public static function stats(): array
    {
        global $db;
        $row = $db->fetchOne(
            "SELECT
                SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN verified_at IS NULL AND reject_reason IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN verified_at IS NULL AND reject_reason IS NOT NULL THEN 1 ELSE 0 END) AS rejected,
                COALESCE(SUM(CASE WHEN verified_at IS NOT NULL THEN amount ELSE 0 END), 0) AS total_ksh
               FROM tblmpesa_callbacks"
        );
        return [
            'verified'  => (int) ($row->verified ?? 0),
            'pending'   => (int) ($row->pending ?? 0),
            'rejected'  => (int) ($row->rejected ?? 0),
            'total_ksh' => (float) ($row->total_ksh ?? 0),
        ];
    }

    /** Daraja payload key allow-list — rejects junk/forged POSTs to the callback. */
    private static function keys_allowed(array $payload): bool
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                return false;
            }
        }
        return true;
    }
}

/**
 * M-Pesa STK push (initiated-from-app payment, Daraja Lipa na M-Pesa Online)
 *
 * The app asks Daraja to prompt the customer's phone ("An OTP-style prompt on
 * their phone"); the customer enters their PIN and Daraja reports the result
 * asynchronously on the callback URL (mpesa_stk_callback.php). A push is a
 * PROMISE, not a payment — only a successful callback fulfils it.
 *
 * Transport: without Daraja credentials (WPMS_MPESA_STK_CONSUMER_KEY/SECRET)
 * the transport is SIMULATED — the push row is created exactly as production
 * would, the phone prompt simply never happens in the real world, and tests
 * / demos apply callbacks directly via apply_callback(). With credentials the
 * real OAuth + STK endpoints are called; the callback flow is identical.
 *
 * Fulfilment is exactly-once per push (status gate + the C2B single-use
 * ledger via MpesaC2B::mark_consumed on the push row id), mirroring the
 * replay-safety of the C2B ingest path.
 */
class MpesaStk
{
    /** What a completed push reports. */
    public const RESULT_OK          = 0;     // Daraja: accepted, payment made
    public const RESULT_CANCELLED   = 1032;  // customer cancelled / dismissed the prompt
    public const RESULT_TIMEOUT     = 1037;  // prompt timed out unanswered

    /** How long a pending push may sit before cancel_stale() gives up on it. */
    public const STALE_MINUTES = 5;

    /** @return array{enabled:bool, credentials:bool} */
    public static function config(): array
    {
        return [
            'enabled'     => self::enabled(),
            'credentials' => trim((string) getenv('WPMS_MPESA_STK_CONSUMER_KEY')) !== ''
                          && trim((string) getenv('WPMS_MPESA_STK_CONSUMER_SECRET')) !== '',
        ];
    }

    /** Master switch: when off, the STK UI is hidden and initiate() refuses. */
    public static function enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('WPMS_MPESA_STK'))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Ask Daraja to prompt $phone for $amount.
     *
     * @param string $purpose 'subscription' (plan slug) or 'deposit' (booking + event)
     * @return array{ok:bool, error?:string, push?:object, status_url?:string}
     */
    public static function initiate(string $purpose, int $userId, string $phone, float $amount, array $context = []): array
    {
        global $db;
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'STK push is not enabled on this installation'];
        }
        $phone = SMS::format_phone($phone);
        if (!preg_match('/^254[17]\d{8}$/', $phone)) {
            return ['ok' => false, 'error' => 'Please enter a valid Kenyan M-Pesa number (e.g. 0712 345 678).'];
        }
        if ($amount < 1) {
            return ['ok' => false, 'error' => 'Amount must be at least KSh 1.'];
        }
        if ($purpose === 'subscription') {
            $plan = (string) ($context['plan'] ?? '');
            if (Subscription::get_plan($plan) === false || Subscription::get_plan($plan) === null) {
                return ['ok' => false, 'error' => 'Unknown plan.'];
            }
            $bookingId = null;
            $eventId   = null;
        } elseif ($purpose === 'deposit') {
            $bookingId = (int) ($context['booking_id'] ?? 0);
            $eventId   = (int) ($context['event_id'] ?? 0);
            $booking   = Booking::find_by_booking_id($bookingId);
            if (!$booking) {
                return ['ok' => false, 'error' => 'Booking not found.'];
            }
            $category = Category::find_by_id((int) $booking->wedding_type);
            $price    = (float) ($category->price ?? 0);
            $paid     = (float) (Liquidation::getTotalsByMethod($bookingId)['grand_total'] ?? 0);
            $balance  = max(0.0, $price - $paid);
            if ($balance <= 0) {
                return ['ok' => false, 'error' => 'Your package is fully paid — there is no balance due.'];
            }
            if ($amount < 50 || $amount > $balance) {
                return ['ok' => false, 'error' => 'Amount must be between KSh 50 and your balance due of ' . format_ksh($balance, 2) . '.'];
            }
            $plan = null;
        } else {
            return ['ok' => false, 'error' => 'Unknown STK purpose.'];
        }

        // Row first (the promise), real Daraja ids stamped after the answer.
        // The placeholder keeps uniq(checkout_request_id) satisfied if the
        // transport dies before returning an id; it is replaced immediately.
        $placeholder = 'STKPENDING-' . bin2hex(random_bytes(12));
        $stmt = $db->prepare(
            'INSERT INTO tblmpesa_stk_pushes
                (checkout_request_id, purpose, plan, booking_id, event_id, user_id, phone, amount, status)
             VALUES (:cr, :p, :plan, :bid, :eid, :uid, :ph, :amt, :st)'
        );
        $stmt->execute([
            ':cr' => $placeholder, ':p' => $purpose, ':plan' => $plan,
            ':bid' => $bookingId, ':eid' => $eventId, ':uid' => $userId,
            ':ph' => $phone, ':amt' => $amount, ':st' => 'pending',
        ]);
        $id   = (int) $db->the_insert_id();
        $push = self::find($id);
        if ($push === null) {
            return ['ok' => false, 'error' => 'Could not start the payment prompt.'];
        }

        $transport = self::transport($phone, (int) round($amount), $purpose);
        if (!$transport['ok']) {
            $db->execute("UPDATE tblmpesa_stk_pushes SET status = 'failed', result_desc = :d WHERE id = :id",
                [':d' => substr((string) ($transport['error'] ?? 'transport error'), 0, 255), ':id' => $id]);
            return ['ok' => false, 'error' => $transport['error'] ?? 'The M-Pesa prompt could not be sent.'];
        }

        $db->execute(
            'UPDATE tblmpesa_stk_pushes SET checkout_request_id = :cr, merchant_request_id = :mr WHERE id = :id',
            [
                ':cr' => substr((string) $transport['checkout_request_id'], 0, 50),
                ':mr' => substr((string) ($transport['merchant_request_id'] ?? ''), 0, 50),
                ':id' => $id,
            ]
        );
        return [
            'ok'         => true,
            'push'       => self::find($id),
            'status_url' => 'stk_status.php?id=' . $id,
        ];
    }

    /**
     * Ask Daraja to prompt the phone. Without credentials this SIMULATES a
     * successful dispatch — the prompt never happens in the real world, but
     * the ledger and callback flow behave exactly as production.
     *
     * @return array{ok:bool, checkout_request_id?:string, merchant_request_id?:string, error?:string}
     */
    private static function transport(string $phone, int $amount, string $purpose): array
    {
        $key    = trim((string) getenv('WPMS_MPESA_STK_CONSUMER_KEY'));
        $secret = trim((string) getenv('WPMS_MPESA_STK_CONSUMER_SECRET'));
        $shortcode = trim((string) (getenv('WPMS_MPESA_STK_SHORTCODE') ?: getenv('WPMS_MPESA_SHORTCODE') ?: ''));
        $passkey   = trim((string) getenv('WPMS_MPESA_STK_PASSKEY'));

        if ($key === '' || $secret === '' || $shortcode === '' || $passkey === '') {
            // Simulated dispatch (dev/demo + tests).
            return [
                'ok'                  => true,
                'checkout_request_id' => 'ws_CO_SIM-' . strtoupper(bin2hex(random_bytes(8))),
                'merchant_request_id' => 'SIM-' . strtoupper(bin2hex(random_bytes(6))),
            ];
        }

        // --- Real Daraja Lipa na M-Pesa Online ---------------------------
        $base    = getenv('WPMS_MPESA_STK_ENV') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
        $token = self::oauth_token($base, $key, $secret);
        if ($token === null) {
            return ['ok' => false, 'error' => 'M-Pesa authentication failed — check the STK credentials.'];
        }
        $timestamp = date('YmdHis');
        $ch = curl_init($base . '/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'BusinessShortCode' => $shortcode,
                'Password'          => base64_encode($shortcode . $passkey . $timestamp),
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => $amount,
                'PartyA'            => $phone,
                'PartyB'            => $shortcode,
                'PhoneNumber'       => $phone,
                'CallBackURL'       => rtrim((string) (getenv('WPMS_SITE_URL') ?: ''), '/') . '/mpesa_stk_callback.php',
                'AccountReference'  => 'WPMS',
                'TransactionDesc'   => $purpose === 'subscription' ? 'Planner subscription' : 'Wedding deposit',
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = is_string($response) ? (json_decode($response, true) ?: []) : [];
        if ($httpCode !== 200 || empty($body['CheckoutRequestID'])) {
            error_log('[WPMS][MpesaStk] push rejected: HTTP ' . $httpCode . ' ' . substr((string) $response, 0, 300));
            return ['ok' => false, 'error' => (string) ($body['errorMessage'] ?? 'The M-Pesa prompt could not be sent.')];
        }
        return [
            'ok'                  => true,
            'checkout_request_id' => (string) $body['CheckoutRequestID'],
            'merchant_request_id' => (string) ($body['MerchantRequestID'] ?? ''),
        ];
    }

    /** Daraja OAuth token (cached for its ~3600s lifetime in-process). */
    private static function oauth_token(string $base, string $key, string $secret): ?string
    {
        static $cache = [];
        $k = md5($base . $key);
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $key . ':' . $secret,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $body = is_string($response) ? (json_decode($response, true) ?: []) : [];
        if (empty($body['access_token'])) {
            return null;
        }
        return $cache[$k] = (string) $body['access_token'];
    }

    /** One push row by id (or null). */
    public static function find(int $id): ?object
    {
        global $db;
        return $db->fetchOne('SELECT * FROM tblmpesa_stk_pushes WHERE id = :id', [':id' => $id]);
    }

    /** Most recent push for a user (the status page polls this). */
    public static function latest_for_user(int $userId): ?object
    {
        global $db;
        return $db->fetchOne(
            'SELECT * FROM tblmpesa_stk_pushes WHERE user_id = :u ORDER BY id DESC LIMIT 1',
            [':u' => $userId]
        );
    }

    /** Ownership check for the status page: staff may view any, owners their own. */
    public static function find_for_user(int $id, int $userId, bool $isStaff): ?object
    {
        $push = self::find($id);
        if ($push === null) {
            return null;
        }
        return ($isStaff || (int) $push->user_id === $userId) ? $push : null;
    }

    /**
     * Apply a Daraja STK result callback (from mpesa_stk_callback.php).
     * Replay-safe: an already-answered push is a no-op success.
     *
     * Expected Daraja Body structure (both success and failure arrive here):
     *   { Body: { stkCallback: { CheckoutRequestID, ResultCode, ResultDesc,
     *             CallbackMetadata: { Item: [ {Name, Value}... ] } } } }
     *
     * @return array{ok:bool, replay?:bool, error?:string, status?:string}
     */
    public static function apply_callback(string $checkoutRequestId, int $resultCode, string $resultDesc, ?string $mpesaReceipt = null): array
    {
        global $db;
        $push = $db->fetchOne(
            'SELECT * FROM tblmpesa_stk_pushes WHERE checkout_request_id = :c',
            [':c' => $checkoutRequestId]
        );
        if ($push === null) {
            return ['ok' => false, 'error' => 'Unknown CheckoutRequestID'];
        }
        // Replays are no-ops. The single exception: a SUCCESS callback that
        // arrives after cancel_stale() timed the push out — money actually
        // moved, so the push is re-opened and fulfilled (only when nothing
        // was fulfilled before; a bound ref means it already happened).
        $reopen = false;
        if ($push->status !== 'pending') {
            if ($push->status === 'timeout' && $resultCode === self::RESULT_OK && $push->ref_table === null) {
                $reopen = true;
            } else {
                return ['ok' => true, 'replay' => true, 'status' => (string) $push->status];
            }
        }

        $status = match (true) {
            $resultCode === self::RESULT_OK        => 'success',
            $resultCode === self::RESULT_CANCELLED => 'cancelled',
            $resultCode === self::RESULT_TIMEOUT   => 'timeout',
            default                                 => 'failed',
        };
        $db->execute(
            'UPDATE tblmpesa_stk_pushes
                SET status = :s, result_code = :rc, result_desc = :rd, mpesa_receipt = :mr, answered_at = NOW()
              WHERE id = :id AND status = :st',
            [
                ':s' => $status, ':rc' => $resultCode,
                ':rd' => substr($resultDesc, 0, 255),
                ':mr' => $mpesaReceipt !== null ? substr($mpesaReceipt, 0, 20) : null,
                ':id' => (int) $push->id, ':st' => $reopen ? 'timeout' : 'pending',
            ]
        );
        $push = self::find((int) $push->id);
        if ($push === null) {
            return ['ok' => false, 'error' => 'Push row vanished mid-callback'];
        }

        if ($status === 'success') {
            $fulfilled = self::fulfil($push);
            if (!$fulfilled['ok']) {
                return $fulfilled; // surfaces fulfilment errors to the callback log
            }
        }
        return ['ok' => true, 'status' => $status];
    }

    /**
     * Turn a successful push into the real thing: a subscription row or a
     * booking deposit — exactly once (guarded by the status gate + ref binding).
     *
     * @return array{ok:bool, error?:string}
     */
    private static function fulfil(object $push): array
    {
        global $db;
        if ($push->purpose === 'subscription') {
            $plan = Subscription::get_plan((string) $push->plan);
            if (!$plan) {
                return ['ok' => false, 'error' => 'Plan vanished before fulfilment'];
            }
            $receipt = (string) ($push->mpesa_receipt ?: 'STK-' . $push->id);
            // Renewal first: when a pending renewal clone exists for this
            // user+plan, this push IS the renewal — extend in place instead
            // of stacking a second live row beside the lapsed one.
            if (Subscription::extend_renewal((int) $push->user_id, (string) $push->plan, (float) $plan->price, $receipt)) {
                $subId = (int) $db->fetchValue(
                    "SELECT id FROM tblsubscriptions
                      WHERE user_id = :u AND plan = :p AND status = 'active' AND auto_renew = 1
                      ORDER BY id DESC LIMIT 1",
                    [':u' => (int) $push->user_id, ':p' => (string) $push->plan]
                );
                $db->execute("UPDATE tblmpesa_stk_pushes SET ref_table = 'tblsubscriptions', ref_id = :r WHERE id = :id",
                    [':r' => $subId, ':id' => (int) $push->id]);
                $renewed = Subscription::sub_by_id($subId);
                $until   = $renewed !== null ? date('d M Y', strtotime((string) $renewed->expiry_date)) : date('d M Y', strtotime('+30 days'));
                Notification::create(
                    (int) $push->user_id,
                    0,
                    'Asante! Your ' . ucfirst((string) $push->plan) . ' renewal payment of ' . format_ksh((float) $push->amount, 2)
                        . ' went through (ref ' . $receipt . '). Valid until ' . $until . '.',
                    'subscription.php',
                    'Subscription renewed — ' . format_ksh((float) $push->amount, 2)
                );
                return ['ok' => true];
            }
            Subscription::create_subscription((int) $push->user_id, (string) $push->plan, (float) $plan->price, $receipt);
            $subId = (int) $db->fetchValue(
                'SELECT id FROM tblsubscriptions WHERE user_id = :u AND mpesa_code = :c ORDER BY id DESC LIMIT 1',
                [':u' => (int) $push->user_id, ':c' => $receipt]
            );
            $db->execute("UPDATE tblmpesa_stk_pushes SET ref_table = 'tblsubscriptions', ref_id = :r WHERE id = :id",
                [':r' => $subId, ':id' => (int) $push->id]);
            Notification::create(
                (int) $push->user_id,
                0,
                'Your ' . ucfirst((string) $push->plan) . ' subscription payment of ' . format_ksh((float) $push->amount, 2)
                    . ' went through (ref ' . $receipt . '). The plan is active.',
                'subscription.php',
                'Subscription activated — ' . format_ksh((float) $push->amount, 2)
            );
            return ['ok' => true];
        }

        // Deposit purpose: book the payment on the couple's ledger and mark
        // the push consumed (single-use — the C2B single-use discipline).
        $result = Liquidation::create_mpesa_deposit(
            (int) $push->booking_id,
            (int) $push->user_id,
            (int) $push->event_id,
            (float) $push->amount
        );
        if (!$result['ok']) {
            return ['ok' => false, 'error' => 'Fulfilment failed: ' . ($result['error'] ?? '?')];
        }
        $db->execute("UPDATE tblmpesa_stk_pushes SET ref_table = 'tbl_liquidation', ref_id = :r WHERE id = :id",
            [':r' => (int) $result['id'], ':id' => (int) $push->id]);

        $booking = Booking::find_by_booking_id((int) $push->booking_id);
        $links   = 'booking_id=' . (int) $push->booking_id . '&user_id=' . (int) $push->user_id;
        Notification::create(
            (int) $push->user_id,
            (int) $push->booking_id,
            'Asante! Your M-Pesa payment of ' . format_ksh((float) $push->amount, 2)
                . ' went through (ref ' . ($push->mpesa_receipt ?: $result['mpesa_code']) . ').',
            'client_budget.php?' . $links,
            'Payment received — ' . format_ksh((float) $push->amount, 2)
        );
        $plannerId = (int) ($booking->organizer_id ?? 0);
        if ($plannerId > 0) {
            Notification::create(
                $plannerId,
                (int) $push->booking_id,
                'STK M-Pesa deposit of ' . format_ksh((float) $push->amount, 2)
                    . ' received (ref ' . ($push->mpesa_receipt ?: $result['mpesa_code']) . ') — booking #' . (int) $push->booking_id,
                'budget.php?' . $links,
                'New M-Pesa deposit — booking #' . (int) $push->booking_id
            );
        }
        return ['ok' => true];
    }

    /** Close out pushes that were never answered (Daraja never called back). */
    public static function cancel_stale(int $minutes = self::STALE_MINUTES): int
    {
        global $db;
        $minutes = max(1, $minutes);
        $stmt = $db->execute(
            "UPDATE tblmpesa_stk_pushes
                SET status = 'timeout', result_code = " . self::RESULT_TIMEOUT . ",
                    result_desc = 'No callback received', answered_at = NOW()
              WHERE status = 'pending'
                AND requested_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
        );
        return $stmt->rowCount();
    }
}

/**
 * Revenue dashboard aggregations (admin/revenue.php)
 *
 * Three platform revenue streams, month by month:
 *   1. Subscription payments — tblsubscriptions.created_at is the moment the
 *      planner paid (create_subscription() stamps it on sign-up/renewal; the
 *      tblsubscription_payments ledger is seeded but never written by the app,
 *      so it is intentionally NOT a source).
 *   2. Vendor commissions — tblvendor_bookings.commission, counted when the
 *      deal is CONFIRMED (pending enquiries carry no agreed amount yet).
 *   3. SMS spend — tblsms_logs.cost, counted when the message actually SENT
 *      (failed/pending rows cost the planner nothing until they go out).
 */
class Revenue
{
    /** How many recent months the dashboard shows. */
    public const MONTHS_SHOWN = 12;

    /**
     * Combined monthly totals for all three streams.
     *
     * @return array<int, array{ym:string, subscriptions:float, commissions:float, sms:float, total:float}>
     */
    public static function monthly_totals(int $months = self::MONTHS_SHOWN): array
    {
        global $db;
        $months = max(1, min(36, $months));

        // 1. Subscriptions: amount recognized in the month the planner paid.
        $subs = $db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS total
               FROM tblsubscriptions
              WHERE status IN ('active', 'expired')
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
        );
        // 2. Commissions: recognized when the vendor deal is confirmed.
        $comm = $db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(commission), 0) AS total
               FROM tblvendor_bookings
              WHERE status IN ('confirmed', 'completed')
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
        );
        // 3. SMS spend: only successful sends cost credits.
        $sms = $db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(cost), 0) AS total
               FROM tblsms_logs
              WHERE status = 'sent'
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
        );

        return self::merge_months($months, $subs, $comm, $sms);
    }

    /**
     * Lifetime totals per stream (all time, statuses as monthly_totals).
     *
     * @return array{subscriptions:float, commissions:float, sms:float, total:float}
     */
    public static function lifetime_totals(): array
    {
        global $db;
        $subscriptions = (float) $db->fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM tblsubscriptions WHERE status IN ('active', 'expired')"
        );
        $commissions = (float) $db->fetchValue(
            "SELECT COALESCE(SUM(commission), 0) FROM tblvendor_bookings WHERE status IN ('confirmed', 'completed')"
        );
        $sms = (float) $db->fetchValue(
            "SELECT COALESCE(SUM(cost), 0) FROM tblsms_logs WHERE status = 'sent'"
        );
        return [
            'subscriptions' => $subscriptions,
            'commissions'   => $commissions,
            'sms'           => $sms,
            'total'         => $subscriptions + $commissions + $sms,
        ];
    }

    /**
     * Details for one stream, newest first (drill-down tables).
     *
     * @return array<object>
     */
    public static function stream_rows(string $stream, int $limit = 100): array
    {
        global $db;
        $limit = max(1, min(500, $limit));
        switch ($stream) {
            case 'subscriptions':
                return $db->fetchAll(
                    "SELECT s.id, s.plan, s.amount, s.mpesa_code, s.start_date, s.expiry_date, s.status,
                            u.firstname, u.lastname, u.email
                       FROM tblsubscriptions s
                  LEFT JOIN tblusers u ON u.id = s.user_id
                      WHERE s.status IN ('active', 'expired')
                      ORDER BY s.created_at DESC
                      LIMIT {$limit}"
                );
            case 'commissions':
                return $db->fetchAll(
                    "SELECT vb.id, vb.amount AS deal_amount, vb.commission, vb.status, vb.event_date,
                            v.business_name, b.bride, b.groom
                       FROM tblvendor_bookings vb
                  LEFT JOIN tblvendors v ON v.id = vb.vendor_id
                  LEFT JOIN tblweddingbook b ON b.booking_id = vb.booking_id
                      WHERE vb.status IN ('confirmed', 'completed')
                      ORDER BY vb.created_at DESC
                      LIMIT {$limit}"
                );
            case 'sms':
                return $db->fetchAll(
                    "SELECT l.id, l.phone, l.message, l.cost, l.sent_at,
                            u.firstname, u.lastname
                       FROM tblsms_logs l
                  LEFT JOIN tblusers u ON u.id = l.user_id
                      WHERE l.status = 'sent'
                      ORDER BY l.created_at DESC
                      LIMIT {$limit}"
                );
        }
        return [];
    }

    /**
     * Fill every month in the window (0.00 where a stream was quiet) so the
     * dashboard's table/chart has a continuous axis. Months are keyed
     * 'YYYY-MM', oldest first (newest last).
     *
     * @param array<object> $subs       ['ym'=>..., 'total'=>...] rows
     * @param array<object> $comm       ['ym'=>..., 'total'=>...] rows
     * @param array<object> $sms        ['ym'=>..., 'total'=>...] rows
     */
    private static function merge_months(int $months, array $subs, array $comm, array $sms): array
    {
        $toMap = function (array $rows): array {
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row->ym] = (float) $row->total;
            }
            return $map;
        };
        $subsMap = $toMap($subs);
        $commMap = $toMap($comm);
        $smsMap  = $toMap($sms);

        $out = [];
        $cursor = new DateTimeImmutable('first day of this month 00:00:00');
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = $cursor->modify("-{$i} months")->format('Y-m');
            $s = $subsMap[$ym] ?? 0.0;
            $c = $commMap[$ym] ?? 0.0;
            $x = $smsMap[$ym] ?? 0.0;
            $out[$ym] = [
                'ym'            => $ym,
                'subscriptions' => $s,
                'commissions'   => $c,
                'sms'           => $x,
                'total'         => $s + $c + $x,
            ];
        }
        return $out;
    }

    /** @return array{months_ago:int, label:string, total:float} */
    public static function month_summary(int $monthsAgo): array
    {
        $rows = self::monthly_totals();
        $ym   = (new DateTimeImmutable('first day of this month 00:00:00'))
            ->modify("-{$monthsAgo} months")->format('Y-m');
        $row  = $rows[$ym] ?? ['total' => 0.0];
        return [
            'months_ago' => $monthsAgo,
            'label'      => date('F Y', (new DateTimeImmutable("first day of {$ym} 00:00:00"))->getTimestamp()),
            'total'      => (float) $row['total'],
        ];
    }
}

/**
 * Vendor class for marketplace — PDO edition
 */
class Vendor extends DB_Object
{
    protected static $db_table = "tblvendors";
    protected static $db_table_fields = array(
        'id', 'user_id', 'business_name', 'category', 'description',
        'phone', 'email', 'location', 'county', 'logo', 'is_premium',
        'is_approved', 'rating', 'total_bookings', 'created_at'
    );

    public $id;
    public $user_id;
    public $business_name;
    public $category;
    public $description;
    public $phone;
    public $email;
    public $location;
    public $county;
    public $logo;
    public $is_premium;
    public $is_approved;
    public $rating;
    public $total_bookings;
    public $created_at;

    public static function get_approved(?string $category = null): array
    {
        global $db;
        if ($category !== null && $category !== '') {
            $sql  = "SELECT * FROM tblvendors WHERE is_approved = 1 AND category = :cat ORDER BY is_premium DESC, rating DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([':cat' => $category]);
        } else {
            $sql  = "SELECT * FROM tblvendors WHERE is_approved = 1 ORDER BY is_premium DESC, rating DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function get_by_user(mixed $user_id): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblvendors WHERE user_id = :uid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /** Active packages of one vendor, cheapest first. (Prepared for the
     *  couple-facing vendor marketplace — not yet wired to a page.) */
    public static function packages_for(int $vendor_id): array
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT * FROM tblvendor_packages WHERE vendor_id = :vid AND is_active = 1 ORDER BY price ASC'
        );
        $stmt->execute([':vid' => $vendor_id]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** One vendor's active packages keyed by id — for validating an enquiry. */
    public static function package_of(int $vendor_id, int $package_id): ?object
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT * FROM tblvendor_packages WHERE id = :pid AND vendor_id = :vid AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':pid' => $package_id, ':vid' => $vendor_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /**
     * Record a couple's vendor enquiry. The vendor's booking row is created
     * pending so staff see the enquiry on the vendor ledger; returns the
     * tblvendor_bookings id or 0 on failure. $booking_id ties the enquiry to
     * the couple's wedding (0 = general enquiry with no wedding attached).
     */
    public static function record_enquiry(
        int $vendor_id,
        int $booking_id,
        int $package_id,
        string $event_date,
        string $message
    ): int {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
             VALUES (:vid, :bid, :pid, :edate, 0, 0, 'pending', :notes)"
        );
        $ok = $stmt->execute([
            ':vid'   => $vendor_id,
            ':bid'   => $booking_id,
            ':pid'   => $package_id > 0 ? $package_id : null,
            ':edate' => $event_date !== '' ? $event_date : null,
            ':notes' => mb_substr($message, 0, 1000),
        ]);
        return $ok ? (int) $db->the_insert_id() : 0;
    }
}

/**
 * SMS helper — PDO + Africa's Talking
 */
class SMS
{
    public static function send(string $phone, string $message, int $user_id = 0, int $booking_id = 0): array
    {
        global $db;

        $credits = self::get_credits($user_id);
        $cost    = self::calculate_cost($message);

        if ($credits < $cost) {
            return ['success' => false, 'error' => 'Insufficient SMS credits. Please purchase more.'];
        }

        $phone = self::format_phone($phone);

        $sql  = "INSERT INTO tblsms_logs (user_id, booking_id, phone, message, cost, status) VALUES (:uid, :bid, :phone, :msg, :cost, 'pending')";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':uid'   => $user_id,
            ':bid'   => $booking_id,
            ':phone' => $phone,
            ':msg'   => $message,
            ':cost'  => $cost,
        ]);
        $sms_id = (int) $db->the_insert_id();

        $response = self::africastalking_send($phone, $message);

        if ($response['success']) {
            $db->execute("UPDATE tblsms_logs SET status = 'sent', sent_at = NOW() WHERE id = :id", [':id' => $sms_id]);
            self::deduct_credits($user_id, $cost);
        } else {
            $db->execute("UPDATE tblsms_logs SET status = 'failed' WHERE id = :id", [':id' => $sms_id]);
        }

        return $response;
    }

    /**
     * Send a system SMS that is NOT billed to anyone's credit balance
     * (cost 0.00). Used for security alerts the platform owes its users —
     * e.g. login lockout notifications — which must not depend on some staff
     * member having bought credits. Still writes a tblsms_logs audit row;
     * in dev mode (no Africa's Talking key) the row is recorded as sent so
     * tests and demos see a complete trail.
     *
     * $ownerUserId attributes the row: tblsms_logs.user_id has a FK to
     * tblusers, so pass the RECIPIENT's tblusers id (0 when unknown — callers
     * resolving couples should translate to the owner staff id instead).
     */
    public static function send_system(string $phone, string $message, int $ownerUserId = 0): bool
    {
        global $db;

        $phone = trim($phone);
        if ($phone === '') {
            return false;
        }
        try {
            $phone = self::format_phone($phone);
            $db->execute(
                "INSERT INTO tblsms_logs (user_id, booking_id, phone, message, cost, status)
                 VALUES (:uid, 0, :phone, :msg, 0.00, 'pending')",
                [':uid' => $ownerUserId, ':phone' => $phone, ':msg' => $message]
            );
            $smsId = (int) $db->the_insert_id();

            $response = self::africastalking_send($phone, $message);
            $db->execute(
                "UPDATE tblsms_logs SET status = :st, sent_at = NOW() WHERE id = :id",
                [
                    ':st'  => !empty($response['success']) ? 'sent' : 'failed',
                    ':id'  => $smsId,
                ]
            );
            return !empty($response['success']);
        } catch (Throwable $e) {
            error_log('[WPMS][Sms] send_system failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Best-effort courtesy SMS for big moments (booking confirmed, vendor
     * confirmed): the couple should hear the news even when logged out, but a
     * missing number, empty planner credits or a gateway failure must never
     * block or fail the request — the in-app bell notification is the
     * reliable channel and is sent by the caller regardless.
     *
     * Charges the given staff user's credits (the couple never pays to
     * receive good news).
     *
     * @return bool true when the SMS actually reported sent
     */
    public static function send_courtesy(string $phone, string $message, int $chargedUserId, int $bookingId): bool
    {
        $phone = trim($phone);
        if ($phone === '' || $chargedUserId <= 0) {
            return false;
        }
        try {
            $r = self::send($phone, $message, $chargedUserId, $bookingId);
            return !empty($r['success']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function send_bulk(mixed $booking_id, string $message, int $user_id): array
    {
        global $db;
        $sql  = "SELECT phone FROM tblguest WHERE booking_id = :bid AND phone IS NOT NULL AND phone != ''";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $response = self::send($row->phone, $message, $user_id, (int) $booking_id);
            if ($response['success']) {
                $sent++;
            } else {
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function calculate_cost(string $message): float
    {
        $length = mb_strlen($message, 'UTF-8');
        if ($length <= 160) return 1.00;
        return ceil($length / 160) * 1.00;
    }

    /**
     * Normalize KE phone to 2547XXXXXXXX (Africa's Talking E.164 without +).
     * Handles: 07XX, 01XX (new KE prefix), +254, 254, 7XX, 1XX, spaces/dashes.
     */
    public static function format_phone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';
        // Already 254...
        if (str_starts_with($phone, '254')) {
            return $phone;
        }
        // 0 leading domestic
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }
        // Bare 7xx or 1xx without leading 0
        if (preg_match('/^[71]\d{8}$/', $phone)) {
            return '254' . $phone;
        }
        return $phone;
    }

    public static function get_credits(mixed $user_id): int
    {
        global $db;
        $sql  = "SELECT credits FROM tblsms_credits WHERE user_id = :uid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ? (int) $row->credits : 0;
    }

    public static function add_credits(mixed $user_id, int $credits): bool
    {
        global $db;
        $sql = "INSERT INTO tblsms_credits (user_id, credits, total_purchased) VALUES (:uid, :c, :c2)
                ON DUPLICATE KEY UPDATE credits = credits + VALUES(credits), total_purchased = total_purchased + VALUES(total_purchased)";
        $stmt = $db->prepare($sql);
        return $stmt->execute([':uid' => (int) $user_id, ':c' => $credits, ':c2' => $credits]);
    }

    public static function deduct_credits(mixed $user_id, mixed $credits): bool
    {
        global $db;
        $sql = "UPDATE tblsms_credits SET credits = GREATEST(0, credits - :c), total_used = total_used + :c2 WHERE user_id = :uid";
        $stmt = $db->prepare($sql);
        $c = (int) $credits;
        return $stmt->execute([':c' => $c, ':c2' => $c, ':uid' => (int) $user_id]);
    }

    public static function africastalking_send(string $phone, string $message): array
    {
        $username = 'wpms';
        $api_key  = '';

        if (empty($api_key)) {
            return ['success' => true, 'message' => 'Dev mode: SMS logged but not sent'];
        }

        $ch = curl_init('https://api.africastalking.com/version1/message');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . $api_key,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $username,
                'to'       => $phone,
                'message'  => $message
            ])
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 201) {
            return ['success' => true, 'response' => json_decode((string) $response, true)];
        }
        return ['success' => false, 'error' => $response];
    }
}

/**
 * Wedding Website — PDO edition
 */
class WeddingWebsite extends DB_Object
{
    protected static $db_table = "tblwedding_websites";
    protected static $db_table_fields = array(
        'id', 'booking_id', 'slug', 'title', 'story', 'venue',
        'venue_address', 'wedding_date', 'cover_image', 'is_published', 'views', 'created_at'
    );

    public $id;
    public $booking_id;
    public $slug;
    public $title;
    public $story;
    public $venue;
    public $venue_address;
    public $wedding_date;
    public $cover_image;
    public $is_published;
    public $views;
    public $created_at;

    public static function get_by_slug(string $slug): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblwedding_websites WHERE slug = :slug AND is_published = 1 LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function get_by_booking(mixed $booking_id): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblwedding_websites WHERE booking_id = :bid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function generate_slug(string $bride, string $groom): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $bride . '-' . $groom));
        $base = trim($base, '-');
        $slug = $base;
        $counter = 1;
        while (self::get_by_slug($slug) !== null) {
            $slug = $base . '-' . $counter;
            $counter++;
        }
        return $slug;
    }

    public static function increment_views(mixed $id): bool
    {
        global $db;
        $sql  = "UPDATE tblwedding_websites SET views = views + 1 WHERE id = :id";
        $stmt = $db->prepare($sql);
        return $stmt->execute([':id' => (int) $id]);
    }
}
