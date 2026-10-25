<?php
/**
 * M-Pesa C2B callback endpoint (Daraja).
 *
 * Register this URL on the Daraja portal as BOTH the Validation and
 * Confirmation URL for your Paybill / Till:
 *
 *   https://your-domain/mpesa_c2b.php
 *
 * Safaricom POSTs a JSON notification for every customer payment:
 *   {
 *     "TransactionType": "Pay Bill",
 *     "TransID": "QKJ7GH9P2W",           ← the 10-char code users type
 *     "TransTime": "20260910143022",
 *     "TransAmount": "7999.00",
 *     "BusinessShortCode": "123456",
 *     "BillRefNumber": "WPMS",           ← the account number the payer typed
 *     "InvoiceNumber": "",
 *     "OrgAccountBalance": "0.00",
 *     "ThirdPartyTransactionID": "",
 *     "MSISDN": "254712345678",
 *     "FirstName": "Jane", "MiddleName": "", "LastName": "Wanjiku"
 *   }
 *
 * The payload is validated, stored in tblmpesa_callbacks and immediately
 * available to MpesaC2B::verify() — no admin copy-paste involved.
 *
 * CONTRACT (do not break): Daraja expects HTTP 200 with
 *   {"ResultCode": 0, "ResultDesc": "..."}  to accept,
 *   {"ResultCode": 1, "ResultDesc": "..."}  to reject (C2B validation only).
 * Any non-200 makes Safaricom retry, so this endpoint NEVER 500s — failures
 * are logged (tblmpesa_callback_log) and answered with a polite 200.
 *
 * Environment (see README §Configuration):
 *   WPMS_MPESA_VERIFY      1 = gate subscription/payment entry on verified codes
 *   WPMS_MPESA_SHORTCODE   expected business short code ("123456"); when set,
 *                          callbacks for other short codes are rejected
 *   WPMS_MPESA_CALLBACK_PASS  optional shared secret: when set, the URL must
 *                          be called as mpesa_c2b.php?key=<secret>
 */

declare(strict_types=1);

// Standalone endpoint: no session, no bootstrap that could emit output.
require_once __DIR__ . '/admin/include/config.php';
require_once __DIR__ . '/admin/include/database.php';
require_once __DIR__ . '/admin/include/db_object.php';
require_once __DIR__ . '/admin/include/Helper.php';
require_once __DIR__ . '/admin/include/Monetization.php';
require_once __DIR__ . '/admin/include/PublicGuard.php';

/** Reply to Daraja and stop. */
function mpesa_reply(int $code, string $desc): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ResultCode' => $code, 'ResultDesc' => $desc]);
    exit;
}

try {
    global $db;
    $db = new Database();
} catch (Throwable $e) {
    error_log('[WPMS][mpesa_c2b] DB unavailable: ' . $e->getMessage());
    mpesa_reply(0, 'Accepted'); // accept-and-log-later: never make Safaricom retry-spam a down DB
}

// Optional shared-secret gate (belt & braces on top of the payload allow-list).
$secret = (string) getenv('WPMS_MPESA_CALLBACK_PASS');
if ($secret !== '' && !hash_equals($secret, (string) ($_GET['key'] ?? ''))) {
    error_log('[WPMS][mpesa_c2b] bad or missing callback key');
    mpesa_reply(1, 'Rejected');
}

// Only Daraja's POSTs are meaningful; GET probes get a friendly 200 too.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mpesa_reply(0, 'Everafter Planner M-Pesa callback — POST Daraja C2B notifications here');
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    error_log('[WPMS][mpesa_c2b] non-JSON body: ' . substr($raw, 0, 300));
    mpesa_reply(1, 'Rejected: body must be a Daraja C2B JSON notification');
}

// Optional short-code pin: refuse payments to a different till/paybill.
$expectedSC = trim((string) getenv('WPMS_MPESA_SHORTCODE'));
if ($expectedSC !== '' && trim((string) ($data['BusinessShortCode'] ?? '')) !== $expectedSC) {
    error_log('[WPMS][mpesa_c2b] unexpected short code: ' . ($data['BusinessShortCode'] ?? '?'));
    mpesa_reply(1, 'Rejected: unknown business short code');
}

/*
 * Shared abuse guard (PublicGuard, v2.14). Everything below this line WRITES:
 * a forensic row now, and — when a payload matches a real booking reference —
 * a receipt bell and email. Over the ceiling this caller writes nothing at all,
 * and the answer stays 200 because Safaricom treats anything else as a reason
 * to retry-storm us. The identifiers are logged first, so a genuine notification
 * refused under a flood can still be reconciled by hand.
 */
$guardWait = PublicGuard::attempt(PublicGuard::CALLBACK, 'c2b');
if ($guardWait > 0) {
    error_log(sprintf(
        '[WPMS][mpesa_c2b] flood guard: caller paused for %ds — payload NOT logged or ingested (TransID %s, amount %s)',
        $guardWait,
        (string) ($data['TransID'] ?? '?'),
        (string) ($data['TransAmount'] ?? '?')
    ));
    mpesa_reply(0, 'Accepted');
}

// Keep the raw payload for forensics before any validation decision.
// Forensics must never break ingestion: if the log table is missing (migration
// not run yet) we log to the error log and carry on.
try {
    $logStmt = $db->prepare(
        'INSERT INTO tblmpesa_callback_log (direction, payload, note) VALUES (:dir, :payload, :note)'
    );
    $logStmt->execute([':dir' => 'in', ':payload' => $raw, ':note' => 'received']);
} catch (Throwable $e) {
    error_log('[WPMS][mpesa_c2b] log write failed (run migrations/v2.1_phase1_hardening.sql?): ' . $e->getMessage());
}

/*
 * ingest() validates and stores, and returns its refusals as data — but it is
 * still PHP running against a live database, and an unexpected Throwable here
 * (a fatal in a helper, a driver error outside its PDOException catch) would
 * escape as HTTP 500. That breaks the one contract this endpoint has with
 * Safaricom: any non-200 is a reason to retry, and a retry storm against a
 * broken path is how a payment outage becomes a handset outage. The raw payload
 * is already in tblmpesa_callback_log by this point, so accepting the
 * notification and logging the failure leaves it reconcilable by hand.
 */
try {
    $result = MpesaC2B::ingest($data);
} catch (Throwable $e) {
    error_log('[WPMS][mpesa_c2b] ingest threw (' . $e->getMessage() . ') for TransID '
        . (string) ($data['TransID'] ?? '?') . ' — payload kept in tblmpesa_callback_log');
    mpesa_reply(0, 'Accepted');
}
if (!$result['ok']) {
    error_log('[WPMS][mpesa_c2b] ingest rejected: ' . ($result['error'] ?? '?'));
    mpesa_reply(1, 'Rejected: ' . ($result['error'] ?? 'invalid payload'));
}

mpesa_reply(0, 'Accepted');
