<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The couple-ownership rule, pinned twice — behaviour and source.
 *
 * WHY THIS EXISTS: couples live in tblaccounts (primary key `user_id`) and
 * planners, admins and vendors live in tblusers (primary key `id`). Those are
 * two independent auto-increment sequences, so their ids are two number spaces
 * that merely look alike. A VENDOR is a tblusers row with access_level 0, which
 * makes is_staff() FALSE for it — so the ownership test every couple page used
 * to hand-roll, `!is_staff() && $booking->user_id === $_SESSION['id']`,
 * compared a tblaccounts id against a tblusers id and handed a vendor the
 * bookings of whichever couple held that number. Measured before the fix: a
 * vendor whose id equalled a couple's account id got HTTP 200 with that
 * couple's guest list, payments, checklist, deposit page, account details,
 * wedding page and both printable sheets, and could write to them.
 *
 * The fix is one rule in Helper.php, in three predicates:
 *   is_client_account()          — this session is a tblaccounts row
 *   booking_visible_to_session() — staff any wedding, a couple only their own
 *   may_open_couple_page()       — the above, plus the ?user_id shape check
 *
 * 1. BEHAVIOUR — the predicates on every kind of session (super admin, planner,
 *    couple, vendor, signed out) against a booking they own, somebody else's,
 *    and no booking at all. No database, no HTTP: these are pure $_SESSION reads.
 * 2. SOURCE — every couple-facing page must route its decision through the
 *    predicates, the sheets must delegate to sheet_boot(), and no file may
 *    hand-roll the cross-table comparison again. The page inventory is
 *    DISCOVERED (couple-friendly paywall + reads a booking/user id from the
 *    request), so a page added later is covered without touching this file.
 */
final class CoupleOwnershipRuleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        // These tests are pure $_SESSION reads; leave the process exactly as found
        // (other classes — session hardening, login, paywall — run in this process).
        $_SESSION = $this->sessionBackup;
    }

    /** Sign a session in the way login.php does for each account kind. */
    private function signedIn(string $privilegeLevel, string $accountType, int $id): void
    {
        $_SESSION = [
            'id'              => $id,
            'privilege_level' => $privilegeLevel,
            'account_type'    => $accountType,
            'user_email'      => 'ownership-rule-probe@example.test',
        ];
    }

    private function bookingOwnedBy(int $accountId): object
    {
        // tblweddingbook: booking_id primary, user_id = the owning tblaccounts row.
        return (object) ['booking_id' => 700, 'user_id' => $accountId];
    }

    // ------------------------------------------------------------------ behaviour

    public function testAPlannerMayOpenAnyWedding(): void
    {
        $this->signedIn('1', 'staff', 5);

        self::assertTrue(is_staff(), 'access_level 1 is staff');
        self::assertFalse(is_client_account(), 'a planner is not a couple account');
        self::assertTrue(booking_visible_to_session($this->bookingOwnedBy(200)), 'staff may preview any wedding');
        self::assertTrue(may_open_couple_page($this->bookingOwnedBy(200), 200), 'staff may carry the couple\'s own id');
        self::assertTrue(may_open_couple_page($this->bookingOwnedBy(200), 999), 'staff may carry another id while assisting');
        self::assertFalse(may_open_couple_page(false, 5), 'a booking that does not exist is open to nobody');
    }

    public function testASuperAdminIsAlsoStaff(): void
    {
        $this->signedIn('2', 'staff', 5);

        self::assertTrue(booking_visible_to_session($this->bookingOwnedBy(200)));
        self::assertTrue(may_open_couple_page($this->bookingOwnedBy(200), 200));
    }

    public function testACoupleOpensOnlyItsOwnBooking(): void
    {
        $this->signedIn('0', 'client', 200);

        self::assertFalse(is_staff(), 'a couple is not staff');
        self::assertFalse(is_vendor(), 'a couple is not a vendor');
        self::assertTrue(is_client_account(), 'a couple is a client account');

        self::assertTrue(booking_visible_to_session($this->bookingOwnedBy(200)), 'its own booking');
        self::assertFalse(booking_visible_to_session($this->bookingOwnedBy(201)), 'another couple\'s booking');
        self::assertFalse(booking_visible_to_session(false), 'a missing booking');

        self::assertTrue(may_open_couple_page($this->bookingOwnedBy(200), 200), 'its own booking and id');
        self::assertFalse(may_open_couple_page($this->bookingOwnedBy(200), 201), 'its own booking, another id');
        self::assertFalse(may_open_couple_page($this->bookingOwnedBy(201), 200), 'another booking, its own id');
        self::assertFalse(may_open_couple_page($this->bookingOwnedBy(201), 201), 'another booking and id');
    }

    /**
     * The regression this whole rule was extracted for: a vendor signing in gets
     * a tblusers id, and a couple's booking names a tblaccounts id. Same number,
     * different table, different person.
     */
    public function testAVendorIsNeverTheCoupleHoldingThatNumber(): void
    {
        $booking = $this->bookingOwnedBy(200);

        $this->signedIn('0', 'client', 200);
        self::assertTrue(booking_visible_to_session($booking), 'the couple whose account id is 200 owns it');

        $this->signedIn('0', 'staff', 200); // a vendor: tblusers row, access_level 0
        self::assertFalse(is_staff(), 'a vendor is not staff (the trap this rule exists for)');
        self::assertTrue(is_vendor(), 'the session is a vendor');
        self::assertFalse(is_client_account(), 'a vendor is not a couple account');
        self::assertFalse(booking_visible_to_session($booking), 'a vendor holding id 200 may NOT open account 200\'s booking');
        self::assertFalse(may_open_couple_page($booking, 200), 'not with the matching user_id either');
        self::assertFalse(may_open_couple_page($booking, 0), 'and not with no user_id');
    }

    public function testASignedOutVisitorOpensNothing(): void
    {
        $_SESSION = [];

        self::assertFalse(is_client_account());
        self::assertFalse(booking_visible_to_session($this->bookingOwnedBy(200)));
        self::assertFalse(may_open_couple_page($this->bookingOwnedBy(200), 200));
    }

    // --------------------------------------------------------------------- source

    /**
     * Every couple-facing page must call the shared rule. Discovered rather than
     * listed: a page that is gated by the couple-friendly paywall, reads a
     * booking or user id from the request, and does not bounce non-staff away is
     * serving couples, so its ownership decision belongs to the helper.
     */
    public function testEveryCoupleFacingPageRoutesThroughTheSharedRule(): void
    {
        $offenders = [];
        $checked   = [];

        foreach (glob(__DIR__ . '/../admin/*.php') ?: [] as $file) {
            $src  = (string) file_get_contents($file);
            $name = basename($file);

            if (!str_contains($src, 'staff_paywall_locked()')) {
                continue; // not a couple-reachable page
            }
            if (!preg_match('/\$_(GET|POST|REQUEST)\[\s*[\'"](booking_id|user_id)[\'"]\s*\]/', $src)) {
                continue; // resolves nothing from the request (client.php scopes by session)
            }
            if (preg_match('/if\s*\(\s*!is_staff\(\)\s*\)/', $src)) {
                continue; // staff-only endpoint (rsvp_mark_invited, rsvp_wa_queue)
            }

            $checked[] = $name;
            if (!str_contains($src, 'may_open_couple_page(') && !str_contains($src, 'booking_visible_to_session(')) {
                $offenders[] = $name;
            }
        }

        self::assertSame([], $offenders, 'these couple-facing pages decide booking ownership themselves instead of via the shared rule');
        self::assertGreaterThanOrEqual(
            10,
            count($checked),
            'the discovery rule found suspiciously few couple-facing pages — it may have stopped matching'
        );
        self::assertContains('client_guests.php', $checked, 'sanity: a known couple page is in the discovered set');
        self::assertContains('event_booking_save.php', $checked, 'sanity: the couple write endpoint is in the discovered set');
    }

    /** The sheets take booking_id alone, so their guard is sheet_boot() → the helper. */
    public function testTheSheetsDelegateToSheetBootAndTheRule(): void
    {
        foreach (['sheet_guests.php', 'sheet_venue.php'] as $sheet) {
            $src = (string) file_get_contents(__DIR__ . '/../admin/' . $sheet);
            self::assertStringContainsString('sheet_boot()', $src, $sheet . ' must resolve and guard through sheet_boot()');
        }

        $boot = (string) file_get_contents(__DIR__ . '/../admin/include/sheet_boot.php');
        self::assertStringContainsString('booking_visible_to_session(', $boot, 'sheet_boot() must apply the shared rule');
        self::assertStringNotContainsString('$_GET[\'user_id\']', $boot, 'the sheets are addressed by booking alone — the query user_id is not an input');
    }

    /**
     * And nobody may hand-roll the comparison again — anywhere, not just in the
     * files that exist today. The pattern is what went wrong: two ids from two
     * tables, compared as if they were one namespace.
     */
    public function testNoFileComparesABookingOwnerIdAgainstTheSessionIdDirectly(): void
    {
        $patterns = [
            '$booking->user_id !== (int) $_SESSION',
            '$booking->user_id === (int) $_SESSION',
            '(int) $booking->user_id !== (int) $_SESSION',
            '(int) $booking->user_id === (int) $_SESSION',
        ];

        $scan = array_merge(
            glob(__DIR__ . '/../admin/*.php') ?: [],
            glob(__DIR__ . '/../admin/include/*.php') ?: [],
            glob(__DIR__ . '/*.php') ?: [],
            glob(__DIR__ . '/../*.php') ?: []
        );

        $offenders = [];
        foreach ($scan as $file) {
            // This file spells the forbidden patterns out as data; it is not code
            // that compares anything. Everywhere else, the pattern is the defect.
            if (realpath($file) === realpath(__FILE__)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                if (str_contains($src, $pattern)) {
                    $offenders[] = str_replace(__DIR__ . '/../', '', $file) . ' contains "' . $pattern . '"';
                }
            }
        }

        self::assertSame([], $offenders, 'use is_client_account()/booking_visible_to_session()/may_open_couple_page() instead of comparing a booking owner id to $_SESSION[\'id\']');
    }
}
