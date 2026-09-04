<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); }

    $booking_id = $_GET['booking_id'] ?? 0;
    $user_id = $_GET['user_id'] ?? 0;
    $links = 'booking_id=' . (int)$booking_id . '&user_id=' . (int)$user_id;
    $booking_id = (int)$booking_id;
    $user_id = (int)$user_id;

    $guest = new Guest();

    if (isset($_POST['submit'])) {

        // CSRF validation
        if (!$session->verify_csrf()) {
            redirect_to("guest_add.php?$links");
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            die();
        }

        $fullname = clean($_POST['fullname']);
        $guestname = clean($_POST['guestname']);
        $address = clean($_POST['address']);
        $city = clean($_POST['city']);
        $state = clean($_POST['state']);
        $zipcode = clean($_POST['zipcode']);
        $priority = clean($_POST['priority']);
        $out_of_town = clean($_POST['out_of_town']);
        $relationship = clean($_POST['relationship']);
        $gifts = clean($_POST['gifts']);
        // Kenyan-specific
        $upcountry_accommodation = isset($_POST['upcountry_accommodation']) ? 1 : 0;
        $harambee_contribution = floatval($_POST['harambee_contribution'] ?? 0);
        $dietary_requirements = clean($_POST['dietary_requirements'] ?? '');
        $transport_needed = isset($_POST['transport_needed']) ? 1 : 0;

        if (empty($fullname) || empty($guestname) || empty($address) || empty($city)) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Please fill in all required fields.</div>');
            redirect_to("guest_add.php?$links");
            die();
        }

        $guest->booking_id = $booking_id;
        $guest->fullname = $fullname;
        $guest->guestname = $guestname;
        $guest->address = $address;
        $guest->city = $city;
        $guest->state = $state;
        $guest->zipcode = $zipcode;
        $guest->priority = $priority;
        $guest->out_of_town = $out_of_town;
        $guest->relationship = $relationship;
        $guest->tracks_and_gifts = $gifts;
        $guest->upcountry_accommodation = $upcountry_accommodation;
        $guest->harambee_contribution = $harambee_contribution;
        $guest->dietary_requirements = $dietary_requirements;
        $guest->transport_needed = $transport_needed;
        $guest->save();

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Guest <strong>' . htmlspecialchars($fullname) . '</strong> added successfully.</div>');
        redirect_to("guest_list.php?$links");
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Add Guest - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .box-shadow { box-shadow: 0 0 2px 1px rgba(0,0,0,0.1); }
        .form-control, .btn { font-size: 13px; }
        .form-section { border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
        .form-section h6 { color: #065F46; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        .required::after { content: ' *'; color: #DC2626; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>
<div class="container">
    <div class="row">
        <div class="col-lg-10 offset-1 pl-3 pb-3 box-shadow mt-4">
            <form method="post" action="">
                <?= $session->csrf_field(); ?>
                <h4 class="h4 mt-4 pb-2" style="border-bottom: 1px solid #dee2e6;">
                    <i class="mdi mdi-account-plus"></i> Add New Guest
                    <a href="guest_list.php?<?= $links; ?>" class="btn btn-sm btn-danger float-right"><i class="mdi mdi-close-circle mr-1"></i> Cancel</a>
                    <button type="submit" name="submit" class="btn btn-sm btn-success float-right mr-2"><i class="mdi mdi-content-save mr-1"></i> Save Guest</button>
                </h4>
                <?php if ($session->message()): ?>
                    <div class="form-group col-md-12 p-0"><?= $session->message(); ?></div>
                <?php endif; ?>

                <!-- Core Info -->
                <div class="form-section"><h6><i class="mdi mdi-account"></i> Guest Details</h6></div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="required">Full Name</label>
                        <input type="text" name="fullname" class="form-control" placeholder="e.g. John Kamau Mwangi" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="required">Guest Name (Display)</label>
                        <input type="text" name="guestname" class="form-control" placeholder="e.g. Mr. & Mrs. Kamau" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Relationship / Group</label>
                    <select name="relationship" class="form-control">
                        <option value="family_elder">Family Elder (Mzee)</option>
                        <option value="bridal_train">Bridal Train (Bridesmaids/Groomsmen)</option>
                        <option value="upcountry_guest">Upcountry Guest (Mgeni wa Mbali)</option>
                        <option value="family_friend">Family Friend</option>
                        <option value="colleague">Colleague / Workmate</option>
                        <option value="pastor_clergy">Pastor / Clergy</option>
                        <option value="vc_member">VC / Committee Member</option>
                        <option value="harambee_sponsor">Harambee Sponsor</option>
                        <option value="close_family">Close Family</option>
                        <option value="regular">Regular Guest</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="required">Address</label>
                    <textarea rows="3" name="address" class="form-control" placeholder="Physical address" required></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="required">City / Town</label>
                        <select name="city" class="form-control" required>
                            <option value="">Select Town</option>
                            <option>Nairobi</option><option>Mombasa</option><option>Nakuru</option>
                            <option>Kisumu</option><option>Eldoret</option><option>Nyeri</option>
                            <option>Kiambu</option><option>Machakos</option><option>Embu</option>
                            <option>Meru</option><option>Thika</option><option>Naivasha</option>
                            <option>Kitale</option><option>Malindi</option><option>Garissa</option>
                            <option>Kakamega</option><option>Bungoma</option><option>Kericho</option>
                            <option>Nanyuki</option><option>Isiolo</option><option>Murang'a</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>County</label>
                        <select name="state" class="form-control">
                            <option value="">Select County</option>
                            <option>Nairobi</option><option>Mombasa</option><option>Nakuru</option>
                            <option>Kisumu</option><option>Uasin Gishu (Eldoret)</option><option>Nyeri</option>
                            <option>Kiambu</option><option>Machakos</option><option>Embu</option>
                            <option>Meru</option><option>Thika (Kiambu)</option><option>Nakuru (Naivasha)</option>
                            <option>Trans Nzoia (Kitale)</option><option>Kilifi (Malindi)</option>
                            <option>Garissa</option><option>Kakamega</option><option>Bungoma</option>
                            <option>Kericho</option><option>Laikipia (Nanyuki)</option>
                            <option>Isiolo</option><option>Murang'a</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Postal Code</label>
                        <input type="text" name="zipcode" class="form-control" placeholder="e.g. 00100">
                    </div>
                </div>

                <!-- Kenyan Wedding Specifics -->
                <div class="form-section mt-3"><h6><i class="mdi mdi-flag-variant"></i> Kenyan Wedding Details</h6></div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Priority Level</label>
                        <select name="priority" class="form-control">
                            <option value="A">A - VIP (Elders, VC, Pastor)</option>
                            <option value="B">B - Bridal Train</option>
                            <option value="C">C - Close Family</option>
                            <option value="D">D - Regular Guest</option>
                            <option value="E">E - General / Plus-ones</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Out of Town Guest</label>
                        <select name="out_of_town" class="form-control">
                            <option value="n">No</option>
                            <option value="y">Yes (Upcountry)</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Harambee Contribution (KSh)</label>
                        <input type="number" name="harambee_contribution" class="form-control" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group form-check col-md-6 ml-3">
                        <input type="checkbox" name="upcountry_accommodation" class="form-check-input" value="1" id="chk_accommodation">
                        <label class="form-check-label" for="chk_accommodation">Requires Accommodation (Upcountry Guest)</label>
                    </div>
                    <div class="form-group form-check col-md-4">
                        <input type="checkbox" name="transport_needed" class="form-check-input" value="1" id="chk_transport">
                        <label class="form-check-label" for="chk_transport">Transport Required</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Dietary Requirements</label>
                    <select name="dietary_requirements" class="form-control">
                        <option value="">None</option>
                        <option value="vegetarian">Vegetarian</option>
                        <option value="vegan">Vegan</option>
                        <option value="halal">Halal</option>
                        <option value="gluten_free">Gluten Free</option>
                        <option value="diabetic">Diabetic Friendly</option>
                        <option value="no_beef">No Beef (Chicken/Pilau only)</option>
                        <option value="other">Other (specify in notes)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Gift &amp; Thank You Notes</label>
                    <textarea rows="3" name="gifts" class="form-control" placeholder="Track harambee gifts, M-Pesa contributions, presents received..."></textarea>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include_once 'include/footer.php'; ?>
</body>
</html>