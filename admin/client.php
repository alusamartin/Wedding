<?php include 'include/init.php'; ?>
<?php
    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }

    // Handle status filter
    $status_filter = isset($_GET['status']) ? clean($_GET['status']) : 'all';
    $search = isset($_GET['search']) ? clean($_GET['search']) : '';

    // Get bookings — staff see all; a client session is scoped to their own account
    $is_staff_view = is_staff();
    $current_user_id = (int) $_SESSION['id'];
    if ($is_staff_view) {
        $pending = Booking::getBooking();
        $confirmed = Booking::ConfirmedBooking();
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

    // Helper function for booking row output
    function getBookingRow($bookWedding, $is_confirmed = false) {
        $guest_count = Guest::count_guest($bookWedding->booking_id);
        $budget = Liquidation::getTotalAmount($bookWedding->booking_id);
        $category = Category::find_by_id($bookWedding->wedding_type);
        $package_name = $category ? $category->wedding_type : 'Not assigned';
        $package_price = $category ? $category->price : 0;

        $status_class = $is_confirmed ? 'status-confirmed' : 'status-pending';
        $status_label = $is_confirmed ? 'Confirmed' : 'Pending';

        $html = '<tr>';
        $html .= '<td>
            <div class="d-flex align-items-center">
                <div class="avatar-sm bg-danger text-white rounded-circle mr-2">' . strtoupper(substr($bookWedding->firstname, 0, 1)) . strtoupper(substr($bookWedding->lastname, 0, 1)) . '</div>
                <div>
                    <strong>' . htmlspecialchars($bookWedding->firstname . ' ' . $bookWedding->lastname) . '</strong>
                    <br><small class="text-muted">' . htmlspecialchars($bookWedding->user_email) . '</small>
                </div>
            </div>
        </td>';
        $html .= '<td>
            <strong class="text-danger">' . htmlspecialchars($bookWedding->bride ?: 'TBD') . '</strong>
            <br><span class="text-muted">&</span>
            <br><strong class="text-danger">' . htmlspecialchars($bookWedding->groom ?: 'TBD') . '</strong>
        </td>';
        $html .= '<td>
            <span class="badge badge-info">' . htmlspecialchars($package_name) . '</span>
            <br><small class="text-muted">' . format_ksh($package_price) . '</small>
        </td>';
        $html .= '<td>
            <i class="mdi mdi-phone text-success"></i> ' . htmlspecialchars($bookWedding->phone) . '
            <br><i class="mdi mdi-map-marker text-danger"></i> ' . htmlspecialchars($bookWedding->city ?: 'TBD') . '
        </td>';
        $html .= '<td>
            <strong>' . htmlspecialchars($bookWedding->wedding_date ?: 'TBD') . '</strong>
            <br><small class="text-muted">' . ($bookWedding->wedding_date ? date('l', strtotime($bookWedding->wedding_date)) : '') . '</small>
        </td>';
        $html .= '<td>
            <div class="mb-1"><i class="mdi mdi-account-multiple text-primary"></i> ' . $guest_count . ' guests</div>
            <div><i class="mdi mdi-cash text-success"></i> ' . format_ksh($budget) . ' paid</div>
        </td>';
        $html .= '<td><span class="status-badge ' . $status_class . '">' . $status_label . '</span></td>';
        $html .= '<td>
            <div class="btn-group btn-group-sm">
                <a href="client_assign.php?booking=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-primary" title="Edit Assignment"><i class="mdi mdi-pencil"></i></a>';

        if ($is_confirmed) {
            $html .= '<a href="client_manage_account_details.php?booking_id=' . $bookWedding->booking_id . '&user_id=' . $bookWedding->user_id . '" class="btn btn-outline-info" title="Overview"><i class="mdi mdi-eye"></i></a>';
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
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css" rel="stylesheet">
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
            border-left: 4px solid #c0392b;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card.success { border-left-color: #27ae60; }
        .stat-card.info { border-left-color: #3498db; }
        .stat-card.warning { border-left-color: #f39c12; }
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
            color: #27ae60;
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
            background: #c0392b;
            border-color: #c0392b;
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
            color: #c0392b;
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
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 2px solid #c0392b; padding-bottom: 15px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-calendar-multiple-check text-danger"></i> Wedding Bookings</h4>
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
        <div class="col-md-3">
            <div class="stat-card">
                <h6><i class="mdi mdi-clock-outline"></i> Pending</h6>
                <div class="value"><?= $total_pending; ?></div>
                <div class="trend">Awaiting assignment</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card success">
                <h6><i class="mdi mdi-check-circle"></i> Confirmed</h6>
                <div class="value"><?= $total_confirmed; ?></div>
                <div class="trend">Active weddings</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info">
                <h6><i class="mdi mdi-account-multiple"></i> Total Guests</h6>
                <div class="value"><?= number_format($total_guests); ?></div>
                <div class="trend">Across all weddings</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning">
                <h6><i class="mdi mdi-cash-multiple"></i> Revenue Collected</h6>
                <div class="value"><?= format_ksh($total_budget); ?></div>
                <div class="trend">Total payments received</div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="btn-group">
                    <a href="client.php?status=all" class="btn btn-outline-danger <?= $status_filter == 'all' ? 'active' : ''; ?>">
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
            <span class="badge badge-danger"><?= $total_all; ?> total</span>
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

    <!-- Upcoming Weddings Sidebar -->
    <?php if (!empty($confirmed)): ?>
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
                                                <small class="text-muted"><i class="mdi mdi-calendar"></i> <?= htmlspecialchars($w->wedding_date); ?></small>
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

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap4.min.js"></script>
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