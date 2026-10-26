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
     * Which month the availability calendar shows. A query parameter so the
     * arrows are plain links — no script, works with a phone keyboard or a
     * screen reader — and anything that is not a real YYYY-MM falls back to
     * this month rather than rendering a nonsense grid.
     */
    $cal_month_raw = clean($_GET['month'] ?? '');
    $cal_month     = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $cal_month_raw) === 1
        ? $cal_month_raw
        : date('Y-m');

    /*
     * A day opened from the calendar (?day=YYYY-MM-DD): the enquiry drawer for
     * that date, where a pending enquiry is answered without leaving the grid
     * the vendor was already reading. Validated as a REAL calendar date, not
     * just the shape — ?day=2026-02-31 is a link somebody typed, and it shows
     * the month rather than a drawer for a day that does not exist.
     */
    $cal_day_raw = clean($_GET['day'] ?? '');
    $cal_day     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cal_day_raw) === 1
        && checkdate(
            (int) substr($cal_day_raw, 5, 2),
            (int) substr($cal_day_raw, 8, 2),
            (int) substr($cal_day_raw, 0, 4)
        )
        ? $cal_day_raw
        : '';
    if ($cal_day !== '') {
        // The drawer's day decides which month is drawn, so the cell it came
        // from is always on screen — even if ?month= disagreed with it.
        $cal_month = substr($cal_day, 0, 7);
    }

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
        /*
         * The calendar drawer answers an enquiry from WHERE the vendor is
         * looking, so it returns them to the same month — accepting the last
         * enquiry on a day then shows that cell filled in, which is the proof
         * the press worked. The day itself is dropped on purpose: it is no
         * longer pending, and an emptied drawer is a worse answer than the grid.
         */
        $ret_month = clean($_POST['ret_month'] ?? '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ret_month) !== 1) {
            $ret_month = '';
        }
        $return_qs = $filter_qs . ($ret_month !== '' ? '&month=' . $ret_month : '');

        $id = (int)($_POST['booking_row_id'] ?? 0);
        $to = clean($_POST['to'] ?? '');
        if (!in_array($to, $valid_statuses, true)) {
            $session->message('<div class="alert alert-danger">Unknown status.</div>');
            redirect_to('vendor_home.php' . $return_qs);
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
            redirect_to('vendor_home.php' . $return_qs);
            die();
        }
        /*
         * The vendor's own words on a decline (v3.3). Optional, and read only on
         * the answer that needs explaining: a bare "no" leaves the couple and
         * their planner guessing whether it was the money, the distance, or a
         * date that was already gone — and the last one is still a booking, one
         * call to a sister studio away. It is stored on the deal so every
         * surface (their list, their plan, the planner's ledger) can show it in
         * the vendor's words, and echoed into the deal's notes trail so the
         * history keeps what was said even after the status moves on.
         */
        $declineReason = $to === 'cancelled'
            ? DealNote::clean((string) ($_POST['status_note'] ?? ''))
            : '';

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
                redirect_to('vendor_home.php' . $return_qs);
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
            /*
             * `status_note` is the answer that is CURRENT (DealNote): stamped by
             * a decline or a cancel, cleared by every other answer, so a stale
             * "already booked that day" can never sit beside a reopened or
             * completed deal. The dated notes line keeps the reason for good —
             * that is the part nothing clears.
             */
            $trailNote = '[' . date('j M Y') . ' — ' . $to . ' by ' . ($row->business_name) . ' (vendor)'
                . ($declineReason !== '' ? '; reason: "' . $declineReason . '"' : '') . ".]\n";
            $baseParams = $ownedParams + [
                ':st'   => $to,
                ':note' => $trailNote,
                ':id'   => $id,
            ];
            // The scope is CONCATENATED, not interpolated: a double-quoted
            // statement with {$ownedIn} inside is invisible to the static promise
            // sweep, which read `status_note` as "read but never written" when
            // this was one interpolated string.
            if (DealNote::installed() && DealNote::author_installed()) {
                $stmt = $db->prepare(
                    'UPDATE tblvendor_bookings
                        SET status = :st, status_note = :snote, status_note_by = :snote_by,
                            notes = CONCAT(:note, notes)
                      WHERE id = :id AND vendor_id IN (' . $ownedIn . ')'
                );
                // NULL, not '', so "no reason was given" is one value everywhere.
                $baseParams[':snote']    = $to === 'cancelled' && $declineReason !== '' ? $declineReason : null;
                $baseParams[':snote_by'] = $to === 'cancelled' && $declineReason !== '' ? DealNote::VENDOR : null;
            } elseif (DealNote::installed()) {
                // v3.3 without v3.3b: the reason still lands, attributed by default.
                $stmt = $db->prepare(
                    'UPDATE tblvendor_bookings
                        SET status = :st, status_note = :snote, notes = CONCAT(:note, notes)
                      WHERE id = :id AND vendor_id IN (' . $ownedIn . ')'
                );
                $baseParams[':snote'] = $to === 'cancelled' && $declineReason !== '' ? $declineReason : null;
            } else {
                // Pre-v3.3 install: the reason lives in the notes trail alone.
                $stmt = $db->prepare(
                    'UPDATE tblvendor_bookings
                        SET status = :st, notes = CONCAT(:note, notes)
                      WHERE id = :id AND vendor_id IN (' . $ownedIn . ')'
                );
            }
            $stmt->execute($baseParams);
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
            // The planner's next move depends on WHY — rebook elsewhere, or wait
            // for a date that is free — so the reason travels with the news,
            // clipped: tblnotifications.message is 255 characters and a 200-char
            // reason would push this sentence over it on a strict server.
            if ($declineReason !== '') {
                $plannerText .= ' They said: "' . DealNote::short($declineReason, 100) . '".';
            }
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
                : 'Habari! Your ' . $vendor->business_name . ' booking was cancelled.'
                    . ($declineReason !== '' ? ' They said: "' . mb_substr($declineReason, 0, 90) . '".' : '')
                    . ' Your planner will help you find a great alternative.';
            SMS::send_courtesy($cphone, $smsText, $plannerId, (int) $row->booking_id);
        }

        /*
         * The couple's bell as well as their phone (v3.3). The SMS is best-effort
         * and a dead number cannot fail loudly, while the reason is the whole
         * point of this answer — so it also lands in the feed, linking to the
         * enquiries list where the vendor's words sit under the badge.
         */
        if ($to === 'cancelled' && (int) $row->couple_user_id > 0) {
            Notification::create_for_couple(
                (int) $row->couple_user_id,
                (int) $row->booking_id,
                $vendor->business_name . ' declined your enquiry.'
                    . ($declineReason !== '' ? ' They said: "' . DealNote::short($declineReason, 100) . '".' : '')
                    . ' Your planner can suggest an alternative.',
                'client_vendors.php?booking_id=' . (int) $row->booking_id . '&user_id=' . (int) $row->couple_user_id
            );
        }

        $session->message('<div class="alert alert-success"><strong><i class="mdi mdi-check"></i></strong> Booking marked <strong>' . $to . '</strong>.'
            . ($declineReason !== ''
                ? ' <span class="text-muted">The couple and their planner will see your reason.</span>'
                : '')
            . ($capacityNote !== ''
                ? ' <span class="text-danger"><i class="mdi mdi-alert-outline"></i> ' . htmlspecialchars($capacityNote) . '</span>'
                : '')
            . ($clash !== []
                ? ' <span class="text-danger"><i class="mdi mdi-calendar-remove"></i> ' . htmlspecialchars(VendorAvailability::alert($clash))
                    . ' Your couple\'s planner has been told.</span>'
                : '')
            . '</div>');
        redirect_to('vendor_home.php' . $return_qs);
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

    /**
     * @param object $b      a booking row (booking_id, vendor_id, ids)
     * @param string $day    an explicit Y-m-d when the caller already knows the
     *                       day — the calendar drawer does, because the cell the
     *                       vendor pressed IS the day being judged. Rows read for
     *                       the ledger carry the dates themselves and pass ''.
     * @return object[] the other weddings committed to that day
     */
    $clash_for = static function (object $b, string $day = '') use ($availability): array {
        if ($day === '') {
            $day = VendorAvailability::day($b->event_date ?? null, $b->function_start ?? null, $b->wedding_date ?? null);
        }
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

    /*
     * The band under the ledger answers the two questions a vendor asks after
     * reading it: what is actually AHEAD of me, and what is the book worth?
     *
     * Its own query, deliberately NOT narrowed by the chips above: switching the
     * ledger to "Unread" must not make next month's job vanish from the calendar
     * and then reappear when the chip is cleared. Only pending and confirmed
     * rows with a date are read — a declined job is not coming up.
     */
    $jobStmt = $db->prepare(
        "SELECT vb.id, vb.booking_id, vb.status, vb.amount, vb.vendor_id, vb.features_id,
                COALESCE(vb.event_date, b.wedding_date) AS job_day,
                p.title AS package_title, p.price AS package_price,
                b.bride, b.groom, e.title AS function_title, v.business_name
           FROM tblvendor_bookings vb
      LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
           JOIN tblweddingbook b ON b.booking_id = vb.booking_id
           JOIN tblvendors v ON v.id = vb.vendor_id
      LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
          WHERE vb.vendor_id IN ({$ownedIn})
            AND vb.status IN ('pending', 'confirmed')
       ORDER BY job_day ASC"
    );
    $jobStmt->execute($ownedParams);

    $vendorJobs           = [];
    $vendorPendingRows    = [];
    $vendorPendingDays    = [];
    $vendorConfirmedValue = 0.0;
    $vendorPendingValue   = 0.0;
    $vendorNext30         = 0;
    foreach ($jobStmt->fetchAll(PDO::FETCH_OBJ) as $jobRow) {
        $jobAmount = (float) ($jobRow->amount ?? 0);
        if ($jobAmount > 0) {
            if ((string) $jobRow->status === 'confirmed') {
                $vendorConfirmedValue += $jobAmount;
            } else {
                $vendorPendingValue += $jobAmount;
            }
        }
        $jobDay = (string) ($jobRow->job_day ?? '');
        if ($jobDay === '' || strtotime($jobDay) === false) {
            continue;
        }
        $jobDay  = date('Y-m-d', strtotime($jobDay));
        if ((string) $jobRow->status === 'pending') {
            // A pending enquiry is not a booking, but the vendor still wants to
            // see which days are being ASKED for while they decide — and the
            // drawer under the grid answers them from there, so the row itself
            // is kept, not just the count.
            $vendorPendingDays[$jobDay] = ($vendorPendingDays[$jobDay] ?? 0) + 1;
            $vendorPendingRows[$jobDay][] = $jobRow;
        }
        if ($jobDay < date('Y-m-d')) {
            continue; // behind us: the ledger already holds the history
        }
        $jobDays = (int) floor((strtotime($jobDay) - strtotime(date('Y-m-d'))) / 86400);
        if ($jobDays <= 30) {
            $vendorNext30++;
        }
        $vendorJobs[] = ['row' => $jobRow, 'date' => $jobDay, 'days' => $jobDays];
    }
    $vendorJobTotal = count($vendorJobs);
    $vendorJobs     = array_slice($vendorJobs, 0, 5);

    /*
     * The month grid. Committed days come from VendorAvailability::commitments()
     * — the same index the clash flags on the ledger use — so "booked" here and
     * "you are also booked" on a row can never mean different things. Pending
     * days come from the enquiry query above: they are not commitments, and the
     * legend says so.
     */
    $vendorBookedDays = [];
    foreach ($ownedIds as $ownedVendorId) {
        foreach ($availability[(int) $ownedVendorId] ?? [] as $bookedDay => $bookedRows) {
            foreach ($bookedRows as $bookedRow) {
                $vendorBookedDays[$bookedDay][] = $bookedRow;
            }
        }
    }

    /*
     * The day drawer (?day=): the pending enquiries on that day and the jobs
     * already committed to it, so accepting or declining is one press from the
     * cell that raised the question. Rows come from the same job query the grid
     * counted, so a cell's "2 enquiries" and the drawer's list can never
     * disagree — and the drawer is empty for a day nobody is asking about.
     */
    $calDayRows = $cal_day !== '' ? ($vendorPendingRows[$cal_day] ?? []) : [];
    $calDayJobs = $cal_day !== '' ? ($vendorBookedDays[$cal_day] ?? []) : [];
    if ($calDayRows === [] && $calDayJobs === []) {
        // Nothing to say about an empty day: a ring around a cell with no drawer
        // under it is a dead end, so a day nobody is asking about is just a day.
        $cal_day = '';
    }

    // Two hrefs, built from the current view, so the filter chips and the
    // listing being read survive both opening a day and closing it again.
    $calDayHref = static fn(string $day): string => 'vendor_home.php?' . http_build_query(
        array_merge($filter_params, ['month' => substr($day, 0, 7), 'day' => $day])
    );
    $calMonthHref = static fn(string $month): string => 'vendor_home.php?' . http_build_query(
        array_merge($filter_params, ['month' => $month])
    );

    $calFirstTs = strtotime($cal_month . '-01');
    $calDays    = (int) date('t', $calFirstTs);
    $calLead    = (int) date('w', $calFirstTs);      // Sunday-first grid
    $calCells   = (int) ceil(($calLead + $calDays) / 7) * 7;
    $calToday   = date('Y-m-d');
    $calTitle   = date('F Y', $calFirstTs);
    $calPrevHref = 'vendor_home.php?' . http_build_query(array_merge($filter_params, [
        'month' => date('Y-m', strtotime($cal_month . '-01 -1 month')),
    ]));
    $calNextHref = 'vendor_home.php?' . http_build_query(array_merge($filter_params, [
        'month' => date('Y-m', strtotime($cal_month . '-01 +1 month')),
    ]));
    $calThisHref = 'vendor_home.php?' . http_build_query($filter_params);

    /*
     * The one-line answer the grid exists for: how full is this month, and when
     * is the next Saturday that is still free? Scoped to the eight weeks ahead
     * because "first free Saturday in 2029" is not a useful sentence.
     */
    $calBookedCount  = 0;
    $calPendingCount = 0;
    for ($calD = 1; $calD <= $calDays; $calD++) {
        $calKey = $cal_month . '-' . str_pad((string) $calD, 2, '0', STR_PAD_LEFT);
        if (($vendorBookedDays[$calKey] ?? []) !== []) {
            $calBookedCount++;
        }
        $calPendingCount += (int) ($vendorPendingDays[$calKey] ?? 0);
    }
    $calNextFreeSaturday = null;
    for ($calAhead = 0; $calAhead < 56; $calAhead++) {
        $calStamp = strtotime('+' . $calAhead . ' day');
        if ((int) date('w', $calStamp) !== 6) {
            continue;
        }
        $calKey = date('Y-m-d', $calStamp);
        if (($vendorBookedDays[$calKey] ?? []) === []) {
            $calNextFreeSaturday = $calKey;
            break;
        }
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
        /*
         * The band under the ledger. Same card language as the rest of the
         * console (white surface, one hairline rule, stone hierarchy) so it
         * reads as part of the page rather than a second dashboard.
         */
        .band-card { background: #fff; border: 1px solid var(--stone-200);
                     border-radius: var(--radius-card); box-shadow: var(--shadow-soft);
                     overflow: hidden; }
        .cal-foot { margin-top: 11px; padding-top: 10px; border-top: 1px solid var(--stone-100);
                    display: flex; flex-direction: column; gap: 4px;
                    font-size: 11.5px; color: var(--text-muted); }
        .cal-foot strong { color: var(--text-body); font-weight: 600; }
        .band-head { padding: 13px 18px; border-bottom: 1px solid var(--stone-100); }
        .band-head h6 { margin: 0; font-size: 10.5px; font-weight: 700; letter-spacing: .09em;
                        text-transform: uppercase; color: var(--text-muted); }
        .band-row { display: flex; align-items: center; gap: 12px; padding: 11px 18px;
                    border-top: 1px solid var(--stone-100); text-decoration: none !important; color: inherit; }
        .band-row:first-child { border-top: 0; }
        .band-row:hover { background: var(--brand-50); color: inherit; }
        .band-row .who { min-width: 0; flex: 1 1 auto; }
        .band-row .who strong { display: block; font-size: 13px; font-weight: 600; color: var(--text-strong);
                                white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .band-row .who span { font-size: 11.5px; color: var(--text-muted); }
        .band-meta { flex: 0 0 auto; text-align: right; font-size: 11.5px; color: var(--text-muted); }
        .band-meta strong { display: block; font-size: 12.5px; color: var(--text-body); font-variant-numeric: tabular-nums; }
        .band-stats { padding: 4px 18px 14px; }
        /* The money block sits under the job list, so it reads two-up instead of
           as four full-width rows that would run the card past the calendar. */
        .band-stats.is-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 22px; padding: 8px 18px 14px; }
        .band-stats.is-grid .band-stat { display: block; padding: 7px 0; border-bottom: 0; }
        .band-stats.is-grid .band-stat span { display: block; font-size: 11px; line-height: 1.35; }
        .band-stats.is-grid .band-stat strong { display: block; font-size: 15px; margin-top: 2px; }
        .band-stat { display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
                     padding: 9px 0; border-bottom: 1px solid var(--stone-100);
                     font-size: 12.5px; color: var(--text-muted); }
        .band-stat:last-child { border-bottom: 0; }
        .band-stat strong { font-size: 13.5px; color: var(--text-strong); font-variant-numeric: tabular-nums; }
        /* Availability calendar: one month, seven columns, no script. */
        .cal-wrap { padding: 12px 16px 14px; }
        .cal-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
        .cal-nav strong { font-size: 12.5px; font-weight: 700; color: var(--text-strong); }
        .cal-nav a { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center;
                     border: 1px solid var(--stone-200); border-radius: 8px; color: var(--text-muted);
                     text-decoration: none !important; font-size: 15px; line-height: 1; }
        .cal-nav a:hover { background: var(--brand-50); border-color: var(--brand-100); color: var(--brand-700); }
        /* Viewing another month: the title itself is the way back to this one,
           so the control sits where the eye already is. */
        .cal-nav a.cal-now { width: auto; height: auto; border: 0; background: none; padding: 0;
                             font-size: 12.5px; font-weight: 700; color: var(--text-strong);
                             text-decoration: underline dotted; }
        .cal-nav a.cal-now:hover { background: none; color: var(--brand-700); }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .cal-dow { text-align: center; font-size: 9.5px; font-weight: 700; letter-spacing: .08em;
                   color: var(--text-muted); padding-bottom: 3px; }
        .cal-cell { height: 32px; display: flex; align-items: center; justify-content: center;
                    border-radius: 8px; font-size: 11.5px; color: var(--text-body);
                    text-decoration: none !important; font-variant-numeric: tabular-nums; }
        a.cal-cell:hover { background: var(--brand-50); color: var(--brand-900); }
        .cal-cell.is-blank { visibility: hidden; }
        /* Committed: brand-700 with white text — 5.2:1, the same pair the
           primary buttons use, because a day cell is small text. */
        .cal-cell.is-booked { background: var(--brand-700); color: #fff; font-weight: 700; }
        a.cal-cell.is-booked:hover { background: var(--brand-900); color: #fff; }
        .cal-cell.is-pending { background: var(--brand-50); color: var(--brand-900); font-weight: 600;
                               box-shadow: inset 0 0 0 1px var(--brand-300); }
        .cal-cell.is-today { box-shadow: inset 0 0 0 2px var(--stone-900); }
        .cal-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 9px;
                      font-size: 10.5px; color: var(--text-muted); }
        .cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
        .cal-key { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
        .cal-key.booked { background: var(--brand-700); }
        .cal-key.pending { background: var(--brand-50); box-shadow: inset 0 0 0 1px var(--brand-300); }
        .cal-key.today { background: #fff; box-shadow: inset 0 0 0 2px var(--stone-900); }
        /* The day that is open: a dark inner rule on a free day, a white one on
           a booked cell (where the brand fill would swallow a dark ring). */
        .cal-cell.is-focus { box-shadow: inset 0 0 0 2px var(--stone-900); }
        .cal-cell.is-booked.is-focus { box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .9); }
        /*
         * The day drawer. It sits inside the calendar card on purpose: answering
         * an enquiry must not cost the vendor the month they were reading, and
         * the cell behind it stays visible as the "is-focus" ring.
         */
        .cal-day { margin-top: 10px; padding: 10px 11px 4px; border-radius: 10px;
                   background: var(--stone-50); border: 1px solid var(--stone-200); }
        .cal-day-head { display: flex; align-items: center; gap: 7px; margin-bottom: 8px; }
        .cal-day-head strong { font-size: 12px; font-weight: 700; color: var(--text-strong); }
        .cal-day-head span { font-size: 11px; color: var(--text-muted); }
        .cal-day-head a.cal-day-close { margin-left: auto; width: 22px; height: 22px; flex: 0 0 22px;
                                        display: inline-flex; align-items: center; justify-content: center;
                                        border-radius: 6px; color: var(--text-muted);
                                        text-decoration: none !important; font-size: 15px; line-height: 1; }
        .cal-day-head a.cal-day-close:hover { background: #fff; color: var(--text-strong); }
        .cal-day-booked { margin: 0 0 8px; font-size: 11px; line-height: 1.5; color: var(--text-muted); }
        .cal-day-booked a { color: var(--brand-700); text-decoration: underline dotted; }
        .cal-day-clash { margin: 5px 0 0; font-size: 10.5px; line-height: 1.45; color: #B42318; }
        .cal-day-item { margin-bottom: 6px; padding: 9px 10px; background: #fff;
                        border: 1px solid var(--stone-200); border-radius: 9px; }
        .cal-day-who strong { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
        .cal-day-who span { font-size: 11px; color: var(--text-muted); }
        /* The decision itself: amount, yes, no — one row, wrapping on a phone. */
        .cal-day-act { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 7px; }
        .cal-day-act input[type=number] { width: 96px; height: 28px; font-size: 12px; }
        /* v3.3 — the reason sits under the decision, not beside it: it is
           optional, and pressing Accept must not look like it needs one. */
        .cal-day-act .cal-day-why { flex: 1 1 100%; height: 28px; font-size: 11.5px; }
        .cal-day-act .btn { font-size: 11px; padding: 3px 10px; }
        .cal-day-link { display: inline-block; margin-top: 7px; font-size: 11px;
                        color: var(--brand-700); text-decoration: none !important; }
        .cal-day-link:hover { text-decoration: underline !important; }

        @media (max-width: 575.98px) {
            .band-row { padding: 11px 14px; flex-wrap: wrap; }
            .band-meta { text-align: left; }
            .band-head, .band-stats { padding-left: 14px; padding-right: 14px; }
        }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="container-fluid mt-3">
    <?php
    /*
     * The vendor's hero answers two questions before the ledger does: when is
     * my next job, and is anyone waiting on me? Both come from rows already
     * loaded for this page, so the dashboard costs no extra queries.
     */
    $vendorNextEvent = null;
    foreach ($bookings as $nextRow) {
        $candidate = (string) ($nextRow->event_date ?? '');
        if ($candidate !== '' && strtotime($candidate) !== false && substr($candidate, 0, 10) >= date('Y-m-d')) {
            if ($vendorNextEvent === null || $candidate < $vendorNextEvent) {
                $vendorNextEvent = $candidate;
            }
        }
    }
    $vendorDays = $vendorNextEvent !== null
        ? (int) floor((strtotime($vendorNextEvent) - strtotime(date('Y-m-d'))) / 86400)
        : null;
    $vendorPending = (int) $tally['pending'];
    ?>
    <section class="dash-hero" aria-labelledby="vendorHeroTitle">
        <div>
            <p class="dash-hero-eyebrow">
                <?= htmlspecialchars(format_date_kd(date('Y-m-d'))); ?>
                <?php if (!$vendor->is_approved): ?>
                    &middot; <span class="badge badge-warning" style="font-size:10px;">pending approval</span>
                <?php endif; ?>
            </p>
            <h1 class="dash-hero-title" id="vendorHeroTitle"><?= htmlspecialchars((string) $vendor->business_name); ?></h1>
            <p class="dash-hero-sub"><?php if ($vendorPending > 0): ?><strong><?= $vendorPending; ?></strong> enquir<?= $vendorPending === 1 ? 'y' : 'ies'; ?> waiting on your answer<?php else: ?>No enquiry is waiting on you<?php endif; ?><?php if ($vendorDays !== null): ?> &middot; next event <?= $vendorDays === 0 ? 'today' : ($vendorDays === 1 ? 'tomorrow' : 'in ' . $vendorDays . ' days'); ?> (<?= htmlspecialchars(format_date_kd($vendorNextEvent)); ?>)<?php endif; ?>.</p>
        </div>
        <div class="dash-hero-actions">
            <?php if ($unreadDeals > 0): ?>
                <a class="btn btn-primary" href="vendor_home.php?unread=1&amp;vendor_id=<?= (int) $vendor->id; ?>">
                    <i class="mdi mdi-email-outline mr-1"></i><?= (int) $unreadDeals; ?> unread message<?= $unreadDeals === 1 ? '' : 's'; ?>
                </a>
            <?php else: ?>
                <a class="btn btn-primary" href="vendor_home.php?status=pending&amp;vendor_id=<?= (int) $vendor->id; ?>">
                    <i class="mdi mdi-clock-outline mr-1"></i>Open enquiries
                </a>
            <?php endif; ?>
            <a href="vendor_packages.php" class="btn btn-outline-secondary"><i class="mdi mdi-package-variant-closed mr-1"></i>My packages</a>
        </div>
    </section>

    <div class="mb-3 d-flex align-items-center flex-wrap" style="gap:10px;">
        <?php vendor_listing_switcher($myListings, (int) $vendor->id, 'vendor_home.php', array_filter([
            'status' => $status_filter !== '' ? $status_filter : null,
            'unread' => $unread_filter ? 1 : null,
        ])); ?>
        <a href="vendor_profile.php" class="btn btn-sm btn-outline-secondary ml-auto"><i class="mdi mdi-store mr-1"></i>My profile</a>
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
        <div class="col-md-3"><div class="stat-card"><h6>Pending</h6><div class="value"><?= $tally['pending']; ?></div><div class="hint">Awaiting your answer</div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--success)"><h6>Confirmed</h6><div class="value"><?= $tally['confirmed']; ?></div><div class="hint">Booked and ahead</div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--info)"><h6>Completed</h6><div class="value"><?= $tally['completed']; ?></div><div class="hint">Delivered</div></div></div>
        <div class="col-md-3"><div class="stat-card" style="border-left-color:var(--stone-500)"><h6>Cancelled</h6><div class="value"><?= $tally['cancelled']; ?></div><div class="hint">Declined or closed</div></div></div>
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
                            <?php /* v3.3 — the reason the deal was dropped, read back.
                                       Their own words are a promise the couple now has in
                                       writing; the planner's words are why the job went
                                       away and who to ring about it (v3.3b). */ ?>
                            <?php $ownReason = (string) $b->status === 'cancelled' ? DealNote::text($b) : ''; ?>
                            <?php if ($ownReason !== ''): ?>
                                <div class="text-muted mt-1" style="font-size:10.5px; line-height:1.35;">
                                    <i class="mdi mdi-quote-open"></i>
                                    <strong><?= DealNote::author($b) === DealNote::PLANNER ? 'Your planner said' : 'You said'; ?>:</strong>
                                    <?= htmlspecialchars($ownReason); ?>
                                </div>
                            <?php endif; ?>
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
                                        <input type="text" name="status_note" maxlength="<?= DealNote::MAX; ?>"
                                               class="form-control form-control-sm mr-1" style="max-width:190px;"
                                               placeholder="Declining? Say why (optional)"
                                               aria-label="Reason for declining, shown to the couple and their planner">
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

    <?php
    /*
     * WHAT'S COMING UP + YOUR BOOK AT A GLANCE.
     *
     * The ledger ends; a vendor's next question is "so what is ahead of me?".
     * The left card is the answer in date order, the right card is what the
     * book is worth and how much of it is still waiting on them. Both read
     * from the unfiltered job query prepared above, so the chips never change
     * what the future looks like.
     */
    ?>
    <div class="row mt-4 mb-2">
        <div class="col-lg-7 mb-3">
            <div class="band-card">
                <div class="band-head d-flex justify-content-between align-items-center">
                    <h6><i class="mdi mdi-calendar-clock mr-1"></i>What's coming up</h6>
                    <?php if ($vendorJobs !== []): ?>
                        <span class="badge badge-light" style="font-size:10px;">
                            <?= count($vendorJobs) < $vendorJobTotal
                                ? 'next ' . count($vendorJobs) . ' of ' . $vendorJobTotal
                                : 'next ' . count($vendorJobs); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($vendorJobs === []): ?>
                    <div class="band-stats">
                        <p class="hint mb-0" style="padding-top:8px;">
                            Nothing booked ahead yet. When you accept an enquiry it lands here with its date,
                            so you can see the next job at a glance.
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($vendorJobs as $job):
                        $jobRow    = $job['row'];
                        $jobDays   = (int) $job['days'];
                        $jobCouple = trim((string) $jobRow->bride . ' & ' . (string) $jobRow->groom);
                        if ($jobCouple === '' || $jobCouple === '&') {
                            $jobCouple = 'Couple';
                        }
                        // The function the deal is scoped to is the most useful
                        // label ("Reception"), then the package, then the day.
                        $jobWhat = trim((string) ($jobRow->function_title ?? ''));
                        if ($jobWhat === '') {
                            $jobWhat = trim((string) ($jobRow->package_title ?? ''));
                        }
                        if ($jobWhat === '') {
                            $jobWhat = 'Wedding';
                        }
                        $jobWhen = $jobDays === 0 ? 'today' : ($jobDays === 1 ? 'tomorrow' : 'in ' . $jobDays . ' days');
                        [$jobBadge, $jobIcon] = $status_badges[(string) $jobRow->status];
                    ?>
                        <a class="band-row" href="<?= htmlspecialchars(VendorThread::opener_url((int) $jobRow->id)); ?>"
                           title="Open your conversation on this job">
                            <span class="wedding-date">
                                <span class="d"><?= htmlspecialchars(date('d', strtotime($job['date']))); ?></span>
                                <span class="m"><?= htmlspecialchars(date('M', strtotime($job['date']))); ?></span>
                            </span>
                            <span class="who">
                                <strong><?= htmlspecialchars($jobCouple); ?></strong>
                                <span><?= htmlspecialchars($jobWhat); ?> &middot; <?= $jobWhen; ?></span>
                            </span>
                            <span class="band-meta">
                                <?php if ((float) $jobRow->amount > 0): ?>
                                    <strong><?= format_ksh((float) $jobRow->amount, 2); ?></strong>
                                <?php endif; ?>
                                <span class="pill <?= $jobBadge; ?>"><i class="mdi mdi-<?= $jobIcon; ?>"></i> <?= ucfirst((string) $jobRow->status); ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="band-card mt-3">
                <div class="band-head"><h6><i class="mdi mdi-chart-donut mr-1"></i>Your book at a glance</h6></div>
                <div class="band-stats is-grid">
                    <div class="band-stat">
                        <span>Confirmed, still ahead</span>
                        <strong><?= format_ksh($vendorConfirmedValue); ?></strong>
                    </div>
                    <div class="band-stat">
                        <span>Awaiting your answer (<?= (int) $tally['pending']; ?>)</span>
                        <strong><?= format_ksh($vendorPendingValue); ?></strong>
                    </div>
                    <div class="band-stat">
                        <span>Jobs in the next 30 days</span>
                        <strong><?= (int) $vendorNext30; ?></strong>
                    </div>
                    <div class="band-stat">
                        <span>Unread couple messages</span>
                        <strong><?= (int) $unreadDeals; ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5 mb-3">
            <div class="band-card">
                <div class="band-head"><h6><i class="mdi mdi-calendar-month mr-1"></i>Availability</h6></div>
                <div class="cal-wrap">
                    <div class="cal-nav">
                        <a href="<?= htmlspecialchars($calPrevHref); ?>" aria-label="Previous month">&lsaquo;</a>
                        <strong>
                            <?php if ($cal_month !== date('Y-m')): ?>
                                <a class="cal-now" href="<?= htmlspecialchars($calThisHref); ?>" title="Back to this month"
                                   aria-label="Back to this month"><?= htmlspecialchars($calTitle); ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($calTitle); ?>
                            <?php endif; ?>
                        </strong>
                        <a href="<?= htmlspecialchars($calNextHref); ?>" aria-label="Next month">&rsaquo;</a>
                    </div>
                    <div class="cal-grid" aria-label="Committed job days in <?= htmlspecialchars($calTitle); ?>">
                        <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $calDow): ?>
                            <span class="cal-dow" aria-hidden="true"><?= $calDow; ?></span>
                        <?php endforeach; ?>
                        <?php for ($calIdx = 0; $calIdx < $calCells; $calIdx++):
                            $calDayNum = $calIdx - $calLead + 1;
                            if ($calDayNum < 1 || $calDayNum > $calDays) {
                                echo '<span class="cal-cell is-blank" aria-hidden="true"></span>';
                                continue;
                            }
                            $calDateKey = $cal_month . '-' . str_pad((string) $calDayNum, 2, '0', STR_PAD_LEFT);
                            $calBooked  = $vendorBookedDays[$calDateKey] ?? [];
                            $calPending = (int) ($vendorPendingDays[$calDateKey] ?? 0);
                            $calClasses = 'cal-cell';
                            $calHref    = '';
                            $calNote    = '';
                            if ($calBooked !== []) {
                                $calClasses .= ' is-booked';
                                $calFirstDeal = $calBooked[0];
                                $calNote = 'booked: ' . $calFirstDeal->couple
                                    . ($calFirstDeal->function_title !== '' ? ' — ' . $calFirstDeal->function_title : '');
                                if (count($calBooked) > 1) {
                                    $calNote .= ' (+' . (count($calBooked) - 1) . ' more)';
                                }
                                // A booked day can also carry unanswered enquiries
                                // — the cell shows the booking, the tooltip says
                                // both, because capacity is the question here.
                                if ($calPending > 0) {
                                    $calNote .= ' · ' . $calPending . ' enquir' . ($calPending === 1 ? 'y' : 'ies') . ' pending';
                                }
                                // Booked days open the conversation on that job.
                                $calHref = VendorThread::opener_url((int) $calFirstDeal->deal_id);
                            } elseif ($calPending > 0) {
                                $calClasses .= ' is-pending';
                                $calNote = $calPending === 1
                                    ? 'new enquiry awaiting your answer'
                                    : $calPending . ' enquiries awaiting your answer';
                            }
                            /*
                             * A day with an unanswered enquiry opens the drawer,
                             * not the conversation: answering is what the tinted
                             * cell is asking for, and one press is the whole
                             * point. The drawer lists the day's committed jobs
                             * (with their conversations) beside the enquiry, so
                             * nothing is hidden by the swap. A booked day with
                             * nothing pending keeps opening its conversation.
                             */
                            if ($calPending > 0) {
                                $calHref = $calDayHref($calDateKey);
                                $calNote .= ' · open to answer';
                            }
                            if ($calDateKey === $calToday) {
                                $calClasses .= ' is-today';
                                $calNote = $calNote === '' ? 'today' : $calNote . ' · today';
                            }
                            if ($calDateKey === $cal_day) {
                                $calClasses .= ' is-focus';
                            }
                            $calLabel = date('D j M', strtotime($calDateKey));
                            $calTitleAttr = $calNote === '' ? $calLabel : $calLabel . ' — ' . $calNote;
                        ?>
                            <?php if ($calHref !== ''): ?>
                                <a class="<?= $calClasses; ?>" href="<?= htmlspecialchars($calHref); ?>"
                                   title="<?= htmlspecialchars($calTitleAttr); ?>"
                                   aria-label="<?= htmlspecialchars($calTitleAttr); ?>"><?= $calDayNum; ?></a>
                            <?php else: ?>
                                <span class="<?= $calClasses; ?>" title="<?= htmlspecialchars($calTitleAttr); ?>"><?= $calDayNum; ?></span>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                    <div class="cal-legend">
                        <span><i class="cal-key booked"></i>Booked</span>
                        <span><i class="cal-key pending"></i>Enquiry</span>
                        <span><i class="cal-key today"></i>Today</span>
                    </div>

                    <?php if ($cal_day !== '' && ($calDayRows !== [] || $calDayJobs !== [])): ?>
                        <div class="cal-day" id="cal-day">
                            <div class="cal-day-head">
                                <strong><?= htmlspecialchars(date('D j M', strtotime($cal_day))); ?></strong>
                                <span><?= $calDayRows !== []
                                    ? count($calDayRows) . ' enquir' . (count($calDayRows) === 1 ? 'y' : 'ies') . ' to answer'
                                    : 'nothing waiting'; ?></span>
                                <a href="<?= htmlspecialchars($calMonthHref($cal_month)); ?>" class="cal-day-close"
                                   aria-label="Close <?= htmlspecialchars(date('j M', strtotime($cal_day))); ?>"
                                   title="Close this day">&times;</a>
                            </div>

                            <?php if ($calDayJobs !== []): ?>
                                <?php /*
                                    * Stated as fact, not as a warning: a couple can
                                    * book two of this vendor's services on their
                                    * own day without any collision at all, so the
                                    * only thing this line may claim is what is
                                    * committed. The per-enquiry note below is the
                                    * place that judges a real clash.
                                    */ ?>
                                <p class="cal-day-booked">
                                    <i class="mdi mdi-calendar-check"></i>
                                    Committed that day:
                                    <?php
                                    // Rendered as a list so the sentence ends in a
                                    // full stop, not in a stray space before one.
                                    $calJobText = '';
                                    foreach ($calDayJobs as $calDayJob) {
                                        $calJobText .= ($calJobText !== '' ? ', ' : '')
                                            . '<a href="'
                                            . htmlspecialchars(VendorThread::opener_url((int) $calDayJob->deal_id))
                                            . '">' . htmlspecialchars($calDayJob->couple) . '</a>'
                                            . ($calDayJob->function_title !== ''
                                                ? ' (' . htmlspecialchars($calDayJob->function_title) . ')'
                                                : '');
                                    }
                                    echo $calJobText . '.';
                                    ?>
                                </p>
                            <?php endif; ?>

                            <?php foreach ($calDayRows as $calRow):
                                $calWho = trim((string) $calRow->bride . ' & ' . (string) $calRow->groom);
                                if ($calWho === '' || $calWho === '&') {
                                    $calWho = 'Couple';
                                }
                                $calWhat = trim((string) ($calRow->function_title ?? ''));
                                if ($calWhat === '') {
                                    $calWhat = trim((string) ($calRow->package_title ?? ''));
                                }
                                // Prefill with what was already agreed on the row,
                                // else the package's own price: the vendor is
                                // pressing Accept, not pricing from scratch.
                                $calDeal = (float) ($calRow->amount ?? 0) > 0
                                    ? (float) $calRow->amount
                                    : (float) ($calRow->package_price ?? 0);
                                // A genuine collision only: another WEDDING this
                                // vendor is committed to that day. The couple's
                                // own second service is not a clash, which is the
                                // same rule the ledger's rows follow.
                                $calRowClash = $clash_for($calRow, $cal_day);
                            ?>
                                <div class="cal-day-item">
                                    <div class="cal-day-who">
                                        <strong><?= htmlspecialchars($calWho); ?></strong>
                                        <span><?= htmlspecialchars($calWhat !== '' ? $calWhat : 'General enquiry'); ?></span>
                                    </div>
                                    <?php if ($calRowClash !== []): ?>
                                        <p class="cal-day-clash">
                                            <i class="mdi mdi-calendar-remove"></i>
                                            <?= htmlspecialchars(VendorAvailability::self_notice($calRowClash)); ?>
                                        </p>
                                    <?php endif; ?>
                                    <form method="post" action="vendor_home.php<?= htmlspecialchars($filter_qs); ?>" class="cal-day-act">
                                        <?= $session->csrf_field(); ?>
                                        <input type="hidden" name="booking_row_id" value="<?= (int) $calRow->id; ?>">
                                        <input type="hidden" name="ret_month" value="<?= htmlspecialchars($cal_month); ?>">
                                        <input type="number" name="amount" class="form-control form-control-sm"
                                               step="0.01" min="0" placeholder="Deal KSh"
                                               aria-label="Agreed deal amount for <?= htmlspecialchars($calWho); ?>"
                                               value="<?= $calDeal > 0 ? $calDeal : ''; ?>">
                                        <button type="submit" name="to" value="confirmed" class="btn btn-sm btn-success">
                                            <i class="mdi mdi-check"></i> Accept
                                        </button>
                                        <button type="submit" name="to" value="cancelled" class="btn btn-sm btn-outline-secondary">
                                            <i class="mdi mdi-close"></i> Decline
                                        </button>
                                        <input type="text" name="status_note" class="form-control form-control-sm cal-day-why"
                                               maxlength="<?= DealNote::MAX; ?>"
                                               placeholder="Declining? Say why (optional) — the couple and their planner see it"
                                               aria-label="Reason for declining, shown to the couple and their planner">
                                    </form>
                                    <a class="cal-day-link" href="<?= htmlspecialchars(VendorThread::opener_url((int) $calRow->id)); ?>">
                                        Read the enquiry <i class="mdi mdi-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="cal-foot">
                        <span>
                            <?php if ($calBookedCount === 0): ?>
                                <strong>No job booked in <?= htmlspecialchars($calTitle); ?></strong><?= $calPendingCount > 0 ? ' &middot; ' . $calPendingCount . ' enquir' . ($calPendingCount === 1 ? 'y' : 'ies') . ' pending' : ''; ?>
                            <?php else: ?>
                                <strong><?= $calBookedCount; ?> booked day<?= $calBookedCount === 1 ? '' : 's'; ?></strong> in <?= htmlspecialchars($calTitle); ?><?= $calPendingCount > 0 ? ' &middot; ' . $calPendingCount . ' enquir' . ($calPendingCount === 1 ? 'y' : 'ies') . ' pending' : ''; ?>
                            <?php endif; ?>
                        </span>
                        <span>
                            <?php if ($calNextFreeSaturday === null): ?>
                                Every Saturday in the next eight weeks is booked.
                            <?php else: ?>
                                Next free Saturday: <strong><?= htmlspecialchars(date('j M', strtotime($calNextFreeSaturday))); ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once 'include/footer.php'; ?>
</body>
</html>
