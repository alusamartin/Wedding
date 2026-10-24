<?php include("include/init.php"); require_staff_subscribed(); ?>

<?php 

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">Invalid request. Please use the Delete button on the packages list.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("service_list.php");
    die();
}

if (empty($_GET['id'])) {
    redirect_to("service_list.php");
    die();
}

$category = Category::find_by_id((int)($_GET['id'] ?? 0));

if ($category) {
    $category->delete_photo();
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert">The package ' . htmlspecialchars($category->wedding_type ?? '') . ' has been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">That package no longer exists.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
}
redirect_to("service_list.php");
die();
?>
