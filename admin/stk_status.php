<?php
/**
 * STK push status — the page the payment forms redirect to after asking
 * Daraja to prompt the phone. Polls the push row (meta refresh) until the
 * callback lands: pending → success / cancelled / timeout / failed.
 *
 * Ownership: users see their own pushes (latest, or ?id=N), staff any.
 */
include 'include/init.php';

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }

    $id   = (int) ($_GET['id'] ?? 0);
    $push = $id > 0
        ? MpesaStk::find_for_user($id, (int) $_SESSION['id'], is_staff())
        : MpesaStk::latest_for_user((int) $_SESSION['id']);

    // Sweep abandoned pushes so the status page never hangs on a dead prompt.
    if ($push !== null && $push->status === 'pending') {
        MpesaStk::cancel_stale();
        $push = MpesaStk::find((int) $push->id);
    }

    $backUrl = 'subscription.php';
    if ($push !== null && $push->purpose === 'deposit') {
        $backUrl = 'client_deposit.php?booking_id=' . (int) $push->booking_id . '&user_id=' . (int) $push->user_id;
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php if ($push !== null && $push->status === 'pending'): ?>
    <meta http-equiv="refresh" content="3">
    <?php endif; ?>
    <title>M-Pesa Payment Status - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <style>
        .stk-card { background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                    border-left: 4px solid #065F46; padding: 28px; max-width: 560px; margin: 24px auto; text-align: center; }
        .stk-card.pending   { border-left-color: var(--warning); }
        .stk-card.success   { border-left-color: var(--success); }
        .stk-card.cancelled, .stk-card.failed, .stk-card.timeout { border-left-color: var(--danger); }
        .stk-amount { font-size: 30px; font-weight: 700; color: #0F172A; }
        .stk-phone  { font-size: 14px; color: #64748B; }
        .stk-ref    { font-weight: 700; color: #065F46; }
        .stk-pulse  { display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #D97706;
                      animation: stkpulse 1.2s ease-in-out infinite; }
        @keyframes stkpulse { 0%,100% { opacity: .25; } 50% { opacity: 1; } }
        .stk-note   { font-size: 12.5px; color: #64748B; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
<?php if ($push === null): ?>
    <div class="stk-card cancelled">
        <i class="mdi mdi-help-circle-outline" style="font-size:42px; color:#B91C1C;"></i>
        <h5 class="mt-2">No payment prompt found</h5>
        <p class="stk-note">This payment prompt does not exist, or it belongs to another account.</p>
        <a href="subscription.php" class="btn btn-outline-secondary btn-sm mt-2">Back</a>
    </div>
<?php else: ?>
    <div class="stk-card <?= $push->status; ?>">
        <?php if ($push->status === 'pending'): ?>
        <span class="stk-pulse"></span>
        <h5 class="mt-3"><i class="mdi mdi-cellphone-vibrate mr-1"></i> Check your phone</h5>
        <div class="stk-amount"><?= format_ksh((float) $push->amount, 2); ?></div>
        <p class="stk-phone mb-2">We asked M-Pesa to prompt <strong><?= htmlspecialchars((string) $push->phone); ?></strong>.<br>
            Enter your M-Pesa PIN on the prompt to pay.</p>
        <p class="stk-note">This page updates itself every few seconds. Unanswered prompts expire after
            <?= (int) MpesaStk::STALE_MINUTES; ?> minutes.</p>
        <?php elseif ($push->status === 'success'): ?>
        <i class="mdi mdi-check-circle-outline" style="font-size:42px; color:#059669;"></i>
        <h5 class="mt-2">Payment received</h5>
        <div class="stk-amount"><?= format_ksh((float) $push->amount, 2); ?></div>
        <p class="stk-note mt-1">M-Pesa reference <span class="stk-ref"><?= htmlspecialchars((string) ($push->mpesa_receipt ?: 'processing…')); ?></span></p>
        <a href="<?= $push->purpose === 'subscription' ? 'subscription.php' : 'client_budget.php?booking_id=' . (int) $push->booking_id . '&user_id=' . (int) $push->user_id; ?>"
           class="btn btn-success btn-sm mt-2">
            <i class="mdi mdi-arrow-right mr-1"></i><?= $push->purpose === 'subscription' ? 'View my subscription' : 'View my payments'; ?>
        </a>
        <?php else: ?>
        <i class="mdi mdi-close-circle-outline" style="font-size:42px; color:#B91C1C;"></i>
        <h5 class="mt-2"><?= $push->status === 'cancelled' ? 'Prompt cancelled' : ($push->status === 'timeout' ? 'Prompt expired' : 'Payment failed'); ?></h5>
        <p class="stk-note"><?= htmlspecialchars((string) ($push->result_desc ?: 'No payment was made — nothing was charged.')); ?></p>
        <a href="<?= $backUrl; ?>" class="btn btn-outline-success btn-sm mt-2">
            <i class="mdi mdi-replay mr-1"></i> Try again
        </a>
        <?php endif; ?>
    </div>

    <?php if (is_staff()): ?>
    <div class="card shadow-sm" style="border:none; border-radius:14px; max-width: 900px; margin: 0 auto;">
        <div class="card-body">
            <h6 style="font-size:11px; text-transform:uppercase; color:#64748B; letter-spacing:.06em;">Recent STK pushes</h6>
            <?php
                global $db;
                $recent = $db->fetchAll('SELECT * FROM tblmpesa_stk_pushes ORDER BY id DESC LIMIT 10');
            ?>
            <?php if (empty($recent)): ?>
                <p class="stk-note mb-0">No pushes yet.</p>
            <?php else: ?>
            <table class="table table-sm table-borderless mb-0" style="font-size:13px;">
                <thead><tr><th>#</th><th>Purpose</th><th>Phone</th><th class="text-right">Amount</th><th>Status</th><th>Receipt</th><th>Requested</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= (int) $r->id; ?></td>
                        <td><?= $r->purpose === 'subscription' ? 'Subscription (' . htmlspecialchars((string) $r->plan) . ')' : 'Booking #' . (int) $r->booking_id; ?></td>
                        <td><?= htmlspecialchars((string) $r->phone); ?></td>
                        <td class="text-right"><?= format_ksh((float) $r->amount, 2); ?></td>
                        <td><span class="badge <?= $r->status === 'success' ? 'badge-success' : ($r->status === 'pending' ? 'badge-warning' : 'badge-secondary'); ?>"><?= htmlspecialchars((string) $r->status); ?></span></td>
                        <td><?= htmlspecialchars((string) ($r->mpesa_receipt ?? '—')); ?></td>
                        <td><?= htmlspecialchars(format_date_kd((string) $r->requested_at, true)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
