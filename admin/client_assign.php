<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); }

    $booking_id = (int)($_GET['booking'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);

    $accounts = Accounts::find_by_user_id($user_id);
    $account_detail = Account_Details::find_by_user_id($user_id);
    $booking_detail = Booking::find_by_booking_id($booking_id);
    $categories = Category::find_all();
    
    if (isset($_POST['confirm'])) {

        // CSRF validation
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("client_assign.php?booking=$booking_id&user_id=$user_id");
            die();
        }

        if ($booking_detail) {
            $firstname = clean($_POST['firstname']);
            $lastname = clean($_POST['lastname']);
            $email = clean($_POST['email']);
            $wedding_date = clean($_POST['wedding_date']);
            $bride = clean($_POST['bride']);
            $groom = clean($_POST['groom']);
            $phone = clean($_POST['phone']);
            $city = clean($_POST['city']);
            $wedding_type = (int)$_POST['wedding_type'];
            $organizer_id = (int)$_POST['organizer_id'];
            $description = clean($_POST['description']);
            $location = clean($_POST['location']);
            $expectation_visitor = clean($_POST['expectation_visitor']);
            $cash_advanced = floatval($_POST['cash_advanced']);
            $status = "confirm";

            $booking_detail->bride = $bride;
            $booking_detail->groom = $groom;
            $booking_detail->wedding_type = $wedding_type;
            $accounts->user_email = $booking_detail->user_email = $email;
            $booking_detail->wedding_date = $wedding_date;
            $booking_detail->organizer_id = $organizer_id;
            $booking_detail->update_booking($booking_id);
            $booking_detail->save_booking();

            $account_detail->firstname = $firstname;
            $account_detail->lastname = $lastname;
            $account_detail->phone = $phone;
            $account_detail->city = $city;
            $account_detail->expectation_visitor = $expectation_visitor;
            $account_detail->cash_advanced = $cash_advanced;
            $account_detail->status = $status;
            $account_detail->location = $location;
            $account_detail->description = $description;
            $account_detail->save_account();
            
            if ($booking_detail->save_booking()) {
                $accounts->save_account();
            }

            // Tell the couple their date is locked in (bell + best-effort email).
            Notification::create(
                $user_id,
                $booking_id,
                'Great news — your wedding date is confirmed! Your planner will be in touch to plan every detail.'
                . (!empty($booking_detail->wedding_date) ? ' Wedding day: ' . format_date_kd((string) $booking_detail->wedding_date) . '.' : ''),
                'client_manage_account_details.php?booking_id=' . $booking_id . '&user_id=' . $user_id
            );

            // Big-moment SMS: couples may not be logged in — text them the
            // news (charged to the assigned planner's credits, best-effort;
            // the bell alert above is the reliable channel).
            $organizerId = (int) ($booking_detail->organizer_id ?? 0);
            $coupleSms = SMS::send_courtesy(
                (string) ($account_detail->phone ?? ''),
                sprintf(
                    'Habari%s! Your wedding date is CONFIRMED%s. Karibu Everafter Planner — your planner will call you soon.',
                    $firstname !== '' ? ' ' . $firstname : '',
                    !empty($booking_detail->wedding_date) ? ' for ' . format_date_kd((string) $booking_detail->wedding_date) : ''
                ),
                $organizerId,
                $booking_id
            );

           redirect_to("client.php");

            $session->message("
            <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-approval'></i></strong> {$account_detail->firstname} {$account_detail->lastname} has been successfully modify.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");

        }
    }

    if (isset($_POST['cancel'])) {

        // CSRF validation
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to("client_assign.php?booking=$booking_id&user_id=$user_id");
            die();
        }

        if ($booking_detail) {
            $status = "cancel";
            $account_detail->status = $status;
            $account_detail->save_account();

            // Tell the couple (bell + best-effort email) so the tab isn't the
            // only place they learn their booking was cancelled.
            Notification::create(
                $user_id,
                $booking_id,
                'Your wedding booking has been cancelled. Please contact your planner to discuss next steps or rebooking.',
                'client_manage_account_details.php?booking_id=' . $booking_id . '&user_id=' . $user_id
            );

            redirect_to("client.php");
            $session->message("
            <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-approval'></i></strong> {$account_detail->firstname} {$account_detail->lastname} has been successfully updated.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");

        }
    }

if (isset($_POST['fraud'])) {

    // CSRF validation
    if (!$session->verify_csrf()) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
        redirect_to("client_assign.php?booking=$booking_id&user_id=$user_id");
        die();
    }

    if ($booking_detail) {
        $status = "fraud";
        $account_detail->status = $status;
        $account_detail->save_account();

        // A fraud flag suspends the couple's access — notify them politely so
        // they know to contact the office (never accuse in writing).
        Notification::create(
            $user_id,
            $booking_id,
            'There is an issue with your booking that needs attention. Please contact our office to verify your details and restore access.',
            'contact.php'
        );

        redirect_to("client.php");
        $session->message("
            <div class=\"alert alert-success alert-dismissible fade show\" role=\"alert\">
              <strong><i class='mdi mdi-approval'></i></strong> {$account_detail->firstname} {$account_detail->lastname} has been successfully updated.
              <button type=\"button\" class=\"close\" data-dismiss=\"alert\" aria-label=\"Close\">
                <span aria-hidden=\"true\">&times;</span>
              </button>
            </div>");
    }
}

?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Edit Client Information - Administrator</title>
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

<?php include_once 'include/sidebar.php'; ?>

    <div class="container">

        <div class="row">

            <div class="col-lg-8 offset-2 pl-3 pb-3 box-shadow mt-4">

                <form method="post" action="">
                    <?= $session->csrf_field(); ?>
                    <h4 class="h4 mt-4 pb-2" style="border-bottom: 1px solid #dee2e6!important;">Client Information
						<a href="client.php" class="btn btn-sm btn-light float-right mr-2 active" style="font-size: 12px;">
							<span class="mdi mdi-arrow-left"></span> Back 
						</a>

                    </h4>
                    <div class="form-row">

                        <div class="form-group col-md-6">
                            <label for="inputFirstname">Firstname</label>
                            <input type="text" name="firstname" class="form-control" id="inputFirstname" value="<?= $account_detail->firstname; ?>" placeholder="Enter firstname">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="inputLastname">Lastname</label>
                            <input type="text" name="lastname" class="form-control" id="inputLastname" value="<?= $account_detail->lastname; ?>" placeholder="Enter lastname">
                        </div>

                    </div>

                    <div class="form-group">
                        <label for="inputEmail">Email</label>
                        <input type="text" name="email" class="form-control" id="inputEmail" value="<?= $booking_detail->user_email; ?>" placeholder="Enter email">
                    </div>

                    <div class="form-row form-group">

                        <div class="col-md-6">
                            <label for="wedding_date">Wedding Date</label>
                        </div>

                        <div class="col-md-6">
                            <label>Wedding Type</label>
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
                            <select class="form-control" id="wedding_type" name="wedding_type">

                                <?php foreach($categories as $category) : ?>
                                    <?php if ($category->id == $booking_detail->wedding_type): ?>
                                        <option value="<?= $category->id;?>" selected><?= $category->wedding_type; ?> - <?= format_ksh($category->price); ?> </option>
                                        <?php else: ?>
                                        <option value="<?= $category->id;?>"><?= $category->wedding_type; ?> - <?= format_ksh($category->price); ?> </option>
                                            
                                    <?php endif ?>

                                <?php endforeach; ?>

                            </select>
                        </div>
                    </div>

                    <div class="form-group">

                        <label for="brideName">Bride's name</label>
                        <input type="text" name="bride" class="form-control" value="<?= $booking_detail->bride; ?>" id="brideName" placeholder="Enter Bride's Name">

                    </div>

                    <div class="form-group">

                        <label for="GroomsName">Groom's name</label>
                        <input type="text" name="groom" class="form-control" value="<?= $booking_detail->groom; ?>" id="GroomsName" placeholder="Enter Groom's Name">

                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="inputPhone">Phone</label>
                            <input type="text" class="form-control" value="<?= $account_detail->phone; ?>" id="inputPhone" name="phone" placeholder="e.g. 0712345678">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="inputcity">City / Town</label>
                            <select class="form-control" id="inputcity" name="city">
                                <?php
                                $kenyan_towns = ['Nairobi','Mombasa','Nakuru','Kisumu','Eldoret','Nyeri','Kiambu','Machakos','Embu','Meru','Thika','Naivasha','Kitale','Malindi','Garissa','Kakamega','Bungoma','Kericho','Nanyuki','Isiolo',"Murang'a"];
                                foreach ($kenyan_towns as $town):
                                ?>
                                    <option value="<?= $town; ?>" <?= ($account_detail->city == $town) ? 'selected' : ''; ?>><?= $town; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="inputOrganizer">Assigned Organizer</label>
                            <select class="form-control" id="inputOrganizer" name="organizer_id">
                                <option value="1">Big Day Planners</option>
                                <option value="2">Joyful Events</option>
                                <option value="3">Roses and Co</option>
                            </select>
                        </div><!-- form-group col-md-6 -->
                    </div><!-- end of form-row -->

					<div class="form-row">
					    <div class="col form-group">
					        <label for="inputLocation">Wedding Location</label>
					        <select name="location" class="form-control" id="inputLocation">
					            <?php
					            $kenyan_venues = [
					                'Nairobi - Carnivore Grounds',
					                'Nairobi - Garden Wedding (Karen)',
					                'Nairobi - Hotel (Serena / Sankara / Villa Rosa)',
					                'Nairobi - KICC / Convention Centre',
					                'Mombasa - Diani Beach',
					                'Mombasa - Nyali / English Point',
					                'Nakuru - Elementaita',
					                'Nakuru - Lake Naivasha',
					                'Nyeri - Upcountry Home',
					                'Kisumu - Lakeside Hotel',
					                'Eldoret - Farm / Agricultural Society',
					                'Kiambu - Church + Reception Hall',
					                'Machakos - Open Air',
					                'Other'
					            ];
					            foreach ($kenyan_venues as $venue):
					            ?>
					                <option value="<?= $venue; ?>" <?= ($account_detail->location == $venue) ? 'selected' : ''; ?>><?= $venue; ?></option>
					            <?php endforeach; ?>
					        </select>
					    </div>

					    <div class="col form-group">
					        <label for="Inputexpectation_visitor">Expected Visitors</label>
					        <input type="number" name="expectation_visitor" value="<?= $account_detail->expectation_visitor; ?>" class="form-control" id="Inputexpectation_visitor" placeholder="e.g. 500">
					    </div>
                        
					    <div class="col form-group">
					        <label for="inputcash_advanced">Cash Advanced (KSh)</label>
					        <input type="number" name="cash_advanced" class="form-control" value="<?= $account_detail->cash_advanced; ?>" id="inputcash_advanced" placeholder="0.00" step="0.01">
					    </div>

					</div>

					<div class="form-group">
                        <label for="Inputdescription">Description</label>
                        <textarea name="description" class="form-control" id="Inputdescription" placeholder="Enter expected visitor" rows="5"><?= $account_detail->description; ?></textarea>
                    </div>

               
                    <button type="submit" name="cancel" class="btn btn-sm btn-secondary float-right mr-2" style="font-size: 12px;" value="">
                    	<i class="mdi mdi-cancel mr-2"></i> Cancel Booking
					</button>
					<button type="submit" name="fraud" class="btn btn-sm btn-danger float-right mr-2" style="font-size: 12px;" value="">
						<i class="mdi mdi-linux mr-2"></i> Fraud Booking
					</button>
                    <button type="submit" name="confirm" class="btn btn-sm btn-primary float-right mr-2" style="font-size: 12px;">
                    	<i class="mdi mdi-check mr-2"></i> Confirm Booking
                    </button>

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
    });
</script>
</body>
</html>