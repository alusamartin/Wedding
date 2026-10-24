<?php
/**
 * Couple rates a completed vendor deal.
 *
 * The marketplace shows a vendor's star rating to everyone (admin/client_vendors.php),
 * but until v2.21 nothing ever set it. Here the couple scores a deal the planner
 * has marked COMPLETED, once — the row's `rating IS NULL` guard is what makes it
 * once, not a flag anyone could flip back — and Vendor::recompute_stats() folds
 * it into the vendor's mean. Only the couple whose wedding the deal belongs to
 * may rate it (staff may act for the couple while assisting), and only a
 * completed deal is ratable: scoring a deal still being negotiated would be
 * scoring something that has not happened yet.
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
    redirect_to("client_vendors.php?" . $links);
    die();
}
$booking = Booking::find_by_booking_id($booking_id);
// A couple may rate only their own wedding's deals; staff may act on their
// behalf. A missing booking is refused for everyone by the helper.
if (!may_open_couple_page($booking, $user_id)) {
    redirect_to("client.php");
    die();
}
if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}

$enquiry_id = (int) ($_POST['enquiry_id'] ?? 0);
$rating     = (int) ($_POST['rating'] ?? 0);
if ($rating < 1 || $rating > 5) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Please choose a rating from 1 to 5 stars.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}

// The deal must be one of this wedding's — vendor_id scopes the recompute, and
// booking_id scopes the permission above to the couple who owns the enquiry.
global $db;
$stmt = $db->prepare(
    'SELECT vb.id, vb.vendor_id, vb.status, vb.rating, v.business_name
       FROM tblvendor_bookings vb
       JOIN tblvendors v ON v.id = vb.vendor_id
      WHERE vb.id = :id AND vb.booking_id = :b LIMIT 1'
);
$stmt->execute([':id' => $enquiry_id, ':b' => $booking_id]);
$row = $stmt->fetch(PDO::FETCH_OBJ);

if ($row === null) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That deal no longer exists.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}
if ((string) $row->status !== 'completed') {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> You can rate a vendor once their services for your wedding are marked completed.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}
if ($row->rating !== null) {
    $session->message('<div class="alert alert-info"><strong><i class="mdi mdi-information"></i></strong> You have already rated <strong>'
        . htmlspecialchars((string) $row->business_name) . '</strong> for this deal.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}

// `rating IS NULL` in the WHERE is the once-only guarantee: a double submit
// changes the first row and nothing else.
$upd = $db->prepare('UPDATE tblvendor_bookings SET rating = :r, rated_at = NOW() WHERE id = :id AND rating IS NULL');
$upd->execute([':r' => $rating, ':id' => $enquiry_id]);

if ($upd->rowCount() > 0) {
    Vendor::recompute_stats((int) $row->vendor_id);
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-star"></i></strong> Thank you — you rated <strong>'
        . htmlspecialchars((string) $row->business_name) . '</strong> '
        . str_repeat('&#9733;', $rating) . str_repeat('&#9734;', 5 - $rating)
        . ' (' . $rating . '/5).'
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-info"><strong><i class="mdi mdi-information"></i></strong> That deal was already rated.</div>');
}
redirect_to("client_vendors.php?" . $links);
die();
