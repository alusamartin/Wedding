<?php
declare(strict_types=1);

/**
 * OpsPulse — the operator's statistics and the findings worth acting on.
 *
 * WHAT THIS IS FOR. Everything else in the MCP catalogue answers a question
 * about ONE wedding (or one vendor's own deals). An owner asking "how is the
 * business doing, and what should I fix first?" had no tool: the answer needed
 * eight per-wedding calls and still could not count the platform. OpsPulse is
 * that surface, and it is deliberately the narrowest one in the codebase.
 *
 * THE PRIVACY BOUNDARY IS THE DESIGN. Every number here is an aggregate or an
 * internal id — no bride or groom name, no guest name, no phone, no email, no
 * dietary note, no payment reference. That is not an accident of the queries:
 * it is the contract, asserted by tests, because this is the data most likely
 * to be handed to a language model. A caller that needs the couple behind a
 * booking id calls list_weddings/get_wedding, where the same ownership rules
 * and the same audit trail apply. Findings carry `booking_id` only; the page
 * that renders them resolves the name from the session, not from the model.
 *
 * DETERMINISTIC BEFORE GENERATIVE. `insights()` is a rule engine, not a model:
 * the same data produces the same findings, each with the numbers that made it
 * fire. An LLM may explain and prioritise them; it cannot invent one. That is
 * also what makes "suggest improvements" auditable — every finding can be
 * traced back to the figure in its `evidence`.
 *
 * TIME ARITHMETIC HAPPENS IN SQL, like the rest of this codebase, so the answer
 * does not shift with PHP's timezone. Money is Kenyan shillings throughout.
 *
 * ACCESS. These are platform-wide numbers, so the only door is
 * `admin:system` — a scope the token page refuses for anyone but a super admin
 * (ApiToken::ADMIN_SCOPES). A planner has their own dashboards and per-wedding
 * tools; they do not get the platform's revenue or every colleague's workload.
 */
final class OpsPulse
{
    /** Weddings this close to the day are "soon" for the risk rules. */
    public const SOON_DAYS = 45;

    /** The window for RSVP and balance findings. */
    public const WATCH_DAYS = 60;

    /** A pending vendor deal older than this is worth chasing. */
    public const DEAL_STALE_DAYS = 7;

    /** Upcoming weddings one planner can carry before the rule fires. */
    public const PLANNER_LOAD = 5;

    /** Subscription/trial renewals this close deserve a conversation. */
    public const RENEWAL_DAYS = 14;

    /** Severity order, worst first. */
    public const SEVERITIES = ['high', 'medium', 'low'];

    /**
     * One bit per rule, so an account's muted findings fit in one integer
     * preference rather than a row per code. Order is stable: never renumber a
     * code that has shipped, or a saved mask will mute the wrong finding.
     */
    public const RULES = [
        'wedding_unconfirmed_soon' => 1,
        'wedding_unassigned'       => 2,
        'rsvp_response_low'        => 4,
        'balance_short'            => 8,
        'tasks_overdue'            => 16,
        'vendor_deals_stale'       => 32,
        'capacity_over'            => 64,
        'capacity_tight'           => 128,
        'planner_overload'         => 256,
        'renewals_soon'            => 512,
        'jobs_troubled'            => 1024,
        'ai_approvals_waiting'     => 2048,
    ];

    /** Human labels for the mute list (a muted rule has no finding to carry one). */
    public const RULE_LABELS = [
        'wedding_unconfirmed_soon' => 'Wedding soon but unconfirmed',
        'wedding_unassigned'       => 'Upcoming wedding with no planner',
        'rsvp_response_low'        => 'RSVP response below half',
        'balance_short'            => 'Collected under 60% of the package',
        'tasks_overdue'            => 'Overdue checklist tasks',
        'vendor_deals_stale'       => 'Vendor deal pending too long',
        'capacity_over'           => 'Planned guests over a booked package limit',
        'capacity_tight'           => 'Within the capacity watch band',
        'planner_overload'        => 'Planner carrying too many weddings',
        'renewals_soon'            => 'Subscription or trial ending soon',
        'jobs_troubled'            => 'Scheduled job stopped or failing',
        'ai_approvals_waiting'     => 'AI request waiting for a decision',
    ];

    /**
     * The windows an operator can tune. Keys are the public names used by the
     * page and by `system_status`; values are the defaults.
     */
    public const DEFAULT_SETTINGS = [
        'soon_days'       => self::SOON_DAYS,
        'watch_days'      => self::WATCH_DAYS,
        'deal_stale_days' => self::DEAL_STALE_DAYS,
        'planner_load'    => self::PLANNER_LOAD,
        'renewal_days'    => self::RENEWAL_DAYS,
        'capacity_share'  => 10, // per cent
    ];

    /** Accepted range for each threshold. Anything else is refused, not clamped. */
    public const SETTING_RANGES = [
        'soon_days'       => [7, 180],
        'watch_days'      => [14, 365],
        'deal_stale_days' => [3, 60],
        'planner_load'    => [2, 50],
        'renewal_days'    => [3, 90],
    ];

    // ==================================================================
    // Settings — per account, validated here, stored by UserPrefs
    // ==================================================================

    /**
     * The thresholds and mute mask for one account, with every stored value
     * validated. A hand-edited row, an older build or a crafted POST reads as
     * the default rather than widening a query nobody asked for.
     *
     * @return array{soon_days:int, watch_days:int, deal_stale_days:int,
     *               planner_load:int, renewal_days:int, capacity_share:int,
     *               muted_mask:int, muted_codes:string[]}
     */
    public static function settings(int $user_id = 0): array
    {
        $settings = self::DEFAULT_SETTINGS;
        $mask     = 0;

        if ($user_id > 0) {
            $stored = UserPrefs::get($user_id, UserPrefs::OPS_THRESHOLDS, '');
            if ($stored !== '') {
                $decoded = json_decode($stored, true);
                if (is_array($decoded)) {
                    foreach (self::DEFAULT_SETTINGS as $key => $default) {
                        if (!array_key_exists($key, $decoded)) {
                            continue;
                        }
                        $value = is_numeric($decoded[$key]) ? (int) $decoded[$key] : -1;
                        if ($key === 'capacity_share') {
                            if (array_key_exists($value, PackageCapacity::shares())) {
                                $settings[$key] = $value;
                            }
                        } elseif ($value >= self::SETTING_RANGES[$key][0] && $value <= self::SETTING_RANGES[$key][1]) {
                            $settings[$key] = $value;
                        }
                    }
                }
            }

            $storedMask = UserPrefs::get($user_id, UserPrefs::OPS_MUTED, '0');
            $mask       = ctype_digit($storedMask) ? ((int) $storedMask & self::ALL_MUTES) : 0;
        }

        return $settings + ['muted_mask' => $mask, 'muted_codes' => self::muted_codes($mask)];
    }

    /**
     * Persist the six thresholds for one account. One invalid field refuses the
     * WHOLE save: a half-applied set of windows is harder to reason about than
     * the form the operator was looking at.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool, errors:array<string,string>}
     */
    public static function save_settings(int $user_id, array $input): array
    {
        $errors   = [];
        $settings = [];

        foreach (self::DEFAULT_SETTINGS as $key => $default) {
            $raw = $input[$key] ?? null;
            if (!is_numeric($raw)) {
                $errors[$key] = 'Enter a number.';
                continue;
            }
            $value = (int) $raw;
            if ($key === 'capacity_share') {
                if (!array_key_exists($value, PackageCapacity::shares())) {
                    $errors[$key] = 'Choose one of the offered widths.';
                    continue;
                }
            } elseif ($value < self::SETTING_RANGES[$key][0] || $value > self::SETTING_RANGES[$key][1]) {
                $errors[$key] = 'Must be between ' . self::SETTING_RANGES[$key][0]
                    . ' and ' . self::SETTING_RANGES[$key][1] . '.';
                continue;
            }
            $settings[$key] = $value;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $ok = UserPrefs::remember($user_id, UserPrefs::OPS_THRESHOLDS, (string) json_encode($settings));
        return ['ok' => $ok, 'errors' => $ok ? [] : ['_form' => 'The preference could not be saved on this install.']];
    }

    /** Mute one rule for this account. Unknown codes are refused. */
    public static function mute(int $user_id, string $code): bool
    {
        if ($user_id <= 0 || !isset(self::RULES[$code])) {
            return false;
        }
        $mask = self::settings($user_id)['muted_mask'] | self::RULES[$code];
        return UserPrefs::remember($user_id, UserPrefs::OPS_MUTED, (string) $mask);
    }

    /** Unmute one rule for this account. */
    public static function unmute(int $user_id, string $code): bool
    {
        if ($user_id <= 0 || !isset(self::RULES[$code])) {
            return false;
        }
        $mask = self::settings($user_id)['muted_mask'] & ~self::RULES[$code];
        return UserPrefs::remember($user_id, UserPrefs::OPS_MUTED, (string) $mask);
    }

    /** @return string[] the codes in a mask, in catalogue order */
    public static function muted_codes(int $mask): array
    {
        $codes = [];
        foreach (self::RULES as $code => $bit) {
            if (($mask & $bit) === $bit) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    public static function rule_label(string $code): string
    {
        return self::RULE_LABELS[$code] ?? $code;
    }

    /** Every known bit at once — the ceiling for a stored mask. */
    private const ALL_MUTES = 4095;

    // ==================================================================
    // The snapshot — one call, the whole platform
    // ==================================================================

    /**
     * Platform totals, in the shape an operator reads a dashboard in.
     *
     * Every leaf is an int or a float. `definitions` says what each window
     * means so a model cannot quietly redefine "upcoming" in its answer.
     *
     * @param int $user_id whose saved thresholds to use (0 = defaults)
     * @return array<string,mixed>
     */
    public static function snapshot(int $user_id = 0): array
    {
        $db       = Database::read_handle();
        $settings = self::settings($user_id);

        $weddings = self::one(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(wedding_date >= CURDATE()), 0) AS upcoming,
                COALESCE(SUM(wedding_date < CURDATE()), 0) AS past,
                COALESCE(SUM(wedding_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . $settings['soon_days'] . ' DAY)), 0) AS next_soon,
                COALESCE(SUM(wedding_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)), 0) AS next_90
             FROM tblweddingbook'
        );

        $status = self::one(
            "SELECT
                COALESCE(SUM(EXISTS (
                    SELECT 1 FROM tblaccounts_detail ad
                     WHERE ad.user_id = wb.user_id AND ad.status = 'pending'
                )), 0) AS awaiting_confirmation,
                COALESCE(SUM(wb.organizer_id IS NULL OR wb.organizer_id = 0), 0) AS unassigned
             FROM tblweddingbook wb"
        );

        $upcomingValue = self::one(
            'SELECT COALESCE(SUM(c.price), 0) AS package_value
               FROM tblweddingbook wb
               LEFT JOIN tblweddingcategories c ON c.id = wb.wedding_type
              WHERE wb.wedding_date >= CURDATE()'
        );

        $upcomingPaid = self::one(
            'SELECT COALESCE(SUM(l.payment), 0) AS collected
               FROM tbl_liquidation l
               JOIN tblweddingbook wb ON wb.booking_id = l.booking_id
              WHERE wb.wedding_date >= CURDATE()'
        );

        $collected30 = self::one(
            'SELECT COALESCE(SUM(payment), 0) AS collected
               FROM tbl_liquidation
              WHERE date_issue >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        );

        $subscriptions = self::one(
            "SELECT
                COALESCE(SUM(status = 'active' AND expiry_date >= CURDATE()), 0) AS active,
                COALESCE(SUM(CASE WHEN status = 'active' AND expiry_date >= CURDATE() THEN amount ELSE 0 END), 0) AS mrr,
                COALESCE(SUM(status = 'active' AND expiry_date >= CURDATE()
                              AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . $settings['renewal_days'] . ' DAY)), 0) AS expiring_soon,
                COALESCE(SUM(status = \'trial\'
                              AND expiry_date >= CURDATE()
                              AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ' . $settings['renewal_days'] . " DAY)), 0) AS trials_ending,
                COALESCE(SUM(status = 'expired' AND expiry_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS expired_30
             FROM tblsubscriptions"
        );

        $guests = self::one(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(rsvp_invited_at IS NOT NULL), 0) AS invited,
                COALESCE(SUM(rsvp_status IN ('yes', 'no', 'maybe')), 0) AS answered,
                COALESCE(SUM(rsvp_status = 'pending'), 0) AS pending
             FROM tblguest"
        );

        $tasks = self::one(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(c.is_done = 1), 0) AS done,
                COALESCE(SUM(c.is_done = 0), 0) AS open,
                COALESCE(SUM(c.is_done = 0
                              AND DATE_SUB(wb.wedding_date, INTERVAL c.due_offset_days DAY) < CURDATE()), 0) AS overdue
             FROM tblchecklist c
             JOIN tblweddingbook wb ON wb.booking_id = c.booking_id"
        );

        $vendors = self::one(
            'SELECT
                COALESCE(SUM(is_approved = 1), 0) AS approved,
                COALESCE(SUM(is_approved = 0), 0) AS awaiting_approval
             FROM tblvendors'
        );

        $deals = self::one(
            "SELECT
                COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
                COALESCE(SUM(status = 'pending'), 0) AS pending,
                COALESCE(SUM(CASE WHEN status IN ('confirmed', 'completed') THEN amount ELSE 0 END), 0) AS committed_value,
                COALESCE(SUM(CASE WHEN status IN ('confirmed', 'completed') THEN commission ELSE 0 END), 0) AS commission
             FROM tblvendor_bookings"
        );

        $ai = self::one(
            'SELECT
                COUNT(*) AS calls,
                COALESCE(SUM(ok = 0), 0) AS failed,
                COALESCE(SUM(tier >= 2), 0) AS write_calls
             FROM tblapi_audit
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        $approvals = self::one(
            'SELECT
                COALESCE(SUM(approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL
                              AND expired_at IS NULL AND expires_at > NOW()), 0) AS pending,
                COALESCE(SUM(expired_at IS NOT NULL
                              AND expired_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) AS expired_7d
             FROM tblapi_approvals'
        );

        $jobs = JobWatch::troubled_count();

        $totalGuests  = (int) ($guests['total'] ?? 0);
        $answered     = (int) ($guests['answered'] ?? 0);
        $packageValue = (float) ($upcomingValue['package_value'] ?? 0);
        $collected    = (float) ($upcomingPaid['collected'] ?? 0);

        return [
            'generated_at' => date('Y-m-d'),
            'currency'     => 'KSh',
            'weddings'     => [
                'total'                => (int) ($weddings['total'] ?? 0),
                'upcoming'             => (int) ($weddings['upcoming'] ?? 0),
                'past'                 => (int) ($weddings['past'] ?? 0),
                'next_' . $settings['soon_days'] . '_days' => (int) ($weddings['next_soon'] ?? 0),
                'next_90_days'         => (int) ($weddings['next_90'] ?? 0),
                'awaiting_confirmation' => (int) ($status['awaiting_confirmation'] ?? 0),
                'unassigned_planner'   => (int) ($status['unassigned'] ?? 0),
            ],
            'money' => [
                'package_value_upcoming' => round($packageValue, 2),
                'collected_upcoming'     => round($collected, 2),
                'outstanding_upcoming'   => round(max(0, $packageValue - $collected), 2),
                'collected_last_30_days' => round((float) ($collected30['collected'] ?? 0), 2),
                'subscription_mrr'       => round((float) ($subscriptions['mrr'] ?? 0), 2),
                'active_subscriptions'   => (int) ($subscriptions['active'] ?? 0),
                'subscriptions_expiring_soon' => (int) ($subscriptions['expiring_soon'] ?? 0),
                'trials_ending_soon'     => (int) ($subscriptions['trials_ending'] ?? 0),
                'subscriptions_expired_last_30' => (int) ($subscriptions['expired_30'] ?? 0),
                'vendor_committed_value' => round((float) ($deals['committed_value'] ?? 0), 2),
                'vendor_commission'      => round((float) ($deals['commission'] ?? 0), 2),
            ],
            'guests' => [
                'total'            => $totalGuests,
                'invited'          => (int) ($guests['invited'] ?? 0),
                'rsvp_answered'    => $answered,
                'rsvp_pending'     => (int) ($guests['pending'] ?? 0),
                'rsvp_response_rate' => $totalGuests > 0 ? round($answered / $totalGuests, 3) : 0.0,
            ],
            'tasks' => [
                'total'   => (int) ($tasks['total'] ?? 0),
                'open'    => (int) ($tasks['open'] ?? 0),
                'done'    => (int) ($tasks['done'] ?? 0),
                'overdue' => (int) ($tasks['overdue'] ?? 0),
            ],
            'vendors' => [
                'approved'          => (int) ($vendors['approved'] ?? 0),
                'awaiting_approval' => (int) ($vendors['awaiting_approval'] ?? 0),
                'deals_confirmed'   => (int) ($deals['confirmed'] ?? 0),
                'deals_pending'     => (int) ($deals['pending'] ?? 0),
            ],
            'operations' => [
                'ai_calls_7d'          => (int) ($ai['calls'] ?? 0),
                'ai_failures_7d'       => (int) ($ai['failed'] ?? 0),
                'ai_write_calls_7d'    => (int) ($ai['write_calls'] ?? 0),
                'approvals_pending'    => (int) ($approvals['pending'] ?? 0),
                'approvals_expired_7d' => (int) ($approvals['expired_7d'] ?? 0),
                'jobs_troubled'        => $jobs,
            ],
            'settings' => [
                'thresholds' => [
                    'soon_days'       => $settings['soon_days'],
                    'watch_days'      => $settings['watch_days'],
                    'deal_stale_days' => $settings['deal_stale_days'],
                    'planner_load'    => $settings['planner_load'],
                    'renewal_days'    => $settings['renewal_days'],
                    'capacity_share'  => $settings['capacity_share'],
                ],
                'muted_codes' => $settings['muted_codes'],
            ],
            'definitions' => [
                'upcoming' => 'wedding_date is today or later',
                'next_' . $settings['soon_days'] . '_days' => 'wedding_date falls inside the next ' . $settings['soon_days'] . ' days',
                'awaiting_confirmation' => 'the couple account is still pending, so their planning tools are locked',
                'unassigned_planner' => 'organizer_id is 0 or empty',
                'package_value_upcoming' => 'sum of package prices on upcoming weddings (tblweddingcategories)',
                'collected_upcoming' => 'sum of payments recorded against upcoming weddings',
                'subscription_mrr' => 'sum of active plan amounts, monthly',
                'rsvp_response_rate' => 'answered / all guests currently on record',
                'tasks.overdue' => 'open checklist item whose due date (wedding_date minus due_offset_days) has passed',
                'vendor_committed_value' => 'sum of confirmed/completed vendor deal amounts',
                'jobs_troubled' => 'scheduled jobs that have run before and have since gone quiet or started failing',
            ],
            'privacy' => 'Aggregates and internal ids only. No names, phone numbers, emails, dietary notes or payment '
                . 'references are returned. Use list_weddings then get_wedding for a specific wedding.',
        ];
    }

    // ==================================================================
    // The findings — a rule engine, not a model
    // ==================================================================

    /**
     * Everything worth acting on right now, worst first, minus what this
     * account has muted.
     *
     * @param string $severity '' = all, otherwise high|medium|low
     * @param int    $user_id  whose thresholds and mutes to apply (0 = defaults)
     * @return array<string,mixed>
     */
    public static function insights(string $severity = '', int $limit = 25, int $user_id = 0): array
    {
        $severity = strtolower(trim($severity));
        if ($severity !== '' && !in_array($severity, self::SEVERITIES, true)) {
            $severity = '';
        }
        $limit = max(1, min(100, $limit));
        $settings = self::settings($user_id);
        $all      = self::evaluate($settings);

        // Muted means "I have accepted this one"; it is filtered from what the
        // operator and the model see, but reported as a count so a mute cannot
        // silently hide a whole class of problem from the owner. The same
        // findings stay listed on the operator page (OpsPulse::muted_findings)
        // so a mute is always one click from being undone.
        $muted    = $settings['muted_codes'];
        $findings = array_values(array_filter(
            $all,
            static fn(array $finding): bool => !in_array($finding['code'], $muted, true)
        ));

        $bySeverity = array_fill_keys(self::SEVERITIES, 0);
        foreach ($findings as $finding) {
            $bySeverity[$finding['severity']]++;
        }

        $selected = $severity === ''
            ? $findings
            : array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === $severity));

        return [
            'generated_at'  => date('Y-m-d'),
            'finding_count' => count($findings),
            'muted_count'   => count($all) - count($findings),
            'muted_codes'   => $muted,
            'by_severity'   => $bySeverity,
            'returned'      => min(count($selected), $limit),
            'findings'      => array_slice($selected, 0, $limit),
            'thresholds'    => [
                'soon_days'       => $settings['soon_days'],
                'watch_days'      => $settings['watch_days'],
                'deal_stale_days' => $settings['deal_stale_days'],
                'planner_load'    => $settings['planner_load'],
                'renewal_days'    => $settings['renewal_days'],
                'capacity_share'  => $settings['capacity_share'],
            ],
            'note'          => 'Each finding is produced by a fixed rule over the platform data and carries the numbers that '
                . 'fired it (evidence). It names internal ids only, never customers. Muted findings are excluded and counted '
                . 'separately; open the linked page to act.',
        ];
    }

    /**
     * The findings an account has muted — the ones `insights()` deliberately
     * leaves out.
     *
     * The operator page needs both halves: what is firing, and what they have
     * already accepted, because a mute that cannot be seen is a mute nobody can
     * undo. This runs the rules again with the same thresholds and returns only
     * the muted codes, so the page can list each one with its count and an
     * Unmute button. The MCP tools still call `insights()` and never see these.
     *
     * @return array<int,array<string,mixed>> worst first
     */
    public static function muted_findings(int $user_id = 0): array
    {
        $settings = self::settings($user_id);
        if ($settings['muted_codes'] === []) {
            return [];
        }
        $muted = $settings['muted_codes'];
        return array_values(array_filter(
            self::evaluate($settings),
            static fn(array $finding): bool => in_array($finding['code'], $muted, true)
        ));
    }

    /**
     * Every rule, run against one set of thresholds, worst first.
     *
     * One list for the whole class: `insights()` filters it by mute and
     * severity; `muted_findings()` picks the muted half back out. A rule added
     * here is automatically in both, so the page cannot show a mute for a rule
     * the engine no longer evaluates.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function evaluate(array $settings): array
    {
        $findings = array_merge(
            self::rule_unconfirmed_soon($settings),
            self::rule_unassigned(),
            self::rule_rsvp_low($settings),
            self::rule_balance_short($settings),
            self::rule_tasks_overdue(),
            self::rule_deals_stale($settings),
            self::rule_capacity($settings),
            self::rule_planner_load($settings),
            self::rule_renewals($settings),
            self::rule_jobs(),
            self::rule_ai_approvals()
        );

        usort($findings, static function (array $a, array $b): int {
            $rank = array_flip(self::SEVERITIES);
            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                ?: ((int) $b['count'] <=> (int) $a['count']);
        });

        return $findings;
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_unconfirmed_soon(array $settings): array
    {
        $rows = self::rows(
            "SELECT wb.booking_id, wb.wedding_date, DATEDIFF(wb.wedding_date, CURDATE()) AS days_to_go
               FROM tblweddingbook wb
              WHERE wb.wedding_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . $settings['soon_days'] . " DAY)
                AND EXISTS (SELECT 1 FROM tblaccounts_detail ad
                             WHERE ad.user_id = wb.user_id AND ad.status = 'pending')
              ORDER BY wb.wedding_date"
        );
        if ($rows === []) {
            return [];
        }
        return [self::finding(
            'wedding_unconfirmed_soon',
            'high',
            count($rows) . ' wedding(s) inside ' . $settings['soon_days'] . ' days are still unconfirmed',
            'A pending couple account means the guest list, budget, checklist and website stay locked, and every '
                . 'reminder counts back from a date nobody has confirmed.',
            count($rows),
            array_map(static fn(array $r): array => [
                'booking_id'  => (int) $r['booking_id'],
                'days_to_go'  => (int) $r['days_to_go'],
            ], $rows),
            'Confirm the booking (or move the date) on the Clients page, then tell the couple their tools are open.',
            'client.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_unassigned(): array
    {
        $count = (int) self::scalar(
            'SELECT COUNT(*) FROM tblweddingbook
              WHERE wedding_date >= CURDATE() AND (organizer_id IS NULL OR organizer_id = 0)'
        );
        if ($count === 0) {
            return [];
        }
        return [self::finding(
            'wedding_unassigned',
            'high',
            $count . ' upcoming wedding(s) have no planner assigned',
            'Nobody owns the day, so no one is watching the checklist, the payments or the supplier confirmations.',
            $count,
            [],
            'Assign a planner from the Clients page.',
            'client.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_rsvp_low(array $settings): array
    {
        $rows = self::rows(
            'SELECT g.booking_id,
                    COUNT(*) AS guests,
                    COALESCE(SUM(g.rsvp_status IN (\'yes\', \'no\', \'maybe\')), 0) AS answered,
                    DATEDIFF(wb.wedding_date, CURDATE()) AS days_to_go
               FROM tblguest g
               JOIN tblweddingbook wb ON wb.booking_id = g.booking_id
              WHERE wb.wedding_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . $settings['watch_days'] . ' DAY)
              GROUP BY g.booking_id, wb.wedding_date
             HAVING COUNT(*) >= 10 AND (SUM(g.rsvp_status IN (\'yes\', \'no\', \'maybe\')) / COUNT(*)) < 0.5
              ORDER BY days_to_go ASC
              LIMIT 5'
        );
        if ($rows === []) {
            return [];
        }
        return [self::finding(
            'rsvp_response_low',
            'medium',
            count($rows) . ' wedding(s) inside ' . $settings['watch_days'] . ' days have under half their RSVPs back',
            'Catering numbers, seating and transport are priced from answers that have not arrived.',
            count($rows),
            array_map(static fn(array $r): array => [
                'booking_id' => (int) $r['booking_id'],
                'guests'     => (int) $r['guests'],
                'answered'   => (int) $r['answered'],
                'days_to_go' => (int) $r['days_to_go'],
            ], $rows),
            'Use the RSVP chase list (or SMS reminders) for these weddings; reminders are rate-limited per guest.',
            'rsvp_dashboard.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_balance_short(array $settings): array
    {
        $rows = self::rows(
            'SELECT wb.booking_id,
                    DATEDIFF(wb.wedding_date, CURDATE()) AS days_to_go,
                    COALESCE(c.price, 0) AS package_price,
                    COALESCE(SUM(l.payment), 0) AS paid
               FROM tblweddingbook wb
               LEFT JOIN tblweddingcategories c ON c.id = wb.wedding_type
               LEFT JOIN tbl_liquidation l ON l.booking_id = wb.booking_id
              WHERE wb.wedding_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . $settings['watch_days'] . ' DAY)
              GROUP BY wb.booking_id, wb.wedding_date, c.price
             HAVING package_price > 0 AND paid < package_price * 0.6
              ORDER BY days_to_go ASC
              LIMIT 5'
        );
        if ($rows === []) {
            return [];
        }
        return [self::finding(
            'balance_short',
            'high',
            count($rows) . ' wedding(s) inside ' . $settings['watch_days'] . ' days have collected under 60% of the package price',
            'Suppliers are paid from deposits; a short balance this close to the day is a cash-flow problem, not a reminder.',
            count($rows),
            array_map(static fn(array $r): array => [
                'booking_id'     => (int) $r['booking_id'],
                'days_to_go'     => (int) $r['days_to_go'],
                'package_price'  => round((float) $r['package_price'], 2),
                'paid'           => round((float) $r['paid'], 2),
                'outstanding'    => round(max(0, (float) $r['package_price'] - (float) $r['paid']), 2),
            ], $rows),
            'Review the budget and the installment plan with the couple, and record any payments already received.',
            'client.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_tasks_overdue(): array
    {
        $total = (int) self::scalar(
            'SELECT COUNT(*)
               FROM tblchecklist c
               JOIN tblweddingbook wb ON wb.booking_id = c.booking_id
              WHERE c.is_done = 0
                AND DATE_SUB(wb.wedding_date, INTERVAL c.due_offset_days DAY) < CURDATE()'
        );
        if ($total === 0) {
            return [];
        }
        $rows = self::rows(
            'SELECT c.booking_id, COUNT(*) AS overdue
               FROM tblchecklist c
               JOIN tblweddingbook wb ON wb.booking_id = c.booking_id
              WHERE c.is_done = 0
                AND DATE_SUB(wb.wedding_date, INTERVAL c.due_offset_days DAY) < CURDATE()
              GROUP BY c.booking_id
              ORDER BY overdue DESC
              LIMIT 5'
        );
        return [self::finding(
            'tasks_overdue',
            'medium',
            $total . ' checklist task(s) are past due across ' . count($rows) . ' wedding(s)',
            'Overdue items late in the plan are the ones that need a supplier or a payment, not just a phone call.',
            $total,
            array_map(static fn(array $r): array => [
                'booking_id' => (int) $r['booking_id'],
                'overdue'    => (int) $r['overdue'],
            ], $rows),
            'Open the Task Calendar, filter to overdue, and reschedule or finish the blocking items first.',
            'task_all_calendar.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_deals_stale(array $settings): array
    {
        $count = (int) self::scalar(
            'SELECT COUNT(*) FROM tblvendor_bookings
              WHERE status = \'pending\' AND created_at < DATE_SUB(NOW(), INTERVAL ' . $settings['deal_stale_days'] . ' DAY)'
        );
        if ($count === 0) {
            return [];
        }
        return [self::finding(
            'vendor_deals_stale',
            'medium',
            $count . ' vendor deal(s) have been pending for over ' . $settings['deal_stale_days'] . ' days',
            'An unanswered enquiry is a supplier the couple believes they have; the date can be lost to a faster booking.',
            $count,
            [],
            'Open Vendor Enquiries, chase the supplier, and confirm or close the deal so the timeline tells the truth.',
            'vendor_enquiries.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_capacity(array $settings): array
    {
        $over = PackageCapacity::watch_over_count();
        if ($over > 0) {
            return [self::finding(
                'capacity_over',
                'high',
                $over . ' wedding(s) plan more guests than a booked package can hold',
                'The caterer, the chairs and the venue contract were bought against a smaller number.',
                $over,
                [],
                'Open Capacity Headroom, then either move up a package or trim the per-function headcount.',
                'headroom.php'
            )];
        }
        $tight = PackageCapacity::watch_count(((int) $settings['capacity_share']) / 100);
        if ($tight > 0) {
            return [self::finding(
                'capacity_tight',
                'medium',
                $tight . ' wedding(s) are within ' . $settings['capacity_share'] . '% of a booked package limit',
                'There is very little room left before a guest-count change becomes an extra supplier cost.',
                $tight,
                [],
                'Watch these on Capacity Headroom before the final guest count is locked.',
                'headroom.php'
            )];
        }
        return [];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_planner_load(array $settings): array
    {
        $rows = self::rows(
            'SELECT organizer_id, COUNT(*) AS weddings
               FROM tblweddingbook
              WHERE wedding_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                AND organizer_id > 0
              GROUP BY organizer_id
             HAVING COUNT(*) > ' . $settings['planner_load'] . '
              ORDER BY weddings DESC
              LIMIT 5'
        );
        if ($rows === []) {
            return [];
        }
        return [self::finding(
            'planner_overload',
            'medium',
            count($rows) . ' planner(s) carry more than ' . $settings['planner_load'] . ' weddings in the next 90 days',
            'Load is where quality slips silently: the same person approves payments, chases suppliers and runs the day.',
            count($rows),
            array_map(static fn(array $r): array => [
                'organizer_id' => (int) $r['organizer_id'],
                'weddings'     => (int) $r['weddings'],
            ], $rows),
            'Rebalance upcoming weddings on the Clients page, or bring in cover before the peak weekend.',
            'client.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_renewals(array $settings): array
    {
        $count = (int) self::scalar(
            "SELECT COUNT(*) FROM tblsubscriptions
              WHERE status IN ('active', 'trial')
                AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . $settings['renewal_days'] . ' DAY)'
        );
        if ($count === 0) {
            return [];
        }
        return [self::finding(
            'renewals_soon',
            'medium',
            $count . ' subscription(s) or trials end within ' . $settings['renewal_days'] . ' days',
            'A planner whose plan lapses loses access to their weddings on the day it expires, and the renewal SMS may not be enough.',
            $count,
            [],
            'Check Subscription Plans and reach out to the ones that are not on auto-renew.',
            'subscription.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_jobs(): array
    {
        $report = JobWatch::report();
        $findings = $report['findings'] ?? [];
        if ($findings === []) {
            return [];
        }
        $subjects = [];
        foreach ($findings as $finding) {
            $subjects[] = [
                'job'    => (string) ($finding['job'] ?? ''),
                'label'  => (string) ($finding['label'] ?? ''),
                'reason' => (string) ($finding['reason'] ?? ''),
            ];
        }
        return [self::finding(
            'jobs_troubled',
            'high',
            count($findings) . ' scheduled job(s) have stopped or are failing',
            'Reminders, renewal sweeps and approval expiry all run on these jobs; a silent one is a task nobody is doing.',
            count($findings),
            $subjects,
            'Open Job Health to see when each job last ran and what it reported.',
            'job_watch.php'
        )];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rule_ai_approvals(): array
    {
        $stale = (int) self::scalar(
            'SELECT COUNT(*) FROM tblapi_approvals
              WHERE approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL
                AND expired_at IS NULL AND expires_at > NOW()
                AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        if ($stale === 0) {
            return [];
        }
        return [self::finding(
            'ai_approvals_waiting',
            'medium',
            $stale . ' AI request(s) have waited more than 24 hours for a decision',
            'Until someone approves or refuses, the assistant cannot finish what it was asked to do — and the approval window is finite.',
            $stale,
            [],
            'Open AI Approvals and decide each request; a refusal with a reason is a valid answer.',
            'mcp_approvals.php'
        )];
    }

    // ==================================================================
    // Plumbing
    // ==================================================================

    /** @return array<int,array<string,mixed>> */
    private static function finding(
        string $code,
        string $severity,
        string $title,
        string $detail,
        int $count,
        array $subjects,
        string $action,
        string $link
    ): array {
        return [
            'code'     => $code,
            'severity' => $severity,
            'title'    => $title,
            'detail'   => $detail,
            'count'    => $count,
            // Internal ids and numbers only. A caller that needs the couple's
            // name resolves it through the per-wedding tools, where ownership
            // is checked and the read is audited.
            'evidence' => ['subjects' => $subjects],
            'suggested_action' => $action,
            'link'     => $link,
        ];
    }

    /**
     * One scalar from a statement with no caller-supplied values.
     *
     * Every SQL string in this class is a constant assembled from integer class
     * constants; there is no user input in any of them, so there is nothing to
     * bind. The public methods that take arguments (severity, limit) filter
     * them in PHP before they reach a query.
     */
    private static function scalar(string $sql): int|float
    {
        $value = Database::read_handle()->fetchValue($sql);
        return is_numeric($value) ? (float) $value : 0;
    }

    /**
     * One row as an array. Database::fetchOne() hands back an object (the
     * project's fetch mode); this class reads named keys, so the cast happens
     * once, here, rather than at every use.
     *
     * @return array<string,mixed>
     */
    private static function one(string $sql): array
    {
        $row = Database::read_handle()->fetchOne($sql);
        return $row === null ? [] : (array) $row;
    }

    /** @return array<int,array<string,mixed>> */
    private static function rows(string $sql): array
    {
        $rows = Database::read_handle()->fetchAll($sql);
        return is_array($rows) ? array_map(static fn(object $row): array => (array) $row, $rows) : [];
    }
}
