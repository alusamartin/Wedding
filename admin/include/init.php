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
require_once(INCLUDES_PATH.DS."Checklist.php");
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
 * Is the current session a vendor account?
 * Vendors are staff rows (tblusers) with access_level 0 — planners/admins
 * are 1/2. Clients (tblaccounts) are ALSO stamped '0', so the account_type
 * flag set at login is the only reliable discriminator: clients are never
 * vendors even though the numeric level matches.
 */
function is_vendor(): bool
{
    if (!isset($_SESSION['id']) || is_staff()) {
        return false;
    }
    if (($_SESSION['account_type'] ?? '') !== 'staff') {
        return false; // client session (tblaccounts) — never a vendor
    }
    $level = $_SESSION['privilege_level'] ?? 'user';
    return is_numeric($level) && (int) $level === 0;
}

/** Gate for vendor self-service pages: only vendor accounts (access_level 0). */
function require_vendor(): void
{
    if (!is_vendor()) {
        redirect_to('../');
        exit;
    }
}

/**
 * Does this staff-row user own a vendor profile? (Vendors AND staff can call
 * this — staff manage vendor records on their behalf.)
 */
function vendor_profile_of(int $user_id): ?object
{
    return Vendor::get_by_user($user_id); // null = not registered as a vendor
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

/**
 * Where a vendor account (tblusers, access_level 0) lands. Registration is
 * optional — an unregistered vendor starts at vendor_register.php; a
 * registered one at their bookings dashboard.
 */
function vendor_redirect_target(int $user_id, bool $from_admin_dir = false): string
{
    $page = vendor_profile_of($user_id) !== null ? 'vendor_home.php' : 'vendor_register.php';
    return $from_admin_dir ? $page : 'admin/' . $page;
}
?>