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
    /*
     * An account can run more than one business, and get_by_user() returns only
     * one of them (LIMIT 1, no ORDER BY). Resolve the listing being VIEWED —
     * honoured only when this account really owns it — and keep the full list,
     * because the ledger below spans every listing the account owns: an enquiry
     * sent to a vendor's second business is still an enquiry sent to that
     * vendor, and the couple who sent it was told "They will contact you
     * shortly". Scoping the ledger to a single arbitrary listing hid those
     * enquiries from their own vendor for good.
     */
    $myListings = Vendor::all_for_user($user_id);
    $vendor   = current_vendor_listing($user_id, $myListings);
    if (!vendor_listing_visible_to_session($vendor)) {
        // No profile yet, or a listing this session does not own — register.
        redirect_to("vendor_register.php");
        die();
    }

    // Ownership scope for every read AND write on this page: the ids of every
    // listing this account owns, as bind parameters. bound_id_list() is the one
    // place an id list becomes SQL.
    $ownedScope  = bound_id_list(
        $myListings !== []
            ? array_map(static fn($v) => (int) $v->id, $myListings)
            : [(int) $vendor->id]
    );
    $ownedIds    = $ownedScope['ids'];
    $ownedParams = $ownedScope['params'];
    $ownedIn     = $ownedScope['sql'];

    $links = 'vendor_id=' . (int) $vendor->id;
    $status_filter = clean($_GET['status'] ?? '');
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($status_filter, $valid_statuses, true)) {
        $status_filter = '';
    }

    /*
     * The unread queue (v2.25) — the vendor's answer to "who is waiting on me?".
     * "Unread" is a message from the couple or their planner that this vendor
     * has not opened yet, so the filter lists exactly the conversations that
     * still need a reply. Only offered when the migration is installed.
     *
     * ?thread=N is read here too: the opener returns with it, and a thread
     * opened from this filtered list has just been marked read — its row must
     * stay visible so the panel still renders.
     */
    $unread_filter  = VendorThread::installed() && (int) ($_GET['unread'] ?? 0) === 1;
    $thread_request = (int) ($_GET['thread'] ?? 0);

    /*
     * One query string for "the view this vendor is working in". vendor_id
     * stays in it (the page lists every listing the account owns, and the
     * switcher remembers which one is being read), so filtering never jumps
     * the vendor to another business.
     */
    $filter_params = [];
    if ($status_filter !== '') {
        $filter_params['status'] = $status_filter;
    }
    if ($unread_filter) {
        $filter_params['unread'] = 1;
    }
    $filter_params['vendor_id'] = (int) $vendor->id;
    $filter_qs = '?' . http_build_query($filter_params);

    // ---- Vendor status transitions (POST + CSRF + ownership re-check) -----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_row_id'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token.</div>');
            redirect_to("vendor_home.php");
            die();
        }
        $id = (int)($_POST['booking_row_id'] ?? 0);
        $to = clean($_POST['to'] ?? '');
        if (!in_array($to, $valid_statuses, true)) {
            $session->message('<div class="alert alert-danger">Unknown status.</div>');
            redirect_to("vendor_home.php");
            die();
        }
        // Ownership re-check: the row MUST belong to THIS vendor.
        $stmt = $db->prepare(
            "SELECT vb.*, v.business_name, b.user_id AS couple_user_id, b.bride, b.groom, b.wedding_date
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
               JOIN tblweddingbook b ON b.booking_id = vb.booking_id
              WHERE vb.id = :id AND vb.vendor_id IN ({$ownedIn}) LIMIT 1"
        );
        $stmt->execute($ownedParams + [':id' => $id]);
        // PDO::fetch() returns FALSE when no row matches — coerce to null so
        // the ownership check below actually fires (a bare === null test
        // silently passes for foreign/phantom ids and reports fake success).
        $row = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
        if ($row === null) {
            $session->message('<div class="alert alert-danger">That booking is not yours.</div>');
            redirect_to("vendor_home.php");
            die();
        }
        /*
         * Availability (v2.25): accepting is the other door a second wedding on
         * one day can walk through, and this is the door no planner is standing
         * at. It is FLAGGED, never blocked — the vendor knows their own capacity
         * (a studio with two photographers covers two weddings; a lone caterer
         * does not), so the platform reports the collision rather than pretending
         * to decide it. Their page says it, their notes keep it, and the couple's
         * planner is told: the planner is the one who has to ring somebody.
         */
        $clash = [];
        if ($to === 'confirmed' && (string) $row->status !== 'confirmed') {
            $clash = VendorAvailability::clashes(
                (int) $row->vendor_id,
                VendorAvailability::deal_day($id),
                (int) $row->booking_id,
                $id
            );
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
            $vendorNote = '[' . date('j M Y') . ' — confirmed by ' . ($row->business_name) . " (vendor); deal " . format_ksh($amount, 2) . ".]\n";
            if ($clash !== []) {
                $vendorNote .= '[' . date('j M Y') . ' — accepted while also booked: ' . VendorAvailability::alert($clash) . "]\n";
            }
            $stmt = $db->prepare(
                "UPDATE tblvendor_bookings
                    SET status = 'confirmed', amount = :amt,
                        notes = CONCAT(:note, notes)
                  WHERE id = :id AND vendor_id IN ({$ownedIn})"
            );
            $stmt->execute($ownedParams + [
                ':amt'  => $amount,
                // Name the business the ROW belongs to, not the one being viewed
                // — an owner of two listings writes to the right ledger.
                ':note' => $vendorNote,
                ':id'   => $id,
            ]);
        } else {
            $stmt = $db->prepare(
                "UPDATE tblvendor_bookings
                    SET status = :st, notes = CONCAT(:note, notes)
                  WHERE id = :id AND vendor_id IN ({$ownedIn})"
            );
            $stmt->execute($ownedParams + [
                ':st'   => $to,
                ':note' => '[' . date('j M Y') . ' — ' . $to . ' by ' . ($row->business_name) . " (vendor).]\n",
                ':id'   => $id,
            ]);
        }

        /*
         * Accepting an enquiry records the deal just as staff do, and this is
         * the door where no planner is watching: the couple asked for a package,
         * the vendor accepts it, and if the wedding's plan is already bigger than
         * what they just agreed to (a 150-pax package for a 300-guest reception)
         * the vendor is the one person who can still say "take the bigger one" —
         * before they cater for 150 and the day runs short. So the deal is judged
         * the moment it is accepted (PackageCapacity::deal_position()), both sides
         * are told, and the vendor reads it on the page they just pressed.
         *
         * Their own copy states the limit and NOT the planner's way out: which
         * package covers the crowd at what price is the planner's conversation
         * with the vendor (the same rule the headcount brief follows), and the
         * vendor's own package list is one page away if they want to offer it.
         * Only on the transition INTO confirmed — the form is rendered for a
         * pending booking, and re-confirming is not a new sale.
         */
        $capacityNote = '';
        if ($to === 'confirmed' && (string) $row->status !== 'confirmed') {
            $here = PackageCapacity::deal_position((int) $row->booking_id, (int) $row->id);
            if ($here !== []) {
                HeadcountBrief::announce_capacity(
                    (int) $row->booking_id,
                    PackageCapacity::clauses($here),
                    (int) ($_SESSION['id'] ?? 0)
                );
                $capacityNote = PackageCapacity::notice($here);
            }
        }

        // Keep the couple's planner in the loop — the ledger they work from.
        $plannerId = (int) $db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1',
            [':b' => (int) $row->booking_id]
        );
        if ($plannerId > 0) {
            $plannerText = sprintf('%s marked your couple\'s %s booking as %s%s.',
                $vendor->business_name,
                trim(($row->bride ?? '') . ' & ' . ($row->groom ?? '')) !== '' ? trim(($row->bride ?? '') . ' & ' . ($row->groom ?? '')) : 'wedding',
                $to,
                $amount !== null ? ' at ' . format_ksh($amount) : ''
            );
            Notification::create_for_staff(
                $plannerId,
                (int) $row->booking_id,
                HeadcountBrief::append_note(
                    $plannerText,
                    $clash !== [] ? VendorAvailability::alert($clash) : ''
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

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Booking marked <strong>' . $to . '</strong>.'
            . ($capacityNote !== ''
                ? ' <span class="text-danger"><i class="mdi mdi-alert-outline"></i> ' . htmlspecialchars($capacityNote) . '</span>'
                : '')
            . ($clash !== []
                ? ' <span class="text-danger"><i class="mdi mdi-calendar-remove"></i> ' . htmlspecialchars(VendorAvailability::alert($clash))
                    . ' Your couple\'s planner has been told.</span>'
                : '')
            . '</div>');
        redirect_to('vendor_home.php' . $filter_qs);
        die();
    }

    // ---- Ledger: every booking of every listing this account owns ---------
    /*
     * e.title is the function this deal is scoped to for the headcount brief
     * (tblvendor_bookings.features_id, v2.10) — NULL when the deal covers the
     * whole wedding. The vendor sees their own scope here because they are the
     * recipient: a brief that reaches them about the reception but not the
     * after-party is only fair if they can see which functions their deal names.
     */
    $sql = "SELECT vb.*, p.title AS package_title, p.price AS package_price,
                   b.bride, b.groom, b.wedding_date, b.user_id AS couple_user_id,
                   d.phone AS couple_phone, v.business_name,
                   e.title AS function_title, e.start AS function_start
              FROM tblvendor_bookings vb
              JOIN tblvendors v ON v.id = vb.vendor_id
         LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
              JOIN tblweddingbook b ON b.booking_id = vb.booking_id
         LEFT JOIN tblaccounts_detail d ON d.user_id = b.user_id
         LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
             WHERE vb.vendor_id IN ({$ownedIn})";
    $params = $ownedParams;
    if ($status_filter !== '') {
        $sql .= " AND vb.status = :st";
        $params[':st'] = $status_filter;
    }
    if ($unread_filter) {
        $queue = "EXISTS (SELECT 1 FROM tblvendor_messages m
                           WHERE m.deal_id = vb.id
                             AND m.author_role <> 'vendor'
                             AND m.read_vendor_at IS NULL)";
        if ($thread_request > 0) {
            // The opener marks the thread read before redirecting here, so an
            // unread-only list would drop the very row it was asked to show.
            $sql .= ' AND (' . $queue . ' OR vb.id = :thread_focus)';
            $params[':thread_focus'] = $thread_request;
        } else {
            $sql .= ' AND ' . $queue;
        }
    }
    $sql .= " ORDER BY vb.status = 'pending' DESC, vb.created_at DESC, vb.id DESC";
    $bookings = $db->prepare($sql);
    $bookings->execute($params);
    $bookings = $bookings->fetchAll(PDO::FETCH_OBJ);

    /*
     * The conversation on each row (v2.25). Two queries for the whole ledger —
     * the threads and this vendor's unread count on each — then `?thread=N`
     * (what vendor_thread_open.php sends us back with) picks the one panel to
     * expand above the table. A thread id from another vendor's ledger is not
     * in this list, so it renders nothing: the opener would already have
     * refused it, and this page refuses it again.
     */
    $deal_ids        = array_map(static fn($b) => (int) $b->id, $bookings);
    $threads_by_deal = VendorThread::for_deals($deal_ids);
    $unread_by_deal  = VendorThread::unread_counts($deal_ids, VendorThread::VENDOR);
    $waiting_by_deal = VendorThread::waiting_since($deal_ids, $threads_by_deal, VendorThread::VENDOR);
    if ($unread_filter) {
        // Worked top to bottom, the queue answers the couple who has waited
        // longest first — and its first row is the deal the sidebar badge
        // opens, because both are ordered by the oldest unread message.
        $bookings = VendorThread::waiting_first($bookings, $waiting_by_deal);
    }
    $open_deal       = null;
    foreach ($bookings as $b) {
        if ((int) $b->id === $thread_request) {
            $open_deal = $b;
            break;
        }
    }

    /*
     * Availability (v2.25), one query for the whole ledger: this vendor's own
     * committed rows, indexed by day. A row's day is its own event_date, else the
     * function's, else the wedding's — so a pending enquiry shows "you are also
     * booked" BEFORE the vendor presses Accept, which is the only moment they can
     * weigh it.
     */
    $availability = VendorAvailability::commitments($ownedIds);

    /** @return object[] the other bookings covering this row's day */
    $clash_for = static function (object $b) use ($availability): array {
        $day = VendorAvailability::day($b->event_date ?? null, $b->function_start ?? null, $b->wedding_date ?? null);
        if ($day === '') {
            return [];
        }
        return VendorAvailability::excluding(
            $availability[(int) $b->vendor_id][$day] ?? [],
            (int) $b->booking_id,
            (int) $b->id
        );
    };

    // Tally per status for the filter chips (scoped to this vendor).
    $tally = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
    $tstmt = $db->prepare("SELECT status, COUNT(*) c FROM tblvendor_bookings WHERE vendor_id IN ({$ownedIn}) GROUP BY status");
    $tstmt->execute($ownedParams);
    foreach ($tstmt->fetchAll(PDO::FETCH_OBJ) as $row) {
        $tally[$row->status] = (int) $row->c;
    }

    /*
     * Threads waiting, for the Unread chip — counted separately from the
     * status tally so the two dimensions stay honest: the chip always says how
     * big the queue is, whatever status filter is on.
     */
    $unreadDeals = 0;
    if (VendorThread::installed()) {
        $unread_tally_sql = 'SELECT COUNT(*) FROM tblvendor_bookings vb'
            . " WHERE vb.vendor_id IN ({$ownedIn})"
            . " AND EXISTS (SELECT 1 FROM tblvendor_messages m
                              WHERE m.deal_id = vb.id
                                AND m.author_role <> 'vendor'
                                AND m.read_vendor_at IS NULL)";
        $unreadDeals = (int) $db->fetchValue($unread_tally_sql, $ownedParams);
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
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
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
            <p class="text-muted mb-0" style="font-size: 13px;">
                <?php if (count($ownedIds) > 1): ?>
                    <strong><?= count($ownedIds); ?> businesses</strong> — every couple enquiry on your ledgers
                <?php else: ?>
                    <?= htmlspecialchars($vendor->business_name); ?> — every couple enquiry on your ledger
                <?php endif; ?>
                <?= $vendor->is_approved ? '' : '<span class="badge badge-warning" style="font-size:10px;">pending approval</span>'; ?></p>
            <?php vendor_listing_switcher($myListings, (int) $vendor->id, 'vendor_home.php', array_filter([
                'status' => $status_filter !== '' ? $status_filter : null,
                'unread' => $unread_filter ? 1 : null,
            ])); ?>
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

    <?php if ($open_deal !== null): ?>
        <div class="card shadow-sm mb-3" style="border:none; border-radius:14px;" id="deal-<?= (int) $open_deal->id; ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap">
                    <div>
                        <h6 class="mb-1"><i class="mdi mdi-message-text-outline mr-1" style="color:#D97706"></i>
                            <?= htmlspecialchars(trim($open_deal->bride . ' & ' . $open_deal->groom)); ?>
                            <span class="text-muted" style="font-size:12px; font-weight:400;">
                                · <?= $open_deal->package_title !== null ? htmlspecialchars((string) $open_deal->package_title) : 'general enquiry'; ?>
                                <?= !empty($open_deal->event_date) ? ' · ' . htmlspecialchars(format_date_kd((string) $open_deal->event_date)) : ''; ?>
                            </span>
                        </h6>
                        <div class="text-muted" style="font-size:12px;">Your conversation with the couple — their planner is copied on everything.</div>
                    </div>
                    <a href="<?= htmlspecialchars(VendorThread::landing($open_deal, VendorThread::VENDOR)); ?>" class="btn btn-sm btn-outline-secondary mt-1">
                        <i class="mdi mdi-chevron-up mr-1"></i> Close
                    </a>
                </div>
                <?php $vt = ['role' => VendorThread::VENDOR, 'deal' => $open_deal, 'messages' => $threads_by_deal[(int) $open_deal->id] ?? []]; ?>
                <?php include 'include/vendor_thread_panel.php'; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-3"><div class="stat-card"><h6>Pending</h6><div class="value"><?= $tally['pending']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--success)"><h6>Confirmed</h6><div class="value"><?= $tally['confirmed']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--info)"><h6>Completed</h6><div class="value"><?= $tally['completed']; ?></div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--stone-500)"><h6>Cancelled</h6><div class="value"><?= $tally['cancelled']; ?></div></div></div>
    </div>

    <div class="mb-3">
        <a href="vendor_home.php?vendor_id=<?= (int) $vendor->id; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === '' && !$unread_filter ? 'active' : ''; ?>">All</a>
        <?php foreach ($valid_statuses as $st): ?>
            <a href="vendor_home.php?status=<?= $st; ?><?= $unread_filter ? '&amp;unread=1' : ''; ?>&amp;vendor_id=<?= (int) $vendor->id; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === $st ? 'active' : ''; ?>"><?= ucfirst($st); ?> (<?= $tally[$st]; ?>)</a>
        <?php endforeach; ?>
        <?php if (VendorThread::installed()): ?>
            <?php
            // A toggle: the active chip puts the whole ledger back, keeping the
            // status filter and the listing being read.
            $unread_params = [];
            if ($status_filter !== '') {
                $unread_params['status'] = $status_filter;
            }
            if (!$unread_filter) {
                $unread_params['unread'] = 1;
            }
            $unread_params['vendor_id'] = (int) $vendor->id;
            $unread_href = 'vendor_home.php?' . http_build_query($unread_params);
            ?>
            <a href="<?= htmlspecialchars($unread_href); ?>"
               class="btn btn-sm btn-outline-secondary filter-chip <?= $unread_filter ? 'active' : ''; ?>"
               title="<?= $unread_filter ? 'Show every booking again' : 'Only bookings with messages you have not read yet, longest waiting first'; ?>">
                <i class="mdi mdi-email-outline mr-1"></i>Unread (<?= $unreadDeals; ?>)
                <?= $unread_filter ? '<i class="mdi mdi-close ml-1"></i>' : ''; ?>
            </a>
        <?php endif; ?>
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
                    <?php empty_state_row(6,
                        $unread_filter ? 'Nothing waiting' : 'No bookings here yet',
                        $unread_filter
                            ? 'Every couple conversation on your bookings has been read. Switch the Unread chip off to see the whole ledger.'
                            : ($status_filter !== '' ? 'Nothing with this status — try another filter.' : 'Couple enquiries reach you the moment they are sent from the marketplace.'),
                        $unread_filter ? 'mdi-email-outline' : 'mdi-handshake-outline'); ?>
                <?php else: ?>
                    <?php foreach ($bookings as $b): [$badge, $icon] = $status_badges[$b->status]; ?>
                    <?php $clash = $clash_for($b); ?>
                    <?php $stage = VendorThread::stage($b, $threads_by_deal[(int) $b->id] ?? [], VendorThread::VENDOR); ?>
                    <?php $deal_unread = $unread_by_deal[(int) $b->id] ?? 0; ?>
                    <tr id="deal-<?= (int) $b->id; ?>">
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars(trim($b->bride . ' & ' . $b->groom)); ?></strong>
                            <div style="font-size:11px; color:#64748B;">
                                <?= !empty($b->wedding_date) ? htmlspecialchars(format_date_kd((string) $b->wedding_date)) : ''; ?>
                                <?= $b->couple_phone !== null ? ' · ' . htmlspecialchars($b->couple_phone) : ''; ?>
                            </div>
                            <?php if (count($ownedIds) > 1): ?>
                                <span class="badge badge-light" style="font-size:10px;"><?= htmlspecialchars((string) $b->business_name); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px;"><?= $b->package_title !== null ? htmlspecialchars($b->package_title) : '<span class="text-muted">General enquiry</span>'; ?>
                            <?php $briefScope = HeadcountBrief::scope_text([
                                'features_id'    => $b->features_id !== null ? (int) $b->features_id : 0,
                                'function_title' => $b->function_title !== null ? (string) $b->function_title : '',
                                'scope'          => $b->features_id === null ? 'wedding' : ($b->function_title === null ? 'missing' : 'function'),
                            ]); ?>
                            <div style="font-size:11px;" class="<?= $b->features_id !== null && $b->function_title === null ? 'text-danger' : 'text-muted'; ?>"
                                 title="The functions the headcount brief sends you">
                                <i class="mdi mdi-account-multiple-check"></i> Headcount brief: <?= htmlspecialchars($briefScope); ?>
                            </div>
                        </td>
                        <td style="font-size:12.5px;"><?= !empty($b->event_date) ? htmlspecialchars(format_date_kd($b->event_date)) : '<span class="text-muted">—</span>'; ?></td>
                        <td style="font-size:12.5px;">
                            <?php if ((float) $b->amount > 0): ?>
                                <strong><?= format_ksh($b->amount, 2); ?></strong>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill <?= $badge; ?>"><i class="mdi mdi-<?= $icon; ?>"></i> <?= ucfirst($b->status); ?></span>
                            <?php if ($clash !== [] && in_array((string) $b->status, ['pending', 'confirmed'], true)): ?>
                                <div class="text-danger mt-1" style="font-size:10.5px; line-height:1.35;">
                                    <i class="mdi mdi-calendar-remove"></i> <?= htmlspecialchars(VendorAvailability::self_notice($clash)); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="vt-glance" href="<?= htmlspecialchars(VendorThread::opener_url((int) $b->id, array_filter([
                                'status' => $status_filter !== '' ? $status_filter : null,
                                'unread' => $unread_filter ? 1 : null,
                            ]))); ?>"
                               style="text-decoration:none; color:<?= $stage['tone'] === 'action' ? '#065F46' : '#64748B'; ?>;"
                               title="<?= htmlspecialchars($stage['detail']); ?>">
                                <i class="mdi mdi-<?= htmlspecialchars($stage['icon']); ?>" style="font-size:13px;"></i>
                                <strong><?= htmlspecialchars($stage['label']); ?></strong>
                                <?php if ($deal_unread > 0): ?>
                                    <span class="vt-unread-pill"><?= $deal_unread; ?> new</span>
                                    <?php $waited = $waiting_by_deal[(int) $b->id][0] ?? null; ?>
                                    <?php if ($waited !== null): ?>
                                        <span class="vt-waiting" title="Waiting since <?= htmlspecialchars(format_date_kd($waited, true)); ?>">waiting <?= htmlspecialchars(VendorThread::waiting_for($waited)); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </a>
                            <details>
                                <summary style="font-size:12px; cursor:pointer; color:#4C6EF5;">notes &amp; actions</summary>
                                <div class="enq-notes mt-1 mb-2"><?= htmlspecialchars($b->notes ?? ''); ?></div>
                                <form method="post" action="vendor_home.php<?= htmlspecialchars($filter_qs); ?>" class="form-inline">
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
