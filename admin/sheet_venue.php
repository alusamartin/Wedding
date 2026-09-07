<?php
/**
 * Venue & Day Summary — printable one-glance brief for the venue, caterers
 * and the coordination team: the running order (events timeline with
 * locations and colors matching the calendar), headcounts per event from
 * the package plan, key contacts, and the logistics numbers.
 * Access: staff any booking, couple their own (sheet_boot()).
 */
include 'include/init.php';
include 'include/sheet_boot.php';

$booking = sheet_boot();
$booking_id = (int) $booking->booking_id;

// ---- events timeline -----------------------------------------------------
$stmt = $db->prepare('SELECT id, title, location, start, end, color FROM events WHERE booking_id = :bid ORDER BY start');
$stmt->execute([':bid' => $booking_id]);
$events = $stmt->fetchAll(PDO::FETCH_OBJ);

// ---- package + planned headcounts (tbleventbooking) ----------------------
$category = Category::find_by_id((int) ($booking->wedding_type ?? 0));
$stmt = $db->prepare(
    'SELECT eb.family, eb.individual, w.title AS feature_title, w.location AS feature_location
     FROM tbleventbooking eb LEFT JOIN tblpostwedding w ON w.id = eb.features_id
     WHERE eb.booking_id = :bid'
);
$stmt->execute([':bid' => $booking_id]);
$plans = $stmt->fetchAll(PDO::FETCH_OBJ);

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

sheet_head('Venue & Day Summary', $booking);
?>
<div class="s-body">

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

    <?php if ($plans !== []): ?>
    <div class="s-section">Headcount plan per function</div>
    <table class="s-table">
        <thead><tr>
            <th>Function</th>
            <th style="width:22%;">Venue</th>
            <th style="width:16%;">Family</th>
            <th style="width:16%;">Individual</th>
        </tr></thead>
        <tbody>
        <?php foreach ($plans as $p): ?>
            <tr>
                <td><strong><?= htmlspecialchars((string) ($p->feature_title ?? '')); ?></strong></td>
                <td><?= htmlspecialchars((string) ($p->feature_location ?? '')); ?></td>
                <td><?= (int) $p->family; ?></td>
                <td><?= (int) $p->individual; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
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
