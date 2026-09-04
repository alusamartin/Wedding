<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); }

    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;
    $guest_list = Guest::getGuest($booking_id);
    $total_harambee = Guest::total_harambee($booking_id);
    $upcountry_guests = Guest::get_upcountry_guests($booking_id);
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Guest List - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css"
    <link href="css/modern-admin.css" rel="stylesheet"> rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        table.table.table-striped.table-bordered.table-sm { font-size:12px; }
        td.special { padding: 0; padding-top: 8px; padding-left:6px; padding-bottom:6px; margin-top:5px; text-transform: capitalize; }
        .stat-card { background: #fff; border-left: 4px solid #c0392b; padding: 10px 15px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #888; margin: 0; }
        .stat-card .value { font-size: 20px; font-weight: 700; color: #333; }
        .badge-priority-A { background: #c0392b; color: #fff; }
        .badge-priority-B { background: #e67e22; color: #fff; }
        .badge-priority-C { background: #f1c40f; color: #333; }
        .badge-priority-D { background: #3498db; color: #fff; }
        .badge-priority-E { background: #95a5a6; color: #fff; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h4 class="h4 mt-4 ml-3"><i class="mdi mdi-account-multiple"></i> Master Guest List</h4>
    <div class="btn-toolbar mb-md-0">
        <div class="btn-group mr-2">
            <a class="btn btn-sm btn-primary" href="client_manage_account_details.php?<?= $links; ?>"><i class="mdi mdi-buffer mr-1"></i> Overview</a>
            <a class="btn btn-sm btn-info active" href="guest_list.php?<?= $links; ?>"><i class="mdi mdi-account-multiple mr-1"></i> Guest List</a>
            <a class="btn btn-sm btn-success" href="budget.php?<?= $links; ?>"><i class="mdi mdi-cash mr-1"></i> Budget</a>
            <a class="btn btn-sm btn-warning" href="task_calendar.php?<?= $links; ?>"><i class="mdi mdi-calendar-text mr-1"></i> Calendar</a>
        </div>
    </div>
</div>

<?php if ($session->message()): ?>
    <div class="ml-3"><?= $session->message(); ?></div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row ml-2 mr-2 mb-3">
    <div class="col-md-3">
        <div class="stat-card">
            <h6>Total Guests</h6>
            <div class="value"><?= count($guest_list); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-left-color: #27ae60;">
            <h6>Upcountry Guests</h6>
            <div class="value"><?= count($upcountry_guests); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-left-color: #f39c12;">
            <h6>Total Harambee</h6>
            <div class="value"><?= format_ksh($total_harambee, 2); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card" style="border-left-color: #3498db;">
            <h6>Transport Needed</h6>
            <div class="value"><?= count(array_filter($guest_list, fn($g) => $g->transport_needed)); ?></div>
        </div>
    </div>
</div>

<div class="text-right mr-3 mb-3">
    <a class="btn btn-sm btn-success" href="guest_add.php?<?= $links; ?>"><i class="mdi mdi-account-plus mr-1"></i> Add New Guest</a>
</div>

<table id="guest_list" class="table table-striped table-hover table-bordered table-sm" cellspacing="0" width="100%" style="background: white;">
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
            <th>Tools</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($guest_list)) : ?>
        <?php empty_state_row(10, 'No guests on the list yet', 'Add your first guest — family elders, bridal train and friends all belong here.', 'mdi-account-multiple-outline', 'guest_add.php?' . $links, 'Add First Guest', 'mdi-account-plus'); ?>
    <?php else : ?>
    <?php foreach ($guest_list as $g) : ?>
        <tr>
            <td class="special">
                <strong><?= htmlspecialchars($g->fullname); ?></strong><br>
                <small class="text-muted"><?= htmlspecialchars($g->guestname); ?></small>
            </td>
            <td class="special">
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
            <td class="special"><?= htmlspecialchars($g->city); ?></td>
            <td class="special"><span class="badge badge-priority-<?= $g->priority; ?>"><?= $g->priority; ?></span></td>
            <td class="special"><?= ($g->out_of_town == 'y') ? '<i class="mdi mdi-check text-success"></i> Yes' : '<i class="mdi mdi-close text-muted"></i>'; ?></td>
            <td class="special"><?= $g->upcountry_accommodation ? '<i class="mdi mdi-bed text-success"></i>' : ''; ?></td>
            <td class="special"><?= $g->transport_needed ? '<i class="mdi mdi-bus text-primary"></i>' : ''; ?></td>
            <td class="special"><?= $g->harambee_contribution > 0 ? format_ksh($g->harambee_contribution) : ''; ?></td>
            <td class="special"><?= htmlspecialchars($g->dietary_requirements); ?></td>
            <td class="special">
                <a href="guest_edit.php?id=<?= $g->id; ?>&<?= $links; ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="mdi mdi-pencil"></i></a>
                <form method="post" action="guest_delete.php?id=<?= $g->id; ?>&<?= $links; ?>" class="d-inline" onsubmit="return confirm('Delete this guest?');">
                    <?= $session->csrf_field(); ?>
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="mdi mdi-delete"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

</div></div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap4.min.js"></script>
<script>
    $(document).ready(function() {
        $('#guest_list').DataTable({ pageLength: 25 });
    });
</script>
</body>
</html>