<?php include 'include/init.php'; ?>
<?php
    if (!isset($_SESSION['id'])) { redirect_to("../"); }

    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $events = Events::find_by_event_all_budget($booking_id);
    $liquidate = new Liquidation();

    if (isset($_POST['submit'])) {

        // CSRF validation
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("budget_add.php?$links");
            die();
        }

        $event_id = (int)$_POST['event_id'];
        $payment = floatval($_POST['payment']);
        $payment_method = clean($_POST['payment_method']);
        $mpesa_code = clean($_POST['mpesa_code'] ?? '');
        $cash = 0;
        $credit = 0;

        if ($event_id <= 0 || $payment <= 0) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Please fill in all required fields.</div>');
            redirect_to("budget_add.php?$links");
            die();
        }

        switch ($payment_method) {
            case 'cash':
                $cash = $payment;
                $credit = 0;
                break;
            case 'mpesa':
                $cash = $payment;
                $credit = 0;
                if (empty($mpesa_code)) {
                    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> M-Pesa transaction code is required for M-Pesa payments.</div>');
                    redirect_to("budget_add.php?$links");
                    die();
                }
                break;
            case 'bank':
                $cash = 0;
                $credit = $payment;
                break;
        }

        $liquidate->event_id = $event_id;
        $liquidate->payment = $payment;
        $liquidate->cash = $cash;
        $liquidate->credit = $credit;
        $liquidate->mpesa_code = $mpesa_code;
        $liquidate->booking_id = $booking_id;
        $liquidate->user_id = $user_id;
        $liquidate->date_issue = date("F j, Y, g:i a");
        $liquidate->save();

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Payment of <strong>KSh ' . number_format($payment, 2) . '</strong> recorded successfully.</div>');
        redirect_to("budget_add.php?$links");
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Add Payment - WPMS Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css"
    <link href="css/modern-admin.css" rel="stylesheet"> rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .box-shadow { box-shadow: 0 0 2px 1px rgba(0,0,0,0.1); }
        .form-control, .btn { font-size: 13px; }
        .mpesa-field { display: none; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>
<div class="container">
    <div class="row">
        <div class="col-lg-8 offset-2 pl-3 pb-3 box-shadow mt-4">
            <form method="post" action="">
                <?= $session->csrf_field(); ?>
                <h4 class="h4 mt-4 pb-2" style="border-bottom: 1px solid #dee2e6;">
                    <i class="mdi mdi-cash-multiple"></i> Record Payment
                    <a href="budget.php?<?= $links; ?>" class="btn btn-sm btn-danger float-right"><i class="mdi mdi-close-circle mr-1"></i> Cancel</a>
                    <button type="submit" name="submit" class="btn btn-sm btn-success float-right mr-2"><i class="mdi mdi-content-save mr-1"></i> Save Payment</button>
                </h4>

                <?php if ($session->message()): ?>
                    <div class="form-group col-md-12 p-0"><?= $session->message(); ?></div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Related Event / Ceremony</label>
                    <select class="form-control" name="event_id" required>
                        <option value="">Select Event</option>
                        <?php foreach($events as $event): ?>
                            <option value="<?= $event->id; ?>"><?= htmlspecialchars(ucfirst($event->title)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Payment Method</label>
                    <select class="form-control" name="payment_method" id="payment_method" required>
                        <option value="">Select Method</option>
                        <option value="cash">Cash</option>
                        <option value="mpesa">M-Pesa</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>

                <div class="form-group mpesa-field" id="mpesa_field">
                    <label>M-Pesa Transaction Code</label>
                    <input type="text" name="mpesa_code" class="form-control" placeholder="e.g. QKJ7GH9P2W" maxlength="20">
                    <small class="text-muted">Enter the M-Pesa confirmation code (10 characters)</small>
                </div>

                <div class="form-group">
                    <label>Amount (KSh)</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">KSh</span>
                        </div>
                        <input type="number" name="payment" class="form-control" placeholder="0.00" step="0.01" min="0" required>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include_once 'include/footer.php'; ?>
<script>
    document.getElementById('payment_method').addEventListener('change', function() {
        var mpesaField = document.getElementById('mpesa_field');
        if (this.value === 'mpesa') {
            mpesaField.style.display = 'block';
        } else {
            mpesaField.style.display = 'none';
        }
    });
</script>
</body>
</html>