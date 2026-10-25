<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Cron-safe RSVP reminder dispatcher.
 *
 * Finds guests who have not replied to their invitation (rsvp_status still
 * 'pending') on CONFIRMED bookings and sends them a polite Kenyan SMS with
 * their personal RSVP link. Timing is INVITE-RELATIVE: the clock starts at
 * the guest's invite (rsvp_invited_at — stamped when their RSVP token was
 * minted or the guest list was marked invited), NOT at the wedding date,
 * so early planners get early reminders and late invitees get a fair
 * countdown of their own. Weddings already past (beyond a grace window)
 * are never chased.
 *
 * Delivery rides the existing SMS::send() pipeline, so every message lands
 * in the tblsms_logs outbox with status pending/sent/failed and credits
 * are charged to the assigned organizer — exactly like a send from the
 * SMS center.
 *
 * Scheduling rules (all configurable via options):
 *   --after=7        first reminder N days after the invite (default 7)
 *   --resend=7       re-remind at most every N days (default 7)
 *   --max=3          lifetime cap of reminders per guest (default 3)
 *   --grace=7        stop reminding N days after the wedding date
 *   --limit=100      max SMS sent per run
 *   --dry-run        print what would be sent; write nothing
 *
 * Every send is recorded on the guest (rsvp_reminded_at + rsvp_reminders)
 * ONLY after SMS::send() reports success — a credits failure or gateway
 * error leaves the guest untouched so the next run retries naturally.
 * The planner (organizer) also gets one in-app notification per run when
 * reminders go out on their booking.
 *
 * Exit codes: 0 = ran (possibly zero sends), 1 = usage/lock error.
 *
 * Cron example (daily at 09:00 Nairobi time):
 *   0 9 * * * php /path/to/scripts/rsvp_reminders.php --quiet
 */

$opts = getopt('', ['after::', 'resend::', 'max::', 'grace::', 'limit::', 'quiet', 'dry-run']) ?: [];
$quiet   = isset($opts['quiet']);
$dryRun  = isset($opts['dry-run']);
$after   = max(0, (int) ($opts['after'] ?? 7));
$resend  = max(1, (int) ($opts['resend'] ?? 7));
$maxR    = max(1, (int) ($opts['max'] ?? 3));
$grace   = max(0, (int) ($opts['grace'] ?? 7));
$limit   = max(1, (int) ($opts['limit'] ?? 100));

$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/Helper.php';
require_once $root . '/admin/include/Guest.php';
require_once $root . '/admin/include/Monetization.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php'; // the heartbeat this job leaves (v2.15)
global $db;
$db = new Database();

// Single-flight lock: overlapping cron runs would double-send.
$lockFile = sys_get_temp_dir() . '/wpms_rsvp_reminders.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another rsvp_reminders run holds the lock — exiting\n";
    }
    exit(1);
}

$due = Guest::find_reminder_due($after, $resend, $maxR, $grace);

if ($due === []) {
    if (!$quiet) {
        echo "no pending guests are due a reminder "
            . "(invited {$after}d+ ago, resend={$resend}d, cap={$maxR}, grace={$grace}d)\n";
    }
    if (!$dryRun) {
        JobWatch::record(JobWatch::RSVP_REMINDERS, JobWatch::OK, 0, 'no pending guests due a reminder', 0, null, 0);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

// Group by booking so the couple name is resolved once and the planner
// notification is one per booking per run, not one per guest.
$byBooking = [];
foreach ($due as $g) {
    $byBooking[(int) $g->booking_id][] = $g;
}

$sent = 0;
$failed = 0;
$skippedNoPhone = 0;
$plannersNotified = [];

foreach ($byBooking as $bookingId => $guests) {
    if ($sent >= $limit) {
        break; // defer the rest to the next run
    }
    $sentForBooking = 0;

    $first = $guests[0];
    $coupleName = trim(($first->firstname ?? '') . ' ' . ($first->lastname ?? ''));
    if ($coupleName === '') {
        $coupleName = trim(($first->bride ?? '') . ' & ' . ($first->groom ?? ''));
    }
    $weddingHuman = format_date_kd($first->wedding_date);

    foreach ($guests as $g) {
        if ($sent >= $limit) {
            break;
        }

        $rsvpLink = rsvp_url_for($g->rsvp_token);
        $phone = trim((string) $g->phone);
        if ($phone === '' || $rsvpLink === '') {
            $skippedNoPhone++;
            if (!$dryRun) {
                // No number to reach: still count the attempt so a phoneless
                // guest cannot spin the cron every day forever.
                Guest::mark_reminded((int) $g->id);
            }
            continue;
        }

        $message = "Habari {$g->fullname}! You are warmly invited to the wedding of "
            . "{$coupleName} on {$weddingHuman}. Kindly confirm attendance: {$rsvpLink} "
            . "- Everafter Planner";

        if ($dryRun) {
            if (!$quiet) {
                echo "[dry-run] guest #{$g->id} {$g->fullname} ({$phone}) | booking {$bookingId}\n";
            }
            $sent++;
            continue;
        }

        // SMS::send writes the outbox row (pending -> sent/failed) and charges
        // the organizer's credits; dev mode (no API key) logs without sending.
        $r = SMS::send($phone, $message, (int) ($first->organizer_id ?? 0), $bookingId);
        if (!empty($r['success'])) {
            Guest::mark_reminded((int) $g->id);
            $sent++;
            $sentForBooking++;
            if (!$quiet) {
                echo "reminded guest #{$g->id} {$g->fullname} ({$phone}) | booking {$bookingId}\n";
            }
        } else {
            $failed++;
            if (!$quiet) {
                echo "FAILED guest #{$g->id} {$g->fullname}: " . ($r['error'] ?? 'unknown error') . "\n";
            }
        }
    }

    // One planner notification per booking per run (in-app bell + mail
    // outbox leg via Notification::create_for_staff()) — counts real sends only.
    if (!$dryRun && $sentForBooking > 0 && !empty($first->organizer_id)) {
        $organizerId = (int) $first->organizer_id;
        Notification::create_for_staff(
            $organizerId,
            $bookingId,
            "RSVP reminders sent to {$sentForBooking} guest"
            . ($sentForBooking === 1 ? '' : 's')
            . " of {$coupleName} (wedding {$weddingHuman})",
            "rsvp_dashboard.php?booking_id={$bookingId}&user_id={$first->couple_user_id}",
            'RSVP reminders dispatched'
        );
        $plannersNotified[] = $organizerId;
    }
}

$summary = 'due: ' . count($due)
    . ", sent: {$sent}, failed: {$failed}, skipped(no phone): {$skippedNoPhone}";
if (!$quiet) {
    echo $summary . ($dryRun ? ' (DRY RUN — nothing written)' : '') . "\n";
}

/*
 * The heartbeat the watchdog judges (v2.15). Only a REAL run leaves one: a dry
 * run wrote nothing, so it must not make a silent job look alive. The queue is
 * Guest::find_reminder_due()'s own selection — guests still pending an answer
 * who have reached their reminder window — counted BEFORE the --limit broke the
 * loop, so a run that stops at its cap still says how much waits behind it. A
 * guest with no number counts as ACTED ON (the run marked the attempt so they
 * cannot spin the cron forever), which is why it is in the items count and not
 * in the queue. Every send failing while others were due is a failure to report,
 * not a clean run: the process exited 0, the guests got nothing.
 */
if (!$dryRun) {
    JobWatch::record(
        JobWatch::RSVP_REMINDERS,
        ($failed > 0 && $sent === 0) ? JobWatch::FAILED : JobWatch::OK,
        $sent + $failed + $skippedNoPhone,
        $summary,
        ($failed > 0 && $sent === 0) ? 1 : 0,
        null,
        count($due)
    );
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
