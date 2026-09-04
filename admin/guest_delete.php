<?php include("include/init.php"); ?>
<?php
if (!is_staff()) { redirect_to("../"); }

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id = (int)($_GET['user_id'] ?? 0);
$links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

if (empty($_GET['id'])) {
    redirect_to("guest_list.php?$links");
}

$guest = Guest::find_by_id((int)$_GET['id']);

if ($guest) {
    $guest->delete();
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Guest <strong>' . htmlspecialchars($guest->fullname) . '</strong> has been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("guest_list.php?$links");
} else {
    redirect_to("guest_list.php?$links");
}
?>