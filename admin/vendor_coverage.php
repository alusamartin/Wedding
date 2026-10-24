<?php include 'include/init.php'; ?>
<?php
/**
 * Vendor coverage board — one wedding, its functions against the services it needs.
 *
 * WHY THIS PAGE EXISTS
 * A planner's supplier work is not "which vendors exist" (the marketplace) and
 * not "what did we agree" (Vendor Enquiries); it is "what is still missing for
 * THIS wedding". Those answers were spread over four screens: the enquiry
 * ledger, the venue sheet, the calendar and the deal rows themselves. A planner
 * holding a 300-guest reception with a caterer and no photographer had to
 * remember it. This board draws the matrix: every calendar function as a row,
 * every vendor category as a column, and the deal (or the hole) in each cell.
 *
 * WHICH CELLS COUNT (v2.24)
 * A column is not "required" because it exists. Each function carries its own
 * list of the services it needs, seeded at all ten and then edited here: the
 * reception needs catering, decor and a photographer; a pre-wedding meeting
 * needs none. Only the required cells are judged — booked, pending or missing —
 * and only they feed the counts. A deal in a category the planner marked as not
 * needed is still SHOWN (the platform must never hide money already committed),
 * but muted, and it does not pretend to be cover the wedding asked for.
 *
 * WHAT A CELL MEANS
 *   booked   a deal in that category is confirmed or completed
 *   pending  the only deal in that category is still an enquiry
 *   missing  nothing at all (a cancelled deal is shown, but it does not cover)
 *   —        the planner marked this category as not required for this function
 * A deal whose `features_id` is NULL covers the WHOLE wedding — the same
 * semantic HeadcountBrief and PackageCapacity already use — so it appears in
 * every function row rather than in one: a caterer booked for the day is not
 * "missing" at the reception because they were never scoped to it. A non-NULL
 * scope naming an event that is not on this wedding's calendar is shown the same
 * way, with a warning that it no longer matches a function.
 *
 * A BOOKED CELL IS ONLY COVER IF THE VENDOR IS FREE (v2.25)
 * A confirmed deal does not put a caterer in the room; the caterer does. A
 * vendor confirmed for this wedding and for another one on the same day is a
 * deal that exists and a function that may still have nobody, so a booked cell
 * whose only cover is such a vendor is marked — red chip, red cell, and a list
 * above the board naming the function, the service, the vendor and the wedding
 * they are also committed to. The cell still counts as booked (the deal is real
 * and the planner has to act on it, not re-sell the slot) and a cell covered by
 * two confirmed vendors where only one clashes is not marked, because somebody
 * unencumbered is covering it. The check is the same one the enquiry, confirm
 * and vendor-accept pages make (include/VendorAvailability.php): one query for
 * the board, never one per cell.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *  - It writes only the requirement list, and only on an explicit POST with the
 *    session's CSRF token. Deals, amounts and statuses stay on Vendor Enquiries.
 *  - It notifies nobody. A bell per ticked checkbox is noise; the board is
 *    where a planner looks on purpose.
 *  - Staff only, like the ledger: which supplier was negotiated at what price
 *    is not the couple's information, and a couple reading "no photographer" is
 *    reading a conversation their planner has not framed yet.
 */
    require_staff_subscribed();

    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $booking    = Booking::find_by_booking_id($booking_id);

    /*
     * Resolve the wedding before anything renders. The request-level planner
     * scope in include/init.php already refuses a booking this planner may not
     * see, but this page also states the rule itself: a booking id is not an
     * authorization, and a page that resolved a false row would warn its way
     * through the template.
     */
    if (!$booking || !booking_visible_to_session($booking)) {
        $session->message('<div class="alert alert-warning"><strong><i class="mdi mdi-alert"></i></strong> That booking is not available to you.</div>');
        redirect_to('client.php');
        die();
    }

    // Trust the row, not the parameter: a booking has one id and one owner.
    $booking_id = (int) $booking->booking_id;
    $user_id    = (int) $booking->user_id;
    $links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;
    $self       = 'vendor_coverage.php?' . $links;

    // ---- The wedding's functions (rows) ---------------------------------
    $stmt = $db->prepare('SELECT id, title, start, end FROM events WHERE booking_id = :b ORDER BY start, id');
    $stmt->execute([':b' => $booking_id]);
    $events = $stmt->fetchAll(PDO::FETCH_OBJ);

    // ---- Saving the required-services list (POST + CSRF) ----------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['coverage_needs'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Invalid security token. Please try again.</div>');
            redirect_to($self);
            die();
        }

        // Only this wedding's own functions can be written, whatever was posted:
        // an event id is not an authorization either.
        $own = [0 => true];
        foreach ($events as $event) {
            $own[(int) $event->id] = true;
        }

        $posted_rows = $_POST['rows'] ?? [];
        $posted_rows = is_array($posted_rows) ? $posted_rows : [];
        $posted_need = $_POST['needs'] ?? [];
        $posted_need = is_array($posted_need) ? $posted_need : [];

        $saved   = 0;
        $refused = 0;
        $seen    = [];
        foreach ($posted_rows as $raw_row) {
            $event_id = (int) $raw_row;
            if (!isset($own[$event_id]) || isset($seen[$event_id])) {
                $refused++;
                continue;
            }
            $seen[$event_id] = true;
            $slugs = is_array($posted_need[$event_id] ?? null) ? $posted_need[$event_id] : [];
            if (VendorCoverage::save($booking_id, $event_id, $slugs, (int) $_SESSION['id'])) {
                $saved++;
            }
        }

        if ($saved === 0 && !VendorCoverage::available()) {
            $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> The requirements table is not installed. '
                . 'Run <code>migrations/v2.24_event_vendor_needs.sql</code>, then save again.</div>');
        } else {
            $session->message('<div class="alert alert-success"><i class="mdi mdi-check"></i> Service requirements saved for '
                . $saved . ' function' . ($saved === 1 ? '' : 's') . '. This board now counts only the services you marked.'
                . ($refused > 0 ? ' ' . $refused . ' row' . ($refused === 1 ? ' was' : 's were') . ' ignored — not this wedding\'s functions.' : '')
                . '</div>');
        }

        // A bare redirect: what opens next is the SAVED list, so the page the
        // planner lands on is the proof that it was stored.
        redirect_to($self);
        die();
    }

    $categories = VendorCoverage::categories();
    $editing    = VendorCoverage::available() && (string) ($_GET['edit'] ?? '') === '1';
    $stored     = VendorCoverage::stored($booking_id);

    $event_ids = [];
    foreach ($events as $event) {
        $event_ids[(int) $event->id] = true;
    }

    // ---- Every deal on the wedding, by function and category ------------
    $stmt = $db->prepare(
        'SELECT vb.id, vb.vendor_id, vb.features_id, vb.status, vb.amount, vb.event_date,
                v.business_name, v.category
           FROM tblvendor_bookings vb
           JOIN tblvendors v ON v.id = vb.vendor_id
          WHERE vb.booking_id = :b
          ORDER BY v.business_name, vb.id'
    );
    $stmt->execute([':b' => $booking_id]);
    $deals = $stmt->fetchAll(PDO::FETCH_OBJ);

    /** Cell key for a deal: its function's id, or 'all' for whole-wedding cover. */
    $cell_deals   = [];
    $unmatched    = 0;
    foreach ($deals as $deal) {
        $fid = $deal->features_id !== null ? (int) $deal->features_id : 0;
        if ($fid > 0 && isset($event_ids[$fid])) {
            $key = (string) $fid;
        } else {
            $key = 'all';
            if ($fid > 0) {
                $unmatched++; // scope names a function this wedding no longer has
            }
        }
        // A category outside the enum cannot happen for a live row, but an
        // unknown value must land somewhere visible instead of disappearing.
        $category = isset($categories[(string) $deal->category]) ? (string) $deal->category : 'other';

        $cell_deals[$key][$category][] = $deal;
    }

    /*
     * With no calendar functions there is no matrix to draw — one "whole
     * wedding" row still answers the only question that can be asked. Deals
     * scoped to a function are then shown here too, because the row means
     * "this wedding", not "scope NULL". Its requirement list is event_id 0.
     */
    if ($events === []) {
        $rows = [['id' => 0, 'title' => 'Whole wedding', 'start' => null, 'end' => null]];
    } else {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = ['id' => (int) $event->id, 'title' => (string) $event->title, 'start' => $event->start, 'end' => $event->end];
        }
    }

    /**
     * The deals in one (function, category) cell.
     *
     * A whole-wedding deal appears in every row. On the pseudo-row (id 0) every
     * scoped deal belongs too, because the row means "this wedding", not
     * "unscoped only".
     *
     * @return object[]
     */
    $cell_for = static function (int $row_id, string $slug) use ($cell_deals): array {
        $cell = $cell_deals['all'][$slug] ?? [];
        foreach ($cell_deals as $key => $by_category) {
            if ($key === 'all') {
                continue;
            }
            if ($row_id === 0 || $key === (string) $row_id) {
                $cell = array_merge($cell, $by_category[$slug] ?? []);
            }
        }
        return $cell;
    };

    /*
     * Availability (v2.25): a confirmed cell is only cover if the vendor can
     * actually be there. A vendor confirmed for this wedding AND another one on
     * the same day is a deal that exists and a wedding that may still have no
     * caterer, so the board says so rather than painting the cell green and
     * leaving it to be discovered on the morning. One query for every vendor on
     * the board (VendorAvailability::commitments), then a same-day lookup per
     * deal in memory — never a query per cell.
     */
    $vendor_ids = [];
    foreach ($deals as $deal) {
        $vendor_ids[(int) $deal->vendor_id] = true;
    }
    $availability = VendorAvailability::commitments(array_keys($vendor_ids));

    $event_starts = [];
    foreach ($events as $event) {
        $event_starts[(int) $event->id] = (string) $event->start;
    }

    /** @var array<int, object[]> deal_id => other weddings the vendor is committed to that day */
    $clashes_by_deal = [];
    foreach ($deals as $deal) {
        $fid = $deal->features_id !== null ? (int) $deal->features_id : 0;
        $day = VendorAvailability::day(
            $deal->event_date,
            $event_starts[$fid] ?? null,
            (string) ($booking->wedding_date ?? '')
        );
        $clashes_by_deal[(int) $deal->id] = $day === ''
            ? []
            : VendorAvailability::excluding(
                $availability[(int) $deal->vendor_id][$day] ?? [],
                $booking_id,
                (int) $deal->id
            );
    }

    /** A deal that actually covers a service (an enquiry is not cover). */
    $deal_in_cover = static fn(object $deal): bool => in_array((string) $deal->status, ['confirmed', 'completed'], true);

    /**
     * Is this cell covered ONLY by vendors who are working somewhere else that
     * day? Two confirmed vendors where one clashes is still covered; one
     * confirmed vendor who clashes is the shape that needs a planner.
     *
     * @param object[] $cell deals in the cell
     */
    $cell_is_risk = static function (array $cell) use ($deal_in_cover, $clashes_by_deal): bool {
        $covered = false;
        foreach ($cell as $deal) {
            if (!$deal_in_cover($deal)) {
                continue;
            }
            $covered = true;
            if (($clashes_by_deal[(int) $deal->id] ?? []) === []) {
                return false; // somebody unencumbered is covering it
            }
        }
        return $covered;
    };

    // ---- Required services and how covered they are ---------------------
    $summary         = ['booked' => 0, 'pending' => 0, 'missing' => 0];
    $required_total  = 0;
    $unconfigured    = 0;
    $row_requirements = [];
    /** @var array<int, array{function:string, service:string, vendor:string, deal_id:int, clash:string}> */
    $at_risk = [];
    /** @var array<int, true> function ids with at least one booked cell that is not real cover */
    $risky_rows = [];
    foreach ($rows as $row) {
        $required = VendorCoverage::required($booking_id, $row['id'], $stored);
        $row_requirements[$row['id']] = $required;
        if (VendorCoverage::available() && !VendorCoverage::configured($booking_id, $row['id'], $stored)) {
            $unconfigured++;
        }
        foreach ($required as $slug) {
            $required_total++;
            $cell  = $cell_for($row['id'], $slug);
            $state = VendorCoverage::state($cell);
            $summary[$state]++;

            // A booked required cell that no unencumbered vendor covers: the
            // number above still counts it (a deal exists), and this list says
            // the wedding cannot rely on it yet.
            if ($state === 'booked' && $cell_is_risk($cell)) {
                foreach ($cell as $deal) {
                    if (!$deal_in_cover($deal) || ($clashes_by_deal[(int) $deal->id] ?? []) === []) {
                        continue;
                    }
                    $risky_rows[$row['id']] = true;
                    $at_risk[] = [
                        'function' => (string) $row['title'],
                        'service'  => $categories[$slug][0],
                        'vendor'   => (string) $deal->business_name,
                        'deal_id'  => (int) $deal->id,
                        'clash'    => VendorAvailability::alert($clashes_by_deal[(int) $deal->id]),
                    ];
                    break; // one line per cell is enough to send the planner to the deal
                }
            }
        }
    }

    $total_deals = count($deals);

    /*
     * The headline card is a filter, not a decoration: `risk=1` narrows the
     * board to the functions that actually carry the problem. The summary above
     * stays computed over every row (the numbers describe the wedding, not the
     * view), editing always edits the whole list, and an old link with nothing
     * left to show falls back to the full board with a note instead of an empty
     * table.
     */
    $risk_filter       = !$editing && (string) ($_GET['risk'] ?? '') === '1';
    $risk_filter_empty = false;
    $display_rows      = $rows;
    if ($risk_filter) {
        $display_rows = [];
        foreach ($rows as $row) {
            if (isset($risky_rows[$row['id']])) {
                $display_rows[] = $row;
            }
        }
        if ($display_rows === []) {
            $risk_filter       = false;
            $risk_filter_empty = true;
            $display_rows      = $rows;
        }
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Vendor Coverage - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <style>
        body { margin-bottom: 2%; }
        .stat-card { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
                     box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #D97706; }
        .stat-card h6 { font-size: 11px; text-transform: uppercase; color: #64748B; margin: 0; letter-spacing: .06em; }
        .stat-card .value { font-size: 24px; font-weight: 700; color: #0F172A; }
        .stat-card .note { font-size: 10.5px; color: #64748B; margin-top: -2px; }
        .stat-card.is-risk { border-left-color: var(--danger); }
        .stat-card.is-risk .value,
        .stat-card.is-risk .note { color: #B91C1C; }
        .stat-card.is-link { display: block; color: inherit; text-decoration: none;
                             transition: transform .15s, box-shadow .15s; }
        .stat-card.is-link:hover, .stat-card.is-link:focus {
            color: inherit; text-decoration: none; transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(220,38,38,0.18);
        }
        .stat-card.is-link:focus { outline: 2px solid #DC2626; outline-offset: 2px; }

        .cv-board { border-collapse: separate; border-spacing: 0; font-size: 12px; }
        .cv-board th, .cv-board td { border-color: #E2E8F0 !important; vertical-align: top; }
        .cv-board thead th {
            font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748B;
            font-weight: 600; white-space: nowrap; padding: 8px 6px; background: #F8FAFC;
            position: sticky; top: 0; z-index: 2;
        }
        .cv-board thead th i { display: block; font-size: 15px; color: #94A3B8; margin-bottom: 2px; }
        .cv-board tbody th {
            position: sticky; left: 0; z-index: 3; background: #fff;
            min-width: 168px; max-width: 200px; padding: 10px 12px; border-right: 1px solid #E2E8F0;
        }
        .cv-fn { font-size: 12.5px; font-weight: 700; color: #0F172A; line-height: 1.3; }
        .cv-when { font-size: 10.5px; color: #64748B; margin-top: 3px; white-space: nowrap; }
        .cv-cell { min-width: 96px; padding: 7px 6px !important; }
        .cv-cell--booked { background: #ECFDF5; }
        .cv-cell--pending { background: #FFFBEB; }
        .cv-cell--missing { background: #fff; }
        .cv-cell--extra { background: #F8FAFC; }
        /* A cell whose only cover is a vendor working another wedding that day. */
        .cv-cell--risk { background: #FEF2F2 !important; box-shadow: inset 3px 0 0 #DC2626; }
        .cv-chip {
            display: block; border-radius: 8px; padding: 3px 6px; margin-bottom: 3px;
            font-size: 10.5px; line-height: 1.3; border: 1px solid transparent;
            max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .cv-chip:hover { text-decoration: none; }
        .cv-chip i { font-size: 10px; }
        .cv-chip.is-booked   { background: #D1FAE5; border-color: #A7F3D0; color: #065F46; }
        .cv-chip.is-done     { background: #DBEAFE; border-color: #BFDBFE; color: #1E40AF; }
        .cv-chip.is-pending  { background: #FEF3C7; border-color: #FDE68A; color: #92400E; }
        .cv-chip.is-cancelled{ background: #F1F5F9; border-color: #E2E8F0; color: #94A3B8; text-decoration: line-through; }
        .cv-chip.is-extra    { background: #F1F5F9; border-color: #E2E8F0; color: #64748B; }
        .cv-chip.is-clash    { border-color: #DC2626; box-shadow: inset 0 0 0 1px #FCA5A5; }
        .cv-chip.is-clash .mdi-alert { color: #B91C1C; margin-left: 2px; }
        .cv-find { font-size: 10px; color: #64748B; border: 1px dashed #CBD5E1; border-radius: 8px;
                   padding: 2px 7px; display: inline-block; }
        .cv-find:hover { color: #065F46; border-color: #065F46; text-decoration: none; background: #F0FDF4; }
        .cv-none { color: #CBD5E1; font-size: 11px; }
        .cv-need { display: block; margin: 0 0 4px; cursor: pointer; }
        .cv-need input { vertical-align: middle; margin-right: 3px; }
        .cv-legend { font-size: 11px; color: #64748B; }
        .cv-legend .cv-key { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
        @media (max-width: 767.98px) {
            .cv-board tbody th { position: static; }
        }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-1"><i class="mdi mdi-view-grid-plus" style="color:#065F46"></i> Vendor Coverage</h4>
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?php
                $who  = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''), ' &');
                $when = !empty($booking->wedding_date) ? format_date_kd((string) $booking->wedding_date) : 'date to be confirmed';
                ?>
                <strong><?= htmlspecialchars($who !== '' ? $who : 'This wedding'); ?></strong>
                · <?= htmlspecialchars($when); ?>
                — each function against the services it needs
            </p>
        </div>
        <div>
            <a href="vendor_enquiries.php" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-handshake-outline mr-1"></i> Vendor enquiries</a>
            <a href="task_calendar.php?<?= $links; ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-calendar-text mr-1"></i> Calendar</a>
            <a href="sheet_venue.php?<?= $links; ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-file-document-outline mr-1"></i> Venue sheet</a>
            <?php if ($editing): ?>
                <a href="<?= $self; ?>" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-close mr-1"></i> Cancel</a>
            <?php elseif (VendorCoverage::available()): ?>
                <a href="<?= $self; ?>&amp;edit=1" class="btn btn-sm btn-outline-secondary"><i class="mdi mdi-tune-variant mr-1"></i> Edit services</a>
            <?php endif; ?>
            <a href="vendors.php?booking_id=<?= $booking_id; ?>" class="btn btn-sm btn-success"><i class="mdi mdi-store mr-1"></i> Find a vendor</a>
        </div>
    </div>

    <?php if ($session->message()): ?>
        <div class="alert alert-dismissible fade show"><?= $session->message(); ?><button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    <?php endif; ?>

    <?php if (!VendorCoverage::available()): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <strong><i class="mdi mdi-database-alert"></i> The requirements table is not installed.</strong>
            Until <code>migrations/v2.24_event_vendor_needs.sql</code> is applied, every function counts all
            ten services as required and the list cannot be edited.
        </div>
    <?php endif; ?>

    <?php
    /*
     * The headline counts and the board must agree. "Booked" still counts a
     * double-booked cell (the deal exists), so the at-risk card sits beside it
     * rather than lowering it — a planner reading only the top of the page sees
     * how many of those booked services cannot actually be relied on.
     */
    $at_risk_count = count($at_risk);
    ?>
    <div class="row">
        <div class="col-6 col-md"><div class="stat-card" style="border-left-color:var(--success)"><h6>Required services booked</h6><div class="value"><?= $summary['booked']; ?></div></div></div>
        <div class="col-6 col-md"><div class="stat-card"><h6>Awaiting a vendor</h6><div class="value"><?= $summary['pending']; ?></div></div></div>
        <div class="col-6 col-md"><div class="stat-card" style="border-left-color:var(--stone-300)"><h6>Missing</h6><div class="value"><?= $summary['missing']; ?></div></div></div>
        <div class="col-6 col-md">
            <?php if ($at_risk_count > 0 && !$editing): ?>
                <!-- The count is the way in: it opens the same page filtered to
                     just these functions, landing on the warning that names them. -->
                <a class="stat-card is-risk is-link"
                   href="<?= $self; ?>&amp;risk=1#coverage-risk"
                   style="border-left-color:var(--danger);"
                   aria-label="Show only the <?= $at_risk_count; ?> function<?= $at_risk_count === 1 ? '' : 's'; ?> whose booked cover is double-booked">
                    <h6>Double-booked cover</h6>
                    <div class="value"><?= $at_risk_count; ?></div>
                    <div class="note">of <?= $summary['booked']; ?> booked &mdash; show only these</div>
                </a>
            <?php else: ?>
                <div class="stat-card <?= $at_risk_count > 0 ? 'is-risk' : ''; ?>" style="border-left-color:<?= $at_risk_count > 0 ? '#DC2626' : '#CBD5E1'; ?>">
                    <h6>Double-booked cover</h6>
                    <div class="value"><?= $at_risk_count; ?></div>
                    <?php if ($at_risk_count > 0): ?>
                        <div class="note">of <?= $summary['booked']; ?> booked &mdash; fix the deals below</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-6 col-md"><div class="stat-card" style="border-left-color:var(--info)"><h6>Deals on this wedding</h6><div class="value"><?= $total_deals; ?></div></div></div>
    </div>

    <p class="text-muted" style="font-size:12.5px; margin-top:-6px;">
        <?php if ($required_total === 0): ?>
            <strong>No service is marked required yet.</strong> Open <strong>Edit services</strong> and tick what this wedding needs.
        <?php else: ?>
            <strong><?= $summary['booked']; ?> of <?= $required_total; ?></strong> required services booked
            <?php if ($summary['pending'] > 0): ?>· <?= $summary['pending']; ?> awaiting a vendor's answer<?php endif; ?>
            <?php if ($summary['missing'] > 0): ?>· <?= $summary['missing']; ?> with no vendor<?php endif; ?>
            <?php if ($at_risk !== []): ?>
                · <span class="text-danger"><strong><?= count($at_risk); ?></strong>
                booked on a vendor who is also booked elsewhere that day</span>
            <?php endif; ?>
        <?php endif; ?>
    </p>

    <?php if ($at_risk !== []): ?>
        <!--
            v2.25 — the cells below still read "booked" because a deal exists,
            and that deal is exactly what the planner has to act on: move it,
            re-scope it, or get the vendor to confirm a second team. This alert
            is the shortest path to those rows.
        -->
        <div class="alert alert-danger" id="coverage-risk" style="font-size:13px;">
            <strong><i class="mdi mdi-calendar-remove"></i> <?= count($at_risk); ?> booked service<?= count($at_risk) === 1 ? ' rests' : 's rest'; ?>
            on a vendor who is also committed to another wedding that day.</strong>
            <ul class="mb-0 mt-1" style="padding-left:18px;">
                <?php foreach ($at_risk as $risk): ?>
                    <li>
                        <strong><?= htmlspecialchars($risk['function']); ?></strong> · <?= htmlspecialchars($risk['service']); ?>:
                        <?= htmlspecialchars($risk['vendor']); ?> — <?= htmlspecialchars($risk['clash']); ?>
                        <a href="vendor_enquiries.php?deal=<?= $risk['deal_id']; ?>#deal-<?= $risk['deal_id']; ?>">Review the deal</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <span style="font-size:12px;">A confirmed deal is still a deal — but if the vendor cannot cover both weddings, this function has no supplier on the day.</span>
        </div>
    <?php endif; ?>

    <?php if ($risk_filter): ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap" style="font-size:12.5px;">
            <span class="mr-2">
                <i class="mdi mdi-filter-variant"></i>
                Showing <strong><?= count($display_rows); ?></strong> of <?= count($rows); ?> function<?= count($rows) === 1 ? '' : 's'; ?>
                — only those whose booked cover rests on a vendor also committed elsewhere that day.
                The counts above still describe the whole wedding.
            </span>
            <a href="<?= $self; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="mdi mdi-close mr-1"></i> Show all functions
            </a>
        </div>
    <?php elseif ($risk_filter_empty): ?>
        <div class="alert alert-info" style="font-size:12.5px;">
            <i class="mdi mdi-check-circle-outline"></i>
            Nothing is double-booked on this wedding any more, so the board shows every function again.
        </div>
    <?php endif; ?>


    <?php if ($events === []): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <strong><i class="mdi mdi-calendar-alert"></i> No functions on this wedding's calendar yet.</strong>
            Coverage is tracked per function, so add Ruracio, church, reception and the rest on the
            <a href="task_calendar.php?<?= $links; ?>">Task Calendar</a> — until then the board judges the wedding as a whole.
        </div>
    <?php endif; ?>

    <?php if ($unmatched > 0): ?>
        <div class="alert alert-warning" style="font-size:13px;">
            <strong><i class="mdi mdi-link-variant-off"></i> <?= $unmatched; ?> deal<?= $unmatched === 1 ? '' : 's'; ?></strong>
            name<?= $unmatched === 1 ? 's' : ''; ?> a function that is not on this wedding's calendar any more, so
            <?= $unmatched === 1 ? 'it is' : 'they are'; ?> counted against the whole wedding. Re-scope the deal on
            <a href="vendor_enquiries.php">Vendor Enquiries</a> if that is wrong.
        </div>
    <?php endif; ?>

    <?php if ($total_deals === 0): ?>
        <div class="alert alert-info" style="font-size:13px;">
            <strong><i class="mdi mdi-information-outline"></i> No vendor is booked on this wedding yet.</strong>
            Every required cell below is a hole — start on the <a href="vendors.php?booking_id=<?= $booking_id; ?>">vendor marketplace</a>.
        </div>
    <?php endif; ?>

    <?php if ($editing): ?>
    <form method="post" action="<?= $self; ?>">
        <?= $session->csrf_field(); ?>
        <input type="hidden" name="coverage_needs" value="1">
    <?php endif; ?>

    <div class="card shadow-sm" style="border:none; border-radius:14px;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                <h6 class="mb-1" style="font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:#64748B;">
                    <?= $editing ? 'Tick the services each function needs' : 'Functions &times; required services'; ?>
                </h6>
                <div class="cv-legend">
                    <span class="mr-3"><span class="cv-key" style="background:#ECFDF5; border:1px solid #A7F3D0;"></span>Booked</span>
                    <span class="mr-3"><span class="cv-key" style="background:#FFFBEB; border:1px solid #FDE68A;"></span>Pending</span>
                    <span class="mr-3"><span class="cv-key" style="background:#fff; border:1px dashed #CBD5E1;"></span>Missing</span>
                    <span class="mr-3"><span class="cv-key" style="background:#F8FAFC; border:1px solid #E2E8F0;"></span>Not required</span>
                    <span class="mr-3 text-danger"><i class="mdi mdi-alert"></i> vendor double-booked</span>
                    <span><i class="mdi mdi-information-outline"></i> a whole-wedding deal shows in every row</span>
                </div>
            </div>

            <div class="table-responsive" style="max-height:70vh;">
                <table class="table table-sm table-bordered cv-board mb-0">
                    <thead>
                        <tr>
                            <th style="min-width:180px;">Function</th>
                            <?php foreach ($categories as $slug => [$label, $icon]): ?>
                                <th>
                                    <i class="mdi mdi-<?= $icon; ?>"></i><?= htmlspecialchars($label); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($display_rows as $row): ?>
                        <?php $required = $row_requirements[$row['id']]; ?>
                        <tr>
                            <th scope="row">
                                <?php if ($editing): ?>
                                    <input type="hidden" name="rows[]" value="<?= (int) $row['id']; ?>">
                                <?php endif; ?>
                                <div class="cv-fn"><?= htmlspecialchars($row['title']); ?></div>
                                <?php if (!empty($row['start'])): ?>
                                    <div class="cv-when">
                                        <?= htmlspecialchars(format_date_kd((string) $row['start'])); ?>
                                        <?php if (!empty($row['end'])): ?>
                                            · <?= htmlspecialchars(date('g:i A', strtotime((string) $row['start']))); ?>–<?= htmlspecialchars(date('g:i A', strtotime((string) $row['end']))); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($events === []): ?>
                                    <div class="cv-when">no functions on the calendar</div>
                                <?php endif; ?>
                                <?php if ($required === []): ?>
                                    <div class="cv-when">no vendor services needed</div>
                                <?php endif; ?>
                            </th>
                            <?php
                            foreach ($categories as $slug => [$label, $icon]):
                                $is_required = in_array($slug, $required, true);
                                $cell        = $cell_for($row['id'], $slug);
                                $state       = VendorCoverage::state($cell);
                                // Booked, but every vendor covering it is working
                                // elsewhere that day: visibly not real cover.
                                $risk        = $is_required && $state === 'booked' && $cell_is_risk($cell);
                            ?>
                                <td class="cv-cell cv-cell--<?= $is_required ? $state : 'extra'; ?><?= $risk ? ' cv-cell--risk' : ''; ?>">
                                    <?php if ($editing): ?>
                                        <label class="cv-need" title="<?= $is_required ? 'Required for this function' : 'Not required for this function'; ?>">
                                            <input type="checkbox"
                                                   name="needs[<?= (int) $row['id']; ?>][]"
                                                   value="<?= htmlspecialchars($slug, ENT_QUOTES); ?>"
                                                   <?= $is_required ? 'checked' : ''; ?>>
                                            <span class="sr-only"><?= htmlspecialchars($label); ?> required for <?= htmlspecialchars($row['title']); ?></span>
                                        </label>
                                    <?php endif; ?>
                                    <?php foreach ($cell as $deal): ?>
                                        <?php
                                        $chip = match ((string) $deal->status) {
                                            'confirmed' => ['is-booked', 'check-decagram', 'confirmed'],
                                            'completed' => ['is-done', 'certificate', 'completed'],
                                            'pending'   => ['is-pending', 'clock-outline', 'pending enquiry'],
                                            default     => ['is-cancelled', 'close-circle', 'cancelled'],
                                        };
                                        $chip_class = $is_required ? $chip[0] : ($chip[0] === 'is-cancelled' ? $chip[0] : 'is-extra');
                                        $chip_title = $deal->business_name . ' — ' . $chip[2] . ($is_required ? '' : ' (not required for this function)');

                                        /*
                                         * A covering deal whose vendor is committed
                                         * elsewhere that day. Only cover deals are
                                         * flagged: a pending enquiry is not booked,
                                         * so there is nothing to double-book yet.
                                         */
                                        $deal_clash = ($is_required && $deal_in_cover($deal))
                                            ? ($clashes_by_deal[(int) $deal->id] ?? [])
                                            : [];
                                        if ($deal_clash !== []) {
                                            $chip_class .= ' is-clash';
                                            $chip_title .= ' — DOUBLE BOOKED: ' . VendorAvailability::alert($deal_clash);
                                        }
                                        ?>
                                        <?php if ($editing): ?>
                                            <span class="cv-chip <?= $chip_class; ?>" title="<?= htmlspecialchars($chip_title); ?>">
                                                <i class="mdi mdi-<?= $chip[1]; ?>"></i>
                                                <?= htmlspecialchars((string) $deal->business_name); ?>
                                                <?php if ($deal_clash !== []): ?><i class="mdi mdi-alert" aria-hidden="true"></i><?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <a class="cv-chip <?= $chip_class; ?>"
                                               href="vendor_enquiries.php?deal=<?= (int) $deal->id; ?>#deal-<?= (int) $deal->id; ?>"
                                               title="<?= htmlspecialchars($chip_title); ?>">
                                                <i class="mdi mdi-<?= $chip[1]; ?>"></i>
                                                <?= htmlspecialchars((string) $deal->business_name); ?>
                                                <?php if ($deal_clash !== []): ?><i class="mdi mdi-alert" aria-hidden="true"></i><?php endif; ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($is_required && $state === 'missing' && !$editing): ?>
                                        <a class="cv-find"
                                           href="vendors.php?category=<?= urlencode($slug); ?>&amp;booking_id=<?= $booking_id; ?>"
                                           title="Find a <?= htmlspecialchars(strtolower($label)); ?> vendor for this wedding">
                                            <i class="mdi mdi-plus"></i> Find
                                        </a>
                                    <?php elseif (!$is_required && $cell === []): ?>
                                        <span class="cv-none" title="Not required for this function">—</span>
                                    <?php endif; ?>
                                    <span class="sr-only"><?= $is_required ? htmlspecialchars(VendorCoverage::state_label($state)) : 'Not required'; ?></span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($editing): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap mt-3">
                    <p class="text-muted mb-0" style="font-size:11.5px;">
                        <i class="mdi mdi-information-outline"></i>
                        Tick every service this function actually needs, then save. A function with nothing ticked
                        needs no vendor. Changing a requirement never changes a deal.
                    </p>
                    <div>
                        <a href="<?= $self; ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-sm btn-success"><i class="mdi mdi-content-save mr-1"></i> Save requirements</button>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-muted mt-3 mb-0" style="font-size:11.5px;">
                    <i class="mdi mdi-information-outline"></i>
                    A required cell is <strong>booked</strong> when a deal is confirmed or completed, <strong>pending</strong> while
                    the only deal is an enquiry, and <strong>missing</strong> when nothing is recorded. A cancelled deal stays
                    visible but does not cover the service. A deal in a service this function does not need is shown muted.
                    A cell marked <span class="text-danger"><i class="mdi mdi-alert"></i> double-booked</span> has a confirmed
                    deal whose vendor is also committed to another wedding that day — a deal exists, but the wedding may
                    still have no supplier on the day.
                    <?php if ($unconfigured > 0): ?>
                        <?= $unconfigured; ?> function<?= $unconfigured === 1 ? ' has' : 's have'; ?> no saved list yet and
                        <?= $unconfigured === 1 ? 'starts' : 'start'; ?> from all ten — <a href="<?= $self; ?>&amp;edit=1">tick what each one needs</a>.
                    <?php endif; ?>
                    Deals and their amounts are managed on <a href="vendor_enquiries.php">Vendor Enquiries</a>.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($editing): ?>
    </form>
    <?php endif; ?>
</div>

<?php include 'include/footer.php'; ?>
</body>
</html>
