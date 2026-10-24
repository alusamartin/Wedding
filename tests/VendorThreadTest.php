<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The deal conversation (v2.25) — the couple, the vendor and the planner on one
 * thread.
 *
 * Two layers, both against the real seeded data:
 *
 *  1. Model — stage() says the right thing to each side for every state a deal
 *     can be in (this is what the couple actually reads, so it is worth pinning
 *     in plain English); post() attributes the message and bells the other two
 *     parties; unread counts are per role and the author never counts their own
 *     words; clean_body() bounds and trims.
 *
 *  2. Doors — up the real php -S server: a GET never writes, a tokenless POST
 *     writes nothing, a couple cannot post to another couple's deal, a vendor
 *     cannot post to a listing they do not own, the opener refuses a foreign
 *     deal, and each side's post lands in the thread with the right role and
 *     opens that thread on the way back.
 *
 * Seeded fixtures used (never modified beyond the thread table):
 *   booking 200 — couple account 200, planner 7
 *   deal 201    — pending, vendor 201 (not the vendor used below)
 *   deal 200    — confirmed, vendor 200 (kamau@nyamachoma.co.ke)
 *
 * Everything this test writes is removed in tearDownAfterClass: messages and
 * notifications above the high-water marks taken at start, on the scratch
 * database the suite runs against.
 *
 * Integration ('integration' group) — needs the live test DB and a bootable
 * php -S; skips cleanly when either is unavailable.
 */
final class VendorThreadTest extends TestCase
{
    private const BOOKING      = 200;
    private const DEAL_PENDING = 201; // vendor 201 — the couple's open enquiry
    private const DEAL_VENDOR  = 200; // vendor 200 — kamau's own deal
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const VENDOR_EMAIL = 'kamau@nyamachoma.co.ke';
    private const VENDOR_PASS  = 'Vendor@2024';
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static int $messageWatermark = 0;
    private static int $notificationWatermark = 0;

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
            if (!class_exists('Vendor', false)) {
                require_once __DIR__ . '/../admin/include/Monetization.php';
            }
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            if (!class_exists('VendorThread', false)) {
                require_once __DIR__ . '/../admin/include/VendorThread.php';
            }
            if (!function_exists('format_ksh')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        if (self::$db !== null) {
            try {
                self::$messageWatermark      = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblvendor_messages');
                self::$notificationWatermark = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications');
            } catch (Throwable $e) {
                self::$db = null; // migration not applied — every test skips
            }
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                self::$db->execute(
                    'DELETE FROM tblvendor_messages WHERE id > :id',
                    [':id' => self::$messageWatermark]
                );
                self::$db->execute(
                    'DELETE FROM tblnotifications WHERE id > :id AND booking_id = :b',
                    [':id' => self::$notificationWatermark, ':b' => self::BOOKING]
                );
            } catch (Throwable $e) {
                // leave the scratch rows; the next demo:reset clears them
            }
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // =====================================================================
    // The next step, in each side's own words
    // =====================================================================

    public function testStageSaysWhoseMoveItIsToEachSide(): void
    {
        require_once __DIR__ . '/../admin/include/Helper.php';
        require_once __DIR__ . '/../admin/include/VendorThread.php';

        $deal = (object) [
            'id'            => 1,
            'status'        => 'pending',
            'business_name' => 'Nyama Choma Masters',
            'bride'         => 'Linda',
            'groom'         => 'Brian',
            'amount'        => 0,
            'category'      => 'catering',
        ];
        $coupleMsg = (object) ['author_role' => 'couple', 'author_name' => 'Linda & Brian', 'body' => 'Is the 14th free?', 'created_at' => '2026-10-01 09:00:00'];
        $vendorMsg = (object) ['author_role' => 'vendor', 'author_name' => 'Nyama Choma Masters', 'body' => 'Yes it is.', 'created_at' => '2026-10-01 10:00:00'];

        // Nothing said yet: the couple is waiting, the vendor owes the reply,
        // and the planner owes the introduction.
        $silent = VendorThread::stage($deal, [], VendorThread::COUPLE);
        $this->assertSame('waiting', $silent['key']);
        $this->assertSame(VendorThread::VENDOR, $silent['owner']);
        $this->assertStringContainsString('waiting on the vendor', strtolower($silent['label']));

        $vendorSilent = VendorThread::stage($deal, [], VendorThread::VENDOR);
        $this->assertSame('reply', $vendorSilent['key'], 'a new enquiry is the vendor\'s move');
        $this->assertSame('action', $vendorSilent['tone']);

        $plannerSilent = VendorThread::stage($deal, [], VendorThread::PLANNER);
        $this->assertSame('reply', $plannerSilent['key']);
        $this->assertStringContainsString('Introduce yourself', $plannerSilent['label']);

        // The couple wrote last: the vendor is on the clock, the couple is not.
        $this->assertSame('waiting', VendorThread::stage($deal, [$coupleMsg], VendorThread::COUPLE)['key']);
        $this->assertSame('reply', VendorThread::stage($deal, [$coupleMsg], VendorThread::VENDOR)['key']);
        $this->assertSame('reply', VendorThread::stage($deal, [$coupleMsg], VendorThread::PLANNER)['key'], 'the planner follows the couple up');

        // The vendor replied: now it is the couple's turn, and the planner relays.
        $coupleTurn = VendorThread::stage($deal, [$coupleMsg, $vendorMsg], VendorThread::COUPLE);
        $this->assertSame('reply', $coupleTurn['key']);
        $this->assertSame(VendorThread::COUPLE, $coupleTurn['owner']);
        $this->assertSame('Your turn', $coupleTurn['label']);
        $this->assertSame('waiting', VendorThread::stage($deal, [$coupleMsg, $vendorMsg], VendorThread::VENDOR)['key']);
        $this->assertSame('reply', VendorThread::stage($deal, [$coupleMsg, $vendorMsg], VendorThread::PLANNER)['key']);

        // A confirmed deal is settled for everybody, whoever spoke last.
        $booked = clone $deal;
        $booked->status = 'confirmed';
        $booked->amount = 45000;
        $this->assertSame('Booked', VendorThread::stage($booked, [$coupleMsg, $vendorMsg], VendorThread::COUPLE)['label']);
        $this->assertSame('Booked', VendorThread::stage($booked, [], VendorThread::VENDOR)['label']);
        $this->assertSame('Booked', VendorThread::stage($booked, [], VendorThread::PLANNER)['label']);

        $closed = clone $deal;
        $closed->status = 'cancelled';
        $this->assertSame('Closed', VendorThread::stage($closed, [], VendorThread::COUPLE)['label']);

        $done = clone $deal;
        $done->status = 'completed';
        $this->assertSame('wrapped', VendorThread::stage($done, [], VendorThread::PLANNER)['key']);
    }

    public function testQuickRepliesSpeakEachSidesLanguage(): void
    {
        require_once __DIR__ . '/../admin/include/VendorThread.php';

        $couple = VendorThread::quick_replies(VendorThread::COUPLE);
        $vendor = VendorThread::quick_replies(VendorThread::VENDOR);
        $this->assertNotEmpty($couple);
        $this->assertNotEmpty($vendor);
        $this->assertContains('Is our date still available?', $couple, 'the question every couple asks first');
        $this->assertNotEmpty(array_filter($vendor, static fn(string $r): bool => stripos($r, 'free') !== false), 'a vendor must be able to answer availability in one tap');
        $this->assertSame([], VendorThread::quick_replies('nobody'));
    }

    public function testTheQueueServesTheLongestWaitingConversationFirst(): void
    {
        require_once __DIR__ . '/../admin/include/VendorThread.php';

        $deal = static fn(int $id): object => (object) ['id' => $id];
        $msg  = static fn(string $role, string $createdAt, int $id, array $read = []): object => (object) [
            'id'              => $id,
            'author_role'     => $role,
            'created_at'      => $createdAt,
            'read_couple_at'  => $read['couple'] ?? null,
            'read_vendor_at'  => $read['vendor'] ?? null,
            'read_planner_at' => $read['planner'] ?? null,
        ];
        $ids = static fn(array $deals): array => array_map(static fn($d): int => (int) $d->id, $deals);

        // Deal 2 has waited longest, deal 1 is newer, deal 3 holds only the
        // vendor's own words — which are never waiting on that vendor.
        $threads = [
            1 => [$msg('couple', '2026-10-02 09:00:00', 10)],
            2 => [$msg('couple', '2026-10-01 09:00:00', 4)],
            3 => [$msg('vendor', '2026-09-30 08:00:00', 1)],
        ];
        $rows = [$deal(1), $deal(2), $deal(3)];
        $this->assertSame(
            [2, 1, 3],
            $ids(VendorThread::waiting_first($rows, VendorThread::waiting_since([1, 2, 3], $threads, VendorThread::VENDOR))),
            'oldest waiting first, nothing waiting last, own words do not count'
        );
        // The label on a row reads the same key the order sorts on.
        $this->assertSame('2026-10-01 09:00:00', VendorThread::waiting_since([1, 2, 3], $threads, VendorThread::VENDOR)[2][0]);

        // Read state is per role: the planner has read deal 1 but never saw the
        // vendor's note on deal 3, so the planner's queue is ordered differently.
        $threads[1] = [$msg('couple', '2026-10-02 09:00:00', 10, ['planner' => '2026-10-03 09:00:00'])];
        $threads[3] = [$msg('vendor', '2026-10-05 09:00:00', 11)];
        $this->assertSame(
            [2, 3, 1],
            $ids(VendorThread::waiting_first($rows, VendorThread::waiting_since([1, 2, 3], $threads, VendorThread::PLANNER)))
        );

        // Messages written in the same second are ordered by id — the one that
        // was written first has waited longest.
        $tie = [
            1 => [$msg('couple', '2026-10-04 09:00:00', 21)],
            2 => [$msg('couple', '2026-10-04 09:00:00', 20)],
        ];
        $this->assertSame(
            [2, 1],
            $ids(VendorThread::waiting_first([$deal(1), $deal(2)], VendorThread::waiting_since([1, 2], $tie, VendorThread::VENDOR)))
        );

        // A silent ledger (and a role with no read column) keeps the rows it
        // was given rather than inventing an order.
        $this->assertSame([2, 1], $ids(VendorThread::waiting_first([$deal(2), $deal(1)], [])));
        $this->assertSame([], VendorThread::waiting_since([1, 2], $threads, 'nobody'));
    }

    public function testWaitingForSaysHowLongAPersonHasWaited(): void
    {
        require_once __DIR__ . '/../admin/include/VendorThread.php';

        $since = '2026-10-24 09:00:00';
        // Relative seconds, not calendar dates: the durations must not shift
        // with a daylight-saving hour in the server's timezone.
        $after = static fn(int $seconds): int => (int) strtotime($since) + $seconds;

        $this->assertSame('less than a minute', VendorThread::waiting_for($since, $after(30)));
        $this->assertSame('8 min', VendorThread::waiting_for($since, $after(8 * 60)));
        $this->assertSame('59 min', VendorThread::waiting_for($since, $after(59 * 60 + 59)));
        $this->assertSame('3 h', VendorThread::waiting_for($since, $after(3 * 3600)));
        $this->assertSame('23 h', VendorThread::waiting_for($since, $after(23 * 3600)));
        $this->assertSame('1 day', VendorThread::waiting_for($since, $after(24 * 3600)));
        $this->assertSame('3 days', VendorThread::waiting_for($since, $after(3 * 86400)));
        $this->assertSame('3 wk', VendorThread::waiting_for($since, $after(21 * 86400)));

        // A clock skewed ahead of the row never renders a negative wait, and a
        // value the database cannot parse is simply no label.
        $this->assertSame('less than a minute', VendorThread::waiting_for($since, $after(-3600)));
        $this->assertSame('', VendorThread::waiting_for('not a date', $after(0)));
    }

    // =====================================================================
    // Writes, attribution, bells, read state
    // =====================================================================

    #[Group('integration')]
    public function testPostStoresTheAuthorsWordsAndBellsTheOtherTwo(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);

        $id = VendorThread::post(
            self::DEAL_PENDING,
            VendorThread::COUPLE,
            200,
            "  Are you free on the 14th?\r\nWe are 180 guests.  "
        );
        $this->assertGreaterThan(0, $id, 'a couple can open the conversation');

        $row = self::$db->prepare('SELECT * FROM tblvendor_messages WHERE id = :id');
        $row->execute([':id' => $id]);
        $row = $row->fetch(PDO::FETCH_OBJ);

        $this->assertSame('couple', $row->author_role);
        $this->assertSame(200, (int) $row->author_id);
        $this->assertSame('Linda Wafula & Brian Wafula', $row->author_name);
        $this->assertStringNotContainsString("\r", $row->body);
        $this->assertSame('Are you free on the 14th?' . "\n" . 'We are 180 guests.', $row->body);

        // The author has read their own message; the other two have not.
        $this->assertNotNull($row->read_couple_at);
        $this->assertNull($row->read_vendor_at);
        $this->assertNull($row->read_planner_at);
        $this->assertSame(0, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::COUPLE));
        $this->assertSame(1, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::VENDOR));
        $this->assertSame(1, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::PLANNER));

        // Both bells landed, in-app, pointing at the right ledger.
        $vendorUser = (int) self::$db->fetchValue(
            'SELECT user_id FROM tblvendors WHERE id = (SELECT vendor_id FROM tblvendor_bookings WHERE id = :d)',
            [':d' => self::DEAL_PENDING]
        );
        $this->assertGreaterThan(0, $vendorUser);

        $bells = self::$db->prepare(
            "SELECT user_id, recipient_type, link, message FROM tblnotifications
              WHERE id > :id AND booking_id = :b AND message LIKE '%Are you free on the 14th?%'"
        );
        $bells->execute([':id' => self::$notificationWatermark, ':b' => self::BOOKING]);
        $bells = $bells->fetchAll(PDO::FETCH_OBJ);
        $recipients = array_map(static fn($n) => (int) $n->user_id, $bells);

        $this->assertContains($vendorUser, $recipients, 'the vendor is told the couple wrote');
        $this->assertContains(7, $recipients, 'the planner is copied on every message');
        foreach ($bells as $bell) {
            $this->assertSame('user', $bell->recipient_type);
            $this->assertStringContainsString('vendor_thread_open.php?deal_id=' . self::DEAL_PENDING, $bell->link, 'one click opens the thread and clears the badge');
            $this->assertStringContainsString('Are you free on the 14th?', $bell->message);
        }

        /*
         * A reply never counts as unread for the person who wrote it, and it
         * never clears what they have not read either: the vendor has now
         * answered, but the couple's question is still unseen on their side.
         */
        $vendorReply = VendorThread::post(self::DEAL_PENDING, VendorThread::VENDOR, $vendorUser, 'Yes — 180 is fine.');
        $this->assertGreaterThan(0, $vendorReply);
        $this->assertSame(1, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::VENDOR), 'the vendor still has the couple\'s question unread');
        $this->assertSame(1, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::COUPLE), 'the couple has the vendor\'s reply unread');
        $this->assertSame(2, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::PLANNER));

        // Read state is per role: clearing one badge leaves the other two alone.
        $this->assertSame(1, VendorThread::mark_read(self::DEAL_PENDING, VendorThread::COUPLE));
        $this->assertSame(0, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::COUPLE));
        $this->assertSame(1, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::VENDOR));
        $this->assertSame(1, VendorThread::mark_read(self::DEAL_PENDING, VendorThread::VENDOR));
        $this->assertSame(0, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::VENDOR));
        $this->assertSame(2, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::PLANNER), 'nobody else\'s reading clears the planner\'s badge');

        // Bounded input: 2,500 characters arrive as exactly MAX_BODY.
        $long = VendorThread::post(self::DEAL_PENDING, VendorThread::PLANNER, 7, str_repeat('a', 2500));
        $stmt = self::$db->prepare('SELECT body FROM tblvendor_messages WHERE id = :id');
        $stmt->execute([':id' => $long]);
        $this->assertSame(VendorThread::MAX_BODY, mb_strlen((string) $stmt->fetchColumn()));

        // Nothing to say is nothing to store.
        $this->assertSame(0, VendorThread::post(self::DEAL_PENDING, VendorThread::COUPLE, 200, "   \n  "));
        $this->assertSame(0, VendorThread::post(999999, VendorThread::COUPLE, 200, 'ghost deal'));
    }

    #[Group('integration')]
    public function testTheOriginalEnquiryOpensAThreadThatPredatesIt(): void
    {
        require_once __DIR__ . '/../admin/include/VendorThread.php';

        $notes = "Could you cater for 180 guests on 14 March?\n"
            . "[12 Feb 2026 — confirmed by staff; deal KSh 45,000.]\n"
            . "[12 Feb 2026 — accepted while also booked: another wedding.]";
        $brief = VendorThread::original_brief($notes);
        $this->assertStringContainsString('180 guests', $brief);
        $this->assertStringNotContainsString('[', $brief, 'ledger lines are the audit trail, not something a person said');
        $this->assertSame('', VendorThread::original_brief('[only system lines]'));
    }

    // =====================================================================
    // The doors
    // =====================================================================

    #[Group('integration')]
    public function testTheDoorsGuardRoleOwnershipAndTokens(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);
        $api = '/admin/vendor_thread_post.php';

        // --- A couple ------------------------------------------------------
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);

        $get = self::http('GET', $api);
        $this->assertSame(302, $get['status'], 'GET must never write');
        $this->assertSame(0, self::messageCount(self::DEAL_PENDING));

        $page  = self::http('GET', '/admin/client_vendors.php?booking_id=200&user_id=200');
        $this->assertSame(200, $page['status']);
        $token = self::csrfFrom($page['body']);
        $this->assertNotSame('', $token);

        $tokenless = self::http('POST', $api, ['deal_id' => self::DEAL_PENDING, 'body' => 'No token']);
        $this->assertSame(302, $tokenless['status']);
        $this->assertSame(0, self::messageCount(self::DEAL_PENDING), 'a tokenless post writes nothing');

        $foreign = self::foreignDeal();
        try {
            $foreignPost = self::http('POST', $api, [
                '_token'  => $token,
                'deal_id' => $foreign,
                'body'    => 'Trying another couple\'s deal',
            ]);
            $this->assertSame(302, $foreignPost['status']);
            $this->assertSame(0, self::messageCount($foreign), 'a couple cannot write on somebody else\'s deal');
        } finally {
            self::dropDeal($foreign);
        }

        $sent = self::http('POST', $api, [
            '_token'  => $token,
            'deal_id' => self::DEAL_PENDING,
            'body'    => 'Is our date still available?',
        ]);
        $this->assertSame(302, $sent['status']);
        $this->assertStringContainsString('thread=' . self::DEAL_PENDING, $sent['location'], 'the reply lands back with its thread open');
        $this->assertStringContainsString('#deal-' . self::DEAL_PENDING, $sent['location']);
        $this->assertSame(1, self::messageCount(self::DEAL_PENDING));

        $row = self::latestMessage(self::DEAL_PENDING);
        $this->assertSame('couple', $row->author_role);
        $this->assertSame(200, (int) $row->author_id);
        $this->assertSame('Is our date still available?', $row->body);

        // --- A vendor ------------------------------------------------------
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
        $vendorHome = self::http('GET', '/admin/vendor_home.php');
        $this->assertSame(200, $vendorHome['status']);
        $vendorToken = self::csrfFrom($vendorHome['body']);

        $notMine = self::http('POST', $api, [
            '_token'  => $vendorToken,
            'deal_id' => self::DEAL_PENDING,
            'body'    => 'This is not my listing',
        ]);
        $this->assertSame(302, $notMine['status']);
        $this->assertSame(1, self::messageCount(self::DEAL_PENDING), 'a vendor cannot write on a listing they do not own');

        $mine = self::http('POST', $api, [
            '_token'  => $vendorToken,
            'deal_id' => self::DEAL_VENDOR,
            'body'    => 'Yes, the date is free. KSh 52,000 for 180 guests.',
        ]);
        $this->assertSame(302, $mine['status']);
        $vendorRow = self::latestMessage(self::DEAL_VENDOR);
        $this->assertSame('vendor', $vendorRow->author_role);
        $this->assertSame('Nyama Choma Masters — Nakuru', $vendorRow->author_name);

        // The opener is a door too: a foreign deal is refused and cleared nowhere.
        $refused = self::http('GET', '/admin/vendor_thread_open.php?deal_id=' . self::DEAL_PENDING);
        $this->assertSame(302, $refused['status']);
        $this->assertSame(1, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::VENDOR), 'a refused open must not clear the badge');

        $opened = self::http('GET', '/admin/vendor_thread_open.php?deal_id=' . self::DEAL_VENDOR);
        $this->assertSame(302, $opened['status']);
        $this->assertStringContainsString('thread=' . self::DEAL_VENDOR, $opened['location']);

        $vendorView = self::http('GET', '/admin/vendor_home.php?thread=' . self::DEAL_VENDOR);
        $this->assertSame(200, $vendorView['status']);
        $this->assertStringContainsString('vt-panel', $vendorView['body'], 'the opened thread renders its panel');
        $this->assertStringContainsString('Yes, the date is free.', $vendorView['body']);

        // --- A planner -----------------------------------------------------
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $ledger = self::http('GET', '/admin/vendor_enquiries.php?thread=' . self::DEAL_PENDING);
        $this->assertSame(200, $ledger['status']);
        $this->assertStringContainsString('vt-panel', $ledger['body'], 'the planner opens the same thread');
        $this->assertStringContainsString('Is our date still available?', $ledger['body']);

        $plannerToken = self::csrfFrom($ledger['body']);
        $plannerSent  = self::http('POST', $api, [
            '_token'  => $plannerToken,
            'deal_id' => self::DEAL_PENDING,
            'body'    => 'I have the couple on the line — can you hold it?',
        ]);
        $this->assertSame(302, $plannerSent['status']);
        $plannerRow = self::latestMessage(self::DEAL_PENDING);
        $this->assertSame('planner', $plannerRow->author_role);
        $this->assertSame(7, (int) $plannerRow->author_id);
        $this->assertSame('Amina Hassan', $plannerRow->author_name);
    }

    #[Group('integration')]
    public function testTheCouplesMarketplaceShowsTheNextStepAndTheComposer(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);

        // Before anybody replies the list says exactly that, and offers the door.
        $list = self::http('GET', '/admin/client_vendors.php?booking_id=200&user_id=200');
        $this->assertSame(200, $list['status']);
        $this->assertStringContainsString('My enquiries', $list['body']);
        $this->assertStringContainsString('vendor_thread_open.php?deal_id=' . self::DEAL_PENDING, $list['body']);
        $this->assertStringContainsString('waiting on the vendor', strtolower($list['body']));

        // The vendor answers: the couple's line flips to "your turn" and the
        // message is counted as new until they actually open it.
        $vendorUser = (int) self::$db->fetchValue(
            'SELECT user_id FROM tblvendors WHERE id = (SELECT vendor_id FROM tblvendor_bookings WHERE id = :d)',
            [':d' => self::DEAL_PENDING]
        );
        VendorThread::post(self::DEAL_PENDING, VendorThread::VENDOR, $vendorUser, 'Yes — the 14th is free.');

        $after = self::http('GET', '/admin/client_vendors.php?booking_id=200&user_id=200');
        $this->assertStringContainsString('Your turn', $after['body'], 'a vendor reply puts the ball in the couple\'s court');
        $this->assertStringContainsString('1 new', $after['body']);

        // Opening it (the bell's link, or the button) clears the badge and
        // renders the conversation with one-tap replies and a composer.
        $open = self::http('GET', '/admin/vendor_thread_open.php?deal_id=' . self::DEAL_PENDING);
        $this->assertSame(302, $open['status']);
        $opened = self::http('GET', '/admin/client_vendors.php?booking_id=200&user_id=200&thread=' . self::DEAL_PENDING);
        $this->assertSame(200, $opened['status']);
        $this->assertStringContainsString('vt-panel', $opened['body']);
        $this->assertStringContainsString('vt-quick', $opened['body'], 'one-tap replies are offered to the couple');
        $this->assertStringContainsString('Yes — the 14th is free.', $opened['body']);
        $this->assertStringContainsString('name="body"', $opened['body'], 'the composer posts a message');
        $this->assertStringContainsString('vendor_thread_post.php', $opened['body']);
        $this->assertStringNotContainsString('1 new', $opened['body'], 'reading the thread clears its badge');
        $this->assertSame(0, VendorThread::unread_count(self::DEAL_PENDING, VendorThread::COUPLE));

        // A thread id that is not this couple's is ignored (no panel, no peek).
        $foreign = self::foreignDeal();
        try {
            $peek = self::http('GET', '/admin/client_vendors.php?booking_id=200&user_id=200&thread=' . $foreign);
            $this->assertSame(200, $peek['status']);
            $this->assertStringNotContainsString('data-deal="' . $foreign . '"', $peek['body']);
        } finally {
            self::dropDeal($foreign);
        }
    }

    // =====================================================================
    // The sidebar badge
    // =====================================================================

    #[Group('integration')]
    public function testUnreadTotalCountsOnlyTheReadersOwnLedger(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);

        $vendorUser = (int) self::$db->fetchValue(
            'SELECT user_id FROM tblvendors WHERE id = (SELECT vendor_id FROM tblvendor_bookings WHERE id = :d)',
            [':d' => self::DEAL_PENDING]
        );

        $this->assertSame(0, VendorThread::unread_total(VendorThread::COUPLE, 200, self::BOOKING));

        VendorThread::post(self::DEAL_PENDING, VendorThread::VENDOR, $vendorUser, 'Yes — the 14th is free.');

        // The couple's badge counts the reply, on the wedding it links to.
        $this->assertSame(1, VendorThread::unread_total(VendorThread::COUPLE, 200, self::BOOKING));
        $this->assertSame(0, VendorThread::unread_total(VendorThread::COUPLE, 200, 201), 'a badge for one wedding never counts another');
        $this->assertSame(0, VendorThread::unread_total(VendorThread::COUPLE, 201), 'another couple\'s booking is not this couple\'s ledger');

        // A role never counts its own words as unread.
        $this->assertSame(0, VendorThread::unread_total(VendorThread::VENDOR, $vendorUser));

        // The planner\'s badge covers the deals on weddings they may open.
        $this->assertSame(1, VendorThread::unread_total(VendorThread::PLANNER, 7));

        // The badge opens that reply, not the ledger list.
        $this->assertSame(self::DEAL_PENDING, VendorThread::oldest_unread(VendorThread::COUPLE, 200, self::BOOKING));
        $this->assertSame(0, VendorThread::oldest_unread(VendorThread::COUPLE, 200, 201), 'a clear wedding has nothing to open');
        $this->assertSame(self::DEAL_PENDING, VendorThread::oldest_unread(VendorThread::PLANNER, 7));

        // A second reply on the other deal: the badge still opens the message
        // that has waited longest, not the newest one.
        VendorThread::post(self::DEAL_VENDOR, VendorThread::VENDOR, 8, 'Second reply, on the other deal.');
        $this->assertSame(2, VendorThread::unread_total(VendorThread::COUPLE, 200, self::BOOKING));
        $this->assertSame(self::DEAL_PENDING, VendorThread::oldest_unread(VendorThread::COUPLE, 200, self::BOOKING), 'a queue is served oldest first');

        // Reading the oldest drops the count and moves the badge target on.
        VendorThread::mark_read(self::DEAL_PENDING, VendorThread::COUPLE);
        $this->assertSame(1, VendorThread::unread_total(VendorThread::COUPLE, 200, self::BOOKING));
        $this->assertSame(self::DEAL_VENDOR, VendorThread::oldest_unread(VendorThread::COUPLE, 200, self::BOOKING));
        $this->assertSame(1, self::messageCount(self::DEAL_PENDING));

        // A clear ledger has no destination, so the entry falls back to the list.
        VendorThread::mark_read(self::DEAL_VENDOR, VendorThread::COUPLE);
        $this->assertSame(0, VendorThread::unread_total(VendorThread::COUPLE, 200, self::BOOKING));
        $this->assertSame(0, VendorThread::oldest_unread(VendorThread::COUPLE, 200, self::BOOKING));
    }

    #[Group('integration')]
    public function testTheSidebarBadgeOpensTheOldestWaitingThread(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);

        $vendorUser = (int) self::$db->fetchValue(
            'SELECT user_id FROM tblvendors WHERE id = (SELECT vendor_id FROM tblvendor_bookings WHERE id = :d)',
            [':d' => self::DEAL_PENDING]
        );
        VendorThread::post(self::DEAL_PENDING, VendorThread::COUPLE, 200, 'Are you free on the 14th?');

        // Planner: the badge is on Vendor Enquiries, the ledger it opens.
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $dash = self::http('GET', '/admin/dashboard.php');
        $this->assertSame(200, $dash['status']);
        $this->assertMatchesRegularExpression(
            '/href="vendor_thread_open\.php\?deal_id=' . self::DEAL_PENDING . '"[^>]*>[\s\S]{0,700}?Vendor Enquiries<\/span>\s*<span class="app-nav-badge[^"]*"[^>]*>1<\/span>/',
            $dash['body'],
            'the planner badge opens the waiting thread, not the ledger list'
        );
        $this->assertStringContainsString('click opens the oldest waiting message', $dash['body']);

        // Vendor: the badge is on My Bookings, and counts only their listings.
        VendorThread::post(self::DEAL_VENDOR, VendorThread::COUPLE, 200, 'Could we have a quick call?');
        self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
        $home = self::http('GET', '/admin/vendor_home.php');
        $this->assertSame(200, $home['status']);
        $this->assertMatchesRegularExpression(
            '/href="vendor_thread_open\.php\?deal_id=' . self::DEAL_VENDOR . '"[^>]*>[\s\S]{0,700}?My Bookings<\/span>\s*<span class="app-nav-badge[^"]*"[^>]*>1<\/span>/',
            $home['body'],
            'the vendor badge opens the waiting message on their own listing'
        );

        VendorThread::post(self::DEAL_PENDING, VendorThread::VENDOR, $vendorUser, 'Yes, it is free.');

        // Couple: a direct way in to their own wedding\'s enquiries, badged.
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
        $bookings = self::http('GET', '/admin/client.php');
        $this->assertSame(200, $bookings['status']);
        $this->assertMatchesRegularExpression(
            '/href="vendor_thread_open\.php\?deal_id=' . self::DEAL_PENDING . '"[^>]*>[\s\S]{0,700}?Vendor enquiries<\/span>\s*<span class="app-nav-badge[^"]*"[^>]*>1<\/span>/',
            $bookings['body'],
            'the couple is taken to the reply that is waiting for them'
        );

        // Reading it turns the same entry back into the list, scoped to their
        // own wedding instead of a guessed id.
        $opened = self::http('GET', '/admin/vendor_thread_open.php?deal_id=' . self::DEAL_PENDING);
        $this->assertSame(302, $opened['status']);
        $this->assertStringContainsString('thread=' . self::DEAL_PENDING, $opened['location']);

        $after = self::http('GET', '/admin/client.php');
        $this->assertSame(200, $after['status']);
        $this->assertMatchesRegularExpression(
            '/href="client_vendors\.php\?booking_id=200&amp;user_id=200"[^>]*>[\s\S]{0,400}?Vendor enquiries<\/span>/',
            $after['body'],
            'a clear ledger sends the entry back to the couple’s own marketplace'
        );
        $this->assertStringNotContainsString('title="unread messages from your vendors', $after['body'], 'no badge, no thread link');
    }

    #[Group('integration')]
    public function testTheLedgerUnreadFilterIsAQueueAPlannerCanWork(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);

        $vendorUser = (int) self::$db->fetchValue(
            'SELECT user_id FROM tblvendors WHERE id = (SELECT vendor_id FROM tblvendor_bookings WHERE id = :d)',
            [':d' => self::DEAL_PENDING]
        );

        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $ledger = '/admin/vendor_enquiries.php';

        // A clear queue says so, and the chip is there to be trusted.
        $clear = self::http('GET', $ledger . '?unread=1');
        $this->assertSame(200, $clear['status']);
        $this->assertStringContainsString('Unread (0)', $clear['body']);
        $this->assertStringContainsString('Nothing waiting', $clear['body']);

        // Two deals get messages from the other side; one is then read.
        VendorThread::post(self::DEAL_PENDING, VendorThread::COUPLE, 200, 'Are you free on the 14th?');
        VendorThread::post(self::DEAL_VENDOR, VendorThread::VENDOR, 8, 'We can hold it for 48 hours.');

        $full = self::http('GET', $ledger);
        $this->assertSame(200, $full['status']);
        $this->assertStringContainsString('Unread (2)', $full['body'], 'the chip counts waiting threads, not messages');

        // Longest waiting first: the confirmed deal (200) is behind the pending
        // one in the ordinary ledger, so only its message being two hours older
        // can lift it to the top of the queue.
        self::$db->execute(
            'UPDATE tblvendor_messages SET created_at = created_at - INTERVAL 2 HOUR WHERE deal_id = :d',
            [':d' => self::DEAL_VENDOR]
        );
        $ordered = self::http('GET', $ledger . '?unread=1');
        $this->assertSame(200, $ordered['status']);
        $waited = strpos($ordered['body'], 'id="deal-' . self::DEAL_VENDOR . '"');
        $newer  = strpos($ordered['body'], 'id="deal-' . self::DEAL_PENDING . '"');
        $this->assertNotFalse($waited, 'the waiting deal is in the queue');
        $this->assertNotFalse($newer, 'the newer deal is in the queue');
        $this->assertLessThan($newer, $waited, 'the conversation that has waited longest leads the queue');
        $this->assertStringContainsString('waiting 2 h', $ordered['body'], 'the row says how long they have been waiting');
        $this->assertStringContainsString('Waiting since', $ordered['body'], 'and the exact time is in the tooltip');

        // The plain ledger keeps its own order (pending first), which is what
        // makes the queue's order a decision rather than an accident.
        $plain = self::http('GET', $ledger);
        $this->assertSame(200, $plain['status']);
        $this->assertLessThan(
            strpos($plain['body'], 'id="deal-' . self::DEAL_VENDOR . '"'),
            strpos($plain['body'], 'id="deal-' . self::DEAL_PENDING . '"'),
            'without the queue filter the pending deal still leads'
        );

        VendorThread::mark_read(self::DEAL_VENDOR, VendorThread::PLANNER);

        $queue = self::http('GET', $ledger . '?unread=1');
        $this->assertSame(200, $queue['status']);
        $this->assertStringContainsString('Unread (1)', $queue['body']);
        $this->assertStringContainsString('id="deal-' . self::DEAL_PENDING . '"', $queue['body'], 'the waiting deal is in the queue');
        $this->assertStringNotContainsString('id="deal-' . self::DEAL_VENDOR . '"', $queue['body'], 'a read deal is not');

        // Acting on a row keeps the queue filter in the form it submits.
        $this->assertStringContainsString('action="vendor_enquiries.php?unread=1"', $queue['body'], 'the confirm form returns to the queue');

        /*
         * Opening a thread from the queue marks it read before redirecting, so
         * the list must still hold that row — otherwise the panel the opener
         * just asked for would vanish. It stays planner-scoped either way.
         */
        $openedRow = self::http('GET', $ledger . '?unread=1&thread=' . self::DEAL_PENDING);
        $this->assertSame(200, $openedRow['status']);
        $this->assertStringContainsString('id="deal-' . self::DEAL_PENDING . '"', $openedRow['body']);
        $this->assertStringContainsString('vt-panel', $openedRow['body']);

        /*
         * Status and the queue combine instead of replacing each other. The
         * list can legitimately be empty here once the queue is worked, so the
         * assertions below run before anything else is read.
         */
        $both = self::http('GET', $ledger . '?unread=1&status=pending');
        $this->assertSame(200, $both['status']);
        $this->assertStringContainsString('id="deal-' . self::DEAL_PENDING . '"', $both['body']);
        $this->assertStringNotContainsString('id="deal-' . self::DEAL_VENDOR . '"', $both['body']);
        $this->assertStringContainsString('status=pending&amp;unread=1', $both['body'], 'the status chip keeps the queue filter');

        // The active chip is a toggle back to the whole ledger, same status.
        $this->assertStringContainsString('href="vendor_enquiries.php?status=pending"', $both['body']);

        // The row's own "open" link carries the work view with it.
        $this->assertStringContainsString(
            'vendor_thread_open.php?deal_id=' . self::DEAL_PENDING . '&amp;status=pending&amp;unread=1',
            $both['body'],
            'opening a thread from the queue asks to come back to the queue'
        );

        /*
         * Opening a thread from the queue marks it read before redirecting, so
         * the list must still hold that row — otherwise the panel the opener
         * just asked for would vanish. It stays planner-scoped either way.
         */
        $openedRow = self::http('GET', $ledger . '?unread=1&thread=' . self::DEAL_PENDING);
        $this->assertSame(200, $openedRow['status']);
        $this->assertStringContainsString('id="deal-' . self::DEAL_PENDING . '"', $openedRow['body']);
        $this->assertStringContainsString('vt-panel', $openedRow['body']);

        // ...and the opener's redirect is the queue it came from.
        $openLink = self::http('GET', '/admin/vendor_thread_open.php?deal_id=' . self::DEAL_PENDING . '&status=pending&unread=1');
        $this->assertSame(302, $openLink['status']);
        $this->assertStringContainsString('thread=' . self::DEAL_PENDING, $openLink['location']);
        $this->assertStringContainsString('status=pending', $openLink['location']);
        $this->assertStringContainsString('unread=1', $openLink['location']);

        // With both threads read the queue is empty and says so.
        $empty = self::http('GET', $ledger . '?unread=1');
        $this->assertSame(200, $empty['status']);
        $this->assertStringContainsString('Unread (0)', $empty['body']);
        $this->assertStringContainsString('Nothing waiting', $empty['body']);
    }

    #[Group('integration')]
    public function testTheVendorsOwnLedgerHasTheSameUnreadQueue(): void
    {
        $this->requireLive();
        self::resetThread(self::DEAL_PENDING, self::DEAL_VENDOR);

        // A second deal on the vendor's listing, so the queue has something to
        // narrow to and the vendor_id stays meaningful.
        $probe = self::foreignDeal();
        try {
            self::login(self::VENDOR_EMAIL, self::VENDOR_PASS);
            $ledger = '/admin/vendor_home.php';

            $clear = self::http('GET', $ledger . '?unread=1');
            $this->assertSame(200, $clear['status']);
            $this->assertStringContainsString('Unread (0)', $clear['body']);
            $this->assertStringContainsString('Nothing waiting', $clear['body']);

            VendorThread::post(self::DEAL_VENDOR, VendorThread::COUPLE, 200, 'Can we confirm timings?');
            VendorThread::post($probe, VendorThread::COUPLE, 201, 'Are you available for the ruracio?');

            $full = self::http('GET', $ledger);
            $this->assertSame(200, $full['status']);
            $this->assertStringContainsString('Unread (2)', $full['body'], 'the chip counts waiting threads');

            // The probe deal is the newer booking, so only its message being
            // older can put the vendor's own deal on top of the queue.
            self::$db->execute(
                'UPDATE tblvendor_messages SET created_at = created_at - INTERVAL 2 HOUR WHERE deal_id = :d',
                [':d' => self::DEAL_VENDOR]
            );
            $ordered = self::http('GET', $ledger . '?unread=1');
            $this->assertSame(200, $ordered['status']);
            $waited = strpos($ordered['body'], 'id="deal-' . self::DEAL_VENDOR . '"');
            $newer  = strpos($ordered['body'], 'id="deal-' . $probe . '"');
            $this->assertNotFalse($waited, 'the waiting deal is in the queue');
            $this->assertNotFalse($newer, 'the newer deal is in the queue');
            $this->assertLessThan($newer, $waited, 'the conversation that has waited longest leads the queue');
            $this->assertStringContainsString('waiting 2 h', $ordered['body'], 'the row says how long they have been waiting');
            $this->assertStringContainsString('Waiting since', $ordered['body'], 'and the exact time is in the tooltip');

            // The plain ledger still leads with the pending deal, so the queue
            // is ordering by waiting time and not merely echoing it.
            $plain = self::http('GET', $ledger);
            $this->assertSame(200, $plain['status']);
            $this->assertLessThan(
                strpos($plain['body'], 'id="deal-' . self::DEAL_VENDOR . '"'),
                strpos($plain['body'], 'id="deal-' . $probe . '"'),
                'without the queue filter the pending deal still leads'
            );

            VendorThread::mark_read(self::DEAL_VENDOR, VendorThread::VENDOR);

            $queue = self::http('GET', $ledger . '?unread=1');
            $this->assertSame(200, $queue['status']);
            $this->assertStringContainsString('Unread (1)', $queue['body']);
            $this->assertStringContainsString('id="deal-' . $probe . '"', $queue['body'], 'the waiting booking is in the queue');
            $this->assertStringNotContainsString('id="deal-' . self::DEAL_VENDOR . '"', $queue['body'], 'the read booking is not');
            $this->assertStringContainsString('vendor_id=200', $queue['body'], 'the listing being read is kept');
            $this->assertStringContainsString(
                'vendor_thread_open.php?deal_id=' . $probe . '&amp;unread=1',
                $queue['body'],
                'opening from the queue asks to come back to it'
            );
            // Accepting or declining from the queue returns to it, not to the
            // whole ledger.
            $this->assertStringContainsString(
                'action="vendor_home.php?unread=1&amp;vendor_id=200"',
                $queue['body'],
                'the status form carries the queue it was submitted from'
            );

            // The opener returns to the queue, and the row it just read stays
            // visible so its panel is not pulled out from under the vendor.
            $openLink = self::http('GET', '/admin/vendor_thread_open.php?deal_id=' . $probe . '&unread=1');
            $this->assertSame(302, $openLink['status']);
            $this->assertStringContainsString('thread=' . $probe, $openLink['location']);
            $this->assertStringContainsString('unread=1', $openLink['location']);
            $this->assertStringContainsString('vendor_id=200', $openLink['location']);

            $landed = self::http('GET', '/admin/vendor_home.php?vendor_id=200&unread=1&thread=' . $probe);
            $this->assertSame(200, $landed['status']);
            $this->assertStringContainsString('data-deal="' . $probe . '"', $landed['body']);
            $this->assertStringContainsString('id="deal-' . $probe . '"', $landed['body']);
            $this->assertStringContainsString('Faith Njeri', $landed['body']);
            $this->assertStringContainsString('Unread (0)', $landed['body']);

            // A clear queue says so.
            $empty = self::http('GET', $ledger . '?unread=1');
            $this->assertSame(200, $empty['status']);
            $this->assertStringContainsString('Nothing waiting', $empty['body']);
        } finally {
            self::dropDeal($probe);
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function requireLive(): void
    {
        if (self::$db === null || self::$base === null) {
            $this->markTestSkipped('live DB + php -S unavailable');
        }
    }

    /**
     * A probe deal on another couple's wedding (booking 201, Faith & James).
     * The seeded database only carries deals for booking 200, so the
     * "somebody else's deal" case needs one; it is removed by dropDeal().
     */
    private static function foreignDeal(): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, amount, commission, status, notes)
             VALUES (200, 201, 0, 0, 'pending', 'probe: thread ownership')"
        );
        return (int) self::$db->the_insert_id();
    }

    private static function dropDeal(int $dealId): void
    {
        if ($dealId > 0) {
            self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
        }
    }

    /** Start a test from an empty thread on the seeded deals. */
    private static function resetThread(int ...$dealIds): void
    {
        if (self::$db === null || $dealIds === []) {
            return;
        }
        $in = implode(',', array_map('intval', $dealIds));
        self::$db->execute("DELETE FROM tblvendor_messages WHERE deal_id IN ($in)");
    }

    private static function messageCount(int $dealId): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_messages WHERE deal_id = :d',
            [':d' => $dealId]
        );
    }

    private static function latestMessage(int $dealId): object
    {
        $stmt = self::$db->prepare('SELECT * FROM tblvendor_messages WHERE deal_id = :d ORDER BY id DESC LIMIT 1');
        $stmt->execute([':d' => $dealId]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row === false) {
            self::fail('no message stored on deal ' . $dealId);
        }
        return $row;
    }

    private static function csrfFrom(string $html): string
    {
        return preg_match('/name="_token" value="([^"]+)"/', $html, $m)
            ? html_entity_decode($m[1], ENT_QUOTES)
            : '';
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vt_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vt_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 41000 + random_int(0, 8999);
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
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    self::$base = 'http://127.0.0.1:' . $port;
                    return;
                }
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
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
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

        foreach ($headers as $h) {
            if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/PHPSESSID=([^;]+)/', $h, $cm)) {
                self::$jar['PHPSESSID'] = $cm[1];
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }
}
