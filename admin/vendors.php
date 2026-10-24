<?php include 'include/init.php'; ?>
<?php
    /*
     * Two audiences share this page: staff planners (paying subscribers) and
     * vendors themselves — the sidebar links it as their "Marketplace" under
     * is_vendor(). Vendors are paid by commission rather than by SaaS plan, so
     * the subscription gate below must not apply to them; testing is_staff()
     * alone 302'd every vendor back out to the public homepage.
     */
    if (!is_staff() && !is_vendor()) { redirect_to("../"); }

    $user_id = $_SESSION['id'];

    /*
     * Use the shared gate, not a local Subscription::get_active() probe. The
     * hand-rolled version had two defects the rest of the back office does not
     * have: it ignored the grace window (a lapsed-but-in-grace planner was
     * hard-locked here while every other page let them in read-only), and it
     * ignored the operator exemption, so a super admin with no plan of their
     * own — the account that runs the SaaS — was bounced to the paywall by the
     * vendor directory alone. staff_paywall_locked() returns false for a
     * vendor session and for a super admin, so the two-audience behaviour that
     * the comment above describes is preserved.
     */
    if (staff_paywall_locked()) {
        redirect_to("subscription.php");
        die();
    }

    $category_filter = isset($_GET['category']) ? clean($_GET['category'] ?? '') : null;
    $vendors = Vendor::get_approved($category_filter);
    $categories = ['catering','decor','photography','music','transport','mc','venue','cakes','flowers','other'];
    /*
     * What each card's vendor actually sells (v2.27), one query for the whole
     * grid. A listing that has not filled in its services yet simply shows
     * none — the card still works, and the price rows it does have are quoted
     * with their unit, so two vendors can be read against each other.
     */
    $services_by_vendor = VendorService::for_vendors(array_map(
        static fn (object $v): int => (int) $v->id,
        $vendors
    ));
    // Silver/Gold/Platinum for every service on the grid, in one query (v3.1).
    // The card still quotes the vendor's own unit against the Silver price; this
    // map only adds the step-ups underneath, so a planner comparing two caterers
    // is no longer comparing one's cheapest tier with the other's best.
    $svc_tier_map = VendorTier::map_for_services(array_merge(...array_map(
        static fn (array $rows): array => array_map(static fn (object $s): int => (int) $s->id, $rows),
        $services_by_vendor === [] ? [[]] : array_values($services_by_vendor)
    )));

    // Handle vendor contact/booking. A marketplace inquiry is always ABOUT a
    // wedding: the planner picks which of theirs it is for, and it is recorded
    // as a pending tblvendor_bookings row tied to that booking — the same
    // shape a couple's enquiry takes, so it lands in vendor_enquiries.php for
    // the vendor to answer. There is deliberately no booking-less inquiry: one
    // with no wedding behind it would be a promise ("they will contact you
    // shortly") with no row to keep it, which is exactly the debt sweep D
    // tracked as F8.
    if (isset($_POST['contact_vendor'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to("vendors.php");
            die();
        }
        if (!is_staff()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-shield-alert"></i></strong> Only a planner may send a marketplace inquiry.</div>');
            redirect_to("vendors.php");
            die();
        }

        $vendor     = Vendor::find_by_id((int) ($_POST['vendor_id'] ?? 0));
        $booking    = Booking::find_by_booking_id((int) ($_POST['booking_id'] ?? 0));
        $message    = trim((string) ($_POST['message'] ?? ''));
        $event_date = clean($_POST['event_date'] ?? '');

        if (!$vendor || (int) $vendor->is_approved !== 1) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That vendor is not available. Please choose another.</div>');
            redirect_to("vendors.php");
            die();
        }
        if (!booking_visible_to_session($booking)) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Choose a wedding you are planning to send this inquiry for.</div>');
            redirect_to("vendors.php");
            die();
        }
        if ($message === '') {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Please write a short message for the vendor.</div>');
            redirect_to("vendors.php");
            die();
        }

        $booking_id = (int) $booking->booking_id;

        /*
         * Availability (v2.25): before the inquiry is recorded, is this vendor
         * already committed to another wedding that day? The message is a toast
         * that disappears, so the fact is written into the deal's own notes as
         * well — the ledger is where the planner comes back to confirm it, and
         * the warning has to still be there when they do.
         */
        $enquiry_day = VendorAvailability::day(
            $event_date !== '' ? $event_date : null,
            null,
            (string) ($booking->wedding_date ?? '')
        );
        $clash = $enquiry_day !== '' ? VendorAvailability::clashes((int) $vendor->id, $enquiry_day, $booking_id) : [];

        $note = '[' . date('j M Y') . ' — market inquiry by ' . ($_SESSION['user_email'] ?? 'staff') . ".]\n"
            . ($clash !== [] ? '[' . date('j M Y') . ' — WARNING: ' . VendorAvailability::alert($clash) . "]\n" : '')
            . $message . "\n";
        $stmt = $db->prepare(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes, created_at)
             VALUES (:v, :b, NULL, :d, 0, 0, 'pending', :n, NOW())"
        );
        $stmt->execute([
            ':v' => (int) $vendor->id,
            ':b' => $booking_id,
            ':d' => $event_date !== '' ? $event_date : null,
            ':n' => $note,
        ]);

        // The listing owner is who "they will contact you shortly" refers to,
        // so the vendor account is the one told about the inquiry.
        if ((int) $vendor->user_id > 0) {
            Notification::create_for_vendor(
                (int) $vendor->user_id,
                $booking_id,
                'New marketplace inquiry from a planner for ' . $vendor->business_name . '.',
                'vendor_enquiries.php'
            );
        }

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Your inquiry has been sent to the vendor. They will contact you shortly.'
            . ($clash !== []
                ? ' <span class="text-danger"><i class="mdi mdi-calendar-remove"></i> '
                    . htmlspecialchars(VendorAvailability::notice($clash, (string) $vendor->business_name))
                    . ' The warning is saved on the deal too.</span>'
                : '')
            . '</div>');
        redirect_to("vendors.php");
        die();
    }

    /*
     * Publish / unpublish a vendor listing. is_approved is the flag that makes
     * a vendor real: Vendor::get_approved() only returns approved rows, and
     * client_vendor_enquiry.php refuses enquiries to anything else — while
     * vendor_register.php always inserts 0. Nothing in the application used to
     * write this column, so a self-registered vendor stayed "Pending approval"
     * forever, invisible in the marketplace and unreachable by couples, with
     * no screen anywhere that could change it. Approval is an operator
     * decision (vendor_profile.php documents the field as admin-controlled),
     * so it is restricted to the super admin.
     */
    if (isset($_POST['set_vendor_approval'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("vendors.php");
            die();
        }
        if (!is_super_admin()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-shield-alert"></i></strong> Only the operator can approve a vendor listing.</div>');
            redirect_to("vendors.php");
            die();
        }
        $target = Vendor::find_by_id((int) ($_POST['vendor_id'] ?? 0));
        if ($target) {
            $target->is_approved = ((int) ($_POST['approved'] ?? 0) === 1) ? 1 : 0;
            $target->update();
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> '
                . htmlspecialchars((string) $target->business_name)
                . ($target->is_approved ? ' is now live in the marketplace.' : ' has been unpublished.')
                . '</div>');

            /*
             * vendor_register.php promises the vendor "You will be notified
             * once approved" and vendor_home.php tells them to wait for exactly
             * this decision — so the decision must reach them. The bell in
             * include/sidebar.php is rendered for any signed-in user and
             * tblnotifications.booking_id defaults to 0, so a listing event has
             * a valid home there (Notification::create also queues the email
             * leg).
             */
            $vendorUserId = (int) $target->user_id;
            if ($vendorUserId > 0) {
                Notification::create_for_vendor(
                    $vendorUserId,
                    0,
                    $target->is_approved
                        ? 'Your listing "' . $target->business_name . '" is approved and now live in the marketplace.'
                        : 'Your listing "' . $target->business_name . '" has been hidden from the marketplace by the operator.',
                    'vendor_profile.php'
                );
            }
        } else {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That vendor listing no longer exists.</div>');
        }
        redirect_to("vendors.php");
        die();
    }

    /*
     * Premium placement. tblvendors.is_premium sorts every marketplace query
     * (Vendor::get_approved: ORDER BY is_premium DESC) and drives the "Premium"
     * badge on the couple's marketplace and the vendor's own profile — but
     * nothing in the application ever wrote the column, so the badge could only
     * ever appear for a hand-seeded row and the operator who is supposed to
     * grant it (vendor_profile.php documents it as an admin-controlled field)
     * had no control at all. Same operator-only rule as approval.
     */
    if (isset($_POST['set_vendor_premium'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("vendors.php");
            die();
        }
        if (!is_super_admin()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-shield-alert"></i></strong> Only the operator can change a vendor\'s placement.</div>');
            redirect_to("vendors.php");
            die();
        }
        $target = Vendor::find_by_id((int) ($_POST['vendor_id'] ?? 0));
        if ($target) {
            $target->is_premium = ((int) ($_POST['premium'] ?? 0) === 1) ? 1 : 0;
            $target->update();
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> '
                . htmlspecialchars((string) $target->business_name)
                . ($target->is_premium ? ' is now a premium listing.' : ' is no longer a premium listing.')
                . '</div>');
            if ((int) $target->user_id > 0) {
                Notification::create_for_vendor(
                    (int) $target->user_id,
                    0,
                    $target->is_premium
                        ? 'Your listing "' . $target->business_name . '" has been upgraded to Premium placement.'
                        : 'Your listing "' . $target->business_name . '" has been removed from Premium placement.',
                    'vendor_profile.php'
                );
            }
        } else {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> That vendor listing no longer exists.</div>');
        }
        redirect_to("vendors.php");
        die();
    }

    /* Listings still waiting on the operator — shown to nobody else. */
    $pending_vendors = is_super_admin()
        ? $db->fetchAll('SELECT * FROM tblvendors WHERE is_approved = 0 ORDER BY id ASC')
        : [];

    /*
     * Weddings this planner may attach an inquiry to — the same scoping the
     * inquiry handler enforces via booking_visible_to_session() (a planner:
     * their own plus the unassigned; a super admin: all). Built only for a
     * planner session, so a vendor browsing the marketplace never sees a
     * booking list they could not use.
     */
    $inquiry_bookings = [];
    $preselected_booking = (int) ($_GET['booking_id'] ?? 0);
    if (is_staff()) {
        $scope = planner_scope_sql('b.organizer_id');
        $inquiry_bookings = $db->fetchAll(
            'SELECT b.booking_id, b.bride, b.groom, b.wedding_date
               FROM tblweddingbook b'
            . ($scope['sql'] !== '' ? ' WHERE ' . $scope['sql'] : '')
            . ' ORDER BY b.wedding_date DESC, b.booking_id DESC',
            $scope['params']
        );
    }

    /*
     * The couple's brief, when the planner arrived here FOR a wedding (v2.28).
     * `?booking_id=N` already exists — the coverage board's "find a vendor"
     * links carry it, and the inquiry modal preselects that wedding — so this
     * is the same context, used for matching instead of only for booking.
     *
     * It is resolved through booking_visible_to_session() rather than trusted:
     * a planner who may not open the wedding must not learn what another
     * planner's couple wants to spend. On a refused id the page simply renders
     * without matching, exactly as it did before this feature.
     */
    $fit_booking = false;
    $fit_prefs   = [];
    $fit_guests  = 0;
    $vendor_fits = [];
    if (is_staff() && $preselected_booking > 0) {
        $candidate = Booking::find_by_booking_id($preselected_booking);
        if (booking_visible_to_session($candidate)) {
            $fit_booking = $candidate;
        }
    }
    if ($fit_booking && VendorFit::installed()) {
        $fit_prefs  = VendorFit::preferences((int) $fit_booking->booking_id);
        $fit_guests = VendorFit::guests((int) $fit_booking->booking_id);
        foreach ($vendors as $v) {
            $vendor_fits[(int) $v->id] = VendorFit::vendor_fit(
                $services_by_vendor[(int) $v->id] ?? [],
                $fit_prefs,
                $fit_guests,
                (string) $v->category
            );
        }
    }
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Vendor Marketplace - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .vendor-card { border-radius: 8px; overflow: hidden; margin-bottom: 20px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; }
        .vendor-card:hover { transform: translateY(-3px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
        .vendor-card .card-body { padding: 20px; }
        .vendor-card .vendor-logo { width: 60px; height: 60px; border-radius: 50%; background: #f8f9fa; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #065F46; margin-bottom: 10px; }
        .vendor-card .vendor-name { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 5px; }
        .vendor-card .vendor-category { font-size: 11px; text-transform: uppercase; color: #888; letter-spacing: 0.5px; }
        .vendor-card .vendor-location { font-size: 12px; color: #666; margin: 8px 0; }
        .vendor-card .vendor-rating { color: #D97706; font-size: 12px; }
        .premium-badge { background: linear-gradient(135deg, #D97706, #B45309); color: #fff; font-size: 10px; padding: 3px 8px; border-radius: 10px; }
        .category-filter { margin-bottom: 20px; }
        .category-filter .btn { margin-right: 5px; margin-bottom: 5px; font-size: 12px; }
        .category-filter .btn.active { background: #065F46; border-color: #065F46; }
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

    <?php if ($pending_vendors !== []): ?>
        <!-- Awaiting approval — operator only -->
        <div class="card mb-4" style="border-left:4px solid #D97706;">
            <div class="card-body">
                <h5 class="mb-3"><i class="mdi mdi-account-clock"></i> Awaiting approval
                    <span class="badge badge-warning"><?= count($pending_vendors); ?></span>
                </h5>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th>Business</th><th>Category</th><th>Contact</th><th>Location</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending_vendors as $pending): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string) $pending->business_name); ?></strong></td>
                                <td><?= htmlspecialchars(ucfirst((string) $pending->category)); ?></td>
                                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars((string) $pending->email); ?><br><?= htmlspecialchars((string) $pending->phone); ?></td>
                                <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars((string) $pending->location); ?>, <?= htmlspecialchars((string) $pending->county); ?></td>
                                <td class="text-right">
                                    <form method="post" class="d-inline">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="set_vendor_approval" value="1">
                                        <input type="hidden" name="vendor_id" value="<?= (int) $pending->id; ?>">
                                        <input type="hidden" name="approved" value="1">
                                        <button type="submit" class="btn btn-sm btn-success"><i class="mdi mdi-check"></i> Approve</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($fit_booking): ?>
        <?php
        $fit_couple = trim(((string) ($fit_booking->bride ?? '')) . ' & ' . ((string) ($fit_booking->groom ?? '')), ' &');
        $fit_when   = !empty($fit_booking->wedding_date) ? format_date_kd((string) $fit_booking->wedding_date) : '';
        ?>
        <div class="card mb-3" style="border:0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div class="mr-3">
                        <h6 class="mb-1" style="font-weight:700;">
                            <i class="mdi mdi-palette-swatch mr-1" style="color:#0E7490;"></i>
                            Shopping for <?= htmlspecialchars($fit_couple !== '' ? $fit_couple : 'this wedding'); ?><?= $fit_when !== '' ? ' · ' . htmlspecialchars($fit_when) : ''; ?>
                        </h6>
                        <div class="text-muted" style="font-size:12px;">
                            <?= (int) $fit_guests; ?> guest<?= $fit_guests === 1 ? '' : 's'; ?> on their list
                            <?php if ($fit_prefs === []): ?>
                                · no taste or budget set yet — ask them, or set it with them
                            <?php endif; ?>
                        </div>
                        <?php if ($fit_prefs !== []): ?>
                            <div class="mt-2 d-flex flex-wrap">
                                <?php foreach (VendorCoverage::categories() as $slug => $meta): ?>
                                    <?php if (!isset($fit_prefs[$slug])) { continue; } ?>
                                    <span class="btn btn-sm btn-light mr-1 mb-1" style="cursor:default;" title="Their note: <?= htmlspecialchars(trim((string) ($fit_prefs[$slug]->notes ?? '')) !== '' ? (string) $fit_prefs[$slug]->notes : 'none'); ?>">
                                        <i class="mdi mdi-<?= htmlspecialchars($meta[1]); ?> mr-1"></i><?= htmlspecialchars($meta[0]); ?>
                                        <span class="text-muted ml-1" style="font-size:11px;"><?= htmlspecialchars(VendorFit::summary($fit_prefs[$slug])); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <a class="btn btn-sm btn-outline-info" href="client_vendor_preferences.php?booking_id=<?= (int) $fit_booking->booking_id; ?>&user_id=<?= (int) $fit_booking->user_id; ?>">
                            <i class="mdi mdi-tune-variant"></i> Taste &amp; budget
                        </a>
                        <div class="text-muted mt-1" style="font-size:11.5px;">Updates as you talk, so the marks below stay honest.</div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Vendors Grid -->
    <div class="row">
        <?php if (empty($vendors)): ?>
            <div class="col-12">
                <?php empty_state('No vendors here yet', 'Vendors in this category will appear here once they are approved.', 'mdi-store-outline', 'vendor_register.php', 'Register as a Vendor', 'mdi-store-plus-outline'); ?>
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
                            <?php $vfit = $vendor_fits[(int) $vendor->id] ?? null; ?>
                            <?php if ($vfit !== null && $vfit['state'] !== 'unset' && $vfit['label'] !== ''): ?>
                                <span class="badge badge-<?= htmlspecialchars($vfit['tone']); ?> mt-1"
                                      title="<?= htmlspecialchars(implode(' ', $vfit['reasons'])); ?>">
                                    <i class="mdi mdi-<?= htmlspecialchars($vfit['icon']); ?> mr-1"></i><?= htmlspecialchars($vfit['label']); ?>
                                </span>
                            <?php endif; ?>
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
                            <?php $svcs = $services_by_vendor[(int) $vendor->id] ?? []; ?>
                            <?php if ($svcs !== []): ?>
                                <div class="mt-2" style="border-top:1px solid #F1F5F9; padding-top:6px;">
                                    <?php foreach (array_slice($svcs, 0, 3) as $svc): ?>
                                        <?php
                                        $svc_cat  = (string) ($svc->catalog_category ?? '');
                                        $svc_pref = $fit_prefs[$svc_cat !== '' ? $svc_cat : (string) $vendor->category] ?? null;
                                        $svc_fit  = $fit_booking ? VendorFit::assess($svc, $svc_pref, $fit_guests) : null;
                                        ?>
                                        <div class="d-flex justify-content-between align-items-center" style="font-size:12px; color:#475569;">
                                            <span class="text-truncate mr-2" title="<?= htmlspecialchars((string) $svc->title); ?>">
                                                <?= htmlspecialchars((string) $svc->title); ?>
                                                <?php if ($svc_fit !== null && $svc_fit['state'] !== 'unset' && $svc_fit['label'] !== ''): ?>
                                                    <span class="badge badge-<?= htmlspecialchars($svc_fit['tone']); ?> ml-1" style="font-size:9px;"
                                                          title="<?= htmlspecialchars(implode(' ', $svc_fit['reasons'])); ?>">
                                                        <i class="mdi mdi-<?= htmlspecialchars($svc_fit['icon']); ?>"></i> <?= htmlspecialchars($svc_fit['label']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="text-nowrap" style="color:#065F46; font-weight:600; font-variant-numeric:tabular-nums;"><?= htmlspecialchars(VendorService::price_line($svc)); ?></span>
                                        </div>
                                        <?php $svc_tiers = $svc_tier_map[(int) $svc->id] ?? []; ?>
                                        <?php if ($svc_tiers !== []): ?>
                                            <div class="text-muted" style="font-size:10.5px; margin-top:-2px;"><?= htmlspecialchars(VendorTier::line($svc_tiers)); ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (count($svcs) > 3): ?>
                                        <div class="text-muted" style="font-size:11px;">+ <?= count($svcs) - 3; ?> more service<?= count($svcs) - 3 === 1 ? '' : 's'; ?> on their profile</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (is_staff() && $inquiry_bookings !== []): ?>
                            <button class="btn btn-sm btn-danger mt-2" onclick="showContact(<?= $vendor->id; ?>, '<?= htmlspecialchars($vendor->business_name); ?>')">
                                <i class="mdi mdi-message"></i> Contact Vendor
                            </button>
                            <?php endif; ?>
                            <?php if (is_super_admin()): ?>
                                <form method="post" class="d-inline">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="set_vendor_approval" value="1">
                                    <input type="hidden" name="vendor_id" value="<?= (int) $vendor->id; ?>">
                                    <input type="hidden" name="approved" value="0">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary mt-2" title="Hide this listing from couples"><i class="mdi mdi-eye-off"></i> Unpublish</button>
                                </form>
                                <form method="post" class="d-inline">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="set_vendor_premium" value="1">
                                    <input type="hidden" name="vendor_id" value="<?= (int) $vendor->id; ?>">
                                    <input type="hidden" name="premium" value="<?= $vendor->is_premium ? '0' : '1'; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning mt-2" title="Paid placement: sorts this listing first in the marketplace">
                                        <i class="mdi mdi-crown"></i> <?= $vendor->is_premium ? 'Remove premium' : 'Make premium'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
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
                            <label>Which wedding is this for?</label>
                            <select name="booking_id" class="form-control" required>
                                <option value="">Choose a wedding&hellip;</option>
                                <?php foreach ($inquiry_bookings as $ib):
                                    $who  = trim(($ib->bride ?? '') . ' & ' . ($ib->groom ?? ''), ' &');
                                    $when = !empty($ib->wedding_date) ? format_date_kd((string) $ib->wedding_date) : 'date to be confirmed';
                                ?>
                                    <option value="<?= (int) $ib->booking_id; ?>" <?= $preselected_booking === (int) $ib->booking_id ? 'selected' : ''; ?>>#<?= (int) $ib->booking_id; ?> &mdash; <?= htmlspecialchars($who !== '' ? $who : 'Wedding'); ?> (<?= htmlspecialchars($when); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Your inquiry is recorded against this wedding, and your planner and the vendor see it.</small>
                        </div>
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