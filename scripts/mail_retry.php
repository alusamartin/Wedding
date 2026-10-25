<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Cron-safe retry sweeper for the notification email outbox.
 *
 * Rows written to tbl_mail_logs while WPMS_MAIL_ENABLED was off (or while
 * the MTA was down) sit as 'pending'. This script re-attempts them through
 * the same opt-in transport as Notification::create_for_staff():
 *
 *   - With WPMS_MAIL_ENABLED=1: sends up to --limit pending rows (oldest
 *     first), incrementing attempts; a row that fails on its final allowed
 *     attempt is marked 'abandoned' so it can never loop forever.
 *   - Without the flag: a deliberate no-op (exit 0) — safe to leave in the
 *     crontab across environments.
 *
 * Usage:
 *   php scripts/mail_retry.php [--limit=50] [--max-attempts=5] [--quiet]
 *
 * Exit codes: 0 = ran (or no-op), 1 = usage/lock error, 2 = transport off
 * when --strict is passed.
 *
 * Cron example (every 15 minutes):
 *   *\/15 * * * * php /path/to/scripts/mail_retry.php --quiet
 */

$opts = getopt('', ['limit::', 'max-attempts::', 'quiet', 'strict']) ?: [];
$quiet  = isset($opts['quiet']);
$strict = isset($opts['strict']);
$limit  = max(1, (int) ($opts['limit'] ?? 50));
$cap    = max(1, (int) ($opts['max-attempts'] ?? 5));

// Bootstrap exactly like the test harness does (config → Database → model).
$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';
require_once $root . '/admin/include/db_object.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php'; // the heartbeat this job leaves (v2.15)
global $db;
$db = new Database();

// Single-flight lock: overlapping cron runs would double-send.
$lockFile = sys_get_temp_dir() . '/wpms_mail_retry.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another mail_retry run holds the lock — exiting\n";
    }
    exit(1);
}

$transportOn = getenv('WPMS_MAIL_ENABLED') === '1';
if (!$transportOn) {
    if ($strict) {
        if (!$quiet) {
            echo "WPMS_MAIL_ENABLED is not set — pending rows left untouched\n";
        }
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::NOOP, 0, 'WPMS_MAIL_ENABLED is not set — pending rows left untouched', 2);
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(2);
    }
    if (!$quiet) {
        echo "WPMS_MAIL_ENABLED is not set — nothing to do (pending rows preserved)\n";
    }
    // 'noop', not 'ok': the transport is off, so a queue that sits is a
    // configuration fact, not this job having nothing to show for itself.
    JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::NOOP, 0, 'WPMS_MAIL_ENABLED is not set — pending rows preserved');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

// The queue's depth, asked of the model that owns the selection — its own
// predicate, so what this run counts as queued is exactly what it will pick up.
$pending = Notification::pending_mail_count($cap);
if ($pending === 0) {
    if (!$quiet) {
        echo "no pending mail rows below the attempt cap ({$cap})\n";
    }
    // queue 0: the sweeper looked at its own queue and there was nothing in it.
    JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 0, "no pending mail rows below the attempt cap ({$cap})", 0, null, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$r = Notification::retry_pending($limit, $cap);
$summary = "pending queued: {$pending}, attempted: {$r['attempted']}, sent: {$r['sent']}, "
    . "failed: {$r['failed']}, abandoned: {$r['abandoned']}";
if (!$quiet) {
    echo $summary . "\n";
}

/*
 * The heartbeat the watchdog judges: rows this run actually acted on
 * (attempted) against the queue its own selection offered it ($pending, which
 * counts only rows below the attempt cap — a finished row is not waiting). A run
 * that attempts nothing while rows wait is the incident the operator is told
 * about. A run that attempted rows and delivered NONE of them is reported as a
 * failure rather than a success: the job exits 0 because it did its work, but
 * 'nothing to show for its window' is exactly what happened, and the summary
 * carries the counts (that is the down-MTA case the watchdog must not call
 * healthy just because the process exited cleanly).
 */
$delivered = (int) $r['sent'] > 0;
JobWatch::record(
    JobWatch::MAIL_RETRY,
    $delivered || (int) $r['attempted'] === 0 ? JobWatch::OK : JobWatch::FAILED,
    (int) $r['attempted'],
    $summary,
    $delivered || (int) $r['attempted'] === 0 ? 0 : 1,
    null,
    $pending
);

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
