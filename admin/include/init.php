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
/*
 * Every constant below is guarded: a headless entry point (mcp.php) or a test
 * that required models.php first has already defined DS, and an unguarded
 * redefinition raised "Constant DS already defined" on PHP 8.2. The guards are
 * order-independent, so init.php can be loaded before OR after models.php.
 */
if (!defined('WPMS_DEBUG')) {
    define('WPMS_DEBUG', $debug);
}
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
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__));  // …/Wedding/admin
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', __DIR__);        // …/Wedding/admin/include
}
/*
 * The model/class include set now lives in ONE place — models.php — because
 * mcp.php (the MCP endpoint) must load the same classes WITHOUT the session
 * and the redirect guards below. It was a hand-kept second list before, and a
 * missing file there is exactly how "Class "Accounts" not found" reached a
 * couple's token. Everything that used to be required here (and the notes on
 * why each one matters) is in models.php, in the same order.
 */
require_once(INCLUDES_PATH.DS."models.php");

/*
 * ApiToken must come BEFORE McpApprovals: the approval table is judged
 * installed through ApiToken::AUDIT_TABLE (the audit columns v2.17 adds), and
 * available() catches Throwable — so a web request that had McpApprovals but
 * not ApiToken did not crash, it answered "Not installed yet" on
 * admin/mcp_approvals.php and silently reported 0 on the sidebar's waiting
 * badge. mcp.php loads both itself (it never uses this file); this is the web
 * side of the same pair, and it is a class definition with no side effects.
 */
require_once(INCLUDES_PATH.DS."ApiToken.php");
/*
 * McpApprovals is the human half of a Tier-3 MCP write. The write tools that
 * touch money or messages (send_bulk_sms, record_payment, initiate_stk_deposit,
 * assign_planner) refuse to act on their own authority: they record a request and
 * a signed-in planner approves that exact payload on admin/mcp_approvals.php,
 * which mints a short-lived, single-use, HMAC-signed string. That page and the
 * sidebar's "waiting" badge both need this class, and it is a class definition
 * with no side effects at include time, so it belongs here with the rest of the
 * web bootstrap rather than as a per-page require.
 */
require_once(INCLUDES_PATH.DS."McpApprovals.php");

/*
 * Role predicates (is_staff / is_vendor / is_super_admin) live in Helper.php:
 * they are pure $_SESSION reads, and this bootstrap is web-request-only while
 * Helper.php is ALSO loaded by CLI scripts, PHPUnit and composer's files
 * autoload. A predicate left here would work in a browser and then fatal with
 * "Call to undefined function is_staff()" for the first command-line caller.
 * The guards below may stay: they HTTP-redirect, so they are web-only anyway.
 */

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
 * subscription_in_grace, require_staff_subscribed, staff_paywall_locked,
 * is_super_admin, subscription_banner) live in Helper.php so CLI scripts,
 * tests and composer's files-autoload all see them — this file is
 * web-request-only.
 */

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
        // The operator is never sent to the paywall (see is_super_admin()).
        $locked = !is_super_admin() && ($s['state'] === 'locked' || $trialFinalDay);
        $page = $locked ? 'subscription.php' : 'dashboard.php';
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

/**
 * Per-planner scoping AT THE DOOR — one check that covers every staff page.
 *
 * `booking_visible_to_session()` answers the question for a page that already
 * resolved its wedding, and that is what the couple pages, the venue and guest
 * sheets, the MCP tools and admin/mcp_approvals.php all call. But the staff
 * back office has eighteen pages that take a `booking_id` (budget, guests,
 * gallery, SMS centre, RSVP tools, the calendar…) and each one resolves the
 * row its own way. Adding the predicate to each of them is eighteen chances to
 * forget one — and the nineteenth page, written next month, would simply not
 * have it.
 *
 * So the rule is applied once, here, to the REQUEST: a scoped planner must be
 * able to open the wedding they are naming, whichever page they are naming it
 * to, in the query string or in a form. Nothing else changes for a super admin,
 * a couple, a vendor, an install with `WPMS_PLANNER_SCOPING=0`, or a request
 * that names no booking at all (every list page).
 *
 * A refusal is the house refusal: a flash message and back to the bookings list,
 * never a blank 403 — the planner needs to know why the page they clicked did
 * not open. It runs before any page body, so nothing is read or written first.
 *
 * This lives in init.php (not Helper.php) because it needs `$session` and it
 * REDIRECTS — the same reason require_staff() lives here while the predicates
 * themselves live in Helper.php for the CLI and test tier.
 */
function planner_scope_guard_request(): void
{
    if (planner_scope_planner_id() <= 0) {
        return; // not a scoped planner: nothing to narrow
    }
    /*
     * `booking` is the same wedding under a second name: client_assign.php and
     * client_edit.php link to `?booking=N`, and while this guard read only
     * `booking_id` those two pages were a hole in the rule it exists to apply.
     * A scoped planner could open the assignment form for somebody else's
     * wedding and POST it — and that form WRITES: it sets bride/groom/date and
     * reassigns `organizer_id`, so the bypass did not just reveal the row, it
     * could take the wedding over. Both names, GET and POST, now name the
     * wedding the guard checks.
     */
    $requested = (int) (
        $_POST['booking_id'] ?? $_GET['booking_id']
        ?? $_POST['booking']    ?? $_GET['booking']
        ?? 0
    );
    if ($requested <= 0) {
        return; // a list page, or a page that takes no wedding
    }
    if (booking_visible_to_session(Booking::find_by_booking_id($requested))) {
        return;
    }

    global $session;

    /*
     * WHICH refusal, when both apply. A lapsed planner is turned away by the
     * paywall, and that gate outranks scoping: they cannot use the product at
     * all, so the honest answer is the screen where they can pay — not a
     * bookings list that would bounce them there a hop later. (It also keeps the
     * paywall's deep-link guarantee intact: a locked planner aiming a booking id
     * at any staff page still lands on subscription.php in one step.)
     */
    if (staff_paywall_locked()) {
        redirect_to('subscription.php');
    }

    if (isset($session) && $session instanceof Session) {
        $session->message('<div class="alert alert-danger"><strong><i class="mdi mdi-lock"></i></strong> '
            . 'Wedding #' . $requested . ' is not one of yours. Ask an administrator to assign the planner on it if it should be.</div>');
    }
    redirect_to('client.php');
}

planner_scope_guard_request();
?>