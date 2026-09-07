<?php include 'include/init.php'; ?>
<?php
    if (!is_staff()) { redirect_to("../"); die(); }

    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    $booking = Booking::find_by_booking_id($booking_id);
    if (!$booking) { redirect_to("client.php"); die(); }

    // Tokens are ensured so every guest shows a usable link for reminder texts.
    Guest::ensure_tokens($booking_id);

    $guest_list   = Guest::getGuest($booking_id);
    $tally        = Guest::rsvp_tally($booking_id);
    $invited      = count($guest_list);
    $responded    = $tally['yes'] + $tally['no'] + $tally['maybe'];

    // Headcount math: attending guests (yes), plus maybes tracked separately —
    // venues cater for "confirmed + tentative band".
    $attending    = $tally['yes'];
    $tentative    = $tally['maybe'];

    // Group + dietary breakdowns of ATTENDING guests (what the venue needs).
    $group_labels = [
        'family_elder' => 'Family Elder (Mzee)', 'bridal_train' => 'Bridal Train',
        'upcountry_guest' => 'Upcountry Guest', 'family_friend' => 'Family Friend',
        'colleague' => 'Colleague', 'pastor_clergy' => 'Pastor/Clergy',
        'vc_member' => 'VC Member', 'harambee_sponsor' => 'Harambee Sponsor',
        'close_family' => 'Close Family', 'regular' => 'Regular Guest', 'other' => 'Other',
    ];
    $attending_rows = array_values(array_filter($guest_list, function ($g) {
        return ($g->rsvp_status ?? 'pending') === 'yes';
    }));
    $by_group = [];
    $dietary  = [];
    $transport_needed = 0;
    $accommodation_needed = 0;
    foreach ($attending_rows as $g) {
        $label = $group_labels[$g->relationship] ?? ucfirst(str_replace('_', ' ', (string) $g->relationship));
        $by_group[$label] = ($by_group[$label] ?? 0) + 1;
        $diet = trim((string) ($g->dietary_requirements ?? ''));
        if ($diet !== '' && strcasecmp($diet, 'None') !== 0) {
            $dietary[$diet] = ($dietary[$diet] ?? 0) + 1;
        }
        if (!empty($g->transport_needed)) { $transport_needed++; }
        if (!empty($g->upcountry_accommodation)) { $accommodation_needed++; }
    }
    arsort($by_group);
    arsort($dietary);

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd($booking->wedding_date) : '';

    // RSVP link reminder texts (planner copies these into WhatsApp).
    function rsvp_whatsapp_reminder(string $guestName, string $couple, string $weddingHuman, string $rsvpUrl): string
    {
        $msg = rsvp_reminder_text($guestName, $couple, $weddingHuman, $rsvpUrl);
        return 'https://wa.me/?text=' . rawurlencode($msg);
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>RSVP Dashboard - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-left: 4px solid #065F46; padding: 10px 15px; margin-bottom: 15px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #888; margin: 0; }
        .stat-card .value { font-size: 22px; font-weight: 700; color: #333; }
        .progress { height: 10px; border-radius: 6px; }
        .badge-rsvp-yes { background: #059669; color: #fff; }
        .badge-rsvp-no { background: #64748B; color: #fff; }
        .badge-rsvp-maybe { background: #D97706; color: #fff; }
        .badge-rsvp-pending { background: #E2E8F0; color: #475569; }
        .btn-wa { background: #25D366; color: #fff; border-radius: 20px; font-size: 11px; padding: 2px 10px; }
        .btn-wa:hover { background: #1DA851; color: #fff; }
        .panel { background:#fff; border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .bar-row { display:flex; align-items:center; margin-bottom:6px; font-size:12.5px; }
        .bar-label { width:42%; padding-right:8px; color:#475569; }
        .bar-track { flex:1; background:#F1F5F9; border-radius:5px; height:14px; overflow:hidden; }
        .bar-fill { height:100%; background:#065F46; border-radius:5px; }
        .bar-num { width:34px; text-align:right; font-weight:600; color:#0F172A; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h4 class="h4 mt-4 ml-3"><i class="mdi mdi-chart-donut"></i> RSVP Dashboard
        <small class="text-muted d-block" style="font-size:12px;">
            <?= htmlspecialchars($couple !== '&' ? $couple : 'Booking #' . $booking_id); ?>
            <?= $wedding_human !== '' ? ' — ' . htmlspecialchars($wedding_human) : ''; ?>
        </small>
    </h4>
    <div class="btn-toolbar mb-md-0">
        <div class="btn-group mr-2">
            <a class="btn btn-sm btn-primary" href="client_manage_account_details.php?<?= $links; ?>"><i class="mdi mdi-buffer mr-1"></i> Overview</a>
            <a class="btn btn-sm btn-info" href="guest_list.php?<?= $links; ?>"><i class="mdi mdi-account-multiple mr-1"></i> Guest List</a>
            <a class="btn btn-sm btn-success" href="budget.php?<?= $links; ?>"><i class="mdi mdi-cash mr-1"></i> Budget</a>
            <a class="btn btn-sm btn-warning" href="task_calendar.php?<?= $links; ?>"><i class="mdi mdi-calendar-text mr-1"></i> Calendar</a>
            <a class="btn btn-sm btn-success active" href="rsvp_dashboard.php?<?= $links; ?>"><i class="mdi mdi-chart-donut mr-1"></i> RSVP</a>
        </div>
    </div>
</div>

<?php if ($session->message()): ?>
    <div class="ml-3"><?= $session->message(); ?></div>
<?php endif; ?>

<div class="container-fluid">

    <!-- Headline stats -->
    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Invited</h6><div class="value"><?= $invited; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#059669"><h6>Attending (Yes)</h6><div class="value"><?= $attending; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#D97706"><h6>Maybe (Tentative)</h6><div class="value"><?= $tentative; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#64748B"><h6>Awaiting Reply</h6><div class="value"><?= $tally['pending']; ?></div></div></div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="panel p-3 mb-3">
                <h6 class="mb-3"><i class="mdi mdi-chart-donut mr-1"></i> Response rate</h6>
                <?php $pct = $invited > 0 ? (int) round($responded * 100 / $invited) : 0; ?>
                <div class="d-flex justify-content-between" style="font-size:12.5px;">
                    <span><?= $responded; ?> of <?= $invited; ?> replied</span><strong><?= $pct; ?>%</strong>
                </div>
                <div class="progress mt-2 mb-3">
                    <div class="progress-bar bg-success" style="width: <?= $pct; ?>%;"></div>
                </div>
                <p class="mb-1" style="font-size:12.5px; color:#475569;">Venue headcount guidance</p>
                <div class="d-flex justify-content-between" style="font-size:12.5px;">
                    <span>Confirmed <strong class="text-success"><?= $attending; ?></strong></span>
                    <span>Tentative band +<strong class="text-warning"><?= $tentative; ?></strong></span>
                    <span>Plan for <strong><?= $attending + $tentative; ?></strong></span>
                </div>
            </div>

            <div class="panel p-3 mb-3">
                <h6 class="mb-3"><i class="mdi mdi-bus mr-1"></i> Logistics for attending guests</h6>
                <div class="d-flex justify-content-between" style="font-size:13px;">
                    <span><i class="mdi mdi-bus text-primary"></i> Transport needed</span><strong><?= $transport_needed; ?></strong>
                </div>
                <div class="d-flex justify-content-between" style="font-size:13px;">
                    <span><i class="mdi mdi-bed text-info"></i> Accommodation needed</span><strong><?= $accommodation_needed; ?></strong>
                </div>
                <div class="mt-2">
                    <a class="btn btn-outline-success btn-sm" href="rsvp_export.php?<?= $links; ?>&format=csv">
                        <i class="mdi mdi-download mr-1"></i> Export venue headcount (CSV)
                    </a>
                    <a class="btn btn-outline-success btn-sm" href="sheet_guests.php?<?= $links; ?>" target="_blank" rel="noopener">
                        <i class="mdi mdi-printer mr-1"></i> Guest list sheet
                    </a>
                    <a class="btn btn-outline-success btn-sm" href="sheet_venue.php?<?= $links; ?>" target="_blank" rel="noopener">
                        <i class="mdi mdi-file-document-outline mr-1"></i> Venue summary
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="panel p-3 mb-3">
                <h6 class="mb-3"><i class="mdi mdi-account-group mr-1"></i> Attending by group</h6>
                <?php if (empty($by_group)): ?>
                    <p class="text-muted mb-0" style="font-size:13px;">No confirmed guests yet — bars fill in as RSVPs arrive.</p>
                <?php else: ?>
                    <?php foreach ($by_group as $label => $n): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= htmlspecialchars($label); ?></span>
                            <span class="bar-track"><span class="bar-fill" style="width: <?= $attending > 0 ? (int) round($n * 100 / $attending) : 0; ?>%;"></span></span>
                            <span class="bar-num"><?= $n; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="panel p-3 mb-3">
                <h6 class="mb-3"><i class="mdi mdi-food mr-1"></i> Dietary requirements (attending)</h6>
                <?php if (empty($dietary)): ?>
                    <p class="text-muted mb-0" style="font-size:13px;">No special dietary requirements recorded.</p>
                <?php else: ?>
                    <?php foreach ($dietary as $diet => $n): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= htmlspecialchars($diet); ?></span>
                            <span class="bar-track"><span class="bar-fill" style="width: <?= (int) round($n * 100 / max(1, array_sum($dietary))); ?>%; background:#D97706;"></span></span>
                            <span class="bar-num"><?= $n; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Awaiting reply: reminder actions -->
    <div class="panel p-3 mb-3">
        <h6 class="mb-2"><i class="mdi mdi-clock-alert-outline mr-1"></i> Awaiting reply (<?= $tally['pending']; ?>)</h6>
        <p class="text-muted" style="font-size:12.5px;">Select guests to remind, or use the per-guest actions. SMS charges the planner's credits; WhatsApp opens each chat pre-filled.</p>
        <?php
        $pending_rows = array_values(array_filter($guest_list, function ($g) {
            return ($g->rsvp_status ?? 'pending') === 'pending';
        }));
        $pending_with_phone = array_filter($pending_rows, function ($g) {
            return wa_number_ke($g->phone ?? '') !== '';
        });
        ?>
        <?php if (empty($pending_rows)): ?>
            <p class="text-success mb-0" style="font-size:13px;"><i class="mdi mdi-check-circle mr-1"></i>Everyone has replied — no reminders needed.</p>
        <?php else: ?>
        <form method="get" action="rsvp_wa_queue.php" id="waQueueForm" target="_blank">
            <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
            <input type="hidden" name="user_id" value="<?= $user_id; ?>">
            <div id="bulkBar" class="mb-2 p-2 rounded" style="background:#F1F5F9; display:none;">
                <span class="mr-2" style="font-size:12.5px; font-weight:600;"><span id="selCount">0</span> selected</span>
                <button type="submit" class="btn btn-sm btn-wa" id="waOpenBtn" <?= count($pending_with_phone) === 0 ? 'disabled title="No selected guest has a WhatsApp number"' : ''; ?>>
                    <i class="mdi mdi-whatsapp mr-1"></i>Open WhatsApp queue
                </button>
                <button type="button" class="btn btn-sm btn-success ml-1" id="smsBtn"
                        data-toggle="modal" data-target="#smsConfirmModal">
                    <i class="mdi mdi-cellphone-arrow-down mr-1"></i>Send SMS to selected
                </button>
                <button type="button" class="btn btn-sm btn-outline-success ml-1" id="invitedBtn"
                        data-toggle="modal" data-target="#invitedConfirmModal" title="Stamp today as each guest's invite date — their reminder window starts counting">
                    <i class="mdi mdi-send-check mr-1"></i>Mark invites sent
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary ml-1" id="clearSel">Clear</button>
            </div>
        </form>
        <form method="post" action="rsvp_remind_bulk.php" id="smsForm">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
            <input type="hidden" name="user_id" value="<?= $user_id; ?>">
        </form>
        <form method="post" action="rsvp_mark_invited.php" id="invitedForm">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
            <input type="hidden" name="user_id" value="<?= $user_id; ?>">
        </form>
        <div class="table-responsive">
                <table class="table table-sm mb-0" style="font-size:12.5px;">
                    <thead><tr>
                        <th style="width:28px;"><input type="checkbox" id="checkAll" aria-label="Select all pending guests"></th>
                        <th>Guest</th><th>Group</th><th>Phone</th><th>Auto-reminders</th><th style="width:30%;">Reminder</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pending_rows as $g):
                        $waNum = wa_number_ke($g->phone ?? '');
                        $remind = rsvp_whatsapp_reminder($g->guestname ?: $g->fullname, $couple, $wedding_human, rsvp_url_for($g->rsvp_token ?? ''));
                        $remCount = (int) ($g->rsvp_reminders ?? 0);
                        $remAt = $g->rsvp_reminded_at ?? null;
                        $invAt = $g->rsvp_invited_at ?? null;
                        $invAge = $invAt ? 'Invited ' . human_age((string) $invAt) : 'Not marked invited yet';
                    ?>
                        <tr>
                            <td><input type="checkbox" class="sel-guest" form="waQueueForm" name="ids[]" value="<?= (int) $g->id; ?>"
                                       data-has-wa="<?= $waNum !== '' ? '1' : '0'; ?>" aria-label="Select <?= htmlspecialchars($g->fullname); ?>"></td>
                            <td><strong><?= htmlspecialchars($g->fullname); ?></strong></td>
                            <td><?= htmlspecialchars($group_labels[$g->relationship] ?? ucfirst(str_replace('_', ' ', (string) $g->relationship))); ?></td>
                            <td><?= $waNum !== '' ? htmlspecialchars($g->phone) : '<span class="text-muted">no number</span>'; ?></td>
                            <td><?php if ($remCount === 0): ?>
                                    <span class="text-muted" style="font-size:12px;" title="<?= htmlspecialchars($invAge); ?>">not yet</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" title="<?= htmlspecialchars($invAge); ?> &middot; Last reminder: <?= htmlspecialchars((string) $remAt); ?>">
                                        <?= $remCount; ?> sent
                                    </span>
                                    <?php if ($remCount >= 3): ?><span class="badge badge-warning">cap</span><?php endif; ?>
                                <?php endif; ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($remind); ?>" target="_blank" rel="noopener" class="btn btn-wa btn-sm">
                                    <i class="mdi mdi-whatsapp mr-1"></i>Send reminder
                                </a>
                                <button type="button" class="btn btn-outline-secondary btn-sm copy-rsvp" data-link="<?= htmlspecialchars(rsvp_url_for($g->rsvp_token ?? '')); ?>">
                                    <i class="mdi mdi-content-copy mr-1"></i>Copy link
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Full responses -->
    <div class="panel p-3 mb-4">
        <h6 class="mb-2"><i class="mdi mdi-format-list-checks mr-1"></i> All responses</h6>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:12.5px;">
                <thead><tr><th>Guest</th><th>Group</th><th>Response</th><th>Note</th><th>Answered</th></tr></thead>
                <tbody>
                <?php foreach ($guest_list as $g):
                    $badge = [
                        'yes' => '<span class="badge badge-rsvp-yes"><i class="mdi mdi-check mr-1"></i>Attending</span>',
                        'no' => '<span class="badge badge-rsvp-no">Not attending</span>',
                        'maybe' => '<span class="badge badge-rsvp-maybe">Maybe</span>',
                    ][$g->rsvp_status ?? 'pending'] ?? '<span class="badge badge-rsvp-pending">Pending</span>';
                    $when = !empty($g->rsvp_at) ? format_date_kd($g->rsvp_at, true) : '';
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($g->fullname); ?></strong></td>
                        <td><?= htmlspecialchars($group_labels[$g->relationship] ?? ucfirst(str_replace('_', ' ', (string) $g->relationship))); ?></td>
                        <td><?= $badge; ?></td>
                        <td><?= htmlspecialchars($g->rsvp_note ?? ''); ?></td>
                        <td><?= $when; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Invites-sent confirm modal (bulk action) -->
<div class="modal fade" id="invitedConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title" style="font-weight:700;"><i class="mdi mdi-send-check mr-1" style="color:#065F46;"></i>Mark invites as sent</h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" style="font-size:13.5px;">
                <p id="invitedModalText" class="mb-2"></p>
                <p class="text-muted mb-0" style="font-size:12px;">Today is stamped as each guest's invite date — their automatic reminder clock starts counting. Guests already invited keep their original date, and missing RSVP links are minted on the spot.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="invitedGo">
                    <i class="mdi mdi-send-check mr-1"></i>Mark invited
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SMS confirm modal (bulk action) -->
<div class="modal fade" id="smsConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title" style="font-weight:700;"><i class="mdi mdi-cellphone-arrow-down mr-1" style="color:#065F46;"></i>Send SMS reminders</h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" style="font-size:13.5px;">
                <p id="smsModalText" class="mb-2"></p>
                <p class="text-muted mb-0" style="font-size:12px;">Each SMS charges the planner's credit balance and is logged in the SMS outbox. Guests already replied, without a number, or at the reminder cap are skipped with a reason.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="smsGo">
                    <i class="mdi mdi-send mr-1"></i>Send now
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ---- bulk selection -------------------------------------------------
    var waForm = document.getElementById('waQueueForm');
    var bulkBar = document.getElementById('bulkBar');
    var selCount = document.getElementById('selCount');
    var checkAll = document.getElementById('checkAll');

    function selectedBoxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.sel-guest:checked'));
    }
    function refreshBulkBar() {
        var sel = selectedBoxes();
        var hasWa = sel.some(function (b) { return b.getAttribute('data-has-wa') === '1'; });
        if (selCount) selCount.textContent = sel.length;
        if (bulkBar) bulkBar.style.display = sel.length > 0 ? 'block' : 'none';
        var waBtn = document.getElementById('waOpenBtn');
        if (waBtn && sel.length > 0) {
            waBtn.disabled = !hasWa;
            waBtn.title = hasWa ? '' : 'No selected guest has a WhatsApp number';
        }
        if (checkAll) {
            var all = document.querySelectorAll('.sel-guest');
            checkAll.checked = all.length > 0 && sel.length === all.length;
        }
    }
    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('sel-guest')) refreshBulkBar();
        if (e.target === checkAll) {
            document.querySelectorAll('.sel-guest').forEach(function (b) { b.checked = checkAll.checked; });
            refreshBulkBar();
        }
    });
    var clearBtn = document.getElementById('clearSel');
    if (clearBtn) clearBtn.addEventListener('click', function () {
        document.querySelectorAll('.sel-guest').forEach(function (b) { b.checked = false; });
        if (checkAll) checkAll.checked = false;
        refreshBulkBar();
    });

    // SMS confirm modal: mirror the selection into the POST form, submit.
    var smsBtn = document.getElementById('smsBtn');
    if (smsBtn) smsBtn.addEventListener('click', function () {
        var sel = selectedBoxes();
        if (sel.length === 0) return;
        var names = sel.map(function (b) {
            return b.closest('tr').querySelector('strong').textContent;
        });
        var noWa = sel.filter(function (b) { return b.getAttribute('data-has-wa') !== '1'; }).length;
        var txt = 'Send an SMS reminder to <strong>' + sel.length + ' guest' + (sel.length > 1 ? 's' : '') + '</strong>';
        if (noWa > 0) txt += ' <span class="text-muted">(' + noWa + ' without a number will be skipped)</span>';
        var el = document.getElementById('smsModalText');
        if (el) el.innerHTML = txt + ':<br><small>' + names.join(', ') + '</small>';
        var post = document.getElementById('smsForm');
        post.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
        sel.forEach(function (b) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'ids[]'; input.value = b.value;
            post.appendChild(input);
        });
    });
    var smsGo = document.getElementById('smsGo');
    if (smsGo) smsGo.addEventListener('click', function () {
        var post = document.getElementById('smsForm');
        if (post) post.submit();
    });

    // Invites-sent modal: mirror the selection into its POST form, submit.
    var invitedBtn = document.getElementById('invitedBtn');
    if (invitedBtn) invitedBtn.addEventListener('click', function () {
        var sel = selectedBoxes();
        if (sel.length === 0) return;
        var names = sel.map(function (b) {
            return b.closest('tr').querySelector('strong').textContent;
        });
        var el = document.getElementById('invitedModalText');
        if (el) el.innerHTML = 'Mark <strong>' + sel.length + ' guest' + (sel.length > 1 ? 's' : '')
            + '</strong> as invited?<br><small>' + names.join(', ') + '</small>';
        var post = document.getElementById('invitedForm');
        post.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
        sel.forEach(function (b) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'ids[]'; input.value = b.value;
            post.appendChild(input);
        });
    });
    var invitedGo = document.getElementById('invitedGo');
    if (invitedGo) invitedGo.addEventListener('click', function () {
        var post = document.getElementById('invitedForm');
        if (post) post.submit();
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.copy-rsvp');
        if (!btn) return;
        e.preventDefault();
        var link = btn.getAttribute('data-link');
        var done = function () {
            var old = btn.innerHTML;
            btn.innerHTML = '<i class="mdi mdi-check mr-1"></i>Copied';
            setTimeout(function () { btn.innerHTML = old; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(done);
        } else {
            var ta = document.createElement('textarea');
            ta.value = link;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        }
    });
</script>
<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
