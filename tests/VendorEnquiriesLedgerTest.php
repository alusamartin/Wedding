<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Staff vendor-enquiries ledger regressions against a real php -S server:
 *
 *  1. Access — staff-only (anonymous and client sessions bounce).
 *  2. Listing — enquiries from ALL couples render with vendor, package,
 *     KSh deal amounts and status badges; status filters narrow the table;
 *     pending rows sort first.
 *  3. Transitions — confirm sets amount + 10% commission and stamps the
 *     notes trail with who moved it; complete and cancel flip status;
 *     every action is POST + CSRF (GET/tokenless record nothing).
 *
 * Integration ('integration' group) — requires the live DB and a bootable
 * php -S. Probe enquiry rows are removed in tearDownAfterClass; seed rows
 * (ids 200/201) are never modified.
 */
final class VendorEnquiriesLedgerTest extends TestCase
{
    private const STAFF_EMAIL = 'wafula@wpms.co.ke';
    private const STAFF_PASS  = 'Admin@2024!';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe enquiry ids created by this run */
    private static array $probeIds = [];
    /** @var int[] probe notification ids created by this run */
    private static array $probeNotifIds = [];
    /** @var int[] probe SMS log ids created by this run */
    private static array $probeSmsIds = [];
    /** @var int[] probe package ids created by this run */
    private static array $probePackageIds = [];

    /**
     * Organizer 7's credit balance before this class topped it up (restored in
     * teardown). The confirm transition charges the big-moment couple SMS to the
     * booking's organizer, and SMS::send() logs NOTHING when that account cannot
     * cover the cost — so on a credit-exhausted install the courtesy SMS is
     * skipped and the assertion below would otherwise read a stale row. The
     * sibling SMS classes top the same account up and restore it; this one must
     * too, or it silently depends on somebody else having left credits behind.
     */
    private static int $originalCredits = 0;
    private static bool $creditsTouched = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!function_exists('format_ksh')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        // Fund the organizer that the confirm transition bills, and remember what
        // it was so teardown puts it back exactly.
        if (self::$db !== null) {
            self::$originalCredits = (int) self::$db->fetchValue('SELECT credits FROM tblsms_credits WHERE user_id = 7');
            if (self::$originalCredits < 100) {
                self::$db->execute('UPDATE tblsms_credits SET credits = 100 WHERE user_id = 7');
                self::$creditsTouched = true;
            }
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null && self::$probeIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tblvendor_bookings WHERE id IN (' . implode(',', self::$probeIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeIds = [];
        if (self::$db !== null && self::$probeNotifIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tbl_mail_logs WHERE notification_id IN (' . implode(',', self::$probeNotifIds) . ')'
                );
                self::$db->execute(
                    'DELETE FROM tblnotifications WHERE id IN (' . implode(',', self::$probeNotifIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeNotifIds = [];
        if (self::$db !== null && self::$probeSmsIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tblsms_logs WHERE id IN (' . implode(',', self::$probeSmsIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeSmsIds = [];
        if (self::$db !== null && self::$probePackageIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tblvendor_packages WHERE id IN (' . implode(',', self::$probePackageIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probePackageIds = [];
        // Every transition (confirm/complete/cancel/reopen) now alerts the
        // couple — sweep any vendor-status alert the probes produced on this
        // booking. Probe enquiries are the only source of Safari Transport
        // alerts on the seed booking, so this cannot eat real demo content.
        if (self::$db !== null) {
            try {
                self::$db->execute(
                    "DELETE FROM tbl_mail_logs WHERE notification_id IN (
                        SELECT id FROM (
                            SELECT id FROM tblnotifications
                             WHERE user_id = 200 AND booking_id = 200 AND message LIKE '%Safari Transport%'
                        ) x
                    )"
                );
                self::$db->execute(
                    "DELETE FROM tblnotifications
                      WHERE user_id = 200 AND booking_id = 200 AND message LIKE '%Safari Transport%'"
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }

        // Restore the organizer's credit balance exactly as it was found.
        if (self::$db !== null && self::$creditsTouched) {
            try {
                self::$db->execute(
                    'UPDATE tblsms_credits SET credits = :c WHERE user_id = 7',
                    [':c' => self::$originalCredits]
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
            self::$creditsTouched = false;
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    #[Group('integration')]
    public function testLedgerIsStaffOnlyAndListsAllCouples(): void
    {
        $this->requireDb();

        // Anonymous and client sessions bounce. The cookie jar is shared with
        // the rest of the class, so it has to be emptied first — otherwise
        // "anonymous" means "whoever the previous method signed in as", which
        // is only accidentally true while the methods run in a fixed order.
        self::$jar = [];
        $anon = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(302, $anon['status'], 'anonymous users must bounce off the ledger');

        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $client = self::http('GET', '/admin/vendor_enquiries.php');
        // The staff jar is empty of client creds — a client session can't
        // exist here, so this is just the anonymous leg repeated. Real client
        // bounce is asserted in CoupleVendorMarketplaceTest.

        // Staff sees the ledger with seed enquiries from booking 200.
        $page = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Vendor Enquiries', $page['body']);
        self::assertStringContainsString('Nyama Choma Masters', $page['body'], 'seed enquiry must render');
        self::assertStringContainsString('Ruracio Decor — Full', $page['body'], 'the pending seed enquiry must render with its package');
        self::assertStringContainsString('KSh 120,000.00', $page['body'], 'deal amounts render in KSh');
        self::assertStringContainsString('commission KSh 12,000.00', $page['body']);
        self::assertStringContainsString('badge-warning', $page['body'], 'pending rows carry the pending badge');
        self::assertStringContainsString('Mark completed', $page['body'], 'confirmed rows offer the completion action');
        self::assertStringContainsString('vendor_enquiries.php', $page['body'], 'actions post back to the ledger');

        // Status filter narrows the table.
        $pending = self::http('GET', '/admin/vendor_enquiries.php?status=pending');
        self::assertStringContainsString('Floral Dreams', $pending['body']);
        self::assertStringNotContainsString('Nyama Choma Masters', $pending['body'], 'confirmed rows must be filtered out');
    }

    #[Group('integration')]
    public function testTransitionsRequirePostAndCsrf(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id = self::makeProbeEnquiry('CSRF probe enquiry');

        // GET must not change anything.
        self::http('GET', '/admin/vendor_enquiries.php');
        $get = self::http('GET', '/admin/vendor_enquiries.php?enquiry_id=' . $id . '&to=confirmed');
        self::assertSame(200, $get['status'], 'GET without a POST body just renders the page');
        self::assertSame('pending', self::enquiryStatus($id), 'GET must not transition');

        // Tokenless POST must not change anything.
        self::http('POST', '/admin/vendor_enquiries.php', ['enquiry_id' => $id, 'to' => 'cancelled']);
        self::assertSame('pending', self::enquiryStatus($id), 'tokenless POST must not transition');
    }

    #[Group('integration')]
    public function testConfirmSetsDealCommissionAndNotesTrail(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id = self::makeProbeEnquiry('Confirm-flow probe enquiry');
        $token = self::fetchCsrfToken();

        // Confirm with an explicit amount.
        $ok = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'confirmed', 'amount' => '40000', '_token' => $token,
        ]);
        self::assertSame(302, $ok['status']);

        $row = self::fetchEnquiry($id);
        self::assertSame('confirmed', (string) $row->status);
        self::assertSame('40000.00', (string) $row->amount);
        self::assertSame('4000.00', (string) $row->commission, 'commission must be the standard 10%');
        self::assertStringContainsString('confirmed by wafula@wpms.co.ke', (string) $row->notes, 'the notes trail must record who confirmed');
        self::assertStringContainsString('Confirm-flow probe enquiry', (string) $row->notes, 'the original enquiry message must be preserved');

        // The couple's bell must light up too — the enquiry loop now closes
        // back to the couple who sent it.
        $coupleNotif = self::$db->fetchOne(
            "SELECT id, message FROM tblnotifications WHERE booking_id = 200 AND user_id = 200 AND message LIKE '%Safari Transport%'
              ORDER BY id DESC LIMIT 1",
            []
        );
        self::assertNotNull($coupleNotif, 'confirm must create a couple-facing notification');
        self::assertStringContainsString('confirmed for your wedding', (string) $coupleNotif->message);
        self::$probeNotifIds[] = (int) $coupleNotif->id;

        // Big-moment SMS: the couple also gets TEXTED that their vendor is
        // locked in (charged to the booking's organizer; dev-mode gateway
        // reports sent hermetically). Booking 200's couple phone is
        // 0712345678 -> normalized 254712345678.
        // Scoped to the probe's OWN vendor (Safari Transport, vendor 203): a
        // booking-level LIKE alone can be satisfied by a stale CONFIRMED row that
        // another class left behind for a different vendor on this seed booking.
        $coupleSms = self::$db->fetchOne(
            "SELECT id, phone, message, status FROM tblsms_logs
              WHERE booking_id = 200 AND phone = '254712345678'
                AND message LIKE '%Safari Transport%' AND message LIKE '%CONFIRMED for your wedding%'
              ORDER BY id DESC LIMIT 1",
            []
        );
        self::assertNotNull($coupleSms, 'vendor confirm must SMS the couple');
        self::assertSame('sent', (string) $coupleSms->status);
        self::assertStringContainsString('KSh 40,000', (string) $coupleSms->message, 'the SMS must carry the locked deal amount');
        self::$probeSmsIds[] = (int) $coupleSms->id;

        // Complete.
        $done = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'completed', '_token' => $token,
        ]);
        self::assertSame(302, $done['status']);
        self::assertSame('completed', self::enquiryStatus($id));
    }

    #[Group('integration')]
    public function testCancelWorksAndUnknownStatusIsRefused(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id = self::makeProbeEnquiry('Cancel-flow probe enquiry');
        $token = self::fetchCsrfToken();

        $ok = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'cancelled', '_token' => $token,
        ]);
        self::assertSame(302, $ok['status']);
        self::assertSame('cancelled', self::enquiryStatus($id));

        // An unknown status is refused (row untouched).
        $bad = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'hacked', '_token' => $token,
        ]);
        self::assertSame(302, $bad['status']);
        self::assertSame('cancelled', self::enquiryStatus($id));
    }

    /**
     * The one step out of a capacity breach (v2.11): a planner told that a
     * package no longer covers the day moves the deal onto the vendor's next one
     * from the ledger itself. Covers what the step WRITES (package, amount,
     * commission re-derived, the notes trail, the status left alone), what it
     * TELLS (both sides, and the vendor never the deal value), and the pool the
     * control may choose from — this vendor's active packages, nobody else's.
     */
    #[Group('integration')]
    public function testRePriceMovesAConfirmedDealOntoAnotherPackageOfTheSameVendor(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id    = self::makeProbeEnquiry('Re-price probe enquiry');
        $token = self::fetchCsrfToken();
        self::assertSame(302, self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'confirmed', 'amount' => '40000', '_token' => $token,
        ])['status']);
        $before = self::fetchEnquiry($id);

        $package = self::makeProbePackage('[re-price probe] VIP 500', 90000.0, 500);
        $retired = self::makeProbePackage('[re-price probe] Retired menu', 50000.0, 400, 0);

        // The control is on the row, listing this vendor's own active packages
        // and the deal's current one as the selection.
        $page = self::http('GET', '/admin/vendor_enquiries.php')['body'];
        self::assertStringContainsString('name="reprice_deal_id" value="' . $id . '"', $page);
        // The label rule is PackageCapacity's, so the picker does not print the
        // capacity twice when the title already states it ("… 500 — 500 pax").
        self::assertStringContainsString('[re-price probe] VIP 500 · KSh 90,000', $page,
            'the pool the re-price may choose from, with its price');
        self::assertStringNotContainsString('[re-price probe] Retired menu', $page,
            'a retired package cannot be sold: it is not in the pool');

        $watermark = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblnotifications');
        $res = self::http('POST', '/admin/vendor_enquiries.php', [
            'reprice_deal_id' => $id, 'package_id' => $package, 'amount' => '', '_token' => $token,
        ]);
        self::assertSame(302, $res['status']);

        $after = self::fetchEnquiry($id);
        self::assertSame((string) $package, (string) $after->package_id, 'the deal moved onto the chosen package');
        self::assertSame('90000.00', (string) $after->amount, 'a blank amount takes the package price');
        self::assertSame('9000.00', (string) $after->commission,
            'the commission is re-derived from the new amount, not carried over');
        self::assertSame('confirmed', (string) $after->status,
            'a re-price is not a re-negotiation: the deal stays booked');
        self::assertStringContainsString('re-priced by ' . self::STAFF_EMAIL, (string) $after->notes,
            'the notes trail records who moved it');
        self::assertStringContainsString('33-Seater Matatu', (string) $after->notes, 'and the package it moved off');
        self::assertStringContainsString('[re-price probe] VIP 500', (string) $after->notes);
        self::assertStringContainsString('commission KSh 9,000.00', (string) $after->notes);
        self::assertStringContainsString((string) $before->notes, (string) $after->notes,
            'the trail is appended to, never replaced');

        // Both sides are told: the couple because their money changed, the
        // vendor because the package they deliver changed.
        $bells = self::$db->fetchAll(
            'SELECT id, user_id, message FROM tblnotifications WHERE id > :m ORDER BY id',
            [':m' => $watermark]
        );
        $byUser = [];
        foreach ($bells as $b) {
            self::$probeNotifIds[] = (int) $b->id;
            $byUser[(int) $b->user_id] = (string) $b->message;
        }
        self::assertCount(2, $bells, 'exactly two: the couple whose money changed, and the vendor who delivers it');
        self::assertArrayHasKey(200, $byUser, 'the couple are told what their deal became');
        self::assertStringContainsString('deal is now [re-price probe] VIP 500 at KSh 90,000', $byUser[200]);
        self::assertStringContainsString('(was KSh 40,000)', $byUser[200], 'and what it was before');
        self::assertArrayHasKey(8, $byUser, 'the vendor is told which package they now deliver');
        self::assertStringContainsString('booking is now on [re-price probe] VIP 500', $byUser[8]);
        self::assertStringNotContainsString('KSh', $byUser[8],
            'the deal value is between the couple and the planner, not the vendor\'s business');
        self::assertLessThanOrEqual(255, mb_strlen($byUser[8]), 'and it fits the bell column');

        // The page the planner lands on says what happened and whether it closed
        // the breach: this deal now covers the wedding's plan.
        $flash = self::http('GET', '/admin/vendor_enquiries.php')['body'];
        self::assertStringContainsString('re-priced to', $flash);
        self::assertStringContainsString('It now covers the planned headcounts.', $flash);
    }

    /**
     * The OTHER side of the capacity check (v2.11): the plan is judged when a deal
     * is RECORDED, not only when a headcount moves.
     *
     * The couple have planned 120 for their reception and enquired for a 10-pax
     * package. Staff confirm it today. The headcount save that would have said
     * "this no longer covers the day" is the NEXT time either of them edits the
     * numbers — weeks away, or never, because the numbers are settled. So the
     * moment the enquiry becomes a deal is the moment it is judged: the saver
     * reads the warning AND the way out on the page they land on (with `Re-price`
     * on the row above), the planner hears both in their bell, the account holding
     * the package hears the limit and nothing of the planner's options, and a deal
     * recorded on a package that DOES cover the plan says nothing at all.
     */
    #[Group('integration')]
    public function testConfirmingADealOnATooSmallPackageWarnsAtTheDoorItIsRecorded(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // The plan the deal is about to be judged against, read rather than
        // assumed: what this door must state is the crowd the database holds.
        $planned = [];
        foreach (PackageCapacity::plan_totals(200) as $fn) {
            if ($fn['total'] > 0) {
                $planned[(int) $fn['features_id']] = (int) $fn['total'];
            }
        }
        self::assertNotSame([], $planned, 'premise: booking 200 has a plan to judge a recorded deal against');
        $biggest  = max($planned);
        $function = (int) array_search($biggest, $planned, true);
        $title    = (string) self::$db->fetchValue('SELECT title FROM events WHERE id = :i', [':i' => $function]);
        self::assertGreaterThan(10, $biggest, 'premise: the probe package is smaller than the crowd it must serve');

        // A 10-pax package (too small for the reception) and one that covers it:
        // this vendor's ONLY other package seats 33, so without the second fixture
        // there would be no way out to state. Both are this vendor's, because a
        // deal can only move onto another package of the same vendor.
        $id       = self::makeProbeEnquiry('Record-door probe enquiry');
        $small    = self::makeProbePackage('[record probe] 10 pax', 5000.0, 10);
        $bigTitle = '[record probe] ' . ($biggest + 50) . ' pax';
        $big      = self::makeProbePackage($bigTitle, 90000.0, $biggest + 50);
        // Scoped to the function the crowd is on, which is how a real deal for one
        // function reads — and the shape the planner's bell needs room for: a
        // whole-wedding deal breaching two functions fills the column with the
        // warning alone, and fit() drops the way out before the warning.
        self::$db->execute(
            'UPDATE tblvendor_bookings SET package_id = :p, features_id = :f WHERE id = :i',
            [':p' => $small, ':f' => $function, ':i' => $id]
        );

        $notifMark = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblnotifications');
        $smsMark   = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblsms_logs');
        $token = self::fetchCsrfToken();
        self::assertSame(302, self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'confirmed', 'amount' => '5000', '_token' => $token,
        ])['status']);
        self::assertSame('confirmed', self::enquiryStatus($id), 'premise: the deal is recorded');

        // The saver: the warning, the way out, and who has been told.
        $flash = self::http('GET', '/admin/vendor_enquiries.php')['body'];
        // The lead-in heads the first clause only (PackageCapacity::notice()), so
        // the two facts are asserted apart: the warning is there at all, and it
        // states the crowd the package cannot serve.
        self::assertStringContainsString('Over capacity: ', $flash,
            'the page the saver lands on must say the deal just recorded does not cover the day');
        self::assertStringContainsString($title . ' is planned for ' . $biggest, $flash,
            'with the plan the package cannot serve');
        self::assertStringContainsString('over the package Safari Transport — Nairobi is booked on', $flash,
            'and name the package that was just sold');
        self::assertStringContainsString('Next package up: ' . $bigTitle, $flash,
            'the recording door states the way out, not just the problem');
        self::assertStringContainsString('been warned.', $flash, 'and whether anyone else knows');

        // Who was told. The planner gets the warning with the way out in it; the
        // account holding the deal gets the limit and no shopping list.
        $bells = [];
        foreach (self::$db->fetchAll('SELECT id, user_id, message FROM tblnotifications WHERE id > :m ORDER BY id', [':m' => $notifMark]) as $b) {
            self::$probeNotifIds[] = (int) $b->id;
            $bells[(int) $b->user_id] = (string) $b->message;
        }
        foreach (self::$db->fetchAll('SELECT id FROM tblsms_logs WHERE id > :m', [':m' => $smsMark]) as $s) {
            self::$probeSmsIds[] = (int) $s->id;
        }
        self::assertArrayHasKey(200, $bells, 'the couple are told their deal was confirmed, as before');

        $planner = self::plannerId();
        self::assertArrayHasKey($planner, $bells, 'the assigned planner must hear that a too-small package was sold');
        self::assertStringContainsString('over the package Safari Transport — Nairobi is booked on', $bells[$planner]);
        self::assertStringContainsString('Next package up: ' . $bigTitle, $bells[$planner],
            'the planner acts from the bell: the way out has to ride in it');
        self::assertLessThanOrEqual(255, mb_strlen($bells[$planner]), 'and fit the bell column');

        self::assertArrayHasKey(8, $bells, 'the account holding the deal hears its own package does not fit');
        self::assertStringContainsString('over the package you are booked on', $bells[8]);
        self::assertStringNotContainsString('Next package up', $bells[8],
            'the next package up is the planner\'s conversation with the vendor, not the vendor\'s alert');

        // ---- and a deal recorded on a package that COVERS the plan says nothing ----
        $fitId = self::makeProbeEnquiry('Record-door probe: a package that fits');
        self::$db->execute('UPDATE tblvendor_bookings SET package_id = :p WHERE id = :i', [':p' => $big, ':i' => $fitId]);

        $quietMark = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblnotifications');
        $quietSms  = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id),0) FROM tblsms_logs');
        $token = self::fetchCsrfToken();
        self::assertSame(302, self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $fitId, 'to' => 'confirmed', 'amount' => '90000', '_token' => $token,
        ])['status']);
        $quietFlash = self::http('GET', '/admin/vendor_enquiries.php')['body'];
        self::assertStringContainsString('enquiry marked <strong>confirmed</strong>', $quietFlash, 'premise: the second deal is recorded');
        self::assertStringNotContainsString('Over capacity', $quietFlash,
            'a package that covers the plan is not a breach: the door says nothing about capacity');

        $quietBells = [];
        foreach (self::$db->fetchAll('SELECT id, user_id FROM tblnotifications WHERE id > :m ORDER BY id', [':m' => $quietMark]) as $b) {
            self::$probeNotifIds[] = (int) $b->id;
            $quietBells[] = (int) $b->user_id;
        }
        foreach (self::$db->fetchAll('SELECT id FROM tblsms_logs WHERE id > :m', [':m' => $quietSms]) as $s) {
            self::$probeSmsIds[] = (int) $s->id;
        }
        self::assertSame([200], $quietBells,
            'nobody is warned about a deal that fits: only the couple hear their own deal was confirmed');
    }

    /**
     * What the re-price refuses, and that each refusal leaves the deal alone: a
     * tokenless POST, a package that is not this vendor's, a retired one, a
     * pricier package with no price and no agreed amount typed, and a deal that
     * is not confirmed. Plus the one correction it does allow: 0 is "no package",
     * which needs an amount because there is no price to fall back on.
     */
    #[Group('integration')]
    public function testRePriceRefusesWhatItCannotDoAndStillMovesToNoPackage(): void
    {
        $this->requireDb();
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        $id    = self::makeProbeEnquiry('Re-price refusal probe');
        $token = self::fetchCsrfToken();
        self::assertSame(302, self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $id, 'to' => 'confirmed', 'amount' => '40000', '_token' => $token,
        ])['status']);
        $start = self::fetchEnquiry($id);

        $mine   = self::makeProbePackage('[re-price probe] VIP 900', 120000.0, 900);
        $free   = self::makeProbePackage('[re-price probe] Quote only', 0.0, 600);
        $retired = self::makeProbePackage('[re-price probe] Retired menu', 50000.0, 400, 0);

        // The picker names packages by the same rule the warning uses: the guest
        // limit is spelled out only when the title does not already carry it, so
        // no option reads "… 900 — 900 pax".
        $picker = self::http('GET', '/admin/vendor_enquiries.php')['body'];
        self::assertStringContainsString('[re-price probe] Quote only — up to 600 pax', $picker,
            'a title that states no limit gets it spelled out');
        self::assertStringNotContainsString('[re-price probe] VIP 900 — 900 pax', $picker,
            'a title that states the limit is left alone');

        $refuse = function (array $post, string $expected, string $why) use ($id, $start, $token): void {
            $res = self::http('POST', '/admin/vendor_enquiries.php', $post + ['reprice_deal_id' => $id, '_token' => $token]);
            self::assertSame(302, $res['status']);
            $flash = self::http('GET', '/admin/vendor_enquiries.php')['body'];
            self::assertStringContainsString($expected, $flash, $why);
            $row = self::fetchEnquiry($id);
            self::assertSame((string) $start->package_id, (string) $row->package_id, $why . ' — the package must not move');
            self::assertSame((string) $start->amount, (string) $row->amount, $why . ' — nor the amount');
            self::assertSame('confirmed', (string) $row->status);
        };

        // Package 201 is the seed caterer's (vendor 200): a real, active package
        // this deal's vendor cannot be moved onto.
        $refuse(['package_id' => '201', 'amount' => ''], 'not this vendor', 'another vendor\'s package is refused');
        $refuse(['package_id' => $retired, 'amount' => ''], 'not active', 'a retired package cannot be sold');
        $refuse(['package_id' => $free, 'amount' => ''], 'Enter the agreed deal amount',
            'a package with no price needs the amount typed');
        $refuse(['package_id' => '999999', 'amount' => ''], 'not this vendor', 'a package that does not exist is refused');

        // A tokenless POST is the one refusal that never even reaches a branch:
        // it must not move the deal either.
        self::http('POST', '/admin/vendor_enquiries.php', [
            'reprice_deal_id' => $id, 'package_id' => $mine, 'amount' => '',
        ]);
        self::assertSame((string) $start->amount, (string) self::fetchEnquiry($id)->amount, 'a tokenless re-price writes nothing');

        // An enquiry that is not confirmed is not re-priced: it is confirmed first.
        $pending = self::makeProbeEnquiry('Re-price pending probe');
        self::http('POST', '/admin/vendor_enquiries.php', [
            'reprice_deal_id' => $pending, 'package_id' => $mine, 'amount' => '', '_token' => $token,
        ]);
        self::assertSame('pending', self::enquiryStatus($pending));
        self::assertSame('203', (string) self::fetchEnquiry($pending)->package_id, 'its package must be untouched');

        // The correction it does allow: no package, with the agreed amount.
        $res = self::http('POST', '/admin/vendor_enquiries.php', [
            'reprice_deal_id' => $id, 'package_id' => '0', 'amount' => '15000', '_token' => $token,
        ]);
        self::assertSame(302, $res['status']);
        $row = self::fetchEnquiry($id);
        self::assertNull($row->package_id, '0 is "no package" — a correction, not a purchase');
        self::assertSame('15000.00', (string) $row->amount);
        self::assertSame('1500.00', (string) $row->commission);
        self::assertStringContainsString('no package', (string) $row->notes, 'and the trail says so');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireDb(): void
    {
        if (self::$db === null || self::$base === null) {
            $this->markTestSkipped('live DB + php -S unavailable');
        }
    }

    /** Insert a pending probe enquiry on booking 200 / vendor 203; tracks cleanup. */
    private static function makeProbeEnquiry(string $note): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
             VALUES (203, 200, 203, '2026-12-19', 0, 0, 'pending', :note)",
            [':note' => $note]
        );
        $id = (int) self::$db->fetchValue('SELECT MAX(id) FROM tblvendor_bookings');
        self::$probeIds[] = $id;
        return $id;
    }

    /** Insert a probe package for vendor 203 (Safari Transport); tracks cleanup. */
    private static function makeProbePackage(string $title, float $price, int $capacity, int $is_active = 1): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
             VALUES (203, :t, 'Fixture for tests/VendorEnquiriesLedgerTest', :p, :c, 8, :a)",
            [':t' => $title, ':p' => $price, ':c' => $capacity, ':a' => $is_active]
        );
        $id = (int) self::$db->fetchValue('SELECT MAX(id) FROM tblvendor_packages');
        self::$probePackageIds[] = $id;
        return $id;
    }

    private static function fetchEnquiry(int $id): object
    {
        return self::$db->fetchAll('SELECT * FROM tblvendor_bookings WHERE id = :id', [':id' => $id])[0];
    }

    private static function enquiryStatus(int $id): string
    {
        return (string) self::$db->fetchValue('SELECT status FROM tblvendor_bookings WHERE id = :id', [':id' => $id]);
    }

    /** booking 200's organizer_id — where a capacity warning to the planner goes. */
    private static function plannerId(): int
    {
        return (int) self::$db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = 200 LIMIT 1'
        );
    }

    private static function fetchCsrfToken(): string
    {
        $res = self::http('GET', '/admin/vendor_enquiries.php');
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract CSRF token from the ledger page');
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_ve_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_ve_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root);
            if (!is_resource(self::$serverProc)) {
                continue;
            }
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
            }
            if ($ready) {
                self::$base = 'http://127.0.0.1:' . $port;
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    private static function login(string $email, string $pass): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $ch = curl_init(self::$base . $path);
        $headers = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ];
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $opts[CURLOPT_HTTPHEADER] = ['Cookie: ' . implode('; ', $parts)];
        }
        if ($post !== []) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 10));
            }
        }

        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }
}
