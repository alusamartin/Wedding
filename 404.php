<?php
/**
 * 404 — page not found.
 *
 * Deliberately self-contained: no database, no session, no admin/include
 * bootstrap. Apache also routes 403 to this page (ErrorDocument 403), so a
 * probe for an internal path such as /scripts/ or /.env gets the same
 * uninformative answer as a typo'd URL — it must not be able to tell the
 * difference between "denied" and "does not exist".
 */
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Page Not Found - Everafter Planner</title>
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
                    <i class="mdi mdi-map-marker-question-outline" style="font-size:40px; color:#047857;"></i>
                </div>
                <h2 style="font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; letter-spacing:-.02em;">We can't find that page</h2>
                <p class="lead text-muted mx-auto" style="max-width:480px; font-size:15px;">The link may be old, or the page may have moved. Here is the way back.</p>
                <div class="mt-4 d-flex justify-content-center flex-wrap" style="gap:.6rem;">
                    <a href="/" class="btn wpms-cta btn-lg"><i class="mdi mdi-home-outline mr-1"></i> Home</a>
                    <a href="/pricing.php" class="btn wpms-cta-ghost btn-lg"><i class="mdi mdi-tag-outline mr-1"></i> Packages</a>
                    <a href="/login.php" class="btn wpms-cta-ghost btn-lg"><i class="mdi mdi-login-variant mr-1"></i> Sign in</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
