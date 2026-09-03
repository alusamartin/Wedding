<?php include_once 'include/init.php';?>
<?php
 if (isset($_POST['login'])) {

     // CSRF validation
     if (!$session->verify_csrf()) {
         $session->message('<div class="alert alert-danger alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
         redirect_to("login.php");
         die();
     }

     $input_email = clean($_POST['input_email']);
     $input_password= clean($_POST['input_password']);
     $logged = Users::user_account_login($input_email, $input_password);

     if($logged) {
         $session->login($logged);
         
         // Check subscription status
         $sub = Subscription::get_active($logged->id);
         if (!$sub) {
             // First login or no subscription — go to subscription page
             redirect_to("subscription.php");
         } else {
             redirect_to("dashboard.php");
         }
     } else {
         redirect_to("login.php");
         $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-alert'></i></strong>  Invalid email or password. Please try again
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
     }
 }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Administrator Login Your Account</title>

    <!-- Bootstrap core CSS -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="../css/signin.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700&display=swap" rel="stylesheet">
    <style>
        body {
            background: radial-gradient(1200px 600px at 20% -10%, #065F46 0%, transparent 60%), radial-gradient(900px 500px at 100% 0%, #D97706 0%, transparent 55%), linear-gradient(180deg, #0F172A 0%, #0B1220 100%) !important;
            min-height:100vh;
            display:flex; align-items:center; justify-content:center;
        }
        /* Card matches the public login (login.php) hero-card look, with colors inlined (modern.css vars unavailable here) */
        .form-signin {
            background: rgba(255,255,255,.97) !important;
            backdrop-filter: blur(18px) saturate(160%);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            border: 1px solid rgba(255,255,255,.75) !important;
            border-radius: 28px !important;
            box-shadow: 0 28px 64px rgba(0,0,0,.16), 0 10px 24px rgba(0,0,0,.08) !important;
            padding: 40px 38px 34px !important;
            max-width: 460px !important;
            transform: scale(1.02);
        }
        .form-signin h5 {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-weight: 800 !important;
            letter-spacing: -.02em;
            color: #0B0F14 !important;
            font-size: 1.55rem !important;
            margin-bottom: 6px !important;
        }
        .form-signin .admin-sub {
            color: #78716C;
            font-size: 13.5px;
            font-weight: 500;
            margin: 0 0 22px;
        }
        .form-signin .form-control {
            background: #fff !important;
            border: 1px solid #E7E5E4 !important;
            border-radius: 14px !important;
            padding: .82rem 1rem !important;
            font-size: 15px !important;
            min-height: 46px;
            margin: 0 0 14px !important;
        }
        .form-signin .form-control:focus {
            border-color: #065F46 !important;
            box-shadow: 0 0 0 4px rgba(6,95,70,.12) !important;
            outline: none;
        }
        .form-signin .btn-danger {
            background: #065F46 !important;
            border-color: #065F46 !important;
            border-radius: 999px !important;
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-weight: 800 !important;
            font-size: 15px !important;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: .9rem 1rem !important;
            box-shadow: 0 6px 18px rgba(6,95,70,.26);
            transition: all .15s ease;
        }
        .form-signin .btn-danger:hover {
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
    </style>
</head>

<body class="text-center">
<form class="form-signin" action="" method="post">
    <?= $session->csrf_field(); ?>
    <a href="../index.php"><img class="mb-4" src="../images/logo/everafter-crest.png" alt="Everafter Planner" style="height:104px;"></a>
    <div class="secure-badge" style="display:inline-flex; align-items:center; gap:.35rem; background:#FFFBEB; border:1px solid #FDE68A; color:#92400E; font-size:11px; font-weight:600; padding:4px 10px; border-radius:999px; margin:0 auto 14px;"><i class="mdi mdi-shield-check-outline"></i> Secure · 15-min rotation · KSh</div>
    <h5 class="h5 mb-2 font-weight-normal" style="color:#0B0F14; font-family:'Plus Jakarta Sans',sans-serif; font-weight:800; letter-spacing:-.02em;">Everafter Planner</h5>
    <p class="admin-sub">Nairobi · Mombasa · Kisumu — M-Pesa ready</p>
    <?php
        if ($session->message()) {
            echo $session->message();
        }
    ?>
    <label for="inputEmail" class="sr-only">Email address</label>
    <input type="text" id="inputEmail" name="input_email" class="form-control" placeholder="Email Address" required autofocus>

    <label for="inputPassword" class="sr-only">Password</label>
    <input type="password" id="inputPassword" name="input_password" class="form-control" placeholder="Password" required>

    <button class="btn btn-md btn-danger btn-block" type="submit" name="login">Log In</button>
    <p class="mt-5 mb-3 text-muted">&copy; <?php echo date('Y')?> - Everafter Planner</p>
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
