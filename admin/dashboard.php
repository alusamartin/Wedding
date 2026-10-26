<?php include 'include/init.php'; ?>
<?php
     require_staff_subscribed();

    /*
     * "Total Customers" means the couples THIS planner can open, not every
     * account on the platform — the tile sits beside a booking count that has
     * been scoped since per-planner scoping shipped, and the two disagreeing
     * was both a small information leak (a planner could read platform size)
     * and a wrong number for the person reading it. Super admins and installs
     * with scoping off keep the platform-wide count, exactly as before.
     */
    $user_count = planner_scope_planner_id() > 0
        ? Booking::count_couples(planner_scope_sql('organizer_id'))
        : Account_Details::count_user();
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

    /*
     * ------------------------------------------------------------------
     * Dashboard v2: what is actually waiting, in numbers, before the page
     * shows any totals.
     *
     * Each of these is the SAME count and the SAME link as the sidebar badge
     * or the queue page it opens — `VendorThread::unread_total()` is what the
     * menu uses, `Proposal::open_count()` is what the proposals queue uses,
     * and `McpApprovals::pending_count()` is what the approvals page uses.
     * A dashboard that counts differently from the page it links to teaches
     * people to distrust both, so nothing is re-derived here.
     * ------------------------------------------------------------------
     */
    $dashThreadUnread = 0;
    $dashThreadHref   = 'vendor_enquiries.php';
    try {
        $dashThreadUnread = VendorThread::unread_total(null, (int) $_SESSION['id']);
        if ($dashThreadUnread > 0) {
            $dashThreadDeal = VendorThread::oldest_unread(null, (int) $_SESSION['id']);
            if ($dashThreadDeal > 0) {
                $dashThreadHref = VendorThread::opener_url($dashThreadDeal);
            }
        }
    } catch (Throwable $e) {
        $dashThreadUnread = 0; // threads not migrated yet: no card, not a broken page
    }

    $dashProposals = 0;
    try {
        $dashProposals = Proposal::open_count(planner_scope_planner_id());
    } catch (Throwable $e) {
        $dashProposals = 0;
    }

    $dashApprovals = 0;
    try {
        $dashApprovals = McpApprovals::pending_count();
    } catch (Throwable $e) {
        $dashApprovals = 0;
    }

    /* Operator-only queues. A planner never sees another tenant's payments or
     * the platform's cron health, so these stay zero for them and their cards
     * are not rendered at all. */
    $dashMpesa = 0;
    $dashJobs  = 0;
    if (is_super_admin()) {
        try {
            $dashMpesa = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM tblmpesa_callbacks WHERE verified_at IS NULL AND reject_reason IS NULL'
            );
        } catch (Throwable $e) {
            $dashMpesa = 0;
        }
        try {
            $dashJobs = JobWatch::troubled_count();
        } catch (Throwable $e) {
            $dashJobs = 0;
        }
    }

    /*
     * The weddings this dashboard is about — the same scoped list the bookings
     * page shows, ordered by the date that actually matters to the reader.
     */
    $dashBookings = array_merge(Booking::getBooking(), Booking::ConfirmedBooking());
    if (planner_scope_planner_id() > 0) {
        $dashBookings = array_values(array_filter($dashBookings, 'booking_visible_to_session'));
    }
    $dashUpcoming = [];
    $today = date('Y-m-d');
    foreach ($dashBookings as $dashBooking) {
        $when = (string) ($dashBooking->wedding_date ?? '');
        if ($when === '' || strtotime($when) === false || substr($when, 0, 10) < $today) {
            continue;
        }
        $dashUpcoming[] = $dashBooking;
    }
    usort($dashUpcoming, static fn($a, $b) => strcmp((string) $a->wedding_date, (string) $b->wedding_date));
    $dashUpcoming = array_slice($dashUpcoming, 0, 4);
    $dashWeddingCount = count($dashBookings);

    /*
     * WEDDINGS NEEDING A NUDGE.
     *
     * "Next weddings" answers *when*, this answers *which ones are drifting*.
     * A wedding earns a place here from facts the planner would otherwise
     * discover one page at a time — a checklist nobody opened, milestones past
     * their date, no planner assigned, money still owed inside the final six
     * weeks. Only weddings with at least one flag are listed, so a healthy book
     * renders nothing at all rather than a table of green ticks.
     *
     * Every figure is read with the same helper its own page uses (Checklist,
     * Liquidation, Category), and nothing is written: opening the dashboard
     * cannot seed a checklist or touch a booking.
     */
    $dashNudges = [];
    foreach ($dashBookings as $nudgeBooking) {
        $nudgeWhen = (string) ($nudgeBooking->wedding_date ?? '');
        if ($nudgeWhen === '' || strtotime($nudgeWhen) === false || substr($nudgeWhen, 0, 10) < $today) {
            continue;
        }
        $nudgeId    = (int) $nudgeBooking->booking_id;
        $nudgeDays  = (int) floor((strtotime(date('Y-m-d', strtotime($nudgeWhen))) - strtotime($today)) / 86400);
        $nudgePlan  = Checklist::progress($nudgeId, $nudgeWhen);
        $nudgeFlags = [];
        if (!$nudgePlan['seeded']) {
            $nudgeFlags[] = ['is-warn', 'Checklist not started'];
        } elseif ((int) $nudgePlan['overdue'] > 0) {
            $nudgeFlags[] = ['is-danger', (int) $nudgePlan['overdue'] . ' overdue'];
        }
        if ((int) ($nudgeBooking->organizer_id ?? 0) <= 0) {
            $nudgeFlags[] = ['is-warn', 'No planner assigned'];
        }
        $nudgeCat   = Category::find_by_id((int) ($nudgeBooking->wedding_type ?? 0));
        $nudgePrice = $nudgeCat ? (float) $nudgeCat->price : 0.0;
        $nudgePaid  = (float) Liquidation::getTotalAmount($nudgeId);
        if ($nudgePrice > 0 && $nudgePaid < $nudgePrice && $nudgeDays <= 45) {
            $nudgeFlags[] = ['is-danger', format_ksh($nudgePrice - $nudgePaid) . ' due'];
        }
        if ($nudgeFlags !== []) {
            $dashNudges[] = [
                'booking' => $nudgeBooking,
                'days'    => $nudgeDays,
                'flags'   => $nudgeFlags,
            ];
        }
    }
    // Most flags first, then the nearest date: the wedding with four problems
    // three weeks out is the one to open before the one with one problem next year.
    usort($dashNudges, static function ($a, $b) {
        return count($b['flags']) <=> count($a['flags'])
            ?: ($a['days'] <=> $b['days']);
    });
    $dashNudges = array_slice($dashNudges, 0, 5);

    $dashAttention = $dashThreadUnread + $dashProposals + $dashApprovals + $dashMpesa + $dashJobs;
    $dashToday     = format_date_kd(date('Y-m-d'));
    $isOperator    = is_super_admin();
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
    <title><?= $isOperator ? 'Platform' : 'Planner'; ?> dashboard - Everafter Planner</title>

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


<section class="dash-hero" aria-labelledby="dashHeroTitle">
    <div>
        <p class="dash-hero-eyebrow"><?= htmlspecialchars($dashToday); ?></p>
        <h1 class="dash-hero-title" id="dashHeroTitle">Habari, <?= htmlspecialchars(ucfirst((string) $users_profile->firstname)); ?></h1>
        <p class="dash-hero-sub"><?php if ($isOperator): ?>You are running the platform: <strong><?= (int) $user_count; ?></strong> couples, <strong><?= (int) $booking_count; ?></strong> weddings<?php if ($dashAttention > 0): ?> &middot; <strong><?= (int) $dashAttention; ?></strong> thing<?= $dashAttention === 1 ? '' : 's'; ?> need you<?php endif; ?>.<?php elseif ($dashWeddingCount === 0): ?>No wedding is in your book yet &mdash; take a pending enquiry and it appears here with its dates and tools.<?php else: ?><strong><?= (int) $dashWeddingCount; ?></strong> wedding<?= $dashWeddingCount === 1 ? '' : 's'; ?> in your book &middot; <strong><?= (int) $notif_unread; ?></strong> unread alert<?= $notif_unread === 1 ? '' : 's'; ?><?php if ($dashAttention > 0): ?> &middot; <strong><?= (int) $dashAttention; ?></strong> waiting on a reply<?php endif; ?>.<?php endif; ?></p>
    </div>
    <div class="dash-hero-actions">
        <a class="btn btn-primary" href="<?= htmlspecialchars($dashThreadHref); ?>">
            <i class="mdi mdi-email-fast-outline mr-1"></i>
            <?= $dashThreadUnread > 0 ? 'Answer ' . (int) $dashThreadUnread . ' waiting' : 'Open enquiries'; ?>
        </a>
        <a class="btn btn-outline-secondary" href="client_add.php"><i class="mdi mdi-plus mr-1"></i>New booking</a>
    </div>
</section>

<?= subscription_banner(); ?>

<?php
/*
 * The strip answers one question before any total does: what needs me NOW?
 * Only queues with work in them become cards, and each card is the same count
 * and the same destination as the page it opens. Zero across the board gets a
 * calm sentence rather than five grey zeros.
 */
$attentionCards = [];
if ($dashThreadUnread > 0) {
    $attentionCards[] = ['is-warn', 'mdi-email-outline', $dashThreadUnread, 'Conversations waiting', 'A couple or vendor is waiting on your reply', $dashThreadHref];
}
if ($dashProposals > 0) {
    $attentionCards[] = ['', 'mdi-file-document-edit-outline', $dashProposals, 'Proposals to price', 'Couples waiting for package options', 'proposals.php'];
}
if ($dashApprovals > 0) {
    $attentionCards[] = ['', 'mdi-shield-check-outline', $dashApprovals, 'Approvals to review', 'AI actions paused for your sign-off', 'mcp_approvals.php'];
}
if ($dashMpesa > 0) {
    $attentionCards[] = ['is-warn', 'mdi-cellphone-key', $dashMpesa, 'Payments to verify', 'Unclaimed M-Pesa codes in the ledger', 'mpesa_ledger.php'];
}
if ($dashJobs > 0) {
    $attentionCards[] = ['is-danger', 'mdi-heart-pulse', $dashJobs, 'Jobs in trouble', 'A scheduled job is silent or failing', 'job_watch.php'];
}
?>
<?php if ($attentionCards !== []): ?>
<div class="attention-grid">
    <?php foreach ($attentionCards as [$tone, $icon, $count, $label, $hint, $href]): ?>
        <a class="attention-card <?= $tone; ?>" href="<?= htmlspecialchars($href); ?>">
            <span class="attention-icon"><i class="mdi <?= $icon; ?>" aria-hidden="true"></i></span>
            <span class="attention-body">
                <span class="attention-count"><?= (int) $count; ?></span>
                <span class="attention-label"><?= htmlspecialchars($label); ?></span>
                <span class="attention-hint"><?= htmlspecialchars($hint); ?></span>
            </span>
            <i class="mdi mdi-arrow-right attention-go" aria-hidden="true"></i>
        </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="attention-clear">
    <i class="mdi mdi-check-circle-outline" aria-hidden="true"></i>
    No enquiry, proposal or approval is waiting on you &mdash; unread alerts are updates, not work.
</div>
<?php endif; ?>
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

<!--
    Three numbers, each with the sentence that makes it mean something. The
    gallery and blog totals used to sit beside them as if they were business
    health; they are publishing counts, so they moved to one compact strip and
    the KPI row now answers "who am I serving, how much, and what is unread".
-->
<div class="row">
    <div class="col-lg-4">
        <div class="card-counter primary">
            <i class="mdi mdi-account-multiple" aria-hidden="true"></i>
            <span class="count-numbers"><?= (int) $user_count; ?></span>
            <span class="count-name"><?= $isOperator ? 'Total Customers' : 'Your Couples'; ?></span>
            <span class="count-note"><?= $isOperator ? 'Couples with an account on the platform' : 'Couples whose wedding you can open'; ?></span>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-counter success">
            <i class="mdi mdi-book-open-page-variant" aria-hidden="true"></i>
            <span class="count-numbers"><?= (int) $booking_count; ?></span>
            <span class="count-name"><?= $isOperator ? 'Total Bookings' : 'Your Weddings'; ?></span>
            <span class="count-note"><?= $isOperator ? 'Every wedding on the platform' : 'Pending and confirmed, all dates'; ?></span>
        </div>
    </div>
    <div class="col-lg-4">
        <a href="notifications.php" class="card-counter warning" style="display:block;"
           title="Unread alerts — deposits recorded by your couples">
            <i class="mdi mdi-bell-ring" aria-hidden="true"></i>
            <span class="count-numbers"><?= (int) $notif_unread; ?></span>
            <span class="count-name">Unread Alerts</span>
            <span class="count-note">Deposits, replies and updates since your last visit</span>
        </a>
    </div>
</div>

<div class="dash-section-head">
    <h2 class="dash-section-title">Content &amp; comms</h2>
    <a href="photos_view.php">Manage gallery</a>
</div>
<div class="card mb-4" style="border:1px solid var(--stone-200); border-radius:var(--radius-card); box-shadow:var(--shadow-soft);">
    <div class="card-body" style="display:flex; gap:26px; flex-wrap:wrap; padding:13px 18px; font-size:13px;">
        <a href="photos_view.php"><i class="mdi mdi-image-multiple mr-1"></i><strong><?= (int) $gallery_count; ?></strong> photos</a>
        <a href="blog_events.php"><i class="mdi mdi-comment-text mr-1"></i><strong><?= (int) $event_post; ?></strong> blogs &amp; events</a>
        <a href="service_list.php"><i class="mdi mdi-check-decagram mr-1"></i>Service catalogue</a>
        <a href="vendors.php"><i class="mdi mdi-storefront mr-1"></i>Vendor marketplace</a>
    </div>
</div>

<div class="dash-section-head">
    <h2 class="dash-section-title">Next weddings</h2>
    <a href="client.php">All weddings &rarr;</a>
</div>
<div class="card mb-4" style="border:1px solid var(--stone-200); border-radius:var(--radius-card); box-shadow:var(--shadow-soft); overflow:hidden;">
    <?php if ($dashUpcoming === []): ?>
        <div class="card-body" style="padding:18px;">
            <p class="mb-1" style="font-size:13.5px; font-weight:600; color:var(--text-strong);">No upcoming weddings yet</p>
            <p class="mb-0 hint">Confirm a booking and its date lands here, soonest first, with a link straight into the wedding.</p>
        </div>
    <?php else: ?>
        <div class="wedding-list">
            <?php foreach ($dashUpcoming as $dashWedding):
                $dashWhen = (string) $dashWedding->wedding_date;
                $dashDays = (int) floor((strtotime($dashWhen) - strtotime($today)) / 86400);
                $dashType = (int) ($dashWedding->wedding_type ?? 0);
                $dashTypeName = 'Wedding';
                if ($dashType > 0) {
                    $dashCat = Category::find_by_id($dashType);
                    if ($dashCat) {
                        // The package label lives in `wedding_type` on
                        // tblweddingcategories; there is no `title` column, and
                        // reading one raised a warning on every row while the
                        // label silently came out empty.
                        $dashTypeName = (string) $dashCat->wedding_type;
                    }
                }
                $dashCouple = trim((string) $dashWedding->bride . ' & ' . (string) $dashWedding->groom);
                if ($dashCouple === '&') {
                    $dashCouple = 'Unnamed couple';
                }
            ?>
                <a class="wedding-row" href="client_manage_account_details.php?booking_id=<?= (int) $dashWedding->booking_id; ?>&user_id=<?= (int) $dashWedding->user_id; ?>">
                    <span class="wedding-date">
                        <span class="d"><?= htmlspecialchars(date('d', strtotime($dashWhen))); ?></span>
                        <span class="m"><?= htmlspecialchars(date('M', strtotime($dashWhen))); ?></span>
                    </span>
                    <span class="wedding-who">
                        <strong><?= htmlspecialchars($dashCouple); ?></strong>
                        <span><?= htmlspecialchars($dashTypeName); ?><?= !empty($dashWedding->location) ? ' · ' . htmlspecialchars((string) $dashWedding->location) : ''; ?></span>
                    </span>
                    <span class="wedding-meta">
                        <?= $dashDays <= 0 ? 'Today' : ($dashDays === 1 ? 'Tomorrow' : 'in ' . $dashDays . ' days'); ?>
                        <br>
                        <span class="pill badge <?= (int) ($dashWedding->organizer_id ?? 0) > 0 ? 'badge-light' : 'badge-warning'; ?>">
                            <?= (int) ($dashWedding->organizer_id ?? 0) > 0 ? 'assigned' : 'unassigned'; ?>
                        </span>
                    </span>
                    <i class="mdi mdi-chevron-right" style="color:var(--stone-500);" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($dashNudges !== []): ?>
<div class="dash-section-head">
    <h2 class="dash-section-title">Weddings needing a nudge</h2>
    <a href="client.php">All weddings &rarr;</a>
</div>
<div class="card mb-4" style="border:1px solid var(--stone-200); border-radius:var(--radius-card); box-shadow:var(--shadow-soft); overflow:hidden;">
    <div class="wedding-list">
        <?php foreach ($dashNudges as $nudge):
            $nudgeRow  = $nudge['booking'];
            $nudgePkg  = 'Wedding';
            $nudgeType = (int) ($nudgeRow->wedding_type ?? 0);
            if ($nudgeType > 0) {
                $nudgeCategory = Category::find_by_id($nudgeType);
                if ($nudgeCategory) {
                    $nudgePkg = (string) $nudgeCategory->wedding_type;
                }
            }
            $nudgeCouple = trim((string) $nudgeRow->bride . ' & ' . (string) $nudgeRow->groom);
            if ($nudgeCouple === '' || $nudgeCouple === '&') {
                $nudgeCouple = 'Unnamed couple';
            }
            $nudgeWhenLabel = (int) $nudge['days'] === 0
                ? 'today'
                : ((int) $nudge['days'] === 1 ? 'tomorrow' : 'in ' . (int) $nudge['days'] . ' days');
        ?>
            <a class="wedding-row" href="client_manage_account_details.php?booking_id=<?= (int) $nudgeRow->booking_id; ?>&user_id=<?= (int) $nudgeRow->user_id; ?>">
                <span class="wedding-date">
                    <span class="d"><?= htmlspecialchars(date('d', strtotime((string) $nudgeRow->wedding_date))); ?></span>
                    <span class="m"><?= htmlspecialchars(date('M', strtotime((string) $nudgeRow->wedding_date))); ?></span>
                </span>
                <span class="wedding-who">
                    <strong><?= htmlspecialchars($nudgeCouple); ?></strong>
                    <span><?= htmlspecialchars($nudgePkg); ?> &middot; <?= $nudgeWhenLabel; ?></span>
                </span>
                <span class="nudge-flags">
                    <?php foreach ($nudge['flags'] as [$nudgeTone, $nudgeLabel]): ?>
                        <span class="nudge-pill <?= $nudgeTone; ?>"><?= htmlspecialchars($nudgeLabel); ?></span>
                    <?php endforeach; ?>
                </span>
                <i class="mdi mdi-chevron-right" style="color:var(--stone-500);" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

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