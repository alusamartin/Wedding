<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Cron sweep for Tier-3 MCP approval requests whose window closed unanswered.
 *
 * An assistant that wants to send 400 guests an SMS, record a payment, start an
 * M-Pesa prompt or assign a planner cannot do it on its own authority: it asks,
 * and a signed-in planner has THIRTY MINUTES to approve that exact payload
 * (McpApprovals, v2.17). When nobody answers, the row stops being usable — but
 * nothing used to SAY so. The planner never learned the message did not go out,
 * the assistant's retry minted a fresh reference as though the first had never
 * existed, and the call log had no line about it.
 *
 * This job closes that gap, on both sides:
 *
 *   - each unanswered request is marked once (`expired_at`, v2.19 — the UPDATE
 *     races on `expired_at IS NULL`, so re-runs are silent);
 *   - the ASSISTANT gets a tblapi_audit row per expiry: ok=0, the reference, the
 *     tool, the payload hash and "nothing was sent or recorded";
 *   - the PLANNER gets ONE digest notification (bell + email when mail is on)
 *     naming the references that died — never one alert per row.
 *
 * It writes nothing to a wedding, sends no SMS and spends no credits. The only
 * outbound message it can cause is the notification email the platform already
 * sends for every bell.
 *
 * Usage:
 *   php scripts/mcp_approval_sweep.php [--dry-run] [--limit=200] [--quiet]
 *
 * Exit codes:
 *   0 = ran (nothing left to close is a success, not a failure)
 *   1 = usage/lock error
 *   2 = the v2.17/v2.19 tables are not installed (deploy incomplete)
 *
 * Cron example (every 10 minutes, next to mail_retry — cPanel's
 * "common settings" offer this exact interval):
 *   php /path/to/scripts/mcp_approval_sweep.php --quiet
 */

$opts  = getopt('', ['dry-run', 'limit::', 'quiet']) ?: [];
$quiet = isset($opts['quiet']);
$dry   = isset($opts['dry-run']);
$limit = max(1, (int) ($opts['limit'] ?? 200));

// Bootstrap exactly like the other cron scripts (config → Database → models).
$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/Database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/ApiToken.php';
require_once $root . '/admin/include/McpApprovals.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php'; // the heartbeat this job leaves (v2.15)
global $db;
$db = new Database();

// Single-flight lock: the sweep is idempotent, but two overlapping runs would
// separate the closing of a row from the alert about it.
$lockFile = sys_get_temp_dir() . '/wpms_mcp_approval_sweep.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another mcp_approval_sweep run holds the lock — exiting\n";
    }
    exit(1);
}

if (!McpApprovals::available() || !McpApprovals::expiry_marker_installed()) {
    $missing = !McpApprovals::available()
        ? 'migrations/v2.17_mcp_approvals.sql'
        : 'migrations/v2.19_mcp_approval_expiry.sql';
    $summary = 'not run — ' . $missing . ' has not been applied';
    if (!$quiet) {
        echo $summary . "\n";
    }
    // NOOP, not FAILED: nothing is broken, the install is mid-upgrade. The
    // watchdog still sees that this job ran, and the summary names what is missing.
    JobWatch::record(JobWatch::MCP_APPROVALS, JobWatch::NOOP, 0, $summary, 0, null, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(2);
}

$report = McpApprovals::sweep_expired($dry, $limit);

$summary = ($dry ? '[dry run] ' : '')
    . "closed {$report['expired']} expired approval request(s) "
    . "({$report['unanswered']} never decided, {$report['unused']} approved but never used), "
    . "recorded {$report['audited']}, alerted {$report['notified']} planner(s); "
    . "queue was {$report['open']} past their window"
    . ($report['limited'] ? ' (limit reached — the next run continues)' : '');
if (!$quiet) {
    echo $summary . "\n";
    foreach ($report['planners'] as $staffId => $refs) {
        echo '  ' . ($staffId > 0 ? 'planner #' . $staffId : 'operators (no owner)')
            . ': ' . implode(', ', $refs) . "\n";
    }
}

/*
 * The heartbeat the watchdog judges: rows ACTUALLY closed, against the backlog
 * this run's window held. Zero closed with zero waiting is a quiet fortnight of
 * no Tier-3 requests; zero closed with a backlog is a sweep that stopped
 * sweeping — which is exactly what the operator is told about. A dry run closes
 * nothing, so it reports NOOP and leaves the heartbeat's item count honest.
 */
JobWatch::record(
    JobWatch::MCP_APPROVALS,
    $dry ? JobWatch::NOOP : JobWatch::OK,
    $dry ? 0 : (int) $report['expired'],
    mb_substr($summary, 0, JobWatch::SUMMARY_MAX),
    0,
    null,
    (int) $report['open']
);

flock($lock, LOCK_UN);
fclose($lock);
