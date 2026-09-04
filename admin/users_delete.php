<?php include("include/init.php"); require_staff(); ?>

<?php 

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">Invalid request. Please use the Delete button on the user list.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("users.php");
    die();
}

if (empty($_GET['id'])) {
    redirect_to("users.php");
    die();
}

$user = Users::find_by_id((int) $_GET['id']);

if ($user) {
    if ((int) $user->id === (int) ($_SESSION['id'] ?? 0)) {
        $session->message('<div class="alert alert-warning alert-dismissible fade show" role="alert">You cannot delete your own account while signed in.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        redirect_to("users.php");
        die();
    }
    $user->delete_photo();
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert">The ' . htmlspecialchars($user->firstname . ' ' . $user->lastname) . ' has been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">That user no longer exists.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
}
redirect_to("users.php");
die();
?>
