<?php
/**
 * Venue & Day Summary — printable one-glance brief for the venue, caterers
 * and the coordination team: the running order (events timeline with
 * locations and colors matching the calendar), headcounts per event from
 * the package plan, key contacts, and the logistics numbers.
 * Access: staff any booking, couple their own (sheet_boot()).
 */
include 'include/init.php'; // paywall: staff need an active plan to preview any booking
    if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; }

include 'include/sheet_boot.php';

$booking = sheet_boot();
$booking_id = (int) $booking->booking_id;

// ---- events timeline -----------------------------------------------------
$stmt = $db->prepare('SELECT id, title, location, start, end, color FROM events WHERE booking_id = :bid ORDER BY start');
$stmt->execute([':bid' => $booking_id]);
$events = $stmt->fetchAll(PDO::FETCH_OBJ);

// ---- package + planned headcounts ----------------------------------------
$category = Category::find_by_id((int) ($booking->wedding_type ?? 0));

/*
 * The headcount plan, one row per FUNCTION of this wedding (v2.9).
 *
 * Built from BookingEvent::plan_for(), which reads `events` rather than
 * joining tblpostwedding: that join resolved a plan row's features_id against
 * the public "real weddings" blog, so the sheet printed other couples' blog
 * posts as this wedding's functions. features_id holds an `events` id, and
 * plan_for() scopes the lookup to the booking, so a plan row can never borrow
 * another wedding's function.
 *
 * A function nobody has planned for still appears (with 0/0) so it can be
 * planned — that is the whole point: before this, the table showed numbers no
 * role could enter and silently omitted anything the seed had not filled in.
 */
$functions = BookingEvent::plan_for($booking_id);
$can_plan  = booking_visible_to_session($booking);
/*
 * Every link and the headcount form address the BOOKING's owner, never the raw
 * request parameter. Authorization never depended on it — sheet_boot() and
 * event_booking_save.php both check the booking row against the session — but
 * echoing it made the sheet render another account's id in its own markup:
 * ?booking_id=203&user_id=204 (a couple's own wedding, someone else's user id)
 * served a form whose hidden user_id was 204, and the add-to-calendar link
 * carried it too. A booking has exactly one owner, so that is what we mean.
 */
$links     = 'booking_id=' . $booking_id . '&user_id=' . (int) $booking->user_id;

$plan_family     = 0;
$plan_individual = 0;
$planned_count   = 0;
foreach ($functions as $fn) {
    $plan_family     += (int) $fn->family;
    $plan_individual += (int) $fn->individual;
    if ($fn->planned) {
        $planned_count++;
    }
}

$tally = Guest::rsvp_tally($booking_id);
$guest_list = Guest::getGuest($booking_id);
$attending = $tally['yes'];
$plan_for  = $tally['yes'] + $tally['maybe'];
$transport = 0; $accom = 0;
foreach ($guest_list as $g) {
    if (($g->rsvp_status ?? 'pending') !== 'yes') continue;
    if (!empty($g->transport_needed)) $transport++;
    if (!empty($g->upcountry_accommodation)) $accom++;
}

// ---- contacts -------------------------------------------------------------
$planner = !empty($booking->organizer_id) ? Users::find_by_id((int) $booking->organizer_id) : false;
$planner_name  = $planner ? trim(($planner->firstname ?? '') . ' ' . ($planner->lastname ?? '')) : '';
$planner_role  = $planner ? (string) ($planner->designation ?? '') : '';

$detail  = Account_Details::find_by_user_id((int) $booking->user_id);
$contact_name  = $detail ? trim(($detail->firstname ?? '') . ' ' . ($detail->lastname ?? '')) : '';
$contact_phone = $detail ? (string) ($detail->phone ?? '') : '';

/*
 * The venue contact this brief is sent to when the couple moves a number
 * (include/HeadcountBrief.php). Listed here because this page is where those
 * numbers are set and printed: a recipient nobody can see is how a brief ends
 * up reaching no one.
 */
$briefRecipients = HeadcountBrief::recipients($booking_id);
$venueContact    = $briefRecipients['venue'];

/*
 * The OTHER vendors the same brief goes to (v2.10): every confirmed or
 * completed deal on this wedding that is not the venue listing, each scoped to
 * the functions its deal covers. Listed on this page because this page is where
 * the numbers are set — a recipient nobody can see is how a brief ends up
 * reaching no one, and a scope nobody can see is how the wrong vendor gets
 * woken up by a number that was never theirs.
 */
/*
 * Does the plan still fit inside what was sold? (include/PackageCapacity.php)
 *
 * A venue or catering package carries a guest capacity and the deal records the
 * package it was bought on, so a function planned past one is a deal that no
 * longer covers the day. Printed HERE, where the numbers are set, because that
 * is where the question is asked — and a warning that only ever appears in a
 * bell can be missed, dismissed, or arrive after the caterer has ordered.
 */
$capacityFunctions = [];
foreach ($functions as $fn) {
    $capacityFunctions[] = [
        'features_id' => (int) $fn->features_id,
        'title'       => (string) $fn->title,
        'total'       => (int) $fn->family + (int) $fn->individual,
    ];
}
$capacityBreaches = PackageCapacity::breaches($booking_id, $capacityFunctions);
$capacityNotice   = PackageCapacity::notice($capacityBreaches);
/*
 * The way out, for STAFF only: the same vendor's next package up and what it
 * costs against the one on the deal (PackageCapacity::upgrades()). Staff-only on
 * purpose — a re-price is the planner's negotiation with the vendor, and the
 * difference shown is between LIST prices while a deal's amount may have been
 * negotiated below that. The couple still see the breach itself: their numbers,
 * their venue.
 */
$capacityUpgrades = $capacityBreaches !== [] && is_staff() ? PackageCapacity::upgrades($capacityBreaches) : [];

$briefVendors = [];
foreach (HeadcountBrief::booked_deals($booking_id) as $deal) {
    if ($deal['category'] === 'venue') {
        continue; // named above, as the venue contact
    }
    $briefVendors[] = [
        'name'      => $deal['business_name'],
        'label'     => ($deal['category'] !== '' ? ucfirst($deal['category']) . ' · ' : '') . HeadcountBrief::scope_text($deal),
        'reachable' => $deal['user_id'] > 0,
    ];
}

sheet_head('Venue & Day Summary', $booking);
?>    <div class="s-body">

    <?php if ($session->message()): ?>
        <div class="s-msg mb-2"><?= $session->message(); ?></div>
    <?php endif; ?>

    <div class="row no-gutters" style="margin:0 -4px;">
        <div class="col p-1"><div class="stat"><div class="n"><?= $attending; ?></div><div class="l">Confirmed</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n" style="color:#B45309;"><?= $plan_for; ?></div><div class="l">Plan for</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= $transport; ?></div><div class="l">Transport</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= $accom; ?></div><div class="l">Accommodation</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= count($events); ?></div><div class="l">Events</div></div></div>
    </div>

    <div class="s-section">Running order</div>
    <?php if ($events === []): ?>
        <p class="text-muted" style="font-size:13px;">No events have been scheduled on the calendar for this wedding yet.</p>
    <?php else: ?>
    <table class="s-table">
        <thead><tr>
            <th style="width:13%;">Time</th>
            <th style="width:26%;">Event</th>
            <th style="width:33%;">Venue / location</th>
            <th style="width:28%;">On-the-day notes</th>
        </tr></thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
            <?php
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $ev->color) ? $ev->color : '#065F46';
            $st = strtotime((string) $ev->start);
            $en = $ev->end ? strtotime((string) $ev->end) : false;
            $day = date('D j M', $st);
            $same_day = $en !== false && date('D j M', $en) === $day;
            ?>
            <tr>
                <td class="ev-time"><?= date('g:i A', $st) . ($en !== false ? ' &ndash; ' . ($same_day ? date('g:i A', $en) : date('g:i A', $en) . ' <span class="s-meta">(' . date('D j M', $en) . ')</span>') : ''); ?></td>
                <td><span class="ev-dot" style="background:<?= htmlspecialchars($color); ?>;"></span><strong><?= htmlspecialchars($ev->title !== '' ? $ev->title : 'Ceremony'); ?></strong></td>
                <td><?= ($ev->location ?? '') !== '' ? htmlspecialchars((string) $ev->location) : '<span class="s-meta">To confirm</span>'; ?></td>
                <td class="s-meta"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="s-section">Headcount plan per function</div>
    <?php if ($functions === []): ?>
        <p class="text-muted" style="font-size:13px; margin:0;">
            This wedding has no functions on the calendar yet, so there is nothing to plan a headcount for.
            <?php if ($can_plan && is_staff()): ?>
                Add them on the <a href="task_calendar.php?<?= htmlspecialchars($links); ?>">calendar</a>, then set the headcounts here.
            <?php else: ?>
                Your planner puts the functions on the calendar first; the headcounts can be set here afterwards.
            <?php endif; ?>
        </p>
    <?php else: ?>
    <table class="s-table">
        <thead><tr>
            <th>Function</th>
            <th style="width:26%;">Venue</th>
            <th style="width:14%;" class="s-num">Family</th>
            <th style="width:14%;" class="s-num">Individual</th>
        </tr></thead>
        <tbody>
        <?php foreach ($functions as $fn): ?>
            <tr>
                <td><strong><?= htmlspecialchars($fn->title !== '' ? $fn->title : 'Ceremony'); ?></strong>
                    <?php if ($fn->start !== ''): ?><div class="s-meta"><?= htmlspecialchars(date('D j M, g:i A', strtotime($fn->start))); ?></div><?php endif; ?>
                    <?php if (!$fn->planned): ?><div class="s-meta">not planned yet</div><?php endif; ?></td>
                <td><?= $fn->location !== '' ? htmlspecialchars($fn->location) : '<span class="s-meta">To confirm</span>'; ?></td>
                <td class="s-num"><?= (int) $fn->family; ?></td>
                <td class="s-num"><?= (int) $fn->individual; ?></td>
            </tr>
        <?php endforeach; ?>
            <tr>
                <td colspan="2"><strong>Planned total</strong>
                    <?php if ($planned_count < count($functions)): ?>
                        <span class="s-meta">(<?= $planned_count; ?> of <?= count($functions); ?> functions planned)</span>
                    <?php endif; ?></td>
                <td class="s-num"><strong><?= $plan_family; ?></strong></td>
                <td class="s-num"><strong><?= $plan_individual; ?></strong></td>
            </tr>
        </tbody>
    </table>
    <p class="s-meta" style="margin-top:6px;">
        The numbers the venue and the caterers work from — a per-function brief, not the RSVP list
        (which has <strong><?= $attending; ?></strong> confirmed so far). The same guest can attend several
        functions, so these counts are not meant to add up to the guest list.
        <?= $can_plan && !is_staff() ? 'Save a change and everyone briefed on it (Key contacts, below) is sent just the functions that moved.' : ''; ?>
    </p>
    <?php if ($capacityNotice !== ''): ?>
    <!--
        The packages the plan has outgrown, named with the vendor holding each one.
        Its own paragraph, not part of the note above: it is the one line on this
        page that says a booking no longer fits. Amber, like the "Plan for" stat
        — a figure to act on rather than an error.
    -->
    <p class="s-meta" style="margin-top:6px; color:#B45309;">
        <i class="mdi mdi-alert-outline"></i> <strong><?= htmlspecialchars($capacityNotice); ?></strong>
    </p>
    <?php endif; ?>
    <?php if ($capacityUpgrades !== []): ?>
    <p class="s-meta" style="margin-top:2px; color:#B45309;">
        <?php $upgrade_i = 0; foreach ($capacityUpgrades as $upgradeDeal): ?>
            <?= $upgrade_i++ > 0 ? '<br>' : ''; ?><i class="mdi mdi-arrow-up-bold-circle-outline"></i>
            <?= htmlspecialchars(PackageCapacity::upgrade_sentence($upgradeDeal)); ?>
            <a href="vendor_enquiries.php?deal=<?= (int) $upgradeDeal['deal_id']; ?>#deal-<?= (int) $upgradeDeal['deal_id']; ?>">Re-price this deal</a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($can_plan && $functions !== []): ?>
    <!--
        The editor for the table above. Deliberately on this page: the sheet is the
        only place these numbers are used, staff already open it for any wedding and a
        couple for their own (sheet_boot), and an edit form anywhere else would be a
        second trip for a number that lives here. Hidden when printed (.s-edit).
    -->
    <div class="s-edit">
        <form method="post" action="event_booking_save.php">
            <?= $session->csrf_field(); ?>
            <input type="hidden" name="save_headcounts" value="1">
            <input type="hidden" name="booking_id" value="<?= $booking_id; ?>">
            <input type="hidden" name="user_id" value="<?= (int) $booking->user_id; ?>">
            <div style="font-weight:600; font-size:13px; margin-bottom:6px;">
                <i class="mdi mdi-account-multiple"></i> Set the planned headcounts
            </div>
            <table>
                <thead><tr>
                    <th>Function</th>
                    <th class="s-num" style="width:22%;">Family</th>
                    <th class="s-num" style="width:22%;">Individual</th>
                </tr></thead>
                <tbody>
                <?php foreach ($functions as $fn): ?>
                    <tr>
                        <td><?= htmlspecialchars($fn->title !== '' ? $fn->title : 'Ceremony'); ?></td>
                        <td class="s-num">
                            <input type="number" min="0" max="10000" step="1"
                                   name="family[<?= (int) $fn->features_id; ?>]" value="<?= (int) $fn->family; ?>"
                                   aria-label="Family headcount for <?= htmlspecialchars($fn->title); ?>">
                        </td>
                        <td class="s-num">
                            <input type="number" min="0" max="10000" step="1"
                                   name="individual[<?= (int) $fn->features_id; ?>]" value="<?= (int) $fn->individual; ?>"
                                   aria-label="Individual headcount for <?= htmlspecialchars($fn->title); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-sm btn-success"><i class="mdi mdi-content-save"></i> Save headcount plan</button>
                <span class="s-meta">Leave a function at 0 for "not planned yet". This block is not printed.</span>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="s-section">Key contacts</div>
    <table class="s-table">
        <tbody>
            <tr>
                <td><strong>Planner</strong></td>
                <td><?= htmlspecialchars($planner_name !== '' ? $planner_name . ($planner_role !== '' ? ' — ' . $planner_role : '') : 'Not assigned yet'); ?></td>
            </tr>
            <tr>
                <td><strong>Client contact</strong></td>
                <td><?= htmlspecialchars($contact_name . ($contact_phone !== '' ? ' · ' . $contact_phone : '')); ?></td>
            </tr>
            <tr>
                <td><strong>Venue contact</strong></td>
                <td>
                <?php if ($venueContact !== null): ?>
                    <?= htmlspecialchars($venueContact['name']); ?>
                    <?php if ((int) $venueContact['user_id'] === 0): ?>
                        <span class="s-meta">— no portal account, so the brief has to be passed on by hand</span>
                    <?php else: ?>
                        <span class="s-meta">— sent the headcount changes</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="s-meta">None booked yet — headcount changes go to the planner</span>
                <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>Vendor brief</strong></td>
                <td>
                <?php if ($briefVendors === []): ?>
                    <span class="s-meta">No other vendor is booked on this wedding — headcount changes go to the planner and the venue contact above</span>
                <?php else: ?>
                    <?php foreach ($briefVendors as $i => $vendorBrief): ?>
                        <?= $i > 0 ? '<br>' : ''; ?><?= htmlspecialchars($vendorBrief['name']); ?>
                        <span class="s-meta">— <?= htmlspecialchars($vendorBrief['label']); ?><?= $vendorBrief['reachable'] ? '' : ' — no portal account, so the brief has to be passed on by hand'; ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
                </td>
            </tr>
            <?php if ($category): ?>
            <tr>
                <td><strong>Package</strong></td>
                <td><?= htmlspecialchars((string) ($category->wedding_type ?? '')); ?> · <?= format_ksh($category->price ?? 0); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="s-section">Coordination checklist</div>
    <table class="s-table">
        <tbody>
            <tr><td style="width:22px;">&#9744;</td><td>Confirm final headcount with the venue by <?= $events !== [] ? htmlspecialchars(date('D j M', strtotime((string) $events[0]->start))) : htmlspecialchars(format_date_kd($booking->wedding_date ?? '')); ?></td></tr>
            <tr><td>&#9744;</td><td>Share the dietary brief with caterers (see the guest list sheet)</td></tr>
            <tr><td>&#9744;</td><td>Arrange transport for the <?= $transport; ?> guests who need it</td></tr>
            <tr><td>&#9744;</td><td>Confirm <?= $accom; ?> upcountry accommodation booking<?= $accom === 1 ? '' : 's'; ?></td></tr>
            <tr><td>&#9744;</td><td>Brief the MC with the running order above</td></tr>
        </tbody>
    </table>

</div>
<?php sheet_foot(); ?>
