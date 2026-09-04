<?php include_once 'admin/include/init.php';?>
<?php
 if (isset($_POST['login'])) {
     if (!$session->verify_csrf()) {
         redirect_to("login.php");
         $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-alert'></i></strong>  Invalid security token. Please try again.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
         die();
     }
     $input_email = clean($_POST['input_email']);
     $input_password= clean($_POST['input_password']);
     $logged = Accounts::login_user($input_email, $input_password);

     if($logged) {
         $session->login($logged);
         redirect_to("admin/client.php");
     } else {
         redirect_to("login.php");
         $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-alert'></i></strong>  Invalid email and password. Please try again
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

    <title>Login Your Account</title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/modern.css" rel="stylesheet">
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
    </style>
</head>

<body class="text-center">
<form class="form-signin" action="login.php" method="post">
    <?= $session->csrf_field(); ?>
    <a href="index.php"><img class="mb-4" src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:104px;"></a>
    <h3 class="h3 mb-3 font-weight-normal">Login Your Account</h3>
    <p class="login-sub">Welcome back — sign in to access your account</p>
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
    <button class="btn btn-lg btn-success btn-block" type="submit" name="login">Login</button>
    <p class="mt-5 mb-3 text-muted">&copy; <?php echo date('Y')?> Everafter Planner</p>
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
