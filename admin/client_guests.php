<?php include 'include/init.php'; ?>
<?php
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        die(); // redirect_to only sends the header — stop rendering!
    }
    $booking = Booking::find_by_booking_id($booking_id);
    if (!is_staff()) {
        // Clients see only their own wedding's guest list (staff may preview any).
        if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
            redirect_to("client.php");
            die();
        }
    }
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    // Couples self-serve: any legacy guest row without a token gets one now.
    Guest::ensure_tokens($booking_id);

    $guest_list = Guest::getGuest($booking_id);
    $total_harambee = Guest::total_harambee($booking_id);
    $upcountry_guests = Guest::get_upcountry_guests($booking_id);
    $rsvp = Guest::rsvp_tally($booking_id);
    $transport_needed = 0;
    foreach ($guest_list as $g) {
        if (!empty($g->transport_needed)) {
            $transport_needed++;
        }
    }

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
    $wedding_human = !empty($booking->wedding_date) ? format_date_kd($booking->wedding_date) : '';
    $is_client_view = !is_staff();
    $edit_id = (int) ($_GET['edit'] ?? 0);
    $edit_guest = null;
    foreach ($guest_list as $g) {
        if ((int) $g->id === $edit_id) { $edit_guest = $g; break; }
    }
    $relationship_labels = [
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
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $is_client_view ? 'My Guests - Everafter Planner' : 'Guest List - Everafter Planner'; ?></title>
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
        .badge-priority-A { background: #DC2626; color: #fff; }
        .badge-priority-B { background: #D97706; color: #fff; }
        .badge-priority-C { background: #EAB308; color: #333; }
        .badge-priority-D { background: #0EA5A0; color: #fff; }
        .badge-priority-E { background: #64748B; color: #fff; }
        .badge-rsvp-yes { background: #059669; color: #fff; }
        .badge-rsvp-no { background: #64748B; color: #fff; }
        .badge-rsvp-maybe { background: #D97706; color: #fff; }
        .badge-rsvp-pending { background: #E2E8F0; color: #475569; }
        .btn-wa { background: #25D366; color: #fff; border-radius: 20px; font-size: 12px; padding: 3px 12px; }
        .btn-wa:hover { background: #1DA851; color: #fff; }
        .guest-form { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 18px; }
        .copy-hint { font-size: 11px; color: #64748B; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-account-network" style="color:#065F46"></i> <?= $is_client_view ? 'My Guests' : 'Guest List (couple-managed)'; ?></h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?= $couple !== '&' ? htmlspecialchars($couple) . ' — ' : ''; ?>add guests, copy their RSVP link, or invite straight on WhatsApp
            </p>
        </div>
        <div>
            <a class="btn btn-outline-success mr-2" href="sheet_guests.php?<?= $links; ?>" target="_blank" rel="noopener" title="Printable guest list for the wedding day">
                <i class="mdi mdi-printer mr-1"></i> Print sheet
            </a>
            <?php if ($is_client_view): ?>
            <button type="button" class="btn btn-success" data-toggle="collapse" data-target="#addGuestForm">
                <i class="mdi mdi-account-plus mr-1"></i> Add Guest
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Total Guests</h6><div class="value"><?= count($guest_list); ?></div></div></div>
        <div class="col-md-2"><div class="stat-card" style="border-left-color:#059669"><h6>Confirmed (RSVP)</h6><div class="value"><?= $rsvp['yes']; ?></div></div></div>
        <div class="col-md-2"><div class="stat-card" style="border-left-color:#D97706"><h6>Harambee Pledged</h6><div class="value"><?= format_ksh($total_harambee); ?></div></div></div>
        <div class="col-md-2"><div class="stat-card" style="border-left-color:#0EA5A0"><h6>Upcountry</h6><div class="value"><?= count($upcountry_guests); ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#64748B"><h6>Transport Needed</h6><div class="value"><?= $transport_needed; ?></div></div></div>
    </div>

    <?php if ($is_client_view): ?>
    <div class="collapse <?= $edit_guest === null ? '' : 'show'; ?>" id="addGuestForm">
        <div class="guest-form mb-3">
            <h6 class="mb-3"><i class="mdi <?= $edit_guest ? 'mdi-pencil' : 'mdi-account-plus'; ?> mr-1"></i> <?= $edit_guest ? 'Edit guest — ' . htmlspecialchars($edit_guest->fullname) : 'New guest'; ?></h6>
            <form method="post" action="client_guest_save.php?<?= $links; ?>">
                <?= $session->csrf_field(); ?>
                <input type="hidden" name="guest_id" value="<?= $edit_guest ? (int) $edit_guest->id : 0; ?>">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="required">Full name</label>
                        <input type="text" name="fullname" class="form-control" maxlength="100" required
                               value="<?= $edit_guest ? htmlspecialchars($edit_guest->fullname) : ''; ?>" placeholder="e.g. Mzee Barasa Wafula">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="required">Short name (on invitation)</label>
                        <input type="text" name="guestname" class="form-control" maxlength="100" required
                               value="<?= $edit_guest ? htmlspecialchars($edit_guest->guestname) : ''; ?>" placeholder="e.g. Mzee Barasa">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Phone (for WhatsApp)</label>
                        <input type="tel" name="phone" class="form-control" maxlength="20"
                               value="<?= $edit_guest ? htmlspecialchars($edit_guest->phone ?? '') : ''; ?>" placeholder="e.g. 0712 345 678">
                    </div>
                    <div class="form-group col-md-2">
                        <label>Group</label>
                        <select name="relationship" class="form-control">
                            <?php foreach ($relationship_labels as $key => $label): ?>
                                <option value="<?= $key; ?>" <?= $edit_guest && $edit_guest->relationship === $key ? 'selected' : ''; ?>><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>City / Town</label>
                        <input type="text" name="city" class="form-control" maxlength="100"
                               value="<?= $edit_guest ? htmlspecialchars($edit_guest->city) : ''; ?>" placeholder="e.g. Bungoma">
                    </div>
                    <div class="form-group col-md-8 d-flex align-items-end flex-wrap">
                        <div class="custom-control custom-checkbox mr-3">
                            <input type="checkbox" class="custom-control-input" id="g_oot" name="out_of_town" value="y"
                                   <?= $edit_guest && $edit_guest->out_of_town === 'y' ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="g_oot">Travelling from upcountry</label>
                        </div>
                        <div class="custom-control custom-checkbox mr-3">
                            <input type="checkbox" class="custom-control-input" id="g_acc" name="upcountry_accommodation" value="1"
                                   <?= $edit_guest && $edit_guest->upcountry_accommodation ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="g_acc">Needs accommodation</label>
                        </div>
                        <div class="custom-control custom-checkbox mr-3">
                            <input type="checkbox" class="custom-control-input" id="g_tra" name="transport_needed" value="1"
                                   <?= $edit_guest && $edit_guest->transport_needed ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="g_tra">Needs transport</label>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Dietary requirements</label>
                        <input type="text" name="dietary_requirements" class="form-control" maxlength="100"
                               value="<?= $edit_guest ? htmlspecialchars($edit_guest->dietary_requirements ?? '') : ''; ?>" placeholder="e.g. Halal, Vegetarian, None">
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <button type="submit" name="save_guest" value="1" class="btn btn-success mr-2">
                            <i class="mdi mdi-content-save mr-1"></i> <?= $edit_guest ? 'Update Guest' : 'Save Guest'; ?>
                        </button>
                        <?php if ($edit_guest): ?>
                            <a href="client_guests.php?<?= $links; ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <table class="table table-sm table-hover" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Group</th>
                        <th>RSVP</th>
                        <th>Phone</th>
                        <th>Upcountry</th>
                        <th>Harambee</th>
                        <th>Dietary</th>
                        <?php if ($is_client_view): ?><th>Invite</th><?php endif; ?>
                        <?php if ($is_client_view): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($guest_list)) : ?>
                    <?php if ($is_client_view): ?>
                        <?php empty_state_row($is_client_view ? 9 : 9, 'No guests yet', 'Add your first guest above — then share their invite on WhatsApp.', 'mdi-account-multiple-outline'); ?>
                    <?php else: ?>
                        <?php empty_state_row(7, 'No guests yet', 'The couple has not added guests yet.', 'mdi-account-multiple-outline'); ?>
                    <?php endif; ?>
                <?php else : ?>
                <?php foreach ($guest_list as $g) :
                    $wa = whatsapp_invite_link($g->phone ?? '', $g->guestname ?: $g->fullname, $wedding_human, rsvp_url_for($g->rsvp_token ?? ''));
                    $rsvp_badge = [
                        'yes' => '<span class="badge badge-rsvp-yes"><i class="mdi mdi-check mr-1"></i>Attending</span>',
                        'no' => '<span class="badge badge-rsvp-no">Not attending</span>',
                        'maybe' => '<span class="badge badge-rsvp-maybe">Maybe</span>',
                    ][$g->rsvp_status ?? 'pending'] ?? '<span class="badge badge-rsvp-pending">Pending</span>';
                ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($g->fullname); ?></strong>
                            <?php if (!empty($g->guestname) && $g->guestname !== $g->fullname): ?><br><small class="text-muted"><?= htmlspecialchars($g->guestname); ?></small><?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($relationship_labels[$g->relationship] ?? ucfirst(str_replace('_', ' ', (string) $g->relationship))); ?></td>
                        <td>
                            <?= $rsvp_badge; ?>
                            <?php if (!empty($g->rsvp_note)): ?><br><small class="copy-hint" title="<?= htmlspecialchars($g->rsvp_note); ?>">&ldquo;<?= htmlspecialchars(mb_strimwidth($g->rsvp_note, 0, 34, '…')); ?>&rdquo;</small><?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($g->phone ?? ''); ?></td>
                        <td><?= ($g->out_of_town === 'y') ? '<i class="mdi mdi-check text-success" title="Upcountry"></i>' : ''; ?><?= $g->upcountry_accommodation ? ' <i class="mdi mdi-bed text-primary" title="Accommodation"></i>' : ''; ?><?= $g->transport_needed ? ' <i class="mdi mdi-bus text-info" title="Transport"></i>' : ''; ?></td>
                        <td><?= $g->harambee_contribution > 0 ? format_ksh($g->harambee_contribution) : ''; ?></td>
                        <td><?= htmlspecialchars($g->dietary_requirements ?? ''); ?></td>
                        <?php if ($is_client_view): ?>
                        <td>
                            <?php if ($wa !== ''): ?>
                                <a href="<?= htmlspecialchars($wa); ?>" target="_blank" rel="noopener" class="btn btn-wa btn-sm" title="Invite on WhatsApp">
                                    <i class="mdi mdi-whatsapp mr-1"></i>Invite
                                </a>
                            <?php else: ?>
                                <a href="#" class="btn btn-wa btn-sm disabled" title="Add a phone number to invite on WhatsApp"><i class="mdi mdi-whatsapp"></i></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="client_guests.php?<?= $links; ?>&edit=<?= (int) $g->id; ?>#addGuestForm" class="btn btn-outline-info btn-sm" title="Edit guest"><i class="mdi mdi-pencil"></i></a>
                            <form method="post" action="client_guest_save.php?<?= $links; ?>" class="d-inline"
                                  onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($g->fullname)); ?> from your guest list?');">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="guest_id" value="<?= (int) $g->id; ?>">
                                <button type="submit" name="delete_guest" value="1" class="btn btn-outline-danger btn-sm" title="Remove guest"><i class="mdi mdi-delete"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($is_client_view && !empty($guest_list)): ?>
    <div class="card shadow-sm mt-3" style="border:none; border-radius:14px;">
        <div class="card-body">
            <h6 class="mb-2"><i class="mdi mdi-link-variant mr-1"></i> RSVP links — copy to share anywhere</h6>
            <p class="copy-hint mb-2">Each guest has a personal link. Anyone with the link can answer for that guest only.</p>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <?php foreach ($guest_list as $g): ?>
                    <tr>
                        <td style="width:30%;"><?= htmlspecialchars($g->fullname); ?></td>
                        <td class="copy-hint text-truncate"><?= htmlspecialchars(rsvp_url_for($g->rsvp_token ?? '')); ?></td>
                        <td style="width:12%;" class="text-right">
                            <button type="button" class="btn btn-outline-secondary btn-sm copy-rsvp"
                                    data-link="<?= htmlspecialchars(rsvp_url_for($g->rsvp_token ?? '')); ?>">
                                <i class="mdi mdi-content-copy mr-1"></i>Copy
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    <script>
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
    <?php endif; ?>
</div>

<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
