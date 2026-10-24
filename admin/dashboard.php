<?php include 'include/init.php'; ?>
<?php
     require_staff_subscribed();

    $user_count =  Account_Details::count_user();
    // The weddings THIS planner is scoped to — a count of every booking on the
    // platform would tell a planner how much business their colleagues hold,
    // which is exactly what per-planner scoping exists to stop. Super admins
    // and installs with scoping off get the platform-wide number as before.
    $booking_count =  Booking::count_booking(planner_scope_sql());
    $gallery_count =  Gallery::count_all();
    $event_post =  EventWedding::count_all();
    // Unread alerts for THIS staff user — surfaces deposits recorded by
    // couples while the planner was away. Links to the full archive.
    $notif_unread = Notification::unread_count(Notification::FOR_USER, (int) $_SESSION['id']);
?>
<?php
$users_profile = Users::find_by_id($_SESSION['id']);

// Trial-funnel report — SUPER ADMINS only (access_level 2, the SaaS
// operator). Window via ?days= (7/30/90/365, default 30).
$funnel = null;
if ((int) ($users_profile->access_level ?? 0) === 2) {
    $funnel_days = filter_input(INPUT_GET, 'days', FILTER_VALIDATE_INT, ['options' => ['default' => 30]]);
    if (!in_array($funnel_days, [7, 30, 90, 365], true)) {
        $funnel_days = 30;
    }
    $funnel = SignupGuard::funnel_report($funnel_days);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard - Administrator</title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css?v=1.13.11" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" href="css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <style>
        table.table.table-striped.table-bordered.table-sm {
            font-size:12px;
        }
        .tooltip {
            font-size: 12px;
        }

        td.special {
            padding: 0;
            padding-top: 8px;
            padding-left:6px;
            padding-bottom:6px;
            margin-top:5px;
            text-transform: capitalize;
        }
        .datepicker {
            font-size: 12px;
        }
        .alert-success {
            color: #fff;
            background-color: #49C8AE;
            border-color: none;
        }
        div.dataTables_wrapper div.dataTables_paginate {
            font-size: 11px;
        }

        .card-counter{
    box-shadow: 2px 2px 10px #DADADA;
    margin: 5px;
    padding: 0px 9px;
    background-color: #fff;
    height: 100px;
    border-radius: 5px;
    transition: .3s linear all;
  }

  .card-counter:hover{
    box-shadow: 4px 4px 20px #DADADA;
    transition: .3s linear all;
  }

  .card-counter.primary{
    background-color: #007bff;
    color: #FFF;
  }

  .card-counter.danger{
    background-color: #ef5350;
    color: #FFF;
  }  

  .card-counter.success{
    background-color: #66bb6a;
    color: #FFF;
  }  

  .card-counter.info{
    background-color: #26c6da;
    color: #FFF;
  }  

  .card-counter i{
    font-size: 5em;
    opacity: 0.9;
  }

  .card-counter .count-numbers{
    position: absolute;
    right: 35px;
    top: 20px;
    font-size: 32px;
    display: block;
  }

  .card-counter .count-name{
    position: absolute;
    right: 35px;
    top: 65px;
    text-transform: capitalize;
    opacity: 0.8;
    display: block;
    font-size: 16px;
  }

    </style>
</head>

<body>

<?php include_once 'include/sidebar.php'; ?>


<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h4 class="h4 mt-4">WELCOME, <?= ucfirst($users_profile->firstname) . ' ' . ucfirst($users_profile->lastname); ?></h4>
</div>
<?= subscription_banner(); ?>
<!-- <div class="row">
    <div class="col-lg-3">
        <div class="card bg-light mb-3" style="max-width: 18rem;">
            <div class="card-header">TOTAL CUSTOMERS</div>
            
                <div class="card-body">
                <h5 class="card-title"><?=  $user_count; ?></h5>
                    <p class="card-text"></p>
               </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="card bg-light mb-3" style="max-width: 18rem;">
            <div class="card-header">TOTAL BOOKING</div>
                <div class="card-body">
                <h5 class="card-title"><?=  $booking_count; ?></h5>
                    <p class="card-text"></p>
               </div>
        </div>
    </div>
       

    <div class="col-lg-3">
        <div class="card bg-light mb-3" style="max-width: 18rem;">
            <div class="card-header">TOTAL PHOTOS</div>
                <div class="card-body">
                <h5 class="card-title"><?=  $gallery_count; ?></h5>
                    <p class="card-text"></p>
                </div>
        </div>
    </div>
       

    <div class="col-lg-3">
        <div class="card bg-light mb-3" style="max-width: 18rem;">
            <div class="card-header">TOTAL BLOGS</div>
                <div class="card-body">
                <h5 class="card-title"><?=  $event_post; ?></h5>
                    <p class="card-text"></p>
               </div>
        </div>
    </div>
       
       
    
</div> -->

    <div class="row">
    <div class="col-lg-3">
      <div class="card-counter primary">
        <i class="mdi mdi-account-multiple"></i>
        <span class="count-numbers"><?=  $user_count; ?></span>
        <span class="count-name">Total Customers</span>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="card-counter success">
        <i class="mdi mdi-book-open-page-variant"></i>
        <span class="count-numbers"><?=  $booking_count; ?></span>
        <span class="count-name">Total Bookings</span>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="card-counter danger">
        <i class="mdi mdi-image-multiple"></i>
        <span class="count-numbers"><?=  $gallery_count; ?></span>
        <span class="count-name">Photos</span>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="card-counter info">
        <i class="mdi mdi-comment-text"></i>
        <span class="count-numbers"><?=  $event_post; ?></span>
        <span class="count-name">Blogs</span>
      </div>
    </div>

    <div class="col-lg-3">
      <a href="notifications.php" class="card-counter warning" style="display:block; text-decoration:none; color:#FFF;"
         title="Unread alerts — deposits recorded by your couples">
        <i class="mdi mdi-bell-ring"></i>
        <span class="count-numbers"><?=  $notif_unread; ?></span>
        <span class="count-name">Unread Alerts</span>
      </a>
    </div>
  </div>

<?php if ($funnel !== null) { ?>
<div class="card mt-4 mb-3" style="border:1px solid #E2E8F0; border-radius:14px;">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap" style="border-bottom:1px solid #E2E8F0;">
    <div>
      <strong style="font-family:'Plus Jakarta Sans',sans-serif;"><i class="mdi mdi-filter-variant mr-1"></i>Trial Funnel</strong>
      <span class="text-muted" style="font-size:12.5px;">— self-serve planner signups, verification and activation</span>
    </div>
    <ul class="nav nav-pills" style="font-size:12.5px;">
      <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label) { ?>
        <li class="nav-item">
          <a class="nav-link <?= $d === $funnel['window_days'] ? 'active' : '' ?>"
             href="dashboard.php?days=<?= $d ?>"><?= $label ?></a>
        </li>
      <?php } ?>
    </ul>
  </div>
  <div class="card-body">
    <div class="row text-center mb-3">
      <?php
      $stages = [
        ['signups', 'Signups', 'mdi-account-plus', '#26c6da'],
        ['verified', 'Verified', 'mdi-email-check', '#007bff'],
        ['active_trials', 'Active Trials', 'mdi-timer-sand-empty', '#f0ad4e'],
        ['converted', 'Converted to Paid', 'mdi-cash-multiple', '#66bb6a'],
        ['expired_unpaid', 'Expired, Never Paid', 'mdi-cancel', '#ef5350'],
      ];
      foreach ($stages as [$key, $label, $icon, $color]) { ?>
        <div class="col">
          <div style="padding:10px 4px;">
            <i class="mdi <?= $icon ?>" style="font-size:1.6em; color:<?= $color ?>;"></i>
            <div style="font-size:1.55rem; font-weight:700; line-height:1.2;"><?= (int) $funnel[$key] ?></div>
            <div style="font-size:11.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em;"><?= $label ?></div>
          </div>
        </div>
      <?php } ?>
    </div>

    <div class="row">
      <div class="col-md-6">
        <h6 class="mb-2" style="font-size:12.5px; color:#64748B;">SIGNUPS PER DAY</h6>
        <?php if ($funnel['per_day'] === []) { ?>
          <p class="text-muted mb-0" style="font-size:13px;">No self-serve signups in this window yet.</p>
        <?php } else {
          $max = max(1, max($funnel['per_day']));
          $shown = array_slice($funnel['per_day'], -21, null, true); // last 21 days max
          foreach ($shown as $day => $n) { ?>
            <div class="d-flex align-items-center mb-1">
              <span style="width:72px; font-size:11.5px; color:#64748B;"><?= htmlspecialchars((string) $day) ?></span>
              <div style="flex:1; background:#F1F5F9; border-radius:4px; height:16px;">
                <div style="width:<?= round($n / $max * 100) ?>%; background:linear-gradient(90deg,#065F46,#059669); height:16px; border-radius:4px;"></div>
              </div>
              <span style="width:30px; text-align:right; font-size:12px; font-weight:600;"><?= (int) $n ?></span>
            </div>
          <?php }
          if (count($funnel['per_day']) > 21) { ?>
            <p class="text-muted mb-0" style="font-size:11.5px;">… oldest <?= count($funnel['per_day']) - 21 ?> days hidden</p>
          <?php }
        } ?>
      </div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between" style="font-size:13px; padding:6px 0; border-bottom:1px solid #F1F5F9;">
          <span>Verification rate</span><strong><?= number_format($funnel['verified_pct'], 1) ?>%</strong>
        </div>
        <div class="d-flex justify-content-between" style="font-size:13px; padding:6px 0; border-bottom:1px solid #F1F5F9;">
          <span>Conversion to paid</span><strong><?= number_format($funnel['conversion_pct'], 1) ?>%</strong>
        </div>
        <div class="d-flex justify-content-between" style="font-size:13px; padding:6px 0; border-bottom:1px solid #F1F5F9;">
          <span>Plans bought</span>
          <strong><?php
            echo $funnel['plans'] === []
              ? '<span class="text-muted" style="font-weight:400;">none yet</span>'
              : implode(', ', array_map(fn($p, $n) => ucfirst($p) . ' × ' . $n, array_keys($funnel['plans']), $funnel['plans']));
          ?></strong>
        </div>
        <div class="mt-3" style="max-height:190px; overflow-y:auto;">
          <table class="table table-sm mb-0" style="font-size:12px;">
            <thead><tr><th>Planner</th><th>Signed up</th><th>Stage</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($funnel['rows'], 0, 50) as $r) { ?>
              <tr>
                <td title="<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['name'] !== '' ? $r['name'] : $r['email']) ?></td>
                <td><?= htmlspecialchars($r['signup_date']) ?></td>
                <td><?php
                  if ($r['paid']) {
                    echo '<span class="badge badge-success">' . ucfirst($r['paid_plan']) . '</span>';
                  } elseif ($r['trial']) {
                    echo '<span class="badge badge-warning">Trial</span>';
                  } elseif (!$r['verified']) {
                    echo '<span class="badge badge-secondary">Unverified</span>'; 
                  } elseif ($r['expired_unpaid']) {
                    echo '<span class="badge badge-danger">Expired</span>';
                  } else {
                    echo '<span class="badge badge-light">—</span>'; 
                  }
                ?></td>
              </tr>
            <?php } ?>
            <?php if ($funnel['rows'] === []) { ?>
              <tr><td colspan="3" class="text-muted text-center" style="padding:14px;">No self-serve planners in this window yet.</td></tr>
            <?php } ?>
            </tbody>
          </table>
          <?php if (count($funnel['rows']) > 50) { ?>
            <p class="text-muted mb-0" style="font-size:11.5px;">… showing 50 of <?= count($funnel['rows']) ?></p>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php } ?>




<?php include_once 'include/footer.php';?>