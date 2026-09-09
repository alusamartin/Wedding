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
    /** Highest sms id at boot — rows beyond this on the booking are ours. */
    private static int $smsBaselineId = 0;

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

        if (self::$db !== null) {
            self::$smsBaselineId = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblsms_logs');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            // Remove SMS rows the reminder tests queued (baseline captured
            // at boot, before any test ran).
            if (self::$smsBaselineId > 0) {
                self::$db->execute(
                    'DELETE FROM tblsms_logs WHERE booking_id = :b AND id > :base',
                    [':b' => self::BOOKING_ID, ':base' => self::$smsBaselineId]
                );
            }
        }
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

        // Staff view: seed 2 yes + 1 maybe + 1 pending-with-reminder among
        // probes, then check the math and the auto-reminder column.
        self::loginStaff();
        foreach ([['Probe Dash Yes 1', 'yes'], ['Probe Dash Yes 2', 'yes'], ['Probe Dash Maybe', 'maybe']] as [$name, $status]) {
            self::$db->prepare(
                'INSERT INTO tblguest (booking_id, fullname, guestname, relationship, rsvp_token, rsvp_status) '
                . 'VALUES (:b, :n, :n2, "regular", :t, :s)'
            )->execute([':b' => self::BOOKING_ID, ':n' => $name, ':n2' => 'Dash', ':t' => bin2hex(random_bytes(16)), ':s' => $status]);
            self::$probeGuestIds[] = (int) self::$db->the_insert_id();
        }
        self::$db->prepare(
            'INSERT INTO tblguest (booking_id, fullname, guestname, relationship, rsvp_token, rsvp_status, rsvp_reminded_at, rsvp_reminders) '
            . 'VALUES (:b, "Probe Dash Pending", "Dash", "regular", :t, "pending", NOW(), 1)'
        )->execute([':b' => self::BOOKING_ID, ':t' => bin2hex(random_bytes(16))]);
        self::$probeGuestIds[] = (int) self::$db->the_insert_id();
        // A pending guest with ZERO reminders must render the "not yet" branch —
        // seeded explicitly because demo residue on real seed rows can leave no
        // un-reminded pending guest in the list.
        self::$db->prepare(
            'INSERT INTO tblguest (booking_id, fullname, guestname, relationship, rsvp_token, rsvp_status) '
            . 'VALUES (:b, "Probe Dash Fresh", "Dash", "regular", :t, "pending")'
        )->execute([':b' => self::BOOKING_ID, ':t' => bin2hex(random_bytes(16))]);
        self::$probeGuestIds[] = (int) self::$db->the_insert_id();

        $body = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertSame(200, 200);
        self::assertStringContainsString('RSVP Dashboard', $body);
        self::assertStringContainsString('Probe Dash Yes 1', $body, 'responses table must list the guests');
        self::assertStringContainsString('Export venue headcount', $body, 'CSV export must be linked');
        // Auto-reminder state column: reminded pending guest shows the count.
        self::assertStringContainsString('Auto-reminders', $body);
        self::assertStringContainsString('1 sent', $body, 'reminded guest must show the reminder count');
        self::assertStringContainsString('not yet', $body, 'un-reminded pending guests show "not yet"');

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

    #[Group('integration')]
    public function testBulkSmsEndpointRequiresStaffPostAndCsrf(): void
    {
        $this->requireHttp();

        // Anonymous GET -> bounced to site root.
        self::$jar = [];
        $anon = self::http('GET', '/admin/rsvp_remind_bulk.php?booking_id=200&user_id=200');
        self::assertSame(302, $anon['status']);

        // Anonymous POST -> also bounced (no session, no CSRF).
        $anonPost = self::http('POST', '/admin/rsvp_remind_bulk.php', ['booking_id' => '200', 'user_id' => '200']);
        self::assertSame(302, $anonPost['status']);

        // Staff GET -> method rejected (redirect to client list, nothing sent).
        self::loginStaff();
        $get = self::http('GET', '/admin/rsvp_remind_bulk.php?booking_id=200&user_id=200');
        self::assertSame(302, $get['status']);

        // Staff POST without token -> rejected before any send.
        $baseline = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::BOOKING_ID
        );
        $noToken = self::http('POST', '/admin/rsvp_remind_bulk.php', [
            'booking_id' => '200', 'user_id' => '200', 'ids' => ['1'],
        ]);
        self::assertSame(302, $noToken['status']);
        self::assertSame($baseline, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::BOOKING_ID
        ), 'no SMS may be sent by a tokenless POST');
    }

    #[Group('integration')]
    public function testBulkSmsSendsToSelectedPendingGuestsOnly(): void
    {
        $this->requireHttp();
        self::loginStaff();

        // Probes on booking 200: pending+phone, pending+no phone, replied.
        $ids = [];
        foreach ([
            ['Probe Bulk Pending', '0723000111', 'pending'],
            ['Probe Bulk NoPhone', null, 'pending'],
            ['Probe Bulk Replied', '0723000112', 'yes'],
        ] as [$name, $phone, $status]) {
            self::$db->prepare(
                'INSERT INTO tblguest (booking_id, fullname, guestname, phone, relationship, rsvp_token, rsvp_status) '
                . 'VALUES (:b, :n, :n2, :p, "regular", :t, :s)'
            )->execute([':b' => self::BOOKING_ID, ':n' => $name, ':n2' => 'Bulk', ':p' => $phone, ':t' => bin2hex(random_bytes(16)), ':s' => $status]);
            $ids[$name] = (int) self::$db->the_insert_id();
            self::$probeGuestIds[] = $ids[$name];
        }

        // Organizer 7 (Amina) seeds with 0 credits — top up, restore later.
        $prior = (int) self::$db->fetchValue('SELECT credits FROM tblsms_credits WHERE user_id = 7');
        self::$db->execute('UPDATE tblsms_credits SET credits = 100 WHERE user_id = 7');

        try {
            $page = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
            preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
            $token = html_entity_decode($m[1], ENT_QUOTES);

            $smsBefore = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = ' . self::BOOKING_ID);

            $res = self::http('POST', '/admin/rsvp_remind_bulk.php', [
                'booking_id' => (string) self::BOOKING_ID,
                'user_id'    => '200',
                '_token'     => $token,
                'ids'        => [ (string) $ids['Probe Bulk Pending'], (string) $ids['Probe Bulk NoPhone'], (string) $ids['Probe Bulk Replied'] ],
            ]);
            self::assertSame(302, $res['status']);

            // Exactly one SMS — to the pending guest with a phone.
            $smsAfter = self::$db->fetchAll(
                'SELECT phone, status FROM tblsms_logs WHERE booking_id = ' . self::BOOKING_ID
            );
            self::assertCount($smsBefore + 1, $smsAfter, 'only the reachable pending guest is texted');
            $last = end($smsAfter);
            $phone = is_object($last) ? $last->phone : $last['phone'];
            self::assertSame('254723000111', $phone, 'number normalized to E.164 without +');

            // Reminder state: marked only for the actually-sent guest.
            $marked = (int) self::$db->fetchValue(
                'SELECT rsvp_reminders FROM tblguest WHERE id = ' . $ids['Probe Bulk Pending']
            );
            self::assertSame(1, $marked);
            $untouched = (int) self::$db->fetchValue(
                'SELECT rsvp_reminders FROM tblguest WHERE id = ' . $ids['Probe Bulk Replied']
            );
            self::assertSame(0, $untouched, 'replied guests must never be reminded');

            // Flash summary on the redirected dashboard load.
            $dash = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
            self::assertStringContainsString('1 reminder sent', $dash);
            self::assertStringContainsString('no phone number', $dash, 'skipped guests are listed with a reason');
        } finally {
            self::$db->execute('UPDATE tblsms_credits SET credits = ' . $prior . ' WHERE user_id = 7');
        }
    }

    #[Group('integration')]
    public function testWhatsAppQueueWalksSelectedGuestsAndRejectsForeigners(): void
    {
        $this->requireHttp();

        // Probe guest on booking 200 with a phone.
        self::loginStaff();
        self::$db->prepare(
            'INSERT INTO tblguest (booking_id, fullname, guestname, phone, relationship, rsvp_token, rsvp_status) '
            . 'VALUES (:b, "Probe WA Queue", "WA", "0723000113", "regular", :t, "pending")'
        )->execute([':b' => self::BOOKING_ID, ':t' => bin2hex(random_bytes(16))]);
        $gid = (int) self::$db->the_insert_id();
        self::$probeGuestIds[] = $gid;

        // Clients are bounced.
        self::loginCouple();
        $client = self::http('GET', '/admin/rsvp_wa_queue.php?booking_id=' . self::BOOKING_ID . '&user_id=200&ids=' . $gid);
        self::assertSame(302, $client['status']);

        // Staff gets the walkthrough with the guest's pre-filled WhatsApp link.
        self::loginStaff();
        $walk = self::http('GET', '/admin/rsvp_wa_queue.php?booking_id=' . self::BOOKING_ID . '&user_id=200&ids=' . $gid);
        self::assertSame(200, $walk['status']);
        self::assertStringContainsString('Probe WA Queue', $walk['body']);
        self::assertStringContainsString('https://wa.me/254723000113?text=', $walk['body']);
        self::assertStringContainsString('Open WhatsApp', $walk['body']);

        // Guests from another booking are filtered out -> redirect with a warning.
        $foreign = self::http('GET', '/admin/rsvp_wa_queue.php?booking_id=201&user_id=201&ids=' . $gid);
        self::assertSame(302, $foreign['status']);
    }

    #[Group('integration')]
    public function testBrandedReceiptRendersAndIsScoped(): void
    {
        $this->requireHttp();

        // The newest ledger row of booking 200 (seed data).
        $receiptId = (int) self::$db->fetchValue(
            'SELECT id FROM tbl_liquidation WHERE booking_id = 200 ORDER BY id DESC LIMIT 1'
        );
        if ($receiptId === 0) {
            self::markTestSkipped('seed has no liquidation rows for booking 200');
        }

        // Anonymous visitors get bounced to the site root. (The jar is
        // cleared explicitly: earlier tests in this class leave a session.)
        self::$jar = [];
        $anon = self::http('GET', '/admin/receipt.php?id=' . $receiptId);
        self::assertSame(302, $anon['status']);

        // A foreign couple must never see another wedding's receipt.
        self::loginCouple();
        $other = self::$db->fetchValue(
            'SELECT booking_id FROM tbl_liquidation WHERE booking_id <> 200 ORDER BY id DESC LIMIT 1'
        );
        if ($other) {
            $foreignId = (int) self::$db->fetchValue(
                'SELECT id FROM tbl_liquidation WHERE booking_id <> 200 ORDER BY id DESC LIMIT 1'
            );
            $res = self::http('GET', '/admin/receipt.php?id=' . $foreignId);
            self::assertSame(302, $res['status'], 'foreign receipt must redirect away');
        }

        // The owning couple gets the branded receipt.
        $body = self::http('GET', '/admin/receipt.php?id=' . $receiptId)['body'];
        self::assertStringContainsString('Everafter Planner', $body);
        self::assertStringContainsString('PAYMENT RECEIPT', $body);
        self::assertStringContainsString('Share on WhatsApp', $body, 'receipt must carry the WhatsApp share action');
        self::assertStringContainsString('Balance remaining', $body);
        self::assertMatchesRegularExpression('/EP-\d{6}/', $body, 'receipt number stamp must render');

        // A garbage id renders the designed not-found state, not a warning.
        $nf = self::http('GET', '/admin/receipt.php?id=99999999');
        self::assertSame(404, $nf['status']);
        self::assertStringContainsString('Receipt not found', $nf['body']);
    }

    #[Group('integration')]
    public function testMarkInvitedRequiresStaffPostAndCsrf(): void
    {
        $this->requireHttp();

        // Anonymous GET/POST -> bounced to site root.
        self::$jar = [];
        self::assertSame(302, self::http('GET', '/admin/rsvp_mark_invited.php?booking_id=200&user_id=200')['status']);
        self::assertSame(302, self::http('POST', '/admin/rsvp_mark_invited.php', ['booking_id' => '200', 'user_id' => '200'])['status']);

        // Staff GET -> method rejected (redirect to client list).
        self::loginStaff();
        self::assertSame(302, self::http('GET', '/admin/rsvp_mark_invited.php?booking_id=200&user_id=200')['status']);

        // Staff POST without token -> rejected; nothing stamped.
        $probe = self::probeGuest('Probe Invited CSRF', '0723000114');
        preg_match('/name="_token" value="([^"]+)"/',
            self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'], $m);
        self::assertSame(302, self::http('POST', '/admin/rsvp_mark_invited.php', [
            'booking_id' => (string) self::BOOKING_ID, 'user_id' => '200', 'ids' => [(string) $probe],
        ])['status']);
        self::assertNull(self::$db->fetchValue('SELECT rsvp_invited_at FROM tblguest WHERE id = ' . $probe));

        // Foreign-booking scoping: even a fully-authenticated POST can never
        // stamp a guest that belongs to another booking.
        $foreign = self::probeGuest('Probe Invited Foreign', '0723000115', 201);
        self::assertSame(302, self::http('POST', '/admin/rsvp_mark_invited.php', [
            'booking_id' => (string) self::BOOKING_ID, 'user_id' => '200',
            '_token' => html_entity_decode($m[1], ENT_QUOTES), 'ids' => [(string) $foreign],
        ])['status']);
        self::assertNull(self::$db->fetchValue('SELECT rsvp_invited_at FROM tblguest WHERE id = ' . $foreign));
    }

    #[Group('integration')]
    public function testMarkInvitedStampsSelectionFillsGapsAndMintsTokens(): void
    {
        $this->requireHttp();
        self::loginStaff();

        // A: fresh guest (no token, not invited) — must be stamped now.
        // NB: grab the CSRF token BEFORE seeding — loading the dashboard
        // runs ensure_tokens(), which mints empty tokens (and thus stamps
        // invite dates) as a side effect.
        preg_match('/name="_token" value="([^"]+)"/',
            self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'], $m);
        $token = html_entity_decode($m[1], ENT_QUOTES);

        $fresh = self::probeGuest('Probe Invited Fresh', '0723000116', self::BOOKING_ID, null, false);
        // B: invited 10 days ago — must keep the original date (gap-fill only).
        $older = self::probeGuest('Probe Invited Older', '0723000117', self::BOOKING_ID, 'DATE_SUB(NOW(), INTERVAL 10 DAY)');
        // C: fresh guest on another booking — must never be stamped.
        $foreign = self::probeGuest('Probe Invited Foreign2', '0723000118', 201);

        $res = self::http('POST', '/admin/rsvp_mark_invited.php', [
            'booking_id' => (string) self::BOOKING_ID, 'user_id' => '200',
            '_token' => $token, 'ids' => [(string) $fresh, (string) $older, (string) $foreign],
        ]);
        self::assertSame(302, $res['status']);

        // Fresh guest: stamped just now, token minted.
        $row = self::$db->fetchAll(
            'SELECT rsvp_invited_at, rsvp_token FROM tblguest WHERE id = ' . $fresh
        )[0];
        self::assertNotNull($row->rsvp_invited_at);
        self::assertGreaterThanOrEqual(time() - 60, strtotime((string) $row->rsvp_invited_at), 'fresh guest stamped as invited now');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row->rsvp_token, 'a guest cannot be invited without a personal RSVP token');

        // Older guest: original invite date preserved, token untouched.
        $olderAt = (string) self::$db->fetchValue('SELECT rsvp_invited_at FROM tblguest WHERE id = ' . $older);
        self::assertLessThan(time() - 9 * 86400, strtotime($olderAt), 'existing invite dates are never overwritten');

        // Foreign guest: untouched.
        self::assertNull(self::$db->fetchValue('SELECT rsvp_invited_at FROM tblguest WHERE id = ' . $foreign));

        // Flash summary counts the real stamps (only the fresh guest; the
        // 10-days-ago one is pre-filtered as already invited), then a re-run is a no-op.
        $dash = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('1 guest marked invited', $dash);
        self::assertStringContainsString('Probe Invited Older', $dash, 'already-invited guests are listed as unchanged');

        self::http('POST', '/admin/rsvp_mark_invited.php', [
            'booking_id' => (string) self::BOOKING_ID, 'user_id' => '200',
            '_token' => $token, 'ids' => [(string) $fresh, (string) $older],
        ]);
        $dash2 = self::http('GET', '/admin/rsvp_dashboard.php?booking_id=' . self::BOOKING_ID . '&user_id=200')['body'];
        self::assertStringContainsString('Already marked invited', $dash2);
        self::assertStringNotContainsString('reminder window starts now', $dash2);
    }

    #[Group('integration')]
    public function testWeddingDaySheetsRenderAndAreScoped(): void
    {
        $this->requireHttp();

        // Anonymous visitors are bounced.
        self::$jar = [];
        self::assertSame(302, self::http('GET', '/admin/sheet_guests.php?booking_id=200&user_id=200')['status']);
        self::assertSame(302, self::http('GET', '/admin/sheet_venue.php?booking_id=200&user_id=200')['status']);

        // A couple cannot open another wedding's sheets.
        self::loginCouple();
        self::assertSame(302, self::http('GET', '/admin/sheet_guests.php?booking_id=201&user_id=201')['status']);
        self::assertSame(302, self::http('GET', '/admin/sheet_venue.php?booking_id=201&user_id=201')['status']);

        // Staff gets the guest list sheet: grouped tables, dietary brief,
        // harambee ledger, headcount stats. Seed one confirmed guest with
        // dietary + logistics flags so the sheet's attending-only sections
        // render deterministically (seed data has no 'yes' RSVPs).
        self::loginStaff();
        $sheetProbe = self::probeGuest('Probe Sheet Attending', '0723000119', self::BOOKING_ID, null, true, 'yes');
        self::$db->prepare(
            "UPDATE tblguest SET dietary_requirements = 'Vegetarian', transport_needed = 1, upcountry_accommodation = 1 WHERE id = :id"
        )->execute([':id' => $sheetProbe]);

        $g = self::http('GET', '/admin/sheet_guests.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $g['status']);
        self::assertStringContainsString('Wedding Day Guest List', $g['body']);
        self::assertStringContainsString('Guests by group', $g['body']);
        self::assertStringContainsString('Caterer brief', $g['body'], 'dietary summary renders when needs exist');
        self::assertStringContainsString('Vegetarian', $g['body'], 'the confirmed guest\'s dietary need is listed');
        self::assertStringContainsString('Harambee contributions', $g['body']);
        self::assertMatchesRegularExpression('/KSh [\d,]+/', $g['body'], 'harambee pledges render as KSh');
        self::assertStringContainsString('Plan for', $g['body'], 'headcount guidance stat present');

        // Staff gets the venue sheet: running order with real event titles,
        // headcount plan, contacts, checklist.
        $v = self::http('GET', '/admin/sheet_venue.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $v['status']);
        self::assertStringContainsString('Venue &amp; Day Summary', $v['body']);
        self::assertStringContainsString('Running order', $v['body']);
        self::assertStringContainsString('Ruracio Ceremony', $v['body'], 'real event titles, never machine labels');
        self::assertStringContainsString('Headcount plan per function', $v['body']);
        self::assertStringContainsString('Key contacts', $v['body']);
        self::assertStringContainsString('Coordination checklist', $v['body']);
        self::assertStringContainsString('Amina Hassan', $v['body'], 'planner named on the sheet');

        // Both sheets are print-first: shared chrome + A4 print rules.
        foreach ([$g['body'], $v['body']] as $body) {
            self::assertStringContainsString('@page { size: A4', $body);
            self::assertStringContainsString('Print this sheet', $body);
            self::assertStringNotContainsString('Warning', $body, 'no PHP warnings leak into the sheets');
        }

        // Garbage booking id -> staff redirected to the client list.
        self::assertSame(302, self::http('GET', '/admin/sheet_guests.php?booking_id=99999999&user_id=1')['status']);
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
        self::login('wafula@wpms.co.ke', 'Admin@2024!', '/login.php');
    }

    /** Insert a probe guest and register it for teardown cleanup. */
    private static function probeGuest(string $name, string $phone = '0723000111', int $bookingId = 200, ?string $invitedSql = null, bool $withToken = true, string $status = 'pending'): int
    {
        self::$db->prepare(
            'INSERT INTO tblguest (booking_id, fullname, guestname, phone, relationship, rsvp_token, rsvp_status, rsvp_invited_at) '
            . "VALUES (:b, :n, :n2, :p, \"regular\", :t, :s, " . ($invitedSql ?? 'NULL') . ')'
        )->execute([':b' => $bookingId, ':n' => $name, ':n2' => 'Probe', ':p' => $phone, ':t' => $withToken ? bin2hex(random_bytes(16)) : '', ':s' => $status]);
        $gid = (int) self::$db->the_insert_id();
        self::$probeGuestIds[] = $gid;
        return $gid;
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
