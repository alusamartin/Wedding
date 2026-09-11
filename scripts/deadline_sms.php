<?php
declare(strict_types=1);

/**
 * Cron-safe payment deadline-band texts to couples.
 *
 * Kenyan weddings settle vendors in a final-month window, so a balance that
 * is fine at four months out is a problem at three weeks. When a CONFIRMED
 * booking with a priced package and an open balance crosses one of the
 * deadline bands (60 / 30 / 7 days to the wedding), the couple gets ONE text
 * with their balance and the monthly pace that clears it a month early —
 * the same numbers their overview nudge shows, so the two never disagree.
 *
 * One text per band, ever: tblweddingbook.pay_band_sms_sent stores the
 * SMALLEST band (in days) already texted, and a band fires only when the
 * current band is smaller — so wedding-date changes can't re-send an old
 * band, and a couple registered mid-plan simply starts at the band they are
 * already in.
 *
 * Skipped on purpose:
 *   - fully-paid bookings (nothing to nudge — and the marker stays put, so
 *     a refund/reversal later still gets future bands),
 *   - pending bookings (their payment plan is still being arranged),
 *   - weddings already past (out of scope for a push).
 *
 * Delivery rides SMS::send() (outbox row, credits charged to the assigned
 * organizer, dev mode logs without sending) and every text also drops the
 * couple a bell notification + email copy via Notification::create(), so
 * the SMS is never the only channel. The assigned planner gets one
 * notification per booking texted, closing the staff loop.
 *
 * Usage:
 *   php scripts/deadline_sms.php [--bands=60,30,7] [--limit=100] [--quiet] [--dry-run]
 *
 * Exit codes: 0 = ran (possibly zero sends), 1 = usage/lock error.
 *
 * Cron example (daily at 10:00 Nairobi time):
 *   0 10 * * * php /path/to/scripts/deadline_sms.php --quiet
 */

$opts   = getopt('', ['bands::', 'limit::', 'quiet', 'dry-run']) ?: [];
$quiet  = isset($opts['quiet']);
$dryRun = isset($opts['dry-run']);
$limit  = max(1, (int) ($opts['limit'] ?? 100));

// Bands: day counts, evaluated largest-first. Customisable for short
// engagements (e.g. --bands=30,14,3).
$bands = array_map('intval', explode(',', (string) ($opts['bands'] ?? '60,30,7')));
$bands = array_values(array_unique(array_filter($bands, fn ($b) => $b > 0)));
rsort($bands);
if ($bands === []) {
    fwrite(STDERR, "no valid bands given (e.g. --bands=60,30,7)\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/Database.php';
require_once $root . '/admin/include/db_object.php';
require_once $root . '/admin/include/Helper.php';
require_once $root . '/admin/include/Liquidation.php';
require_once $root . '/admin/include/Monetization.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
global $db;
$db = new Database();

// Single-flight lock: overlapping cron runs would double-text.
$lockFile = sys_get_temp_dir() . '/wpms_deadline_sms.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another deadline_sms run holds the lock — exiting\n";
    }
    exit(1);
}

// Confirmed bookings with a priced package and a future wedding date. The
// per-row band/balance/phone checks happen below — this query only narrows
// the candidate set (marker > smallest band OR unset).
$smallest = min($bands);
$rows = $db->fetchAll(
    "SELECT b.booking_id, b.user_id, b.bride, b.groom, b.wedding_date,
            b.organizer_id, b.pay_band_sms_sent,
            d.firstname, d.lastname, d.phone, d.status,
            c.price AS package_price
       FROM tblweddingbook b
       JOIN tblaccounts_detail d ON d.user_id = b.user_id
       JOIN tblweddingcategories c ON c.id = b.wedding_type
      WHERE LOWER(TRIM(d.status)) = 'confirm'
        AND c.price > 0
        AND b.wedding_date IS NOT NULL
        AND b.wedding_date >= CURDATE()
        AND (b.pay_band_sms_sent IS NULL OR b.pay_band_sms_sent > :smallest)",
    [':smallest' => $smallest]
);

$sent = 0;
$failed = 0;
$skippedNoPhone = 0;
$skippedPaid = 0;

foreach ($rows as $b) {
    if ($sent >= $limit) {
        break; // defer the rest to the next run
    }

    $days = (int) round(
        (strtotime(date('Y-m-d', strtotime((string) $b->wedding_date))) - strtotime(date('Y-m-d'))) / 86400
    );
    if ($days < 0) {
        continue; // wedding passed between query and now
    }

    // Which band applies? The SMALLEST band whose threshold the days have
    // crossed — a couple registered 10 days out starts at band 30, not 60,
    // and never receives the earlier (already-missed) bands back-to-back.
    $currentBand = 0;
    foreach (array_reverse($bands) as $band) {
        if ($days <= $band) {
            $currentBand = $band;
            break;
        }
    }
    if ($currentBand === 0) {
        continue; // further out than any band
    }

    // Marker semantics: fire only when this band is SMALLER than anything
    // already texted (NULL marker = nothing sent yet).
    $marker = $b->pay_band_sms_sent === null ? PHP_INT_MAX : (int) $b->pay_band_sms_sent;
    if ($currentBand >= $marker) {
        continue; // this (or a tighter) band already texted
    }

    $phone = trim((string) $b->phone);
    if ($phone === '') {
        $skippedNoPhone++;
        continue;
    }

    // Same money math as the overview nudge — the text and the page can
    // never disagree.
    $packagePrice = (float) $b->package_price;
    $amountPaid   = Liquidation::getTotalAmount((int) $b->booking_id);
    $balance      = max(0.0, $packagePrice - $amountPaid);
    if ($balance <= 0) {
        $skippedPaid++;
        continue; // fully paid — no nudge, marker untouched
    }

    $coupleName = trim((string) $b->bride . ' & ' . (string) $b->groom, ' &');
    if ($coupleName === '' || $coupleName === '&') {
        $coupleName = trim(($b->firstname ?? '') . ' ' . ($b->lastname ?? ''));
    }
    $weddingHuman = format_date_kd((string) $b->wedding_date);
    $balanceHuman = format_ksh($balance);

    // Band copy from the REAL day count (not the band), mirroring the
    // overview nudge's escalation so text and page always agree.
    if ($days > 30) {
        $pace = format_ksh($balance / max(1, (int) ceil(($days - 30) / 30)));
        $text = "Habari {$coupleName}! Your wedding on {$weddingHuman} is {$days} days away. "
            . "Balance: {$balanceHuman}. About {$pace}/mo keeps you fully paid a month early. "
            . '- Everafter Planner';
        $bell = 'Payment pace: ' . $balanceHuman . ' left — a monthly pace clears it a month before the big day.';
        $lbl  = 'pace reminder';
    } elseif ($days > 7) {
        $text = "Habari {$coupleName}! One month to your wedding on {$weddingHuman}. "
            . "Balance: {$balanceHuman} — vendors expect final settlement a month out. "
            . 'Pay via M-Pesa today. - Everafter Planner';
        $bell = 'Final stretch! Vendors are settled in this last month — your balance is ' . $balanceHuman . '.';
        $lbl  = 'settlement reminder';
    } else {
        $text = "Habari {$coupleName}! Your wedding on {$weddingHuman} is " . ($days === 1 ? 'tomorrow' : "{$days} days away") . '. '
            . "Remaining balance: {$balanceHuman} — kindly settle in the next few days "
            . 'so everything is confirmed. - Everafter Planner';
        $bell = 'Almost there! Kindly settle the remaining ' . $balanceHuman
            . ' so your vendors are fully confirmed for the day.';
        $lbl  = 'final-week reminder';
    }

    if ($dryRun) {
        $sent++;
        if (!$quiet) {
            echo "[dry-run] booking {$b->booking_id} {$coupleName} ({$phone}) | band {$currentBand}d | balance {$balanceHuman}\n";
        }
        continue;
    }

    $r = SMS::send($phone, $text, (int) ($b->organizer_id ?? 0), (int) $b->booking_id);
    if (!empty($r['success'])) {
        // Stamp the marker FIRST so a crash mid-loop can never double-text
        // this band on the next run.
        $db->execute(
            'UPDATE tblweddingbook SET pay_band_sms_sent = :bnd WHERE booking_id = :bid',
            [':bnd' => $currentBand, ':bid' => (int) $b->booking_id]
        );
        $sent++;

        // The couple's bell + email copy (same moment, second channel).
        Notification::create(
            (int) $b->user_id,
            (int) $b->booking_id,
            $bell . ' Open My Payments to pay via M-Pesa.',
            'client_budget.php?booking_id=' . (int) $b->booking_id . '&user_id=' . (int) $b->user_id,
            'Payment reminder — ' . $lbl . ' (balance ' . $balanceHuman . ')'
        );

        // One planner notification per booking texted — staff see the nudge
        // happened without running the script themselves.
        if (!empty($b->organizer_id)) {
            Notification::create(
                (int) $b->organizer_id,
                (int) $b->booking_id,
                'Deadline text sent to ' . $coupleName . ' (balance ' . $balanceHuman
                    . ', band ' . $currentBand . 'd).',
                'budget.php?booking_id=' . (int) $b->booking_id . '&user_id=' . (int) $b->user_id,
                'Payment deadline text dispatched'
            );
        }

        if (!$quiet) {
            echo "texted booking {$b->booking_id} {$coupleName} ({$phone}) | band {$currentBand}d | balance {$balanceHuman}\n";
        }
    } else {
        $failed++;
        if (!$quiet) {
            echo "FAILED booking {$b->booking_id}: " . ($r['error'] ?? 'unknown error') . "\n";
        }
    }
}

if (!$quiet) {
    echo 'bands: ' . implode('/', $bands)
        . ', candidates: ' . count($rows)
        . ", sent: {$sent}, failed: {$failed}"
        . ", skipped(no phone): {$skippedNoPhone}, paid-up: {$skippedPaid}"
        . ($dryRun ? ' (DRY RUN — nothing written)' : '')
        . "\n";
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
