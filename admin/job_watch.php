<?php include 'include/init.php'; ?>
<?php
/**
 * Job Health — the operator's view of the platform's own silence (v2.15).
 *
 * WHAT THIS PAGE IS FOR
 * The scheduled jobs keep promises to people who are not in the room:
 * mail_retry.php drains the email outbox, notify_prune.php keeps the alert
 * archive from growing forever, subscription_renewals.php prompts, duns and
 * nurtures planners whose plans are about to lapse, rsvp_reminders.php chases
 * invitations the couple is still waiting on, deadline_sms.php collects
 * balances before the wedding day rather than on it, and thread_escalation.php
 * (v2.26) tells a planner when a vendor has left their couple's conversation
 * unanswered for a day. Every one of them is
 * silent when it stops — a dead pinger, a renamed crontab entry, a lock left
 * behind — and the platform used to find out days later, from a planner asking
 * why an invoice email never arrived. Each job now leaves a heartbeat
 * (include/JobWatch.php → tbljob_heartbeats, migrations/v2.15) and this page
 * is where the operator reads them.
 *
 * WHO SEES IT
 * Super admins only (tblusers.access_level 2 — the SaaS operator), like Plan
 * Definitions and for the same reason: whether the platform's own machinery is
 * running is the operator's business, not a planner's. The plan an operator
 * sells and the sweep that keeps the platform honest are the same audience.
 *
 * THE PAGE ITSELF IS THE CHECK
 * Opening it runs JobWatch::audit(): every judged job is compared against the
 * schedule it is deployed with, the queue it is supposed to be draining is
 * counted, and any finding the operator has not already been told about (inside
 * the half-day cooldown) becomes a bell. That is deliberate rather than
 * decorative — a watchdog that only runs when the scheduler is healthy cannot
 * report that the scheduler is dead, and an operator opening this page is
 * exactly the moment the platform should notice. "Run the check now" does the
 * same thing explicitly, with the CSRF token every write in this application
 * carries, for an operator who wants to see the audit run.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * It does not run the jobs (cron_bridge.php and the crontab do that, and a
 * button here that sent 200 emails would be a foot-gun), and it does not
 * retune them: the schedule each job is judged against is the deployed one
 * (JobWatch::JOBS), stated on the page so a mismatch is visible rather than
 * guessed at.
 *
 * A database that has not run v2.15 says so plainly and nothing else breaks —
 * the jobs keep running, they simply have nowhere to leave a heartbeat.
 */
    require_staff_subscribed();

    if (!is_super_admin()) {
        redirect_to('dashboard.php');
        die();
    }

    // ---- Explicit re-check (POST + CSRF) ----------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check') {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Invalid security token.</div>');
            redirect_to('job_watch.php');
            die();
        }
        $run = JobWatch::audit(true);
        $n   = count($run['findings']);
        error_log('[WPMS][JobWatch] manual check by ' . (string) ($_SESSION['user_email'] ?? 'staff')
            . ' findings=' . $n . ' alerts=' . (int) $run['alerted']);
        $session->message($n === 0
            ? '<div class="alert alert-success"><i class="mdi mdi-check"></i> Every judged job reported in time with nothing waiting.</div>'
            : '<div class="alert alert-warning"><i class="mdi mdi-alert"></i> ' . $n . ' finding(s) — '
                . (int) $run['alerted'] . ' alert(s) sent. The table below says what each job did.</div>');
        redirect_to('job_watch.php');
        die();
    }

    /*
     * The page load IS the check (see the docblock). The audit is idempotent
     * and its alert path is deduped per (job, finding) for half a day, so
     * opening this page five times tells the operator once.
     */
    $run      = JobWatch::audit(true);
    $report   = $run['report'];
    $findings = $run['findings'];

    /** How a state reads to a human, and in which colour. */
    $stateBadge = static function (array $e): array {
        return match ($e['finding']) {
            JobWatch::F_SILENT => ['Silent', 'danger'],
            JobWatch::F_FAILED => ['Failing', 'danger'],
            JobWatch::F_IDLE   => ['Nothing to show', 'warning'],
            JobWatch::F_NEVER  => ['Never run', 'secondary'],
            default            => match ($e['state']) {
                'late' => ['Late', 'warning'],
                'noop' => ['Deliberate no-op', 'light'],
                default => ['Healthy', 'success'],
            },
        };
    };
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); // what include/sidebar.php chips the reader with ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Job Health - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #0F172A; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .stat-card.bad { border-left-color: var(--danger); }
        .stat-card.warn { border-left-color: var(--warning); }
        .stat-card.good { border-left-color: var(--success); }
        .job-table td, .job-table th { font-size: 12.5px; vertical-align: middle; }
        .job-report { color: #64748B; font-size: 11.5px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-timer-sand-complete" style="color:#0F172A"></i> Job Health</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                what the scheduled jobs last did, and whether the platform's own sweeps have gone quiet
                <?php if ($report['checked_age'] !== null): ?>
                    — last checked <?= htmlspecialchars(JobWatch::human_age((int) $report['checked_age'])); ?> ago
                <?php endif; ?>
            </p>
        </div>
        <form method="post" action="job_watch.php" class="mb-0">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="action" value="check">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="mdi mdi-refresh mr-1"></i> Run the check now
            </button>
        </form>
    </div>

    <?php if (!$report['installed']): ?>
        <div class="alert alert-warning">
            <strong><i class="mdi mdi-database-alert"></i> tbljob_heartbeats is not installed.</strong>
            Apply <code>migrations/v2.15_job_heartbeats.sql</code> to start recording what the scheduled jobs do.
            The jobs themselves keep running; they simply have nowhere to leave a heartbeat.
        </div>
    <?php else: ?>

        <div class="row">
            <div class="col-md-3">
                <div class="stat-card <?= $findings === [] ? 'good' : 'bad' ?>">
                    <h6>Findings</h6>
                    <div class="value"><?= count($findings); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card <?= (int) $run['alerted'] === 0 ? '' : 'warn' ?>">
                    <h6>Alerts sent now</h6>
                    <div class="value"><?= (int) $run['alerted']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <h6>Jobs judged</h6>
                    <div class="value"><?= count($report['jobs']); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <h6>Waiting work</h6>
                    <div class="value">
                        <?php $waiting = 0; foreach ($report['jobs'] as $e) { $waiting += (int) $e['waiting']; } echo (int) $waiting; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($findings !== []): ?>
            <div class="alert alert-warning">
                <strong><i class="mdi mdi-alert"></i> <?= count($findings); ?> job(s) need attention:</strong>
                <ul class="mb-0 mt-2" style="font-size:13px;">
                    <?php foreach ($findings as $f): ?>
                        <li><?= htmlspecialchars((string) $f['message']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="text-muted mt-2" style="font-size:12px;">
                    Each finding is told to the operator once per half-day, so a job that stays down does not fill the bell.
                    A job that recovers forgets its alert, and the next silence is news again.
                </div>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered job-table bg-white">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>State</th>
                        <th>Last run</th>
                        <th class="text-right">Handled</th>
                        <th class="text-right">Waiting<br><span class="job-report">(at its last run)</span></th>
                        <th>Its own report</th>
                        <th>Schedule</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($report['jobs'] as $e): ?>
                    <?php [$stateLabel, $stateClass] = $stateBadge($e); ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) $e['label']); ?></strong>
                            <div class="job-report"><code><?= htmlspecialchars((string) $e['job']); ?></code></div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $stateClass; ?>"><?= htmlspecialchars($stateLabel); ?></span>
                            <?php if ((int) $e['fail_streak'] > 1): ?>
                                <div class="job-report"><?= (int) $e['fail_streak']; ?> runs in a row failed</div>
                            <?php endif; ?>
                            <?php if ($e['alerted_age'] !== null): ?>
                                <div class="job-report">operator told <?= htmlspecialchars(JobWatch::human_age((int) $e['alerted_age'])); ?> ago
                                    (<?= htmlspecialchars((string) $e['alert_reason']); ?>)</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $e['age'] === null ? 'never' : htmlspecialchars(JobWatch::human_age((int) $e['age'])) . ' ago'; ?>
                            <?php if ((int) $e['runs'] > 0): ?>
                                <div class="job-report"><?= (int) $e['runs']; ?> heartbeat(s)</div>
                                <div class="job-report">exit <?= (int) $e['exit_code']; ?> · <?= htmlspecialchars((string) $e['status']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= (int) $e['items']; ?></td>
                        <td class="text-right">
                            <?php $queued = JobWatch::queued($e); ?>
                            <?php if ($queued === null): ?>
                                <span class="text-muted" title="this job does not report a queue">—</span>
                            <?php elseif ($queued > 0): ?>
                                <span class="badge badge-warning"><?= $queued; ?></span>
                                <div class="job-report"><?= htmlspecialchars((string) $e['waiting_text']); ?></div>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="job-report"><?= htmlspecialchars((string) $e['summary']); ?></td>
                        <td class="job-report">
                            every <?= htmlspecialchars(JobWatch::human_age((int) $e['every'])); ?>,
                            tolerated <?= htmlspecialchars(JobWatch::human_age((int) $e['grace'])); ?> late
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>            <p class="text-muted" style="font-size:12px;">
                A heartbeat is written by the JOB, so it is the same fact whatever started it — the cron bridge, a
                crontab entry or a manual run — and the queue beside it is the work that run's own window held,
                counted by the job's own selection before its <code>--limit</code> could stop it. <strong>Never run</strong> means
                no run has ever been recorded on this install (usually cron is not wired up yet) and is reported but
                never alerted; <strong>silent</strong> means a job that HAS run has stopped; <strong>failing</strong> is a run that died
                or delivered nothing at all; <strong>nothing to show</strong> is a run that reported success while the queue it
                reported held work. <code>php scripts/job_watch.php</code> prints the same report on the command line and
                exits non-zero when there is a finding, so a pinger can watch it — the cron bridge runs it hourly as
                <code>job_watch</code> and turns this into HTTP 500.
            </p>
    <?php endif; ?>
</div>

<?php include 'include/footer.php'; ?>
</body>
</html>
