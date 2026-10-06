<?php include_once 'admin/include/init.php';?>
<?php
/**
 * One login for everyone. Credentials are tried against the client table
 * (tblaccounts) and the staff table (tblusers); on success the session is
 * bound and the user lands on the exact page their role needs:
 *
 *   access_level 2  -> admin/dashboard.php        (Super Admin)
 *   access_level 1  -> admin/dashboard.php        (Planner — also via
 *                      subscription.php first when no active subscription)
 *   client (tblaccounts) -> admin/client.php      (the couple's bookings page)
 */
 if (isset($_POST['login'])) {
     if (!$session->verify_csrf()) {
         $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-alert'></i></strong>  Invalid security token. Please try again.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
         redirect_to("login.php");
         die();
     }
     $input_email = clean($_POST['input_email']);
     $input_password= clean($_POST['input_password']);

     // Built-in brute-force throttle (tbllogin_throttle): too many failed
     // sign-ins for this (email, IP) pair — or from this IP overall — short-
     // circuits the attempt BEFORE any credential lookup. Checked before the
     // CSRF token too, so the lock can't be probed with tokenless requests.
     $throttle_wait = LoginThrottle::secondsRemaining($input_email);
     if ($throttle_wait > 0) {
         $mins = max(1, (int) ceil($throttle_wait / 60));
         // Repeat offenders serve longer windows — say "1 hour 5 min", not "65 minutes".
         $wait_text = $mins >= 60
             ? intdiv($mins, 60) . ' hour' . (intdiv($mins, 60) === 1 ? '' : 's') . ($mins % 60 > 0 ? ' ' . ($mins % 60) . ' min' : '')
             : $mins . ' minute' . ($mins === 1 ? '' : 's');
         error_log(sprintf(
             '[WPMS][LoginThrottle] blocked attempt email=%s ip=%s wait=%ds',
             $input_email,
             LoginThrottle::clientIp(),
             $throttle_wait
         ));
         $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-lock-alert'></i></strong>  Too many failed login attempts. Please try again in {$wait_text}.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
         redirect_to("login.php");
         die();
     }

     $redirect_after_login = 'admin/client.php'; // sensible default: a couple

     // Timing/eumeration guard: an unknown email must cost the same as a
     // known one. Without this, a real account's path includes a bcrypt
     // verify (~100ms) that a miss doesn't — response latency would confirm
     // which addresses are registered. $dummy_target is a fixed hash of a
     // constant string; verify() still fails, the cost is paid either way.
     // (A fixed dummy is deliberately a REAL bcrypt hash of the constant
     // string, so verify() pays the full bcrypt cost before failing.)
     $dummy_target = '$2y$10$DrX57xsHArMM1XyjxvVe3e.jI.v3hXKrvDkYuWHpnlwqVrN3WH83O';
     $known = Accounts::email_exists($input_email) || Users::email_exists($input_email);
     if (!$known) {
         password_verify('timing-guard', $dummy_target);
     }

     if ($logged = Accounts::login_user($input_email, $input_password)) {

         // Client account (tblaccounts).
         $session->login($logged);
         $_SESSION['account_type'] = 'client'; // distinguishes from staff rows with the same access_level 0
    } elseif ($logged = Users::user_account_login($input_email, $input_password)) {
        // Staff account (tblusers): admin (2), planner (1) or vendor (0).
        if ((int) ($logged->email_verified ?? 1) !== 0) {
            $session->login($logged);
            $_SESSION['account_type'] = 'staff';
            $level = (int) ($logged->access_level ?? 1);
            if ($level === 0) {
                // Vendor self-service: register first, then their dashboard.
                $redirect_after_login = vendor_redirect_target((int) $logged->id);
            } else {
                // Trial/grace/lockout-aware routing (login_redirect_target):
                // subscription.php on a trial's final day or when locked,
                // dashboard.php otherwise (banners tell the story).
                $redirect_after_login = login_redirect_target(true, (int) $logged->id, false);
            }
        } else {
            // Right password, unverified mailbox (v2.4 signup flow): no
            // session and NO throttle failure — the credentials were CORRECT.
            // The verify page carries the resend form.
            $session->message("
                <div class=\"alert alert-info alert-dismissible fade show\" role=\"alert\">
                  <strong><i class='mdi mdi-email-lock'></i></strong>  One more step: verify your email to activate your free trial — we can resend the link.
                  <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                    <span aria-hidden=\"true\">&times;</span>
                  </button>
                </div>");
            redirect_to('verify_planner.php?sent=1&email=' . urlencode($input_email));
            die();
        }
    }

     if ($logged) {
         LoginThrottle::clear($input_email); // legit user — drop the fail counters
         session_regenerate_id(true); // fresh ID at the privilege boundary
         redirect_to($redirect_after_login);
         die();
     }

     LoginThrottle::recordFailure($input_email);
     $session->message("
        <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
          <strong><i class='mdi mdi-alert'></i></strong>  Invalid email and password. Please try again
          <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
            <span aria-hidden=\"true\">&times;</span>
          </button>
        </div>");
     redirect_to("login.php");
 }

// Already signed in? Straight to this user's role home — never show the
// form to someone holding an active session (POST logins above still run
// first so switching accounts keeps working).
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
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Login Your Account</title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/modern.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700&display=swap" rel="stylesheet">
    <!-- Custom styles for this template -->
    <link href="css/signin.css" rel="stylesheet">
    <!-- Match the rounded hero-card look from css/modern.css -->
    <style>
        body {
            background:
                linear-gradient(180deg, rgba(11, 15, 20, .18) 0%, rgba(11, 15, 20, .62) 100%),
                url(images/carousel2.jpg) center / cover no-repeat !important;
        }
        .form-signin {
            background: rgba(255, 255, 255, .97) !important;
            backdrop-filter: blur(18px) saturate(160%);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            border: 1px solid rgba(255, 255, 255, .75) !important;
            border-radius: 28px !important;
            box-shadow: 0 28px 64px rgba(0, 0, 0, .16), 0 10px 24px rgba(0, 0, 0, .08) !important;
            padding: 40px 38px 34px !important;
            max-width: 460px !important;
            transform: scale(1.02);
        }
        .form-signin h3 {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-weight: 800 !important;
            letter-spacing: -.02em;
            color: var(--kenya-black) !important;
            font-size: 1.55rem !important;
            margin-bottom: 6px !important;
        }
        .form-signin .login-sub {
            color: var(--muted);
            font-size: 13.5px;
            font-weight: 500;
            margin: 0 0 22px;
        }
        .form-signin .form-control {
            background: #fff !important;
            border: 1px solid var(--line) !important;
            border-radius: 14px !important;
            padding: .82rem 1rem !important;
            font-size: 15px !important;
            min-height: 46px;
            margin: 0 0 14px !important;
        }
        .form-signin .form-control:focus {
            border-color: var(--kenya-green) !important;
            box-shadow: 0 0 0 4px rgba(6, 95, 70, .12) !important;
            outline: none;
        }
        .form-signin .checkbox {
            margin: 4px 0 18px;
        }
        .form-signin .checkbox label {
            color: var(--muted);
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin: 0;
        }
        .form-signin .btn-success {
            background: var(--kenya-green) !important;
            border-color: var(--kenya-green) !important;
            border-radius: 999px !important;
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-weight: 800 !important;
            font-size: 15px !important;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: .9rem 1rem !important;
            box-shadow: 0 6px 18px rgba(6, 95, 70, .26);
            transition: all .15s ease;
        }
        .form-signin .btn-success:hover {
            background: #0a7a5c !important;
            border-color: #0a7a5c !important;
            transform: translateY(-1px);
        }
        .form-signin p.text-muted {
            margin-top: 1.6rem !important;
            margin-bottom: 0;
        }
        @media (max-width: 768px) {
            .form-signin {
                transform: none !important;
                border-radius: 22px !important;
                padding: 30px 22px 26px !important;
            }
        }
        /* Neat pill login button — Everafter palette, gentle hover lift */
        .form-signin .btn-login {
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
        .form-signin .btn-login:hover, .form-signin .btn-login:focus {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(6, 95, 70, .34);
            filter: brightness(1.04);
            color: #fff;
        }
        .form-signin .btn-login:active { transform: translateY(0); }
    </style>
</head>

<body class="text-center">
<form class="form-signin" action="login.php" method="post">
    <?= $session->csrf_field(); ?>
    <a href="index.php"><img class="mb-4" src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:104px;"></a>
    <h3 class="h3 mb-3 font-weight-normal">Login Your Account</h3>
    <p class="login-sub">Welcome back — sign in to access your account.<br>One login for everyone: your account takes you to the right place.</p>
    <?php
        if ($session->message()) {
            echo $session->message();
        }
    ?>
    <label for="inputEmail" class="sr-only">Email address</label>
    <input type="text" id="inputEmail" name="input_email" class="form-control" placeholder="Email address" required autofocus>

    <label for="inputPassword" class="sr-only">Password</label>
    <input type="password" id="inputPassword" name="input_password" class="form-control" placeholder="Password" required>

    <div class="checkbox mb-1">
        <label>
            <input type="checkbox" value="remember-me" name="remember"> Remember me
        </label>
    </div>
    <button class="btn btn-lg btn-block btn-login" type="submit" name="login">
        <i class="mdi mdi-login-variant mr-1"></i> Login
    </button>
    <p class="mt-3 mb-0" style="font-size:13px;"><a href="forgot_password.php">Forgot your password?</a></p>
    <p class="mt-3 mb-0" style="font-size:13px;">Are you a <strong>wedding planner</strong>? <a href="planner_register.php">Start your 14-day free trial &rarr;</a></p>
    <p class="mt-4 mb-0 text-muted">&copy; <?php echo date('Y')?> Everafter Planner</p>
</form>
<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/jquery.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
    $('.your-checkbox').prop('indeterminate', true);
</script>
</body>
</html>
