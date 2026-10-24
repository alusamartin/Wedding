<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }

    /*
     * Staff see every booking; a couple sees only their own. A VENDOR is
     * neither — is_staff() is false for access_level 0, so they used to fall
     * straight through into the client branch and be served the entire
     * planner back office (sidebar and all). Send them to their own home.
     */
    if (is_vendor()) {
        redirect_to("vendor_home.php");
    }

    // Handle status filter
    $status_filter = isset($_GET['status']) ? clean($_GET['status'] ?? '') : 'all';
    $search = isset($_GET['search']) ? clean($_GET['search'] ?? '') : '';

    // Get bookings — staff see all; a client session is scoped to their own account
    $is_staff_view = is_staff();
    $current_user_id = (int) $_SESSION['id'];
    if ($is_staff_view) {
        $pending = Booking::getBooking();
        $confirmed = Booking::ConfirmedBooking();

        /*
         * Per-planner scoping, applied with the SAME predicate every page and
         * every endpoint uses — so a booking this planner cannot open is not
         * listed here either, and the counts below (pending, confirmed, guests,
         * budget) are the planner's own book of business. `Booking::getBooking()`
         * and `ConfirmedBooking()` stay unscoped on purpose: they are also the
         * model's record of the platform, and a model that silently filtered by
         * whoever happened to be signed in would be a trap for the scripts and
         * the tests that legitimately want every row.
         */
        if (planner_scope_planner_id() > 0) {
            $pending   = array_values(array_filter($pending, 'booking_visible_to_session'));
            $confirmed = array_values(array_filter($confirmed, 'booking_visible_to_session'));
        }
    } else {
        $pending = Booking::getBookingForUser($current_user_id);
        $confirmed = Booking::ConfirmedBookingForUser($current_user_id);
    }

    // Combine for "all" view
    $all_bookings = array_merge($pending, $confirmed);

    // Get statistics
    $total_pending = count($pending);
    $total_confirmed = count($confirmed);
    $total_all = count($all_bookings);

    // Calculate totals
    $total_guests = 0;
    $total_budget = 0;
    foreach ($confirmed as $b) {
        $total_guests += Guest::count_guest($b->booking_id);
        $total_budget += Liquidation::getTotalAmount($b->booking_id);
    }

    /*
     * THE VENDOR TILE (couple's own view only).
     *
     * "Who is doing our wedding, and what is left to spend on them?" is the
     * question a couple opens the dashboard with, and until now the answer was
     * two clicks away on the vendor plan. VendorPlan::build() is a VIEW over the
     * deals that already exist, so this tile can never disagree with that page or
     * with the vendor's ledger — and it writes nothing.
     *
     * The budget is the couple's own vendor budget: the caps they set per category
     * on Taste & Budget. Before they have set any, the package they bought is the
     * only figure that means "the budget"; with neither, the tile says so rather
     * than comparing money with nothing. Staff do not get this tile — their
     * dashboard lists every wedding, so one vendor figure would mix couples up.
     */
    $vendor_total  = 0.0;
    $vendor_caps   = 0.0;
    $vendor_deals  = 0;
    $vendor_link   = '';
    $vendor_tile   = false;
    $tile_class    = $is_staff_view ? 'col-md-3' : 'col-md-6 col-lg';
    if (!$is_staff_view) {
        foreach ($all_bookings as $plan_booking) {
            $plan_totals   = VendorPlan::build((int) $plan_booking->booking_id)['totals'];
            $vendor_total += (float) $plan_totals['total'];
            $vendor_caps  += (float) $plan_totals['caps'];
            $vendor_deals += (int) $plan_totals['deals'];
            if ($vendor_link === '') {
                $vendor_link = 'client_vendor_plan.php?booking_id=' . (int) $plan_booking->booking_id
                    . '&user_id=' . (int) $plan_booking->user_id;
            }
        }
        if ($vendor_caps <= 0.0) {
            $primary = $confirmed[0] ?? ($pending[0] ?? null);
            if ($primary !== null) {
                $primary_package = Category::find_by_id((int) ($primary->wedding_type ?? 0));
                $vendor_caps     = (float) ($primary_package->price ?? 0);
            }
        }
        $vendor_tile = true;
    }

    /*
     * THE PLANNING TILE (couple's own view only).
     *
     * "Am I on track?" is what the checklist answers one item at a time; until
     * now the answer was a click away. Checklist::progress() is the SAME
     * calculation the checklist page renders, so the bar here and the
     * percentage there cannot disagree — and it only READS, so opening the
     * dashboard can never seed, tick or reset anything.
     *
     * A couple with no checklist yet (a wedding that predates v2.30, or one
     * still pending) gets an invitation to start one rather than a 0% bar,
     * which would read like work they had already missed.
     */
    $plan_progress  = null;
    $checklist_link = '';
    if (!$is_staff_view) {
        $plan_booking = $confirmed[0] ?? ($pending[0] ?? ($all_bookings[0] ?? null));
        if ($plan_booking !== null) {
            $plan_progress = Checklist::progress(
                (int) $plan_booking->booking_id,
                (string) ($plan_booking->wedding_date ?? '')
            );
            $checklist_link = 'client_checklist.php?booking_id=' . (int) $plan_booking->booking_id
                . '&user_id=' . (int) $plan_booking->user_id;
        }
    }

    // Helper function for booking row output
    function getBookingRow($bookWedding, $is_confirmed = false) {
        $guest_count = Guest::count_guest($bookWedding->booking_id);
        $budget = Liquidation::getTotalAmount($bookWedding->booking_id);
        $category = Category::find_by_id($bookWedding->wedding_type);
        $package_name = $category ? $category->wedding_type : 'Not assigned';
        $package_price = $category ? $category->price : 0;

        $status_class = $is_confirmed ? 'status-confirmed' : 'status-pending';
        $status_label = $is_confirmed ? 'Confirmed' : 'Pending';

        // Payment progress at a glance — which couples are on pace, and which
        // need a nudge. Same 30-day vendor-settlement norm as the couple's
        // overview nudge: inside 30 days with a balance, the bar turns red.
        $paid_pct = $package_price > 0 ? (int) floor(min(100.0, $budget / $package_price * 100)) : 0;
        $balance  = max(0.0, $package_price - $budget);
        $pay_urgent = false;
        $pace_note  = '';
        if ($package_price > 0 && $balance > 0 && !empty($bookWedding->wedding_date)
            && ($wts = strtotime((string) $bookWedding->wedding_date)) !== false) {
            $ddays = (int) round((strtotime(date('Y-m-d', $wts)) - strtotime(date('Y-m-d'))) / 86400);
            if ($ddays > 60) {
                $months    = max(1, (int) ceil(($ddays - 30) / 30));
                $pace_note = 'pace ~' . format_ksh($balance / $months) . '/mo';
            } elseif ($ddays > 30) {
                $pace_note = 'settle by ' . date('j M', strtotime('+' . ($ddays - 30) . ' days'));
            } elseif ($ddays >= 0) {
                $pay_urgent = true;
                $pace_note  = $ddays === 0 ? 'due today' : 'due within ' . $ddays . 'd';
            } else {
                $pace_note = 'wedding passed';
            }
        }

        $html = '<tr>';
        $html .= '<td>
            <div class="d-flex align-items-center">
                <div class="avatar-sm text-white rounded-circle mr-2" style="background:#065F46">' . strtoupper(substr($bookWedding->firstname, 0, 1)) . strtoupper(substr($bookWedding->lastname, 0, 1)) . '</div>
                <div>
                    <strong>' . htmlspecialchars($bookWedding->firstname . ' ' . $bookWedding->lastname) . '</strong>
                    <br><small class="text-muted">' . htmlspecialchars($bookWedding->user_email) . '</small>
                </div>
            </div>
        </td>';
        // Couple names: fall back to the account holder, then a friendly
        // "to come" — never a bare TBD. A couple that has just signed up
        // (like the public hero form) has no bride/groom fields yet.
        $acct_name = trim(($bookWedding->firstname ?? '') . ' ' . ($bookWedding->lastname ?? ''));
        if ($bookWedding->bride && $bookWedding->groom) {
            $couple_html = '<strong>' . htmlspecialchars($bookWedding->bride) . '</strong>'
                . '<br><span class="text-muted">&</span>'
                . '<br><strong>' . htmlspecialchars($bookWedding->groom) . '</strong>';
        } elseif ($bookWedding->bride || $bookWedding->groom) {
            $couple_html = '<strong>' . htmlspecialchars($bookWedding->bride ?: $bookWedding->groom) . '</strong>'
                . '<br><span class="text-muted">Partner details to come</span>';
        } else {
            $couple_html = '<strong>' . htmlspecialchars($acct_name !== '' ? $acct_name : 'Couple') . '</strong>'
                . '<br><span class="text-muted">Wedding details to come</span>';
        }
        $html .= '<td>' . $couple_html . '</td>';
        $html .= '<td>
            <span class="badge badge-info">' . htmlspecialchars($package_name) . '</span>
            <br><small class="text-muted">' . format_ksh($package_price) . '</small>
        </td>';
        $html .= '<td>
            <i class="mdi mdi-phone text-success"></i> ' . htmlspecialchars($bookWedding->phone) . '
            <br><i class="mdi mdi-map-marker text-muted"></i> ' . htmlspecialchars($bookWedding->city ?: '—') . '
        </td>';
        $html .= '<td>
            <strong>' . ($bookWedding->wedding_date ? format_date_kd($bookWedding->wedding_date) : 'Date to be set') . '</strong>
            <br><small class="text-muted">' . ($bookWedding->wedding_date ? date('l', strtotime($bookWedding->wedding_date)) : 'Pick a date with your planner') . '</small>
        </td>';
        $html .= '<td>
            <div class="mb-1"><i class="mdi mdi-account-multiple text-primary"></i> ' . $guest_count . ' guests</div>';
        if ($package_price > 0) {
            $fill_cls = $balance <= 0 ? ' pay-fill--paid' : ($pay_urgent ? ' pay-fill--urgent' : '');
            $meta     = $balance <= 0
                ? '<span class="pay-full">Fully paid</span>'
                : $paid_pct . '% · ' . format_ksh($balance) . ' left' . ($pace_note !== '' ? ' · ' . htmlspecialchars($pace_note) : '');
            $html .= '<div class="pay-cell">
                <div class="pay-line"><i class="mdi mdi-cash text-success"></i> ' . format_ksh($budget) . ' <span class="text-muted">of ' . format_ksh($package_price) . '</span></div>
                <div class="pay-bar"><div class="pay-fill' . $fill_cls . '" style="width:' . $paid_pct . '%"></div></div>
                <div class="pay-meta' . ($pay_urgent && $balance > 0 ? ' pay-meta--urgent' : '') . '">' . $meta . '</div>
            </div>';
        } else {
            $html .= '<div><i class="mdi mdi-cash text-success"></i> ' . format_ksh($budget) . ' paid</div>';
        }
        $html .= '</td>';
        $html .= '<td><span class="status-badge ' . $status_class . '">' . $status_label . '</span></td>';
        $html .= '<td>
            <div class="btn-group btn-group-sm">';

        // Staff-only assignment tool
        if (is_staff()) {
            $html .= '<a href="client_assign.php?booking=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-primary" title="Edit Assignment"><i class="mdi mdi-pencil"></i></a>';
        }

        // Overview is available to the couple on their own booking (pending or confirmed)
        $html .= '<a href="client_manage_account_details.php?booking_id=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-info" title="View Overview"><i class="mdi mdi-eye"></i></a>';

        // Staff planning tools only appear on confirmed bookings, staff sessions only
        if (is_staff() && $is_confirmed) {
            $html .= '<a href="guest_list.php?booking_id=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-success" title="Guest List"><i class="mdi mdi-account-multiple"></i></a>';
            $html .= '<a href="budget.php?booking_id=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-warning" title="Budget"><i class="mdi mdi-cash"></i></a>';
            $html .= '<a href="task_calendar.php?booking_id=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-dark" title="Calendar"><i class="mdi mdi-calendar"></i></a>';
        }

        $html .= '</div>
        </td>';
        $html .= '</tr>';

        return $html;
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Bookings - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css?v=1.13.11" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 3%; background: #f8f9fa; }
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #065F46;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.info { border-left-color: var(--info); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card h6 {
            font-size: 11px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }
        .stat-card .trend {
            font-size: 11px;
            color: #059669;
            margin-top: 5px;
        }
        .filter-bar {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .filter-bar .btn {
            margin-right: 5px;
            font-size: 12px;
        }
        .filter-bar .btn.active {
            background: #065F46;
            border-color: #065F46;
            color: #fff;
        }
        .search-box {
            position: relative;
        }
        .search-box input {
            padding-left: 35px;
            font-size: 13px;
            border-radius: 20px;
        }
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }
        .booking-table {
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .booking-table table {
            font-size: 12px;
            margin: 0;
        }
        .booking-table thead th {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.5px;
            padding: 12px 8px;
        }
        .booking-table tbody tr:hover {
            background: #fff5f5;
        }
        .avatar-sm {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge {
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-confirmed {
            background: #d4edda;
            color: #155724;
        }
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        .pay-cell { min-width: 150px; }
        .pay-line { font-size: 12.5px; }
        .pay-bar { height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin: 4px 0 3px; }
        .pay-fill { height: 100%; background: #059669; border-radius: 999px; }
        .pay-fill--paid { background: #059669; }
        .pay-fill--urgent { background: #DC2626; }
        .pay-meta { font-size: 11px; color: #64748B; }
        .pay-meta--urgent { color: #B91C1C; font-weight: 600; }
        .pay-full { color: #059669; font-weight: 600; }
        .btn-group-sm .btn {
            padding: 4px 8px;
            font-size: 11px;
        }
        .section-header {
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            border-bottom: 1px solid #eee;
        }
        .section-header h5 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .section-header .badge {
            font-size: 11px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }
        .empty-state i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }
        .quick-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .quick-stat {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 12px;
        }
        .quick-stat strong {
            color: #065F46;
        }
        .table-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        @media (max-width: 768px) {
            .stat-card { margin-bottom: 10px; }
            .btn-group-sm .btn { padding: 3px 6px; }
            .booking-table { font-size: 11px; }
        }
    </style>
</head>
<body>

<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show alert-info">
            <?= $session->message(); ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 2px solid #E2E8F0; padding-bottom: 15px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-calendar-multiple-check" style="color:#065F46"></i> Wedding Bookings</h4>
            <p class="text-muted mb-0" style="font-size: 13px;"><?= $is_staff_view ? 'Manage all wedding bookings, track progress, and access planning tools' : 'Track your wedding booking and planning progress'; ?></p>
        </div>
        <div class="table-actions">
            <?php if ($is_staff_view) : ?>
                <a href="client_add.php" class="btn btn-success"><i class="mdi mdi-plus"></i> New Booking</a>
                <button class="btn btn-outline-secondary" onclick="window.print()"><i class="mdi mdi-printer"></i> Print</button>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-danger"><i class="mdi mdi-logout"></i> Sign Out</a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="<?= $tile_class; ?>">
            <div class="stat-card">
                <h6><i class="mdi mdi-clock-outline"></i> Pending</h6>
                <div class="value"><?= $total_pending; ?></div>
                <div class="trend"><?= $is_staff_view ? 'Awaiting assignment' : 'Awaiting confirmation'; ?></div>
            </div>
        </div>
        <div class="<?= $tile_class; ?>">
            <div class="stat-card success">
                <h6><i class="mdi mdi-check-circle"></i> Confirmed</h6>
                <div class="value"><?= $total_confirmed; ?></div>
                <div class="trend"><?= $is_staff_view ? 'Active weddings' : 'Your confirmed booking'; ?></div>
            </div>
        </div>
        <div class="<?= $tile_class; ?>">
            <div class="stat-card info">
                <h6><i class="mdi mdi-account-multiple"></i> <?= $is_staff_view ? 'Total Guests' : 'My Guests'; ?></h6>
                <div class="value"><?= number_format($total_guests); ?></div>
                <div class="trend"><?= $is_staff_view ? 'Across all weddings' : 'On your confirmed booking'; ?></div>
            </div>
        </div>
        <div class="<?= $tile_class; ?>">
            <div class="stat-card warning">
                <h6><i class="mdi mdi-cash-multiple"></i> <?= $is_staff_view ? 'Revenue Collected' : 'Amount Paid'; ?></h6>
                <div class="value"><?= format_ksh($total_budget); ?></div>
                <div class="trend"><?= $is_staff_view ? 'Total payments received' : 'Payments received on your booking'; ?></div>
            </div>
        </div>
        <?php if ($vendor_tile): ?>
            <?php
            // Over budget is the one figure here a couple must not have to work
            // out: it is stated in words, in red, and the tile opens the plan so
            // the category that caused it is one click away.
            $vendor_over = $vendor_caps > 0 && $vendor_total > $vendor_caps;
            ?>
            <div class="<?= $tile_class; ?>">
                <div class="stat-card" style="border-left-color:var(--info);">
                    <h6><i class="mdi mdi-store"></i> Vendor Costs</h6>
                    <div class="value"><?= format_ksh($vendor_total); ?></div>
                    <div class="trend" style="color:<?= $vendor_over ? '#B91C1C' : ($vendor_caps > 0 ? '#059669' : '#6B7280'); ?>;">
                        <?php if ($vendor_deals === 0 && $vendor_total <= 0): ?>
                            <?php /* Nothing chosen yet: a "left of" sentence here would read like progress that has not happened. */ ?>
                            No vendors planned yet
                        <?php elseif ($vendor_over): ?>
                            <i class="mdi mdi-alert"></i> <?= format_ksh($vendor_total - $vendor_caps); ?> over your budget
                        <?php elseif ($vendor_caps > 0): ?>
                            <?= format_ksh($vendor_caps - $vendor_total); ?> left of <?= format_ksh($vendor_caps); ?>
                        <?php else: ?>
                            No budget set yet
                        <?php endif; ?>
                    </div>
                    <?php if ($vendor_link !== ''): ?>
                        <a href="<?= htmlspecialchars($vendor_link); ?>" style="font-size:11px; font-weight:600; color:#0E7490;">
                            <?= $vendor_deals > 0 ? 'See who is booked' : 'Plan your vendors'; ?> <i class="mdi mdi-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Planning progress (couple's own view): the same numbers as the checklist page -->
    <?php if ($plan_progress !== null): ?>
    <div class="card mb-4" style="border-radius:12px; border:1px solid #E2E8F0; box-shadow:0 1px 3px rgba(0,0,0,.06);">
        <div class="card-body" style="padding:16px 20px;">
            <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:10px;">
                <div>
                    <h6 style="margin:0; font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#64748B;">
                        <i class="mdi mdi-checkbox-marked-outline" style="color:#065F46;"></i> Planning progress
                    </h6>
                    <div style="font-size:13px; color:#0F172A; margin-top:3px;">
                        <?php if (!$plan_progress['seeded']): ?>
                            Your checklist has not been started yet — the Kenyan milestone plan takes two minutes to open.
                        <?php else: ?>
                            <strong><?= (int) $plan_progress['done']; ?> of <?= (int) $plan_progress['total']; ?></strong> milestones done<?php if ((int) $plan_progress['overdue'] > 0): ?>
                                &middot; <span style="color:#B91C1C; font-weight:700;"><?= (int) $plan_progress['overdue']; ?> overdue</span><?php endif; ?>
                            <?php if ($plan_progress['next'] !== null): ?>
                                <?php $next_title = (string) $plan_progress['next']->title; ?>
                                &middot; up next: <?= htmlspecialchars(mb_substr($next_title, 0, 60)); ?><?= mb_strlen($next_title) > 60 ? '…' : ''; ?>
                            <?php else: ?>
                                &middot; everything ticked 🎉
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center" style="gap:12px;">
                    <strong style="font-size:20px; color:#065F46;"><?= (int) $plan_progress['pct']; ?>%</strong>
                    <a href="<?= htmlspecialchars($checklist_link); ?>" class="btn btn-sm btn-outline-success">
                        <?= $plan_progress['seeded'] ? 'Open checklist' : 'Start checklist'; ?> <i class="mdi mdi-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="pay-bar" style="height:10px; margin-top:10px;"><div class="pay-fill" style="width:<?= (int) $plan_progress['pct']; ?>%;"></div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="btn-group">
                    <a href="client.php?status=all" class="btn btn-outline-primary <?= $status_filter == 'all' ? 'active' : ''; ?>">
                        <i class="mdi mdi-view-list"></i> All <span class="badge badge-light"><?= $total_all; ?></span>
                    </a>
                    <a href="client.php?status=pending" class="btn btn-outline-warning <?= $status_filter == 'pending' ? 'active' : ''; ?>">
                        <i class="mdi mdi-clock-outline"></i> Pending <span class="badge badge-light"><?= $total_pending; ?></span>
                    </a>
                    <a href="client.php?status=confirmed" class="btn btn-outline-success <?= $status_filter == 'confirmed' ? 'active' : ''; ?>">
                        <i class="mdi mdi-check-circle"></i> Confirmed <span class="badge badge-light"><?= $total_confirmed; ?></span>
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="search-box">
                    <i class="mdi mdi-magnify"></i>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search by name, email, phone..." value="<?= htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-2 text-right">
                <div class="quick-stats">
                    <div class="quick-stat">
                        <i class="mdi mdi-calendar text-primary"></i>
                        <strong><?= date('M Y'); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- All Bookings Table -->
    <?php if ($status_filter == 'all' || $status_filter == ''): ?>
    <div class="booking-table mb-4">
        <div class="section-header d-flex justify-content-between align-items-center">
            <h5><i class="mdi mdi-view-list"></i> All Bookings</h5>
            <span class="badge badge-dark"><?= $total_all; ?> total</span>
        </div>
        <?php if (empty($all_bookings)): ?>
            <?php if ($is_staff_view): ?>
                <div class="empty-state">
                    <i class="mdi mdi-calendar-blank"></i>
                    <h5>No bookings found</h5>
                    <p>Start by adding a new wedding booking</p>
                    <a href="client_add.php" class="btn btn-success"><i class="mdi mdi-plus"></i> Add First Booking</a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="mdi mdi-calendar-heart"></i>
                    <h5>No bookings yet</h5>
                    <p>Your journey with Everafter Planner starts with a chat — reach out and we will plan your day together.</p>
                    <a href="../contact.php" class="btn btn-success"><i class="mdi mdi-email-outline mr-1"></i> Enquire Now</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
        <table id="all_bookings" class="table table-hover mb-0" style="width:100%">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Couple</th>
                    <th>Package</th>
                    <th>Contact</th>
                    <th>Wedding Date</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($pending as $b) { echo getBookingRow($b, false); }
                foreach ($confirmed as $b) { echo getBookingRow($b, true); }
                ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Pending Bookings Table -->
    <?php if ($status_filter == 'pending'): ?>
    <div class="booking-table mb-4">
        <div class="section-header d-flex justify-content-between align-items-center">
            <h5><i class="mdi mdi-clock-outline text-warning"></i> Pending Bookings</h5>
            <span class="badge badge-warning"><?= $total_pending; ?> pending</span>
        </div>
        <?php if (empty($pending)): ?>
            <div class="empty-state">
                <i class="mdi mdi-check-circle-outline"></i>
                <h5>All caught up!</h5>
                <p>No pending bookings to review</p>
            </div>
        <?php else: ?>
        <table id="pending_bookings" class="table table-hover mb-0" style="width:100%">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Couple</th>
                    <th>Package</th>
                    <th>Contact</th>
                    <th>Wedding Date</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $b) { echo getBookingRow($b, false); } ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Confirmed Bookings Table -->
    <?php if ($status_filter == 'confirmed'): ?>
    <div class="booking-table mb-4">
        <div class="section-header d-flex justify-content-between align-items-center">
            <h5><i class="mdi mdi-check-circle text-success"></i> Confirmed Bookings</h5>
            <span class="badge badge-success"><?= $total_confirmed; ?> confirmed</span>
        </div>
        <?php if (empty($confirmed)): ?>
            <div class="empty-state">
                <i class="mdi mdi-calendar-remove"></i>
                <h5>No confirmed bookings</h5>
                <p>Assign pending bookings to confirm them</p>
            </div>
        <?php else: ?>
        <table id="confirmed_bookings" class="table table-hover mb-0" style="width:100%">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Couple</th>
                    <th>Package</th>
                    <th>Contact</th>
                    <th>Wedding Date</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($confirmed as $b) { echo getBookingRow($b, true); } ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Upcoming Weddings Sidebar (staff workspace) -->
    <?php if ($is_staff_view && !empty($confirmed)): ?>
    <div class="row">
        <div class="col-md-8">
            <div class="booking-table">
                <div class="section-header">
                    <h5><i class="mdi mdi-calendar-clock text-primary"></i> Upcoming Weddings (Next 30 Days)</h5>
                </div>
                <div class="p-3">
                    <?php
                    $upcoming = array_filter($confirmed, function($b) {
                        if (empty($b->wedding_date)) return false;
                        $days = (strtotime($b->wedding_date) - strtotime(date('Y-m-d'))) / 86400;
                        return $days >= 0 && $days <= 30;
                    });
                    if (empty($upcoming)):
                    ?>
                        <p class="text-muted text-center py-3">No weddings in the next 30 days</p>
                    <?php else: ?>
                        <div class="row">
                        <?php foreach (array_slice($upcoming, 0, 4) as $w): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card border-primary">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h6 class="mb-1"><?= htmlspecialchars($w->bride . ' & ' . $w->groom); ?></h6>
                                                <small class="text-muted"><i class="mdi mdi-calendar"></i> <?= format_date_kd($w->wedding_date); ?></small>
                                            </div>
                                            <span class="badge badge-primary"><?= Guest::count_guest($w->booking_id); ?> guests</span>
                                        </div>
                                        <div class="mt-2">
                                            <a href="client_manage_account_details.php?booking_id=<?= $w->booking_id; ?>&user_id=<?= $w->user_id; ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="booking-table">
                <div class="section-header">
                    <h5><i class="mdi mdi-chart-line text-success"></i> Quick Stats</h5>
                </div>
                <div class="p-3">
                    <div class="mb-3">
                        <small class="text-muted">Average Guests per Wedding</small>
                        <h4><?= $total_confirmed > 0 ? round($total_guests / $total_confirmed) : 0; ?></h4>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted">Average Budget Collected</small>
                        <h4><?= $total_confirmed > 0 ? format_ksh($total_budget / $total_confirmed) : 'KSh 0'; ?></h4>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted">This Month's Bookings</small>
                        <h4>
                            <?php
                            $this_month = array_filter($confirmed, function($b) {
                                return !empty($b->wedding_date) && date('Y-m', strtotime($b->wedding_date)) == date('Y-m');
                            });
                            echo count($this_month);
                            ?>
                        </h4>
                    </div>
                    <div>
                        <small class="text-muted">Conversion Rate</small>
                        <h4><?= $total_all > 0 ? round(($total_confirmed / $total_all) * 100) : 0; ?>%</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

</main>
</div>
</div>

<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
<script src="js/jquery.dataTables.min.js?v=1.13.11"></script>
<script src="js/dataTables.bootstrap4.min.js?v=1.13.11"></script>
<script>
    $(document).ready(function() {
        // Initialize DataTables
        $('.booking-table table').DataTable({
            pageLength: 10,
            order: [[4, 'asc']], // Sort by wedding date
            columnDefs: [
                { orderable: false, targets: [6, 7] } // Disable sort on status and actions
            ],
            language: {
                search: "",
                searchPlaceholder: "Search bookings..."
            }
        });

        // Search functionality
        $('#searchInput').on('keyup', function() {
            $('.booking-table table').DataTable().search(this.value).draw();
        });

        // Auto-focus search
        $('#searchInput').focus();

        // Tooltips
        $('[data-toggle="tooltip"]').tooltip();

        // Animate stats on load
        $('.stat-card .value').each(function() {
            var $this = $(this);
            var value = parseInt($this.text().replace(/,/g, ''));
            if (!isNaN(value)) {
                $({ Counter: 0 }).animate({ Counter: value }, {
                    duration: 1000,
                    easing: 'swing',
                    step: function() {
                        $this.text(Math.ceil(this.Counter).toLocaleString());
                    }
                });
            }
        });
    });
</script>
</body>
</html>