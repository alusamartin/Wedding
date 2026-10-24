<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The MCP WRITE layer (McpWrites + McpApprovals) — proved through the endpoint.
 *
 * McpEndpointTest proves the read door: identity, ownership, protocol, the read
 * catalogue, the audit trail and the brake. This suite is about the other half,
 * the one that can change a wedding, message real people and record money — so
 * it is ruthless about exactly four things:
 *
 *   A. THE TIERS HOLD. A Tier-2 tool without `confirm: true` returns a preview
 *      and the database is PROVED unchanged (a guest count, not a promise). A
 *      Tier-3 tool with no approval sends nothing, records nothing and answers
 *      `approval_required`.
 *   B. THE APPROVAL IS A BINDING, NOT A BLANKET. It is minted by a signed-in
 *      human (here: McpApprovals::mint, which is what the Approvals page calls),
 *      it is bound to the tool AND the payload hash, it works ONCE, and a changed
 *      message, amount or planner id does not fit it.
 *   C. EXACTLY ONCE. An approval cannot be replayed, and a repeated `request_id`
 *      cannot repeat the write: the retry gets the first outcome back.
 *   D. WHAT HAPPENED IS LEGIBLE. The audit row carries the tier, the approval it
 *      used, the idempotency key, the payload hash and a server-authored outcome
 *      note — and still carries no argument VALUE (no M-Pesa code, no amount
 *      echoed as text, no phone number).
 *
 * Everything runs over real HTTP against a real `php -S` server started WITH
 * `WPMS_MCP_WRITES=1`, against the seeded database — the same shape an assistant
 * uses, not a shortcut into the classes. Every row this suite writes is removed
 * again in tearDownAfterClass (guests, a liquidation row, an organizer_id
 * restored to what it was, approvals, audit rows and tokens), so a run leaves the
 * seeded database as it found it.
 *
 * If the v2.17 migration has not been applied, every test skips rather than
 * failing: the assertion is about the write layer, not about a missing table.
 */
final class McpWriteToolsTest extends TestCase
{
    /** Name prefix for every token this suite creates — the sweep key. */
    private const TEST_PREFIX = 'wpms-test-mcpwrite-';

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;

    /** @var int[] */
    private static array $tokenIds = [];
    /** @var array<string,string> name => secret */
    private static array $secrets = [];
    /** @var array<string,int> name => token id (so an audit row can be found by TOKEN) */
    private static array $tokenIdByName = [];
    private static int $adminId = 0;
    private static int $booking = 0;
    private static int $bookingUser = 0;
    private static int $foreignBooking = 0;
    private static int $plannerId = 0;
    private static int $otherPlanner = 0;
    private static int $targetPlanner = 0;
    private static int $eventId = 0;
    private static string $eventTitle = '';
    private static int $taskItemId = 0;
    private static bool $taskDone = false;
    private static int $originalOrganizer = 0;

    /** Rows to remove afterwards (table => ids). */
    private static array $cleanup = ['tblguest' => [], 'tbl_liquidation' => []];

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
        } catch (Throwable $e) {
            self::$db = null;
        }

        putenv('WPMS_MAIL_ENABLED=');
        putenv('WPMS_SITE_URL=');

        self::bootServer();
        if (self::$base === null || self::$db === null) {
            return;
        }
        if (!ApiToken::available() || !McpApprovals::available()) {
            self::$base = null; // v2.16/v2.17 migrations not applied — skip
            return;
        }

        self::sweep();

        try {
            // access_level is an enum: compare as the STRING MySQL stores.
            self::$adminId = (int) self::$db->fetchValue("SELECT id FROM tblusers WHERE access_level = '2' ORDER BY id LIMIT 1");
            $planner = self::$db->fetchOne("SELECT id FROM tblusers WHERE access_level IN ('1','2') AND id <> :a ORDER BY id LIMIT 1",
                [':a' => self::$adminId]);
            self::$plannerId = (int) ($planner->id ?? 0);
            $other = self::$db->fetchOne("SELECT id FROM tblusers WHERE access_level IN ('1','2') AND id NOT IN (:a, :b) ORDER BY id LIMIT 1",
                [':a' => self::$adminId, ':b' => self::$plannerId]);
            self::$otherPlanner = (int) ($other->id ?? 0);

            $row = self::$db->fetchOne(
                'SELECT b.booking_id, b.user_id, b.organizer_id FROM tblweddingbook b
                   JOIN tblaccounts a ON a.user_id = b.user_id
                  JOIN tblaccounts_detail d ON d.user_id = b.user_id
                  WHERE b.wedding_date >= CURDATE() AND d.status = \'confirm\'
               ORDER BY b.booking_id LIMIT 1'
            );
            self::$booking       = (int) ($row->booking_id ?? 0);
            self::$bookingUser   = (int) ($row->user_id ?? 0);
            self::$originalOrganizer = (int) ($row->organizer_id ?? 0);
            // A planner DIFFERENT from the one the seed already assigned, so an
            // approved assignment is a real change rather than a no-op.
            self::$targetPlanner = self::$originalOrganizer === self::$plannerId ? self::$otherPlanner : self::$plannerId;
            self::$foreignBooking = (int) self::$db->fetchValue(
                'SELECT booking_id FROM tblweddingbook WHERE user_id <> :u ORDER BY booking_id LIMIT 1',
                [':u' => self::$bookingUser]
            );
            $event = self::$db->fetchOne(
                'SELECT id, title FROM events WHERE booking_id = :b ORDER BY id LIMIT 1',
                [':b' => self::$booking]
            );
            self::$eventId    = (int) ($event->id ?? 0);
            self::$eventTitle = (string) ($event->title ?? '');

            $item = self::$db->fetchOne(
                'SELECT id, is_done FROM tblchecklist WHERE booking_id = :b ORDER BY position ASC, id ASC LIMIT 1',
                [':b' => self::$booking]
            );
            self::$taskItemId = (int) ($item->id ?? 0);
            self::$taskDone   = (int) ($item->is_done ?? 0) === 1;
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if (self::$booking <= 0 || self::$bookingUser <= 0 || self::$foreignBooking <= 0
            || self::$adminId <= 0 || self::$plannerId <= 0 || self::$eventId <= 0 || self::$taskItemId <= 0) {
            self::$base = null; // seed incomplete — skip
            return;
        }

        try {
            self::mint('writer', ApiToken::KIND_USER, self::$adminId, array_merge(
                ApiToken::READ_SCOPES,
                ['write:guests', 'write:tasks', 'write:sms', 'write:budget', 'write:planners']
            ), null);
            self::mint('reader', ApiToken::KIND_USER, self::$adminId, ApiToken::READ_SCOPES, null);
            self::mint('couple', ApiToken::KIND_ACCOUNT, self::$bookingUser, ['read:weddings', 'write:guests'], null);
            // A couple's token CARRYING the money/message scopes: mint() allows
            // them (they are not admin scopes), so the role gate is the only thing
            // standing between a couple and staff tools. This token exists to
            // prove that gate is real rather than a side effect of the scope list.
            self::mint('couple-writer', ApiToken::KIND_ACCOUNT, self::$bookingUser,
                ['read:weddings', 'write:guests', 'write:sms', 'write:budget', 'write:planners'], null);
        } catch (Throwable $e) {
            self::sweep();
            self::$base = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                // Undo everything this suite wrote, in reverse order of writing.
                foreach (self::$cleanup['tbl_liquidation'] as $id) {
                    self::$db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => $id]);
                }
                foreach (self::$cleanup['tblguest'] as $id) {
                    self::$db->execute('DELETE FROM tblguest WHERE id = :id', [':id' => $id]);
                }
                if (self::$taskItemId > 0) {
                    self::$db->execute(
                        'UPDATE tblchecklist SET is_done = :d, done_at = ' . (self::$taskDone ? 'NOW()' : 'NULL') . ' WHERE id = :id',
                        [':d' => self::$taskDone ? 1 : 0, ':id' => self::$taskItemId]
                    );
                }
                if (self::$booking > 0) {
                    self::$db->execute(
                        'UPDATE tblweddingbook SET organizer_id = :o WHERE booking_id = :b',
                        [':o' => self::$originalOrganizer > 0 ? self::$originalOrganizer : null, ':b' => self::$booking]
                    );
                }
            } catch (Throwable $e) {
                // Best-effort: a leftover row is visible and harmless.
            }
        }
        self::sweep();
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    private static function mint(string $name, string $kind, int $id, array $scopes, ?int $bookingId): void
    {
        $minted = ApiToken::mint($kind, $id, self::TEST_PREFIX . $name, $scopes, $bookingId, 1, null);
        self::$tokenIds[]              = (int) $minted['id'];
        self::$tokenIdByName[$name]    = (int) $minted['id'];
        self::$secrets[$name]          = (string) $minted['secret'];
    }

    /** Remove this suite's tokens, audit rows, approvals and throttle counters. */
    private static function sweep(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            $rows = self::$db->fetchAll(
                'SELECT id FROM ' . ApiToken::TABLE . ' WHERE name LIKE :p',
                [':p' => self::TEST_PREFIX . '%']
            );
            foreach ($rows as $row) {
                $id = (int) (is_array($row) ? $row['id'] : $row->id);
                self::$db->execute('DELETE FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :id', [':id' => $id]);
                if (McpApprovals::available()) {
                    self::$db->execute('DELETE FROM ' . McpApprovals::TABLE . ' WHERE token_id = :id', [':id' => $id]);
                }
                self::$db->execute('DELETE FROM ' . ApiToken::TABLE . ' WHERE id = :id', [':id' => $id]);
            }
            foreach (self::$secrets as $secret) {
                self::$db->execute(
                    'DELETE FROM tblpublic_throttle WHERE kind = :k AND subject = :s',
                    [':k' => PublicGuard::MCP, ':s' => PublicGuard::subjectHash($secret)]
                );
            }
            /*
             * And the sign-in-throttle counters an MCP authentication failure
             * writes (ApiToken files them under `mcp:<token id>`, or
             * `mcp:unknown` for a secret matching no row). They are this suite's
             * own rows — no human's email starts with `mcp:` — and a suite that
             * presents invalid tokens must not leave a 15-minute lockout behind
             * for the next run on the same machine.
             */
            self::$db->execute("DELETE FROM tbllogin_throttle WHERE throttle_email LIKE 'mcp:%'");
        } catch (Throwable $e) {
            // Best-effort cleanup.
        }
    }

    /**
     * Start `php -S` with the WRITE SWITCH ON — explicitly, in the child's
     * environment, so this suite never depends on the developer's shell and never
     * leaks the switch into another suite's server.
     */
    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_mcpw_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_mcpw_err');
        $env    = getenv();
        $env['WPMS_MCP_WRITES'] = '1';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 32000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            self::$serverProc = @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root, $env);
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

    /**
     * One JSON-RPC POST to /mcp.php.
     *
     * @return array{status:int, body:string, json:mixed}
     */
    private static function rpc(mixed $message, ?string $secret = null): array
    {
        $raw    = is_string($message) ? $message : (string) json_encode($message);
        $header = 'Host: 127.0.0.1' . "\r\n" . "Content-Type: application/json\r\n";
        if ($secret !== null) {
            $header .= 'Authorization: Bearer ' . $secret . "\r\n";
        }
        $header .= 'Content-Length: ' . strlen($raw) . "\r\n" . 'Connection: close';
        $ctx = stream_context_create(['http' => [
            'method'          => 'POST',
            'header'          => $header,
            'content'         => $raw,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 20,
        ]]);
        $body   = @file_get_contents(self::$base . '/mcp.php', false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'body' => (string) $body, 'json' => json_decode((string) $body, true)];
    }

    /**
     * Call one tool and decode the payload an assistant would read.
     *
     * @return array{status:int, isError:bool, text:string, data:mixed}
     */
    private static function tool(string $secret, string $tool, array $args = []): array
    {
        $res    = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => $args],
        ], $secret);
        $result = $res['json']['result'] ?? [];
        $text   = (string) ($result['content'][0]['text'] ?? '');
        return [
            'status'  => $res['status'],
            'isError' => (bool) ($result['isError'] ?? false),
            'text'    => $text,
            'data'    => json_decode($text, true),
        ];
    }

    /** The tools this token is offered. @return string[] */
    private static function advertised(string $secret): array
    {
        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], $secret);
        $names = [];
        foreach ($res['json']['result']['tools'] ?? [] as $tool) {
            $names[] = (string) ($tool['name'] ?? '');
        }
        return $names;
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / seeded database / v2.16-v2.17 tables unavailable — MCP write tests skipped');
        }
    }

    private function secret(string $name): string
    {
        $this->requireHttp();
        return self::$secrets[$name] ?? '';
    }

    private function guestCount(int $bookingId): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $bookingId]);
    }

    private function smsLogCount(int $bookingId): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblsms_logs WHERE booking_id = :b', [':b' => $bookingId]);
    }

    /** The newest audit row for one tool, on the writing token. */
    private function auditRow(string $tool): object
    {
        $row = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t AND tool = :tool ORDER BY id DESC LIMIT 1',
            [':t' => self::$tokenIdByName['writer'] ?? 0, ':tool' => $tool]
        );
        self::assertNotNull($row, 'an audit row must exist for ' . $tool);
        return $row;
    }

    // ==================================================================
    // A. The catalogue and what it may never contain
    // ==================================================================

    /**
     * A token that may only read is never told the write tools exist, and a
     * writer gets them — the scope is a ceiling on the LIST, not only on the call.
     */
    #[Group('integration')]
    public function testWriteToolsAreAdvertisedOnlyToTokensThatMayUseThem(): void
    {
        $this->requireHttp();
        $writer = self::advertised($this->secret('writer'));
        $reader = self::advertised($this->secret('reader'));

        foreach (['add_guest', 'update_guest', 'mark_invited', 'toggle_task', 'send_bulk_sms', 'record_payment', 'initiate_stk_deposit', 'assign_planner'] as $tool) {
            self::assertContains($tool, $writer, $tool . ' must be offered to a token carrying its write scope');
            self::assertNotContains($tool, $reader, $tool . ' must not be offered to a read-only token');
        }
        self::assertContains('list_guests', $reader, 'the read catalogue is unaffected');
    }

    /**
     * The hard rules, asserted against the live catalogue rather than trusted: the
     * methods no tool may expose, and the credential columns no schema may mention.
     */
    /**
     * Per-role visibility, asserted where it actually bites: a COUPLE's token that
     * carries the money/message scopes is still not offered the Tier-3 tools, and
     * asking for one anyway is refused as a role decision.
     *
     * This is the ceiling on top of the scopes: a couple may hold `write:budget`
     * (nothing refuses it at mint), and yet recording payments, prompting a
     * stranger's phone, texting a guest list and deciding who plans a wedding are
     * staff actions — the same ones only staff pages perform. The couple keeps the
     * Tier-2 tools its own client pages have, and its row rules are unchanged: a
     * couple still cannot write to another wedding.
     */
    #[Group('integration')]
    public function testTierThreeToolsAreStaffOnlyEvenForATokenThatCarriesTheirScopes(): void
    {
        $this->requireHttp();
        $names = self::advertised($this->secret('couple-writer'));

        foreach (['send_bulk_sms', 'record_payment', 'initiate_stk_deposit', 'assign_planner'] as $tool) {
            self::assertNotContains($tool, $names,
                $tool . ' is a staff tool — a couple\'s token must not even be told it exists, whatever scopes it holds');
        }
        self::assertContains('add_guest', $names, 'the couple keeps the Tier-2 tool its own guest page has');

        $out = self::tool($this->secret('couple-writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);
        self::assertTrue($out['isError'], 'asking for it anyway is refused');
        self::assertStringContainsString('staff accounts only', $out['text'],
            'and the refusal is a sentence the assistant can repeat to the planner');

        // And it is a ROLE refusal, not an accident of the booking predicate: the
        // couple's own wedding was named, and it still could not have worked.
        self::assertStringNotContainsString('read:weddings', $out['text'], 'not a scope refusal dressed up as one');
    }

    #[Group('integration')]
    public function testTheCatalogueExposesNoForbiddenMethodOrCredentialColumn(): void
    {
        $this->requireHttp();
        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], $this->secret('writer'));

        $forbidden = [
            'mark_consumed', 'increment_views', 'update_plan', 'create_plan', 'set_plan_active',
            'create_subscription', 'add_credits', 'deduct_credits', 'africastalking_send', 'raw_sql', 'query',
        ];
        foreach ($res['json']['result']['tools'] ?? [] as $tool) {
            $name = (string) ($tool['name'] ?? '');
            self::assertNotContains($name, $forbidden, 'never advertise ' . $name . ' as a tool');
            $schema = json_encode($tool['inputSchema'] ?? []);
            foreach (['user_password', 'password', 'passwd', 'token_hash', 'secret'] as $column) {
                self::assertStringNotContainsString($column, (string) $schema, 'a tool argument must never name a credential column');
            }
        }
    }

    // ==================================================================
    // B. Tier 2 — preview, confirm, exactly once
    // ==================================================================

    /** No `confirm` means NO ROW: a preview is a proposal, not a write. */
    #[Group('integration')]
    public function testAddingAGuestWithoutConfirmPreviewsAndWritesNothing(): void
    {
        $this->requireHttp();
        $before = $this->guestCount(self::$booking);

        $out = self::tool($this->secret('writer'), 'add_guest', [
            'booking_id' => self::$booking,
            'fullname'   => 'Preview Only Guest',
            'guestname'  => 'Preview',
            'relationship' => 'colleague',
        ]);

        self::assertFalse($out['isError'], $out['text']);
        self::assertSame('preview', $out['data']['status'] ?? '', 'a Tier-2 call without confirm answers with a preview');
        self::assertSame($before, $this->guestCount(self::$booking), 'and adds no guest');
        self::assertStringContainsString('nothing has been written', strtolower($out['text']));
        self::assertSame('D', $out['data']['would_change']['priority'] ?? '', 'the band shown is the one the platform would derive');
    }

    /** Confirm writes; the same `request_id` again must NOT write a second guest. */
    #[Group('integration')]
    public function testConfirmingAddsOneGuestAndARepeatedRequestIdChangesNothingMore(): void
    {
        $this->requireHttp();
        $before  = $this->guestCount(self::$booking);
        $request = 'test-' . bin2hex(random_bytes(6));
        $args = [
            'booking_id' => self::$booking,
            'fullname'   => 'MCP Test Guest',
            'guestname'  => 'Test Guest',
            'phone'      => '0712 345 678',
            'relationship' => 'family_friend',
            'out_of_town'  => true,
            'confirm'      => true,
            'request_id'   => $request,
        ];

        $first = self::tool($this->secret('writer'), 'add_guest', $args);
        self::assertFalse($first['isError'], $first['text']);
        self::assertSame('applied', $first['data']['status'] ?? '');
        self::assertGreaterThan(0, (int) ($first['data']['guest_id'] ?? 0));
        self::$cleanup['tblguest'][] = (int) $first['data']['guest_id'];
        self::assertSame($before + 1, $this->guestCount(self::$booking));

        // The row itself carries what the form would have derived.
        $row = self::$db->fetchOne('SELECT * FROM tblguest WHERE id = :id', [':id' => (int) $first['data']['guest_id']]);
        self::assertSame('C', (string) $row->priority, 'family_friend derives band C, as the couple\'s own form does');
        self::assertSame('y', (string) $row->out_of_town);
        self::assertSame(32, strlen((string) $row->rsvp_token), 'the guest gets an RSVP token with the row, so they are invitable');

        $again = self::tool($this->secret('writer'), 'add_guest', $args);
        self::assertSame('already_applied', $again['data']['status'] ?? '',
            'a retry with the same request_id reports the first outcome instead of adding a second guest');
        self::assertSame($before + 1, $this->guestCount(self::$booking));

        // The row carrying the key is the call that DID the write; the replay is a
        // separate row with no key, because it changed nothing.
        $audit = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE
            . ' WHERE token_id = :t AND tool = :tool AND request_id = :r ORDER BY id DESC LIMIT 1',
            [':t' => self::$tokenIdByName['writer'], ':tool' => 'add_guest', ':r' => $request]
        );
        self::assertNotNull($audit, 'the applied call is recorded under its idempotency key');
        self::assertSame(2, (int) $audit->tier, 'a Tier-2 write is audited as tier 2');
        self::assertNotSame('', (string) $audit->outcome_note, 'the audit row says what changed');
        self::assertStringNotContainsString('MCP Test Guest', (string) $audit->outcome_note,
            'the note describes the change without copying the guest\'s name into the log');
    }

    /** A couple may use its write scope on its OWN wedding and nothing else. */
    #[Group('integration')]
    public function testACouplesTokenCannotWriteToAnotherWedding(): void
    {
        $this->requireHttp();
        $foreign = $this->guestCount(self::$foreignBooking);

        $out = self::tool($this->secret('couple'), 'add_guest', [
            'booking_id' => self::$foreignBooking,
            'fullname'   => 'Intruder Guest',
            'guestname'  => 'Intruder',
            'confirm'    => true,
        ]);

        self::assertTrue($out['isError'], 'writing to a wedding this account cannot open must be refused');
        self::assertStringContainsString('cannot open', strtolower($out['text']));
        self::assertSame($foreign, $this->guestCount(self::$foreignBooking), 'and nothing was written');
    }

    /** Tier 2 on a checklist item: the toggle happens once, and it is reversible. */
    #[Group('integration')]
    public function testTogglingAChecklistItemIsPreviewedThenReversible(): void
    {
        $this->requireHttp();
        $out = self::tool($this->secret('writer'), 'toggle_task', [
            'booking_id' => self::$booking,
            'item_id'    => self::$taskItemId,
        ]);
        self::assertSame('preview', $out['data']['status'] ?? '', 'no confirm, no change');
        self::assertSame(self::$taskDone ? 1 : 0, (int) $out['data']['would_change']['is_done']);

        $done = self::tool($this->secret('writer'), 'toggle_task', [
            'booking_id' => self::$booking,
            'item_id'    => self::$taskItemId,
            'confirm'    => true,
        ]);
        self::assertSame('applied', $done['data']['status'] ?? '', $done['text']);
        $now = (int) self::$db->fetchValue('SELECT is_done FROM tblchecklist WHERE id = :id', [':id' => self::$taskItemId]);
        self::assertSame(self::$taskDone ? 0 : 1, $now, 'the item flipped once');

        // Put it back (tearDownAfterClass also guards this) so the seed is intact.
        self::$db->execute(
            'UPDATE tblchecklist SET is_done = :d, done_at = ' . (self::$taskDone ? 'NOW()' : 'NULL') . ' WHERE id = :id',
            [':d' => self::$taskDone ? 1 : 0, ':id' => self::$taskItemId]
        );
    }

    // ==================================================================
    // C. Tier 3 — nothing happens without a human
    // ==================================================================

    /** An unapproved Tier-3 call records a request and does NOT perform it. */
    #[Group('integration')]
    public function testATier3CallWithoutApprovalAsksForOneAndChangesNothing(): void
    {
        $this->requireHttp();
        $before = (int) self::$db->fetchValue('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::$booking]);

        $out = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);

        self::assertFalse($out['isError'], $out['text']);
        self::assertSame('approval_required', $out['data']['status'] ?? '');
        self::assertMatchesRegularExpression('/^apr-\d+$/', (string) ($out['data']['reference'] ?? ''));

        $after = (int) self::$db->fetchValue('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::$booking]);
        self::assertSame($before, $after, 'the booking is untouched until a human approves');

        $replay = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);
        self::assertSame($out['data']['reference'], $replay['data']['reference'] ?? '',
            'asking twice about the same payload is ONE request, not two');

        $row = self::$db->fetchOne('SELECT * FROM ' . McpApprovals::TABLE . ' WHERE tool = :t AND booking_id = :b ORDER BY id DESC LIMIT 1',
            [':t' => 'assign_planner', ':b' => self::$booking]);
        self::assertNotNull($row);
        self::assertNull($row->approved_at);
        self::assertNotSame('', (string) $row->payload_note, 'the planner is shown a sentence the server wrote from the data');
        /*
         * The sentence a planner authorises must state what the write DECIDES,
         * and since per-planner scoping exists that is visibility: the note says
         * who will be able to open the wedding from now on. A note that only
         * named "the planner the couple is told about" would understate it, and a
         * note that still said "this creates no scoping" would be false.
         */
        self::assertStringContainsString('who can open this wedding', (string) $row->payload_note,
            'the note says the assignment decides visibility');
        self::assertStringContainsString('unassigned ones visible to everybody', (string) $row->payload_note,
            'and states the unassigned case plainly');
    }

    /** Refusing is a decision: the request can no longer be approved. */
    #[Group('integration')]
    public function testARefusedRequestCannotBeApproved(): void
    {
        $this->requireHttp();
        $row = self::$db->fetchOne(
            'SELECT id FROM ' . McpApprovals::TABLE . ' WHERE tool = :t AND denied_at IS NULL ORDER BY id DESC LIMIT 1',
            [':t' => 'assign_planner']
        );
        self::assertNotNull($row, 'the earlier test asked for an approval');
        $id = (int) $row->id;

        McpApprovals::deny($id, self::$adminId, 'not this planner');

        $this->expectException(McpApprovalError::class);
        McpApprovals::mint($id, self::$adminId);
    }

    /**
     * A refusal is an ANSWER, not a closed row. Asking the identical question
     * again must report what the planner said and mint nothing — otherwise the
     * assistant learns nothing, the planner is asked the same thing forever, and
     * the Approvals page's promise that "the assistant is told" is untrue.
     */
    #[Group('integration')]
    public function testARefusedPayloadIsReportedAsRefusedInsteadOfBeingAskedAgain(): void
    {
        $this->requireHttp();
        $out = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);

        self::assertFalse($out['isError'], $out['text']);
        self::assertSame('refused', $out['data']['status'] ?? '', $out['text']);
        self::assertSame('not this planner', $out['data']['reason'] ?? '', 'the planner\'s own reason reaches the model');
        self::assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM ' . McpApprovals::TABLE . '
              WHERE tool = :t AND denied_at IS NULL AND approved_at IS NULL AND consumed_at IS NULL',
            [':t' => 'assign_planner']
        ), 'and no fresh request was queued for the planner to refuse twice');
    }

    /**
     * An approval is bound to ONE payload: a DIFFERENT PLANNER (a valid one — an
     * invalid id is refused for its own reason, before the approval is ever
     * consulted) does not fit the string that was minted.
     */
    #[Group('integration')]
    public function testAnApprovalDoesNotFitADifferentPayload(): void
    {
        $this->requireHttp();
        if (self::$otherPlanner <= 0) {
            self::markTestSkipped('this seed has only one planner account');
        }
        $target = self::$otherPlanner;

        $asked = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => $target,
        ]);
        self::assertSame('approval_required', $asked['data']['status'] ?? '', $asked['text']);
        $id = (int) self::$db->fetchValue(
            'SELECT id FROM ' . McpApprovals::TABLE . ' WHERE tool = :t AND payload_hash = :h ORDER BY id DESC LIMIT 1',
            [':t' => 'assign_planner', ':h' => McpApprovals::payload_hash('assign_planner', McpWrites::committed('assign_planner', [
                'booking_id' => self::$booking, 'organizer_id' => $target,
            ]))]
        );
        self::assertGreaterThan(0, $id, 'the request row is addressable by the payload hash the model was shown');
        $minted = McpApprovals::mint($id, self::$adminId);

        // Same tool, a DIFFERENT valid planner: the HMAC covers the payload hash,
        // so this is refused BEFORE anything is written.
        $wrong = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
            'approval'     => $minted['token'],
        ]);
        self::assertTrue($wrong['isError'], 'an approval must not be reuseable for a different payload');
        self::assertStringContainsString('different payload', strtolower($wrong['text']));
        self::assertSame(
            (int) self::$db->fetchValue('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::$booking]),
            self::$originalOrganizer,
            'and the booking is still exactly as it was'
        );
    }

    /** Approve → apply once → the SAME string is dead the second time. */
    #[Group('integration')]
    public function testAnApprovedCallAppliesOnceAndTheApprovalIsThenSpent(): void
    {
        $this->requireHttp();
        if (self::$targetPlanner <= 0) {
            self::markTestSkipped('this seed has only the planner it already assigned');
        }

        $asked = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$targetPlanner,
        ]);
        self::assertSame('approval_required', $asked['data']['status'] ?? '', $asked['text']);
        $id = (int) self::$db->fetchValue(
            'SELECT id FROM ' . McpApprovals::TABLE . ' WHERE tool = :t AND booking_id = :b AND approved_at IS NULL AND denied_at IS NULL
               ORDER BY id DESC LIMIT 1',
            [':t' => 'assign_planner', ':b' => self::$booking]
        );
        $minted = McpApprovals::mint($id, self::$adminId);

        $args = [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$targetPlanner,
            'approval'     => $minted['token'],
            'request_id'   => 'test-appr-' . bin2hex(random_bytes(5)),
        ];
        $done = self::tool($this->secret('writer'), 'assign_planner', $args);
        self::assertFalse($done['isError'], $done['text']);
        self::assertSame('applied', $done['data']['status'] ?? '');
        self::assertSame(self::$targetPlanner, (int) self::$db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::$booking]), 'the approved write landed');
        self::assertStringContainsString('who can open this wedding', strtolower((string) $done['data']['note']),
            'the result repeats that organizer_id is what decides visibility');
        self::assertStringContainsString('other planner', strtolower((string) ($done['data']['scoping'] ?? '')),
            'and the machine-readable line says who can no longer open it');

        // The audit row points at the human decision and the tier.
        $audit = $this->auditRow('assign_planner');
        self::assertSame(3, (int) $audit->tier);
        self::assertSame($id, (int) $audit->approval_id, 'the audit row names the approval that authorised it');
        self::assertSame($args['request_id'], (string) $audit->request_id);
        self::assertSame(
            McpApprovals::payload_hash('assign_planner', McpWrites::committed('assign_planner', $args)),
            (string) $audit->payload_hash,
            'the recorded hash is the one the human approved'
        );

        // Single use: the same string cannot do it again.
        $reuse = self::tool($this->secret('writer'), 'assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$targetPlanner,
            'approval'     => $minted['token'],
            'request_id'   => 'test-appr2-' . bin2hex(random_bytes(5)),
        ]);
        self::assertTrue($reuse['isError'], 'an approval is single-use');
        self::assertStringContainsString('already been used', strtolower($reuse['text']));
        self::assertSame(self::$targetPlanner, (int) self::$db->fetchValue(
            'SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => self::$booking]), 'and the reuse did not change it again');
    }

    /** A money write that lands: one ledger row, and no argument values in the log. */
    #[Group('integration')]
    public function testApprovedPaymentWritesOneLedgerRowAndIsAuditedWithoutValues(): void
    {
        $this->requireHttp();
        $amount = 1500.0;
        $code   = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
        $code   = preg_replace('/[^A-Z0-9]/', 'A', $code) ?? 'QDLTESTAAA';

        $args = [
            'booking_id' => self::$booking,
            'event_id'   => self::$eventId,
            'amount'     => $amount,
            'method'     => 'mpesa',
            'mpesa_code' => $code,
        ];

        $asked = self::tool($this->secret('writer'), 'record_payment', $args);
        self::assertSame('approval_required', $asked['data']['status'] ?? '', $asked['text']);
        self::assertStringContainsString($code, (string) ($asked['data']['will_happen'] ?? ''),
            'the planner is shown the M-Pesa code they are approving, in the server\'s own sentence');

        $payloadHash = McpApprovals::payload_hash('record_payment', McpWrites::committed('record_payment', $args));
        $id = (int) self::$db->fetchValue(
            'SELECT id FROM ' . McpApprovals::TABLE . ' WHERE tool = :t AND payload_hash = :h ORDER BY id DESC LIMIT 1',
            [':t' => 'record_payment', ':h' => $payloadHash]
        );
        self::assertGreaterThan(0, $id);
        $minted = McpApprovals::mint($id, self::$adminId);

        // An unverified M-Pesa code is refused when verification is switched on;
        // either way NOTHING is written before the approval is checked.
        $out = self::tool($this->secret('writer'), 'record_payment', $args + [
            'approval'   => $minted['token'],
            'request_id' => 'test-pay-' . bin2hex(random_bytes(5)),
        ]);

        if ($out['isError']) {
            self::assertStringContainsString('could not be verified', strtolower($out['text']),
                'with verification enabled the only refusal is the unverified code: ' . $out['text']);
            self::assertSame(0, (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbl_liquidation WHERE mpesa_code = :c', [':c' => $code]));
            return; // This install verifies codes — nothing further to assert here.
        }

        self::assertSame('applied', $out['data']['status'] ?? '');
        $id = (int) ($out['data']['liquidation_id'] ?? 0);
        self::assertGreaterThan(0, $id);
        self::$cleanup['tbl_liquidation'][] = $id;

        $row = self::$db->fetchOne('SELECT * FROM tbl_liquidation WHERE id = :id', [':id' => $id]);
        self::assertNotNull($row, 'the payment is in the couple\'s ledger');
        self::assertSame($amount, (float) $row->payment);
        self::assertSame($code, (string) $row->mpesa_code);
        self::assertSame(0.0, (float) $row->cash, 'an M-Pesa row is cash-neutral, as the staff form records it');
        self::assertSame(self::$eventId, (int) $row->event_id);
        self::assertSame(self::$bookingUser, (int) $row->user_id, 'the ledger row belongs to the couple, not to the approver');

        // The audit row: legible, and free of the values themselves.
        $audit = $this->auditRow('record_payment');
        self::assertSame(3, (int) $audit->tier);
        self::assertGreaterThan(0, (int) $audit->approval_id, 'the audit row names the human decision behind the payment');
        self::assertStringNotContainsString($code, (string) $audit->args_digest, 'no M-Pesa code in the audit trail');
        self::assertStringNotContainsString('1500', (string) $audit->args_digest, 'no argument VALUES — only their names and sizes');
        self::assertStringNotContainsString($code, (string) $audit->outcome_note, 'the note says what changed, not the code');
        self::assertNotSame('', (string) $audit->outcome_note);
    }

    // ==================================================================
    // D. The switch, the audience and the cost
    // ==================================================================

    /**
     * With WPMS_MCP_WRITES unset, a token that carries a write scope still cannot
     * write — the scope is a ceiling, not a switch.
     */
    #[Group('integration')]
    public function testWriteToolsAreInertWhenTheInstallSwitchIsOff(): void
    {
        $this->requireHttp();
        $principal = [
            'token_id' => 1, 'kind' => ApiToken::KIND_USER, 'id' => self::$adminId,
            'role' => 'super_admin', 'scopes' => array_merge(ApiToken::READ_SCOPES, ['write:guests']),
        ];

        $previous = getenv(McpServer::WRITE_SWITCH);
        putenv(McpServer::WRITE_SWITCH);
        try {
            self::assertFalse(McpServer::writes_enabled(), 'writes are off by default');
            self::assertFalse(McpWrites::permitted('add_guest', $principal), 'a write scope alone does not enable a write');
            self::assertSame([], McpWrites::definitions($principal), 'and nothing write-shaped is advertised');
        } finally {
            $previous === false ? putenv(McpServer::WRITE_SWITCH) : putenv(McpServer::WRITE_SWITCH . '=' . $previous);
        }

        putenv(McpServer::WRITE_SWITCH . '=1');
        try {
            self::assertTrue(McpWrites::permitted('add_guest', $principal), 'the switch plus the scope is what enables it');
            self::assertFalse(McpWrites::permitted('send_bulk_sms', $principal), 'the SMSScope is still required on its own tool');
        } finally {
            $previous === false ? putenv(McpServer::WRITE_SWITCH) : putenv(McpServer::WRITE_SWITCH . '=' . $previous);
        }
    }

    /**
     * The SMS audience and its cost come from the platform's own rules — KSh 1 per
     * 160 characters, and the same three audiences the SMS centre names.
     */
    #[Group('integration')]
    public function testSmsAudiencesAndCostsUseThePlatformsOwnRules(): void
    {
        $this->requireHttp();
        $all       = SMS::audience_phones(self::$booking, 'all');
        $upcountry = SMS::audience_phones(self::$booking, 'upcountry');
        $vip       = SMS::audience_phones(self::$booking, 'vip');

        self::assertLessThanOrEqual(count($all), count($upcountry), 'upcountry is a subset of everyone with a number');
        self::assertLessThanOrEqual(count($all), count($vip), 'vip is a subset too');
        self::assertSame(count($all), count(array_unique($all)), 'no number is counted twice');

        self::assertSame(1.0, SMS::calculate_cost('Habari! Your wedding is on Saturday.'), 'one segment is KSh 1');
        self::assertSame(2.0, SMS::calculate_cost(str_repeat('a', 161)), '161 characters is two segments');
        self::assertSame(3.0, SMS::calculate_cost(str_repeat('a', 480)), 'three segments at the tool ceiling');
    }

    /**
     * The SMS path, either way this install is configured — and BOTH branches
     * assert the same thing that matters: no message goes out on an unapproved
     * call, and an unaffordable one is refused before a planner is ever bothered.
     */
    #[Group('integration')]
    public function testASmsIsEitherAffordableEnoughToAskAboutOrRefusedWithTheNumbers(): void
    {
        $this->requireHttp();
        $audience = SMS::audience_phones(self::$booking, 'all');
        if ($audience === []) {
            self::markTestSkipped('this seeded wedding has no guest phone numbers to message');
        }
        $cost    = SMS::calculate_cost('x') * count($audience);
        $credits = SMS::get_credits(self::$adminId);
        $logsBefore = $this->smsLogCount(self::$booking);

        $out = self::tool($this->secret('writer'), 'send_bulk_sms', [
            'booking_id' => self::$booking,
            'audience'   => 'all',
            'message'    => 'Habari! This is a test message.',
        ]);

        if ($credits < $cost) {
            self::assertTrue($out['isError'], 'an unaffordable send is refused: ' . $out['text']);
            self::assertStringContainsString('credits', strtolower($out['text']), 'and the reason names the credits, with the cost');
        } else {
            self::assertFalse($out['isError'], $out['text']);
            self::assertSame('approval_required', $out['data']['status'] ?? '', 'an affordable send asks a planner first');
            self::assertSame(count($audience), (int) ($out['data']['recipients'] ?? 0), 'and the count is the audience the models resolved');
        }

        self::assertSame($logsBefore, $this->smsLogCount(self::$booking),
            'NOTHING is sent to anyone without an approval, affordable or not');
    }

    /**
     * v2.19: a request that expired unanswered is replaced, not silently
     * resurrected — and the retry SAYS which reference died.
     *
     * The v2.17 gate always minted a fresh `apr-` reference when the old one timed
     * out, which let a model wait forever on a reference that could never be
     * approved (and let a planner believe a request was still queued). The result
     * now carries `previous_request_expired` so the assistant can quote the new
     * reference and stop retrying the dead one.
     */
    #[Group('integration')]
    public function testARetryAfterAnExpiredRequestNamesTheDeadReference(): void
    {
        $this->requireHttp();
        $args = [
            'booking_id' => self::$booking,
            'event_id'   => self::$eventId,
            'amount'     => 4321.00,
            'method'     => 'cash',
        ];

        $first = self::tool($this->secret('writer'), 'record_payment', $args);
        self::assertSame('approval_required', $first['data']['status'] ?? '', $first['text']);
        $ref = (string) ($first['data']['reference'] ?? '');
        self::assertNotSame('', $ref);
        self::assertNull($first['data']['previous_request_expired'] ?? null, 'the first ask had nothing before it');

        // Silence past the 30-minute window, on the database's clock.
        self::$db->execute(
            'UPDATE ' . McpApprovals::TABLE . ' SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = :i',
            [':i' => (int) substr($ref, 4)]
        );

        $again = self::tool($this->secret('writer'), 'record_payment', $args);
        self::assertSame('approval_required', $again['data']['status'] ?? '', $again['text']);
        self::assertNotSame($ref, (string) ($again['data']['reference'] ?? ''), 'a NEW reference, not the dead one');
        self::assertSame($ref, (string) ($again['data']['previous_request_expired']['reference'] ?? ''),
            'the result names the request that died');
        self::assertSame('expired unanswered', (string) ($again['data']['previous_request_expired']['outcome'] ?? ''));
        self::assertStringContainsString('NEW request', (string) ($again['data']['previous_request_expired']['what_now'] ?? ''));
    }
}
