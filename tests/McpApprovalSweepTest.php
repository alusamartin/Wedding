<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The expiry sweep (McpApprovals::sweep_expired + scripts/mcp_approval_sweep.php)
 * — v2.19: an approval request whose window closes is CLOSED, not forgotten.
 *
 * The v2.17 gate gives a Tier-3 write a 30-minute window. This suite is about
 * what happens when nobody answers inside it, and it insists on four things:
 *
 *   1. THE ROW IS CLOSED ONCE. `expired_at` is set by the sweep and by nobody
 *      else; a second run is silent instead of ringing the bell again, and a
 *      planner's decision (deny, or approve-then-unused) is never overwritten.
 *   2. THE ASSISTANT IS TOLD. One tblapi_audit row per expiry, ok=0, naming the
 *      approval, its payload hash and the reference — so a model reading its own
 *      history sees that nothing ran. A retry of the identical call gets the old
 *      reference back in `previous_request_expired` and an explicit "this is a
 *      NEW request", even on an install where cron was never wired.
 *   3. THE PLANNER IS TOLD, ONCE. One digest notification per planner per run
 *      (+ the email Notification already queues), never one per row — the
 *      planner's bell is the only thing that makes an unsent SMS visible.
 *   4. THE JOB IS REAL. It is in the watchdog's registry with a sane period, the
 *      cron bridge lists it, and the script itself runs (dry run here) with
 *      exit 0 against the live schema.
 *
 * Two kinds of death, both covered: the planner never decided (unanswered), and
 * the planner approved but the assistant never used the string before its own
 * 15-minute window closed (unused) — the second is the nastier one, because the
 * planner believes the message went out.
 *
 * Integration ('integration' group) — uses the live DB and the v2.17 + v2.19
 * tables. Every approval, audit row, notification and token this suite creates is
 * removed in tearDownAfterClass.
 */
final class McpApprovalSweepTest extends TestCase
{
    private const PREFIX = 'wpms-test-approval-sweep-';
    private const TOOL   = 'sweep_probe_tool';

    private static ?Database $db = null;
    private static int $plannerId = 0;
    private static int $tokenId = 0;
    /** @var array<string,mixed>|null the token's principal, as the MCP layer sees it */
    private static ?array $principal = null;
    /** @var int[] every approval row this suite creates */
    private static array $approvalIds = [];
    private static bool $hadMailFlag = false;

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
            foreach (['ApiToken', 'McpApprovals', 'Notification', 'JobWatch'] as $class) {
                if (!class_exists($class, false)) {
                    require_once __DIR__ . '/../admin/include/' . $class . '.php';
                }
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
            return;
        }

        // Keep the sweep's email out of SMTP: the bell row is what is asserted.
        self::$hadMailFlag = getenv('WPMS_MAIL_ENABLED') === '1';
        putenv('WPMS_MAIL_ENABLED=');
        putenv('WPMS_SITE_URL=');

        if (!McpApprovals::available() || !McpApprovals::expiry_marker_installed()) {
            self::$db = null; // v2.17/v2.19 not applied — skip cleanly
            return;
        }

        try {
            // A real staff account with a real token: the sweep alerts the
            // request's OWNER, so the fixture must have one.
            $row = self::$db->fetchOne(
                "SELECT id FROM tblusers WHERE access_level IN ('1','2') ORDER BY (access_level = '1') DESC, id LIMIT 1"
            );
            self::$plannerId = (int) ($row->id ?? 0);
            if (self::$plannerId <= 0) {
                self::$db = null;
                return;
            }
            $minted = ApiToken::mint(
                ApiToken::KIND_USER,
                self::$plannerId,
                self::PREFIX . bin2hex(random_bytes(4)),
                ApiToken::READ_SCOPES,
                null,
                1,
                null
            );
            self::$tokenId = (int) $minted['id'];
            $principal = ApiToken::principal(ApiToken::KIND_USER, self::$plannerId);
            if ($principal === null) {
                self::$db = null;
                return;
            }
            $principal['token_id'] = self::$tokenId;
            self::$principal = $principal;
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                foreach (self::$approvalIds as $id) {
                    $ref = McpApprovals::reference($id);
                    $notes = self::$db->fetchAll(
                        "SELECT id FROM tblnotifications WHERE link = 'mcp_approvals.php' AND message LIKE :m",
                        [':m' => '%' . $ref . ' (%']
                    );
                    foreach ($notes as $note) {
                        self::$db->execute('DELETE FROM tbl_mail_logs WHERE notification_id = :n', [':n' => (int) $note->id]);
                        self::$db->execute('DELETE FROM tblnotifications WHERE id = :n', [':n' => (int) $note->id]);
                    }
                }
                if (self::$approvalIds !== []) {
                    $in = implode(',', array_map('intval', self::$approvalIds));
                    self::$db->execute('DELETE FROM ' . ApiToken::AUDIT_TABLE . ' WHERE approval_id IN (' . $in . ')');
                    self::$db->execute('DELETE FROM ' . McpApprovals::TABLE . ' WHERE id IN (' . $in . ')');
                }
                if (self::$tokenId > 0) {
                    self::$db->execute('DELETE FROM ' . ApiToken::TABLE . ' WHERE id = :i', [':i' => self::$tokenId]);
                }
            } catch (Throwable $e) {
                // Best-effort: a leftover probe row is visible and harmless.
            }
        }
        self::$approvalIds = [];
        self::$principal  = null;
        self::$tokenId    = 0;

        if (self::$hadMailFlag) {
            putenv('WPMS_MAIL_ENABLED=1');
        } else {
            putenv('WPMS_MAIL_ENABLED');
        }
    }

    private function requireDb(): void
    {
        if (self::$db === null || self::$principal === null) {
            self::markTestSkipped('live DB / v2.17 + v2.19 approval tables unavailable — sweep tests skipped');
        }
    }

    // ------------------------------------------------------------------ helpers

    private static function db(): Database
    {
        if (!self::$db instanceof Database) {
            self::fail('the live database is not available');
        }
        return self::$db;
    }

    /** A fresh Tier-3 request for a payload nobody else uses. */
    private function request(array $payload): array
    {
        $request = McpApprovals::request(self::$principal, self::TOOL, 3, 0, $payload, 'probe: nothing real');
        self::$approvalIds[] = (int) $request['id'];
        return $request;
    }

    private function payload(): array
    {
        return ['probe' => bin2hex(random_bytes(6))];
    }

    /** Move the request window into the past, the way a planner's silence does. */
    private function ageOut(int $id): void
    {
        self::db()->execute(
            'UPDATE ' . McpApprovals::TABLE . ' SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE id = :i',
            [':i' => $id]
        );
    }

    private function expiredRequest(): array
    {
        $request = $this->request($this->payload());
        $this->ageOut((int) $request['id']);
        return $request;
    }

    private function auditFor(int $id): array
    {
        return self::db()->fetchAll(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE approval_id = :a ORDER BY id ASC',
            [':a' => $id]
        );
    }

    /** Notifications that name one reference (they are written by the sweep). */
    private function bellsFor(array $request): array
    {
        return self::db()->fetchAll(
            "SELECT * FROM tblnotifications
              WHERE recipient_type = 'user' AND user_id = :u AND link = 'mcp_approvals.php'
                AND message LIKE :m",
            [':u' => self::$plannerId, ':m' => '%' . $request['reference'] . ' (%']
        );
    }

    // -------------------------------------------------------------------- tests

    /**
     * The asked-for behaviour: an unanswered request is closed once, recorded for
     * the assistant, and rung up for the planner exactly once.
     */
    public function testAnUnansweredRequestIsClosedAuditedAndAlertedOnce(): void
    {
        $this->requireDb();
        $request = $this->expiredRequest();
        $id      = (int) $request['id'];

        $sweep = McpApprovals::sweep_expired();
        self::assertGreaterThanOrEqual(1, $sweep['expired'], 'the sweep closed at least this request');
        self::assertGreaterThanOrEqual(1, $sweep['unanswered']);
        self::assertSame($sweep['expired'], $sweep['audited'], 'every closed request leaves one record');

        $row = McpApprovals::find($id);
        self::assertNotNull($row);
        self::assertNotEmpty($row->expired_at, 'the marker is set on the row');

        $audit = $this->auditFor($id);
        self::assertCount(1, $audit, 'exactly one audit row for the expiry');
        self::assertSame(0, (int) $audit[0]->ok, 'nothing ran');
        self::assertSame(3, (int) $audit[0]->tier, 'it is a Tier-3 record');
        self::assertSame((string) $row->payload_hash, (string) $audit[0]->payload_hash);
        self::assertStringContainsString($request['reference'], (string) $audit[0]->outcome_note);
        self::assertStringContainsString('nothing was sent or recorded', strtolower((string) $audit[0]->outcome_note));

        $bells = $this->bellsFor($request);
        self::assertCount(1, $bells, 'one bell, naming the reference');
        self::assertStringContainsString('expired unanswered', (string) $bells[0]->message);
        self::assertSame(0, (int) $bells[0]->is_read, 'it starts unread, which is what makes it audible');

        // A second sweep must be SILENT: no second audit row, no second bell.
        $closedAt = (string) $row->expired_at;
        McpApprovals::sweep_expired();
        self::assertCount(1, $this->auditFor($id), 'the record is written once');
        self::assertCount(1, $this->bellsFor($request), 'the planner is not rung twice for one expiry');
        self::assertSame($closedAt, (string) McpApprovals::find($id)->expired_at, 'the marker does not move');
    }

    /** A planner's decision is an answer: the sweep must not close it. */
    public function testARefusedRequestIsNeverSwept(): void
    {
        $this->requireDb();
        $denied = $this->expiredRequest();
        McpApprovals::deny((int) $denied['id'], self::$plannerId, 'probe refusal');

        $pending = $this->request($this->payload()); // still inside its window

        McpApprovals::sweep_expired();

        $row = McpApprovals::find((int) $denied['id']);
        self::assertNull($row->expired_at, 'a refusal is already reported; it is not an expiry');
        self::assertSame('probe refusal', (string) $row->deny_reason);
        self::assertSame([], $this->auditFor((int) $denied['id']));

        $live = McpApprovals::find((int) $pending['id']);
        self::assertNull($live->expired_at, 'a request inside its window is untouched');
        self::assertSame('pending', McpApprovals::state_of($live));
    }

    /**
     * The nastier kind: the planner DID approve, and the assistant never used it.
     * The planner believes the SMS went out — so the sweep says it did not.
     */
    public function testAnApprovedButUnusedRequestIsClosedAndSaidOutLoud(): void
    {
        $this->requireDb();
        $request = $this->request($this->payload());
        McpApprovals::mint((int) $request['id'], self::$plannerId);
        $this->ageOut((int) $request['id']); // the minted 15-minute string ran out

        $sweep = McpApprovals::sweep_expired();
        self::assertGreaterThanOrEqual(1, $sweep['unused'], 'approved-but-unused is its own bucket');

        $row = McpApprovals::find((int) $request['id']);
        self::assertNotEmpty($row->expired_at);
        self::assertEmpty($row->consumed_at, 'nothing was consumed — that is the finding');

        $audit = $this->auditFor((int) $request['id']);
        self::assertCount(1, $audit);
        self::assertStringContainsString('approved but never used', strtolower((string) $audit[0]->outcome_note));

        $bells = $this->bellsFor($request);
        self::assertCount(1, $bells);
        self::assertStringContainsString('was approved but never used', (string) $bells[0]->message);
    }

    /**
     * A retry of the identical call names the dead reference — even when the
     * sweep has never run, because cron being absent must not leave the assistant
     * waiting on a request that cannot be approved.
     */
    public function testARetryNamesTheExpiredReferenceWithoutWaitingForCron(): void
    {
        $this->requireDb();
        $payload = $this->payload();
        $first   = $this->request($payload);
        self::assertNull($first['previous'] ?? null, 'the first request had nothing to replace');
        $this->ageOut((int) $first['id']);

        $again = McpApprovals::request(self::$principal, self::TOOL, 3, 0, $payload, 'probe: nothing real');
        self::$approvalIds[] = (int) $again['id'];

        self::assertNotSame((int) $first['id'], (int) $again['id'], 'a new row, not the dead one');
        self::assertSame($first['reference'], $again['previous']['reference'] ?? '');
        self::assertSame('expired unanswered', $again['previous']['outcome'] ?? '');
        self::assertFalse($again['previous']['swept'] ?? true, 'no sweep needed for the assistant to hear it');

        // The live row still takes precedence: a third identical call reuses it.
        $third = McpApprovals::request(self::$principal, self::TOOL, 3, 0, $payload, 'probe: nothing real');
        self::assertTrue($third['reused'], 'the pending request is reused, as before');
        self::assertSame($again['reference'], $third['reference']);
    }

    /** Approving an old request, then never using it, is described as exactly that. */
    public function testTheNoticeTellsAnUnusedApprovalFromAnUnansweredOne(): void
    {
        $this->requireDb();
        $payload = $this->payload();
        $first   = $this->request($payload);
        McpApprovals::mint((int) $first['id'], self::$plannerId);
        $this->ageOut((int) $first['id']);

        $again = McpApprovals::request(self::$principal, self::TOOL, 3, 0, $payload, 'probe: nothing real');
        self::$approvalIds[] = (int) $again['id'];
        self::assertSame('approved but not used in time', $again['previous']['outcome'] ?? '');
    }

    /** A dry run counts what would close and writes nothing at all. */
    public function testADryRunClosesNothing(): void
    {
        $this->requireDb();
        $request = $this->expiredRequest();

        $sweep = McpApprovals::sweep_expired(true);
        self::assertTrue($sweep['dry_run']);
        self::assertGreaterThanOrEqual(1, $sweep['expired'], 'it still counts the queue');
        self::assertSame(0, $sweep['audited']);
        self::assertSame(0, $sweep['notified']);
        self::assertNull(McpApprovals::find((int) $request['id'])->expired_at, 'nothing is marked');
        self::assertSame([], $this->auditFor((int) $request['id']));
        self::assertSame([], $this->bellsFor($request));
    }

    /** The job is judged, schedulable, and the script really runs. */
    public function testTheSweepIsAWiredCronJob(): void
    {
        self::assertArrayHasKey(JobWatch::MCP_APPROVALS, JobWatch::JOBS, 'the watchdog judges it');
        self::assertSame(900, JobWatch::JOBS[JobWatch::MCP_APPROVALS]['every'], 'every 10 minutes, inside the 30-minute window');

        $bridge = (string) file_get_contents(__DIR__ . '/../cron_bridge.php');
        self::assertStringContainsString("'mcp_approval_sweep'", $bridge);
        self::assertStringContainsString('scripts/mcp_approval_sweep.php', $bridge);

        $script = (string) file_get_contents(__DIR__ . '/../scripts/mcp_approval_sweep.php');
        self::assertStringContainsString("require __DIR__ . '/cli_guard.php'", $script, 'CLI-only, like every other job');
        self::assertStringContainsString('JobWatch::record(JobWatch::MCP_APPROVALS', $script, 'and it leaves its heartbeat');

        $this->requireDb();
        $out = [];
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/mcp_approval_sweep.php')
            . ' --dry-run --quiet 2>&1',
            $out,
            $code
        );
        self::assertSame(0, $code, "the script must run: \n" . implode("\n", $out));
    }
}
