<?php include 'include/init.php'; ?><?php require_staff_subscribed(); ?><?php
/**
 * One proposal, end to end (v2.30): the couple's brief, the planner's
 * acknowledgement, AT LEAST THREE priced options built from real vendor
 * offerings, and the lock that turns the chosen one into vendor deals.
 *
 * WHY THE OPTIONS ARE BUILT HERE AND NOT ON THE VENDOR PLAN PAGE
 * The vendor plan answers "who is doing this wedding and what does it cost" for
 * a wedding the planner has already decided. A proposal is the decision itself:
 * three different answers at three prices, sent to the couple as one question.
 * The builder therefore reuses the plan's picker and quote (`VendorPlan::
 * offerings()`, `quote()`) so the figures agree to the shilling, but stores the
 * options in tblproposal_options/tblproposal_items — a QUOTE the couple was
 * shown, not a live sum that changes under them.
 *
 * WHAT EACH ACTION IS
 *   acknowledge  claim the proposal and tell the couple it has been seen
 *   add_option   name an option, then fill it with vendors
 *   publish      send the options; refused below three priced options
 *   lock         after the couple chooses: re-check availability, create the
 *                deals through VendorPlan::add (so vendors are notified), and
 *                confirm the wedding. One transaction: nothing half-happens.
 * Every action is a POST + CSRF and re-checks ownership. A GET writes nothing.
 */
    if (!Proposal::installed()) {
        $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-database-alert"></i></strong> The proposal tables are not installed. Apply <code>migrations/v2.30_proposals.sql</code>.</div>');
        redirect_to('client.php');
        die();
    }

    $proposal_id = (int) ($_GET['id'] ?? 0);
    $proposal    = Proposal::find($proposal_id);
    if ($proposal === null || !staff_may_open_proposal($proposal)) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-lock"></i></strong> That proposal is not yours to open.</div>');
        redirect_to('proposals.php');
        die();
    }

    $self = 'proposal_view.php?id=' . $proposal_id;
    $me   = (int) ($_SESSION['id'] ?? 0);

    // The picker, loaded once: the builder's form and the POST handler read the
    // same map, so what is displayed and what is bought cannot come apart.
    $picker = VendorPlan::offerings();
    $offering_map = [];
    $vendor_map   = [];
    foreach ($picker as $group) {
        $vendor_map[(int) $group['vendor_id']] = $group['vendor'];
        foreach ($group['offerings'] as $offering) {
            $offering_map[(int) $group['vendor_id'] . ':' . $offering['kind'] . ':' . (int) $offering['id']] = $offering;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to($self);
            die();
        }

        $action = (string) ($_POST['action'] ?? '');
        $notice = '';
        $failed = '';

        if ($action === 'acknowledge') {
            $result = Proposal::acknowledge($proposal_id, $me);
            $result['ok']
                ? $notice = 'Proposal picked up — the couple has been told you are on it.'
                : $failed = $result['error'];
        } elseif ($action === 'add_option') {
            $result = Proposal::create_option(
                $proposal_id,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['tier'] ?? ''),
                (string) ($_POST['summary'] ?? ''),
                $me
            );
            $result['ok'] ? $notice = 'Option added — now put the vendors in it.' : $failed = $result['error'];
        } elseif ($action === 'update_option') {
            $result = Proposal::update_option(
                $proposal_id,
                (int) ($_POST['option_id'] ?? 0),
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['tier'] ?? ''),
                (string) ($_POST['summary'] ?? '')
            );
            $result['ok'] ? $notice = 'Option updated.' : $failed = $result['error'];
        } elseif ($action === 'add_item') {
            $option_id = (int) ($_POST['option_id'] ?? 0);
            // "kind:id:vendor[:tier]" — the tier is the fourth part when the
            // planner picked a priced level rather than the vendor's plain price.
            $choice    = explode(':', (string) ($_POST['offering'] ?? ''), 4);
            $kind      = (string) ($choice[0] ?? '');
            $offering_id = (int) ($choice[1] ?? 0);
            $vendor_id   = (int) ($choice[2] ?? 0);
            $tier        = VendorTier::normalise_tier($choice[3] ?? null);
            $key = $vendor_id . ':' . $kind . ':' . $offering_id;
            $offering = $offering_map[$key] ?? null;
            if ($offering !== null) {
                $offering['tier'] = $tier;
            }

            if ($offering === null || $vendor_id <= 0) {
                $failed = 'Choose a vendor and one of their offerings.';
            } else {
                $posted_qty = $_POST['qty'] ?? null;
                $posted_amt = $_POST['amount'] ?? null;
                $qty    = is_numeric($posted_qty) && (int) $posted_qty > 0 ? (int) $posted_qty : null;
                $amount = is_numeric($posted_amt) ? (float) $posted_amt : 0.0;
                $result = Proposal::add_item(
                    $proposal_id,
                    $option_id,
                    $vendor_id,
                    $offering,
                    $qty,
                    $amount,
                    (string) ($_POST['note'] ?? ''),
                    (string) ($_POST['label'] ?? '')
                );
                $result['ok']
                    ? $notice = $result['label'] . ' added at ' . format_ksh((float) $result['amount']) . '.'
                    : $failed = $result['error'];
            }
        } elseif ($action === 'add_custom') {
            $vendor_id = (int) ($_POST['vendor_id'] ?? 0);
            $posted_amt = $_POST['amount'] ?? null;
            $result = Proposal::add_item(
                $proposal_id,
                (int) ($_POST['option_id'] ?? 0),
                $vendor_id,
                ['kind' => 'custom', 'id' => 0, 'price' => 0.0, 'unit' => ''],
                null,
                is_numeric($posted_amt) ? (float) $posted_amt : 0.0,
                (string) ($_POST['note'] ?? ''),
                (string) ($_POST['label'] ?? '')
            );
            $result['ok']
                ? $notice = $result['label'] . ' added at ' . format_ksh((float) $result['amount']) . '.'
                : $failed = $result['error'];
        } elseif ($action === 'remove_item') {
            $result = Proposal::remove_item($proposal_id, (int) ($_POST['item_id'] ?? 0));
            $result['ok'] ? $notice = 'Line removed.' : $failed = $result['error'];
        } elseif ($action === 'delete_option') {
            $result = Proposal::delete_option($proposal_id, (int) ($_POST['option_id'] ?? 0));
            $result['ok'] ? $notice = 'Option deleted.' : $failed = $result['error'];
        } elseif ($action === 'publish') {
            $result = Proposal::publish($proposal_id, $me);
            $result['ok'] ? $notice = 'Options sent — the couple can choose now.' : $failed = $result['error'];
        } elseif ($action === 'lock') {
            $result = Proposal::lock($proposal_id, $me, isset($_POST['acknowledge_clash']));
            if ($result['ok']) {
                $notice = 'Confirmed: ' . $result['deals'] . ' vendor deal' . ($result['deals'] === 1 ? '' : 's')
                    . ' created for ' . format_ksh((float) $result['total'])
                    . '. The couple has been told, and each vendor sees the enquiry.';
                foreach ($result['warnings'] as $warning) {
                    $notice .= ' <span class="text-warning"><i class="mdi mdi-alert"></i> ' . htmlspecialchars($warning) . '</span>';
                }
            } else {
                $failed = $result['error'];
            }
        } else {
            $failed = 'Nothing to do.';
        }

        $session->message($failed !== ''
            ? '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($failed) . '</div>'
            : '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> ' . $notice . '</div>');
        redirect_to($self);
        die();
    }

    // Re-read after any write (this is a GET at this point).
    $proposal = Proposal::find($proposal_id);
    $options  = Proposal::options($proposal_id);
    [$status_label, $status_tone, $status_icon] = Proposal::status_meta((string) $proposal->status);
    $open_for_edit = !in_array((string) $proposal->status, ['locked', 'declined'], true);
    $priced = 0;
    foreach ($options as $option) {
        if ((float) $option->total > 0 && $option->items !== []) {
            $priced++;
        }
    }
    $chosen = null;
    foreach ($options as $option) {
        if ((int) $option->is_chosen === 1) {
            $chosen = $option;
        }
    }
    $couple_name = Proposal::couple_name($proposal);
    $wedding_human = !empty($proposal->wedding_date) ? format_date_kd((string) $proposal->wedding_date) : '';
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Proposal - <?= htmlspecialchars($couple_name); ?></title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .prop-tile { background:#fff; border-radius:10px; padding:14px 18px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .prop-tile .label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748B; }
        .prop-tile .value { font-size:20px; font-weight:700; color:#0F172A; font-variant-numeric:tabular-nums; }
        .hint { font-size:11.5px; color:#64748B; }
        .mono { font-variant-numeric: tabular-nums; }
        .opt-card { background:#fff; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; border-left:4px solid #CBD5E1; }
        .opt-card.chosen { border-left-color:var(--success); }
        .opt-card h6 { font-weight:700; }
        .tier { display:inline-block; border-radius:999px; padding:1px 9px; font-size:10.5px; font-weight:600; background:#E0F2FE; color:#075985; }
        .line-table td { font-size:12px; vertical-align:middle; }
        .step { display:inline-block; font-size:11px; padding:3px 10px; border-radius:999px; background:#F1F5F9; color:#475569; margin:0 4px 4px 0; }
        .step.done { background:#D1FAE5; color:#065F46; }
        .step.current { background:#0E7490; color:#fff; font-weight:600; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap" style="border-bottom:1px solid #dee2e6; padding-bottom:12px;">
        <div>
            <h4 class="mb-1">
                <i class="mdi mdi-file-document-edit-outline" style="color:#0E7490;"></i>
                <?= htmlspecialchars($couple_name); ?>
                <span class="badge badge-<?= htmlspecialchars($status_tone); ?>" style="font-size:11px; vertical-align:middle;">
                    <i class="mdi mdi-<?= htmlspecialchars($status_icon); ?>"></i> <?= htmlspecialchars($status_label); ?>
                </span>
            </h4>
            <p class="text-muted mb-0" style="font-size:13px;">
                <?= $wedding_human !== '' ? htmlspecialchars($wedding_human) . ' · ' : ''; ?>
                <?= Proposal::planner_name($proposal) !== '' ? 'Planner: ' . htmlspecialchars(Proposal::planner_name($proposal)) : 'Unclaimed'; ?>
            </p>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-secondary mr-1" href="proposals.php"><i class="mdi mdi-arrow-left mr-1"></i> Proposals</a>
            <a class="btn btn-sm btn-outline-info mr-1" href="client_manage_account_details.php?booking_id=<?= (int) $proposal->booking_id; ?>&user_id=<?= (int) $proposal->couple_id; ?>">
                <i class="mdi mdi-eye mr-1"></i> Wedding
            </a>
            <a class="btn btn-sm btn-outline-success" href="vendor_plan.php?booking_id=<?= (int) $proposal->booking_id; ?>&user_id=<?= (int) $proposal->couple_id; ?>">
                <i class="mdi mdi-clipboard-text-outline mr-1"></i> Vendor plan
            </a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="mb-3" style="font-size:11px;">
        <?php foreach (Proposal::steps($proposal) as $step): ?>
            <span class="step <?= $step['done'] ? 'done' : ''; ?> <?= $step['current'] ? 'current' : ''; ?>"><?= htmlspecialchars($step['label']); ?></span>
        <?php endforeach; ?>
    </div>

    <div class="row mb-3">
        <div class="col-md-3 mb-2">
            <div class="prop-tile">
                <div class="label">Their budget</div>
                <div class="value"><?= (float) ($proposal->budget ?? 0) > 0 ? format_ksh((float) $proposal->budget) : '—'; ?></div>
                <div class="hint"><?= (int) ($proposal->guests ?? 0) > 0 ? (int) $proposal->guests . ' guests' : 'headcount not stated'; ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="prop-tile">
                <div class="label">Options ready</div>
                <div class="value"><?= $priced; ?> / <?= (int) Proposal::MIN_OPTIONS; ?></div>
                <div class="hint"><?= $priced >= Proposal::MIN_OPTIONS ? 'enough to send' : 'at least three are needed'; ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="prop-tile">
                <div class="label">Chosen option</div>
                <div class="value" style="font-size:15px;"><?= $chosen !== null ? htmlspecialchars((string) $chosen->title) : '—'; ?></div>
                <div class="hint"><?= $chosen !== null ? format_ksh((float) $chosen->total) : 'the couple decides'; ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="prop-tile">
                <div class="label">Waiting</div>
                <div class="value" style="font-size:16px;"><?= htmlspecialchars(Proposal::waiting_for($proposal)); ?></div>
                <div class="hint">since the last move</div>
            </div>
        </div>
    </div>

    <div class="card mb-3" style="border-radius:10px;">
        <div class="card-body">
            <h6 class="mb-2" style="font-weight:700;"><i class="mdi mdi-account-heart" style="color:#0E7490;"></i> Their brief</h6>
            <div class="row" style="font-size:12.5px;">
                <div class="col-md-4">
                    <div class="hint">Venue or town</div>
                    <div><?= trim((string) ($proposal->venue ?? '')) !== '' ? htmlspecialchars((string) $proposal->venue) : '<span class="text-muted">not stated</span>'; ?></div>
                </div>
                <div class="col-md-4">
                    <div class="hint">Style they love</div>
                    <div><?= trim((string) ($proposal->taste_tags ?? '')) !== '' ? htmlspecialchars((string) $proposal->taste_tags) : '<span class="text-muted">not stated</span>'; ?></div>
                </div>
                <div class="col-md-4">
                    <div class="hint">In their words</div>
                    <div><?= trim((string) ($proposal->couple_note ?? '')) !== '' ? htmlspecialchars((string) $proposal->couple_note) : '<span class="text-muted">—</span>'; ?></div>
                </div>
            </div>
            <?php if (trim((string) ($proposal->tweak_note ?? '')) !== ''): ?>
                <div class="alert alert-info mt-3 mb-0" style="font-size:12.5px;">
                    <i class="mdi mdi-pencil"></i> <strong>They asked for a change:</strong> <?= htmlspecialchars((string) $proposal->tweak_note); ?>
                </div>
            <?php endif; ?>
            <?php if (trim((string) ($proposal->decline_note ?? '')) !== '' && (string) $proposal->status === 'declined'): ?>
                <div class="alert alert-secondary mt-3 mb-0" style="font-size:12.5px;">
                    <i class="mdi mdi-close-circle-outline"></i> <strong>They declined:</strong> <?= htmlspecialchars((string) $proposal->decline_note); ?>
                </div>
            <?php endif; ?>

            <?php if ((string) $proposal->status === 'proposal'): ?>
                <form method="post" class="mt-3">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="action" value="acknowledge">
                    <button class="btn btn-sm btn-success"><i class="mdi mdi-account-check mr-1"></i> Acknowledge this couple</button>
                    <span class="hint ml-2">They are told immediately that a planner has picked their wedding up.</span>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ((string) $proposal->status === 'chosen'): ?>
        <div class="card mb-3" style="border-radius:10px; border-left:4px solid #059669;">
            <div class="card-body">
                <h6 class="mb-1" style="font-weight:700;"><i class="mdi mdi-hand-okay" style="color:#059669;"></i> The couple chose <?= $chosen !== null ? htmlspecialchars((string) $chosen->title) : 'an option'; ?></h6>
                <p class="hint mb-2">
                    Confirming re-checks every vendor's calendar, creates one pending deal per line (the vendors are notified),
                    and marks the wedding confirmed. Nothing is written if any line fails.
                </p>
                <form method="post" onsubmit="return confirm('Confirm this wedding and create the vendor deals?');">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="action" value="lock">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="acknowledge_clash" id="acknowledge_clash" value="1">
                        <label class="form-check-label hint" for="acknowledge_clash">
                            Book them even if a vendor is already working another wedding that day (the clash is recorded on the deal)
                        </label>
                    </div>
                    <button class="btn btn-sm btn-success"><i class="mdi mdi-lock-check mr-1"></i> Confirm and create the deals</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ((string) $proposal->status === 'locked'): ?>
        <div class="alert alert-success" style="font-size:13px;">
            <i class="mdi mdi-lock-check"></i>
            Confirmed on <?= htmlspecialchars(date('j M Y', strtotime((string) $proposal->locked_at))); ?>.
            The deals are on the vendor plan and in the enquiry ledger.
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
        <h5 class="mb-0"><i class="mdi mdi-format-list-checks" style="color:#0E7490;"></i> Options sent to the couple</h5>
        <?php if ($open_for_edit && in_array((string) $proposal->status, ['acknowledged', 'options', 'tweak_requested'], true)): ?>
            <form method="post" class="mb-0">
                <?= $session->csrf_field(); ?>
                <input type="hidden" name="action" value="publish">
                <button class="btn btn-sm btn-primary" <?= $priced < Proposal::MIN_OPTIONS ? 'disabled title="At least three priced options are needed"' : ''; ?>>
                    <i class="mdi mdi-send mr-1"></i> <?= (string) $proposal->status === 'options' ? 'Re-send the options' : 'Send the options to the couple'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($options === []): ?>
        <div class="alert alert-light border" style="font-size:13px;">
            No options yet. Name one below, add the vendors it is made of, then add two more — a couple must be able to choose.
        </div>
    <?php endif; ?>

    <?php foreach ($options as $option): ?>
        <div class="opt-card <?= (int) $option->is_chosen === 1 ? 'chosen' : ''; ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div>
                        <h6 class="mb-1">
                            <?= htmlspecialchars((string) $option->title); ?>
                            <span class="tier"><?= htmlspecialchars((string) $option->tier); ?></span>
                            <?php if ((int) $option->is_chosen === 1): ?>
                                <span class="badge badge-success" style="font-size:10px;">Chosen by the couple</span>
                            <?php endif; ?>
                        </h6>
                        <?php if (trim((string) $option->summary) !== ''): ?>
                            <div class="hint mb-1"><?= htmlspecialchars((string) $option->summary); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <div class="mono" style="font-size:18px; font-weight:700;"><?= format_ksh((float) $option->items_total); ?></div>
                        <div class="hint"><?= count($option->items); ?> line<?= count($option->items) === 1 ? '' : 's'; ?></div>
                    </div>
                </div>

                <?php if ($option->items !== []): ?>
                    <table class="table table-sm line-table mt-2 mb-2">
                        <thead>
                            <tr><th>Vendor</th><th>What</th><th class="text-right">Amount</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($option->items as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string) $item->business_name); ?></strong><div class="hint"><?= htmlspecialchars(ucfirst((string) $item->kind)); ?></div></td>
                                <td>
                                    <?= htmlspecialchars((string) $item->label); ?>
                                    <?php if (VendorTier::label($item->tier ?? null) !== ''): ?>
                                        <span class="badge badge-light" style="font-size:9px; border:1px solid #E2E8F0;" title="The tier this line was priced at">
                                            <i class="mdi mdi-layers-triple-outline"></i> <?= htmlspecialchars(VendorTier::label((string) $item->tier)); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($item->service_qty !== null): ?>
                                        <div class="hint">× <?= (int) $item->service_qty; ?></div>
                                    <?php endif; ?>
                                    <?php if (trim((string) $item->note) !== ''): ?>
                                        <div class="hint"><?= htmlspecialchars((string) $item->note); ?></div>
                                    <?php endif; ?>
                                    <?php if ((int) ($item->deal_id ?? 0) > 0): ?>
                                        <div class="hint text-success"><i class="mdi mdi-check"></i> deal #<?= (int) $item->deal_id; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right mono"><?= format_ksh((float) $item->amount); ?></td>
                                <td class="text-right">
                                    <?php if ($open_for_edit && (int) ($item->deal_id ?? 0) === 0): ?>
                                        <form method="post" class="mb-0">
                                            <?= $session->csrf_field(); ?>
                                            <input type="hidden" name="action" value="remove_item">
                                            <input type="hidden" name="item_id" value="<?= (int) $item->id; ?>">
                                            <button class="btn btn-sm btn-link text-danger p-0" title="Remove this line"><i class="mdi mdi-close"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($open_for_edit): ?>
                    <form method="post" class="form-row align-items-end mb-2">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="action" value="add_item">
                        <input type="hidden" name="option_id" value="<?= (int) $option->id; ?>">
                        <div class="form-group col-lg-5 mb-1">
                            <label class="hint font-weight-bold mb-1">Vendor and offering</label>
                            <select name="offering" class="form-control form-control-sm" required>
                                <option value="">Choose…</option>
                                <?php foreach (VendorCoverage::categories() as $slug => $meta):
                                    $groups = array_values(array_filter($picker, static fn (array $g): bool => $g['category'] === $slug && $g['offerings'] !== []));
                                    if ($groups === []) {
                                        continue;
                                    }
                                ?>
                                    <optgroup label="<?= htmlspecialchars($meta[0]); ?>">
                                        <?php foreach ($groups as $group): ?>
                                            <?php foreach ($group['offerings'] as $offering): ?>
                                                <?php $tiers = is_array($offering['tiers'] ?? null) ? $offering['tiers'] : []; ?>
                                                <?php if ($tiers === []): ?>
                                                    <option value="<?= htmlspecialchars($offering['kind'] . ':' . (int) $offering['id'] . ':' . (int) $group['vendor_id']); ?>">
                                                        <?= htmlspecialchars($group['vendor'] . ' — ' . $offering['label'] . ' — ' . $offering['price_line']); ?>
                                                    </option>
                                                <?php else: ?>
                                                    <?php foreach ($tiers as $tier_slug => $tier_row): ?>
                                                        <option value="<?= htmlspecialchars($offering['kind'] . ':' . (int) $offering['id'] . ':' . (int) $group['vendor_id'] . ':' . (string) $tier_slug); ?>">
                                                            <?= htmlspecialchars($group['vendor'] . ' — ' . $offering['label'] . ' — ' . VendorTier::label((string) $tier_slug) . ' ' . format_ksh((float) $tier_row->price)); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-lg-2 col-md-3 mb-1">
                            <label class="hint font-weight-bold mb-1">Quantity</label>
                            <input type="number" name="qty" class="form-control form-control-sm mono" min="1" step="1" placeholder="guests / hours">
                        </div>
                        <div class="form-group col-lg-2 col-md-3 mb-1">
                            <label class="hint font-weight-bold mb-1">Agreed amount</label>
                            <input type="number" name="amount" class="form-control form-control-sm mono" min="0" step="1" placeholder="KSh (optional)">
                        </div>
                        <div class="form-group col-lg-2 col-md-4 mb-1">
                            <label class="hint font-weight-bold mb-1">Note</label>
                            <input type="text" name="note" class="form-control form-control-sm" maxlength="255" placeholder="halal menu">
                        </div>
                        <div class="form-group col-lg-1 col-md-2 mb-1">
                            <button class="btn btn-sm btn-success btn-block"><i class="mdi mdi-plus"></i></button>
                        </div>
                    </form>

                    <form method="post" class="form-row align-items-end mb-2">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="action" value="add_custom">
                        <input type="hidden" name="option_id" value="<?= (int) $option->id; ?>">
                        <div class="form-group col-lg-3 mb-1">
                            <label class="hint font-weight-bold mb-1">Custom line — vendor</label>
                            <select name="vendor_id" class="form-control form-control-sm" required>
                                <option value="">Choose…</option>
                                <?php foreach ($vendor_map as $vid => $vname): ?>
                                    <option value="<?= (int) $vid; ?>"><?= htmlspecialchars((string) $vname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-lg-3 mb-1">
                            <label class="hint font-weight-bold mb-1">What it is</label>
                            <input type="text" name="label" class="form-control form-control-sm" maxlength="160" placeholder="Negotiated garden setup" required>
                        </div>
                        <div class="form-group col-lg-2 col-md-4 mb-1">
                            <label class="hint font-weight-bold mb-1">Amount</label>
                            <input type="number" name="amount" class="form-control form-control-sm mono" min="1" step="1" required>
                        </div>
                        <div class="form-group col-lg-3 col-md-6 mb-1">
                            <label class="hint font-weight-bold mb-1">Note</label>
                            <input type="text" name="note" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="form-group col-lg-1 col-md-2 mb-1">
                            <button class="btn btn-sm btn-outline-success btn-block" title="Add an arrangement that is not in the vendor's catalogue"><i class="mdi mdi-plus"></i></button>
                        </div>
                    </form>

                    <details class="mb-1">
                        <summary class="hint" style="cursor:pointer;">Rename this option, change its tier or summary</summary>
                        <form method="post" class="form-row align-items-end mt-2">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="update_option">
                            <input type="hidden" name="option_id" value="<?= (int) $option->id; ?>">
                            <div class="form-group col-lg-4 mb-1">
                                <input type="text" name="title" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $option->title); ?>" maxlength="160" required>
                            </div>
                            <div class="form-group col-lg-2 mb-1">
                                <input type="text" name="tier" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $option->tier); ?>" maxlength="40">
                            </div>
                            <div class="form-group col-lg-5 mb-1">
                                <input type="text" name="summary" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $option->summary); ?>" maxlength="500">
                            </div>
                            <div class="form-group col-lg-1 mb-1">
                                <button class="btn btn-sm btn-outline-primary btn-block"><i class="mdi mdi-check"></i></button>
                            </div>
                        </form>
                    </details>

                    <?php if ((int) $option->is_chosen === 0): ?>
                        <form method="post" class="mb-0" onsubmit="return confirm('Delete this option and everything in it?');">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_option">
                            <input type="hidden" name="option_id" value="<?= (int) $option->id; ?>">
                            <button class="btn btn-sm btn-link text-danger p-0" style="font-size:11.5px;"><i class="mdi mdi-trash-can-outline"></i> Delete this option</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($open_for_edit && in_array((string) $proposal->status, ['acknowledged', 'options', 'tweak_requested'], true)): ?>
        <div class="card mb-4" style="border-radius:10px;">
            <div class="card-body">
                <h6 class="mb-1" style="font-weight:700;"><i class="mdi mdi-plus-circle-outline" style="color:#0E7490;"></i> Add another option</h6>
                <p class="hint mb-2">A couple must be given at least <?= (int) Proposal::MIN_OPTIONS; ?> priced options — an essential, a recommended and a premium is the usual shape.</p>
                <form method="post" class="form-row align-items-end">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="action" value="add_option">
                    <div class="form-group col-lg-4 mb-1">
                        <label class="hint font-weight-bold mb-1">Name the couple will see</label>
                        <input type="text" name="title" class="form-control form-control-sm" maxlength="160" placeholder="The Garden Wedding" required>
                    </div>
                    <div class="form-group col-lg-2 mb-1">
                        <label class="hint font-weight-bold mb-1">Tier</label>
                        <select name="tier" class="form-control form-control-sm">
                            <option value="essential">Essential</option>
                            <option value="recommended" selected>Recommended</option>
                            <option value="premium">Premium</option>
                        </select>
                    </div>
                    <div class="form-group col-lg-5 mb-1">
                        <label class="hint font-weight-bold mb-1">What it buys, in one paragraph</label>
                        <input type="text" name="summary" class="form-control form-control-sm" maxlength="500" placeholder="Church ceremony, garden reception, buffet for 120">
                    </div>
                    <div class="form-group col-lg-1 mb-1">
                        <button class="btn btn-sm btn-success btn-block"><i class="mdi mdi-plus"></i></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
