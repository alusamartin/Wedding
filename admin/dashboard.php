<?php include 'include/init.php'; ?>
<?php
     if (!is_staff()) {
         redirect_to("../");
     }

    $user_count =  Account_Details::count_user();
    $booking_count =  Booking::count_booking();
    $gallery_count =  Gallery::count_all();
    $event_post =  EventWedding::count_all();
    // Unread alerts for THIS staff user — surfaces deposits recorded by
    // couples while the planner was away. Links to the full archive.
    $notif_unread = Notification::unread_count((int) $_SESSION['id']);
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard - Administrator</title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap4.min.css" rel="stylesheet">
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




<?php include_once 'include/footer.php';?>