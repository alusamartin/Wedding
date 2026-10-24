<?php
include 'include/init.php';
/**
 * The single WRITE door for a deal conversation (v2.25).
 *
 * Every side posts here — the couple from the marketplace, the vendor from
 * My Bookings, the planner from the enquiry ledger — and the endpoint decides,
 * from the SESSION and never from the request, which side the writer is:
 *
 *   - couple  : a tblaccounts session; the deal must belong to their booking
 *   - vendor  : a vendor listing owner; the deal must be with their listing
 *   - planner : staff who may open the deal's booking (per-planner scoping
 *               and the super-admin bypass come from booking_visible_to_session)
 *
 * POST + CSRF, as every other write in this admin does. The message and the
 * bells for the other two parties are one act inside VendorThread::post(), so
 * no caller can send without notifying and no notification can claim a message
 * that was not stored.
 *
 * A successful post returns to the writer's own page with that thread open, so
 * the reply they just sent is on screen (and the stage strip has already moved
 * the ball to the other side).
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

/** This role's own ledger, for every "go back and look" case below. */
$home = match ($role) {
    VendorThread::COUPLE  => 'client.php',
    VendorThread::VENDOR  => 'vendor_home.php',
    VendorThread::PLANNER => 'vendor_enquiries.php',
    default               => '../',
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // A GET here is somebody typing the URL; it must never write a message.
    redirect_to($home);
    exit;
}

if (!VendorThread::installed()) {
    $session->message('<div class="alert alert-warning"><i class="mdi mdi-alert"></i> The conversation is not enabled on this database yet.</div>');
    redirect_to($home);
    exit;
}

$sessionId = (int) ($_SESSION['id'] ?? 0);
$dealId    = (int) ($_POST['deal_id'] ?? 0);

// Ownership first: a foreign or phantom deal never gets as far as a CSRF check,
// and never learns whether its token was good.
$deal = VendorThread::authorize($dealId, $role, $sessionId);
if ($deal === null) {
    $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> That enquiry is not yours to reply to.</div>');
    redirect_to($home);
    exit;
}

if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Invalid security token. Please try again.</div>');
    redirect_to(VendorThread::landing($deal, $role, ['thread' => $dealId]));
    exit;
}

$body     = VendorThread::clean_body((string) ($_POST['body'] ?? ''));
$vendor   = trim((string) ($deal->business_name ?? '')) ?: 'the vendor';
$couple   = trim((string) ($deal->bride ?? '') . ' & ' . (string) ($deal->groom ?? ''), ' &');
$couple   = $couple !== '' ? $couple : 'the couple';

if ($body === '') {
    $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> Write a message before sending.</div>');
    redirect_to(VendorThread::landing($deal, $role, ['thread' => $dealId]));
    exit;
}

$messageId = VendorThread::post($dealId, $role, $sessionId, $body);

if ($messageId <= 0) {
    $session->message('<div class="alert alert-danger"><i class="mdi mdi-alert"></i> We could not send that message. Please try again.</div>');
    redirect_to(VendorThread::landing($deal, $role, ['thread' => $dealId]));
    exit;
}

/*
 * Say who can see it, in the reader's own terms. "Your planner is copied" is
 * the promise the couple was sold; "the couple and their planner both read
 * this" is the one the vendor needs, because it replaces the phone call.
 */
$confirm = match ($role) {
    VendorThread::COUPLE  => 'Message sent to <strong>' . htmlspecialchars($vendor) . '</strong> — your planner is copied, so nothing is agreed behind your back.',
    VendorThread::VENDOR  => 'Reply sent — <strong>' . htmlspecialchars($couple) . '</strong> and their planner can see it now.',
    VendorThread::PLANNER => 'Sent — <strong>' . htmlspecialchars($couple) . '</strong> and <strong>' . htmlspecialchars($vendor) . '</strong> both read it.',
    default               => 'Message sent.',
};
$session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="mdi mdi-check-circle"></i> ' . $confirm
    . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');

redirect_to(VendorThread::landing($deal, $role, ['thread' => $dealId]));
exit;
