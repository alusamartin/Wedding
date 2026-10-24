<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The MCP endpoint (mcp.php) — the AI door, tested through the door itself.
 *
 * Everything here goes over real HTTP against a real `php -S` server and the
 * seeded database, because that is the interface an assistant uses: a JSON-RPC
 * message with a bearer token, in one of the two MCP eras, answered by the same
 * PHP that serves the pages. What is being proved, in order of how badly it
 * would hurt to get wrong:
 *
 *   A. IDENTITY — no token, a bad token, a revoked token and an expired token
 *      are all refused before any tool runs (401), and a browser Origin is never
 *      allowed to call it on a signed-in person's behalf (403).
 *   B. OWNERSHIP — a couple's token reaches its own wedding and NOT another's,
 *      and the refusal comes back as an `isError` result (readable, with the
 *      reason) rather than a wedding overview or a 500. The row rules the pages
 *      use are the row rules the AI gets.
 *   C. PROTOCOL — both eras answer correctly: the legacy initialize handshake,
 *      the current stateless shape (version in `_meta` AND the headers, agreement
 *      between them, `resultType: complete`), an unsupported version refused with
 *      the spec's own -32022 and its data payload, notifications answered 202
 *      with an empty body, GET refused 405.
 *   D. THE CATALOGUE — the tools advertised are exactly the tools that run: every
 *      tool `tools/list` returns for a super admin is called against real data
 *      and must answer without an internal error. (This is the assertion that
 *      caught a missing model include the first time the couple path ran.)
 *   E. THE RECORD — every call, including every refusal, lands in tblapi_audit
 *      with the tool, the scope, the outcome and the SHAPE of the arguments; and
 *      no argument VALUE is ever written there (a guest's phone number must not
 *      be duplicated into the log).
 *   F. THE BRAKE — the endpoint is behind the shared abuse guard on a door of its
 *      own (`mcp`), keyed by the token's HASH, so one runaway assistant cannot
 *      become a flood and a leaked token cannot be ground through the catalogue.
 *   G. DOCUMENTS — resources/list and resources/templates/list are filtered by
 *      scope; resources/read answers with the SAME data the equivalent tool
 *      returns (one query, two surfaces); a wedding this account may not open is
 *      reported EXACTLY as one that does not exist, in both protocol eras (-32602
 *      today, -32002 the older way), so the URI space cannot be walked; and a
 *      read carries the caching hints the current revision expects (private,
 *      never public).
 *   H. INSTRUCTIONS — prompts/list is filtered the same way; a prompt gathers its
 *      own context through the read tools (so the message carries real figures and
 *      not a shopping list of tool names); a required argument or a wedding this
 *      account cannot open fails the request with a readable -32602 rather than
 *      producing a brief built from nothing.
 *
 * Fixtures: tokens are minted through ApiToken::mint() with a `wpms-test-` name
 * prefix and deleted again (with their audit rows) in tearDownAfterClass, so a
 * run leaves the audit table exactly as it found it. Re-runs are safe: setup
 * sweeps any residue from an aborted earlier run first.
 */
final class McpEndpointTest extends TestCase
{
    /** Name prefix for every token this suite creates — the sweep key. */
    private const TEST_PREFIX = 'wpms-test-mcp-';

    /** The number of tool calls this suite is allowed to spend in total. */
    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;

    /** @var int[] */
    private static array $tokenIds = [];
    /** @var array<string,string> name => secret */
    private static array $secrets = [];
    private static int $adminId = 0;
    private static int $adminBooking = 0;
    private static int $ownAccount = 0;
    private static int $ownBooking = 0;
    private static int $foreignBooking = 0;

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

        // Hermetic: the endpoint never sends mail, but a notification written by
        // any tool must not try to. Same discipline as the other flow tests.
        putenv('WPMS_MAIL_ENABLED=');
        putenv('WPMS_SITE_URL=');

        self::bootServer();
        if (self::$base === null || self::$db === null) {
            return; // every test skips
        }
        if (!ApiToken::available()) {
            self::$base = null;
            return; // v2.16 migration not applied — skip
        }

        self::sweep();

        try {
            /*
             * '2' as a STRING: tblusers.access_level is enum('0','1','2') and
             * MySQL compares an enum to an integer by ORDINAL POSITION, so
             * `access_level = 2` silently selects every '1' (a planner).
             * JobWatch::operator_ids() documents the same trap.
             */
            self::$adminId      = (int) self::$db->fetchValue("SELECT id FROM tblusers WHERE access_level = '2' ORDER BY id LIMIT 1");
            self::$adminBooking = (int) self::$db->fetchValue('SELECT booking_id FROM tblweddingbook WHERE wedding_date >= CURDATE() ORDER BY booking_id LIMIT 1');
            $own = self::$db->fetchOne(
                'SELECT b.booking_id, b.user_id FROM tblweddingbook b
                   JOIN tblaccounts a ON a.user_id = b.user_id
                  WHERE b.wedding_date >= CURDATE()
               ORDER BY b.booking_id LIMIT 1'
            );
            self::$ownAccount   = (int) ($own->user_id ?? 0);
            self::$ownBooking   = (int) ($own->booking_id ?? 0);
            self::$foreignBooking = (int) self::$db->fetchValue(
                'SELECT booking_id FROM tblweddingbook WHERE user_id <> :u ORDER BY booking_id LIMIT 1',
                [':u' => self::$ownAccount]
            );
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if (self::$adminId <= 0 || self::$adminBooking <= 0 || self::$ownAccount <= 0 || self::$ownBooking <= 0 || self::$foreignBooking <= 0) {
            self::$base = null; // seed incomplete — skip
            return;
        }

        // The five principals this suite needs. A failure to mint (missing
        // tables mid-migration, a scope rule changed) must skip rather than fail:
        // the assertion is about the ENDPOINT, not about minting.
        try {
            // array_merge, not `+`: both lists are numeric-keyed, so a union
            // would silently drop admin:system (and the operator surface with it).
            self::mint('admin', ApiToken::KIND_USER, self::$adminId, array_merge(ApiToken::READ_SCOPES, ApiToken::ADMIN_SCOPES), null);
            self::mint('couple', ApiToken::KIND_ACCOUNT, self::$ownAccount, ApiToken::READ_SCOPES, null);
            self::mint('guests-only', ApiToken::KIND_USER, self::$adminId, ['read:guests'], null);
            /*
             * Its own token for the revocation test: PHPUnit runs tests in
             * declaration order, so a revoke of the shared 'guests-only' fixture
             * would leave the later catalogue test holding a dead token and fail
             * it for the wrong reason.
             */
            self::mint('revocable', ApiToken::KIND_USER, self::$adminId, ['read:guests'], null);
            self::mint('narrowed', ApiToken::KIND_USER, self::$adminId, ApiToken::READ_SCOPES, self::$adminBooking);
            self::mint('writer', ApiToken::KIND_USER, self::$adminId, ['read:weddings', 'write:tasks'], null);
        } catch (Throwable $e) {
            self::sweep();
            self::$base = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::sweep();
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    /** Mint one fixture token and remember its id. */
    private static function mint(string $name, string $kind, int $id, array $scopes, ?int $bookingId): void
    {
        $minted = ApiToken::mint($kind, $id, self::TEST_PREFIX . $name, $scopes, $bookingId, 1, null);
        self::$tokenIds[]              = (int) $minted['id'];
        self::$secrets[$name]          = (string) $minted['secret'];
    }

    /**
     * Remove this suite's tokens and every audit row they wrote. Deleting the
     * tokens first would orphan the audit rows (token_id has no FK on purpose —
     * a log that disappears with its subject is not a log), so the rows go by the
     * ids remembered above.
     */
    private static function sweep(): void
    {
        if (self::$db === null || !ApiToken::available()) {
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
                self::$db->execute('DELETE FROM ' . ApiToken::TABLE . ' WHERE id = :id', [':id' => $id]);
            }
            // Counters the brake test may have written, keyed by token hash.
            foreach (self::$secrets as $secret) {
                self::$db->execute(
                    'DELETE FROM tblpublic_throttle WHERE kind = :k AND subject = :s',
                    [':k' => PublicGuard::MCP, ':s' => PublicGuard::subjectHash($secret)]
                );
            }
            /*
             * This suite presents revoked, expired and unknown secrets on purpose,
             * and every one of those is now a recorded authentication failure under
             * `mcp:<token id>` / `mcp:unknown` (ApiToken::authenticate()). Those are
             * this suite's own counters — no human's email begins with `mcp:` — and
             * leaving a 15-minute lockout behind would judge the NEXT run for this
             * one's misses.
             */
            self::$db->execute("DELETE FROM tbllogin_throttle WHERE throttle_email LIKE 'mcp:%'");
        } catch (Throwable $e) {
            // Cleanup is best-effort; a leftover token is revoked by expiry in a day.
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_mcp_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_mcp_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 32000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            self::$serverProc = @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [
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

    /**
     * One JSON-RPC POST to /mcp.php.
     *
     * @param array<string,mixed>  $message the JSON-RPC message
     * @param array<string,string> $headers extra headers (Authorization is added when a secret is given)
     * @return array{status:int, body:string, headers:array<string,string>, json:mixed}
     */
    private static function rpc(mixed $message, ?string $secret = null, array $headers = []): array
    {
        $raw = is_string($message) ? $message : (string) json_encode($message);
        $header = 'Host: 127.0.0.1' . "\r\n" . "Content-Type: application/json\r\n";
        if ($secret !== null) {
            $header .= 'Authorization: Bearer ' . $secret . "\r\n";
        }
        foreach ($headers as $name => $value) {
            $header .= $name . ': ' . $value . "\r\n";
        }
        $header .= 'Content-Length: ' . strlen($raw) . "\r\n" . 'Connection: close';

        $ctx = stream_context_create(['http' => [
            'method'          => 'POST',
            'header'          => $header,
            'content'         => $raw,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 15,
        ]]);
        $body = @file_get_contents(self::$base . '/mcp.php', false, $ctx);
        $status = 0;
        $out = [];
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
                continue;
            }
            $pos = strpos($h, ':');
            if ($pos !== false) {
                $out[strtolower(trim(substr($h, 0, $pos)))] = trim(substr($h, $pos + 1));
            }
        }
        return [
            'status'  => $status,
            'body'    => (string) $body,
            'headers' => $out,
            'json'    => json_decode((string) $body, true),
        ];
    }

    /** A current-protocol (2026-07-28) request, headers and body agreeing. */
    private static function modern(string $method, string $secret, array $params = [], ?string $name = null): array
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => McpServer::VERSION];
        $headers = [
            'MCP-Protocol-Version' => McpServer::VERSION,
            'Mcp-Method'           => $method,
        ];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }
        return self::rpc(['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params], $secret, $headers);
    }

    /** Call a tool the legacy way (no era headers) and decode its payload. */
    private static function tool(string $secret, string $tool, array $args = []): array
    {
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => $args],
        ], $secret);
        $result = $res['json']['result'] ?? [];
        $text   = $result['content'][0]['text'] ?? '';
        return [
            'status'  => $res['status'],
            'isError' => (bool) ($result['isError'] ?? false),
            'text'    => (string) $text,
            'data'    => json_decode((string) $text, true),
        ];
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / seeded database / v2.16 token tables unavailable — MCP endpoint tests skipped');
        }
    }

    private function secret(string $name): string
    {
        $this->requireHttp();
        return self::$secrets[$name] ?? '';
    }

    // ==================================================================
    // A. Identity
    // ==================================================================

    #[Group('integration')]
    public function testGetIsRefusedWith405AndAllowPost(): void
    {
        $this->requireHttp();
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);
        @file_get_contents(self::$base . '/mcp.php', false, $ctx);
        $status = 0;
        $allow  = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Allow:') === 0) {
                $allow = trim(substr($h, 6));
            }
        }
        self::assertSame(405, $status, 'This server offers no SSE stream, so GET is a 405');
        self::assertSame('POST', $allow, 'A 405 must say what IS allowed');
    }

    #[Group('integration')]
    public function testNoTokenIsRefusedWith401(): void
    {
        $this->requireHttp();
        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertSame(401, $res['status']);
        self::assertSame(-32001, $res['json']['error']['code'] ?? null);
        self::assertStringContainsString('Bearer', $res['headers']['www-authenticate'] ?? '', 'A 401 must tell the client how to authenticate');
    }

    #[Group('integration')]
    public function testUnknownTokenIsRefusedWith401(): void
    {
        $this->requireHttp();
        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ApiToken::new_secret());
        self::assertSame(401, $res['status']);
        self::assertNull($res['json']['result'] ?? null, 'An unauthenticated call must not reach a tool');
    }

    #[Group('integration')]
    public function testRevokedTokenIsRefusedOnItsVeryNextCall(): void
    {
        $this->requireHttp();
        $secret = self::secret('revocable');
        self::assertFalse(self::tool($secret, 'whoami')['isError'], 'the fixture token works before the revoke');

        $id = (int) self::$db->fetchValue(
            'SELECT id FROM ' . ApiToken::TABLE . ' WHERE token_hash = :h LIMIT 1',
            [':h' => ApiToken::hash($secret)]
        );
        self::assertTrue(ApiToken::revoke($id, ApiToken::KIND_USER, self::$adminId), 'revoke() must find its own token');

        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $secret);
        self::assertSame(401, $res['status'], 'A revoked token is refused at once, without waiting for expiry');
    }

    #[Group('integration')]
    public function testExpiredTokenIsRefused(): void
    {
        $this->requireHttp();
        // Mint a throwaway token, then move its expiry into the past — the same
        // state the clock reaches, without waiting a day for it.
        $minted = ApiToken::mint(ApiToken::KIND_USER, self::$adminId, self::TEST_PREFIX . 'expired', ['read:weddings'], null, 1, null);
        self::$db->execute(
            'UPDATE ' . ApiToken::TABLE . ' SET expires_at = NOW() - INTERVAL 1 DAY WHERE id = :id',
            [':id' => (int) $minted['id']]
        );
        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], (string) $minted['secret']);
        self::assertSame(401, $res['status'], 'An expired token is refused');
        self::$db->execute('DELETE FROM ' . ApiToken::TABLE . ' WHERE id = :id', [':id' => (int) $minted['id']]);
    }

    #[Group('integration')]
    public function testForeignOriginIsRefusedAndTheSiteOriginIsAllowed(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');

        $bad = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $secret, ['Origin' => 'https://evil.example']);
        self::assertSame(403, $bad['status']);
        self::assertStringContainsString('Origin not allowed', $bad['body']);

        // The install's own origin is legitimate: a page on this platform may
        // call its own endpoint (nothing else is allowed to).
        $good = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $secret, ['Origin' => self::$base]);
        self::assertSame(200, $good['status']);
    }

    // ==================================================================
    // B. Ownership — the row rules are the pages' row rules
    // ==================================================================

    #[Group('integration')]
    public function testCoupleTokenReachesItsOwnWedding(): void
    {
        $this->requireHttp();
        $out = self::tool(self::secret('couple'), 'get_wedding', ['booking_id' => self::$ownBooking]);
        self::assertFalse($out['isError'], 'A couple must be able to read its own wedding: ' . $out['text']);
        self::assertSame(self::$ownBooking, (int) ($out['data']['wedding']['booking_id'] ?? 0));
    }

    #[Group('integration')]
    public function testCoupleTokenCannotOpenAnotherWedding(): void
    {
        $this->requireHttp();
        $out = self::tool(self::secret('couple'), 'get_wedding', ['booking_id' => self::$foreignBooking]);
        self::assertTrue($out['isError'], 'A booking id is a request, not permission');
        self::assertStringContainsString('cannot open wedding', $out['text']);

        // And no scrap of the other wedding may come back with the refusal.
        $foreignName = (string) self::$db->fetchValue(
            'SELECT CONCAT(bride, " & ", groom) FROM tblweddingbook WHERE booking_id = :b',
            [':b' => self::$foreignBooking]
        );
        if (trim($foreignName) !== ' & ') {
            self::assertStringNotContainsString(trim($foreignName), $out['text'], 'A refused call must leak nothing about the wedding it refused');
        }
    }

    #[Group('integration')]
    public function testCoupleTokenSeesOnlyItsOwnWeddingInTheList(): void
    {
        $this->requireHttp();
        $out = self::tool(self::secret('couple'), 'list_weddings', ['when' => 'all']);
        self::assertFalse($out['isError'], $out['text']);
        $ids = array_map(static fn($w) => (int) $w['booking_id'], $out['data']['weddings'] ?? []);
        self::assertContains(self::$ownBooking, $ids, 'The couple sees its own wedding');
        self::assertNotContains(self::$foreignBooking, $ids, 'and nobody else\'s');
    }

    #[Group('integration')]
    public function testNarrowedTokenRefusesEveryOtherWedding(): void
    {
        $this->requireHttp();
        $secret = self::secret('narrowed');
        self::assertFalse(self::tool($secret, 'get_wedding', ['booking_id' => self::$adminBooking])['isError'], 'The narrowed wedding is allowed');
        $out = self::tool($secret, 'get_wedding', ['booking_id' => self::$foreignBooking]);
        self::assertTrue($out['isError']);
        self::assertStringContainsString('narrowed to wedding #' . self::$adminBooking, $out['text'], 'The refusal names the token\'s own limit');
    }

    // ==================================================================
    // C. Protocol
    // ==================================================================

    #[Group('integration')]
    public function testLegacyInitializeHandshake(): void
    {
        $this->requireHttp();
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => new stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1'],
            ],
        ], self::secret('admin'));

        self::assertSame(200, $res['status']);
        self::assertSame('2025-06-18', $res['json']['result']['protocolVersion'] ?? null, 'A supported revision is agreed to');
        self::assertSame('everafter-planner', $res['json']['result']['serverInfo']['name'] ?? null);
        self::assertArrayHasKey('tools', $res['json']['result']['capabilities'] ?? []);
        self::assertArrayNotHasKey('resultType', $res['json']['result'], 'The legacy shape carries no resultType');
        self::assertStringContainsString('booking_id', $res['json']['result']['instructions'] ?? '', 'The handshake tells the model how this platform is addressed');

        // An unknown (older) revision is answered with one we do support, rather
        // than refused: that is what the legacy contract asks for.
        $old = self::rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']], self::secret('admin'));
        self::assertContains($old['json']['result']['protocolVersion'] ?? '', McpServer::LEGACY_VERSIONS);
    }

    #[Group('integration')]
    public function testNotificationIsAnsweredWithNoContent(): void
    {
        $this->requireHttp();
        $res = self::rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], self::secret('admin'));
        self::assertSame(202, $res['status'], 'A notification expects no answer at all');
        self::assertSame('', $res['body'], 'and must not get a body to parse');
    }

    #[Group('integration')]
    public function testCurrentProtocolAnswerCarriesResultType(): void
    {
        $this->requireHttp();
        $res = self::modern('tools/list', self::secret('admin'));
        self::assertSame(200, $res['status']);
        self::assertSame('complete', $res['json']['result']['resultType'] ?? null, 'The current protocol requires resultType');
        self::assertNotEmpty($res['json']['result']['tools'] ?? []);
    }

    #[Group('integration')]
    public function testCurrentProtocolRequiresAgreeingHeaders(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');

        // _meta but no headers: the client is not actually speaking this revision.
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => McpServer::VERSION]],
        ], $secret);
        self::assertSame(400, $res['status']);

        // Headers that disagree with the body: a rewriting proxy must not be obeyed.
        $mismatch = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'method'  => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => McpServer::VERSION]],
        ], $secret, ['MCP-Protocol-Version' => McpServer::VERSION, 'Mcp-Method' => 'tools/call']);
        self::assertSame(400, $mismatch['status']);
        self::assertStringContainsString('Mcp-Method', $mismatch['body']);

        // Mcp-Name is required for a call, and must name the same tool.
        $noName = self::modern('tools/call', $secret, ['name' => 'whoami', 'arguments' => []]);
        self::assertSame(400, $noName['status'], 'A current-protocol tools/call must carry Mcp-Name');

        $wrongName = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => ['name' => 'whoami', 'arguments' => [], '_meta' => ['io.modelcontextprotocol/protocolVersion' => McpServer::VERSION]],
        ], $secret, [
            'MCP-Protocol-Version' => McpServer::VERSION,
            'Mcp-Method'           => 'tools/call',
            'Mcp-Name'             => 'list_guests',
        ]);
        self::assertSame(400, $wrongName['status']);
        self::assertStringContainsString('Mcp-Name', $wrongName['body']);
    }

    #[Group('integration')]
    public function testUnsupportedProtocolVersionIsRefusedWithTheSpecsCode(): void
    {
        $this->requireHttp();
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1999-01-01']],
        ], self::secret('admin'), ['MCP-Protocol-Version' => '1999-01-01', 'Mcp-Method' => 'tools/list']);

        self::assertSame(400, $res['status']);
        self::assertSame(-32022, $res['json']['error']['code'] ?? null);
        self::assertSame('1999-01-01', $res['json']['error']['data']['requested'] ?? null, 'The error says what was asked for');
        self::assertContains(McpServer::VERSION, $res['json']['error']['data']['supported'] ?? [], 'and what IS supported');
    }

    #[Group('integration')]
    public function testUnknownMethodIsARpcErrorAndNotAToolResult(): void
    {
        $this->requireHttp();
        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'sampling/createMessage'], self::secret('admin'));
        self::assertSame(-32601, $res['json']['error']['code'] ?? null);

        $res = self::rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'wedding/erase'], self::secret('admin'));
        self::assertSame(-32601, $res['json']['error']['code'] ?? null);
    }

    #[Group('integration')]
    public function testMalformedBodyIsAParseErrorNotACrash(): void
    {
        $this->requireHttp();
        $res = self::rpc('{"jsonrpc": "2.0", "method":', self::secret('admin'));
        self::assertSame(-32700, $res['json']['error']['code'] ?? null);
    }

    // ==================================================================
    // D. The catalogue
    // ==================================================================

    #[Group('integration')]
    public function testCatalogueIsFilteredByTheTokensScopes(): void
    {
        $this->requireHttp();
        $guestOnly = self::tool(self::secret('guests-only'), 'whoami');
        self::assertFalse($guestOnly['isError']);

        $list = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], self::secret('guests-only'));
        $names = array_map(static fn($t) => (string) $t['name'], $list['json']['result']['tools'] ?? []);
        self::assertContains('list_guests', $names, 'The scope it holds is offered');
        self::assertNotContains('list_weddings', $names, 'A read:weddings tool is not even advertised without the scope');

        // Asking for it anyway is refused BEFORE any query runs.
        $refused = self::tool(self::secret('guests-only'), 'list_weddings', []);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('read:weddings', $refused['text'], 'The refusal names the scope that would have been needed');
    }

    #[Group('integration')]
    public function testEveryAdvertisedToolActuallyRuns(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');
        $list   = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $secret);
        $tools  = $list['json']['result']['tools'] ?? [];
        self::assertNotEmpty($tools, 'A super admin is offered the whole catalogue');

        // Arguments that are valid for any wedding-scoped tool. `search_vendors`
        // and `list_weddings` need none, and neither do the account-level reads
        // (my_subscription, list_plans — their subject is the token's own
        // account, and their schemas take no booking_id at all); every other tool
        // takes the booking id.
        $noBooking = ['whoami', 'list_weddings', 'search_vendors', 'my_subscription', 'list_plans'];
        foreach ($tools as $tool) {
            $name = (string) $tool['name'];
            $args = in_array($name, $noBooking, true) ? [] : ['booking_id' => self::$adminBooking];
            $out  = self::tool($secret, $name, $args);
            self::assertFalse(
                $out['isError'],
                'Advertised tool "' . $name . '" must run against real data — got: ' . $out['text']
            );
        }
    }

    /**
     * The filters that are more than one condition — regression guard for a bug
     * this suite found the moment the prompt layer started passing a county.
     *
     * Every statement below bound ONE value to a placeholder used two, three or
     * four times. On a connection with native prepares (this app's is: see
     * database.php, ATTR_EMULATE_PREPARES => false) that is not a style question —
     * it is "Invalid parameter number" at execute(), so the wedding search, the
     * guest search and any county-filtered vendor search all failed while the
     * unfiltered versions worked. A test that never passes a filter cannot see it.
     */
    #[Group('integration')]
    public function testMultiColumnSearchFiltersRunInsteadOfErroring(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');

        $guests = self::tool($secret, 'list_guests', ['booking_id' => self::$adminBooking, 'search' => '07', 'limit' => 3]);
        self::assertFalse($guests['isError'], 'A guest search must run: ' . $guests['text']);
        self::assertArrayHasKey('filtered_total', $guests['data'] ?? [], 'and still report how many matched');

        $weddings = self::tool($secret, 'list_weddings', ['when' => 'all', 'search' => 'a', 'limit' => 3]);
        self::assertFalse($weddings['isError'], 'A wedding search must run: ' . $weddings['text']);

        $vendors = self::tool($secret, 'search_vendors', ['category' => 'cater', 'county' => 'Nakuru', 'max_price' => 300000]);
        self::assertFalse($vendors['isError'], 'A category + county + price search must run: ' . $vendors['text']);
        self::assertGreaterThan(0, (int) ($vendors['data']['count'] ?? 0), 'and must still find the vendor those filters describe');

        // The same filter through the prompt that surfaced it.
        $shortlist = self::prompt($secret, 'vendor_shortlist', ['category' => 'cater', 'county' => 'Nakuru']);
        self::assertSame(200, $shortlist['status'], 'vendor_shortlist must assemble a county-filtered search: ' . $shortlist['body']);
        self::assertStringContainsString('--- search_vendors ---', (string) ($shortlist['json']['result']['messages'][0]['content']['text'] ?? ''));
    }

    #[Group('integration')]
    public function testUnknownToolIsAnInvalidCallNotABusinessRefusal(): void
    {
        $this->requireHttp();
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'drop_everything', 'arguments' => []],
        ], self::secret('admin'));
        self::assertSame(-32602, $res['json']['error']['code'] ?? null, 'A call naming a tool that does not exist is a malformed call');
        self::assertArrayNotHasKey('result', $res['json']);
    }

    #[Group('integration')]
    public function testWriteScopedTokenIsInertWhileTheWriteSwitchIsOff(): void
    {
        $this->requireHttp();
        $out = self::tool(self::secret('writer'), 'whoami');
        self::assertFalse($out['isError']);
        self::assertFalse(
            (bool) ($out['data']['can_change_data'] ?? true),
            'carrying a write scope must not mean writing when WPMS_MCP_WRITES is off'
        );
        // ...and the catalogue says so, rather than advertising a tool that can
        // only refuse.
        $list  = self::rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], self::secret('writer'));
        $names = array_map(static fn($t) => (string) $t['name'], $list['json']['result']['tools'] ?? []);
        self::assertSame([], array_filter($names, static fn(string $n): bool => str_starts_with($n, 'write')), 'Write tools are not advertised while writing is off');
    }

    // ==================================================================
    // G. Documents (resources) — the same answers, reached by URI
    // ==================================================================

    /** A current-protocol resources/read, headers and body agreeing on the URI. */
    private static function resource(string $secret, string $uri): array
    {
        return self::modern('resources/read', $secret, ['uri' => $uri], $uri);
    }

    #[Group('integration')]
    public function testTheHandshakeAdvertisesAllThreeSurfaces(): void
    {
        $this->requireHttp();
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => ['protocolVersion' => '2025-11-25'],
        ], self::secret('admin'));
        $caps = $res['json']['result']['capabilities'] ?? [];
        self::assertArrayHasKey('tools', $caps, 'The handshake still says what an assistant may ask for');
        self::assertArrayHasKey('resources', $caps, 'and that weddings are openable as documents');
        self::assertArrayHasKey('prompts', $caps, 'and that ready-made instructions exist');
        self::assertFalse((bool) ($caps['resources']['subscribe'] ?? true), 'A stateless server holds no subscription open');

        $discover = self::modern('server/discover', self::secret('admin'));
        self::assertArrayHasKey('prompts', $discover['json']['result']['capabilities'] ?? [], 'server/discover agrees with the handshake');
    }

    #[Group('integration')]
    public function testResourceListsAreFilteredByScope(): void
    {
        $this->requireHttp();
        $admin = self::secret('admin');

        $list = self::modern('resources/list', $admin);
        self::assertSame(200, $list['status'], $list['body']);
        $uris = array_map(static fn($r) => (string) $r['uri'], $list['json']['result']['resources'] ?? []);
        self::assertContains(McpResources::ME_URI, $uris, 'An assistant can always read who it is');
        self::assertContains(McpResources::WEDDINGS_URI, $uris);
        self::assertContains('wedding://' . self::$adminBooking . '/overview', $uris);

        $templates = array_map(
            static fn($t) => (string) $t['uriTemplate'],
            self::modern('resources/templates/list', $admin)['json']['result']['resourceTemplates'] ?? []
        );
        foreach (['overview', 'timeline', 'guests', 'tasks', 'budget', 'capacity', 'vendors', 'website'] as $view) {
            self::assertContains('wedding://{booking_id}/' . $view, $templates, 'Every view must be addressable, not just the 8 that exist');
        }

        // A token holding one read scope is offered exactly what that scope reaches.
        $guestOnly = self::secret('guests-only');
        $rows      = self::modern('resources/list', $guestOnly)['json']['result']['resources'] ?? [];
        self::assertSame(
            [McpResources::ME_URI],
            array_map(static fn($r) => (string) $r['uri'], $rows),
            'Browsing must not double as a map of weddings this token has no business with'
        );
        // Two, not one: the capacity document is read:guests as well, because the
        // question it answers is whether the GUEST plan still fits what was sold.
        $offered = array_map(
            static fn($t) => (string) $t['uriTemplate'],
            self::modern('resources/templates/list', $guestOnly)['json']['result']['resourceTemplates'] ?? []
        );
        self::assertSame(['wedding://{booking_id}/guests', 'wedding://{booking_id}/capacity'], $offered);
    }

    #[Group('integration')]
    public function testAWeddingDocumentIsTheSameAnswerAsTheToolBehindIt(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');
        $uri    = 'wedding://' . self::$adminBooking . '/overview';

        $res = self::resource($secret, $uri);
        self::assertSame(200, $res['status'], $res['body']);
        $contents = $res['json']['result']['contents'][0] ?? [];
        self::assertSame($uri, $contents['uri'] ?? null, 'The answer says which document it is');
        self::assertSame('application/json', $contents['mimeType'] ?? null);
        $viaResource = json_decode((string) ($contents['text'] ?? ''), true);

        $viaTool = self::tool($secret, 'get_wedding', ['booking_id' => self::$adminBooking]);
        self::assertFalse($viaTool['isError'], $viaTool['text']);
        self::assertEquals(
            $viaTool['data'],
            $viaResource,
            'A document and the tool behind it are one query with two doors — never two answers'
        );
    }

    #[Group('integration')]
    public function testAForeignWeddingIsReportedExactlyLikeOneThatDoesNotExist(): void
    {
        $this->requireHttp();
        $secret  = self::secret('couple');
        $foreign = self::resource($secret, 'wedding://' . self::$foreignBooking . '/overview');
        $ghost   = self::resource($secret, 'wedding://99999999/overview');

        self::assertArrayNotHasKey('result', $foreign['json'], 'A refused read is an error, not a document');
        self::assertSame(-32602, $foreign['json']['error']['code'] ?? null, 'The current revision has no separate resource-not-found code');

        // Same sentence, only the caller's own id echoed back: an assistant cannot
        // walk the id space to learn which weddings exist on this platform.
        $shape = '~^no such resource: wedding://\d+/overview$~';
        self::assertMatchesRegularExpression($shape, (string) ($foreign['json']['error']['message'] ?? ''));
        self::assertMatchesRegularExpression($shape, (string) ($ghost['json']['error']['message'] ?? ''));

        $bride = trim((string) self::$db->fetchValue(
            'SELECT bride FROM tblweddingbook WHERE booking_id = :b',
            [':b' => self::$foreignBooking]
        ));
        if ($bride !== '') {
            self::assertStringNotContainsString($bride, $foreign['body'], 'and nothing about the wedding it refused comes back with the refusal');
        }
    }

    #[Group('integration')]
    public function testALegacyClientGetsTheOldResourceNotFoundCode(): void
    {
        $this->requireHttp();
        $res = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 5,
            'method'  => 'resources/read',
            'params'  => ['uri' => 'wedding://' . self::$foreignBooking . '/budget'],
        ], self::secret('couple'));
        self::assertSame(-32002, $res['json']['error']['code'] ?? null, 'The older revision has its own code, and its clients recognise it');
        self::assertStringContainsString('no such resource', (string) ($res['json']['error']['message'] ?? ''));
        self::assertArrayNotHasKey('resultType', $res['json']['result'] ?? [], 'The older shape carries no current-era fields');
    }

    #[Group('integration')]
    public function testAResourceWhoseScopeTheTokenLacksSaysSoPlainly(): void
    {
        $this->requireHttp();
        $res = self::resource(self::secret('guests-only'), 'wedding://' . self::$adminBooking . '/budget');
        self::assertSame(-32602, $res['json']['error']['code'] ?? null);
        self::assertStringContainsString(
            'read:budget',
            (string) ($res['json']['error']['message'] ?? ''),
            'A refusal about the TOKEN can be fixed, so it is named — only ownership refusals are hidden'
        );
    }

    #[Group('integration')]
    public function testAnUnknownUriIsANotFoundAndNotACrash(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');
        foreach (['everafter://nonsense', 'wedding://' . self::$adminBooking . '/nginx', 'wedding://abc/overview'] as $uri) {
            $res = self::resource($secret, $uri);
            self::assertSame(-32602, $res['json']['error']['code'] ?? null, $uri . ' must be refused as an unknown resource: ' . $res['body']);
            self::assertStringContainsString('unknown resource uri', (string) ($res['json']['error']['message'] ?? ''));
        }
    }

    #[Group('integration')]
    public function testReadResultsCarryPrivateCachingHints(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');
        $results = [
            'resources/list'           => self::modern('resources/list', $secret)['json']['result'] ?? [],
            'resources/templates/list' => self::modern('resources/templates/list', $secret)['json']['result'] ?? [],
            'resources/read'           => self::resource($secret, 'wedding://' . self::$adminBooking . '/tasks')['json']['result'] ?? [],
            'prompts/list'             => self::modern('prompts/list', $secret)['json']['result'] ?? [],
            'prompts/get'              => self::prompt($secret, 'budget_watch', ['booking_id' => self::$adminBooking])['json']['result'] ?? [],
        ];
        foreach ($results as $method => $result) {
            self::assertGreaterThan(0, (int) ($result['ttlMs'] ?? 0), $method . ' must say how long its answer may be kept');
            self::assertSame('private', $result['cacheScope'] ?? null, $method . ' carries one account\'s data and must never be cacheable by a shared cache');
        }
    }

    // ==================================================================
    // H. Instructions (prompts) — expertise the server brings
    // ==================================================================

    /** A current-protocol prompts/get, headers and body agreeing on the name. */
    private static function prompt(string $secret, string $name, array $arguments): array
    {
        return self::modern('prompts/get', $secret, ['name' => $name, 'arguments' => $arguments], $name);
    }

    #[Group('integration')]
    public function testPromptsAreAdvertisedAndFilteredByScope(): void
    {
        $this->requireHttp();
        $admin = self::modern('prompts/list', self::secret('admin'));
        self::assertSame(200, $admin['status'], $admin['body']);
        $prompts = $admin['json']['result']['prompts'] ?? [];
        $names   = array_map(static fn($p) => (string) $p['name'], $prompts);
        foreach (['weekly_wedding_brief', 'rsvp_chase_list', 'budget_watch', 'vendor_shortlist'] as $expected) {
            self::assertContains($expected, $names, 'A prompt must be offered when the token can actually assemble it');
        }
        self::assertNotEmpty($prompts[0]['arguments'] ?? [], 'A prompt says what arguments it takes');

        $guestOnly = array_map(
            static fn($p) => (string) $p['name'],
            self::modern('prompts/list', self::secret('guests-only'))['json']['result']['prompts'] ?? []
        );
        self::assertSame(['rsvp_chase_list'], $guestOnly, 'A command that could only fail is not offered');
    }

    #[Group('integration')]
    public function testAPromptGathersItsOwnContextFromRealData(): void
    {
        $this->requireHttp();
        $res = self::prompt(self::secret('admin'), 'weekly_wedding_brief', ['booking_id' => (string) self::$adminBooking]);
        self::assertSame(200, $res['status'], $res['body']);

        $result  = $res['json']['result'] ?? [];
        $message = $result['messages'][0] ?? [];
        self::assertNotEmpty($result['description'] ?? '');
        self::assertSame('user', $message['role'] ?? null);
        self::assertSame('text', $message['content']['type'] ?? null);
        $text = (string) ($message['content']['text'] ?? '');

        // The FIGURES, not a list of tool names to go and call: the context was
        // gathered through the same read tools tools/call runs.
        self::assertStringContainsString('--- get_wedding ---', $text);
        self::assertStringContainsString('--- list_tasks (open only) ---', $text);
        self::assertStringContainsString('KSh', $text, 'the platform\'s own rules travel with the brief');
        $couple = trim((string) self::$db->fetchValue(
            'SELECT CONCAT(bride, " & ", groom) FROM tblweddingbook WHERE booking_id = :b',
            [':b' => self::$adminBooking]
        ));
        self::assertStringContainsString($couple, $text, 'The brief names the couple it is about');
        self::assertStringContainsString('SECTIONS', $text, 'and says what the brief must contain');
    }

    #[Group('integration')]
    public function testAPromptThatCannotGatherItsContextFailsHonestly(): void
    {
        $this->requireHttp();
        $missing = self::prompt(self::secret('admin'), 'rsvp_chase_list', []);
        self::assertSame(-32602, $missing['json']['error']['code'] ?? null);
        self::assertStringContainsString('booking_id is required', (string) ($missing['json']['error']['message'] ?? ''));

        $foreign = self::prompt(self::secret('couple'), 'budget_watch', ['booking_id' => (string) self::$foreignBooking]);
        self::assertSame(-32602, $foreign['json']['error']['code'] ?? null);
        self::assertStringContainsString(
            'cannot open wedding',
            (string) ($foreign['json']['error']['message'] ?? ''),
            'A brief assembled from silence is the one failure a user cannot detect, so it is never assembled'
        );

        $unknown = self::prompt(self::secret('admin'), 'make_it_up', []);
        self::assertSame(-32602, $unknown['json']['error']['code'] ?? null);
        self::assertStringContainsString('unknown prompt', (string) ($unknown['json']['error']['message'] ?? ''));
    }

    #[Group('integration')]
    public function testPromptsGetRequiresAnAgreeingMcpNameInBothEras(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');

        $noName = self::modern('prompts/get', $secret, ['name' => 'budget_watch', 'arguments' => ['booking_id' => self::$adminBooking]]);
        self::assertSame(400, $noName['status'], 'A current-protocol prompts/get must carry Mcp-Name');
        self::assertStringContainsString('Mcp-Name', $noName['body']);

        $wrong = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'prompts/get',
            'params'  => ['name' => 'budget_watch', 'arguments' => [], '_meta' => ['io.modelcontextprotocol/protocolVersion' => McpServer::VERSION]],
        ], $secret, [
            'MCP-Protocol-Version' => McpServer::VERSION,
            'Mcp-Method'           => 'prompts/get',
            'Mcp-Name'             => 'weekly_wedding_brief',
        ]);
        self::assertSame(400, $wrong['status']);
        self::assertStringContainsString('Mcp-Name', $wrong['body']);

        // For a resource read, Mcp-Name names the URI.
        $noUri = self::modern('resources/read', $secret, ['uri' => 'wedding://' . self::$adminBooking . '/tasks']);
        self::assertSame(400, $noUri['status'], 'A current-protocol resources/read must carry Mcp-Name naming the uri');

        // A legacy client gets the same prompt, without the current-era fields.
        $legacy = self::rpc([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'prompts/get',
            'params'  => ['name' => 'budget_watch', 'arguments' => ['booking_id' => self::$adminBooking]],
        ], $secret);
        self::assertSame(200, $legacy['status'], $legacy['body']);
        self::assertArrayNotHasKey('resultType', $legacy['json']['result'] ?? []);
        self::assertNotEmpty($legacy['json']['result']['messages'] ?? [], 'The prompt itself is the same in both eras');
    }

    #[Group('integration')]
    public function testResourceReadsAndPromptGetsAreAuditedByName(): void
    {
        $this->requireHttp();
        $secret  = self::secret('admin');
        $tokenId = (int) self::$db->fetchValue(
            'SELECT id FROM ' . ApiToken::TABLE . ' WHERE token_hash = :h LIMIT 1',
            [':h' => ApiToken::hash($secret)]
        );
        self::$db->execute('DELETE FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t', [':t' => $tokenId]);

        $uri = 'wedding://' . self::$adminBooking . '/timeline';
        self::assertSame(200, self::resource($secret, $uri)['status']);
        self::assertSame(200, self::prompt($secret, 'budget_watch', ['booking_id' => self::$adminBooking])['status']);

        $read = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t AND tool LIKE :like ORDER BY id DESC LIMIT 1',
            [':t' => $tokenId, ':like' => 'resources/read %']
        );
        self::assertNotNull($read, 'A document read is recorded');
        self::assertSame('resources/read ' . $uri, (string) $read->tool, 'and the row says WHICH document, not just that one was read');
        self::assertSame('read:weddings', (string) $read->scope, 'against the same scope the equivalent tool call would use');
        self::assertSame(1, (int) $read->ok);
        self::assertSame(self::$adminBooking, (int) $read->booking_id);
        self::assertStringContainsString('uri:', (string) $read->args_digest, 'the argument NAME is kept');

        $prompt = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t AND tool LIKE :like ORDER BY id DESC LIMIT 1',
            [':t' => $tokenId, ':like' => 'prompts/get %']
        );
        self::assertNotNull($prompt, 'A prompt is recorded by name');
        self::assertSame('prompts/get budget_watch', (string) $prompt->tool);
        self::assertSame('read:budget', (string) $prompt->scope, 'the scope the prompt gathered its context with');
        self::assertSame(1, (int) $prompt->ok);

        // A refused read is recorded too, with the reason it was refused.
        self::resource(self::secret('couple'), 'wedding://' . self::$foreignBooking . '/overview');
        $coupleId = (int) self::$db->fetchValue(
            'SELECT id FROM ' . ApiToken::TABLE . ' WHERE token_hash = :h LIMIT 1',
            [':h' => ApiToken::hash(self::secret('couple'))]
        );
        $refused = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t AND ok = 0 ORDER BY id DESC LIMIT 1',
            [':t' => $coupleId]
        );
        self::assertNotNull($refused, '"The AI tried to read a wedding it may not have" is what an operator wants to find later');
        self::assertStringContainsString('no such resource', (string) $refused->error);
    }

    // ==================================================================
    // E. The record
    // ==================================================================

    #[Group('integration')]
    public function testEveryCallLandsInTheAuditTrail(): void
    {
        $this->requireHttp();
        $secret = self::secret('admin');
        $tokenId = (int) self::$db->fetchValue(
            'SELECT id FROM ' . ApiToken::TABLE . ' WHERE token_hash = :h LIMIT 1',
            [':h' => ApiToken::hash($secret)]
        );

        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t', [':t' => $tokenId]);
        $out = self::tool($secret, 'list_tasks', ['booking_id' => self::$adminBooking, 'limit' => 5]);
        self::assertFalse($out['isError'], $out['text']);
        $after = (int) self::$db->fetchValue('SELECT COUNT(*) FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t', [':t' => $tokenId]);
        self::assertSame($before + 1, $after, 'One call writes exactly one audit row');

        $row = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t ORDER BY id DESC LIMIT 1',
            [':t' => $tokenId]
        );
        self::assertSame('list_tasks', (string) $row->tool);
        self::assertSame('read:tasks', (string) $row->scope, 'The audit says which scope the call used');
        self::assertSame(1, (int) $row->ok);
        self::assertNotEmpty((string) $row->args_digest, 'The SHAPE of the arguments is recorded');
    }

    #[Group('integration')]
    public function testRefusalsAreAuditedToo(): void
    {
        $this->requireHttp();
        $secret  = self::secret('couple');
        $tokenId = (int) self::$db->fetchValue(
            'SELECT id FROM ' . ApiToken::TABLE . ' WHERE token_hash = :h LIMIT 1',
            [':h' => ApiToken::hash($secret)]
        );

        self::tool($secret, 'get_wedding', ['booking_id' => self::$foreignBooking]);

        $row = self::$db->fetchOne(
            'SELECT * FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t ORDER BY id DESC LIMIT 1',
            [':t' => $tokenId]
        );
        self::assertSame('get_wedding', (string) $row->tool);
        self::assertSame(0, (int) $row->ok, '"The AI asked for something it may not have" is exactly what an operator wants to find later');
        self::assertStringContainsString('cannot open wedding', (string) $row->error);
    }

    #[Group('integration')]
    public function testNoArgumentValueIsEverWrittenToTheAuditTrail(): void
    {
        $this->requireHttp();
        $secret  = self::secret('admin');
        $tokenId = (int) self::$db->fetchValue(
            'SELECT id FROM ' . ApiToken::TABLE . ' WHERE token_hash = :h LIMIT 1',
            [':h' => ApiToken::hash($secret)]
        );
        self::$db->execute('DELETE FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t', [':t' => $tokenId]);

        // A search term that is a real phone number — the kind of value a guest
        // list search contains, and the kind that must never be duplicated here.
        $needle = '0712345678';
        self::tool($secret, 'list_guests', ['booking_id' => self::$adminBooking, 'search' => $needle, 'limit' => 3]);

        $rows = self::$db->fetchAll('SELECT tool, args_digest, error FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :t', [':t' => $tokenId]);
        self::assertNotEmpty($rows, 'the call was audited');
        foreach ($rows as $row) {
            $flat = (string) $row->tool . '|' . (string) $row->args_digest . '|' . (string) $row->error;
            self::assertStringNotContainsString($needle, $flat, 'Argument VALUES stay out of the audit table; only their names and sizes are kept');
            self::assertStringContainsString('search:', (string) $row->args_digest, 'the argument NAME is kept, so the call is still answerable');
        }
    }

    // ==================================================================
    // F. The brake
    // ==================================================================

    public function testTheMcpDoorIsRegisteredOnTheSharedGuard(): void
    {
        self::assertArrayHasKey(PublicGuard::MCP, PublicGuard::LIMITS, 'The MCP endpoint has its own counter');
        $limits = PublicGuard::LIMITS[PublicGuard::MCP];
        self::assertGreaterThan(PublicGuard::LIMITS[PublicGuard::RSVP]['subject'], $limits['subject'], 'An assistant is burstier than one RSVP answer, so its ceiling is higher');
        self::assertSame(3600, $limits['window']);

        $this->expectException(InvalidArgumentException::class);
        PublicGuard::attempt('not-a-door');
    }

    public function testTheMcpDoorRefusesPastItsCeiling(): void
    {
        if (self::$db === null || !ApiToken::available()) {
            self::markTestSkipped('live DB unavailable');
        }
        $envBefore = getenv('WPMS_LOGIN_THROTTLE_OFF');
        putenv('WPMS_LOGIN_THROTTLE_OFF'); // the suite disables the guard globally; this test needs it live
        $subject = PublicGuard::subjectHash(ApiToken::new_secret());
        try {
            $cap  = PublicGuard::LIMITS[PublicGuard::MCP]['subject'];
            $wait = 0;
            for ($i = 1; $i <= $cap; $i++) {
                $wait = PublicGuard::attempt(PublicGuard::MCP, $subject);
                if ($wait > 0) {
                    self::fail('The cap tripped early, at attempt ' . $i . ' of ' . $cap);
                }
            }
            self::assertGreaterThan(0, PublicGuard::attempt(PublicGuard::MCP, $subject), 'The ' . ($cap + 1) . 'th call in the window is refused');
            self::assertGreaterThan(0, PublicGuard::secondsRemaining(PublicGuard::MCP, $subject), 'and the caller is told how long to wait');
        } finally {
            // Both rows this test wrote: the token's, and the caller's (attempt()
            // counts the IP as well — an hour of cooldown left behind here would
            // be a surprise for whatever runs next against this door).
            self::$db->execute('DELETE FROM tblpublic_throttle WHERE kind = :k', [':k' => PublicGuard::MCP]);
            is_string($envBefore) ? putenv('WPMS_LOGIN_THROTTLE_OFF=' . $envBefore) : putenv('WPMS_LOGIN_THROTTLE_OFF');
        }
    }
}
