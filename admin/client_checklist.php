<?php include 'include/init.php'; if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; } ?>
<?php
/**
 * Couple's Kenyan planning checklist — the "come back daily" page.
 *
 * Every booking gets the standard Kenyan milestone plan (ruracio before the
 * church booking, banns/21-day registrar notice, committee, final-month
 * sprint) seeded on first open. Couples tick items off themselves; staff can
 * preview any booking's checklist. Progress is computed against the wedding
 * date so the page answers "am I on track?" at a glance.
 */
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id    = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die(); // redirect_to only sends the header — stop rendering!
    }
    $booking = Booking::find_by_booking_id($booking_id);
    // Couples see only their own wedding's checklist; staff may preview any.
    if (!may_open_couple_page($booking, $user_id)) {
        redirect_to("client.php");
        die();
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    if (!$booking) {
        redirect_to("client.php");
        die();
    }

    // First open seeds the Kenyan milestone template (idempotent).
    Checklist::ensure_seeded($booking_id);
    $items = Checklist::find_for_booking($booking_id);

    /*
     * ONE calculation, shared with the couple's dashboard: an item is overdue
     * when its target date (wedding_date - due_offset_days) has passed and it
     * is still open, nothing is overdue without a wedding date to be late for,
     * and once the wedding has happened the nagging stops — the checklist
     * becomes a keepsake, not a guilt trip. Checklist::progress() owns that
     * rule; this page renders it.
     */
    $progress     = Checklist::progress($booking_id, (string) ($booking->wedding_date ?? ''));
    $total        = (int) $progress['total'];
    $done         = (int) $progress['done'];
    $overdue      = (int) $progress['overdue'];
    $pct          = (int) $progress['pct'];
    $next         = $progress['next'];
    $wedding_past = (bool) $progress['wedding_past'];

    // The per-item target dates below still need the wedding's own timestamp.
    $today_start = strtotime('today');
    $wedding_ts = !empty($booking->wedding_date) ? strtotime((string) $booking->wedding_date) : false;

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &');
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd($booking->wedding_date) : '';
    $is_client_view = !is_staff();
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $is_client_view ? 'Planning Checklist - Everafter Planner' : 'Wedding Checklist - Everafter Planner'; ?></title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #065F46; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .check-progress { background: #fff; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px;
                          box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .check-progress .bar { background: #E2E8F0; border-radius: 999px; height: 10px; overflow: hidden; }
        .check-progress .bar > div { background: linear-gradient(90deg, #065F46, #059669); height: 100%; border-radius: 999px; transition: width .3s; }
        .phase-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 16px; overflow: hidden; }
        .phase-card > .phase-head { padding: 12px 18px; background: #F8FAFC; border-bottom: 1px solid #E2E8F0;
                                    font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #065F46; }
        .check-item { display: flex; align-items: flex-start; padding: 11px 18px; border-bottom: 1px solid #F1F5F9; }
        .check-item:last-child { border-bottom: 0; }
        .check-item.is-done .check-title { text-decoration: line-through; color: #94A3B8; }
        .check-item.is-overdue .check-title::after { content: "overdue"; margin-left: 8px; font-size: 10px; font-weight: 700;
                                    text-transform: uppercase; color: #B91C1C; background: #FEE2E2; border-radius: 999px; padding: 2px 8px; }
        .check-title { font-size: 14px; color: #1C1917; }
        .check-due { font-size: 11.5px; color: #64748B; margin-top: 2px; }
        .check-due.is-overdue { color: #B91C1C; font-weight: 600; }
        .check-toggle { margin: 3px 12px 0 0; width: 17px; height: 17px; cursor: pointer; accent-color: #065F46; }
        .check-form { display: flex; align-items: flex-start; margin: 0; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-checkbox-marked-outline" style="color:#065F46"></i> <?= $is_client_view ? 'Planning Checklist' : 'Wedding Checklist (couple-managed)'; ?></h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '' ? htmlspecialchars($couple) . ' — ' : ''; ?>the Kenyan way, from ruracio to send-off — tick items off as you go
            </p>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Progress</h6><div class="value"><?= $done; ?>/<?= $total; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--danger)"><h6>Overdue</h6><div class="value" <?= $overdue > 0 ? 'style="color:#B91C1C"' : ''; ?>><?= $overdue; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--warning)"><h6>Wedding Date</h6><div class="value" style="font-size:16px; line-height:2.1;"><?= $wedding_human !== '' ? htmlspecialchars($wedding_human) : 'To be set'; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--info)"><h6>Up Next</h6><div class="value" style="font-size:13px; line-height:1.9; padding-top:4px;"><?= $next ? htmlspecialchars(mb_substr($next->title, 0, 46)) . (mb_strlen($next->title) > 46 ? '…' : '') : 'All done 🎉'; ?></div></div></div>
    </div>

    <div class="check-progress">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong style="font-size:14px;">Planning progress</strong>
            <span style="font-size:13px; color:#065F46; font-weight:700;"><?= $pct; ?>%</span>
        </div>
        <div class="bar"><div style="width: <?= $pct; ?>%;"></div></div>
    </div>

    <?php if ($total === 0): ?>
        <div class="alert alert-info">No checklist items yet — reopen this page to seed the Kenyan milestone plan.</div>
    <?php endif; ?>

    <?php
    // Group items by phase, preserving template order.
    $grouped = [];
    foreach ($items as $it) {
        $grouped[$it->phase][] = $it;
    }
    foreach ($grouped as $phase => $phase_items): ?>
    <div class="phase-card">
        <div class="phase-head"><i class="mdi mdi-flag-variant-outline mr-1"></i><?= htmlspecialchars($phase); ?></div>
        <?php foreach ($phase_items as $it):
            $is_done = (int) $it->is_done === 1;
            $due_ts = $wedding_ts !== false ? $wedding_ts - ((int) $it->due_offset_days * 86400) : false;
            $is_overdue = !$is_done && !$wedding_past && $due_ts !== false && $today_start > $due_ts;
        ?>
        <div class="check-item <?= $is_done ? 'is-done' : ''; ?> <?= $is_overdue ? 'is-overdue' : ''; ?>">
            <?php if ($is_client_view): ?>
            <form class="check-form" method="post" action="client_checklist_toggle.php?<?= $links; ?>">
                <?= $session->csrf_field(); ?>
                <input type="hidden" name="item_id" value="<?= (int) $it->id; ?>">
                <input type="checkbox" class="check-toggle" title="<?= $is_done ? 'Mark as not done' : 'Mark as done'; ?>"
                       onchange="this.form.submit()" <?= $is_done ? 'checked' : ''; ?>>
            </form>
            <?php else: ?>
            <input type="checkbox" class="check-toggle" disabled <?= $is_done ? 'checked' : ''; ?> title="Couples tick their own checklist">
            <?php endif; ?>
            <div>
                <div class="check-title"><?= htmlspecialchars($it->title); ?></div>
                <?php if ($due_ts !== false): ?>
                <div class="check-due <?= $is_overdue ? 'is-overdue' : ''; ?>">
                    Target: by <?= htmlspecialchars(date('D j M Y', $due_ts)); ?>
                    <?php if ($is_done && !empty($it->done_at)): ?>
                        &middot; done <?= htmlspecialchars(time_elapsed_string(strtotime((string) $it->done_at))); ?> ago
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php include 'include/footer.php'; ?>
