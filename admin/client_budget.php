<?php include 'include/init.php'; ?>
<?php
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }
    $booking = Booking::find_by_booking_id($booking_id);
    if (!is_staff()) {
        // Clients see only their own wedding's payments (staff may preview any).
        if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
            redirect_to("client.php");
        }
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $category_details = Category::find_by_id($booking->wedding_type ?? 0);
    $package_price = (float) ($category_details->price ?? 0);
    $totals = Liquidation::getTotalsByMethod($booking_id);
    $amount_paid = (float) ($totals['grand_total'] ?? 0);
    $mpesa_total = (float) ($totals['mpesa_total'] ?? 0);
    $balance_due = max(0.0, $package_price - $amount_paid);
    $budget_items = Liquidation::find_by_liquadate_all($booking_id);

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= is_staff() ? 'Budget & Payments - Everafter Planner' : 'My Payments - Everafter Planner'; ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #065F46; }
        .btn-wa { background: #25D366; color: #fff; border-radius: 20px; font-size: 11px; padding: 2px 9px; }
        .btn-wa:hover { background: #1DA851; color: #fff; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .stat-card .value.due { color: #B45309; }
        .readonly-note { font-size: 12px; color: #64748B; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-cash-multiple" style="color:#065F46"></i> My Payments</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '&' ? htmlspecialchars($couple) . ' — ' : ''; ?>every payment made toward your day
            </p>
        </div>
        <?php if (is_staff()): ?>
            <div class="readonly-note"><i class="mdi mdi-eye-outline mr-1"></i>Read-only preview — planner tools live on Budget</div>
        <?php else: ?>
            <a href="client_deposit.php?<?= $links; ?>" class="btn btn-success btn-sm">
                <i class="mdi mdi-cellphone-arrow-down mr-1"></i> Deposit via M-Pesa
            </a>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Package Price</h6><div class="value"><?= format_ksh($package_price); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#059669"><h6>Paid To Date</h6><div class="value"><?= format_ksh($amount_paid, 2); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#0EA5A0"><h6>Via M-Pesa</h6><div class="value"><?= format_ksh($mpesa_total, 2); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#D97706"><h6>Balance Due</h6><div class="value due"><?= format_ksh($balance_due, 2); ?></div></div></div>
    </div>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <table class="table table-sm table-hover" style="margin-bottom:0;">
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
                    <?php empty_state_row(6, 'No payments recorded yet', 'Your planner will log deposits here as they arrive — M-Pesa, cash or bank.', 'mdi-cash-multiple', '../contact.php', 'Ask Your Planner', 'mdi-email-outline'); ?>
                <?php else : ?>
                <?php $total = 0; foreach ($budget_items as $i => $item):
                    $total += (float) $item->payment;
                    if (!empty($item->mpesa_code)) {
                        $method_label = '<span class="badge badge-success"><i class="mdi mdi-cellphone"></i> M-Pesa</span>';
                    } elseif ($item->cash > 0 && empty($item->mpesa_code)) {
                        $method_label = '<span class="badge badge-primary"><i class="mdi mdi-cash"></i> Cash</span>';
                    } elseif ($item->credit > 0) {
                        $method_label = '<span class="badge badge-info"><i class="mdi mdi-bank"></i> Bank</span>';
                    } else {
                        $method_label = '<span class="badge badge-secondary">Other</span>';
                    }
                    // Receipt share text (WhatsApp chooser: the couple picks the chat).
                    $receipt_text = 'Wedding payment receipt — ' . event_label($item->event_id) . "\n"
                        . 'Paid: ' . format_ksh($item->payment, 2)
                        . (!empty($item->mpesa_code) ? "\nM-Pesa code: " . $item->mpesa_code : '')
                        . "\nDate: " . format_date_kd($item->date_issue)
                        . "\n— Everafter Planner";
                ?>
                    <tr>
                        <td><?= $i + 1; ?></td>
                        <td><?= format_date_kd($item->date_issue, true); ?></td>
                        <td><?= htmlspecialchars(event_label($item->event_id)); ?></td>
                        <td><?= $method_label; ?>
                            <a href="receipt.php?id=<?= (int) $item->id; ?>" class="btn btn-sm btn-outline-success ml-1" title="View branded receipt">
                                <i class="mdi mdi-receipt"></i></a>
                            <a href="<?= htmlspecialchars(whatsapp_share_link($receipt_text)); ?>" target="_blank" rel="noopener"
                               class="btn btn-sm btn-wa ml-1" title="Share this receipt on WhatsApp"><i class="mdi mdi-share-variant"></i></a>
                        </td>
                        <td><?= htmlspecialchars($item->mpesa_code ?? ''); ?></td>
                        <td class="text-right font-weight-bold"><?= format_ksh($item->payment, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-right"><strong>TOTAL PAID:</strong></td>
                        <td class="text-right"><strong><?= format_ksh($total, 2); ?></strong></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
