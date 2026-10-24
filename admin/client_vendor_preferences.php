<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
/**
 * The couple's taste and budget, per vendor category — the brief that makes a
 * marketplace list answer their question instead of restating every vendor.
 *
 * WHO EDITS IT
 * The couple (their own wedding), and their planner through the documented
 * preview/assist path — the same `?booking_id=N&user_id=N` gate every couple
 * page uses (`may_open_couple_page`), so a planner who cannot open the wedding
 * cannot learn, or change, what the couple wants to spend.
 *
 * WHY PER CATEGORY
 * A wedding does not have one budget: it has KSh 250,000 for catering, a
 * different number for flowers, and a venue already paid for. Taste is per
 * category for the same reason — "rustic" is a decor note, not a photography
 * note. One row per (wedding, category), upserted by VendorFit.
 *
 * WHAT SAVING DOES
 * An empty form CLEARS the category: "we changed our mind" must look exactly
 * like "we never said", and a row of empty fields would make every later fit
 * say "no preference" while pretending a preference exists.
 *
 * This page writes nothing else. It does not touch the package price or
 * tbl_liquidation: those are what was sold and what was paid, while this is
 * what the couple WANTS for the vendor market.
 *
 * SPLITTING ONE TOTAL (the helper above the cards)
 * A couple usually arrives with one number — what the package costs — and ten
 * blank caps. The split panel takes that total (or their own) and a share per
 * category, previews the caps it would write, and only writes them on a second,
 * explicit submit. Two deliberate choices:
 *
 *   - The client posts the TOTAL and the SHARES, never the amounts. The server
 *     recalculates the plan on the apply request, so a tampered form cannot
 *     invent a cap the arithmetic would not produce.
 *   - Previewing writes nothing, and a failed preview keeps the form exactly as
 *     typed — a couple should never lose ten numbers to an error message.
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
    $self  = 'client_vendor_preferences.php?' . $links;

    $package       = Category::find_by_id((int) ($booking->wedding_type ?? 0));
    $package_price = (float) ($package->price ?? 0);

    /*
     * The split form's state. On a GET it opens at the package price with the
     * suggested shares; after a preview it keeps exactly what was typed.
     */
    $split_total  = $package_price > 0 ? $package_price : 0.0;
    $split_shares = VendorFit::shares();
    $split_plan   = null;
    $split_error  = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to($self);
            die();
        }

        $action   = clean($_POST['action'] ?? '');
        $category = clean($_POST['category'] ?? '');

        if ($action === 'save') {
            $result = VendorFit::save($booking_id, $category, $_POST, (int) $_SESSION['id']);
            $label  = VendorCoverage::categories()[$category][0] ?? 'That category';
            $session->message($result['ok']
                ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> '
                    . htmlspecialchars($label) . ($result['cleared'] ? ' preference cleared.' : ' preference saved — your marketplace now shows what fits.') . '</div>'
                : '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($result['error']) . '</div>');
        } elseif ($action === 'clear') {
            $cleared = VendorFit::clear($booking_id, $category);
            $session->message($cleared
                ? '<div class="alert alert-success">Preference cleared.</div>'
                : '<div class="alert alert-secondary">There was nothing saved for that category.</div>');
        } elseif ($action === 'split_plan' || $action === 'split_apply') {
            $posted_total = $_POST['total'] ?? null;
            $split_total  = is_numeric($posted_total) ? max(0.0, round((float) $posted_total, 2)) : 0.0;
            $posted       = $_POST['shares'] ?? null;
            $posted       = is_array($posted) ? $posted : [];
            $split_shares = [];
            foreach (VendorCoverage::slugs() as $slug) {
                $split_shares[$slug] = is_numeric($posted[$slug] ?? null) ? (float) $posted[$slug] : 0.0;
            }

            $split_plan = VendorFit::plan_split($split_total, $split_shares, VendorFit::preferences($booking_id));

            if ($action === 'split_apply') {
                $result = $split_plan['error'] !== ''
                    ? ['ok' => false, 'error' => $split_plan['error'], 'written' => 0, 'lifted' => 0, 'planned' => 0.0, 'resulting' => 0.0]
                    : VendorFit::apply_split($booking_id, $split_total, $split_shares, (int) $_SESSION['id']);

                if (!$result['ok']) {
                    $split_error = $result['error'];
                } else {
                    $note = $result['written'] === 0
                        ? 'Those caps were already in place — nothing changed.'
                        : VendorFit::money_text((float) $result['planned']) . ' placed across '
                            . $result['written'] . ' categor' . ($result['written'] === 1 ? 'y' : 'ies') . '.';
                    $extra = [];
                    if ($result['lifted'] > 0) {
                        $extra[] = $result['lifted'] . ' cap' . ($result['lifted'] === 1 ? '' : 's')
                            . ' sat below a floor you had already set, so the floor was used instead.';
                    }
                    if ($result['resulting'] > 0 && abs($result['resulting'] - $result['planned']) > 0.5) {
                        $extra[] = 'Your caps now total ' . VendorFit::money_text((float) $result['resulting'])
                            . ' because categories left out of the split kept their own caps.';
                    }
                    $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> '
                        . htmlspecialchars($note)
                        . ($extra !== [] ? ' ' . htmlspecialchars(implode(' ', $extra)) : '') . '</div>');
                    redirect_to($self);
                    die();
                }
            }

            // A preview (or a refused apply) renders in place, with the form intact.
        }

        if ($action !== 'split_plan' && $action !== 'split_apply') {
            redirect_to($self);
            die();
        }
    }

    $prefs       = VendorFit::preferences($booking_id);
    $guests      = VendorFit::guests($booking_id);
    $allocation  = VendorFit::allocation($booking_id);
    $categories  = VendorCoverage::categories();
    $installed   = VendorFit::installed();

    $over_allocated = $package_price > 0 && $allocation['allocated'] > $package_price;

    $couple = trim(((string) ($booking->bride ?? '')) . ' & ' . ((string) ($booking->groom ?? '')), ' &');
    if ($couple === '') {
        $couple = 'This wedding';
    }
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd((string) $booking->wedding_date) : '';
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Taste &amp; Budget - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .pref-card { background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:14px;
                     box-shadow:0 1px 3px rgba(0,0,0,.08); border-left:4px solid #CBD5E1; height:100%; }
        .pref-card.is-set { border-left-color:var(--info); }
        .pref-card h6 { font-size:13.5px; font-weight:700; color:#0F172A; margin-bottom:2px; }
        .pref-card h6 i { color:#0E7490; }
        .pref-summary { font-size:12px; color:#475569; }
        .pref-chip { display:inline-block; background:#ECFEFF; color:#0E7490; border-radius:10px;
                     padding:1px 8px; font-size:11px; margin:2px 4px 0 0; }
        .pref-label { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748B; font-weight:600; }
        .pref-card .form-control-sm { font-size:12.5px; }
        .summary-tile { background:#fff; border-radius:12px; padding:14px 18px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .summary-tile .value { font-size:22px; font-weight:700; color:#0F172A; }
        .summary-tile .value.warn { color:#B45309; }
        .summary-tile .label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748B; }
        .mono { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap" style="border-bottom:1px solid #dee2e6; padding-bottom:12px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-palette-swatch" style="color:#0E7490;"></i> Taste &amp; Budget</h4>
            <p class="text-muted mb-0" style="font-size:13px;">
                <?= htmlspecialchars($couple); ?><?= $wedding_human !== '' ? ' — ' . htmlspecialchars($wedding_human) : ''; ?>
                · what you want from each kind of vendor, and what you can spend
            </p>
        </div>
        <a class="btn btn-sm btn-success mt-2" href="client_vendors.php?<?= htmlspecialchars($links); ?>">
            <i class="mdi mdi-store mr-1"></i> Back to the marketplace
        </a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!$installed): ?>
        <div class="alert alert-warning">
            <strong>Taste and budget are not available yet.</strong>
            Ask your planner to apply <code>migrations/v2.28_vendor_preferences.sql</code>. The marketplace still works; it just cannot show what fits.
        </div>
    <?php else: ?>
        <div class="row mb-3">
            <div class="col-md-4 mb-2">
                <div class="summary-tile">
                    <div class="label">Guest list</div>
                    <div class="value mono"><?= $guests; ?></div>
                    <div class="text-muted" style="font-size:11.5px;">
                        Used to price per-guest services. <?= $guests === 0 ? 'Add guests for a meaningful estimate.' : ''; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="summary-tile">
                    <div class="label">Caps you have set</div>
                    <div class="value mono <?= $over_allocated ? 'warn' : ''; ?>"><?= VendorFit::money_text((float) $allocation['allocated']); ?></div>
                    <div class="text-muted" style="font-size:11.5px;">
                        across <?= (int) $allocation['capped']; ?> of <?= count($categories); ?> categories
                        <?= $package_price > 0 ? ' · package ' . VendorFit::money_text($package_price) : ''; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="summary-tile">
                    <div class="label">Saved preferences</div>
                    <div class="value mono"><?= (int) $allocation['stated']; ?></div>
                    <div class="text-muted" style="font-size:11.5px;">
                        <?= $allocation['stated'] === 0 ? 'Start with the category you are shopping now.' : 'The marketplace marks each service against these.'; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($over_allocated): ?>
            <div class="alert alert-warning">
                <i class="mdi mdi-alert"></i>
                Your caps add up to more than the package price (<?= VendorFit::money_text($package_price); ?>).
                This is not a receipt — it is what you are willing to spend on vendors — but it is worth agreeing the total with your planner.
            </div>
        <?php endif; ?>

        <?php
        $plan_rows = [];
        if ($split_plan !== null && $split_plan['error'] === '') {
            foreach ($split_plan['rows'] as $row) {
                $plan_rows[$row['category']] = $row;
            }
        }
        $plan_changes = 0;
        foreach ($plan_rows as $row) {
            if ($row['changed']) {
                $plan_changes++;
            }
        }
        ?>

        <div class="card mb-3" style="border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div>
                        <h6 class="mb-1" style="font-weight:700;"><i class="mdi mdi-call-split" style="color:#0E7490;"></i> Split one total across the categories</h6>
                        <p class="text-muted mb-2" style="font-size:12.5px; max-width:780px;">
                            Most couples start with a single number — what the package costs. Put that figure here (or your own), move the shares
                            around, and preview the caps before anything is saved. The shares are a <em>starting point</em>, not a rule: leave a category
                            at 0 and it is left out, not cleared.
                        </p>
                    </div>
                    <?php if ($package_price > 0): ?>
                        <div class="text-right ml-3">
                            <div class="pref-label">Package price</div>
                            <div class="mono" style="font-weight:700; color:#0F172A;"><?= VendorFit::money_text($package_price); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($split_error !== ''): ?>
                    <div class="alert alert-danger py-2 mb-2" style="font-size:13px;"><?= htmlspecialchars($split_error); ?></div>
                <?php endif; ?>

                <form method="post" id="split-form">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="action" value="split_plan">
                    <div class="form-row">
                        <div class="form-group col-md-4 mb-2">
                            <label class="pref-label mb-1">Total for vendors (KSh)</label>
                            <input type="number" name="total" class="form-control form-control-sm mono" min="0" step="500"
                                   value="<?= $split_total > 0 ? (int) $split_total : ''; ?>" placeholder="e.g. 300000">
                        </div>
                    </div>

                    <table class="table table-sm mb-2" style="font-size:12.5px;">
                        <thead>
                            <tr>
                                <th style="width:92px;">Share</th>
                                <th>Category</th>
                                <th class="text-right" style="width:130px;">This split</th>
                                <th class="text-right" style="width:130px;">Now</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $slug => $meta):
                                [$label, $icon] = $meta;
                                $share = (float) ($split_shares[$slug] ?? 0);
                                $row   = $plan_rows[$slug] ?? null;
                            ?>
                                <tr>
                                    <td>
                                        <input type="number" name="shares[<?= htmlspecialchars($slug); ?>]"
                                               class="form-control form-control-sm mono text-right" min="0" max="100" step="1"
                                               value="<?= $share == floor($share) ? (int) $share : htmlspecialchars((string) $share); ?>"
                                               aria-label="Share for <?= htmlspecialchars($label); ?>">
                                    </td>
                                    <td>
                                        <i class="mdi mdi-<?= htmlspecialchars($icon); ?>" style="color:#0E7490;"></i>
                                        <?= htmlspecialchars($label); ?>
                                        <?php if ($row !== null && $row['status'] !== 'skipped'): ?>
                                            <span class="text-muted" style="font-size:11px;">(<?= $row['share']; ?>% of the split)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right mono" style="color:#065F46; font-weight:600;">
                                        <?php if ($row === null || $row['amount'] === null): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <?= VendorFit::money_text((float) $row['amount']); ?>
                                            <?php if ($row['status'] === 'lifted'): ?>
                                                <div class="text-muted" style="font-size:10.5px; font-weight:400;">your floor</div>
                                            <?php elseif ($row['status'] === 'same'): ?>
                                                <div class="text-muted" style="font-size:10.5px; font-weight:400;">unchanged</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right mono" style="color:#64748B;">
                                        <?php if ($row !== null && $row['status'] === 'skipped' && $row['current'] !== null): ?>
                                            <?= VendorFit::money_text((float) $row['current']); ?>
                                            <div class="text-muted" style="font-size:10.5px;">kept — left out</div>
                                        <?php elseif (isset($prefs[$slug]) && $prefs[$slug]->budget_max !== null && (float) $prefs[$slug]->budget_max > 0): ?>
                                            <?= VendorFit::money_text((float) $prefs[$slug]->budget_max); ?>
                                        <?php else: ?>
                                            <span class="text-muted">no cap</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="text-muted mb-2" style="font-size:11.5px;">
                        Shares are used relative to each other, so they do not have to add up to 100 — the amounts below are what counts.
                        Caps are placed in whole KSh <?= number_format(VendorFit::SPLIT_STEP); ?> blocks, so a total that is not a multiple of
                        that cannot be placed to the last shilling and the leftover is reported.
                    </p>

                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="mdi mdi-eye-outline"></i> Preview the split
                    </button>
                </form>

                <?php if ($split_plan !== null && $split_plan['error'] === ''): ?>
                    <hr>
                    <div class="d-flex justify-content-between align-items-end flex-wrap">
                        <div>
                            <div class="pref-label">This split places</div>
                            <div class="mono" style="font-size:18px; font-weight:700; color:#0F172A;">
                                <?= VendorFit::money_text((float) $split_plan['planned']); ?>
                                <span class="text-muted" style="font-size:12.5px; font-weight:400;">of <?= VendorFit::money_text((float) $split_plan['total']); ?></span>
                            </div>
                            <div class="text-muted" style="font-size:11.5px;">
                                across <?= (int) $split_plan['active']; ?> categor<?= $split_plan['active'] === 1 ? 'y' : 'ies'; ?>
                                <?php if (abs((float) $split_plan['unplaced']) >= 0.5): ?>
                                    <?php if ($split_plan['unplaced'] > 0): ?>
                                        · <?= VendorFit::money_text((float) $split_plan['unplaced']); ?> left unplaced (smaller than one block)
                                    <?php else: ?>
                                        · <?= VendorFit::money_text(abs((float) $split_plan['unplaced'])); ?> OVER, because a floor you set is higher than its share
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($split_plan['resulting'] > 0 && abs($split_plan['resulting'] - $split_plan['planned']) >= 0.5): ?>
                                    · all caps would total <?= VendorFit::money_text((float) $split_plan['resulting']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-2">
                            <?php if ($plan_changes === 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                    <i class="mdi mdi-check"></i> These caps are already in place
                                </button>
                            <?php else: ?>
                                <form method="post" onsubmit="return confirm('Replace the caps of <?= (int) $plan_changes; ?> categor<?= $plan_changes === 1 ? 'y' : 'ies'; ?> with this split? Your taste tags and floors stay as they are.');">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="action" value="split_apply">
                                    <input type="hidden" name="total" value="<?= htmlspecialchars((string) $split_total); ?>">
                                    <?php foreach ($split_shares as $slug => $value): ?>
                                        <input type="hidden" name="shares[<?= htmlspecialchars((string) $slug); ?>]" value="<?= htmlspecialchars((string) $value); ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="mdi mdi-content-save"></i> Apply these caps (<?= (int) $plan_changes; ?>)
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="text-muted mb-0 mt-2" style="font-size:11.5px;">
                        Previewing saves nothing. Applying changes only the caps — every taste tag, floor and note you have written stays exactly as it is.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <p class="text-muted" style="font-size:12.5px;">
            Tags are your own words — <em>rustic, halal, outdoor, live band</em>. The marketplace matches them against the tags a vendor put on
            each service, and compares the price with your cap using the unit it is quoted in. Where a total cannot be worked out honestly
            (per hour, per km, or a quantity the vendor never stated) you will see <strong>Quote needed</strong> instead of an invented number.
        </p>

        <div class="row">
            <?php foreach ($categories as $slug => $meta):
                [$label, $icon] = $meta;
                $pref = $prefs[$slug] ?? null;
                $tags = $pref !== null ? VendorFit::tag_list((string) $pref->taste_tags) : [];
            ?>
                <div class="col-md-6 col-xl-4">
                    <div class="pref-card <?= $pref !== null ? 'is-set' : ''; ?>" id="<?= htmlspecialchars($slug); ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6><i class="mdi mdi-<?= htmlspecialchars($icon); ?>"></i> <?= htmlspecialchars($label); ?></h6>
                                <div class="pref-summary">
                                    <?php if ($pref === null): ?>
                                        No preference yet
                                    <?php elseif ($pref->budget_max !== null && (float) $pref->budget_max > 0): ?>
                                        <span class="mono"><?= VendorFit::money_text((float) $pref->budget_max); ?></span> cap
                                    <?php else: ?>
                                        Taste only
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($pref !== null): ?>
                                <form method="post" class="ml-2" onsubmit="return confirm('Clear your <?= htmlspecialchars(mb_strtolower($label)); ?> preference?');">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="action" value="clear">
                                    <input type="hidden" name="category" value="<?= htmlspecialchars($slug); ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-muted" title="Clear this preference" style="font-size:11px;">clear</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if ($pref !== null && ($tags !== [] || trim((string) $pref->notes) !== '')): ?>
                            <div class="mb-1">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="pref-chip"><?= htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if (trim((string) $pref->notes) !== ''): ?>
                                <div class="text-muted mb-1" style="font-size:11.5px;">“<?= htmlspecialchars((string) $pref->notes); ?>”</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <form method="post" class="mt-2">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="category" value="<?= htmlspecialchars($slug); ?>">
                            <div class="form-group mb-2">
                                <label class="pref-label mb-1">Taste tags</label>
                                <input type="text" name="taste_tags" class="form-control form-control-sm" maxlength="255"
                                       value="<?= htmlspecialchars((string) ($pref->taste_tags ?? '')); ?>"
                                       placeholder="rustic, halal, outdoor">
                            </div>
                            <div class="form-row">
                                <div class="form-group col-6 mb-2">
                                    <label class="pref-label mb-1">Floor (KSh)</label>
                                    <input type="number" name="budget_min" class="form-control form-control-sm mono" min="0" step="1"
                                           value="<?= $pref !== null && $pref->budget_min !== null ? (int) $pref->budget_min : ''; ?>"
                                           placeholder="optional">
                                </div>
                                <div class="form-group col-6 mb-2">
                                    <label class="pref-label mb-1">Cap (KSh)</label>
                                    <input type="number" name="budget_max" class="form-control form-control-sm mono" min="0" step="1"
                                           value="<?= $pref !== null && $pref->budget_max !== null ? (int) $pref->budget_max : ''; ?>"
                                           placeholder="e.g. 250000">
                                </div>
                            </div>
                            <div class="form-group mb-2">
                                <label class="pref-label mb-1">A note for your planner</label>
                                <input type="text" name="notes" class="form-control form-control-sm" maxlength="500"
                                       value="<?= htmlspecialchars((string) ($pref->notes ?? '')); ?>"
                                       placeholder="e.g. must be halal, no pork">
                            </div>
                            <button type="submit" class="btn btn-sm btn-<?= $pref !== null ? 'outline-success' : 'success'; ?> btn-block">
                                <i class="mdi mdi-content-save"></i> <?= $pref !== null ? 'Update' : 'Save'; ?> <?= htmlspecialchars(mb_strtolower($label)); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
