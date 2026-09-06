<?php
/**
 * Couple-scoped guest add/edit/delete.
 *
 * Clients may only touch guests of their OWN booking — enforced both by
 * the session check below (booking->user_id must match) and by the insert
 * using the session booking id. Staff preview via guest_list.php keeps its
 * own tooling; this endpoint serves the couple's My Guests page.
 */
include 'include/init.php';

$booking_id = (int) ($_GET['booking_id'] ?? 0);
$user_id    = (int) ($_GET['user_id'] ?? 0);
$links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

if (!isset($_SESSION['id'])) {
    redirect_to("../");
    die();
}
$booking = Booking::find_by_booking_id($booking_id);
if (!is_staff()) {
    if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
        redirect_to("client.php");
        die();
    }
} elseif (!$booking) {
    // Staff may only act through the couple's link too — a valid booking is required.
    redirect_to("client.php");
    die();
}

function guest_back(string $links, string $msg, string $cls = 'alert-danger'): void
{
    global $session;
    $session->message('<div class="' . $cls . ' alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-alert"></i></strong> '
        . $msg
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("client_guests.php?" . $links);
    die();
}

if (!isset($_POST['save_guest']) && !isset($_POST['delete_guest'])) {
    guest_back($links, 'Invalid request.');
}
if (!$session->verify_csrf()) {
    guest_back($links, 'Invalid security token. Please try again.');
}

// ---------------------------------------------------------------- add/edit
if (isset($_POST['save_guest'])) {
    $guest_id = (int) ($_POST['guest_id'] ?? 0);
    $fullname  = clean($_POST['fullname'] ?? '');
    $guestname = clean($_POST['guestname'] ?? '');
    $phone     = clean($_POST['phone'] ?? '');
    $city      = clean($_POST['city'] ?? '');
    $relationship = clean($_POST['relationship'] ?? 'regular');
    $out_of_town  = (($_POST['out_of_town'] ?? 'n') === 'y') ? 'y' : 'n';
    $upcountry_accommodation = isset($_POST['upcountry_accommodation']) ? 1 : 0;
    $transport_needed        = isset($_POST['transport_needed']) ? 1 : 0;
    $dietary_requirements    = clean($_POST['dietary_requirements'] ?? '');

    if ($fullname === '' || $guestname === '') {
        guest_back($links, 'Please give the guest a full name (and a short name for the invitation).');
    }

    if ($guest_id > 0) {
        // Edit — the row must belong to this booking.
        $existing = Guest::find_by_id($guest_id);
        if (!$existing || (int) $existing->booking_id !== $booking_id) {
            guest_back($links, 'That guest is not on your list.');
        }
        $existing->fullname       = $fullname;
        $existing->guestname      = $guestname;
        $existing->phone          = $phone;
        $existing->city           = $city;
        $existing->relationship   = $relationship;
        $existing->out_of_town    = $out_of_town;
        $existing->upcountry_accommodation = $upcountry_accommodation;
        $existing->transport_needed        = $transport_needed;
        $existing->dietary_requirements    = $dietary_requirements;
        if ($existing->update()) {
            guest_back($links, '<strong>' . htmlspecialchars($fullname) . '</strong> has been updated.', 'alert-success');
        }
        guest_back($links, 'Could not save the changes. Please try again.');
    } else {
        // Add — a fresh RSVP token is minted immediately so the WhatsApp
        // button is ready without a reload dance.
        $guest = new Guest();
        $guest->booking_id  = $booking_id;
        $guest->fullname    = $fullname;
        $guest->guestname   = $guestname;
        $guest->phone       = $phone;
        $guest->city        = $city;
        // Legacy staff-facing columns are NOT NULL; the couple form collects
        // the town only, so derive them instead of leaving them NULL.
        $guest->address     = $city;
        $guest->state       = '';
        $guest->zipcode     = '';
        $guest->tracks_and_gifts = '';
        $guest->relationship = in_array($relationship, ['family_elder', 'bridal_train', 'upcountry_guest', 'family_friend', 'colleague', 'pastor_clergy', 'vc_member', 'harambee_sponsor', 'close_family', 'regular', 'other'], true)
            ? $relationship : 'regular';
        $guest->out_of_town = $out_of_town;
        $guest->upcountry_accommodation = $upcountry_accommodation;
        $guest->transport_needed        = $transport_needed;
        $guest->dietary_requirements    = $dietary_requirements;
        // priority is a NOT NULL enum with no default: derive a sensible one
        // from the group (staff can still fine-tune it on the master list).
        $priority_by_group = [
            'family_elder' => 'A', 'pastor_clergy' => 'A',
            'bridal_train' => 'B', 'vc_member' => 'B', 'close_family' => 'B', 'harambee_sponsor' => 'B',
            'upcountry_guest' => 'C', 'family_friend' => 'C',
            'colleague' => 'D', 'regular' => 'D', 'other' => 'E',
        ];
        $guest->priority = $priority_by_group[$guest->relationship] ?? 'D';
        $guest->rsvp_status = 'pending';
        $guest->rsvp_token  = bin2hex(random_bytes(16));
        if ($guest->create()) {
            guest_back($links, '<strong>' . htmlspecialchars($fullname) . '</strong> added — their RSVP link and WhatsApp invite are ready.', 'alert-success');
        }
        guest_back($links, 'Could not add the guest. Please try again.');
    }
}

// ------------------------------------------------------------------ delete
if (isset($_POST['delete_guest'])) {
    $guest_id = (int) ($_POST['guest_id'] ?? 0);
    $existing = $guest_id > 0 ? Guest::find_by_id($guest_id) : false;
    if (!$existing || (int) $existing->booking_id !== $booking_id) {
        guest_back($links, 'That guest is not on your list.');
    }
    if ($existing->delete()) {
        guest_back($links, '<strong>' . htmlspecialchars($existing->fullname) . '</strong> removed from your guest list.', 'alert-success');
    }
    guest_back($links, 'Could not remove the guest. Please try again.');
}

guest_back($links, 'Invalid request.');
