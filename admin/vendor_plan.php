<?php include 'include/init.php'; ?>
<?php
/**
 * The planner's vendor plan for ONE wedding: every vendor chosen (or being
 * asked), what each was chosen for, and one total.
 *
 * WHY THIS PAGE EXISTS
 * The coverage board answers "what does this function still need", the enquiry
 * ledger answers "what has been asked of whom", and the budget page records what
 * the couple has PAID. None of them answers the question the couple asks first:
 * "who is doing our wedding, and what does it cost?" A planner was left adding
 * deal figures up in their head, and the couple had no single number at all.
 * This page is that number, built from the deals that already exist — plus the
 * caps from the couple's Taste & Budget, so "we have planned KSh 480,000 of
 * KSh 650,000" is one glance rather than a spreadsheet.
 *
 * WHAT IS WRITTEN HERE, AND WHAT IS NOT
 * Only one action: adding a vendor to the plan (POST + CSRF), which creates the
 * same pending deal a marketplace enquiry creates — so the vendor sees it on
 * their ledger, the planner confirms it with an amount, and the couple reads it
 * on their own plan. Nothing re-prices a deal: the amount the planner enters (or
 * the published price × the quantity) is what is stored, and where a vendor has
 * since changed their price the row says so instead of rewriting it.
 */
    require_staff_subscribed();

    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id    = (int) ($_GET['user_id'] ?? 0);
    $links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;
    $self       = 'vendor_plan.php?' . $links;

    $booking = Booking::find_by_booking_id($booking_id);
    if (!$booking || !booking_visible_to_session($booking)) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-lock"></i></strong> That wedding is not one of yours.</div>');
        redirect_to('client.php');
        die();
    }

    $package       = Category::find_by_id((int) ($booking->wedding_type ?? 0));
    $package_price = (float) ($package->price ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_add'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to($self);
            die();
        }

        // The picker's value carries all three ids: kind:offering:vendor. A
        // custom arrangement has no offering row, so its middle id is 0.
        $parts  = explode(':', (string) ($_POST['offering'] ?? ''), 3);
        $kind   = (string) ($parts[0] ?? '');
        $offering_id = (int) ($parts[1] ?? 0);
        $vendor_id   = (int) ($parts[2] ?? 0);

        $posted_qty = $_POST['qty'] ?? null;
        $posted_amt = $_POST['amount'] ?? null;
        $qty    = is_numeric($posted_qty) && (int) $posted_qty > 0 ? (int) $posted_qty : null;
        $amount = is_numeric($posted_amt) ? (float) $posted_amt : 0.0;
        $note   = clean($_POST['note'] ?? '');

        $result = VendorPlan::add($booking_id, $vendor_id, $kind, $offering_id, $qty, $amount, $note, (int) $_SESSION['id']);

        $session->message($result['ok']
            ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> '
                . htmlspecialchars($result['label']) . ' added to the plan at '
                . VendorPlan::money_text((float) $result['amount']) . '. The vendor has been asked and it now counts in the total.'
                . ($result['warning'] !== '' ? ' <span class="text-danger"><i class="mdi mdi-calendar-remove"></i> '
                    . htmlspecialchars($result['warning']) . '</span>' : '')
                . '</div>'
            : '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($result['error']) . '</div>');

        redirect_to($self);
        die();
    }

    $plan = VendorPlan::build($booking_id);
    $pick = VendorPlan::offerings();
    $totals = $plan['totals'];

    $couple = trim(((string) ($booking->bride ?? '')) . ' & ' . ((string) ($booking->groom ?? '')), ' &');
    if ($couple === '') {
        $couple = 'This wedding';
    }
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd((string) $booking->wedding_date) : '';

    // Where the total sits against what the couple said they would spend.
    $caps  = (float) $totals['caps'];
    $over_caps = $caps > 0 && (float) $totals['total'] > $caps;
    $vs_package = $package_price > 0 ? $package_price - (float) $totals['total'] : null;
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Vendor Plan - Everafter Planner</title>
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
        .plan-tile .value.ok { color:#065F46; }
        .mono { font-variant-numeric: tabular-nums; }
        .cat-row td { background:#F8FAFC; font-weight:600; color:#0F172A; border-top:2px solid #E2E8F0; }
        .deal-row td { font-size:12.5px; }
        .deal-row .offering { color:#0F172A; font-weight:600; }
        .deal-row .math { color:#64748B; font-size:11.5px; }
        .pick-card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .hint { font-size:11.5px; color:#64748B; }
        .chip { display:inline-block; border-radius:999px; padding:1px 8px; font-size:10.5px; font-weight:600; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap" style="border-bottom:1px solid #dee2e6; padding-bottom:12px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-clipboard-text-outline" style="color:#0E7490;"></i> Vendor Plan</h4>
            <p class="text-muted mb-0" style="font-size:13px;">
                <?= htmlspecialchars($couple); ?><?= $wedding_human !== '' ? ' — ' . htmlspecialchars($wedding_human) : ''; ?>
                · <?= (int) $plan['guests']; ?> guests on the list · every vendor, one total
            </p>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-secondary mr-1" href="client_manage_account_details.php?<?= htmlspecialchars($links); ?>">
                <i class="mdi mdi-buffer mr-1"></i> Overview
            </a>
            <a class="btn btn-sm btn-outline-dark mr-1" href="vendor_coverage.php?<?= htmlspecialchars($links); ?>">
                <i class="mdi mdi-view-grid-plus mr-1"></i> Coverage
            </a>
            <a class="btn btn-sm btn-outline-info mr-1" href="vendor_enquiries.php">
                <i class="mdi mdi-email-outline mr-1"></i> Enquiries
            </a>
            <a class="btn btn-sm btn-outline-success" href="vendors.php?booking_id=<?= (int) $booking_id; ?>">
                <i class="mdi mdi-store mr-1"></i> Marketplace
            </a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!VendorPlan::installed()): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <strong>Planning a specific service is not available yet.</strong>
            Apply <code>migrations/v2.29_vendor_plan.sql</code> to record which offering a deal is for.
            Package deals and agreed amounts still work, and the totals below are still correct.
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Committed</div>
                <div class="value ok"><?= VendorPlan::money_text((float) $totals['committed']); ?></div>
                <div class="hint"><?= (int) $totals['confirmed']; ?> confirmed or completed deal<?= (int) $totals['confirmed'] === 1 ? '' : 's'; ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Awaiting reply</div>
                <div class="value"><?= VendorPlan::money_text((float) $totals['planned']); ?></div>
                <div class="hint">
                    <?= (int) $totals['pending']; ?> enquir<?= (int) $totals['pending'] === 1 ? 'y' : 'ies'; ?> not agreed yet
                    <?php if ((int) $totals['unpriced'] > 0): ?>
                        · <?= (int) $totals['unpriced']; ?> without a price
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Total vendor cost</div>
                <div class="value <?= $over_caps ? 'warn' : ''; ?>"><?= VendorPlan::money_text((float) $totals['total']); ?></div>
                <div class="hint">
                    <?php if ($caps > 0): ?>
                        of <?= VendorPlan::money_text($caps); ?> the couple capped
                        (<?= $over_caps
                            ? VendorPlan::money_text((float) $totals['total'] - $caps) . ' over'
                            : VendorPlan::money_text($caps - (float) $totals['total']) . ' left'; ?>)
                    <?php elseif ($package_price > 0): ?>
                        against a <?= VendorPlan::money_text($package_price); ?> package
                    <?php else: ?>
                        the couple has not set category caps yet
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="plan-tile">
                <div class="label">Categories open</div>
                <div class="value"><?= (int) $totals['gaps']; ?></div>
                <div class="hint">
                    <?php if ($vs_package !== null): ?>
                        <?= VendorPlan::money_text(abs($vs_package)); ?> <?= $vs_package >= 0 ? 'of the package unspent' : 'above the package price'; ?>
                    <?php else: ?>
                        budgeted but no vendor chosen yet
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ((int) $totals['dropped'] > 0): ?>
        <p class="text-muted" style="font-size:12px;">
            <i class="mdi mdi-cancel"></i> <?= (int) $totals['dropped']; ?> cancelled deal<?= (int) $totals['dropped'] === 1 ? '' : 's'; ?>
            (<?= VendorPlan::money_text((float) $totals['cancelled']); ?>) are kept on the record but not in the total.
        </p>
    <?php endif; ?>

    <div class="card mb-3" style="border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div class="card-body">
            <table class="table table-sm mb-0" style="font-size:12.5px;">
                <thead>
                    <tr>
                        <th style="width:22%;">Category</th>
                        <th>Vendor &amp; what they are doing</th>
                        <th class="text-right" style="width:120px;">Their cap</th>
                        <th class="text-right" style="width:120px;">Cost</th>
                        <th style="width:170px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($plan['categories'] as $slug => $cat):
                    [$state_label, $state_tone, $state_icon] = VendorPlan::states()[$cat['state']];
                ?>
                    <tr class="cat-row" id="cat-<?= htmlspecialchars($slug); ?>">
                        <td><i class="mdi mdi-<?= htmlspecialchars($cat['icon']); ?> mr-1" style="color:#0E7490;"></i><?= htmlspecialchars($cat['label']); ?></td>
                        <td class="font-weight-normal" style="font-size:11.5px; color:#64748B;">
                            <?= $cat['rows'] === [] ? 'No vendor chosen' : count($cat['rows']) . ' deal' . (count($cat['rows']) === 1 ? '' : 's'); ?>
                        </td>
                        <td class="text-right mono">
                            <?= $cat['cap'] !== null ? VendorPlan::money_text((float) $cat['cap']) : '<span class="text-muted">—</span>'; ?>
                        </td>
                        <td class="text-right mono <?= $cat['state'] === 'over' ? 'text-danger font-weight-bold' : ''; ?>">
                            <?= $cat['total'] > 0 ? VendorPlan::money_text((float) $cat['total']) : '<span class="text-muted">—</span>'; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($state_tone); ?>" style="font-size:10.5px;">
                                <i class="mdi mdi-<?= htmlspecialchars($state_icon); ?>"></i> <?= htmlspecialchars($state_label); ?>
                            </span>
                            <?php if ($cat['remaining'] !== null && $cat['total'] > 0 && $cat['state'] !== 'over'): ?>
                                <div class="hint"><?= VendorPlan::money_text((float) $cat['remaining']); ?> left</div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php foreach ($cat['rows'] as $row):
                        $status = (string) $row->status;
                        $tone = match ($status) {
                            'confirmed' => 'success',
                            'completed' => 'primary',
                            'cancelled' => 'secondary',
                            default     => 'warning',
                        };
                        $status_label = match ($status) {
                            'confirmed' => 'Confirmed',
                            'completed' => 'Completed',
                            'cancelled' => 'Cancelled',
                            default     => 'Awaiting reply',
                        };
                    ?>
                        <tr class="deal-row">
                            <td></td>
                            <td>
                                <span class="offering"><?= htmlspecialchars((string) $row->business_name); ?></span>
                                — <?= htmlspecialchars((string) $row->offering_label); ?>
                                <div class="math">
                                    <?= htmlspecialchars((string) $row->money_line); ?>
                                    <?php if (!empty($row->function_title)): ?>
                                        · <?= htmlspecialchars((string) $row->function_title); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($row->price_moved): ?>
                                    <div class="hint text-warning">
                                        <i class="mdi mdi-tag-alert"></i>
                                        Their price is now <?= VendorPlan::money_text((float) $row->price_now); ?> at this quantity — the plan keeps what you entered.
                                    </div>
                                <?php endif; ?>
                                <?php if ($row->unpriced): ?>
                                    <div class="hint">No amount yet — confirm the deal in Enquiries to put a price on it.</div>
                                <?php endif; ?>
                            </td>
                            <td></td>
                            <td class="text-right mono"><?= VendorPlan::money_text((float) $row->amount); ?></td>
                            <td>
                                <span class="chip" style="background:#FEF3C7; color:#92400E;">
                                    <?= htmlspecialchars($status_label); ?>
                                </span>
                                <div class="mt-1">
                                    <a href="vendor_thread_open.php?deal_id=<?= (int) $row->id; ?>" class="hint">conversation</a>
                                    · <a href="vendor_enquiries.php?status=<?= urlencode($status); ?>" class="hint">ledger</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($cat['rows'] === []): ?>
                        <tr class="deal-row">
                            <td></td>
                            <td class="text-muted">
                                <?= $cat['cap'] !== null
                                    ? 'Budgeted, but nobody chosen yet — pick a vendor below.'
                                    : 'Nothing planned for this category.'; ?>
                            </td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
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

    <div class="pick-card mb-4">
        <div class="card-body">
            <h6 class="mb-1" style="font-weight:700;"><i class="mdi mdi-plus-circle-outline" style="color:#0E7490;"></i> Add a vendor to the plan</h6>
            <p class="hint mb-3">
                Choosing a vendor here sends them the enquiry and puts their price in the total. Pick one of their published
                packages or services and the cost is worked out from the unit; for a per-hour or per-km service, say how many
                hours or kilometres you need. Vendors with nothing published can still be planned with an agreed amount.
            </p>

            <?php if ($pick === []): ?>
                <div class="alert alert-light border mb-0" style="font-size:13px;">
                    No approved vendors have published anything yet. Approve vendors in the marketplace, or ask them to add
                    their services from their dashboard.
                </div>
            <?php else: ?>
                <form method="post">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="plan_add" value="1">
                    <div class="form-row">
                        <div class="form-group col-lg-6">
                            <label class="hint font-weight-bold mb-1">Vendor and offering</label>
                            <select name="offering" class="form-control form-control-sm" required>
                                <option value="">Choose a vendor…</option>
                                <?php foreach (VendorCoverage::categories() as $slug => $meta):
                                    $groups = array_values(array_filter($pick, static fn (array $g): bool => $g['category'] === $slug));
                                    if ($groups === []) {
                                        continue;
                                    }
                                ?>
                                    <optgroup label="<?= htmlspecialchars($meta[0]); ?>">
                                        <?php foreach ($groups as $group):
                                            if ($group['offerings'] === []): ?>
                                                <option value="custom:0:<?= (int) $group['vendor_id']; ?>">
                                                    <?= htmlspecialchars($group['vendor']); ?> — custom arrangement (enter the amount)
                                                </option>
                                            <?php else: ?>
                                                <?php foreach ($group['offerings'] as $offering): ?>
                                                    <option value="<?= htmlspecialchars($offering['kind'] . ':' . $offering['id'] . ':' . $group['vendor_id']); ?>">
                                                        <?= htmlspecialchars($group['vendor'] . ' — ' . $offering['label'] . ' — ' . $offering['price_line']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-lg-2 col-md-4">
                            <label class="hint font-weight-bold mb-1">Quantity</label>
                            <input type="number" name="qty" class="form-control form-control-sm mono" min="1" step="1"
                                   placeholder="<?= (int) $plan['guests'] > 0 ? (int) $plan['guests'] . ' guests' : 'guests'; ?>">
                            <div class="hint mt-1">Blank uses the guest list.</div>
                        </div>
                        <div class="form-group col-lg-2 col-md-4">
                            <label class="hint font-weight-bold mb-1">Agreed amount</label>
                            <input type="number" name="amount" class="form-control form-control-sm mono" min="0" step="1" placeholder="KSh (optional)">
                            <div class="hint mt-1">Overrides the published price.</div>
                        </div>
                        <div class="form-group col-lg-2 col-md-4">
                            <label class="hint font-weight-bold mb-1">Note</label>
                            <input type="text" name="note" class="form-control form-control-sm" maxlength="200" placeholder="e.g. halal menu">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="mdi mdi-send mr-1"></i> Add to the plan and send the enquiry
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
