<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The escalation sweep (v2.26): scripts/thread_escalation.php.
 *
 * v2.25 made an unanswered conversation VISIBLE on the ledgers. This job is the
 * one that TELLS somebody — the couple's planner — when a vendor has left the
 * newest message unanswered for a day. The rules under test are the ones that
 * decide whether a real planner is interrupted:
 *
 *   - the trigger is the newest message, so reading without replying still
 *     counts as unanswered;
 *   - one alert per unanswered message (the row is claimed before it rings), so
 *     a re-run — or a second pinger — is silent;
 *   - a vendor reply, a settled deal and a young conversation are left alone;
 *   - --dry-run reports without stamping anything.
 *
 * Every fixture is a probe deal on booking 200 (Faith/James and the seeded
 * couple's own wedding are untouched) and is removed in tearDownAfterClass,
 * together with the alerts this class caused.
 */
final class ThreadEscalationTest extends TestCase
{
    private const BOOKING      = 200;
    private const VENDOR_ID    = 200;
    private const VENDOR_USER  = 8;
    private const PLANNER      = 7;
    private const COUPLE_USER  = 200;
    private const SCRIPT       = __DIR__ . '/../scripts/thread_escalation.php';

    private static ?Database $db = null;
    private static int $notificationWatermark = 0;
    /** @var int[] probe deals this class created */
    private static array $probes = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';
        require_once __DIR__ . '/../admin/include/db_object.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
        require_once __DIR__ . '/../admin/include/VendorThread.php';
        require_once __DIR__ . '/../admin/include/Notification.php';

        try {
            global $db;
            $db = new Database();
            self::$db = $db;
            if (!VendorThread::installed() || !VendorThread::escalation_installed()) {
                self::$db = null; // migrations absent — every test skips
                return;
            }
            self::$notificationWatermark = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications');
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            foreach (self::$probes as $dealId) {
                // Messages go with the deal (ON DELETE CASCADE).
                self::$db->execute('DELETE FROM tblvendor_bookings WHERE id = :id', [':id' => $dealId]);
            }
            self::$db->execute(
                'DELETE FROM tblnotifications WHERE id > :id AND booking_id = :b',
                [':id' => self::$notificationWatermark, ':b' => self::BOOKING]
            );
        } catch (Throwable $e) {
            // the scratch database is rebuilt by composer test:db
        }
    }

    // =====================================================================
    // The rules
    // =====================================================================

    public function testTheMessageSaysWhoWaitedHowLongAndOnWhom(): void
    {
        require_once __DIR__ . '/../admin/include/VendorThread.php';

        // November dates: a fixed 48 h must not be checked across a daylight-
        // saving change in whatever timezone the test machine runs in.
        $row = (object) [
            'bride'         => 'Linda Wafula',
            'groom'         => 'Brian Wafula',
            'business_name' => 'Nyama Choma Masters — Nakuru',
            'waiting_since' => '2026-11-22 09:00:00',
        ];
        $this->assertSame(
            'Linda Wafula & Brian Wafula have been waiting 2 days for Nyama Choma Masters — Nakuru'
                . ' to reply. Open the thread and give them a nudge.',
            VendorThread::escalation_message($row, (int) strtotime('2026-11-24 09:00:00'))
        );

        // Missing names never produce "  &  have been waiting".
        $bare = (object) ['bride' => '', 'groom' => '', 'business_name' => '', 'waiting_since' => ''];
        $this->assertStringStartsWith('A couple have been waiting over a day for their vendor to reply', VendorThread::escalation_message($bare));
    }

    #[Group('integration')]
    public function testThePlannerIsToldOnceWhenTheVendorGoesQuiet(): void
    {
        $this->requireLive();
        $deal = $this->probeDeal();
        $this->coupleWrote($deal, 'Is the venue still free for the ruracio?');

        $this->assertContains($deal, $this->staleIds(24), 'a two-day-old question is a stale conversation');

        $out = $this->runScript(''); // not --quiet: the operator's copy is under test
        $this->assertMatchesRegularExpression('/alerted \d+ planner/', $out, 'the run reports who it told');

        $alert = $this->alertFor($deal);
        $this->assertNotNull($alert, 'the planner got the bell');
        $this->assertSame(self::PLANNER, (int) $alert->user_id);
        $this->assertSame('user', $alert->recipient_type, 'a planner is a staff account, not a couple');
        $this->assertSame(self::BOOKING, (int) $alert->booking_id);
        $this->assertSame('vendor_thread_open.php?deal_id=' . $deal, $alert->link, 'the bell opens the thread');
        $this->assertStringContainsString('Linda Wafula & Brian Wafula', $alert->message);
        $this->assertStringContainsString('Nyama Choma Masters', $alert->message);
        // The script sets Africa/Nairobi before it reasons about time (the
        // database session is +03:00 too), so two days is two days whatever
        // timezone the test process itself inherits.
        $this->assertStringContainsString('have been waiting 2 days', $alert->message);
        $this->assertMatchesRegularExpression('/to reply\. Open the thread/', $alert->message);
        $this->assertSame(0, (int) $alert->is_read, 'it is news');

        // The job leaves the heartbeat the operator's watchdog judges: what it
        // alerted about, against the queue its own window held.
        $beat = self::$db->fetchOne(
            'SELECT * FROM tbljob_heartbeats WHERE job = :j',
            [':j' => 'thread_escalation']
        );
        $this->assertNotNull($beat, 'the sweep must leave a heartbeat');
        $this->assertSame('ok', (string) $beat->last_status);
        $this->assertGreaterThanOrEqual(1, (int) $beat->last_items, 'it reported at least this alert');
        $this->assertGreaterThanOrEqual(1, (int) $beat->last_queue, 'and the queue it found');

        // The message is stamped, so the same silence is never reported twice.
        $this->assertNotNull($this->escalatedAt($deal), 'the message was claimed');
        $this->assertNotContains($deal, $this->staleIds(24), 'a claimed message leaves the queue');

        $this->runScript();
        $this->assertSame(1, $this->alertCount($deal), 'a second run is silent');

        // And the claim itself refuses a second caller.
        $messageId = (int) self::$db->fetchValue(
            'SELECT MAX(id) FROM tblvendor_messages WHERE deal_id = :d',
            [':d' => $deal]
        );
        $this->assertFalse(VendorThread::mark_escalated($messageId), 'a claimed message cannot be claimed twice');
    }

    #[Group('integration')]
    public function testReadingWithoutReplyingStillCountsAsSilence(): void
    {
        $this->requireLive();
        $deal = $this->probeDeal();
        $this->coupleWrote($deal, 'Can you do 150 guests?');
        // The vendor opened the thread and said nothing: read state is not an answer.
        self::$db->execute(
            'UPDATE tblvendor_messages SET read_vendor_at = NOW() WHERE deal_id = :d',
            [':d' => $deal]
        );

        $this->assertContains($deal, $this->staleIds(24));
        $this->runScript();
        $this->assertSame(1, $this->alertCount($deal), 'the planner is told even though the vendor looked');
    }

    #[Group('integration')]
    public function testAVendorsReplyEndsTheSilence(): void
    {
        $this->requireLive();
        $deal = $this->probeDeal();
        $this->coupleWrote($deal, 'Are you available on the 14th?');
        self::$db->execute(
            'UPDATE tblvendor_messages SET created_at = NOW() - INTERVAL 2 DAY WHERE deal_id = :d',
            [':d' => $deal]
        );

        VendorThread::post($deal, VendorThread::VENDOR, self::VENDOR_USER, 'Yes — we can hold it for 48 hours.');
        $this->assertNotContains($deal, $this->staleIds(24), 'the newest message is the vendor\'s own');

        $this->runScript();
        $this->assertSame(0, $this->alertCount($deal), 'nobody is chased once the vendor has answered');
    }

    #[Group('integration')]
    public function testAYoungConversationIsNotEscalated(): void
    {
        $this->requireLive();
        $deal = $this->probeDeal();
        VendorThread::post($deal, VendorThread::COUPLE, self::COUPLE_USER, 'Hello — just checking in.');

        $this->assertNotContains($deal, $this->staleIds(24), 'a fresh message has not waited a day');
        $this->runScript();
        $this->assertSame(0, $this->alertCount($deal));
        $this->assertNull($this->escalatedAt($deal), 'nothing was stamped');
    }

    #[Group('integration')]
    public function testSettledDealsAreLeftAlone(): void
    {
        $this->requireLive();
        $settled = [];
        foreach (['completed', 'cancelled'] as $status) {
            $deal = $this->probeDeal($status);
            $this->coupleWrote($deal, 'Any update on the refund?');
            $this->assertNotContains($deal, $this->staleIds(24), "a {$status} deal needs no reply");
            $settled[] = $deal;
        }

        $this->runScript();
        foreach ($settled as $deal) {
            $this->assertSame(0, $this->alertCount($deal), 'a settled deal never rings the planner');
        }
    }

    #[Group('integration')]
    public function testDryRunReportsWithoutAlerting(): void
    {
        $this->requireLive();
        $deal = $this->probeDeal();
        $this->coupleWrote($deal, 'Is the package price still valid?');

        $out = $this->runScript('--dry-run');
        $this->assertStringContainsString('[dry run] deal #' . $deal, $out);
        $this->assertStringContainsString('give them a nudge', $out);

        $this->assertSame(0, $this->alertCount($deal), 'a dry run rings nobody');
        $this->assertNull($this->escalatedAt($deal), 'and stamps nothing');
        $this->assertContains($deal, $this->staleIds(24), 'the queue is unchanged');
    }

    #[Group('integration')]
    public function testTheGraceIsTheScriptsOwnArgument(): void
    {
        $this->requireLive();
        $deal = $this->probeDeal();
        $this->coupleWrote($deal, 'One more question about the menu.');

        // The message is ~2 days old: past 24 h, not past 72 h.
        self::$db->execute(
            'UPDATE tblvendor_messages SET created_at = NOW() - INTERVAL 48 HOUR WHERE deal_id = :d',
            [':d' => $deal]
        );
        $this->assertContains($deal, $this->staleIds(24));
        $this->assertNotContains($deal, $this->staleIds(72), 'a longer grace holds it back');

        $out = $this->runScript('--hours=72');
        $this->assertSame(0, $this->alertCount($deal), 'nothing is reported inside the longer grace');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function requireLive(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('live DB with v2.25/v2.26 unavailable');
        }
    }

    /** A deal on the seeded wedding that no other test class knows about. */
    private function probeDeal(string $status = 'pending'): int
    {
        self::$db->execute(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, amount, commission, status, notes)
             VALUES (:v, :b, 0, 0, :st, 'probe: thread escalation')",
            [':v' => self::VENDOR_ID, ':b' => self::BOOKING, ':st' => $status]
        );
        $dealId = (int) self::$db->the_insert_id();
        self::$probes[] = $dealId;
        return $dealId;
    }

    /** The couple asks something, and the question is two days old. */
    private function coupleWrote(int $dealId, string $body): int
    {
        $id = VendorThread::post($dealId, VendorThread::COUPLE, self::COUPLE_USER, $body);
        self::$db->execute(
            'UPDATE tblvendor_messages SET created_at = NOW() - INTERVAL 2 DAY WHERE id = :id',
            [':id' => $id]
        );
        return $id;
    }

    /** @return int[] the deals stale_deals() would report at this grace */
    private function staleIds(int $hours): array
    {
        return array_map(static fn($row): int => (int) $row->deal_id, VendorThread::stale_deals($hours));
    }

    /**
     * The escalation bell, told apart from the "new message" bells by its own
     * sentence — a message bell links to the SAME opener, which is the point
     * (one click clears the badge and opens the thread).
     */
    private const ESCALATION_MARK = '% to reply. Open the thread and give them a nudge.%';

    private function alertFor(int $dealId): ?object
    {
        return self::$db->fetchOne(
            'SELECT * FROM tblnotifications
              WHERE booking_id = :b AND link = :link AND message LIKE :mark
              ORDER BY id DESC LIMIT 1',
            [
                ':b'    => self::BOOKING,
                ':link' => 'vendor_thread_open.php?deal_id=' . $dealId,
                ':mark' => self::ESCALATION_MARK,
            ]
        );
    }

    private function alertCount(int $dealId): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications
              WHERE booking_id = :b AND link = :link AND message LIKE :mark',
            [
                ':b'    => self::BOOKING,
                ':link' => 'vendor_thread_open.php?deal_id=' . $dealId,
                ':mark' => self::ESCALATION_MARK,
            ]
        );
    }

    private function escalatedAt(int $dealId): ?string
    {
        $value = self::$db->fetchValue(
            'SELECT MAX(escalated_at) FROM tblvendor_messages WHERE deal_id = :d',
            [':d' => $dealId]
        );
        return $value === null || $value === false ? null : (string) $value;
    }

    private function runScript(string $args = '--quiet'): string
    {
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' ' . $args . ' 2>&1';
        return (string) shell_exec($cmd);
    }
}
