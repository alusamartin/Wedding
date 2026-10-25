<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/*
 * ONE clock — the same one the pages use.
 *
 * admin/include/init.php sets Africa/Nairobi for every web request, and
 * Database connects with `time_zone = '+03:00'`, so a date read from the
 * database and "now" agree there. A cron script inherits nothing but php.ini,
 * which on this host is a different zone: a message two days old would be
 * announced as "waiting 1 day", and the alert would contradict the ledger row
 * it links to. Set the clock this script reasons with before it reasons.
 */
date_default_timezone_set('Africa/Nairobi');

/**
 * Cron sweep for conversations the vendor has left unanswered.
 *
 * v2.25 put the couple, the vendor and the planner on one thread per deal and
 * made waiting VISIBLE: the ledger row says "1 new, waiting 3 h", the queue is
 * served longest-waiting first, and the sidebar badge counts what nobody has
 * opened. None of that leaves the page. A couple's question to their caterer
 * can sit for a week, and the only person who finds out is the couple —
 * shopping elsewhere, which is the whole reason convenience is the product.
 *
 * This job closes that gap. Once a day it looks for deals whose NEWEST message
 * is not from the vendor and is at least `--hours` old (24 by default), and
 * rings the couple's planner (bell + email, the platform's normal alert) with
 * one sentence: who has waited, how long, on whom. The planner is the person
 * who can pick up the phone; the couple should not have to chase.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *   - It does not message the couple, the vendor's guests or the vendor. The
 *     couple must never be told "your vendor is ignoring you" by a robot, and
 *     the vendor's nudge is the planner's call, not the scheduler's.
 *   - It does not read state as the trigger. `read_vendor_at` says the vendor
 *     opened the page; the question is still unanswered, which is the fact
 *     that matters.
 *   - It does not chase a settled deal. Completed and cancelled deals, and
 *     weddings already held, are skipped — a reply cannot help them.
 *   - It does not repeat itself. The message it alerted about is stamped
 *     (`tblvendor_messages.escalated_at`, v2.26) before the bell goes out, so a
 *     re-run, a second pinger or an overlapping cron is silent. Once the vendor
 *     replies, a later message starts a new silence and gets its own alert in
 *     turn — the alert follows the unanswered MESSAGE, not the deal.
 *
 * Usage:
 *   php scripts/thread_escalation.php [--hours=24] [--limit=100] [--dry-run] [--quiet]
 *
 * Exit codes:
 *   0 = ran (nothing waiting is a success, not a failure)
 *   1 = usage/lock error
 *   2 = v2.25/v2.26 not installed (deploy incomplete — reported as NOOP)
 *
 * Cron example (daily at 08:30 Nairobi time, before the working day starts):
 *   30 8 * * * php /path/to/scripts/thread_escalation.php --quiet
 */

$opts  = getopt('', ['hours::', 'limit::', 'dry-run', 'quiet']) ?: [];
$quiet = isset($opts['quiet']);
$dry   = isset($opts['dry-run']);
$limit = max(1, (int) ($opts['limit'] ?? 100));

// Bootstrap exactly like the other cron scripts (config → Database → models).
$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/Helper.php';
require_once $root . '/admin/include/VendorThread.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php'; // the heartbeat this job leaves (v2.15)
global $db;
$db = new Database();

// The grace in hours is the class's own default, so the script and the ledgers
// can never disagree about what "waiting too long" means.
$hours = max(1, (int) ($opts['hours'] ?? VendorThread::REPLY_GRACE_HOURS));

// Single-flight lock: claiming a message and ringing about it must not interleave
// with a second run doing the same.
$lockFile = sys_get_temp_dir() . '/wpms_thread_escalation.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another thread_escalation run holds the lock — exiting\n";
    }
    exit(1);
}

if (!VendorThread::installed() || !VendorThread::escalation_installed()) {
    $missing = !VendorThread::installed()
        ? 'migrations/v2.25_vendor_threads.sql'
        : 'migrations/v2.26_thread_escalation.sql';
    $summary = 'not run — ' . $missing . ' has not been applied';
    if (!$quiet) {
        echo $summary . "\n";
    }
    // NOOP, not FAILED: nothing is broken, the install is mid-upgrade.
    JobWatch::record(JobWatch::THREAD_ESCALATION, JobWatch::NOOP, 0, $summary, 0, null, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(2);
}

$queue   = VendorThread::stale_deals($hours, $limit);
$alerted = 0;
$failed  = 0;

if ($queue === []) {
    $summary = ($dry ? '[dry run] ' : '') . "no conversation has been unanswered for {$hours} h";
    if (!$quiet) {
        echo $summary . "\n";
    }
    if (!$dry) {
        JobWatch::record(JobWatch::THREAD_ESCALATION, JobWatch::OK, 0, $summary, 0, null, 0);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

foreach ($queue as $row) {
    $dealId    = (int) $row->deal_id;
    $plannerId = (int) $row->organizer_id;
    $message   = VendorThread::escalation_message($row);
    $link      = 'vendor_thread_open.php?deal_id=' . $dealId;

    if ($dry) {
        if (!$quiet) {
            echo "[dry run] deal #{$dealId} → planner #{$plannerId}: {$message}\n";
        }
        continue;
    }

    /*
     * Claim first, ring second. A message that is already stamped has been
     * reported by another run (or another pinger) and must stay silent here.
     */
    if (!VendorThread::mark_escalated((int) $row->message_id)) {
        continue;
    }

    try {
        Notification::create_for_staff($plannerId, (int) $row->booking_id, $message, $link);
        $alerted++;
        if (!$quiet) {
            echo "deal #{$dealId} → planner #{$plannerId}: {$message}\n";
        }
    } catch (Throwable $e) {
        // The stamp stays: re-ringing every day about the same message would be
        // worse than one lost bell, and the row is still in the planner's queue.
        $failed++;
        error_log('[WPMS][thread_escalation] deal ' . $dealId . ' could not alert planner ' . $plannerId . ': ' . $e->getMessage());
    }
}

$summary = ($dry ? '[dry run] ' : '')
    . "alerted {$alerted} planner(s) about conversations unanswered for {$hours} h"
    . ($failed > 0 ? ", {$failed} alert(s) failed" : '')
    . "; queue was " . count($queue) . ' waiting'
    . (count($queue) >= $limit ? ' (limit reached — the next run continues)' : '');

if (!$quiet) {
    echo $summary . "\n";
}

/*
 * The heartbeat the watchdog judges: alerts ACTUALLY sent, against the backlog
 * this run's window held.
 *
 * A DRY RUN WRITES NO HEARTBEAT AT ALL — the same rule, and the same reason, as
 * the empty-queue branch above: `last_finished_at` is the only thing the
 * watchdog ages, so a NOOP row from a `--dry-run` crontab would make a planner
 * whose conversations never escalate look like a healthy job forever.
 */
if (!$dry) {
    JobWatch::record(
        JobWatch::THREAD_ESCALATION,
        ($failed > 0 && $alerted === 0) ? JobWatch::FAILED : JobWatch::OK,
        $alerted,
        mb_substr($summary, 0, JobWatch::SUMMARY_MAX),
        ($failed > 0 && $alerted === 0) ? 1 : 0,
        null,
        count($queue)
    );
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
