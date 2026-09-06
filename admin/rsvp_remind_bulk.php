<?php
/**
 * Bulk SMS reminder — POST + CSRF, staff-only, booking-scoped.
 *
 * Accepts guest ids from the RSVP dashboard's checkbox selection, re-checks
 * every guest belongs to the booking (never trusts the client), sends each
 * a reminder SMS through the shared SMS::send() outbox (credits charged to
 * the booking's organizer), and marks the reminder state only on success.
 * The planner is shown one flash summary; skipped guests are listed with
 * their reason.
 */
require_once __DIR__ . '/include/init.php';

if (!is_staff()) {
    redirect_to('../');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_to('client.php');
    exit;
}
if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
    redirect_to('client.php');
    exit;
}

$booking_id = (int) ($_POST['booking_id'] ?? 0);
$user_id    = (int) ($_POST['user_id'] ?? 0);
$guest_ids  = $_POST['ids'] ?? [];   // matches the dashboard checkbox name="ids[]"

if (!is_array($guest_ids) || $guest_ids === []) {
    $session->message('<div class="alert alert-warning"><i class="mdi mdi-alert-outline mr-1"></i>Select at least one guest to remind.</div>');
    redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
    exit;
}
$guest_ids = array_map('intval', $guest_ids);

$booking = Booking::find_by_booking_id($booking_id);
if (!$booking) {
    redirect_to('client.php');
    exit;
}
if ((int) $booking->user_id !== $user_id) {
    redirect_to('client.php');
    exit;
}

$couple       = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
$weddingHuman = format_date_kd($booking->wedding_date ?? '');
$organizerId  = (int) ($booking->organizer_id ?? 0);

$sent    = [];
$skipped = [];

foreach ($guest_ids as $gid) {
    // Re-check ownership per guest — a crafted form cannot text strangers.
    $stmt = $db->prepare(
        "SELECT id, fullname, phone, rsvp_token, rsvp_status
         FROM tblguest WHERE id = :id AND booking_id = :bid LIMIT 1"
    );
    $stmt->execute([':id' => $gid, ':bid' => $booking_id]);
    $g = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$g) {
        $skipped[] = "Guest #{$gid}: not on this booking";
        continue;
    }
    if (($g->rsvp_status ?? 'pending') !== 'pending') {
        $skipped[] = $g->fullname . ': already replied';
        continue;
    }
    $phone = trim((string) $g->phone);
    if ($phone === '') {
        $skipped[] = $g->fullname . ': no phone number';
        continue;
    }

    $rsvpUrl = rsvp_url_for($g->rsvp_token ?? '');
    if ($rsvpUrl === '') {
        Guest::ensure_tokens($booking_id);
        $stmt2 = $db->prepare('SELECT rsvp_token FROM tblguest WHERE id = :id');
        $stmt2->execute([':id' => $g->id]);
        $rsvpUrl = rsvp_url_for((string) $stmt2->fetchColumn());
        if ($rsvpUrl === '') {
            $skipped[] = $g->fullname . ': could not mint RSVP link';
            continue;
        }
    }

    $msg = rsvp_reminder_text($g->fullname, $couple, $weddingHuman, $rsvpUrl);
    $r   = SMS::send($phone, $msg, $organizerId, $booking_id);

    if (!empty($r['success'])) {
        Guest::mark_reminded((int) $g->id);
        $sent[] = $g->fullname;
    } else {
        $skipped[] = $g->fullname . ': ' . ($r['error'] ?? 'SMS failed');
    }
}

// ---- flash summary -------------------------------------------------------
if ($sent !== []) {
    $html = '<div class="alert alert-success"><strong><i class="mdi mdi-check-circle mr-1"></i>'
        . count($sent) . ' reminder' . (count($sent) === 1 ? '' : 's') . ' sent to:</strong> '
        . htmlspecialchars(implode(', ', $sent));
    if ($skipped !== []) {
        $html .= '<br><small class="text-muted">Skipped — ' . htmlspecialchars(implode('; ', $skipped)) . '</small>';
    }
    $html .= '</div>';
    $session->message($html);

    Notification::create(
        $organizerId,
        $booking_id,
        'Manual RSVP reminders sent to ' . count($sent) . ' guest' . (count($sent) === 1 ? '' : 's')
        . ' of ' . $couple . ' (wedding ' . $weddingHuman . ')',
        'rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $booking->user_id,
        'RSVP reminders sent'
    );
} else {
    $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-alert-outline mr-1"></i>No reminders sent.</strong> '
        . htmlspecialchars(implode('; ', $skipped)) . '</div>');
}

redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
exit;
