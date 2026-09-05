<?php include 'include/init.php'; ?>
<?php
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die();
    }
    // Ownership gate (IDOR): the booking row itself must belong to this client.
    // die() after the redirect so the POST handler below cannot overwrite the
    // Location header (the gate must always win over any later redirect).
    $booking = Booking::find_by_booking_id($booking_id);
    if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
        redirect_to("client.php");
        die();
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $account = Account_Details::find_by_user_id($user_id);
    $phone = is_object($account) ? (string) ($account->phone ?? '') : '';

    $events = Events::find_by_event_all_budget($booking_id);

    $category_details = Category::find_by_id($booking->wedding_type ?? 0);
    $package_price = (float) ($category_details->price ?? 0);
    $totals = Liquidation::getTotalsByMethod($booking_id);
    $amount_paid = (float) ($totals['grand_total'] ?? 0);
    $balance_due = max(0.0, $package_price - $amount_paid);

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));

    $error = '';

    if (isset($_POST['submit'])) {
        // CSRF
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("client_deposit.php?$links");
            die();
        }
        // Light anti-spam: one deposit per session every 30s.
        if (!empty($_SESSION['last_deposit_ts']) && time() - (int) $_SESSION['last_deposit_ts'] < 30) {
            $error = 'Please wait a moment before making another deposit.';
        } elseif ($balance_due <= 0) {
            $error = 'Your package is fully paid — there is no balance due.';
        } else {
            $amount   = (float) ($_POST['amount'] ?? 0);
            $event_id = (int) ($_POST['event_id'] ?? 0);
            $phone    = clean($_POST['phone'] ?? '');

            // Basic Kenyan mobile format (07XXXXXXXX / 2547XXXXXXXX)
            $digits = preg_replace('/[^0-9]/', '', $phone);
            if (!preg_match('/^(?:254|0)(7|1)[0-9]{8}$/', $digits)) {
                $error = 'Please enter a valid Kenyan M-Pesa number (e.g. 0712 345 678).';
            } elseif ($amount < 50 || $amount > $balance_due) {
                $error = 'Amount must be between KSh 50 and your balance due of ' . format_ksh($balance_due, 2) . '.';
            } else {
                $result = Liquidation::create_mpesa_deposit($booking_id, $user_id, $event_id, $amount);
                if (!$result['ok']) {
                    $error = $result['error'];
                } else {
                    $_SESSION['last_deposit_ts'] = time();

                    // Notify the assigned planner (in-app alert with deep link).
                    $plannerId = (int) ($booking->organizer_id ?? 0);
                    if ($plannerId > 0) {
                        $coupleName = $couple !== '&' ? $couple : (string) ($_SESSION['user_email'] ?? 'Your couple');
                        $newBalance = max(0.0, $balance_due - $result['payment']);
                        $plannerMessage = sprintf(
                            'M-Pesa deposit of %s received from %s (ref %s). Balance due: %s.',
                            format_ksh($result['payment'], 2),
                            $coupleName,
                            $result['mpesa_code'],
                            format_ksh($newBalance, 2)
                        );
                        Notification::create(
                            $plannerId,
                            $booking_id,
                            $plannerMessage,
                            'budget.php?' . $links,
                            'New M-Pesa deposit — booking #' . $booking_id
                        );
                    }

                    $session->message(
                        '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> ' .
                        'Deposit of <strong>' . format_ksh($result['payment'], 2) . '</strong> recorded. ' .
                        'M-Pesa reference <strong>' . htmlspecialchars($result['mpesa_code']) . '</strong> ' .
                        '(simulated STK — no money moved).</div>'
                    );
                    redirect_to("client_budget.php?$links");
                    die();
                }
            }
        }
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Deposit via M-Pesa - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #065F46; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .stat-card .value.due { color: #B45309; }
        .paybill-panel { background: linear-gradient(135deg, #F8FAFC 0%, #ECFDF5 100%);
                         border: 1px solid #D1FAE5; border-radius: 12px; }
        .paybill-panel .label { font-size: 11px; text-transform: uppercase; color: #64748B; letter-spacing: .06em; }
        .paybill-panel .value { font-weight: 700; color: #065F46; }
        .mpesa-note { font-size: 12px; color: #64748B; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-cellphone" style="color:#065F46"></i> Deposit via M-Pesa</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '&' ? htmlspecialchars($couple) . ' — ' : ''; ?>pay toward your package in seconds
            </p>
        </div>
        <a href="client_budget.php?<?= $links; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="mdi mdi-arrow-left mr-1"></i> Back to My Payments
        </a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm" style="border:none; border-radius:14px;">
                <div class="card-body">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> <?= htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <?php if (empty($events)) : ?>
                        <div class="text-center py-4">
                            <i class="mdi mdi-calendar-blank-outline" style="font-size:42px; color:#94A3B8;"></i>
                            <h5 class="mt-2">No ceremonies scheduled yet</h5>
                            <p class="text-muted mb-1" style="font-size:13px;">
                                Deposits are linked to a ceremony on your plan, so they stay on your planner's books.
                            </p>
                            <p class="text-muted mb-3" style="font-size:13px;">
                                Ask your planner to add your event dates, or have them record this deposit for you.
                            </p>
                            <a href="client_manage_account_details.php?<?= $links; ?>" class="btn btn-success btn-sm">
                                <i class="mdi mdi-calendar-check mr-1"></i> View My Overview
                            </a>
                        </div>
                    <?php else : ?>
                    <form method="post" action="">
                        <?= $session->csrf_field(); ?>

                        <div class="paybill-panel p-3 mb-3">
                            <div class="row">
                                <div class="col-md-4"><div class="label">Paybill / Till</div><div class="value">247 247</div></div>
                                <div class="col-md-4"><div class="label">Account</div><div class="value">WED-<?= (int) $booking_id; ?></div></div>
                                <div class="col-md-4"><div class="label">Business</div><div class="value">Everafter Planner</div></div>
                            </div>
                            <div class="mpesa-note mt-2">
                                <i class="mdi mdi-information-outline mr-1"></i>
                                Demo mode — this is a simulated STK push. No real money moves; a fictional
                                M-Pesa reference is recorded on your ledger.
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="required">M-Pesa Number</label>
                                <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($phone); ?>"
                                       placeholder="e.g. 0712 345 678" required>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="required">Amount (KSh)</label>
                                <input type="number" name="amount" class="form-control" min="50"
                                       max="<?= (int) $balance_due; ?>" step="50"
                                       placeholder="Balance due: <?= htmlspecialchars(str_replace('KSh ', '', format_ksh($balance_due, 2))); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="required">Towards Ceremony</label>
                            <select name="event_id" class="form-control" required>
                                <?php $first = true; foreach ($events as $event): ?>
                                    <option value="<?= (int) $event->id; ?>" <?= $first ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars(ucfirst($event->title)); ?> —
                                        <?= htmlspecialchars($event->start); ?>
                                    </option>
                                <?php $first = false; endforeach; ?>
                            </select>
                            <small class="form-text text-muted mpesa-note">
                                Your deposit is logged against this ceremony so your planner can reconcile it.
                            </small>
                        </div>

                        <button type="submit" name="submit" class="btn btn-success" <?= $balance_due <= 0 ? 'disabled' : ''; ?>>
                            <i class="mdi mdi-cellphone-arrow-down mr-1"></i> Initiate M-Pesa Deposit
                        </button>
                        <a href="client_budget.php?<?= $links; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card"><h6>Package Price</h6><div class="value"><?= format_ksh($package_price); ?></div></div>
            <div class="stat-card" style="border-left-color:#059669"><h6>Paid To Date</h6><div class="value"><?= format_ksh($amount_paid, 2); ?></div></div>
            <div class="stat-card" style="border-left-color:#D97706"><h6>Balance Due</h6><div class="value due"><?= format_ksh($balance_due, 2); ?></div></div>
            <div class="card shadow-sm" style="border:none; border-radius:14px;">
                <div class="card-body mpesa-note">
                    <i class="mdi mdi-shield-check-outline mr-1" style="color:#065F46"></i>
                    Deposits appear on <a href="client_budget.php?<?= $links; ?>">My Payments</a> the moment
                    they're recorded, with their M-Pesa reference and ceremony.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>