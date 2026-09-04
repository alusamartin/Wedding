<?php include 'include/init.php'; ?>
<?php
    if (!isset($_SESSION['id'])) { redirect_to("../"); }

    $user_id = $_SESSION['id'];
    $current_sub = Subscription::get_active($user_id);
    $plans = Subscription::get_all_plans();
    $sub_history = Subscription::get_all_for_user($user_id);

    // Handle subscription purchase
    if (isset($_POST['purchase'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token. Please try again.</div>');
            redirect_to("subscription.php");
            die();
        }

        $plan = clean($_POST['plan']);
        $mpesa_code = clean($_POST['mpesa_code']);

        $plan_details = Subscription::get_plan($plan);
        if (!$plan_details) {
            $session->message('<div class="alert alert-danger">Invalid plan selected.</div>');
            redirect_to("subscription.php");
            die();
        }

        if (empty($mpesa_code) || strlen($mpesa_code) != 10) {
            $session->message('<div class="alert alert-danger">Please enter a valid M-Pesa transaction code (10 characters).</div>');
            redirect_to("subscription.php");
            die();
        }

        Subscription::create_subscription($user_id, $plan, $plan_details->price, $mpesa_code);
        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Subscription activated! Plan: <strong>' . htmlspecialchars($plan_details->name) . '</strong> — Valid until ' . date('d M Y', strtotime('+30 days')) . '.</div>');
        redirect_to("subscription.php");
    }
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Subscription - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css"
    <link href="css/modern-admin.css" rel="stylesheet"> rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .plan-card { border-radius: 8px; padding: 25px; margin-bottom: 20px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; border: 2px solid transparent; }
        .plan-card:hover { border-color: #c0392b; transform: translateY(-2px); }
        .plan-card.popular { border-color: #27ae60; }
        .plan-card h4 { color: #333; margin-bottom: 5px; }
        .plan-card .price { font-size: 36px; font-weight: 700; color: #c0392b; }
        .plan-card .period { color: #888; font-size: 14px; }
        .plan-card ul { list-style: none; padding: 0; margin: 15px 0; }
        .plan-card ul li { padding: 5px 0; font-size: 13px; color: #555; }
        .plan-card ul li i { color: #27ae60; margin-right: 8px; }
        .current-plan { background: #e8f8f5; border-color: #27ae60; }
        .status-badge { font-size: 11px; padding: 4px 10px; border-radius: 20px; }
        .status-active { background: #d4edda; color: #155724; }
        .status-expired { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4><i class="mdi mdi-crown"></i> Subscription Plans</h4>
        <?php if ($current_sub): ?>
            <span class="status-badge status-active"><i class="mdi mdi-check-circle"></i> Active Plan: <?= ucfirst($current_sub->plan); ?> (Expires: <?= date('d M Y', strtotime($current_sub->expiry_date)); ?>)</span>
        <?php else: ?>
            <span class="status-badge status-expired"><i class="mdi mdi-alert-circle"></i> No Active Subscription</span>
        <?php endif; ?>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <!-- Plans Row -->
    <div class="row mb-5">
        <?php foreach ($plans as $plan): ?>
            <?php $is_popular = ($plan->plan_slug == 'professional'); ?>
            <div class="col-md-4">
                <div class="plan-card <?= $is_popular ? 'popular' : ''; ?> <?= ($current_sub && $current_sub->plan == $plan->plan_slug) ? 'current-plan' : ''; ?>">
                    <?php if ($is_popular): ?>
                        <span class="badge badge-success float-right" style="font-size: 10px;">MOST POPULAR</span>
                    <?php endif; ?>
                    <h4><?= htmlspecialchars($plan->name); ?></h4>
                    <div class="price"><?= format_ksh($plan->price); ?></div>
                    <div class="period">per month</div>
                    <ul>
                        <?php foreach (explode(',', $plan->features) as $feature): ?>
                            <li><i class="mdi mdi-check"></i> <?= htmlspecialchars(trim($feature)); ?></li>
                        <?php endforeach; ?>
                        <?php if ($plan->wedding_limit > 0): ?>
                            <li><i class="mdi mdi-check"></i> Up to <?= $plan->wedding_limit; ?> weddings/year</li>
                        <?php else: ?>
                            <li><i class="mdi mdi-check"></i> Unlimited weddings</li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($current_sub && $current_sub->plan == $plan->plan_slug): ?>
                        <button class="btn btn-block btn-success" disabled><i class="mdi mdi-check"></i> Current Plan</button>
                    <?php else: ?>
                        <button class="btn btn-block btn-danger" onclick="showPurchase('<?= $plan->plan_slug; ?>', '<?= htmlspecialchars($plan->name); ?>', <?= $plan->price; ?>)">
                            <i class="mdi mdi-arrow-right"></i> Upgrade
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Purchase Modal -->
    <div class="modal fade" id="purchaseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="plan" id="modal_plan">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modal_title">Purchase Subscription</h5>
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>How to pay:</strong><br>
                            1. Go to M-Pesa → Lipa na M-Pesa → Paybill<br>
                            2. Business No: <strong>123456</strong><br>
                            3. Account No: <strong>WPMS</strong><br>
                            4. Amount: <strong id="modal_amount_display"></strong><br>
                            5. Enter the M-Pesa code below
                        </div>
                        <div class="form-group">
                            <label>M-Pesa Transaction Code</label>
                            <input type="text" name="mpesa_code" class="form-control" placeholder="e.g. QKJ7GH9P2W" maxlength="20" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="purchase" class="btn btn-success"><i class="mdi mdi-check"></i> Activate Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Subscription History -->
    <h5 class="mt-4 mb-3"><i class="mdi mdi-history"></i> Subscription History</h5>
    <table class="table table-bordered table-sm" style="background: white;">
        <thead>
            <tr>
                <th>Plan</th>
                <th>Amount</th>
                <th>M-Pesa Code</th>
                <th>Start</th>
                <th>Expiry</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($sub_history)): ?>
            <tr><td colspan="6" class="text-center text-muted">No subscription history</td></tr>
        <?php else: ?>
            <?php foreach ($sub_history as $sub): ?>
                <tr>
                    <td><?= ucfirst($sub->plan); ?></td>
                    <td><?= format_ksh($sub->amount); ?></td>
                    <td><?= htmlspecialchars($sub->mpesa_code ?? ''); ?></td>
                    <td><?= date('d M Y', strtotime($sub->start_date)); ?></td>
                    <td><?= date('d M Y', strtotime($sub->expiry_date)); ?></td>
                    <td>
                        <?php if ($sub->status == 'active' && strtotime($sub->expiry_date) >= time()): ?>
                            <span class="badge badge-success">Active</span>
                        <?php elseif (strtotime($sub->expiry_date) < time()): ?>
                            <span class="badge badge-danger">Expired</span>
                        <?php else: ?>
                            <span class="badge badge-secondary"><?= ucfirst($sub->status); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
    function showPurchase(slug, name, amount) {
        document.getElementById('modal_plan').value = slug;
        document.getElementById('modal_title').textContent = 'Purchase: ' + name;
        document.getElementById('modal_amount_display').textContent = 'KSh ' + amount.toLocaleString();
        $('#purchaseModal').modal('show');
    }
</script>
</body>
</html>