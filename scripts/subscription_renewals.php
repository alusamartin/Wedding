<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Cron-safe subscription auto-renewal + SMS dunning + trial nurture.
 *
 * Three legs per run, all idempotent:
 *
 * LEG 1 — AUTO-RENEW (WPMS_SUB_AUTO_RENEW=1)
 *   For every staff member whose NEWEST subscription row is an active
 *   auto-renew row inside the renewal window (expiry within
 *   WPMS_SUB_RENEW_DAYS days, default 3):
 *     1. clone the row as status='pending_renewal' (the promise),
 *     2. MpesaStk::initiate() the plan price to the staff phone
 *        (tblusers.phone — the promise keeps the plan + amount),
 *     3. if the phone is missing or the initiate fails, delete the promise
 *        and drop a bell notification instead — nothing is silently lost.
 *   The Daraja result lands asynchronously on mpesa_stk_callback.php;
 *   MpesaStk::apply_callback() fulfils it by extend_renewal() — the SAME
 *   subscription's expiry moves +30 days from its own expiry (never from
 *   today), and the promise flips back to active/auto_renew=1. No double
 *   rows, no double charges: a clone is created only when none is pending,
 *   and sweep_pending_renewals() clears dead ones.
 *
 * LEG 2 — SMS DUNNING (WPMS_SUB_GRACE=1)
 *   Newest-row-per-user, never history rows:
 *     - active inside a band (7/3/0 days before expiry): one text per band
 *       ever (marker-first: renewal_sms_band stores the smallest band
 *       texted; a band fires only when the current one is smaller — the
 *       proven pay_band_sms_sent semantics).
 *     - lapsed inside the grace window (day 3 of grace): the grace text.
 *   Every text ALSO drops a bell notification — the SMS is never the only
 *   channel. Auto-renew-off planners are reminded too (they must act
 *   manually); a pending promise suppresses texts (the STK leg owns that
 *   user this run).
 *
 * LEG 3 — TRIAL NURTURE (WPMS_TRIAL_NURTURE=1)
 *   Newest-row-per-user, trial rows only (converted planners never appear —
 *   their newest row is paid):
 *     - check-in email at the halfway point of the trial (90 days left for
 *       the default six-month trial), with getting-started tips,
 *     - last-call email 1 day before expiry.
 *   Emails ride the shared tbl_mail_logs outbox (pending/retryable when
 *   WPMS_MAIL_ENABLED is off); the band marker trial_nurture_band is
 *   stamped FIRST so a crash can never double-email. Lapsed trials are
 *   skipped — the nurture leg never emails an already-ended trial.
 *
 * Usage:
 *   php scripts/subscription_renewals.php [--limit=50] [--quiet] [--dry-run]
 *
 * Exit codes: 0 = ran (possibly zero actions), 1 = usage/lock error.
 *
 * Cron example (daily at 08:30 Nairobi time):
 *   30 8 * * * php /path/to/scripts/subscription_renewals.php --quiet
 */

$opts   = getopt('', ['limit::', 'quiet', 'dry-run']) ?: [];
$quiet  = isset($opts['quiet']);
$dryRun = isset($opts['dry-run']);
$limit  = max(1, (int) ($opts['limit'] ?? 50));

$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/Helper.php';
require_once $root . '/admin/include/Monetization.php';
require_once $root . '/admin/include/SignupGuard.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
require_once $root . '/admin/include/JobWatch.php'; // the heartbeat this job leaves (v2.15)
global $db;
$db = new Database();

// Single-flight lock (same pattern as deadline_sms.php).
$lockFile = sys_get_temp_dir() . '/wpms_subscription_renewals.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another subscription_renewals run holds the lock — exiting\n";
    }
    exit(1);
}

$renewEnabled = Subscription::auto_renew_enabled();
$graceEnabled = Subscription::grace_enabled();
$nurtureEnabled = Subscription::trial_nurture_enabled();
$renewWindow  = max(1, (int) (getenv('WPMS_SUB_RENEW_DAYS') ?: 3));

/*
 * WHAT EACH LEG HAD IN ITS WINDOW (v2.15) — the queue the heartbeat reports.
 * Counted from the leg's OWN selection, at the point the run commits to acting,
 * and BEFORE the --limit can break the loop, so a run that stops at its cap still
 * says how much waits behind it. A leg that is switched off contributes nothing:
 * the job cannot be idle over work it is not configured to do, and an
 * already-texted band or a promised renewal is not waiting either (which is why
 * the dunning and nurture counts happen after the marker checks below).
 */
$renewDue = 0;
$dunningDue = 0;
$nurtureDue = 0;

// ------------------------------------------------------------------
// LEG 1 — auto-renew STK prompts
// ------------------------------------------------------------------
$pushed = 0;
$pushFailed = 0;
$pushSkippedNoPhone = 0;

if ($renewEnabled && !MpesaStk::enabled()) {
    // Nothing we can initiate without the transport; the dunning leg below
    // still reminds planners to pay manually.
    if (!$quiet) {
        echo "auto-renew is on but the STK transport is off (set WPMS_MPESA_STK=1 + Daraja credentials) — reminders only\n";
    }
}

if ($renewEnabled && MpesaStk::enabled() && !$dryRun) {
    $candidates = Subscription::auto_renew_candidates($renewWindow);
    $renewDue   = count($candidates); // every candidate is a renewal owed today
    foreach ($candidates as $row) {
        if ($pushed + $pushFailed >= $limit) {
            break; // defer the rest to the next run
        }
        $uid  = (int) $row->user_id;
        $plan = (string) $row->plan;
        $phone = Subscription::staff_phone($uid);
        if ($phone === '') {
            $pushSkippedNoPhone++;
            Notification::create_for_staff(
                $uid,
                0,
                'Auto-renewal could not run: no M-Pesa number on file. '
                    . 'Add your number on the subscription page so the renewal prompt can reach you.',
                'subscription.php',
                'Auto-renewal needs your M-Pesa number'
            );
            continue;
        }

        // Promise first (crash-safe: the clone records what was attempted),
        // prompt second. A failed prompt removes the promise again.
        $clone = Subscription::create_renewal_clone($uid, $plan, (float) $row->amount);
        if ($clone === null) {
            $pushFailed++;
            continue;
        }
        $init = MpesaStk::initiate(
            'subscription',
            $uid,
            $phone,
            (float) $row->amount,
            ['plan' => $plan]
        );
        if (!$init['ok']) {
            // Remove the dead promise so the next run can try again cleanly.
            global $db;
            $db->execute(
                "DELETE FROM tblsubscriptions WHERE id = :i AND status = 'pending_renewal'",
                [':i' => (int) $clone->id]
            );
            $pushFailed++;
            Notification::create_for_staff(
                $uid,
                0,
                'Auto-renewal prompt failed: ' . (string) ($init['error'] ?? 'unknown error')
                    . '. Please renew manually on the subscription page.',
                'subscription.php',
                'Auto-renewal prompt failed'
            );
            if (!$quiet) {
                echo "FAILED renewal prompt user {$uid} ({$plan}): " . (string) ($init['error'] ?? '?') . "\n";
            }
            continue;
        }
        $pushed++;
        if (!$quiet) {
            echo "renewal prompt sent user {$uid} ({$plan}, " . format_ksh((float) $row->amount)
                . ") → push #{$init['push']->id}\n";
        }
    }

    // Sweep dead promises (timed-out prompts, manual payers, opt-outs).
    $swept = Subscription::sweep_pending_renewals();
    if (!$quiet && $swept > 0) {
        echo "swept {$swept} dead renewal promise(s)\n";
    }
}

// ------------------------------------------------------------------
// LEG 2 — SMS dunning (7/3/0 before expiry, day 3 of grace)
// ------------------------------------------------------------------
$bands = [7, 3, 0];
$texted = 0;
$textFailed = 0;

/*
 * The dunning queue is counted in its own pass, because the marker check sits
 * inside the loop below (right after the state branches) and a band already
 * texted is not waiting. One rule, stated once here and once there: a target is
 * due when its marker has not reached the band it is in — 0 for a trial or a
 * grace text, its days-left for an active plan.
 */
if ($graceEnabled) {
    $targets = Subscription::dunning_targets($bands);
    foreach ($targets as $t) {
        $dueBand = $t['state'] === 'active' ? (int) $t['days_left'] : 0;
        if (Subscription::sent_band($t['sub']) > $dueBand) {
            $dunningDue++;
        }
    }
    foreach ($targets as $t) {
        if ($texted + $textFailed >= $limit) {
            break;
        }
        $row    = $t['sub'];
        $subId  = (int) $row->id;
        $uid    = (int) $row->user_id;
        $plan   = ucfirst((string) $row->plan);
        $price  = format_ksh((float) $row->amount, 2);

        $phone = Subscription::staff_phone($uid);
        if ($phone === '') {
            continue; // nowhere to send — the bell notification still fires below
        }

        if ($t['state'] === 'trial') {
            // Trial expiry-eve: one last conversion nudge with the entry price.
            $band = 0;
            if (Subscription::sent_band($row) <= $band) {
                continue;
            }
            $text = "Habari! Your Everafter Planner free trial ends TODAY. "
                . "Activate a plan (from KSh 2,999/mo) to keep full access to your weddings. "
                . '- Everafter Planner';
            $bell = 'Your free trial ends today — activate a plan to keep full access.';
            $lbl  = 'trial ending reminder';
        } elseif ($t['state'] === 'grace') {
            // Grace text: the account is lapsed, the clock is running.
            $band = 0; // grace texts key on band 0 (smallest) in the marker
            if (Subscription::sent_band($row) <= $band) {
                continue; // already texted during this grace
            }
            $left = max(0, (int) $t['grace_days_left']);
            $text = "Habari! Your Everafter Planner {$plan} subscription has lapsed. "
                . "You have {$left} day(s) of read-only access left. "
                . "Renew now: {$price} via M-Pesa on the subscription page. - Everafter Planner";
            $bell = 'Your subscription has lapsed — ' . $left . ' day(s) of read-only grace left. Renew to keep full access.';
            $lbl  = 'grace reminder';
        } else {
            $band = (int) $t['days_left'];
            if (Subscription::sent_band($row) <= $band) {
                continue; // this (or a tighter) band already texted
            }
            $when = $band === 0 ? 'expires TODAY' : ($band === 1 ? 'expires TOMORROW' : 'expires in ' . $band . ' days');
            $auto = ((int) ($row->auto_renew ?? 1)) === 1
                ? 'Auto-renewal is ON — an M-Pesa prompt will reach your phone. Keep it reachable.'
                : 'Auto-renewal is OFF — kindly pay manually to keep access.';
            $text = "Habari! Your Everafter Planner {$plan} subscription {$when}. {$auto} "
                . "Renewal amount: {$price}. - Everafter Planner";
            $bell = 'Subscription ' . ($band === 0 ? 'expires today' : 'expires in ' . $band . ' days')
                . ' — ' . $price . '. ' . (((int) ($row->auto_renew ?? 1)) === 1 ? 'Auto-renewal will prompt you.' : 'Renew manually to avoid losing access.');
            $lbl  = 'expiry reminder';
        }

        if ($dryRun) {
            $texted++;
            if (!$quiet) {
                echo "[dry-run] user {$uid} ({$plan}) | {$t['state']} | {$phone}\n";
            }
            continue;
        }

        // Marker FIRST (crash can never double-text), then send.
        Subscription::stamp_band($subId, $band);
        $r = SMS::send($phone, $text, $uid, 0);
        if (!empty($r['success'])) {
            $texted++;
            Notification::create_for_staff($uid, 0, $bell, 'subscription.php', 'Subscription ' . $lbl);
            if (!$quiet) {
                echo "texted user {$uid} ({$plan}) | {$t['state']}\n";
            }
        } else {
            $textFailed++;
            if (!$quiet) {
                echo "FAILED text user {$uid}: " . ($r['error'] ?? 'unknown error') . "\n";
            }
        }
    }
}

// ------------------------------------------------------------------
// LEG 3 — trial nurture emails (check-in, then last-call)
// ------------------------------------------------------------------
$nurtured = 0;
$nurtureFailed = 0;

if ($nurtureEnabled) {
    $nurtureTargets = Subscription::trial_nurture_targets();
    foreach ($nurtureTargets as $t) {
        // Same rule as the loop's own marker check: an emailed band is done.
        if (Subscription::sent_nurture_band($t['sub']) > (int) $t['band']) {
            $nurtureDue++;
        }
    }
    foreach ($nurtureTargets as $t) {
        if ($nurtured + $nurtureFailed >= $limit) {
            break;
        }
        $row    = $t['sub'];
        $subId  = (int) $row->id;
        $uid    = (int) $row->user_id;
        $band   = (int) $t['band'];

        // One email per band ever, marker-FIRST (crash can never double-send).
        if (Subscription::sent_nurture_band($row) <= $band) {
            continue;
        }
        $email = trim((string) ($row->user_email ?? ''));
        if ($email === '') {
            continue;
        }
        $endDate = date('j M Y', strtotime((string) $row->expiry_date));

        if ($dryRun) {
            $nurtured++;
            if (!$quiet) {
                echo "[dry-run] trial nurture user {$uid} | band {$band} | {$email}\n";
            }
            continue;
        }

        Subscription::stamp_nurture_band($subId, $band);
        try {
            SignupGuard::send_trial_nurture(
                $email,
                (string) ($row->user_firstname ?? ''),
                $band,
                $endDate
            );
            $nurtured++;
            Notification::create_for_staff(
                $uid,
                0,
                $band <= 1
                    ? 'Your free trial ends tomorrow — activate a plan to keep full access.'
                    : 'Halfway through your trial — tips to get the most out of it.',
                'subscription.php',
                'Trial ' . ($band <= 1 ? 'last call' : 'check-in')
            );
            if (!$quiet) {
                echo "trial nurture email user {$uid} | band {$band}\n";
            }
        } catch (Throwable $e) {
            $nurtureFailed++;
            if (!$quiet) {
                echo "FAILED trial nurture user {$uid}: " . $e->getMessage() . "\n";
            }
        }
    }
}

// ------------------------------------------------------------------
// Summary
// ------------------------------------------------------------------
$summary = 'auto-renew: ' . ($renewEnabled ? 'on' : 'off')
    . ', prompts: ' . $pushed . ', failed: ' . $pushFailed
    . ', no-phone: ' . $pushSkippedNoPhone
    . ' | dunning: ' . ($graceEnabled ? 'on' : 'off')
    . ', texted: ' . $texted . ', failed: ' . $textFailed
    . ' | nurture: ' . ($nurtureEnabled ? 'on' : 'off')
    . ', emailed: ' . $nurtured . ', failed: ' . $nurtureFailed
    . ' | queued: ' . ($renewDue + $dunningDue + $nurtureDue)
    . ($dryRun ? ' (DRY RUN — nothing written)' : '');
if (!$quiet) {
    echo $summary . "\n";
}

/*
 * The heartbeat the watchdog judges (v2.15). Only a REAL run leaves one: a
 * dry run wrote nothing, so it must not make a silent job look alive. The
 * count is what the three legs actually did (prompts sent, texts sent,
 * emails queued); the watchdog compares it against the queues those legs
 * select from, so 'ok' with nothing done while planners wait is the finding.
 * With every leg switched off there is nothing this job is allowed to do,
 * and it says so ('noop') rather than looking idle.
 */
if (!$dryRun) {
    $legsOn = $renewEnabled || $graceEnabled || $nurtureEnabled;
    JobWatch::record(
        JobWatch::RENEWALS,
        $legsOn ? JobWatch::OK : JobWatch::NOOP,
        // Actions taken, not just successes: a prompt that failed, a planner
        // warned that they have no M-Pesa number, a text that bounced — every
        // one of them is the run having done something about the queue.
        $pushed + $pushFailed + $pushSkippedNoPhone + $texted + $textFailed + $nurtured + $nurtureFailed,
        $summary,
        0,
        null,
        // And what that window held, before the limit broke any loop.
        $renewDue + $dunningDue + $nurtureDue
    );
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
