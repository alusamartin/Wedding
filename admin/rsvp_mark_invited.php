<?php
/**
 * Mark invites sent — POST + CSRF, staff-only, booking-scoped.
 *
 * Stamps rsvp_invited_at for the selected guests (the moment their reminder
 * window starts counting from). Missing RSVP tokens are minted on the spot:
 * a guest cannot be invited without a personal link. Existing invite dates
 * are never overwritten — re-running on the same selection is a no-op.
 *
 * Reached from the RSVP dashboard's bulk bar ("Mark invites sent") next to
 * the SMS / WhatsApp reminder actions.
 */
require_once __DIR__ . '/include/init.php'; // paywall: staff need an active plan to preview any booking
    if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; }


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
$guest_ids  = $_POST['ids'] ?? [];   // matches the dashboard checkbox name

if (!is_array($guest_ids) || $guest_ids === []) {
    $session->message('<div class="alert alert-warning"><i class="mdi mdi-alert-outline mr-1"></i>Select at least one guest.</div>');
    redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
    exit;
}
$guest_ids = array_map('intval', $guest_ids);

$booking = Booking::find_by_booking_id($booking_id);
if (!$booking || (int) $booking->user_id !== $user_id) {
    redirect_to('client.php');
    exit;
}

// Keep only guests that actually belong to this booking.
$placeholders = implode(',', array_fill(0, count($guest_ids), '?'));
$params   = $guest_ids;
$params[] = $booking_id;
$stmt = $db->prepare(
    "SELECT id, fullname, rsvp_invited_at FROM tblguest
     WHERE id IN ($placeholders) AND booking_id = ?"
);
$stmt->execute($params);
$owned = [];
$already = [];
foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $g) {
    if ($g->rsvp_invited_at !== null) {
        $already[] = $g->fullname;
    } else {
        $owned[] = (int) $g->id;
    }
}

if ($owned === []) {
    $session->message('<div class="alert alert-info"><i class="mdi mdi-information-outline mr-1"></i>'
        . ($already !== []
            ? 'Already marked invited: ' . htmlspecialchars(implode(', ', $already)) . '.'
            : 'None of the selected guests are on this booking.')
        . '</div>');
    redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
    exit;
}

// Stamp + mint tokens (mark_invited fills gaps only and mints missing tokens).
$stamped = Guest::mark_invited($owned, $booking_id);

$html = '<div class="alert alert-success"><strong><i class="mdi mdi-send-check mr-1"></i>'
    . $stamped . ' guest' . ($stamped === 1 ? '' : 's') . ' marked invited.</strong> '
    . 'Their reminder window starts now.';
if ($already !== []) {
    $html .= '<br><small class="text-muted">Already invited (unchanged): '
        . htmlspecialchars(implode(', ', $already)) . '</small>';
}
$html .= '</div>';
$session->message($html);

Notification::create_for_staff(
    (int) ($booking->organizer_id ?? 0),
    $booking_id,
    'Invites marked sent for ' . $stamped . ' guest' . ($stamped === 1 ? '' : 's')
    . ' of ' . trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? '')),
    'rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $booking->user_id,
    'Invites marked sent'
);

redirect_to('rsvp_dashboard.php?booking_id=' . $booking_id . '&user_id=' . $user_id);
exit;
