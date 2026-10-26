<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
/**
 * The couple's view of the same plan: every vendor their planner has chosen for
 * them, what each is doing, and one total — read-only, in their words.
 *
 * WHY THEY NEED THEIR OWN PAGE
 * The marketplace shows what they could buy, the enquiry list shows what they
 * asked, and My Payments shows what has been paid. None of them adds up the
 * vendors. This page is the single number a couple actually wants ("the
 * wedding's vendors come to KSh 1,240,000, and here is who is doing what"), and
 * it compares that with the caps they set on Taste & Budget, so "over" is
 * visible while there is still time to act.
 *
 * NOTHING IS WRITTEN HERE. Adding or confirming a vendor is the planner's job
 * (admin/vendor_plan.php and the enquiry ledger); a page the couple can open
 * must not be able to change what a vendor was promised.
 */
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id    = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }
    $booking = Booking::find_by_booking_id($booking_id);
    if (!may_open_couple_page($booking, $user_id)) {
        redirect_to("client.php");
        die();
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $plan   = VendorPlan::build($booking_id);
    $totals = $plan['totals'];

    $couple = trim(((string) ($booking->bride ?? '')) . ' & ' . ((string) ($booking->groom ?? '')), ' &');
    if ($couple === '') {
        $couple = 'Our wedding';
    }
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd((string) $booking->wedding_date) : '';

    $caps      = (float) $totals['caps'];
    $over_caps = $caps > 0 && (float) $totals['total'] > $caps;

    // Only the categories that concern the couple: money planned, money capped,
    // or a vendor already chosen.
    $visible = array_filter($plan['categories'], static function (array $cat): bool {
        return $cat['rows'] !== [] || $cat['cap'] !== null;
    });
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Our Vendor Plan - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .plan-tile { background:#fff; border-radius:12px; padding:14px 18px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .plan-tile .label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748B; }
        .plan-tile .value { font-size:22px; font-weight:700; color:#0F172A; font-variant-numeric:tabular-nums; }
        .plan-tile .value.warn { color:#B45309; }
        .plan-tile .hint, .hint { font-size:11.5px; color:#64748B; }
        .mono { font-variant-numeric: tabular-nums; }
        .cat-row td { background:#F8FAFC; font-weight:600; border-top:2px solid #E2E8F0; }
        .deal-row td { font-size:12.5px; }
        .deal-row .math { color:#64748B; font-size:11.5px; }
        .chip { display:inline-block; border-radius:999px; padding:1px 9px; font-size:10.5px; font-weight:600; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap" style="border-bottom:1px solid #dee2e6; padding-bottom:12px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-clipboard-text-outline" style="color:#0E7490;"></i> Our Vendor Plan</h4>
            <p class="text-muted mb-0" style="font-size:13px;">
                <?= htmlspecialchars($couple); ?><?= $wedding_human !== '' ? ' — ' . htmlspecialchars($wedding_human) : ''; ?>
                · who is doing what, and what it all comes to
            </p>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-success mr-1" href="client_vendors.php?<?= htmlspecialchars($links); ?>">
                <i class="mdi mdi-store mr-1"></i> Vendors
            </a>
            <a class="btn btn-sm btn-outline-info mr-1" href="client_vendor_preferences.php?<?= htmlspecialchars($links); ?>">
                <i class="mdi mdi-palette-swatch mr-1"></i> Taste &amp; Budget
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="client_budget.php?<?= htmlspecialchars($links); ?>">
                <i class="mdi mdi-cash-multiple mr-1"></i> My Payments
            </a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Total vendor cost</div>
                <div class="value <?= $over_caps ? 'warn' : ''; ?>"><?= VendorPlan::money_text((float) $totals['total']); ?></div>
                <div class="hint">
                    <?php if ($caps > 0): ?>
                        of <?= VendorPlan::money_text($caps); ?> you budgeted
                    <?php else: ?>
                        everything planned so far
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Confirmed</div>
                <div class="value" style="color:#065F46;"><?= VendorPlan::money_text((float) $totals['committed']); ?></div>
                <div class="hint"><?= (int) $totals['confirmed']; ?> vendor<?= (int) $totals['confirmed'] === 1 ? '' : 's'; ?> booked and agreed</div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Waiting to hear back</div>
                <div class="value"><?= VendorPlan::money_text((float) $totals['planned']); ?></div>
                <div class="hint"><?= (int) $totals['pending']; ?> enquir<?= (int) $totals['pending'] === 1 ? 'y' : 'ies'; ?> with a vendor</div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Left in your budget</div>
                <div class="value <?= $over_caps ? 'warn' : ''; ?>">
                    <?= $caps > 0 ? VendorPlan::money_text(abs($caps - (float) $totals['total'])) : '—'; ?>
                </div>
                <div class="hint">
                    <?= $caps > 0
                        ? ($over_caps ? 'over the caps you set' : 'still to plan')
                        : 'set caps per category on Taste & Budget'; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($over_caps): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <i class="mdi mdi-alert"></i>
            The vendors planned so far come to <strong><?= VendorPlan::money_text((float) $totals['total']); ?></strong>,
            which is <?= VendorPlan::money_text((float) $totals['total'] - $caps); ?> over the caps you set.
            Talk to your planner about which category to change — a cap is what you said you would spend, not a limit anyone enforced.
        </div>
    <?php endif; ?>

    <?php if ($visible === []): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-body text-center py-5">
                <i class="mdi mdi-store-outline" style="font-size:38px; color:#94A3B8;"></i>
                <h6 class="mt-2">No vendors planned yet</h6>
                <p class="text-muted mb-3" style="font-size:13px;">
                    When your planner adds a vendor for a category — or you send an enquiry yourself — it appears here with
                    its price, and this page keeps the running total.
                </p>
                <a href="client_vendors.php?<?= htmlspecialchars($links); ?>" class="btn btn-sm btn-success">Browse vendors</a>
                <a href="client_vendor_preferences.php?<?= htmlspecialchars($links); ?>" class="btn btn-sm btn-outline-info ml-1">Set your taste &amp; budget</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card mb-3" style="border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <div class="card-body">
                <table class="table table-sm mb-0" style="font-size:12.5px;">
                    <thead>
                        <tr>
                            <th style="width:22%;">Category</th>
                            <th>Vendor and what they are doing</th>
                            <th class="text-right" style="width:110px;">Your cap</th>
                            <th class="text-right" style="width:110px;">Cost</th>
                            <th style="width:130px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visible as $slug => $cat):
                        $over = $cat['state'] === 'over';
                    ?>
                        <tr class="cat-row">
                            <td><i class="mdi mdi-<?= htmlspecialchars($cat['icon']); ?> mr-1" style="color:#0E7490;"></i><?= htmlspecialchars($cat['label']); ?></td>
                            <td class="font-weight-normal" style="font-size:11.5px; color:#64748B;">
                                <?php if ($cat['rows'] === []): ?>
                                    Nothing chosen yet<?= $cat['cap'] !== null ? ' — your cap is set' : ''; ?>
                                <?php else: ?>
                                    <?= count($cat['rows']); ?> vendor<?= count($cat['rows']) === 1 ? '' : 's'; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right mono"><?= $cat['cap'] !== null ? VendorPlan::money_text((float) $cat['cap']) : '<span class="text-muted">—</span>'; ?></td>
                            <td class="text-right mono <?= $over ? 'text-danger font-weight-bold' : ''; ?>">
                                <?= $cat['total'] > 0 ? VendorPlan::money_text((float) $cat['total']) : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td>
                                <?php if ($cat['rows'] === []): ?>
                                    <span class="chip" style="background:#F1F5F9; color:#475569;">To choose</span>
                                <?php elseif ($cat['committed'] > 0): ?>
                                    <span class="chip" style="background:#D1FAE5; color:#065F46;">Booked</span>
                                <?php else: ?>
                                    <span class="chip" style="background:#FEF3C7; color:#92400E;">Awaiting reply</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <?php foreach ($cat['rows'] as $row):
                            $status = (string) $row->status;
                            $chip = match ($status) {
                                'confirmed' => ['Booked', '#D1FAE5', '#065F46'],
                                'completed' => ['Done', '#DBEAFE', '#1E40AF'],
                                'cancelled' => ['Cancelled', '#F1F5F9', '#475569'],
                                default     => ['Awaiting reply', '#FEF3C7', '#92400E'],
                            };
                        ?>
                            <tr class="deal-row">
                                <td></td>
                                <td>
                                    <strong><?= htmlspecialchars((string) $row->business_name); ?></strong>
                                    — <?= htmlspecialchars((string) $row->offering_label); ?>
                                    <div class="math"><?= htmlspecialchars((string) $row->money_line); ?></div>
                                </td>
                                <td></td>
                                <td class="text-right mono"><?= VendorPlan::money_text((float) $row->amount); ?></td>
                                <td>
                                    <span class="chip" style="background:<?= $chip[1]; ?>; color:<?= $chip[2]; ?>;"><?= $chip[0]; ?></span>
                                    <?php if ($status === 'cancelled'): ?>
                                        <div class="hint">not in the total</div>
                                        <?php /* v3.3 — the reason, so a dropped line
                                                   explains itself here as well as on the
                                                   enquiries list, and names who said
                                                   it (vendor decline vs planner cut). */ ?>
                                        <?php $planWhy = DealNote::text($row); ?>
                                        <?php if ($planWhy !== ''): ?>
                                            <div class="hint" style="color:#475569;">
                                                <i class="mdi mdi-quote-open"></i>
                                                <strong><?= htmlspecialchars(DealNote::attribution($row)); ?>:</strong>
                                                <?= htmlspecialchars($planWhy); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #0F172A;">
                            <th colspan="3" class="text-right">Total vendor cost</th>
                            <th class="text-right mono"><?= VendorPlan::money_text((float) $totals['total']); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <p class="text-muted" style="font-size:11.5px;">
        <i class="mdi mdi-information-outline"></i>
        This is what your vendors cost — not what you have paid. Payments, instalments and M-Pesa receipts are on
        <a href="client_budget.php?<?= htmlspecialchars($links); ?>">My Payments</a>.
        <?php if ((int) $totals['dropped'] > 0): ?>
            <?= (int) $totals['dropped']; ?> cancelled deal<?= (int) $totals['dropped'] === 1 ? '' : 's'; ?> stay on the record but not in this total.
        <?php endif; ?>
    </p>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
