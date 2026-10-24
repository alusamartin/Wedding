<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.16 — the MCP endpoint (Model Context Protocol over HTTP)
 *
 *   POST https://your-domain/mcp.php      Authorization: Bearer wpms_…
 *
 * This URL is what an AI client is pointed at — Claude Code, Claude Desktop,
 * Cursor, ChatGPT, an internal agent — so it can read, and (once switched on)
 * act on, this platform's real wedding data: guests, budgets, vendors, tasks,
 * timelines. The AI is the CLIENT; PHP stays the authority. Every call is
 * authenticated as one of this app's own accounts and then runs with exactly
 * that account's permissions, through the same models and the same ownership
 * predicates the pages use — so nothing here can see or do what its human could
 * not do by hand. Read admin/include/ApiToken.php for the identity bridge and
 * admin/include/McpTools.php for what is on offer.
 *
 * WHAT IT MAY DO, AND ON WHOSE AUTHORITY. Reads are free. A WRITE has a tier:
 * Tier 2 (add a guest, tick a checklist item, mark invitations) needs
 * `confirm: true` and otherwise returns a preview, changing nothing; Tier 3 (SMS
 * to a guest list, a payment, an M-Pesa deposit prompt, a planner assignment)
 * sends nothing on its own authority — it records a request and answers
 * `approval_required`, and a signed-in planner approves that exact payload on
 * admin/mcp_approvals.php, which mints a short-lived, single-use, HMAC-signed
 * approval bound to the tool and the payload hash (McpApprovals). Writes are off
 * entirely unless WPMS_MCP_WRITES=1. See admin/include/McpWrites.php.
 *
 * THREE SURFACES, ONE DOOR. Tools (McpTools) are what an assistant may ask for;
 * resources (McpResources) are the documents it may open, addressed as
 * wedding://{booking_id}/overview and its seven siblings; prompts (McpPrompts)
 * are the instructions this platform hands over ready-made, such as the weekly
 * wedding brief. Every one of them is authenticated, scoped and audited here, on
 * this path, by the token — a resource is not a bypass and a prompt is not a
 * shortcut.
 *
 * WHAT THIS FILE IS, AND IS NOT. It is deliberately twelve lines of transport:
 * read the body, ask McpServer what the answer is, write it. All the protocol
 * judgement — the two MCP eras, header/body agreement, auth, rate limiting,
 * scopes, auditing, and how an error is shaped — lives in
 * admin/include/McpServer.php, so there is one implementation to read and one to
 * test. docs/MCP_PLAN.md describes the whole design and its phases.
 *
 * NO SESSION, NO COOKIE, NO BOOTSTRAP. It never includes admin/include/init.php:
 * a machine has no cookies, and an endpoint that started a session would set one
 * on every call and depend on server-side session state that shared hosting and
 * a load balancer cannot guarantee. It includes admin/include/models.php — the
 * same model set every page loads, minus the session and the redirect guards —
 * plus the three classes that make up the MCP layer. Responses are
 * `application/json`, `Cache-Control: no-store` (they carry wedding data), and
 * nothing else.
 *
 * The .htaccess denylist that hides scripts/, tests/ and admin/include/ does not
 * touch this path, so the endpoint is reachable — by design, since that is the
 * whole point — and every request has to present a token before a single row is
 * read.
 *
 * SETUP (operator): mint a token on admin/api_tokens.php, then give the client
 * this URL and the secret. Switches:
 *   WPMS_MCP=0            switch the endpoint off entirely (503)
 *   WPMS_MCP_WRITES=1     allow data-changing tools (off by default)
 *   WPMS_MCP_APPROVAL_SECRET  HMAC key for Tier-3 approvals (set it in production)
 *   WPMS_MCP_ORIGINS=…    csv of extra browser origins allowed to call it
 *   WPMS_SITE_URL         used to recognise the site's own Origin
 */

// Error handling is set here (not inherited from init.php): warnings must never
// be printed into a JSON body, and everything still lands in the PHP error log.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Africa/Nairobi');

/*
 * No session, before anything loads it: models.php pulls in Session.php, whose
 * bottom-line singleton starts a PHP session and therefore sets a PHPSESSID
 * cookie and writes a session file on every call. For a machine that sends no
 * cookie back that file is garbage the moment the response ends. The constant
 * only suppresses the SINGLETON; the class, the CSRF helpers and the gates it
 * serves are still loaded for the pages that need them.
 */
define('WPMS_NO_SESSION', true);

// What a machine needs, and not one file more: the shared model set (the same
// list every page loads — one list, so it cannot drift), then the MCP layer.
require_once __DIR__ . '/admin/include/models.php';         // config + every model + the guards
require_once __DIR__ . '/admin/include/PublicGuard.php';    // — already in models.php; kept explicit because the rate limit is used here
require_once __DIR__ . '/admin/include/ApiToken.php';       // identity, scopes, audit
require_once __DIR__ . '/admin/include/McpApprovals.php';   // the human approval a Tier-3 write needs
require_once __DIR__ . '/admin/include/OpsPulse.php';       // the operator's aggregate statistics and rule-based findings
require_once __DIR__ . '/admin/include/McpWrites.php';      // the write catalogue: what it may DO, and its tiers
require_once __DIR__ . '/admin/include/McpServer.php';      // the protocol
require_once __DIR__ . '/admin/include/McpTools.php';       // the catalogue: what the assistant may ask
require_once __DIR__ . '/admin/include/McpResources.php';   // the documents: what it may open
require_once __DIR__ . '/admin/include/McpPrompts.php';     // the instructions: what this platform hands over ready-made

/*
 * A failure that killed PHP before it could answer (a database that refused the
 * connection calls die(), for one) must still leave a machine-readable body: an
 * MCP client shown "Database failed to connect" as plain text can only report a
 * parse error, and the operator cannot tell an outage from a bad token. The
 * shutdown hook runs only while $answered is false, so nothing is appended to a
 * response that already went out.
 */
$answered = false;
register_shutdown_function(static function () use (&$answered): void {
    if ($answered) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => null,
        'error'   => ['code' => -32000, 'message' => 'this install could not serve the request; see the PHP error log'],
    ], JSON_UNESCAPED_SLASHES);
});

try {
    global $db;
    $db = new Database();
} catch (Throwable $e) {
    error_log('[WPMS][Mcp] database unavailable: ' . $e->getMessage());
    // Fall through: ApiToken::available() answers false without a working
    // connection, so the request is refused with 503 rather than answered.
}

$result = McpServer::respond((string) file_get_contents('php://input'), (string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

$answered = true;
http_response_code($result['status']);
if ($result['body'] !== '') {
    header('Content-Type: application/json; charset=utf-8');
}
// Wedding data must not sit in a proxy or browser cache, and never be sniffed
// into some other type.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
foreach ($result['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $result['body'];
