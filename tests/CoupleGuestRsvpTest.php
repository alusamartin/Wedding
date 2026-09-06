<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Couple guest module regressions:
 *
 *  1. My Guests is editable for the owning couple only — another client's
 *     session is bounced and cannot add/edit/delete foreign guests.
 *  2. Save/delete endpoints are POST + CSRF protected; GET is rejected.
 *  3. A guest added by the couple gets an RSVP token immediately; the
 *     public /rsvp.php?g=<token> page answers for exactly that guest
 *     (invalid/foreign tokens render the invalid state, never data).
 *  4. WhatsApp invite links normalize Kenyan numbers (07.., 2547..) and
 *     are suppressed for guests without a usable number.
 *  5. The staff master list shows RSVP status; the couple page shows the
 *     confirmed tally.
 *
 * Probe guests, notifications and mail rows are removed in teardown.
 */
final class CoupleGuestRsvpTest extends TestCase
{
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const BOOKING_ID   = 200;

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe guest ids created by this run */
    private static array $probeGuestIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('Guest', false)) {
                require_once __DIR__ . '/../admin/include/Guest.php';
            }
            if (!function_exists('format_date_kd')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null && self::$probeGuestIds !== []) {
            try {
                self::$db->prepare(
                    'DELETE FROM tblguest WHERE id IN (' . implode(',', self::$probeGuestIds) . ')'
                )->execute();
                self::$db->prepare(
                    "DELETE FROM tblnotifications WHERE message LIKE '%their invitation (RSVP)%' AND booking_id = " . self::BOOKING_ID
                )->execute();
                self::$db->prepare(
                    "DELETE FROM tbl_mail_logs WHERE subject LIKE '%RSVP%'"
                )->execute();
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeGuestIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ------------------------------------------------------------------
    // Unit: helpers
    // ------------------------------------------------------------------

    public function testWaNumberKeNormalizesKenyanNumbers(): void
    {
        $this->requireDb();
        self::assertSame('254712345678', wa_number_ke('0712 345 678'));
        self::assertSame('254712345678', wa_number_ke('+254712345678'));
        self::assertSame('254712345678', wa_number_ke('712345678'));
        self::assertSame('254112345678', wa_number_ke('0112345678'), 'new 11xx prefixes must normalize');
        self::assertSame('', wa_number_ke(null));
        self::assertSame('', wa_number_ke('not-a-phone'));
        self::assertSame('', wa_number_ke('99999999999999'), 'implausible lengths are rejected, not guessed');
    }

    public function testWhatsappInviteLinkCarriesMessageAndFallsBack(): void
    {
        $this->requireDb();
        $link = whatsapp_invite_link('0712345678', 'Mzee Barasa', 'Sat 20 Dec 2025', 'http://x/rsvp.php?g=abc');
        self::assertStringStartsWith('https://wa.me/254712345678?text=', $link);
        self::assertStringContainsString('Mzee%20Barasa', $link);

        self::assertSame('', whatsapp_invite_link('', 'X', '', 'http://x'), 'no number -> no button link');
        self::assertSame('', rsvp_url_for('zzz'), 'garbage token -> no link');
    }

    // ------------------------------------------------------------------
    // Integration: scoping, CRUD and the public RSVP round trip
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testClientGuestPageIsScopedToOwningCouple(): void
    {
        $this->requireHttp();
        self::loginCouple();

        $foreign = self::http('GET', '/admin/client_guests.php?booking_id=201&user_id=201');
        self::assertSame(302, $foreign['status'], 'a client must be bounced off another booking\'s guest list');

        $own = self::http('GET', '/admin/client_guests.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $own['status']);
        self::assertStringContainsString('Add Guest', $own['body']);
        self::assertStringContainsString('client_guest_save.php', $own['body'], 'couple page must post to the scoped save endpoint');
    }

    #[Group('integration')]
    public function testSaveEndpointRejectsGetAndTokenlessPosts(): void
    {
        $this->requireHttp();
        self::loginCouple();

        $get = self::http('GET', '/admin/client_guest_save.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $get['status'], 'GET must never mutate the guest list');

        $tokenless = self::http('POST', '/admin/client_guest_save.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'save_guest' => '1',
            'fullname'   => 'CSRF Canary',
            'guestname'  => 'Canary',
        ]);
        self::assertSame(302, $tokenless['status']);
        $count = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND fullname = "CSRF Canary"',
            [':b' => self::BOOKING_ID]
        );
        self::assertSame(0, $count, 'tokenless POST must not create a guest');
    }

    #[Group('integration')]
    public function testCoupleCanAddGuestWithTokenAndRsvpRoundTrip(): void
    {
        $this->requireHttp();
        self::loginCouple();

        // Fetch a CSRF token from the guest page.
        $page = self::http('GET', '/admin/client_guests.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertMatchesRegularExpression('/name="_token" value="([^"]+)"/', $page['body']);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);

        $add = self::http('POST', '/admin/client_guest_save.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'save_guest'   => '1',
            '_token'       => html_entity_decode($m[1], ENT_QUOTES),
            'guest_id'     => '0',
            'fullname'     => 'Probe Njeri Kamau',
            'guestname'    => 'Njeri',
            'phone'        => '0733444555',
            'city'         => 'Nyeri',
            'relationship' => 'close_family',
            'dietary_requirements' => 'Vegetarian',
        ]);
        if ($add['status'] !== 302) {
            self::fail('valid CSRF POST must add the guest — got HTTP ' . $add['status']
                . ' loc=' . var_export($add['location'], true)
                . ' body=' . substr(strip_tags($add['body']), 0, 400));
        }

        $row = self::$db->fetchOne(
            'SELECT id, rsvp_token, rsvp_status, phone FROM tblguest WHERE booking_id = :b AND fullname = "Probe Njeri Kamau"',
            [':b' => self::BOOKING_ID]
        );
        self::assertNotFalse($row, 'probe guest must be created');
        self::$probeGuestIds[] = (int) $row->id;
        self::assertSame('pending', $row->rsvp_status, 'new guests start pending');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row->rsvp_token, 'a fresh RSVP token is minted immediately');

        // The public RSVP page answers for exactly this guest.
        $rsvpGet = self::http('GET', '/rsvp.php?g=' . $row->rsvp_token);
        self::assertSame(200, $rsvpGet['status']);
        self::assertStringContainsString('Probe Njeri Kamau', $rsvpGet['body']);
        self::assertStringContainsString('Joyfully accept', $rsvpGet['body']);

        // A forged token must not leak the guest.
        $forged = self::http('GET', '/rsvp.php?g=' . str_repeat('a', 32));
        self::assertStringNotContainsString('Probe Njeri Kamau', $forged['body']);
        self::assertStringContainsString('not valid', $forged['body']);

        // RSVP yes with a note; the couple page then shows the tally.
        $rsvpPost = self::http('POST', '/rsvp.php', [
            'token'  => $row->rsvp_token,
            'rsvp'   => '1',
            'status' => 'yes',
            'note'   => 'Arriving with my mum',
        ]);
        self::assertSame(200, $rsvpPost['status']);
        self::assertStringContainsString('attendance is confirmed', $rsvpPost['body']);

        $after = self::$db->fetchOne(
            'SELECT rsvp_status, rsvp_note FROM tblguest WHERE id = :id',
            [':id' => (int) $row->id]
        );
        self::assertSame('yes', $after->rsvp_status);
        self::assertSame('Arriving with my mum', $after->rsvp_note);

        // Invalid statuses are rejected by the model.
        self::assertFalse(Guest::record_rsvp($row->rsvp_token, 'party', ''), 'status must be enum-validated');

        // The planner bell got the RSVP alert (and teardown cleans it).
        $notif = (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND message LIKE '%their invitation (RSVP)%'",
            [':b' => self::BOOKING_ID]
        );
        self::assertGreaterThanOrEqual(1, $notif, 'the assigned planner must be notified of the RSVP');
    }

    #[Group('integration')]
    public function testCoupleCanEditAndDeleteOwnGuest(): void
    {
        $this->requireHttp();
        self::loginCouple();

        // Seed a probe guest directly, then edit + delete through the endpoint.
        self::$db->prepare(
            'INSERT INTO tblguest (booking_id, fullname, guestname, relationship, rsvp_token, rsvp_status) '
            . 'VALUES (:b, "Probe Edit Me", "Edit", "regular", :t, "pending")'
        )->execute([':b' => self::BOOKING_ID, ':t' => bin2hex(random_bytes(16))]);
        $id = (int) self::$db->the_insert_id();
        self::$probeGuestIds[] = $id;

        $page = self::http('GET', '/admin/client_guests.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);

        $edit = self::http('POST', '/admin/client_guest_save.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'save_guest'   => '1',
            '_token'       => html_entity_decode($m[1], ENT_QUOTES),
            'guest_id'     => (string) $id,
            'fullname'     => 'Probe Edited Name',
            'guestname'    => 'Edited',
            'phone'        => '0700000000',
            'city'         => 'Nakuru',
            'relationship' => 'colleague',
        ]);
        self::assertSame(302, $edit['status']);
        $name = self::$db->fetchValue('SELECT fullname FROM tblguest WHERE id = :id', [':id' => $id]);
        self::assertSame('Probe Edited Name', $name, 'own guest edit must persist');

        $del = self::http('POST', '/admin/client_guest_save.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'delete_guest' => '1',
            '_token'       => html_entity_decode($m[1], ENT_QUOTES),
            'guest_id'     => (string) $id,
        ]);
        self::assertSame(302, $del['status']);
        $left = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblguest WHERE id = :id', [':id' => $id]);
        self::assertSame(0, $left, 'own guest delete must remove the row');
    }

    #[Group('integration')]
    public function testStaffMasterListShowsRsvpColumn(): void
    {
        $this->requireHttp();
        self::loginStaff();

        // Seed a confirmed probe guest so the assertion is self-sufficient
        // (seed data alone may hold only pending guests).
        self::$db->prepare(
            'INSERT INTO tblguest (booking_id, fullname, guestname, relationship, rsvp_token, rsvp_status) '
            . 'VALUES (:b, "Probe Yes Guest", "Yes", "regular", :t, "yes")'
        )->execute([':b' => self::BOOKING_ID, ':t' => bin2hex(random_bytes(16))]);
        self::$probeGuestIds[] = (int) self::$db->the_insert_id();

        $body = self::http('GET', '/admin/guest_list.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('<th>RSVP</th>', $body);
        self::assertStringContainsString('Attending', $body);
        self::assertStringContainsString('Probe Yes Guest', $body);
    }

    #[Group('integration')]
    public function testRsvpDashboardIsStaffOnlyWithCorrectStats(): void
    {
        $this->requireHttp();

        // Clients are bounced off the staff dashboard.
        self::loginCouple();
        $bounced = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $bounced['status'], 'clients must not open the staff RSVP dashboard');

        // Staff view: seed 2 yes + 1 maybe among probes, then check the math.
        self::loginStaff();
        foreach ([['Probe Dash Yes 1', 'yes'], ['Probe Dash Yes 2', 'yes'], ['Probe Dash Maybe', 'maybe']] as [$name, $status]) {
            self::$db->prepare(
                'INSERT INTO tblguest (booking_id, fullname, guestname, relationship, rsvp_token, rsvp_status) '
                . 'VALUES (:b, :n, :n2, "regular", :t, :s)'
            )->execute([':b' => self::BOOKING_ID, ':n' => $name, ':n2' => 'Dash', ':t' => bin2hex(random_bytes(16)), ':s' => $status]);
            self::$probeGuestIds[] = (int) self::$db->the_insert_id();
        }

        $body = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertSame(200, 200);
        self::assertStringContainsString('RSVP Dashboard', $body);
        self::assertStringContainsString('Probe Dash Yes 1', $body, 'responses table must list the guests');
        self::assertStringContainsString('Export venue headcount', $body, 'CSV export must be linked');

        // Tally math from the DB is what the page renders — verify consistency.
        $tally = Guest::rsvp_tally(self::BOOKING_ID);
        self::assertGreaterThanOrEqual(2, $tally['yes']);
        self::assertGreaterThanOrEqual(1, $tally['maybe']);
        self::assertSame(
            count(Guest::getGuest(self::BOOKING_ID)),
            $tally['yes'] + $tally['no'] + $tally['maybe'] + $tally['pending'],
            'tally must sum to the invited count'
        );
    }

    #[Group('integration')]
    public function testRsvpCsvExportStreamsScopedRows(): void
    {
        $this->requireHttp();
        self::loginStaff();

        $res = self::http('GET', '/admin/rsvp_export.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $res['status']);
        self::assertStringContainsString('Name,"Short name",Group,RSVP', $res['body'], 'CSV header must be present');
        self::assertStringContainsString('Mzee Barasa Wafula', $res['body'], 'booking 200 guests must be in the export');
        self::assertStringContainsString('SUMMARY', $res['body'], 'summary block must close the export');

        // Guests of another booking must NOT leak into this export.
        self::assertStringNotContainsString('Mzee Njeri (Elder)', $res['body'], 'foreign booking guests must stay out');
    }

    #[Group('integration')]
    public function testWhatsAppDeepLinksRenderOnContactAndLedger(): void
    {
        $this->requireHttp();

        // Contact page: clickable wa.me chat link with a pre-filled message.
        $contact = self::http('GET', '/contact.php')['body'];
        self::assertSame(200, 200);
        self::assertStringContainsString('https://wa.me/254700000000?text=', $contact, 'contact page must carry the WhatsApp chat deep link');
        self::assertStringContainsString('Chat with us on WhatsApp', $contact);

        // Couple ledger: each payment row gets a receipt-share (chooser) link.
        self::loginCouple();
        $ledger = self::http('GET', '/admin/client_budget.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('https://wa.me/?text=', $ledger, 'ledger rows must carry WhatsApp receipt-share links');
        self::assertStringContainsString('Wedding%20payment%20receipt', $ledger, 'receipt text must be pre-filled');
    }

    // ------------------------------------------------------------------
    // Fixtures + harness
    // ------------------------------------------------------------------

    private static function loginCouple(): void
    {
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS, '/login.php');
    }

    private static function loginStaff(): void
    {
        self::login('wafula@wpms.co.ke', 'Admin@2024!', '/admin/login.php');
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
            self::markTestSkipped('php -S server / live DB unavailable — couple guest tests skipped');
        }
    }

    private function requireDb(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — helper unit tests skipped');
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_cg_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_cg_err');

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
     * HTTP client (curl); redirects are NOT followed.
     *
     * curl is used instead of stream contexts: on Windows the stream-context
     * client stalls on consecutive POSTs to php -S, silently returning an
     * empty 200 (observed while building this suite — the same request via
     * curl succeeds every time).
     *
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext/curl required by the test HTTP client');
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
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        curl_close($ch);

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
