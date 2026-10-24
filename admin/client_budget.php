<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }
    $booking = Booking::find_by_booking_id($booking_id);
    // Couples see only their own wedding's payments; staff may preview any.
    if (!may_open_couple_page($booking, $user_id)) {
        redirect_to("client.php");
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

    // ---- Budget-vs-actual: harambee pledges against the package balance ----
    // The Kenyan reality: families rarely pay the package out of pocket — the
    // committee collects harambee from guests. Show both sides of that plan:
    // what the wedding costs, what has been paid, what the committee has
    // pledged, and what is realistically still to be raised.
    $harambee_pledged = Guest::total_harambee($booking_id);
    $harambee_backed  = max(0.0, $package_price - $amount_paid - $harambee_pledged); // still to raise after pledges
    $over_collected   = max(0.0, $amount_paid + $harambee_pledged - $package_price); // pledges + payments exceed the package
    $planners_note    = $package_price > 0 && $amount_paid + $harambee_pledged >= $package_price;
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= is_staff() ? 'Budget & Payments - Everafter Planner' : 'My Payments - Everafter Planner'; ?></title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
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
        .budget-panel { background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 20px 22px; margin-bottom: 18px; }
        .budget-panel h6 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #065F46; font-weight: 700; }
        .fund-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #F1F5F9; font-size: 13.5px; }
        .fund-row:last-child { border-bottom: 0; }
        .fund-row .label { color: #334155; }
        .fund-row .amount { font-weight: 700; color: #0F172A; }
        .fund-row.total { border-top: 2px solid #E2E8F0; margin-top: 4px; padding-top: 12px; }
        .fund-row.total .amount { font-size: 16px; }
        .fund-bar { background: #E2E8F0; border-radius: 999px; height: 12px; overflow: hidden; margin: 10px 0 4px; display: flex; }
        .fund-bar .paid { background: linear-gradient(90deg, #065F46, #059669); }
        .fund-bar .pledged { background: #F59E0B; }
        .fund-legend { font-size: 11.5px; color: #64748B; }
        .fund-legend .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin: 0 4px 0 10px; }
        .harambee-note { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 10px 14px; font-size: 12.5px; color: #92400E; margin-top: 12px; }
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
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--success)"><h6>Paid To Date</h6><div class="value"><?= format_ksh($amount_paid, 2); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--info)"><h6>Via M-Pesa</h6><div class="value"><?= format_ksh($mpesa_total, 2); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--warning)"><h6>Balance Due</h6><div class="value due"><?= format_ksh($balance_due, 2); ?></div></div></div>
    </div>

    <?php if ($package_price > 0): ?>
    <div class="budget-panel">
        <h6><i class="mdi mdi-chart-donut mr-1"></i>Budget vs Actual — how your wedding is funded</h6>
        <?php
            $paid_pct    = $package_price > 0 ? min(100, $amount_paid / $package_price * 100) : 0;
            $pledged_pct = $package_price > 0 ? min(100 - $paid_pct, $harambee_pledged / $package_price * 100) : 0;
        ?>
        <div class="fund-bar">
            <div class="paid" style="width: <?= round($paid_pct, 1); ?>%;"></div>
            <div class="pledged" style="width: <?= round($pledged_pct, 1); ?>%;"></div>
        </div>
        <div class="fund-legend">
            <span class="dot" style="background:#059669;"></span>Paid to date
            <span class="dot" style="background:#F59E0B;"></span>Harambee pledged
            <span class="dot" style="background:#E2E8F0;"></span>Still to raise
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="fund-row"><span class="label">Package price</span><span class="amount"><?= format_ksh($package_price, 2); ?></span></div>
                <div class="fund-row"><span class="label">Paid to date (M-Pesa, cash, bank)</span><span class="amount" style="color:#065F46;"><?= format_ksh($amount_paid, 2); ?></span></div>
                <div class="fund-row"><span class="label">Balance due</span><span class="amount" style="color:#B45309;"><?= format_ksh($balance_due, 2); ?></span></div>
            </div>
            <div class="col-md-6">
                <div class="fund-row"><span class="label">Harambee pledged by guests (<?= $harambee_pledged > 0 ? (int) Guest::count_pledged($booking_id) : 0; ?> guests)</span><span class="amount" style="color:#B45309;"><?= format_ksh($harambee_pledged, 2); ?></span></div>
                <div class="fund-row"><span class="label">Still to raise after pledges</span><span class="amount"><?= format_ksh($harambee_backed, 2); ?></span></div>
                <?php if ($over_collected > 0): ?>
                <div class="fund-row total"><span class="label">Pledges + payments exceed the package by</span><span class="amount" style="color:#065F46;"><?= format_ksh($over_collected, 2); ?></span></div>
                <?php else: ?>
                <div class="fund-row total"><span class="label">Projected total once pledges land</span><span class="amount"><?= format_ksh($amount_paid + $harambee_pledged, 2); ?></span></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($planners_note): ?>
            <div class="harambee-note"><i class="mdi mdi-hand-heart mr-1"></i>Great news — payments plus harambee pledges already cover your package. Your planner will guide you on any extras.</div>
        <?php elseif ($harambee_backed > 0 && $harambee_pledged > 0): ?>
            <div class="harambee-note"><i class="mdi mdi-hand-heart mr-1"></i>Your committee has pledged <?= format_ksh($harambee_pledged); ?> of the <?= format_ksh($balance_due, 2); ?> balance — about <?= round($balance_due > 0 ? $harambee_pledged / $balance_due * 100 : 0); ?>% of what remains. Encourage the committee to collect early; M-Pesa makes it easy.</div>
        <?php elseif ($balance_due > 0): ?>
            <div class="harambee-note"><i class="mdi mdi-hand-heart mr-1"></i>No harambee pledges recorded yet — add them on <a href="client_guests.php?<?= $links; ?>">My Guests</a> as your committee confirms contributions.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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

<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
