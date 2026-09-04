<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); }

    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $account_details = Account_Details::find_by_user_id($user_id);
    $booking_details = Booking::find_by_booking_id($booking_id);
    $category_details = Category::find_by_id($booking_details->wedding_type ?? 0);
    $totals = Liquidation::getTotalsByMethod($booking_id);
    $budget_items = Liquidation::find_by_liquadate_all($booking_id);
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Budget - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        table.table.table-striped.table-bordered.table-sm { font-size:12px; }
        .stat-card { background: #fff; border-left: 4px solid #065F46; padding: 12px 15px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #888; margin: 0; }
        .stat-card .value { font-size: 22px; font-weight: 700; color: #333; }
        .mpesa-green { color: #059669; }
        .cash-blue { color: #0EA5A0; }
        .credit-orange { color: #D97706; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h4 class="h4 mt-4 ml-3"><i class="mdi mdi-cash-multiple"></i> Budget & Payments</h4>
    <div class="btn-toolbar mb-md-0">
        <div class="btn-group mr-2">
            <a class="btn btn-sm btn-primary" href="client_manage_account_details.php?<?= $links; ?>"><i class="mdi mdi-buffer mr-1"></i> Overview</a>
            <a class="btn btn-sm btn-info" href="guest_list.php?<?= $links; ?>"><i class="mdi mdi-account-multiple mr-1"></i> Guests</a>
            <a class="btn btn-sm btn-success active" href="budget.php?<?= $links; ?>"><i class="mdi mdi-cash mr-1"></i> Budget</a>
            <a class="btn btn-sm btn-warning" href="task_calendar.php?<?= $links; ?>"><i class="mdi mdi-calendar-text mr-1"></i> Calendar</a>
        </div>
    </div>
</div>

<?php if ($session->message()): ?>
    <div class="ml-3 mr-3"><?= $session->message(); ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row ml-2 mr-2 mb-3">
    <div class="col-md-3">
        <div class="stat-card">
            <h6>Package Price</h6>
            <div class="value"><?= format_ksh($category_details->price ?? 0); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-left-color: #059669;">
            <h6>Total Paid</h6>
            <div class="value"><?= format_ksh($totals['grand_total'] ?? 0); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-left-color: #D97706;">
            <h6>Balance Due</h6>
            <div class="value"><?= format_ksh(($category_details->price ?? 0) - ($totals['grand_total'] ?? 0)); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-left-color: #0EA5A0;">
            <h6>Budget Items</h6>
            <div class="value"><?= count($budget_items); ?></div>
        </div>
    </div>
</div>

<!-- Payment Method Breakdown -->
<div class="row ml-2 mr-2 mb-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body p-3">
                <h6 class="card-title text-muted text-uppercase" style="font-size: 11px;">Payment Breakdown by Method</h6>
                <div class="row">
                    <div class="col-md-4 text-center">
                        <h5 class="mpesa-green"><i class="mdi mdi-cellphone"></i> M-Pesa</h5>
                        <strong><?= format_ksh($totals['mpesa_total'] ?? 0); ?></strong>
                    </div>
                    <div class="col-md-4 text-center">
                        <h5 class="cash-blue"><i class="mdi mdi-cash"></i> Cash</h5>
                        <strong><?= format_ksh($totals['cash_total'] ?? 0); ?></strong>
                    </div>
                    <div class="col-md-4 text-center">
                        <h5 class="credit-orange"><i class="mdi mdi-bank"></i> Bank Transfer</h5>
                        <strong><?= format_ksh($totals['credit_total'] ?? 0); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="text-right mr-3 mb-3">
    <a class="btn btn-sm btn-success" href="budget_add.php?<?= $links; ?>"><i class="mdi mdi-plus mr-1"></i> Record Payment</a>
</div>

<table id="budget_table" class="table table-striped table-hover table-bordered table-sm" cellspacing="0" width="100%" style="background: white;">
    <thead>
        <tr>
            <th>#</th>
            <th>Date</th>
            <th>Event</th>
            <th>Payment Method</th>
            <th>M-Pesa Code</th>
            <th class="text-right">Amount (KSh)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($budget_items)) : ?>
        <?php empty_state_row(6, 'No payments recorded yet', 'Record deposits, M-Pesa and bank payments to track this wedding’s budget.', 'mdi-cash-multiple', 'budget_add.php?' . $links, 'Record First Payment', 'mdi-plus'); ?>
    <?php else : ?>
    <?php $total = 0; foreach ($budget_items as $i => $item): 
        $total += $item->payment;
        $method_label = '';
        if (!empty($item->mpesa_code)) {
            $method_label = '<span class="badge badge-success"><i class="mdi mdi-cellphone"></i> M-Pesa</span>';
        } elseif ($item->cash > 0 && empty($item->mpesa_code)) {
            $method_label = '<span class="badge badge-primary"><i class="mdi mdi-cash"></i> Cash</span>';
        } elseif ($item->credit > 0) {
            $method_label = '<span class="badge badge-info"><i class="mdi mdi-bank"></i> Bank</span>';
        } else {
            $method_label = '<span class="badge badge-secondary">Other</span>';
        }
    ?>
        <tr>
            <td><?= $i + 1; ?></td>
            <td><?= htmlspecialchars($item->date_issue); ?></td>
            <td>Event #<?= $item->event_id; ?></td>
            <td><?= $method_label; ?></td>
            <td><?= htmlspecialchars($item->mpesa_code ?? ''); ?></td>
            <td class="text-right font-weight-bold"><?= format_ksh($item->payment, 2); ?></td>
        </tr>
    <?php endforeach; ?>
    <tfoot>
        <tr class="table-dark">
            <td colspan="5" class="text-right"><strong>TOTAL:</strong></td>
            <td class="text-right"><strong><?= format_ksh($total, 2); ?></strong></td>
        </tr>
    </tfoot>
    <?php endif; ?>
    </tbody>
</table>

</div></div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap4.min.js"></script>
<script>
    $(document).ready(function() {
        $('#budget_table').DataTable({ pageLength: 25, order: [[0, 'desc']] });
    });
</script>
</body>
</html>