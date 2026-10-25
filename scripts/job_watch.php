<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Cron-safe scheduled-job watchdog — the platform reports its OWN silence.
 *
 * mail_retry.php, notify_prune.php and subscription_renewals.php each print
 * what they did, for whoever runs them. When one of them stops running, the
 * only evidence is that nobody printed anything — which nobody notices. Each
 * job therefore leaves a heartbeat (include/JobWatch.php → record()), and this
 * script reads the heartbeats, judges each job against the schedule it is
 * deployed with, and TELLS THE OPERATOR when one of them is silent, failing,
 * or ran on time and handled nothing while its own queue had work waiting.
 *
 * It is also the watchdog's own cron entry:
 *
 *   *\/30 * * * * php /path/to/scripts/job_watch.php --quiet
 *
 * and the cron bridge runs it hourly (cron_bridge.php: job_watch), so the
 * scheduler that is supposed to run the jobs is also the thing that reports
 * them missing. Nothing here depends on the jobs' own transports: the report
 * is a database read, and the alert lands in the operator's bell whether or
 * not email is working.
 *
 * Usage:
 *   php scripts/job_watch.php [--quiet] [--json] [--no-alert]
 *
 * Exit codes:
 *   0 = every judged job reported in time and had something to show
 *   1 = at least one finding (the operator has been told, if --no-alert was
 *       not passed) — so a keyword/status pinger can watch THIS too
 *   2 = the heartbeat table is not installed, or the check could not read it
 *
 * See also: deploy_check.php (is the install wired up at all) and
 * admin/job_watch.php (the same report, for the operator, in the browser).
 */

$opts    = getopt('', ['quiet', 'json', 'no-alert']) ?: [];
$quiet   = isset($opts['quiet']);
$asJson  = isset($opts['json']);
$alert   = !isset($opts['no-alert']);

$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/Helper.php';
require_once $root . '/admin/include/Monetization.php';   // Subscription/MpesaStk — the renewal leg's queues
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php';
global $db;
$db = new Database();

// Single-flight lock: two overlapping audits could both pass the alert
// cooldown and tell the operator the same thing twice.
$lockFile = sys_get_temp_dir() . '/wpms_job_watch.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another job_watch run holds the lock — exiting\n";
    }
    exit(1);
}

$result   = JobWatch::audit($alert);
$report   = $result['report'];
$findings = $result['findings'];

if (!$report['installed']) {
    if (!$quiet && !$asJson) {
        echo "tbljob_heartbeats is not installed — apply migrations/v2.15_job_heartbeats.sql\n";
        echo "the scheduled jobs keep running; only their heartbeat is missing\n";
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(2);
}

if ($asJson) {
    echo json_encode([
        'checked_at'  => $report['checked_at'],
        'findings'    => array_values($findings),
        'alerted'     => $result['alerted'],
        'jobs'        => array_values($report['jobs']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($quiet) {
    // One line per finding: the bridge reports the first 160 characters of a
    // job's output, so the reason must be the whole output.
    foreach ($findings as $f) {
        echo '!! ' . $f['message'] . "\n";
    }
} else {
    $rows = [];
    foreach ($report['jobs'] as $e) {
        $queued = JobWatch::queued($e); // null = this job reports no queue at all
        $rows[] = sprintf(
            "%-22s %-7s %-10s %8d %8s  %s",
            $e['job'],
            $e['state'],
            $e['age'] === null ? 'never' : JobWatch::human_age((int) $e['age']) . ' ago',
            (int) $e['items'],
            $queued === null ? '—' : (string) $queued,
            $e['summary'] !== '' ? mb_substr($e['summary'], 0, 60) : '—'
        );
    }
    echo "Everafter Planner — scheduled-job watchdog\n";
    echo 'checked: ' . ($report['checked_at'] ?? 'never')
        . ($report['checked_age'] !== null ? ' (' . JobWatch::human_age((int) $report['checked_age']) . ' ago)' : '')
        . "\n\n";
    echo sprintf("%-22s %-7s %-10s %8s %8s  %s\n", 'job', 'state', 'last run', 'handled', 'waiting*', 'its own report');
    echo "* the queue its last run reported, counted by the job's own selection\n";
    echo implode("\n", $rows) . "\n";

    if ($findings === []) {
        echo "\n-- every judged job reported in time and had nothing waiting.\n";
    } else {
        echo "\n-- " . count($findings) . " finding(s):\n";
        foreach ($findings as $f) {
            echo '!! ' . $f['message'] . "\n";
        }
        if ($result['alerted'] > 0) {
            echo '   the operator was told (' . $result['alerted'] . ' alert(s) delivered; later checks stay quiet for '
                . JobWatch::human_age(JobWatch::RE_ALERT_AFTER) . ').' . "\n";
        } elseif (!$alert) {
            echo "   --no-alert: the operator was NOT told.\n";
        } else {
            echo '   already told within the last ' . JobWatch::human_age(JobWatch::RE_ALERT_AFTER) . " — not repeated.\n";
        }
    }
}

flock($lock, LOCK_UN);
fclose($lock);

// A finding is a failure of the platform's own promises, so it is a non-zero
// exit: the pinger that runs this (and the cron bridge) reports it as broken.
exit($findings === [] ? 0 : 1);
