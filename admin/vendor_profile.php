<?php include 'include/init.php'; ?>
<?php
/**
 * Vendor self-service profile — business details + logo.
 *
 * Vendors edit ONLY their own row (ownership re-checked on every write).
 * Admin-controlled fields (is_approved, is_premium, rating, total_bookings)
 * are never touched by this page: approval and premium status remain the
 * administrator's call, matching the manual's RBAC matrix.
 */
    require_vendor();

    $user_id = (int) $_SESSION['id'];
    /*
     * An account may run several businesses, and get_by_user() returns only one
     * of them — so the owner of a second listing could not edit that listing's
     * profile at all. Edit the session's selected listing (ownership re-checked
     * on every request and on every write below).
     */
    $myListings = Vendor::all_for_user($user_id);
    $vendor  = current_vendor_listing($user_id, $myListings);
    if (!vendor_listing_visible_to_session($vendor)) {
        redirect_to("vendor_register.php"); // not registered yet, or not this account's
        die();
    }

    $categories = ['catering','decor','photography','music','transport','mc','venue','cakes','flowers','other'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to("vendor_profile.php");
            die();
        }

        $business_name = clean($_POST['business_name'] ?? '');
        $category      = clean($_POST['category'] ?? '');
        $description   = clean($_POST['description'] ?? '');
        $phone         = clean($_POST['phone'] ?? '');
        $email         = clean($_POST['email'] ?? '');
        $location      = clean($_POST['location'] ?? '');
        $county        = clean($_POST['county'] ?? '');

        if ($business_name === '' || !in_array($category, $categories, true) || $phone === '') {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Business name, a valid category and phone are required.</div>');
            redirect_to("vendor_profile.php");
            die();
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That email address is not valid.</div>');
            redirect_to("vendor_profile.php");
            die();
        }

        $stmt = $db->prepare(
            'UPDATE tblvendors
                SET business_name = :bn, category = :cat, description = :descr,
                    phone = :phone, email = :email, location = :loc, county = :county
              WHERE id = :vid AND user_id = :uid'
        );
        $ok = $stmt->execute([
            ':bn'    => $business_name,
            ':cat'   => $category,
            ':descr' => $description,
            ':phone' => $phone,
            ':email' => $email,
            ':loc'   => $location,
            ':county' => $county,
            ':vid'   => (int) $vendor->id,
            ':uid'   => $user_id, // ownership: the row must be the session vendor's
        ]);

        // Optional logo upload — image-only guard like Users::set_file().
        if ($ok && !empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (isset($allowed[$ext]) && ($file['size'] ?? 0) > 0 && ($file['size'] ?? 0) <= 3 * 1024 * 1024
                && @getimagesize($file['tmp_name']) !== false) {
                $name = bin2hex(random_bytes(8)) . '.' . $ext;
                $target = SITE_ROOT . DS . 'upload' . DS . 'vendors' . DS . $name;
                if (!is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0775, true);
                }
                if (move_uploaded_file($file['tmp_name'], $target)) {
                    // Remove the previous file if it existed.
                    if (!empty($vendor->logo)) {
                        @unlink(SITE_ROOT . DS . 'upload' . DS . 'vendors' . DS . $vendor->logo);
                    }
                    // Carries its own ownership clause like the profile UPDATE
                    // above, instead of relying on $vendor having been resolved
                    // safely one step earlier.
                    $db->prepare('UPDATE tblvendors SET logo = :logo WHERE id = :vid AND user_id = :uid')
                       ->execute([':logo' => $name, ':vid' => (int) $vendor->id, ':uid' => $user_id]);
                }
            } else {
                $session->message('<div class="alert alert-warning">Profile saved, but the logo was skipped — use a JPG/PNG/WebP image up to 3 MB.</div>');
            }
        }

        if ($session->message() === '') {
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Profile updated.</div>');
        }
        redirect_to("vendor_profile.php");
        die();
    }

    // Re-read after any save so the form shows fresh values.
    $vendor = current_vendor_listing($user_id, $myListings);
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Vendor Profile - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .box-shadow { box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 20px; background: #fff; border-radius: 12px; }
        .form-control { font-size: 13px; }
        .required::after { content: ' *'; color: #DC2626; }
        .logo-preview { width: 96px; height: 96px; border-radius: 16px; object-fit: cover; background: #f1f5f9; }
        .status-pill { font-size: 11px; padding: 4px 12px; border-radius: 999px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-store-edit"></i> My Vendor Profile</h4>
            <p class="text-muted mb-0" style="font-size:13px;"><?= htmlspecialchars((string) $vendor->business_name); ?></p>
            <?php vendor_listing_switcher($myListings, (int) $vendor->id, 'vendor_profile.php'); ?>
        </div>
        <a href="vendor_home.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> My Bookings</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-6">
            <span class="status-pill <?= $vendor->is_approved ? 'badge-success' : 'badge-warning'; ?>">
                <i class="mdi mdi-<?= $vendor->is_approved ? 'check-decagram' : 'clock-outline'; ?>"></i>
                <?= $vendor->is_approved ? 'Approved — visible in the marketplace' : 'Pending approval'; ?>
            </span>
            <?php if ($vendor->is_premium): ?>
                <span class="status-pill badge-warning ml-1"><i class="mdi mdi-crown"></i> Premium</span>
            <?php endif; ?>
        </div>
        <div class="col-md-6 text-right text-muted" style="font-size:12px;">
            Rating: <?= number_format((float) $vendor->rating, 1); ?> / 5 · <?= (int) $vendor->total_bookings; ?> bookings
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= $session->csrf_field(); ?>
        <div class="row">
            <div class="col-md-7">
                <div class="box-shadow mb-3">
                    <h6 class="mb-3"><i class="mdi mdi-information"></i> Business Information</h6>
                    <div class="form-group">
                        <label class="required">Business Name</label>
                        <input type="text" name="business_name" class="form-control" value="<?= htmlspecialchars($vendor->business_name); ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="required">Category</label>
                            <select name="category" class="form-control" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat; ?>" <?= $vendor->category === $cat ? 'selected' : ''; ?>><?= ucfirst($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="required">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($vendor->phone); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Your services, experience, specialties..."><?= htmlspecialchars($vendor->description ?? ''); ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($vendor->email ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="required">Location (Town)</label>
                            <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($vendor->location ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="required">County</label>
                        <input type="text" name="county" class="form-control" value="<?= htmlspecialchars($vendor->county ?? ''); ?>" required>
                    </div>
                    <p class="text-muted mb-0" style="font-size:11.5px;">
                        Approval, premium status and ratings are managed by the Everafter Planner team —
                        changes here only affect how couples see your listing.
                    </p>
                </div>
            </div>
            <div class="col-md-5">
                <div class="box-shadow mb-3">
                    <h6 class="mb-3"><i class="mdi mdi-image"></i> Logo</h6>
                    <div class="text-center mb-2">
                        <img src="<?= !empty($vendor->logo) ? 'upload/vendors/' . htmlspecialchars($vendor->logo) : 'https://placehold.co/96x96/f1f5f9/94a3b8?text=Logo'; ?>"
                             class="logo-preview" alt="Vendor logo">
                    </div>
                    <div class="form-group">
                        <input type="file" name="logo" class="form-control-file" accept=".jpg,.jpeg,.png,.webp">
                        <small class="text-muted">JPG, PNG or WebP, up to 3 MB. Square images look best.</small>
                    </div>
                </div>
                <div class="box-shadow">
                    <h6 class="mb-3"><i class="mdi mdi-package-variant-closed"></i> Packages</h6>
                    <p class="text-muted" style="font-size:12.5px;">Manage what couples can enquire about on the
                        <a href="vendor_packages.php">My Packages</a> page.</p>
                </div>
            </div>
        </div>
        <div class="text-right mt-2 mb-4">
            <button type="submit" name="save_profile" class="btn btn-success"><i class="mdi mdi-content-save"></i> Save Profile</button>
        </div>
    </form>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
