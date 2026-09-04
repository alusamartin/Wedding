<?php include("include/init.php"); require_staff(); ?>

<?php 

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">Invalid request. Please use the Delete button on the feature list.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to("service_list.php");
    die();
}

if (empty($_GET['id'])) {
    redirect_to("service_list.php");
    die();
}

$id = (int) $_GET['id'];
$features = Features::find_by_feature_id($id);

if ($features) {
    $features->delete_feature($id);
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert">The feature ' . htmlspecialchars($features->title ?? '') . ' has been deleted.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert">That feature no longer exists.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
}
redirect_to("service_list.php");
die();
?>
