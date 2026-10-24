<?php include 'admin/include/init.php'; ?>
<?php
$id = (int) ($_GET['id'] ?? 0);
$blogspot = $id > 0 ? EventWedding::find_by_id($id) : false;
$not_found = ($blogspot === false || $blogspot === null);

if ($not_found):
    http_response_code(404);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Story Not Found - Everafter Planner</title>
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=4.6.2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
</head>
<body>
<?php include 'include/nav.php'; ?>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 mt-5">
            <div class="text-center p-5 bg-white" style="border-radius:20px; box-shadow:0 12px 40px rgba(11,15,20,.08);">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:84px; height:84px; background:var(--kenya-cream, #FFFBEB); border:1px solid #FDE68A;">
                    <i class="mdi mdi-heart-broken" style="font-size:40px; color:var(--kenya-green, #047857);"></i>
                </div>
                <h2 style="font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; letter-spacing:-.02em;">This story has moved</h2>
                <p class="lead text-muted mx-auto" style="max-width:480px; font-size:15px;">The wedding story you're looking for isn't here anymore — but there are plenty more real Kenyan weddings to explore.</p>
                <div class="mt-4 d-flex justify-content-center flex-wrap" style="gap:.6rem;">
                    <a href="real-weddings.php" class="btn wpms-cta btn-lg"><i class="mdi mdi-heart-outline mr-1"></i> Explore Real Weddings</a>
                    <a href="index.php" class="btn wpms-cta-ghost btn-lg"><i class="mdi mdi-arrow-left mr-1"></i> Back to Home</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php
    exit;
endif;
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
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
    <link rel="stylesheet" href="css/bootstrap-datepicker.css?v=1.10.1">
    <style>
        body {
            margin-top: 6%;
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


        .navbar-expand-lg .navbar-nav .nav-link {
            padding-right: .9rem;
        }

        .navbar-brand {
            margin-left: 20px;
            width: 200px;
        }

        img.img-fluid.img-custom {
            width: 344px;
            height:230px;
        }
        .btn.btn-sm.btn-light.active:hover {
            background: white;
        }
        .list-group-item:first-child {
            border-top-left-radius: 0rem;
            border-top-right-radius: 0rem;
        }
    </style>
</head>
<body>

<?php include 'include/nav.php'; ?>

<div class="container">

    <div class="row">

        <div class="col-md-12 p-0" style="margin-bottom: 20px;"> <!-- border:1px solid rgba(0,0,0,.125) -->
            <h3 class="h5 text-uppercase text-center text-muted mt-4">Wedding</h3>
            <h2 class="h2 text-uppercase text-center mb-4"><?= htmlspecialchars((string) $blogspot->wedding_type); ?></h2>
            <?php $hero = 'admin/' . $blogspot->preview_image_picture(); ?>
            <div class="text-center">
                <img src="<?= $hero; ?>" class="img-fluid rounded-circle" style="width:270px;height:250px;object-fit:cover;" alt="<?= htmlspecialchars((string) $blogspot->title); ?>"
                     onerror="this.onerror=null;this.src='images/logo/everafter-crest.png';">
            </div>
            <h5 class="h5 text-uppercase text-center mt-3"><?= htmlspecialchars((string) $blogspot->title); ?></h5>
            <div class="font-weight-light text-center mb-3" style="font-size:16px;"><?= htmlspecialchars((string) $blogspot->location); ?></div>
            <p class="text-center"><?= htmlspecialchars((string) $blogspot->description); ?></p>

        </div><!-- end of col-md-8 p-0 pl-3 -->
    </div>
</div><!-- end of container -->



<!-- Optional JavaScript -->
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
<script src="js/bootstrap-datepicker.min.js?v=1.10.1"></script>

<script>

    $(document).ready(function () {
        $('#wedding_date').datepicker();
    });
</script>
</body>
</html>