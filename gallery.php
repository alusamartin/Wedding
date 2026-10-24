<?php 
    include 'admin/include/init.php'; 

    $gallery = Gallery::find_all(); 

?>
<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Wedding Planner</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans|Roboto" rel="stylesheet">
    <link rel="stylesheet" type="text/css"
          href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=4.6.2">
    <link rel="stylesheet" href="css/style.css"></link><link rel="stylesheet" href="css/modern.css">
    <link rel="stylesheet" href="css/blueimp-gallery.min.css?v=3.4.0">
    <style>
        body {
            font-family: 'Open Sans', 'Roboto', sans-serif;
            line-height: 1.5em;
            margin-bottom: 2%;
            margin-top: 2%;
            width: 100%;
            overflow-x: hidden;
            background: #f1f1f1;
        }

        .navbar-light .navbar-brand {
            color: #1a1a1a;
            font-weight: bold;
            line-height: 22px;
        }

        .navbar {
            font-weight: 700;
            padding: 12px;
            font-style: normal;
            font-size: 14px;
            text-transform: uppercase;
            color: black;
            border-bottom: 1px solid #ddd;
        }

        li.nav-item > a.nav-link {
            color: black !important;
            font-weight: bold !important;
        }

        #review {
            font-size: 16px;
            font-weight: bold;
            margin-right: 5px;
        }

        .form-inline > a.mr-2, .btn.btn-sm.my-2.my-sm-0 {
            color: black;
            font-size: 14px;
            font-weight: 700;
            margin-left: 10px;
        }

        .form-inline > a.mr-2:hover, .btn.btn-sm.my-2.my-sm-0:hover {
            color: #17b4bc;
            text-decoration: none;
        }

        a.btn.btn-sm.my-2.my-sm-0.mr-2.loginbtn {
            background: #dc3545;
            font-size: 14px;
            color: white;
            padding: 5px;
            border: 2px solid transparent;
            width: 85px;
        }

        a.btn.btn-sm.my-2.my-sm-0.mr-2.loginbtn:hover {
            background: white;
            border: 2px solid #dc3545;
            color: #dc3545;
        }

        .navbar-expand-lg .navbar-nav .nav-link {
            padding-right: .9rem;
        }

        .navbar-brand {
            margin-left: 20px;
            width: 200px;
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
        /* The caption bar sits over the photo; let clicks reach the photo. */
        #links .card-body { pointer-events: none; }

    </style>
</head>
<body>
<?php include 'include/nav.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-12">
            <h4 class="text-center mb-0 mt-5">Wedding Photos</h4>
            <p class="text-center mt-0 text-muted">Photos That Capture Your Moment</p>

            <?php if (empty($gallery)) : ?>
                <?php empty_state('Photos are coming soon', 'Real weddings from our couples will appear here — follow along as Everafter Planner grows.', 'mdi-image-multiple-outline'); ?>
            <?php else : ?>
            <div class="card-columns" id="links">
                <?php foreach($gallery as $galleries) : ?>
                <div class="card" style="position: relative;">
                    <a class="gallery-item" href="admin/<?= $galleries->picture_path(); ?>"
                       title="<?= htmlspecialchars(empty($galleries->title) ? 'No Title' : (string) $galleries->title); ?>">
                        <img class="card-img-top" src="admin/<?= $galleries->picture_path(); ?>" alt="Card image cap">
                    </a>
                    <div class="card-body" style="position: absolute;bottom: 0;left:0; width: 100%;background: rgba(0,0,0, 0.5);color:white;padding: 10px 10px 0 10px;">
                        <p class="card-title text-capitalize" style="font-size:12px;"><?= empty($galleries->title) ? 'No Title' : $galleries->title; ?></p>
                    </div>
                </div><!-- end of body -->
                <?php endforeach; ?>
            </div><!-- end of card columns -->
            <?php endif; ?>
        </div>
    </div>

<!-- Optional JavaScript -->
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/popper.min.js"></script>
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
<script src="js/blueimp-gallery.min.js?v=3.4.0"></script>
<script>
/*
 * Blueimp Gallery 3 (patched) — replaces jQuery Lightbox 2.6, which stopped
 * receiving fixes in 2015. Only the photo anchors open the lightbox; the
 * overlay card body and its controls keep their own click behaviour.
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