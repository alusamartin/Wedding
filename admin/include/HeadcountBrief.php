<?php
declare(strict_types=1);

/**
 * The headcount brief — who is told when a couple moves the numbers, and
 * exactly what they are told.
 *
 * WHY THIS EXISTS
 * The venue sheet's "Headcount plan per function" table is editable by the
 * couple (sheet_venue.php → admin/event_booking_save.php), and the numbers on
 * it are the brief the venue and the caterers work from. Before this, a couple
 * could move those numbers all day and nobody was told: the sheet is a page
 * only staff and the couple can open, so the venue saw a change only if a
 * planner noticed it and picked up the phone. This turns the couple's edit into
 * the notification the brief was always meant to be.
 *
 * WHEN THE PLAN OUTGROWS WHAT WAS SOLD
 * A vendor package states a guest capacity (tblvendor_packages.capacity) and a
 * deal records the package it was sold on (tblvendor_bookings.package_id), so a
 * function planned past that capacity is a deal that no longer covers the day.
 * include/PackageCapacity.php owns that check and its wording; this class owns
 * the sending, so the SAME save that says what moved also says which booked
 * package no longer covers it — to the planner and to the vendor holding it.
 * It is folded into the one message a recipient gets (see the capacity clause in
 * announce()), because two bells from one save for one account is noise a person
 * cannot act on. The planner's copy also carries the way out — the same vendor's
 * next package up and what it costs — as its own candidate string, so a bell that
 * runs out of room drops the suggestion before the warning. A STAFF save announces it too: the planner works this sheet all
 * day and their own correction must not be briefed as a change, but a package
 * that no longer covers the day is not a matter of who typed the number — see
 * announce_capacity().
 *
 * WHO IS TOLD
 *   - the PLANNER assigned to the booking (tblweddingbook.organizer_id),
 *   - the VENUE CONTACT: the venue-category vendor whose deal on this wedding is
 *     confirmed or completed (tblvendor_bookings + tblvendors.category =
 *     'venue'), and
 *   - every OTHER VENDOR booked on the wedding — the caterer, the decorator —
 *     whose deal is confirmed or completed.
 * A pending enquiry is an enquiry, not an assignment, so it is told nothing;
 * and a listing with no portal account (tblvendors.user_id 0/NULL) cannot be
 * reached at all, which the planner is told to pass on.
 *
 * SCOPE — a deal covers the functions it was made for
 * A venue hosts the whole day, so the venue contact gets the whole brief. Every
 * other vendor's deal names what they are booked for: tblvendor_bookings.
 * features_id holds the function (an `events` id of the same booking) a deal
 * covers, and NULL means the vendor was booked for the whole wedding. So a
 * caterer scoped to the reception hears about the reception and nothing else,
 * an unscoped caterer hears about every move, and a photographer booked for the
 * ceremony is never woken up by a change to the after-party. Who set that scope,
 * and where it is visible, is answered on the pages that own the deal
 * (admin/vendor_enquiries.php for staff, admin/vendor_home.php for the vendor)
 * and again on the sheet where the numbers live (admin/sheet_venue.php).
 *
 * ONE ALERT PER ACCOUNT
 * An account can hold several listings on the same wedding (the seed's vendor
 * login owns a caterer and a transport company). Each listing is a separate
 * recipient with its own scope, but a bell per listing per change is noise a
 * person cannot act on, so the lines every listing of one account is entitled
 * to arrive as ONE message. A vendor who is also the assigned planner hears
 * once, as the planner.
 *
 * A MESSAGE THAT FITS
 * tblnotifications.message is varchar(255), and on a non-strict MySQL a longer
 * body is cut off mid-number without a word. Every message here is fitted to
 * the column (fit()): the optional closing prose goes first, then the moved
 * functions are counted ("and 2 more moved functions") instead of half-printed.
 *
 * WHAT IS TOLD — only the functions whose numbers moved. A form that submits
 * every function is the shape this codebase already had to fix once (a save
 * used to re-stamp the package on rows nobody touched), so the brief names the
 * moved functions and nothing else: "Reception — family 20 → 25", not a
 * reprinted table. A function that gains its first plan is "now planned for",
 * and one cleared back to 0/0 is "no longer planned" — both are moves the venue
 * has to act on.
 *
 * HOW — Notification::create_for_staff()/create_for_vendor(), the bell plus
 * already uses for planner and vendor alerts. No SMS: the brief is a list, it
 * is not urgent to the minute, and a courtesy text is charged to the planner's
 * credits. The message body carries the numbers in full, so both channels are
 * self-sufficient — a recipient never has to open a page they cannot reach to
 * learn what changed. The link is chosen per role: the planner gets the sheet,
 * a vendor gets their own dashboard (vendors cannot open the sheet).
 *
 * The caller decides WHEN. Only a couple's own save announces the change: the
 * planner works this sheet all day and must not spam vendors with their own
 * corrections, and a save that changes nothing notifies nobody. A capacity
 * breach is announced on any save that creates or widens one
 * (announce_capacity()), because it is a fact about the deal rather than a
 * claim about who changed their mind.
 */
class HeadcountBrief
{
    /**
     * The in-app bell's message column is varchar(255) (tblnotifications), so a
     * brief that grew past it would be cut off mid-number by the database — with
     * the setting that hides it (non-strict sql_mode) doing so silently. Every
     * message this class writes is fitted to the column before it is stored.
     */
    private const MESSAGE_LIMIT = 255;
    /**
     * Who should hear about a change to this wedding's headcounts.
     *
     * @return array{planner: ?array{user_id:int,name:string},
     *               venue: ?array{user_id:int,name:string,features_id:int,scope:string,function_title:string},
     *               venue_on_file: bool}
     *         `venue` is the venue booked on the wedding; its `user_id` is 0 when
     *         that vendor has no portal account (a hand-inserted listing), in
     *         which case nobody can be sent to and the planner is told so.
     *         `venue_on_file` is shorthand for "a venue is booked here".
     */
    public static function recipients(int $booking_id): array
    {
        global $db;

        $out = ['planner' => null, 'venue' => null, 'venue_on_file' => false];
        if ($booking_id <= 0) {
            return $out;
        }

        $organizer_id = (int) $db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1',
            [':b' => $booking_id]
        );
        if ($organizer_id > 0) {
            $planner = Users::find_by_id($organizer_id);
            if ($planner) {
                $name = trim((string) ($planner->firstname ?? '') . ' ' . (string) ($planner->lastname ?? ''));
                $out['planner'] = ['user_id' => $organizer_id, 'name' => $name !== '' ? $name : 'your planner'];
            }
        }

        // The venue is the one on the wedding's calendar of vendors: a
        // venue-category listing with a deal that has been confirmed (or
        // delivered). Read from the same list the other vendors come from, so
        // the venue this page names and the venue the brief reaches are the
        // same row.
        foreach (self::booked_deals($booking_id) as $deal) {
            if ($deal['category'] !== 'venue') {
                continue;
            }
            $name = $deal['business_name'];
            $out['venue'] = [
                'user_id'        => $deal['user_id'],
                'name'           => $name !== '' ? $name : 'the venue',
                'features_id'    => $deal['features_id'],
                'scope'          => $deal['scope'],
                'function_title' => $deal['function_title'],
            ];
            $out['venue_on_file'] = true;
            break;
        }

        return $out;
    }

    /**
     * The wedding's assignments: every confirmed or completed deal, with the
     * function it covers.
     *
     * `scope` is how the deal reads to a person:
     *   - 'wedding'  — booked for the day (features_id NULL): every move is news;
     *   - 'function' — booked for one function (features_id names it);
     *   - 'missing'  — pinned to a function that is no longer on the booking's
     *                  calendar (the couple deleted it): it can never match a
     *                  change, which the planner is told to sort out by hand
     *                  rather than left to assume the vendor is covered.
     *
     * @return array<int, array{deal_id:int,vendor_id:int,business_name:string,category:string,
     *                          user_id:int,features_id:int,function_title:string,scope:string}>
     */
    public static function booked_deals(int $booking_id): array
    {
        global $db;
        if ($booking_id <= 0) {
            return [];
        }

        $rows = $db->fetchAll(
            "SELECT vb.id AS deal_id, vb.features_id, vb.status,
                    v.id AS vendor_id, v.user_id, v.business_name, v.category,
                    e.id AS function_id, e.title AS function_title
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
          LEFT JOIN events e ON e.id = vb.features_id AND e.booking_id = vb.booking_id
              WHERE vb.booking_id = :b
                AND vb.status IN ('confirmed', 'completed')
           ORDER BY vb.id",
            [':b' => $booking_id]
        );

        $out = [];
        foreach ($rows as $r) {
            $fid = $r->features_id !== null ? (int) $r->features_id : 0;
            $out[] = [
                'deal_id'        => (int) $r->deal_id,
                'vendor_id'      => (int) $r->vendor_id,
                'business_name'  => trim((string) $r->business_name),
                'category'       => (string) $r->category,
                'user_id'        => (int) ($r->user_id ?? 0),
                'features_id'    => $fid,
                'function_title' => $r->function_title !== null ? trim((string) $r->function_title) : '',
                'scope'          => $fid === 0 ? 'wedding' : ($r->function_id === null ? 'missing' : 'function'),
            ];
        }
        return $out;
    }

    /** How one deal's scope reads to the planner, the vendor and the couple. */
    public static function scope_text(array $deal): string
    {
        $scope = (string) ($deal['scope'] ?? 'wedding');
        if ($scope === 'missing') {
            return 'a function no longer on the calendar';
        }
        if ($scope === 'function') {
            $title = trim((string) ($deal['function_title'] ?? ''));
            return $title !== '' ? $title : 'one function';
        }
        return 'the whole wedding';
    }

    /**
     * One line per function whose numbers moved. Formatting only — no database,
     * no notification — so the wording is testable on its own.
     *
     * @param array<int, array{title:mixed,from:?array{family:int,individual:int},
     *                        to:array{family:int,individual:int}}> $moves
     * @return string[]
     */
    public static function lines(array $moves): array
    {
        $lines = [];
        foreach ($moves as $move) {
            $text = self::describe($move);
            if ($text !== null) {
                $lines[] = $text;
            }
        }
        return $lines;
    }

    /**
     * Tell the planner and every vendor booked on this wedding about the moved
     * functions their deal covers.
     *
     * @param array<int, array{title:mixed,features_id?:int,
     *                        from:?array{family:int,individual:int},
     *                        to:array{family:int,individual:int}}> $moves
     * @param array<string,mixed> $capacity     the breach copy for this save
     *                                           (PackageCapacity::clauses()): 'vendors' keyed by
     *                                           user_id with the sentence about that account's own
     *                                           package, 'planner_brief' (the bell's copy of the
     *                                           warning, without the count the moved line above
     *                                           already quotes — the room it frees is what lets
     *                                           'planner_upgrade' fit beside it), and the vendors
     *                                           nobody can reach. Empty when the plan still fits.
     * @return array{planner:bool,venue:bool,venue_name:string,venue_on_file:bool,venue_state:string,
     *               vendors:array<int,array{name:string,category:string,scope:string}>,
     *               unsent:array<int,array{name:string,category:string,why:string}>,
     *               lines:string[]}
     *         A summary the caller can turn into copy (notice()). The booleans
     *         say who was actually reached and `unsent` names the booked vendors
     *         nobody could be sent to, so a booking with no planner, no venue
     *         and no reachable vendor is visible rather than silently
     *         un-notified. `venue_state` is 'sent' | 'no_account' | 'none'.
     */
    public static function announce(int $booking_id, array $moves, array $capacity = []): array
    {
        $result = [
            'planner' => false, 'venue' => false, 'venue_name' => '', 'venue_on_file' => false,
            'venue_state' => 'none', 'vendors' => [], 'unsent' => [], 'lines' => [],
        ];

        $lines = self::lines($moves);
        if ($booking_id <= 0 || $lines === []) {
            return $result;
        }
        $result['lines'] = $lines;

        /*
         * The moved functions, in two shapes: $weddingLines for a vendor booked
         * for the whole day, and $byFunction for one scoped to a single function.
         * A move the caller could not key (a features_id it did not send) is
         * still news for a whole-wedding vendor and can never match a scoped one.
         */
        $weddingLines = [];
        $byFunction   = [];
        foreach ($moves as $move) {
            $text = self::describe($move);
            if ($text === null) {
                continue;
            }
            $weddingLines[] = $text;
            $fid = (int) ($move['features_id'] ?? 0);
            if ($fid > 0 && !isset($byFunction[$fid])) {
                $byFunction[$fid] = $text;
            }
        }

        $recipients = self::recipients($booking_id);
        $planner_id = (int) ($recipients['planner']['user_id'] ?? 0);
        $venue      = $recipients['venue'];
        $venue_id   = (int) ($venue['user_id'] ?? 0);
        $venue_name = (string) ($venue['name'] ?? '');

        $result['venue_on_file'] = $recipients['venue_on_file'];
        $result['venue_name']    = $venue_name;
        if ($venue !== null) {
            $result['venue_state'] = $venue_id > 0 ? 'sent' : 'no_account';
        }

        $couple = self::couple_label($booking_id);
        $sheet  = 'sheet_venue.php?booking_id=' . $booking_id;

        /*
         * One entry per ACCOUNT, not per listing: the lines a person can act on,
         * and the deals they came from. The venue is handled as the venue
         * contact (the whole brief), so the first venue-category deal on the
         * wedding is skipped here.
         */
        $accounts   = [];
        $unsent     = [];
        $venue_seen = false;

        foreach (self::booked_deals($booking_id) as $deal) {
            $is_venue = false;
            if ($deal['category'] === 'venue' && !$venue_seen) {
                $is_venue   = true;
                $venue_seen = true;
            }

            $covered = $is_venue
                ? $weddingLines
                : self::covered_lines($deal, $byFunction, $weddingLines);

            if ($covered === []) {
                // Scoped to a function that no longer exists: the planner believes
                // this vendor is covered, and they never can be again.
                if ($deal['scope'] === 'missing') {
                    $unsent[] = ['name' => $deal['business_name'], 'category' => $deal['category'], 'why' => 'missing_function'];
                }
                continue;
            }

            if ($deal['user_id'] <= 0) {
                // Nobody to send to. The venue's own case is reported through
                // venue_on_file (the planner's "pass this on to them"), so it is
                // not repeated here.
                if (!$is_venue) {
                    $unsent[] = ['name' => $deal['business_name'], 'category' => $deal['category'], 'why' => 'no_portal'];
                }
                continue;
            }

            $uid = $deal['user_id'];
            if (!isset($accounts[$uid])) {
                $accounts[$uid] = ['lines' => [], 'names' => [], 'scopes' => [], 'category' => $deal['category'], 'whole' => false, 'is_venue' => false];
            }
            foreach ($covered as $text) {
                if (!in_array($text, $accounts[$uid]['lines'], true)) {
                    $accounts[$uid]['lines'][] = $text;
                }
            }
            if ($deal['business_name'] !== '' && !in_array($deal['business_name'], $accounts[$uid]['names'], true)) {
                $accounts[$uid]['names'][] = $deal['business_name'];
            }
            if ($is_venue || $deal['scope'] !== 'function') {
                $accounts[$uid]['whole'] = true;
            } else {
                $accounts[$uid]['scopes'][] = self::scope_text($deal);
            }
            if ($is_venue) {
                $accounts[$uid]['is_venue'] = true;
            }
        }

        // ---- the planner: the list, plus who else has it ---------------------
        if ($planner_id > 0) {
            $venueClause = 'No venue is booked on this wedding yet.';
            if ($venue !== null && $venue_id === 0) {
                $venueClause = 'The venue on file (' . $venue_name . ') has no portal account, so pass this on to them.';
            } elseif ($venue !== null) {
                // The venue needs no naming here: one is booked, this planner put
                // it there, and the sheet names it. Length is the point — see fit().
                $venueClause = 'The venue has the same list.';
            }

            $also = [];
            foreach ($accounts as $uid => $acct) {
                if ($uid === $planner_id || $uid === $venue_id) {
                    continue; // the planner is reading it; the venue is the clause above
                }
                foreach ($acct['names'] as $name) {
                    $also[] = $name;
                }
            }

            $base = [
                $venueClause
                    . ($also !== [] ? ' Also sent to ' . self::join_names(self::capped($also)) . '.' : '')
                    . ($unsent !== [] ? ' ' . self::unsent_notice($unsent) : ''),
                $venueClause . ($unsent !== [] ? ' ' . self::unsent_notice($unsent) : ''),
                $venueClause,
                'The venue sheet has the rest.',
            ];

            /*
             * A capacity breach outranks the closing prose. Every candidate then
             * carries the warning, and the last one is the warning ALONE — so a
             * message that runs out of room drops the moved functions (fit()
             * counts them: "and 2 more moved functions") rather than the fact that
             * a package no longer covers the day, which is the one thing here
             * that needs a phone call today. The way out (the next package up and
             * what it costs) is the longest candidate of each pair and is dropped
             * first: it is only useful once the warning has been read.
             */
            $planner_clause  = trim((string) ($capacity['planner_brief'] ?? $capacity['planner'] ?? ''));
            $planner_upgrade = trim((string) ($capacity['planner_upgrade'] ?? ''));
            /*
             * A breaching function is printed ONCE, by the warning. The warning
             * names the function and the count it now holds ("After-Party is
             * planned for 254 — over the package …"), so its moved line is the
             * same fact a second time — and message is varchar(255): on the seed
             * that line is exactly what stands between the planner reading the way
             * out of the breach and having to open the sheet to find it. Functions
             * the warning does not speak about keep their lines, and nothing is
             * lost: the sheet prints every function's split, and the vendor's own
             * copy still carries the one their deal covers.
             */
            $planner_lines = self::without_breaches($lines, $moves, (array) ($capacity['features'] ?? []));
            if ($planner_clause === '') {
                $tiers = $base;
            } else {
                $with_way_out = $planner_clause . ($planner_upgrade !== '' ? ' ' . $planner_upgrade : '');
                $tiers = [];
                foreach ($base as $tier) {
                    $tiers[] = $with_way_out . ' ' . $tier;
                }
                if ($with_way_out !== $planner_clause) {
                    $tiers[] = $with_way_out; // the way out outranks the closing prose
                }
                foreach ($base as $tier) {
                    $tiers[] = $planner_clause . ' ' . $tier;
                }
                $tiers[] = $planner_clause;
            }

            Notification::create_for_staff(
                $planner_id,
                $booking_id,
                self::fit($couple . ' changed the planned headcounts: ', $planner_lines, $tiers),
                $sheet,
                'Headcount change — ' . $couple
            );
            $result['planner'] = true;
        }

        // ---- every reachable account: its own lines, one message ------------
        foreach ($accounts as $uid => $acct) {
            if ($uid === $planner_id) {
                $result['planner'] = true; // an account that is also the planner hears once
                continue;
            }
            if ($acct['lines'] === []) {
                continue;
            }

            $scopeNote = $acct['whole'] || $acct['scopes'] === []
                ? 'These are the numbers for your function(s) — your dashboard has the booking.'
                : 'Your deal covers ' . self::join_names(self::capped(array_values(array_unique($acct['scopes']))))
                    . ' — this is what moved there. Your dashboard has the booking.';

            // The package this vendor is booked on, when the numbers just moved
            // past it (PackageCapacity). Folded in rather than sent as a second
            // bell: it is about the same move, and it is the reason the move
            // matters to them. Same rule as the planner's: the warning outranks
            // the prose, so the last candidate is the warning on its own.
            $capacityClause = trim((string) ($capacity['vendors'][$uid]['text'] ?? ''));
            if ($capacityClause === '') {
                $tails = [$scopeNote, 'Your dashboard has the booking.'];
            } else {
                $tails = [
                    $capacityClause . ' ' . $scopeNote,
                    $capacityClause . ' Your dashboard has the booking.',
                    $capacityClause,
                ];
            }

            Notification::create_for_vendor(
                $uid,
                $booking_id,
                self::fit(
                    'Headcount change for ' . $couple . '\'s wedding: ',
                    $acct['lines'],
                    $tails
                ),
                'vendor_home.php',
                'Headcount change — ' . $couple
            );

            if ($acct['is_venue']) {
                $result['venue'] = true;
            }
            foreach ($acct['names'] as $name) {
                // The venue listing is reported as the venue, once — an account
                // that also sells coffee for the day is still two recipients.
                if ($name === $result['venue_name'] && $result['venue']) {
                    continue;
                }
                $result['vendors'][] = [
                    'name'     => $name,
                    'category' => (string) $acct['category'],
                    'scope'    => $acct['whole'] ? 'the whole wedding' : implode(', ', $acct['scopes']),
                ];
            }
        }

        foreach ($unsent as $entry) {
            $result['unsent'][] = $entry;
        }

        return $result;
    }

    /**
     * What to tell the person who made the change. Says plainly when nobody
     * could be reached: an edit that silently notifies no one is exactly the
     * "no actor" dead end this release is closing.
     */
    public static function notice(array $result): string
    {
        $planner = !empty($result['planner']);
        $venue   = !empty($result['venue']);
        $name    = trim((string) ($result['venue_name'] ?? ''));
        $state   = (string) ($result['venue_state']
            ?? ($venue ? 'sent' : (!empty($result['venue_on_file']) ? 'no_account' : 'none')));

        $vendorNames = [];
        foreach ((array) ($result['vendors'] ?? []) as $vendor) {
            $vendorName = trim((string) ($vendor['name'] ?? ''));
            if ($vendorName !== '' && !in_array($vendorName, $vendorNames, true)) {
                $vendorNames[] = $vendorName;
            }
        }

        $unsent = self::unsent_notice(array_values((array) ($result['unsent'] ?? [])));

        // The planner is named last so the phrase reads the same however many
        // vendors joined: "Karen Gardens, X and your planner have been sent…".
        $who = [];
        if ($venue && $name !== '') {
            $who[] = $name;
        }
        foreach (self::capped($vendorNames, 'vendors') as $vendorName) {
            $who[] = $vendorName;
        }
        if ($planner) {
            $who[] = 'your planner';
        }

        if ($who === []) {
            return $unsent !== ''
                ? 'Nobody could be reached: ' . $unsent
                : 'Nobody was notified — no planner or venue is assigned to this wedding yet.';
        }

        $text = ucfirst(self::join_names($who)) . (count($who) === 1 ? ' has' : ' have') . ' been sent the changed functions';

        // Said whenever it is true, vendors reached or not: the venue on file
        // being unreachable is something the couple has to know, and it is not
        // answered by their caterer having been told.
        if ($state === 'no_account') {
            $text .= '; the venue on file has no portal account to send to';
        } elseif ($state === 'none' && $who === ['your planner']) {
            $text .= ' — no venue is booked on this wedding yet';
        }
        if (!$planner) {
            $text .= '; no planner is assigned to this wedding yet';
        }

        $text .= '.';

        return $unsent !== '' ? $text . ' ' . $unsent : $text;
    }

    /**
     * The functions whose planned headcount outgrew a package booked on them,
     * told to the vendors holding those packages and to the assigned planner.
     *
     * The body leads with "Headcount check" rather than "Capacity check" on
     * purpose: every message this class writes names itself as a headcount
     * message, so a residue sweep (and any audit) can find them all by one word.
     *
     * This is the one announcement a STAFF save makes (admin/event_booking_save.php):
     * the brief above stays silent on the planner's own corrections, but the
     * plan no longer fitting inside what was sold is a fact about the deal, not
     * about who typed the number — the caterer ordered against 250 and the
     * planner has to re-price. The saver is not told what they are looking at:
     * they were the one who pressed Save, and the page says it in words
     * (PackageCapacity::notice()).
     *
     * A save that falls back UNDER a limit announces nothing: churn on the guest
     * count is not news, and the sheet prints the position it is in now.
     *
     * @param array<string,mixed> $capacity PackageCapacity::clauses() — the breach
     *                                     copy per account, per planner, and the
     *                                     vendors nobody can reach
     * @param int                             $saver_id the account that saved (0 for nobody)
     * @return array{vendors:array<int,array{name:string,category:string,scope:string}>,
     *               planner:bool,
     *               unsent:array<int,array{name:string,category:string,why:string}>,
     *               told:string} the `told` sentence is what the page appends to
     *               the notice, so the saver can see that the warning landed.
     */
    public static function announce_capacity(int $booking_id, array $capacity, int $saver_id = 0): array
    {
        $out     = ['vendors' => [], 'planner' => false, 'unsent' => [], 'told' => ''];
        $vendors = (array) ($capacity['vendors'] ?? []);
        $unsent  = array_values((array) ($capacity['unsent'] ?? []));
        $clause  = trim((string) ($capacity['planner_brief'] ?? $capacity['planner'] ?? ''));

        if ($booking_id <= 0 || ($vendors === [] && $clause === '')) {
            return $out;
        }

        $couple  = self::couple_label($booking_id);
        $planner = self::recipients($booking_id)['planner'];
        $planner_id = (int) ($planner['user_id'] ?? 0);

        // ---- the planner: who has to be re-priced, and who cannot be reached ----
        $upgrade = trim((string) ($capacity['planner_upgrade'] ?? ''));
        if ($clause !== '' && $planner_id > 0 && $planner_id !== $saver_id) {
            $unsentNote  = $unsent !== [] ? self::unsent_notice($unsent) : '';
            $with_way_out = $upgrade !== '' ? $clause . ' ' . $upgrade : $clause;
            $tails = [];
            foreach ([$unsentNote, 'The venue sheet has the numbers.'] as $tail) {
                if ($tail !== '') {
                    $tails[] = $with_way_out . ' ' . $tail;
                }
            }
            $tails[] = $with_way_out;
            foreach ([$unsentNote, 'The venue sheet has the numbers.'] as $tail) {
                if ($tail !== '') {
                    $tails[] = $clause . ' ' . $tail;
                }
            }
            // The last candidate is the warning alone: fit() takes the first tail
            // that fits, so the way out and the pointers go before it does.
            $tails[] = $clause;

            Notification::create_for_staff(
                $planner_id,
                $booking_id,
                self::fit('Headcount check — ' . $couple . '\'s wedding: ', [], $tails),
                'sheet_venue.php?booking_id=' . $booking_id,
                'Headcount capacity — ' . $couple
            );
            $out['planner'] = true;
        }

        // ---- every vendor holding a package that no longer covers the day ----
        foreach ($vendors as $uid => $acct) {
            $text = trim((string) ($acct['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ((int) $uid === $planner_id && $out['planner']) {
                continue; // one message per account: this one already heard as the planner
            }

            // Built the same way as the planner's copy: the warning is the one
            // fact here (no moved function is reported), so it is a tail rather
            // than a line — a line would be punctuated again and read
            // "… (Choma Premium — 250 pax).."
            Notification::create_for_vendor(
                (int) $uid,
                $booking_id,
                self::fit(
                    'Headcount check — ' . $couple . '\'s wedding: ',
                    [],
                    [$text . ' Your dashboard has the booking.', $text]
                ),
                'vendor_home.php',
                'Headcount capacity — ' . $couple
            );

            foreach ((array) ($acct['names'] ?? []) as $name) {
                $name = trim((string) $name);
                if ($name !== '' && !in_array($name, array_column($out['vendors'], 'name'), true)) {
                    $out['vendors'][] = [
                        'name'     => $name,
                        'category' => (string) ($acct['category'] ?? ''),
                        'scope'    => 'a package that no longer covers the day',
                    ];
                }
            }
        }
        $out['unsent'] = $unsent;

        // Who was warned, in one sentence, for the page the saver lands on.
        $who = array_column($out['vendors'], 'name');
        if ($out['planner']) {
            $who[] = 'your planner';
        }
        if ($who !== []) {
            $out['told'] = ucfirst(self::join_names(self::capped($who, 'vendors')))
                . (count($who) === 1 ? ' has' : ' have') . ' been warned.';
        }

        return $out;
    }

    /**
     * A message this class did NOT compose, with one more sentence on the end —
     * but only when the column can hold it.
     *
     * The one caller is the headcount capacity check at the ENQUIRY door
     * (admin/client_vendor_enquiry.php): the planner's "A couple enquired with X"
     * bell is written by that page, and when the package the couple picked does not
     * cover the function they have already planned, the planner is the one who has
     * to negotiate a bigger one — so the note rides on the bell that already tells
     * them the enquiry exists, rather than arriving as a second bell for one event
     * (the rule the rest of this class follows).
     *
     * The note is dropped, never cut, when the two do not fit together: a bell that
     * ends mid-number is worse than a bell the planner has to open the sheet to
     * finish. The column's limit stays private to this class — every message this
     * codebase stores in tblnotifications.message is fitted here or by a caller
     * that passes the same limit on purpose, and a caller that guessed the number
     * would be the fifth place it is written down.
     */
    public static function append_note(string $message, string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            return $message;
        }
        $joined = rtrim($message) . ' ' . $note;
        return mb_strlen($joined) <= self::MESSAGE_LIMIT ? $joined : $message;
    }

    /**
     * A notification body that FITS the bell's column: the numbers first, the
     * prose after.
     *
     * The order of what gets dropped is deliberate, and it is the CALLER's: the
     * first tail that fits the column wins, and the last one is the floor — what
     * survives when almost nothing else does. Callers build $tails most
     * informative first and put the one fact the message exists for last (see
     * the planner's capacity tiers in announce(): a message that runs out of room
     * loses the suggestion and then the closing prose, never the warning). Only
     * once no tail fits at all are moved functions dropped, from the end, as "and
     * N more moved functions". What is never done is cut a number in half, which
     * is what the column itself would do to a long message on a non-strict
     * server. Duplicates and empty strings are ignored.
     *
     * @param string[]        $lines the moved functions, in order; EMPTY when the
     *                              message has one fact and nothing to count down
     *                              (the capacity check, which reports no moved
     *                              function at all)
     * @param array<int,string> $tails closing prose, in the caller's priority order
     */
    private static function fit(string $lead, array $lines, array $tails): string
    {
        $tails = array_values(array_filter($tails, static fn($t): bool => is_string($t) && $t !== ''));
        if ($tails === []) {
            $tails = ['']; // only the numbers then
        }

        foreach ($tails as $tail) {
            $text = self::assemble($lead, $lines, $tail);
            if (mb_strlen($text) <= self::MESSAGE_LIMIT) {
                return $text;
            }
        }

        $tail = $tails[count($tails) - 1];
        $total = count($lines);

        /*
         * A message with no moved functions has nothing to count down, so the
         * floor is the last tail — the warning itself — and the "and N more moved
         * functions" rung is skipped rather than invented: on the capacity check
         * it would tell the planner a function moved when none did. Only if the
         * warning alone is over the column is it cut, and the cut keeps its head
         * ("After-Party is planned for 254 — …"), never a number in half.
         */
        if ($total === 0) {
            $text = $lead . $tail;
            return mb_strlen($text) <= self::MESSAGE_LIMIT
                ? $text
                : mb_substr($text, 0, self::MESSAGE_LIMIT - 1) . '…';
        }
        for ($take = $total - 1; $take >= 0; $take--) {
            $lines = array_slice($lines, 0, $take);
            $more = $total - $take;
            $text = self::assemble($lead, $lines, 'and ' . $more . ' more moved function' . ($more === 1 ? '' : 's') . '. ' . $tail);
            if (mb_strlen($text) <= self::MESSAGE_LIMIT) {
                return $text;
            }
        }

        // Not even one line with the shortest tail: say where the numbers are.
        $text = $lead . $total . ' moved function' . ($total === 1 ? '' : 's') . ' changed — open the venue sheet for the numbers.';
        return mb_substr($text, 0, self::MESSAGE_LIMIT - 1) . '…';
    }

    /**
     * The moved lines minus those the capacity warning already states.
     *
     * @param string[]                  $lines  every moved line, in order
     * @param array<int,array<string,mixed>> $moves the moves they came from
     * @param array<int,int>            $breaching  the functions the warning is about
     * @return string[]
     */
    private static function without_breaches(array $lines, array $moves, array $breaching): array
    {
        $breaching = array_values(array_filter(array_map('intval', $breaching), static fn(int $i): bool => $i > 0));
        if ($lines === [] || $breaching === []) {
            return $lines;
        }

        $spent = [];
        foreach ($moves as $move) {
            if (!in_array((int) ($move['features_id'] ?? 0), $breaching, true)) {
                continue;
            }
            $text = self::describe($move);
            if ($text !== null) {
                $spent[] = $text;
            }
        }
        if ($spent === []) {
            return $lines;
        }

        return array_values(array_filter(
            $lines,
            static fn(string $line): bool => !in_array($line, $spent, true)
        ));
    }

    /**
     * A lead, the lines that follow it, and a closing tail as one string. The
     * lines are a sentence of their own (hence the full stop), and an empty list
     * leaves none — the lead then runs straight into the tail, which is how the
     * capacity check reads: "… wedding: After-Party is planned for 254 — over …".
     *
     * @param string[] $lines
     */
    private static function assemble(string $lead, array $lines, string $tail): string
    {
        return $lead . ($lines !== [] ? implode('; ', $lines) . '. ' : '') . $tail;
    }

    /**
     * The lines of one deal's brief: the whole day's moves for a vendor booked
     * for the wedding, its own function's move for a scoped one.
     *
     * @param array<string,mixed> $deal
     * @param array<int,string>   $byFunction   features_id => line
     * @param string[]            $weddingLines every moved line, in order
     * @return string[]
     */
    private static function covered_lines(array $deal, array $byFunction, array $weddingLines): array
    {
        if (($deal['scope'] ?? 'wedding') === 'missing') {
            return [];
        }
        if (($deal['scope'] ?? 'wedding') === 'wedding') {
            return $weddingLines;
        }
        $fid = (int) ($deal['features_id'] ?? 0);
        return isset($byFunction[$fid]) ? [$byFunction[$fid]] : [];
    }

    /**
     * The one line this move is worth, or null when nothing moved (the caller
     * iterates the wedding's own functions, so a form that submits every box
     * hands over the untouched ones too).
     */
    private static function describe(array $move): ?string
    {
        $title = trim((string) ($move['title'] ?? ''));
        $title = $title !== '' ? $title : 'Ceremony';
        $from  = $move['from'] ?? null;
        $to    = $move['to'] ?? ['family' => 0, 'individual' => 0];
        $famTo = max(0, (int) ($to['family'] ?? 0));
        $indTo = max(0, (int) ($to['individual'] ?? 0));

        if ($from === null) {
            return $title . ' — now planned for ' . $famTo . ' family / ' . $indTo . ' individual';
        }

        $famFrom = max(0, (int) ($from['family'] ?? 0));
        $indFrom = max(0, (int) ($from['individual'] ?? 0));

        if ($famTo === 0 && $indTo === 0) {
            return $title . ' — no longer planned (was ' . $famFrom . ' family / ' . $indFrom . ' individual)';
        }

        // Only the side that moved: a brief that reports "individual 100 → 100"
        // teaches the reader to skim, which is how the one number that did
        // change gets missed.
        $bits = [];
        if ($famFrom !== $famTo) {
            $bits[] = 'family ' . $famFrom . ' → ' . $famTo;
        }
        if ($indFrom !== $indTo) {
            $bits[] = 'individual ' . $indFrom . ' → ' . $indTo;
        }
        if ($bits === []) {
            return null; // nothing moved: not a change worth reporting
        }
        return $title . ' — ' . implode(', ', $bits);
    }

    /**
     * The vendors nobody could be sent to, as one sentence. Naming them is the
     * point: a booked vendor with no portal account means the planner is the
     * only channel left, and they have to know that.
     *
     * @param array<int,array{name?:string,category?:string,why?:string}> $unsent
     */
    private static function unsent_notice(array $unsent): string
    {
        $sentence = [];
        $named = 0;
        foreach ($unsent as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $who = trim((string) ($entry['name'] ?? ''));
            if ($who === '') {
                continue;
            }
            // Two names carry the point (the planner has to call them); beyond
            // that the count is the honest shape.
            if ($named >= 2) {
                break;
            }
            $named++;
            $category = trim((string) ($entry['category'] ?? ''));
            $sentence[] = $who . ($category !== '' ? ' (' . $category . ')' : '')
                . (($entry['why'] ?? '') === 'missing_function'
                    ? ' is scoped to a function that is gone — check that deal'
                    : ' has no portal account — pass the numbers on by hand');
        }
        if ($sentence === []) {
            return '';
        }
        $more = max(0, count($unsent) - count($sentence));
        return implode('; ', $sentence) . '.' . ($more > 0 ? ' (' . $more . ' more)' : '');
    }

    /**
     * At most two names listed, the rest summed up — a brief that prints nine
     * vendors is a brief nobody reads to the end. The summand is returned as its
     * own list item ("3 more vendors") so it joins like one: prose baked into an
     * element produces "A, B and 2 more and your planner".
     *
     * @param array<int,string> $items
     * @return array<int,string>
     */
    private static function capped(array $items, string $unit = 'names'): array
    {
        $items = array_values(array_filter(array_map(
            static fn($i): string => trim((string) $i),
            $items
        ), static fn(string $i): bool => $i !== ''));

        if (count($items) <= 2) {
            return $items;
        }
        return array_merge(array_slice($items, 0, 2), [(count($items) - 2) . ' more ' . $unit]);
    }

    /** "A", "A and B", "A, B and C" — the house style for a short list. */
    private static function join_names(array $names): string
    {
        $names = array_values(array_filter(array_map(
            static fn($n): string => trim((string) $n),
            $names
        ), static fn(string $n): bool => $n !== ''));

        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** "Linda & Brian" for the booking, or a neutral fallback. */
    private static function couple_label(int $booking_id): string
    {
        $booking = Booking::find_by_booking_id($booking_id);
        $couple  = $booking ? trim((string) ($booking->bride ?? '') . ' & ' . (string) ($booking->groom ?? ''), ' &') : '';
        return $couple !== '' ? $couple : 'Your couple';
    }
}
