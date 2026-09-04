<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); }

    $user_id = $_SESSION['id'];
    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id_get = (int)($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id_get;

    $credits = SMS::get_credits($user_id);
    $sms_logs = array();

    // Get SMS logs for this user
    global $db;
    $sql = "SELECT * FROM tblsms_logs WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $user_id]);
    $sms_logs = $stmt->fetchAll(PDO::FETCH_OBJ);

    // Handle SMS purchase
    if (isset($_POST['purchase_sms'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to("sms_center.php?$links");
            die();
        }

        $package = clean($_POST['sms_package']);
        $packages = [
            'basic' => ['credits' => 100, 'price' => 999],
            'standard' => ['credits' => 500, 'price' => 2499],
            'premium' => ['credits' => 9999, 'price' => 4999]
        ];

        if (isset($packages[$package])) {
            SMS::add_credits($user_id, $packages[$package]['credits']);
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> ' . $packages[$package]['credits'] . ' SMS credits added successfully!</div>');
        }
        redirect_to("sms_center.php?$links");
    }

    // Handle send SMS
    if (isset($_POST['send_sms'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger">Invalid security token.</div>');
            redirect_to("sms_center.php?$links");
            die();
        }

        $send_to = clean($_POST['send_to']);
        $message = clean($_POST['message']);

        if ($send_to == 'all') {
            // Send to all guests in booking
            $result = SMS::send_bulk($booking_id, $message, $user_id);
            $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> SMS sent: ' . $result['sent'] . ' successful, ' . $result['failed'] . ' failed.</div>');
        } else {
            $result = SMS::send($send_to, $message, $user_id, $booking_id);
            if ($result['success']) {
                $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> SMS sent successfully!</div>');
            } else {
                $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . ($result['error'] ?? 'Failed to send SMS.') . '</div>');
            }
        }
        redirect_to("sms_center.php?$links");
    }
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>SMS Center - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .sms-card { border-radius: 8px; padding: 20px; margin-bottom: 20px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .credit-badge { background: linear-gradient(135deg, #065F46, #059669); color: #fff; padding: 15px 25px; border-radius: 8px; text-align: center; }
        .credit-badge .number { font-size: 36px; font-weight: 700; }
        .credit-badge .label { font-size: 12px; text-transform: uppercase; opacity: 0.9; }
        .sms-log { font-size: 12px; padding: 8px; border-bottom: 1px solid #eee; }
        .sms-log:last-child { border-bottom: none; }
        .status-sent { color: #059669; }
        .status-failed { color: #DC2626; }
        .status-pending { color: #D97706; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <h4><i class="mdi mdi-message-text"></i> SMS Center</h4>
        <a href="guest_list.php?<?= $links; ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> Back to Guests</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row">
        <!-- Credits + Purchase -->
        <div class="col-md-4">
            <div class="credit-badge mb-3">
                <div class="number"><?= number_format($credits); ?></div>
                <div class="label">SMS Credits Remaining</div>
            </div>

            <div class="sms-card">
                <h6><i class="mdi mdi-cart"></i> Purchase Credits</h6>
                <form method="post">
                    <?= $session->csrf_field(); ?>
                    <div class="form-group">
                        <select name="sms_package" class="form-control" required>
                            <option value="">Select Package</option>
                            <option value="basic">100 SMS — KSh 999</option>
                            <option value="standard">500 SMS — KSh 2,499</option>
                            <option value="premium">Unlimited — KSh 4,999/mo</option>
                        </select>
                    </div>
                    <button type="submit" name="purchase_sms" class="btn btn-block btn-success">
                        <i class="mdi mdi-cellphone"></i> Buy via M-Pesa
                    </button>
                </form>
                <small class="text-muted d-block mt-2">Paybill: 123456 | Account: SMS</small>
            </div>

            <!-- Quick Templates -->
            <div class="sms-card">
                <h6><i class="mdi mdi-text-box"></i> Quick Templates</h6>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle btn-block" data-toggle="dropdown">
                        Select Template
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="javascript:void(0)" onclick="setMessage('Invitation: You are invited to [Bride] & [Groom] wedding on [Date] at [Venue]. Transport provided from [Pickup] at [Time]. RSVP: [Phone]')">Wedding Invitation</a>
                        <a class="dropdown-item" href="javascript:void(0)" onclick="setMessage('Transport Update: Your wedding transport departs [Pickup] at [Time] sharp. Please be punctual. Contact [Phone] for queries.')">Transport Reminder</a>
                        <a class="dropdown-item" href="javascript:void(0)" onclick="setMessage('Harambee Thank You: Thank you for your generous contribution of KSh [Amount]. God bless you! - [Bride] & [Groom]')">Harambee Thanks</a>
                        <a class="dropdown-item" href="javascript:void(0)" onclick="setMessage('Reminder: [Bride] & [Groom] wedding is tomorrow! [Date] at [Venue]. See you there!')">Day Before Reminder</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Send SMS -->
        <div class="col-md-8">
            <div class="sms-card">
                <h6><i class="mdi mdi-send"></i> Send SMS</h6>
                <form method="post">
                    <?= $session->csrf_field(); ?>
                    <div class="form-group">
                        <label>Send To</label>
                        <select name="send_to" class="form-control" id="send_to">
                            <option value="all">All Guests (Bulk)</option>
                            <option value="upcountry">Upcountry Guests Only</option>
                            <option value="vip">VIP Guests (Priority A) Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" rows="4" id="sms_message" maxlength="480" placeholder="Type your message here..." required></textarea>
                        <small class="text-muted"><span id="char_count">0</span>/480 characters (<span id="sms_count">1</span> SMS)</small>
                    </div>
                    <button type="submit" name="send_sms" class="btn btn-danger" <?= $credits <= 0 ? 'disabled' : ''; ?>>
                        <i class="mdi mdi-send"></i> Send SMS
                    </button>
                    <?php if ($credits <= 0): ?>
                        <small class="text-danger d-block mt-2">No credits. Please purchase SMS credits first.</small>
                    <?php endif; ?>
                </form>
            </div>

            <!-- SMS History -->
            <div class="sms-card">
                <h6><i class="mdi mdi-history"></i> Recent SMS</h6>
                <?php if (empty($sms_logs)): ?>
                    <p class="text-muted text-center py-3">No SMS sent yet.</p>
                <?php else: ?>
                    <?php foreach ($sms_logs as $log): ?>
                        <div class="sms-log d-flex justify-content-between">
                            <div>
                                <strong><?= htmlspecialchars($log->phone); ?></strong>
                                <span class="text-muted d-block"><?= htmlspecialchars(substr($log->message, 0, 60)); ?>...</span>
                            </div>
                            <div class="text-right">
                                <span class="status-<?= $log->status; ?>"><?= ucfirst($log->status); ?></span>
                                <small class="text-muted d-block"><?= date('d M H:i', strtotime($log->created_at)); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
    function setMessage(text) {
        document.getElementById('sms_message').value = text;
        updateCount();
    }
    document.getElementById('sms_message').addEventListener('input', updateCount);
    function updateCount() {
        var len = document.getElementById('sms_message').value.length;
        document.getElementById('char_count').textContent = len;
        document.getElementById('sms_count').textContent = Math.ceil(len / 160) || 1;
    }
</script>
</body>
</html>