<?php include 'include/init.php'; ?>

<?php
require_staff_subscribed();

// $booking_id = $_GET['booking_id'];
// $user_id = $_GET['user_id'];
// $links='booking_id='.$booking_id.'&user_id='.$user_id;
// $guest_list =  Guest::getGuest($booking_id);
// $category = Category::find_all();
$gallery = Gallery::find_all();
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Gallery Photos - Administrator</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css?v=1.13.11" rel="stylesheet">
    <link rel="stylesheet" type="text/css"
          href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <link rel="stylesheet" href="../css/blueimp-gallery.min.css?v=3.4.0">
    <style>

        p.card-text a {
            font-size: 12px;
        }
        p.card-text {
            line-height: 16px;
        }
        .btn.btn-light.mr-2.text-uppercase {
            background-color: #e2e6ea;
        }

        .card {
            -webkit-border-radius:0;
            -moz-border-radius:0;
            border-radius:0;
        }
        .card img {
            -webkit-border-radius:0;
            -moz-border-radius:0;
            border-radius:0;
        }
        /* Blueimp Gallery (patched) replaces jQuery Lightbox 2.6. */
        #links a.gallery-item img { cursor: zoom-in; }
        /* The caption bar sits over the photo; let clicks reach the photo while
           keeping the Edit / Delete controls clickable. */
        #links .card-body { pointer-events: none; }
        #links .card-body a, #links .card-body form, #links .card-body button { pointer-events: auto; }

    </style>
</head>

<body>

<?php include_once 'include/sidebar.php'; ?>


<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h4 class="h4 mt-4">Gallery </h4>
    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="btn-group mr-2">
            <a class="btn btn-primary mr-2 text-uppercase" style="font-size: 12px;font-weight: bold;" href="photos_add.php"><i class="mdi mdi-upload mr-2"></i> Upload Image</a>
        </div>
    </div>
</div>
<?php
if ($session->message()) {
    echo $session->message();
}
?>
<div class="row">
    <div class="col-md-12">
        <?php if (empty($gallery)) : ?>
            <?php empty_state('The gallery is empty', 'Showcase real weddings so couples can picture their own big day.', 'mdi-image-multiple-outline', 'photos_add.php', 'Upload First Photo', 'mdi-upload'); ?>
        <?php else : ?>
        <div class="card-columns" id="links">
            <?php foreach($gallery as $galleries) : ?>
                <div class="card" style="position: relative;">
                    <a class="gallery-item" href="<?= $galleries->picture_path(); ?>"
                       title="<?= htmlspecialchars(empty($galleries->title) ? 'No Title' : (string) $galleries->title); ?>">
                        <img class="card-img-top" src="<?= $galleries->picture_path(); ?>" alt="Card image cap">
                    </a>
                    <div class="card-body" style="position: absolute;bottom: 0;left:0; width: 100%;background: rgba(0,0,0, 0.5);color:white;padding: 10px 10px 0 10px;">
                        <p class="card-title text-capitalize" style="font-size:14px;">
                            <?= empty($galleries->title) ? 'No Title' : $galleries->title; ?>
                            <span class="float-right pb-2">
                              <a href="photos_edit.php?id=<?= $galleries->id; ?>" class="btn btn-info btn-sm" data-toggle="tooltip" title="Edit This Picture"><i class="mdi mdi-pencil"></i></a>
                              <form method="post" action="photo_delete.php?id=<?= $galleries->id; ?>" class="d-inline" onsubmit="return confirm('Delete this photo and its file?');">
                                  <?= $session->csrf_field(); ?>
                                  <button type="submit" class="btn btn-danger btn-sm" data-toggle="tooltip" title="Delete This Picture"><i class="mdi mdi-delete"></i></button>
                              </form>
                          </span>
                        </p>
                    </div>
                </div><!-- end of body -->
            <?php endforeach; ?>
        </div><!-- end of card columns -->
        <?php endif; ?>
    </div>
</div><!-- end of col-md-12 -->
</div><!-- end of row -->
</main>
</div>
</div>

<script src="js/jquery-3.7.1.slim.min.js"></script>
<script>window.jQuery || document.write('<script src="../../../../assets/js/vendor/jquery-slim.min.js"><\/script>')</script>
<script src="js/popper.min.js"></script>
<script src="../js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
<!-- Lightbox dialog: blueimp Gallery 3 needs this markup (v2 created it in JS). -->
<div id="blueimp-gallery" class="blueimp-gallery" aria-label="image gallery" aria-modal="true" role="dialog">
    <div class="slides" aria-live="polite"></div>
    <h3 class="title"></h3>
    <a class="prev" aria-controls="blueimp-gallery" aria-label="previous slide" aria-keyshortcuts="ArrowLeft"></a>
    <a class="next" aria-controls="blueimp-gallery" aria-label="next slide" aria-keyshortcuts="ArrowRight"></a>
    <a class="close" aria-controls="blueimp-gallery" aria-label="close" aria-keyshortcuts="Escape"></a>
    <a class="play-pause" aria-controls="blueimp-gallery" aria-label="play slideshow" aria-keyshortcuts="Space" aria-pressed="false" role="button"></a>
    <ol class="indicator"></ol>
</div>
<script src="../js/blueimp-gallery.min.js?v=3.4.0"></script>
<script>
/*
 * Blueimp Gallery 3 (patched) replaces jQuery Lightbox 2.6. Only the photo
 * anchors open the lightbox, so the Edit / Delete controls in the card body
 * keep their own click behaviour.
 */
(function () {
    var container = document.getElementById('links');
    if (!container || !window.blueimp || !window.blueimp.Gallery) { return; }
    var links = Array.prototype.slice.call(container.querySelectorAll('a.gallery-item'));
    container.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a.gallery-item') : null;
        if (!link || links.indexOf(link) === -1) { return; }
        event.preventDefault();
        blueimp.Gallery(links, { index: links.indexOf(link), event: event });
    });
})();
</script>
</body>
</html>


