<?php include 'include/init.php'; ?>
<?php
    if (!isset($_SESSION['id'])) { redirect_to("../"); }

    $user_id = $_SESSION['id'];
    $existing = Vendor::get_by_user($user_id);

    // Handle vendor registration
    if (isset($_POST['register_vendor'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to("vendor_register.php");
            die();
        }

        $business_name = clean($_POST['business_name']);
        $category = clean($_POST['category']);
        $description = clean($_POST['description']);
        $phone = clean($_POST['phone']);
        $email = clean($_POST['email']);
        $location = clean($_POST['location']);
        $county = clean($_POST['county']);

        if (empty($business_name) || empty($category) || empty($phone)) {
            $session->message('<div class="alert alert-danger">Please fill in all required fields.</div>');
            redirect_to("vendor_register.php");
            die();
        }

        // Insert vendor — PDO prepared (v2.1)
        global $db;
        $sql = "INSERT INTO tblvendors (user_id, business_name, category, description, phone, email, location, county, is_approved) VALUES (:uid, :bname, :cat, :descr, :phone, :email, :loc, :county, 0)";
        $stmt = $db->prepare($sql);
        if ($stmt->execute([':uid' => $user_id, ':bname' => $business_name, ':cat' => $category, ':descr' => $description, ':phone' => $phone, ':email' => $email, ':loc' => $location, ':county' => $county])) {
            $vendor_id = (int) $db->the_insert_id();

            // Add packages
            if (!empty($_POST['package_name'])) {
                $pkg_names = $_POST['package_name'];
                $pkg_prices = $_POST['package_price'];
                $pkg_descriptions = $_POST['package_description'];

                for ($i = 0; $i < count($pkg_names); $i++) {
                    if (!empty($pkg_names[$i]) && !empty($pkg_prices[$i])) {
                        $pkg_sql = "INSERT INTO tblvendor_packages (vendor_id, title, description, price) VALUES (:vid, :title, :descr, :price)";
                        $pkg_stmt = $db->prepare($pkg_sql);
                        $price = floatval($pkg_prices[$i]);
                        $pkg_stmt->execute([':vid' => $vendor_id, ':title' => $pkg_names[$i], ':descr' => $pkg_descriptions[$i], ':price' => $price]);
                    }
                }
            }

            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Vendor registration submitted! You will be notified once approved.</div>');
            redirect_to("vendors.php");
        } else {
            $session->message('<div class="alert alert-danger">Registration failed. Please try again.</div>');
            redirect_to("vendor_register.php");
        }
    }
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Register Vendor - WPMS Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css"
    <link href="css/modern-admin.css" rel="stylesheet"> rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .box-shadow { box-shadow: 0 0 2px 1px rgba(0,0,0,0.1); padding: 20px; background: white; border-radius: 8px; }
        .form-control { font-size: 13px; }
        .required::after { content: ' *'; color: #e74c3c; }
        .package-row { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 10px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4><i class="mdi mdi-store-plus"></i> Register as Vendor</h4>
        <a href="vendors.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> Back to Marketplace</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if ($existing): ?>
        <div class="alert alert-info">
            <strong>You are already registered as a vendor:</strong> <?= htmlspecialchars($existing->business_name); ?> 
            (Status: <?= $existing->is_approved ? 'Approved' : 'Pending Approval'; ?>)
        </div>
    <?php else: ?>
        <form method="post">
            <?= $session->csrf_field(); ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="box-shadow mb-3">
                        <h6 class="mb-3"><i class="mdi mdi-information"></i> Business Information</h6>
                        <div class="form-group">
                            <label class="required">Business Name</label>
                            <input type="text" name="business_name" class="form-control" placeholder="e.g. Nyama Choma Masters" required>
                        </div>
                        <div class="form-group">
                            <label class="required">Category</label>
                            <select name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <option value="catering">Catering Services</option>
                                <option value="decor">Wedding Decor</option>
                                <option value="photography">Photography & Videography</option>
                                <option value="music">Music & DJ</option>
                                <option value="transport">Guest Transport</option>
                                <option value="mc">MC & Entertainment</option>
                                <option value="venue">Wedding Venue</option>
                                <option value="cakes">Wedding Cakes</option>
                                <option value="flowers">Flowers & Bouquets</option>
                                <option value="other">Other Services</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Describe your services, experience, specialties..."></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="required">Phone</label>
                                <input type="text" name="phone" class="form-control" placeholder="0712345678" required>
                            </div>
                            <div class="form-group col-md-6">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control" placeholder="info@business.co.ke">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="required">Location</label>
                                <select name="location" class="form-control" required>
                                    <option value="">Select Town</option>
                                    <option>Nairobi</option><option>Mombasa</option><option>Nakuru</option>
                                    <option>Kisumu</option><option>Eldoret</option><option>Nyeri</option>
                                    <option>Kiambu</option><option>Machakos</option><option>Other</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="required">County</label>
                                <input type="text" name="county" class="form-control" placeholder="e.g. Nairobi County" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="box-shadow">
                        <h6 class="mb-3"><i class="mdi mdi-package"></i> Packages</h6>
                        <div id="packages">
                            <div class="package-row">
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Package Name</label>
                                        <input type="text" name="package_name[]" class="form-control" placeholder="e.g. Premium Package">
                                    </div>
                                    <div class="form-group col-md-3">
                                        <label>Price (KSh)</label>
                                        <input type="number" name="package_price[]" class="form-control" placeholder="0.00" step="0.01">
                                    </div>
                                    <div class="form-group col-md-3">
                                        <label>&nbsp;</label>
                                        <button type="button" class="btn btn-sm btn-danger btn-block remove-package"><i class="mdi mdi-close"></i></button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Description</label>
                                    <textarea name="package_description[]" class="form-control" rows="2" placeholder="What's included..."></textarea>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success" id="add-package"><i class="mdi mdi-plus"></i> Add Another Package</button>
                    </div>
                </div>
            </div>
            <div class="text-right mt-3">
                <button type="submit" name="register_vendor" class="btn btn-success"><i class="mdi mdi-check"></i> Submit Registration</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
    document.getElementById('add-package').addEventListener('click', function() {
        var container = document.getElementById('packages');
        var row = document.createElement('div');
        row.className = 'package-row';
        row.innerHTML = `
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Package Name</label>
                    <input type="text" name="package_name[]" class="form-control" placeholder="e.g. Standard Package">
                </div>
                <div class="form-group col-md-3">
                    <label>Price (KSh)</label>
                    <input type="number" name="package_price[]" class="form-control" placeholder="0.00" step="0.01">
                </div>
                <div class="form-group col-md-3">
                    <label>&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger btn-block remove-package"><i class="mdi mdi-close"></i></button>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="package_description[]" class="form-control" rows="2" placeholder="What's included..."></textarea>
            </div>
        `;
        container.appendChild(row);
    });

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-package')) {
            e.target.closest('.package-row').remove();
        }
    });
</script>
</body>
</html>