<?php
/**
 * Deployment health check — open once in the browser after uploading, then
 * DELETE this file (or rename it with a leading dot) on a live site.
 *
 * SAFE TO RE-RUN: every check is read-only (it never writes or migrates), so
 * you can hit it as many times as you like while fixing problems.
 *
 * It verifies the things that break most cPanel deploys:
 *   1. PHP version + required extensions
 *   2. .env discovery (HOME copy or project-root copy) + no debug leaks
 *   3. Database connection + all expected tables + at least one account
 *   3b. Scheduler readiness — INFORMATIONAL lines, never a failure: which of
 *      the five judged jobs have ever recorded a heartbeat (v2.15), whether the
 *      cron bridge key is set, and the two commands that watch them. A fresh
 *      install has no heartbeats yet and that is not a broken deployment — but
 *      cron never being wired up is the thing cPanel installs get wrong, and on
 *      every other page it is invisible until a couple says an email never
 *      arrived.
 *   4. Writable upload directories
 *   5. Core pages reachable via the app's own bootstrap (no fatals)
 *
 * FAILURE REPORTING
 *   - Each check prints [ OK ] or [FAIL] with the exact reason.
 *   - [INFO] lines are context, not verdicts: they never affect the status code
 *     or the summary below.
 *   - The response is HTTP 500 when ANY check fails (200 when all pass), so a
 *     status-code monitor can watch it too.
 *   - The last line is a machine-readable summary: "RESULT: ok=N fail=M".
 *   - Run from the shell (php deploy_check.php KEY) and it exits 0/1 as well.
 *
 * Usage:  https://yourdomain.co.ke/deploy_check.php?key=<DEPLOY_CHECK_KEY>
 *         php deploy_check.php <key>
 */

// Change this to any long random string BEFORE uploading, then pass ?key=...
const DEPLOY_CHECK_KEY = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';

// Accept the key from ?key= (browser) or argv[1] (CLI), compared in constant time.
$given = $_GET['key'] ?? ($_SERVER['argv'][1] ?? '');
$given = is_string($given) ? $given : '';
$forbidden = DEPLOY_CHECK_KEY === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING'
        || !hash_equals(DEPLOY_CHECK_KEY, $given);
if ($forbidden) {
    http_response_code(403);
    $msg = "Forbidden — set DEPLOY_CHECK_KEY in this file and pass ?key=...\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
        exit(1);
    }
    exit($msg);
}

header('Content-Type: text/plain; charset=utf-8');
// Buffer everything so the final status code can reflect the result.
ob_start();

$okCount = 0;
$failCount = 0;
$ok = function (string $label, bool $pass, string $detail = '') use (&$okCount, &$failCount) {
    echo ($pass ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $pass ? $okCount++ : $failCount++;
};

// Context only: an install that has not wired cron up yet is not broken, it is
// new. Deliberately does not touch $okCount/$failCount.
$info = function (string $label, string $detail = '') {
    echo '[INFO] ' . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
};

echo "=== Everafter Planner deployment check ===\n\n";

// 1. PHP platform
/*
 * Two questions, deliberately separate. The floor is what the codebase can
 * RUN on; the branch is what the vendor still SECURITY-PATCHES. PHP 8.2 is
 * security-only and reaches end of life on 2026-12-31 (8.3 on 2027-12-31), so a
 * host left on an old MultiPHP selection must be told loudly, and a branch past
 * its published EOL is a failed deploy rather than a footnote. The dates are
 * the vendor's published table (php.net/supported-versions) — extend it when a
 * new branch ships, not when a site breaks.
 */
$phpEol = [
    '8.1' => '2025-12-31',
    '8.2' => '2026-12-31',
    '8.3' => '2027-12-31',
];
$phpBranch   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$phpPastEol  = isset($phpEol[$phpBranch]) && strtotime($phpEol[$phpBranch]) < time();
$phpActive   = version_compare(PHP_VERSION, '8.4.0', '>=');
$ok('PHP >= 8.2 (minimum this codebase supports)', version_compare(PHP_VERSION, '8.2.0', '>='), 'running ' . PHP_VERSION);
$ok('PHP branch still supported', !$phpPastEol,
    $phpPastEol
        ? "PHP {$phpBranch} reached end of life on {$phpEol[$phpBranch]} — set PHP 8.4 or 8.5 in cPanel → MultiPHP Manager"
        : 'PHP ' . PHP_VERSION);
if (!$phpActive) {
    $info('PHP branch is security-only, not actively supported',
        PHP_VERSION . ' — plan the upgrade to 8.4 or 8.5 (cPanel → MultiPHP Manager); 8.2 ends 2026-12-31');
}
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'curl'] as $ext) {
    $ok("ext {$ext}", extension_loaded($ext));
}

// 2. .env discovery + DB constants materialize
require __DIR__ . '/admin/include/config.php';
$envFileHome = dirname(__DIR__) . '/.env';
$envFileRoot = __DIR__ . '/.env';
$envFound    = is_file($envFileHome) || is_file($envFileRoot);
$ok('.env file found (HOME or project root)', $envFound,
    $envFound ? (is_file($envFileHome) ? $envFileHome : $envFileRoot) : 'deployment will use built-in defaults');
$ok('DB_NAME configured', defined('DB_NAME') && DB_NAME !== '' && DB_NAME !== 'dbwedding',
    defined('DB_NAME') ? DB_NAME : 'still the dev default');

// Debug output must be OFF in production (it leaks paths/SQL to visitors).
$debugOn = in_array(strtolower(trim((string) getenv('WPMS_DEBUG'))), ['1', 'true', 'yes', 'on'], true);
$ok('WPMS_DEBUG is off', !$debugOn, $debugOn ? 'set WPMS_DEBUG=0 in .env — errors are visible to visitors' : '');

// 3. Database + tables
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $ok('Database connection', true, DB_NAME . '@' . DB_HOST);
    // Every statement this health check runs goes through prepare() + execute(),
    // like the application's own — a check that reaches the driver unprepared is
    // how an unprepared path survives in a codebase nobody dares touch.
    $stmt = $pdo->prepare('SHOW TABLES');
    $stmt->execute();
    $have = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Tables the application actually uses. Deliberately NOT required:
    // `tblsession_audits`, `tblorganizer` (dead — nothing reads or writes
    // them; only this list ever named them) and `calendar` (legacy widget row
    // the seed-date tooling touches, never a page or a request path). A fresh
    // install no longer has to create any of them.
    $need = ['tblusers','tblaccounts','tblaccounts_detail','tblweddingbook','tblguest','tblsubscriptions',
             'tblsubscription_plans','tblsubscription_payments','tblvendors','tblvendor_bookings',
             'tblvendor_packages','tblnotifications','tbl_mail_logs','tblsms_logs','tblsms_credits',
             'tbllogin_throttle','tblsignup_throttle','tblpublic_throttle','tblplanner_verifications','tbl_password_resets',
             'tbljob_heartbeats','tblmpesa_callbacks','tblmpesa_callback_log','tblmpesa_stk_pushes',
             'tblgallery','tblpostwedding','tblweddingcategories','tbl_features','tblwedding_websites',
             'tblchecklist','tbl_liquidation','tbl_installment_plans','events',
             'tbluser_prefs','tblproposals','tblproposal_options','tblproposal_items',
             'tblvendor_service_tiers','tblvendor_package_tiers'];
    $missing = array_diff($need, $have);
    $ok('All ' . count($need) . ' expected tables present', $missing === [],
        $missing === [] ? count($have) . ' tables total' : 'missing: ' . implode(', ', $missing));
    $staffStmt = $pdo->prepare('SELECT COUNT(*) FROM tblusers');
    $staffStmt->execute();
    $staff = (int) $staffStmt->fetchColumn();
    $clientsStmt = $pdo->prepare('SELECT COUNT(*) FROM tblaccounts');
    $clientsStmt->execute();
    $clients = (int) $clientsStmt->fetchColumn();
    // The operator's own account (seed_admin.sql) is the thing that proves the
    // import worked. Couples are NOT required: on install day there are none,
    // and the check that demanded one failed every fresh deployment (this check
    // runs by the guide's step 7, before anyone has signed up). It is context
    // now, not a verdict.
    $ok('Staff account(s) exist', $staff > 0, "{$staff} staff");
    $info('couple accounts', $clients > 0
        ? "{$clients} (couples register themselves — seed_admin.sql creates no couples)"
        : 'none yet — couples appear when they sign up');

    // 3b. The scheduler. Every judged job leaves a heartbeat when it really
    //     runs (v2.15), so this is the only place a deployment can see whether
    //     anything is running them at all. INFORMATIONAL: the status code must
    //     not fail here, or every fresh install ships red.
    $judged = ['mail_retry', 'notify_prune', 'subscription_renewals', 'rsvp_reminders', 'deadline_sms'];
    if (in_array('tbljob_heartbeats', $have, true)) {
        $beatStmt = $pdo->prepare('SELECT job FROM tbljob_heartbeats');
        $beatStmt->execute();
        $seen  = array_map('strval', $beatStmt->fetchAll(PDO::FETCH_COLUMN));
        $never = array_values(array_diff($judged, $seen));
        $info('scheduled jobs that have ever run: ' . count(array_intersect($judged, $seen)) . ' of ' . count($judged),
            $never === []
                ? 'every judged job has a heartbeat'
                : 'never run: ' . implode(', ', $never)
                    . ' — wire the crontab up (Part 6) or call cron_bridge.php?job=all');
    } else {
        $info('scheduled jobs', 'tbljob_heartbeats is missing, so nothing records what they do'
            . ' — import migrations/v2.15_job_heartbeats.sql');
    }
    // 3c. AI access over the MCP endpoint (v2.16). INFORMATIONAL, like the
    //     scheduler above and for the same reason: the platform is complete
    //     without it, so a fresh install that does not want an AI client must not
    //     ship red — but a deployment that DOES want one has no other way to see
    //     whether the tokens table, the kill switch and the write gate agree.
    //     Reads are always available to a valid token; writes need the switch.
    if (in_array('tblapi_tokens', $have, true) && in_array('tblapi_audit', $have, true)) {
        $flag = static function (string $name, bool $default): bool {
            $raw = getenv($name);
            if (!is_string($raw) || trim($raw) === '') {
                return $default;
            }
            $v = strtolower(trim($raw));
            return in_array($v, ['1', 'true', 'yes', 'on'], true) ? true
                : (in_array($v, ['0', 'false', 'no', 'off'], true) ? false : $default);
        };
        $liveStmt = $pdo->prepare('SELECT COUNT(*) FROM tblapi_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
        $liveStmt->execute();
        $liveTokens = (int) $liveStmt->fetchColumn();
        $auditStmt = $pdo->prepare('SELECT COUNT(*) FROM tblapi_audit');
        $auditStmt->execute();
        $auditRows = (int) $auditStmt->fetchColumn();
        if (!$flag('WPMS_MCP', true)) {
            $info('AI access (MCP)', 'tables installed but WPMS_MCP=0 — the endpoint answers 503 to every client');
        } else {
            $info('AI access (MCP)', $liveTokens > 0
                ? $liveTokens . ' live token(s), ' . $auditRows . ' audit row(s); writes '
                    . ($flag('WPMS_MCP_WRITES', false) ? 'ENABLED (WPMS_MCP_WRITES=1)' : 'off')
                : 'tables installed, no live tokens — mint one on admin/api_tokens.php'
                    . ($flag('WPMS_MCP_WRITES', false) ? '; writes ENABLED' : '; writes off'));
        }
        /*
         * 3d. The write tiers (v2.17). Also informational, and for a sharper
         * reason: without the approvals table, a Tier-3 tool (an SMS to a guest
         * list, a payment, an M-Pesa deposit prompt, a planner assignment)
         * refuses — which is a SAFE state, not a broken one. What an operator
         * cannot see without this line is the difference between "an assistant
         * asked for something and is waiting for a human" and "the feature was
         * never installed", and those need different answers.
         */
        if (in_array('tblapi_approvals', $have, true)) {
            $pendingStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM tblapi_approvals
                  WHERE approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL AND expires_at > NOW()'
            );
            $pendingStmt->execute();
            $pending = (int) $pendingStmt->fetchColumn();
            $info('AI approvals (Tier 3)', $pending > 0
                ? $pending . ' request(s) waiting for a planner on admin/mcp_approvals.php'
                : 'installed, nothing waiting — money and message tools need a signed-in approval'
                    . (trim((string) getenv('WPMS_MCP_APPROVAL_SECRET')) === ''
                        ? '; WPMS_MCP_APPROVAL_SECRET is unset, so the approval key is derived from the DB credentials'
                        : ''));
        } else {
            $info('AI approvals (Tier 3)', 'not installed — import migrations/v2.17_mcp_approvals.sql'
                . ' to let an assistant ask for an SMS, a payment or a planner assignment'
                . ' (without it those tools refuse, which is safe but silent)');
        }

        // The schema is only half of it, so the endpoint itself is probed in
        // section 5b below (reachable, and refusing the way it should).
    } else {
        $info('AI access (MCP)', 'not installed — import migrations/v2.16_api_tokens.sql'
            . ' to let an AI client (Claude, ChatGPT, Cursor) read the planner'
            . ' (v2.17 adds the human-approved write tier on top of it)');
    }

    $bridgeKey = trim((string) getenv('WPMS_CRON_BRIDGE_KEY'));
    $info('cron bridge', $bridgeKey !== ''
        ? 'WPMS_CRON_BRIDGE_KEY is set — cron_bridge.php accepts ?key=...&job=all'
        : 'WPMS_CRON_BRIDGE_KEY is not set, so cron_bridge.php refuses every request'
            . ' (fine if you run the jobs from crontab instead)');
    $info('watching it', 'php scripts/job_watch.php --quiet exits non-zero when a job goes quiet;'
        . ' /admin/job_watch.php (super admin) shows the same table');
} catch (Throwable $e) {
    $ok('Database connection', false, $e->getMessage());
}

// 4. Writable uploads
foreach (['admin/upload/users', 'admin/upload/gallery', 'admin/upload/events',
          'admin/upload/categories', 'admin/upload/vendors'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    $ok("writable {$dir}", is_dir($path) && is_writable($path),
        is_dir($path) ? '' : 'directory missing');
}

// 5. Core pages — probed over HTTP so each runs in its own request. (They
//    cannot be include()d one after another: every page re-includes init.php,
//    which redeclares its functions and fatals the second page.)
function wpms_base_url(): string
{
    $env = trim((string) getenv('WPMS_SITE_URL'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    if ($host === '') {
        return ''; // CLI with no host — cannot self-probe
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** @return array{code:int, body:string, err:string} */
function wpms_probe(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false, // self-check; cert matters to visitors, not here
            CURLOPT_USERAGENT      => 'EverafterDeployCheck/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = $body === false ? curl_error($ch) : '';
        return ['code' => $code, 'body' => (string) $body, 'err' => $err];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 1, 'max_redirects' => 4,
                                                 'ignore_errors' => true, 'user_agent' => 'EverafterDeployCheck/1.0']]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return ['code' => $code, 'body' => (string) $body, 'err' => $body === false ? 'request failed' : ''];
    }
    return ['code' => 0, 'body' => '', 'err' => 'no curl and allow_url_fopen off'];
}

$base = wpms_base_url();
if ($base === '') {
    echo "[SKIP] core page probes — could not determine the site URL (set WPMS_SITE_URL to enable)\n";
} else {
    foreach (['index.php', 'login.php', 'pricing.php', 'gallery.php', 'contact.php', 'thank_you.php'] as $page) {
        if (!is_file(__DIR__ . '/' . $page)) {
            $ok("page {$page}", false, 'file missing');
            continue;
        }
        $r    = wpms_probe($base . '/' . $page);
        $leak = (bool) preg_match('~(Fatal error|Parse error|Uncaught|Warning:)~i', $r['body']);
        if ($r['code'] === 0) {
            echo "[SKIP] page {$page} — {$r['err']}\n";
            continue;
        }
        $ok("page {$page}", $r['code'] < 500 && !$leak,
            $leak ? 'PHP error text in output' : ($r['code'] >= 500 ? 'HTTP ' . $r['code'] : ''));
    }

    /*
     * 5b. The MCP endpoint (v2.16), when it is installed. mcp.php is
     * deliberately NOT in the .htaccess denylist — it has to be reachable to be
     * useful — so the question a deployment has to answer is whether it refuses
     * the way it should. A GET is the one probe that needs no token: the answer
     * must be 405 with `Allow: POST` (this server offers no SSE stream), never
     * 200 and never PHP error text. A 401 to a GET would mean the method gate
     * let the request further in than it should have.
     */
    if (is_file(__DIR__ . '/mcp.php') && in_array('tblapi_tokens', $have ?? [], true)) {
        $r    = wpms_probe($base . '/mcp.php');
        $leak = (bool) preg_match('~(Fatal error|Parse error|Uncaught|Warning:)~i', $r['body']);
        if ($r['code'] === 0) {
            echo "[SKIP] mcp.php — {$r['err']}\n";
        } else {
            $ok('mcp.php refuses a GET with 405', $r['code'] === 405 && !$leak,
                $leak ? 'PHP error text in output' : ($r['code'] === 405 ? 'Allow: POST' : 'HTTP ' . $r['code']));
        }
    }
}

echo "\n";
echo $failCount === 0
    ? "=== ALL CHECKS PASSED — delete this file now ===\n"
    : "=== {$failCount} check(s) FAILED, {$okCount} passed ===\n";
echo "RESULT: ok={$okCount} fail={$failCount}\n";

// Emit the buffered body with a status code that matches the result.
$body = ob_get_clean();
http_response_code($failCount > 0 ? 500 : 200);
echo $body;

// CLI: mirror the result in the exit code so scripts/CI can gate on it.
if (PHP_SAPI === 'cli') {
    exit($failCount > 0 ? 1 : 0);
}
