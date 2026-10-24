<?php include("include/init.php"); ?>
<?php
require_staff_subscribed();

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id = (int)($_GET['user_id'] ?? 0);
$links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

// Deletion is a state-changing action — POST + CSRF token only
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">Invalid request. Please use the Delete button on the guest list.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("guest_list.php?$links");
    die();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect_to("guest_list.php?$links");
    die();
}

$guest = Guest::find_by_id($id);

if ($guest) {
    $guest->delete();
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Guest <strong>' . htmlspecialchars($guest->fullname) . '</strong> has been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
}
redirect_to("guest_list.php?$links");
die();
?>
