<?php
include 'include/init.php';
/**
 * Open one deal conversation and mark it read (v2.25).
 *
 * The ledgers are tables of deals; a thread only earns its space when somebody
 * wants it. This tiny door is the "open" click:
 *
 *   1. authorise exactly as the POST endpoint does (VendorThread::authorize),
 *      so a couple cannot clear a badge on another couple's deal and a vendor
 *      cannot touch a listing they do not own;
 *   2. stamp the unread messages as read for THIS role only;
 *   3. send the reader back to their own ledger with that row's thread open
 *      (`?thread=N`), where the panel renders and the anchor lands on the row.
 *
 * The view the link came from travels with it (`status`, `unread`) so a planner
 * working the unread queue stays in the queue after reading a thread. Only
 * those two are accepted, and the landing URL is built from the deal's own ids
 * — never from request data — so nothing here can re-point the redirect.
 *
 * It changes nothing but read state, which is why it is a GET: it is the click
 * behind a notification ("2 new messages"), and it must work as a plain link
 * on pages that do not run any JavaScript of their own.
 */
$role = VendorThread::session_role();
if ($role === null) {
    redirect_to('../');
    exit;
}
if (staff_paywall_locked()) {
    redirect_to('subscription.php');
    exit;
}

$home = match ($role) {
    VendorThread::COUPLE  => 'client.php',
    VendorThread::VENDOR  => 'vendor_home.php',
    VendorThread::PLANNER => 'vendor_enquiries.php',
    default               => '../',
};

$dealId    = (int) ($_GET['deal_id'] ?? 0);
$sessionId = (int) ($_SESSION['id'] ?? 0);
$deal      = VendorThread::authorize($dealId, $role, $sessionId);

if ($deal === null) {
    $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> That enquiry is not yours to open.</div>');
    redirect_to($home);
    exit;
}

VendorThread::mark_read($dealId, $role);

/*
 * Preserve the view that sent the reader here, within a whitelist. A bell or a
 * badge passes nothing, and lands on the plain ledger.
 */
$keep = [];
$keep_status = clean($_GET['status'] ?? '');
if (in_array($keep_status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
    $keep['status'] = $keep_status;
}
if ((int) ($_GET['unread'] ?? 0) === 1) {
    $keep['unread'] = 1;
}

redirect_to(VendorThread::landing($deal, $role, ['thread' => $dealId] + $keep));
exit;
