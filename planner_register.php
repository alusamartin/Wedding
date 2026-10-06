<?php include_once 'admin/include/init.php';?>
<?php
/**
 * Self-serve planner registration — the SaaS funnel's front door.
 *
 * A wedding planner fills name/email/phone/password and gets a staff account
 * that is UNVERIFIED: no trial, no sign-in yet. The account is activated by
 * proving the mailbox — the token link emailed here opens verify_planner.php,
 * which stamps the account verified, STARTS the 14-day trial (WPMS_TRIAL_DAYS)
 * and signs the planner straight into subscription.php, the activation page.
 * No admin seeds the account; no unproven mailbox gets free entitlements.
 *
 * Guards: CSRF, per-IP/per-email signup throttle (successes COUNT — the
 * flood itself is the attack), email format + uniqueness across BOTH account
 * tables, password >= 8 chars. No account enumeration: a blocked or
 * duplicate signup shows the same shaped error as any other refusal.
 */
if (isset($_POST['register_planner'])) {
    if (!$session->verify_csrf()) {
        $session->message('
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <strong><i class=\'mdi mdi-alert\'></i></strong>  Invalid security token. Please try again.
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>');
        redirect_to("planner_register.php");
        die();
    }

    $email = clean($_POST['email'] ?? '');

    // Throttle BEFORE doing any work; record EVERY attempt (success or not)
    // afterwards — the flood itself is the signal.
    $wait = SignupGuard::secondsRemaining($email);
    if ($wait > 0) {
        $mins = max(1, (int) ceil($wait / 60));
        $session->message("
            <div class=\"alert alert-warning alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-clock-alert-outline'></i></strong>  Too many sign-up attempts. Please try again in {$mins} minute(s).
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
        redirect_to("planner_register.php");
        die();
    }

    $result = register_planner(
        clean($_POST['firstname'] ?? ''),
        clean($_POST['lastname'] ?? ''),
        $email,
        clean($_POST['phone'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );
    SignupGuard::recordAttempt($email, (bool) ($result['ok'] ?? false));

    if ($result['ok']) {
        // Issue + deliver the verification token. A rate-limited/failed issue
        // still shows the check-inbox panel — the account exists and a retry
        // (or the resend form on the verify page) recovers it.
        $token = SignupGuard::issueVerification((int) $result['user_id']);
        if ($token !== null) {
            SignupGuard::deliver($email, clean($_POST['firstname'] ?? ''), $token);
        }
        redirect_to("verify_planner.php?sent=1&email=" . urlencode($email));
        die();
    }

    $session->message('
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <i class="mdi mdi-alert mr-2"></i>' . htmlspecialchars($result['error'] ?? 'Registration failed.') . '
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>');
    redirect_to("planner_register.php");
}

// Already signed in? To your role home — never show the form twice.
if ($session->is_signed_in() && isset($_SESSION['id'])) {
    if (is_vendor()) {
        redirect_to(vendor_redirect_target((int) $_SESSION['id']));
    } else {
        redirect_to(login_redirect_target(is_staff(), (int) $_SESSION['id']));
    }
    die();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Start your 14-day free Everafter Planner trial — the Kenyan wedding planning back office.">
    <meta name="author" content="">

    <title>Planner Sign Up — Start Your Free Trial</title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/modern.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">

    <style>
        :root {
            --kenya-green: #065F46;
            --kenya-black: #0F172A;
            --muted: #64748B;
            --line: #E2E8F0;
        }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(1200px 500px at 85% -10%, rgba(6, 95, 70, .14), transparent 60%),
                linear-gradient(160deg, #FAF9F6 0%, #F1F5F3 100%);
            padding: 24px 12px;
            font-family: 'Inter', system-ui, sans-serif;
        }
        .form-signup {
            width: 100%;
            max-width: 460px;
            background: #fff;
            border-radius: 28px !important;
            box-shadow: 0 28px 64px rgba(0, 0, 0, .16), 0 10px 24px rgba(0, 0, 0, .08) !important;
            padding: 40px 38px 34px !important;
        }
        .form-signup h3 {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-weight: 800 !important;
            letter-spacing: -.02em;
            color: var(--kenya-black) !important;
            font-size: 1.55rem !important;
            margin-bottom: 6px !important;
        }
        .form-signup .login-sub {
            color: var(--muted);
            font-size: 13.5px;
            font-weight: 500;
            margin: 0 0 22px;
        }
        .form-signup .form-control {
            background: #fff !important;
            border: 1px solid var(--line) !important;
            border-radius: 14px !important;
            padding: .82rem 1rem !important;
            font-size: 15px !important;
            min-height: 46px;
            margin: 0 0 14px !important;
        }
        .form-signup .form-control:focus {
            border-color: var(--kenya-green) !important;
            box-shadow: 0 0 0 4px rgba(6, 95, 70, .12) !important;
            outline: none;
        }
        .trial-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #E7F4EE;
            border: 1px solid #A7D7BF;
            color: #065F46;
            border-radius: 999px;
            padding: 5px 14px;
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 14px;
        }
        .form-signup .btn-login {
            background: linear-gradient(135deg, #065F46 0%, #059669 100%);
            border: 0;
            color: #fff;
            border-radius: 999px;
            padding: .8rem 1.4rem;
            font-weight: 600;
            font-size: 15.5px;
            letter-spacing: .01em;
            box-shadow: 0 8px 20px rgba(6, 95, 70, .28);
            transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
        }
        .form-signup .btn-login:hover, .form-signup .btn-login:focus {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(6, 95, 70, .34);
            filter: brightness(1.04);
            color: #fff;
        }
        .form-signup .btn-login:active { transform: translateY(0); }
    </style>
</head>

<body>
<form class="form-signup" action="planner_register.php" method="post">
    <?= $session->csrf_field(); ?>
    <div class="text-center">
        <a href="index.php"><img class="mb-3" src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:88px;"></a>
        <div class="trial-badge"><i class="mdi mdi-gift-outline"></i> 14 days free · every feature · no card needed</div>
        <h3 class="h3 mb-2 font-weight-normal">Planners — Start Here</h3>
        <p class="login-sub">Run your wedding business: clients, guests, budgets, vendors, M-Pesa.
            Your free trial opens the full back office — activate a plan when you're ready.</p>
    </div>
    <?php
        if ($session->message()) {
            echo $session->message();
        }
    ?>
    <div class="form-row">
        <div class="form-group col-6">
            <label for="firstname" class="sr-only">First name</label>
            <input type="text" id="firstname" name="firstname" class="form-control" placeholder="First name" required autofocus maxlength="32">
        </div>
        <div class="form-group col-6">
            <label for="lastname" class="sr-only">Last name</label>
            <input type="text" id="lastname" name="lastname" class="form-control" placeholder="Last name" required maxlength="32">
        </div>
    </div>
    <label for="email" class="sr-only">Email address</label>
    <input type="email" id="email" name="email" class="form-control" placeholder="you@yourbusiness.co.ke" required>
    <label for="phone" class="sr-only">M-Pesa number</label>
    <input type="tel" id="phone" name="phone" class="form-control" placeholder="M-Pesa number (e.g. 0712 345 678)" maxlength="20">
    <label for="password" class="sr-only">Password</label>
    <input type="password" id="password" name="password" class="form-control" placeholder="Create Password (min 8 chars)" autocomplete="new-password" minlength="8" required>

    <button class="btn btn-lg btn-block btn-login mt-2" type="submit" name="register_planner">
        <i class="mdi mdi-rocket-launch-outline mr-1"></i> Start my free trial
    </button>
    <p class="mt-3 mb-0 text-center" style="font-size:12.5px; color:var(--muted);">
        After 14 days, plans start at <strong>KSh 2,999/mo</strong> — cancel any time.
    </p>
    <p class="mt-3 mb-0 text-center" style="font-size:13px;">Already have an account? <a href="login.php">Sign in</a></p>
    <p class="mt-4 mb-0 text-center text-muted" style="font-size:13px;">Couple planning a wedding? <a href="index.php#sign-up">Register your wedding here</a></p>
    <p class="mt-4 mb-0 text-center text-muted">&copy; <?php echo date('Y')?> Everafter Planner</p>
</form>
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
