<?php
ob_start();
date_default_timezone_set('Africa/Nairobi');

/*
 * Error display: OFF unless WPMS_DEBUG is explicitly enabled. A stray
 * warning on a hosted site would leak paths and SQL to visitors; everything
 * still lands in the PHP error log either way. Set WPMS_DEBUG=1 only on a
 * dev machine (it also makes database.php die() with the real PDO message).
 */
$debug = in_array(strtolower(trim((string) getenv('WPMS_DEBUG'))), ['1', 'true', 'yes', 'on'], true);
define('WPMS_DEBUG', $debug);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

/*
 * Portable install root — resolved from THIS file's location, so the project
 * runs from any folder name, any drive, any host (XAMPP subfolder, a vhost
 * docroot, /home/user/public_html, …) with zero edits. Previously this was
 * hard-coded to C:/xampp/htdocs/Wedding and broke on every other layout.
 */
define('DS', DIRECTORY_SEPARATOR);
define('SITE_ROOT', dirname(__DIR__));      // …/Wedding/admin
define('INCLUDES_PATH', __DIR__);            // …/Wedding/admin/include
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
require_once(INCLUDES_PATH.DS."InstallmentPlan.php");
require_once(INCLUDES_PATH.DS."Categories.php");
require_once(INCLUDES_PATH.DS."Features.php");
require_once(INCLUDES_PATH.DS."EventWedding.php");
require_once(INCLUDES_PATH.DS."Gallery.php");
require_once(INCLUDES_PATH.DS."Users.php");
require_once(INCLUDES_PATH.DS."Events.php");
require_once(INCLUDES_PATH.DS."Liquidation.php");
require_once(INCLUDES_PATH.DS."LoginThrottle.php");
require_once(INCLUDES_PATH.DS."SignupGuard.php");
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

/*
 * Subscription grace/gate helpers (subscription_state_for_session,
 * subscription_in_grace, require_staff_subscribed, subscription_banner)
 * live in Helper.php so CLI scripts, tests and composer's files-autoload
 * all see them — this file is web-request-only.
 */

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
        // Trial-aware routing: a NEW self-serve planner (trial in its final
        // day) lands on subscription.php — the activation funnel — while
        // earlier trial days, paid plans and grace all go to the dashboard
        // with banners. Locked (trial expired + grace elapsed, or never
        // paid) is diverted to subscription.php first, as before.
        $s = Subscription::state((int) $user_id);
        $trialFinalDay = !empty($s['trial']) && $s['state'] === 'active' && $s['days_left'] === 0;
        $page = ($s['state'] === 'locked' || $trialFinalDay) ? 'subscription.php' : 'dashboard.php';
        return $from_admin_dir ? $page : 'admin/' . $page;
    }
    return $from_admin_dir ? 'client.php' : 'admin/client.php';
}

/**
 * Where a vendor account (tblusers, access_level 0) lands. Registration is
 * optional — an unregistered vendor starts at vendor_register.php; a
 * registered one at their bookings dashboard. Subscription rules do NOT
 * apply to vendors (they are paid via commission, not SaaS).
 */
function vendor_redirect_target(int $user_id, bool $from_admin_dir = false): string
{
    $page = vendor_profile_of($user_id) !== null ? 'vendor_home.php' : 'vendor_register.php';
    return $from_admin_dir ? $page : 'admin/' . $page;
}
?>