<?php
declare(strict_types=1);

/**
 * BusinessPulse — the owner's weekly brief, written from the same aggregate
 * tools the AI assistant reads.
 *
 * WHAT THIS IS, AND WHY IT IS NOT A MODEL. Every Monday the operator gets one
 * email: the platform's numbers, the findings that need attention, and the five
 * things worth fixing first. The content is rendered DETERMINISTICALLY from
 * OpsPulse::snapshot() and OpsPulse::insights() — the exact calls behind the
 * `system_status` and `list_insights` MCP tools and the Business Pulse page — so
 * the email, the screen and anything an AI says all quote the same figures. No
 * language-model provider is involved: nothing here sends customer data
 * anywhere, which is what makes it safe to schedule before a DPIA is signed.
 * The AI-narrated version still exists (the `business_pulse` MCP prompt); this
 * is the one that arrives without anybody opening a chat window.
 *
 * THE PRIVACY CONTRACT IS INHERITED, NOT REIMPLEMENTED. This class reads no
 * table of its own. It formats what OpsPulse returns — aggregates and internal
 * ids — so the guarantee that no bride, groom, guest, phone number, email,
 * dietary note or M-Pesa code can appear in the email is the same guarantee the
 * tools carry, asserted by OpsPulseTest. A booking id in the brief is a pointer;
 * the page behind it resolves the people from the signed-in session.
 *
 * ONE EMAIL PER OWNER PER WEEK. The cron entry may run daily (the bridge's
 * throttle is not a calendar), so the week is stamped in the owner's own
 * preferences (UserPrefs::PULSE_WEEK) only after the transport ACCEPTED the
 * message — 'sent', or 'pending' because WPMS_MAIL_ENABLED is off and
 * mail_retry will drain the outbox. A hard failure leaves the stamp alone, so
 * tomorrow's run can try again rather than skipping the week. With no
 * preference table (v2.12 not applied) the job still sends on Monday; it just
 * cannot remember, and says so in its summary.
 *
 * WHO GETS IT. Super admins (tblusers.access_level 2) — the people the rest of
 * the platform is the operator's view of. WPMS_PULSE_TO overrides the list for
 * an install that wants the brief on a shared mailbox; each entry must be a
 * valid address or it is dropped, never silently mis-sent. A recipient's own
 * tuned thresholds and mutes (ops_pulse.php) are applied, so two owners can
 * legitimately get different briefs from the same data.
 *
 * WHAT IT DOES NOT DO. It does not write to any business table, send SMS, or
 * change a booking. It reads, formats, queues one email, and reports through
 * JobWatch so the operator learns when the brief stops arriving.
 *
 * WEEK OVER WEEK. A number on its own does not say whether the week was good.
 * Each brief therefore prints the change against the most recent snapshot taken
 * STRICTLY BEFORE its own week (tblops_snapshots, migrations/v2.23), so
 * "collected KSh 1.24M (+95,000)" means the same thing on a Tuesday catch-up run
 * as on a Monday. A row is written only after the transport accepted the mail —
 * a preview or a failed send must not become next week's baseline — and a
 * re-send inside one week replaces that week's row rather than competing with
 * it. Snapshots are per scope: an owner's own thresholds change one of the
 * snapshot keys, so two owners never compare against each other's figures.
 *
 * Storage: tbl_mail_logs for the send (Notification::send_address_mail),
 * tbluser_prefs for the week stamp, and tblops_snapshots for the comparison
 * history. The history table is OPTIONAL: without it the brief still sends and
 * says the comparison is off, exactly as the preferences table does.
 */
final class BusinessPulse
{
    /** The operator's clock: the same Africa/Nairobi the database session uses. */
    public const TZ = 'Africa/Nairobi';

    /** How many findings the email carries before it stops being read on a phone. */
    public const MAX_FINDINGS = 8;

    /** How many "fix first" suggestions. Matches the AI prompt's five. */
    public const MAX_FIXES = 5;

    /** The environment override for recipients, comma separated. */
    public const RECIPIENTS_ENV = 'WPMS_PULSE_TO';

    /** How many weekly snapshots to keep per scope (a quarter, pruned on write). */
    public const HISTORY_WEEKS = 26;

    /**
     * The figures compared week over week, in the order the brief prints them.
     *
     * ONE LIST, TWO READERS: build() renders these rows, and movement() gives the
     * same figures to the operator's AI tool. Adding a figure here is the only
     * way to add it to either surface, so the email and the assistant cannot
     * disagree about which numbers moved. `path` is a dotted leaf in
     * OpsPulse::snapshot(); `unit` is count, KSh or a 0–1 ratio (shown in the
     * email as percentage points).
     */
    public const METRICS = [
        ['key' => 'weddings_upcoming',        'path' => 'weddings.upcoming',                 'label' => 'Weddings upcoming',        'unit' => 'count'],
        ['key' => 'couple_accounts_pending',   'path' => 'weddings.awaiting_confirmation',    'label' => 'Couple accounts pending',  'unit' => 'count'],
        ['key' => 'no_planner_assigned',       'path' => 'weddings.unassigned_planner',       'label' => 'No planner assigned',      'unit' => 'count'],
        ['key' => 'package_value_upcoming',    'path' => 'money.package_value_upcoming',      'label' => 'Package value (upcoming)', 'unit' => 'money'],
        ['key' => 'collected_upcoming',        'path' => 'money.collected_upcoming',          'label' => 'Collected',                'unit' => 'money'],
        ['key' => 'outstanding_upcoming',      'path' => 'money.outstanding_upcoming',        'label' => 'Outstanding',              'unit' => 'money'],
        ['key' => 'subscription_mrr',          'path' => 'money.subscription_mrr',            'label' => 'Subscription MRR',         'unit' => 'money'],
        ['key' => 'renewals_due',              'path' => 'money.subscriptions_expiring_soon', 'label' => 'Renewals due',             'unit' => 'count'],
        ['key' => 'rsvp_response_rate',        'path' => 'guests.rsvp_response_rate',         'label' => 'RSVP response',            'unit' => 'ratio'],
        ['key' => 'overdue_tasks',             'path' => 'tasks.overdue',                     'label' => 'Overdue tasks',            'unit' => 'count'],
        ['key' => 'vendor_deals_confirmed',    'path' => 'vendors.deals_confirmed',           'label' => 'Vendor deals',             'unit' => 'count'],
        ['key' => 'ai_calls_7d',               'path' => 'operations.ai_calls_7d',            'label' => 'AI calls (7 days)',        'unit' => 'count'],
        ['key' => 'approvals_waiting',         'path' => 'operations.approvals_pending',      'label' => 'Approvals waiting',        'unit' => 'count'],
        ['key' => 'jobs_troubled',             'path' => 'operations.jobs_troubled',          'label' => 'Jobs troubled',            'unit' => 'count'],
    ];

    /** Is tblops_snapshots installed? Cached for the request, like UserPrefs. */
    private static ?bool $history = null;

    // ==================================================================
    // The calendar — one week, one stamp
    // ==================================================================

    /** ISO week key for a date, e.g. '2026-W43'. Stable across years. */
    public static function week_key(?string $date = null): string
    {
        return self::moment($date)->format('o-\WW');
    }

    /**
     * The Monday–Sunday span, for the subject line and the body.
     *
     * @return array{start:string, end:string, label:string}
     */
    public static function week_range(?string $date = null): array
    {
        $monday = self::moment($date)->modify('monday this week');
        $sunday = $monday->modify('+6 days');
        return [
            'start' => $monday->format('Y-m-d'),
            'end'   => $sunday->format('Y-m-d'),
            // "19–25 Oct 2026": the reader knows which month they are in.
            'label' => $monday->format('j') . '–' . $sunday->format('j M Y'),
        ];
    }

    /** Is this a Monday in Nairobi? The job may run any day; only Monday sends. */
    public static function is_due(?string $date = null): bool
    {
        return self::moment($date)->format('N') === '1';
    }

    /** Has this account already been handed the brief for that ISO week? */
    public static function already_sent(int $userId, ?string $week = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $week ??= self::week_key();
        return UserPrefs::get($userId, UserPrefs::PULSE_WEEK, '') === $week;
    }

    /** Stamp one account's week. Only called after the transport accepted the mail. */
    public static function mark_sent(int $userId, ?string $week = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return UserPrefs::remember($userId, UserPrefs::PULSE_WEEK, $week ?? self::week_key());
    }

    /** The last week this account was sent, or '' if never. */
    public static function last_sent(int $userId): string
    {
        return UserPrefs::get($userId, UserPrefs::PULSE_WEEK, '');
    }

    /** A DateTimeImmutable in the operator's timezone, never the PHP default. */
    private static function moment(?string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date ?? 'now', new DateTimeZone(self::TZ));
    }

    // ==================================================================
    // The comparison — last week's snapshot, kept on disk
    // ==================================================================

    /**
     * Is the v2.23 history table present? A database mid-upgrade must still
     * send a brief, so every read and write here degrades to "no comparison".
     */
    public static function history_available(): bool
    {
        if (self::$history !== null) {
            return self::$history;
        }

        try {
            $found = Database::read_handle()->fetchValue(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = :t',
                [':t' => 'tblops_snapshots']
            );
        } catch (Throwable $e) {
            return self::$history = false;
        }

        return self::$history = (int) $found === 1;
    }

    /**
     * The most recent snapshot taken before $week, for this scope.
     *
     * STRICTLY BEFORE is the whole contract: a re-send on Wednesday writes
     * this week's row again, and Thursday's brief must still compare against
     * last week, not against Wednesday. ISO week keys sort correctly as strings
     * ('2026-W53' < '2027-W01'), so one indexed range answers it.
     *
     * @return array{week:string, snapshot:array<string,mixed>}|null
     */
    public static function baseline(int $scopeId, ?string $week = null): ?array
    {
        $week ??= self::week_key();
        if ($scopeId < 0 || !self::history_available()) {
            return null;
        }

        try {
            $row = Database::read_handle()->fetchOne(
                'SELECT iso_week, payload FROM tblops_snapshots
                  WHERE scope_id = :s AND iso_week < :w
                  ORDER BY iso_week DESC LIMIT 1',
                [':s' => $scopeId, ':w' => $week]
            );
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null || !isset($row->iso_week, $row->payload)) {
            return null;
        }

        $snapshot = json_decode((string) $row->payload, true);
        if (!is_array($snapshot)) {
            return null;
        }
        /*
         * A row from another shape (hand-edited, or an older payload) is
         * ignored rather than half-read: a delta needs both sides of the same
         * object, and "no comparison" is truthful where a made-up zero is not.
         */
        foreach (['weddings', 'money', 'guests', 'tasks', 'vendors', 'operations'] as $key) {
            if (!isset($snapshot[$key]) || !is_array($snapshot[$key])) {
                return null;
            }
        }

        return ['week' => (string) $row->iso_week, 'snapshot' => $snapshot];
    }

    /**
     * Keep this week's snapshot, so the next brief has something to compare
     * against. Called ONLY after the transport accepted the mail.
     *
     * An upsert: a forced resend inside the week refreshes the same row instead
     * of adding a second one. Old rows are pruned here too (HISTORY_WEEKS), so
     * the table is bounded by the writer rather than by a cron nobody runs.
     */
    public static function save_snapshot(int $scopeId, array $snapshot, ?string $week = null): bool
    {
        $week ??= self::week_key();
        if ($scopeId < 0 || $snapshot === [] || !self::history_available()
            || !preg_match('/^\d{4}-W\d{2}$/', $week)) {
            return false;
        }

        $payload = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return false;
        }

        global $db;
        if (!($db instanceof Database)) {
            return false;
        }

        try {
            // The primary, not the read replica: the next week reads its own write.
            $db->execute(
                'INSERT INTO tblops_snapshots (scope_id, iso_week, payload)
                 VALUES (:s, :w, :p)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), captured_at = CURRENT_TIMESTAMP',
                [':s' => $scopeId, ':w' => $week, ':p' => $payload]
            );

            $start = self::week_start($week);
            if ($start !== null) {
                $cut = $start->modify('-' . self::HISTORY_WEEKS . ' weeks')->format('o-\\WW');
                $db->execute(
                    'DELETE FROM tblops_snapshots WHERE scope_id = :s AND iso_week < :cut',
                    [':s' => $scopeId, ':cut' => $cut]
                );
            }
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * The Monday–Sunday span of an ISO week key, for naming the comparison
     * ("since week 42, 12–18 Oct 2026"). Falls back to the key itself.
     *
     * @return array{start:string, end:string, label:string}
     */
    public static function range_for_week(string $week): array
    {
        $monday = self::week_start($week);
        if ($monday === null) {
            return ['start' => '', 'end' => '', 'label' => $week];
        }
        $sunday = $monday->modify('+6 days');
        return [
            'start' => $monday->format('Y-m-d'),
            'end'   => $sunday->format('Y-m-d'),
            'label' => $monday->format('j') . '–' . $sunday->format('j M Y'),
        ];
    }

    /** The Monday of an ISO week key, or null when the key is malformed. */
    private static function week_start(string $week): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) {
            return null;
        }
        $date = self::moment('now')->setISODate((int) $m[1], (int) $m[2], 1);

        return $date->setTime(0, 0, 0);
    }

    /**
     * The signed change printed beside one figure.
     *
     * Three units, because the brief shows three: counts, KSh and percentage
     * points. A missing side prints 'n/a' (the key may not exist in an older
     * snapshot — the soon window is part of a key name), never a zero, which
     * would claim "no change" where the truth is "not comparable".
     */
    private static function change(mixed $now, mixed $before, string $unit = 'count'): string
    {
        if (!is_numeric($now) || !is_numeric($before)) {
            return 'n/a';
        }

        if ($unit === 'ratio') {
            $points = round(((float) $now - (float) $before) * 100, 1);
            if (abs($points) < 0.05) {
                return '0pp';
            }
            return ($points > 0 ? '+' : '-') . rtrim(rtrim(number_format(abs($points), 1), '0'), '.') . 'pp';
        }

        if ($unit === 'money') {
            $delta = (int) round((float) $now - (float) $before);
            return $delta === 0 ? '0' : ($delta > 0 ? '+' : '-') . number_format(abs($delta));
        }

        $delta = (int) $now - (int) $before;
        return $delta === 0 ? '0' : ($delta > 0 ? '+' . $delta : (string) $delta);
    }

    /** Read one leaf out of a snapshot by dotted path; null when absent. */
    private static function dig(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    // ==================================================================
    // Recipients
    // ==================================================================

    /**
     * Who the brief is emailed to, with the account id whose thresholds apply.
     *
     * An env override wins outright (a shared operations mailbox has no
     * thresholds, so it gets the defaults). Otherwise: every super admin with an
     * address on file. Deliberately NOT every staff member — a planner's own
     * clients' money and every colleague's workload are not theirs to receive by
     * email, which is the same reason Business Pulse is a super-admin page.
     *
     * @return array<int, array{user_id:int, email:string, name:string}>
     */
    public static function recipients(): array
    {
        $override = getenv(self::RECIPIENTS_ENV);
        if (is_string($override) && trim($override) !== '') {
            $out = [];
            foreach (explode(',', $override) as $address) {
                $address = trim($address);
                if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $out[] = ['user_id' => 0, 'email' => $address, 'name' => ''];
                }
            }
            return $out;
        }

        /*
         * Quoted, not numeric: tblusers.access_level is ENUM('0','1','2'), and
         * MySQL compares an ENUM against an integer by its 1-based INDEX — so
         * `access_level = 2` means "the second enum value" ('1', a planner), not
         * the super admin. The quotes are the difference between emailing the
         * operators and emailing every planner's own revenue to them.
         */
        $rows = Database::read_handle()->fetchAll(
            "SELECT id, email, firstname, lastname
               FROM tblusers
              WHERE access_level = '2' AND email IS NOT NULL AND email <> ''
              ORDER BY id"
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $row   = (array) $row;
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[] = [
                'user_id' => (int) ($row['id'] ?? 0),
                'email'   => $email,
                'name'    => trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * The recipients a given run should write to.
     *
     * A MANUAL run (--force, --dry-run, --to) ignores both the calendar and
     * this week's stamp: somebody asked for the brief now, and a preview that
     * refuses to render on a Tuesday is not a preview. The scheduled run obeys
     * both, which is what makes a daily cron entry deliver once a week.
     *
     * The selection lives here rather than in the script so it can be tested
     * without waiting for a Monday (see BusinessPulseTest).
     *
     * @param array<int,array{user_id:int,email:string,name:string}>|null $recipients
     * @return array<int,array{user_id:int,email:string,name:string}>
     */
    public static function due_recipients(bool $manual = false, ?string $date = null, ?array $recipients = null): array
    {
        $recipients ??= self::recipients();
        if ($manual) {
            return $recipients;
        }
        $week = self::week_key($date);
        $due  = [];
        foreach ($recipients as $recipient) {
            if (!self::is_due($date)) {
                continue;
            }
            if ((int) $recipient['user_id'] > 0 && self::already_sent((int) $recipient['user_id'], $week)) {
                continue;
            }
            $due[] = $recipient;
        }
        return $due;
    }

    // ==================================================================
    // The brief
    // ==================================================================

    /**
     * Render one owner's brief. Pure read: no state, no send, no writes.
     *
     * @return array{subject:string, text:string, week:string, week_label:string,
     *               generated_on:string, findings:int, muted:int,
     *               compared_week:?string, snapshot:array<string,mixed>}
     */
    public static function build(int $userId = 0, ?string $date = null): array
    {
        $snapshot = OpsPulse::snapshot($userId);
        $insights = OpsPulse::insights('', 100, $userId);
        $settings = $insights['thresholds'];
        $week     = self::week_key($date);
        $range    = self::week_range($date);

        $findings  = array_slice((array) $insights['findings'], 0, self::MAX_FINDINGS);
        $previous  = self::baseline($userId, $week);

        // '' when there is nothing to compare against: the change column is
        // omitted entirely rather than printed as a row of zeroes.
        $delta = static fn(string $path, string $unit = 'count'): string => $previous === null
            ? ''
            : self::change(
                self::dig($snapshot, $path),
                self::dig((array) $previous['snapshot'], $path),
                $unit
            );

        $lines   = [];
        $lines[] = 'EVERAFTER PLANNER — WEEKLY BUSINESS PULSE';
        $lines[] = 'Week ' . substr($week, -2) . ' (' . $range['label'] . ') · generated ' . $snapshot['generated_at'];
        $lines[] = str_repeat('=', 68);

        // ---- Numbers -------------------------------------------------
        $lines[] = '';
        $lines[] = 'THE WEEK IN NUMBERS';
        if ($previous !== null) {
            $prevRange = self::range_for_week($previous['week']);
            foreach (self::wrap(
                'Change since week ' . substr($previous['week'], -2) . ' (' . $prevRange['label']
                . ') — money in KSh, pp = percentage points.',
                66
            ) as $line) {
                $lines[] = '  ' . $line;
            }
        } elseif (self::history_available()) {
            foreach (self::wrap('No earlier snapshot is on record yet — next week\'s brief will show the change.', 66) as $line) {
                $lines[] = '  ' . $line;
            }
        } else {
            foreach (self::wrap(
                'Week-over-week changes are off until migrations/v2.23_business_pulse_snapshots.sql is applied.',
                66
            ) as $line) {
                $lines[] = '  ' . $line;
            }
        }
        foreach (self::METRICS as $metric) {
            [$value, $note] = self::figure($metric['key'], $snapshot, $settings);
            $lines[] = self::row($metric['label'], $value, $note, $delta($metric['path'], $metric['unit']));
        }

        // ---- Attention ------------------------------------------------
        $lines[] = '';
        $lines[] = 'WHAT NEEDS ATTENTION' . ($insights['muted_count'] > 0
            ? '  (' . $insights['muted_count'] . ' muted rule(s) not listed)'
            : '');
        if ($findings === []) {
            $lines[] = '  No rule is firing at these thresholds.';
        } else {
            foreach ($findings as $finding) {
                $lines[] = '';
                $lines[] = '  [' . strtoupper((string) $finding['severity']) . '] ' . $finding['title'];
                foreach (self::wrap((string) $finding['detail'], 66) as $line) {
                    $lines[] = '      ' . $line;
                }
                $evidence = self::evidence_line((array) $finding['evidence']);
                if ($evidence !== '') {
                    $lines[] = '      Evidence: ' . $evidence;
                }
                $lines[] = '      Do: ' . $finding['suggested_action'];
                $lines[] = '      Page: ' . $finding['link'];
            }
        }

        // ---- Fix first ------------------------------------------------
        $lines[] = '';
        $lines[] = 'WHAT I WOULD FIX FIRST (suggestions, not facts)';
        if ($findings === []) {
            $lines[] = '  Nothing is flagged — keep the week as it is.';
        } else {
            $n = 0;
            foreach (array_slice($findings, 0, self::MAX_FIXES) as $finding) {
                $n++;
                $lines[] = '  ' . $n . '. ' . $finding['title'];
                foreach (self::wrap((string) $finding['suggested_action'], 63) as $line) {
                    $lines[] = '     ' . $line;
                }
            }
        }

        // ---- Muted ----------------------------------------------------
        if (($settings['muted_codes'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'MUTED (accepted by you — the rules still run)';
            foreach ((array) $settings['muted_codes'] as $code) {
                $lines[] = '  · ' . OpsPulse::rule_label((string) $code);
            }
        }

        // ---- What the data cannot see ---------------------------------
        $lines[] = '';
        $lines[] = 'WHAT THE DATA CANNOT SEE';
        $lines[] = '  · A vendor\'s real availability next weekend.';
        $lines[] = '  · Whether a couple has decided, or is only quiet.';
        $lines[] = '  · A price agreed on the phone and never recorded.';
        $lines[] = '  These need a conversation, not another report.';

        // ---- Thresholds + privacy -------------------------------------
        $lines[] = '';
        $lines[] = 'THRESHOLDS USED (yours — change them on Business Pulse)';
        $lines[] = '  soon ' . $settings['soon_days'] . 'd · RSVP/balance ' . $settings['watch_days'] . 'd · stale deal '
            . $settings['deal_stale_days'] . 'd · planner load ' . $settings['planner_load']
            . ' · renewals ' . $settings['renewal_days'] . 'd · capacity ' . $settings['capacity_share'] . '%';
        $lines[] = '';
        $lines[] = 'This brief was generated from aggregate figures only — no customer names, phone';
        $lines[] = 'numbers, emails or payment references. Open a linked page to see who is involved.';
        $lines[] = 'It reads; it does not change anything.';
        $lines[] = '';
        $lines[] = 'Business Pulse: ' . self::link('ops_pulse.php');
        $lines[] = 'Connect an AI assistant: ' . self::link('api_tokens.php');

        return [
            'subject'      => 'Business pulse — week ' . substr($week, -2) . ' (' . $range['label'] . ')'
                . ($findings !== [] ? ' · ' . count($findings) . ' finding(s)' : ''),
            'text'         => implode("\n", $lines) . "\n",
            'week'         => $week,
            'week_label'   => $range['label'],
            'generated_on' => (string) $snapshot['generated_at'],
            'findings'     => (int) $insights['finding_count'],
            'muted'        => (int) $insights['muted_count'],
            'compared_week' => $previous['week'] ?? null,
            'snapshot'     => $snapshot,
        ];
    }

    /**
     * The same week-over-week movement, shaped for the operator's AI tools.
     *
     * READ, NEVER WRITE: this never records a snapshot. The history is written
     * by the Monday brief (send()), so `movement` describes what the operator was
     * actually told, and a chatty assistant cannot move the baseline it is
     * reporting against. With no earlier snapshot it says so in `available` and
     * `note` instead of returning zeroes — a model must not turn "no history"
     * into "nothing changed".
     *
     * $snapshot is the caller's OpsPulse::snapshot(), passed in so an AI call
     * does not run the same sixteen queries twice. Figures are in the metric's
     * own unit: counts and KSh are absolute differences, `rsvp_response_rate` is
     * a 0–1 ratio and also carries `change_pp` (percentage points), which is how
     * the email shows it.
     *
     * @param array<string,mixed> $snapshot
     * @return array{week:string, available:bool, compared_week:?string,
     *               compared_range:?string, note:string,
     *               metrics:array<int,array<string,mixed>>, not_comparable:string[]}
     */
    public static function movement(int $userId, array $snapshot, ?string $date = null): array
    {
        $week = self::week_key($date);

        if (!self::history_available()) {
            return [
                'week'           => $week,
                'available'      => false,
                'compared_week'  => null,
                'compared_range' => null,
                'note'           => 'Week-over-week history is not installed on this database '
                    . '(migrations/v2.23_business_pulse_snapshots.sql), so no change can be reported.',
                'metrics'        => [],
                'not_comparable' => [],
            ];
        }

        $previous = self::baseline($userId, $week);
        if ($previous === null) {
            return [
                'week'           => $week,
                'available'      => false,
                'compared_week'  => null,
                'compared_range' => null,
                'note'           => 'No weekly business-pulse snapshot exists before this week. The first weekly '
                    . 'brief becomes the baseline; do not infer that a figure was unchanged.',
                'metrics'        => [],
                'not_comparable' => [],
            ];
        }

        $metrics       = [];
        $notComparable = [];
        foreach (self::METRICS as $metric) {
            $now    = self::dig($snapshot, $metric['path']);
            $before = self::dig((array) $previous['snapshot'], $metric['path']);
            if (!is_numeric($now) || !is_numeric($before)) {
                // A snapshot from an older build may not have the leaf yet.
                $notComparable[] = $metric['key'];
                continue;
            }

            $entry = ['metric' => $metric['key'], 'label' => $metric['label']];
            if ($metric['unit'] === 'ratio') {
                $value  = round((float) $now, 3);
                $was    = round((float) $before, 3);
                $change = round($value - $was, 4);
                $entry += [
                    'unit'      => 'ratio',
                    'value'     => $value,
                    'previous'  => $was,
                    'change'    => $change,
                    'change_pp' => round($change * 100, 1),
                ];
            } elseif ($metric['unit'] === 'money') {
                $value = round((float) $now, 2);
                $was   = round((float) $before, 2);
                $entry += [
                    'unit'     => 'KSh',
                    'value'    => $value,
                    'previous' => $was,
                    'change'   => round($value - $was, 2),
                ];
            } else {
                $value = (int) $now;
                $was   = (int) $before;
                $entry += [
                    'unit'     => 'count',
                    'value'    => $value,
                    'previous' => $was,
                    'change'   => $value - $was,
                ];
            }
            $metrics[] = $entry;
        }

        $range = self::range_for_week($previous['week']);
        return [
            'week'           => $week,
            'available'      => true,
            'compared_week'  => $previous['week'],
            'compared_range' => $range['label'],
            'note'           => 'Change against the last weekly business-pulse snapshot before this week. '
                . 'Counts and KSh are absolute differences; the RSVP rate is a 0–1 ratio and change_pp is percentage points.',
            'metrics'        => $metrics,
            'not_comparable' => $notComparable,
        ];
    }

    /**
     * The formatted value and the note beside one metric in the email.
     *
     * Keyed by METRICS[].key so the table (and therefore the AI's movement
     * block) has exactly the rows the owner reads. An unknown key is a bug, not
     * a blank row: it throws rather than quietly dropping a figure from the
     * brief.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $settings
     * @return array{0:string,1:string}
     */
    private static function figure(string $key, array $snapshot, array $settings): array
    {
        $w = (array) ($snapshot['weddings'] ?? []);
        $m = (array) ($snapshot['money'] ?? []);
        $g = (array) ($snapshot['guests'] ?? []);
        $t = (array) ($snapshot['tasks'] ?? []);
        $v = (array) ($snapshot['vendors'] ?? []);
        $o = (array) ($snapshot['operations'] ?? []);
        $soonKey = 'next_' . (int) $settings['soon_days'] . '_days';
        $soon    = (int) ($w[$soonKey] ?? 0);

        return match ($key) {
            'weddings_upcoming'      => [(string) $w['upcoming'], $soon . ' inside ' . $settings['soon_days'] . ' days'],
            'couple_accounts_pending' => [(string) $w['awaiting_confirmation'], 'planning tools locked'],
            'no_planner_assigned'     => [(string) $w['unassigned_planner'], 'upcoming weddings'],
            'package_value_upcoming'  => [self::ksh($m['package_value_upcoming']), ''],
            'collected_upcoming'      => [self::ksh($m['collected_upcoming']), self::ksh($m['collected_last_30_days']) . ' in 30 days'],
            'outstanding_upcoming'    => [self::ksh($m['outstanding_upcoming']), ''],
            'subscription_mrr'        => [self::ksh($m['subscription_mrr']), $m['active_subscriptions'] . ' active'],
            'renewals_due'            => [(string) $m['subscriptions_expiring_soon'], $m['trials_ending_soon'] . ' trial(s) in ' . $settings['renewal_days'] . 'd'],
            'rsvp_response_rate'      => [round(((float) $g['rsvp_response_rate']) * 100) . '%', $g['rsvp_answered'] . ' of ' . $g['total'] . ' guests'],
            'overdue_tasks'           => [(string) $t['overdue'], $t['open'] . ' open'],
            'vendor_deals_confirmed'  => [(string) $v['deals_confirmed'], $v['deals_pending'] . ' pending'],
            'ai_calls_7d'             => [(string) $o['ai_calls_7d'], $o['ai_failures_7d'] . ' failed, ' . $o['ai_write_calls_7d'] . ' write'],
            'approvals_waiting'       => [(string) $o['approvals_pending'], ''],
            'jobs_troubled'           => [(string) $o['jobs_troubled'], $o['jobs_troubled'] > 0 ? 'open Job Health' : 'all reporting'],
            default                   => throw new LogicException('BusinessPulse: no figure for metric ' . $key),
        };
    }

    /**
     * Build and send one account's brief, stamping the week only when the
     * transport accepted it.
     *
     * @return array{ok:bool, status:string, note:?string, week:string, subject:string, text:string}
     */
    public static function send(int $userId, string $email, ?string $date = null): array
    {
        $email = trim($email);
        $week  = self::week_key($date);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok'      => false,
                'status'  => 'skipped',
                'note'    => 'no valid address on file',
                'week'    => $week,
                'subject' => '',
                'text'    => '',
            ];
        }

        if (!class_exists('Notification', false)) {
            // The page and the job both load the model set; this is for a caller
            // that only pulled this class in. Never a second transport.
            require_once __DIR__ . '/Notification.php';
        }

        $brief  = self::build($userId, $date);
        $result = Notification::send_address_mail($email, $brief['subject'], $brief['text']);

        $accepted = in_array($result['status'], ['sent', 'pending'], true);
        if ($accepted) {
            if ($userId > 0) {
                self::mark_sent($userId, $brief['week']);
            }
            // Only an accepted send becomes next week's baseline. A preview or a
            // hard failure must never move the comparison forward.
            self::save_snapshot($userId, (array) $brief['snapshot'], $brief['week']);
        }

        return [
            'ok'      => $accepted,
            'status'  => $result['status'],
            'note'    => $result['note'],
            'week'    => $brief['week'],
            'subject' => $brief['subject'],
            'text'    => $brief['text'],
        ];
    }

    // ==================================================================
    // Formatting
    // ==================================================================

    /**
     * One label/value row: an optional signed change column, then the note.
     * The change is '' when there is no baseline, so the column disappears
     * rather than implying "no change".
     */
    private static function row(string $label, string $value, string $note = '', string $change = ''): string
    {
        $line = '  ' . str_pad($label, 26) . ' ' . str_pad($value, 14);
        if ($change !== '') {
            $line .= ' ' . str_pad($change, 8);
        }
        return rtrim($line . ($note !== '' ? ' ' . $note : ''));
    }

    /** Money, in the currency every figure in this platform is in. */
    private static function ksh(int|float $amount): string
    {
        return 'KSh ' . number_format((float) $amount);
    }

    /**
     * The numbers that fired a finding, as one line.
     *
     * Only the keys OpsPulse is allowed to emit are printed, and money keys are
     * formatted — anything else is ignored rather than guessed at, so a new
     * evidence field cannot leak something into an email before this formatter
     * has been taught what it is.
     */
    private static function evidence_line(array $evidence): string
    {
        $labels = [
            'booking_id'    => 'booking',
            'days_to_go'    => 'days to go',
            'guests'        => 'guests',
            'answered'      => 'answered',
            'overdue'       => 'overdue tasks',
            'organizer_id'  => 'planner id',
            'weddings'      => 'weddings',
            'job'           => 'job',
        ];
        $money = ['package_price' => 'package', 'paid' => 'paid', 'outstanding' => 'outstanding'];

        $parts = [];
        foreach ((array) ($evidence['subjects'] ?? []) as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $bits = [];
            foreach ($subject as $key => $value) {
                $key = (string) $key;
                if (isset($labels[$key]) && (is_int($value) || is_float($value) || is_string($value))) {
                    $bits[] = $labels[$key] . ' ' . (is_string($value) ? $value : (string) (int) $value);
                } elseif (isset($money[$key]) && is_numeric($value)) {
                    $bits[] = $money[$key] . ' ' . self::ksh((float) $value);
                }
            }
            if ($bits !== []) {
                $parts[] = implode(', ', $bits);
            }
        }
        return implode('; ', $parts);
    }

    /** Hard-wrap one paragraph for a plain-text email read on a phone. */
    private static function wrap(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", false));
    }

    /** An absolute link when the site URL is configured, else a relative one. */
    private static function link(string $page): string
    {
        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? trim($base) : '';
        return $base !== '' ? rtrim($base, '/') . '/admin/' . ltrim($page, '/') : 'admin/' . ltrim($page, '/');
    }
}
