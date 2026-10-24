<?php include 'include/init.php'; ?><?php require_staff_subscribed(); ?><?php
/**
 * The planner's proposal queue (v2.30).
 *
 * WHY THIS PAGE EXISTS
 * A couple registers and the platform creates a pending booking; from there the
 * planner used to work in a spreadsheet and a WhatsApp group. This is the one
 * list that says "these couples are waiting on you", in the order they are
 * waiting: new proposal → acknowledged → options sent → tweak asked → chosen,
 * with confirmed and declined behind them. Opening a row leads to
 * proposal_view.php, where the approval, the three options and the lock happen.
 *
 * WHAT IT WRITES: nothing. Every state change is a POST on the proposal page.
 * The queue is scoped like everything else (Proposal::queue() → a planner sees
 * their own plus the unclaimed ones; a super admin sees all), so the list and
 * the page it links to can never disagree.
 */
    $status = clean($_GET['status'] ?? '');
    if (!isset(Proposal::statuses()[$status])) {
        $status = '';
    }
    $me    = planner_scope_planner_id();
    $rows  = Proposal::queue($status !== '' ? $status : null, $me);
    $open  = Proposal::open_count($me);
    $self  = 'proposals.php' . ($status !== '' ? '?status=' . urlencode($status) : '');
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Proposals - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 2px 8px rgba(0,0,0,.08); border-left:4px solid #0E7490; }
        .stat-card h6 { font-size:11px; text-transform:uppercase; color:#888; letter-spacing:.5px; margin-bottom:4px; }
        .stat-card .value { font-size:26px; font-weight:700; color:#0F172A; font-variant-numeric:tabular-nums; }
        .filter-bar { background:#fff; padding:12px 15px; border-radius:8px; margin-bottom:18px; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .filter-bar .btn { margin-right:5px; font-size:12px; }
        .filter-bar .btn.active { background:#065F46; border-color:#065F46; color:#fff; }
        table.prop-table td, table.prop-table th { vertical-align: middle; font-size:12.5px; }
        .pill { display:inline-block; border-radius:999px; padding:2px 9px; font-size:10.5px; font-weight:600; }
        .brief { font-size:11.5px; color:#64748B; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap" style="border-bottom:1px solid #dee2e6; padding-bottom:12px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-file-document-edit-outline" style="color:#0E7490;"></i> Proposals</h4>
            <p class="text-muted mb-0" style="font-size:13px;">
                Every couple waiting on a proposal — price three options, let them choose, then lock it in.
            </p>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-success" href="client.php">
                <i class="mdi mdi-calendar-multiple-check mr-1"></i> Weddings
            </a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!Proposal::enabled()): ?>
        <div class="alert alert-light border" style="font-size:13px;">
            The proposal lifecycle is switched off on this install (<code>WPMS_PROPOSAL_FLOW=0</code>),
            so new registrations go straight into the pending queue as ordinary bookings.
        </div>
    <?php endif; ?>

    <?php if (!Proposal::installed()): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <strong>The proposal tables are not installed.</strong>
            Apply <code>migrations/v2.30_proposals.sql</code> to record proposals, their options and their items.
            The pending queue keeps working without it.
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-4 mb-2">
            <div class="stat-card">
                <h6>Waiting on a planner</h6>
                <div class="value"><?= (int) $open; ?></div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="stat-card" style="border-left-color:var(--warning);">
                <h6><?= $status !== '' ? 'In this filter' : 'In the list' ?></h6>
                <div class="value"><?= count($rows); ?></div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="stat-card" style="border-left-color:var(--success);">
                <h6>Options a couple must get</h6>
                <div class="value"><?= (int) Proposal::MIN_OPTIONS; ?></div>
            </div>
        </div>
    </div>

    <div class="filter-bar">
        <a href="proposals.php" class="btn btn-sm btn-outline-primary <?= $status === '' ? 'active' : ''; ?>">
            <i class="mdi mdi-view-list"></i> All
        </a>
        <?php foreach (Proposal::statuses() as $slug => $meta): ?>
            <a href="proposals.php?status=<?= urlencode($slug); ?>" class="btn btn-sm btn-outline-<?= htmlspecialchars($meta[1]); ?> <?= $status === $slug ? 'active' : ''; ?>">
                <i class="mdi mdi-<?= htmlspecialchars($meta[2]); ?>"></i> <?= htmlspecialchars($meta[0]); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($rows === []): ?>
        <div class="card" style="border-radius:10px;">
            <div class="card-body text-center py-5">
                <i class="mdi mdi-file-document-outline" style="font-size:40px; color:#94A3B8;"></i>
                <h6 class="mt-2">Nothing waiting here</h6>
                <p class="text-muted mb-0" style="font-size:13px;">
                    <?php if ($status !== ''): ?>
                        No proposal is in "<?= htmlspecialchars(Proposal::status_label($status)); ?>" right now.
                        <a href="proposals.php">See all proposals</a>.
                    <?php elseif (!Proposal::installed()): ?>
                        Install the proposal tables and new registrations will appear here.
                    <?php else: ?>
                        New couples land here the moment they register on the public site.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.08);">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm prop-table mb-0">
                        <thead>
                            <tr>
                                <th>Couple</th>
                                <th>Wedding day</th>
                                <th>Their brief</th>
                                <th class="text-center">Options</th>
                                <th>Waiting</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row):
                            [$label, $tone, $icon] = Proposal::status_meta((string) $row->status);
                            $waiting = Proposal::waiting_for($row);
                            $owner = Proposal::planner_name($row);
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars(Proposal::couple_name($row)); ?></strong>
                                    <div class="brief"><?= htmlspecialchars((string) ($row->phone ?? '')); ?><?= !empty($row->user_email) ? ' · ' . htmlspecialchars((string) $row->user_email) : ''; ?></div>
                                    <div class="brief">
                                        <?= $owner !== '' ? 'Planner: ' . htmlspecialchars($owner) : 'Unclaimed — anyone can pick it up'; ?>
                                    </div>
                                </td>
                                <td>
                                    <?= !empty($row->wedding_date) ? htmlspecialchars(format_date_kd((string) $row->wedding_date)) : '<span class="text-muted">No date</span>'; ?>
                                </td>
                                <td>
                                    <div class="brief">
                                        <?php if ((int) ($row->guests ?? 0) > 0): ?>
                                            <i class="mdi mdi-account-multiple"></i> <?= (int) $row->guests; ?> guests
                                        <?php endif; ?>
                                        <?php if ((float) ($row->budget ?? 0) > 0): ?>
                                            · <i class="mdi mdi-cash"></i> <?= format_ksh((float) $row->budget); ?>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($row->venue ?? '')) !== ''): ?>
                                            <br><i class="mdi mdi-map-marker"></i> <?= htmlspecialchars((string) $row->venue); ?>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($row->taste_tags ?? '')) !== ''): ?>
                                            <br><i class="mdi mdi-palette-swatch"></i> <?= htmlspecialchars((string) $row->taste_tags); ?>
                                        <?php endif; ?>
                                        <?php if ((int) ($row->guests ?? 0) <= 0 && (float) ($row->budget ?? 0) <= 0 && trim((string) ($row->venue ?? '')) === ''): ?>
                                            <span class="text-muted">Nothing stated yet</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center"><?= (int) ($row->option_count ?? 0); ?></td>
                                <td class="brief"><?= $waiting !== '' ? htmlspecialchars($waiting) : '—'; ?></td>
                                <td>
                                    <span class="pill" style="background:#F1F5F9; color:#334155;">
                                        <i class="mdi mdi-<?= htmlspecialchars($icon); ?>"></i> <?= htmlspecialchars($label); ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <a href="proposal_view.php?id=<?= (int) $row->id; ?>" class="btn btn-sm btn-outline-info">
                                        Open <i class="mdi mdi-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
