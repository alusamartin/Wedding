<?php include("include/init.php"); require_staff(); ?>

<?php 

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">Invalid request. Please use the Delete button on the gallery.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("photos_view.php");
    die();
}

if (empty($_GET['id'])) {
    redirect_to("photos_view.php");
}

$gallery = Gallery::find_by_id((int) $_GET['id']);

if ($gallery) {
    if ($gallery->delete_photo()) {
        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert">The ' . htmlspecialchars($gallery->title ?: 'photo') . ' and its file have been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    } else {
        $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">The record was removed but its file could not be deleted from disk.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    }
}
redirect_to("photos_view.php");
die();
?>
