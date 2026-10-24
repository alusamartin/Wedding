<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-planner scoping, pinned in three places — the rule, its two renderings,
 * and the surfaces that must apply it.
 *
 * The rule (`Helper.php`): a planner sees the weddings whose
 * `tblweddingbook.organizer_id` is their own id PLUS the unassigned ones; a
 * super admin sees every wedding; a couple sees its own booking; a vendor sees
 * none of it. It has two renderings — `staff_may_open_organizer()` for a row
 * PHP already has, and `planner_scope_sql()` for a list query that must not
 * fetch what a page would refuse.
 *
 * 1. BEHAVIOUR — the rule on every kind of session (planner own / another
 *    planner's / unassigned / NULL, super admin, couple, vendor, signed out),
 *    and the switch that turns the whole thing off.
 * 2. DRIFT — the two renderings must agree for every organizer id, because a
 *    list that disagrees with the page it links to is how this kind of feature
 *    leaks quietly.
 * 3. SOURCE — `booking_visible_to_session()` must delegate (not go back to
 *    "staff see everything"), the door in `admin/include/init.php` must be
 *    wired, and every list that showed the whole platform must scope its query.
 *
 * No database and no HTTP: pure $_SESSION reads and source inspection.
 */
final class PlannerScopingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionBackup = [];
    private ?string $switchBackup = null;

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $value = getenv('WPMS_PLANNER_SCOPING');
        $this->switchBackup = $value === false ? null : (string) $value;
        putenv('WPMS_PLANNER_SCOPING='); // the documented default: ON
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        if ($this->switchBackup === null) {
            putenv('WPMS_PLANNER_SCOPING');
        } else {
            putenv('WPMS_PLANNER_SCOPING=' . $this->switchBackup);
        }
    }

    /** Sign a session in the way login.php does for each account kind. */
    private function signedIn(string $privilegeLevel, string $accountType, int $id): void
    {
        $_SESSION = [
            'id'              => $id,
            'privilege_level' => $privilegeLevel,
            'account_type'    => $accountType,
            'user_email'      => 'scoping-probe@example.test',
        ];
    }

    /** A booking row as tblweddingbook hands it over. */
    private function booking(int $organizerId, int $ownerAccountId = 200): object
    {
        return (object) ['booking_id' => 200, 'user_id' => $ownerAccountId, 'organizer_id' => $organizerId];
    }

    // ------------------------------------------------------------- behaviour

    /** The asked-for behaviour: a planner's own weddings, plus what nobody owns. */
    public function testAPlannerSeesOwnAndUnassignedWeddingsOnly(): void
    {
        $this->signedIn('1', 'staff', 7);

        self::assertSame(7, planner_scope_planner_id(), 'a planner is scoped');
        self::assertTrue(booking_visible_to_session($this->booking(7)), 'their own wedding');
        self::assertFalse(booking_visible_to_session($this->booking(100)), 'another planner\'s wedding');
        self::assertTrue(booking_visible_to_session($this->booking(0)), 'an unassigned wedding, so it can be picked up');
        self::assertTrue(booking_visible_to_session($this->booking(-1)), 'a garbage organizer id reads as unassigned');
    }

    /**
     * A NULL organizer reads as 0 — unassigned. MySQL returns NULL, not 0, for a
     * row written before the column existed, so the rule has to survive both.
     */
    public function testANullOrganizerReadsAsUnassigned(): void
    {
        $this->signedIn('1', 'staff', 7);
        $row = (object) ['booking_id' => 200, 'user_id' => 200, 'organizer_id' => null];

        self::assertTrue(booking_visible_to_session($row));
    }

    /** The operator is exempt, exactly as they are from the paywall. */
    public function testASuperAdminSeesEveryWedding(): void
    {
        $this->signedIn('2', 'staff', 5);

        self::assertSame(0, planner_scope_planner_id(), 'nothing is narrowed for the operator');
        self::assertTrue(booking_visible_to_session($this->booking(7)));
        self::assertTrue(booking_visible_to_session($this->booking(100)));
    }

    /** The couple half of the predicate must not have been weakened by any of this. */
    public function testACoupleStillSeesOnlyItsOwnBooking(): void
    {
        $this->signedIn('0', 'client', 200);

        self::assertSame(0, planner_scope_planner_id(), 'a couple is not a scoped planner');
        self::assertTrue(booking_visible_to_session($this->booking(7, 200)), 'their own booking opens');
        self::assertFalse(booking_visible_to_session($this->booking(7, 201)), 'another couple\'s does not');
        self::assertFalse(booking_visible_to_session(false), 'and a row that did not resolve is open to nobody');
    }

    /** A vendor is staff-row with access_level 0: staff and planner rules must both skip it. */
    public function testAVendorGetsNoBookingsAtAll(): void
    {
        $this->signedIn('0', 'staff', 8);

        self::assertFalse(is_staff(), 'a vendor is not staff');
        self::assertSame(0, planner_scope_planner_id());
        self::assertFalse(booking_visible_to_session($this->booking(7)));
        self::assertFalse(booking_visible_to_session($this->booking(0)));
    }

    public function testASignedOutSessionSeesNothing(): void
    {
        $_SESSION = [];

        self::assertSame(0, planner_scope_planner_id());
        self::assertFalse(booking_visible_to_session($this->booking(7)));
    }

    /** The escape hatch: WPMS_PLANNER_SCOPING=0 restores the old whole-platform view. */
    public function testTheSwitchTurnsScopingOff(): void
    {
        putenv('WPMS_PLANNER_SCOPING=0');
        $this->signedIn('1', 'staff', 7);

        self::assertFalse(planner_scoping_enabled());
        self::assertSame(0, planner_scope_planner_id(), 'nothing is narrowed when the switch is off');
        self::assertTrue(booking_visible_to_session($this->booking(100)), 'another planner\'s wedding is visible again');
        self::assertSame(['sql' => '', 'params' => []], planner_scope_sql('b.organizer_id'), 'and no query is scoped');
    }

    /** Explicitly on, and anything unrecognised, means on — forgetting it must not leak. */
    public function testOnlyAnExplicitOffDisablesScoping(): void
    {
        foreach (['', '1', 'on', 'true', 'yes'] as $value) {
            putenv('WPMS_PLANNER_SCOPING=' . $value);
            self::assertTrue(planner_scoping_enabled(), 'value ' . var_export($value, true) . ' must leave scoping on');
        }
        foreach (['0', 'off', 'OFF', 'no', 'false'] as $value) {
            putenv('WPMS_PLANNER_SCOPING=' . $value);
            self::assertFalse(planner_scoping_enabled(), 'value ' . var_export($value, true) . ' must switch scoping off');
        }
    }

    // ----------------------------------------------------------------- drift

    /**
     * The PHP rule and the SQL rule, evaluated case by case against one literal
     * restatement of the rule. A list query that returned a row this predicate
     * would refuse (or hid one it would allow) fails here.
     */
    public function testThePhpRuleAndTheSqlRuleAgreeForEveryOrganizerId(): void
    {
        $cases = [7, 100, 5, 0, -1, 9999];

        foreach ([7, 100, 5] as $me) { // planner 7, planner 100, super admin 5
            $this->signedIn($me === 5 ? '2' : '1', 'staff', $me);
            $scope    = planner_scope_sql('b.organizer_id');
            $scopedTo = $scope['params'][':planner_scope_me'] ?? 0;

            foreach ($cases as $organizerId) {
                $mayOpen = staff_may_open_organizer($organizerId);

                if ($scopedTo === 0) {
                    self::assertTrue($mayOpen, 'an unscoped session may open anything');
                    self::assertSame('', $scope['sql'], 'and its query carries no condition');
                    continue;
                }

                // The same rule, restated: own id, or nobody's.
                $expected = $organizerId === $scopedTo || $organizerId <= 0;
                self::assertSame($expected, $mayOpen, "PHP rule for organizer {$organizerId} as user {$me}");

                // ...and the SQL says the same thing, with the same bound id.
                self::assertSame(
                    '(b.organizer_id = :planner_scope_me OR b.organizer_id IS NULL OR b.organizer_id = 0)',
                    $scope['sql'],
                    'the condition is a complete, parenthesised restatement of the rule'
                );
            }
        }
    }

    /** An empty condition means "callers must not add a WHERE" — pinned so it cannot drift. */
    public function testAnUnscopedInstallProducesNoConditionAndNoParams(): void
    {
        $this->signedIn('2', 'staff', 5);
        self::assertSame(['sql' => '', 'params' => []], planner_scope_sql());

        $this->signedIn('0', 'client', 200);
        self::assertSame(['sql' => '', 'params' => []], planner_scope_sql());

        $_SESSION = [];
        self::assertSame(['sql' => '', 'params' => []], planner_scope_sql());
    }

    // ---------------------------------------------------------------- source

    /**
     * The predicate must DELEGATE for staff. It used to be a bare `return true`,
     * and that single line is what made `booking_visible_to_session()` — and
     * with it the venue sheet, the approval page and every MCP tool — scoping-
     * blind.
     */
    public function testBookingVisibleToSessionDelegatesToTheScopingRule(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Helper.php');

        self::assertStringContainsString('function staff_may_open_organizer(', $src);
        self::assertStringContainsString('staff_may_open_organizer((int) ($booking->organizer_id ?? 0))', $src,
            'the staff branch of booking_visible_to_session() must ask the scoping rule');
        self::assertDoesNotMatchRegularExpression(
            '/if \(is_staff\(\)\) \{\s*return true;/',
            $src,
            'staff may no longer be handed every wedding'
        );
    }

    /**
     * The door is wired: `admin/include/init.php` is what covers the eighteen
     * staff pages that take a `booking_id` without resolving a booking
     * themselves. If this call disappears, they silently go back to being
     * reachable by id.
     */
    public function testTheWebBootstrapRefusesABookingOutsideThePlannersScope(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/init.php');

        self::assertStringContainsString('function planner_scope_guard_request(', $src);
        self::assertStringContainsString("\nplanner_scope_guard_request();", str_replace("\r\n", "\n", $src),
            'and it must actually run — a defined-but-uncalled guard guards nothing');
        self::assertStringContainsString('booking_visible_to_session(Booking::find_by_booking_id($requested))', $src,
            'through the same predicate the pages use, not a second copy of the rule');
    }

    /**
     * Every admin page that reads a `booking_id` from the request must be a page
     * the door protects — i.e. one that boots through init.php. Discovered, not
     * listed, so a page added later is covered.
     */
    public function testEveryPageThatReadsABookingIdFromTheRequestBootsThroughTheDoor(): void
    {
        $checked  = 0;
        $offenders = [];
        foreach (glob(__DIR__ . '/../admin/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match('/\$_(?:POST|GET|REQUEST)\[\'booking_id\'\]/', $src)) {
                continue;
            }
            $checked++;
            /*
             * Three idioms are in use and all three load the door: the page
             * top `include 'include/init.php'`, `include("include/init.php")`,
             * and `require_once __DIR__ . '/include/init.php'`. What matters is
             * that the bootstrap is loaded before the request's booking_id is
             * used — anything else would mean a page answering by id with no
             * guard at all.
             */
            if (!preg_match('/(?:include|require)(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*)?[\'"](?:\.\.?\/|\/)?include\/init\.php[\'"]/', $src)) {
                $offenders[] = basename($file);
            }
        }

        self::assertGreaterThanOrEqual(10, $checked, 'the staff pages that take a booking_id were not discovered');
        self::assertSame([], $offenders, 'these pages read a booking_id but never load the scoping door');
    }

    /**
     * The surfaces that listed the whole platform must scope their own query (a
     * filter applied nowhere is a rule applied nowhere). Each is named with the
     * reason it is on the list.
     */
    public function testEveryDirectoryAndListScopesItsOwnQuery(): void
    {
        /*
         * Every one of these must ask the scoping rule. `client.php` filters
         * rows it already has (the predicate), the other three build SQL and so
         * need the bound condition — a list that does not scope its query shows
         * a planner a count, a row or a name it will then refuse to open.
         */
        $expected = [
            'admin/client.php'           => ['the bookings list', ['planner_scope_planner_id(', 'booking_visible_to_session']],
            'admin/vendor_enquiries.php' => ['the enquiries ledger (list AND its status tallies)', ['planner_scope_sql(', 'booking_visible_to_session']],
            'admin/dashboard.php'        => ['the dashboard booking count', ['planner_scope_sql(']],
            'admin/include/McpTools.php' => ['list_weddings and the visible-booking totals', ['planner_scope_sql(']],
        ];

        foreach ($expected as $relative => [$what, $mustContain]) {
            $src = (string) file_get_contents(__DIR__ . '/../' . $relative);
            foreach ($mustContain as $needle) {
                self::assertStringContainsString($needle, $src,
                    $what . ' must scope itself (' . $relative . ' has no ' . $needle . ')');
            }
        }

        // The list that holds rows rather than SQL must gate the filter on there
        // being a scope at all, or every list would run through the predicate on
        // an install that has scoping switched off (harmless, but it also means
        // the switch's "no narrowing" answer is never actually consulted).
        $list = (string) file_get_contents(__DIR__ . '/../admin/client.php');
        self::assertStringContainsString('if (planner_scope_planner_id() > 0)', $list);

        // The ledger's POST handlers act on an id, so they need the predicate too:
        // a list that hides a deal while its transition handler still accepts the
        // id is not scoping, it is decoration.
        $ledger = (string) file_get_contents(__DIR__ . '/../admin/vendor_enquiries.php');
        self::assertStringContainsString('$deal_is_mine = static function (int $dealId)', $ledger);
        self::assertSame(3, substr_count($ledger, 'if (!$deal_is_mine('),
            'every deal transition (status, scope, re-price) must prove the wedding is the planner\'s');
    }

    /**
     * The model keeps recording the platform. `Booking::getBooking()` is also the
     * scripts' and tests' whole-platform read, and a model that silently filtered
     * by whoever happened to be signed in would be a trap rather than a guard.
     */
    public function testTheBookingModelItselfWasLeftUnscoped(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Booking.php');

        self::assertStringContainsString('public static function count_booking(array $scope = [])', $src,
            'the count takes a scope the CALLER decided on');
        // A CALL, not a mention: the docblock names the helper on purpose (to say
        // why the scope is passed in), so the bracket and the parenthesis are what
        // distinguish prose from a lookup the model must never do.
        self::assertStringNotContainsString('staff_may_open_organizer(', $src, 'the model must not apply the rule');
        self::assertStringNotContainsString('$_SESSION[', $src, 'nor read the session');
    }
}
