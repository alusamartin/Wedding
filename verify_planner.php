<?php include_once 'admin/include/init.php';?>
<?php
/**
 * Planner email verification — the gate between signup and the trial.
 *
 *  GET ?token=... : consume the single-use token (SignupGuard::verify) —
 *                   stamp the account verified, START the free trial
 *                   (WPMS_TRIAL_DAYS), auto-sign-in and route to
 *                   subscription.php, the activation page.
 *  GET ?sent=1    : "check your inbox" panel shown after registering.
 *  POST resend    : rate-limited re-issue of a fresh link for an UNVERIFIED
 *                   account (SignupGuard::unverified_by_email). Deliberately
 *                   no enumeration: verified/unknown addresses get the same
 *                   neutral confirmation.
 *
 * An invalid/expired token lands here too, with the resend form to recover.
 */
$prefillEmail = isset($_GET['email']) ? (string)($_GET['email'] ?? '') : '';
$showResend   = false;

// Signed-in and verified? Straight to the role home — the token is moot.
if ($session->is_signed_in() && isset($_SESSION['id']) && is_staff()) {
    redirect_to(login_redirect_target(true, (int) $_SESSION['id'], true));
    die();
}

// Resend POST: PRG, CSRF-guarded, no enumeration.
if (isset($_POST['resend_verification'])) {
    if ($session->verify_csrf()) {
        $row = SignupGuard::unverified_by_email(clean($_POST['email'] ?? ''));
        if ($row !== null) {
            $token = SignupGuard::issueVerification((int) $row->id);
            if ($token !== null) {
                SignupGuard::deliver((string) $row->email, (string) $row->firstname, $token);
            }
        }
        $session->message("
            <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-email-check-outline'></i></strong>  If that address still needs verification, a fresh link is on its way.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
    } else {
        $session->message('
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <strong><i class=\'mdi mdi-alert\'></i></strong>  Invalid security token. Please try again.
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>');
    }
    redirect_to('verify_planner.php?resent=1');
    die();
}

// Token consumption: the actual activation.
$token = isset($_GET['token']) ? trim((string)($_GET['token'] ?? '')) : '';
if ($token !== '') {
    $row = SignupGuard::verify($token);
    if ($row !== false) {
        // The trial starts NOW — at proof-of-mailbox, not at signup.
        $trial = Subscription::activate_trial((int) $row->user_id);
        if ($trial !== null) {
            // Welcome aboard: trial end date + getting-started steps, through
            // the shared outbox (pending/retryable when the transport is off).
            SignupGuard::send_welcome(
                (string) $row->email,
                (string) $row->firstname,
                date('j M Y', strtotime((string) $trial->expiry_date))
            );
        }
        $account = Users::find_by_id((int) $row->user_id);
        if ($account) {
            // Auto-login: same privilege-boundary discipline as login.php.
            $session->login($account);
            $_SESSION['account_type'] = 'staff';
            session_regenerate_id(true);
        }
        $days = Subscription::trial_days();
        $session->message("
            <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-party-popper'></i></strong>  Karibu" . ($row->firstname !== '' ? ', ' . htmlspecialchars((string) $row->firstname) : '') . "! Email verified — your {$days}-day free trial is live.
              Activate a plan below any time before it ends to keep full access.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
        redirect_to('admin/subscription.php');
        die();
    }
    // Bad/expired token — fall through to the recovery panel below.
    $session->message('
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong><i class=\'mdi mdi-link-off\'></i></strong>  That verification link is invalid or has expired. Enter your email below and we\'ll send a fresh one.
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>');
    $showResend = true;
} elseif (isset($_GET['sent'])) {
    $session->message("
        <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
          <strong><i class='mdi mdi-email-newsletter'></i></strong>  Almost there! We've emailed you a verification link. Open it to start your 6-month free trial.
          <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
            <span aria-hidden=\"true\">&times;</span>
          </button>
        </div>");
    $showResend = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verify Your Email — Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/modern.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <style>
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(160deg, #FAF9F6 0%, #F1F5F3 100%);
            padding: 24px 12px; font-family: 'Inter', system-ui, sans-serif;
        }
        .form-signup {
            width: 100%; max-width: 460px; background: #fff; border-radius: 28px !important;
            box-shadow: 0 28px 64px rgba(0,0,0,.16), 0 10px 24px rgba(0,0,0,.08) !important;
            padding: 40px 38px 34px !important;
        }
        .form-signup h3 {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; font-weight: 800 !important;
            letter-spacing: -.02em; color: #0F172A !important; font-size: 1.45rem !important;
            margin-bottom: 6px !important;
        }
        .form-signup .form-control {
            background: #fff !important; border: 1px solid #E2E8F0 !important;
            border-radius: 14px !important; padding: .82rem 1rem !important; font-size: 15px !important;
            min-height: 46px; margin: 0 0 14px !important;
        }
        .form-signup .btn-login {
            background: linear-gradient(135deg, #065F46 0%, #059669 100%); border: 0; color: #fff;
            border-radius: 999px; padding: .8rem 1.4rem; font-weight: 600; font-size: 15.5px;
            box-shadow: 0 8px 20px rgba(6,95,70,.28);
        }
    </style>
</head>
<body>
<div class="form-signup">
    <div class="text-center">
        <a href="index.php"><img class="mb-3" src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:88px;"></a>
        <h3 class="h3 mb-2 font-weight-normal">Verify Your Email</h3>
        <p class="mb-3" style="color:#64748B; font-size:13.5px;">
            One click proves your inbox, starts your free trial and unlocks the back office.
        </p>
    </div>
    <?php
        if ($session->message()) {
            echo $session->message();
        }
    ?>
    <?php if ($showResend) { ?>
    <form action="verify_planner.php" method="post" class="mt-2">
        <?= $session->csrf_field(); ?>
        <label for="email" class="sr-only">Email address</label>
        <input type="email" id="email" name="email" class="form-control" placeholder="you@yourbusiness.co.ke"
               required value="<?= htmlspecialchars($prefillEmail, ENT_QUOTES) ?>">
        <button class="btn btn-lg btn-block btn-login" type="submit" name="resend_verification">
            <i class="mdi mdi-email-send-outline mr-1"></i> Resend verification link
        </button>
    </form>
    <?php } else { ?>
    <p class="text-center mb-0" style="font-size:13.5px; color:#64748B;">
        Waiting for your email? Open the link we sent you. Didn't get one?
        <a href="verify_planner.php?sent=1">Resend it here</a>.
    </p>
    <?php } ?>
    <p class="mt-4 mb-0 text-center" style="font-size:13px;">
        <a href="planner_register.php">Back to sign up</a> &middot; <a href="login.php">Sign in</a>
    </p>
    <p class="mt-4 mb-0 text-center text-muted">&copy; <?php echo date('Y')?> Everafter Planner</p>
</div>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
