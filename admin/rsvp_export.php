<?php
/**
 * Venue headcount export (CSV) — staff-only, scoped to one booking.
 * Columns cover what a venue/caterer needs: attendance, group, contact,
 * logistics flags, dietary needs and the guest's note.
 */
include 'include/init.php';

if (!is_staff()) { redirect_to("../"); die(); }

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = (int)($_GET['user_id'] ?? 0);
$booking = Booking::find_by_booking_id($booking_id);
if (!$booking) { redirect_to("client.php"); die(); }

Guest::ensure_tokens($booking_id);
$guest_list = Guest::getGuest($booking_id);

$group_labels = [
    'family_elder' => 'Family Elder (Mzee)', 'bridal_train' => 'Bridal Train',
    'upcountry_guest' => 'Upcountry Guest', 'family_friend' => 'Family Friend',
    'colleague' => 'Colleague', 'pastor_clergy' => 'Pastor/Clergy',
    'vc_member' => 'VC Member', 'harambee_sponsor' => 'Harambee Sponsor',
    'close_family' => 'Close Family', 'regular' => 'Regular Guest', 'other' => 'Other',
];
$status_labels = ['yes' => 'Attending', 'no' => 'Not attending', 'maybe' => 'Maybe', 'pending' => 'Pending'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rsvp-booking-' . $booking_id . '-' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Kenyan names correctly
fputcsv($out, ['Name', 'Short name', 'Group', 'RSVP', 'Phone', 'City', 'Upcountry', 'Accommodation', 'Transport', 'Dietary', 'Note', 'Answered at']);

foreach ($guest_list as $g) {
    fputcsv($out, [
        $g->fullname,
        $g->guestname,
        $group_labels[$g->relationship] ?? (string) $g->relationship,
        $status_labels[$g->rsvp_status ?? 'pending'] ?? 'Pending',
        (string) ($g->phone ?? ''),
        (string) $g->city,
        ($g->out_of_town === 'y') ? 'Yes' : 'No',
        !empty($g->upcountry_accommodation) ? 'Yes' : 'No',
        !empty($g->transport_needed) ? 'Yes' : 'No',
        (string) ($g->dietary_requirements ?? ''),
        (string) ($g->rsvp_note ?? ''),
        !empty($g->rsvp_at) ? date('Y-m-d H:i', strtotime((string) $g->rsvp_at)) : '',
    ]);
}

// Summary block for the venue.
$tally = Guest::rsvp_tally($booking_id);
fputcsv($out, []);
fputcsv($out, ['SUMMARY']);
fputcsv($out, ['Invited', count($guest_list)]);
fputcsv($out, ['Attending', $tally['yes']]);
fputcsv($out, ['Maybe', $tally['maybe']]);
fputcsv($out, ['Not attending', $tally['no']]);
fputcsv($out, ['Awaiting reply', $tally['pending']]);
fputcsv($out, ['Plan for (yes + maybe)', $tally['yes'] + $tally['maybe']]);

fclose($out);
exit;
