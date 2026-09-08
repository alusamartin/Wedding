<?php include 'include/init.php'; ?>
<?php
/**
 * Staff vendor-enquiries ledger — every couple's enquiry across all vendors.
 *
 * Couple enquiries land here as pending rows (from client_vendor_enquiry.php)
 * alongside vendor bookings created by staff. The planner works the list:
 * confirm the deal (setting amount + commission), mark it completed after the
 * wedding, or cancel it. Every state change is POST + CSRF and records who
 * moved it in the notes trail.
 */
    if (!is_staff()) {
        redirect_to("../");
        die();
    }

    $status_filter = clean($_GET['status'] ?? '');
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($status_filter, $valid_statuses, true)) {
        $status_filter = '';
    }

    global $db;
    $sql = "SELECT vb.*, v.business_name, v.category, v.phone AS vendor_phone, v.email AS vendor_email,
                   p.title AS package_title, p.price AS package_price,
                   b.bride, b.groom, b.user_id, b.wedding_date,
                   d.status AS account_status
            FROM tblvendor_bookings vb
            INNER JOIN tblvendors v ON v.id = vb.vendor_id
            LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
            INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id
            LEFT JOIN tblaccounts_detail d ON d.user_id = b.user_id";
    $params = [];
    if ($status_filter !== '') {
        $sql .= " WHERE vb.status = :st";
        $params[':st'] = $status_filter;
    }
    $sql .= " ORDER BY vb.status = 'pending' DESC, vb.created_at DESC, vb.id DESC";
    $enquiries = $db->prepare($sql);
    $enquiries->execute($params);
    $enquiries = $enquiries->fetchAll(PDO::FETCH_OBJ);

    // Tally per status for the filter chips.
    $tally = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
    foreach ($db->query('SELECT status, COUNT(*) c FROM tblvendor_bookings GROUP BY status')->fetchAll(PDO::FETCH_OBJ) as $row) {
        $tally[$row->status] = (int) $row->c;
    }

    // ---- Status transitions (POST + CSRF) --------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enquiry_id'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("vendor_enquiries.php" . ($status_filter !== '' ? "?status={$status_filter}" : ''));
            die();
        }
        $id = (int) $_POST['enquiry_id'];
        $to = clean($_POST['to'] ?? '');
        if (!in_array($to, $valid_statuses, true)) {
            $session->message('<div class="alert alert-danger">Unknown status.</div>');
            redirect_to("vendor_enquiries.php" . ($status_filter !== '' ? "?status={$status_filter}" : ''));
            die();
        }

        $stmt = $db->prepare('SELECT vb.*, v.business_name FROM tblvendor_bookings vb JOIN tblvendors v ON v.id = vb.vendor_id WHERE vb.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row === null) {
            $session->message('<div class="alert alert-danger">That enquiry no longer exists.</div>');
            redirect_to("vendor_enquiries.php" . ($status_filter !== '' ? "?status={$status_filter}" : ''));
            die();
        }

        // Confirming requires a deal amount (defaults to the package price).
        $amount = null;
        if ($to === 'confirmed') {
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount <= 0 && $row->package_price > 0) {
                $amount = (float) $row->package_price;
            }
            if ($amount <= 0) {
                $session->message('<div class="alert alert-danger">Enter the agreed deal amount to confirm this enquiry.</div>');
                redirect_to("vendor_enquiries.php" . ($status_filter !== '' ? "?status={$status_filter}" : ''));
                die();
            }
            $commission = round($amount * 0.10, 2); // standard 10% planner commission
            $stmt = $db->prepare(
                "UPDATE tblvendor_bookings
                    SET status = 'confirmed', amount = :amt, commission = :comm,
                        notes = CONCAT(:note, notes)
                  WHERE id = :id"
            );
            $stmt->execute([
                ':amt'  => $amount,
                ':comm' => $commission,
                ':note' => '[' . date('j M Y') . ' — confirmed by ' . ($_SESSION['user_email'] ?? 'staff') . '; deal ' . format_ksh($amount, 2) . ', commission ' . format_ksh($commission, 2) . ".]\n",
                ':id'   => $id,
            ]);
        } else {
            $stmt = $db->prepare(
                "UPDATE tblvendor_bookings
                    SET status = :st,
                        notes = CONCAT(:note, notes)
                  WHERE id = :id"
            );
            $stmt->execute([
                ':st'   => $to,
                ':note' => '[' . date('j M Y') . ' — ' . $to . ' by ' . ($_SESSION['user_email'] ?? 'staff') . ".]\n",
                ':id'   => $id,
            ]);
        }
        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> '
            . htmlspecialchars($row->business_name) . ' enquiry marked <strong>' . $to . '</strong>.'
            . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        redirect_to("vendor_enquiries.php" . ($status_filter !== '' ? "?status={$status_filter}" : ''));
        die();
    }

    $status_badges = [
        'pending'   => ['badge-warning', 'clock-outline'],
        'confirmed' => ['badge-success', 'check-decagram'],
        'completed' => ['badge-primary', 'certificate'],
        'cancelled' => ['badge-secondary', 'close-circle'],
    ];
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Vendor Enquiries - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #D97706; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .enq-notes { font-size: 11.5px; color: #64748B; white-space: pre-line; }
        .filter-chip { border-radius: 999px; font-size: 12px; padding: 4px 14px; margin: 0 5px 6px 0; }
        .filter-chip.active { background: #D97706; border-color: #D97706; color: #fff; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-handshake-outline" style="color:#D97706"></i> Vendor Enquiries</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">every couple's enquiry across all vendors — confirm deals, track completions</p>
        </div>
        <a href="vendors.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-store mr-1"></i> Vendor directory</a>
    </div>

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
        <a href="vendor_enquiries.php" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === '' ? 'active' : ''; ?>">All</a>
        <?php foreach ($valid_statuses as $st): ?>
            <a href="vendor_enquiries.php?status=<?= $st; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === $st ? 'active' : ''; ?>"><?= ucfirst($st); ?> (<?= $tally[$st]; ?>)</a>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="table-responsive">
            <table class="table table-hover align-middle" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Couple</th>
                        <th>Vendor</th>
                        <th>Package</th>
                        <th>Event date</th>
                        <th>Deal</th>
                        <th>Status</th>
                        <th style="min-width: 220px;">Notes &amp; actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($enquiries)): ?>
                    <?php empty_state_row(7, 'No enquiries here', $status_filter !== '' ? 'Nothing with this status — try another filter.' : 'Couple enquiries land here the moment they are sent from the marketplace.', 'mdi-handshake-outline'); ?>
                <?php else: ?>
                    <?php foreach ($enquiries as $e): [$badge, $icon] = $status_badges[$e->status]; ?>
                    <tr>
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars(trim($e->bride . ' & ' . $e->groom)); ?></strong>
                            <div style="font-size:11px; color:#64748B;">
                                <a href="client_manage_account_details.php?booking_id=<?= (int) $e->booking_id; ?>&user_id=<?= (int) $e->user_id; ?>">booking #<?= (int) $e->booking_id; ?></a>
                                <?= $e->account_status === 'confirm' ? '<span class="badge badge-success" style="font-size:9px;">confirmed</span>' : ($e->account_status === 'pending' ? '<span class="badge badge-warning" style="font-size:9px;">pending</span>' : ''); ?>
                            </div>
                        </td>
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars($e->business_name); ?></strong>
                            <div style="font-size:11px; color:#64748B;"><?= htmlspecialchars(ucfirst($e->category)); ?></div>
                        </td>
                        <td style="font-size:12.5px;"><?= $e->package_title !== null ? htmlspecialchars($e->package_title) : '<span class="text-muted">General enquiry</span>'; ?></td>
                        <td style="font-size:12.5px;"><?= !empty($e->event_date) ? htmlspecialchars(format_date_kd($e->event_date)) : '<span class="text-muted">—</span>'; ?></td>
                        <td style="font-size:12.5px;">
                            <?php if ((float) $e->amount > 0): ?>
                                <strong><?= format_ksh($e->amount, 2); ?></strong>
                                <div style="font-size:11px; color:#64748B;">commission <?= format_ksh($e->commission, 2); ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $badge; ?>"><i class="mdi mdi-<?= $icon; ?>"></i> <?= ucfirst($e->status); ?></span></td>
                        <td>
                            <div class="enq-notes mb-1"><?= htmlspecialchars((string) $e->notes); ?></div>
                            <?php if ($e->status === 'pending'): ?>
                            <form method="post" action="vendor_enquiries.php<?= $status_filter !== '' ? '?status=' . $status_filter : ''; ?>" class="d-flex align-items-center">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="enquiry_id" value="<?= (int) $e->id; ?>">
                                <input type="number" name="amount" class="form-control form-control-sm mr-1" style="max-width: 110px;"
                                       value="<?= $e->package_price > 0 ? (int) $e->package_price : ''; ?>" min="1" title="Agreed deal amount (KSh)" required>
                                <button type="submit" name="to" value="confirmed" class="btn btn-sm btn-success mr-1" title="Confirm at this amount (10% commission)">
                                    <i class="mdi mdi-check"></i> Confirm
                                </button>
                                <button type="submit" name="to" value="cancelled" class="btn btn-sm btn-outline-secondary" title="Cancel this enquiry">
                                    <i class="mdi mdi-close"></i>
                                </button>
                            </form>
                            <?php elseif ($e->status === 'confirmed'): ?>
                            <form method="post" action="vendor_enquiries.php<?= $status_filter !== '' ? '?status=' . $status_filter : ''; ?>">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="enquiry_id" value="<?= (int) $e->id; ?>">
                                <button type="submit" name="to" value="completed" class="btn btn-sm btn-primary" title="Mark delivered and paid">
                                    <i class="mdi mdi-certificate"></i> Mark completed
                                </button>
                            </form>
                            <?php endif; ?>
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

<?php include 'include/footer.php'; ?>
</body>
</html>
