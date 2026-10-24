<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
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
    // Couples browse with their own booking only; staff may browse for any
    // (a missing booking is refused for everyone by the helper).
    if (!may_open_couple_page($booking, $user_id)) {
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
    /*
     * Individual services per vendor (v2.27): the pieces behind a package,
     * each with the unit its price is quoted in. A couple comparing two
     * caterers can finally read "1,200 / guest" against "1,800 / guest"
     * instead of two flat totals for different guest counts. One query.
     */
    $services_by_vendor = VendorService::for_vendors(array_map(
        static fn (object $v): int => (int) $v->id,
        $vendors
    ));
    /*
     * Silver/Gold/Platinum per service and per package (v3.1), one query each
     * for the whole grid. The card keeps quoting the vendor's unit against the
     * Silver price and prints the step-ups under it, so a couple can see that
     * the figure they fit is the entry level rather than the whole offer.
     */
    $svc_tier_map = VendorTier::map_for_services(array_merge(...array_map(
        static fn (array $rows): array => array_map(static fn (object $s): int => (int) $s->id, $rows),
        $services_by_vendor === [] ? [[]] : array_values($services_by_vendor)
    )));
    $pkg_tier_map = VendorTier::map_for_packages(array_merge(...array_map(
        static fn (array $rows): array => array_map(static fn (object $p): int => (int) $p->id, $rows),
        $packages_by_vendor === [] ? [[]] : array_values($packages_by_vendor)
    )));

    /*
     * The couple's brief (v2.28): their taste tags and budget cap per vendor
     * category, and how each vendor's services measure against it. The fit is
     * computed here, on read, from the unit price and the guest list — nothing
     * is stored, so changing the budget or a vendor re-pricing a service can
     * never leave a stale badge behind.
     *
     * `?fit=1` is the filter the whole feature exists for: keep only vendors
     * with at least one service inside the couple's budget (fits or partly
     * fits). A vendor whose only service is over the cap is not "close" — the
     * whole point is that the couple sees the queue that is actually shoppable.
     */
    $prefs      = VendorFit::preferences($booking_id);
    $guests     = VendorFit::guests($booking_id);
    $fit_filter = isset($_GET['fit']) && (string) $_GET['fit'] === '1';

    $vendor_fits = [];
    foreach ($vendors as $v) {
        $vendor_fits[(int) $v->id] = VendorFit::vendor_fit(
            $services_by_vendor[(int) $v->id] ?? [],
            $prefs,
            $guests,
            (string) $v->category
        );
    }
    if ($fit_filter) {
        $vendors = array_values(array_filter($vendors, static function (object $v) use ($vendor_fits): bool {
            return in_array($vendor_fits[(int) $v->id]['state'] ?? 'unset', ['fits', 'partial'], true);
        }));
    }

    // This couple's enquiries with their live status — the loop-closer so
    // couples aren't sending enquiries into a void.
    global $db;
    $myEnquiries = $db->prepare(
        'SELECT vb.id, vb.status, vb.event_date, vb.amount, vb.notes, vb.created_at, vb.rating,
                v.business_name, v.category,
                p.title AS package_title
           FROM tblvendor_bookings vb
           JOIN tblvendors v ON v.id = vb.vendor_id
           LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
          WHERE vb.booking_id = :b
          ORDER BY vb.status = \'pending\' DESC, vb.created_at DESC, vb.id DESC'
    );
    $myEnquiries->execute([':b' => $booking_id]);
    $myEnquiries = $myEnquiries->fetchAll(PDO::FETCH_OBJ);

    /*
     * The conversation on each enquiry (v2.25). Three reads for the whole
     * list, not three per row: the threads, and this couple's unread count on
     * each. The deals are given the couple's own names so stage() can speak
     * about the wedding rather than about a booking id.
     *
     * `?thread=N` is how vendor_thread_open.php (the link behind a bell, or
     * the "Open conversation" button) says which panel to render; a value that
     * is not one of this couple's own deals is ignored, exactly as the opener
     * would have refused it.
     */
    $enq_ids = array_map(static fn($e) => (int) $e->id, $myEnquiries);
    $threads_by_deal = VendorThread::for_deals($enq_ids);
    $unread_by_deal  = VendorThread::unread_counts($enq_ids, VendorThread::COUPLE);
    $thread_request  = (int) ($_GET['thread'] ?? 0);
    $thread_to_open  = in_array($thread_request, $enq_ids, true) ? $thread_request : 0;
    foreach ($myEnquiries as $enq) {
        $enq->bride          = $booking->bride ?? '';
        $enq->groom          = $booking->groom ?? '';
        $enq->booking_id     = (int) $booking_id;
        $enq->couple_user_id = (int) ($booking->user_id ?? $user_id);
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
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
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

    <!-- The couple's own brief (v2.28) -->
    <div class="card mb-3" style="border:0; border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div class="mr-3">
                    <h6 class="mb-1" style="font-weight:700;"><i class="mdi mdi-palette-swatch mr-1" style="color:#0E7490;"></i> <?= $is_client_view ? 'Your' : "The couple's"; ?> taste &amp; budget</h6>
                    <?php if ($prefs === []): ?>
                        <p class="text-muted mb-0" style="font-size:12.5px;">
                            Tell us what you want from each kind of vendor — “rustic, halal, up to KSh 250,000” — and this page marks which services actually fit.
                        </p>
                    <?php else: ?>
                        <div class="d-flex flex-wrap">
                            <?php foreach (VendorCoverage::categories() as $slug => $meta): ?>
                                <?php if (!isset($prefs[$slug])) { continue; } ?>
                                <?php $pref_row = $prefs[$slug]; ?>
                                <a class="btn btn-sm btn-outline-info filter-chip"
                                   href="client_vendor_preferences.php?<?= $links; ?>#<?= htmlspecialchars($slug); ?>"
                                   title="Edit your <?= htmlspecialchars(mb_strtolower($meta[0])); ?> preference">
                                    <i class="mdi mdi-<?= htmlspecialchars($meta[1]); ?> mr-1"></i><?= htmlspecialchars($meta[0]); ?>
                                    <?php if (VendorFit::summary($pref_row) !== ''): ?>
                                        <span class="text-muted ml-1" style="font-size:11px;"><?= htmlspecialchars(VendorFit::summary($pref_row)); ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if ($guests === 0): ?>
                                <span class="text-muted align-self-center ml-1" style="font-size:11.5px;">
                                    <i class="mdi mdi-account-alert"></i> No guests on your list yet, so per-guest prices show as “quote needed”.
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mt-1">
                    <a class="btn btn-sm btn-outline-success" href="client_vendor_preferences.php?<?= $links; ?>">
                        <i class="mdi mdi-tune-variant" aria-hidden="true"></i> <?= $prefs === [] ? ($is_client_view ? 'Set your taste & budget' : 'Set the brief with them') : 'Edit'; ?>
                    </a>
                    <a class="btn btn-sm btn-outline-dark" href="<?= $is_client_view ? 'client_vendor_plan.php' : 'vendor_plan.php'; ?>?<?= $links; ?>">
                        <i class="mdi mdi-clipboard-text-outline" aria-hidden="true"></i> Plan &amp; total
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-3">
        <a href="client_vendors.php?<?= $links; ?><?= $fit_filter ? '&fit=1' : ''; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $category_filter === '' ? 'active' : ''; ?>">All</a>
        <?php foreach ($categories as $cat): ?>
            <a href="client_vendors.php?<?= $links; ?>&category=<?= $cat; ?><?= $fit_filter ? '&fit=1' : ''; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $category_filter === $cat ? 'active' : ''; ?>"><?= ucfirst($cat); ?></a>
        <?php endforeach; ?>
        <?php if ($prefs !== []): ?>
            <a href="client_vendors.php?<?= $links; ?><?= $category_filter !== '' ? '&category=' . urlencode($category_filter) : ''; ?><?= $county_filter !== '' ? '&county=' . urlencode($county_filter) : ''; ?><?= $fit_filter ? '' : '&fit=1'; ?>"
               class="btn btn-sm btn-outline-success filter-chip <?= $fit_filter ? 'active' : ''; ?>">
                <i class="mdi mdi-check-decagram mr-1"></i><?= $fit_filter ? 'Showing only what fits' : 'Fits my brief'; ?>
            </a>
        <?php endif; ?>
        <?php if (count($counties) > 1): ?>
        <form method="get" action="client_vendors.php" class="d-inline ml-2">
            <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
            <input type="hidden" name="user_id" value="<?= $user_id; ?>">
            <?php if ($category_filter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category_filter); ?>"><?php endif; ?>
            <?php if ($fit_filter): ?><input type="hidden" name="fit" value="1"><?php endif; ?>
            <select name="county" class="btn btn-sm btn-outline-secondary filter-chip" onchange="this.form.submit()">
                <option value="">All counties</option>
                <?php foreach ($counties as $c): ?>
                    <option value="<?= htmlspecialchars($c); ?>" <?= strcasecmp($county_filter, $c) === 0 ? 'selected' : ''; ?>><?= htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <!-- My enquiries: status of everything this couple has sent -->
    <?php if ($myEnquiries !== []): ?>
    <div class="card mb-4" style="border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08);">
        <div class="card-body p-3">
            <h6 class="mb-3" style="font-weight: 700;"><i class="mdi mdi-message-text-clock mr-1" style="color:#065F46;"></i> My enquiries (<?= count($myEnquiries); ?>)</h6>
            <?php
            $enq_badges = [
                'pending'   => ['badge-warning', 'clock-outline', 'Waiting on the vendor — your planner is following up'],
                'confirmed' => ['badge-success', 'check-decagram', 'Booked! Deal confirmed by your planner'],
                'completed' => ['badge-primary', 'certificate', 'Services delivered'],
                'cancelled' => ['badge-secondary', 'close-circle', 'Cancelled — talk to your planner about alternatives'],
            ];
            ?>
            <?php foreach ($myEnquiries as $enq):
                $b = $enq_badges[$enq->status] ?? ['badge-secondary', 'help-circle', ucfirst((string) $enq->status)];
                $deal_thread = $threads_by_deal[(int) $enq->id] ?? [];
                $deal_unread = $unread_by_deal[(int) $enq->id] ?? 0;
                $stage = VendorThread::stage($enq, $deal_thread, VendorThread::COUPLE);
                $is_open = $thread_to_open === (int) $enq->id;
                $last_msg = $deal_thread !== [] ? $deal_thread[count($deal_thread) - 1] : null;
                $last_text = $last_msg !== null ? trim(preg_replace('/\s+/', ' ', (string) $last_msg->body) ?? '') : '';
            ?>
                <div class="py-3" id="deal-<?= (int) $enq->id; ?>" style="border-bottom: 1px dashed #E2E8F0;">
                    <div class="d-flex flex-wrap justify-content-between align-items-start">
                        <div class="mr-3">
                            <strong style="font-size: 13.5px;"><i class="mdi mdi-<?= vendor_category_icon((string) $enq->category); ?> mr-1"></i><?= htmlspecialchars((string) $enq->business_name); ?></strong>
                            <div class="text-muted" style="font-size: 12px;">
                                <?= $enq->package_title !== null ? htmlspecialchars((string) $enq->package_title) : 'General enquiry'; ?>
                                <?= !empty($enq->event_date) ? ' · ' . htmlspecialchars(format_date_kd((string) $enq->event_date)) : ''; ?>
                                <?php if ($enq->status === 'confirmed' && (float) $enq->amount > 0): ?>
                                    · <strong style="color:#065F46;"><?= format_ksh((float) $enq->amount); ?></strong>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <?php if ($deal_unread > 0): ?>
                                <span class="vt-unread-pill mr-2" title="New messages you have not read"><?= $deal_unread; ?> new</span>
                            <?php endif; ?>
                            <span class="badge <?= $b[0]; ?>" title="<?= htmlspecialchars($b[2]); ?>"><i class="mdi mdi-<?= $b[1]; ?> mr-1"></i><?= ucfirst((string) $enq->status); ?></span>
                        </div>
                    </div>

                    <?php if (!$is_open): ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-2">
                            <div style="min-width: 0;" class="mr-2">
                                <span class="vt-glance" style="color:<?= $stage['tone'] === 'action' ? '#065F46' : '#64748B'; ?>;">
                                    <i class="mdi mdi-<?= htmlspecialchars($stage['icon']); ?>" style="font-size:13px;"></i>
                                    <strong><?= htmlspecialchars($stage['label']); ?></strong>
                                </span>
                                <?php if ($last_msg !== null): ?>
                                    <div class="text-muted text-truncate" style="font-size:11.5px; max-width:560px;">
                                        <strong><?= htmlspecialchars((string) $last_msg->author_name); ?>:</strong>
                                        <?= htmlspecialchars(mb_strlen($last_text) > 88 ? mb_substr($last_text, 0, 87) . '…' : $last_text); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <a class="btn btn-sm btn-outline-primary mt-1" href="<?= htmlspecialchars(VendorThread::opener_url((int) $enq->id)); ?>">
                                <i class="mdi mdi-message-text-outline mr-1"></i><?= $deal_thread === [] ? 'Write to ' . htmlspecialchars((string) $enq->business_name) : 'Open conversation'; ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <?php $vt = ['role' => VendorThread::COUPLE, 'deal' => $enq, 'messages' => $deal_thread]; ?>
                        <?php include 'include/vendor_thread_panel.php'; ?>
                        <div class="text-right mt-1">
                            <a href="<?= htmlspecialchars(VendorThread::landing($enq, VendorThread::COUPLE)); ?>" class="text-muted" style="font-size:11.5px;">
                                <i class="mdi mdi-chevron-up"></i> Close conversation
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if ($enq->status === 'completed'): ?>
                        <div class="w-100 mt-2">
                            <?php if ($enq->rating === null): ?>
                                <form method="post" action="client_vendor_rate.php?<?= $links; ?>" class="form-inline">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="enquiry_id" value="<?= (int) $enq->id; ?>">
                                    <label class="mr-2 mb-0" style="font-size: 12px;">Rate this vendor</label>
                                    <select name="rating" class="form-control form-control-sm mr-2" style="width: auto;">
                                        <?php for ($s = 5; $s >= 1; $s--): ?>
                                            <option value="<?= $s; ?>"><?= str_repeat('★', $s) ?> (<?= $s; ?>/5)</option>
                                        <?php endfor; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="mdi mdi-star"></i> Submit rating</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size: 12px; color: #D97706;"
                                      title="You rated this vendor <?= (int) $enq->rating; ?> out of 5">
                                    <?php for ($s = 1; $s <= 5; $s++): ?><i class="mdi mdi-star<?= $s <= (int) $enq->rating ? '' : '-outline'; ?>"></i><?php endfor; ?>
                                    <span class="text-muted">You rated <?= (int) $enq->rating; ?>/5</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Vendors Grid -->
    <div class="row">
        <?php if (empty($vendors)): ?>
            <div class="col-12">
                <div class="alert alert-light border text-center" style="border-radius: 14px;">
                    <i class="mdi mdi-store-outline" style="font-size: 34px; color: #94A3B8;"></i>
                    <h6 class="mt-2"><?= $fit_filter ? 'Nothing fits that brief yet' : 'No vendors here yet'; ?></h6>
                    <p class="text-muted mb-0" style="font-size: 13px;">
                        <?php if ($fit_filter): ?>
                            No vendor here has a service inside the cap and taste you set for this category.
                            <a href="client_vendor_preferences.php?<?= $links; ?>">Widen the brief</a>, or
                            <a href="client_vendors.php?<?= $links; ?><?= $category_filter !== '' ? '&category=' . urlencode($category_filter) : ''; ?>">see everything</a>
                            and ask a vendor for a quote.
                        <?php else: ?>
                            <?= $county_filter !== '' ? 'Nothing in ' . htmlspecialchars($county_filter) . ' for this category — try another county or category.' : 'Vendors in this category will appear here once they are approved.'; ?>
                        <?php endif; ?>
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
                                <?php $vfit = $vendor_fits[(int) $vendor->id] ?? null; ?>
                                <?php if ($vfit !== null && $vfit['state'] !== 'unset' && $vfit['label'] !== ''): ?>
                                    <span class="badge badge-<?= htmlspecialchars($vfit['tone']); ?>"
                                          title="<?= htmlspecialchars(implode(' ', $vfit['reasons'])); ?>">
                                        <i class="mdi mdi-<?= htmlspecialchars($vfit['icon']); ?> mr-1"></i><?= htmlspecialchars($vfit['label']); ?>
                                    </span>
                                <?php endif; ?>
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
                                        <?php $pkg_tiers = $pkg_tier_map[(int) $p->id] ?? []; ?>
                                        <?php if ($pkg_tiers !== []): ?>
                                            <div class="text-muted" style="font-size:10.5px; margin-top:-1px;"><?= htmlspecialchars(VendorTier::line($pkg_tiers)); ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (count($pkgs) > 3): ?>
                                        <div class="text-muted" style="font-size: 11px;">+ <?= count($pkgs) - 3; ?> more package<?= count($pkgs) - 3 === 1 ? '' : 's'; ?> on enquiry</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php $svcs = $services_by_vendor[(int) $vendor->id] ?? []; ?>
                            <?php if ($svcs !== []): ?>
                                <div class="mt-2" style="border-top:1px solid #F1F5F9; padding-top:6px;">
                                    <?php foreach (array_slice($svcs, 0, 3) as $svc): ?>
                                        <?php
                                        // Judge each service against the brief for ITS category
                                        // (a caterer's transport add-on uses the transport budget).
                                        $svc_cat  = (string) ($svc->catalog_category ?? '');
                                        $svc_pref = $prefs[$svc_cat !== '' ? $svc_cat : (string) $vendor->category] ?? null;
                                        $svc_fit  = VendorFit::assess($svc, $svc_pref, $guests);
                                        ?>
                                        <div class="d-flex justify-content-between align-items-center" style="font-size:12px; color:#475569;">
                                            <span class="text-truncate mr-2" title="<?= htmlspecialchars((string) $svc->title); ?>">
                                                <?= htmlspecialchars((string) $svc->title); ?>
                                                <?php if ($svc_fit['state'] !== 'unset' && $svc_fit['label'] !== ''): ?>
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
                                        <div class="text-muted" style="font-size:11px;">+ <?= count($svcs) - 3; ?> more service<?= count($svcs) - 3 === 1 ? '' : 's'; ?></div>
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
<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
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
