<?php include 'include/init.php'; ?>
<?php
/**
 * Admin revenue dashboard — platform money at a glance.
 *
 * Aggregates the three revenue streams recorded by the app:
 *   • Subscriptions  — planner plan payments (tblsubscriptions)
 *   • Commissions    — confirmed vendor deals (tblvendor_bookings)
 *   • SMS spend      — successful sends (tblsms_logs)
 *
 * All KSh, month by month, with lifetime totals and per-stream drill-downs.
 * Staff-only (planners see the platform's money too; the roster is small).
 */
    if (!is_staff()) {
        redirect_to("../");
        die();
    }

    global $db;

    $lifetime = Revenue::lifetime_totals();
    $months   = Revenue::monthly_totals();            // continuous 12-month axis
    $thisM    = Revenue::month_summary(0);            // this month
    $lastM    = Revenue::month_summary(1);            // last month
    $rows     = array_reverse($months, true);         // newest last for display

    $drill  = clean($_GET['stream'] ?? '');
    $streams = ['subscriptions' => 'Subscription payments', 'commissions' => 'Vendor commissions', 'sms' => 'SMS spend'];

    // ---- CSV export (staff-only, same gate as the page) -------------------
    // ?stream=<x>&export=1 streams a spreadsheet-friendly file instead of
    // HTML. Cells are CSV-injection-sanitized and output is UTF-8 BOM'd so
    // Excel opens Kenyan names / KSh symbols without mojibake.
    if (isset($_GET['export'], $_GET['stream']) && isset($streams[clean($_GET['stream'])])) {
        $export_stream = clean($_GET['stream']);
        $export_rows = Revenue::stream_rows($export_stream, 500); // full cap for exports
        // Discard anything init.php buffered (its ob_start catches the stray
        // newline after the first PHP close tag) so the file starts with the
        // BOM, not whitespace that corrupts strict CSV parsers.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="everafter-' . $export_stream . '-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $out = fopen('php://output', 'w');
        $emit = function (array $cells) use ($out): void {
            // Neutralize =/+/-/@ prefixes so Excel/Sheets don't evaluate cells as formulas.
            fputcsv($out, array_map('csv_cell', $cells));
        };
        if ($export_stream === 'subscriptions') {
            $emit(['Planner', 'Email', 'Plan', 'Amount (KSh)', 'M-Pesa code', 'Start', 'Expiry', 'Status']);
            foreach ($export_rows as $r) {
                $emit([trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')), $r->email ?? '', $r->plan, $r->amount, $r->mpesa_code, $r->start_date, $r->expiry_date, $r->status]);
            }
        } elseif ($export_stream === 'commissions') {
            $emit(['Vendor', 'Couple', 'Deal (KSh)', 'Commission (KSh)', 'Event date', 'Status']);
            foreach ($export_rows as $r) {
                $emit([$r->business_name ?? '', trim(($r->bride ?? '') . ' & ' . ($r->groom ?? '')), $r->deal_amount, $r->commission, $r->event_date ?? '', $r->status]);
            }
        } else { // sms
            $emit(['Sent at', 'To', 'Message', 'Sent by', 'Cost (KSh)']);
            foreach ($export_rows as $r) {
                $emit([$r->sent_at, $r->phone, $r->message, trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')) ?: 'system', $r->cost]);
            }
        }
        fclose($out);
        exit;
    }

    $drill_rows = isset($streams[$drill]) ? Revenue::stream_rows($drill, 100) : [];
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Revenue - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" href="css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #4C6EF5; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .stat-card .sub { font-size: 11.5px; color: #64748B; margin-top: 2px; }
        .delta-up   { color: #059669; }
        .delta-down { color: #DC2626; }
        .mono { font-variant-numeric: tabular-nums; }
        .spark { display: flex; align-items: flex-end; gap: 3px; height: 44px; margin-top: 8px; }
        .spark span { flex: 1 1 auto; background: #c7d2fe; border-radius: 2px 2px 0 0; min-height: 2px; }
        .spark span.today { background: #4C6EF5; }
        .spark-labels { display: flex; gap: 3px; margin-top: 3px; }
        .spark-labels span { flex: 1 1 auto; font-size: 9px; color: #94A3B8; text-align: center; overflow: hidden; }
        .month-table td, .month-table th { font-size: 12.5px; vertical-align: middle; }
        .month-table .cur { background: #eef2ff; font-weight: 600; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-cash-multiple" style="color:#4C6EF5"></i> Revenue</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">subscriptions · vendor commissions · SMS spend — all KSh, all M-Pesa</p>
        </div>
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-home mr-1"></i> Dashboard</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <!-- ===== Lifetime totals ===== -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card" style="border-left-color:#0F172A">
                <h6>Lifetime revenue</h6>
                <div class="value mono"><?= format_ksh($lifetime['total']); ?></div>
                <div class="sub">all streams, all time</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h6>Subscriptions</h6>
                <div class="value mono"><?= format_ksh($lifetime['subscriptions']); ?></div>
                <div class="sub"><a href="revenue.php?stream=subscriptions" style="font-size:11.5px;">view payments →</a></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="border-left-color:#059669">
                <h6>Vendor commissions</h6>
                <div class="value mono"><?= format_ksh($lifetime['commissions']); ?></div>
                <div class="sub"><a href="revenue.php?stream=commissions" style="font-size:11.5px;">view deals →</a></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="border-left-color:#D97706">
                <h6>SMS spend</h6>
                <div class="value mono"><?= format_ksh($lifetime['sms']); ?></div>
                <div class="sub"><a href="revenue.php?stream=sms" style="font-size:11.5px;">view sends →</a></div>
            </div>
        </div>
    </div>

    <!-- ===== This month vs last month + sparkline ===== -->
    <div class="row">
        <div class="col-lg-4 col-md-6">
            <div class="stat-card" style="border-left-color:#0F172A">
                <h6>This month · <?= htmlspecialchars($thisM['label']); ?></h6>
                <div class="value mono"><?= format_ksh($thisM['total']); ?></div>
                <div class="sub">
                    <?php if ($lastM['total'] > 0): $pct = (($thisM['total'] - $lastM['total']) / $lastM['total']) * 100; ?>
                        <span class="<?= $pct >= 0 ? 'delta-up' : 'delta-down'; ?>">
                            <i class="mdi mdi-<?= $pct >= 0 ? 'trending-up' : 'trending-down'; ?>"></i>
                            <?= number_format(abs($pct), 1); ?>% vs <?= htmlspecialchars($lastM['label']); ?>
                        </span>
                    <?php else: ?>
                        first month on record<?= $thisM['total'] > 0 ? '' : ' — no revenue yet'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8 col-md-6">
            <div class="stat-card" style="border-left-color:#4C6EF5">
                <h6>Last <?= count($months); ?> months · total revenue</h6>
                <?php
                    $peak = 0.0;
                    foreach ($rows as $r) { $peak = max($peak, (float) $r['total']); }
                ?>
                <div class="spark" title="monthly total revenue, oldest left">
                    <?php foreach ($rows as $i => $r): $h = $peak > 0 ? max(2, (int) round(((float) $r['total'] / $peak) * 100)) : 2; ?>
                        <span style="height:<?= $h; ?>%;" class="<?= $i === array_key_last($rows) ? 'today' : ''; ?>"
                              title="<?= htmlspecialchars(date('M Y', strtotime($r['ym'] . '-01'))); ?>: <?= format_ksh($r['total']); ?>"></span>
                    <?php endforeach; ?>
                </div>
                <div class="spark-labels">
                    <?php foreach ($rows as $i => $r): ?>
                        <span><?= htmlspecialchars(date('M', strtotime($r['ym'] . '-01'))); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Month-by-month table ===== -->
    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <h6 class="mb-3" style="font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:#64748B;">Month by month</h6>
            <div class="table-responsive">
            <table class="table table-hover month-table" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-right">Subscriptions</th>
                        <th class="text-right">Commissions</th>
                        <th class="text-right">SMS</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): $isCurrent = $r['ym'] === date('Y-m'); ?>
                    <tr class="<?= $isCurrent ? 'cur' : ''; ?>">
                        <td><?= htmlspecialchars(date('F Y', strtotime($r['ym'] . '-01'))); ?><?= $isCurrent ? ' <span class="badge badge-primary" style="font-size:9px;">now</span>' : ''; ?></td>
                        <td class="text-right mono"><?= format_ksh($r['subscriptions']); ?></td>
                        <td class="text-right mono"><?= format_ksh($r['commissions']); ?></td>
                        <td class="text-right mono"><?= format_ksh($r['sms']); ?></td>
                        <td class="text-right mono"><strong><?= format_ksh($r['total']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="border-top:2px solid #0F172A;">
                        <td><strong><?= count($months); ?>-month total</strong></td>
                        <?php
                            $win = ['subscriptions' => 0.0, 'commissions' => 0.0, 'sms' => 0.0, 'total' => 0.0];
                            foreach ($months as $r) {
                                $win['subscriptions'] += $r['subscriptions'];
                                $win['commissions']   += $r['commissions'];
                                $win['sms']           += $r['sms'];
                                $win['total']         += $r['total'];
                            }
                        ?>
                        <td class="text-right mono"><strong><?= format_ksh($win['subscriptions']); ?></strong></td>
                        <td class="text-right mono"><strong><?= format_ksh($win['commissions']); ?></strong></td>
                        <td class="text-right mono"><strong><?= format_ksh($win['sms']); ?></strong></td>
                        <td class="text-right mono"><strong><?= format_ksh($win['total']); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ===== Stream drill-down ===== -->
    <div class="card shadow-sm mt-3" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                <h6 class="mb-0" style="font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:#64748B;">
                    <?= isset($streams[$drill]) ? htmlspecialchars($streams[$drill]) . ' — latest ' . count($drill_rows) : 'Revenue streams — pick a stream above'; ?>
                </h6>
                <?php if (isset($streams[$drill])): ?>
                    <div>
                        <a href="revenue.php?stream=<?= urlencode($drill); ?>&export=1" class="btn btn-sm btn-outline-success mr-1"
                           title="Download this table as CSV (opens in Excel / Google Sheets)"><i class="mdi mdi-file-download"></i> Export CSV</a>
                        <a href="revenue.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-close"></i> Clear</a>
                    </div>
                <?php endif; ?>

            <?php if ($drill === 'subscriptions'): ?>
                <div class="table-responsive">
                <table class="table table-hover month-table" style="margin-bottom:0;">
                    <thead><tr><th>Planner</th><th>Plan</th><th>Amount</th><th>M-Pesa code</th><th>Period</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (empty($drill_rows)): ?>
                        <?php empty_state_row(6, 'No subscription payments yet', 'Planner sign-ups and renewals will appear here.', 'mdi-crown'); ?>
                    <?php else: foreach ($drill_rows as $p): ?>
                        <tr>
                            <td style="font-size:12.5px;"><strong><?= htmlspecialchars(trim(($p->firstname ?? '') . ' ' . ($p->lastname ?? '')) ?: '—'); ?></strong>
                                <div style="font-size:11px; color:#64748B;"><?= htmlspecialchars($p->email ?? ''); ?></div></td>
                            <td style="font-size:12.5px; text-transform:capitalize;"><?= htmlspecialchars($p->plan); ?></td>
                            <td class="mono" style="font-size:12.5px;"><strong><?= format_ksh($p->amount, 2); ?></strong></td>
                            <td class="mono" style="font-size:12.5px;"><?= htmlspecialchars($p->mpesa_code ?: '—'); ?></td>
                            <td style="font-size:12.5px;"><?= htmlspecialchars(format_date_kd((string) $p->start_date)); ?> → <?= htmlspecialchars(format_date_kd((string) $p->expiry_date)); ?></td>
                            <td><span class="badge badge-<?= $p->status === 'active' ? 'success' : 'secondary'; ?>" style="font-size:10px;"><?= htmlspecialchars($p->status); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            <?php elseif ($drill === 'commissions'): ?>
                <div class="table-responsive">
                <table class="table table-hover month-table" style="margin-bottom:0;">
                    <thead><tr><th>Vendor</th><th>Couple</th><th>Deal</th><th>Commission</th><th>Event date</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (empty($drill_rows)): ?>
                        <?php empty_state_row(6, 'No confirmed vendor deals yet', 'Confirm a couple\'s enquiry on the vendor ledger to record commission.', 'mdi-handshake-outline'); ?>
                    <?php else: foreach ($drill_rows as $p): ?>
                        <tr>
                            <td style="font-size:12.5px;"><strong><?= htmlspecialchars($p->business_name ?? '—'); ?></strong></td>
                            <td style="font-size:12.5px;"><?= htmlspecialchars(trim(($p->bride ?? '') . ' & ' . ($p->groom ?? ''))); ?></td>
                            <td class="mono" style="font-size:12.5px;"><?= format_ksh($p->deal_amount, 2); ?></td>
                            <td class="mono" style="font-size:12.5px;"><strong><?= format_ksh($p->commission, 2); ?></strong></td>
                            <td style="font-size:12.5px;"><?= !empty($p->event_date) ? htmlspecialchars(format_date_kd((string) $p->event_date)) : '—'; ?></td>
                            <td><span class="badge badge-<?= $p->status === 'completed' ? 'primary' : 'success'; ?>" style="font-size:10px;"><?= htmlspecialchars($p->status); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            <?php elseif ($drill === 'sms'): ?>
                <div class="table-responsive">
                <table class="table table-hover month-table" style="margin-bottom:0;">
                    <thead><tr><th>Sent</th><th>To</th><th>Message</th><th>By</th><th class="text-right">Cost</th></tr></thead>
                    <tbody>
                    <?php if (empty($drill_rows)): ?>
                        <?php empty_state_row(5, 'No SMS sent yet', 'Successful sends and their KSh cost will appear here.', 'mdi-message-text'); ?>
                    <?php else: foreach ($drill_rows as $p): ?>
                        <tr>
                            <td style="font-size:12.5px; white-space:nowrap;"><?= htmlspecialchars(format_date_kd((string) $p->sent_at, true) ?: '—'); ?></td>
                            <td class="mono" style="font-size:12.5px;"><?= htmlspecialchars($p->phone); ?></td>
                            <td style="font-size:12.5px;"><?= htmlspecialchars(trim_body((string) $p->message, 70)); ?></td>
                            <td style="font-size:12.5px;"><?= htmlspecialchars(trim(($p->firstname ?? '') . ' ' . ($p->lastname ?? '')) ?: 'system'); ?></td>
                            <td class="text-right mono" style="font-size:12.5px;"><strong><?= format_ksh($p->cost, 2); ?></strong></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0" style="font-size:13px;">
                    Click <strong>view payments</strong>, <strong>view deals</strong> or <strong>view sends</strong> above to see the individual
                    rows behind each stream. Subscription revenue is recognized when the planner pays; commissions when the deal is
                    confirmed; SMS when the message actually sends.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php';?>
</body>
</html>
