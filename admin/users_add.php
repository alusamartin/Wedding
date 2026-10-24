<?php include 'include/init.php'; ?>
<?php


    require_staff_subscribed();

    $users = new Users();

    if (isset($_POST['submit'])) {

        // CSRF validation
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("users_add.php");
            die();
        }

        $firstname   = clean($_POST['firstname'] ?? '');
        $lastname    = clean($_POST['lastname'] ?? '');
        $address     = clean($_POST['address'] ?? '');
        $email       = clean($_POST['email'] ?? '');
        $username    = clean($_POST['username'] ?? '');
        $password    = clean($_POST['password'] ?? '');
        $password2   = clean($_POST['password2'] ?? '');
        $gender      = clean($_POST['gender'] ?? '');
        $designation = clean($_POST['designation'] ?? '');
        /*
         * tblusers.access_level is `enum('0','1','2') NOT NULL` with no default,
         * and this form had no field for it — so $users->access_level was never
         * set and the INSERT died with "Column 'access_level' cannot be null"
         * (HTTP 500) while the page still claimed the account was created.
         * The role is now chosen explicitly and validated against the enum.
         */
        $access_level = (string) ($_POST['access_level'] ?? '1');
        if (!in_array($access_level, ['0', '1', '2'], true)) {
            $access_level = '1'; // planner — the safe default for a new staff row
        }

         if (empty($firstname) || empty($lastname) || empty($address) || empty($email) || empty($username)) {
            $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-account-alert'></i></strong> Please Fill up all the information.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
            redirect_to("users_add.php");
            die();
        }

        if ($password != $password2) {

            $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-account-alert'></i></strong> Password input mismatched.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
            redirect_to("users_add.php");
            die();

        } else {

           $users->firstname = $firstname;
           $users->lastname = $lastname;
           $users->address = $address;
           $users->email = $email;
           $users->username = $username;
           // Use bcrypt password hashing
           $users->password = password_hash($password, PASSWORD_DEFAULT);
           $users->gender = $gender;
           $users->designation = $designation;
           $users->access_level = $access_level;
           // The avatar is optional: only hand set_file() a real upload. Passing
           // the raw $_FILES entry when nothing was chosen also emitted
           // "Undefined array key" / aborted the insert (see Users::save()).
           if (!empty($_FILES['profile_picture']['name'])) {
               $users->set_file($_FILES['profile_picture']);
           }
           $users->date_created = date("F j, Y, g:i a");

           if ($users->save()) {
               $session->message("
                <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
                  <strong><i class='mdi mdi-check'></i></strong> {$users->firstname} {$users->lastname}  is successfully added.
                  <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                    <span aria-hidden=\"true\">&times;</span>
                  </button>
                </div>");
               redirect_to("users.php");
           } else {
               // Never report success for a write that did not happen.
               $reason = !empty($users->errors) ? implode(' ', (array) $users->errors) : 'The account could not be saved.';
               $session->message("
                <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
                  <strong><i class='mdi mdi-alert'></i></strong> " . htmlspecialchars($reason) . "
                  <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                    <span aria-hidden=\"true\">&times;</span>
                  </button>
                </div>");
               redirect_to("users_add.php");
           }
        }
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Add New User - Administrator</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body {
            margin-bottom: 2%;
        }
        .box-shadow {
            box-shadow: 0 0 2px 1px rgba(0, 0, 0, 0.3);
            font-size: 12px;
        }
        .form-control {
            font-size: 12px;
        }
        .datepicker {
            font-size: 12px;
        }
    </style>
</head>

<body>

<?php include_once 'include/sidebar.php'; ?>

<div class="container">

    <div class="row">

        <div class="col-lg-8 offset-2 pl-3 pb-3 box-shadow mt-4">
        
            <form method="post" action="" enctype="multipart/form-data">
                <?= $session->csrf_field(); ?>

                <h4 class="h4 mt-4 pb-2" style="border-bottom: 1px solid #dee2e6!important;">New User Information
                    <a href="users.php" class="btn btn-sm btn-danger float-right" style="font-size: 12px;"><i class="mdi mdi-close-circle mr-2"></i> Cancel</a>

                    <button type="submit" name="submit" class="btn btn-sm btn-success float-right mr-2" style="font-size: 12px;"><i class="mdi mdi-account-plus mr-2"></i> Save User</button>
                </h4>

                <?php
                    if ($session->message()) {
                        echo $session->message();
                    }
                ?>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="inputFirstname">Firstname:</label>
                        <input type="text" name="firstname" class="form-control" id="inputFirstname"  placeholder="Enter Firstname">
                    </div>
                   <div class="form-group col-md-6">
                        <label for="inputLastname">Lastname:</label>
                        <input type="text" name="lastname" class="form-control" id="inputLastname"  placeholder="Enter Lastname">
                    </div>
                   
                </div>
                
                <div class="form-group">
                    <label for="inputEmail">Email:</label>
                    <input type="text" name="email" class="form-control" id="inputEmail" placeholder="Enter Email">
                </div>

                <div class="form-group">
                    <label for="inputUsername">Username:</label>
                    <input type="text" name="username" class="form-control" id="inputUsername" placeholder="Enter Username">
                </div>

                <div class="form-group">
                    <label for="inputpassword">Password:</label>
                    <input type="password" name="password" class="form-control" id="inputpassword" placeholder="Enter Password">
                </div>

                <div class="form-group">
                    <label for="inputpasswordConfirm">Confirm Password:</label>
                    <input type="password" name="password2" class="form-control" id="inputpasswordConfirm" placeholder="Confirm password">
                </div>


                 <div class="form-group">
                        <label for="gender">Gender:</label>
                        <select name="gender" class="custom-select" id="gender">
                            <option value="m">Male</option>
                            <option value="f">Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="access_level">Role:</label>
                        <select name="access_level" class="custom-select" id="access_level">
                            <option value="1" selected>Wedding Planner (staff)</option>
                            <option value="0">Vendor Lead (vendor sign-in)</option>
                            <option value="2">Super Admin (operator)</option>
                        </select>
                    </div>

                <div class="form-group">
                    <label for="inputAddress">Address</label>
                    <textarea rows="5" name="address" class="form-control" id="inputAddress"  placeholder="Enter Address"></textarea>
                </div>

                 <div class="form-group">
                    <label for="designation">Designation:</label>
                    <select name="designation" id="designation" class="custom-select">
                        <option value="0">Administrator</option>
                        <option value="1">Moderator</option>
                    </select>
                </div>
                  <div class="form-group">
                    <label for="inputProfilePicture">Profile Image</label>
                    <input type="file" name="profile_picture" class="form-control-file" id="inputProfilePicture">
                  </div>
            </form><!-- end of input form -->
        </div>
    </div>
</div>




</main>
</div>
</div>

<!-- Bootstrap core JavaScript
================================================== -->
<!-- Placed at the end of the document so the pages load faster -->
<script src="js/jquery-3.7.1.slim.min.js"></script>
<script>window.jQuery || document.write('<script src="../../../../assets/js/vendor/jquery-slim.min.js"><\/script>')</script>
<script src="js/popper.min.js"></script>
<script src="../js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
<script>

    $(document).ready(function() {
        $('[data-toggle="tooltip"]').tooltip();
    });

</script>

</body>
</html>
