<?php include 'include/init.php'; ?>
<?php

    $count = 0;
    $error = '';
    $firstname = $lastname = $email = $wedding_date = $bride = $groom = $phone = $city = '';
    if (!is_staff()) { redirect_to("../"); }

    // $booking_id = $_GET['booking'];
    // $user_id = $_GET['user_id'];
    $category = Category::find_all();

    $accounts =  new Accounts();
    $account_detail =  new Account_Details();
    $booking_detail =  new Booking();

    if (isset($_POST['submit'])) {

        // CSRF validation (this handler creates accounts — it must never run
        // from a forged cross-site POST).
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("client_add.php");
            die();
        }

        $user_password1 = (string) ($_POST['user_password1'] ?? '');
        $user_password2 = (string) ($_POST['user_password2'] ?? '');

        if ($user_password1 !== $user_password2 || strlen($user_password1) < 8) {
            $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-alert-circle-outline'></i></strong>  Passwords must match and be at least 8 characters.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
              redirect_to("client_add.php");
            die();
        }

        $firstname = clean($_POST['firstname'] ?? '');
        $lastname = clean($_POST['lastname'] ?? '');
        $email = clean($_POST['email'] ?? '');
        $wedding_date = clean($_POST['wedding_date'] ?? '');
        $bride = clean($_POST['bride'] ?? '');
        $groom = clean($_POST['groom'] ?? '');
        $phone = clean($_POST['phone'] ?? '');
        $city = clean($_POST['city'] ?? '');
        $wedding_type = (int) clean($_POST['wedding_type'] ?? '0');
        $organizer_id = (int) clean($_POST['organizer_id'] ?? '0');

        if (empty($firstname) || empty($lastname) || empty($email) || empty($bride) || empty($groom)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $session->message("
            <div class=\"alert alert-danger alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-account-alert mr-2'></i></strong> Please fill in all required fields with a valid email.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
            redirect_to("client_add.php");
            die();
        }

        if (Accounts::email_exists($email)) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That email is already registered.</div>');
            redirect_to("client_add.php");
            die();
        }

        // Create account + detail + booking atomically (bcrypt-hashed password,
        // consistent user_id linkage) via the shared registration routine.
        $result = register_client($firstname, $lastname, $email, $phone, $user_password1, $wedding_date, $wedding_type);
        if (!$result['ok']) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($result['error']) . '</div>');
            redirect_to("client_add.php");
            die();
        }

        // Enrich the fresh booking/detail rows with the staff-only fields
        // (register_client seeds them neutral: no bride/groom, organizer 0).
        $uid = (int) $db->fetchValue('SELECT user_id FROM tblaccounts WHERE user_email = :e LIMIT 1', [':e' => $email]);
        if ($uid > 0) {
            $booking_row = Booking::find_by_user_id($uid);
            if ($booking_row !== false) {
                $booking_row->bride = $bride;
                $booking_row->groom = $groom;
                $booking_row->organizer_id = $organizer_id;
                $booking_row->update_booking();
            }
            $detail_row = Account_Details::find_by_user_id($uid);
            if ($detail_row !== false && $city !== '') {
                $detail_row->city = $city;
                $detail_row->update_user();
            }
        }

        $session->message("
        <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
          <strong><i class='mdi mdi-check-circle-outline'></i></strong>  New client has been added.
          <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
            <span aria-hidden=\"true\">&times;</span>
          </button>
        </div>");
        redirect_to("client.php");
        die();
    }


?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Add New Client Information - Administrator</title>
        <link href="css/bootstrap.min.css" rel="stylesheet">        <link href="css/dashboard.css" rel="stylesheet">
        <link href="css/modern-admin.css" rel="stylesheet">
        <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
        <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
        <link rel="stylesheet" href="../css/bootstrap-datepicker.css">
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

<?php include 'include/sidebar.php'; ?>

    <div class="container">

        <div class="row">

            <div class="col-lg-8 offset-2 pl-3 pb-3 box-shadow mt-4">
            
                <form action="client_add.php" method="post">
                    <?= $session->csrf_field(); ?>
                    <h4 class="h4 mt-4 pb-2" style="border-bottom: 1px solid #dee2e6!important;">New Client Information
                        <a href="client.php" class="btn btn-sm btn-danger float-right" style="font-size: 12px;"><i class="mdi mdi-close-circle mr-2"></i> Cancel</a>
                        <button type="submit" name="submit" class="btn btn-sm btn-success float-right mr-2" style="font-size: 12px;"><i class="mdi mdi-account-plus mr-2"></i> Save Client</button>
                    </h4>
                    <div class="form-row">
                        <?php
                            if ($session->message()) {
                                echo ' <div class="form-group col-md-12">' . $session->message() . '</div>';
                            }
                        ?>
                        <div class="form-group col-md-6">
                            <label for="">Firstname</label>
                            <input type="text" name="firstname" 
                            class="form-control" 
                            id="inputFirstname"  
                            placeholder="Enter firstname" 
                            value="<?= $firstname;?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="">Lastname</label>
                            <input type="text" name="lastname" class="form-control" id="inputLastname" placeholder="Enter lastname">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="">Email</label>
                        <input type="text" name="email" class="form-control" id="inputEmail" placeholder="Enter email">
                    </div>

                    <!-- <div class="form-group">
                        <label for="">Password</label>
                        <input type="password" name="user_password1" class="form-control" id="inputPassword1"  placeholder="Enter email">
                    </div>

                    <div class="form-group">
                        <label for="">Confirm Password</label>
                        <input type="password" name="user_password2" class="form-control" id="inputPassword2" placeholder="Re-enter password">
                    </div> -->

                    <div class="form-row form-group">

                        <div class="col-md-6">
                            <label for="wedding_date">Wedding Date</label>
                        </div>

                        <div class="col-md-6">
                            <label for="wedding_type">Wedding Type</label>
                        </div>

                        <div class="input-group col-md-6">
                            <input type="text" class="form-control"
                                   name="wedding_date" value="<?= $booking_detail->wedding_date; ?>" data-provide="datepicker" id="wedding_date"
                                   placeholder="Wedding Date">
                            <div class="input-group-append">
                                    <span class="input-group-text"
                                          style="background: white;">
                                        <i style="color:#19b5bc;" class="mdi mdi-calendar-check"
                                            id="review" aria-hidden="true"></i>
                                    </span>
                            </div>
                        </div>

                        <div class="input-group col-md-6">
                            <select class="custom-select form-control" id="wedding_type" name="wedding_type">
                                <?php foreach($category as $category_item) : ?>
                                    <option value="<?= $category_item->id; ?>" selected><?= ucfirst($category_item->wedding_type); ?></option>
                                <?php endforeach; ?>
                              </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="">Bride's name</label>
                        <input type="text" name="bride" class="form-control" value="<?= $booking_detail->bride; ?>" id="brideName" placeholder="Enter Bride's Name">
                    </div>
                    <div class="form-group">
                        <label for="">Groom's name</label>
                        <input type="text" name="groom" class="form-control" value="<?= $booking_detail->groom; ?>" id="GroomsName" placeholder="Enter Groom's Name">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="">Phone</label>
                            <input type="text" class="form-control" value="<?= $account_detail->phone; ?>" id="inputPhone" name="phone" placeholder="Enter Phone Number">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="">City</label>
                            <input type="text" class="form-control" value="<?= $account_detail->city; ?>" id="inputcity" name="city" placeholder="Enter City">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="">Assigned Organizer</label>
                            <select class="form-control" id="inputOrganizer" name="organizer_id">
                                <option value="1">Big Day Planners</option>
                                <option value="2">Joyful Events</option>
                                <option value="3">Roses and Co</option>
                            </select>
                        </div><!-- form-group col-md-6 -->
                    </div><!-- end of form-row -->
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
<script src="js/jquery-3.2.1.slim.min.js"></script>
<script>window.jQuery || document.write('<script src="../../../../assets/js/vendor/jquery-slim.min.js"><\/script>')</script>
<script src="js/popper.min.js"></script>
<script src="../js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="../js/bootstrap-datepicker.min.js"></script>
<script>
  
    $(document).ready(function() {
        $('#wedding_date').datepicker();
        $('[data-toggle="tooltip"]').tooltip();
    });
    
</script>

</body>
</html>

