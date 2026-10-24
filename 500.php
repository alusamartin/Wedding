<?php
/**
 * 500 — server error.
 *
 * Self-contained on purpose: no database, no session, no admin bootstrap, and
 * no error text. Apache serves this for engine-level failures, and a PHP fatal
 * must never surface its message, file path or SQL to a visitor. The real
 * detail is in the PHP error log (error_log is on in admin/include/init.php).
 */
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Something Went Wrong - Everafter Planner</title>
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=4.6.2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 mt-5">
            <div class="text-center p-5 bg-white" style="border-radius:20px; box-shadow:0 12px 40px rgba(11,15,20,.08);">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:84px; height:84px; background:#FFFBEB; border:1px solid #FDE68A;">
                    <i class="mdi mdi-alert-outline" style="font-size:40px; color:#B45309;"></i>
                </div>
                <h2 style="font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; letter-spacing:-.02em;">Something went wrong on our side</h2>
                <p class="lead text-muted mx-auto" style="max-width:480px; font-size:15px;">The problem is logged and we are on it. Nothing you entered has been lost — please try again in a moment.</p>
                <div class="mt-4 d-flex justify-content-center flex-wrap" style="gap:.6rem;">
                    <a href="/" class="btn wpms-cta btn-lg"><i class="mdi mdi-refresh mr-1"></i> Try again</a>
                    <a href="/contact.php" class="btn wpms-cta-ghost btn-lg"><i class="mdi mdi-email-outline mr-1"></i> Contact us</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
