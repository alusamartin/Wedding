<?php include 'include/init.php'; ?>
<?php
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }
    $booking = Booking::find_by_booking_id($booking_id);
    if (!is_staff()) {
        // Clients see only their own wedding's guest list (staff may preview any).
        if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
            redirect_to("client.php");
        }
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $guest_list = Guest::getGuest($booking_id);
    $total_harambee = Guest::total_harambee($booking_id);
    $upcountry_guests = Guest::get_upcountry_guests($booking_id);
    $transport_needed = 0;
    foreach ($guest_list as $g) {
        if (!empty($g->transport_needed)) {
            $transport_needed++;
        }
    }

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= is_staff() ? 'Guest List - Everafter Planner' : 'My Guests - Everafter Planner'; ?></title>
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
        .readonly-note { font-size: 12px; color: #64748B; }
        .badge-priority-A { background: #DC2626; color: #fff; }
        .badge-priority-B { background: #D97706; color: #fff; }
        .badge-priority-C { background: #EAB308; color: #333; }
        .badge-priority-D { background: #0EA5A0; color: #fff; }
        .badge-priority-E { background: #64748B; color: #fff; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-account-network" style="color:#065F46"></i> My Guests</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '&' ? htmlspecialchars($couple) . ' — ' : ''; ?>everyone we are celebrating with
            </p>
        </div>
        <div class="readonly-note"><i class="mdi mdi-eye-outline mr-1"></i>Read-only — share updates with your planner</div>
    </div>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Total Guests</h6><div class="value"><?= count($guest_list); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#059669"><h6>Upcountry Guests</h6><div class="value"><?= count($upcountry_guests); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#D97706"><h6>Harambee Pledged</h6><div class="value"><?= format_ksh($total_harambee); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#0EA5A0"><h6>Transport Needed</h6><div class="value"><?= $transport_needed; ?></div></div></div>
    </div>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <table class="table table-sm table-hover" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Group</th>
                        <th>Location</th>
                        <th>Priority</th>
                        <th>Upcountry</th>
                        <th>Accommodation</th>
                        <th>Transport</th>
                        <th>Harambee</th>
                        <th>Dietary</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($guest_list)) : ?>
                    <?php empty_state_row(9, 'Your guest list is being prepared', 'Your planner is building it — or share names with them through the contact page.', 'mdi-account-multiple-outline', '../contact.php', 'Talk to Your Planner', 'mdi-email-outline'); ?>
                <?php else : ?>
                <?php foreach ($guest_list as $g) : ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($g->fullname); ?></strong>
                            <?php if (!empty($g->guestname)): ?><br><small class="text-muted"><?= htmlspecialchars($g->guestname); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $labels = [
                                'family_elder' => 'Family Elder (Mzee)',
                                'bridal_train' => 'Bridal Train',
                                'upcountry_guest' => 'Upcountry Guest',
                                'family_friend' => 'Family Friend',
                                'colleague' => 'Colleague',
                                'pastor_clergy' => 'Pastor/Clergy',
                                'vc_member' => 'VC Member',
                                'harambee_sponsor' => 'Harambee Sponsor',
                                'close_family' => 'Close Family',
                                'regular' => 'Regular Guest',
                                'other' => 'Other'
                            ];
                            echo $labels[$g->relationship] ?? ucfirst(str_replace('_', ' ', (string) $g->relationship));
                            ?>
                        </td>
                        <td><?= htmlspecialchars($g->city); ?></td>
                        <td><span class="badge badge-priority-<?= $g->priority; ?>"><?= $g->priority; ?></span></td>
                        <td><?= ($g->out_of_town == 'y') ? '<i class="mdi mdi-check text-success"></i> Yes' : '<i class="mdi mdi-close text-muted"></i>'; ?></td>
                        <td><?= $g->upcountry_accommodation ? '<i class="mdi mdi-bed text-success"></i>' : ''; ?></td>
                        <td><?= $g->transport_needed ? '<i class="mdi mdi-bus text-primary"></i>' : ''; ?></td>
                        <td><?= $g->harambee_contribution > 0 ? format_ksh($g->harambee_contribution) : ''; ?></td>
                        <td><?= htmlspecialchars($g->dietary_requirements); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
