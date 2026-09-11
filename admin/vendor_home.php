<?php include 'include/init.php'; ?>
<?php
/**
 * Vendor home — the bookings/enquiries dashboard for vendor accounts
 * (tblusers, access_level 0). Closes the vendor self-service gap: vendors
 * land here after login and see every couple enquiry on their own ledger,
 * scoped by vendor ownership (never another vendor's rows).
 *
 * Vendors CONFIRM/COMPLETE/CANCEL their own bookings: confirming stamps the
 * agreed amount (commission is computed by staff policy, left untouched here
 * — staff confirm commission on the staff ledger). A pending booking the
 * vendor accepts notifies the couple's assigned planner, who keeps 10-15%
 * commission recorded there.
 */
    require_vendor();

    $user_id  = (int) $_SESSION['id'];
    $vendor   = Vendor::get_by_user($user_id);
    if ($vendor === null) {
        // No vendor profile yet — straight to registration.
        redirect_to("vendor_register.php");
        die();
    }

    $links = 'vendor_id=' . (int) $vendor->id;
    $status_filter = clean($_GET['status'] ?? '');
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($status_filter, $valid_statuses, true)) {
        $status_filter = '';
    }

    // ---- Vendor status transitions (POST + CSRF + ownership re-check) -----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_row_id'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to("vendor_home.php");
            die();
        }
        $id = (int) $_POST['booking_row_id'];
        $to = clean($_POST['to'] ?? '');
        if (!in_array($to, $valid_statuses, true)) {
            $session->message('<div class="alert alert-danger">Unknown status.</div>');
            redirect_to("vendor_home.php");
            die();
        }
        // Ownership re-check: the row MUST belong to THIS vendor.
        $stmt = $db->prepare(
            'SELECT vb.*, v.business_name, b.user_id AS couple_user_id, b.bride, b.groom, b.wedding_date
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
               JOIN tblweddingbook b ON b.booking_id = vb.booking_id
              WHERE vb.id = :id AND vb.vendor_id = :vid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':vid' => (int) $vendor->id]);
        // PDO::fetch() returns FALSE when no row matches — coerce to null so
        // the ownership check below actually fires (a bare === null test
        // silently passes for foreign/phantom ids and reports fake success).
        $row = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
        if ($row === null) {
            $session->message('<div class="alert alert-danger">That booking is not yours.</div>');
            redirect_to("vendor_home.php");
            die();
        }
        // Vendors confirm with an agreed amount (they know their price);
        // completing/cancelling/reopening needs none.
        $amount = null;
        if ($to === 'confirmed') {
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount <= 0 && (float) $row->amount > 0) {
                $amount = (float) $row->amount; // keep the staff-confirmed amount
            }
            if ($amount <= 0 && (float) ($row->package_price ?? 0) > 0) {
                $amount = (float) $row->package_price;
            }
            if ($amount <= 0) {
                $session->message('<div class="alert alert-danger">Enter the agreed deal amount to confirm.</div>');
                redirect_to("vendor_home.php");
                die();
            }
            $stmt = $db->prepare(
                "UPDATE tblvendor_bookings
                    SET status = 'confirmed', amount = :amt,
                        notes = CONCAT(:note, notes)
                  WHERE id = :id AND vendor_id = :vid"
            );
            $stmt->execute([
                ':amt'  => $amount,
                ':note' => '[' . date('j M Y') . ' — confirmed by ' . ($vendor->business_name) . " (vendor); deal " . format_ksh($amount, 2) . ".]\n",
                ':id'   => $id,
                ':vid'  => (int) $vendor->id,
            ]);
        } else {
            $stmt = $db->prepare(
                "UPDATE tblvendor_bookings
                    SET status = :st, notes = CONCAT(:note, notes)
                  WHERE id = :id AND vendor_id = :vid"
            );
            $stmt->execute([
                ':st'   => $to,
                ':note' => '[' . date('j M Y') . ' — ' . $to . ' by ' . ($vendor->business_name) . " (vendor).]\n",
                ':id'   => $id,
                ':vid'  => (int) $vendor->id,
            ]);
        }

        // Keep the couple's planner in the loop — the ledger they work from.
        $plannerId = (int) $db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1',
            [':b' => (int) $row->booking_id]
        );
        if ($plannerId > 0) {
            Notification::create(
                $plannerId,
                (int) $row->booking_id,
                sprintf('%s marked your couple\'s %s booking as %s%s.',
                    $vendor->business_name,
                    trim(($row->bride ?? '') . ' & ' . ($row->groom ?? '')) !== '' ? trim(($row->bride ?? '') . ' & ' . ($row->groom ?? '')) : 'wedding',
                    $to,
                    $amount !== null ? ' at ' . format_ksh($amount) : ''
                ),
                'vendor_enquiries.php'
            );
        }

        // Best-effort SMS to the COUPLE — the vendor is (or isn't) locked in.
        if ($to === 'confirmed' || $to === 'cancelled') {
            $cphone = (string) $db->fetchValue(
                'SELECT phone FROM tblaccounts_detail WHERE user_id = :u LIMIT 1',
                [':u' => (int) $row->couple_user_id]
            );
            $smsText = $to === 'confirmed'
                ? 'Habari! ' . $vendor->business_name . ' is CONFIRMED for your wedding'
                    . (!empty($row->wedding_date) ? ' (' . format_date_kd((string) $row->wedding_date) . ')' : '')
                    . ' — deal ' . format_ksh((float) $amount) . '. Your planner finalized the details.'
                : 'Habari! Your ' . $vendor->business_name . ' booking was cancelled. Your planner will help you find a great alternative.';
            SMS::send_courtesy($cphone, $smsText, $plannerId, (int) $row->booking_id);
        }

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Booking marked <strong>' . $to . '</strong>.</div>');
        redirect_to("vendor_home.php" . ($status_filter !== '' ? "?status={$status_filter}" : ''));
        die();
    }

    // ---- Ledger: this vendor's bookings only (ownership-scoped) -----------
    $sql = "SELECT vb.*, p.title AS package_title, p.price AS package_price,
                   b.bride, b.groom, b.wedding_date, b.user_id AS couple_user_id,
                   d.phone AS couple_phone
              FROM tblvendor_bookings vb
         LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
              JOIN tblweddingbook b ON b.booking_id = vb.booking_id
         LEFT JOIN tblaccounts_detail d ON d.user_id = b.user_id
             WHERE vb.vendor_id = :vid";
    $params = [':vid' => (int) $vendor->id];
    if ($status_filter !== '') {
        $sql .= " AND vb.status = :st";
        $params[':st'] = $status_filter;
    }
    $sql .= " ORDER BY vb.status = 'pending' DESC, vb.created_at DESC, vb.id DESC";
    $bookings = $db->prepare($sql);
    $bookings->execute($params);
    $bookings = $bookings->fetchAll(PDO::FETCH_OBJ);

    // Tally per status for the filter chips (scoped to this vendor).
    $tally = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
    $tstmt = $db->prepare('SELECT status, COUNT(*) c FROM tblvendor_bookings WHERE vendor_id = :vid GROUP BY status');
    $tstmt->execute([':vid' => (int) $vendor->id]);
    foreach ($tstmt->fetchAll(PDO::FETCH_OBJ) as $row) {
        $tally[$row->status] = (int) $row->c;
    }

    $status_badges = [
        'pending'   => ['badge-warning', 'clock-outline'],
        'confirmed' => ['badge-success', 'check-decagram'],
        'completed' => ['badge-primary', 'certificate'],
        'cancelled' => ['badge-secondary', 'close-circle'],
    ];
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Bookings - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #D97706; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .filter-chip { border-radius: 999px; font-size: 12px; padding: 4px 14px; margin: 0 5px 6px 0; }
        .filter-chip.active { background: #D97706; border-color: #D97706; color: #fff; }
        .bookings-table td, .bookings-table th { font-size: 12.5px; vertical-align: middle; }
        .enq-notes { font-size: 11.5px; color: #64748B; white-space: pre-line; }
        .pill { font-size: 10px; padding: 3px 9px; border-radius: 999px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-handshake-outline" style="color:#D97706"></i> My Bookings</h4>
            <p class="text-muted mb-0" style="font-size: 13px;"><?= htmlspecialchars($vendor->business_name); ?> — every couple enquiry on your ledger <?= $vendor->is_approved ? '' : '<span class="badge badge-warning" style="font-size:10px;">pending approval</span>'; ?></p>
        </div>
        <div>
            <a href="vendor_profile.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-store mr-1"></i> My Profile</a>
            <a href="vendor_packages.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-package-variant-closed mr-1"></i> My Packages</a>
        </div>
    </div>

    <?php if (!$vendor->is_approved): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <strong><i class="mdi mdi-alert"></i> Approval pending.</strong> Couples can't see you in the marketplace
            and can't enquire until an administrator approves your listing. You can still prepare your profile and packages.
        </div>
    <?php endif; ?>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Pending</h6><div class="value"><?= $tally['pending']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#059669"><h6>Confirmed</h6><div class="value"><?= $tally['confirmed']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#4C6EF5"><h6>Completed</h6><div class="value"><?= $tally['completed']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#64748B"><h6>Cancelled</h6><div class="value"><?= $tally['cancelled']; ?></div></div></div>
    </div>

    <div class="mb-3">
        <a href="vendor_home.php" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === '' ? 'active' : ''; ?>">All</a>
        <?php foreach ($valid_statuses as $st): ?>
            <a href="vendor_home.php?status=<?= $st; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === $st ? 'active' : ''; ?>"><?= ucfirst($st); ?> (<?= $tally[$st]; ?>)</a>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="table-responsive">
            <table class="table table-hover bookings-table" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Couple</th>
                        <th>Package</th>
                        <th>Event date</th>
                        <th>Deal</th>
                        <th>Status</th>
                        <th style="min-width:230px;">Notes &amp; actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bookings)): ?>
                    <?php empty_state_row(6, 'No bookings here yet', $status_filter !== '' ? 'Nothing with this status — try another filter.' : 'Couple enquiries reach you the moment they are sent from the marketplace.', 'mdi-handshake-outline'); ?>
                <?php else: ?>
                    <?php foreach ($bookings as $b): [$badge, $icon] = $status_badges[$b->status]; ?>
                    <tr>
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars(trim($b->bride . ' & ' . $b->groom)); ?></strong>
                            <div style="font-size:11px; color:#64748B;">
                                <?= !empty($b->wedding_date) ? htmlspecialchars(format_date_kd((string) $b->wedding_date)) : ''; ?>
                                <?= $b->couple_phone !== null ? ' · ' . htmlspecialchars($b->couple_phone) : ''; ?>
                            </div>
                        </td>
                        <td style="font-size:12.5px;"><?= $b->package_title !== null ? htmlspecialchars($b->package_title) : '<span class="text-muted">General enquiry</span>'; ?></td>
                        <td style="font-size:12.5px;"><?= !empty($b->event_date) ? htmlspecialchars(format_date_kd($b->event_date)) : '<span class="text-muted">—</span>'; ?></td>
                        <td style="font-size:12.5px;">
                            <?php if ((float) $b->amount > 0): ?>
                                <strong><?= format_ksh($b->amount, 2); ?></strong>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill <?= $badge; ?>"><i class="mdi mdi-<?= $icon; ?>"></i> <?= ucfirst($b->status); ?></span></td>
                        <td>
                            <details>
                                <summary style="font-size:12px; cursor:pointer; color:#4C6EF5;">notes &amp; actions</summary>
                                <div class="enq-notes mt-1 mb-2"><?= htmlspecialchars($b->notes ?? ''); ?></div>
                                <form method="post" class="form-inline">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="booking_row_id" value="<?= (int) $b->id; ?>">
                                    <?php if ($b->status === 'pending'): ?>
                                        <input type="number" name="amount" class="form-control form-control-sm mr-1" placeholder="Deal KSh" step="0.01" min="0" style="max-width:120px;" value="<?= (float) $b->package_price > 0 ? (float) $b->package_price : ''; ?>">
                                        <button type="submit" name="to" value="confirmed" class="btn btn-sm btn-success mr-1" style="font-size:11px;"><i class="mdi mdi-check"></i> Accept</button>
                                        <button type="submit" name="to" value="cancelled" class="btn btn-sm btn-outline-secondary" style="font-size:11px;"><i class="mdi mdi-close"></i> Decline</button>
                                    <?php elseif ($b->status === 'confirmed'): ?>
                                        <button type="submit" name="to" value="completed" class="btn btn-sm btn-primary mr-1" style="font-size:11px;"><i class="mdi mdi-certificate"></i> Mark completed</button>
                                        <button type="submit" name="to" value="cancelled" class="btn btn-sm btn-outline-secondary" style="font-size:11px;"><i class="mdi mdi-close"></i> Cancel</button>
                                    <?php elseif ($b->status === 'cancelled' || $b->status === 'completed'): ?>
                                        <button type="submit" name="to" value="pending" class="btn btn-sm btn-outline-secondary" style="font-size:11px;"><i class="mdi mdi-reload"></i> Reopen</button>
                                    <?php endif; ?>
                                </form>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
