<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendor availability (admin/include/VendorAvailability.php) and the three
 * doors that ask it, against a real php -S server:
 *
 *  1. The day a deal occupies — its own event_date, else the function's, else
 *     the wedding's; nonsense dates fall through and an unreadable deal is
 *     never guessed at.
 *  2. Commitments — confirmed/completed rows are what commit a vendor; a deal
 *     on the SAME wedding is not a clash, and neither is a different day.
 *  3. The planner's CONFIRM (vendor_enquiries.php) — a clashing enquiry is
 *     refused until the acknowledgement is ticked, and the acknowledgement is
 *     written into the notes trail. The promise is that no second wedding is
 *     confirmed on a day the vendor is already working without somebody saying
 *     so out loud.
 *  4. The couple's enquiry (client_vendor_enquiry.php) — still recorded, but
 *     flagged to the couple and to the planner rather than discovered later.
 *  5. The vendor's own dashboard (vendor_home.php) — the row says "you are also
 *     booked" before Accept is pressed, and accepting records the clash.
 *
 * Integration ('integration') — needs the live test DB and a bootable php -S.
 * The class writes its own probe deals (with literal booking ids, so the
 * fixture sweep can attribute them) and removes them, plus the notifications and
 * SMS logs the requests produce, in tearDown.
 */
final class VendorAvailabilityTest extends TestCase
{
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';
    private const COUPLE_EMAIL  = 'faith.njeri@yahoo.com';
    private const COUPLE_PASS   = 'Test@1234';
    private const VENDOR_EMAIL  = 'kamau@nyamachoma.co.ke';
    private const VENDOR_PASS   = 'Vendor@2024';

    private const VENDOR_ID    = 200; // Nyama Choma Masters — seeded confirmed deal
    private const BUSY_BOOKING = 200; // the wedding they are already committed to
    private const PROBE_BOOKING = 201; // Faith & James — no vendor-200 deal of its own

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;
    /** @var array<string, string> */
    private static array $jar = [];

    /** The seeded confirmed deal that makes the vendor busy, and its day. */
    private static int $busyDealId = 0;
    private static string $busyDay = '';

    /** @var int[] deals this test wrote, removed in tearDown */
    private array $probeDealIds = [];
    /** @var array<int, array{notif:int, sms:int}> booking_id => high-water marks */
    private array $watermarks = [];

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
            global $db;
            $db = self::$db = new Database();

            $busy = $db->fetchOne(
                "SELECT id, event_date FROM tblvendor_bookings
                  WHERE vendor_id = 200 AND status = 'confirmed'
               ORDER BY id LIMIT 1"
            );
            if ($busy === null || trim((string) ($busy->event_date ?? '')) === '') {
                self::$db = null; // not the seed this test describes
            } else {
                self::$busyDealId = (int) $busy->id;
                self::$busyDay    = date('Y-m-d', strtotime((string) $busy->event_date));
            }
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    protected function tearDown(): void
    {
        foreach ($this->probeDealIds as $dealId) {
            try {
                self::$db?->execute('DELETE FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        $this->probeDealIds = [];

        foreach ($this->watermarks as $bookingId => $mark) {
            try {
                self::$db?->execute(
                    'DELETE FROM tbl_mail_logs WHERE notification_id IN (
                        SELECT id FROM (SELECT id FROM tblnotifications WHERE booking_id = :b AND id > :n) x
                    )',
                    [':b' => $bookingId, ':n' => $mark['notif']]
                );
                self::$db?->execute(
                    'DELETE FROM tblnotifications WHERE booking_id = :b AND id > :n',
                    [':b' => $bookingId, ':n' => $mark['notif']]
                );
                self::$db?->execute(
                    'DELETE FROM tblsms_logs WHERE booking_id = :b AND id > :s',
                    [':b' => $bookingId, ':s' => $mark['sms']]
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        $this->watermarks = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ---------------------------------------------------------------------
    // 1. The day a deal occupies, and the wording built from it
    // ---------------------------------------------------------------------

    public function testTheDayFallsBackFromDealToFunctionToWedding(): void
    {
        // The deal's own date always wins: that is the day the planner typed.
        self::assertSame('2026-12-19', VendorAvailability::day('2026-12-19', '2026-12-20 09:00:00', '2026-12-21'));

        // No deal date: the function's day, because that is the day the vendor works.
        self::assertSame('2026-12-20', VendorAvailability::day('', '2026-12-20 09:00:00', '2026-12-21'));
        self::assertSame('2026-12-20', VendorAvailability::day('not a date', '2026-12-20 09:00:00', '2026-12-21'));

        // No function either: the wedding's day, which is what a whole-wedding
        // deal means.
        self::assertSame('2026-12-21', VendorAvailability::day(null, null, '2026-12-21'));

        // Nothing readable is not a date. Guessing would invent collisions.
        self::assertSame('', VendorAvailability::day('', null, null));
        self::assertSame('', VendorAvailability::day('0000-00-00', '0000-00-00 00:00:00', ''));
    }

    public function testTheWordingNamesTheOtherWeddingAndDeduplicatesIt(): void
    {
        $label = date('D j M Y', strtotime('2026-12-19'));
        $first = (object) [
            'deal_id' => 1, 'booking_id' => 900, 'status' => 'confirmed', 'day' => '2026-12-19',
            'couple' => 'Faith Njeri & James Mwangi', 'function_title' => 'Reception',
        ];
        $sameWedding = (object) [
            'deal_id' => 2, 'booking_id' => 900, 'status' => 'completed', 'day' => '2026-12-19',
            'couple' => 'Faith Njeri & James Mwangi', 'function_title' => 'Church Wedding',
        ];
        $other = (object) [
            'deal_id' => 3, 'booking_id' => 901, 'status' => 'confirmed', 'day' => '2026-12-19',
            'couple' => 'Akinyi & Otieno', 'function_title' => '',
        ];

        self::assertSame(
            'Nyama Choma Masters is already confirmed for Faith Njeri & James Mwangi on ' . $label . ' (Reception).',
            VendorAvailability::notice([$first, $sameWedding], 'Nyama Choma Masters'),
            'two deals at one wedding are one collision, not two'
        );
        self::assertStringContainsString(
            'also committed to one more wedding that day',
            VendorAvailability::notice([$first, $other], 'Nyama Choma Masters')
        );
        self::assertSame(
            'Already committed to Faith Njeri & James Mwangi on ' . $label . ' (Reception).',
            VendorAvailability::alert([$first])
        );
        self::assertSame(
            'You are also booked for Faith Njeri & James Mwangi on ' . $label . ' (Reception).',
            VendorAvailability::self_notice([$first])
        );

        // No clash, no sentence — a page must be able to print nothing at all.
        self::assertSame('', VendorAvailability::notice([]));
        self::assertSame('', VendorAvailability::alert([]));
        self::assertSame('', VendorAvailability::self_notice([]));
    }

    // ---------------------------------------------------------------------
    // 2. What counts as a commitment
    // ---------------------------------------------------------------------

    #[Group('integration')]
    public function testCommitmentsAreConfirmedRowsAtOtherWeddingsOnTheSameDayOnly(): void
    {
        $this->requireDb();
        $this->watchBooking(self::PROBE_BOOKING);

        $byVendor = VendorAvailability::commitments([self::VENDOR_ID]);
        $onDay    = $byVendor[self::VENDOR_ID][self::$busyDay] ?? [];
        self::assertNotSame([], $onDay, 'the seeded confirmed deal must index under its day');
        self::assertContains(
            self::$busyDealId,
            array_map(static fn(object $row): int => (int) $row->deal_id, $onDay)
        );

        // The other wedding on that day is a clash; the wedding itself is not,
        // and neither is any other day.
        $clash = VendorAvailability::clashes(self::VENDOR_ID, self::$busyDay);
        self::assertNotSame([], $clash);
        self::assertSame(
            [],
            VendorAvailability::clashes(self::VENDOR_ID, self::$busyDay, self::BUSY_BOOKING),
            'a vendor booked twice for ONE wedding is not double-booked'
        );
        self::assertSame([], VendorAvailability::clashes(self::VENDOR_ID, '1999-01-01'));
        self::assertSame([], VendorAvailability::clashes(0, self::$busyDay));
        self::assertSame([], VendorAvailability::commitments([0, -4]));
        self::assertSame([], VendorAvailability::excluding($clash, self::BUSY_BOOKING));

        // A pending enquiry does not commit anybody — only an agreed deal does.
        $pending = $this->probeDeal('pending', self::PROBE_BOOKING);
        $indexed = VendorAvailability::commitments([self::VENDOR_ID]);
        self::assertNotContains(
            $pending,
            array_map(
                static fn(object $row): int => (int) $row->deal_id,
                $indexed[self::VENDOR_ID][self::$busyDay] ?? []
            ),
            'an enquiry is not an assignment'
        );
    }

    // ---------------------------------------------------------------------
    // 3. The planner's confirm gate
    // ---------------------------------------------------------------------

    #[Group('integration')]
    public function testConfirmingAClashingDealNeedsTheAcknowledgementAndRecordsIt(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $this->watchBooking(self::PROBE_BOOKING);

        $dealId = $this->probeDeal('pending', self::PROBE_BOOKING);

        $page = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('already confirmed for', $page['body'], 'the row must flag the clash before anyone confirms');
        self::assertStringContainsString('Linda Wafula', $page['body'], 'and name the wedding already holding the vendor');
        self::assertStringContainsString('Book them anyway', $page['body']);
        $token = self::csrfFrom($page['body']);

        // Without the tick: refused, and nothing is written.
        $refused = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $dealId,
            'to'         => 'confirmed',
            'amount'     => '50000',
            '_token'     => $token,
        ]);
        self::assertSame(302, $refused['status']);
        self::assertSame('pending', self::dealStatus($dealId), 'an unacknowledged clash must not be confirmed');

        $afterRefusal = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertStringContainsString('Nothing has been changed', $afterRefusal['body']);
        self::assertStringContainsString('already confirmed for', $afterRefusal['body']);

        // With the tick: confirmed, and the trail says why.
        $token   = self::csrfFrom($afterRefusal['body']);
        $allowed = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id'        => $dealId,
            'to'                => 'confirmed',
            'amount'            => '50000',
            'acknowledge_clash' => '1',
            '_token'            => $token,
        ]);
        self::assertSame(302, $allowed['status']);
        self::assertSame('confirmed', self::dealStatus($dealId));

        $notes = (string) self::$db->fetchValue(
            'SELECT notes FROM tblvendor_bookings WHERE id = :id',
            [':id' => $dealId]
        );
        self::assertStringContainsString('double-booking acknowledged', $notes);
        self::assertStringContainsString('Linda Wafula', $notes);

        $landed = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertStringContainsString('Recorded with a known clash', $landed['body']);
    }

    #[Group('integration')]
    public function testAFreeDayIsConfirmedWithoutAnyFriction(): void
    {
        $this->requireDb();
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $this->watchBooking(self::PROBE_BOOKING);

        // A different day entirely: the same enquiry, no acknowledgement offered
        // or required — the gate must exist for the collision, not for confirming.
        $dealId = $this->probeDeal('pending', self::PROBE_BOOKING, '2027-03-14');

        $page = self::http('GET', '/admin/vendor_enquiries.php');
        self::assertSame(200, $page['status']);
        $token = self::csrfFrom($page['body']);

        $res = self::http('POST', '/admin/vendor_enquiries.php', [
            'enquiry_id' => $dealId,
            'to'         => 'confirmed',
            'amount'     => '40000',
            '_token'     => $token,
        ]);
        self::assertSame(302, $res['status']);
        self::assertSame('confirmed', self::dealStatus($dealId));

        $notes = (string) self::$db->fetchValue('SELECT notes FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
        self::assertStringNotContainsString('double-booking', $notes);
    }

    // ---------------------------------------------------------------------
    // 4. The couple's enquiry
    // ---------------------------------------------------------------------

    #[Group('integration')]
    public function testACoupleEnquiryIsRecordedAndFlaggedNotRefused(): void
    {
        $this->requireDb();
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
        $this->watchBooking(self::PROBE_BOOKING);
        $marker = '[availability-couple-probe]';

        $links = 'booking_id=' . self::PROBE_BOOKING . '&user_id=' . self::PROBE_BOOKING;
        $form  = self::http('GET', '/admin/client_vendors.php?' . $links);
        self::assertSame(200, $form['status']);
        $token = self::csrfFrom($form['body']);

        $sent = self::http('POST', '/admin/client_vendor_enquiry.php?' . $links, [
            'vendor_id'  => (string) self::VENDOR_ID,
            'package_id' => '0',
            'event_date' => self::$busyDay,
            'message'    => $marker . ' please quote for the reception',
            '_token'     => $token,
        ]);
        self::assertSame(302, $sent['status'], 'the enquiry itself must still be accepted');

        // The deal exists — a couple's request is data, not a refused action.
        $dealId = (int) self::$db->fetchValue(
            'SELECT id FROM tblvendor_bookings WHERE booking_id = :b AND notes LIKE :m ORDER BY id DESC LIMIT 1',
            [':b' => self::PROBE_BOOKING, ':m' => '%' . $marker . '%']
        );
        self::assertGreaterThan(0, $dealId, 'the enquiry must be recorded');
        $this->probeDealIds[] = $dealId;

        $back = self::http('GET', '/admin/client_vendors.php?' . $links);
        self::assertStringContainsString('already confirmed for', $back['body'], 'the couple must be told');
        self::assertStringContainsString('Your planner has been told', $back['body']);

        // And so is the planner, on the lead itself rather than a separate hunt.
        $bell = (string) self::$db->fetchValue(
            'SELECT message FROM tblnotifications WHERE booking_id = :b AND message LIKE :m ORDER BY id DESC LIMIT 1',
            [':b' => self::PROBE_BOOKING, ':m' => '%Already committed to%']
        );
        self::assertStringContainsString('Already committed to Linda Wafula', $bell);
    }

    // ---------------------------------------------------------------------
    // 5. The vendor's own dashboard
    // ---------------------------------------------------------------------

    #[Group('integration')]
    public function testTheVendorSeesTheCollisionBeforeAcceptingAndItIsRecorded(): void
    {
        $this->requireDb();
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
        $this->watchBooking(self::PROBE_BOOKING);

        $dealId = $this->probeDeal('pending', self::PROBE_BOOKING);

        $page = self::http('GET', '/admin/vendor_home.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('You are also booked for Linda Wafula', $page['body'], 'flagged before Accept');
        $token = self::csrfFrom($page['body']);

        $accepted = self::http('POST', '/admin/vendor_home.php', [
            'booking_row_id' => $dealId,
            'to'             => 'confirmed',
            'amount'         => '45000',
            '_token'         => $token,
        ]);
        self::assertSame(302, $accepted['status']);
        self::assertSame('confirmed', self::dealStatus($dealId), 'the vendor is never blocked — they know their capacity');

        $notes = (string) self::$db->fetchValue('SELECT notes FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
        self::assertStringContainsString('accepted while also booked', $notes);

        $landed = self::http('GET', '/admin/vendor_home.php');
        self::assertStringContainsString("Your couple's planner has been told", $landed['body']);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function requireDb(): void
    {
        if (self::$db === null || self::$base === null || self::$busyDay === '') {
            self::markTestSkipped('needs the seeded test DB and a bootable php -S');
        }
    }

    /** Remember where this booking's notifications and SMS logs start, so teardown stops there. */
    private function watchBooking(int $bookingId): void
    {
        if (self::$db === null || isset($this->watermarks[$bookingId])) {
            return;
        }
        $this->watermarks[$bookingId] = [
            'notif' => (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications'),
            'sms'   => (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblsms_logs'),
        ];
    }

    /**
     * A probe deal for vendor 200 on the probe wedding, dated to collide with
     * their seeded confirmed deal unless the caller names another day.
     */
    private function probeDeal(string $status, int $bookingId, ?string $day = null): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_bookings
                (vendor_id, booking_id, package_id, features_id, event_date, amount, commission, status, notes)
             VALUES (200, :b, NULL, NULL, :d, 50000, 5000, :st, '[availability-probe]')",
            [':b' => $bookingId, ':d' => $day ?? self::$busyDay, ':st' => $status]
        );
        $id = (int) self::$db->the_insert_id();
        $this->probeDealIds[] = $id;
        return $id;
    }

    private static function dealStatus(int $dealId): string
    {
        return (string) self::$db->fetchValue('SELECT status FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
    }

    private static function csrfFrom(string $body): string
    {
        if (preg_match('/name="_token" value="([^"]+)"/', $body, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract a CSRF token from the page');
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_av_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_av_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 32000 + random_int(0, 8999);
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

        $status   = 0;
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
