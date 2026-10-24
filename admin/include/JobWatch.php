<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.15 — The platform's own silence, reported (JobWatch)
 *
 * The scheduled jobs keep promises to people who are not watching:
 *
 *   scripts/mail_retry.php              a queued email actually leaves
 *   scripts/notify_prune.php            the alert archive does not grow forever
 *   scripts/subscription_renewals.php   a planner is prompted, dunned and
 *                                       nurtured before their plan lapses
 *   scripts/rsvp_reminders.php          guests who have not answered are nudged
 *   scripts/deadline_sms.php            a couple with a balance is texted as
 *                                       the final month closes in
 *   scripts/thread_escalation.php       a vendor who has left a couple's
 *                                       conversation unanswered is reported to
 *                                       their planner (v2.26)
 *
 * Every one of them is SILENT when it stops. A dead pinger, a renamed crontab
 * entry, a PHP upgrade that breaks the CLI binary, a lock left behind by a
 * killed process — all of them produce exactly the same observable result:
 * nothing. The platform finds out days later, when a planner says an invoice
 * email never arrived and the renewal window is already gone. Each script
 * already prints what it did, for whoever is standing there. Nobody is.
 *
 * So each job now leaves a HEARTBEAT (record()), and this class reads them:
 *
 *   record()  — called by the job itself at the end of every real run: what it
 *               did ('ok' / 'noop' / 'failed'), how many rows it acted on, how
 *               much work its window held, and its own one-line summary.
 *   report()  — the whole picture, with each job's state and whether the run
 *               had anything to show for the window it was handed.
 *   audit()   — report() plus the operator alert, deduped, and the watchdog's
 *               own heartbeat, so "the watcher last ran at 14:05" is a fact.
 *
 * WHY THE JOB REPORTS ITS OWN QUEUE. "Nothing to show for its window" needs two
 * numbers, and only the job has both: how many rows it acted on, and how many
 * its own selection offered it. Each script's selection is its own — invite
 * windows for RSVP reminders, band markers for deadline texts, the retry cap for
 * the mail sweep — so a copy of that predicate here would be a second set of
 * rules to keep in step, and the day the two disagreed the watchdog would be
 * wrong about a job that was right. record() therefore takes the queue the run
 * found, and this class never asks the database a question the job can answer
 * about itself ('never recorded a queue' is a state of its own, not a zero).
 *
 * THE FOUR THINGS WORTH TELLING THE OPERATOR, and nothing else:
 *
 *   never    no run has ever been recorded for this job. The platform has no
 *            evidence it works on this install — usually cron/the bridge is
 *            not wired up. Reported, never alerted by itself (see below).
 *   silent   it HAS run before, and has not run for longer than its own
 *            interval plus the grace it is allowed. This is the real incident:
 *            something that used to work stopped.
 *   failed   the last run died (non-zero exit), with the streak when it keeps
 *            failing.
 *   idle     it ran on time, said 'ok', and handled NOTHING while the queue IT
 *            reported held work. A sweeper that reports success while the queue
 *            grows is silent in the only way that matters.
 *
 * WHY 'never' DOES NOT RING THE BELL. On the day an install goes live every job
 * is in that state, and a bell saying so is not news — it is the deploy
 * checklist. The operator's page shows it, `scripts/job_watch.php` exits 1 on
 * it, and the cron bridge reports it as a failed job, so a pinger's keyword or
 * status monitor catches a scheduler that was never configured. What alerts by
 * itself is a job that HAS run and then went quiet, which is always news.
 *
 * WHY 'noop' EXISTS. mail_retry with WPMS_MAIL_ENABLED off is a deliberate
 * no-op (the script documents it as safe to leave in a crontab across
 * environments), and subscription_renewals with every leg disabled has nothing
 * it is allowed to do. Those runs record 'noop' and are never called idle — a
 * job cannot fail to do what it was told not to do.
 *
 * NOISE CONTROL. An alert is delivered once per (job, reason) and then stays
 * quiet for RE_ALERT_AFTER, so a job that has been dead for three days costs
 * one bell per half-day rather than one per audit; when the job recovers the
 * memory is cleared, so the NEXT silence is news again. That memory lives in
 * the job's own heartbeat row (alert_reason/alerted_at), which is why a bounded
 * table can carry an unbounded incident.
 *
 * AN INSTALL THAT WANTS NO JOB ALERTS AT ALL sets WPMS_JOB_ALERTS=0 in `.env`
 * (see alerts_enabled()). It silences the bell and the email only: the report,
 * the non-zero exit and the operator's page are unaffected, so the fact is
 * never lost — only the interruption is.
 *
 * FAIL OPEN, ALWAYS. A heartbeat must never be able to break the job it
 * describes: a missing table (v2.15 not applied yet), a lost connection or a
 * create race is logged and swallowed. The same goes for the alert path — an
 * operator notification that cannot be written must not stop the report, the
 * non-zero exit, or the job that produced it.
 *
 * Storage: migrations/v2.15_job_heartbeats.sql. All aging happens in SQL
 * against the DB session timezone (Africa/Nairobi), never in PHP — same
 * discipline as LoginThrottle, SignupGuard and PublicGuard.
 */
final class JobWatch
{
    // ------------------------------------------------------------------
    // The registry — the only jobs this watchdog knows how to judge
    // ------------------------------------------------------------------

    public const MAIL_RETRY      = 'mail_retry';
    public const NOTIFY_PRUNE    = 'notify_prune';
    public const MCP_APPROVALS   = 'mcp_approval_sweep';
    public const RENEWALS        = 'subscription_renewals';
    public const RSVP_REMINDERS  = 'rsvp_reminders';
    public const DEADLINE_SMS    = 'deadline_sms';
    public const BUSINESS_PULSE  = 'business_pulse_email';
    public const THREAD_ESCALATION = 'thread_escalation';

    /**
     * The watchdog's own heartbeat, kept under a reserved key that is not a
     * scheduled job: it is how the page can say when the check last ran.
     */
    public const AUDIT = '_audit';

    /** What a job says it did. */
    public const OK     = 'ok';     // ran, and did everything it was configured to do
    public const NOOP   = 'noop';   // deliberately did nothing — transport/legs off
    public const FAILED = 'failed'; // died; last_exit_code carries the code

    /** What the watchdog says about a job. */
    public const F_NEVER  = 'never';
    public const F_SILENT = 'silent';
    public const F_FAILED = 'failed';
    public const F_IDLE   = 'idle';

    /**
     * Every job this watchdog judges, with the schedule it is deployed at and
     * the queue it is supposed to be draining.
     *
     *   every   seconds between runs (mail_retry: the 15-minute crontab in its
     *           own docblock and the bridge's 10-minute throttle),
     *   grace   lateness tolerated before a missing run is an incident (a
     *           daily job gets half a day — one slow night is not a crisis),
     *   waiting what the queue that run found is called, in the operator's words.
     *
     * A job that is not in here is not judged: silence about a job nobody
     * scheduled would be noise, not information.
     *
     * @var array<string, array{label:string, every:int, grace:int, waiting:string}>
     */
    public const JOBS = [
        self::MAIL_RETRY => [
            'label'   => 'Mail retry',
            'every'   => 900,     // 15 min
            'grace'   => 1800,    // + 30 min → silent after 45 min
            'waiting' => 'queued email(s) inside the retry cap',
        ],
        self::NOTIFY_PRUNE => [
            'label'   => 'Notification prune',
            'every'   => 86400,   // daily
            'grace'   => 43200,   // + 12 h → silent after 36 h
            'waiting' => 'alert(s) past their retention window',
        ],
        // v2.19. Every 10 minutes, because the request window it closes is 30
        // minutes: a planner's bell should arrive soon after the deadline, not
        // the next morning. `waiting` is the count of requests already past
        // their window that this run had not closed yet — a backlog here means
        // nobody is being told their SMS did not go out.
        self::MCP_APPROVALS => [
            'label'   => 'AI approval expiry sweep',
            'every'   => 900,     // 10 min
            'grace'   => 1800,    // + 30 min → silent after 45 min
            'waiting' => 'AI request(s) past their window, unanswered or never used',
        ],
        self::RENEWALS => [
            'label'   => 'Subscription renewals',
            'every'   => 86400,   // daily
            'grace'   => 43200,   // + 12 h → silent after 36 h
            'waiting' => 'subscription(s) waiting for a prompt, text or email',
        ],
        // The two SMS jobs run from the same crontab/bridge as the rest (09:00
        // and 10:00 Nairobi), so they get the same daily interval and the same
        // half-day grace: one slow night is not an incident, two are.
        self::RSVP_REMINDERS => [
            'label'   => 'RSVP reminders',
            'every'   => 86400,   // daily
            'grace'   => 43200,   // + 12 h → silent after 36 h
            'waiting' => 'guest(s) due a reminder',
        ],
        self::DEADLINE_SMS => [
            'label'   => 'Payment deadline texts',
            'every'   => 86400,   // daily
            'grace'   => 43200,   // + 12 h → silent after 36 h
            'waiting' => 'booking(s) inside a payment band with a balance open',
        ],
        /*
         * The weekly owner's brief (BusinessPulse). It runs DAILY and decides
         * in code whether today is Monday and whether this week was already
         * delivered, so a missed Monday is caught on Tuesday rather than
         * skipping a week. The queue it reports is the owners a run selected as
         * due; on a Tuesday that is zero, which is a legitimate quiet window
         * rather than a job that failed to do its work.
         */
        self::BUSINESS_PULSE => [
            'label'   => 'Weekly business pulse',
            'every'   => 86400,   // daily check; sends on Monday
            'grace'   => 43200,   // + 12 h → silent after 36 h
            'waiting' => 'owner(s) due the weekly brief',
        ],
        /*
         * v2.26. Daily, like the other daily jobs: "unanswered for a day" is the
         * trigger, so an hourly check would only re-ask about a silence nobody
         * has had the chance to end. `waiting` is the number of past-grace
         * messages this run had not reported yet — a backlog here means couples
         * are waiting and their planners have not been told.
         */
        self::THREAD_ESCALATION => [
            'label'   => 'Vendor reply escalation',
            'every'   => 86400,   // daily
            'grace'   => 43200,   // + 12 h → silent after 36 h
            'waiting' => 'unanswered conversation(s) past the reply grace',
        ],
    ];

    /** One alert per (job, reason) per half-day — the operator-noise damper. */
    public const RE_ALERT_AFTER = 43200;

    /** Alerts go to at most this many super admins (the operator is one person). */
    public const OPERATOR_LIMIT = 5;

    /** Longest summary the column keeps. */
    public const SUMMARY_MAX = 255;

    /** Is tbljob_heartbeats present? Cached for the request. */
    private static ?bool $installed = null;

    // ==================================================================
    // WRITE — the jobs' own heartbeat
    // ==================================================================

    /**
     * Remember one run of one job. Called by the job itself, at the end of
     * every run that actually happened (a dry run is not a run; a run that
     * found nothing to do still is — that is the point of the heartbeat).
     *
     * Never throws: a heartbeat that could break its own job would be a worse
     * bug than the silence it exists to report.
     *
     * @param string   $status self::OK | self::NOOP | self::FAILED
     * @param int      $items  rows/prompts/emails the run actually acted on
     * @param int|null $queue  how much work the run's own window held (its own
     *                         selection, before the --limit broke the loop);
     *                         null = this job does not report a queue, which is
     *                         a state of its own and never read as an idle zero
     * @return bool whether the heartbeat was recorded
     * @throws InvalidArgumentException on a job or status this watchdog does not know
     */
    public static function record(
        string $job,
        string $status,
        int $items = 0,
        string $summary = '',
        int $exitCode = 0,
        ?int $startedAt = null,
        ?int $queue = null
    ): bool {
        $job    = self::knownJob($job);   // outside the try: a bad call is a bug, not an outage
        $status = self::knownStatus($status);
        try {
            global $db;
            $db->execute(
                'INSERT INTO tbljob_heartbeats
                     (job, label, last_started_at, last_finished_at, last_status, last_exit_code,
                      last_items, last_queue, last_summary, runs, fail_streak)
                 VALUES (:job, :label, :started, NOW(), :status, :exit, :items, :queue, :summary, 1, :streak)
                 ON DUPLICATE KEY UPDATE
                     label            = VALUES(label),
                     last_started_at  = COALESCE(VALUES(last_started_at), last_started_at),
                     last_finished_at = NOW(),
                     last_status      = VALUES(last_status),
                     last_exit_code   = VALUES(last_exit_code),
                     last_items       = VALUES(last_items),
                     last_queue       = VALUES(last_queue),
                     last_summary     = VALUES(last_summary),
                     runs             = runs + 1,
                     fail_streak      = IF(VALUES(last_status) = :fail2, fail_streak + 1, 0),
                     updated_at       = NOW()',
                [
                    ':job'     => $job,
                    ':label'   => self::label($job),
                    ':started' => $startedAt !== null ? date('Y-m-d H:i:s', $startedAt) : null,
                    ':status'  => $status,
                    ':exit'    => $exitCode,
                    ':items'   => max(0, $items),
                    ':queue'   => $queue === null ? -1 : max(0, $queue),
                    ':summary' => self::trim($summary),
                    ':streak'  => $status === self::FAILED ? 1 : 0,
                    ':fail2'   => self::FAILED,
                ]
            );
            return true;
        } catch (Throwable $e) {
            error_log('[WPMS][JobWatch] ' . $job . ' heartbeat not recorded: ' . $e->getMessage());
            return false;
        }
    }

    // ==================================================================
    // READ — what the operator is shown
    // ==================================================================

    /**
     * The cheap read the sidebar badge uses: every judged job's state from the
     * heartbeat table alone — ONE indexed query for the whole registry, which is
     * all a page-load badge may cost. Every number here was written by the job
     * itself, so the badge can never disagree with the jobs about what they did.
     *
     * @return array<string, array{job:string, label:string, state:string, finding:?string, age:?int, items:int, runs:int}>
     */
    public static function states(): array
    {
        $out = [];
        foreach (self::JOBS as $job => $spec) {
            $out[$job] = [
                'job'     => $job,
                'label'   => $spec['label'],
                'state'   => 'missing',
                'finding' => self::F_NEVER,
                'age'     => null,
                'items'   => 0,
                'runs'    => 0,
            ];
        }
        $beats = self::beats();
        foreach ($beats as $job => $row) {
            if (!isset($out[$job])) {
                continue;
            }
            $age = self::ageOf($row);
            [$state, $finding] = self::judge($row, $age);
            $out[$job]['state']   = $state;
            $out[$job]['finding'] = $finding;
            $out[$job]['age']     = $age;
            $out[$job]['items']   = (int) ($row->last_items ?? 0);
            $out[$job]['runs']    = (int) ($row->runs ?? 0);
        }
        return $out;
    }

    /**
     * Jobs that used to run and have stopped (or are failing) — the number the
     * sidebar badge shows. A never-run job is deliberately NOT counted: on the
     * day an install goes live every job is in that state, and a badge that is
     * permanently on says nothing.
     */
    public static function troubled_count(): int
    {
        $n = 0;
        foreach (self::states() as $state) {
            if ($state['runs'] > 0 && in_array($state['finding'], [self::F_SILENT, self::F_FAILED], true)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * The whole picture: one entry per judged job, with the queue its last run
     * reported and the finding that follows from the two together. Reads only —
     * audit() is the one that writes (its own heartbeat, the alerts, the
     * recovered memories).
     *
     * @return array{installed:bool, checked_at:?string, checked_age:?int, jobs:array<string,array<string,mixed>>, findings:array<string,array<string,mixed>>}
     */
    public static function report(): array
    {
        $installed = self::available();
        $beats     = $installed ? self::beats() : [];
        $auditRow  = $beats[self::AUDIT] ?? null;

        $jobs     = [];
        $findings = [];

        foreach (self::JOBS as $job => $spec) {
            $row = $beats[$job] ?? null;
            $age = $row !== null ? self::ageOf($row) : null;
            // -1 is "this job does not report a queue": a state of its own, so
            // the page can print a dash instead of a zero it never claimed.
            $queued = $row !== null ? (int) $row->last_queue : -1;
            [$state, $finding] = self::judge($row, $age);

            $entry = [
                'job'              => $job,
                'label'            => $spec['label'],
                'every'            => $spec['every'],
                'grace'            => $spec['grace'],
                'waiting'          => max(0, $queued),
                'waiting_reported' => $queued >= 0,
                'waiting_text'     => $spec['waiting'],
                'state'            => $state,
                'finding'          => $finding,
                'age'              => $age,
                'runs'             => $row !== null ? (int) $row->runs : 0,
                'status'           => $row !== null ? (string) $row->last_status : '',
                'exit_code'        => $row !== null ? (int) $row->last_exit_code : 0,
                'items'            => $row !== null ? (int) $row->last_items : 0,
                'summary'          => $row !== null ? (string) $row->last_summary : '',
                'finished_at'      => $row !== null ? (string) ($row->last_finished_at ?? '') : '',
                'fail_streak'      => $row !== null ? (int) $row->fail_streak : 0,
                'alert_reason'     => $row !== null ? (string) $row->alert_reason : '',
                'alerted_age'      => $row !== null && $row->alerted_at !== null ? self::ageOf($row, 'alerted_at') : null,
            ];
            $entry['sentence'] = self::sentence($entry);
            $jobs[$job] = $entry;

            if ($finding !== null) {
                $findings[$job] = [
                    'job'     => $job,
                    'label'   => $spec['label'],
                    'reason'  => $finding,
                    'message' => self::alertMessage($entry),
                    'subject' => self::alertSubject($job, $finding),
                ];
            }
        }

        return [
            'installed'   => $installed,
            'checked_at'  => $auditRow !== null ? (string) ($auditRow->last_finished_at ?? '') : null,
            'checked_age' => $auditRow !== null ? self::ageOf($auditRow) : null,
            'jobs'        => $jobs,
            'findings'    => $findings,
        ];
    }

    // ==================================================================
    // AUDIT — report, alert, and remember that we looked
    // ==================================================================

    /**
     * Look, tell the operator about anything that has never been said (or has
     * not been said inside the cooldown), and record the watchdog's own
     * heartbeat so the next reader can see when this last ran.
     *
     * @param bool $alert false = inspect only: no bell, no memory change
     * @return array{report:array<string,mixed>, findings:array<string,array<string,mixed>>, alerted:int}
     */
    public static function audit(bool $alert = true): array
    {
        $report   = self::report();
        $findings = $report['findings'];
        $alerted  = 0;

        if ($alert && $report['installed'] && self::alerts_enabled()) {
            foreach ($findings as $job => $finding) {
                /*
                 * A job that has NEVER run is reported, not announced: on the
                 * day an install goes live every judged job is in that state,
                 * and there is no heartbeat row to remember the alert in — the
                 * bell would repeat on every audit until cron was wired up.
                 * The CLI's non-zero exit and the cron bridge's HTTP 500 are
                 * what reach the operator about a scheduler that was never
                 * configured (see the class docblock).
                 */
                if ($finding['reason'] === self::F_NEVER) {
                    continue;
                }
                if (self::throttled($job, (string) $finding['reason'])) {
                    continue;
                }
                $alerted += self::deliver($job, $finding);
                self::stamp_alert($job, (string) $finding['reason']);
            }
            // Recovery: a job that is well again forgets. That is what makes
            // the NEXT silence news instead of a repeat we suppress.
            foreach ($report['jobs'] as $job => $entry) {
                if ($entry['finding'] === null && $entry['alert_reason'] !== '') {
                    self::stamp_alert($job, '');
                }
            }
            self::prune();
        }

        self::record(
            self::AUDIT,
            self::OK,
            count($findings),
            $findings === [] ? 'every judged job reported in time' : implode(', ', array_keys($findings)),
            count($findings) === 0 ? 0 : 1
        );

        // The stamp of THIS check (the heartbeat above just wrote it), so a
        // caller can say "checked 0 s ago" instead of reporting the previous
        // run's age back to the operator who just asked.
        $stamp = self::audit_stamp();
        $report['checked_at']  = $stamp['at'];
        $report['checked_age'] = $stamp['age'];

        return ['report' => $report, 'findings' => $findings, 'alerted' => $alerted];
    }

    /**
     * Drop heartbeats for jobs the registry no longer judges (a renamed job
     * leaves a row behind, and this table should only ever claim what the code
     * still runs). The watchdog's own row is kept.
     */
    public static function prune(): int
    {
        if (!self::available()) {
            return 0;
        }
        try {
            global $db;
            $keys  = array_merge(array_keys(self::JOBS), [self::AUDIT]);
            $marks = [];
            foreach ($keys as $i => $k) {
                $marks[] = ':k' . $i;
            }
            $stmt = $db->execute(
                'DELETE FROM tbljob_heartbeats WHERE job NOT IN (' . implode(', ', $marks) . ')',
                array_combine($marks, $keys)
            );
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[WPMS][JobWatch] prune failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ==================================================================
    // Internals — judging
    // ==================================================================

    /**
     * The state and the finding for one heartbeat row.
     *
     * @return array{0:string, 1:?string} [state, finding]
     */
    private static function judge(?object $row, ?int $age): array
    {
        if ($row === null || $row->last_finished_at === null) {
            return ['missing', self::F_NEVER];
        }
        $job   = (string) $row->job;
        $spec  = self::JOBS[$job] ?? null;
        $every = (int) ($spec['every'] ?? 86400);
        $grace = (int) ($spec['grace'] ?? 0);
        $status = strtolower((string) $row->last_status);

        if ($age !== null && $age > $every + $grace) {
            return ['silent', self::F_SILENT];
        }
        if ($status === self::FAILED || (int) $row->fail_streak >= 2) {
            return ['failed', self::F_FAILED];
        }
        /*
         * Only a job that CLAIMS it did its job can be idle, and only over a
         * queue IT reported: a 'noop' run with a full queue is a configuration
         * fact (the transport is off), and a job that reports no queue at all
         * (-1) is never credited with one it did not mention.
         */
        if ($status === self::OK && (int) $row->last_items === 0 && (int) $row->last_queue > 0) {
            return ['idle', self::F_IDLE];
        }
        if ($age !== null && $age > $every) {
            return ['late', null]; // inside the grace: worth showing, not worth saying
        }
        return [$status === self::NOOP ? 'noop' : 'ok', null];
    }

    /**
     * One job's queue as the last run reported it, or null when that run did not
     * report one. The number is deliberately NOT recomputed here: the job's own
     * selection decides what waits (see the class docblock), and a second
     * implementation would be wrong the first time either side changed. Read
     * from a report() entry, so "no queue reported" stays distinguishable from a
     * genuine zero.
     */
    public static function queued(array $entry): ?int
    {
        return !empty($entry['waiting_reported']) ? (int) $entry['waiting'] : null;
    }

    /** The operator's sentence for one judged job. */
    private static function sentence(array $e): string
    {
        $age = $e['age'] !== null ? self::human_age((int) $e['age']) : '';
        switch ($e['finding']) {
            case self::F_NEVER:
                return $e['label'] . ' has never recorded a run — check cron / cron_bridge.php on this install.';
            case self::F_SILENT:
                return $e['label'] . ' has not run for ' . $age . ' (expected at least every '
                    . self::human_age((int) $e['every']) . ').';
            case self::F_FAILED:
                return $e['label'] . ' failed on its last run (exit ' . (int) $e['exit_code']
                    . ((int) $e['fail_streak'] > 1 ? ', ' . (int) $e['fail_streak'] . ' in a row' : '')
                    . ')' . ($e['summary'] !== '' ? ': ' . $e['summary'] : '.');
            case self::F_IDLE:
                return $e['label'] . ' ran ' . $age . ' ago but handled nothing while '
                    . (int) $e['waiting'] . ' ' . $e['waiting_text'] . ' waited.';
        }
        if ($e['state'] === 'missing') {
            return $e['label'] . ' has no run recorded yet.';
        }
        if ($e['state'] === 'noop') {
            return $e['label'] . ' ran ' . $age . ' ago, deliberately doing nothing'
                . ($e['summary'] !== '' ? ' (' . $e['summary'] . ')' : '') . '.';
        }
        if ($e['state'] === 'late') {
            return $e['label'] . ' last ran ' . $age . ' ago — inside its grace, nothing to do yet.';
        }
        return $e['label'] . ' last ran ' . $age . ' ago, handled ' . (int) $e['items'] . ' item(s).';
    }

    /** The bell text: what happened, in one line the archive can hold. */
    private static function alertMessage(array $e): string
    {
        return self::trim(self::sentence($e));
    }

    /** The mail subject: which job, which problem — readable at a glance. */
    private static function alertSubject(string $job, string $reason): string
    {
        $word = [
            self::F_NEVER  => 'has never run',
            self::F_SILENT => 'is silent',
            self::F_FAILED => 'is failing',
            self::F_IDLE   => 'has nothing to show for its window',
        ][$reason] ?? 'needs attention';
        // The mail-log subject is a column too: keep the job's own label out of
        // it and let the message carry the detail.
        return 'Scheduled job ' . $word . ' — ' . self::label($job);
    }

    // ==================================================================
    // Internals — the alert path
    // ==================================================================

    /**
     * Has this (job, reason) been told to the operator recently enough to stay
     * quiet about? A different reason is always news — a job that stopped is
     * not made less interesting by the fact that we once said it was failing.
     */
    private static function throttled(string $job, string $reason): bool
    {
        try {
            global $db;
            $age = $db->fetchValue(
                'SELECT TIMESTAMPDIFF(SECOND, alerted_at, NOW()) FROM tbljob_heartbeats
                  WHERE job = :j AND alert_reason = :r AND alerted_at IS NOT NULL',
                [':j' => $job, ':r' => $reason]
            );
            return $age !== null && $age !== false && (int) $age < self::RE_ALERT_AFTER;
        } catch (Throwable $e) {
            error_log('[WPMS][JobWatch] alert cooldown not readable: ' . $e->getMessage());
            return false; // when in doubt, tell the operator
        }
    }

    /** Remember that (job, reason) has been delivered — '' forgets instead. */
    private static function stamp_alert(string $job, string $reason): void
    {
        try {
            global $db;
            $db->execute(
                'UPDATE tbljob_heartbeats
                    SET alert_reason = :r, alerted_at = IF(:r2 = \'\', NULL, NOW()), updated_at = NOW()
                  WHERE job = :j',
                [':r' => $reason, ':r2' => $reason, ':j' => $job]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][JobWatch] alert memory not written: ' . $e->getMessage());
        }
    }

    /**
     * Tell the operator. The bell row is the durable channel — it is read on
     * the next sign-in whatever happened to email — and the error log line is
     * for a host with no database at all to reason about. Every super admin
     * gets it (they are the SaaS operator) and no failure in this path is
     * allowed to surface: the report and the exit code already carry the fact.
     */
    private static function deliver(string $job, array $finding): int
    {
        error_log('[WPMS][JobWatch] ' . $job . ': ' . $finding['message']);
        if (!class_exists('Notification', false)) {
            return 0;
        }
        $sent = 0;
        foreach (self::operator_ids() as $id) {
            try {
                Notification::create_for_staff(
                    $id,
                    0,
                    (string) $finding['message'],
                    'job_watch.php',
                    (string) $finding['subject']
                );
                $sent++;
            } catch (Throwable $e) {
                error_log('[WPMS][JobWatch] alert not delivered to user ' . $id . ': ' . $e->getMessage());
            }
        }
        return $sent;
    }

    /**
     * The platform's operator: super admins (tblusers.access_level 2). The same
     * definition LoginThrottle falls back to when a locked account has no owner
     * to warn — one answer to "who runs this install?".
     *
     * @return int[]
     */
    public static function operator_ids(): array
    {
        try {
            global $db;
            /*
             * '2' as a STRING, deliberately: tblusers.access_level is
             * enum('0','1','2') and MySQL compares an enum to an INTEGER by
             * ordinal position — `access_level = 2` would match every '1'
             * (index 2), i.e. every planner. The quoted literal compares the
             * stored string, which is the question this asks.
             */
            $ids = $db->fetchAll(
                "SELECT id FROM tblusers WHERE access_level = '2' ORDER BY id LIMIT " . self::OPERATOR_LIMIT
            );
            return array_map(static fn($r): int => (int) $r->id, $ids);
        } catch (Throwable $e) {
            error_log('[WPMS][JobWatch] operator list not readable: ' . $e->getMessage());
            return [];
        }
    }

    // ==================================================================
    // Internals — storage and formatting
    // ==================================================================

    /**
     * Every heartbeat row, keyed by job, with its age computed in SQL (the DB
     * session clock is the one every job already writes with).
     *
     * @return array<string, object>
     */
    private static function beats(): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            global $db;
            $rows = $db->fetchAll(
                'SELECT *, TIMESTAMPDIFF(SECOND, last_finished_at, NOW()) AS age,
                        TIMESTAMPDIFF(SECOND, alerted_at, NOW()) AS alerted_age
                   FROM tbljob_heartbeats'
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->job] = $row;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[WPMS][JobWatch] heartbeats not readable: ' . $e->getMessage());
            return [];
        }
    }

    /** The watchdog's own last check, [stamp, age] — nulls when it never ran. */
    private static function audit_stamp(): array
    {
        try {
            global $db;
            $row = $db->fetchOne(
                'SELECT last_finished_at, TIMESTAMPDIFF(SECOND, last_finished_at, NOW()) AS age
                   FROM tbljob_heartbeats WHERE job = :j',
                [':j' => self::AUDIT]
            );
            if ($row === null) {
                return ['at' => null, 'age' => null];
            }
            return [
                'at'  => (string) ($row->last_finished_at ?? ''),
                'age' => $row->age === null ? null : max(0, (int) $row->age),
            ];
        } catch (Throwable $e) {
            return ['at' => null, 'age' => null];
        }
    }

    /** One row's age in seconds (null = the column is NULL). */
    private static function ageOf(object $row, string $column = 'age'): ?int
    {
        $col = $column === 'alerted_at' ? 'alerted_age' : $column;
        $v   = $row->{$col} ?? null;
        return $v === null ? null : max(0, (int) $v);
    }

    /**
     * Is tbljob_heartbeats installed? A database that has not run v2.15 — a
     * live site mid-upgrade, a scratch build from an older dump — must still
     * serve every page and run every job: the report says "not installed", the
     * jobs skip their heartbeat, and nothing else changes. Cached per request.
     */
    public static function available(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }
        try {
            global $db;
            self::$installed = (bool) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbljob_heartbeats'"
            );
        } catch (Throwable $e) {
            self::$installed = false;
        }
        return self::$installed;
    }

    /**
     * TWO switches can silence the watchdog's ALERTS, and neither touches the
     * heartbeat it reads.
     *
     *   1. WPMS_JOB_ALERTS=0 (off/false/no) — the OPERATOR's own switch, set in
     *      `.env` on a live install. It exists because the alternative was
     *      WPMS_LOGIN_THROTTLE_OFF below, which silences job alerts only by also
     *      switching off brute-force protection on the login door: an operator
     *      who wanted a quieter bell during a maintenance window would have had
     *      to unlock every account an attacker was guessing at. Absent or empty
     *      means on, so an untouched install alerts.
     *
     *   2. WPMS_LOGIN_THROTTLE_OFF=1 (true/yes/on) — the SUITE's switch, the one
     *      LoginThrottle, SignupGuard and PublicGuard already read, so a test run
     *      that must not leave bells in operators' inboxes flips one flag. It
     *      silences the job alerts too, because a suite that suppresses the
     *      operator's other bells and leaves these behind would be worse than
     *      useless — and JobWatchTest restores each flag per test to prove both
     *      doors work.
     *
     * The HEARTBEAT is deliberately gated by neither: a few indexed rows with no
     * bell and no email are not an operator-visible side effect, and a suite that
     * suppresses alerts should still be able to assert what the table says.
     * Reads are never gated either — an inspection always tells the truth.
     */
    public static function alerts_enabled(): bool
    {
        if (!self::env_flag(getenv('WPMS_JOB_ALERTS'), true)) {
            return false; // the operator asked for a quiet feed
        }
        // The suite's switch is the opposite polarity: it is ON to mean
        // "sampled quiet", and absent (the production case) means alerts run.
        return !self::env_flag(getenv('WPMS_LOGIN_THROTTLE_OFF'), false);
    }

    /**
     * Read a boolean-looking environment flag.
     *
     * @param string|false $raw     getenv()'s answer: false = the variable is not set
     * @param bool         $default what an absent or empty variable means
     */
    private static function env_flag($raw, bool $default): bool
    {
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default; // an unreadable value is not a reason to change behaviour
    }

    /** The human label for a job key. */
    public static function label(string $job): string
    {
        return self::JOBS[$job]['label'] ?? ($job === self::AUDIT ? 'Watchdog' : $job);
    }

    /** A job key this watchdog judges (or the watchdog's own row). */
    private static function knownJob(string $job): string
    {
        $job = trim($job);
        if (!isset(self::JOBS[$job]) && $job !== self::AUDIT) {
            throw new InvalidArgumentException(
                "JobWatch: '" . $job . "' is not a job this watchdog judges — pass JobWatch::MAIL_RETRY, "
                . 'JobWatch::NOTIFY_PRUNE or JobWatch::RENEWALS'
            );
        }
        return $job;
    }

    /** A status a job may report. */
    private static function knownStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, [self::OK, self::NOOP, self::FAILED], true)) {
            throw new InvalidArgumentException(
                "JobWatch: '" . $status . "' is not a status — pass JobWatch::OK, JobWatch::NOOP or JobWatch::FAILED"
            );
        }
        return $status;
    }

    /** Column-width clamp for anything a job wrote. */
    private static function trim(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, self::SUMMARY_MAX);
    }

    /** "4 h 12 m", "3 d 2 h", "6 m", "45 s" — how a human reads a gap. */
    public static function human_age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) {
            return $d . ' d' . ($h > 0 ? ' ' . $h . ' h' : '');
        }
        if ($h > 0) {
            return $h . ' h' . ($m > 0 ? ' ' . $m . ' m' : '');
        }
        if ($m > 0) {
            return $m . ' m';
        }
        return $seconds . ' s';
    }
}
