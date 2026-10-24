<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The vendor-ownership rule, pinned twice — behaviour and source.
 *
 * Vendors are staff rows (tblusers, access_level 0) and `tblvendors.user_id`
 * holds a tblusers id, so both sides of the comparison come from the SAME table:
 * the cross-table id confusion that let a vendor open a couple's booking (see
 * CoupleOwnershipRuleTest) cannot happen here. What can happen is the quieter
 * failure — a page resolving "the vendor" once, checking for null, and calling
 * that a guard, then running writes that carry no ownership predicate of their
 * own. This class holds the surface to the shared rule:
 *
 *   vendor_listing_visible_to_session()  — the session owns this listing
 *   bound_id_list()                      — the shared builder for an id-list scope
 *
 * 1. BEHAVIOUR — the predicate on every kind of session (vendor, own second
 *    listing, another vendor's listing, planner, super admin, couple, signed
 *    out), and the binder's contract (de-dupe, drop non-positive ids, empty in
 *    → empty fragment out).
 * 2. SOURCE — every page that calls require_vendor() must use the rule; every
 *    UPDATE/DELETE those pages run against a vendor-owned table must carry its
 *    own ownership clause; their id lists must come from the shared binder; and
 *    the id-inlining idiom that was removed may not come back anywhere.
 *
 * No database and no HTTP: pure $_SESSION reads plus source inspection.
 */
final class VendorOwnershipRuleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
    }

    /** Sign a session in the way login.php does for each account kind. */
    private function signedIn(string $privilegeLevel, string $accountType, int $id): void
    {
        $_SESSION = [
            'id'              => $id,
            'privilege_level' => $privilegeLevel,
            'account_type'    => $accountType,
            'user_email'      => 'vendor-rule-probe@example.test',
        ];
    }

    /** A listing row as tblvendors hands it over: user_id is the owning tblusers id. */
    private function listingOwnedBy(int $accountId, int $listingId = 200): object
    {
        return (object) ['id' => $listingId, 'user_id' => $accountId, 'business_name' => 'Probe Caterers'];
    }

    // ------------------------------------------------------------------ behaviour

    public function testAVendorSessionOwnsItsOwnListingsAndNoOthers(): void
    {
        $this->signedIn('0', 'staff', 8); // vendor: tblusers row, access_level 0

        self::assertTrue(is_vendor(), 'the session is a vendor');
        self::assertFalse(is_staff(), 'and is not staff');
        self::assertTrue(vendor_listing_visible_to_session($this->listingOwnedBy(8)), 'its own listing');
        self::assertTrue(vendor_listing_visible_to_session($this->listingOwnedBy(8, 203)), 'a second listing it also owns');
        self::assertFalse(vendor_listing_visible_to_session($this->listingOwnedBy(102)), 'another vendor\'s listing');
        self::assertFalse(vendor_listing_visible_to_session(false), 'a listing that did not resolve');
    }

    /**
     * The mirror of the couple defect, from the other side: a couple whose
     * tblaccounts id equals a vendor's tblusers id must NOT be handed that
     * vendor's listing just because the numbers match. is_vendor() is what
     * separates the two id spaces here.
     */
    public function testNumberCoincidenceAcrossTheTwoAccountTablesIsNotOwnership(): void
    {
        $listing = $this->listingOwnedBy(8);

        $this->signedIn('0', 'client', 8); // a couple, account id 8
        self::assertFalse(is_vendor(), 'a couple is not a vendor');
        self::assertFalse(vendor_listing_visible_to_session($listing), 'a couple holding number 8 does not own vendor 8\'s listing');

        $this->signedIn('1', 'staff', 8); // a planner, tblusers id 8
        self::assertTrue(is_staff());
        self::assertFalse(vendor_listing_visible_to_session($listing), 'staff get no vendor self-service bypass');

        $this->signedIn('2', 'staff', 8); // the operator
        self::assertFalse(vendor_listing_visible_to_session($listing), 'not the operator either — they manage vendors elsewhere');
    }

    public function testASignedOutVisitorOwnsNothing(): void
    {
        $_SESSION = [];

        self::assertFalse(vendor_listing_visible_to_session($this->listingOwnedBy(8)));
    }

    public function testTheBinderDeduplicatesDropsBlanksAndStaysEmptyWhenEmpty(): void
    {
        $one = bound_id_list([203]);
        self::assertSame([203], $one['ids']);
        self::assertSame(':own0', $one['sql']);
        self::assertSame([':own0' => 203], $one['params']);

        $many = bound_id_list([8, 102, 8, 0, -3, '203']);
        self::assertSame([8, 102, 203], $many['ids'], 'de-duped, positive, cast to int');
        self::assertSame(':own0, :own1, :own2', $many['sql'], 'one named placeholder per id');
        self::assertSame([':own0' => 8, ':own1' => 102, ':own2' => 203], $many['params']);

        $none = bound_id_list([]);
        self::assertSame([], $none['ids']);
        self::assertSame('', $none['sql'], 'an empty list yields an empty fragment — callers must skip the query');
        self::assertSame([], $none['params']);
    }

    // --------------------------------------------------------------------- source

    /**
     * Every vendor self-service page starts from a listing this rule has
     * approved. Discovered, not listed: any page that calls require_vendor() is
     * the vendor's own surface, so a page added later is covered here.
     */
    public function testEveryVendorSelfServicePageUsesTheSharedRule(): void
    {
        $pages = self::vendorSelfServicePages();
        self::assertGreaterThanOrEqual(3, count($pages), 'the vendor self-service surface was not discovered');

        $offenders = [];
        foreach ($pages as $file) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, 'vendor_listing_visible_to_session(')) {
                $offenders[] = basename($file);
            }
        }
        self::assertSame([], $offenders, 'these vendor pages gate on a bare null check instead of the shared ownership rule');
    }

    /**
     * Resolution proves ownership once; a write has to prove it too. Every
     * UPDATE/DELETE a vendor page runs against a vendor-owned table must carry
     * `vendor_id` or `user_id` in the same statement — the difference between
     * "the row we loaded was ours" and "the row this statement targets is ours".
     */
    public function testEveryVendorSelfServiceWriteCarriesItsOwnOwnershipClause(): void
    {
        $offenders = [];
        foreach (self::vendorSelfServicePages() as $file) {
            $src  = (string) file_get_contents($file);
            $name = basename($file);

            preg_match_all('/(?:UPDATE|DELETE\s+FROM)\s+tblvendor\w*/i', $src, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$statement, $offset]) {
                // The statement runs to its first `;` (the closing `);` of the call).
                $rest = substr($src, $offset);
                $end  = strpos($rest, ';');
                $text = $end === false ? substr($rest, 0, 400) : substr($rest, 0, $end);
                if (!str_contains($text, 'vendor_id') && !str_contains($text, 'user_id')) {
                    $offenders[] = $name . ': ' . preg_replace('/\s+/', ' ', substr($statement, 0, 60)) . ' has no ownership clause';
                }
            }
        }
        self::assertSame([], $offenders, 'a vendor-owned row may only be written by a statement that says whose row it is');
    }

    /** The id-list scope has one builder; the pages must use it, never inline ids. */
    public function testVendorIdListsComeFromTheSharedBinderAndAreNeverInlined(): void
    {
        foreach (self::vendorSelfServicePages() as $file) {
            $src  = (string) file_get_contents($file);
            $name = basename($file);
            self::assertStringNotContainsString('implode(', $src, $name . ' must build its id-list SQL through bound_id_list()');
        }

        $home = (string) file_get_contents(__DIR__ . '/../admin/vendor_home.php');
        self::assertStringContainsString('bound_id_list(', $home, 'vendor_home.php scopes its ledger with the shared binder');

        $staffLedger = (string) file_get_contents(__DIR__ . '/../admin/vendor_enquiries.php');
        self::assertStringContainsString('bound_id_list(', $staffLedger, 'the staff ledger binds its vendor id list too');
    }

    /**
     * And the removed idiom may not come back anywhere: mapping ids to strings
     * and interpolating them into an `IN (...)` is exactly how the vendor ledger
     * used to build its scope.
     */
    public function testNoPageMapsIdsIntoAnInList(): void
    {
        $scan = array_merge(
            glob(__DIR__ . '/../admin/*.php') ?: [],
            glob(__DIR__ . '/../admin/include/*.php') ?: [],
            glob(__DIR__ . '/../*.php') ?: []
        );

        $offenders = [];
        foreach ($scan as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, "implode(',', array_map(")) {
                $offenders[] = str_replace(__DIR__ . '/../', '', $file);
            }
        }
        self::assertSame([], $offenders, 'bind id lists (bound_id_list()) instead of interpolating them into SQL');
    }

    /**
     * The pages the vendor self-service rules apply to: the ones gated by
     * require_vendor(), which is the gate that turns a session into "the vendor".
     *
     * @return string[]
     */
    private static function vendorSelfServicePages(): array
    {
        $pages = [];
        foreach (glob(__DIR__ . '/../admin/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'require_vendor()')) {
                $pages[] = $file;
            }
        }
        sort($pages);
        return $pages;
    }
}
