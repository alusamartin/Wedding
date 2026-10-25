<?php
/**
 * M-Pesa STK push result callback (Daraja Lipa na M-Pesa Online).
 *
 * Register https://your-domain/mpesa_stk_callback.php as the CallBackURL of
 * the STK push (MpesaStk sends it). Daraja POSTs the result of every prompt —
 * success, customer-cancelled, timeout or failure:
 *
 *   {
 *     "Body": {
 *       "stkCallback": {
 *         "MerchantRequestID": "...",
 *         "CheckoutRequestID": "ws_CO_...",   ← matches tblmpesa_stk_pushes
 *         "ResultCode": 0,                    ← 0 = paid; 1032 cancelled; 1037 timeout
 *         "ResultDesc": "The service request is processed successfully.",
 *         "CallbackMetadata": { "Item": [
 *             { "Name": "Amount", "Value": 7999.00 },
 *             { "Name": "MpesaReceiptNumber", "Value": "QKJ7GH9P2W" },
 *             { "Name": "PhoneNumber", "Value": 254712345678 } ] }
 *       }
 *     }
 *   }
 *
 * CONTRACT: answer HTTP 200 (any body) so Daraja does not retry — failures
 * are logged and answered politely, never a 500.
 *
 * Environment (see README §Configuration):
 *   WPMS_MPESA_STK_CALLBACK_PASS  optional shared secret: when set, the URL
 *                                 must be called as mpesa_stk_callback.php?key=<secret>
 */

declare(strict_types=1);

// Standalone endpoint: no session, no bootstrap that could emit output.
// Beyond Monetization, fulfilment needs the classes it books through:
// Liquidation (deposit rows), Booking/Categories (deposit validation) and
// Notification (bell + email receipts) — none load without init.php here.
require_once __DIR__ . '/admin/include/config.php';
require_once __DIR__ . '/admin/include/database.php';
require_once __DIR__ . '/admin/include/db_object.php';
require_once __DIR__ . '/admin/include/Helper.php';
require_once __DIR__ . '/admin/include/Monetization.php';
require_once __DIR__ . '/admin/include/Liquidation.php';
require_once __DIR__ . '/admin/include/Booking.php';
require_once __DIR__ . '/admin/include/Categories.php';
require_once __DIR__ . '/admin/include/Notification.php';
require_once __DIR__ . '/admin/include/PublicGuard.php';

/** Acknowledge to Daraja and stop. */
function stk_reply(int $code, string $desc): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ResultCode' => $code, 'ResultDesc' => $desc]);
    exit;
}

try {
    global $db;
    $db = new Database();
} catch (Throwable $e) {
    error_log('[WPMS][mpesa_stk_callback] DB unavailable: ' . $e->getMessage());
    stk_reply(0, 'Accepted'); // never make Safaricom retry-spam a down DB
}

// Optional shared-secret gate (belt & braces on top of the payload shape check).
$secret = (string) getenv('WPMS_MPESA_STK_CALLBACK_PASS');
if ($secret !== '' && !hash_equals($secret, (string) ($_GET['key'] ?? ''))) {
    error_log('[WPMS][mpesa_stk_callback] bad or missing callback key');
    stk_reply(1, 'Rejected');
}

// Only Daraja's POSTs are meaningful; GET probes get a friendly 200 too.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stk_reply(0, 'Everafter Planner M-Pesa STK callback — Daraja posts push results here');
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    error_log('[WPMS][mpesa_stk_callback] non-JSON body: ' . substr($raw, 0, 300));
    stk_reply(1, 'Rejected: body must be a Daraja STK result notification');
}

/*
 * Shared abuse guard (PublicGuard, v2.14). Everything below this line WRITES:
 * a forensic row now, and — when the CheckoutRequestID is a live push — a
 * fulfilment receipt (bell + email). Over the ceiling this caller writes
 * nothing, and the answer stays 200 because Daraja treats anything else as a
 * reason to retry-storm us. Logged with the identifiers so a genuine result
 * refused under a flood can still be reconciled by hand.
 */
$guardWait = PublicGuard::attempt(PublicGuard::CALLBACK, 'stk');
if ($guardWait > 0) {
    $peek = $data['Body']['stkCallback'] ?? [];
    error_log(sprintf(
        '[WPMS][mpesa_stk_callback] flood guard: caller paused for %ds — payload NOT logged or applied (CheckoutRequestID %s)',
        $guardWait,
        (string) (($peek['CheckoutRequestID'] ?? '') !== '' ? $peek['CheckoutRequestID'] : '?')
    ));
    stk_reply(0, 'Accepted');
}

// Keep the raw payload for forensics before any validation decision.
try {
    $logStmt = $db->prepare(
        'INSERT INTO tblmpesa_callback_log (direction, payload, note) VALUES (:dir, :payload, :note)'
    );
    $logStmt->execute([':dir' => 'in', ':payload' => $raw, ':note' => 'stk callback']);
} catch (Throwable $e) {
    error_log('[WPMS][mpesa_stk_callback] log write failed: ' . $e->getMessage());
}

$cb        = $data['Body']['stkCallback'] ?? null;
$checkout  = trim((string) ($cb['CheckoutRequestID'] ?? ''));
$resultCode = (int) ($cb['ResultCode'] ?? -1);
$resultDesc = trim((string) ($cb['ResultDesc'] ?? ''));
if ($checkout === '') {
    error_log('[WPMS][mpesa_stk_callback] callback without CheckoutRequestID');
    stk_reply(1, 'Rejected: missing CheckoutRequestID');
}

// Pull the receipt out of CallbackMetadata.Item (success callbacks only).
$receipt = null;
foreach (($cb['CallbackMetadata']['Item'] ?? []) as $item) {
    if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
        $receipt = trim((string) ($item['Value'] ?? ''));
        break;
    }
}

$result = MpesaStk::apply_callback($checkout, $resultCode, $resultDesc, $receipt !== '' ? $receipt : null);
if (!$result['ok']) {
    error_log('[WPMS][mpesa_stk_callback] apply failed: ' . ($result['error'] ?? '?'));
    stk_reply(1, 'Rejected: ' . ($result['error'] ?? 'could not apply result'));
}

stk_reply(0, 'Accepted');
