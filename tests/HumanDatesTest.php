<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Human-date / event-label regressions:
 *
 *  1. format_date_kd() renders "Sat 20 Dec 2025[, 6:30 PM]" and never
 *     leaks a raw "2025-12-20 18:00:00" timestamp to a page.
 *  2. event_label() turns an event id into "Title — Sat 20 Dec 2025";
 *     orphaned/deleted ids render "Unassigned", never "Event #202".
 *  3. The ledger (staff + couple), deposit dropdown and booking tables
 *     actually render the human forms over HTTP (booking 200 fixtures).
 *  4. All three FullCalendars open on the wedding/next-event month with
 *     a Jump-to-Wedding-Day button instead of today.
 *
 * Probe ledger rows are removed in teardown.
 */
final class HumanDatesTest extends TestCase
{
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const STAFF_EMAIL  = 'wafula@wpms.co.ke';
    private const STAFF_PASS   = 'Admin@2024!';
    private const BOOKING_ID   = 200; // seeded rich booking (5 ceremonies; date read live from the DB)

    /** Booking 200's wedding date (Y-m-d), read from the DB in setUpBeforeClass so
     *  assertions follow the seed data when the date-shift script moves it forward —
     *  never a pinned calendar year that rots as the demo data ages. */
    private static ?string $weddingDate = null;

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe ledger ids created by this run */
    private static array $probeLedgerIds = [];

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
            if (!function_exists('format_date_kd')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
            $wed = $db->fetchOne(
                'SELECT wedding_date FROM tblweddingbook WHERE booking_id = :b',
                [':b' => self::BOOKING_ID]
            );
            self::$weddingDate = $wed ? date('Y-m-d', strtotime((string) $wed->wedding_date)) : null;
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null && self::$probeLedgerIds !== []) {
            try {
                self::$db->prepare(
                    'DELETE FROM tbl_liquidation WHERE id IN (' . implode(',', self::$probeLedgerIds) . ')'
                )->execute();
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeLedgerIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ------------------------------------------------------------------
    // Unit: the helpers themselves
    // ------------------------------------------------------------------

    public function testFormatDateKdRendersKenyanStyleDate(): void
    {
        $this->requireDb();
        self::assertSame('Sat 20 Dec 2025', format_date_kd('2025-12-20'));
        self::assertSame('Sat 20 Dec 2025, 6:30 PM', format_date_kd('2025-12-20 18:30:00', true));
        self::assertSame('Sat 20 Dec 2025', format_date_kd('2025-12-20 00:00:00'), 'midnight timestamps must not gain a bogus ", 12:00 AM"');
    }

    public function testFormatDateKdHandlesEmptyAndGarbage(): void
    {
        $this->requireDb();
        self::assertSame('', format_date_kd(''));
        self::assertSame('', format_date_kd('0000-00-00'));
        self::assertSame('', format_date_kd('0000-00-00 00:00:00'));
        self::assertSame('TBD-ish', format_date_kd('TBD-ish'), 'non-dates pass through unchanged (caller escapes)');
    }

    public function testEventLabelBuildsTitlePlusDate(): void
    {
        $this->requireDb();
        global $db;
        $event = $db->fetchOne(
            'SELECT id, title, start FROM events WHERE booking_id = :b ORDER BY start ASC LIMIT 1',
            [':b' => self::BOOKING_ID]
        );
        if (!$event) {
            self::markTestSkipped('booking 200 has no seeded ceremonies');
        }

        $label = event_label($event->id);
        self::assertStringContainsString($event->title, $label, 'label must carry the event title');
        self::assertStringContainsString(' — ' . format_date_kd($event->start), $label);
        self::assertStringNotContainsString('Event #', $label);
    }

    public function testEventLabelNeverRendersBareIds(): void
    {
        $this->requireDb();
        self::assertSame('Unassigned', event_label(0));
        self::assertSame('Unassigned', event_label(-5));
        self::assertSame('Unassigned', event_label(999999999), 'deleted/orphaned event ids must not render as a number');
    }

    // ------------------------------------------------------------------
    // Integration: the pages render the human forms over HTTP
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testCoupleLedgerShowsHumanDatesAndEventNames(): void
    {
        $this->requireHttp();
        self::loginCouple();
        self::seedProbeLedgerRow();

        $body = self::http('GET', '/admin/client_budget.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];

        self::assertStringNotContainsString('Event #', $body, 'machine event labels must be gone');
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $body, 'raw timestamps must not render');

        // The probe row: payment 111.11 on 2025-12-18 14:30 against booking 200's first ceremony.
        $firstEvent = self::firstCeremony();
        self::assertStringContainsString('Thu 18 Dec 2025, 2:30 PM', $body, 'probe payment date must render human');
        self::assertStringContainsString($firstEvent->title, $body, 'probe payment must show the ceremony name');
    }

    #[Group('integration')]
    public function testDepositDropdownUsesHumanDates(): void
    {
        $this->requireHttp();
        self::loginCouple();

        $page = self::http('GET', '/admin/client_deposit.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        $body = $page['body'];
        self::assertSame(200, $page['status'], 'deposit page must render for the couple');
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $body, 'raw start timestamps must not appear in the dropdown');
        self::assertMatchesRegularExpression('/\w{3} \d{1,2} \w{3} \d{4}, \d{1,2}:\d{2} (AM|PM)/', $body, 'ceremony options must carry the human date+time form');
    }

    #[Group('integration')]
    public function testOverviewCalendarOpensOnWeddingMonth(): void
    {
        $this->requireHttp();
        self::loginCouple();

        $body = self::http('GET', '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('defaultDate: "' . self::$weddingDate . '"', $body, 'couple calendar must open on the wedding month');
        self::assertStringContainsString('Jump to Wedding Day', $body);
    }

    #[Group('integration')]
    public function testOverviewShowsCountdownAndStatusBanner(): void
    {
        $this->requireHttp();

        // Couple view: booking 200 is confirmed. The banner branch follows the
        // LIVE seed date (future => countdown, past => "Happily married"), the
        // date is asserted in its human form, and the opposite branch is proven
        // with a transient row swap — no pinned calendar year anywhere.
        $wd = self::$weddingDate;
        self::assertNotEmpty($wd, 'booking 200 must have a wedding date');
        self::loginCouple();
        $body = self::http('GET', '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('class="countdown-banner', $body, 'overview must render the countdown banner');
        self::assertStringContainsString(date('l, j F Y', strtotime($wd)), $body, 'wedding date must be human-formatted in the banner');
        // The pill's markup, not its class in the stylesheet — the stylesheet
        // carries both class names on every render.
        self::assertStringContainsString('cd-status mt-2 cd-status-confirmed', $body, 'confirmed account must show the Confirmed pill');
        self::assertStringContainsString('Your date is locked in', $body);
        $weddingIsFuture = strtotime($wd) > time();
        if ($weddingIsFuture) {
            self::assertMatchesRegularExpression('/\d+ days? to go/', $body, 'future weddings show a live days-to-go countdown');
        } else {
            self::assertStringContainsString('Happily married', $body, 'past weddings show the married headline, not a countdown');
        }
        self::assertStringNotContainsString('Pending Confirmation', $body, 'confirmed account must never show the pending pill');
        self::assertStringContainsString('Your date is locked in', $body);

        // A pending future booking must show the countdown + pending messaging.
        // Driven by a transient status swap on this fixture's own couple row:
        // booking 200 / user 200 is the pair this class seeds, dates and
        // restores, and it is the pair both databases carry. Any other booking
        // would make the assertion a claim about which seed was imported, and
        // its countdown a claim about another couple's calendar date.
        self::loginStaff();
        self::swapAccountStatus('pending');
        try {
            $pending = self::http('GET', '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
            self::assertStringContainsString('class="countdown-banner', $pending, 'a pending booking still renders the banner');
            if ($weddingIsFuture) {
                self::assertMatchesRegularExpression('/\d+ days? to go/', $pending, 'a pending future booking still shows its countdown');
            }
            self::assertStringContainsString('Pending Confirmation', $pending, 'booking without a confirmed account row shows pending');
            self::assertStringContainsString('Awaiting confirmation', $pending);
            self::assertStringContainsString('cd-status mt-2 cd-status-pending', $pending, 'a pending booking must carry the pending pill');
            self::assertStringNotContainsString('cd-status mt-2 cd-status-confirmed', $pending, 'a pending booking must not carry the Confirmed pill');
            self::assertStringNotContainsString('Your date is locked in', $pending, 'a pending booking must not promise a locked-in date');
        } finally {
            self::swapAccountStatus('confirm');
        }

        // Opposite banner branch via a transient past/future date, restored after.
        $swapTo = $weddingIsFuture ? '2010-01-02' : date('Y-m-d', strtotime('+90 days'));
        self::swapWeddingDate($swapTo);
        try {
            $flipped = self::http('GET', '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
            if ($weddingIsFuture) {
                self::assertStringContainsString('Happily married', $flipped, 'past weddings show the married headline, not a countdown');
                self::assertStringContainsString('Saturday, 2 January 2010', $flipped, 'wedding date must be human-formatted in the banner');
            } else {
                self::assertMatchesRegularExpression('/\d+ days? to go/', $flipped, 'future weddings show a live days-to-go countdown');
            }
        } finally {
            self::swapWeddingDate($wd);
        }
    }

    #[Group('integration')]
    public function testOverviewDepositNudgeMatchesPaymentState(): void
    {
        $this->requireHttp();
        self::loginCouple();

        $overview = fn (): string => self::http(
            'GET',
            '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=200'
        )['body'];

        // Booking 200 carries the seeded 245,000 paid of a 250,000 package —
        // the final-stretch nudge with the live balance and the M-Pesa deep
        // link. The balance figure is computed from the DB, not pinned.
        $body = $overview();
        self::assertStringContainsString('pay-nudge', $body, 'a package with a balance must render the deposit nudge');
        self::assertStringContainsString('pay-nudge--final', $body, 'a <=10% remaining balance must render the final-stretch variant');
        self::assertStringContainsString('client_deposit.php?booking_id=' . self::BOOKING_ID, $body, 'the nudge must deep-link the M-Pesa deposit flow');
        self::assertStringContainsString('Pay via M-Pesa', $body);

        // Fully-paid branch: a probe M-Pesa deposit closing the balance flips
        // the nudge to the congrats variant, and its removal restores the
        // final-stretch state (probe row deleted below).
        global $db;
        $db->prepare(
            'INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified) '
            . 'VALUES (:b, 200, :e, 5000.00, 0, 0, "QDLPROBE01", NOW(), NOW())'
        )->execute([':b' => self::BOOKING_ID, ':e' => (int) self::firstCeremony()->id]);
        self::$probeLedgerIds[] = (int) $db->the_insert_id();
        try {
            $paid = $overview();
            self::assertStringContainsString('pay-nudge--paid', $paid, 'a fully paid package must render the congrats variant');
            self::assertStringContainsString('Hongera', $paid, 'the congrats variant must celebrate');
            self::assertStringNotContainsString('Pay via M-Pesa', $paid, 'a fully paid package must not push more deposits');
        } finally {
            self::$db->execute(
                'DELETE FROM tbl_liquidation WHERE mpesa_code = :c AND booking_id = :b',
                [':c' => 'QDLPROBE01', ':b' => self::BOOKING_ID]
            );
        }
    }

    #[Group('integration')]
    public function testOverviewNudgeEscalatesWithDaysToWedding(): void
    {
        $this->requireHttp();
        self::loginCouple();

        $wd = self::$weddingDate;
        self::assertNotEmpty($wd, 'booking 200 must have a wedding date');
        $overview = fn (): string => self::http(
            'GET',
            '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=200'
        )['body'];

        // The nudge panel's own class attribute — the <style> block always
        // contains the .pay-nudge--urgent CSS rules, so raw body regexes would
        // false-positive on the stylesheet rather than the element state.
        $nudgeDiv = fn (string $html): string =>
            (string) (preg_match('/class="pay-nudge[^"]*"/', $html, $m) ? $m[0] : '');

        // Default future seed (>60 days out): the pace line, no urgency.
        $days = (int) round((strtotime($wd) - strtotime(date('Y-m-d'))) / 86400);
        $body = $overview();
        self::assertStringContainsString('pn-milestone', $body, 'an open balance must carry a milestone line');
        if ($days > 60) {
            self::assertStringContainsString('Pace yourself', $body, 'far-out weddings suggest a monthly pace');
            self::assertStringNotContainsString('pay-nudge--urgent', $nudgeDiv($body), 'no deadline urgency far out');
            self::assertStringContainsString('a month clears the balance', $body);
        }

        // 45 days: firm settle-by date, still calm.
        self::swapWeddingDate(date('Y-m-d', strtotime('+45 days')));
        try {
            $b45 = $overview();
            self::assertStringContainsString('Aim to clear the balance by', $b45, 'inside 60 days the nudge names the settlement date');
            self::assertStringContainsString('final settlement a month out', $b45);
            self::assertStringNotContainsString('pay-nudge--urgent', $nudgeDiv($b45), '45 days is firm but not urgent');
        } finally {
            self::swapWeddingDate($wd);
        }

        // 12 days: final-month urgency with the red accent.
        self::swapWeddingDate(date('Y-m-d', strtotime('+12 days')));
        try {
            $b12 = $overview();
            self::assertStringContainsString('Final month', $b12, 'inside 30 days the nudge escalates to vendor-settlement copy');
            self::assertStringContainsString('pay-nudge--urgent', $nudgeDiv($b12), 'final month must carry the urgent accent');
        } finally {
            self::swapWeddingDate($wd);
        }

        // This week: same urgency, day-specific copy.
        self::swapWeddingDate(date('Y-m-d', strtotime('+3 days')));
        try {
            $b3 = $overview();
            self::assertStringContainsString('this week', $b3, 'the final week speaks in days, not months');
            self::assertStringContainsString('pay-nudge--urgent', $nudgeDiv($b3));
        } finally {
            self::swapWeddingDate($wd);
        }

        // Past the day: a polite settle-up note, never a push.
        self::swapWeddingDate(date('Y-m-d', strtotime('-6 days')));
        try {
            $past = $overview();
            self::assertStringContainsString('settle the remaining', $past, 'after the wedding the nudge becomes a settle-up note');
            self::assertStringNotContainsString('pay-nudge--urgent', $nudgeDiv($past), 'no urgency after the day');
        } finally {
            self::swapWeddingDate($wd);
        }
    }

    #[Group('integration')]
    public function testStaffTaskCalendarOpensOnWeddingMonth(): void
    {
        $this->requireHttp();
        self::loginStaff();

        $body = self::http('GET', '/admin/task_calendar.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('defaultDate: "' . self::$weddingDate . '"', $body, 'staff calendar must open on the couple\'s wedding month');
        self::assertStringContainsString('Jump to Wedding Day', $body);
    }

    #[Group('integration')]
    public function testClientTableShowsHumanWeddingDate(): void
    {
        $this->requireHttp();
        self::loginStaff();

        $body = self::http('GET', '/admin/client.php')['body'];
        // Booking 200's wedding must render in the human form, and the raw
        // ISO form must not appear as a standalone cell value.
        self::assertStringContainsString(format_date_kd(self::$weddingDate), $body);
        self::assertDoesNotMatchRegularExpression('/>\d{4}-\d{2}-\d{2}</', $body, 'table cells must not render ISO dates');
    }

    #[Group('integration')]
    public function testStaffClientTableShowsPaymentProgress(): void
    {
        $this->requireHttp();
        self::loginStaff();

        $body = self::http('GET', '/admin/client.php')['body'];

        // Booking 200's progress cell: figures derived from the live DB (seed:
        // 245,000 of a 250,000 package → 98%), never pinned calendar math.
        // SUM(payment) only — payment already includes the cash/credit method
        // amounts (adding them again double-counts, exactly the bug the
        // M-Pesa cash-neutrality guard exists for).
        $paid  = (float) self::$db->fetchValue(
            'SELECT COALESCE(SUM(payment), 0) FROM tbl_liquidation WHERE booking_id = 200'
        );
        $price = (float) self::$db->fetchValue(
            'SELECT c.price FROM tblweddingbook b JOIN tblweddingcategories c ON c.id = b.wedding_type WHERE b.booking_id = 200'
        );
        self::assertGreaterThan(0, $price, 'booking 200 must have a priced package');
        $pct     = (int) floor(min(100.0, $paid / $price * 100));
        $balance = max(0.0, $price - $paid);
        self::assertStringContainsString('of KSh ' . number_format($price), $body, 'the row must show paid against package price');
        self::assertStringContainsString($pct . '% · KSh ' . number_format($balance) . ' left', $body, 'the row must show percent + balance');
        self::assertMatchesRegularExpression('/width:' . $pct . '%/', $body, 'the progress bar width must match the paid percentage');

        // Deadline note on 200's row: pace line far out, urgency only near the
        // date — asserted on 200's OWN row fragment (other bookings, like
        // 203's ten-day-out wedding, may legitimately be red).
        $rowHtml = self::rowFragment($body, self::BOOKING_ID);
        if ($rowHtml === '') {
            self::fail('booking 200 row not found in the staff client table');
        }
        self::assertStringContainsString('KSh ' . number_format($price), $rowHtml, 'the extracted fragment must be 200\'s row');
        $days = (int) round((strtotime(self::$weddingDate) - strtotime(date('Y-m-d'))) / 86400);
        if ($days > 60) {
            self::assertStringContainsString('/mo', $rowHtml, 'far-out bookings show a monthly pace');
            self::assertStringNotContainsString('pay-meta--urgent', $rowHtml, 'no red far out');
        } elseif ($days <= 30 && $days >= 0) {
            self::assertStringContainsString('pay-meta--urgent', $rowHtml, 'inside 30 days the row shows the urgent accent');
        }

        // A fully paid booking must show the calm Fully-paid label AND drop the
        // raw percent form — a closed balance has no "N% · KSh 0 left" to
        // render. Booking 200 is flipped to a closed balance for the length of
        // one page load (probe payment in, deleted after), so the branch is
        // exercised on the fixture this class owns rather than through a second
        // booking whose price and payment state the seed decides.
        global $db;
        $db->prepare(
            'INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified) '
            . 'VALUES (200, 200, :e, 5000.00, 0, 0, "QDLPROBE02", NOW(), NOW())'
        )->execute([':e' => (int) self::firstCeremony()->id]);
        self::$probeLedgerIds[] = (int) $db->the_insert_id();
        try {
            $full = self::http('GET', '/admin/client.php')['body'];
            self::assertStringContainsString('Fully paid', $full, 'a closed balance must show the Fully-paid label');
            $paidRow = self::rowFragment($full, self::BOOKING_ID);
            self::assertNotSame('', $paidRow, '200\'s row must still be rendered once its balance is closed');
            self::assertStringContainsString('pay-full">Fully paid', $paidRow, '200\'s own row must carry the Fully-paid label');
            self::assertStringNotContainsString('% · KSh', $paidRow, 'a closed balance must not render the raw percent form');
        } finally {
            self::$db->execute(
                'DELETE FROM tbl_liquidation WHERE mpesa_code = :c AND booking_id = 200',
                [':c' => 'QDLPROBE02']
            );
        }
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Insert one probe ledger payment (KSh 111.11, 2025-12-18 14:30) against booking 200's first ceremony. */
    private static function seedProbeLedgerRow(): void
    {
        global $db;
        $event = self::firstCeremony();
        if (!$event) {
            self::markTestSkipped('booking 200 has no seeded ceremonies');
        }
        $db->prepare(
            'INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified) '
            . 'VALUES (:b, 200, :e, 111.11, 111.11, 0, NULL, "2025-12-18 14:30:00", "2025-12-18 14:30:00")'
        )->execute([':b' => self::BOOKING_ID, ':e' => (int) $event->id]);
        self::$probeLedgerIds[] = (int) $db->the_insert_id();
    }

    /**
     * The <tr>…</tr> fragment one booking occupies in a staff table.
     *
     * The row runs from the last <tr> before the booking's first link marker to
     * the next </tr>: a lazy regex from the first <tr> would swallow the rows
     * above it, and the table lists other weddings whose pace and payment state
     * are their own business. Empty string when the booking has no row here.
     */
    private static function rowFragment(string $body, int $bookingId): string
    {
        $marker = strpos($body, 'booking_id=' . $bookingId);
        if ($marker === false) {
            return '';
        }
        $rowStart = strrpos(substr($body, 0, $marker), '<tr>');
        $rowEnd   = strpos($body, '</tr>', $marker);
        return ($rowStart !== false && $rowEnd !== false)
            ? substr($body, $rowStart, $rowEnd - $rowStart + 5)
            : '';
    }

    /** Temporarily move booking 200's wedding date (banner past/future branches). */
    private static function swapWeddingDate(string $date): void
    {
        global $db;
        $db->prepare('UPDATE tblweddingbook SET wedding_date = :d WHERE booking_id = :b')
           ->execute([':d' => $date, ':b' => self::BOOKING_ID]);
    }

    /**
     * Temporarily move booking 200's couple row to another status.
     *
     * The banner's Confirmed/Pending pill reads tblaccounts_detail.status for
     * the user_id in the URL (the couple view is always booking 200 / user
     * 200 here), so this is the branch the test can flip without leaning on a
     * second seed booking.
     */
    private static function swapAccountStatus(string $status): void
    {
        global $db;
        $db->prepare('UPDATE tblaccounts_detail SET status = :s WHERE user_id = :u')
           ->execute([':s' => $status, ':u' => 200]);
    }

    private static function firstCeremony(): ?object
    {
        global $db;
        $row = $db->fetchOne(
            'SELECT id, title, start FROM events WHERE booking_id = :b ORDER BY start ASC LIMIT 1',
            [':b' => self::BOOKING_ID]
        );
        return $row ?: null;
    }

    private static function loginCouple(): void
    {
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS, '/login.php');
    }

    private static function loginStaff(): void
    {
        self::login(self::STAFF_EMAIL, self::STAFF_PASS, '/login.php');
    }

    private static function login(string $email, string $pass, string $page): void
    {
        self::$jar = [];
        $res = self::http('GET', $page);
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', $page, [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / live DB unavailable — human-dates page tests skipped');
        }
    }

    private function requireDb(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — human-dates unit tests skipped');
        }
    }

    // ------------------------------------------------------------------
    // Harness (same hardened pattern as StaffNotificationsPageTest)
    // ------------------------------------------------------------------

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_hd_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_hd_err');

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
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
                if (@file_get_contents(self::$base . '/index.php', false, $ctx) !== false) {
                    return;
                }
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
                self::$serverProc = null;
                self::$base = null;
            } else {
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
                self::$serverProc = null;
            }
        }
        self::$base = null;
    }

    /**
     * Minimal curl-free HTTP client; redirects are NOT followed.
     *
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
        }
        $body = $post === [] ? '' : http_build_query($post);
        $header = 'Host: 127.0.0.1' . "\r\n"
            . $cookieHeader
            . ($post !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close";

        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => $header,
            'content'         => $body,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 10,
        ]]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);
        $headers  = $http_response_header ?? [];

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }
}
