<?php include 'include/init.php'; ?>
<?php
/**
 * Vendor self-service packages — CRUD on the vendor's own tblvendor_packages
 * rows only. Every query is scoped by vendor_id AND the session user; the
 * vendor can never touch another vendor's packages. Toggling a package
 * inactive hides it from the couple-facing marketplace instantly.
 */
    require_vendor();

    $user_id = (int) $_SESSION['id'];
    /*
     * An account may run several businesses. get_by_user() returns only the
     * first of them, which pinned this page to one listing and left a second
     * business's packages unmanageable by its own owner. Work on the listing
     * the session has selected instead (ownership re-validated on every
     * request, so the choice can never reach another vendor's rows).
     */
    $myListings = Vendor::all_for_user($user_id);
    $vendor  = current_vendor_listing($user_id, $myListings);
    if (!vendor_listing_visible_to_session($vendor)) {
        redirect_to("vendor_register.php");
        die();
    }
    $vid = (int) $vendor->id;

    /** Ownership-checked fetch: the package must belong to THIS vendor. */
    $own_package = function (int $pid) use ($db, $vid): ?object {
        $stmt = $db->prepare('SELECT * FROM tblvendor_packages WHERE id = :pid AND vendor_id = :vid LIMIT 1');
        $stmt->execute([':pid' => $pid, ':vid' => $vid]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to("vendor_packages.php");
            die();
        }

        $action = clean($_POST['action'] ?? '');

        if ($action === 'add') {
            $title = clean($_POST['title'] ?? '');
            $description = clean($_POST['description'] ?? '');
            $price = (float) ($_POST['price'] ?? 0);
            $capacity = (int) ($_POST['capacity'] ?? 0);
            $duration = (int) ($_POST['duration_hours'] ?? 0);
            if ($title === '' || $price <= 0) {
                $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> A package needs a name and a price above zero.</div>');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
                     VALUES (:vid, :title, :descr, :price, :cap, :dur, 1)'
                );
                $stmt->execute([
                    ':vid' => $vid, ':title' => $title, ':descr' => $description,
                    ':price' => $price,
                    ':cap' => $capacity > 0 ? $capacity : null,
                    ':dur' => $duration > 0 ? $duration : null,
                ]);
                $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Package added — couples can now enquire about it.</div>');
            }
        } elseif ($action === 'toggle') {
            $pkg = $own_package((int) ($_POST['package_id'] ?? 0));
            if ($pkg === null) {
                $session->message('<div class="alert alert-danger">That package is not yours.</div>');
            } else {
                // Every statement below carries the ownership clause itself:
                // $pkg was fetched through $own_package(), and the write proves
                // it again rather than trusting that one step.
                $db->prepare('UPDATE tblvendor_packages SET is_active = 1 - is_active WHERE id = :pid AND vendor_id = :vid')
                   ->execute([':pid' => (int) $pkg->id, ':vid' => $vid]);
                $session->message('<div class="alert alert-success">Package ' . ((int) $pkg->is_active === 1 ? 'hidden' : 'published') . '.</div>');
            }
        } elseif ($action === 'delete') {
            $pkg = $own_package((int) ($_POST['package_id'] ?? 0));
            if ($pkg === null) {
                $session->message('<div class="alert alert-danger">That package is not yours.</div>');
            } else {
                // Keep history consistent: past bookings reference package_id,
                // so soft-hide instead of hard delete when it has bookings.
                $used = (int) $db->fetchValue(
                    'SELECT COUNT(*) FROM tblvendor_bookings WHERE package_id = :pid',
                    [':pid' => (int) $pkg->id]
                );
                if ($used > 0) {
                    $db->prepare('UPDATE tblvendor_packages SET is_active = 0 WHERE id = :pid AND vendor_id = :vid')
                       ->execute([':pid' => (int) $pkg->id, ':vid' => $vid]);
                    $session->message('<div class="alert alert-warning">This package has past bookings, so it was hidden instead of deleted (history stays intact).</div>');
                } else {
                    $db->prepare('DELETE FROM tblvendor_packages WHERE id = :pid AND vendor_id = :vid')
                       ->execute([':pid' => (int) $pkg->id, ':vid' => $vid]);
                    $session->message('<div class="alert alert-success">Package deleted.</div>');
                }
            }
        } elseif ($action === 'tiers') {
            $result = VendorTier::save_package((int) ($_POST['package_id'] ?? 0), $vid, $_POST);
            $session->message($result['ok']
                ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Price tiers saved.</div>'
                : '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($result['error']) . '</div>');
        } elseif ($action === 'edit') {
            $pkg = $own_package((int) ($_POST['package_id'] ?? 0));
            $title = clean($_POST['title'] ?? '');
            $description = clean($_POST['description'] ?? '');
            $price = (float) ($_POST['price'] ?? 0);
            $capacity = (int) ($_POST['capacity'] ?? 0);
            $duration = (int) ($_POST['duration_hours'] ?? 0);
            if ($pkg === null) {
                $session->message('<div class="alert alert-danger">That package is not yours.</div>');
            } elseif ($title === '' || $price <= 0) {
                $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> A package needs a name and a price above zero.</div>');
            } else {
                $db->prepare(
                    'UPDATE tblvendor_packages
                        SET title = :title, description = :descr, price = :price,
                            capacity = :cap, duration_hours = :dur
                      WHERE id = :pid AND vendor_id = :vid'
                )->execute([
                    ':title' => $title, ':descr' => $description, ':price' => $price,
                    ':cap' => $capacity > 0 ? $capacity : null,
                    ':dur' => $duration > 0 ? $duration : null,
                    ':pid' => (int) $pkg->id,
                    ':vid' => $vid,
                ]);
                // A plain edit still posts one price; when tiers exist, Silver
                // must win or the card's "from" price stops being the cheapest.
                VendorTier::sync_base('package', (int) $pkg->id);
                $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Package updated.</div>');
            }
        }

        redirect_to("vendor_packages.php");
        die();
    }

    $packages = Vendor::packages_for($vid); // active only — plus all for the owner below
    $stmt = $db->prepare('SELECT * FROM tblvendor_packages WHERE vendor_id = :vid ORDER BY is_active DESC, price ASC');
    $stmt->execute([':vid' => $vid]);
    $all_packages = $stmt->fetchAll(PDO::FETCH_OBJ);
    // One query for every card's tiers, rather than one per package.
    $tierMap = VendorTier::map_for_packages(array_map(static fn (object $p): int => (int) $p->id, $all_packages));
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Packages - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .pkg-card { background: #fff; border-radius: 12px; padding: 16px 18px; margin-bottom: 12px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #D97706; }
        .pkg-card.inactive { opacity: .55; border-left-color: var(--stone-300); }
        .pkg-title { font-size: 15px; font-weight: 700; color: #0F172A; }
        .pkg-price { font-size: 18px; font-weight: 700; color: #065F46; }
        .mono { font-variant-numeric: tabular-nums; }
        .required::after { content: ' *'; color: #DC2626; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-package-variant-closed"></i> My Packages</h4>
            <p class="text-muted mb-0" style="font-size:13px;"><?= htmlspecialchars((string) $vendor->business_name); ?></p>
            <?php vendor_listing_switcher($myListings, $vid, 'vendor_packages.php'); ?>
            <p class="text-muted mb-0 mt-1" style="font-size:12px;">
                A package is what a couple buys as one price. The pieces inside it — buffet per guest, service crew,
                cake cutting — are your <a href="vendor_services.php">services</a>, and those are what a planner can compare.
            </p>
        </div>
        <a href="vendor_home.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> My Bookings</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-5">
            <div class="pkg-card" style="border-left-color:var(--success)">
                <h6 class="mb-3"><i class="mdi mdi-plus-circle"></i> Add a package</h6>
                <form method="post">
                    <?= $session->csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label class="required">Name</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Choma Premium — 250 pax" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="required">Price (KSh)</label>
                            <input type="number" name="price" class="form-control" step="0.01" min="1" required>
                        </div>
                        <div class="form-group col-3">
                            <label>Pax</label>
                            <input type="number" name="capacity" class="form-control" min="1">
                        </div>
                        <div class="form-group col-3">
                            <label>Hours</label>
                            <input type="number" name="duration_hours" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>What's included</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Menu, equipment, service details..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm btn-block"><i class="mdi mdi-check"></i> Add Package</button>
                </form>
            </div>
        </div>

        <div class="col-md-7">
            <h6 class="mb-3" style="font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:#64748B;">Your packages (<?= count($all_packages); ?>)</h6>
            <?php if (empty($all_packages)): ?>
                <?php empty_state('No packages yet', 'Add your first package on the left — couples enquire against these.', 'mdi-package-variant-closed'); ?>
            <?php else: foreach ($all_packages as $p): ?>
                <div class="pkg-card <?= (int) $p->is_active === 1 ? '' : 'inactive'; ?>">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <span class="pkg-title"><?= htmlspecialchars($p->title); ?></span>
                            <?php if ((int) $p->is_active !== 1): ?>
                                <span class="badge badge-secondary" style="font-size:9px;">hidden</span>
                            <?php endif; ?>
                        </div>
                        <span class="pkg-price mono"><?= format_ksh($p->price); ?></span>
                    </div>
                    <?php $tiers = $tierMap[(int) $p->id] ?? []; ?>
                    <?php if ($tiers !== []): ?>
                        <div class="text-muted mb-1" style="font-size:12px;"><i class="mdi mdi-layers-triple-outline"></i> <?= htmlspecialchars(VendorTier::line($tiers)); ?></div>
                    <?php endif; ?>
                    <div class="text-muted mb-2" style="font-size:12px;">
                        <?= !empty($p->capacity) ? (int) $p->capacity . ' pax · ' : ''; ?>
                        <?= !empty($p->duration_hours) ? (int) $p->duration_hours . ' hrs · ' : ''; ?>
                        <?= htmlspecialchars(trim_body((string) ($p->description ?? ''), 90)); ?>
                    </div>
                    <details>
                        <summary style="font-size:12px; cursor:pointer; color:#4C6EF5;">edit / manage</summary>
                        <form method="post" class="mt-2">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="package_id" value="<?= (int) $p->id; ?>">
                            <div class="form-row">
                                <div class="form-group col-7">
                                    <label>Name</label>
                                    <input type="text" name="title" class="form-control form-control-sm" value="<?= htmlspecialchars($p->title); ?>" required>
                                </div>
                                <div class="form-group col-5">
                                    <label>Price (KSh)</label>
                                    <input type="number" name="price" class="form-control form-control-sm" step="0.01" min="1" value="<?= htmlspecialchars((string) $p->price); ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-4">
                                    <label>Pax</label>
                                    <input type="number" name="capacity" class="form-control form-control-sm" min="1" value="<?= (int) ($p->capacity ?? 0); ?>">
                                </div>
                                <div class="form-group col-4">
                                    <label>Hours</label>
                                    <input type="number" name="duration_hours" class="form-control form-control-sm" min="1" value="<?= (int) ($p->duration_hours ?? 0); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($p->description ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-sm btn-success"><i class="mdi mdi-content-save"></i> Save</button>
                            <?php if ($tiers !== []): ?>
                                <small class="text-muted d-block mt-1">Silver is set by your price tiers below.</small>
                            <?php endif; ?>
                        </form>
                        <?php if (VendorTier::installed()): ?>
                        <form method="post" class="mt-3">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="tiers">
                            <input type="hidden" name="package_id" value="<?= (int) $p->id; ?>">
                            <strong style="font-size:12px;"><i class="mdi mdi-layers-triple-outline"></i> Price tiers</strong>
                            <table class="table table-sm mb-1 mt-1">
                                <thead><tr>
                                    <th style="font-size:11px;">Tier</th>
                                    <th style="font-size:11px;">Price (KSh)</th>
                                    <th style="font-size:11px;">Includes</th>
                                    <th style="font-size:11px;">Leaves out</th>
                                </tr></thead>
                                <tbody><?= VendorTier::editor_rows($tiers); ?></tbody>
                            </table>
                            <small class="text-muted d-block mb-1">All three or none. Silver becomes the price shown on every card.</small>
                            <button type="submit" class="btn btn-sm btn-outline-success"><i class="mdi mdi-layers-triple-outline"></i> Save tiers</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Hide or show this package?');">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="package_id" value="<?= (int) $p->id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                <i class="mdi mdi-<?= (int) $p->is_active === 1 ? 'eye-off' : 'eye'; ?>"></i>
                                <?= (int) $p->is_active === 1 ? 'Hide' : 'Publish'; ?>
                            </button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this package?');">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="package_id" value="<?= (int) $p->id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="mdi mdi-delete"></i> Delete</button>
                        </form>
                    </details>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
