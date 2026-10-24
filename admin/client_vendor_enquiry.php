<?php
/**
 * Couple-scoped vendor enquiry. POST + CSRF; the booking must belong to the
 * session client (staff may enquire on a couple's behalf while assisting).
 * The enquiry is recorded as a pending row in tblvendor_bookings tied to the
 * couple's wedding, and the assigned planner is notified so they can follow
 * up and negotiate — the couple never negotiates alone.
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
// A couple may enquire only for their own wedding; staff may enquire on a
// couple's behalf. Refusing a missing booking is part of the helper.
if (!may_open_couple_page($booking, $user_id)) {
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

/*
 * Availability (v2.25): is this vendor already committed to another wedding on
 * the day being asked about? An enquiry is not a confirmation, so nothing is
 * refused — a couple's request is data, and the planner negotiates it — but the
 * couple should not spend a week believing a vendor is free when their planner
 * is about to discover otherwise, and the planner should get that fact with the
 * lead rather than by ringing the vendor. The day is the one typed here, else
 * the wedding's own: no function has been chosen yet at this point in the flow.
 */
$enquiry_day = VendorAvailability::day(
    $event_date !== '' ? $event_date : null,
    null,
    (string) ($booking->wedding_date ?? '')
);
$clash = $enquiry_day !== '' ? VendorAvailability::clashes($vendor_id, $enquiry_day, $booking_id) : [];

$enquiry_id = Vendor::record_enquiry($vendor_id, $booking_id, $package_id, $event_date, $message);
if ($enquiry_id > 0) {
    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &');
    $pkg    = $package_id > 0 ? Vendor::package_of($vendor_id, $package_id) : null;
    $what   = $pkg !== null ? 'package "' . $pkg->title . '"' : 'a general enquiry';

    /*
     * The package the couple just chose, against the plan they have already set.
     * This is the earliest of the doors a deal is recorded at
     * (include/PackageCapacity.php owns the check): nothing is sold yet, so
     * nothing is warned about — but a couple who planned 300 for their reception
     * and asked a caterer for a 150-pax package can pick a bigger one here, in one
     * click, before the vendor has replied to anything. The wording says which
     * side of the deal they are on (requested_clause(): "the package you asked X
     * for"), because "booked on" would be a deal that does not exist yet.
     *
     * Both readers are given it: the couple on the confirmation they land on, and
     * the planner on the bell that already tells them the enquiry exists — the
     * planner negotiates it, and it is their call whether to move the vendor up a
     * package or move the numbers.
     */
    $requested = PackageCapacity::deal_position($booking_id, $enquiry_id);
    $capacityNote = PackageCapacity::requested_position($requested); // the couple's copy: every function, and the way out
    $capacityShort = PackageCapacity::requested_summary($requested);   // the planner's bell: the fact, within its column

    // Planner (organizer) notification — they guide the negotiation.
    $plannerId = (int) ($booking->organizer_id ?? 0);
    if ($plannerId > 0) {
        // Availability leads the bell: a clash is the one fact here that costs
        // money if it is read late. The capacity note follows it when it fits.
        $plannerText = sprintf('%s enquired with %s (%s)%s.', $couple !== '' ? $couple : 'A couple', $vendor->business_name, $what, $event_date !== '' ? ' for ' . format_date_kd($event_date) : '');
        $plannerText = HeadcountBrief::append_note(
            $plannerText,
            $clash !== [] ? VendorAvailability::alert($clash) : ''
        );
        Notification::create_for_staff(
            $plannerId,
            $booking_id,
            HeadcountBrief::append_note($plannerText, $capacityShort),
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
        . ($capacityNote !== ''
            ? '<div class="text-danger mt-1" style="font-size:12.5px;"><i class="mdi mdi-alert-outline"></i> '
                . htmlspecialchars($capacityNote) . '</div>'
            : '')
        . ($clash !== []
            ? '<div class="text-danger mt-1" style="font-size:12.5px;"><i class="mdi mdi-calendar-remove"></i> '
                . htmlspecialchars(VendorAvailability::notice($clash, (string) $vendor->business_name))
                . ' Your planner has been told to check availability before anything is paid.</div>'
            : '')
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> We could not record that enquiry. Please try again.</div>');
}
redirect_to("client_vendors.php?" . $links);
die();
