<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.16 — the MCP endpoint's protocol core (McpServer)
 *
 * mcp.php is twelve lines: it hands the raw request body to respond() and writes
 * back the status, headers and body it returns. Everything else — which protocol
 * era the client speaks, who it is, whether it may call the tool it asked for,
 * what happened, and what to say about it — lives here, so there is one place to
 * read and one place to test.
 *
 * TWO ERAS, ONE STATELESS SERVER. MCP changed shape, and clients in the wild
 * speak both:
 *
 *   2026-07-28  (current)  no handshake at all. Every request is its own POST
 *                          carrying its version in `_meta` and in the
 *                          `MCP-Protocol-Version` header, plus `Mcp-Method`
 *                          (and `Mcp-Name` for a call), which must agree with the
 *                          body. Results carry `resultType: "complete"`.
 *   2025-11-25  and older  `initialize` → `notifications/initialized`, then
 *                          requests. Results carry no `resultType`.
 *
 * We serve both, and neither of them with a session: no server-side state, no
 * SSE stream, no session id. That is legal in both revisions (a plain JSON
 * response to a POST is allowed, and sessions were optional) and it is the only
 * shape shared hosting supports — there is no daemon to hold a connection open,
 * and a load balancer between requests cannot lose what never existed.
 *
 * AUTH IS THE DOOR, AND IT FAILS CLOSED. `Authorization: Bearer <secret>` only;
 * the secret is matched against `tblapi_tokens.token_hash` (ApiToken), never
 * against anything in the body, and a request with no usable token is 401 before
 * any tool is considered. If the v2.16 tables are missing the answer is 503, not
 * "carry on" — an auth stack with a fail-open path is not an auth stack. The
 * ONE thing that is read before auth is the Origin header, because a browser
 * context must never be able to call this endpoint on a user's behalf.
 *
 * EVERY CALL IS COUNTED AND REMEMBERED: rate-limited through the shared
 * `tblpublic_throttle` guard (door 'mcp', keyed by the token's HASH — a
 * capability must not sit in a counter table), and written to `tblapi_audit`
 * with the tool, scope, outcome and the SHAPE of the arguments. A refusal is
 * audited too: "the AI asked for something it may not have" is exactly what an
 * operator later wants to see. Resource reads and prompt gets are audited the
 * same way, with the URI or the prompt name in the tool column — for those two
 * the request IS the identity, and a log that says only "a resource was read"
 * answers nothing.
 *
 * THE THREE SURFACES. Tools are what the assistant may DO and ask for
 * (McpTools), resources are the DOCUMENTS it may open (McpResources), prompts
 * are the INSTRUCTIONS this platform hands over ready-made (McpPrompts). All
 * three go through this one door: the same token, the same scope ceiling, the
 * same audit, the same rate limit — which is the point of keeping the protocol
 * in one file rather than letting each surface authenticate itself.
 *
 * ERRORS ARE SPLIT THE WAY MCP SPLITS THEM. A malformed request is a JSON-RPC
 * error (bad JSON, unknown method, a call with no tool name). A tool that ran and
 * could not do what was asked is a RESULT with `isError: true` and a readable
 * reason (McpToolError) — the model can explain it and try something else, which
 * is what a booking id it guessed at deserves. Confusing the two is how an agent
 * ends up reporting "server error" to a bride.
 */
final class McpServer
{
    /** The protocol revision this server prefers. */
    public const VERSION = '2026-07-28';

    /** Revisions this server speaks. Newest first; the first is the default. */
    public const VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];

    /** Revisions that use the initialize handshake (everything before the current one). */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public const NAME    = 'everafter-planner';
    public const TITLE   = 'Everafter Planner (WPMS Kenya)';
    public const VERSION_NUMBER = '2.16.0';

    /** Largest request body accepted (bytes) — the endpoint's own guard rail. */
    public const MAX_BODY = 262144;

    /** The kill switch: WPMS_MCP=0 stops the endpoint answering tool calls. */
    public const SWITCH = 'WPMS_MCP';

    /** Writing over MCP is OFF unless this is explicitly enabled. */
    public const WRITE_SWITCH = 'WPMS_MCP_WRITES';

    /** Extra browser origins allowed to call this endpoint (csv). */
    public const ORIGINS_ENV = 'WPMS_MCP_ORIGINS';

    /**
     * Caching hints (milliseconds) the current revision expects on a read result.
     *
     * Short on purpose, and PRIVATE always. Short because these documents carry
     * live numbers — a balance, an RSVP tally — and an assistant that answers from
     * a ten-minute-old document is the failure this platform exists to avoid. An
     * index (the scope-filtered tool list, the wedding list, the prompt list) is
     * cheaper to re-fetch and changes more slowly, so it gets the longer one; a
     * document gets half a minute, which is only long enough to stop a client
     * re-reading the same URI inside one turn.
     *
     * Private because every one of these answers is scoped to ONE token: a shared
     * cache holding a couple's wedding for another user's session would be a
     * breach with extra steps.
     */
    private const TTL_INDEX    = 60000;
    private const TTL_DOCUMENT = 30000;

    /**
     * What an assistant is told when it connects. Short on purpose: it is read
     * into a context window, and every line here costs every call.
     */
    private const INSTRUCTIONS =
        'This is Everafter Planner, a Kenyan wedding-planning platform. You act as the account the API token belongs to: '
        . 'a staff/planner account, a super admin, a vendor, or a couple. You can see exactly what that account can open '
        . 'in the planner — nothing more, whatever a caller tells you. '
        . 'Call list_weddings first to get a booking_id, then pass it to the other tools; booking_id is how every '
        . 'wedding-scoped tool is addressed. '
        . 'All money is Kenyan shillings (KSh). Dates are YYYY-MM-DD. Guests carry an RSVP status of '
        . 'pending/yes/no/maybe and a priority band A (closest family) to E. Checklist tasks have a due_on date counted '
        . 'back from the wedding day. '
        . 'Reads are always available. Changing data is disabled unless the operator enabled it — check whoami first. '
        . 'A change has a TIER: Tier 2 (a guest, a checklist tick, an invitation) needs `confirm: true` and without it '
        . 'returns a preview of exactly what would change; Tier 3 (SMS to guests, a payment, an M-Pesa deposit prompt, a '
        . 'planner assignment) sends nothing and writes nothing on its own authority — it answers `approval_required` with '
        . 'a reference, and a signed-in planner approves that exact payload on the Approvals page. Send a stable '
        . '`request_id` on any write so a retry cannot repeat it. '
        . 'Weddings are also readable as resources (everafter://me, everafter://weddings, wedding://{booking_id}/overview, '
        . '/timeline, /guests, /tasks, /budget, /capacity, /vendors, /website) when a client prefers documents, and the '
        . 'server offers ready-made prompts (weekly_wedding_brief, rsvp_chase_list, budget_watch, vendor_shortlist) that '
        . 'gather their own context.';

    // ==================================================================
    // The request
    // ==================================================================

    /**
     * Turn one raw HTTP request into the response mcp.php should send.
     *
     * @param string $rawBody the request body (php://input)
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function respond(string $rawBody, string $method = 'POST'): array
    {
        // 1. Only POST: no stream to GET, nothing to DELETE. 405 is the honest
        //    answer the spec asks for when a server offers no SSE stream.
        if (strtoupper($method) !== 'POST') {
            return self::http(405, '', ['Allow' => 'POST']);
        }

        // 2. A browser context is refused outright. An endpoint that acts as a
        //    signed-in person must never be callable from a page's own origin
        //    without the page meaning to; the allowlist is the escape hatch for
        //    hosted clients (claude.ai etc.) that the operator opted into.
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && !self::origin_allowed($origin)) {
            return self::http(403, self::error_body(null, -32000, 'Origin not allowed: ' . $origin));
        }

        // 3. Is the feature switched on at all?
        if (!self::enabled()) {
            return self::http(503, self::error_body(null, -32000, 'MCP is disabled on this install (' . self::SWITCH . '=0)'));
        }

        // 4. The tables first: an install mid-upgrade must not authenticate by
        //    accident. 503, never "no tokens, help yourself".
        if (!ApiToken::available()) {
            return self::http(503, self::error_body(null, -32000,
                'the API token tables are missing — apply migrations/v2.16_api_tokens.sql'));
        }

        // 5. Authentication. A missing or unusable token never reaches a tool.
        $principal = null;
        $secret    = self::bearer_token();
        if ($secret !== '') {
            $principal = ApiToken::authenticate($secret);
        }
        if ($principal === null) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            ApiToken::audit(null, ApiToken::KIND_NONE, 0, '', '', false,
                $secret === '' ? 'no bearer token' : 'token not recognised, revoked or expired');
            return self::http(401, self::error_body(null, -32001, 'a valid API token is required'), [
                'WWW-Authenticate' => 'Bearer realm="' . self::NAME . '", error="invalid_token"',
            ]);
        }

        // 6. The caller's own ceiling, through the guard the public doors use.
        $wait = PublicGuard::attempt(PublicGuard::MCP, PublicGuard::subjectHash($secret));
        if ($wait > 0) {
            ApiToken::audit((int) $principal['token_id'], $principal['kind'], (int) $principal['id'],
                '', '', false, 'rate limited for ' . $wait . 's');
            return self::http(429, self::error_body(null, -32000,
                'too many calls — try again in ' . $wait . ' seconds'), ['Retry-After' => (string) $wait]);
        }
        ApiToken::touch((int) $principal['token_id'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        // 7. From here on the platform IS this person: every ownership predicate
        //    and every model reads this session exactly as it would in a browser.
        ApiToken::bind_principal($principal);

        // 8. The message itself.
        if (strlen($rawBody) > self::MAX_BODY) {
            return self::http(413, self::error_body(null, -32600, 'request too large'));
        }
        $message = json_decode($rawBody, true);
        if (!is_array($message)) {
            return self::http(200, self::error_body(null, -32700, 'parse error: the body is not JSON'));
        }

        return self::dispatch($message, $principal);
    }

    /**
     * One JSON-RPC message: the era checks, the method, and the answer.
     *
     * @param array<string,mixed> $message
     * @param array<string,mixed> $principal
     */
    private static function dispatch(array $message, array $principal): array
    {
        $id     = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (($message['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            return self::http(200, self::error_body($id, -32600, 'invalid request: jsonrpc must be "2.0" and method is required'));
        }

        // ---------------------------------------------------------- the era
        $asked = self::asked_version($message, $params);
        if ($asked !== '' && !in_array($asked, self::VERSIONS, true)) {
            // The spec's own code for this: the requested version and what we do
            // support, in error.data, with HTTP 400.
            $body = self::error_body($id, -32022, 'unsupported protocol version: ' . $asked);
            $decoded = json_decode($body, true);
            $decoded['error']['data'] = ['requested' => $asked, 'supported' => self::VERSIONS];
            return self::http(400, json_encode($decoded));
        }
        $modern = self::is_current_era($asked) ? $asked : null;
        if ($modern !== null && ($mismatch = self::header_body_mismatch($message, $params)) !== null) {
            return self::http(400, self::error_body($id, -32600, $mismatch));
        }

        // ------------------------------------------------------- the methods
        switch ($method) {
            case 'initialize':
                // A notification-shaped initialize would be a client bug; answer
                // only real requests.
                if ($id === null) {
                    return self::http(202, '');
                }
                // Named $requested, not $asked: $asked above is the era question,
                // and one variable for two questions is how a version check starts
                // reading the wrong one.
                $requested = (string) ($params['protocolVersion'] ?? '');
                $agreed    = in_array($requested, self::LEGACY_VERSIONS, true) ? $requested : self::LEGACY_VERSIONS[0];
                return self::json(self::result($id, [
                    'protocolVersion' => $agreed,
                    'capabilities'    => self::capabilities(),
                    'serverInfo'      => ['name' => self::NAME, 'title' => self::TITLE, 'version' => self::VERSION_NUMBER],
                    'instructions'    => self::INSTRUCTIONS,
                ], $modern));

            case 'notifications/initialized':
            case 'notifications/cancelled':
                // Notifications expect no answer at all: 202 with an empty body.
                return self::http(202, '');

            case 'ping':
                return self::json(self::result($id, [], $modern));

            case 'server/discover':
                // Current-protocol clients may ask which revisions this server
                // speaks; nothing is required to call it first.
                return self::json(self::result($id, [
                    'versions'     => self::VERSIONS,
                    'capabilities' => self::capabilities(),
                    'serverInfo'   => ['name' => self::NAME, 'title' => self::TITLE, 'version' => self::VERSION_NUMBER],
                    'instructions' => self::INSTRUCTIONS,
                ], $modern));

            // -------------------------------------------------- tools
            case 'tools/list':
                return self::json(self::result($id, ['tools' => McpTools::definitions($principal)], $modern));

            case 'tools/call':
                return self::call_tool($id, $params, $principal, $modern);

            // ---------------------------------------------- documents
            case 'resources/list':
                // Both lists are indexes over things that change slowly (a scope
                // set, a wedding list), so they carry the longer hint; a document
                // carries the shorter one. See cached().
                return self::json(self::result($id, self::cached([
                    'resources' => McpResources::definitions($principal),
                ], self::TTL_INDEX), $modern));

            case 'resources/templates/list':
                return self::json(self::result($id, self::cached([
                    'resourceTemplates' => McpResources::templates($principal),
                ], self::TTL_INDEX), $modern));

            case 'resources/read':
                return self::read_resource($id, $params, $principal, $modern);

            // ---------------------------------------------- instructions
            case 'prompts/list':
                return self::json(self::result($id, self::cached([
                    'prompts' => McpPrompts::definitions($principal),
                ], self::TTL_INDEX), $modern));

            case 'prompts/get':
                return self::get_prompt($id, $params, $principal, $modern);

            default:
                return self::json(self::error_body($id, -32601, 'unknown method: ' . $method));
        }
    }

    /**
     * One tools/call: validate, run, audit, and shape the result.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $principal
     */
    private static function call_tool(mixed $id, array $params, array $principal, ?string $modern): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        if ($name === '' || !McpTools::known($name)) {
            // A call naming a tool that does not exist is a malformed call, not a
            // business refusal — the spec's example for this is -32602.
            $scope = '';
            ApiToken::audit((int) $principal['token_id'], $principal['kind'], (int) $principal['id'],
                $name, $scope, false, 'unknown tool', $args);
            return self::json(self::error_body($id, -32602, 'unknown tool: ' . $name));
        }

        $scope = McpTools::scope_of($name);
        $tier  = McpTools::tier_of($name);
        // The two arguments that only a write cares about: an idempotency key so a
        // retry cannot repeat the write, and (Tier 3) the human's approval string.
        $requestId = $tier >= McpWrites::TIER_CONFIRM ? trim((string) ($args['request_id'] ?? '')) : '';
        $approval  = $tier >= McpWrites::TIER_APPROVAL ? trim((string) ($args['approval'] ?? '')) : '';
        $start = (int) round(microtime(true) * 1000);

        try {
            if (!McpTools::permitted($name, $principal)) {
                // The role list and the scope are both ceilings: refuse before any
                // query runs, and say which one actually did it (McpTools::refusal_for()
                // — a role-refused tool must not read as a missing scope, or the
                // operator would grant a scope that could never have helped).
                throw new McpToolError(McpTools::refusal_for($name, $principal));
            }

            /*
             * IDEMPOTENCY, before anything else runs. A write that was already
             * applied with this key returns the FIRST outcome rather than doing it
             * again: an assistant that retried after a timeout must not send the
             * SMS twice or record the payment twice. The key is recorded only for
             * a call that actually changed data, and (token_id, request_id) is
             * unique, so two concurrent retries cannot both claim it.
             */
            if ($requestId !== '') {
                $prior = ApiToken::idempotent_outcome((int) $principal['token_id'], $requestId);
                if ($prior !== null) {
                    $ms = (int) round(microtime(true) * 1000) - $start;
                    ApiToken::audit((int) $principal['token_id'], $principal['kind'], (int) $principal['id'],
                        $name, $scope, true, '', $args, null, $ms, [
                            'tier'         => $tier,
                            'outcome_note' => 'idempotent replay — this request_id had already been applied',
                        ]);
                    return self::json(self::result($id, self::tool_content([
                        'status' => 'already_applied',
                        'note'   => $prior,
                        'detail' => 'That request_id was already applied on this token, so nothing was done a second time.',
                    ]), $modern));
                }
            }

            $out     = McpTools::call($name, $args, $principal);
            $ms      = (int) round(microtime(true) * 1000) - $start;
            $applied = !empty($out['applied']);

            ApiToken::audit((int) $principal['token_id'], $principal['kind'], (int) $principal['id'],
                $name, $scope, true, '', $args, $out['booking_id'], $ms, self::audit_extra(
                    $tier, $applied ? $requestId : '', $approval, $name, $args, (string) ($out['note'] ?? '')));
            return self::json(self::result($id, self::tool_content($out['data']), $modern));
        } catch (McpToolError|McpApprovalError $e) {
            /*
             * BOTH refusal kinds are business results, not crashes. A rejected
             * approval belongs here for a specific reason: "that approval was
             * minted for a different payload" is a sentence the assistant can act
             * on, and reporting it as an internal error would hide the one thing
             * it needs to know.
             */
            $ms = (int) round(microtime(true) * 1000) - $start;
            ApiToken::audit((int) $principal['token_id'], $principal['kind'], (int) $principal['id'],
                $name, $scope, false, $e->getMessage(), $args, null, $ms, self::audit_extra(
                    $tier, '', $approval, $name, $args, ''));
            return self::json(self::result($id, self::tool_content(['error' => $e->getMessage()], true), $modern));
        } catch (Throwable $e) {
            /*
             * Anything else is this platform's bug, and the caller is told so
             * without the stack: an English sentence for the model, the details in
             * the PHP error log for whoever fixes it.
             */
            $ms = (int) round(microtime(true) * 1000) - $start;
            error_log('[WPMS][Mcp] ' . $name . ' failed: ' . $e->getMessage());
            ApiToken::audit((int) $principal['token_id'], $principal['kind'], (int) $principal['id'],
                $name, $scope, false, 'internal: ' . $e->getMessage(), $args, null, $ms, self::audit_extra(
                    $tier, '', $approval, $name, $args, ''));
            return self::json(self::result($id, self::tool_content(
                ['error' => 'the platform could not complete that request; it has been logged'], true), $modern));
        }
    }

    /**
     * What the audit row needs beyond the v2.16 columns: the tier, the
     * idempotency key (only for a call that changed something), the approval that
     * authorised it and the payload hash that approval was bound to, and the
     * server's own line about what happened.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function audit_extra(
        int $tier,
        string $requestId,
        string $approval,
        string $tool,
        array $args,
        string $note
    ): array {
        return [
            'tier'         => $tier,
            'request_id'   => $requestId,
            'approval_id'  => $approval !== '' ? McpApprovals::id_from($approval) : null,
            'payload_hash' => $tier >= McpWrites::TIER_APPROVAL ? McpApprovals::payload_hash($tool, McpWrites::committed($tool, $args)) : '',
            'outcome_note' => $note,
        ];
    }

    // ==================================================================
    // Documents (resources) and instructions (prompts)
    // ==================================================================

    /**
     * One resources/read: resolve the URI, run the tool behind it, audit, answer.
     *
     * The URI goes into the audit row's tool column ("resources/read
     * wedding://200/budget") because for a document read the URI IS the request —
     * a log line saying only "a resource was read" would tell an operator nothing
     * they could act on. The wedding it names also lands in the booking_id column,
     * where the tools put theirs.
     *
     * A refusal is a JSON-RPC ERROR, not a result: there is no `isError` on a
     * document, and the caller did not ask a question that can be answered with
     * one. `McpResourceError::$notFound` decides the code (see missing_code()).
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $principal
     */
    private static function read_resource(mixed $id, array $params, array $principal, ?string $modern): array
    {
        $uri   = trim((string) ($params['uri'] ?? ''));
        $scope = McpResources::scope_for($uri);
        $label = 'resources/read' . ($uri !== '' ? ' ' . $uri : '');
        $args  = ['uri' => $uri];
        $start = self::now_ms();

        try {
            $doc = McpResources::read($uri, $principal);
        } catch (McpResourceError $e) {
            self::audit_request($principal, $label, $scope, false, $e->getMessage(), $args, null, self::now_ms() - $start);
            return self::json(self::error_body($id, self::missing_code($e->notFound, $modern), $e->getMessage()));
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] resources/read ' . $uri . ' failed: ' . $e->getMessage());
            self::audit_request($principal, $label, $scope, false, 'internal: ' . $e->getMessage(), $args, null, self::now_ms() - $start);
            return self::json(self::error_body($id, -32603, 'the platform could not complete that request; it has been logged'));
        }

        self::audit_request($principal, $label, $scope, true, '', $args, $doc['booking_id'], self::now_ms() - $start);
        return self::json(self::result($id, self::cached([
            'contents' => [[
                'uri'      => $uri,
                'mimeType' => 'application/json',
                // The very same text a tools/call would return, so an assistant
                // that read the document and one that called the tool are looking
                // at one rendering of one query.
                'text'     => self::pretty($doc['data']),
            ]],
        ], self::TTL_DOCUMENT), $modern));
    }

    /**
     * One prompts/get: assemble the instructions and the context they need.
     *
     * Always -32602 when it fails, and never a not-found disguise: prompt names
     * are a published, fixed list, so naming one tells the caller nothing they
     * could not have read in prompts/list, and a message that says which scope or
     * which argument is missing is one the caller can act on.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $principal
     */
    private static function get_prompt(mixed $id, array $params, array $principal, ?string $modern): array
    {
        $name  = trim((string) ($params['name'] ?? ''));
        $args  = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $scope = McpPrompts::known($name) ? McpPrompts::scope_of($name) : '';
        $label = 'prompts/get' . ($name !== '' ? ' ' . $name : '');
        $start = self::now_ms();

        try {
            $out = McpPrompts::get($name, $args, $principal);
        } catch (McpPromptError $e) {
            self::audit_request($principal, $label, $scope, false, $e->getMessage(), $args, null, self::now_ms() - $start);
            return self::json(self::error_body($id, -32602, $e->getMessage()));
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] prompts/get ' . $name . ' failed: ' . $e->getMessage());
            self::audit_request($principal, $label, $scope, false, 'internal: ' . $e->getMessage(), $args, null, self::now_ms() - $start);
            return self::json(self::error_body($id, -32603, 'the platform could not complete that request; it has been logged'));
        }

        self::audit_request($principal, $label, $scope, true, '', $args, null, self::now_ms() - $start);
        return self::json(self::result($id, self::cached([
            'description' => (string) $out['description'],
            'messages'    => $out['messages'],
        ], self::TTL_DOCUMENT), $modern));
    }

    /**
     * The code a failed resource read answers with.
     *
     * `$notFound` is the PROBING case — "this is not yours" is reported exactly as
     * "there is no such thing" (McpResources explains why) — and the code for it
     * changed between the revisions: the current one uses -32602 (invalid params),
     * the older ones have a dedicated -32002 (resource not found) that legacy
     * clients still recognise. A refusal about the CALLER (a scope the token lacks)
     * is the same -32602 in every era, because it is genuinely an invalid request
     * for this caller and hiding it would only make it harder to fix.
     */
    private static function missing_code(bool $notFound, ?string $modern): int
    {
        if (!$notFound) {
            return -32602;
        }
        return $modern !== null ? -32602 : -32002;
    }

    /** The MCP content block for one tool answer: the data as pretty JSON. */
    private static function tool_content(mixed $data, bool $isError = false): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => self::pretty($data),
            ]],
            'isError' => $isError,
        ];
    }

    /**
     * The one way this server renders data for a model: pretty, unescaped, UTF-8.
     * Shared so a tool result and a resource's `text` are the same bytes (a
     * client that reads the document and one that calls the tool must not be able
     * to tell the two apart).
     */
    private static function pretty(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A result that says how long a client may keep it, and for whom.
     *
     * Both fields are on every result this server builds for a read of an index or
     * a document (see the TTL constants for the values and why), and `private` is
     * not a tuning knob: these answers are computed for one token's scopes and one
     * account's rows.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function cached(array $payload, int $ttlMs): array
    {
        return $payload + ['ttlMs' => $ttlMs, 'cacheScope' => 'private'];
    }

    /**
     * One audit row for a resource read or a prompt get — the two requests that
     * are not tool calls. Shares ApiToken::audit(), so the argument digest rule
     * (names and sizes, never values) and the retention job cover them too.
     *
     * @param array<string,mixed> $principal
     * @param array<string,mixed> $args
     */
    private static function audit_request(array $principal, string $what, string $scope, bool $ok, string $error, array $args, ?int $bookingId, int $ms): void
    {
        ApiToken::audit(
            (int) $principal['token_id'],
            (string) $principal['kind'],
            (int) $principal['id'],
            $what,
            $scope,
            $ok,
            $error,
            $args,
            $bookingId,
            $ms
        );
    }

    /** Milliseconds since the epoch — for the audit row's duration. */
    private static function now_ms(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    // ==================================================================
    // Eras, headers, switches
    // ==================================================================

    /**
     * Which protocol version is this message asking for? '' when it says nothing
     * — which is what a legacy handshake looks like.
     *
     * Read from `_meta` first (the body is the message) and the header second, so
     * a request that carries both but disagrees is caught by
     * header_body_mismatch() rather than silently obeyed.
     *
     * @param array<string,mixed> $message
     * @param array<string,mixed> $params
     */
    private static function asked_version(array $message, array $params): string
    {
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $metaVersion = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
        if (is_string($metaVersion) && trim($metaVersion) !== '') {
            return trim($metaVersion);
        }
        return trim((string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
    }

    /**
     * The era this message is speaking in: the current stateless revision, or the
     * legacy handshake one.
     *
     * ONLY THE CURRENT REVISION IS `null`-BREAKING. An older supported version in
     * `_meta` (a client that names 2025-11-25 but sends no handshake) is treated as
     * legacy on purpose: that revision's results carry no `resultType`, its reads
     * answer a missing resource with -32002, and it never required the matching
     * request headers. Answering it in the modern shape would be answering a
     * language the client did not ask for.
     */
    private static function is_current_era(string $asked): bool
    {
        return $asked === self::VERSION;
    }

    /**
     * In the current protocol the request's own headers have to agree with its
     * body: `MCP-Protocol-Version` with `_meta`, `Mcp-Method` with `method`, and
     * `Mcp-Name` with the tool being called. Disagreement is a 400 — a proxy that
     * rewrote one and not the other must not be silently obeyed.
     *
     * @param array<string,mixed> $message
     * @param array<string,mixed> $params
     * @return string|null the reason to refuse, or null when they agree
     */
    private static function header_body_mismatch(array $message, array $params): ?string
    {
        $headerVersion = trim((string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $metaVersion = (string) ($meta['io.modelcontextprotocol/protocolVersion'] ?? '');
        if ($headerVersion === '' || $metaVersion === '') {
            return 'the ' . self::VERSION . ' protocol requires MCP-Protocol-Version and _meta["io.modelcontextprotocol/protocolVersion"]';
        }
        if ($headerVersion !== $metaVersion) {
            return 'MCP-Protocol-Version header (' . $headerVersion . ') disagrees with the request body (' . $metaVersion . ')';
        }
        $method = (string) ($message['method'] ?? '');
        $headerMethod = trim((string) ($_SERVER['HTTP_MCP_METHOD'] ?? ''));
        if ($headerMethod === '') {
            return 'the ' . self::VERSION . ' protocol requires the Mcp-Method header';
        }
        if ($headerMethod !== $method) {
            return 'Mcp-Method header (' . $headerMethod . ') disagrees with the request body (' . $method . ')';
        }

        /*
         * Mcp-Name names WHAT is being addressed, in the header and in the body:
         * the tool for a call, the URI for a resource read, the prompt for a
         * prompts/get. It is the same argument the load balancer, the WAF and the
         * access log see — a request that says "wedding://200/budget" in one and
         * something else in the other is a request nobody can audit.
         */
        $named = match ($method) {
            'tools/call'     => ['name', 'a tools/call'],
            'prompts/get'    => ['name', 'a prompts/get'],
            'resources/read' => ['uri',  'a resources/read'],
            default          => null,
        };
        if ($named !== null) {
            [$param, $what] = $named;
            $headerName = trim((string) ($_SERVER['HTTP_MCP_NAME'] ?? ''));
            $bodyName   = (string) ($params[$param] ?? '');
            if ($headerName === '') {
                return $what . ' in the ' . self::VERSION . ' protocol must carry the Mcp-Name header';
            }
            if ($headerName !== $bodyName) {
                return 'Mcp-Name header (' . $headerName . ') disagrees with the request body (' . $bodyName . ')';
            }
        }
        return null;
    }

    /** The bearer secret, or '' — header only, never a query string. */
    private static function bearer_token(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Is this Origin one we serve? The site's own origin always is (a page on the
     * platform may legitimately call it); anything else must be named in
     * WPMS_MCP_ORIGINS by the operator. An absent Origin is fine: server-side
     * clients (Claude Code, an internal agent, curl) send none, and a browser
     * always does.
     */
    private static function origin_allowed(string $origin): bool
    {
        $origin = rtrim(strtolower($origin), '/');
        if ($origin === '') {
            return true;
        }
        $base = rtrim(strtolower(site_base_url()), '/');
        if ($base !== '' && $origin === $base) {
            return true;
        }
        if (preg_match('~^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$~', $origin)) {
            return true; // a local client talking to a local install
        }
        $configured = trim((string) getenv(self::ORIGINS_ENV));
        if ($configured === '') {
            return false;
        }
        foreach (explode(',', $configured) as $allowed) {
            if (rtrim(strtolower(trim($allowed)), '/') === $origin) {
                return true;
            }
        }
        return false;
    }

    /** WPMS_MCP=0 stops the endpoint; anything else (and nothing at all) runs it. */
    public static function enabled(): bool
    {
        return self::flag(self::SWITCH, true);
    }

    /**
     * Are data-changing tools available? OFF unless WPMS_MCP_WRITES=1 — the
     * second gate in front of any write, on top of the token's write scope.
     */
    public static function writes_enabled(): bool
    {
        return self::flag(self::WRITE_SWITCH, false);
    }

    /** A boolean-looking environment flag with an explicit default. */
    private static function flag(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    // ==================================================================
    // Wire helpers
    // ==================================================================

    /**
     * A JSON-RPC result, with `resultType` when the client speaks the current
     * protocol (the field is mandatory there and absent in the legacy shape).
     *
     * @param array<string,mixed> $payload
     */
    private static function result(mixed $id, array $payload, ?string $modern): array
    {
        if ($modern !== null) {
            $payload = ['resultType' => 'complete'] + $payload;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $payload];
    }

    /**
     * What this server offers, as the handshake and server/discover report it.
     *
     * All three surfaces are advertised unconditionally even though each token
     * sees a different slice of them: capabilities describe the SERVER, and the
     * filtering that matters (which tools, which documents, which prompts) happens
     * in the lists themselves, per token. `listChanged: false` is the honest
     * answer for a stateless server — there is no subscription to change.
     *
     * @return array<string, array<string,bool>>
     */
    private static function capabilities(): array
    {
        return [
            'tools'     => ['listChanged' => false],
            'resources' => ['subscribe' => false, 'listChanged' => false],
            'prompts'   => ['listChanged' => false],
        ];
    }

    /** A JSON-RPC error envelope, as JSON text. */
    private static function error_body(mixed $id, int $code, string $message): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed>|string $payload a JSON-RPC envelope (array) or already-encoded JSON
     */
    private static function json(mixed $payload): array
    {
        return self::http(200, is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string,string> $headers
     */
    private static function http(int $status, string $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
