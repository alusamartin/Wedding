<?php include_once 'admin/include/init.php'; ?>
<?php
// Always show the same confirmation regardless of whether the address is
// registered — no account enumeration. Delivery is logged to tbl_mail_logs
// and retried by scripts/mail_retry.php when the transport is off.
if (isset($_POST['forgot'])) {
    if (!$session->verify_csrf()) {
        $session->message(
            "<div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">"
            . "<strong><i class='mdi mdi-alert'></i></strong> Invalid security token. Please try again."
            . "<button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button></div>"
        );
        redirect_to("forgot_password.php");
        die();
    }
    $email = strtolower(clean($_POST['input_email'] ?? ''));
    $token = $email !== '' ? PasswordReset::issue($email) : null;

    if ($token !== null) {
        // Resolve the first name for a warm greeting (best effort). The token
        // may belong to a couple (tblaccounts_detail) or to a staff member
        // (tblusers) — tried in the same order PasswordReset resolved it.
        $first = '';
        $stmt  = $GLOBALS['db']->prepare(
            'SELECT d.firstname FROM tblaccounts a JOIN tblaccounts_detail d ON d.user_id = a.user_id '
            . 'WHERE LOWER(a.user_email) = :e LIMIT 1'
        );
        $stmt->execute([':e' => $email]);
        $first = (string) $stmt->fetchColumn();

        if ($first === '') {
            $stmt = $GLOBALS['db']->prepare(
                'SELECT firstname FROM tblusers WHERE LOWER(email) = :e LIMIT 1'
            );
            $stmt->execute([':e' => $email]);
            $first = (string) $stmt->fetchColumn();
        }

        PasswordReset::deliver($email, $first, $token);
    }

    // Identical response either way.
    redirect_to("forgot_password.php?sent=1");
    die();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Reset Password - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/modern.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700&display=swap" rel="stylesheet">
    <link href="css/signin.css" rel="stylesheet">
    <style>
        body {
            background:
                linear-gradient(180deg, rgba(11, 15, 20, .18) 0%, rgba(11, 15, 20, .62) 100%),
                url(images/carousel2.jpg) center / cover no-repeat !important;
        }
        .form-signin {
            background: rgba(255, 255, 255, .97) !important;
            border-radius: 28px !important;
            box-shadow: 0 28px 64px rgba(0, 0, 0, .16) !important;
            padding: 40px 38px 34px !important;
            max-width: 460px !important;
        }
        .form-signin h3 { font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800 !important; letter-spacing:-.02em; font-size:1.5rem !important; }
        .form-signin .form-control {
            background:#fff !important; border:1px solid var(--line) !important; border-radius:14px !important;
            padding:.82rem 1rem !important; font-size:15px !important; min-height:46px; margin:0 0 14px !important;
        }
        .form-signin .btn-success {
            background:var(--kenya-green) !important; border-color:var(--kenya-green) !important; border-radius:999px !important;
            font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800 !important; letter-spacing:.06em;
            text-transform:uppercase; padding:.9rem 1rem !important; box-shadow:0 6px 18px rgba(6,95,70,.26);
        }
    </style>
</head>
<body class="text-center">
<div class="form-signin" style="margin: 8vh auto;">
    <a href="index.php"><img class="mb-4" src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:96px;"></a>
    <h3 class="h3 mb-3 font-weight-normal">Reset your password</h3>
    <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success fade show" role="alert" style="font-size:14px;">
            <strong><i class='mdi mdi-email-check-outline'></i></strong>
            If that email is registered, a reset link is on its way. Check your inbox (and spam) — the link works for 60 minutes.
        </div>
        <a href="login.php" class="btn btn-success btn-block" style="font-size:14px;">Back to Login</a>
    <?php else: ?>
        <p class="login-sub text-muted" style="font-size:13.5px;">Enter the email you registered with and we'll send you a reset link.</p>
        <?php
            if ($session->message()) {
                echo $session->message();
            }
        ?>
        <form action="forgot_password.php" method="post">
            <?= $session->csrf_field(); ?>
            <label for="inputEmail" class="sr-only">Email address</label>
            <input type="email" id="inputEmail" name="input_email" class="form-control" placeholder="Email address" required autofocus>
            <button class="btn btn-lg btn-success btn-block" type="submit" name="forgot">Send Reset Link</button>
        </form>
        <p class="mt-4 mb-0 text-muted" style="font-size:13px;">
            Remembered it? <a href="login.php">Back to login</a>
        </p>
    <?php endif; ?>
    <p class="mt-4 mb-0 text-muted">&copy; <?= date('Y'); ?> Everafter Planner</p>
</div>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
