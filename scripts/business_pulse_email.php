<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Cron-safe weekly business pulse — the owner's Monday brief by email.
 *
 * WHAT IT SENDS. One plain-text email per super admin, rendered by
 * BusinessPulse from the SAME aggregate calls the AI assistant reads
 * (`system_status` and `list_insights`, admin/include/OpsPulse.php): the week's
 * numbers, the findings that need attention, the five things worth fixing first,
 * and the thresholds those findings ran at. Each figure also carries its signed
 * change against the most recent snapshot taken BEFORE that week
 * (tblops_snapshots, v2.23): counts as +3, money as +95,000, rates as +2.1pp.
 * No model writes it, so no customer data leaves the platform and a scheduled
 * send needs no DPIA.
 *
 * WHY A DAILY CRON ENTRY FOR A WEEKLY EMAIL. The bridge throttles jobs by
 * interval, not by calendar; running this daily and deciding in code means a
 * missed Monday (server down, a pinger that skipped a beat) is caught on
 * Tuesday instead of skipping a whole week. The week stamp lives in the owner's
 * preferences (UserPrefs::PULSE_WEEK), so the email goes out once per ISO week
 * even if the job runs twenty times. A hard transport failure does NOT stamp, so
 * the next run retries.
 *
 * WHO IT REACHES. Every super admin with an address on file, each with their own
 * tuned thresholds and mutes. WPMS_PULSE_TO=a@x,b@y overrides the list for a
 * shared operations mailbox. A muted rule is left out of the findings (it is
 * still named in the brief's MUTED section) — muting is not hiding.
 *
 * TRANSPORT. The same opt-in mail() path as every other email in this
 * application, recorded in tbl_mail_logs. With WPMS_MAIL_ENABLED unset the row
 * is queued as 'pending' and scripts/mail_retry.php delivers it later; the week
 * is still stamped, because the brief has been handed to the outbox. That
 * acceptance is also the only moment this week's snapshot is recorded, so a
 * dry run, a preview or a failed send can never become next week's baseline.
 *
 * Usage:
 *   php scripts/business_pulse_email.php [--quiet] [--dry-run] [--force]
 *                                        [--to=owner@example.com] [--json]
 *
 *   --dry-run   render and print the brief any day; send nothing, stamp nothing
 *               (a preview is not the schedule, so it does not wait for Monday)
 *   --force     ignore the Monday check and this week's stamp (a manual resend)
 *   --to=…      send only to this address (implies --force, never stamps)
 *
 * Exit codes:
 *   0 = ran — sent/queued, nothing due, or a deliberate dry run
 *   1 = lock held, or at least one send failed
 *
 * Cron example (every day at 06:00 Nairobi; Mondays send):
 *   0 6 * * * php /path/to/scripts/business_pulse_email.php --quiet
 */

$opts   = getopt('', ['quiet', 'dry-run', 'force', 'to::', 'json']) ?: [];
$quiet  = isset($opts['quiet']);
$dryRun = isset($opts['dry-run']);
$force  = isset($opts['force']);
$asJson = isset($opts['json']);
$to     = isset($opts['to']) ? trim((string) $opts['to']) : '';

$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/Helper.php';
require_once $root . '/admin/include/Monetization.php';
require_once $root . '/admin/include/PackageCapacity.php'; // OpsPulse reads capacity positions
require_once $root . '/admin/include/UserPrefs.php';       // the per-owner week stamp
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php';        // the heartbeat this job leaves
require_once $root . '/admin/include/OpsPulse.php';        // the aggregate tools
require_once $root . '/admin/include/BusinessPulse.php';   // the renderer and the send
global $db;
$db = new Database();

// Single-flight lock: two overlapping runs could both miss the week stamp.
$lockFile = sys_get_temp_dir() . '/wpms_business_pulse.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another business_pulse_email run holds the lock — exiting\n";
    }
    exit(1);
}

$week = BusinessPulse::week_key();

/**
 * The recipients this run is allowed to write to. --to is a single explicit
 * address (a manual send), so it never reads the owner list and never stamps a
 * week: it is not the scheduled brief.
 */
if ($to !== '') {
    $recipients = filter_var($to, FILTER_VALIDATE_EMAIL)
        ? [['user_id' => 0, 'email' => $to, 'name' => '']]
        : [];
    if ($recipients === []) {
        if (!$quiet) {
            echo "--to is not a valid email address — nothing sent\n";
        }
        JobWatch::record(JobWatch::BUSINESS_PULSE, JobWatch::FAILED, 0, 'invalid --to address', 1, null, 0);
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(1);
    }
    $force = true;
} else {
    $recipients = BusinessPulse::recipients();
}

/*
 * A dry run is a PREVIEW, so it selects everybody regardless of the calendar or
 * this week's stamp — the whole point is looking at the brief before Monday. It
 * never writes a mail row and never stamps a week (see the send loop below).
 */
$manual = $force || $dryRun;
$due    = BusinessPulse::due_recipients($manual, null, $recipients);

// ------------------------------------------------------------------
// Nothing due — say exactly why, and leave an honest heartbeat
// ------------------------------------------------------------------
if ($due === []) {
    $why = !$force && !BusinessPulse::is_due()
        ? 'not Monday in Nairobi — the weekly brief is not due'
        : 'every owner already has the brief for ' . $week;
    if (!$quiet && !$asJson) {
        echo $why . "\n";
    }
    if ($asJson) {
        echo json_encode(['due' => 0, 'reason' => $why, 'week' => $week], JSON_PRETTY_PRINT) . "\n";
    }
    // A no-op here is deliberate: the calendar said not today, or the week was
    // already delivered. Both are 'ok' with an empty queue, never 'idle'.
    JobWatch::record(JobWatch::BUSINESS_PULSE, JobWatch::OK, 0, $why, 0, null, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

// ------------------------------------------------------------------
// Render once per recipient (their own thresholds), then send
// ------------------------------------------------------------------
$results = [];
$sent = $queued = $failed = 0;

foreach ($due as $recipient) {
    $userId = (int) $recipient['user_id'];

    if ($dryRun) {
        $brief = BusinessPulse::build($userId);
        $results[] = ['to' => $recipient['email'], 'status' => 'dry-run', 'subject' => $brief['subject']];
        if (!$quiet && !$asJson) {
            echo "[dry-run] " . $recipient['email'] . ' | ' . $brief['subject'] . "\n";
            echo $brief['text'];
        }
        continue;
    }

    $result = BusinessPulse::send($userId, (string) $recipient['email']);
    $results[] = [
        'to'      => $recipient['email'],
        'status'  => $result['status'],
        'ok'      => $result['ok'],
        'note'    => $result['note'],
        'subject' => $result['subject'],
    ];

    if ($result['status'] === 'sent') {
        $sent++;
    } elseif ($result['status'] === 'pending') {
        // Queued in tbl_mail_logs; mail_retry will deliver it.
        $queued++;
    } elseif (!$result['ok']) {
        $failed++;
        if (!$quiet) {
            echo 'FAILED ' . $recipient['email'] . ': ' . (string) $result['note'] . "\n";
        }
    }
}

if ($asJson) {
    echo json_encode([
        'week'    => $week,
        'due'     => count($due),
        'sent'    => $sent,
        'queued'  => $queued,
        'failed'  => $failed,
        'dry_run' => $dryRun,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} elseif (!$quiet && !$dryRun) {
    echo 'business pulse ' . $week . ': due ' . count($due) . ', sent ' . $sent
        . ', queued ' . $queued . ', failed ' . $failed
        . (getenv('WPMS_MAIL_ENABLED') === '1' ? '' : ' (transport off — queued rows are retried by mail_retry)') . "\n";
}

$summary = 'due ' . count($due) . ', sent ' . $sent . ', queued ' . $queued . ', failed ' . $failed
    . (getenv('WPMS_MAIL_ENABLED') === '1' ? '' : ' — transport off, queued');

/*
 * The heartbeat the watchdog judges. `items` is what this run actually handed
 * over (sent + queued), and `queue` is the owners this run selected before it
 * started — so a run that selected two owners and delivered none is the
 * 'nothing to show for its window' case, not a quiet success.
 *
 * A DRY RUN WRITES NO HEARTBEAT AT ALL. That is the rule JobWatch is built on
 * ("a dry run is not a run") and the one its own tests assert for the other
 * jobs: a heartbeat is a claim that the job ran, and `last_finished_at` is the
 * only thing the watchdog ages. Recording NOOP here made a crontab left on
 * `--dry-run` look permanently healthy — the exact silence the watcher exists
 * to report.
 */
$handed = $sent + $queued;
if (!$dryRun) {
    JobWatch::record(
        JobWatch::BUSINESS_PULSE,
        $failed > 0 ? JobWatch::FAILED : JobWatch::OK,
        $handed,
        mb_substr($summary, 0, JobWatch::SUMMARY_MAX),
        $failed > 0 ? 1 : 0,
        null,
        count($due)
    );
}

flock($lock, LOCK_UN);
fclose($lock);
exit($failed > 0 ? 1 : 0);
