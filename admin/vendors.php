<?php include 'include/init.php'; ?>
<?php
    if (!isset($_SESSION['id'])) { redirect_to("../"); }

    $user_id = $_SESSION['id'];
    $current_sub = Subscription::get_active($user_id);

    // Check subscription
    if (!$current_sub) {
        redirect_to("subscription.php");
        die();
    }

    $category_filter = isset($_GET['category']) ? clean($_GET['category']) : null;
    $vendors = Vendor::get_approved($category_filter);
    $categories = ['catering','decor','photography','music','transport','mc','venue','cakes','flowers','other'];

    // Handle vendor contact/booking
    if (isset($_POST['contact_vendor'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to("vendors.php");
            die();
        }

        $vendor_id = (int)$_POST['vendor_id'];
        $message = clean($_POST['message']);
        $event_date = clean($_POST['event_date']);

        // Log the inquiry
        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Your inquiry has been sent to the vendor. They will contact you shortly.</div>');
        redirect_to("vendors.php");
    }
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Vendor Marketplace - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css"
    <link href="css/modern-admin.css" rel="stylesheet"> rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .vendor-card { border-radius: 8px; overflow: hidden; margin-bottom: 20px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; }
        .vendor-card:hover { transform: translateY(-3px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
        .vendor-card .card-body { padding: 20px; }
        .vendor-card .vendor-logo { width: 60px; height: 60px; border-radius: 50%; background: #f8f9fa; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #c0392b; margin-bottom: 10px; }
        .vendor-card .vendor-name { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 5px; }
        .vendor-card .vendor-category { font-size: 11px; text-transform: uppercase; color: #888; letter-spacing: 0.5px; }
        .vendor-card .vendor-location { font-size: 12px; color: #666; margin: 8px 0; }
        .vendor-card .vendor-rating { color: #f39c12; font-size: 12px; }
        .premium-badge { background: linear-gradient(135deg, #f39c12, #e67e22); color: #fff; font-size: 10px; padding: 3px 8px; border-radius: 10px; }
        .category-filter { margin-bottom: 20px; }
        .category-filter .btn { margin-right: 5px; margin-bottom: 5px; font-size: 12px; }
        .category-filter .btn.active { background: #c0392b; border-color: #c0392b; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4><i class="mdi mdi-store"></i> Vendor Marketplace</h4>
        <a href="vendor_register.php" class="btn btn-sm btn-success"><i class="mdi mdi-plus"></i> Register as Vendor</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <!-- Category Filter -->
    <div class="category-filter">
        <a href="vendors.php" class="btn btn-sm btn-outline-danger <?= !$category_filter ? 'active' : ''; ?>">All</a>
        <?php foreach ($categories as $cat): ?>
            <a href="vendors.php?category=<?= $cat; ?>" class="btn btn-sm btn-outline-danger <?= ($category_filter == $cat) ? 'active' : ''; ?>"><?= ucfirst($cat); ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Vendors Grid -->
    <div class="row">
        <?php if (empty($vendors)): ?>
            <div class="col-12 text-center py-5">
                <i class="mdi mdi-store" style="font-size: 48px; color: #ccc;"></i>
                <p class="text-muted mt-3">No vendors found in this category. Check back soon!</p>
            </div>
        <?php else: ?>
            <?php foreach ($vendors as $vendor): ?>
                <div class="col-md-4">
                    <div class="vendor-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="vendor-logo">
                                    <i class="mdi mdi-<?= get_category_icon($vendor->category); ?>"></i>
                                </div>
                                <?php if ($vendor->is_premium): ?>
                                    <span class="premium-badge"><i class="mdi mdi-crown"></i> Premium</span>
                                <?php endif; ?>
                            </div>
                            <div class="vendor-name"><?= htmlspecialchars($vendor->business_name); ?></div>
                            <div class="vendor-category"><?= ucfirst($vendor->category); ?></div>
                            <div class="vendor-location"><i class="mdi mdi-map-marker"></i> <?= htmlspecialchars($vendor->location); ?>, <?= htmlspecialchars($vendor->county); ?></div>
                            <div class="vendor-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="mdi mdi-star<?= $i <= $vendor->rating ? '' : '-outline'; ?>"></i>
                                <?php endfor; ?>
                                <span class="text-muted">(<?= $vendor->total_bookings; ?> bookings)</span>
                            </div>
                            <?php if ($vendor->description): ?>
                                <p class="text-muted mt-2" style="font-size: 12px;"><?= htmlspecialchars(substr($vendor->description, 0, 100)); ?>...</p>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger mt-2" onclick="showContact(<?= $vendor->id; ?>, '<?= htmlspecialchars($vendor->business_name); ?>')">
                                <i class="mdi mdi-message"></i> Contact Vendor
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Contact Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="vendor_id" id="contact_vendor_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="contact_title">Contact Vendor</h5>
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Event Date</label>
                            <input type="date" name="event_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Your Message</label>
                            <textarea name="message" class="form-control" rows="4" placeholder="Tell the vendor about your wedding, expected guests, budget, etc." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="contact_vendor" class="btn btn-success"><i class="mdi mdi-send"></i> Send Inquiry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
    function showContact(id, name) {
        document.getElementById('contact_vendor_id').value = id;
        document.getElementById('contact_title').textContent = 'Contact: ' + name;
        $('#contactModal').modal('show');
    }
</script>
</body>
</html>
<?php
function get_category_icon($category) {
    $icons = [
        'catering' => 'food',
        'decor' => 'flower',
        'photography' => 'camera',
        'music' => 'music',
        'transport' => 'bus',
        'mc' => 'microphone',
        'venue' => 'home',
        'cakes' => 'cake',
        'flowers' => 'flower-tulip',
        'other' => 'briefcase'
    ];
    return $icons[$category] ?? 'store';
}
?>