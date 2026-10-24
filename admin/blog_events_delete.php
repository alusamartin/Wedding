<?php include("include/init.php"); require_staff_subscribed(); ?>

<?php 

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">Invalid request. Please use the Delete button on the article list.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("blog_events.php");
    die();
}

if (empty($_GET['id'])) {
    redirect_to("blog_events.php");
}

$event_wedding = EventWedding::find_by_id((int)($_GET['id'] ?? 0));

if ($event_wedding) {
    if ($event_wedding->delete_photo()) {
        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert">The ' . htmlspecialchars($event_wedding->title ?: 'article') . ' and its preview image have been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    } else {
        $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">The record was removed but its preview image could not be deleted from disk.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    }
}
redirect_to("blog_events.php");
die();
?>
