<?php
include 'include/init.php';

require_staff_subscribed();

/*
 * Which wedding does the new event belong to? It arrives two different ways:
 *   - task_calendar.php      form action="task_event_save.php?booking_id=N&user_id=N"
 *   - task_all_calendar.php  a <select name="booking_id"> couple picker (no query string)
 * Reading only $_GET silently discarded the couple chosen on the all-calendars
 * page and stored the event with booking_id = 0 — an orphan no calendar shows.
 */
$booking_id = (int) ($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);
$user_id    = (int) ($_GET['user_id'] ?? 0);
$links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

if ($booking_id <= 0 || Booking::find_by_booking_id($booking_id) === false) {
    $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-alert"></i></strong> Pick a wedding before adding an event.</div>');
    redirect_to("task_calendar.php");
    die();
}

// CSRF validation
if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
    redirect_to("task_calendar.php?" . $links);
    die();
}

if (isset($_POST['title']) && isset($_POST['start']) && isset($_POST['end'])) {

    $title = clean($_POST['title'] ?? '');
    $location = clean($_POST['location'] ?? '');
    $start = clean($_POST['start'] ?? '');
    $end = clean($_POST['end'] ?? '');
    $color = clean($_POST['color'] ?? '#607d8b');

    // Auto-assign color based on event type
    $color_map = [
        'Ruracio' => '#c0392b',
        'Church' => '#8e44ad',
        'Reception' => '#27ae60',
        'After-Party' => '#f39c12',
        'VC Meeting' => '#3498db',
        'Civil' => '#2c3e50',
        'Bridal' => '#e91e63',
        'Engagement' => '#ff9800',
        'Send-off' => '#00897b',
        'Disco' => '#00897b'
    ];

    foreach ($color_map as $keyword => $auto_color) {
        if (stripos($title, $keyword) !== false) {
            $color = $auto_color;
            break;
        }
    }

    // PDO prepared (v2.1)
    global $db;
    $sql = "INSERT INTO events (title, location, start, end, color, booking_id, date_created) VALUES (:title, :location, :start, :end, :color, :bid, :created)";
    $stmt = $db->prepare($sql);
    $date_created = date("Y-m-d H:i:s");

    if ($stmt->execute([':title' => $title, ':location' => $location, ':start' => $start, ':end' => $end, ':color' => $color, ':bid' => $booking_id, ':created' => $date_created])) {
        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Event "' . htmlspecialchars($title) . '" added successfully.</div>');
    } else {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Error saving event. Please try again.</div>');
    }
}

redirect_to("task_calendar.php?" . $links);
exit;
?>
