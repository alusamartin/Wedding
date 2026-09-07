<?php
/**
 * Couple-scoped vendor enquiry. POST + CSRF; the booking must belong to the
 * session client (staff may enquire on a couple's behalf while assisting).
 * The enquiry is recorded as a pending row in tblvendor_bookings tied to the
 * couple's wedding, and the assigned planner is notified so they can follow
 * up and negotiate — the couple never negotiates alone.
 */
include 'include/init.php';

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
if (!is_staff()) {
    if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
        redirect_to("client.php");
        die();
    }
} elseif (!$booking) {
    redirect_to("client.php");
    die();
}
if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}

$vendor_id  = (int) ($_POST['vendor_id'] ?? 0);
$package_id = (int) ($_POST['package_id'] ?? 0);
$event_date = clean($_POST['event_date'] ?? '');
$message    = trim((string) ($_POST['message'] ?? ''));

// The vendor must exist and be approved — hidden vendors take no enquiries.
$vendor_stmt_grab = function () use ($vendor_id) {
    global $db;
    $stmt = $db->prepare('SELECT * FROM tblvendors WHERE id = :vid AND is_approved = 1 LIMIT 1');
    $stmt->execute([':vid' => $vendor_id]);
    return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
};
$vendor = $vendor_stmt_grab();

if ($vendor === null) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That vendor is not available. Please choose another.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}
if ($message === '') {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Please write a short message for the vendor.</div>');
    redirect_to("client_vendors.php?" . $links);
    die();
}
// A chosen package must genuinely belong to this vendor and be active.
if ($package_id > 0 && Vendor::package_of($vendor_id, $package_id) === null) {
    $package_id = 0; // degrade to a general enquiry rather than fail the send
}
// Event date must be a real date if provided (defaults to the wedding date).
if ($event_date !== '' && strtotime($event_date) === false) {
    $event_date = '';
}

$enquiry_id = Vendor::record_enquiry($vendor_id, $booking_id, $package_id, $event_date, $message);
if ($enquiry_id > 0) {
    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &');
    $pkg    = $package_id > 0 ? Vendor::package_of($vendor_id, $package_id) : null;
    $what   = $pkg !== null ? 'package "' . $pkg->title . '"' : 'a general enquiry';

    // Planner (organizer) notification — they guide the negotiation.
    $plannerId = (int) ($booking->organizer_id ?? 0);
    if ($plannerId > 0) {
        Notification::create(
            $plannerId,
            $booking_id,
            sprintf('%s enquired with %s (%s) for %s.', $couple !== '' ? $couple : 'A couple', $vendor->business_name, $what, $event_date !== '' ? format_date_kd($event_date) : 'an unset date'),
            'vendors.php',
            'Vendor enquiry — ' . $vendor->business_name
        );
    }

    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Your enquiry to <strong>'
        . htmlspecialchars($vendor->business_name) . '</strong> has been sent — your planner has been notified and will follow up.'
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> We could not record that enquiry. Please try again.</div>');
}
redirect_to("client_vendors.php?" . $links);
die();
