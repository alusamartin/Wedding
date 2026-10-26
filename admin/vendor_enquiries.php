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
 *
 * WHO ELSE READS THIS PAGE (v2.10)
 * A confirmed deal makes its vendor a recipient of the headcount brief
 * (include/HeadcountBrief.php): when a couple moves a function's numbers, every
 * vendor booked on that wedding is told — scoped to the functions their own
 * deal covers. That scope is set here, on the deal, because this ledger is
 * where a deal is made: the planner books the caterer for the Ruracio and the
 * reception, or the MC for the after-party only, and the scope says which
 * moves are that vendor's business. "Whole wedding" (the default, and what
 * every deal written before v2.10 means) sends every moved function — which is
 * what a venue or a caterer for the day needs. Only functions on the deal's OWN
 * wedding are offered or accepted: a scope pointing at another couple's
 * calendar could never match a change and no page would ever show it.
 */
    require_staff_subscribed();

    $status_filter = clean($_GET['status'] ?? '');
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($status_filter, $valid_statuses, true)) {
        $status_filter = '';
    }

    /*
     * The unread queue (v2.25). "Unread" means a message on the deal that this
     * planner has not read yet, so the filter is the ledger's answer to "what
     * still needs an answer?" — one list to work top to bottom instead of
     * opening deals at random. Only offered when the migration is installed;
     * an older database keeps the status filters alone.
     *
     * ?thread=N is read here too because the queue uses it twice: the opener
     * returns with it, and a deal opened from this filtered list may have just
     * been marked read — the row must stay visible so its panel still renders.
     */
    $unread_filter  = VendorThread::installed() && (int) ($_GET['unread'] ?? 0) === 1;
    $thread_request = (int) ($_GET['thread'] ?? 0);

    // One query string for "the filters that are on", so every form and every
    // redirect returns to the same view instead of silently dropping one.
    $filter_params = [];
    if ($status_filter !== '') {
        $filter_params['status'] = $status_filter;
    }
    if ($unread_filter) {
        $filter_params['unread'] = 1;
    }
    $filter_qs = $filter_params !== [] ? '?' . http_build_query($filter_params) : '';

    global $db;

    /*
     * Per-planner scoping (see Helper.php): the ledger is every deal on the
     * weddings this planner owns, plus the ones nobody has been assigned yet.
     * The condition is the SAME rule the pages apply, rendered as SQL, so the
     * list cannot offer a deal whose own wedding the planner would be refused.
     * A super admin (or an install with scoping switched off) gets an empty
     * condition and the old whole-platform view.
     */
    $scope = planner_scope_sql('b.organizer_id');

    $sql = "SELECT vb.*, v.business_name, v.category, v.phone AS vendor_phone, v.email AS vendor_email,
                   p.title AS package_title, p.price AS package_price,
                   b.bride, b.groom, b.user_id, b.wedding_date, b.organizer_id,
                   d.status AS account_status
            FROM tblvendor_bookings vb
            INNER JOIN tblvendors v ON v.id = vb.vendor_id
            LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
            INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id
            LEFT JOIN tblaccounts_detail d ON d.user_id = b.user_id";
    $where  = [];
    $params = $scope['params'];
    if ($scope['sql'] !== '') {
        $where[] = $scope['sql'];
    }
    if ($status_filter !== '') {
        $where[]       = 'vb.status = :st';
        $params[':st'] = $status_filter;
    }
    if ($unread_filter) {
        $queue = "EXISTS (SELECT 1 FROM tblvendor_messages m
                           WHERE m.deal_id = vb.id
                             AND m.author_role <> 'planner'
                             AND m.read_planner_at IS NULL)";
        if ($thread_request > 0) {
            // The opener marks the thread read before redirecting here, so an
            // unread-only list would drop the very row it was asked to show.
            // The OR keeps that row (still planner-scoped) in the result.
            $where[]                = '(' . $queue . ' OR vb.id = :thread_focus)';
            $params[':thread_focus'] = $thread_request;
        } else {
            $where[] = $queue;
        }
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY vb.status = 'pending' DESC, vb.created_at DESC, vb.id DESC";
    $enquiries = $db->prepare($sql);
    $enquiries->execute($params);
    $enquiries = $enquiries->fetchAll(PDO::FETCH_OBJ);

    /*
     * The conversation (v2.25): two queries for the whole visible ledger, and
     * ?thread=N — what vendor_thread_open.php returns with — picks the one
     * panel to expand above the table. The list is already planner-scoped, so
     * a thread id belonging to somebody else's deal simply is not in it.
     */
    $deal_ids        = array_map(static fn($e) => (int) $e->id, $enquiries);
    $threads_by_deal = VendorThread::for_deals($deal_ids);
    $unread_by_deal  = VendorThread::unread_counts($deal_ids, VendorThread::PLANNER);
    $waiting_by_deal = VendorThread::waiting_since($deal_ids, $threads_by_deal, VendorThread::PLANNER);
    if ($unread_filter) {
        // Worked top to bottom, the queue answers the couple who has waited
        // longest first — and its first row is the deal the sidebar badge
        // opens, because both are ordered by the oldest unread message.
        $enquiries = VendorThread::waiting_first($enquiries, $waiting_by_deal);
    }
    $open_deal       = null;
    foreach ($enquiries as $e) {
        if ((int) $e->id === $thread_request) {
            $open_deal = $e;
            break;
        }
    }

    /*
     * Tally per status for the filter chips — scoped the same way, or the chips
     * would advertise deals the list underneath them cannot show.
     */
    $tally = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
    $tally_sql = 'SELECT vb.status, COUNT(*) c FROM tblvendor_bookings vb'
        . ' INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id'
        . ($scope['sql'] !== '' ? ' WHERE ' . $scope['sql'] : '')
        . ' GROUP BY vb.status';
    foreach ($db->execute($tally_sql, $scope['params'])->fetchAll(PDO::FETCH_OBJ) as $row) {
        $tally[$row->status] = (int) $row->c;
    }

    /*
     * The unread chip counts THREADS waiting, scoped like the list: the number
     * of deals a planner can act on right now. It deliberately ignores the
     * status filter (as the status chips ignore unread) so the two dimensions
     * stay independent; a chip that counted its own filtered view could never
     * tell you the queue was bigger than what you were looking at.
     */
    $unreadDeals = 0;
    if (VendorThread::installed()) {
        $unread_tally_sql = 'SELECT COUNT(*) FROM tblvendor_bookings vb'
            . ' INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id'
            . " WHERE EXISTS (SELECT 1 FROM tblvendor_messages m
                              WHERE m.deal_id = vb.id
                                AND m.author_role <> 'planner'
                                AND m.read_planner_at IS NULL)"
            . ($scope['sql'] !== '' ? ' AND ' . $scope['sql'] : '');
        $unreadDeals = (int) $db->fetchValue($unread_tally_sql, $scope['params']);
    }

    /*
     * Each wedding's functions, for the scope picker below (and for reading a
     * stored scope back as a title). BookingEvent::plan_for() reads the
     * booking's OWN `events`, so the options a planner can pick from are the
     * functions of that wedding and nothing else.
     */
    $functions_by_booking = [];
    foreach ($enquiries as $e) {
        $bid = (int) $e->booking_id;
        if (!isset($functions_by_booking[$bid])) {
            $functions_by_booking[$bid] = BookingEvent::plan_for($bid);
        }
    }

    /*
     * Every ACTIVE package of the vendors on this page, in one query, keyed by
     * vendor: the pool the re-price control may choose from (v2.11).
     * include/PackageCapacity.php reads the same rows for the package it suggests,
     * and only ever a vendor's own — a deal cannot be moved onto somebody else's
     * package, or onto a retired one. The id list is BOUND through
     * bound_id_list() — the one place an id list becomes SQL — rather than
     * inlined, so the fragment carries placeholders and nothing else.
     */
    $packages_by_vendor = [];
    $vendor_ids = array_values(array_unique(array_map(static fn($e): int => (int) $e->vendor_id, $enquiries)));
    if ($vendor_ids !== []) {
        $vendor_scope = bound_id_list($vendor_ids);
        $pkg_rows = $db->execute(
            "SELECT id, vendor_id, title, capacity, price FROM tblvendor_packages
              WHERE is_active = 1 AND vendor_id IN ({$vendor_scope['sql']})
           ORDER BY capacity ASC, price ASC, id ASC",
            $vendor_scope['params']
        )->fetchAll(PDO::FETCH_OBJ);
        foreach ($pkg_rows as $pkg) {
            $packages_by_vendor[(int) $pkg->vendor_id][] = $pkg;
        }
    }

    /*
     * Availability (v2.25): is the vendor on this deal already committed to
     * ANOTHER wedding that day? The comparison is per deal because the day is:
     * a deal's own event_date when one was typed, otherwise the day of the
     * function it is scoped to, otherwise the wedding's. One query covers every
     * vendor on the page (VendorAvailability::commitments), and each row then
     * looks itself up in memory — the ledger lists every deal in the system, so
     * "one query per row" is exactly what this must not be.
     */
    $availability = VendorAvailability::commitments($vendor_ids);

    /**
     * The other weddings this deal's vendor is committed to on the deal's day.
     *
     * @return object[] rows from VendorAvailability::commitments()
     */
    $clashes_for_deal = static function (object $e) use ($availability, $functions_by_booking): array {
        $fid   = $e->features_id !== null ? (int) $e->features_id : 0;
        $start = '';
        if ($fid > 0) {
            foreach ($functions_by_booking[(int) $e->booking_id] ?? [] as $fn) {
                if ((int) $fn->features_id === $fid) {
                    $start = (string) $fn->start;
                    break;
                }
            }
        }
        $day = VendorAvailability::day($e->event_date ?? null, $start, (string) ($e->wedding_date ?? ''));
        if ($day === '') {
            return [];
        }
        return VendorAvailability::excluding(
            $availability[(int) $e->vendor_id][$day] ?? [],
            (int) $e->booking_id,
            (int) $e->id
        );
    };

    /*
     * Arriving from the venue sheet's "Re-price this deal" link (?deal=N): that
     * row is highlighted and its own capacity position is printed with it — the
     * one thing the planner clicked through to see. Computed for that booking
     * only, never per row: the ledger lists every deal in the system, and this
     * check would otherwise run a plan + deal query for every line on it.
     */
    $focus_deal = (int) ($_GET['deal'] ?? 0);
    $focus_note = '';
    if ($focus_deal > 0) {
        foreach ($enquiries as $e) {
            if ((int) $e->id !== $focus_deal) {
                continue;
            }
            $focus_note = PackageCapacity::notice(
                PackageCapacity::breaches((int) $e->booking_id, PackageCapacity::plan_totals((int) $e->booking_id))
            );
            break;
        }
    }

    /**
     * A deal row's scope, in the shape HeadcountBrief reads it: the stored
     * features_id, the title it resolves to on this wedding, and whether that
     * is the whole wedding, one function, or a function the couple has since
     * deleted (which leaves the vendor un-reachable and is reported as such).
     *
     * @return array{features_id:int,function_title:string,scope:string}
     */
    $deal_scope = static function (object $e) use ($functions_by_booking): array {
        $fid = $e->features_id !== null ? (int) $e->features_id : 0;
        $title = '';
        $found = false;
        foreach ($functions_by_booking[(int) $e->booking_id] ?? [] as $fn) {
            if ($fid > 0 && (int) $fn->features_id === $fid) {
                $title = (string) $fn->title;
                $found = true;
                break;
            }
        }
        return [
            'features_id'    => $fid,
            'function_title' => $title,
            'scope'          => $fid === 0 ? 'wedding' : ($found ? 'function' : 'missing'),
        ];
    };

    /**
     * May THIS planner act on this deal? Ids arrive in a POST, which is its own
     * request with no view behind it, so every handler below has to re-ask the
     * question rather than trust that the row was reachable a moment ago. It asks
     * it through the SAME predicate the list and the wedding pages use, by
     * resolving the deal's booking — one rule, one place, and per-planner scoping
     * applies to a POST exactly as it applies to a page.
     */
    $deal_is_mine = static function (int $dealId) use ($db): bool {
        if ($dealId <= 0) {
            return false;
        }
        $bookingId = (int) $db->fetchValue(
            'SELECT booking_id FROM tblvendor_bookings WHERE id = :id LIMIT 1',
            [':id' => $dealId]
        );
        return $bookingId > 0 && booking_visible_to_session(Booking::find_by_booking_id($bookingId));
    };

    // ---- Status transitions (POST + CSRF) --------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enquiry_id'])) {
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to("vendor_enquiries.php" . ($filter_qs));
            die();
        }
        $id = (int)($_POST['enquiry_id'] ?? 0);
        $to = clean($_POST['to'] ?? '');
        if (!in_array($to, $valid_statuses, true)) {
            $session->message('<div class="alert alert-danger">Unknown status.</div>');
            redirect_to("vendor_enquiries.php" . ($filter_qs));
            die();
        }

        // The vendor's own account and phone come along: a cancellation is their
        // diary changing, so they are told as well as the couple (v3.3c).
        $stmt = $db->prepare('SELECT vb.*, v.business_name, v.user_id AS vendor_user_id, v.phone AS vendor_phone, b.user_id AS couple_user_id, b.bride, b.groom, b.wedding_date FROM tblvendor_bookings vb JOIN tblvendors v ON v.id = vb.vendor_id JOIN tblweddingbook b ON b.booking_id = vb.booking_id WHERE vb.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row === null) {
            $session->message('<div class="alert alert-danger">That enquiry no longer exists.</div>');
            redirect_to("vendor_enquiries.php" . ($filter_qs));
            die();
        }
        if (!$deal_is_mine($id)) {
            $session->message('<div class="alert alert-danger">That enquiry is on a wedding you are not assigned to, so it is not yours to move.</div>');
            redirect_to("vendor_enquiries.php" . ($filter_qs));
            die();
        }

        /*
         * Availability gate (v2.25). Confirming is the moment a second wedding
         * on the same day becomes the platform's problem, and the only moment
         * anybody can still ask "can they really be in two places?". So a clash
         * is REFUSED unless the planner ticks the acknowledgement the row now
         * offers — nothing is written, and the refusal names the other wedding.
         * A vendor with a second team is a legitimate booking, which is why the
         * answer is a tick and not a wall; what is not allowed is confirming
         * past the warning without noticing it. Only on the transition INTO
         * confirmed: re-confirming a settled deal is not a new booking.
         */
        $clash        = [];
        $acknowledged = (string) ($_POST['acknowledge_clash'] ?? '') === '1';
        if ($to === 'confirmed' && (string) $row->status !== 'confirmed') {
            $clash = VendorAvailability::clashes(
                (int) $row->vendor_id,
                VendorAvailability::deal_day($id),
                (int) $row->booking_id,
                $id
            );
            if ($clash !== [] && !$acknowledged) {
                $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-calendar-remove"></i></strong> '
                    . htmlspecialchars(VendorAvailability::notice($clash, (string) $row->business_name))
                    . ' Nothing has been changed. If they can still cover both weddings, tick '
                    . '<strong>Book them anyway</strong> on the deal and confirm again.</div>');
                redirect_to("vendor_enquiries.php" . ($filter_qs));
                die();
            }
        }

        /*
         * Why the planner is dropping it (v3.3). Optional, and only read on a
         * cancel: confirming does not need explaining, and a stray value in the
         * field must not travel with an Accept. `status_note` is the answer that
         * is CURRENT, so both branches write it — a confirm CLEARS whatever the
         * vendor said when they first declined, exactly as the vendor's own
         * answer does. Otherwise "already booked that Saturday" would sit beside
         * a deal the planner has since agreed.
         *
         * The attribution is written alongside it: the couple and the vendor
         * read the same badge, and "your planner said" is a different phone call
         * from "the vendor said".
         */
        $plannerReason = $to === 'cancelled'
            ? DealNote::clean((string) ($_POST['status_note'] ?? ''))
            : '';
        $noteSets   = '';
        $noteParams = [];
        if (DealNote::installed()) {
            // NULL, not '', so "no reason was given" is one value everywhere.
            $noteSets           .= ', status_note = :snote';
            $noteParams[':snote'] = $plannerReason !== '' ? $plannerReason : null;
            if (DealNote::author_installed()) {
                $noteSets              .= ', status_note_by = :snote_by';
                $noteParams[':snote_by'] = $plannerReason !== '' ? DealNote::PLANNER : null;
            }
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
                redirect_to("vendor_enquiries.php" . ($filter_qs));
                die();
            }
            $commission = round($amount * 0.10, 2); // standard 10% planner commission
            $confirmNote = '[' . date('j M Y') . ' — confirmed by ' . ($_SESSION['user_email'] ?? 'staff') . '; deal ' . format_ksh($amount, 2) . ', commission ' . format_ksh($commission, 2) . ".]\n";
            /*
             * The acknowledgement is a fact about the deal, so it goes in the
             * trail with the rest of them: six months later, "why was the same
             * photographer on two weddings?" has an answer and a name.
             */
            if ($clash !== []) {
                $confirmNote .= '[' . date('j M Y') . ' — double-booking acknowledged by '
                    . ($_SESSION['user_email'] ?? 'staff') . ': ' . VendorAvailability::alert($clash) . "]\n";
            }
            $stmt = $db->prepare(
                'UPDATE tblvendor_bookings SET status = \'confirmed\', amount = :amt, commission = :comm,'
                . ' notes = CONCAT(:note, notes)' . $noteSets . ' WHERE id = :id'
            );
            $stmt->execute([
                ':amt'  => $amount,
                ':comm' => $commission,
                ':note' => $confirmNote,
                ':id'   => $id,
            ] + $noteParams);
        } else {
            $trailNote = '[' . date('j M Y') . ' — ' . $to . ' by ' . ($_SESSION['user_email'] ?? 'staff')
                . ($plannerReason !== '' ? '; reason: "' . $plannerReason . '"' : '') . ".]\n";
            $stmt = $db->prepare(
                'UPDATE tblvendor_bookings SET status = :st, notes = CONCAT(:note, notes)'
                . $noteSets . ' WHERE id = :id'
            );
            $stmt->execute([
                ':st'   => $to,
                ':note' => $trailNote,
                ':id'   => $id,
            ] + $noteParams);
        }

        // The card numbers are derived, not tallied: a deal that has just been
        // confirmed, completed, cancelled or reopened changes what this vendor's
        // total_bookings and rating should read, so recompute them from the rows
        // (Vendor::recompute_stats — the same writer the couple's rating uses).
        Vendor::recompute_stats((int) $row->vendor_id);

        /*
         * Recording the deal is the first moment a package is what the wedding is
         * sold on, and the last moment it is still free to change — and the plan
         * may already be bigger than it: a couple who planned 300 for the reception
         * and asked the caterer for their 150-pax package, confirmed here, would
         * otherwise not hear a word of it until the next headcount save, which may
         * never come (the numbers are settled by then). So the deal is judged
         * against the plan as it stands, right where it is made
         * (PackageCapacity::deal_position()), the planner and the vendor holding
         * the package are told, and the saver — who has one more decision to make,
         * with `Re-price` on the row above — is told in words with the way out.
         *
         * Only on the transition INTO confirmed, which is what "a new deal" means
         * here: the confirm form is rendered for pending enquiries only, and a
         * re-confirm from a crafted POST is not a new sale.
         */
        $capacityNote = '';
        if ($to === 'confirmed' && (string) $row->status !== 'confirmed') {
            $here = PackageCapacity::deal_position((int) $row->booking_id, $id);
            if ($here !== []) {
                $warned = HeadcountBrief::announce_capacity(
                    (int) $row->booking_id,
                    PackageCapacity::clauses($here),
                    (int) ($_SESSION['id'] ?? 0)
                );
                $capacityNote = PackageCapacity::position($here, (string) ($warned['told'] ?? ''));
            }
        }

        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> '
            . htmlspecialchars($row->business_name) . ' enquiry marked <strong>' . $to . '</strong>.'
            . ($capacityNote !== ''
                ? ' <span class="text-danger"><i class="mdi mdi-alert-outline"></i> ' . htmlspecialchars($capacityNote) . '</span>'
                : '')
            . ($clash !== []
                ? ' <span class="text-danger"><i class="mdi mdi-calendar-remove"></i> Recorded with a known clash: '
                    . htmlspecialchars(VendorAvailability::alert($clash)) . '</span>'
                : '')
            . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');

        // Keep the couple in the loop: every status change lands in their bell.
        $coupleUid = (int) ($row->couple_user_id ?? 0);
        if ($coupleUid > 0) {
            $status_copy = [
                'confirmed' => 'Good news — ' . $row->business_name . ' is confirmed for your wedding'
                               . ($amount !== null && $amount > 0 ? ' (deal ' . format_ksh($amount) . ')' : '')
                               . '. Your planner finalized the details.',
                'completed' => $row->business_name . ' has completed their services for your wedding. Thank you for planning with us.',
                // The reason is clipped: the column is 255 characters and a
                // full one would push the sentence over it on a strict server.
                'cancelled' => 'Your enquiry with ' . $row->business_name . ' was cancelled'
                               . ($plannerReason !== '' ? ' — ' . DealNote::short($plannerReason, 110) : '')
                               . '. Your planner will help you find an alternative.',
                'pending'   => 'Your enquiry with ' . $row->business_name . ' was reopened — your planner is reviewing it again.',
            ];
            Notification::create_for_couple(
                $coupleUid,
                (int) $row->booking_id,
                $status_copy[$to] ?? ($row->business_name . ' enquiry updated to ' . $to . '.'),
                'client_vendors.php?booking_id=' . (int) $row->booking_id . '&user_id=' . $coupleUid
            );

            // Big-moment SMS on the two moments couples care most about:
            // the vendor is locked in, or the deal fell through. Best-effort
            // (charged to the assigned planner's credits); the bell alert
            // above already fired either way.
            if ($to === 'confirmed' || $to === 'cancelled') {
                global $db;
                $cphone = (string) $db->fetchValue(
                    'SELECT phone FROM tblaccounts_detail WHERE user_id = :u LIMIT 1',
                    [':u' => $coupleUid]
                );
                $organizerId = (int) $db->fetchValue(
                    'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1',
                    [':b' => (int) $row->booking_id]
                );
                if ($to === 'confirmed') {
                    $smsText = 'Habari! ' . $row->business_name . ' is CONFIRMED for your wedding'
                        . (!empty($row->wedding_date) ? ' (' . format_date_kd((string) $row->wedding_date) . ')' : '')
                        . ' — deal locked at ' . format_ksh((float) ($amount ?? 0)) . '. Your planner finalized the details.';
                } else {
                    /*
                     * The planner's own words travel with the cancellation: the
                     * SMS is often the only thing the couple sees before they
                     * ring, and "cancelled" alone reads like the wedding is off.
                     * Cut to 90 characters — the budget the vendor's decline SMS
                     * already uses — so it stays one segment, not three.
                     */
                    $smsText = 'Habari! Your ' . $row->business_name . ' enquiry was cancelled'
                        . ($plannerReason !== '' ? ': "' . mb_substr($plannerReason, 0, 90) . '"' : '')
                        . '. Your planner will help you find a great alternative.';
                }
                SMS::send_courtesy($cphone, $smsText, $organizerId, (int) $row->booking_id);
            }
        }

        /*
         * The vendor's own bell and phone (v3.3c). Until now a cancellation was
         * the vendor's diary changing in silence: the couple were told twice and
         * the vendor found out the next time they opened My Bookings — which for
         * a date they were holding for a booking fee is too late to re-let it.
         * The planner's words travel with the news because "we found another
         * caterer" and "the date moved to the 26th" are different answers to
         * "shall I hold the day?".
         *
         * Only on the transition INTO cancelled, so a double submit cannot ring
         * them twice. Best-effort SMS (charged to the planner's credits, like the
         * couple's); the bell above fires either way.
         */
        if ($to === 'cancelled' && (string) $row->status !== 'cancelled') {
            $vendorUid   = (int) ($row->vendor_user_id ?? 0);
            $vendorPhone = trim((string) ($row->vendor_phone ?? ''));
            $coupleNames = trim(($row->bride ?? '') . ' & ' . ($row->groom ?? ''), ' &');
            $coupleNames = $coupleNames !== '' ? mb_substr($coupleNames, 0, 60) : 'a couple';
            $dayText     = !empty($row->wedding_date) ? ' (' . format_date_kd((string) $row->wedding_date) . ')' : '';

            if ($vendorUid > 0) {
                Notification::create_for_vendor(
                    $vendorUid,
                    (int) $row->booking_id,
                    'Your booking for ' . $coupleNames . '\'s wedding' . $dayText . ' was cancelled by their planner.'
                        . ($plannerReason !== '' ? ' They said: "' . DealNote::short($plannerReason, 100) . '".' : '')
                        . ' It is off your calendar.',
                    'vendor_home.php?status=cancelled'
                );
            }

            if ($vendorPhone !== '') {
                $organizerId = (int) $db->fetchValue(
                    'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1',
                    [':b' => (int) $row->booking_id]
                );
                $vendorSms = 'Habari ' . mb_substr(trim((string) $row->business_name), 0, 40) . '! Your '
                    . $coupleNames . ' booking' . $dayText . ' was cancelled by their planner'
                    . ($plannerReason !== '' ? ': "' . mb_substr($plannerReason, 0, 80) . '"' : '')
                    . '. It is off your calendar.';
                SMS::send_courtesy($vendorPhone, $vendorSms, $organizerId, (int) $row->booking_id);
            }
        }

        redirect_to("vendor_enquiries.php" . ($filter_qs));
        die();
    }

    // ---- Deal scope: the functions this vendor is briefed on (POST + CSRF) --
    /*
     * Its own handler, not a field on the confirm transition: re-confirming a
     * deal to change its scope would re-price it (the confirm branch takes the
     * amount from the form), and the scope has to be correctable long after the
     * deal was agreed. So the scope save touches the scope, records who set it
     * in the notes trail, and nothing else.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scope_deal_id'])) {
        $back = "vendor_enquiries.php" . ($filter_qs);
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to($back);
            die();
        }

        $dealId = (int)($_POST['scope_deal_id'] ?? 0);
        $scope  = (int) ($_POST['features_id'] ?? 0);

        $stmt = $db->prepare('SELECT vb.id, vb.booking_id, vb.features_id, vb.status, v.business_name
                                FROM tblvendor_bookings vb
                                JOIN tblvendors v ON v.id = vb.vendor_id
                               WHERE vb.id = :id LIMIT 1');
        $stmt->execute([':id' => $dealId]);
        $deal = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
        if ($deal === null) {
            $session->message('<div class="alert alert-danger">That deal no longer exists.</div>');
            redirect_to($back);
            die();
        }
        if (!$deal_is_mine($dealId)) {
            $session->message('<div class="alert alert-danger">That deal is on a wedding you are not assigned to, so its scope is not yours to set.</div>');
            redirect_to($back);
            die();
        }

        // Only this deal's own wedding's functions, ever: a scope pointing at
        // another couple's calendar could never match a headcount change, so it
        // would look set and quietly reach nobody.
        if ($scope > 0 && !BookingEvent::function_belongs_to((int) $deal->booking_id, $scope)) {
            $session->message('<div class="alert alert-danger">That function is not on this wedding\'s calendar, so the deal cannot be scoped to it.</div>');
            redirect_to($back);
            die();
        }

        $scopeLabel = 'the whole wedding';
        foreach (BookingEvent::plan_for((int) $deal->booking_id) as $fn) {
            if ($scope > 0 && (int) $fn->features_id === $scope) {
                $scopeLabel = trim((string) $fn->title) !== '' ? trim((string) $fn->title) : 'Ceremony';
                break;
            }
        }

        $db->prepare("UPDATE tblvendor_bookings
                         SET features_id = :f, notes = CONCAT(:note, notes)
                       WHERE id = :id")
           ->execute([
               ':f'    => $scope > 0 ? $scope : null,
               ':note' => '[' . date('j M Y') . ' — headcount brief scoped to ' . $scopeLabel
                           . ' by ' . ($_SESSION['user_email'] ?? 'staff') . ".]\n",
               ':id'   => $dealId,
           ]);

        /*
         * A scope is the other way a confirmed deal starts covering a crowd: the
         * package never changed and no headcount moved, but a caterer scoped
         * to the reception can be re-scoped to the whole wedding — including a
         * function planned past what they are sold for — with one dropdown. So the
         * widened deal is judged here too, and only when the scope actually
         * changed: saving the same scope again is not a change and announces
         * nothing.
         */
        $capacityNote = '';
        if ((string) $deal->status === 'confirmed' && $scope !== (int) ($deal->features_id ?? 0)) {
            $here = PackageCapacity::deal_position((int) $deal->booking_id, $dealId);
            if ($here !== []) {
                $warned = HeadcountBrief::announce_capacity(
                    (int) $deal->booking_id,
                    PackageCapacity::clauses($here),
                    (int) ($_SESSION['id'] ?? 0)
                );
                $capacityNote = PackageCapacity::position($here, (string) ($warned['told'] ?? ''));
            }
        }

        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> '
            . htmlspecialchars((string) $deal->business_name) . ' is now briefed on <strong>'
            . htmlspecialchars($scopeLabel) . '</strong> headcount changes.'
            . ($capacityNote !== ''
                ? ' <span class="text-danger"><i class="mdi mdi-alert-outline"></i> ' . htmlspecialchars($capacityNote) . '</span>'
                : '')
            . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        redirect_to($back);
        die();
    }

    // ---- Re-price a confirmed deal onto another package of the SAME vendor ----
    /*
     * The one step out of a capacity breach (include/PackageCapacity.php finds it
     * and the venue sheet links here). A deal's package was fixed when the deal
     * was recorded and nothing in the application could change it, so a planner
     * could be told that a package no longer covers the day with nowhere to act:
     * the honest one-step fix is to move the deal onto the vendor's next package
     * up, at the vendor's price for it or an agreed amount.
     *
     * Its own handler, like the scope save, and for the same reason — re-confirming
     * a deal to change its package would re-run the confirm transition and its
     * alerts. What it touches is package + amount + commission + the notes trail.
     * What it never touches: the status (a re-price is not a re-negotiation of
     * whether the vendor is booked) and the headcount scope.
     *
     * The amount may be left blank, in which case the chosen package's own price
     * is used — the same rule the confirm step already applies. A package must be
     * this vendor's and active (Vendor::package_of), so a crafted POST cannot park
     * a deal on somebody else's package or on a retired one; 0 is "no package",
     * which is a correction, not a purchase, and then an amount is required.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reprice_deal_id'])) {
        $back = "vendor_enquiries.php" . ($filter_qs);
        if (!$session->verify_csrf()) {
            $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
            redirect_to($back);
            die();
        }

        $dealId    = (int)($_POST['reprice_deal_id'] ?? 0);
        $packageId = (int) ($_POST['package_id'] ?? 0);
        $typed     = (float) ($_POST['amount'] ?? 0);

        $stmt = $db->prepare(
            'SELECT vb.id, vb.booking_id, vb.vendor_id, vb.package_id, vb.amount, vb.status,
                    v.business_name, v.user_id AS vendor_user_id, b.user_id AS couple_user_id,
                    p.title AS old_title
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
               JOIN tblweddingbook b ON b.booking_id = vb.booking_id
          LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
              WHERE vb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $dealId]);
        $deal = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
        if ($deal === null) {
            $session->message('<div class="alert alert-danger">That deal no longer exists.</div>');
            redirect_to($back);
            die();
        }
        if (!$deal_is_mine($dealId)) {
            $session->message('<div class="alert alert-danger">That deal is on a wedding you are not assigned to, so it is not yours to re-price.</div>');
            redirect_to($back);
            die();
        }
        if ($deal->status !== 'confirmed') {
            $session->message('<div class="alert alert-danger">Only a confirmed deal can be re-priced — the ' . htmlspecialchars((string) $deal->status) . ' ones are settled. Confirm it first if it is still an enquiry.</div>');
            redirect_to($back);
            die();
        }

        $package = null;
        if ($packageId > 0) {
            $package = Vendor::package_of((int) $deal->vendor_id, $packageId);
            if ($package === null) {
                $session->message('<div class="alert alert-danger">That package is not this vendor\'s, or it is not active. A deal can only move onto another active package of the same vendor.</div>');
                redirect_to($back);
                die();
            }
        }
        $amount = $typed > 0 ? $typed : (float) ($package->price ?? 0);
        if ($amount <= 0) {
            $session->message('<div class="alert alert-danger">Enter the agreed deal amount — the package you picked states no price.</div>');
            redirect_to($back);
            die();
        }
        $commission = round($amount * 0.10, 2); // the same standard 10% the confirm step applies
        $was        = (float) $deal->amount;
        $oldLabel   = $deal->old_title !== null ? (string) $deal->old_title : 'no package';
        $newLabel   = $package !== null ? (string) $package->title : 'no package';

        $db->prepare(
            "UPDATE tblvendor_bookings
                SET package_id = :p, amount = :amt, commission = :comm,
                    notes = CONCAT(:note, notes)
              WHERE id = :id"
        )->execute([
            ':p'    => $packageId > 0 ? $packageId : null,
            ':amt'  => $amount,
            ':comm' => $commission,
            ':note' => '[' . date('j M Y') . ' — re-priced by ' . ($_SESSION['user_email'] ?? 'staff')
                        . ': ' . mb_substr($oldLabel, 0, 80) . ' (' . format_ksh($was, 2) . ') → '
                        . mb_substr($newLabel, 0, 80) . ' (' . format_ksh($amount, 2) . '), commission '
                        . format_ksh($commission, 2) . ".]\n",
            ':id'   => $dealId,
        ]);

        /*
         * Both sides are told, because both sides are affected: the couple because
         * their money changed, the vendor because the package they are delivering
         * changed. Same path the page already uses for its transitions (bell +
         * queued email); the business name is trimmed because a vendor may have
         * typed a very long one and tblnotifications.message is varchar(255).
         */
        $who   = mb_substr(trim((string) $deal->business_name), 0, 60);
        $price = $was !== $amount ? ' (was ' . format_ksh($was) . ')' : '';
        $coupleUid = (int) ($deal->couple_user_id ?? 0);
        if ($coupleUid > 0) {
            Notification::create_for_couple(
                $coupleUid,
                (int) $deal->booking_id,
                'Your ' . $who . ' deal is now ' . mb_substr($newLabel, 0, 60) . ' at '
                    . format_ksh($amount) . $price . '. Your planner updated it.',
                'client_vendors.php?booking_id=' . (int) $deal->booking_id . '&user_id=' . $coupleUid
            );
        }
        /*
         * The vendor gets the package they are now delivering. The AMOUNT is left
         * out of their copy on purpose: the deal value is a figure between the
         * couple and the planner, and what the vendor has to act on is which
         * package the day is being served on.
         */
        $vendorUid = (int) ($deal->vendor_user_id ?? 0);
        if ($vendorUid > 0) {
            Notification::create_for_vendor(
                $vendorUid,
                (int) $deal->booking_id,
                'Your ' . $who . ' booking is now on ' . mb_substr($newLabel, 0, 80)
                    . '. Your dashboard has the details.',
                'vendor_home.php'
            );
        }

        /*
         * And the loop closes where it started: does THIS deal cover the plan now?
         * The re-price may have moved it onto a package that covers the crowd or
         * onto one that still does not — the person who just acted is the one
         * person who must not have to guess which, and the answer is scoped to the
         * deal they touched (other deals on the wedding are the sheet's business,
         * not this confirmation's).
         */
        /*
         * And the loop closes where it started: does THIS deal cover the plan now?
         * The re-price may have moved it onto a package that covers the crowd or
         * onto one that still does not — the person who just acted is the one
         * person who must not have to guess which, and the answer is scoped to the
         * deal they touched (other deals on the wedding are the sheet's business,
         * not this confirmation's). PackageCapacity::deal_position() is that one
         * deal's slice of the check the sheet runs, so the row and the sheet can
         * never disagree about what the deal covers.
         */
        $here = PackageCapacity::deal_position((int) $deal->booking_id, (int) $deal->id);

        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-cash-sync"></i></strong> '
            . htmlspecialchars(trim((string) $deal->business_name)) . ' re-priced to <strong>'
            . htmlspecialchars($newLabel) . '</strong> at ' . format_ksh($amount)
            . ' (commission ' . format_ksh($commission) . '). '
            . ($here === []
                ? 'It now covers the planned headcounts.'
                : htmlspecialchars(PackageCapacity::position($here)))
            . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        redirect_to($back);
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

    <?php if ($open_deal !== null): ?>
        <div class="card shadow-sm mb-3" style="border:none; border-radius:14px;" id="deal-<?= (int) $open_deal->id; ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap">
                    <div>
                        <h6 class="mb-1"><i class="mdi mdi-message-text-outline mr-1" style="color:#D97706"></i>
                            <?= htmlspecialchars(trim($open_deal->bride . ' & ' . $open_deal->groom)); ?>
                            <span class="text-muted" style="font-weight:400; font-size:12px;">with <?= htmlspecialchars((string) $open_deal->business_name); ?></span>
                        </h6>
                        <div class="text-muted" style="font-size:12px;">Three-way thread — the couple, the vendor and you. Anything written here is read by the other two immediately.</div>
                    </div>
                    <a href="<?= htmlspecialchars(VendorThread::landing($open_deal, VendorThread::PLANNER)); ?>" class="btn btn-sm btn-outline-secondary mt-1">
                        <i class="mdi mdi-chevron-up mr-1"></i> Close
                    </a>
                </div>
                <?php $vt = ['role' => VendorThread::PLANNER, 'deal' => $open_deal, 'messages' => $threads_by_deal[(int) $open_deal->id] ?? []]; ?>
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
        <a href="vendor_enquiries.php" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === '' && !$unread_filter ? 'active' : ''; ?>">All</a>
        <?php foreach ($valid_statuses as $st): ?>
            <a href="vendor_enquiries.php?status=<?= $st; ?><?= $unread_filter ? '&amp;unread=1' : ''; ?>" class="btn btn-sm btn-outline-secondary filter-chip <?= $status_filter === $st ? 'active' : ''; ?>"><?= ucfirst($st); ?> (<?= $tally[$st]; ?>)</a>
        <?php endforeach; ?>
        <?php if (VendorThread::installed()): ?>
            <?php
            /*
             * The queue filter: a toggle, so clicking the active chip puts the
             * whole ledger back. The current status stays on either way.
             */
            $unread_href = $unread_filter
                ? 'vendor_enquiries.php' . ($status_filter !== '' ? '?status=' . urlencode($status_filter) : '')
                : 'vendor_enquiries.php?' . http_build_query(
                    $status_filter !== '' ? ['status' => $status_filter, 'unread' => 1] : ['unread' => 1]
                );
            ?>
            <a href="<?= htmlspecialchars($unread_href); ?>"
               class="btn btn-sm btn-outline-secondary filter-chip <?= $unread_filter ? 'active' : ''; ?>"                title="<?= $unread_filter ? 'Show every deal again' : 'Only deals with messages you have not read yet, longest waiting first'; ?>">
                <i class="mdi mdi-email-outline mr-1"></i>Unread (<?= $unreadDeals; ?>)
                <?= $unread_filter ? '<i class="mdi mdi-close ml-1"></i>' : ''; ?>
            </a>
        <?php endif; ?>
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
                    <?php empty_state_row(7,
                        $unread_filter ? 'Nothing waiting' : 'No enquiries here',
                        $unread_filter
                            ? 'Every conversation on these weddings has been read. Switch the Unread chip off to see the whole ledger.'
                            : ($status_filter !== '' ? 'Nothing with this status — try another filter.' : 'Couple enquiries land here the moment they are sent from the marketplace.'),
                        $unread_filter ? 'mdi-email-outline' : 'mdi-handshake-outline'); ?>
                <?php else: ?>
                    <?php foreach ($enquiries as $e): [$badge, $icon] = $status_badges[$e->status]; ?>
                    <?php $is_focus = $focus_deal === (int) $e->id; ?>
                    <?php $clash = $clashes_for_deal($e); ?>
                    <?php $stage = VendorThread::stage($e, $threads_by_deal[(int) $e->id] ?? [], VendorThread::PLANNER); ?>
                    <?php $deal_unread = $unread_by_deal[(int) $e->id] ?? 0; ?>
                    <tr id="deal-<?= (int) $e->id; ?>" class="<?= $is_focus ? 'table-warning' : ''; ?>">
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars(trim($e->bride . ' & ' . $e->groom)); ?></strong>
                            <div style="font-size:11px; color:#64748B;">
                                <a href="client_manage_account_details.php?booking_id=<?= (int) $e->booking_id; ?>&user_id=<?= (int) $e->user_id; ?>">booking #<?= (int) $e->booking_id; ?></a>
                                · <a href="vendor_coverage.php?booking_id=<?= (int) $e->booking_id; ?>&user_id=<?= (int) $e->user_id; ?>">coverage</a>
                                <?= $e->account_status === 'confirm' ? '<span class="badge badge-success" style="font-size:9px;">confirmed</span>' : ($e->account_status === 'pending' ? '<span class="badge badge-warning" style="font-size:9px;">pending</span>' : ''); ?>
                            </div>
                        </td>
                        <td>
                            <strong style="font-size:13px;"><?= htmlspecialchars($e->business_name); ?></strong>
                            <div style="font-size:11px; color:#64748B;"><?= htmlspecialchars(ucfirst($e->category)); ?></div>
                        </td>
                        <td style="font-size:12.5px;"><?= $e->package_title !== null ? htmlspecialchars($e->package_title) : '<span class="text-muted">General enquiry</span>'; ?>
                            <?php if ($is_focus && $focus_note !== ''): ?>
                                <div class="text-danger" style="font-size:11px;">
                                    <i class="mdi mdi-alert-outline"></i> <?= htmlspecialchars($focus_note); ?>
                                </div>
                            <?php endif; ?>
                            <?php $scope = $deal_scope($e); ?>
                            <div style="font-size:11px;" class="<?= $scope['scope'] === 'missing' ? 'text-danger' : 'text-muted'; ?>">
                                <i class="mdi mdi-<?= $scope['scope'] === 'wedding' ? 'earth' : 'calendar-check'; ?>"></i>
                                Headcount brief: <?= htmlspecialchars(HeadcountBrief::scope_text($scope)); ?>
                            </div>
                        </td>
                        <td style="font-size:12.5px;"><?= !empty($e->event_date) ? htmlspecialchars(format_date_kd($e->event_date)) : '<span class="text-muted">—</span>'; ?></td>
                        <td style="font-size:12.5px;">
                            <?php if ((float) $e->amount > 0): ?>
                                <strong><?= format_ksh($e->amount, 2); ?></strong>
                                <div style="font-size:11px; color:#64748B;">commission <?= format_ksh($e->commission, 2); ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $badge; ?>"><i class="mdi mdi-<?= $icon; ?>"></i> <?= ucfirst($e->status); ?></span>
                            <?php /*
                                v3.3 — the reason a deal was dropped, in the words
                                of whoever dropped it. It is the difference between
                                "find another caterer" and "the 19th is gone, ask
                                about the 26th", which is a different phone call to
                                a different person — so it belongs on the row, not
                                buried in the deal's history. The planner reads
                                their own cancels back as "You said", the vendor's
                                as "Vendor said", because on a shared badge the
                                attribution IS the information.
                            */ ?>
                            <?php $dealWhy = (string) $e->status === 'cancelled' ? DealNote::text($e) : ''; ?>
                            <?php if ($dealWhy !== ''): ?>
                                <div class="mt-1" style="font-size:11px; line-height:1.4; color:#475569;">
                                    <i class="mdi mdi-quote-open"></i>
                                    <strong><?= DealNote::author($e) === DealNote::PLANNER ? 'You said' : 'Vendor said'; ?>:</strong>
                                    <?= htmlspecialchars($dealWhy); ?>
                                </div>
                            <?php endif; ?>
                            <a class="vt-glance" href="<?= htmlspecialchars(VendorThread::opener_url((int) $e->id, array_filter([
                                'status' => $status_filter !== '' ? $status_filter : null,
                                'unread' => $unread_filter ? 1 : null,
                            ]))); ?>"
                               style="text-decoration:none; color:<?= $stage['tone'] === 'action' ? '#065F46' : '#64748B'; ?>;"
                               title="<?= htmlspecialchars($stage['detail']); ?>">
                                <i class="mdi mdi-<?= htmlspecialchars($stage['icon']); ?>" style="font-size:13px;"></i>
                                <strong><?= htmlspecialchars($stage['label']); ?></strong>
                                <?php if ($deal_unread > 0): ?>
                                    <span class="vt-unread-pill"><?= $deal_unread; ?> new</span>
                                    <?php $waited = $waiting_by_deal[(int) $e->id][0] ?? null; ?>
                                    <?php if ($waited !== null): ?>
                                        <span class="vt-waiting" title="Waiting since <?= htmlspecialchars(format_date_kd($waited, true)); ?>">waiting <?= htmlspecialchars(VendorThread::waiting_for($waited)); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </a>
                            <?php if ($clash !== [] && in_array((string) $e->status, ['pending', 'confirmed'], true)): ?>
                                <!--
                                    v2.25 — this vendor is committed elsewhere on
                                    the deal's own day. Shown on the row, not only
                                    after a refusal, so the planner sees it while
                                    reading the ledger rather than while clicking.
                                -->
                                <div class="text-danger mt-1" style="font-size:11px; line-height:1.35;">
                                    <i class="mdi mdi-calendar-remove"></i>
                                    <?= htmlspecialchars(VendorAvailability::notice($clash, (string) $e->business_name)); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <!--
                                The deal's audit trail (confirmations, availability
                                flags, staff edits). Kept, but folded away: the
                                conversation above is where the people talk now.
                            -->
                            <details class="mb-1">
                                <summary style="font-size:11px; cursor:pointer; color:#64748B;">Deal history</summary>
                                <div class="enq-notes mt-1"><?= htmlspecialchars((string) $e->notes); ?></div>
                            </details>
                            <?php if ($e->status === 'pending'): ?>
                            <form method="post" action="vendor_enquiries.php<?= $filter_qs; ?>" class="d-flex align-items-center flex-wrap">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="enquiry_id" value="<?= (int) $e->id; ?>">
                                <input type="number" name="amount" class="form-control form-control-sm mr-1" style="max-width: 110px;"
                                       value="<?= $e->package_price > 0 ? (int) $e->package_price : ''; ?>" min="1" title="Agreed deal amount (KSh)" required>
                                <button type="submit" name="to" value="confirmed" class="btn btn-sm btn-success mr-1" title="Confirm at this amount (10% commission)">
                                    <i class="mdi mdi-check"></i> Confirm
                                </button>
                                <?php /*
                                    v3.3 — one optional line, read only when the
                                    press is Cancel: the couple and the vendor
                                    read the same grey badge, and "your planner
                                    found a better price" is the sentence that
                                    stops them ringing to ask why.
                                */ ?>
                                <input type="text" name="status_note" maxlength="<?= DealNote::MAX; ?>"
                                       class="form-control form-control-sm mr-1" style="max-width: 170px;"
                                       placeholder="Cancelling? Say why (optional)"
                                       aria-label="Reason for cancelling, shown to the couple and the vendor"
                                       title="Only used with Cancel — the couple and the vendor see your words">
                                <?php /*
                                    `formnovalidate` matters: the amount box above is
                                    `required` for Confirm, and a general enquiry has
                                    no package price to prefill it with — so without
                                    this, a Cancel on that row never left the browser
                                    ("please fill out this field") and the planner
                                    could not drop an enquiry at all. Cancelling reads
                                    no amount, so the browser has nothing to check.
                                */ ?>
                                <button type="submit" name="to" value="cancelled" formnovalidate class="btn btn-sm btn-outline-secondary" title="Cancel this enquiry">
                                    <i class="mdi mdi-close"></i> Cancel
                                </button>
                                <?php if ($clash !== []): ?>
                                    <!--
                                        The acknowledgement the server gate demands
                                        when this vendor is committed elsewhere that
                                        day. Not `required`: the same form also
                                        cancels, and a browser-side block would make
                                        dropping a clashing enquiry impossible.
                                    -->
                                    <label class="text-danger mb-0 ml-1" style="font-size:10.5px; line-height:1.3; max-width:220px;"
                                           title="Confirming while the vendor is committed to another wedding that day records the clash in the notes trail">
                                        <input type="checkbox" name="acknowledge_clash" value="1">
                                        Book them anyway — I know they are committed that day
                                    </label>
                                <?php endif; ?>
                            </form>
                            <?php elseif ($e->status === 'confirmed'): ?>
                            <form method="post" action="vendor_enquiries.php<?= $filter_qs; ?>">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="enquiry_id" value="<?= (int) $e->id; ?>">
                                <button type="submit" name="to" value="completed" class="btn btn-sm btn-primary" title="Mark delivered and paid">
                                    <i class="mdi mdi-certificate"></i> Mark completed
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($e->status !== 'cancelled'): ?>
                            <!--
                                Which functions this vendor's headcount brief covers
                                (v2.10). Set on the deal, where the deal is made.
                            -->
                            <form method="post" action="vendor_enquiries.php<?= $filter_qs; ?>" class="d-flex align-items-center mt-1">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="scope_deal_id" value="<?= (int) $e->id; ?>">
                                <select name="features_id" class="form-control form-control-sm mr-1" style="max-width: 165px;"
                                        title="Headcount brief: the functions this vendor hears about">
                                    <option value="0" <?= $scope['features_id'] === 0 ? 'selected' : ''; ?>>Whole wedding</option>
                                    <?php foreach ($functions_by_booking[(int) $e->booking_id] ?? [] as $fn): ?>
                                        <option value="<?= (int) $fn->features_id; ?>" <?= $scope['features_id'] === (int) $fn->features_id ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars(trim((string) $fn->title) !== '' ? (string) $fn->title : 'Ceremony'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        title="Save which functions this vendor is briefed on"><i class="mdi mdi-content-save"></i></button>
                            </form>
                            <?php if ($scope['scope'] === 'missing'): ?>
                                <div class="text-danger" style="font-size:11px;">
                                    Pinned to a function that is no longer on the calendar — this vendor reached
                                    nobody on the last change. Pick a function from the list to fix it.
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($e->status === 'confirmed' && !empty($packages_by_vendor[(int) $e->vendor_id])): ?>
                            <!--
                                The one step out of a capacity breach (v2.11): move the
                                deal onto another of THIS vendor's packages. Left empty,
                                the amount becomes the chosen package's price — the same
                                rule the confirm form above already applies — so picking a
                                package and saving is the whole action.
                            -->
                            <form method="post" action="vendor_enquiries.php<?= $filter_qs; ?>" class="d-flex align-items-center mt-1">
                                <?= $session->csrf_field(); ?>
                                <input type="hidden" name="reprice_deal_id" value="<?= (int) $e->id; ?>">
                                <select name="package_id" class="form-control form-control-sm mr-1" style="max-width: 190px;"
                                        title="Move this deal onto another package of this vendor">
                                    <option value="0" <?= $e->package_id === null ? 'selected' : ''; ?>>No package</option>
                                    <?php foreach ($packages_by_vendor[(int) $e->vendor_id] as $pkg): ?>
                                        <option value="<?= (int) $pkg->id; ?>" <?= (int) $e->package_id === (int) $pkg->id ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars(PackageCapacity::package_label(['title' => (string) $pkg->title, 'capacity' => (int) $pkg->capacity])); ?> · <?= format_ksh((float) $pkg->price); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="amount" class="form-control form-control-sm mr-1" style="max-width: 110px;"
                                       min="1" placeholder="package price"
                                       title="Agreed deal amount (KSh). Leave empty to use the package's price.">
                                <button type="submit" class="btn btn-sm btn-outline-primary"
                                        title="Re-price this deal onto the selected package"><i class="mdi mdi-cash-sync"></i> Re-price</button>
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
