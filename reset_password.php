<?php include_once 'admin/include/init.php'; ?>
<?php
$plain = clean($_POST['token'] ?? $_GET['token'] ?? '');
$row   = $plain !== '' ? PasswordReset::select_valid($plain) : false;

// Invalid/expired/consumed token → dedicated state, not a PHP warning.
$tokenDead = $row === false && (isset($_GET['token']) || isset($_POST['token']));

if (isset($_POST['reset'])) {
    if (!$session->verify_csrf()) {
        $session->message(
            "<div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">"
            . "<strong><i class='mdi mdi-alert'></i></strong> Invalid security token. Please try again."
            . "<button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button></div>"
        );
        redirect_to("reset_password.php?token=" . urlencode($plain));
        die();
    }
    $p1 = (string) ($_POST['input_password'] ?? '');
    $p2 = (string) ($_POST['input_password2'] ?? '');

    if ($row === false) {
        redirect_to("forgot_password.php?sent=1");
        die();
    }
    if (strlen($p1) < 8) {
        $inlineError = 'Password must be at least 8 characters.';
    } elseif ($p1 !== $p2) {
        $inlineError = 'The two passwords don\'t match.';
    } else {
        if (PasswordReset::reset_password($plain, $p1)) {
            $session->message(
                "<div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">"
                . "<strong><i class='mdi mdi-check-circle'></i></strong> Password updated. Log in with your new password."
                . "<button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button></div>"
            );
            redirect_to("login.php");
            die();
        }
        $session->message(
            "<div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">"
            . "<strong><i class='mdi mdi-alert'></i></strong> This reset link is no longer valid. Request a new one."
            . "<button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\"><span aria-hidden=\"true\">&times;</span></button></div>"
        );
        redirect_to("forgot_password.php");
        die();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Choose a New Password - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
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

    <?php if ($tokenDead || $row === false): ?>
        <h3 class="h3 mb-3 font-weight-normal">Link expired</h3>
        <div class="alert alert-warning fade show" role="alert" style="font-size:14px;">
            <strong><i class='mdi mdi-clock-alert-outline'></i></strong>
            This reset link is invalid or has expired (they last 60 minutes, and can only be used once).
        </div>
        <a href="forgot_password.php" class="btn btn-success btn-block" style="font-size:14px;">Request a New Link</a>
    <?php else: ?>
        <h3 class="h3 mb-3 font-weight-normal">Choose a new password</h3>
        <p class="login-sub text-muted" style="font-size:13.5px;">for <strong><?= htmlspecialchars((string) $row->user_email); ?></strong></p>
        <?php
            // Inline error: the message set during THIS POST can't go through
            // $session->message() (the constructor already consumed the session
            // slot before the handler ran) — render it directly instead.
            if (!empty($inlineError)): ?>
            <div class="alert alert-danger fade show" role="alert" style="font-size:14px;">
                <strong><i class='mdi mdi-alert'></i></strong> <?= htmlspecialchars($inlineError); ?>
            </div>
        <?php endif; ?>
        <form action="reset_password.php" method="post">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($plain, ENT_QUOTES); ?>">
            <label for="inputPassword" class="sr-only">New password</label>
            <input type="password" id="inputPassword" name="input_password" class="form-control" placeholder="New password (min 8 chars)" minlength="8" required autofocus>
            <label for="inputPassword2" class="sr-only">Confirm new password</label>
            <input type="password" id="inputPassword2" name="input_password2" class="form-control" placeholder="Confirm new password" minlength="8" required>
            <button class="btn btn-lg btn-success btn-block" type="submit" name="reset">Update Password</button>
        </form>
    <?php endif; ?>
    <p class="mt-4 mb-0 text-muted">&copy; <?= date('Y'); ?> Everafter Planner</p>
</div>
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
