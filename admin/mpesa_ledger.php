<?php include 'include/init.php'; ?>
<?php
/**
 * M-Pesa callback ledger — admin review queue for Daraja C2B verification.
 *
 * Every payment Safaricom reported to mpesa_c2b.php lands here. Verified rows
 * are marked with the subscription/payment they activated; pending rows are
 * payments nobody has claimed yet (an admin can accept them manually after a
 * phone check); rejected rows carry the reason a user-typed code failed
 * verification (wrong amount, wrong account, already used, unknown code).
 */
    if (!is_staff()) {
        redirect_to("../");
        die();
    }

    $filter = clean($_GET['filter'] ?? '');
    $valid_filters = ['pending', 'verified', 'rejected', ''];
    if (!in_array($filter, $valid_filters, true)) {
        $filter = '';
    }

    // ---- Manual accept (POST + CSRF): an admin confirms a pending payment ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_id'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to('mpesa_ledger.php');
            die();
        }
        $id = (int) $_POST['accept_id'];
        // Only a pending row can be accepted; verified_at is stamped with the
        // accepting admin's email in reject_reason='accepted-by ...' style notes.
        $stmt = $db->prepare(
            "UPDATE tblmpesa_callbacks
                SET verified_at = NOW(), ref_table = 'manual', ref_id = 0,
                    reject_reason = CONCAT('accepted manually by ', :who, ' on ', NOW())
              WHERE id = :id AND verified_at IS NULL"
        );
        $stmt->execute([':who' => (string) ($_SESSION['user_email'] ?? 'staff'), ':id' => $id]);
        $session->message($stmt->rowCount() > 0
            ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Payment accepted — it is now marked verified in the ledger.</div>'
            : '<div class="alert alert-danger">That payment was already processed.</div>');
        redirect_to('mpesa_ledger.php' . ($filter !== '' ? "?filter={$filter}" : ''));
        die();
    }

    $stats = MpesaC2B::stats();

    global $db;
    $sql    = 'SELECT * FROM tblmpesa_callbacks';
    $where  = [];
    $params = [];
    if ($filter === 'pending')   { $where[] = 'verified_at IS NULL AND reject_reason IS NULL'; }
    if ($filter === 'verified')  { $where[] = 'verified_at IS NOT NULL'; }
    if ($filter === 'rejected')  { $where[] = 'verified_at IS NULL AND reject_reason IS NOT NULL'; }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 200';
    $rows = $db->prepare($sql);
    $rows->execute($params);
    $rows = $rows->fetchAll(PDO::FETCH_OBJ);

    // Human label for what a verified callback was used on.
    $refLabel = function ($row): string {
        if ($row->verified_at === null) {
            return '';
        }
        return match ($row->ref_table) {
            'tblsubscriptions' => 'Subscription #' . (int) $row->ref_id,
            'tbl_liquidation'  => 'Payment #' . (int) $row->ref_id,
            'manual'           => 'Accepted manually',
            default            => $row->ref_table !== null ? $row->ref_table . ' #' . (int) $row->ref_id : '—',
        };
    };
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>M-Pesa Ledger - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #059669; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .filter-chip { border-radius: 999px; font-size: 12px; padding: 4px 14px; margin: 0 5px 6px 0; }
        .filter-chip.active { background: #059669; border-color: #059669; color: #fff; }
        .ledger-table td, .ledger-table th { font-size: 12.5px; vertical-align: middle; }
        .mono { font-variant-numeric: tabular-nums; }
        .pill { font-size: 10px; padding: 3px 9px; border-radius: 999px; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-cellphone-arrow-down" style="color:#059669"></i> M-Pesa Ledger</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">Daraja C2B payments reported by Safaricom — verification for subscription &amp; payment codes</p>
        </div>
        <div class="text-right">
            <span class="badge <?= MpesaC2B::verification_enabled() ? 'badge-success' : 'badge-secondary'; ?>" style="font-size: 11px;">
                <i class="mdi mdi-<?= MpesaC2B::verification_enabled() ? 'shield-check' : 'shield-off'; ?>"></i>
                Auto-verification <?= MpesaC2B::verification_enabled() ? 'ON' : 'OFF'; ?> (WPMS_MPESA_VERIFY)
            </span>
            <a href="revenue.php" class="btn btn-sm btn-outline-secondary ml-1"><i class="mdi mdi-cash-multiple mr-1"></i> Revenue</a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Verified</h6><div class="value"><?= $stats['verified']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#D97706"><h6>Pending review</h6><div class="value"><?= $stats['pending']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#DC2626"><h6>Rejected</h6><div class="value"><?= $stats['rejected']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:#0F172A"><h6>Verified value</h6><div class="value mono"><?= format_ksh($stats['total_ksh']); ?></div></div></div>
    </div>

    <div class="mb-3">
        <a href="mpesa_ledger.php" class="btn btn-sm btn-outline-secondary filter-chip <?= $filter === '' ? 'active' : ''; ?>">All</a>
        <a href="mpesa_ledger.php?filter=pending" class="btn btn-sm btn-outline-secondary filter-chip <?= $filter === 'pending' ? 'active' : ''; ?>">Pending (<?= $stats['pending']; ?>)</a>
        <a href="mpesa_ledger.php?filter=verified" class="btn btn-sm btn-outline-secondary filter-chip <?= $filter === 'verified' ? 'active' : ''; ?>">Verified (<?= $stats['verified']; ?>)</a>
        <a href="mpesa_ledger.php?filter=rejected" class="btn btn-sm btn-outline-secondary filter-chip <?= $filter === 'rejected' ? 'active' : ''; ?>">Rejected (<?= $stats['rejected']; ?>)</a>
    </div>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="table-responsive">
            <table class="table table-hover ledger-table" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Received</th>
                        <th>Code</th>
                        <th>Payer</th>
                        <th>Amount</th>
                        <th>Account ref</th>
                        <th>Status</th>
                        <th style="min-width:180px;">Used on / reason</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <?php empty_state_row(7, 'Nothing here yet', $filter === 'pending' ? 'Payments appear the moment Safaricom reports them to mpesa_c2b.php.' : 'No rows with this status.', 'mdi-cellphone-arrow-down'); ?>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="font-size:12.5px; white-space:nowrap;"><?= htmlspecialchars(format_date_kd((string) $r->created_at, true)); ?></td>
                        <td class="mono" style="font-size:12.5px;"><strong><?= htmlspecialchars($r->trans_id); ?></strong></td>
                        <td style="font-size:12.5px;">
                            <?= htmlspecialchars(trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '—'); ?>
                            <div class="mono" style="font-size:11px; color:#64748B;"><?= htmlspecialchars($r->msisdn); ?></div>
                        </td>
                        <td class="mono" style="font-size:12.5px;"><strong><?= format_ksh($r->amount, 2); ?></strong></td>
                        <td style="font-size:12.5px;"><?= $r->bill_ref_number !== null ? htmlspecialchars($r->bill_ref_number) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php if ($r->verified_at !== null): ?>
                                <span class="pill badge-success">verified</span>
                            <?php elseif ($r->reject_reason !== null): ?>
                                <span class="pill badge-danger">rejected</span>
                            <?php else: ?>
                                <span class="pill badge-warning">pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;">
                            <?php if ($r->verified_at !== null): ?>
                                <span class="text-success"><?= htmlspecialchars($refLabel($r)); ?></span>
                            <?php elseif ($r->reject_reason !== null): ?>
                                <span class="text-danger"><?= htmlspecialchars($r->reject_reason); ?></span>
                            <?php else: ?>
                                <form method="post" class="form-inline">
                                    <?= $session->csrf_field(); ?>
                                    <input type="hidden" name="accept_id" value="<?= (int) $r->id; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" style="font-size:11px;"
                                            onclick="return confirm('Accept this <?= format_ksh($r->amount, 2); ?> payment (<?= htmlspecialchars($r->trans_id); ?>) as verified?');">
                                        <i class="mdi mdi-check"></i> Accept
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

    <p class="text-muted mt-3" style="font-size: 12px;">
        Auto-verification gates code entry on <code>subscription.php</code> and <code>budget_add.php</code> when
        <code>WPMS_MPESA_VERIFY=1</code> is set. With it off, codes are accepted as before (dev/demo mode) and this
        ledger still records everything Safaricom reports — flip the flag in production without a code change.
        A rejected entry only means the <em>user-typed code</em> failed verification; the underlying Safaricom
        payment above it is never deleted.
    </p>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
