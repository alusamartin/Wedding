<?php
/**
 * WhatsApp reminder queue — GET, staff-only, booking-scoped.
 *
 * A selection of pending guests arrives as ?ids=1,2,3. The page walks the
 * planner through them one at a time: a big "Open WhatsApp" button with the
 * guest's pre-filled reminder, then Next. Each opened chat is marked so the
 * planner always knows where they left off (state lives in the query string,
 * so refresh/back/forward all behave).
 */
include 'include/init.php'; // paywall: staff need an active plan to preview any booking
    if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; }


if (!is_staff()) {
    redirect_to('../');
    exit;
}

$booking_id = (int) ($_GET['booking_id'] ?? 0);
$user_id    = (int) ($_GET['user_id'] ?? 0);
// Selection arrives either as ids[]=1&ids[]=2 (dashboard form) or ids=1,2
// (deep link built by the queue itself).
$rawIds = $_GET['ids'] ?? [];
$ids = is_array($rawIds)
    ? array_filter(array_map('intval', $rawIds))
    : array_filter(array_map('intval', explode(',', (string) $rawIds)));

$booking = Booking::find_by_booking_id($booking_id);
if (!$booking || (int) $booking->user_id !== $user_id || $ids === []) {
    redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
    exit;
}

// Keep only pending guests of THIS booking, in the given order.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
$params[] = $booking_id;
$stmt = $db->prepare(
    "SELECT id, fullname, guestname, phone, rsvp_token
     FROM tblguest
     WHERE id IN ($placeholders) AND booking_id = ? AND rsvp_status = 'pending'"
);
$stmt->execute($params);
$byId = [];
foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $g) {
    $byId[(int) $g->id] = $g;
}
$queue = array_values(array_filter($ids, fn ($id) => isset($byId[$id])));

if ($queue === []) {
    $session->message('<div class="alert alert-warning"><i class="mdi mdi-alert-outline mr-1"></i>No pending guests among those selected.</div>');
    redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
    exit;
}

$done = (int) ($_GET['done'] ?? 0);
$pos  = (int) ($_GET['pos'] ?? 0);
if ($pos < 0 || $pos >= count($queue)) {
    // Finished (or out of range): record the count and go home.
    if ($done > 0) {
        $coupleName = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
        Notification::create_for_staff(
            (int) ($booking->organizer_id ?? 0),
            $booking_id,
            'Manual RSVP reminders queued on WhatsApp for ' . $done . ' guest' . ($done === 1 ? '' : 's')
            . ' of ' . $coupleName,
            'rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $booking->user_id,
            'RSVP reminders queued'
        );
    }
    redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
    exit;
}

$g = $byId[$queue[$pos]];
$couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
$weddingHuman = format_date_kd($booking->wedding_date ?? '');
$rsvpUrl = rsvp_url_for($g->rsvp_token ?? '');
$msg = rsvp_reminder_text($g->guestname ?: $g->fullname, $couple, $weddingHuman, $rsvpUrl);

$base = 'rsvp_wa_queue.php?booking_id=' . $booking_id . '&user_id=' . $user_id . '&ids=' . implode(',', $queue);
$waLink = $msg;
$nextLink = $base . '&pos=' . ($pos + 1) . '&done=' . ($done + 1);
$skipLink = $base . '&pos=' . ($pos + 1) . '&done=' . $done;
$quitLink = 'rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id;
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp Reminder Queue - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500;700" rel="stylesheet">
    <style>
        body { background: #F6F3EC; }
        .walk-card { background: #fff; border-radius: 20px; box-shadow: 0 18px 44px rgba(15,23,42,.10);
                     max-width: 470px; margin: 40px auto; padding: 30px 32px; }
        .step { font-size: 12px; letter-spacing: .1em; color: #64748B; text-transform: uppercase; font-weight: 600; }
        .gname { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: 1.35rem; color: #0F172A; }
        .gmeta { color: #64748B; font-size: 13px; }
        .gphone { font-family: 'Consolas', 'Menlo', monospace; font-size: 14.5px; letter-spacing: .06em; color: #0F172A; font-weight: 600; }
        .btn-wa { background: #25D366; color: #fff; border-radius: 999px; font-weight: 700; font-size: 15px;
                  padding: 12px 26px; box-shadow: 0 8px 20px rgba(37, 211, 102, .32); border: 0; }
        .btn-wa:hover, .btn-wa:focus { background: #1DA851; color: #fff; }
        .btn-next { background: #065F46; color: #fff; border-radius: 999px; font-weight: 700; font-size: 14px;
                    padding: 10px 26px; border: 0; }
        .btn-next:hover { background: #059669; color: #fff; }
        .btn-skip { color: #64748B; font-size: 13px; text-decoration: underline; }
        .qprog { height: 7px; border-radius: 999px; background: #E2E8F0; overflow: hidden; }
        .qprog > div { height: 100%; background: linear-gradient(90deg, #065F46, #059669); }
        .qtext { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px;
                 padding: 10px 14px; font-size: 12.5px; color: #475569; }
    </style>
</head>
<body>
    <div class="walk-card text-center">
        <div class="step mb-2">WhatsApp reminder — <?= $pos + 1; ?> of <?= count($queue); ?></div>
        <div class="qprog mb-4"><div style="width: <?= (int) round(($pos) * 100 / max(1, count($queue))); ?>%;"></div></div>

        <div class="gname"><?= htmlspecialchars($g->fullname); ?></div>
        <div class="gmeta mb-1"><?= htmlspecialchars($couple); ?> — <?= htmlspecialchars($weddingHuman); ?></div>
        <?php $waNum = wa_number_ke($g->phone ?? ''); ?>
        <div class="gphone mb-3">
            <?= $waNum !== '' ? htmlspecialchars($g->phone) : '<span class=\"text-warning\">no number saved</span>'; ?>
        </div>

        <div class="qtext mb-4" style="text-align: left;"><?= htmlspecialchars($msg); ?></div>

        <?php if ($waNum !== ''): ?>
            <a class="btn btn-wa btn-lg" href="https://wa.me/<?= $waNum; ?>?text=<?= rawurlencode($msg); ?>"
               target="_blank" rel="noopener" onclick="setTimeout(function(){window.location='<?= $nextLink; ?>';}, 1200);">
                <i class="mdi mdi-whatsapp mr-1"></i>Open WhatsApp &amp; Continue
            </a>
            <div class="mt-3"><a class="btn-skip" href="<?= $skipLink; ?>">Skip this guest</a></div>
        <?php else: ?>
            <p class="text-warning mb-3" style="font-size:13px;"><i class="mdi mdi-alert mr-1"></i>This guest has no usable WhatsApp number.</p>
            <a class="btn btn-next" href="<?= $skipLink; ?>">Next guest</a>
        <?php endif; ?>

        <div class="mt-4">
            <a class="btn-skip" href="<?= $quitLink; ?>" onclick="return confirm('Stop the reminder queue? Guests you already opened stay marked.');">
                Stop and go back to dashboard
            </a>
        </div>
        <p class="text-muted mt-3 mb-0" style="font-size:11.5px;">
            Guests you open are tracked only in this walkthrough — the Auto-reminder count on the dashboard is updated by SMS sends and the nightly cron.
        </p>
    </div>
</body>
</html>
