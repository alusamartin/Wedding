<?php include 'include/init.php'; ?>
<?php
/**
 * Branded payment receipt — one row of the liquidation ledger.
 *
 * Couples open it from My Payments ("View receipt") to screenshot or share
 * it on WhatsApp (the family group / harambee committee); staff can open
 * any. Ownership is enforced against the booking, never the query string.
 */
    if (!isset($_SESSION['id'])) {
        redirect_to("../");
        exit;
    }

    $receipt_id = (int) ($_GET['id'] ?? 0);
    $item = $receipt_id > 0 ? Liquidation::find_by_id($receipt_id) : false;

    if (!$item) {
        http_response_code(404);
        $not_found = true;
    } else {
        $booking = Booking::find_by_booking_id((int) $item->booking_id);
        if (!$booking) {
            http_response_code(404);
            $not_found = true;
        } elseif (!is_staff()) {
            // Clients may only open receipts of their own wedding.
            if ((int) $booking->user_id !== (int) $_SESSION['id']) {
                redirect_to("client.php");
                exit;
            }
        }
    }

    if (!empty($not_found)) { ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt Not Found - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #F6F3EC; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; }
        .nf { background: #fff; border-radius: 22px; padding: 44px 40px; max-width: 430px;
              box-shadow: 0 18px 44px rgba(15, 23, 42, .10); text-align: center; }
        .nf h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1.3rem; color: #0F172A; }
    </style>
</head>
<body>
    <div class="nf">
        <i class="mdi mdi-receipt-remove" style="font-size:44px; color:#C9A227;"></i>
        <h1 class="mt-3">Receipt not found</h1>
        <p class="text-muted" style="font-size:14px;">This payment record doesn't exist or was removed.</p>
        <a href="client.php" class="btn btn-success btn-sm">Back to my dashboard</a>
    </div>
</body>
</html>
<?php exit; } ?>

<?php
    // ---- Receipt data -------------------------------------------------
    $category  = Category::find_by_id((int) ($booking->wedding_type ?? 0));
    $package_price = (float) ($category->price ?? 0);
    $totals    = Liquidation::getTotalsByMethod((int) $item->booking_id);
    $amount_paid   = (float) ($totals['grand_total'] ?? 0);
    $balance_due   = max(0.0, $package_price - $amount_paid);

    $couple = trim(($booking->bride ?? '') . ' & ' . ($booking->groom ?? ''));
    $wedding_human = format_date_kd($booking->wedding_date ?? '');
    $event_name = event_label((int) $item->event_id);

    $is_mpesa = !empty($item->mpesa_code);
    if ($is_mpesa) {
        $method_label = 'M-Pesa';
    } elseif ((float) $item->cash > 0) {
        $method_label = 'Cash payment';
    } elseif ((float) $item->credit > 0) {
        $method_label = 'Bank transfer';
    } else {
        $method_label = 'Payment';
    }

    $receipt_no = 'EP-' . str_pad((string) $receipt_id, 6, '0', STR_PAD_LEFT);
    $planner = !empty($booking->organizer_id) ? Users::find_by_id((int) $booking->organizer_id) : false;
    $planner_name = $planner ? trim(($planner->firstname ?? '') . ' ' . ($planner->lastname ?? '')) : '';

    $share_text = "Payment Receipt {$receipt_no} — Everafter Planner\n"
        . "For: {$couple} ({$wedding_human})\n"
        . "Ceremony: {$event_name}\n"
        . "Paid: " . format_ksh($item->payment, 2) . " via {$method_label}"
        . ($is_mpesa ? " — code {$item->mpesa_code}" : '') . "\n"
        . "Date: " . format_date_kd($item->date_issue, true) . "\n"
        . "Balance remaining: " . format_ksh($balance_due, 2);
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= $receipt_no; ?> - Everafter Planner</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #F6F3EC; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; padding: 18px; margin: 0; }
        .receipt { background: #fff; width: 100%; max-width: 430px; border-radius: 22px;
                   box-shadow: 0 24px 56px rgba(15, 23, 42, .13); overflow: hidden; position: relative; }
        .r-head { background: linear-gradient(135deg, #065F46 0%, #059669 100%);
                  color: #fff; padding: 26px 30px 22px; text-align: center; }
        .r-head img { height: 58px; margin-bottom: 6px; }
        .r-head .brand { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700;
                         letter-spacing: .14em; font-size: 12px; text-transform: uppercase; opacity: .92; }
        .r-head .doc { font-size: 12px; letter-spacing: .08em; opacity: .85; margin-top: 8px; }
        .stamp { position: absolute; top: 74px; right: 22px; border: 2.5px solid #C9A227; color: #C9A227;
                 border-radius: 10px; padding: 4px 12px; font-weight: 700; font-size: 13px;
                 letter-spacing: .22em; transform: rotate(8deg); background: rgba(255,255,255,.92);
                 font-family: 'Plus Jakarta Sans', sans-serif; }
        .r-body { padding: 26px 30px 8px; }
        .r-couple { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700;
                    font-size: 1.18rem; color: #0F172A; text-align: center; letter-spacing: -.01em; }
        .r-wedding { text-align: center; color: #64748B; font-size: 13px; margin-top: 3px; }
        .r-amount { text-align: center; margin: 20px 0 6px; }
        .r-amount .amt { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700;
                         font-size: 2.1rem; color: #065F46; letter-spacing: -.02em; }
        .r-amount .via { font-size: 12.5px; color: #64748B; }
        .badge-mpesa { display: inline-block; background: #E8F7EE; color: #059669; border-radius: 999px;
                       padding: 3px 12px; font-size: 12px; font-weight: 600; margin-top: 6px; }
        .badge-cash  { display: inline-block; background: #EAF2FE; color: #1D4ED8; border-radius: 999px;
                       padding: 3px 12px; font-size: 12px; font-weight: 600; margin-top: 6px; }
        .badge-bank  { display: inline-block; background: #E0F2F1; color: #0E7490; border-radius: 999px;
                       padding: 3px 12px; font-size: 12px; font-weight: 600; margin-top: 6px; }
        .mpesa-code { font-family: 'Consolas', 'Menlo', monospace; font-size: 15px; letter-spacing: .12em;
                      color: #0F172A; font-weight: 600; }
        .r-rows { margin-top: 20px; border-top: 1px dashed #E2E8F0; padding-top: 14px; font-size: 13.5px; }
        .r-row { display: flex; justify-content: space-between; padding: 5px 0; }
        .r-row .k { color: #64748B; }
        .r-row .v { color: #0F172A; font-weight: 600; text-align: right; }
        .r-total { display: flex; justify-content: space-between; border-top: 1px dashed #E2E8F0;
                   margin-top: 10px; padding-top: 12px; font-size: 14px; }
        .r-total .bal { color: #B45309; font-weight: 700; }
        .r-foot { text-align: center; color: #94A3B8; font-size: 11.5px; padding: 16px 30px 24px; line-height: 1.55; }
        .r-actions { text-align: center; padding: 0 30px 24px; }
        .btn-wa { background: #25D366; color: #fff; border-radius: 999px; font-weight: 600; font-size: 13.5px;
                  padding: 8px 20px; box-shadow: 0 6px 16px rgba(37, 211, 102, .3); border: 0; }
        .btn-wa:hover { background: #1DA851; color: #fff; }
        .btn-print { background: transparent; color: #065F46; border: 1.5px solid #065F46;
                     border-radius: 999px; font-weight: 600; font-size: 13.5px; padding: 8px 20px; }
        .btn-print:hover { background: #065F46; color: #fff; }
        @media print {
            body { background: #fff; padding: 0; }
            .receipt { box-shadow: none; max-width: 100%; border-radius: 0; }
            .r-actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="r-head">
            <img src="../images/logo/everafter-crest.png" alt="Everafter Planner">
            <div class="brand">Everafter Planner</div>
            <div class="doc">PAYMENT RECEIPT &middot; <?= htmlspecialchars($receipt_no); ?></div>
        </div>
        <div class="stamp">PAID</div>
        <div class="r-body">
            <div class="r-couple"><?= htmlspecialchars($couple); ?></div>
            <div class="r-wedding"><i class="mdi mdi-calendar-heart mr-1"></i><?= htmlspecialchars($wedding_human); ?></div>

            <div class="r-amount">
                <div class="amt"><?= format_ksh($item->payment, 2); ?></div>
                <div class="via">received via <?= htmlspecialchars(strtolower($method_label)); ?></div>
                <?php if ($is_mpesa): ?>
                    <div class="badge-mpesa"><i class="mdi mdi-cellphone mr-1"></i>M-Pesa</div>
                    <div class="mt-2 mpesa-code"><?= htmlspecialchars($item->mpesa_code); ?></div>
                <?php elseif ($method_label === 'Cash payment'): ?>
                    <div class="badge-cash"><i class="mdi mdi-cash mr-1"></i>Cash</div>
                <?php else: ?>
                    <div class="badge-bank"><i class="mdi mdi-bank mr-1"></i>Bank transfer</div>
                <?php endif; ?>
            </div>

            <div class="r-rows">
                <div class="r-row"><span class="k">Ceremony</span><span class="v"><?= htmlspecialchars($event_name); ?></span></div>
                <div class="r-row"><span class="k">Date paid</span><span class="v"><?= format_date_kd($item->date_issue, true); ?></span></div>
                <?php if ($planner_name !== ''): ?>
                    <div class="r-row"><span class="k">Planner</span><span class="v"><?= htmlspecialchars($planner_name); ?></span></div>
                <?php endif; ?>
            </div>

            <div class="r-total">
                <span>Package total</span>
                <span><?= format_ksh($package_price, 2); ?></span>
            </div>
            <div class="r-total">
                <span>Paid to date</span>
                <span style="color:#065F46; font-weight:700;"><?= format_ksh($amount_paid, 2); ?></span>
            </div>
            <div class="r-total">
                <span>Balance remaining</span>
                <span class="bal"><?= format_ksh($balance_due, 2); ?></span>
            </div>
        </div>
        <div class="r-actions">
            <a class="btn btn-wa" href="<?= htmlspecialchars(whatsapp_share_link($share_text)); ?>" target="_blank" rel="noopener">
                <i class="mdi mdi-whatsapp mr-1"></i>Share on WhatsApp
            </a>
            <button type="button" class="btn btn-print ml-2" onclick="window.print();">
                <i class="mdi mdi-printer mr-1"></i>Print
            </button>
        </div>
        <div class="r-foot">
            Thank you for choosing Everafter Planner.<br>
            This receipt is computer-generated &mdash; <?= htmlspecialchars($receipt_no); ?>
        </div>
    </div>
</body>
</html>
