<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); }

    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);
    $id = (int)($_GET['id'] ?? 0);
    $links = 'id=' . $id . '&booking_id=' . $booking_id . '&user_id=' . $user_id;

    $guest_query = Guest::find_by_id($id);

    if (!$guest_query) {
        redirect_to("guest_list.php?booking_id=$booking_id&user_id=$user_id");
    }

    if (isset($_POST['submit'])) {
        // CSRF validation
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token. Please try again.</div>');
            redirect_to("guest_edit.php?$links");
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
        $upcountry_accommodation = isset($_POST['upcountry_accommodation']) ? 1 : 0;
        $harambee_contribution = floatval($_POST['harambee_contribution'] ?? 0);
        $dietary_requirements = clean($_POST['dietary_requirements'] ?? '');
        $transport_needed = isset($_POST['transport_needed']) ? 1 : 0;

        if (empty($fullname) || empty($guestname) || empty($address)) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Please fill in required fields.</div>');
            redirect_to("guest_edit.php?$links");
            die();
        }

        $guest_query->fullname = $fullname;
        $guest_query->guestname = $guestname;
        $guest_query->address = $address;
        $guest_query->city = $city;
        $guest_query->state = $state;
        $guest_query->zipcode = $zipcode;
        $guest_query->priority = $priority;
        $guest_query->out_of_town = $out_of_town;
        $guest_query->relationship = $relationship;
        $guest_query->tracks_and_gifts = $gifts;
        $guest_query->upcountry_accommodation = $upcountry_accommodation;
        $guest_query->harambee_contribution = $harambee_contribution;
        $guest_query->dietary_requirements = $dietary_requirements;
        $guest_query->transport_needed = $transport_needed;
        $guest_query->update();

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Guest <strong>' . htmlspecialchars($fullname) . '</strong> updated successfully.</div>');
        redirect_to("guest_list.php?$links");
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Edit Guest - Everafter Planner</title>
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
                    <i class="mdi mdi-account-edit"></i> Edit Guest: <?= htmlspecialchars($guest_query->fullname); ?>
                    <a href="guest_list.php?<?= $links; ?>" class="btn btn-sm btn-danger float-right"><i class="mdi mdi-close-circle mr-1"></i> Cancel</a>
                    <button type="submit" name="submit" class="btn btn-sm btn-success float-right mr-2"><i class="mdi mdi-content-save mr-1"></i> Update Guest</button>
                </h4>
                <?php if ($session->message()): ?>
                    <div class="form-group col-md-12 p-0"><?= $session->message(); ?></div>
                <?php endif; ?>

                <div class="form-section"><h6><i class="mdi mdi-account"></i> Guest Details</h6></div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="required">Full Name</label>
                        <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($guest_query->fullname); ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="required">Guest Name (Display)</label>
                        <input type="text" name="guestname" class="form-control" value="<?= htmlspecialchars($guest_query->guestname); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Relationship / Group</label>
                    <select name="relationship" class="form-control">
                        <?php
                        $relationships = [
                            'family_elder' => 'Family Elder (Mzee)',
                            'bridal_train' => 'Bridal Train (Bridesmaids/Groomsmen)',
                            'upcountry_guest' => 'Upcountry Guest (Mgeni wa Mbali)',
                            'family_friend' => 'Family Friend',
                            'colleague' => 'Colleague / Workmate',
                            'pastor_clergy' => 'Pastor / Clergy',
                            'vc_member' => 'VC / Committee Member',
                            'harambee_sponsor' => 'Harambee Sponsor',
                            'close_family' => 'Close Family',
                            'regular' => 'Regular Guest',
                            'other' => 'Other'
                        ];
                        foreach ($relationships as $val => $label):
                        ?>
                            <option value="<?= $val; ?>" <?= ($guest_query->relationship == $val) ? 'selected' : ''; ?>><?= $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="required">Address</label>
                    <textarea rows="3" name="address" class="form-control" required><?= htmlspecialchars($guest_query->address); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="required">City / Town</label>
                        <select name="city" class="form-control" required>
                            <?php
                            $towns = ['Nairobi','Mombasa','Nakuru','Kisumu','Eldoret','Nyeri','Kiambu','Machakos','Embu','Meru','Thika','Naivasha','Kitale','Malindi','Garissa','Kakamega','Bungoma','Kericho','Nanyuki','Isiolo',"Murang'a"];
                            foreach ($towns as $town):
                            ?>
                                <option <?= ($guest_query->city == $town) ? 'selected' : ''; ?>><?= $town; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>County</label>
                        <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($guest_query->state); ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Postal Code</label>
                        <input type="text" name="zipcode" class="form-control" value="<?= htmlspecialchars($guest_query->zipcode); ?>">
                    </div>
                </div>

                <div class="form-section mt-3"><h6><i class="mdi mdi-flag-variant"></i> Kenyan Wedding Details</h6></div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Priority Level</label>
                        <select name="priority" class="form-control">
                            <option value="A" <?= ($guest_query->priority == 'A') ? 'selected' : ''; ?>>A - VIP (Elders, VC, Pastor)</option>
                            <option value="B" <?= ($guest_query->priority == 'B') ? 'selected' : ''; ?>>B - Bridal Train</option>
                            <option value="C" <?= ($guest_query->priority == 'C') ? 'selected' : ''; ?>>C - Close Family</option>
                            <option value="D" <?= ($guest_query->priority == 'D') ? 'selected' : ''; ?>>D - Regular Guest</option>
                            <option value="E" <?= ($guest_query->priority == 'E') ? 'selected' : ''; ?>>E - General / Plus-ones</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Out of Town Guest</label>
                        <select name="out_of_town" class="form-control">
                            <option value="n" <?= ($guest_query->out_of_town == 'n') ? 'selected' : ''; ?>>No</option>
                            <option value="y" <?= ($guest_query->out_of_town == 'y') ? 'selected' : ''; ?>>Yes (Upcountry)</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Harambee Contribution (KSh)</label>
                        <input type="number" name="harambee_contribution" class="form-control" value="<?= $guest_query->harambee_contribution; ?>" step="0.01" min="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group form-check col-md-6 ml-3">
                        <input type="checkbox" name="upcountry_accommodation" class="form-check-input" value="1" id="chk_accommodation" <?= $guest_query->upcountry_accommodation ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_accommodation">Requires Accommodation</label>
                    </div>
                    <div class="form-group form-check col-md-4">
                        <input type="checkbox" name="transport_needed" class="form-check-input" value="1" id="chk_transport" <?= $guest_query->transport_needed ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_transport">Transport Required</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Dietary Requirements</label>
                    <select name="dietary_requirements" class="form-control">
                        <option value="">None</option>
                        <option value="vegetarian" <?= ($guest_query->dietary_requirements == 'vegetarian') ? 'selected' : ''; ?>>Vegetarian</option>
                        <option value="vegan" <?= ($guest_query->dietary_requirements == 'vegan') ? 'selected' : ''; ?>>Vegan</option>
                        <option value="halal" <?= ($guest_query->dietary_requirements == 'halal') ? 'selected' : ''; ?>>Halal</option>
                        <option value="gluten_free" <?= ($guest_query->dietary_requirements == 'gluten_free') ? 'selected' : ''; ?>>Gluten Free</option>
                        <option value="diabetic" <?= ($guest_query->dietary_requirements == 'diabetic') ? 'selected' : ''; ?>>Diabetic Friendly</option>
                        <option value="no_beef" <?= ($guest_query->dietary_requirements == 'no_beef') ? 'selected' : ''; ?>>No Beef</option>
                        <option value="other" <?= ($guest_query->dietary_requirements == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Gift &amp; Thank You Notes</label>
                    <textarea rows="3" name="gifts" class="form-control"><?= htmlspecialchars($guest_query->tracks_and_gifts); ?></textarea>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include_once 'include/footer.php'; ?>
</body>
</html>