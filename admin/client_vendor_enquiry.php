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
            sprintf('%s enquired with %s (%s)%s.', $couple !== '' ? $couple : 'A couple', $vendor->business_name, $what, $event_date !== '' ? ' for ' . format_date_kd($event_date) : ''),
            'vendor_enquiries.php',
            'Vendor enquiry — ' . $vendor->business_name
        );
    }

    // Best-effort SMS heads-up to the vendor so they hear about the lead
    // even with no dashboard session. The ASSIGNED PLANNER's credits pay
    // (same rule as RSVP reminders). A 6-hour cooldown per booking+vendor
    // keeps a click-happy couple from flooding the vendor's phone; a
    // credits or gateway failure never blocks the enquiry itself.
    $vendorSmsNote = '';
    $vendorPhone = trim((string) ($vendor->phone ?? ''));
    if ($vendorPhone !== '') {
        // tblsms_logs stores the NORMALIZED number (format_phone turns
        // 0722… into 254722…), so the cooldown must compare like for like.
        // Scoping to enquiry texts keeps a guest RSVP reminder to the same
        // number from suppressing a legitimate vendor alert.
        $vendorPhone = SMS::format_phone($vendorPhone);
        $cooldown = $db->prepare(
            "SELECT COUNT(*) FROM tblsms_logs
              WHERE booking_id = :bid AND phone = :phone
                AND message LIKE '%enquiry%'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)"
        );
        $cooldown->execute([':bid' => $booking_id, ':phone' => $vendorPhone]);
        if ((int) $cooldown->fetchColumn() > 0) {
            $vendorSmsNote = ''; // already alerted recently — silent no-op
        } elseif ($plannerId <= 0) {
            $vendorSmsNote = ''; // no planner to charge the SMS to
        } else {
            $smsText = sprintf(
                'Habari %s! New wedding enquiry from %s (%s)%s via Everafter Planner. Kindly confirm availability.',
                $vendor->business_name,
                $couple !== '' ? $couple : 'A couple',
                $what,
                $event_date !== '' ? ' for ' . format_date_kd($event_date) : ''
            );
            $r = SMS::send($vendorPhone, $smsText, $plannerId, $booking_id);
            if (!empty($r['success'])) {
                $vendorSmsNote = ' The vendor has also been alerted by SMS.';
            }
            // A failed SMS (no credits, gateway down) stays quiet here — the
            // planner notification above is the reliable channel.
        }
    }

    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Your enquiry to <strong>'
        . htmlspecialchars($vendor->business_name) . '</strong> has been sent — your planner has been notified and will follow up.'
        . htmlspecialchars($vendorSmsNote)
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> We could not record that enquiry. Please try again.</div>');
}
redirect_to("client_vendors.php?" . $links);
die();
