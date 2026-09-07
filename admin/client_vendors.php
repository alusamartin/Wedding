<?php include 'include/init.php'; ?>
<?php
/**
 * Couple-facing vendor marketplace.
 *
 * The staff marketplace (vendors.php) lives behind the staff sidebar and a
 * subscription gate; couples had no way to browse vendors at all. This page
 * shows approved vendors with their active packages and prices in KSh,
 * filters by category and county, and sends enquiries through
 * client_vendor_enquiry.php (POST + CSRF, booking-scoped). Every enquiry is
 * tied to the couple's own wedding so the planner sees the context in the
 * notification.
 */
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id    = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }
    $booking = Booking::find_by_booking_id($booking_id);
    if (!is_staff()) {
        if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
            redirect_to("client.php");
            die();
        }
    }
    if (!$booking) {
        redirect_to("client.php");
        die();
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $categories = ['catering','decor','photography','music','transport','mc','venue','cakes','flowers','other'];
    $category_filter = clean($_GET['category'] ?? '');
    $county_filter   = clean($_GET['county'] ?? '');
    if (!in_array($category_filter, $categories, true)) {
        $category_filter = '';
    }

    // Browse approved vendors (premium first, then rating). County filter is
    // applied in PHP — the vendor table is small and the enum categories are
    // the real navigation.
    $vendors = Vendor::get_approved($category_filter !== '' ? $category_filter : null);
    if ($county_filter !== '') {
        $vendors = array_values(array_filter($vendors, fn($v) => strcasecmp((string) $v->county, $county_filter) === 0));
    }
    $counties = [];
    foreach (Vendor::get_approved(null) as $v) {
        $counties[$v->county] = true;
    }
    $counties = array_keys($counties);
    sort($counties);

    // Active packages per vendor, keyed for the cards (one query per vendor
    // is fine at marketplace scale; the table is tens of rows, not thousands).
    $packages_by_vendor = [];
    foreach ($vendors as $v) {
        $packages_by_vendor[$v->id] = Vendor::packages_for((int) $v->id);
    }

    function vendor_category_icon(string $category): string
    {
        $icons = [
            'catering' => 'food', 'decor' => 'flower', 'photography' => 'camera',
            'music' => 'music', 'transport' => 'bus', 'mc' => 'microphone',
            'venue' => 'home', 'cakes' => 'cake', 'flowers' => 'flower-tulip',
            'other' => 'briefcase',
        ];
        return $icons[$category] ?? 'store';
    }

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &');
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd($booking->wedding_date) : '';
    $is_client_view = !is_staff();
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $is_client_view ? 'Vendor Marketplace - Everafter Planner' : 'Couple Vendor Marketplace - Everafter Planner'; ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .vendor-card { border-radius: 14px; overflow: hidden; margin-bottom: 20px; background: #fff;
                       box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: all 0.3s; height: 100%; }
        .vendor-card:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,0.12); }
        .vendor-logo { width: 54px; height: 54px; border-radius: 50%; background: #F0FDF4; display: flex;
                       align-items: center; justify-content: center; font-size: 24px; color: #065F46; }
        .vendor-name { font-size: 15px; font-weight: 700; color: #1C1917; }
        .vendor-category { font-size: 10.5px; text-transform: uppercase; color: #64748B; letter-spacing: .06em; }
        .vendor-rating { color: #D97706; font-size: 12px; }
        .premium-badge { background: linear-gradient(135deg, #D97706, #B45309); color: #fff; font-size: 10px;
                         padding: 3px 8px; border-radius: 10px; }
        .pkg-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0;
                   border-bottom: 1px dashed #E2E8F0; font-size: 12.5px; }
        .pkg-row:last-child { border-bottom: 0; }
        .pkg-price { font-weight: 700; color: #065F46; white-space: nowrap; margin-left: 10px; }
        .filter-chip { border-radius: 999px; font-size: 12px; padding: 4px 14px; margin: 0 5px 6px 0; }
        .filter-chip.active { background: #065F46; border-color: #065F46; color: #fff; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-store" style="color:#065F46"></i> Vendor Marketplace</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '' ? htmlspecialchars($couple) . ' — ' : ''; ?>trusted vendors for your day<?= $wedding_human !== '' ? ' (' . htmlspecialchars($wedding_human) . ')' : ''; ?> — enquire and your planner stays in the loop
            </p>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="mb-3">
        <a href="client_vendors.php?<?= $links; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $category_filter === '' ? 'active' : ''; ?>">All</a>
        <?php foreach ($categories as $cat): ?>
            <a href="client_vendors.php?<?= $links; ?>&category=<?= $cat; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $category_filter === $cat ? 'active' : ''; ?>"><?= ucfirst($cat); ?></a>
        <?php endforeach; ?>
        <?php if (count($counties) > 1): ?>
        <form method="get" action="client_vendors.php" class="d-inline ml-2">
            <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
            <input type="hidden" name="user_id" value="<?= $user_id; ?>">
            <?php if ($category_filter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category_filter); ?>"><?php endif; ?>
            <select name="county" class="btn btn-sm btn-outline-secondary filter-chip" onchange="this.form.submit()">
                <option value="">All counties</option>
                <?php foreach ($counties as $c): ?>
                    <option value="<?= htmlspecialchars($c); ?>" <?= strcasecmp($county_filter, $c) === 0 ? 'selected' : ''; ?>><?= htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <!-- Vendors Grid -->
    <div class="row">
        <?php if (empty($vendors)): ?>
            <div class="col-12">
                <div class="alert alert-light border text-center" style="border-radius: 14px;">
                    <i class="mdi mdi-store-outline" style="font-size: 34px; color: #94A3B8;"></i>
                    <h6 class="mt-2">No vendors here yet</h6>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        <?= $county_filter !== '' ? 'Nothing in ' . htmlspecialchars($county_filter) . ' for this category — try another county or category.' : 'Vendors in this category will appear here once they are approved.'; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($vendors as $vendor): ?>
                <div class="col-md-4">
                    <div class="vendor-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="d-flex align-items-center">
                                    <div class="vendor-logo mr-2"><i class="mdi mdi-<?= vendor_category_icon((string) $vendor->category); ?>"></i></div>
                                    <div>
                                        <div class="vendor-name"><?= htmlspecialchars($vendor->business_name); ?></div>
                                        <div class="vendor-category"><?= ucfirst($vendor->category); ?></div>
                                    </div>
                                </div>
                                <?php if ($vendor->is_premium): ?>
                                    <span class="premium-badge"><i class="mdi mdi-crown"></i> Premium</span>
                                <?php endif; ?>
                            </div>
                            <div class="vendor-location mt-2" style="font-size: 12px; color: #64748B;">
                                <i class="mdi mdi-map-marker"></i> <?= htmlspecialchars($vendor->location); ?>, <?= htmlspecialchars($vendor->county); ?>
                            </div>
                            <div class="vendor-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="mdi mdi-star<?= $i <= (float) $vendor->rating ? '' : '-outline'; ?>"></i>
                                <?php endfor; ?>
                                <span class="text-muted">(<?= (int) $vendor->total_bookings; ?> bookings)</span>
                            </div>
                            <?php if ($vendor->description): ?>
                                <p class="text-muted mt-2 mb-1" style="font-size: 12px;"><?= htmlspecialchars(mb_substr((string) $vendor->description, 0, 110)); ?><?= mb_strlen((string) $vendor->description) > 110 ? '…' : ''; ?></p>
                            <?php endif; ?>
                            <?php $pkgs = $packages_by_vendor[$vendor->id] ?? []; ?>
                            <?php if ($pkgs !== []): ?>
                                <div class="mt-2">
                                    <?php foreach (array_slice($pkgs, 0, 3) as $p): ?>
                                        <div class="pkg-row">
                                            <span><?= htmlspecialchars($p->title); ?><?= (int) $p->capacity > 0 ? ' <span class="text-muted" style="font-size:11px;">· up to ' . (int) $p->capacity . ' pax</span>' : ''; ?></span>
                                            <span class="pkg-price"><?= format_ksh($p->price); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($pkgs) > 3): ?>
                                        <div class="text-muted" style="font-size: 11px;">+ <?= count($pkgs) - 3; ?> more package<?= count($pkgs) - 3 === 1 ? '' : 's'; ?> on enquiry</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-success mt-2"
                                    onclick="showEnquiry(<?= (int) $vendor->id; ?>, '<?= htmlspecialchars(addslashes($vendor->business_name)); ?>')">
                                <i class="mdi mdi-message"></i> Enquire
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Enquiry Modal -->
    <div class="modal fade" id="enquiryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 14px;">
                <form method="post" action="client_vendor_enquiry.php?<?= $links; ?>">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="vendor_id" id="enquiry_vendor_id">
                    <div class="modal-header" style="border-bottom: 0;">
                        <h5 class="modal-title" id="enquiry_title">Enquire</h5>
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Package of interest <span class="text-muted" style="font-size:12px;">(optional)</span></label>
                            <select name="package_id" id="enquiry_package" class="form-control">
                                <option value="0">General enquiry — no specific package</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Event date</label>
                            <input type="date" name="event_date" class="form-control"
                                   value="<?= !empty($booking->wedding_date) ? htmlspecialchars(date('Y-m-d', strtotime((string) $booking->wedding_date))) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>Your message</label>
                            <textarea name="message" class="form-control" rows="4" required
                                      placeholder="Tell the vendor about your wedding — expected guests, venue, budget…"></textarea>
                        </div>
                        <p class="text-muted mb-0" style="font-size: 12px;">
                            <i class="mdi mdi-information-outline mr-1"></i>Your planner is notified of every enquiry and can help negotiate.
                        </p>
                    </div>
                    <div class="modal-footer" style="border-top: 0;">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="send_enquiry" value="1" class="btn btn-success"><i class="mdi mdi-send"></i> Send Enquiry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'include/footer.php'; ?>
<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
    var packagesByVendor = <?= json_encode(array_map(fn($rows) => array_map(fn($p) => ['id' => (int) $p->id, 'title' => $p->title], $rows), $packages_by_vendor)) ?: '[]'; ?>;
    function showEnquiry(id, name) {
        document.getElementById('enquiry_vendor_id').value = id;
        document.getElementById('enquiry_title').textContent = 'Enquire: ' + name;
        var sel = document.getElementById('enquiry_package');
        sel.innerHTML = '<option value="0">General enquiry — no specific package</option>';
        (packagesByVendor[id] || []).forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.title;
            sel.appendChild(opt);
        });
        $('#enquiryModal').modal('show');
    }
</script>
</body>
</html>
