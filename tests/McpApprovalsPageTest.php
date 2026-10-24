<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Approvals page (admin/mcp_approvals.php) — the HUMAN half, driven through
 * a real browser session against a real `php -S` server.
 *
 * McpWriteToolsTest proves that a Tier-3 tool refuses without an approval and
 * that an approval is bound to one payload and spent once. What it cannot prove
 * is the part the design actually rests on: that the string exists only because a
 * signed-in PERSON asked for it, on a page no assistant can reach. So, in order:
 *
 *   1. THE GATE — signed out, the page is not readable at all (it bounces), and
 *      the words of the page never appear in the response.
 *   2. THE INBOX — a planner signed in with the platform's own login sees the
 *      waiting request, the reference the assistant was given, the sentence the
 *      SERVER wrote from the data, and an Approve button.
 *   3. CSRF — an approve POST with no `_token` is refused AND the request is
 *      still pending afterwards. (A page that minted on a forged POST would be an
 *      approval the planner never gave.)
 *   4. THE MINT — approving with a valid `_token` mints a string shaped like an
 *      approval, shows it exactly once, records `approved_at`, and a SECOND
 *      approve POST for the same request cannot mint a second string: the stored
 *      `approval_hash` is unchanged, so only one string for this request has ever
 *      existed.
 *
 * Fixtures: one token (name prefix `wpms-test-mcpage-`) and one pending approval
 * row for a real wedding, both created here, both deleted in tearDownAfterClass.
 * If the seeded staff credentials or the v2.17 table are unavailable, every test
 * skips rather than fails — the assertion is about the PAGE, not the seed.
 */
final class McpApprovalsPageTest extends TestCase
{
    private const STAFF_EMAIL = 'wafula@wpms.co.ke';
    private const STAFF_PASS  = 'Admin@2024!';
    private const TOKEN_PREFIX = 'wpms-test-mcpage-';

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;

    /** @var array<string, string> cookie jar for the signed-in session */
    private static array $jar = [];
    private static int $staffId = 0;
    private static int $booking = 0;
    private static int $plannerId = 0;
    private static int $tokenId = 0;
    private static string $tokenSecret = '';
    private static int $approvalId = 0;
    private static string $note = '';

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
            if (!class_exists('McpApprovals', false)) {
                require_once __DIR__ . '/../admin/include/McpApprovals.php';
            }
            if (!class_exists('McpWrites', false)) {
                require_once __DIR__ . '/../admin/include/McpWrites.php';
            }
            if (!class_exists('McpTools', false)) {
                require_once __DIR__ . '/../admin/include/McpTools.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        putenv('WPMS_MAIL_ENABLED=');
        putenv('WPMS_SITE_URL=');

        self::bootServer();
        if (self::$base === null || self::$db === null || !ApiToken::available() || !McpApprovals::available()) {
            self::$base = null;
            return;
        }

        try {
            self::$staffId = (int) self::$db->fetchValue(
                'SELECT id FROM tblusers WHERE email = :e LIMIT 1',
                [':e' => self::STAFF_EMAIL]
            );
            self::$booking = (int) self::$db->fetchValue(
                'SELECT booking_id FROM tblweddingbook ORDER BY booking_id LIMIT 1'
            );
            $planner = self::$db->fetchOne(
                "SELECT id FROM tblusers WHERE access_level IN ('1','2') ORDER BY id DESC LIMIT 1"
            );
            self::$plannerId = (int) ($planner->id ?? 0);
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if (self::$staffId <= 0 || self::$booking <= 0 || self::$plannerId <= 0) {
            self::$base = null; // seed incomplete — skip
            return;
        }

        self::sweep();
        if (!self::login()) {
            self::sweep();
            self::$base = null; // seeded staff credentials unavailable — skip
            return;
        }

        // A real waiting request, made the way the tool makes one: through
        // McpApprovals::request with the committed payload.
        try {
            $minted    = ApiToken::mint(ApiToken::KIND_USER, self::$staffId, self::TOKEN_PREFIX . 'writer',
                ['read:weddings', 'write:planners'], null, 1, null);
            self::$tokenId    = (int) $minted['id'];
            self::$tokenSecret = (string) $minted['secret'];
            $principal = ApiToken::authenticate((string) $minted['secret']);
            $payload   = McpWrites::committed('assign_planner', [
                'booking_id'   => self::$booking,
                'organizer_id' => self::$plannerId,
            ]);
            self::$note = '[page-test] set the planner of wedding #' . self::$booking
                . ' to staff account #' . self::$plannerId;
            $request = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
                self::$booking, $payload, self::$note);
            self::$approvalId = (int) $request['id'];
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

    /** Remove this suite's token, its audit rows and its approval row. */
    private static function sweep(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            $rows = self::$db->fetchAll(
                'SELECT id FROM ' . ApiToken::TABLE . ' WHERE name LIKE :p',
                [':p' => self::TOKEN_PREFIX . '%']
            );
            foreach ($rows as $row) {
                $id = (int) (is_array($row) ? $row['id'] : $row->id);
                self::$db->execute('DELETE FROM ' . ApiToken::AUDIT_TABLE . ' WHERE token_id = :id', [':id' => $id]);
                if (McpApprovals::available()) {
                    self::$db->execute('DELETE FROM ' . McpApprovals::TABLE . ' WHERE token_id = :id', [':id' => $id]);
                }
                self::$db->execute('DELETE FROM ' . ApiToken::TABLE . ' WHERE id = :id', [':id' => $id]);
            }
        } catch (Throwable $e) {
            // Best-effort cleanup.
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_ap_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_ap_err');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 30000 + random_int(0, 8999);
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
     * One request through the signed-in session's cookie jar.
     *
     * @param array<string,string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        $headers = '';
        foreach (self::$jar as $name => $value) {
            $headers .= $name . '=' . $value . '; ';
        }
        $options = [
            'method'          => $method,
            'ignore_errors'   => true,
            'timeout'         => 20,
            // NOT following redirects is the point of several assertions here: a
            // bounced request must be SEEN as a bounce, not chased into whatever
            // the landing page happens to be.
            'follow_location' => 0,
            'max_redirects'   => 0,
        ];
        if ($headers !== '') {
            $options['header'] = 'Cookie: ' . rtrim($headers, '; ');
        }
        if ($method === 'POST') {
            $options['header'] = ($options['header'] ?? '') . "\r\nContent-Type: application/x-www-form-urlencoded";
            $options['content'] = http_build_query($post);
        }
        $ctx      = stream_context_create(['http' => $options]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);

        $status = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq   = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    /**
     * Sign in as the seeded staff account, on a fresh cookie jar.
     *
     * The success test is the SIGN-IN REDIRECT, not the cookie: a failed login
     * still sets a session cookie, so an empty jar is not the question — where
     * login.php lands a super admin is (`dashboard.php`).
     */
    private static function login(): bool
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::$jar = [];
            return false;
        }
        $post = self::http('POST', '/login.php', [
            'input_email'    => self::STAFF_EMAIL,
            'input_password' => self::STAFF_PASS,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
        if ($post['status'] !== 302 || stripos($post['location'], 'dashboard') === false) {
            self::$jar = [];
            return false;
        }
        return true;
    }

    /** The CSRF token the page itself renders. */
    private static function pageToken(): string
    {
        $page = self::http('GET', '/admin/mcp_approvals.php');
        return preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)
            ? html_entity_decode($m[1], ENT_QUOTES)
            : '';
    }

    private function requirePage(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / seeded staff login / v2.17 approvals table unavailable — Approvals page tests skipped');
        }
    }

    private function approvalRow(): ?object
    {
        return self::$db->fetchOne(
            'SELECT * FROM ' . McpApprovals::TABLE . ' WHERE id = :id',
            [':id' => self::$approvalId]
        );
    }

    // ==================================================================
    // 1. The gate
    // ==================================================================

    #[Group('integration')]
    public function testThePageIsNotReadableWithoutASignedInStaffSession(): void
    {
        $this->requirePage();
        $saved = self::$jar;
        self::$jar = []; // signed out
        try {
            $res = self::http('GET', '/admin/mcp_approvals.php');
            self::assertNotSame(200, $res['status'], 'a signed-out visitor must not get the page');
            self::assertStringNotContainsString('AI Approvals', $res['body'], 'and must not get anything from it');
            self::assertStringNotContainsString('Approve this exact payload', $res['body']);
            self::assertDoesNotMatchRegularExpression('/(Fatal error|Parse error|Uncaught|Warning:)/i', $res['body'],
                'a refusal is a redirect, never PHP error text');
        } finally {
            self::$jar = $saved;
        }
    }

    // ==================================================================
    // 2. The inbox
    // ==================================================================

    #[Group('integration')]
    public function testASignedInPlannerSeesWhatIsWaitingWithTheServersOwnSentence(): void
    {
        $this->requirePage();
        $res = self::http('GET', '/admin/mcp_approvals.php');

        self::assertSame(200, $res['status'], 'the seeded super admin is exempt from the paywall and sees the page');
        self::assertStringContainsString('AI Approvals', $res['body']);
        self::assertStringContainsString(McpApprovals::reference(self::$approvalId), $res['body'],
            'the reference the assistant was given is the one on the page');
        self::assertStringContainsString('assign_planner', $res['body'], 'the tool is named for the human');
        self::assertStringContainsString('Tier 3', $res['body'], 'and so is the tier');
        self::assertStringContainsString(htmlspecialchars(self::$note, ENT_QUOTES), $res['body'],
            'the sentence a planner approves is the SERVER\'s, built from the data — not the model\'s prose');
        self::assertStringContainsString('Approve this exact payload', $res['body']);
        self::assertStringContainsString('Refuse', $res['body'], 'and refusing is as available as approving');
        self::assertDoesNotMatchRegularExpression('/(Fatal error|Parse error|Uncaught|Warning:)/i', $res['body']);
    }

    // ==================================================================
    // 3. CSRF, and 4. the mint
    // ==================================================================

    #[Group('integration')]
    public function testAnApprovePostWithoutACsrfTokenIsRefusedAndMintsNothing(): void
    {
        $this->requirePage();
        $res = self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) self::$approvalId,
            'action'      => 'approve',
        ]);

        self::assertNotSame(200, $res['status'], 'a POST with no CSRF token is refused');
        $row = $this->approvalRow();
        self::assertNotNull($row);
        self::assertNull($row->approved_at, 'and nothing was approved');
        self::assertNull($row->approval_hash, 'and no approval string exists for it');
    }

    #[Group('integration')]
    public function testApprovingMintsOneStringOnceAndRecordsTheDecision(): void
    {
        $this->requirePage();
        $token = self::pageToken();
        self::assertNotSame('', $token, 'the page renders its own CSRF token');

        $res = self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) self::$approvalId,
            'action'      => 'approve',
            '_token'      => $token,
        ]);
        self::assertSame(200, $res['status']);
        self::assertMatchesRegularExpression('/mcpap_' . self::$approvalId . '-\d+-[a-f0-9]{64}/', $res['body'],
            'approving prints an approval bound to THIS request, with its signature');
        self::assertStringContainsString('This is the only time it is shown', $res['body']);

        $row = $this->approvalRow();
        self::assertNotNull($row);
        self::assertNotNull($row->approved_at, 'the decision is recorded');
        self::assertSame(self::$staffId, (int) $row->approved_by, 'and attributed to the planner who made it');
        self::assertSame(64, strlen((string) $row->approval_hash), 'only the hash of the minted string is stored');
        $firstHash = (string) $row->approval_hash;

        // A second approve POST cannot mint a second string for the same request.
        $again = self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) self::$approvalId,
            'action'      => 'approve',
            '_token'      => $token,
        ]);
        self::assertNotSame(200, $again['status'], 'the request is no longer waiting, so the POST is bounced');
        $after = $this->approvalRow();
        self::assertSame($firstHash, (string) $after->approval_hash,
            'exactly one string has ever existed for this request');
        self::assertSame(0, McpApprovals::pending_count(), 'and nothing is left waiting');

        // The string the page printed is the one the tool will accept: it verifies
        // against the row's signature, and it is already spent-proof.
        preg_match('/mcpap_' . self::$approvalId . '-\d+-[a-f0-9]{64}/', $res['body'], $m);
        $printed = $m[0] ?? '';
        self::assertNotSame('', $printed);
        self::assertSame(self::$approvalId, McpApprovals::id_from($printed), 'the printed string names this request');
        self::assertSame(
            McpApprovals::payload_hash('assign_planner', McpWrites::committed('assign_planner', [
                'booking_id'   => self::$booking,
                'organizer_id' => self::$plannerId,
            ])),
            (string) $row->payload_hash,
            'and it is bound to the payload the planner read, not to whatever a caller sends later'
        );
    }

    /**
     * The page and the endpoint are two PHP processes, and they must derive the
     * SAME approval key. They once did not: admin/include/init.php defines
     * SITE_ROOT and mcp.php does not, so the old fallback hashed a different
     * install path in each process and every approval minted on the page failed
     * at the endpoint with "does not verify". This test mints in a child process
     * that defines SITE_ROOT exactly as init.php does, then CONSUMES that string
     * here — the headless context — which is the only way to see the mismatch.
     */
    #[Group('integration')]
    public function testAnApprovalMintedByThePageVerifiesAtTheEndpoint(): void
    {
        $this->requirePage();
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable — cannot mint in a separate process');
        }

        $principal = ApiToken::authenticate(self::$tokenSecret);
        self::assertNotNull($principal, "the suite's token still authenticates");

        $payload = McpWrites::committed('assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);
        $request = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
            self::$booking, $payload, '[page-test] mint-context check');
        $id = (int) $request['id'];

        $code = 'define("SITE_ROOT", ' . var_export(realpath(__DIR__ . '/../admin'), true) . ');'
            . 'require ' . var_export(__DIR__ . '/../admin/include/models.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../admin/include/ApiToken.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../admin/include/McpApprovals.php', true) . ';'
            . '$GLOBALS["db"] = new Database();'
            . '$m = McpApprovals::mint(' . $id . ', ' . self::$staffId . ');'
            . 'echo $m["token"];';

        $proc = @proc_open([PHP_BINARY, '-r', $code], [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($proc, 'a PHP child process could be started');

        $printed = trim((string) stream_get_contents($pipes[1]));
        $stderr  = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        self::assertMatchesRegularExpression(
            '/^mcpap_' . $id . '-\d+-[a-f0-9]{64}$/',
            $printed,
            'the page-context process minted a string: ' . $stderr
        );

        $row = McpApprovals::consume($printed, $principal, 'assign_planner', $payload);
        self::assertSame($id, (int) $row->id, 'and the endpoint process accepts exactly that string');
    }

    /**
     * One click on an expired row asks the SAME question again — a new reference,
     * a fresh window, the old row left expired — and the assistant's identical
     * retry lands on the new request instead of minting yet another one.
     */
    #[Group('integration')]
    public function testAnExpiredRequestCanBeReRequestedWithoutTheAssistantAskingAgain(): void
    {
        $this->requirePage();
        $principal = ApiToken::authenticate(self::$tokenSecret);
        self::assertNotNull($principal, "the suite's token still authenticates");

        // A real expired request, made the way the tool makes one.
        $payload = McpWrites::committed('assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$staffId,
        ]);
        $request = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
            self::$booking, $payload, '[page-test] re-request probe');
        $oldId = (int) $request['id'];
        self::$db->execute(
            'UPDATE ' . McpApprovals::TABLE . ' SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE id = :i',
            [':i' => $oldId]
        );
        self::assertSame('expired', McpApprovals::state_of(McpApprovals::find($oldId)));

        // CSRF: the same rule as Approve and Refuse. No token, no new request.
        $bad = self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) $oldId,
            'action'      => 're_request',
        ]);
        self::assertNotSame(200, $bad['status']);
        self::assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM ' . McpApprovals::TABLE . ' WHERE re_request_of = :o',
            [':o' => $oldId]
        ), 'nothing was revived by a forged POST');

        $token = self::pageToken();
        self::assertNotSame('', $token);
        $res = self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) $oldId,
            'action'      => 're_request',
            '_token'      => $token,
        ]);
        self::assertSame(302, $res['status'], 'the click redirects back to the inbox');

        $row = self::$db->fetchOne(
            'SELECT * FROM ' . McpApprovals::TABLE . ' WHERE re_request_of = :o ORDER BY id DESC LIMIT 1',
            [':o' => $oldId]
        );
        self::assertNotNull($row, 'the click created a new request row');
        $newId = (int) $row->id;
        self::assertNotSame($oldId, $newId, 'it is a NEW reference, not the old one reopened');
        self::assertSame((string) self::$staffId, (string) $row->re_requested_by);
        self::assertSame('assign_planner', (string) $row->tool);
        self::assertSame(self::$booking, (int) $row->booking_id);
        self::assertSame(
            McpApprovals::payload_hash('assign_planner', $payload),
            (string) $row->payload_hash,
            'the revived request is bound to the SAME payload the planner read'
        );
        self::assertSame('pending', McpApprovals::state_of(McpApprovals::find($newId)));
        self::assertGreaterThan(time(), strtotime((string) $row->expires_at), 'it has a fresh 30-minute window');

        // The old row keeps its true history: it expired, and it was not reopened.
        $old = McpApprovals::find($oldId);
        self::assertNotNull($old);
        self::assertSame('expired', McpApprovals::state_of($old));
        self::assertNull($old->approved_at);
        self::assertNull($old->consumed_at);

        // The inbox names the revived reference and where it came from.
        $page = self::http('GET', '/admin/mcp_approvals.php');
        self::assertStringContainsString(McpApprovals::reference($newId), $page['body']);
        self::assertStringContainsString('re-requested from ' . McpApprovals::reference($oldId), $page['body']);
        self::assertStringContainsString('revived as ' . McpApprovals::reference($newId), $page['body'],
            'the expired row links forward to what replaced it');
        self::assertStringContainsString('Re-request this payload', $page['body'], 'the expired row offers the button');

        // An assistant retry of the identical call now finds the revived request:
        // it does NOT create a third reference and does not wait on the dead one.
        $again = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
            self::$booking, $payload, 'ignored');
        self::assertSame($newId, (int) $again['id']);
        self::assertTrue($again['reused']);

        // A double-click is one question, not two pending rows.
        self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) $oldId,
            'action'      => 're_request',
            '_token'      => $token,
        ]);
        self::assertSame(1, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM ' . McpApprovals::TABLE . ' WHERE re_request_of = :o',
            [':o' => $oldId]
        ), 'the second click returns the same revived request');

        // Only an expired row can be revived; a live one would be a second question
        // for a payload already waiting.
        try {
            McpApprovals::revive($newId, self::$staffId);
            self::fail('a pending request must not be re-requested');
        } catch (McpApprovalError $e) {
            self::assertStringContainsString('only an expired request', $e->getMessage());
        }
    }

    /**
     * Reviving is refused when the asking token is gone: the new approval could
     * only ever be spent by that token, so a fresh request would be a decision
     * nobody can carry out.
     */
    /**
     * The OTHER kind of dead reference: the planner DID approve it, and the
     * assistant never used the string before its 15-minute window closed. It is
     * just as revivable as an unanswered one.
     */
    #[Group('integration')]
    public function testAnApprovedButUnusedRequestCanAlsoBeReRequested(): void
    {
        $this->requirePage();
        $principal = ApiToken::authenticate(self::$tokenSecret);
        self::assertNotNull($principal);
        $payload = McpWrites::committed('assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);
        $request = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
            self::$booking, $payload, '[page-test] approved-unused probe');
        $oldId = (int) $request['id'];
        McpApprovals::mint($oldId, self::$staffId);
        self::$db->execute(
            'UPDATE ' . McpApprovals::TABLE . ' SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE id = :i',
            [':i' => $oldId]
        );
        $old = McpApprovals::find($oldId);
        self::assertSame('expired', McpApprovals::state_of($old));
        self::assertNotEmpty($old->approved_at, 'it was approved — and the string was never used');

        $page = self::http('GET', '/admin/mcp_approvals.php');
        self::assertStringContainsString('Expired unused', $page['body'], 'the state badge names the actual death');
        self::assertStringContainsString('Re-request this payload', $page['body'], 'an unused approval can be revived too');

        $token = self::pageToken();
        $res = self::http('POST', '/admin/mcp_approvals.php', [
            'approval_id' => (string) $oldId,
            'action'      => 're_request',
            '_token'      => $token,
        ]);
        self::assertSame(302, $res['status']);

        $new = self::$db->fetchOne(
            'SELECT * FROM ' . McpApprovals::TABLE . ' WHERE re_request_of = :o ORDER BY id DESC LIMIT 1',
            [':o' => $oldId]
        );
        self::assertNotNull($new);
        self::assertNull($new->approved_at, 'the revived request is UNANSWERED: the planner decides again');
        self::assertSame('pending', McpApprovals::state_of(McpApprovals::find((int) $new->id)));
    }

    #[Group('integration')]
    public function testReviveRefusesARequestWhoseTokenHasBeenRevoked(): void
    {
        $this->requirePage();
        $minted = ApiToken::mint(ApiToken::KIND_USER, self::$staffId,
            self::TOKEN_PREFIX . 'revoked-' . bin2hex(random_bytes(4)),
            ['read:weddings'], null, 1, null);
        $tokenId = (int) $minted['id'];
        $principal = ApiToken::authenticate((string) $minted['secret']);
        self::assertNotNull($principal);

        $payload = McpWrites::committed('assign_planner', [
            'booking_id'   => self::$booking,
            'organizer_id' => self::$plannerId,
        ]);
        $request = McpApprovals::request($principal, 'assign_planner', McpWrites::TIER_APPROVAL,
            self::$booking, $payload, '[page-test] revoked-token probe');
        $oldId = (int) $request['id'];
        self::$db->execute(
            'UPDATE ' . McpApprovals::TABLE . ' SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE id = :i',
            [':i' => $oldId]
        );
        self::assertTrue(ApiToken::revoke($tokenId, ApiToken::KIND_USER, self::$staffId));

        try {
            McpApprovals::revive($oldId, self::$staffId);
            self::fail('a request whose token is revoked must not be revived');
        } catch (McpApprovalError $e) {
            self::assertStringContainsString('no longer active', $e->getMessage());
        }
        self::assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM ' . McpApprovals::TABLE . ' WHERE re_request_of = :o',
            [':o' => $oldId]
        ), 'no pending request was created');
    }
}
