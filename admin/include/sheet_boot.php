<?php
/**
 * Shared chrome for the printable wedding-day sheets (guest list + venue
 * summary). Handles staff/client access, booking resolution and the
 * print-first HTML shell so both sheets look identical.
 *
 * Usage (after include/init.php):
 *   $booking = sheet_boot();          // access guard, returns the booking
 *   sheet_head('Wedding Day Guest List', $booking);
 *   ... sheet body ...
 *   sheet_foot();
 */

/**
 * Access guard for the sheets: staff can print for any wedding, a signed-in
 * couple only for their own. Returns the booking row or redirects.
 */
function sheet_boot(): object
{
    if (!isset($_SESSION['id'])) {
        redirect_to('../');
        exit;
    }
    $booking_id = (int) ($_GET['booking_id'] ?? 0);
    $booking = $booking_id > 0 ? Booking::find_by_booking_id($booking_id) : false;
    if (!$booking) {
        redirect_to('client.php');
        exit;
    }
    if (!is_staff() && (int) $booking->user_id !== (int) $_SESSION['id']) {
        redirect_to('client.php');
        exit;
    }
    return $booking;
}

/** Open the print-first shell with the branded Everafter header. */
function sheet_head(string $title, object $booking): void
{
    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
    $when   = format_date_kd($booking->wedding_date ?? '');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title); ?> &mdash; <?= htmlspecialchars($couple); ?> - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700&display=swap" rel="stylesheet">
    <style>
        :root { --evergreen:#065F46; --gold:#C9A227; --ink:#0F172A; --muted:#64748B; --line:#E2E8F0; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: 'Inter', sans-serif; color: var(--ink); background: #F6F3EC; margin: 0; padding: 18px; }
        .sheet { background: #fff; max-width: 820px; margin: 0 auto; box-shadow: 0 18px 44px rgba(15,23,42,.10);
                 border-radius: 14px; overflow: hidden; }
        .s-head { background: linear-gradient(135deg, #065F46 0%, #059669 100%); color: #fff;
                  padding: 22px 30px 18px; display: flex; align-items: center; gap: 16px; }
        .s-head img { height: 46px; }
        .s-head .t { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: 17px; letter-spacing: .02em; }
        .s-head .d { font-size: 12px; opacity: .92; margin-top: 2px; }
        .s-body { padding: 22px 30px 8px; }
        .s-section { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: 12px;
                     letter-spacing: .12em; text-transform: uppercase; color: var(--evergreen);
                     border-bottom: 2px solid var(--gold); display: inline-block; padding-bottom: 3px;
                     margin: 22px 0 10px; }
        .s-foot { text-align: center; color: #94A3B8; font-size: 11px; padding: 14px 30px 22px; line-height: 1.6; }
        table.s-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .s-table th { text-align: left; font-size: 10.5px; letter-spacing: .07em; text-transform: uppercase;
                      color: var(--muted); border-bottom: 1.5px solid var(--ink); padding: 6px 8px; }
        .s-table td { border-bottom: 1px solid var(--line); padding: 7px 8px; vertical-align: top; }
        .s-table tr { page-break-inside: avoid; }
        .badge-k { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
        .b-yes { background:#E8F7EE; color:#059669; } .b-no { background:#FEE2E2; color:#B91C1C; }
        .b-maybe { background:#FEF3C7; color:#B45309; } .b-pending { background:#EEF2F7; color:#64748B; }
        .b-gold { background:#FBF3D9; color:#8A6D1A; }
        .stat { border: 1.5px solid var(--line); border-radius: 10px; padding: 10px 12px; text-align: center; height: 100%; }
        .stat .n { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: 22px; color: var(--evergreen); line-height: 1.15; }
        .stat .l { font-size: 10px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .ev-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; margin-right: 8px; vertical-align: baseline; }
        .ev-time { font-weight: 600; font-size: 12.5px; white-space: nowrap; color: var(--ink); }
        .s-actions { text-align: center; padding: 4px 30px 26px; }
        .btn-print { background: transparent; color: var(--evergreen); border: 1.5px solid var(--evergreen);
                     border-radius: 999px; font-weight: 600; font-size: 13px; padding: 8px 22px; }
        .btn-print:hover { background: var(--evergreen); color: #fff; }
        .s-meta { color: var(--muted); font-size: 12.5px; }
        .day-h { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; font-size: 13.5px;
                 color: var(--ink); margin: 16px 0 4px; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; max-width: 100%; }
            .s-actions { display: none; }
            @page { size: A4; margin: 14mm 12mm; }
        }
        @media (max-width: 640px) { .s-body { padding: 18px 14px 6px; } .s-head { padding: 18px 14px 14px; } }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="s-head">
            <img src="../images/logo/everafter-crest.png" alt="Everafter Planner">
            <div>
                <div class="t"><?= htmlspecialchars($title); ?></div>
                <div class="d"><?= htmlspecialchars($couple); ?><?= $when !== '' ? ' &middot; ' . htmlspecialchars($when) : ''; ?></div>
            </div>
        </div>
    <?php
}

/** Close the shell with the print action + footer. */
function sheet_foot(): void
{
    ?>
        <div class="s-actions">
            <button type="button" class="btn btn-print" onclick="window.print();">
                <i class="mdi mdi-printer mr-1"></i>Print this sheet
            </button>
        </div>
        <div class="s-foot">
            Wedding-day sheet &mdash; printed <?= htmlspecialchars(date('D j M Y, g:i A')); ?><br>
            Everafter Planner
        </div>
    </div>
</body>
</html>
    <?php
}

/** RSVP status -> badge markup, shared by the sheets. */
function sheet_rsvp_badge(string $status): string
{
    $map = [
        'yes'     => ['b-yes', 'Attending'],
        'no'      => ['b-no', 'Not attending'],
        'maybe'   => ['b-maybe', 'Maybe'],
        'pending' => ['b-pending', 'Pending'],
    ];
    [$cls, $label] = $map[$status] ?? ['b-pending', 'Pending'];
    return '<span class="badge-k ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}
