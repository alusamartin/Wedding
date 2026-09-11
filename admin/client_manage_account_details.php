<?php include 'include/init.php'; ?>

<?php
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
    }
    $booking = Booking::find_by_booking_id($booking_id);
    if (!is_staff()) {
        // Client sessions may only open their OWN booking. Checking the booking
        // row itself (not just the user_id param) closes an IDOR where a client
        // could pass their own user_id with someone else's booking_id.
        if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
            redirect_to("client.php");
        }
    }
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

    // Countdown + booking-status banner (the couple's "where do I stand" moment).
    // Booking status lives on the account row (tblaccounts_detail.status), not the
    // wedding row, so look it up there.
    $account_status_row = Account_Details::find_by_user_id($user_id);
    $is_confirmed = strtolower(trim((string) ($account_status_row->status ?? ''))) === 'confirm';
    $countdown_days = null;
    $countdown_headline = '';
    $countdown_sub = '';
    if (!empty($booking->wedding_date) && ($wts = strtotime((string) $booking->wedding_date)) !== false) {
        $today = strtotime(date('Y-m-d'));
        $wedding_day = strtotime(date('Y-m-d', $wts));
        $countdown_days = (int) round(($wedding_day - $today) / 86400);
        if ($countdown_days > 0) {
            $countdown_headline = $countdown_days . ' day' . ($countdown_days === 1 ? '' : 's') . ' to go';
            $countdown_sub = $countdown_days <= 7
                ? 'The final week — your planner is putting the finishing touches in place.'
                : 'Plenty of time to plan every detail, one step at a time.';
        } elseif ($countdown_days === 0) {
            $countdown_headline = 'Today is the day!';
            $countdown_sub = ' Congratulations from all of us at Everafter Planner.';
        } else {
            $countdown_headline = 'Happily married';
            $countdown_sub = 'Thank you for letting us be part of your story.';
        }
    }

    // Open the calendar on the wedding month (or the next scheduled ceremony),
    // not today — couples book 12-18 months out and today is usually empty.
    $wedding_default = null;
    if (!empty($booking->wedding_date) && strtotime($booking->wedding_date) !== false) {
        $wedding_default = date('Y-m-d', strtotime($booking->wedding_date));
    } elseif (!empty($events)) {
        foreach ($events as $ev) {
            if (!empty($ev['start']) && strtotime($ev['start']) >= time()) {
                $wedding_default = date('Y-m-d', strtotime($ev['start']));
                break;
            }
        }
        if ($wedding_default === null && !empty($events[0]['start'])) {
            $wedding_default = date('Y-m-d', strtotime($events[0]['start']));
        }
    }

    // Deposit nudge — the stat cards show the balance; this tells the couple
    // what to DO about it (first deposit, keep going, final stretch, done)
    // and deep-links the M-Pesa flow. Couples only: staff have the full
    // Budget page, and client_deposit.php is couple-scoped anyway.
    $harambee_pledged = Guest::total_harambee($booking_id);
    $nudge_kind = ''; // '' | 'pending' | 'progress' | 'final' | 'paid'
    $nudge_pct  = 0;
    if ($package_price > 0) {
        $nudge_pct = (int) floor(min(100.0, $amount_paid / $package_price * 100));
        if ($balance_due <= 0) {
            $nudge_kind = 'paid';
        } elseif ($amount_paid <= 0) {
            $nudge_kind = 'pending';
        } elseif ($balance_due <= 0.10 * $package_price) {
            $nudge_kind = 'final';
        } else {
            $nudge_kind = 'progress';
        }
    }

    // Deadline escalation — the same balance reads differently at 8 months
    // than at 8 days. Before the final two months the nudge suggests a
    // monthly pace that lands the package fully paid ~30 days early (the
    // window most Kenyan vendors expect final settlement); inside 30 days it
    // firms up with a red accent, and after the day it becomes a settle-up
    // note rather than a push.
    $nudge_milestone = '';
    $nudge_urgent    = false;
    if ($nudge_kind !== '' && $nudge_kind !== 'paid' && $countdown_days !== null) {
        if ($countdown_days > 60) {
            $months = max(1, (int) ceil(($countdown_days - 30) / 30));
            $nudge_milestone = 'Pace yourself: about ' . format_ksh($balance_due / $months)
                . ' a month clears the balance a month before the big day.';
        } elseif ($countdown_days > 30) {
            $nudge_milestone = 'Aim to clear the balance by '
                . date('j M Y', strtotime('+' . ($countdown_days - 30) . ' days'))
                . " — most vendors expect final settlement a month out.";
        } elseif ($countdown_days > 7) {
            $nudge_urgent    = true;
            $nudge_milestone = 'Final month — vendors are confirmed and settled now. Clearing '
                . format_ksh($balance_due) . ' keeps everything on schedule.';
        } elseif ($countdown_days >= 0) {
            $nudge_urgent    = true;
            $nudge_milestone = 'The wedding is ' . ($countdown_days === 0 ? 'today' : 'this week')
                . ' — the remaining ' . format_ksh($balance_due) . ' needs settling in the next few days.';
        } else {
            $nudge_milestone = 'Your big day has passed — settle the remaining '
                . format_ksh($balance_due) . ' with your planner when you can.';
        }
    }

    // Opt-in installment plan: when the couple committed to a month-by-month
    // pace, the nudge tracks THEIR plan (installment X of N, next due date)
    // instead of the generic deadline bands.
    $plan_nudge = InstallmentPlan::nudge_for($booking_id, $amount_paid, $package_price);
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

        .countdown-banner {
            background: linear-gradient(135deg, #064E3B 0%, #059669 100%);
            border-radius: 16px;
            color: #fff;
            padding: 22px 26px;
            margin-bottom: 18px;
            box-shadow: 0 10px 24px rgba(6, 78, 59, .18);
        }
        .countdown-banner .cd-days { font-size: 34px; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; font-family: 'Plus Jakarta Sans','Inter',sans-serif; }
        .countdown-banner .cd-sub { font-size: 13px; opacity: .85; margin-top: 2px; }
        .countdown-banner .cd-couple { font-size: 15px; font-weight: 700; margin: 0; }
        .countdown-banner .cd-date { font-size: 12.5px; opacity: .8; }
        .countdown-banner .cd-status { font-size: 12px; font-weight: 700; border-radius: 999px; padding: 6px 14px; display: inline-block; }
        .cd-status-confirmed { background: rgba(255,255,255,.22); }
        .cd-status-pending { background: #FBBF24; color: #78350F; }
        .cd-status-note { font-size: 12.5px; opacity: .9; margin-top: 6px; }

        .pay-nudge { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px;
                     padding: 14px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border-left: 4px solid #D97706; }
        .pay-nudge--paid     { border-left-color: #059669; }
        .pay-nudge--progress { border-left-color: #D97706; }
        .pay-nudge--final    { border-left-color: #B45309; }
        .pay-nudge--pending  { border-left-color: #9CA3AF; }
        .pay-nudge--urgent   { border-left-color: #DC2626; }
        .pay-nudge--urgent .pn-fill { background: linear-gradient(90deg, #DC2626, #F97316); }
        .pay-nudge .pn-head { font-size: 13.5px; color: #334155; }
        .pay-nudge .pn-bar { height: 8px; background: #E2E8F0; border-radius: 999px; margin: 10px 0 8px; overflow: hidden; }
        .pay-nudge .pn-fill { height: 100%; background: linear-gradient(90deg, #059669, #0EA5A0); border-radius: 999px; transition: width .6s ease; }
        .pay-nudge--paid .pn-fill { background: #059669; }
        .pay-nudge .pn-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .pay-nudge .pn-note { font-size: 12.5px; color: #64748B; }
        .pay-nudge .pn-milestone { font-size: 12.5px; color: #64748B; margin-top: 8px; }
        .pay-nudge--urgent .pn-milestone { color: #B91C1C; }
        .pay-nudge .pn-plan { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #E2E8F0; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .pay-nudge .pn-plan-label { font-size: 12px; font-weight: 700; color: #065F46; letter-spacing: .02em; }
        .pay-nudge .pn-plan-form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .pay-nudge .pn-plan-form select { font-size: 12.5px; padding: 4px 8px; border: 1px solid #CBD5E1; border-radius: 8px; }
        @media (max-width: 767px) { .countdown-banner { padding: 18px; } .countdown-banner .cd-days { font-size: 27px; } }

    </style>
</head>

<body>

<?php include_once 'include/sidebar.php'; ?>

<?php if ($booking && ($countdown_headline !== '' || !empty($booking->wedding_date))): ?>
<div class="row no-gutters mt-4">
    <div class="col-md-12">
        <div class="countdown-banner d-flex flex-wrap justify-content-between align-items-center">
            <div class="mr-4 mb-2 mb-md-0">
                <p class="cd-couple">
                    <?= htmlspecialchars(trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &')) ?: 'Your Wedding'; ?>
                </p>
                <div class="cd-date">
                    <i class="mdi mdi-calendar-heart mr-1"></i><?= htmlspecialchars(date('l, j F Y', strtotime((string) $booking->wedding_date))); ?>
                    <?php $cat_name = $category_details->wedding_type ?? ''; if ($cat_name !== ''): ?>
                        &nbsp;&middot;&nbsp;<i class="mdi mdi-gift mr-1"></i><?= htmlspecialchars((string) $cat_name); ?>
                    <?php endif; ?>
                </div>
                <?php if ($is_confirmed): ?>
                    <div class="cd-status-note"><i class="mdi mdi-check-decagram mr-1"></i>Your date is locked in — your planner is on it.</div>
                <?php elseif ($countdown_days === null || $countdown_days >= 0): ?>
                    <div class="cd-status-note"><i class="mdi mdi-clock-outline mr-1"></i>Awaiting confirmation — our team is reviewing your date and will reach out shortly.</div>
                <?php endif; ?>
            </div>
            <div class="text-md-right">
                <div class="cd-days"><?= $countdown_headline !== '' ? htmlspecialchars($countdown_headline) : 'Date to be set'; ?></div>
                <div class="cd-sub"><?= htmlspecialchars($countdown_sub !== '' ? $countdown_sub : 'Pick a date with your planner to start the countdown.'); ?></div>
                <span class="cd-status mt-2 <?= $is_confirmed ? 'cd-status-confirmed' : 'cd-status-pending'; ?>">
                    <?= $is_confirmed ? 'Confirmed' : 'Pending Confirmation'; ?>
                </span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


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
                <a class="btn btn-sm btn-success mr-2 " style="font-size: 12px;" href="budget.php?<?= $links; ?>"><i class="mdi mdi-cash-multiple mr-2"></i> Budget</a>
                <a class="btn btn-sm btn-warning mr-2 " style="font-size: 12px;" href="task_calendar.php?<?= $links; ?>"><i class="mdi mdi-calendar-text mr-2"></i> Task Calendar</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-info mr-2 " style="font-size: 12px;" href="client_guests.php?<?= $links; ?>"><i class="mdi mdi-account-network mr-2"></i> My Guests</a>
                <a class="btn btn-sm btn-outline-primary mr-2 " style="font-size: 12px;" href="client_checklist.php?<?= $links; ?>"><i class="mdi mdi-checkbox-marked-outline mr-2"></i> Checklist</a>
                <a class="btn btn-sm btn-outline-secondary mr-2 " style="font-size: 12px;" href="client_website.php?<?= $links; ?>"><i class="mdi mdi-web mr-2"></i> Wedding Page</a>
                <a class="btn btn-sm btn-outline-success mr-2 " style="font-size: 12px;" href="client_budget.php?<?= $links; ?>"><i class="mdi mdi-cash-multiple mr-2"></i> My Payments</a>
                <a class="btn btn-sm btn-outline-warning mr-2 " style="font-size: 12px;" href="client_vendors.php?<?= $links; ?>"><i class="mdi mdi-store mr-2"></i> Vendors</a>
                <a class="btn btn-sm btn-outline-secondary" style="font-size: 12px;" href="../contact.php"><i class="mdi mdi-email-outline mr-2"></i> Contact Planner</a>
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

<?php if ($nudge_kind !== ''): ?>
<div class="row">
    <div class="col-md-12 mb-3">
        <div class="pay-nudge pay-nudge--<?= $nudge_kind; ?><?= $nudge_urgent ? ' pay-nudge--urgent' : ''; ?>">
            <div class="pn-head">
                <i class="mdi <?= $nudge_kind === 'paid' ? 'mdi-check-decagram' : 'mdi-hand-coin-outline'; ?> mr-1"></i>
                <?php if ($nudge_kind === 'paid'): ?>
                    Fully paid — Hongera! Your package is covered; your planner will guide you on any extras.
                <?php elseif ($nudge_kind === 'pending'): ?>
                    Ready when you are — your first deposit secures the plan. Balance: <strong><?= format_ksh($balance_due, 2); ?></strong>
                <?php elseif ($nudge_kind === 'final'): ?>
                    So close — <?= $nudge_pct; ?>% paid, only <strong><?= format_ksh($balance_due, 2); ?></strong> to go.
                <?php else: ?>
                    <?= $nudge_pct; ?>% paid — <strong><?= format_ksh($balance_due, 2); ?></strong> to go.
                <?php endif; ?>
            </div>
            <div class="pn-bar"><div class="pn-fill" style="width: <?= $nudge_pct; ?>%;"></div></div>
            <?php if ($nudge_kind !== 'paid' && !is_staff()): ?>
            <div class="pn-actions">
                <a class="btn btn-sm btn-success" href="client_deposit.php?<?= $links; ?>"><i class="mdi mdi-cellphone-arrow-down mr-1"></i>Pay via M-Pesa</a>
                <?php if ($harambee_pledged > 0): ?>
                    <span class="pn-note"><i class="mdi mdi-hand-heart mr-1"></i>Your committee has pledged <?= format_ksh($harambee_pledged, 2); ?> toward this balance.</span>
                <?php elseif ($nudge_kind === 'pending'): ?>
                    <span class="pn-note">Deposits start from KSh 50 — pay in small, comfortable steps.</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($plan_nudge !== null): ?>
            <div class="pn-milestone"><i class="mdi mdi-calendar-check mr-1"></i><?= htmlspecialchars($plan_nudge['head']); ?>
                <br><span style="font-size:12px; color:#64748B;"><?= htmlspecialchars($plan_nudge['note']); ?></span></div>
            <?php elseif ($nudge_milestone !== ''): ?>
            <div class="pn-milestone"><i class="mdi mdi-flag-checkered mr-1"></i><?= htmlspecialchars($nudge_milestone); ?></div>
            <?php endif; ?>
            <?php if ($nudge_kind !== 'paid' && $package_price > 0 && $balance_due > 0): ?>
            <div class="pn-plan">
                <?php $active_plan = InstallmentPlan::for_booking($booking_id); ?>
                <?php if ($active_plan): ?>
                    <span class="pn-plan-label"><i class="mdi mdi-split-vertical mr-1"></i>Plan: <?= (int) $active_plan->months; ?> × <?= format_ksh($active_plan->monthly_amount); ?>/mo</span>
                    <form method="post" action="client_installment_plan.php?<?= $links; ?>" style="display:inline;">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="cancel" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" style="font-size:11px; padding:2px 10px;">Cancel plan</button>
                    </form>
                <?php else: ?>
                    <span class="pn-plan-label"><i class="mdi mdi-split-vertical mr-1"></i>Spread it monthly:</span>
                    <form method="post" action="client_installment_plan.php?<?= $links; ?>" class="pn-plan-form">
                        <?= $session->csrf_field(); ?>
                        <select name="months" aria-label="Installment months">
                            <?php foreach (InstallmentPlan::MONTHS_CHOICES as $m): ?>
                                <option value="<?= $m; ?>"><?= $m; ?> months · <?= format_ksh(ceil($balance_due / $m / 50) * 50); ?>/mo</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-success" style="font-size:11px; padding:2px 10px;">Set my plan</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
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
        <?php if ($wedding_default !== null): ?>
        <button type="button" class="btn btn-sm btn-outline-primary mb-2" id="jumpToWedding" data-date="<?= $wedding_default; ?>">
            <i class="mdi mdi-calendar-heart mr-1"></i> Jump to Wedding Day
        </button>
        <?php endif; ?>
        <div id="calendar"></div>
    </div>
</div>
<script>
    $(function () {
        $('#jumpToWedding').on('click', function () {
            $('#calendar').fullCalendar('gotoDate', $(this).data('date'));
        });
    });
</script>
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
            defaultDate: <?= json_encode($wedding_default); ?>,
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