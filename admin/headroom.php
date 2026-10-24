<?php include 'include/init.php'; ?>
<?php
/**
 * Capacity headroom — which weddings are CLOSE to what a booked package holds.
 *
 * WHY THIS PAGE EXISTS
 * Every other capacity surface in this application speaks after the fact. The
 * venue sheet prints the position beside the numbers, and the notify path warns
 * the caterer and the planner when a plan has ALREADY outgrown a package or a
 * deal is recorded on one that cannot hold the crowd (include/PackageCapacity.php
 * owns both). By then the caterer has been briefed and the deal is made: what is
 * left is a phone call under pressure. The useful moment is earlier, and it is
 * quiet rather than urgent — a reception planned for 240 against a 250-pax
 * package is not a breach, it may never become one, and a planner who sees it a
 * month out still has every option open.
 *
 * WHAT IS LISTED
 * PackageCapacity::watch() answers one question per wedding — the functions
 * planned within a share (a tenth by default) of the guest capacity of a package
 * booked on them — over the same limits, scope rules and plan the sheet and the
 * warnings use, so "close" and "over" can never disagree. A wedding appears when
 * it has a close position OR a position already over a limit, with the two
 * counted separately: a list that silently dropped the over ones would read as
 * "nothing to do here" for the wedding that needs the call most.
 *
 * WHO SEES IT, AND WHAT IT DOES NOT DO
 * Staff only, the same audience as the vendor ledger and for the same reason:
 * which deals were sold is not a couple's information, and a couple reading
 * "your caterer's package is nearly full" would be reading a negotiation their
 * planner has not framed yet. It notifies nobody. A bell per wobble of a guest
 * count is noise, and both announced events (the breach, the recording door) are
 * facts rather than forecasts; this page is where a planner looks on purpose.
 *
 * THE WIDTH IS REMEMBERED (v2.12). How wide to look is a judgement about the
 * planner's own patch — one planner wants the day's problems, another wants a
 * month of lead time — and it does not change between visits, so re-picking it
 * every time is the friction that stops a page being opened. The width an
 * account chose is stored per ACCOUNT (include/UserPrefs.php → tbluser_prefs,
 * migrations/v2.12_user_preferences.sql) and the page opens there. The saved
 * value is a hint, not a policy: it is validated against the widths the feature
 * actually offers on the way back out, and an uninstalled table simply means the
 * page cannot remember (it says so) rather than failing.
 *
 * A GET NEVER WRITES. `?within=N` widens or narrows THIS view — a hand-edited
 * URL should change what is on screen, never what is stored — and changing the
 * default is an explicit POST (the Remember/Forget control below the chips),
 * with the same CSRF token every other write in this application carries.
 */
    require_staff_subscribed();

    $user_id = (int) ($_SESSION['id'] ?? 0);

    // ---- Remembering the width (POST + CSRF) -----------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['headroom_default'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Invalid security token. Please try again.</div>');
            redirect_to('headroom.php');
            die();
        }

        $action = (string)($_POST['headroom_default'] ?? '');
        $ok     = $action === 'forget'
            ? UserPrefs::forget($user_id, UserPrefs::HEADROOM_WIDTH)
            : UserPrefs::remember_headroom($user_id, (int) ($_POST['within'] ?? 0));

        $session->message($ok
            ? '<div class="alert alert-success"><i class="mdi mdi-check"></i> ' . ($action === 'forget'
                ? 'Saved width forgotten — this page opens at the default again.'
                : 'Saved — this page will open at ' . (int) ($_POST['within'] ?? 0) . '% for your account.') . '</div>'
            : '<div class="alert alert-danger"><i class="mdi mdi-alert"></i> That width could not be saved. '
                . (UserPrefs::available()
                    ? 'Pick one of the widths offered above.'
                    : 'This database has not run migrations/v2.12_user_preferences.sql yet.') . '</div>');

        // A bare redirect, deliberately: what opens next is the SAVED width, so
        // the page the planner lands on is the proof that it was stored.
        redirect_to('headroom.php');
        die();
    }

    /*
     * Which width to watch at, in one order and three steps:
     *   1. `?within=N` — what THIS view asks for, when N is one of the widths
     *      offered. Anything else is not an error: a hand-edited URL that asks
     *      for a width this page does not have simply opens where the page would
     *      have opened anyway (the account's saved width, else the default),
     *      because a filter must never 500 on a typo — and nothing here is ever
     *      written (see above).
     *   2. the account's saved width, when they have chosen one (v2.12).
     *   3. the feature's own default (PackageCapacity::default_share()).
     */
    $asked   = isset($_GET['within']) ? (int)($_GET['within'] ?? 0) : 0;
    $saved   = UserPrefs::headroom_percent($user_id); // null = never chose one
    $default = (int) round(PackageCapacity::default_share() * 100);

    /*
     * EVERY branch is narrowed against the widths that exist, including the
     * stored one — which is already validated by the reader. That is deliberate
     * belt-and-braces: this line decides which key the page reads, so it has to
     * be total for ANY input, a stored row an older or newer build wrote
     * included, rather than trusting that the two ends agree forever.
     */
    $wanted  = array_key_exists($asked, PackageCapacity::shares())
        ? $asked
        : (array_key_exists((int) $saved, PackageCapacity::shares()) ? (int) $saved : $default);
    $share   = PackageCapacity::shares()[$wanted];
    $percent = $wanted;

    $watch = PackageCapacity::watch($share);
    $over  = PackageCapacity::watch_over_count(); // whole-database, not this share: a breach is a breach at any width

    $weddings  = count($watch);
    $positions = 0;
    foreach ($watch as $row) {
        $positions += (int) $row['count'];
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); // what include/sidebar.php chips the reader with ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Capacity Headroom - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #D97706; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .filter-chip { border-radius: 999px; font-size: 12px; padding: 4px 14px; margin: 0 5px 6px 0; }
        .filter-chip.active { background: #D97706; border-color: #D97706; color: #fff; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-gauge" style="color:#D97706"></i> Capacity Headroom</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">weddings running close to the guest capacity of a package a vendor is booked on — before it becomes a problem</p>
        </div>
        <a href="vendor_enquiries.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-handshake-outline mr-1"></i> Vendor enquiries</a>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4"><div class="stat-card"><h6>Weddings on watch</h6><div class="value"><?= $weddings; ?></div></div></div>
        <div class="col-md-4"><div class="stat-card" style="border-left-color:var(--warning)"><h6>Positions inside <?= $percent; ?>%</h6><div class="value"><?= $positions; ?></div></div></div>
        <div class="col-md-4"><div class="stat-card" style="border-left-color:var(--danger)"><h6>Already over a limit</h6><div class="value"><?= $over; ?></div></div></div>
    </div>

    <div class="mb-3">
        <?php foreach (PackageCapacity::shares() as $option => $fraction): ?>
            <?php /* Every chip carries its width, including the default one: with a
                     saved preference, a bare link would open at THAT width, so the 10%
                     chip would quietly stop meaning ten per cent. */ ?>
            <a href="headroom.php?within=<?= (int) $option; ?>"
               class="btn btn-sm btn-outline-secondary filter-chip <?= $option === $percent ? 'active' : ''; ?>">
                Within <?= (int) $option; ?>%
            </a>
        <?php endforeach; ?>

        <?php
        /*
         * The page's only write, and the reason it is a POST: widening a view
         * for a minute and changing what the page opens at are two different
         * acts. The button states the width it would store, so nobody has to
         * work out what the active chip is.
         */
        ?>
        <form method="post" action="headroom.php" class="d-inline-block align-middle ml-1">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="within" value="<?= (int) $percent; ?>">
            <?php if (!UserPrefs::available()): ?>
                <span class="text-muted" style="font-size:12px;">
                    <i class="mdi mdi-information-outline"></i>
                    This page cannot remember a width yet — run
                    <code>migrations/v2.12_user_preferences.sql</code>.
                </span>
            <?php elseif ($saved === $percent): ?>
                <span class="text-muted mr-2" style="font-size:12px;">
                    <i class="mdi mdi-pin"></i> Opening at <?= (int) $percent; ?>% — your saved default.
                </span>
                <button type="submit" name="headroom_default" value="forget" class="btn btn-sm btn-outline-secondary">
                    <i class="mdi mdi-pin-off mr-1"></i> Forget
                </button>
            <?php else: ?>
                <?php if ($saved !== null): ?>
                    <span class="text-muted mr-2" style="font-size:12px;">Your default is <?= (int) $saved; ?>%.</span>
                <?php endif; ?>
                <button type="submit" name="headroom_default" value="remember" class="btn btn-sm btn-outline-secondary">
                    <i class="mdi mdi-pin-outline mr-1"></i> Remember <?= (int) $percent; ?>% as my default
                </button>
            <?php endif; ?>
        </form>
    </div>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <?php if ($over > 0): ?>
            <!--
                Said first, and separately: this list is about drift, and a reader who
                took an empty one as "nothing to do" would be wrong about a wedding
                that is already over a booked limit. Those breaches were announced
                when they were created and are printed on their venue sheets.
            -->
            <p class="mb-3" style="font-size:13px; color:#B91C1C;">
                <i class="mdi mdi-alert-outline"></i>
                <strong><?= (int) $over; ?> wedding<?= $over === 1 ? ' is' : 's are'; ?> already over a booked package's limit.</strong>
                That is not drift — it was announced to the vendor and the planner when it happened, and the venue sheet prints it.
            </p>
            <?php endif; ?>

            <div class="table-responsive">
            <table class="table table-hover align-middle" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Wedding</th>
                        <th>Date</th>
                        <th style="min-width: 45%;">Closest position</th>
                        <th>On watch</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($watch === []): ?>
                    <?php empty_state_row(5, 'Nothing is running close to a limit',
                        'Every function of every wedding with a booked package is planned comfortably inside its guest capacity — at this width (' . $percent . '%). Widen the window above to look further ahead.',
                        'mdi-gauge'); ?>
                <?php else: ?>
                    <?php foreach ($watch as $row): ?>
                    <?php $sheet = 'sheet_venue.php?booking_id=' . (int) $row['booking_id'] . '&user_id=' . (int) $row['couple_user_id']; ?>
                    <tr>
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars($row['couple'] !== '' ? $row['couple'] : 'Booking #' . (int) $row['booking_id']); ?></strong>
                            <div style="font-size:11px; color:#64748B;">
                                <a href="<?= htmlspecialchars($sheet); ?>">booking #<?= (int) $row['booking_id']; ?></a>
                            </div>
                        </td>
                        <td style="font-size:12.5px;">
                            <?= $row['wedding_date'] !== '' ? htmlspecialchars(format_date_kd($row['wedding_date'])) : '<span class="text-muted">—</span>'; ?>
                        </td>
                        <td style="font-size:12.5px;">
                            <?php if ($row['tightest'] !== null): ?>
                                <span style="color:#B45309;"><i class="mdi mdi-gauge"></i>
                                    <?= htmlspecialchars(PackageCapacity::headroom_sentence($row['tightest'])); ?></span>
                            <?php endif; ?>
                            <?php
                            /*
                             * The rest of this wedding's close positions, each stated the
                             * same way — a planner reading one line needs to know whether
                             * the other four functions are also leaning on their packages.
                             */
                            $others = array_slice($row['positions'], 1);
                            if ($others !== []):
                            ?>
                                <div class="text-muted" style="font-size:11.5px;">
                                    <?php foreach ($others as $i => $position): ?>
                                        <?= $i > 0 ? '<br>' : ''; ?><?= htmlspecialchars(PackageCapacity::headroom_sentence($position)); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px;">
                            <span class="badge badge-warning"><?= (int) $row['count']; ?> close</span>
                            <?php if ((int) $row['over'] > 0): ?>
                                <div class="mt-1"><span class="badge badge-danger"><?= (int) $row['over']; ?> over</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <a href="<?= htmlspecialchars($sheet); ?>" class="btn btn-sm btn-outline-secondary"
                               title="The venue sheet: the numbers, and who is briefed on them"><i class="mdi mdi-clipboard-text-outline"></i> Sheet</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <p class="text-muted mt-3" style="font-size:12px;">
        Counted when a function is planned within <?= $percent; ?>% of the guest capacity stated on a package a vendor is
        confirmed on for it — on a 250-pax package, <?= (int) ceil(250 * $share); ?> guests and up. A function planned
        <em>over</em> that capacity is a breach rather than drift: it is announced to the vendor holding the package and to the
        planner, and printed on the venue sheet. A package with no capacity stated, a deal with no package chosen, and an
        unplanned function all state no limit to run out of and are never listed here.
        The width is remembered per account — <em>Remember</em> below the chips makes it
        the one this page opens at, <em>Forget</em> drops it, and a <code>?within=</code>
        in the address bar changes this view alone without touching either.
    </p>
</div>

<?php include 'include/footer.php'; ?>
</body>
</html>
