<?php
/**
 * WPMS Kenya v2.16 — the model include set, in one place (models.php)
 *
 * Every entry here is a CLASS with no side effects at include time: no session,
 * no output, no redirect, no query. That is what makes this list reusable by
 * entry points that are not a browser request — today mcp.php (the MCP endpoint,
 * which must not start a session or set a cookie), tomorrow a CLI tool or a
 * webhook — while admin/include/init.php keeps being the WEB bootstrap: it
 * requires this file and then adds the things only a page needs (the session,
 * and the redirect guards).
 *
 * WHY IT EXISTS. mcp.php's first version hand-listed the models its tools read,
 * and promptly broke on the couples' path: get_wedding() reaches tblaccounts
 * through the Accounts class, that file was not in the hand-written list, and a
 * perfectly valid token got `Class "Accounts" not found` — an internal error
 * where the honest answer was a wedding overview. A hand-kept second list of the
 * same files can only ever be a copy that drifts; there is one list now, and
 * both entry points read it.
 *
 * IMPORTANT HEADLESS CONSTRAINT: nothing here may touch $_SESSION or emit
 * output. Helper.php is required because the ownership predicates live in it
 * (booking_visible_to_session(), may_open_couple_page(), is_staff()…) and those
 * read $_SESSION — which is fine: in a headless request ApiToken::bind_principal()
 * has already filled $_SESSION as a plain array, and no session is ever started.
 *
 * ORDER MATTERS in two places, both documented at their entry below
 * (PackageCapacity before UserPrefs, HeadcountBrief before PackageCapacity), and
 * the order here is exactly the order init.php used before this file existed.
 *
 * DS is defined by init.php; a headless entry point that includes only this file
 * (mcp.php) must not have to remember that, so it is defined here too.
 */

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

require_once __DIR__ . DS . "Helper.php";
/*
 * Request is the null-safe superglobal reader (Request::post()/getInt()/…).
 * It has no dependencies and no side effects, so it loads with Helper.php and
 * is available to every web page, CLI script and the headless MCP entry point.
 */
require_once __DIR__ . DS . "Request.php";
require_once __DIR__ . DS . "config.php";
require_once __DIR__ . DS . "database.php";
require_once __DIR__ . DS . "db_object.php";
require_once __DIR__ . DS . "Session.php";
require_once __DIR__ . DS . "Accounts.php";
require_once __DIR__ . DS . "Account_Details.php";
require_once __DIR__ . DS . "Booking.php";
/*
 * BookingEvent had never been included here because nothing used the class: it
 * declared the tbleventbooking fields but did not extend DB_Object, so it could
 * not be instantiated into anything that persists. It is a real active record
 * now and the venue sheet's headcount plan reads through it (sheet_venue.php,
 * event_booking_save.php), so it has to be part of the runtime include set —
 * composer's classmap only serves the CLI/test tier.
 */
require_once __DIR__ . DS . "BookingEvent.php";
require_once __DIR__ . DS . "Guest.php";
require_once __DIR__ . DS . "Checklist.php";
require_once __DIR__ . DS . "InstallmentPlan.php";
require_once __DIR__ . DS . "Categories.php";
require_once __DIR__ . DS . "Features.php";
require_once __DIR__ . DS . "EventWedding.php";
require_once __DIR__ . DS . "Gallery.php";
require_once __DIR__ . DS . "Users.php";
require_once __DIR__ . DS . "Events.php";
require_once __DIR__ . DS . "Liquidation.php";
require_once __DIR__ . DS . "LoginThrottle.php";
require_once __DIR__ . DS . "SignupGuard.php";
/*
 * PublicGuard is the counter behind the anonymous doors (rsvp.php, site.php, the
 * M-Pesa callbacks) AND behind the MCP endpoint's per-token rate limit — it
 * belongs in the runtime include set for the same reason as the two above; the
 * callbacks, which never load init.php, require it themselves.
 */
require_once __DIR__ . DS . "PublicGuard.php";
/*
 * JobWatch is the heartbeat reader behind admin/job_watch.php and the sidebar's
 * Job Health badge: the scheduled jobs write a heartbeat into
 * tbljob_heartbeats (v2.15) and this class judges them, so the runtime include
 * set needs it for the same reason as the two guards above. The CLI watchdog
 * (scripts/job_watch.php) requires it itself.
 */
require_once __DIR__ . DS . "JobWatch.php";
require_once __DIR__ . DS . "Monetization.php";
require_once __DIR__ . DS . "Notification.php";
/*
 * HeadcountBrief turns a couple's headcount edit on the venue sheet into the
 * notification that brief is for (the assigned planner + the booked venue
 * contact, with only the functions whose numbers moved). event_booking_save.php
 * calls it, so it belongs in the runtime include set — composer's classmap only
 * serves the CLI/test tier, exactly as with BookingEvent above.
 */
require_once __DIR__ . DS . "HeadcountBrief.php";
/*
 * PackageCapacity is the other half of that question: HeadcountBrief says what
 * moved, PackageCapacity says whether what was sold still covers it (a package's
 * guest capacity against the per-function plan). event_booking_save.php and
 * sheet_venue.php both use it, so it belongs in the runtime include set for the
 * same reason. It is required AFTER HeadcountBrief because the brief is what
 * delivers its copy.
 */
require_once __DIR__ . DS . "PackageCapacity.php";
/*
 * UserPrefs is where an account's own choices live (tbluser_prefs, v2.12) —
 * today the width the capacity headroom view opens at. headroom.php reads and
 * writes it on every request, so it belongs in the runtime include set for the
 * same reason as the two above; and because it validates a stored width through
 * PackageCapacity, it is required after it.
 */
require_once __DIR__ . DS . "UserPrefs.php";
/*
 * VendorCoverage is the per-function statement of what a wedding still needs
 * (v2.24), which the board in vendor_coverage.php reads and its editor writes.
 * It is required here for the same reason as the models above: the board is a
 * page, not a CLI tool, and composer's classmap is not the web include set.
 */
require_once __DIR__ . DS . "VendorCoverage.php";
/*
 * VendorAvailability answers the other half of that board's question: a booked
 * cell is only really covered if the vendor is not already working somebody
 * else's wedding that day. The enquiry, confirm and vendor-accept pages all ask
 * it, so it belongs in the runtime include set with the models above.
 */
require_once __DIR__ . DS . "VendorAvailability.php";
/*
 * VendorThread is the conversation on a vendor deal (v2.25): the couple, the
 * vendor and the couple's planner in one thread, with per-role read state. It
 * is here rather than per-page because three different roles open it on three
 * different pages, plus the POST endpoint that writes to it.
 */
require_once __DIR__ . DS . "VendorThread.php";
/*
 * DealNote is the vendor's own words on a decline (v3.3): the optional line
 * they get when they turn an enquiry down, which the couple and their planner
 * then read instead of a bare grey badge. It is in the runtime include set
 * because both ledgers, the couple's enquiries list and the plan pages render
 * it, and it is required next to VendorThread because it is the same deal's
 * story — the message thread is what they said, this is why they said no.
 */
require_once __DIR__ . DS . "DealNote.php";
/*
 * VendorService is what one business sells, as distinct from the packages it
 * bundles (v2.27): the vendor's own page edits it, the staff marketplace and the
 * public profile read it. It is in the runtime include set because all three
 * are pages — composer's classmap only serves the CLI/test tier.
 */
require_once __DIR__ . DS . "VendorService.php";
/*
 * VendorTier is the three levels of one offer — Silver, Gold, Platinum, each
 * with its own price, inclusions and exclusions (v3.1). It is in the runtime
 * include set because the vendor's own page saves it, the marketplaces print
 * it, and the proposal builder prices a line from it; it is required after
 * VendorService (it checks service ownership through that class) and after
 * Vendor (package ownership).
 */
require_once __DIR__ . DS . "VendorTier.php";
/*
 * VendorFit is the couple's half of that comparison (v2.28): what they want
 * and can spend per vendor category, and whether a service fits. The couple's
 * marketplace, the planner's marketplace and the couple's own editor all read
 * it, so it belongs in the runtime include set; it is required after
 * VendorService and VendorCoverage because it asks them for units and slugs.
 */
require_once __DIR__ . DS . "VendorFit.php";
/*
 * VendorSignup is the public front door for a business creating its own
 * listing and login (vendor_signup.php): account, unapproved profile, services
 * and an optional package in one transaction. It is in the runtime include set
 * for the same reason as the models above — the page is not a CLI tool, and
 * composer's classmap only serves the test tier — and it is required after
 * VendorService and VendorCoverage because it validates through both.
 */
require_once __DIR__ . DS . "VendorSignup.php";
/*
 * VendorPlan is the wedding's vendor plan (v2.29): which vendor is doing what,
 * at what price, totalled — a VIEW over the deals, not a table of its own. Both
 * the planner's page and the couple's read-only page use it, so it belongs in
 * the runtime include set; it is required after VendorFit (caps), VendorCoverage
 * (categories) and VendorService (units).
 */
require_once __DIR__ . DS . "VendorPlan.php";
/*
 * Proposal is the lifecycle a registration travels through (v2.30): brief,
 * planner acknowledgement, at least three priced options built from vendor
 * offerings, the couple's choice, and the lock that writes the deals. Required
 * after VendorPlan and PackageCapacity (it locks THROUGH the plan's add door and
 * reports package capacity), and after VendorThread/Notification for the bells
 * every transition rings.
 */
require_once __DIR__ . DS . "Proposal.php";
require_once __DIR__ . DS . "PasswordReset.php";
