<?php include 'include/init.php'; ?>

<?php
    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }
    // Client sessions may only open their OWN account detail (staff see all).
    if (!is_staff() && (int) ($_GET['user_id'] ?? 0) !== (int) $_SESSION['id']) {
        redirect_to("client.php");
    }
     $booking_id = (int) ($_GET['booking_id'] ?? 0);
     $user_id = (int) ($_GET['user_id'] ?? 0);
     $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;
     $booking =  Booking::find_by_booking_id($booking_id);
     $guest_count =  Guest::count_guest($booking_id);
     $account_details = Account_Details::find_by_user_id($user_id);
     $category_details = Category::find_by_id($booking->wedding_type ?? 0);
     $features_details = Features::find_by_feature_all_a($category_details->id ?? 0);

     // Total paid across every payment method (M-Pesa + cash + bank).
     $amount_paid = Liquidation::getTotalAmount($booking_id);
     $package_price = (float) ($category_details->price ?? 0);
     $balance_due = max(0.0, $package_price - $amount_paid);

     global $db;
    $bdd = $db->pdo();
    $sql = "SELECT id, title, location, start, end, color FROM events WHERE booking_id = :bid ORDER BY start ASC";
    $req = $bdd->prepare($sql);
    $req->execute([':bid' => $booking_id]);

    $events = $req->fetchAll(PDO::FETCH_ASSOC);
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= is_staff() ? 'Booking Overview - Everafter Planner' : 'My Wedding Overview - Everafter Planner'; ?></title>
    <link href='calendar/all.css' rel='stylesheet'>
    <link href='calendar/fullcalendar.min.css' rel='stylesheet' />
    <link href='calendar/fullcalendar.print.min.css' rel='stylesheet' media='print' />
    <script src='calendar/moment.min.js'></script>
    <script src='calendar/jquery.min.js'></script>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css"
          href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <script src='calendar/fullcalendar.min.js'></script>
    <link rel="stylesheet" href="css/font-awesome.min.css">
    <style>
        #calendar {
            max-width:100%;
            margin: 0px auto;
            padding: 20px 20px;
            background: white;

        }
        .col-centered{
            float: none;
            margin: 0 auto;
        }
         .fc-content {
            background: white;
            color: black;
            padding:3px;

        }

        .fc-title {
            text-transform: capitalize;
        }

        /* .btn-primary {
            background-color: #17B4BC;
            border-color: #17B4BC;
        }

        .btn-primary.disabled, .btn-primary:disabled {
            background-color: #17B4BC;
            border-color: #17B4BC;
        } */

        .list-group-item.active{
            border-color:#ff000000;
        }

    </style>
</head>

<body>

<?php include_once 'include/sidebar.php'; ?>


<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h6 class="h6 mt-4 text-uppercase" style="margin-bottom:0;">
        <?php if ($booking && ($booking->bride !== '' || $booking->groom !== '')): ?>
            Overview — <?= htmlspecialchars(trim($booking->bride . ' & ' . $booking->groom)); ?>
        <?php else: ?>
            Booking Overview
        <?php endif; ?>
        <?php if (!empty($booking->wedding_date)): ?>
            <small class="text-muted d-block" style="font-size:11px; letter-spacing:.04em; text-transform:none;">
                <i class="mdi mdi-calendar-check mr-1"></i><?= htmlspecialchars(date('l, j F Y', strtotime($booking->wedding_date))); ?>
            </small>
        <?php endif; ?>
    </h6>

    <div class="btn-toolbar mb-2 mb-md-0">
        <div class="btn-group mr-2">
            <a class="btn btn-sm btn-primary mr-2" style="font-size: 12px;" href="client_manage_account_details.php?<?= $links; ?>"><i class="mdi mdi-buffer mr-2"></i> Overview</a>
            <?php if (is_staff()): ?>
                <a class="btn btn-sm btn-info mr-2 " style="font-size: 12px;" href="guest_list.php?<?= $links; ?>"><i class="mdi mdi-account-network mr-2"></i> Master List Guest</a>
                <a class="btn btn-sm btn-success mr-2 " style="font-size: 12px;" href="budget.php?<?= $links; ?>"><i class="mdi mdi-currency-usd mr-2"></i> Budget</a>
                <a class="btn btn-sm btn-warning mr-2 " style="font-size: 12px;" href="task_calendar.php?<?= $links; ?>"><i class="mdi mdi-calendar-text mr-2"></i> Task Calendar</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-success" style="font-size: 12px;" href="../contact.php"><i class="mdi mdi-email-outline mr-2"></i> Contact Planner</a>
            <?php endif; ?>
        </div>
    </div>
</div>


<div class="row">
    <div class="col-md-12">
        <div class="row">
            <div class="col-md-3">
                <div class="card text-white bg-dark mb-3" style="max-width: 18rem;">
                    <div class="card-header">Total Guests</div>
                    <div class="card-body">
                        <h5 class="card-title"><span class="count"><?= $guest_count; ?></span></h5>
                        <p class="card-text"></p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-white bg-dark mb-3" style="max-width: 18rem;">
                    <div class="card-header">Package Price</div>
                    <div class="card-body">
                        <h5 class="card-title"><?= format_ksh($package_price, 2); ?></h5>
                        <p class="card-text"></p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-white bg-dark mb-3" style="max-width: 18rem;">
                    <div class="card-header"> Amount Paid</div>
                    <div class="card-body">
                        <h5 class="card-title"><?= format_ksh($amount_paid, 2); ?></h5>
                        <p class="card-text"></p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-white bg-dark mb-3" style="max-width: 18rem;">
                    <div class="card-header">Balance Due</div>
                    <div class="card-body">
                        <h5 class="card-title"><?= format_ksh($balance_due, 2); ?></h5>
                        <p class="card-text"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12 mb-3">
        <ul class="list-group">
            <li class="list-group-item active" style="background:#065F46;border-color:#065F46;"><?= htmlspecialchars($category_details->wedding_type ?? 'Your'); ?> Package Includes:</li>
            <?php if (empty($features_details)) : ?>
                <li class="list-group-item text-muted">Package features are being finalised — our team will share the full list with you.</li>
            <?php else : ?>
                <?php foreach ($features_details as $feature_item) : ?>
                    <li class="list-group-item"><i class="mdi mdi-checkbox-marked mr-2" style="font-size: 16px;"></i><?= htmlspecialchars($feature_item->title); ?></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul> 
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <div id="calendar"></div>
    </div>
</div>
</main>
</div>
</div>

<!-- Bootstrap core JavaScript
================================================== -->
<!-- Placed at the end of the document so the pages load faster -->
<!-- <script src="js/jquery-3.2.1.slim.min.js"></script> -->
<script src="js/popper.min.js"></script>
<!-- <script src="../js/jquery.min.js"></script> -->
<script src="js/bootstrap.min.js"></script>
<script>
  
    $(document).ready(function() {
        $('.count').each(function () {
            $(this).prop('Counter',0).animate({
                Counter: $(this).text()
            }, {
                duration: 4000,
                easing: 'swing',
                step: function (now) {
                    $(this).text(Math.ceil(now));
                }
            });
        });

        // $('#wedding_date').datepicker();
        $('[data-toggle="tooltip"]').tooltip();
    });
    
</script>
<script>

    $(document).ready(function() {
        
        $('#calendar').fullCalendar({
            themeSystem: 'bootstrap4',
            header: {
                left: 'prev,next today',
                center: 'title',
                right: 'month,agendaWeek,agendaDay,listMonth'
            },
            // defaultDate: '2016-01-12',
            // editable: true,
            eventLimit: true, // allow "more" link when too many events
            selectable: false, // read-only overview — editing happens on the staff Task Calendar
            selectHelper: true,
            select: function(start, end) {
                
                $('#ModalAdd #start').val(moment(start).format('YYYY-MM-DD HH:mm:ss'));
                $('#ModalAdd #end').val(moment(end).format('YYYY-MM-DD HH:mm:ss'));
                $('#ModalAdd').modal('show');
            },
            eventRender: function(event, element) {
                element.bind('dblclick', function() {
                    $('#ModalEdit #id').val(event.id);
                    $('#ModalEdit #title').val(event.title);
                    $('#ModalEdit #location').val(event.location);
                    $('#ModalEdit #color').val(event.color);
                    $('#ModalEdit').modal('show');
                });
            },
            eventDrop: function(event, delta, revertFunc) { // si changement de position

                edit(event);

            },
            eventResize: function(event,dayDelta,minuteDelta,revertFunc) { // si changement de longueur

                edit(event);

            },
            events: [
            <?php foreach($events as $event): 
            
                $raw_start = (string) $event['start'];
                $raw_end   = (string) ($event['end'] ?? '');
                $start = (substr($raw_start, 11) === '00:00:00') ? substr($raw_start, 0, 10) : $raw_start;
                $end   = ($raw_end !== '' && substr($raw_end, 11) === '00:00:00') ? substr($raw_end, 0, 10) : $raw_end;
            ?>
                {
                    id: <?= (int) $event['id']; ?>,
                    title: <?= json_encode($event['title'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
                    location: <?= json_encode($event['location'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
                    start: <?= json_encode($start); ?>,
                    end: <?= json_encode($end); ?>,
                    color: <?= json_encode($event['color']); ?>,
                },
            <?php endforeach; ?>
            ]
        });
        
        function edit(event){
            start = event.start.format('YYYY-MM-DD HH:mm:ss');
            if(event.end){
                end = event.end.format('YYYY-MM-DD HH:mm:ss');
            }else{
                end = start;
            }
            
            id =  event.id;
            
            Event = [];
            Event[0] = id;
            Event[1] = start;
            Event[2] = end;
            
            jQuery.ajax({
             url: 'task_editEventDate.php',
             type: "POST",
             data: {Event:Event},
             success: function(rep) {
                    if(rep == 'OK'){
                        alert('Saved');
                    }else{
                        alert('Could not be saved. try again.'); 
                    }
                }
            });
        }
        
    });



</script>

</body>
</html>