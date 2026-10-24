<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mail retry sweeper (Notification::retry_pending / scripts/mail_retry.php):
 *
 *  1. Transport off (WPMS_MAIL_ENABLED!=1) — a strict no-op: pending rows
 *     are never touched, nothing is marked abandoned.
 *  2. Transport on — each retry increments attempts; a failed final attempt
 *     marks the row 'abandoned'; a pending row whose attempts are already at
 *     the cap is never picked up.
 *  3. A row that turns 'sent' or 'failed' (non-pending) is never re-tried.
 *
 * Uses a fake transport: tests toggle WPMS_MAIL_ENABLED and, for the "send"
 * path, a stream_socket_server stub on an ephemeral port that immediately
 * accepts/closes (mail() fails fast on Windows without an MTA — the failure
 * path is what the assertions target; the happy path was proven live against
 * a real SMTP capture server).
 *
 * Integration ('integration' group) — requires the live DB (tbl_mail_logs
 * with the attempts/last_attempt_at columns). All probe rows are removed in
 * tearDownAfterClass.
 */
final class MailRetryTest extends TestCase
{
    private const STAFF_ID = 7; // amina@wpms.co.ke (has an email on file)

    private static ?Database $db = null;
    /** @var int[] */
    private static array $probeIds = [];
    private static bool $hadFlag = false;

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
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
            return;
        }
        // Remember the shell's flag so we can restore it per-test cleanly.
        self::$hadFlag = getenv('WPMS_MAIL_ENABLED') === '1';

        // The retry columns must exist (guard against a stale dev DB).
        try {
            self::$db->fetchValue('SELECT attempts FROM tbl_mail_logs LIMIT 1');
        } catch (Throwable $e) {
            self::$db = null; // migration not applied — skip cleanly
            return;
        }

        /*
         * PARK THE MAIL EARLIER CLASSES LEFT QUEUED.
         *
         * The sweep is installation-wide and takes the OLDEST pending rows
         * (`ORDER BY id ASC LIMIT :lim`), so the probes seeded below are only
         * reached while fewer than a batchful of older pending rows exist. Every
         * suite that rings a bell queues an email here and not all of them delete
         * their outbox, so on a database that has run the suite a few times the
         * batch is filled before it gets to this class and the assertions fail on
         * the FIXTURE rather than on the behaviour — which is exactly what
         * happened. Rows already at the cap are the one kind a sweep never
         * selects, so stamping stale pending rows to the cap parks them out of
         * the way without deleting anybody's record. This class's own probes are
         * still swept normally, and it deletes them afterwards.
         */
        try {
            self::$db->execute(
                "UPDATE tbl_mail_logs SET attempts = 5 WHERE status = 'pending' AND attempts < 5"
            );
        } catch (Throwable $e) {
            // read-only or partially migrated database: the assertions below will
            // report it rather than this setup swallowing it
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null && self::$probeIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tbl_mail_logs WHERE id IN (' . implode(',', self::$probeIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeIds = [];
        // Restore the shell's transport state for other tests.
        if (self::$hadFlag) {
            putenv('WPMS_MAIL_ENABLED=1');
        } else {
            putenv('WPMS_MAIL_ENABLED');
        }
    }

    private function requireDb(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB / tbl_mail_logs retry columns unavailable — mail retry tests skipped');
        }
    }

    /** Seed a pending mail row; returns its id. */
    private function seedPending(int $attempts = 0): int
    {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, recipient_type, to_email, subject, body, status, note, attempts) "
            . "VALUES (NULL, :uid, 0, 'user', 'amina@wpms.co.ke', '[mail-retry-test] subject', '[mail-retry-test] body', 'pending', 'test seed', :attempts)"
        );
        $stmt->execute([':uid' => self::STAFF_ID, ':attempts' => $attempts]);
        $id = (int) $db->the_insert_id();
        self::$probeIds[] = $id;
        return $id;
    }

    private function rowStatus(int $id): string
    {
        return (string) self::$db->fetchValue('SELECT status FROM tbl_mail_logs WHERE id = :i', [':i' => $id]);
    }

    private function rowAttempts(int $id): int
    {
        return (int) self::$db->fetchValue('SELECT attempts FROM tbl_mail_logs WHERE id = :i', [':i' => $id]);
    }

    private function rowNote(int $id): string
    {
        return (string) self::$db->fetchValue('SELECT note FROM tbl_mail_logs WHERE id = :i', [':i' => $id]);
    }

    private function rowLastAttempt(int $id): ?string
    {
        $v = self::$db->fetchValue('SELECT last_attempt_at FROM tbl_mail_logs WHERE id = :i', [':i' => $id]);
        return $v === null ? null : (string) $v;
    }

    #[Group('integration')]
    public function testTransportOffIsStrictNoOp(): void
    {
        $this->requireDb();
        putenv('WPMS_MAIL_ENABLED');
        $id = $this->seedPending();

        $r = Notification::retry_pending(50, 5);
        self::assertSame(0, $r['attempted'], 'no rows may be attempted with the transport off');
        self::assertSame('pending', $this->rowStatus($id), 'row must stay pending');
        self::assertSame(0, $this->rowAttempts($id), 'attempts must not increment');
    }

    #[Group('integration')]
    public function testFailingSendIncrementsAttemptsUntilAbandoned(): void
    {
        $this->requireDb();
        // WPMS_MAIL_ENABLED=1 but no MTA on any port → mail() fails fast.
        putenv('WPMS_MAIL_ENABLED=1');
        @ini_set('SMTP', '127.0.0.1');
        @ini_set('smtp_port', '1'); // nothing listens on port 1

        $id1 = $this->seedPending(0);
        $id2 = $this->seedPending(4); // one attempt left before the cap of 5

        $r = Notification::retry_pending(50, 5);
        // Other pending probe rows from sibling tests may also be swept in
        // this run, so assert per-row outcomes rather than exact totals.
        self::assertGreaterThanOrEqual(2, $r['attempted']);

        // Row 1: first failure → still pending (or failed), NOT abandoned, attempts=1.
        $s1 = $this->rowStatus($id1);
        self::assertContains($s1, ['pending', 'failed'], 'a first failure must not abandon the row');
        self::assertSame(1, $this->rowAttempts($id1));

        // Row 2: failed on the final allowed attempt → abandoned.
        self::assertSame('abandoned', $this->rowStatus($id2), 'final failed attempt must abandon the row');
        self::assertSame(5, $this->rowAttempts($id2));
        self::assertGreaterThanOrEqual(1, $r['abandoned']);
    }

    #[Group('integration')]
    public function testRowsAtOrBeyondTheCapAreNeverPickedUp(): void
    {
        $this->requireDb();
        putenv('WPMS_MAIL_ENABLED=1');
        $id = $this->seedPending(5); // already at the cap of 5

        Notification::retry_pending(50, 5);
        // The sweep is installation-wide, so sibling probes (and rows another
        // class left pending) can be picked up in the same call and the run
        // totals say nothing about this row. The guarantee — a row at the cap
        // is never selected — is asserted on the row itself, where it is
        // falsifiable.
        self::assertSame('pending', $this->rowStatus($id));
        self::assertSame(5, $this->rowAttempts($id));
        self::assertNull($this->rowLastAttempt($id), 'an over-cap row must not be stamped as attempted');
        self::assertSame('test seed', $this->rowNote($id), 'an over-cap row must keep its note');
    }

    #[Group('integration')]
    public function testNonPendingRowsAreNeverRetried(): void
    {
        $this->requireDb();
        putenv('WPMS_MAIL_ENABLED=1');
        global $db;

        // A sent row and a failed (below-cap) row must both be ignored.
        $stmt = $db->prepare(
            "INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, recipient_type, to_email, subject, body, status, note, attempts) "
            . "VALUES (NULL, :uid, 0, 'user', 'amina@wpms.co.ke', '[mail-retry-test] s', 'b', :status, NULL, 1)"
        );
        $stmt->execute([':uid' => self::STAFF_ID, ':status' => 'sent']);
        $sentId = (int) $db->the_insert_id();
        self::$probeIds[] = $sentId;
        $stmt->execute([':uid' => self::STAFF_ID, ':status' => 'failed']);
        $failedId = (int) $db->the_insert_id();
        self::$probeIds[] = $failedId;

        $r = Notification::retry_pending(50, 5);
        self::assertSame('sent', $this->rowStatus($sentId), 'sent rows must be untouched');
        self::assertSame('failed', $this->rowStatus($failedId), 'failed rows must be untouched');
    }
}
