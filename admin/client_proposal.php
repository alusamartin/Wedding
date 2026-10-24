<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?><?php
/**
 * The couple's proposal page (v2.30): their planner's priced options, side by
 * side, and the one decision that is theirs.
 *
 * WHY IT LOOKS LIKE THIS
 * The marketplace shows what they could buy and the vendor plan shows what
 * their planner has already chosen. This is the moment in between: three
 * answers to "what will our wedding be, and what does it cost", each one priced
 * from real vendors, and a single click to choose. Choosing is the couple's;
 * locking the wedding is the planner's, so this page never writes a deal.
 *
 * WHAT IT WRITES: the couple's own decision — choose, ask for a change, or
 * decline — each POST + CSRF, and only by the couple's own session. A planner
 * opening this page through the preview path sees exactly what the couple sees,
 * with no buttons, so a preview can never answer a question on their behalf.
 */
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id    = (int) ($_GET['user_id'] ?? 0);
    $links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;
    $self       = 'client_proposal.php?' . $links;

    if (!isset($_SESSION['id'])) {
        redirect_to('../');
        die();
    }
    if (!Proposal::installed()) {
        $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-database-alert"></i></strong> Your options are not available yet — ask your planner.</div>');
        redirect_to('client.php');
        die();
    }

    $proposal = Proposal::find_by_booking($booking_id);
    $booking  = Booking::find_by_booking_id($booking_id);
    /*
     * The shared couple rule decides the wedding, and two proposal-only
     * questions finish the job: is this proposal actually for that booking,
     * and — for a staff session on the documented preview path — is it one
     * that planner may manage? Without the second, a planner previewing
     * somebody else's wedding would read the couple's budget here. Written out
     * rather than wrapped, so this page names the same rule every other couple
     * page names and tests/CoupleOwnershipRuleTest.php can see it.
     */
    if ($proposal === null
        || (int) $proposal->booking_id !== $booking_id
        || !may_open_couple_page($booking, $user_id)
        || (is_staff() && !staff_may_open_proposal($proposal))) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-lock"></i></strong> That proposal is not yours to open.</div>');
        redirect_to('client.php');
        die();
    }

    // Only the couple answers for the couple. A planner's preview is read-only.
    $can_act = is_client_account() && (int) ($_SESSION['id'] ?? 0) === $user_id;
    $proposal_id = (int) $proposal->id;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_act) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to($self);
            die();
        }
        $action = (string) ($_POST['action'] ?? '');
        $notice = '';
        $failed = '';

        if ($action === 'choose') {
            $result = Proposal::choose($proposal_id, (int) ($_POST['option_id'] ?? 0));
            $result['ok']
                ? $notice = 'Thank you — your planner has been told. They will confirm the vendors and you will see the deals here.'
                : $failed = $result['error'];
        } elseif ($action === 'tweak') {
            $result = Proposal::request_tweak($proposal_id, (string) ($_POST['note'] ?? ''));
            $result['ok']
                ? $notice = 'Sent — your planner will come back with a changed option.'
                : $failed = $result['error'];
        } elseif ($action === 'decline') {
            $result = Proposal::decline($proposal_id, (string) ($_POST['note'] ?? ''));
            $result['ok']
                ? $notice = 'Your planner has been told. You can still talk to them about a different plan.'
                : $failed = $result['error'];
        } else {
            $failed = 'Nothing to do.';
        }

        $session->message($failed !== ''
            ? '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($failed) . '</div>'
            : '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> ' . htmlspecialchars($notice) . '</div>');
        redirect_to($self);
        die();
    }

    $proposal = Proposal::find($proposal_id);
    $options  = Proposal::options($proposal_id);
    $chosen   = null;
    foreach ($options as $option) {
        if ((int) $option->is_chosen === 1) {
            $chosen = $option;
        }
    }

    $status       = (string) $proposal->status;
    $can_choose   = $can_act && in_array($status, ['options', 'tweak_requested'], true);
    $couple_name  = Proposal::couple_name($proposal);
    $wedding_human = !empty($proposal->wedding_date) ? format_date_kd((string) $proposal->wedding_date) : '';
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Your wedding options - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { background:#f8f9fa; }
        .step { display:inline-block; font-size:11px; padding:3px 10px; border-radius:999px; background:#F1F5F9; color:#475569; margin:0 4px 4px 0; }
        .step.done { background:#D1FAE5; color:#065F46; }
        .step.current { background:#0E7490; color:#fff; font-weight:600; }
        .opt-card { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.07); margin-bottom:18px; overflow:hidden; border:1px solid #E2E8F0; }
        .opt-card.chosen { border-color:#059669; box-shadow:0 2px 14px rgba(5,150,105,.15); }
        .opt-head { padding:16px 20px 8px; }
        .opt-head .tier { display:inline-block; border-radius:999px; padding:2px 10px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; background:#E0F2FE; color:#075985; }
        .opt-total { font-size:24px; font-weight:800; color:#0F172A; font-variant-numeric:tabular-nums; }
        .opt-list { padding:0 20px 6px; }
        .opt-list li { font-size:12.5px; padding:5px 0; border-bottom:1px dashed #E2E8F0; list-style:none; }
        .opt-list li:last-child { border-bottom:0; }
        .hint { font-size:11.5px; color:#64748B; }
        .chip { display:inline-block; border-radius:999px; padding:3px 10px; font-size:10.5px; font-weight:600; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap" style="border-bottom:1px solid #dee2e6; padding-bottom:12px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-file-document-edit-outline" style="color:#0E7490;"></i> Your wedding options</h4>
            <p class="text-muted mb-0" style="font-size:13px;">
                <?= htmlspecialchars($couple_name); ?><?= $wedding_human !== '' ? ' · ' . htmlspecialchars($wedding_human) : ''; ?>
                <?= Proposal::planner_name($proposal) !== '' ? ' · Planned by ' . htmlspecialchars(Proposal::planner_name($proposal)) : ''; ?>
            </p>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-secondary mr-1" href="client.php"><i class="mdi mdi-arrow-left mr-1"></i> My wedding</a>
            <a class="btn btn-sm btn-outline-info" href="client_vendor_plan.php?<?= htmlspecialchars($links); ?>">
                <i class="mdi mdi-clipboard-text-outline mr-1"></i> My vendors
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

    <?php if ($status === 'proposal'): ?>
        <div class="alert alert-info" style="font-size:13px;">
            <i class="mdi mdi-clock-outline"></i> Your registration is with us. A planner will pick it up shortly and price your options.
        </div>
    <?php elseif ($status === 'acknowledged'): ?>
        <div class="alert alert-info" style="font-size:13px;">
            <i class="mdi mdi-account-check"></i> A planner has picked up your wedding and is pricing your options now.
        </div>
    <?php elseif ($status === 'options' || $status === 'tweak_requested'): ?>
        <div class="alert alert-light border" style="font-size:13px;">
            <i class="mdi mdi-format-list-checks"></i>
            <?= $can_act
                ? 'Here are your options. Read them side by side, then choose the one that feels right — or ask for a change.'
                : 'These are the options your planner has sent.'; ?>
        </div>
    <?php elseif ($status === 'chosen'): ?>
        <div class="alert alert-success" style="font-size:13px;">
            <i class="mdi mdi-hand-okay"></i>
            You chose <strong><?= $chosen !== null ? htmlspecialchars((string) $chosen->title) : 'an option'; ?></strong>.
            Your planner is confirming each vendor now — this page updates when the wedding is locked in.
        </div>
    <?php elseif ($status === 'locked'): ?>
        <div class="alert alert-success" style="font-size:13px;">
            <i class="mdi mdi-lock-check"></i>
            Your wedding is confirmed<?= $chosen !== null ? ' on ' . htmlspecialchars((string) $chosen->title) : ''; ?>.
            Every vendor is booked and you can follow them on <a href="client_vendor_plan.php?<?= htmlspecialchars($links); ?>">My vendors</a>.
        </div>
    <?php elseif ($status === 'declined'): ?>
        <div class="alert alert-secondary" style="font-size:13px;">
            <i class="mdi mdi-close-circle-outline"></i> You declined this proposal. Talk to your planner if you would like a different plan.
        </div>
    <?php endif; ?>

    <?php if ($options === []): ?>
        <div class="card" style="border-radius:14px;">
            <div class="card-body text-center py-5">
                <i class="mdi mdi-file-document-outline" style="font-size:40px; color:#94A3B8;"></i>
                <h6 class="mt-2">No options yet</h6>
                <p class="text-muted mb-0" style="font-size:13px;">Your planner is putting them together. You will get a notification the moment they are ready.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($options as $option):
        $is_chosen = (int) $option->is_chosen === 1;
        $diff = (float) ($proposal->budget ?? 0) > 0 ? (float) $option->items_total - (float) $proposal->budget : null;
    ?>
        <div class="opt-card <?= $is_chosen ? 'chosen' : ''; ?>">
            <div class="opt-head d-flex justify-content-between align-items-start flex-wrap">
                <div>
                    <div class="tier"><?= htmlspecialchars((string) $option->tier); ?></div>
                    <h5 class="mt-2 mb-1" style="font-weight:700;"><?= htmlspecialchars((string) $option->title); ?></h5>
                    <?php if (trim((string) $option->summary) !== ''): ?>
                        <div class="hint"><?= htmlspecialchars((string) $option->summary); ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <div class="opt-total"><?= format_ksh((float) $option->items_total); ?></div>
                    <?php if ($diff !== null): ?>
                        <div class="hint" style="color:<?= $diff > 0 ? '#B45309' : '#059669'; ?>;">
                            <?= $diff > 0 ? format_ksh($diff) . ' over your budget' : format_ksh(abs($diff)) . ' under your budget'; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <ul class="opt-list">
                <?php foreach ($option->items as $item): ?>
                    <li>
                        <strong><?= htmlspecialchars((string) $item->business_name); ?></strong> — <?= htmlspecialchars((string) $item->label); ?>
                        <?php if (VendorTier::label($item->tier ?? null) !== ''): ?>
                            <span class="badge badge-light" style="font-size:9px; border:1px solid #E2E8F0;" title="Which level of this vendor's offer you are being quoted">
                                <?= htmlspecialchars(VendorTier::label((string) $item->tier)); ?> tier
                            </span>
                        <?php endif; ?>
                        <?php if ($item->service_qty !== null): ?><span class="hint"> × <?= (int) $item->service_qty; ?></span><?php endif; ?>
                        <span class="float-right mono"><?= format_ksh((float) $item->amount); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="px-4 pb-4">
                <?php if ($is_chosen): ?>
                    <span class="chip" style="background:#D1FAE5; color:#065F46;">
                        <i class="mdi mdi-check"></i> <?= $status === 'locked' ? 'Confirmed' : 'Your choice'; ?>
                    </span>
                <?php elseif ($can_choose): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Choose this option? Your planner will then confirm the vendors.');">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="action" value="choose">
                        <input type="hidden" name="option_id" value="<?= (int) $option->id; ?>">
                        <button class="btn btn-sm btn-success"><i class="mdi mdi-check mr-1"></i> Choose this option</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($can_choose): ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card h-100" style="border-radius:14px;">
                    <div class="card-body">
                        <h6 style="font-weight:700;"><i class="mdi mdi-pencil-outline" style="color:#0E7490;"></i> Ask for a change</h6>
                        <p class="hint">Tell your planner what you would like different — a smaller cake, a cheaper venue, more photography hours.</p>
                        <form method="post">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="tweak">
                            <textarea name="note" class="form-control form-control-sm mb-2" rows="3" maxlength="500" required placeholder="We love option two but the decor budget is too high…"></textarea>
                            <button class="btn btn-sm btn-outline-info"><i class="mdi mdi-send mr-1"></i> Send to my planner</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card h-100" style="border-radius:14px;">
                    <div class="card-body">
                        <h6 style="font-weight:700;"><i class="mdi mdi-close-circle-outline" style="color:#B91C1C;"></i> Not for us</h6>
                        <p class="hint">If none of these is right, tell your planner why. Nothing is booked and nothing is charged.</p>
                        <form method="post" onsubmit="return confirm('Decline this proposal? Your planner will be told.');">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="decline">
                            <textarea name="note" class="form-control form-control-sm mb-2" rows="3" maxlength="500" placeholder="Why none of these works (optional)"></textarea>
                            <button class="btn btn-sm btn-outline-danger"><i class="mdi mdi-close mr-1"></i> Decline</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$can_act && in_array($status, ['options', 'tweak_requested'], true)): ?>
        <p class="hint">
            <i class="mdi mdi-eye-outline"></i>
            You are previewing this page. Only <?= htmlspecialchars($couple_name); ?> can choose, ask for a change or decline.
        </p>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
