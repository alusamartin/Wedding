<?php include 'include/init.php'; ?>
<?php
/**
 * Vendor self-service services — the vendor's own rows in tblvendor_services,
 * scoped by vendor_id AND the session user on every read and write.
 *
 * WHY A SEPARATE PAGE
 * My Packages sells a BUNDLE ("Full catering — KSh 180,000"). This page sells
 * the PIECES that bundle is made of, each with a unit: buffet per guest, service
 * crew per waiter, cake cutting flat. Those pieces are what a planner can
 * compare across vendors and budget against a couple's number, which a package
 * title never allowed. Packages keep working exactly as before.
 *
 * TWO WAYS TO ADD, ONE SHAPE
 *   from the catalogue   pick a known service; its name and unit come from the
 *                        platform vocabulary, so a vendor cannot invent a
 *                        synonym for "buffet"
 *   custom               type a title and choose a unit — a "Ngurario goat
 *                        feast" is stored with the same price/unit shape as a
 *                        catalogue buffet, so comparison works on both
 *
 * The catalogue is read-only here by design: it is the platform's vocabulary,
 * not each vendor's. Adding to it is an admin change (a migration), so two
 * caterers always quote the same line item the same way.
 */
    require_vendor();

    $user_id = (int) $_SESSION['id'];
    $myListings = Vendor::all_for_user($user_id);
    $vendor  = current_vendor_listing($user_id, $myListings);
    if (!vendor_listing_visible_to_session($vendor)) {
        redirect_to("vendor_register.php");
        die();
    }
    $vid = (int) $vendor->id;

    $installed = VendorService::installed();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to("vendor_services.php");
            die();
        }

        $action = clean($_POST['action'] ?? '');

        if ($action === 'add_catalog' || $action === 'add_custom') {
            $input = $_POST;
            if ($action === 'add_catalog') {
                $item = VendorService::catalogue_item((int) ($_POST['catalog_id'] ?? 0));
                if ($item === null) {
                    $session->message('<div class="alert alert-danger">That catalogue service is not available — pick another, or add your own.</div>');
                    redirect_to("vendor_services.php");
                    die();
                }
                // The vocabulary decides the NAME and the UNIT; the vendor only
                // says their price and quantity. That is what keeps two
                // caterers' "Buffet" comparable.
                $input['title'] = (string) $item->name;
                $input['unit']  = (string) $item->default_unit;
                $input['catalog_id'] = (int) $item->id;
                if (trim((string) ($input['description'] ?? '')) === '') {
                    $input['description'] = (string) $item->description;
                }
            } else {
                $input['catalog_id'] = 0;
            }

            $result = VendorService::create($vid, $input);
            $session->message($result['ok']
                ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Service added — it is live on your profile.</div>'
                : '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($result['error']) . '</div>');
        } elseif ($action === 'edit') {
            $service_id = (int) ($_POST['service_id'] ?? 0);
            $result = VendorService::update($service_id, $vid, $_POST);
            // An edit still posts a single price; when tiers exist, Silver must
            // win, or the "from" price on every marketplace card drifts away
            // from the cheapest tier the vendor is actually offering.
            if ($result['ok']) {
                VendorTier::sync_base('service', $service_id);
            }
            $session->message($result['ok']
                ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Service updated.</div>'
                : '<div class="alert alert-danger">' . htmlspecialchars($result['error']) . '</div>');
        } elseif ($action === 'tiers') {
            $result = VendorTier::save_service((int) ($_POST['service_id'] ?? 0), $vid, $_POST);
            $session->message($result['ok']
                ? '<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Price tiers saved.</div>'
                : '<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> ' . htmlspecialchars($result['error']) . '</div>');
        } elseif ($action === 'toggle') {
            $now_active = VendorService::toggle((int) ($_POST['service_id'] ?? 0), $vid);
            $session->message($now_active === null
                ? '<div class="alert alert-danger">That service is not yours.</div>'
                : '<div class="alert alert-success">Service ' . ($now_active ? 'published' : 'hidden') . '.</div>');
        } elseif ($action === 'delete') {
            $session->message(VendorService::remove((int) ($_POST['service_id'] ?? 0), $vid)
                ? '<div class="alert alert-success">Service deleted.</div>'
                : '<div class="alert alert-danger">That service is not yours.</div>');
        }

        redirect_to("vendor_services.php" . (isset($_GET['all']) ? '?all=1' : ''));
        die();
    }

    // The catalogue picker shows this vendor's own category by default; a
    // photographer who also runs transport can ask for every category.
    $show_all   = isset($_GET['all']) && (string) $_GET['all'] === '1';
    $catalogue  = $installed ? VendorService::catalogue_grouped($show_all ? null : (string) $vendor->category) : [];
    $services   = $installed ? VendorService::for_vendor($vid, false) : [];
    $active     = array_values(array_filter($services, static fn (object $s): bool => (int) $s->is_active === 1));
    $hidden     = array_values(array_filter($services, static fn (object $s): bool => (int) $s->is_active !== 1));
    $summary    = $installed ? VendorService::summary($vid) : ['count' => 0, 'min' => null, 'max' => null];
    $categories = VendorCoverage::categories();
    // One query for every card's tiers, rather than one per service.
    $tierMap    = VendorTier::map_for_services(array_map(static fn (object $s): int => (int) $s->id, $services));

    /** Group published services by their catalogue category, custom last. */
    $grouped = [];
    foreach ($services as $s) {
        $key = (string) ($s->catalog_category ?? '');
        $grouped[$key === '' ? '_custom' : $key][] = $s;
    }
    // Deterministic order: catalogue categories in board order, custom last.
    $ordered = [];
    foreach (array_keys($categories) as $slug) {
        if (isset($grouped[$slug])) {
            $ordered[$slug] = $grouped[$slug];
            unset($grouped[$slug]);
        }
    }
    if (isset($grouped['_custom'])) {
        $ordered['_custom'] = $grouped['_custom'];
    }
?>
<?php $users_profile = Users::find_by_id($user_id); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>My Services - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/7.4.47/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .svc-card { background:#fff; border-radius:12px; padding:14px 18px; margin-bottom:10px;
                    box-shadow:0 1px 3px rgba(0,0,0,.08); border-left:4px solid #0E7490; }
        .svc-card.inactive { opacity:.55; border-left-color:var(--stone-300); }
        .svc-title { font-size:15px; font-weight:700; color:#0F172A; }
        .svc-price { font-size:15px; font-weight:700; color:#065F46; white-space:nowrap; }
        .svc-meta { font-size:12px; color:#64748B; }
        .svc-group-head { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#64748B;
                          margin:18px 0 8px; font-weight:600; }
        .svc-tag { display:inline-block; background:#ECFEFF; color:#0E7490; border-radius:10px;
                   padding:1px 8px; font-size:11px; margin:2px 4px 0 0; }
        .svc-includes { font-size:12px; color:#475569; }
        .mono { font-variant-numeric: tabular-nums; }
        .required::after { content:' *'; color:#DC2626; }
        .summary-chip { background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px;
                        padding:8px 12px; font-size:12px; color:#475569; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4" style="border-bottom:1px solid #dee2e6; padding-bottom:15px;">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-format-list-bulleted"></i> My Services</h4>
            <p class="text-muted mb-0" style="font-size:13px;"><?= htmlspecialchars((string) $vendor->business_name); ?></p>
            <?php vendor_listing_switcher($myListings, $vid, 'vendor_services.php', $show_all ? ['all' => '1'] : []); ?>
        </div>
        <div class="text-right">
            <a href="vendor_packages.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-package-variant-closed"></i> My Packages</a>
            <a href="vendor_home.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-arrow-left"></i> My Bookings</a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!$installed): ?>
        <div class="alert alert-warning">
            <strong>Services are not available yet.</strong>
            Apply <code>migrations/v2.27_vendor_services.sql</code> to finish the upgrade. Your packages are unaffected and keep selling.
        </div>
    <?php else: ?>
        <div class="d-flex flex-wrap align-items-center mb-3">
            <div class="summary-chip mr-2 mb-2"><strong><?= count($active); ?></strong> published</div>
            <?php if ($hidden !== []): ?>
                <div class="summary-chip mr-2 mb-2"><strong><?= count($hidden); ?></strong> hidden</div>
            <?php endif; ?>
            <?php if ($summary['count'] > 0): ?>
                <div class="summary-chip mr-2 mb-2"><?= format_ksh((float) $summary['min']); ?> – <?= format_ksh((float) $summary['max']); ?></div>
            <?php endif; ?>
            <div class="ml-auto mb-2">
                <a href="vendor_services.php<?= $show_all ? '' : '?all=1'; ?>" class="btn btn-sm btn-outline-info">
                    <i class="mdi mdi-<?= $show_all ? 'filter-variant-minus' : 'filter-variant-plus'; ?>"></i>
                    <?= $show_all ? 'Only my category' : 'Show every category'; ?>
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="svc-card" style="border-left-color:var(--success);">
                    <h6 class="mb-1"><i class="mdi mdi-plus-circle"></i> Add a known service</h6>
                    <p class="svc-meta mb-3">
                        Picking from the list sets the name and the unit, so your price can be compared with every other vendor's.
                    </p>
                    <?php if ($catalogue === []): ?>
                        <p class="text-muted mb-0" style="font-size:12.5px;">
                            There is no catalogue service in this category<?= $show_all ? '' : ' — try “Show every category”'; ?>. Add your own below.
                        </p>
                    <?php else: ?>
                        <form method="post" id="catalogForm">
                            <?= $session->csrf_field(); ?>
                            <input type="hidden" name="action" value="add_catalog">
                            <div class="form-group">
                                <label class="required" for="catalogPick">Service</label>
                                <select name="catalog_id" id="catalogPick" class="form-control" required>
                                    <option value="">Choose a service&hellip;</option>
                                    <?php foreach ($catalogue as $slug => $items): ?>
                                        <optgroup label="<?= htmlspecialchars($categories[$slug][0] ?? ucfirst((string) $slug)); ?>">
                                            <?php foreach ($items as $item): ?>
                                                <option value="<?= (int) $item->id; ?>"
                                                        data-unit="<?= htmlspecialchars((string) $item->default_unit); ?>"
                                                        data-title="<?= htmlspecialchars((string) $item->name); ?>"
                                                        data-description="<?= htmlspecialchars((string) $item->description); ?>">
                                                    <?= htmlspecialchars((string) $item->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="catalogUnitHint">Pick a service to see how it is quoted.</small>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-6">
                                    <label class="required">Price (KSh)</label>
                                    <input type="number" name="price" class="form-control" step="0.01" min="1" required>
                                </div>
                                <div class="form-group col-3">
                                    <label>Min</label>
                                    <input type="number" name="min_qty" class="form-control" min="1">
                                </div>
                                <div class="form-group col-3">
                                    <label>Max</label>
                                    <input type="number" name="max_qty" class="form-control" min="1">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>What's included</label>
                                <input type="text" name="includes" class="form-control" maxlength="500" placeholder="e.g. staff, crockery, setup">
                            </div>
                            <div class="form-group">
                                <label>Taste tags</label>
                                <input type="text" name="tags" class="form-control" maxlength="255" placeholder="rustic, halal, outdoor (comma separated)">
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" class="form-control" rows="2" maxlength="2000" placeholder="Optional — the catalogue wording is used if left blank."></textarea>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm btn-block"><i class="mdi mdi-check"></i> Add service</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="svc-card" style="border-left-color:var(--warning);">
                    <h6 class="mb-1"><i class="mdi mdi-pencil-plus"></i> Add your own service</h6>
                    <p class="svc-meta mb-3">
                        For what the catalogue does not cover. Choose a unit — that is what makes it comparable.
                    </p>
                    <form method="post">
                        <?= $session->csrf_field(); ?>
                        <input type="hidden" name="action" value="add_custom">
                        <div class="form-group">
                            <label class="required">Name</label>
                            <input type="text" name="title" class="form-control" maxlength="160" placeholder="e.g. Ngurario goat feast" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-6">
                                <label class="required">Quoted</label>
                                <select name="unit" class="form-control">
                                    <?php foreach (VendorService::units() as $slug => $unit): ?>
                                        <option value="<?= htmlspecialchars($slug); ?>" <?= $slug === 'per_guest' ? 'selected' : ''; ?>><?= htmlspecialchars($unit[0]); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-6">
                                <label class="required">Price (KSh)</label>
                                <input type="number" name="price" class="form-control" step="0.01" min="1" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-4">
                                <label>Min</label>
                                <input type="number" name="min_qty" class="form-control" min="1">
                            </div>
                            <div class="form-group col-4">
                                <label>Max</label>
                                <input type="number" name="max_qty" class="form-control" min="1">
                            </div>
                            <div class="form-group col-4">
                                <label>Lead days</label>
                                <input type="number" name="lead_time_days" class="form-control" min="1" max="365">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>What's included</label>
                            <input type="text" name="includes" class="form-control" maxlength="500" placeholder="e.g. chef, serving dishes, cleanup">
                        </div>
                        <div class="form-group">
                            <label>Taste tags</label>
                            <input type="text" name="tags" class="form-control" maxlength="255" placeholder="rustic, halal, outdoor">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3" maxlength="2000"></textarea>
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm btn-block"><i class="mdi mdi-check"></i> Add custom service</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($services === []): ?>
                    <?php empty_state(
                        'No services yet',
                        'Add the pieces you sell — a buffet per guest, a service crew per waiter. Packages stay as bundles of these.',
                        'mdi-format-list-bulleted',
                        'vendor_packages.php',
                        'Manage my packages',
                        'mdi-package-variant-closed'
                    ); ?>
                <?php else: ?>
                    <?php foreach ($ordered as $slug => $rows): ?>
                        <div class="svc-group-head">
                            <?= $slug === '_custom' ? 'Your own services' : htmlspecialchars($categories[$slug][0] ?? ucfirst((string) $slug)); ?>
                            <span class="text-muted" style="text-transform:none; letter-spacing:0;">(<?= count($rows); ?>)</span>
                        </div>
                        <?php foreach ($rows as $s): ?>
                            <div class="svc-card <?= (int) $s->is_active === 1 ? '' : 'inactive'; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="svc-title"><?= htmlspecialchars((string) $s->title); ?></span>
                                        <?php if ((string) ($s->catalog_id ?? '') !== ''): ?>
                                            <span class="badge badge-info" style="font-size:9px;">catalogue</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary" style="font-size:9px;">custom</span>
                                        <?php endif; ?>
                                        <?php if ((int) $s->is_active !== 1): ?>
                                            <span class="badge badge-secondary" style="font-size:9px;">hidden</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="svc-price mono"><?= htmlspecialchars(VendorService::price_line($s)); ?></span>
                                </div>
                                <?php $tiers = $tierMap[(int) $s->id] ?? []; ?>
                                <?php if ($tiers !== []): ?>
                                    <div class="svc-meta mt-1"><i class="mdi mdi-layers-triple-outline"></i> <?= htmlspecialchars(VendorTier::line($tiers)); ?></div>
                                <?php endif; ?>

                                <?php if (trim((string) ($s->includes ?? '')) !== ''): ?>
                                    <div class="svc-includes mt-1"><i class="mdi mdi-check-circle-outline"></i> <?= htmlspecialchars((string) $s->includes); ?></div>
                                <?php endif; ?>
                                <?php if (VendorService::tag_list($s) !== []): ?>
                                    <div class="mt-1">
                                        <?php foreach (VendorService::tag_list($s) as $tag): ?>
                                            <span class="svc-tag"><?= htmlspecialchars($tag); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($s->description ?? '')) !== ''): ?>
                                    <p class="svc-meta mt-2 mb-0"><?= nl2br(htmlspecialchars(trim((string) $s->description))); ?></p>
                                <?php endif; ?>
                                <div class="svc-meta mt-2">
                                    <?php if ((int) ($s->lead_time_days ?? 0) > 0): ?>
                                        <span class="mr-3"><i class="mdi mdi-calendar-clock"></i> <?= (int) $s->lead_time_days; ?> days' notice</span>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($s->catalog_name ?? '')) !== ''): ?>
                                        <span class="mr-3"><i class="mdi mdi-tag-outline"></i> <?= htmlspecialchars((string) $s->catalog_name); ?></span>
                                    <?php endif; ?>
                                </div>

                                <details class="mt-2">
                                    <summary style="font-size:12px; cursor:pointer; color:#4C6EF5;">edit / manage</summary>
                                    <form method="post" class="mt-2">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="service_id" value="<?= (int) $s->id; ?>">
                                        <div class="form-row">
                                            <div class="form-group col-7">
                                                <label>Name</label>
                                                <input type="text" name="title" class="form-control form-control-sm" maxlength="160" value="<?= htmlspecialchars((string) $s->title); ?>" required>
                                            </div>
                                            <div class="form-group col-5">
                                                <label>Price (KSh)</label>
                                                <input type="number" name="price" class="form-control form-control-sm" step="0.01" min="1" value="<?= htmlspecialchars((string) $s->price); ?>" required>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group col-4">
                                                <label>Quoted</label>
                                                <select name="unit" class="form-control form-control-sm">
                                                    <?php foreach (VendorService::units() as $unit_slug => $unit): ?>
                                                        <option value="<?= htmlspecialchars($unit_slug); ?>" <?= (string) $s->pricing_unit === $unit_slug ? 'selected' : ''; ?>><?= htmlspecialchars($unit[0]); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group col-4">
                                                <label>Min</label>
                                                <input type="number" name="min_qty" class="form-control form-control-sm" min="1" value="<?= (int) ($s->min_qty ?? 0) ?: ''; ?>">
                                            </div>
                                            <div class="form-group col-4">
                                                <label>Max</label>
                                                <input type="number" name="max_qty" class="form-control form-control-sm" min="1" value="<?= (int) ($s->max_qty ?? 0) ?: ''; ?>">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group col-6">
                                                <label>Lead days</label>
                                                <input type="number" name="lead_time_days" class="form-control form-control-sm" min="1" max="365" value="<?= (int) ($s->lead_time_days ?? 0) ?: ''; ?>">
                                            </div>
                                            <div class="form-group col-6">
                                                <label>Hidden?</label>
                                                <select name="is_active" class="form-control form-control-sm">
                                                    <option value="1" <?= (int) $s->is_active === 1 ? 'selected' : ''; ?>>Published</option>
                                                    <option value="0" <?= (int) $s->is_active !== 1 ? 'selected' : ''; ?>>Hidden</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label>What's included</label>
                                            <input type="text" name="includes" class="form-control form-control-sm" maxlength="500" value="<?= htmlspecialchars((string) $s->includes); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Taste tags</label>
                                            <input type="text" name="tags" class="form-control form-control-sm" maxlength="255" value="<?= htmlspecialchars((string) $s->tags); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Description</label>
                                            <textarea name="description" class="form-control form-control-sm" rows="2" maxlength="2000"><?= htmlspecialchars((string) ($s->description ?? '')); ?></textarea>
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
                                        <input type="hidden" name="service_id" value="<?= (int) $s->id; ?>">
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
                                    <form method="post" class="d-inline">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="service_id" value="<?= (int) $s->id; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">
                                            <i class="mdi mdi-<?= (int) $s->is_active === 1 ? 'eye-off' : 'eye'; ?>"></i>
                                            <?= (int) $s->is_active === 1 ? 'Hide' : 'Publish'; ?>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this service? A hidden service can be brought back; a deleted one cannot.');">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="service_id" value="<?= (int) $s->id; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="mdi mdi-delete"></i> Delete</button>
                                    </form>
                                </details>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include_once 'include/footer.php'; ?>
<script>
    // Show how the chosen catalogue service is quoted, so the vendor knows
    // whether the number they type is per guest, per day or a flat fee.
    (function () {
        var pick = document.getElementById('catalogPick');
        var hint = document.getElementById('catalogUnitHint');
        if (!pick || !hint) { return; }
        var labels = <?= json_encode(array_map(static fn (array $u): string => $u[0], VendorService::units())); ?>;
        pick.addEventListener('change', function () {
            var opt = pick.options[pick.selectedIndex];
            var unit = opt ? opt.getAttribute('data-unit') : '';
            hint.textContent = unit && labels[unit]
                ? 'Quoted ' + labels[unit].toLowerCase() + '.'
                : 'Pick a service to see how it is quoted.';
        });
    }());
</script>
</body>
</html>
