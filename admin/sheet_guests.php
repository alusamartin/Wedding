<?php
/**
 * Wedding-Day Guest List — printable A4 sheet.
 *
 * Groups the confirmed headcount by guest group (elders, bridal train,
 * upcountry guests…), lists every guest with RSVP state, dietary needs,
 * transport/accommodation flags and harambee pledges — what the master of
 * ceremony, caterers and the elders' committee check on the morning of the
 * wedding. Access: staff any booking, couple their own (sheet_boot()).
 */
include 'include/init.php'; // paywall: staff need an active plan to preview any booking
    if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; }

include 'include/sheet_boot.php';

$booking = sheet_boot();
$booking_id = (int) $booking->booking_id;

Guest::ensure_tokens($booking_id);
$guest_list = Guest::getGuest($booking_id);
$tally = Guest::rsvp_tally($booking_id);

$group_labels = [
    'family_elder' => 'Family Elder (Mzee)', 'bridal_train' => 'Bridal Train',
    'upcountry_guest' => 'Upcountry Guest', 'family_friend' => 'Family Friend',
    'colleague' => 'Colleague', 'pastor_clergy' => 'Pastor/Clergy',
    'vc_member' => 'VC Member', 'harambee_sponsor' => 'Harambee Sponsor',
    'close_family' => 'Close Family', 'regular' => 'Regular Guest', 'other' => 'Other',
];

$group_of = function ($g) use ($group_labels): string {
    return $group_labels[$g->relationship] ?? ucfirst(str_replace('_', ' ', (string) $g->relationship));
};

// ---- headcounts ----------------------------------------------------------
$attending = array_values(array_filter($guest_list, function ($g) {
    return ($g->rsvp_status ?? 'pending') === 'yes';
}));
$tentative = $tally['maybe'];
$plan_for  = $tally['yes'] + $tentative;
$transport = 0; $accom = 0; $dietary = [];
foreach ($attending as $g) {
    if (!empty($g->transport_needed)) $transport++;
    if (!empty($g->upcountry_accommodation)) $accom++;
    $diet = trim((string) ($g->dietary_requirements ?? ''));
    if ($diet !== '' && strcasecmp($diet, 'None') !== 0) {
        $dietary[$diet] = ($dietary[$diet] ?? 0) + 1;
    }
}
arsort($dietary);

// guests grouped by group, attending first then the rest, names alphabetical
$by_group = [];
foreach ($guest_list as $g) {
    $by_group[$group_of($g)][] = $g;
}
$group_order = array_keys($by_group);
usort($group_order, function ($a, $b) {
    return strcasecmp($a, $b);
});

$harambee_total = Guest::total_harambee($booking_id);

sheet_head('Wedding Day Guest List', $booking);
?>
<div class="s-body">

    <div class="row no-gutters" style="margin:0 -4px;">
        <div class="col p-1"><div class="stat"><div class="n"><?= count($guest_list); ?></div><div class="l">Invited</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= $tally['yes']; ?></div><div class="l">Attending</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= $tentative; ?></div><div class="l">Maybe</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n" style="color:#B45309;"><?= $plan_for; ?></div><div class="l">Plan for</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= $transport; ?></div><div class="l">Transport</div></div></div>
        <div class="col p-1"><div class="stat"><div class="n"><?= $accom; ?></div><div class="l">Accommodation</div></div></div>
    </div>

    <?php if ($dietary !== []): ?>
    <div class="s-section">Caterer brief &mdash; dietary needs</div>
    <?php foreach ($dietary as $diet => $n): ?>
        <span class="badge-k b-gold mr-1 mb-1"><?= htmlspecialchars($diet); ?> &times; <?= (int) $n; ?></span>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="s-section">Guests by group</div>
    <?php if ($by_group === []): ?>
        <p class="text-muted" style="font-size:13px;">No guests have been added to this wedding yet.</p>
    <?php else: ?>
    <?php foreach ($group_order as $label): ?>
        <div class="day-h"><?= htmlspecialchars($label); ?> <span class="badge-k b-pending"><?= count($by_group[$label]); ?></span></div>
        <table class="s-table">
            <thead><tr>
                <th style="width:26%;">Guest</th>
                <th style="width:8%;">RSVP</th>
                <th style="width:13%;">Phone</th>
                <th style="width:14%;">Dietary</th>
                <th style="width:15%;">Logistics</th>
                <th style="width:24%;">Note</th>
            </tr></thead>
            <tbody>
            <?php foreach ($by_group[$label] as $g): ?>
                <?php
                $log = [];
                if (!empty($g->upcountry_accommodation)) $log[] = 'Accommodation';
                if (!empty($g->transport_needed))        $log[] = 'Transport';
                if (($g->out_of_town ?? '') === 'y')     $log[] = 'Upcountry';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($g->fullname); ?></strong><?= ($g->guestname ?? '') !== '' ? ' <span class="s-meta">(' . htmlspecialchars($g->guestname) . ')</span>' : ''; ?></td>
                    <td><?= sheet_rsvp_badge((string) ($g->rsvp_status ?? 'pending')); ?></td>
                    <td><?= htmlspecialchars((string) ($g->phone ?? '')); ?></td>
                    <td><?= htmlspecialchars((string) ($g->dietary_requirements ?? '')); ?></td>
                    <td><?= $log !== [] ? htmlspecialchars(implode(', ', $log)) : '<span class="s-meta">&mdash;</span>'; ?></td>
                    <td class="s-meta"><?= htmlspecialchars((string) ($g->rsvp_note ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ((float) $harambee_total > 0): ?>
    <div class="s-section">Harambee contributions</div>
    <table class="s-table">
        <thead><tr><th>Guest</th><th style="width:22%;">Pledge</th><th style="width:20%;">Group</th></tr></thead>
        <tbody>
        <?php foreach ($guest_list as $g): if ((float) ($g->harambee_contribution ?? 0) <= 0) continue; ?>
            <tr>
                <td><strong><?= htmlspecialchars($g->fullname); ?></strong></td>
                <td><?= format_ksh($g->harambee_contribution); ?></td>
                <td class="s-meta"><?= htmlspecialchars($group_of($g)); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>Total pledged</strong></td>
            <td colspan="2"><strong><?= format_ksh($harambee_total); ?></strong></td>
        </tr>
        </tbody>
    </table>
    <?php endif; ?>

</div>
<?php sheet_foot(); ?>
