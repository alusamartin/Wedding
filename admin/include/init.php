<?php
ob_start();
date_default_timezone_set('Africa/Nairobi');
$webroot = "C:/xampp/htdocs/";
define('DS', DIRECTORY_SEPARATOR);
define('SITE_ROOT', $webroot.DS.'Wedding'.DS.'admin');
define('INCLUDES_PATH', SITE_ROOT.DS.'include');
require_once(INCLUDES_PATH.DS."Helper.php");
require_once(INCLUDES_PATH.DS."config.php");
require_once(INCLUDES_PATH.DS."database.php");
require_once(INCLUDES_PATH.DS."db_object.php");
require_once(INCLUDES_PATH.DS."Session.php");
require_once(INCLUDES_PATH.DS."Accounts.php");
require_once(INCLUDES_PATH.DS."Account_Details.php");
require_once(INCLUDES_PATH.DS."Booking.php");
require_once(INCLUDES_PATH.DS."Guest.php");
require_once(INCLUDES_PATH.DS."Categories.php");
require_once(INCLUDES_PATH.DS."Features.php");
require_once(INCLUDES_PATH.DS."EventWedding.php");
require_once(INCLUDES_PATH.DS."Gallery.php");
require_once(INCLUDES_PATH.DS."Users.php");
require_once(INCLUDES_PATH.DS."Events.php");
require_once(INCLUDES_PATH.DS."Liquidation.php");
require_once(INCLUDES_PATH.DS."Monetization.php");
require_once(INCLUDES_PATH.DS."Notification.php");
require_once(INCLUDES_PATH.DS."PasswordReset.php");

/**
 * Is the current session a staff (planner/admin) account?
 * Client accounts (tblaccounts) are stamped privilege '0'; staff (tblusers)
 * carry a numeric access_level (1 = planner, 2 = super admin) or a legacy
 * designation string.
 */
function is_staff(): bool
{
    if (!isset($_SESSION['id'])) {
        return false;
    }
    $level = $_SESSION['privilege_level'] ?? 'user';
    if ((string) $level === '0') {
        return false;
    }
    if (is_numeric($level)) {
        return (int) $level >= 1;
    }
    return in_array(
        strtolower((string) $level),
        ['super admin', 'admin', 'planner', 'wedding planner', 'staff'],
        true
    );
}

/** Gate for staff-only pages. */
function require_staff(): void
{
    if (!is_staff()) {
        redirect_to('../');
        exit;
    }
}

/**
 * Where should this authenticated user land? One answer for every caller —
 * the unified login POST and the already-signed-in GET guard on both login
 * pages. Staff without an active subscription are routed to the subscription
 * page first (first login or expired); everyone else to their role's home.
 *
 * $from_admin_dir: caller lives under /admin/ so Location paths resolve
 * against that directory (redirect_to() emits them verbatim).
 */
function login_redirect_target(bool $staff, int $user_id, bool $from_admin_dir = false): string
{
    if ($staff) {
        $page = Subscription::get_active($user_id) ? 'dashboard.php' : 'subscription.php';
        return $from_admin_dir ? $page : 'admin/' . $page;
    }
    return $from_admin_dir ? 'client.php' : 'admin/client.php';
}
?>