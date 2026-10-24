<?php
/**
 * Couple-scoped checklist toggle. POST + CSRF; the item must belong to the
 * booking in the query string AND that booking must belong to the session
 * client (staff may toggle while assisting a couple).
 */
include 'include/init.php'; // paywall: staff need an active plan to preview any booking
    if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; }


$booking_id = (int) ($_GET['booking_id'] ?? 0);
$user_id    = (int) ($_GET['user_id'] ?? 0);
$links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

if (!isset($_SESSION['id'])) {
    redirect_to("../");
    die();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to("client_checklist.php?" . $links);
    die();
}
$booking = Booking::find_by_booking_id($booking_id);
// A couple may toggle only their own booking; staff may assist any. A missing
// booking is refused for everyone, so the old staff-side `elseif (!$booking)`
// bail-out is folded into the one helper.
if (!may_open_couple_page($booking, $user_id)) {
    redirect_to("client.php");
    die();
}
if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
    redirect_to("client_checklist.php?" . $links);
    die();
}

$item_id = (int) ($_POST['item_id'] ?? 0);
if ($item_id > 0 && Checklist::toggle($item_id, $booking_id)) {
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Checklist updated.'
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Could not update that item. Please try again.</div>');
}
redirect_to("client_checklist.php?" . $links);
die();
