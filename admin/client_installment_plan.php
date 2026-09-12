<?php
/**
 * Couple-scoped installment-plan actions. POST + CSRF; the booking must
 * belong to the session client (staff may set a plan while assisting a
 * couple). One action per POST:
 *   months=N  -> create/replace the active plan (2-12 months)
 *   cancel=1  -> remove the plan (nudge falls back to deadline bands)
 */
include 'include/init.php';

$booking_id = (int) ($_GET['booking_id'] ?? 0);
$user_id    = (int) ($_GET['user_id'] ?? 0);
$links      = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

if (!isset($_SESSION['id'])) {
    redirect_to("../");
    die();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to("client_manage_account_details.php?" . $links);
    die();
}
$booking = Booking::find_by_booking_id($booking_id);
if (!is_staff()) {
    if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
        redirect_to("client.php");
        die();
    }
} elseif (!$booking) {
    redirect_to("client.php");
    die();
}
if (!$session->verify_csrf()) {
    $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Invalid security token. Please try again.</div>');
    redirect_to("client_manage_account_details.php?" . $links);
    die();
}

if (isset($_POST['cancel'])) {
    InstallmentPlan::cancel($booking_id);
    $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Installment plan removed — the payment guide is back to the standard pace.'
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
} else {
    $months = (int) ($_POST['months'] ?? 0);
    // Validate against the supported lengths instead of silently clamping:
    // a 1- or 13-month request is a mangled form, not a wish for 2/12.
    $plan = null;
    if (in_array($months, InstallmentPlan::MONTHS_CHOICES, true)) {
        $plan = InstallmentPlan::create_plan($booking_id, $months);
    }
    if ($plan) {
        $session->message('<div class="alert alert-success alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-check"></i></strong> Your plan is set: '
            . $plan->months . ' × KSh ' . number_format($plan->monthly_amount)
            . ' a month. The overview now tracks your progress against it.'
            . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    } else {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-alert"></i></strong> Could not set that plan — check that your package has a balance due.</div>');
    }
}
redirect_to("client_manage_account_details.php?" . $links);
die();
