<?php include 'admin/include/init.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Booking Received - Everafter Planner</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans|Roboto" rel="stylesheet">
    <link rel="stylesheet" type="text/css"
          href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
    <style>
        .alert {
            font-size: 12px;
        }
        .error {
            background-color: #F2DEDE;
        }
        .alert.alert-danger.text-center {
            font-size: 16px;
        }
        .mdi.mdi-alert-circle.mr-3 {
            font-size: 16px;
        }

    </style>
</head>
<body>
<?php include 'include/nav.php'; ?>

<div class="container">
    <div class="row">
        <div class="col-md-12 mt-5">
            <div class="text-center p-5 bg-white" style="border-radius:20px; box-shadow:0 12px 40px rgba(11,15,20,.08);">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:84px; height:84px; background:var(--kenya-cream); border:1px solid #FDE68A;">
                    <i class="mdi mdi-calendar-check" style="font-size:40px; color:var(--kenya-green);"></i>
                </div>
                <h2 style="font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; letter-spacing:-.02em; color:var(--kenya-black);">Karibu! Your booking request is in.</h2>
                <p class="lead text-muted mx-auto" style="max-width:520px; font-size:15px;">Thank you for choosing Everafter Planner. Our team will review your date and confirm your booking shortly — watch your email for updates.</p>
                <div class="mt-4 d-flex justify-content-center flex-wrap" style="gap:.6rem;">
                    <a href="login.php" class="btn wpms-cta btn-lg"><i class="mdi mdi-login mr-1"></i> Log In to View Your Booking</a>
                    <a href="index.php" class="btn wpms-cta-ghost btn-lg"><i class="mdi mdi-arrow-left mr-1"></i> Back to Home</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/jquery.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
