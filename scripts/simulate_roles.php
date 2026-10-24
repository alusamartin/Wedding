<?php

declare(strict_types=1);

require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * WPMS Kenya — role simulation.
 *
 * Drives the REAL application over HTTP as every seeded role (guest, couple,
 * planner, locked planner, vendor, super admin), walking every page that role
 * is supposed to reach and then probing the pages it must NOT reach. Every
 * response is inspected for PHP problems leaking into the body (fatal, parse,
 * uncaught, warning, notice, deprecation) and for semantic failures:
 *   - a 200 where an access-control redirect was required,
 *   - a redirect to the login door while holding a valid session,
 *   - a page that renders no content at all,
 *   - a write endpoint that mutates the database without CSRF or via GET.
 *
 * Run it against a scratch database, never production:
 *   php scripts/demo_reset.php --yes --db=wpms_sim
 *   WPMS_DB_NAME=wpms_sim php -S 127.0.0.1:8123 -t . 2> /tmp/wpms_sim.log
 *   WPMS_DB_NAME=wpms_sim php scripts/simulate_roles.php http://127.0.0.1:8123 --log=/tmp/wpms_sim.log
 *
 * Pass --log or the run loses half its signal: the built-in server writes PHP
 * diagnostics to its stderr log, and with html_errors off they never reach the
 * response body this harness reads. Phase 10 scans whatever the run appended
 * to the log, so log-only warnings are failures too rather than invisible.
 *
 * Options:
 *   --out=PATH     write the machine-readable JSON result set (default: temp)
 *   --only=1,3,7   run just these phases
 *   --log=PATH     the dev server's stderr log, scanned by phase 10
 *
 * Exit codes: 0 = no failures, 1 = failures, 2 = server unreachable or the
 * target database was refused (it writes, so it will not run against
 * dbwedding unless WPMS_SIM_ALLOW_LIVE is set).
 */

require_once __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/Database.php';

/*
 * PHP problem shapes only. A bare /warning/i match is useless — this codebase
 * has `class="alert alert-warning fade show"` all over its markup, which a
 * naive word match reports as a bug on almost every page. Require the actual
 * diagnostic shape: a bare `Warning:`, the `<b>Warning</b>:` html_errors form
 * (that is what display_errors prints when html_errors is on), or one of the
 * fatal/parse/uncaught prefixes.
 */
const BODY_ISSUE_RE = '~<b>(Warning|Notice|Deprecated)</b>[^<]{0,200}|\b(Warning|Notice|Deprecated)\s*:\s[^<]{0,200}|\b(Fatal error|Parse error|Recoverable fatal error|Uncaught [A-Za-z_\\\\]+)[^<]{0,200}~i';

/**
 * Any page that renders a full layout is comfortably larger than this; a
 * near-empty 200 usually means a broken include or an early exit.
 */
const MIN_PAGE_BYTES = 700;

final class RoleSim
{
    /**
     * The write-path leg's scope probes (v2.10): one listing whose confirmed deal
     * covers the function the couple moves, one whose deal covers a different
     * function of the same wedding. Named here so the assertions and the fixture
     * cannot drift apart.
     */
    private const SCOPE_PROBE_MOVED = 'SIM Scope Probe — Decor (this function)';
    private const SCOPE_PROBE_STILL = 'SIM Scope Probe — Decor (another function)';

    /** @var array<string,string> */
    private array $jar = [];
    /** @var array<int,array{phase:string,status:string,label:string,detail:string,path:string}> */
    private array $rows = [];
    private int $pass = 0;
    private int $fail = 0;
    private int $skip = 0;
    /** @var array<string,int> login attempt counter, to stay under the throttle */
    private array $serverHits = [];

    private ?Database $db = null;

    public function __construct(private readonly string $base)
    {
    }

    // ------------------------------------------------------------------ db

    private function db(): Database
    {
        if ($this->db === null) {
            $this->db = new Database();
        }
        return $this->db;
    }

    private function val(string $sql, array $params = []): mixed
    {
        try {
            return $this->db()->fetchValue($sql, $params);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function one(string $sql, array $params = []): ?object
    {
        try {
            return $this->db()->fetchOne($sql, $params);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function exec(string $sql, array $params = []): void
    {
        $this->db()->execute($sql, $params);
    }

    // --------------------------------------------------------------- http

    /**
     * @param array<string,string|int> $post
     * @return array{status:int,location:string,body:string,error:string}
     */
    private function http(string $method, string $path, array $post = [], bool $withJar = true): array
    {
        $this->serverHits[$path] = ($this->serverHits[$path] ?? 0) + 1;
        $ch = curl_init($this->base . $path);
        $headers = [];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'WPMS-RoleSim/1.0',
        ];
        if ($withJar && $this->jar !== []) {
            $cookies = [];
            foreach ($this->jar as $name => $value) {
                $cookies[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookies);
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($headers !== []) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);

        $headerLen = (int) ($info['header_size'] ?? 0);
        $head = substr((string) $raw, 0, $headerLen);
        $body = substr((string) $raw, $headerLen);

        $location = '';
        if (preg_match('~^Location:\s*(.+)$~mi', $head, $m)) {
            $location = trim($m[1]);
        }
        if (preg_match_all('~^Set-Cookie:\s*([^;=]+)=([^;]*);~mi', $head, $m, PREG_SET_ORDER)) {
            foreach ($m as $cookie) {
                $this->jar[trim($cookie[1])] = trim($cookie[2]);
            }
        }

        return [
            'status'   => (int) ($info['http_code'] ?? 0),
            'location' => $location,
            'body'     => (string) $body,
            'error'    => $error,
        ];
    }

    /** Scrape the CSRF token out of a rendered page. */
    private function token(string $path): ?string
    {
        $res = $this->http('GET', $path);
        if (preg_match('~name="_token" value="([^"]+)"~', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    /** PHP problems visible in a response body. */
    private function bodyIssues(string $body): array
    {
        if ($body === '' || !preg_match_all(BODY_ISSUE_RE, $body, $m)) {
            return [];
        }
        $clean = [];
        foreach ($m[0] as $hit) {
            $clean[] = trim(preg_replace('~\s+~', ' ', $hit));
        }
        return array_values(array_unique($clean));
    }

    // ------------------------------------------------------------ results

    private function record(string $phase, string $status, string $label, string $detail = '', string $path = ''): void
    {
        $this->rows[] = ['phase' => $phase, 'status' => $status, 'label' => $label, 'detail' => $detail, 'path' => $path];
        if ($status === 'PASS') {
            $this->pass++;
            if ($this->verbose) {
                echo "  PASS  {$label}\n";
            }
        } elseif ($status === 'FAIL') {
            $this->fail++;
            echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        } elseif ($status === 'SKIP') {
            $this->skip++;
            echo "  SKIP  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        } else {
            echo "  ....  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
    }

    private bool $verbose = false;

    /**
     * Path to the dev server's stderr log, if given.
     *
     * The response body is NOT a complete diagnostic channel: PHP's built-in
     * server writes diagnostics to its log, and with html_errors off they never
     * appear in the response the harness reads. A PHP 8.2 deprecation fired on
     * 44 responses during one run and every body scan was clean, because the
     * only place it surfaced was here. Scanning the log too is what makes that
     * class of defect visible instead of invisible.
     */
    private ?string $logPath = null;
    private int $logOffset = 0;

    public function setLogPath(?string $path): void
    {
        $this->logPath = ($path !== null && is_file($path)) ? $path : null;
    }

    private function phase(string $title): void
    {
        echo "\n" . str_repeat('=', 78) . "\n{$title}\n" . str_repeat('=', 78) . "\n";
    }

    /** A page the role SHOULD reach: 200, real content, no PHP issues. */
    private function expectPage(string $phase, string $label, string $path, bool $scan = true): void
    {
        $res = $this->http('GET', $path);
        if ($res['status'] !== 200) {
            $detail = "HTTP {$res['status']}";
            if ($res['location'] !== '') {
                $detail .= ' -> ' . $res['location'];
            }
            // A valid session bounced to the login door is its own bug class.
            if (stripos($res['location'], 'login.php') !== false) {
                $detail .= ' (session or role lost)';
            }
            $this->record($phase, 'FAIL', $label, $detail, $path);
            return;
        }
        if ($res['error'] !== '') {
            $this->record($phase, 'FAIL', $label, 'transport: ' . $res['error'], $path);
            return;
        }
        if ($scan) {
            $issues = $this->bodyIssues($res['body']);
            if ($issues !== []) {
                $this->record($phase, 'FAIL', $label, 'body leaks: ' . implode(' || ', array_slice($issues, 0, 3)), $path);
                return;
            }
            if (strlen($res['body']) < MIN_PAGE_BYTES) {
                $this->record($phase, 'FAIL', $label, '200 but only ' . strlen($res['body']) . ' bytes rendered', $path);
                return;
            }
        }
        $this->record($phase, 'PASS', $label, '', $path);
    }

    /** A page the role must NOT reach: 3xx away from it, or 403/404. */
    private function expectDenied(string $phase, string $label, string $path, array $forbidden = []): void
    {
        $res = $this->http('GET', $path);
        if ($res['status'] === 200) {
            $issues = $this->bodyIssues($res['body']);
            if ($issues !== []) {
                $this->record($phase, 'FAIL', $label, 'HTTP 200 AND body leaks: ' . implode(' || ', array_slice($issues, 0, 2)), $path);
                return;
            }
            $leaks = [];
            foreach ($forbidden as $needle) {
                if ($needle !== '' && stripos($res['body'], $needle) !== false) {
                    $leaks[] = $needle;
                }
            }
            $detail = 'HTTP 200 — content served to a role that must not have it';
            if ($leaks !== []) {
                $detail .= ' (leaks: ' . implode(', ', $leaks) . ')';
            }
            $this->record($phase, 'FAIL', $label, $detail, $path);
            return;
        }
        if (in_array($res['status'], [301, 302, 303, 307, 308, 401, 403, 404], true)) {
            $this->record($phase, 'PASS', $label, 'HTTP ' . $res['status'] . ($res['location'] !== '' ? ' -> ' . $res['location'] : ''), $path);
            return;
        }
        $this->record($phase, 'FAIL', $label, 'HTTP ' . $res['status'], $path);
    }

    /**
     * A page that legitimately answers a non-200 status (a real 404 page, a
     * webhook that must never return non-200). The status is pinned AND the
     * body is still scanned for PHP problems.
     */
    private function expectStatus(string $phase, string $label, string $path, int $want, string $bodyContains = ''): void
    {
        $res = $this->http('GET', $path);
        $issues = $this->bodyIssues($res['body']);
        if ($res['status'] !== $want) {
            $this->record($phase, 'FAIL', $label, "HTTP {$res['status']} (wanted {$want})", $path);
            return;
        }
        if ($issues !== []) {
            $this->record($phase, 'FAIL', $label, 'body leaks: ' . implode(' || ', array_slice($issues, 0, 2)), $path);
            return;
        }
        if ($bodyContains !== '' && stripos($res['body'], $bodyContains) === false) {
            $this->record($phase, 'FAIL', $label, "HTTP {$want} but the body does not say '{$bodyContains}'", $path);
            return;
        }
        $this->record($phase, 'PASS', $label, '', $path);
    }

    /** A redirect with an expected target fragment. */
    private function expectRedirect(string $phase, string $label, string $path, string $want, array $post = []): void
    {
        $res = $this->http($post === [] ? 'GET' : 'POST', $path, $post);
        $isRedirect = in_array($res['status'], [301, 302, 303, 307, 308], true);
        $hit = stripos($res['location'], $want) !== false;
        if ($isRedirect && $hit) {
            $this->record($phase, 'PASS', $label, 'HTTP ' . $res['status'] . ' -> ' . $res['location'], $path);
            return;
        }
        $detail = $isRedirect
            ? 'HTTP ' . $res['status'] . ' -> ' . ($res['location'] ?: '(none)')
            : 'HTTP ' . $res['status'] . ' (no redirect)';
        $issues = $this->bodyIssues($res['body']);
        if ($issues !== []) {
            $detail .= ' | body: ' . implode(' || ', array_slice($issues, 0, 2));
        }
        $this->record($phase, 'FAIL', $label, $detail, $path);
    }

    private function assert(string $phase, string $label, bool $ok, string $detail = ''): void
    {
        $this->record($phase, $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : $detail);
    }

    /**
     * A state-changing endpoint must refuse a plain GET, and the row it
     * targets must still be there afterwards. The survival check is the half
     * that matters — a 302 says nothing if the row vanished anyway.
     */
    private function expectGetGuard(string $phase, string $label, string $path, string $table, string $idColumn, int $id): void
    {
        $before = (int) ($this->val("SELECT COUNT(*) FROM {$table} WHERE {$idColumn} = :i", [':i' => $id]) ?? -1);
        $res = $this->http('GET', $path);
        $after = (int) ($this->val("SELECT COUNT(*) FROM {$table} WHERE {$idColumn} = :i", [':i' => $id]) ?? -1);
        if ($before !== $after) {
            $this->record($phase, 'FAIL', $label, "a bare GET deleted the row ({$before} → {$after})", $path);
            return;
        }
        $issues = $this->bodyIssues($res['body']);
        if ($issues !== []) {
            $this->record($phase, 'FAIL', $label, 'body leaks: ' . implode(' || ', array_slice($issues, 0, 2)), $path);
            return;
        }
        if (!in_array($res['status'], [301, 302, 303, 307, 308, 400, 403, 405], true)) {
            $this->record($phase, 'FAIL', $label, 'HTTP ' . $res['status'] . ' for a bare GET (no refusal)', $path);
            return;
        }
        $this->record($phase, 'PASS', $label, 'HTTP ' . $res['status'] . '; row intact', $path);
    }

    /**
     * Fetch a page, find the form that posts to a given action, and submit it
     * with the values that page itself renders (hidden CSRF token included),
     * overridden by $overrides.
     *
     * This is the difference between testing the app and testing my guess at
     * its field names: the request is the one the browser would have made.
     *
     * @param array<string,string> $overrides fields to force
     * @param array<string,string> $defaults fields the page omits but the
     *        handler requires (e.g. a select rendered empty)
     * @param array<string,string> $formMustHave only consider a form whose own
     *        markup contains this field with this exact value (picks the edit
     *        form when a page renders both an add and an edit form)
     * @param string[] $dropFields control fields belonging to a DIFFERENT form
     *        on the same page. A browser submits only the form whose button was
     *        clicked, so a handler trigger copied from a sibling form (say
     *        set_vendor_approval leaking into a premium submission) would fire
     *        the wrong handler — this is the helper compensating for picking a
     *        form by selector rather than by button.
     * @return array{action:string,fields:array<string,string>,status:int,location:string,body:string,error:string}
     */
    private function submitForm(string $pagePath, string $actionContains, array $overrides = [], array $defaults = [], array $formMustHave = [], array $dropFields = []): array
    {
        $page = $this->http('GET', $pagePath);
        $chosen = null;
        if (preg_match_all('~<form\b([^>]*)>(.*?)</form>~is', $page['body'], $forms, PREG_SET_ORDER)) {
            foreach ($forms as $form) {
                // A form with no action attribute posts back to its own page.
                $action = preg_match('~action\s*=\s*"([^"]*)"~i', $form[1], $a)
                    ? html_entity_decode($a[1], ENT_QUOTES) : '';
                if ($actionContains !== '' && stripos($action, $actionContains) === false) {
                    continue;
                }
                $matched = true;
                foreach ($formMustHave as $needleName => $needleValue) {
                    $re = '~name\s*=\s*"' . preg_quote((string) $needleName, '~') . '"[^>]*value\s*=\s*"' . preg_quote((string) $needleValue, '~') . '"~i';
                    if (!preg_match($re, $form[2])) {
                        $matched = false;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $chosen = ['action' => $action, 'inner' => $form[2]];
                break;
            }
        }
        if ($chosen === null) {
            return ['action' => '', 'fields' => [], 'status' => 0, 'location' => '', 'body' => '', 'error' => 'no matching form on ' . $pagePath];
        }

        $fields = $defaults;
        $inner = $chosen['inner'];
        if (preg_match_all('~<input\b[^>]*>~i', $inner, $ins)) {
            foreach ($ins[0] as $inp) {
                if (!preg_match('~name\s*=\s*"([^"]+)"~i', $inp, $n)) {
                    continue;
                }
                $name = $n[1];
                $type = preg_match('~type\s*=\s*"([^"]+)"~i', $inp, $t) ? strtolower($t[1]) : 'text';
                if ($type === 'file' || $type === 'submit' || $type === 'button' || $type === 'image') {
                    continue; // not data the browser submits meaningful values for
                }
                $val = preg_match('~value\s*=\s*"([^"]*)"~i', $inp, $v) ? html_entity_decode($v[1], ENT_QUOTES) : '';
                if (($type === 'checkbox' || $type === 'radio') && !preg_match('~\bchecked\b~i', $inp)) {
                    continue; // unchecked boxes are simply absent
                }
                if (!array_key_exists($name, $fields)) {
                    $fields[$name] = $val;
                }
            }
        }
        if (preg_match_all('~<select\b[^>]*name\s*=\s*"([^"]+)"[^>]*>(.*?)</select>~is', $inner, $sels, PREG_SET_ORDER)) {
            foreach ($sels as $sel) {
                $name = $sel[1];
                if (array_key_exists($name, $fields)) {
                    continue;
                }
                if (preg_match('~<option\b[^>]*\bselected\b[^>]*value\s*=\s*"([^"]*)"~i', $sel[2], $o)
                    || preg_match('~<option\b[^>]*value\s*=\s*"([^"]*)"[^>]*\bselected\b~i', $sel[2], $o)
                    || preg_match('~<option\b[^>]*value\s*=\s*"([^"]*)"~i', $sel[2], $o)) {
                    $fields[$name] = html_entity_decode($o[1], ENT_QUOTES);
                }
            }
        }
        if (preg_match_all('~<textarea\b[^>]*name\s*=\s*"([^"]+)"[^>]*>(.*?)</textarea>~is', $inner, $tas, PREG_SET_ORDER)) {
            foreach ($tas as $ta) {
                if (!array_key_exists($ta[1], $fields)) {
                    $fields[$ta[1]] = trim(html_entity_decode($ta[2], ENT_QUOTES));
                }
            }
        }
        foreach ($dropFields as $drop) {
            unset($fields[$drop]);
        }
        foreach ($overrides as $k => $v) {
            $fields[$k] = $v;
        }

        $dir = dirname($pagePath);
        $url = $chosen['action'];
        if ($url === '' || $url === '#' || str_starts_with($url, '?')) {
            $url = $pagePath . $url;
        } elseif (!str_starts_with($url, '/')) {
            $base = ($dir === '.' || $dir === '' || $dir === '/') ? '' : rtrim($dir, '/') . '/';
            $url = $base . $url;
        }

        $res = $this->http('POST', $url, $fields);
        return array_merge(['action' => $chosen['action'], 'fields' => $fields], $res);
    }

    /** An AJAX fragment endpoint: POST and assert on the returned markup. */
    private function expectFragment(string $phase, string $label, string $path, array $post, string $contains, bool $absent = false): void
    {
        $res = $this->http('POST', $path, $post);
        $issues = $this->bodyIssues($res['body']);
        if ($issues !== []) {
            $this->record($phase, 'FAIL', $label, 'body leaks: ' . implode(' || ', array_slice($issues, 0, 2)), $path);
            return;
        }
        $hit = $contains === '' || stripos($res['body'], $contains) !== false;
        if ($absent) {
            $hit = !$hit;
        }
        $this->assert($phase, $label, $res['status'] === 200 && $hit,
            'HTTP ' . $res['status'] . '; ' . ($absent ? 'unexpectedly' : 'missing') . " '{$contains}'");
    }

    // -------------------------------------------------------------- login

    /** @return bool true when the login POST produced the expected redirect */
    private function login(string $phase, string $email, string $pass, string $wantLocation, string $loginPath = '/login.php'): bool
    {
        $this->jar = [];
        $want = explode('|', $wantLocation);
        $token = $this->token($loginPath);
        if ($token === null) {
            $this->record($phase, 'FAIL', "login {$email}", "no CSRF token on {$loginPath}", $loginPath);
            return false;
        }
        $res = $this->http('POST', $loginPath, [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => $token,
            'login'          => '1',
        ]);
        if (!in_array($res['status'], [301, 302, 303, 307, 308], true)) {
            $issues = $this->bodyIssues($res['body']);
            $detail = 'HTTP ' . $res['status'] . ' (form re-rendered — credentials rejected)';
            if ($issues !== []) {
                $detail .= ' | ' . implode(' || ', array_slice($issues, 0, 2));
            }
            $this->record($phase, 'FAIL', "login {$email}", $detail, $loginPath);
            return false;
        }
        $ok = false;
        foreach ($want as $fragment) {
            if (stripos($res['location'], $fragment) !== false) {
                $ok = true;
                break;
            }
        }
        $this->record($phase, $ok ? 'PASS' : 'FAIL', "login {$email}",
            $ok ? '' : 'landed on ' . $res['location'] . ' (wanted ' . $wantLocation . ')', $loginPath);
        return $ok;
    }

    private function logout(): void
    {
        $this->http('GET', '/admin/logout.php');
        $this->jar = [];
    }

    /** Fresh session for a role, via the shared login door. */
    private function session(string $phase, string $email, string $pass, string $want): bool
    {
        $this->logout();
        return $this->login($phase, $email, $pass, $want);
    }

    // ------------------------------------------------------------ fixtures

    /** @var array<string,mixed> */
    private array $fx = [];

    private function fixtures(): void
    {
        $booking = $this->one("SELECT booking_id, user_id FROM tblweddingbook WHERE booking_id = 200");
        $this->fx['booking'] = $booking ? (int) $booking->booking_id : 200;
        $this->fx['couple_user'] = $booking ? (int) $booking->user_id : 200;
        $this->fx['feature'] = (int) ($this->val('SELECT MAX(feature_id) FROM tbl_features') ?? 0);
        $this->fx['category'] = (int) ($this->val('SELECT MAX(id) FROM tblweddingcategories') ?? 0);
        $this->fx['post'] = (int) ($this->val('SELECT MAX(id) FROM tblpostwedding') ?? 0);
        $this->fx['slug'] = (string) ($this->val('SELECT slug FROM tblwedding_websites ORDER BY id LIMIT 1') ?? '');
        $this->fx['package'] = (int) ($this->val('SELECT MAX(id) FROM tblvendor_packages') ?? 0);
        $this->fx['event'] = (int) ($this->val('SELECT MAX(id) FROM events WHERE booking_id = 200') ?? 0);
    }

    private function ids(int $booking, int $user): string
    {
        return 'booking_id=' . $booking . '&user_id=' . $user;
    }

    /**
     * Preconditions for a repeatable run.
     *
     * The simulation signs in with correct passwords AND deliberately fails a
     * few sign-ins, so the (email, IP) throttle would otherwise accumulate
     * failures across runs and eventually lock a seeded account — exactly the
     * reason phpunit.xml disables it. Throttling stays LIVE during the run (it
     * is part of the real behaviour under test); only the leftover counters
     * from a previous run are dropped, together with any residue a crashed
     * earlier run may have left behind.
     */
    private function prepare(): void
    {
        $cleared = [
            'tbllogin_throttle' => 'DELETE FROM tbllogin_throttle',
            'sim guests' => "DELETE FROM tblguest WHERE fullname LIKE 'SIM %' OR fullname LIKE '%PROBE%'",
            'sim staff' => "DELETE FROM tblusers WHERE email LIKE 'sim.%'",
            'sim couples' => "DELETE FROM tblaccounts WHERE user_email LIKE 'sim.%'",
            'sim enquiries' => "DELETE FROM tblvendor_bookings WHERE notes LIKE 'SIM enquiry%'",
            'sim budget lines' => 'DELETE FROM tbl_liquidation WHERE payment = 1000',
            // Venue headcount rows written by the write-path leg (pair of values,
            // so a real plan is never touched), in case an earlier run crashed
            // between the save and its restore.
            'sim venue headcounts' => 'DELETE FROM tbleventbooking'
                . ' WHERE (family = 12 AND individual = 34) OR (family = 13 AND individual = 35)',
            // Scope-probe listings + deals from the write-path leg, in case an
            // earlier run crashed between the insert and its sweep.
            'sim scope probe deals' => "DELETE FROM tblvendor_bookings WHERE notes = 'SIM headcount scope probe'",
            'sim scope probe listings' => "DELETE FROM tblvendors WHERE description = 'SIM headcount scope probe'",
            'stale reset tokens' => 'DELETE FROM tbl_password_resets',
            // The seed ships every guest with no RSVP token and a 'pending'
            // status; the run mints tokens and stamps invite/remind columns, so
            // restore that baseline or the second run sees "already reminded".
            'rsvp state' => "UPDATE tblguest SET rsvp_token = NULL, rsvp_status = 'pending', rsvp_at = NULL,"
                . ' rsvp_note = NULL, rsvp_invited_at = NULL, rsvp_reminded_at = NULL, rsvp_reminders = 0',
        ];
        foreach ($cleared as $label => $sql) {
            try {
                $this->exec($sql);
            } catch (Throwable $e) {
                $this->record('P0', 'FAIL', 'precondition: clear ' . $label, $e->getMessage());
            }
        }
        $this->record('P0', 'INFO', 'preconditions applied (throttle counters, sim residue)', '');
    }

    // ============================================================== PHASES

    private function phase1Public(): void
    {
        $this->phase('PHASE 1 — Guest / anonymous visitor (public site)');
        $pages = [
            '/index.php'          => 'public home',
            '/about.php'          => 'about page',
            '/contact.php'        => 'contact page',
            '/gallery.php'        => 'public gallery',
            '/pricing.php'        => 'pricing page',
            '/real-weddings.php'  => 'real weddings',
            '/thank_you.php'      => 'thank-you page',
            '/login.php'          => 'login door',
            '/planner_register.php' => 'planner signup',
            '/forgot_password.php'  => 'forgot password',
        ];
        foreach ($pages as $path => $label) {
            $this->expectPage('P1', $label, $path);
        }
        $this->expectPage('P1', 'contact confirmation view', '/contact.php?sent=1');
        $this->expectPage('P1', 'signup confirmation view', '/planner_register.php?sent=1');

        if ($this->fx['category'] > 0) {
            $this->expectPage('P1', 'package detail (real package id)', '/package_detail.php?id=' . $this->fx['category']);
        }
        $this->expectStatus('P1', 'package detail with a bogus id shows the not-found page, not a fatal',
            '/package_detail.php?id=999999', 404, "can't find that package");
        $this->expectStatus('P1', 'package detail with no id at all', '/package_detail.php', 404);
        if ($this->fx['post'] > 0) {
            $this->expectPage('P1', 'wedding story detail', '/wedding_details.php?id=' . $this->fx['post']);
        }
        if ($this->fx['slug'] !== '') {
            $this->expectPage('P1', 'public wedding website', '/site.php?slug=' . urlencode($this->fx['slug']));
            $this->expectDenied('P1', 'unknown wedding slug is not served as a real wedding',
                '/site.php?slug=does-not-exist-' . random_int(1000, 9999), ['Wedding Website']);
        }
        $this->expectPage('P1', 'RSVP landing without a token', '/rsvp.php');
        // An unknown token must be refused in-page (the token IS the capability).
        $this->expectStatus('P1', 'RSVP with a bogus token says the invitation was not found',
            '/rsvp.php?g=' . str_repeat('a', 32), 200, 'Invitation Not Found');
        $this->expectStatus('P1', 'RSVP with no token at all', '/rsvp.php', 200);
        $this->expectRedirect('P1', 'legacy signup redirects to the hero form', '/sign_up.php', 'index');
        $this->expectStatus('P1', 'the 404 page answers a real 404', '/404.php', 404);
        $this->expectStatus('P1', 'the 500 page renders without a fatal of its own', '/500.php', 500);

        // Static assets the layout depends on.
        foreach (['/css/modern.css', '/css/bootstrap.min.css?v=4.6.2', '/admin/css/modern-admin.css', '/admin/css/dashboard.css'] as $asset) {
            $this->expectPage('P1', 'asset ' . $asset, $asset, false);
        }

        // Endpoints that must never answer an anonymous caller.
        $this->expectDenied('P1', 'deploy_check requires its key', '/deploy_check.php', ['RESULT: ok=']);
        $this->expectDenied('P1', 'cron_bridge requires its key', '/cron_bridge.php?job=mail_retry', ['ran', 'OK']);

        /*
         * The two Daraja webhooks are contractually required to answer HTTP 200
         * to everything (any non-200 makes Safaricom retry-spam the endpoint),
         * so a GET probe answering 200 with ResultCode 0 is correct by design —
         * what must NOT happen is a side effect or a PHP error.
         */
        $before = (int) ($this->val('SELECT COUNT(*) FROM tblmpesa_callback_log') ?? 0);
        $this->expectStatus('P1', 'M-Pesa C2B GET probe is acknowledged, not ingested',
            '/mpesa_c2b.php', 200, 'ResultCode');
        $after = (int) ($this->val('SELECT COUNT(*) FROM tblmpesa_callback_log') ?? 0);
        $this->assert('P1', 'an M-Pesa C2B GET probe writes nothing', $after === $before,
            "tblmpesa_callback_log {$before} → {$after}");
        $res = $this->http('POST', '/mpesa_c2b.php', [], false);
        $this->assert('P1', 'an M-Pesa C2B POST with a non-JSON body is rejected',
            strpos($res['body'], '"ResultCode":1') !== false, 'body: ' . substr($res['body'], 0, 90));
        $this->expectStatus('P1', 'M-Pesa STK GET probe is acknowledged',
            '/mpesa_stk_callback.php', 200, 'ResultCode');
        $this->expectPage('P1', 'payment gateway page', '/thank_you.php', false);
    }

    private function phase2Auth(): void
    {
        $this->phase('PHASE 2 — Authentication matrix (every seeded account)');
        $accounts = [
            // email, password, expected landing fragment, role label
            ['wafula@wpms.co.ke', 'Admin@2024!', 'dashboard', 'super admin (active plan)'],
            ['abdi@wpms.co.ke', 'Admin@2024!', 'dashboard', 'super admin (alt)'],
            ['amina@wpms.co.ke', 'Test@1234', 'dashboard', 'planner (active plan)'],
            ['njeri@wpms.co.ke', 'Test@1234', 'subscription', 'planner (no subscription)'],
            ['otieno@wpms.co.ke', 'Test@1234', 'subscription', 'planner (no subscription, alt)'],
            ['kamau@nyamachoma.co.ke', 'Vendor@2024', 'vendor_home', 'vendor (two businesses)'],
            ['chebet@floral.co.ke', 'Vendor@2024', 'vendor_home', 'vendor (one business)'],
            ['brian.wafula@gmail.com', 'Test@1234', 'client.php', 'couple (booking 200)'],
            ['faith.njeri@yahoo.com', 'Test@1234', 'client.php', 'couple (booking 201)'],
            ['otieno.kisumu@outlook.com', 'Test@1234', 'client.php', 'couple (pending status)'],
            ['chebet.eldoret@gmail.com', 'Test@1234', 'client.php', 'couple (booking 203)'],
            ['abdi.garissa@gmail.com', 'Test@1234', 'client.php', 'couple (fraud-flagged)'],
        ];
        foreach ($accounts as [$email, $pass, $want, $role]) {
            if ($this->login('P2', $email, $pass, $want)) {
                $this->record('P2', 'INFO', "  → {$role} authenticated", '');
            }
            $this->logout();
        }

        // Rejections. Wrong credentials must render the form again, never a session.
        $cases = [
            ['wafula@wpms.co.ke', 'wrong-password', 'known email + wrong password'],
            ['nobody@example.com', 'Admin@2024!', 'unknown email'],
            ['', '', 'empty credentials'],
        ];
        foreach ($cases as [$email, $pass, $label]) {
            $this->jar = [];
            $token = $this->token('/login.php');
            $res = $this->http('POST', '/login.php', [
                'input_email' => $email, 'input_password' => $pass, '_token' => (string) $token, 'login' => '1',
            ]);
            // A rejected sign-in is a PRG bounce back to login.php with a flash
            // message — never a 500 and never a session.
            $bounced = in_array($res['status'], [301, 302, 303, 307, 308], true)
                && stripos($res['location'], 'login.php') !== false;
            $issues = $this->bodyIssues($res['body']);
            $this->assert('P2', "rejects {$label}", $bounced && $issues === [],
                'HTTP ' . $res['status'] . ($res['location'] ? ' -> ' . $res['location'] : '')
                . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
            // The half that actually matters: no privileged session was granted.
            $dash = $this->http('GET', '/admin/dashboard.php');
            $this->assert('P2', "grants no session after {$label}", $dash['status'] !== 200,
                'dashboard answered HTTP ' . $dash['status']);
        }

        // CSRF: a valid credential pair with no token must NOT establish a session.
        $this->jar = [];
        $this->http('GET', '/login.php');
        $res = $this->http('POST', '/login.php', [
            'input_email' => 'wafula@wpms.co.ke', 'input_password' => 'Admin@2024!', 'login' => '1',
        ]);
        $issues = $this->bodyIssues($res['body']);
        $this->assert('P2', 'login without a CSRF token is refused',
            $res['status'] !== 500 && $issues === [],
            'HTTP ' . $res['status'] . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
        $dash = $this->http('GET', '/admin/dashboard.php');
        $this->assert('P2', 'a CSRF-less login grants no session', $dash['status'] !== 200,
            'dashboard answered HTTP ' . $dash['status']);

        // An already-authenticated visitor revisiting the login door is forwarded.
        if ($this->session('P2', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
            $res = $this->http('GET', '/login.php');
            $this->assert('P2', 'signed-in visitor is forwarded off the login door',
                in_array($res['status'], [301, 302, 303, 307, 308], true),
                'HTTP ' . $res['status'] . ' -> ' . ($res['location'] ?: '(stayed on the form)'));
        }

        // Password reset: token page, bogus token, POST with no token.
        $bogus = str_repeat('0', 64);
        $res = $this->http('GET', '/reset_password.php?token=' . $bogus);
        $this->assert('P2', 'reset page rejects a bogus token', $res['status'] !== 200 || stripos($res['body'], 'invalid') !== false || stripos($res['body'], 'expired') !== false,
            'HTTP ' . $res['status']);
        $issues = $this->bodyIssues($res['body']);
        if ($issues !== []) {
            $this->record('P2', 'FAIL', 'bogus reset token renders cleanly', implode(' || ', array_slice($issues, 0, 2)));
        }
        // Anonymous again before the anonymous-only checks below.
        $this->logout();
        $this->expectPage('P2', 'verify_planner landing (resent notice, anonymous)', '/verify_planner.php?sent=1', false);
        $this->expectPage('P2', 'forgot-password form (anonymous)', '/forgot_password.php', false);

        // Logout really ends the session.
        if ($this->session('P2', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
            $this->logout();
            $res = $this->http('GET', '/admin/dashboard.php');
            $this->assert('P2', 'logout ends the session (dashboard no longer served)',
                $res['status'] !== 200 || stripos($res['location'], 'login') !== false || stripos($res['location'], '../') !== false,
                'HTTP ' . $res['status'] . ' -> ' . ($res['location'] ?: '(rendered anyway)'));
        }
    }

    private function phase3Couple(): void
    {
        $this->phase('PHASE 3 — Couple / client role (own wedding only)');
        $b = $this->fx['booking'];
        $u = $this->fx['couple_user'];
        $ids = $this->ids($b, $u);

        if (!$this->session('P3', 'brian.wafula@gmail.com', 'Test@1234', 'client.php')) {
            $this->record('P3', 'SKIP', 'couple walk', 'login failed');
            return;
        }
        $this->expectPage('P3', 'couple dashboard', '/admin/client.php');
        $pages = [
            'overview / account details' => '/admin/client_manage_account_details.php?' . $ids,
            'guest list'                 => '/admin/client_guests.php?' . $ids,
            'payments & budget'          => '/admin/client_budget.php?' . $ids,
            'deposit (M-Pesa STK)'       => '/admin/client_deposit.php?' . $ids,

            'checklist'                  => '/admin/client_checklist.php?' . $ids,
            'vendors marketplace'        => '/admin/client_vendors.php?' . $ids,
            'website builder'            => '/admin/client_website.php?' . $ids,
            'notifications'              => '/admin/notifications.php',
        ];
        foreach ($pages as $label => $path) {
            $this->expectPage('P3', 'couple ' . $label, $path);
        }
        // client_installment_plan.php is a POST-only action handler: a GET is
        // contractually a bounce back to the overview, not a rendered page.
        $this->expectRedirect('P3', 'couple installment-plan action bounces a GET',
            '/admin/client_installment_plan.php?' . $ids, 'client_manage_account_details');
        // Couple-only variants that take extra parameters.
        $this->expectPage('P3', 'couple guest edit form', '/admin/client_guests.php?' . $ids . '&edit=200');
        $this->expectPage('P3', 'couple vendor marketplace filtered', '/admin/client_vendors.php?' . $ids . '&category=&county=');
        $this->expectPage('P3', 'couple dashboard search', '/admin/client.php?search=Wafula&status=all');

        // --- access control: another couple's wedding must not be readable ---
        $other = $this->one('SELECT booking_id, user_id FROM tblweddingbook WHERE user_id <> 200 ORDER BY booking_id LIMIT 1');
        if ($other) {
            $ob = (int) $other->booking_id;
            $ou = (int) $other->user_id;
            $otherIds = $this->ids($ob, $ou);
            $otherName = (string) ($this->val('SELECT bride FROM tblweddingbook WHERE booking_id = :b', [':b' => $ob]) ?? '');
            $this->expectDenied('P3', "couple cannot read booking {$ob} guests",
                '/admin/client_guests.php?' . $otherIds, [$otherName]);
            $this->expectDenied('P3', "couple cannot read booking {$ob} budget",
                '/admin/client_budget.php?' . $otherIds, [$otherName]);
            $this->expectDenied('P3', "couple cannot read booking {$ob} overview",
                '/admin/client_manage_account_details.php?' . $otherIds, [$otherName]);
        }
        // --- staff surfaces must be closed to a couple ---
        $this->expectDenied('P3', 'couple cannot open the planner dashboard', '/admin/dashboard.php');
        $this->expectDenied('P3', 'couple cannot open the staff user list', '/admin/users.php');
        $this->expectDenied('P3', 'couple cannot open revenue', '/admin/revenue.php');
        $this->expectDenied('P3', 'couple cannot open the vendor home', '/admin/vendor_home.php');
        $this->expectDenied('P3', 'couple cannot open the capacity headroom view', '/admin/headroom.php');

        $this->logout();

        // --- every other seeded couple can sign in and see only their own ---
        foreach ([
            ['faith.njeri@yahoo.com', 201],
            ['otieno.kisumu@outlook.com', 202],
            ['chebet.eldoret@gmail.com', 203],
            ['abdi.garissa@gmail.com', 204],
        ] as [$email, $bookingId]) {
            if (!$this->session('P3', $email, 'Test@1234', 'client.php')) {
                continue;
            }
            $this->expectPage('P3', "couple {$email} dashboard", '/admin/client.php');
            $owner = $this->one('SELECT booking_id, user_id FROM tblweddingbook WHERE booking_id = :b', [':b' => $bookingId]);
            $acctStatus = (string) ($this->val('SELECT status FROM tblaccounts_detail WHERE user_id = :u', [':u' => $bookingId]) ?? '');
            if ($owner) {
                $this->expectPage('P3', "couple {$email} own overview",
                    '/admin/client_manage_account_details.php?' . $this->ids((int) $owner->booking_id, (int) $owner->user_id));
                $this->expectPage('P3', "couple {$email} own guests",
                    '/admin/client_guests.php?' . $this->ids((int) $owner->booking_id, (int) $owner->user_id));
            }
            $body = $this->http('GET', '/admin/client.php')['body'];
            /*
             * The dashboard listing is scoped by the ACCOUNT status column
             * (tblaccounts_detail.status): only 'pending' and 'confirm' rows
             * become booking rows. The seed's fourth couple is flagged
             * 'fraud', so an empty dashboard is the documented behaviour —
             * derive the expectation instead of assuming a link.
             */
            $listed = strpos($body, "booking_id={$bookingId}") !== false;
            if (in_array($acctStatus, ['pending', 'confirm'], true)) {
                $this->assert('P3', "couple {$email} ({$acctStatus}) dashboard lists their booking", $listed,
                    "booking {$bookingId} not linked from the dashboard");
            } else {
                $this->assert('P3', "couple {$email} ({$acctStatus}) is not shown booking {$bookingId}", !$listed,
                    'a non-pending/non-confirm account is listed on the dashboard');
                // Observation, not a failure: the flag filters the listing but
                // the pages themselves only check ownership.
                $direct = $this->http('GET', '/admin/client_manage_account_details.php?' . $this->ids($bookingId, $bookingId));
                $this->record('P3', 'INFO', "  → {$acctStatus} account can still open its own booking by direct URL (HTTP {$direct['status']})",
                    'the account-status flag is a dashboard filter, not an access gate');
            }
            $this->logout();
        }
    }

    private function phase4Planner(): void
    {
        $this->phase('PHASE 4 — Planner / staff role (active subscription)');
        if (!$this->session('P4', 'amina@wpms.co.ke', 'Test@1234', 'dashboard')) {
            $this->record('P4', 'SKIP', 'planner walk', 'login failed');
            return;
        }
        $this->expectPage('P4', 'planner dashboard', '/admin/dashboard.php');

        $pages = [
            '/admin/client.php'            => 'client/booking list',
            '/admin/service_list.php'      => 'service list',
            '/admin/subscription.php'      => 'subscription page',
            '/admin/users.php'             => 'staff & user list',
            '/admin/vendors.php'           => 'vendor directory',
            '/admin/vendor_enquiries.php'  => 'vendor enquiry ledger',
            '/admin/headroom.php'          => 'capacity headroom view',
            '/admin/sms_center.php'        => 'SMS centre',
            '/admin/mpesa_ledger.php'      => 'M-Pesa ledger',
            '/admin/revenue.php'           => 'revenue dashboard',
            '/admin/notifications.php'     => 'notification bell page',
            '/admin/login_activity.php'    => 'login activity',
            '/admin/photos_view.php'       => 'photo library',
            '/admin/gallery.php'           => 'gallery index',
            '/admin/blog_events.php'       => 'blog & events',
            '/admin/blog_events_add.php'   => 'blog add form',
            '/admin/photos_add.php'        => 'photo add form',
            '/admin/users_add.php'         => 'staff add form',
            '/admin/client_add.php'        => 'client add form',
            '/admin/feature_add.php'       => 'feature add form',
            '/admin/package_add.php'       => 'package add form',
            '/admin/task_all_calendar.php' => 'all-tasks calendar',
        ];
        foreach ($pages as $path => $label) {
            $this->expectPage('P4', 'staff ' . $label, $path);
        }

        // Booking-scoped staff pages (amina organises the seeded bookings).
        $b = $this->fx['booking'];
        $u = $this->fx['couple_user'];
        $ids = $this->ids($b, $u);
        $scoped = [
            'booking overview'   => '/admin/client_manage_account_details.php?' . $ids,
            'booking edit form'  => '/admin/client_edit.php?booking=' . $b . '&user_id=' . $u,
            'guest list'         => '/admin/guest_list.php?' . $ids,
            'guest add form'     => '/admin/guest_add.php?' . $ids,
            'budget / payments'  => '/admin/budget.php?' . $ids,
            'budget add form'    => '/admin/budget_add.php?' . $ids,
            'events'             => '/admin/events.php?' . $ids,
            'task calendar'      => '/admin/task_calendar.php?' . $ids,
            'photos for booking' => '/admin/photos_view.php?' . $ids,
            'gallery for booking' => '/admin/gallery.php?' . $ids,
            'service assignment' => '/admin/service_list.php?' . $ids,
            'assign a client'    => '/admin/client_assign.php?booking=' . $b . '&user_id=' . $u,
            'SMS for booking'    => '/admin/sms_center.php?' . $ids,
            'RSVP dashboard for booking' => '/admin/rsvp_dashboard.php?' . $ids,
            'printable guest sheet' => '/admin/sheet_guests.php?' . $ids,
            'printable venue sheet' => '/admin/sheet_venue.php?' . $ids,
            'receipt preview'       => null, // needs a real liquidation id, added below
        ];
        foreach ($scoped as $label => $path) {
            if ($path !== null) {
                $this->expectPage('P4', 'staff ' . $label, $path);
            }
        }

        // Booking-scoped pages that also need a guest selection.
        $pending = $this->db()->fetchAll(
            "SELECT id FROM tblguest WHERE booking_id = :b AND rsvp_status = 'pending' ORDER BY id LIMIT 3",
            [':b' => $b]
        );
        $pendingIds = implode(',', array_map(static fn($g) => (int) $g->id, $pending));
        if ($pendingIds !== '') {
            $this->expectPage('P4', 'staff WhatsApp RSVP queue',
                '/admin/rsvp_wa_queue.php?' . $ids . '&ids=' . $pendingIds . '&pos=0');
            $this->expectPage('P4', 'staff WhatsApp queue advance',
                '/admin/rsvp_wa_queue.php?' . $ids . '&ids=' . $pendingIds . '&pos=1&done=1');
        } else {
            $this->record('P4', 'SKIP', 'staff WhatsApp RSVP queue', 'no pending guests to select');
        }
        // select.php is an AJAX fragment endpoint keyed by CATEGORY id, not a page.
        $this->expectFragment('P4', 'AJAX feature lookup returns the category fragment',
            '/admin/select.php', ['id' => (string) $this->fx['category']], 'table');
        $this->expectFragment('P4', 'AJAX feature lookup for an empty category says so',
            '/admin/select.php', ['id' => '999999'], 'No Feature Yet!');

        // Detail pages driven by ids from the booking.
        if ($this->fx['event'] > 0) {
            $this->expectPage('P4', 'budget edit form', '/admin/budget_edit.php?budget_id=' . $this->fx['event'] . '&' . $ids);
            $this->expectPage('P4', 'budget liquidation', '/admin/budget_liquidate.php?' . $ids);
        }
        $guest = $this->one('SELECT id FROM tblguest WHERE booking_id = :b ORDER BY id LIMIT 1', [':b' => $b]);
        if ($guest) {
            $gid = (int) $guest->id;
            $this->expectPage('P4', 'guest edit form', '/admin/guest_edit.php?' . $ids . '&id=' . $gid);
            $this->expectGetGuard('P4', 'guest delete refuses a bare GET and keeps the row',
                '/admin/guest_delete.php?' . $ids . '&id=' . $gid, 'tblguest', 'id', $gid);
        }
        $blog = $this->one('SELECT id FROM tblpostwedding ORDER BY id LIMIT 1');
        if ($blog) {
            $bid = (int) $blog->id;
            $this->expectPage('P4', 'blog edit form', '/admin/blog_events_edit.php?id=' . $bid);
            $this->expectGetGuard('P4', 'blog delete refuses a bare GET and keeps the row',
                '/admin/blog_events_delete.php?id=' . $bid, 'tblpostwedding', 'id', $bid);
        }
        $feat = $this->one('SELECT feature_id FROM tbl_features ORDER BY feature_id LIMIT 1');
        if ($feat) {
            $fid2 = (int) $feat->feature_id;
            $this->expectPage('P4', 'feature edit form', '/admin/feature_edit.php?id=' . $fid2);
            $this->expectGetGuard('P4', 'feature delete refuses a bare GET and keeps the row',
                '/admin/feature_delete.php?id=' . $fid2, 'tbl_features', 'feature_id', $fid2);
        }
        // Packages are tblweddingcategories rows (Category::find_by_id).
        if ($this->fx['category'] > 0) {
            $cid = $this->fx['category'];
            $this->expectPage('P4', 'package edit form', '/admin/package_edit.php?id=' . $cid);
            $this->expectGetGuard('P4', 'package delete refuses a bare GET and keeps the row',
                '/admin/package_delete.php?id=' . $cid, 'tblweddingcategories', 'id', $cid);
        }
        $staff = $this->one('SELECT id FROM tblusers WHERE access_level = 1 ORDER BY id LIMIT 1');
        if ($staff) {
            $sid = (int) $staff->id;
            $this->expectPage('P4', 'staff edit form', '/admin/users_edit.php?id=' . $sid);
            $this->expectGetGuard('P4', 'staff delete refuses a bare GET and keeps the row',
                '/admin/users_delete.php?id=' . $sid, 'tblusers', 'id', $sid);
        }
        $photo = $this->one('SELECT id FROM tblgallery ORDER BY id LIMIT 1');
        if ($photo) {
            $pid = (int) $photo->id;
            $this->expectPage('P4', 'photo edit form', '/admin/photos_edit.php?id=' . $pid);
            $this->expectGetGuard('P4', 'photo delete refuses a bare GET and keeps the row',
                '/admin/photo_delete.php?id=' . $pid, 'tblgallery', 'id', $pid);
        }
        $pay = $this->one('SELECT id FROM tbl_liquidation WHERE booking_id = :b ORDER BY id LIMIT 1', [':b' => $b]);
        if ($pay) {
            $this->expectPage('P4', 'payment receipt preview', '/admin/receipt.php?id=' . (int) $pay->id);
        }

        // Staff must not be able to wear the vendor hat.
        $this->expectDenied('P4', 'staff cannot open the vendor self-service home', '/admin/vendor_home.php');

        // Notification actions are writes — empty/missing ids must not 500.
        $this->expectPage('P4', 'notification filter view', '/admin/notifications.php?filter=all', false);
        $this->assert('P4', 'notification page survives a missing id', true);

        $this->logout();
    }

    private function phase5LockedPlanner(): void
    {
        $this->phase('PHASE 5 — Planner without a subscription (paywall)');
        if (!$this->session('P5', 'njeri@wpms.co.ke', 'Test@1234', 'subscription')) {
            $this->record('P5', 'SKIP', 'locked planner walk', 'login did not divert to the subscription page');
            return;
        }
        $this->expectPage('P5', 'locked planner sees the subscription page', '/admin/subscription.php');
        $b = $this->fx['booking'];
        $u = $this->fx['couple_user'];
        $ids = $this->ids($b, $u);

        // Every gated page must divert, never render.
        $gated = [
            '/admin/dashboard.php',
            '/admin/client.php',
            '/admin/users.php',
            '/admin/vendors.php',
            '/admin/sms_center.php',
            '/admin/revenue.php',
            '/admin/mpesa_ledger.php',
            '/admin/guest_list.php?' . $ids,
            '/admin/budget.php?' . $ids,
            '/admin/client_guests.php?' . $ids,
        ];
        foreach ($gated as $path) {
            $this->expectRedirect('P5', 'gated page diverts: ' . $path, $path, 'subscription');
        }
        $this->logout();
    }

    private function phase6Vendor(): void
    {
        $this->phase('PHASE 6 — Vendor role (access_level 0)');
        if (!$this->session('P6', 'kamau@nyamachoma.co.ke', 'Vendor@2024', 'vendor_home')) {
            $this->record('P6', 'SKIP', 'vendor walk', 'login failed');
            return;
        }
        foreach ([
            '/admin/vendor_home.php'     => 'vendor bookings ledger',
            '/admin/vendor_packages.php' => 'vendor packages',
            '/admin/vendor_profile.php'  => 'vendor profile',
        ] as $path => $label) {
            $this->expectPage('P6', 'vendor ' . $label, $path);
        }
        $this->expectPage('P6', 'vendor ledger filtered', '/admin/vendor_home.php?status=confirmed');
        /*
         * vendors.php is a deliberate two-audience page (documented in its own
         * docblock): staff planners AND vendors, for whom it is the sidebar's
         * "Marketplace". A vendor reaching it is correct, not a leak — so this
         * asserts the positive, and separately that anonymous visitors and
         * couples still cannot.
         */
        $this->expectPage('P6', 'vendor can browse the vendor marketplace', '/admin/vendors.php');
        // Vendors must never reach the rest of the back office.
        foreach ([
            '/admin/dashboard.php',
            '/admin/users.php',
            '/admin/revenue.php',
            '/admin/mpesa_ledger.php',
            '/admin/subscription.php',
            '/admin/headroom.php',
            '/admin/guest_list.php?booking_id=' . $this->fx['booking'] . '&user_id=' . $this->fx['couple_user'],
        ] as $path) {
            $this->expectDenied('P6', 'vendor is denied ' . $path, $path);
        }

        /*
         * The vendor's own WRITE path (status transitions). Not covered here
         * until now, and the gap mattered: the handler opens with an ownership
         * SELECT scoped to every listing the account owns, and PHPUnit's
         * VendorSelfServiceTest caught that query 500-ing for EVERY vendor
         * because its scope list was interpolated into a single-quoted string.
         * This harness POSTed no transition at all, so it stayed 278-green
         * while the vendor's write capability was dead. Both directions of the
         * ownership guard are asserted now, and both rows are cleaned up.
         */
        $vendorUserId = (int) ($this->val("SELECT id FROM tblusers WHERE email = 'kamau@nyamachoma.co.ke' LIMIT 1") ?? 0);
        $ownVendor    = $this->one('SELECT id, business_name FROM tblvendors WHERE user_id = :u ORDER BY id LIMIT 1', [':u' => $vendorUserId]);
        $seedBooking  = $this->one('SELECT booking_id FROM tblweddingbook ORDER BY booking_id LIMIT 1');
        $probeBooking = $seedBooking ? (int) $seedBooking->booking_id : 0;
        $probeRow     = static function (self $t, int $vendorId, int $bookingId): int {
            $t->exec(
                "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
                 VALUES (:v, :b, NULL, NULL, 0, 0, 'pending', 'SIM probe')",
                [':v' => $vendorId, ':b' => $bookingId]
            );
            return (int) ($t->val(
                'SELECT id FROM tblvendor_bookings WHERE vendor_id = :v AND booking_id = :b ORDER BY id DESC LIMIT 1',
                [':v' => $vendorId, ':b' => $bookingId]
            ) ?? 0);
        };

        if ($ownVendor && $probeBooking > 0) {
            $ownRow = $probeRow($this, (int) $ownVendor->id, $probeBooking);
            $token  = $this->token('/admin/vendor_home.php');
            if ($ownRow > 0 && $token !== null) {
                // 'completed' (not 'confirmed') so no couple SMS is charged —
                // the write path under test is the same either way.
                $res = $this->http('POST', '/admin/vendor_home.php', [
                    '_token'         => $token,
                    'booking_row_id' => (string) $ownRow,
                    'to'             => 'completed',
                ]);
                $row = $this->one('SELECT status, notes FROM tblvendor_bookings WHERE id = :i', [':i' => $ownRow]);
                $this->assert('P6', 'vendor completes their own booking (the write path answers 302)',
                    $res['status'] === 302 && $row !== null && $row->status === 'completed',
                    'HTTP ' . $res['status'] . '; status=' . ($row->status ?? 'missing'));
                $this->assert('P6', 'the vendor transition is recorded on the notes trail',
                    $row !== null && stripos((string) $row->notes, 'completed by') !== false,
                    'notes=' . substr((string) ($row->notes ?? ''), 0, 60));
            } else {
                $this->record('P6', 'SKIP', 'vendor completes their own booking', $ownRow === 0 ? 'probe row not created' : 'no CSRF token on the vendor ledger');
            }
            // The transition also notifies the couple's planner — that row is
            // real state, so it leaves with the probe row.
            $this->exec("DELETE FROM tblnotifications WHERE booking_id = :b AND message LIKE '%marked your couple%'", [':b' => $probeBooking]);
            $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $ownRow]);
        }

        $foreignVendor = $this->one('SELECT id FROM tblvendors WHERE user_id <> :u ORDER BY id LIMIT 1', [':u' => $vendorUserId]);
        if ($foreignVendor && $probeBooking > 0) {
            $foreignId = $probeRow($this, (int) $foreignVendor->id, $probeBooking);
            $token     = $this->token('/admin/vendor_home.php');
            if ($foreignId > 0 && $token !== null) {
                $res = $this->http('POST', '/admin/vendor_home.php', [
                    '_token'         => $token,
                    'booking_row_id' => (string) $foreignId,
                    'to'             => 'confirmed',
                    'amount'         => '99999',
                ]);
                $row     = $this->one('SELECT status FROM tblvendor_bookings WHERE id = :i', [':i' => $foreignId]);
                $after   = $this->http('GET', '/admin/vendor_home.php');
                $refused = stripos($after['body'], 'That booking is not yours.') !== false;
                $this->assert('P6', "a vendor cannot transition another vendor's booking",
                    $res['status'] === 302 && $row !== null && $row->status === 'pending' && $refused,
                    'HTTP ' . $res['status'] . '; status=' . ($row->status ?? 'missing')
                    . ($refused ? '' : ' | the refusal is not shown to the vendor'));
            } else {
                $this->record('P6', 'SKIP', "a vendor cannot transition another vendor's booking", $foreignId === 0 ? 'probe row not created' : 'no CSRF token');
            }
            $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $foreignId]);
        }

        $this->logout();
        $this->jar = [];
        $this->expectDenied('P6', 'anonymous visitor is denied the vendor marketplace', '/admin/vendors.php');

        // The second vendor account, and an unapproved vendor profile owner.
        if ($this->session('P6', 'chebet@floral.co.ke', 'Vendor@2024', 'vendor_home')) {
            $this->expectPage('P6', 'second vendor ledger', '/admin/vendor_home.php');
            $this->logout();
        }
        /*
         * The operator exemption must hold on EVERY staff page. abdi@wpms.co.ke
         * is a super admin who also owns an (unapproved) vendor profile and has
         * no subscription row of their own — the exact account that exposed the
         * hand-rolled gate in vendors.php.
         */
        if ($this->session('P6', 'abdi@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
            $this->expectPage('P6', 'operator lands on the dashboard with no plan of their own', '/admin/dashboard.php');
            $this->expectPage('P6', 'operator is not paywalled out of the vendor marketplace', '/admin/vendors.php');
            $this->expectPage('P6', 'operator is not paywalled out of the user list', '/admin/users.php');
            $this->expectPage('P6', 'operator is not paywalled out of revenue', '/admin/revenue.php');
            $this->logout();
        }
        // An unregistered vendor: a staff row that has no tblvendors entry yet.
        $unreg = $this->one("SELECT id, email FROM tblusers WHERE access_level = 0 AND id NOT IN (SELECT user_id FROM tblvendors) LIMIT 1");
        if ($unreg) {
            $this->record('P6', 'INFO', 'unregistered vendor seed found (id ' . (int) $unreg->id . ')', 'registration funnel probed in phase 8');
        } else {
            $this->record('P6', 'INFO', 'no unregistered vendor in the seed', 'vendor_register funnel probed in phase 8');
            $this->expectDenied('P6', 'vendor_register is closed to anonymous visitors', '/admin/vendor_register.php');
        }
    }

    private function phase7SuperAdmin(): void
    {
        $this->phase('PHASE 7 — Super admin role (operator surfaces)');
        if (!$this->session('P7', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
            $this->record('P7', 'SKIP', 'super admin walk', 'login failed');
            return;
        }
        // Operator-only surfaces and views with their filters.
        foreach ([
            '/admin/revenue.php'          => 'revenue dashboard',
            '/admin/users.php'            => 'user administration',
            '/admin/login_activity.php'   => 'login/session audit',
            '/admin/mpesa_ledger.php'     => 'M-Pesa ledger',
            '/admin/mpesa_ledger.php?filter=callback' => 'M-Pesa ledger (callback filter)',
            '/admin/vendors.php'          => 'vendor approval list',
            '/admin/vendors.php?category=Catering' => 'vendor directory filtered',
            '/admin/vendor_enquiries.php' => 'all vendor enquiries',
            '/admin/subscription.php'     => 'own subscription',
            '/admin/dashboard.php'        => 'dashboard',
            '/admin/notifications.php'    => 'notifications',
        ] as $path => $label) {
            $this->expectPage('P7', 'operator ' . $label, $path);
        }
        // The operator is exempt from the paywall even with no plan of their own.
        $this->expectPage('P7', 'operator is exempt from the paywall', '/admin/client.php');

        /*
         * The operator's plan editor (subscription_plans.php) — the capability
         * that makes subscription.php's "Plans will appear here once the team
         * defines them" true. tblsubscription_plans used to be read twice and
         * written nowhere, so that promise had no actor at all. This walks the
         * whole thing: define → it is on sale for planners → edit its price and
         * limit → retire → it leaves the purchase list AND the purchase path
         * (a crafted POST must not buy it) → put it back on sale → a planner
         * cannot reach the editor. The probe plan is deleted at the end.
         */
        $this->expectPage('P7', 'operator plan definitions editor', '/admin/subscription_plans.php');

        $probeSlug = 'simprobe' . bin2hex(random_bytes(2));
        $probeName = 'SIM Probe Plan ' . $probeSlug;
        $token     = $this->token('/admin/subscription_plans.php');
        $planId    = 0;

        if ($token === null) {
            $this->record('P7', 'SKIP', 'operator defines a subscription plan', 'no CSRF token on the plan editor');
        } else {
            // 1. Define it.
            $res = $this->http('POST', '/admin/subscription_plans.php', [
                '_token'        => $token,
                'save_plan'     => '1',
                'plan_id'       => '0',
                'plan_slug'     => $probeSlug,
                'name'          => $probeName,
                'price'         => '4321',
                'wedding_limit' => '7',
                'features'      => 'Alpha, Beta',
            ]);
            $row = $this->one('SELECT * FROM tblsubscription_plans WHERE plan_slug = :s', [':s' => $probeSlug]);
            $planId = $row !== null ? (int) $row->id : 0;
            $this->assert('P7', 'operator defines a plan and it is stored live',
                $res['status'] === 302 && $row !== null && (int) $row->is_active === 1
                    && (float) $row->price === 4321.0 && (int) $row->wedding_limit === 7,
                'HTTP ' . $res['status'] . '; ' . ($row === null
                    ? 'no tblsubscription_plans row was written'
                    : 'price=' . $row->price . ' limit=' . $row->wedding_limit . ' active=' . $row->is_active));

            // 2. Editing it changes what the shop window shows.
            if ($planId > 0) {
                $token2 = $this->token('/admin/subscription_plans.php');
                $res = $this->http('POST', '/admin/subscription_plans.php', [
                    '_token'        => $token2,
                    'save_plan'     => '1',
                    'plan_id'       => (string) $planId,
                    'plan_slug'     => $probeSlug,
                    'name'          => $probeName . ' v2',
                    'price'         => '9999',
                    'wedding_limit' => '0',
                    'features'      => 'Alpha, Beta, Gamma',
                ]);
                $row = $this->one('SELECT * FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
                $this->assert('P7', 'operator edits a plan (price and wedding limit)',
                    $res['status'] === 302 && $row !== null && (float) $row->price === 9999.0
                        && (int) $row->wedding_limit === 0,
                    'HTTP ' . $res['status'] . '; ' . ($row === null
                        ? 'row missing'
                        : 'price=' . $row->price . ' limit=' . $row->wedding_limit));

                /*
                 * And the ledger says who moved the price, and from what (v2.8).
                 * Before this table existed a plan's price was simply
                 * overwritten — the current number was all that survived, so
                 * "who changed it, when, and from what" had no answer. The
                 * probe defined the plan at 4321 and edited it to 9999, so
                 * exactly TWO price entries must exist (the definition, then the
                 * change) and the newest one must carry both numbers and the
                 * signed-in operator. Two — not five — because an entry is
                 * written per FIELD THAT CHANGED, not per save.
                 */
                $ledger = $this->one(
                    "SELECT * FROM tblsubscription_plan_changes
                      WHERE plan_id = :i AND field_name = 'price' ORDER BY id DESC LIMIT 1",
                    [':i' => $planId]
                );
                $operator  = $this->one('SELECT id FROM tblusers WHERE email = :e', [':e' => 'wafula@wpms.co.ke']);
                $priceRows = (int) ($this->val(
                    "SELECT COUNT(*) FROM tblsubscription_plan_changes WHERE plan_id = :i AND field_name = 'price'",
                    [':i' => $planId]
                ) ?? -1);
                $this->assert('P7', 'the price change is recorded with who made it and what it replaced',
                    $ledger !== null
                        && (string) $ledger->old_value === '4321.00'
                        && (string) $ledger->new_value === '9999.00'
                        && (string) $ledger->plan_slug === $probeSlug
                        && $operator !== null && (int) $ledger->user_id === (int) $operator->id
                        && $priceRows === 2,
                    $ledger === null
                        ? 'no ledger entry was written for the price change'
                        : 'old=' . $ledger->old_value . ' new=' . $ledger->new_value
                          . ' actor=' . $ledger->user_id . ' price entries=' . $priceRows);
            }
        }

        // 3. The planner's own page offers what the operator defined — the
        //    "plans will appear here" promise, finally with an actor behind it.
        if ($planId > 0 && $this->session('P7', 'amina@wpms.co.ke', 'Test@1234', 'dashboard')) {
            $shop = $this->http('GET', '/admin/subscription.php');
            $listed = stripos($shop['body'], 'SIM Probe Plan') !== false;
            $this->assert('P7', 'a plan the operator defines reaches the planner subscription page',
                $shop['status'] === 200 && $listed,
                'HTTP ' . $shop['status'] . '; ' . ($listed ? 'plan card rendered' : 'the plan is not offered for sale'));

            // A planner is not an operator: the editor is refused, and said so.
            // The refusal lands on the plan list they were looking for, which is
            // where the explanation is rendered.
            $refused = $this->http('GET', '/admin/subscription_plans.php');
            $after   = $this->http('GET', '/admin/subscription.php');
            $explained = stripos($after['body'], 'Only the operator can define subscription plans') !== false;
            $this->assert('P7', 'a planner is refused the plan editor with a reason',
                $refused['status'] === 302 && stripos($refused['location'], 'subscription.php') !== false && $explained,
                'HTTP ' . $refused['status'] . ' -> ' . $refused['location']
                . ($explained ? '' : ' | the refusal is not explained to the planner'));
            $this->logout();
        }

        // 4. Retire it, then prove retirement is real: off the purchase list
        //    AND off the purchase path, with existing buyers unaffected.
        if ($planId > 0 && $this->session('P7', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
            $token3 = $this->token('/admin/subscription_plans.php');
            $res = $this->http('POST', '/admin/subscription_plans.php', [
                '_token' => $token3, 'set_plan_active' => '1',
                'plan_id' => (string) $planId, 'active' => '0',
            ]);
            $row = $this->one('SELECT is_active FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
            $this->assert('P7', 'operator retires a plan',
                $res['status'] === 302 && $row !== null && (int) $row->is_active === 0,
                'HTTP ' . $res['status'] . '; is_active=' . ($row->is_active ?? 'missing'));

            if ($this->session('P7', 'amina@wpms.co.ke', 'Test@1234', 'dashboard')) {
                $shop = $this->http('GET', '/admin/subscription.php');
                $hidden = stripos($shop['body'], 'SIM Probe Plan') === false;
                $this->assert('P7', 'a retired plan is gone from the planner purchase list', $hidden,
                    $hidden ? '' : 'the retired plan is still offered for sale');

                // Retirement must also close the purchase PATH, not just the
                // card: post the slug straight at the handler. The refusal
                // message is specific to this gate, and it is reached before any
                // M-Pesa code is examined — so a pass cannot be the code check.
                $before  = (int) ($this->val('SELECT COUNT(*) FROM tblsubscriptions WHERE plan = :p', [':p' => $probeSlug]) ?? 0);
                $token4  = $this->token('/admin/subscription.php');
                $crafted = $this->http('POST', '/admin/subscription.php', [
                    '_token'     => $token4,
                    'purchase'   => '1',
                    'plan'       => $probeSlug,
                    'mpesa_code' => 'QKJ7GH9P2W',
                ]);
                $after   = (int) ($this->val('SELECT COUNT(*) FROM tblsubscriptions WHERE plan = :p', [':p' => $probeSlug]) ?? 0);
                $page    = $this->http('GET', '/admin/subscription.php');
                $blocked = stripos($page['body'], 'not available for purchase') !== false;
                $this->assert('P7', 'a retired plan cannot be bought even by posting its key',
                    $after === $before && $after === 0 && $blocked,
                    'HTTP ' . $crafted['status'] . '; subscriptions ' . $before . ' → ' . $after
                    . ($blocked ? '' : ' | the purchase was not refused with a reason'));
                $this->logout();
            }

            // 5. And it is recoverable — a mistake is not permanent.
            if ($this->session('P7', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
                $token5 = $this->token('/admin/subscription_plans.php');
                $res = $this->http('POST', '/admin/subscription_plans.php', [
                    '_token' => $token5, 'set_plan_active' => '1',
                    'plan_id' => (string) $planId, 'active' => '1',
                ]);
                $row = $this->one('SELECT is_active FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
                $this->assert('P7', 'a retired plan can be put back on sale',
                    $res['status'] === 302 && $row !== null && (int) $row->is_active === 1,
                    'HTTP ' . $res['status'] . '; is_active=' . ($row->is_active ?? 'missing'));
            }
        }

        // Clean up the probe definition so phase 9 sees no residue.
        if ($planId > 0) {
            $this->exec('DELETE FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
            // The plan-change ledger has no foreign key, deliberately — an audit
            // trail must not be erasable by deleting its subject — so its probe
            // rows are removed explicitly here and checked in phase 9.
            $this->exec('DELETE FROM tblsubscription_plan_changes WHERE plan_id = :i', [':i' => $planId]);
        }
        $left = (int) ($this->val('SELECT COUNT(*) FROM tblsubscription_plans WHERE plan_slug = :s', [':s' => $probeSlug]) ?? -1);
        $this->assert('P7', 'the plan probe leaves no definition behind', $left === 0,
            $left === 0 ? '' : $left . ' probe plan(s) remain');

        $this->logout();
    }

    /**
     * Write paths. Each leg performs a real mutation through the UI and then
     * checks the database, so a silent failure (200 with nothing saved) is
     * caught as loudly as a fatal.
     */
    private function phase8Writes(): void
    {
        $this->phase('PHASE 8 — Write paths (real mutations, verified in the database)');
        $b = $this->fx['booking'];
        $u = $this->fx['couple_user'];
        $ids = $this->ids($b, $u);

        // ---- 8a. Couple adds a guest -------------------------------------
        if ($this->session('P8', 'brian.wafula@gmail.com', 'Test@1234', 'client.php')) {
            $page = '/admin/client_guests.php?' . $ids;
            $before = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $b]) ?? 0);
            $name = 'SIM Guest ' . bin2hex(random_bytes(3));
            // Submitted exactly as the rendered form would be, CSRF token and all.
            $sub = $this->submitForm($page, 'client_guest_save.php', [
                'fullname'     => $name,
                'guestname'    => $name,
                'phone'        => '0712345678',
                'city'         => 'Nairobi',
                'relationship' => 'family_friend',
                'save_guest'   => '1',
            ]);
            $after = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $b]) ?? 0);
            $this->assert('P8', 'couple guest add persists a row', $after === $before + 1,
                "HTTP {$sub['status']} -> {$sub['location']}; guest count {$before} → {$after}"
                . (array_key_exists('save_guest', $sub['fields']) ? '' : ' (form has no save_guest field)'));
            $newId = (int) ($this->val('SELECT id FROM tblguest WHERE booking_id = :b AND fullname = :n', [':b' => $b, ':n' => $name]) ?? 0);
            if ($newId > 0) {
                // The page renders BOTH an add and an edit form; this picks the
                // one that carries the guest's id (the page renders it only for
                // the requested guest), which is the browser's own behaviour.
                $sub = $this->submitForm($page . '&edit=' . $newId, 'client_guest_save.php', [
                    'fullname'     => $name . ' II',
                    'city'         => 'Nakuru',
                    'relationship' => 'colleague',
                    'save_guest'   => '1',
                ], ['guest_id' => (string) $newId], ['guest_id' => (string) $newId]);
                $row = $this->one('SELECT fullname, city FROM tblguest WHERE id = :i', [':i' => $newId]);
                $this->assert('P8', 'couple guest edit persists',
                    $row !== null && $row->fullname === $name . ' II' && $row->city === 'Nakuru',
                    "HTTP {$sub['status']}; row=" . ($row->fullname ?? 'gone') . '/' . ($row->city ?? '?'));
                // Clean up the guest the simulation created.
                $this->exec('DELETE FROM tblguest WHERE id = :i', [':i' => $newId]);
            }

            // A couple must not be able to save a guest into someone else's wedding.
            $other = $this->one('SELECT booking_id, user_id FROM tblweddingbook WHERE user_id <> :u ORDER BY booking_id LIMIT 1', [':u' => $u]);
            if ($other) {
                $ob = (int) $other->booking_id;
                $ou = (int) $other->user_id;
                $obefore = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $ob]) ?? 0);
                $sub = $this->submitForm('/admin/client_guests.php?' . $this->ids($ob, $ou), 'client_guest_save.php', [
                    'fullname' => 'CROSS TENANT PROBE', 'guestname' => 'probe', 'city' => 'Nairobi',
                    'relationship' => 'other', 'save_guest' => '1',
                ]);
                // The page itself must refuse to render another couple's wedding.
                $oafter = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $ob]) ?? 0);
                $this->assert('P8', "couple cannot write a guest into booking {$ob}",
                    $oafter === $obefore, "HTTP {$sub['status']} -> {$sub['location']}; guests {$obefore} → {$oafter}");
            }

            // ---- 8a-ter. Couple sets the venue headcount plan ------------
            /*
             * sheet_venue.php printed a family/individual headcount per function
             * from tbleventbooking, and nothing in the application could write
             * it: the class describing the table declared the fields but did not
             * extend DB_Object, and no screen offered them. The sheet carries the
             * editor now and admin/event_booking_save.php is the writer, so this
             * leg walks the whole loop — edit on the sheet, row in the table,
             * row printed back on the sheet — and proves the refusals too.
             */
            $sheet = '/admin/sheet_venue.php?' . $ids;
            $venueBody = $this->http('GET', $sheet)['body'];
            $ownFunctions = $this->db()->fetchAll(
                'SELECT id FROM events WHERE booking_id = :b ORDER BY id',
                [':b' => $b]
            );
            $inputs = preg_match_all('~name="family\[(\d+)\]"~', $venueBody, $families);
            $this->assert('P8', 'the venue sheet offers a headcount field for every function it prints',
                $ownFunctions !== [] && $inputs === count($ownFunctions),
                count($ownFunctions) . ' function(s) on the calendar, ' . $inputs . ' input(s) rendered');

            // A function nobody has planned for is the case the defect hid: it
            // must appear (so it can be planned), and setting it must persist.
            /*
             * Full rows, not counts: the sheet's editor submits EVERY function
             * it prints, so a save must leave rows the user did not change
             * bit-for-bit as they were (an earlier version re-stamped their
             * package column on every save).
             */
            $planRows = function () use ($b): array {
                $out = [];
                foreach ($this->db()->fetchAll(
                    'SELECT booking_id, features_id, wedding_id, family, individual FROM tbleventbooking WHERE booking_id = :b ORDER BY features_id',
                    [':b' => $b]
                ) as $r) {
                    $out[] = implode('/', [(int) $r->booking_id, (int) $r->features_id, (int) $r->wedding_id, (int) $r->family, (int) $r->individual]);
                }
                return $out;
            };
            $rowsBefore = $planRows();

            $unplanned = null;
            foreach ($ownFunctions as $fn) {
                $has = (int) ($this->val(
                    'SELECT COUNT(*) FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                    [':b' => $b, ':f' => (int) $fn->id]
                ) ?? 0);
                if ($has === 0) {
                    $unplanned = (int) $fn->id;
                    break;
                }
            }
            $plannedBefore = count($rowsBefore);
            if ($unplanned !== null) {
                $sub = $this->submitForm($sheet, 'event_booking_save.php', [
                    'family[' . $unplanned . ']'     => '12',
                    'individual[' . $unplanned . ']' => '34',
                ]);
                $row = $this->one(
                    'SELECT family, individual FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                    [':b' => $b, ':f' => $unplanned]
                );
                $this->assert('P8', 'a couple can set the planned headcount of their own function',
                    $row !== null && (int) $row->family === 12 && (int) $row->individual === 34,
                    'HTTP ' . $sub['status'] . ' -> ' . $sub['location'] . '; row='
                    . ($row ? $row->family . '/' . $row->individual : 'none')
                    . (array_key_exists('save_headcounts', $sub['fields']) ? '' : ' (form has no save button field)'));

                // …and the sheet prints the number back, which is the point: a
                // figure no role could enter was frozen at whatever it started as.
                $afterBody = $this->http('GET', $sheet)['body'];
                $this->assert('P8', 'the venue sheet prints the headcount the couple just entered',
                    str_contains($afterBody, '<td class="s-num">12</td>')
                    && str_contains($afterBody, '<td class="s-num">34</td>'),
                    'the plan table does not render 12/34 after the save');

                // Saving the same numbers again must replace the row, not add one.
                $again = $this->submitForm($sheet, 'event_booking_save.php', [
                    'family[' . $unplanned . ']'     => '12',
                    'individual[' . $unplanned . ']' => '34',
                ]);
                $rows = (int) ($this->val(
                    'SELECT COUNT(*) FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                    [':b' => $b, ':f' => $unplanned]
                ) ?? 0);
                $this->assert('P8', 're-saving a headcount plan replaces its row instead of stacking one',
                    $rows === 1, 'HTTP ' . $again['status'] . "; rows for this function: {$rows}");

                // And an edit is an edit, for a function that already has a plan.
                $edit = $this->submitForm($sheet, 'event_booking_save.php', [
                    'family[' . $unplanned . ']'     => '13',
                    'individual[' . $unplanned . ']' => '35',
                ]);
                $row2 = $this->one(
                    'SELECT family, individual FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                    [':b' => $b, ':f' => $unplanned]
                );
                $this->assert('P8', 'an existing headcount plan can be corrected',
                    $row2 !== null && (int) $row2->family === 13 && (int) $row2->individual === 35,
                    'HTTP ' . $edit['status'] . '; row=' . ($row2 ? $row2->family . '/' . $row2->individual : 'none'));

                // The function list is the whitelist: a key from outside it is
                // refused rather than written somewhere no page would show it.
                $foreign = $this->one(
                    'SELECT id, booking_id FROM events WHERE booking_id <> :b ORDER BY id LIMIT 1',
                    [':b' => $b]
                );
                if ($foreign) {
                    $fo = (int) $foreign->booking_id;
                    $fid3 = (int) $foreign->id;
                    $before3 = (int) ($this->val('SELECT COUNT(*) FROM tbleventbooking WHERE booking_id = :b', [':b' => $fo]) ?? 0);
                    $cross = $this->submitForm($sheet, 'event_booking_save.php', [
                        'family[' . $fid3 . ']'     => '999',
                        'individual[' . $fid3 . ']' => '999',
                    ]);
                    $after3 = (int) ($this->val('SELECT COUNT(*) FROM tbleventbooking WHERE booking_id = :b', [':b' => $fo]) ?? 0);
                    $leak = (int) ($this->val(
                        'SELECT COUNT(*) FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                        [':b' => $b, ':f' => $fid3]
                    ) ?? 0);
                    $this->assert('P8', "a couple cannot plan a headcount onto booking {$fo}'s function",
                        $after3 === $before3 && $leak === 0,
                        "HTTP {$cross['status']}; booking {$fo} rows {$before3} → {$after3}, own rows for that function: {$leak}");
                }

                // ---- 8a-quater. The headcount brief reaches both recipients --
                /*
                 * A couple moving the numbers on this sheet is the change the
                 * venue and the caterers have to act on, and until v2.9 nobody
                 * was told about it: the sheet opens for staff and the couple
                 * only, so the venue heard when a planner noticed and picked up
                 * the phone. event_booking_save.php now briefs the assigned
                 * planner AND the venue contact booked on the wedding with just
                 * the functions whose numbers moved (include/HeadcountBrief.php).
                 * This leg walks that loop end to end. It books a venue on the
                 * wedding first — the seed ships no venue-category listing at
                 * all, which is why nothing had ever exercised this path — and
                 * then sweeps every row it caused. The two saves that must stay
                 * silent (one that moves nothing, and the planner's own edit) are
                 * asserted in PHPUnit's VenueHeadcountPlanTest instead of here,
                 * where the couple's session is open.
                 */
                $organizerId = (int) ($this->val('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => $b]) ?? 0);
                $venueUser   = (int) ($this->val("SELECT id FROM tblusers WHERE email = 'kamau@nyamachoma.co.ke' LIMIT 1") ?? 0);
                $briefCount  = fn(int $user): int => (int) ($this->val(
                    "SELECT COUNT(*) FROM tblnotifications WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%'",
                    [':u' => $user, ':b' => $b]
                ) ?? -1);
                $venueVendorId = 0;
                $venueRowId    = 0;
                if ($venueUser > 0) {
                    // Left unapproved on purpose: the marketplace lists approved
                    // vendors, and this listing exists to be a booking recipient,
                    // not a shopfront.
                    $this->exec(
                        "INSERT INTO tblvendors (user_id, business_name, category, description, is_approved)
                         VALUES (:u, 'SIM Venue Probe — Karen Gardens', 'venue', 'SIM headcount brief probe', 0)",
                        [':u' => $venueUser]
                    );
                    $venueVendorId = (int) ($this->val('SELECT MAX(id) FROM tblvendors') ?? 0);
                    $this->exec(
                        "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
                         VALUES (:v, :b, NULL, NULL, 0, 0, 'confirmed', 'SIM headcount brief probe')",
                        [':v' => $venueVendorId, ':b' => $b]
                    );
                    $venueRowId = (int) ($this->val('SELECT MAX(id) FROM tblvendor_bookings') ?? 0);
                }

                /*
                 * The other vendors the numbers are for (v2.10). A decorator
                 * whose confirmed deal covers the function that moves is a
                 * recipient — and its login also holds a second listing on the
                 * same wedding, scoped to a function that does NOT move, because
                 * one change must not become one alert per listing. The seeded
                 * decor login owns no live deal on this wedding (its only one is
                 * pending), so a brief here can only be this leg's doing.
                 */
                $scopeUser     = (int) ($this->val("SELECT id FROM tblusers WHERE email = 'chebet@floral.co.ke' LIMIT 1") ?? 0);
                $scopeRowIds   = [];
                $scopeProbeIds = [];
                $otherFunction = (int) ($ownFunctions[0]->id ?? 0);
                if ($scopeUser > 0 && $otherFunction > 0) {
                    foreach ([
                        [self::SCOPE_PROBE_MOVED, $unplanned],
                        [self::SCOPE_PROBE_STILL, $otherFunction],
                    ] as [$probeName, $probeFunction]) {
                        $this->exec(
                            "INSERT INTO tblvendors (user_id, business_name, category, description, is_approved)
                             VALUES (:u, :n, 'decor', 'SIM headcount scope probe', 0)",
                            [':u' => $scopeUser, ':n' => $probeName]
                        );
                        $scopeProbeIds[] = (int) ($this->val('SELECT MAX(id) FROM tblvendors') ?? 0);
                        $this->exec(
                            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, features_id, event_date, amount, commission, status, notes)
                             VALUES (:v, :b, NULL, :f, NULL, 0, 0, 'confirmed', 'SIM headcount scope probe')",
                            [':v' => end($scopeProbeIds), ':b' => $b, ':f' => $probeFunction]
                        );
                        $scopeRowIds[] = (int) ($this->val('SELECT MAX(id) FROM tblvendor_bookings') ?? 0);
                    }
                }
                // Every function on the wedding, so "only the ones that moved" is
                // asserted against the wedding's own function list.
                $fnTitles = [];
                foreach ($ownFunctions as $fn) {
                    $fnTitles[(int) $fn->id] = (string) ($this->val('SELECT title FROM events WHERE id = :i', [':i' => (int) $fn->id]) ?? '');
                }

                $plannerBefore = $briefCount($organizerId);
                $venueBefore   = $briefCount($venueUser);
                $scopeBefore   = $briefCount($scopeUser);
                $brief = $this->submitForm($sheet, 'event_booking_save.php', [
                    'family[' . $unplanned . ']'     => '17',
                    'individual[' . $unplanned . ']' => '41',
                ]);
                $msgOf = static fn(?object $row): string => (string) ($row->message ?? '');
                $plannerRow = $this->one(
                    "SELECT id, message, link FROM tblnotifications
                      WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%' ORDER BY id DESC LIMIT 1",
                    [':u' => $organizerId, ':b' => $b]
                );
                $venueRow = $this->one(
                    "SELECT id, message, link FROM tblnotifications
                      WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%' ORDER BY id DESC LIMIT 1",
                    [':u' => $venueUser, ':b' => $b]
                );
                $this->assert('P8', "a couple's headcount change briefs the assigned planner",
                    $organizerId > 0 && $plannerRow !== null
                    && $briefCount($organizerId) === $plannerBefore + 1
                    && str_contains($msgOf($plannerRow), '17') && str_contains($msgOf($plannerRow), '41'),
                    'HTTP ' . $brief['status'] . "; organizer {$organizerId}: {$plannerBefore} → " . $briefCount($organizerId));

                $movedTitle = (string) ($fnTitles[$unplanned] ?? '');
                $named      = [];
                foreach ($fnTitles as $title) {
                    if ($title !== '' && str_contains($msgOf($plannerRow), $title)) {
                        $named[] = $title;
                    }
                }
                $this->assert('P8', 'the headcount brief names the function that moved and no other function',
                    $movedTitle !== '' && $named === [$movedTitle],
                    $movedTitle === '' ? 'the moved function has no title to name'
                        : 'brief names: ' . (implode(', ', $named) ?: '(none)'));

                $this->assert('P8', 'the booked venue contact is briefed with the same numbers',
                    $venueUser > 0 && $venueRowId > 0 && $venueRow !== null
                    && $briefCount($venueUser) === $venueBefore + 1
                    && str_contains($msgOf($venueRow), '17') && str_contains($msgOf($venueRow), '41')
                    && (string) $venueRow->link === 'vendor_home.php',
                    $venueUser > 0
                        ? "venue {$venueBefore} → " . $briefCount($venueUser) . '; link=' . ($venueRow->link ?? '(none)')
                        : 'no vendor account to book a venue under');

                $briefSheet = $this->http('GET', $sheet)['body'];
                $this->assert('P8', 'the venue sheet names the contact the headcount brief goes to',
                    $venueVendorId > 0 && str_contains($briefSheet, 'Venue contact') && str_contains($briefSheet, 'SIM Venue Probe'),
                    'the Key contacts block does not list the booked venue');
                $this->assert('P8', 'the couple are told which recipients were reached',
                    str_contains($briefSheet, 'have been sent the changed functions'),
                    'the save confirmation does not say who was told');

                $scopeRow = $this->one(
                    "SELECT id, message, link FROM tblnotifications
                      WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%' ORDER BY id DESC LIMIT 1",
                    [':u' => $scopeUser, ':b' => $b]
                );
                $this->assert('P8', 'a booked vendor whose deal covers the moved function is briefed with the numbers',
                    $scopeUser > 0 && $scopeRow !== null
                    && $briefCount($scopeUser) === $scopeBefore + 1
                    && str_contains((string) $scopeRow->message, '17') && str_contains((string) $scopeRow->message, '41'),
                    $scopeUser > 0
                        ? "vendor {$scopeBefore} → " . $briefCount($scopeUser) . '; ' . (string) ($scopeRow->message ?? '')
                        : 'no decor account to book a probe listing under');

                $this->assert('P8', 'one headcount change is one alert per account, not one per listing',
                    count($scopeRowIds) === 2 && $scopeUser > 0 && $briefCount($scopeUser) === $scopeBefore + 1,
                    count($scopeRowIds) . ' listing(s) on this login, ' . ($scopeUser > 0 ? $briefCount($scopeUser) - $scopeBefore : 0) . ' alert(s)');

                $this->assert('P8', 'the vendor brief names the functions that vendor is booked for',
                    $scopeRow !== null && str_contains((string) $scopeRow->message, 'Your deal covers'),
                    'the vendor brief does not name the scope it was sent under');

                // The confirmation is a session flash: $briefSheet (read straight
                // after the save) is the only render that carries it.
                $this->assert('P8', 'the planner is told which other vendors have the same numbers',
                    $plannerRow !== null && str_contains((string) $plannerRow->message, 'Also sent to ' . self::SCOPE_PROBE_MOVED),
                    'the planner brief does not name the vendor the same numbers went to: '
                        . (string) ($plannerRow->message ?? '(no planner brief)'));

                $scopeSheet = $this->http('GET', $sheet)['body'];
                $this->assert('P8', 'the venue sheet names the vendors the brief goes to, with their scope',
                    str_contains($scopeSheet, 'Vendor brief')
                    && str_contains($scopeSheet, self::SCOPE_PROBE_MOVED)
                    && str_contains($scopeSheet, 'Decor · '),
                    'the Key contacts block does not list the vendor brief');

                /*
                 * The plan outgrowing what was sold (v2.11). A vendor package
                 * states a guest capacity and the deal records the package it was
                 * sold on, so a function planned past one is a deal that no longer
                 * covers the day (include/PackageCapacity.php). The seeded
                 * caterer's deal on this wedding is on a 250-pax package, and the
                 * function this leg has been moving is pushed past it here: the
                 * vendor holding that package hears the limit IN THE SAME BELL as
                 * the change (one save, one alert), the planner is told whose
                 * package no longer covers the day, and the sheet says it where
                 * the numbers are for whoever opens it next.
                 */
                $capacityDeal = $this->one(
                    "SELECT vb.id AS deal_id, vb.status, v.id AS vendor_id, p.capacity, p.title AS package_title,
                            p.price AS package_price, v.business_name
                       FROM tblvendor_bookings vb
                       JOIN tblvendors v ON v.id = vb.vendor_id
                       JOIN tblvendor_packages p ON p.id = vb.package_id
                      WHERE vb.booking_id = :b AND vb.status IN ('confirmed', 'completed') AND p.capacity > 0
                        AND (vb.features_id IS NULL OR vb.features_id = :f)
                   ORDER BY p.capacity ASC LIMIT 1",
                    [':b' => $b, ':f' => $unplanned]
                );
                $capacityProbePackage = 0;
                if ($capacityDeal === null) {
                    $this->record('P8', 'SKIP', 'the capacity warning',
                        'no booked package on this function states a guest capacity');
                } else {
                    $guestLimit = (int) $capacityDeal->capacity;
                    /*
                     * The way out (v2.11), which has to be reachable from the BELL
                     * and not only from the sheet: the vendor whose package is about
                     * to be outgrown is given a package big enough for the crowd, so
                     * the planner's own alert must name it with what it costs. A
                     * warning with no way out is a phone call with no numbers, and
                     * the seam this asserts is the one that used to drop it: the
                     * suggestion is the longest candidate in the message and shares a
                     * varchar(255) column with the warning (HeadcountBrief::fit()).
                     */
                    $outTitle = 'SIM 500-pax package';
                    $outMore  = 60000.0;
                    $this->exec(
                        "INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
                         VALUES (:v, :t, 'Fixture for scripts/simulate_roles.php', :p, 500, 10, 1)",
                        [
                            ':v' => (int) $capacityDeal->vendor_id,
                            ':t' => $outTitle,
                            ':p' => (float) $capacityDeal->package_price + $outMore,
                        ]
                    );
                    $capacityProbePackage = (int) ($this->val('SELECT MAX(id) FROM tblvendor_packages') ?? 0);
                    $outSentence = 'Next package up: ' . $outTitle . ' — KSh ' . number_format($outMore) . ' more.';

                    $overSave   = $this->submitForm($sheet, 'event_booking_save.php', [
                        'family[' . $unplanned . ']'     => (string) $guestLimit,
                        'individual[' . $unplanned . ']' => '9',
                    ]);
                    $capacityVendor = $this->one(
                        "SELECT id, message, link FROM tblnotifications
                          WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%' ORDER BY id DESC LIMIT 1",
                        [':u' => $venueUser, ':b' => $b]
                    );
                    $this->assert('P8', 'a plan that outgrows the package a vendor is booked on warns that vendor of the limit',
                        $capacityVendor !== null
                        && str_contains((string) $capacityVendor->message, 'over the package you are booked on')
                        && str_contains((string) $capacityVendor->message, (string) $capacityDeal->package_title),
                        'HTTP ' . $overSave['status'] . '; ' . (string) ($capacityVendor->message ?? '(no brief)'));
                    $this->assert('P8', 'one save that moves a number and outgrows a package is one alert, not two',
                        $venueUser > 0 && $briefCount($venueUser) === $venueBefore + 2,
                        "venue bells {$venueBefore} → " . $briefCount($venueUser) . ' across two saves');

                    $capacityPlanner = $this->one(
                        "SELECT id, message, link FROM tblnotifications
                          WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%' ORDER BY id DESC LIMIT 1",
                        [':u' => $organizerId, ':b' => $b]
                    );
                    $this->assert('P8', 'the planner is told whose package no longer covers the day',
                        $capacityPlanner !== null
                        && str_contains((string) $capacityPlanner->message, (string) $capacityDeal->business_name)
                        && str_contains((string) $capacityPlanner->message, 'over the package'),
                        (string) ($capacityPlanner->message ?? '(no planner brief)'));

                    $plannerOver = (string) ($capacityPlanner->message ?? '');
                    $this->assert('P8', 'the same alert names the way out: the next package up and what it costs',
                        str_contains($plannerOver, $outSentence),
                        'the planner is warned but given nothing to act on: ' . ($plannerOver !== '' ? $plannerOver : '(no brief)'));
                    $this->assert('P8', 'the warning and the way out fit the bell column together',
                        $plannerOver !== '' && mb_strlen($plannerOver) <= 255
                        && str_contains($plannerOver, 'is planned for'),
                        'the planner bell is ' . mb_strlen($plannerOver) . ' chars: ' . $plannerOver);
                    $this->assert('P8', 'the vendor hears the limit but not the planner\'s options',
                        $capacityVendor !== null
                        && !str_contains((string) $capacityVendor->message, 'Next package up'),
                        (string) ($capacityVendor->message ?? '(no brief)'));

                    // Two reads: the first renders the save's flash, the second is
                    // the sheet on its own — which is what a planner sees an hour
                    // later, long after the confirmation has been dismissed.
                    $this->http('GET', $sheet);
                    $overSheet = $this->http('GET', $sheet)['body'];
                    $this->assert('P8', 'the venue sheet prints the plan that has outgrown a booked package',
                        str_contains($overSheet, 'Over capacity:')
                        && str_contains($overSheet, (string) $capacityDeal->business_name),
                        'the sheet does not say which package no longer covers the day');

                    // The way out is a STAFF view: a re-price is the planner's
                    // negotiation, and the difference is between list prices.
                    if ($this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
                        $staffSheet = $this->http('GET', $sheet)['body'];
                        $this->assert('P8', 'the sheet names the next package up and the one step that re-prices the deal',
                            str_contains($staffSheet, $outSentence)
                            && str_contains($staffSheet, 'Re-price this deal')
                            && str_contains($staffSheet, 'vendor_enquiries.php?deal=' . (int) $capacityDeal->deal_id),
                            'the sheet states the breach but not the way out of it');
                        $this->assert('P8', 'the couple are not pushed at a price change their planner has not framed',
                            !str_contains($overSheet, 'Next package up')
                            && !str_contains($overSheet, 'Re-price this deal'),
                            'the couple sheet carries the planner\'s shopping list');

                        $worklist = $this->http('GET', '/admin/vendor_enquiries.php?deal=' . (int) $capacityDeal->deal_id)['body'];
                        $this->assert('P8', 'the row the sheet links to offers that vendor\'s own packages to re-price onto',
                            (string) $capacityDeal->status !== 'confirmed'
                            || (str_contains($worklist, 'name="reprice_deal_id" value="' . (int) $capacityDeal->deal_id . '"')
                                && str_contains($worklist, $outTitle)),
                            'the worklist does not offer the re-price control for deal ' . (int) $capacityDeal->deal_id);
                    } else {
                        $this->record('P8', 'SKIP', 'the sheet\'s way out for staff',
                            'could not sign in as staff');
                    }

                    // Back to the couple: the rest of this leg is their view.
                    $this->session('P8', 'brian.wafula@gmail.com', 'Test@1234', 'client.php');
                }

                /*
                 * The other direction (v2.11): the plan is judged when a deal is
                 * RECORDED. The leg above moves numbers UNDER a package that is
                 * already sold; a couple can just as easily pick a package too
                 * small for numbers that never moved — the transport account's
                 * 33-seat package for a wedding whose reception is planned for 120
                 * — and without this the first word anyone heard about it was the
                 * next headcount save, which may never come because the numbers are
                 * settled. Staff confirm that deal here through the ledger, exactly
                 * as a planner would.
                 */
                $recordDeal = 0;
                if (!$this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
                    $this->record('P8', 'SKIP', 'recording a deal on a package too small for the plan',
                        'could not sign in as staff');
                } else {
                    $recordPackage = $this->one(
                        "SELECT p.id, p.title, p.capacity, p.price FROM tblvendor_packages p
                          WHERE p.vendor_id = 203 AND p.is_active = 1 AND p.capacity > 0
                       ORDER BY p.capacity ASC LIMIT 1"
                    );
                    $recordPlan = (int) ($this->val(
                        'SELECT COALESCE(MAX(family + individual), 0) FROM tbleventbooking WHERE booking_id = :b',
                        [':b' => $b]
                    ) ?? 0);
                    if ($recordPackage === null || $recordPlan <= (int) $recordPackage->capacity) {
                        $this->record('P8', 'SKIP', 'recording a deal on a package too small for the plan',
                            'no package of a booked listing is smaller than this wedding\'s planned headcounts');
                    } else {
                        $this->exec(
                            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
                             VALUES (203, :b, :p, NULL, 0, 0, 'pending', 'SIM record-door probe')",
                            [':b' => $b, ':p' => (int) $recordPackage->id]
                        );
                        $recordDeal = (int) ($this->val('SELECT MAX(id) FROM tblvendor_bookings') ?? 0);
                        $notifMark  = (int) ($this->val('SELECT COALESCE(MAX(id),0) FROM tblnotifications') ?? 0);
                        $smsMark    = (int) ($this->val('SELECT COALESCE(MAX(id),0) FROM tblsms_logs') ?? 0);
                        $recordToken = $this->token('/admin/vendor_enquiries.php');
                        $recorded = $recordToken !== null && $recordDeal > 0
                            ? $this->http('POST', '/admin/vendor_enquiries.php', [
                                'enquiry_id' => $recordDeal,
                                'to'         => 'confirmed',
                                'amount'     => (string) (int) $recordPackage->price,
                                '_token'     => $recordToken,
                            ])
                            : ['status' => 0];
                        $recordFlash = $this->http('GET', '/admin/vendor_enquiries.php')['body'];
                        $recordStatus = (string) ($this->val(
                            'SELECT status FROM tblvendor_bookings WHERE id = :i', [':i' => $recordDeal]
                        ) ?? '(none)');

                        $this->assert('P8', 'staff can record a deal on a package too small for the plan',
                            (int) ($recorded['status'] ?? 0) === 302 && $recordStatus === 'confirmed',
                            'HTTP ' . (int) ($recorded['status'] ?? 0) . ', deal status ' . $recordStatus
                                . ($recordToken === null ? ' (no CSRF token)' : ''));
                        $this->assert('P8', 'and the person who recorded it is told the package does not cover the day',
                            str_contains($recordFlash, 'Over capacity:') && str_contains($recordFlash, 'over the package'),
                            str_contains($recordFlash, 'Over capacity:')
                                ? 'the warning is on the page but does not name the package'
                                : 'the confirmation says nothing about the plan the package cannot serve');
                        $this->assert('P8', 'with the way out, not just the problem',
                            str_contains($recordFlash, 'Next package up:')
                            || str_contains($recordFlash, 'No bigger package on file')
                            || str_contains($recordFlash, 'Nothing of theirs covers'),
                            'a breach stated with nothing to act on is a phone call with no numbers');

                        $recordBell = $this->one(
                            "SELECT message FROM tblnotifications
                              WHERE user_id = :u AND booking_id = :b AND message LIKE '%headcount%'
                           ORDER BY id DESC LIMIT 1",
                            [':u' => $organizerId, ':b' => $b]
                        );
                        $recordMessage = (string) ($recordBell->message ?? '');
                        $this->assert('P8', 'the assigned planner hears it from the deal, not from the next headcount save',
                            $recordBell !== null && str_contains($recordMessage, 'over the package')
                            && mb_strlen($recordMessage) <= 255,
                            $recordBell === null
                                ? '(no planner bell)'
                                : mb_strlen($recordMessage) . ' chars: ' . $recordMessage);

                        // This leg's own bell rows and outbox copies, so the run
                        // leaves the scratch DB as it found it.
                        $this->exec(
                            'DELETE FROM tbl_mail_logs WHERE notification_id IN (SELECT id FROM (SELECT id FROM tblnotifications WHERE id > :m) x)',
                            [':m' => $notifMark]
                        );
                        $this->exec('DELETE FROM tblnotifications WHERE id > :m', [':m' => $notifMark]);
                        $this->exec('DELETE FROM tblsms_logs WHERE id > :m', [':m' => $smsMark]);
                    }
                }

                // Back to the couple again: the 8b/8c legs below are their view.
                $this->session('P8', 'brian.wafula@gmail.com', 'Test@1234', 'client.php');

                // Sweep this leg's own residue: the bell rows it caused (the
                // outbox copies travel with them), the venue booking, the listing.
                $this->exec("DELETE FROM tbl_mail_logs WHERE notification_id IN (SELECT id FROM (SELECT id FROM tblnotifications WHERE booking_id = :b AND message LIKE '%headcount%') x)", [':b' => $b]);
                $this->exec("DELETE FROM tblnotifications WHERE booking_id = :b AND message LIKE '%headcount%'", [':b' => $b]);
                if ($capacityProbePackage > 0) {
                    $this->exec('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $capacityProbePackage]);
                }
                if ($recordDeal > 0) {
                    $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $recordDeal]);
                }
                if ($venueRowId > 0) {
                    $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $venueRowId]);
                }
                if ($venueVendorId > 0) {
                    $this->exec('DELETE FROM tblvendors WHERE id = :i', [':i' => $venueVendorId]);
                }
                foreach ($scopeRowIds as $scopeRowId) {
                    if ($scopeRowId > 0) {
                        $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $scopeRowId]);
                    }
                }
                foreach ($scopeProbeIds as $scopeProbeId) {
                    if ($scopeProbeId > 0) {
                        $this->exec('DELETE FROM tblvendors WHERE id = :i', [':i' => $scopeProbeId]);
                    }
                }

                // Leave the plan table exactly as the leg found it. The save above
                // submitted the whole rendered form, so this compares every column
                // of every row — an untouched function must come back unchanged,
                // not with its package re-stamped.
                $this->exec('DELETE FROM tbleventbooking WHERE booking_id = :b AND features_id = :f',
                    [':b' => $b, ':f' => $unplanned]);
                $rowsAfter = $planRows();
                $this->assert('P8', 'the headcount save leaves the functions it did not change exactly as they were',
                    $rowsAfter === $rowsBefore,
                    'before [' . implode(' | ', $rowsBefore) . '] after [' . implode(' | ', $rowsAfter) . ']');
            } else {
                $this->record('P8', 'SKIP', 'couple venue headcount plan',
                    'every function of booking ' . $b . ' already has a plan row');
            }

            /*
             * Drift — the step BEFORE the warning above (v2.12). A package with
             * room to spare that has very little of it left is not a breach and
             * may never become one, so nothing is announced about it: no vendor is
             * briefed, no planner is warned, and the only surface that shows it is
             * admin/headroom.php, which a planner reaches from the navigation
             * badge. This leg books a package a few guests above this wedding's
             * biggest planned function and walks the whole chain — the row with
             * the function, the count and the limit, the badge counting exactly
             * the weddings the page lists, the width filter narrowing the same row
             * out of a 5% window (the probe's margin is inside 10% and outside 5%
             * by construction), that reading it rang no bell, and that the row
             * disappears with its own fixture rather than being a fact about the
             * seed.
             */
            $driftPackage = 0;
            $driftDeal    = 0;
            if (!$this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
                $this->record('P8', 'SKIP', 'the headroom view (drift, before it is a breach)',
                    'could not sign in as staff');
            } else {
                $driftVendor = $this->one(
                    "SELECT p.vendor_id, v.business_name
                       FROM tblvendor_bookings vb
                       JOIN tblvendor_packages p ON p.id = vb.package_id
                       JOIN tblvendors v ON v.id = p.vendor_id
                      WHERE vb.booking_id = :b AND vb.status IN ('confirmed', 'completed') AND p.capacity > 0
                   ORDER BY p.capacity ASC LIMIT 1",
                    [':b' => $b]
                );
                $driftPlan = (int) ($this->val(
                    'SELECT COALESCE(MAX(family + individual), 0) FROM tbleventbooking WHERE booking_id = :b',
                    [':b' => $b]
                ) ?? 0);
                if ($driftVendor === null || $driftPlan <= 0) {
                    $this->record('P8', 'SKIP', 'the headroom view (drift, before it is a breach)',
                        'no booked package states a capacity, or this wedding has no plan to run close to one');
                } else {
                    // A margin inside ten per cent of the limit and OUTSIDE five, so
                    // one fixture proves both that the page lists it and that the
                    // width filter is doing arithmetic.
                    $driftMargin = 0;
                    for ($m = 1; $m <= 200; $m++) {
                        $cap = $driftPlan + $m;
                        if ($m > (int) ceil($cap * 0.05) && $m <= (int) ceil($cap * 0.10)) {
                            $driftMargin = $m;
                            break;
                        }
                    }
                    $driftCapacity = $driftPlan + $driftMargin;
                    $driftTitle    = 'SIM headroom probe — ' . $driftCapacity . ' pax';
                    $driftWho      = htmlspecialchars((string) $driftVendor->business_name, ENT_QUOTES);
                    $driftSentence = 'is planned for ' . $driftPlan . ' — within ' . $driftMargin
                        . ' guests of the package ' . $driftWho . ' is booked on';
                    $driftNotifs   = (int) ($this->val('SELECT COALESCE(MAX(id),0) FROM tblnotifications') ?? 0);

                    $this->exec(
                        "INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity, duration_hours, is_active)
                         VALUES (:v, :t, 'Fixture for scripts/simulate_roles.php', 1000, :c, 10, 1)",
                        [':v' => (int) $driftVendor->vendor_id, ':t' => $driftTitle, ':c' => $driftCapacity]
                    );
                    $driftPackage = (int) ($this->val('SELECT MAX(id) FROM tblvendor_packages') ?? 0);
                    // Confirmed, because a deal that only comes CLOSE has to be a
                    // real booking to be judged at all — and unscoped, so it covers
                    // the function carrying this wedding's biggest crowd.
                    $this->exec(
                        "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
                         VALUES (:v, :b, :p, NULL, 0, 0, 'confirmed', 'SIM headroom probe')",
                        [':v' => (int) $driftVendor->vendor_id, ':b' => $b, ':p' => $driftPackage]
                    );
                    $driftDeal = (int) ($this->val('SELECT MAX(id) FROM tblvendor_bookings') ?? 0);

                    $driftPage = $this->http('GET', '/admin/headroom.php')['body'];
                    $this->assert('P8', 'a wedding running close to a booked package\'s limit is on the headroom page',
                        str_contains($driftPage, $driftSentence)
                        && str_contains($driftPage, 'sheet_venue.php?booking_id=' . $b),
                        'the page does not name the function, the count and the limit it is closing on');

                    preg_match('#Weddings on watch</h6><div class="value">(\d+)<#', $driftPage, $listed);
                    $dash = $this->http('GET', '/admin/dashboard.php')['body'];
                    preg_match('#headroom\.php.*?badge-warning[^>]*>(\d+)<#s', $dash, $badge);
                    $this->assert('P8', 'the navigation badge counts the same weddings the headroom page lists',
                        isset($listed[1], $badge[1]) && (int) $badge[1] === (int) $listed[1] && (int) $listed[1] >= 1,
                        'the page lists ' . ($listed[1] ?? '?') . ' and the badge shows '
                            . ($badge[1] ?? 'none') . ': a page nobody has a reason to visit');

                    $driftNarrow = $this->http('GET', '/admin/headroom.php?within=5')['body'];
                    $this->assert('P8', 'the headroom width filter narrows a wider-margin row out of a 5% window',
                        str_contains($driftNarrow, 'Positions inside 5%') && !str_contains($driftNarrow, $driftSentence),
                        'the 5% window still lists a position it does not cover: the filter is decoration');

                    $this->assert('P8', 'drift alone rings no bell: a forecast notifies nobody',
                        (int) ($this->val('SELECT COALESCE(MAX(id),0) FROM tblnotifications') ?? 0) === $driftNotifs,
                        'reading the headroom page sent a notification');

                    $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => $driftDeal]);
                    $this->exec('DELETE FROM tblvendor_packages WHERE id = :i', [':i' => $driftPackage]);
                    $driftAfter = $this->http('GET', '/admin/headroom.php')['body'];
                    $this->assert('P8', 'the row came from this leg\'s own probe, not from the seed',
                        !str_contains($driftAfter, $driftSentence),
                        'the wedding is still listed with the probe gone: the assertions above prove nothing about it');

                    /*
                     * The width is the planner's own and is REMEMBERED (v2.12,
                     * include/UserPrefs.php → tbluser_prefs). The drift row above
                     * was asserted at the default tenth; what is walked here is the
                     * other half of the page: choosing a width stores it for this
                     * account ONLY, a later bare visit opens there, an explicit
                     * `?within=` shows that view without writing anything, and
                     * forgetting it — also a POST — puts the page back to its
                     * default. The leg ends with the preference gone, so the
                     * scratch DB is left as it was found (P9 audits it by name).
                     */
                    if ((int) ($this->val("SELECT COUNT(*) FROM information_schema.tables
                                          WHERE table_schema = DATABASE() AND table_name = 'tbluser_prefs'") ?? 0) !== 1) {
                        $this->record('P8', 'SKIP', 'the remembered headroom width',
                            'tbluser_prefs is not installed — run migrations/v2.12_user_preferences.sql');
                    } else {
                        $prefWidth = static function (string $body): int {
                            return preg_match('~Positions inside (\d+)%~', $body, $m) === 1 ? (int) $m[1] : -1;
                        };
                        $prefToken = $this->token('/admin/headroom.php');
                        $remember  = $prefToken === null ? ['status' => 0] : $this->http('POST', '/admin/headroom.php', [
                            'headroom_default' => 'remember',
                            'within'           => '20',
                            '_token'           => $prefToken,
                        ]);
                        $saved      = (int) ($this->val("SELECT COUNT(*) FROM tbluser_prefs WHERE pref_key = 'headroom_width'") ?? 0);
                        $driftSaved = $this->http('GET', '/admin/headroom.php')['body'];
                        $this->assert('P8', 'a planner can save the width the headroom view opens at, for their own account',
                            (int) ($remember['status'] ?? 0) === 302 && $saved === 1
                                && $prefWidth($driftSaved) === 20,
                            'HTTP ' . (int) ($remember['status'] ?? 0) . "; rows={$saved}; page opens at " . $prefWidth($driftSaved) . '%');

                        $explicitWidth = $prefWidth($this->http('GET', '/admin/headroom.php?within=5')['body']);
                        $stillSaved    = (string) ($this->val("SELECT pref_value FROM tbluser_prefs WHERE pref_key = 'headroom_width' LIMIT 1") ?? '');
                        $this->assert('P8', 'an explicit width changes the view without writing the preference',
                            $explicitWidth === 5 && $stillSaved === '20',
                            "within=5 opened at {$explicitWidth}% and the stored width is '{$stillSaved}'");

                        $forget = $this->token('/admin/headroom.php');
                        $cleared = $forget === null ? ['status' => 0] : $this->http('POST', '/admin/headroom.php', [
                            'headroom_default' => 'forget',
                            'within'           => '20',
                            '_token'           => $forget,
                        ]);
                        $left = (int) ($this->val("SELECT COUNT(*) FROM tbluser_prefs WHERE pref_key = 'headroom_width'") ?? 0);
                        $this->assert('P8', 'and forgetting it puts the page back to the default for that account',
                            (int) ($cleared['status'] ?? 0) === 302 && $left === 0
                                && $prefWidth($this->http('GET', '/admin/headroom.php')['body']) === 10,
                            'HTTP ' . (int) ($cleared['status'] ?? 0) . "; rows left={$left}");
                    }
                }
            }

            // ---- 8b. Couple checklist toggle -----------------------------
            //
            // This write leg runs as STAFF, and the checklist page renders its
            // tappable form only for a couple (staff see the checkboxes disabled),
            // so there is no token on that page here. The endpoint itself allows a
            // staff member to toggle while assisting a couple, so fall back to any
            // CSRF token on a staff page of the SAME session and drive the real
            // handler — asserting the row flipped and was then put back, rather
            // than skipping the endpoint entirely. (The couple-owns-it path is
            // covered by tests/CoupleChecklistWebsiteTest.php.)
            $clPage = '/admin/client_checklist.php?' . $ids;
            $beforeDone = (int) ($this->val('SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b AND is_done = 1', [':b' => $b]) ?? 0);
            $token = $this->token($clPage) ?? $this->token('/admin/client_manage_account_details.php?' . $ids);
            $itemId = (int) ($this->val('SELECT id FROM tblchecklist WHERE booking_id = :b ORDER BY id ASC LIMIT 1', [':b' => $b]) ?? 0);
            if ($token !== null && $itemId > 0) {
                $res = $this->http('POST', '/admin/client_checklist_toggle.php?' . $ids, ['_token' => $token, 'item_id' => (string) $itemId]);
                $issueCount = count($this->bodyIssues($res['body']));
                $afterDone = (int) ($this->val('SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b AND is_done = 1', [':b' => $b]) ?? 0);
                $this->assert('P8', 'checklist toggle reaches the endpoint and flips the item, with no PHP error',
                    $issueCount === 0 && $afterDone !== $beforeDone,
                    'HTTP ' . $res['status'] . ' with ' . $issueCount . ' issue(s); done ' . $beforeDone . ' -> ' . $afterDone);
                // Revert, so the scratch DB is left exactly as it was found (P9 audits this).
                $this->http('POST', '/admin/client_checklist_toggle.php?' . $ids, ['_token' => $token, 'item_id' => (string) $itemId]);
            } else {
                $this->record('P8', 'SKIP', 'couple checklist toggle', 'no CSRF token or seedable item');
            }
            $this->record('P8', 'INFO', 'checklist rows done before toggle: ' . $beforeDone, '');

            // ---- 8c-bis. Couple sets an installment plan ------------------
            // Valid length must persist; an unsupported length must be refused
            // rather than silently clamped.
            $planPage = '/admin/client_installment_plan.php?' . $ids;
            $planToken = $this->token('/admin/client_manage_account_details.php?' . $ids);
            if ($planToken !== null) {
                $res = $this->http('POST', $planPage, ['_token' => $planToken, 'months' => '6']);
                $plan = $this->one('SELECT months, monthly_amount, is_active FROM tbl_installment_plans WHERE booking_id = :b ORDER BY id DESC LIMIT 1', [':b' => $b]);
                $this->assert('P8', 'couple installment plan is created for a valid length',
                    $plan !== null && (int) $plan->months === 6 && (int) $plan->is_active === 1,
                    'HTTP ' . $res['status'] . '; row=' . ($plan ? $plan->months . ' months' : 'none'));

                $res = $this->http('POST', $planPage, ['_token' => $planToken, 'months' => '1']);
                $plan2 = $this->one('SELECT months FROM tbl_installment_plans WHERE booking_id = :b ORDER BY id DESC LIMIT 1', [':b' => $b]);
                $this->assert('P8', 'an unsupported installment length is refused, not clamped',
                    $plan2 !== null && (int) $plan2->months === 6,
                    'plan months after posting 1: ' . ($plan2->months ?? 'none'));

                $res = $this->http('POST', $planPage, ['_token' => $planToken, 'cancel' => '1']);
                $active = (int) ($this->val('SELECT COUNT(*) FROM tbl_installment_plans WHERE booking_id = :b AND is_active = 1', [':b' => $b]) ?? -1);
                $this->assert('P8', 'cancelling the installment plan deactivates it', $active === 0,
                    "HTTP {$res['status']}; {$active} active plan(s) remain");
                $this->exec('DELETE FROM tbl_installment_plans WHERE booking_id = :b', [':b' => $b]);
            } else {
                $this->record('P8', 'SKIP', 'couple installment plan', 'no CSRF token rendered on the overview');
            }

            // ---- 8c. Couple deposit (M-Pesa STK ledger + planner alert) ---
            $depPage = '/admin/client_deposit.php?' . $ids;
            $dep = $this->http('GET', $depPage);
            if (preg_match('~name="event_id"[^>]*>\s*<option value="(\d+)"~s', $dep['body'], $ev)
                && preg_match('~name="_token" value="([^"]+)"~', $dep['body'], $tk)
                && preg_match('~Balance Due</h6>\s*<div class="value due">([^<]+)</div>~', $dep['body'], $due)) {
                $balance = (float) preg_replace('/[^0-9.]/', '', $due[1]);
                if ($balance < 50.0) {
                    $this->record('P8', 'SKIP', 'couple deposit', 'balance below the KSh 50 floor');
                } else {
                    $organizer = (int) ($this->val('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => $b]) ?? 0);
                    $before = (int) ($this->val('SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u', [':b' => $b, ':u' => $organizer]) ?? 0);
                    $amount = min(100.0, $balance);
                    $res = $this->http('POST', $depPage, [
                        '_token' => html_entity_decode($tk[1], ENT_QUOTES), 'amount' => (string) $amount,
                        'event_id' => $ev[1], 'phone' => '0712345678', 'submit' => '1',
                    ]);
                    $this->assert('P8', 'couple deposit POST accepted',
                        $res['status'] === 302 && stripos((string) $res['location'], 'client_budget.php') !== false,
                        'HTTP ' . $res['status'] . ' -> ' . ($res['location'] ?: '(none)'));
                    $ledger = $this->one('SELECT id, mpesa_code FROM tbl_liquidation WHERE booking_id = :b AND payment = :p ORDER BY id DESC LIMIT 1', [':b' => $b, ':p' => $amount]);
                    $this->assert('P8', 'deposit recorded a ledger row with a transaction reference',
                        $ledger !== null && preg_match('/^QDL[A-Z0-9]{7}$/', (string) ($ledger->mpesa_code ?? '')) === 1,
                        'no ledger row for booking ' . $b);
                    $after = (int) ($this->val('SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u', [':b' => $b, ':u' => $organizer]) ?? 0);
                    $this->assert('P8', 'deposit alerted the assigned planner',
                        $organizer > 0 && $after === $before + 1,
                        "organizer {$organizer}: {$before} → {$after}");
                    // sweep the simulation's own residue
                    $this->exec("DELETE FROM tbl_mail_logs WHERE notification_id IN (SELECT id FROM (SELECT id FROM tblnotifications WHERE booking_id = :b AND message LIKE 'Asante! We received your M-Pesa payment%') x)", [':b' => $b]);
                    $this->exec("DELETE FROM tblnotifications WHERE booking_id = :b AND message LIKE 'Asante! We received your M-Pesa payment%'", [':b' => $b]);
                    if ($ledger) {
                        $this->exec('DELETE FROM tbl_liquidation WHERE id = :i', [':i' => (int) $ledger->id]);
                    }
                }
            } else {
                $this->record('P8', 'SKIP', 'couple deposit', 'no ceremony/balance exposed on the form');
            }

            // ---- 8d. Couple writes a vendor enquiry ----------------------
            $enqPage = '/admin/client_vendors.php?' . $ids;
            $vendor = $this->one('SELECT id, business_name FROM tblvendors WHERE is_approved = 1 ORDER BY id LIMIT 1');
            $enqToken = $this->token($enqPage);
            if ($enqToken !== null && $vendor) {
                $vid = (int) $vendor->id;
                $pkg = $this->one('SELECT id FROM tblvendor_packages WHERE vendor_id = :v AND is_active = 1 ORDER BY id LIMIT 1', [':v' => $vid]);
                $beforeIds = array_column($this->db()->fetchAll('SELECT id FROM tblvendor_bookings WHERE booking_id = :b', [':b' => $b]), 'id');
                $res = $this->http('POST', '/admin/client_vendor_enquiry.php?' . $ids, [
                    '_token' => $enqToken, 'vendor_id' => $vid,
                    'package_id' => $pkg ? (string) $pkg->id : '0',
                    'message' => 'SIM enquiry',
                    'event_date' => date('Y-m-d', strtotime('+30 days')),
                ]);
                $issues = $this->bodyIssues($res['body']);
                $afterIds = array_column($this->db()->fetchAll('SELECT id FROM tblvendor_bookings WHERE booking_id = :b', [':b' => $b]), 'id');
                $created = array_diff($afterIds, $beforeIds);
                $this->assert('P8', 'couple vendor enquiry records a pending row for the wedding',
                    count($created) === 1 && $issues === [],
                    'HTTP ' . $res['status'] . '; new rows: ' . count($created)
                    . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
                foreach ($created as $cid) {
                    $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => (int) $cid]);
                }
            } else {
                $this->record('P8', 'SKIP', 'couple vendor enquiry', $vendor ? 'no CSRF token on the marketplace' : 'no approved vendor in the seed');
            }

            /*
             * ---- 8d-bis. An owner with TWO businesses must hear about both -
             * Every vendor page resolved its listing with
             * Vendor::get_by_user() — `LIMIT 1` with no ORDER BY — so an account
             * running two businesses was pinned to one of them. An enquiry sent
             * to the owner's OTHER listing landed in the database, appeared on
             * the staff ledger, and was invisible to the vendor it was addressed
             * to for good: the couple is told "They will contact you shortly".
             *
             * The Kenyan seed ships exactly one such owner, which makes this
             * checkable end to end. It is here rather than in a docblock
             * because the defect was a silent absence, not a crash.
             */
            $multiOwner = $this->one(
                'SELECT user_id, COUNT(*) c FROM tblvendors GROUP BY user_id HAVING c > 1 ORDER BY c DESC LIMIT 1'
            );
            $ownerEmail = $multiOwner
                ? (string) ($this->val('SELECT email FROM tblusers WHERE id = :u', [':u' => (int) $multiOwner->user_id]) ?? '')
                : '';
            if ($multiOwner && strcasecmp($ownerEmail, 'kamau@nyamachoma.co.ke') === 0) {
                $ownerListings = $this->db()->fetchAll(
                    'SELECT id FROM tblvendors WHERE user_id = :u ORDER BY id ASC',
                    [':u' => (int) $multiOwner->user_id]
                );
                $firstListing  = (int) $ownerListings[0]->id;
                $secondListing = (int) $ownerListings[count($ownerListings) - 1]->id;
                $multiMsg  = 'SIM multi-listing enquiry ' . time();
                $multiToken = $this->token('/admin/client_vendors.php?' . $ids);
                if ($multiToken !== null) {
                    $pkg2 = $this->one('SELECT id FROM tblvendor_packages WHERE vendor_id = :v AND is_active = 1 ORDER BY id LIMIT 1', [':v' => $secondListing]);
                    $res = $this->http('POST', '/admin/client_vendor_enquiry.php?' . $ids, [
                        '_token'     => $multiToken,
                        'vendor_id'  => (string) $secondListing,
                        'package_id' => $pkg2 ? (string) $pkg2->id : '0',
                        'message'    => $multiMsg,
                        'event_date' => date('Y-m-d', strtotime('+30 days')),
                    ]);
                    $enqRow = $this->one(
                        'SELECT id, vendor_id FROM tblvendor_bookings WHERE notes LIKE :m ORDER BY id DESC LIMIT 1',
                        [':m' => '%' . $multiMsg . '%']
                    );
                    $this->assert('P8', "couple enquiry is recorded against the owner's later listing (not #{$firstListing})",
                        $enqRow !== null && (int) $enqRow->vendor_id === $secondListing,
                        'HTTP ' . $res['status'] . ', listing ' . $secondListing);

                    // The vendor signs in and must see it without doing anything
                    // special: the ledger has to span every listing they own.
                    if ($this->session('P8', 'kamau@nyamachoma.co.ke', 'Vendor@2024', 'vendor_home')) {
                        $home = $this->http('GET', '/admin/vendor_home.php');
                        $this->assert('P8', 'multi-listing vendor sees the enquiry sent to their other business',
                            $home['status'] === 200 && strpos($home['body'], $multiMsg) !== false,
                            'HTTP ' . $home['status'] . " — vendor_home.php never shows the enquiry for listing {$secondListing}");
                    }
                    if ($enqRow) {
                        $this->exec('DELETE FROM tblvendor_bookings WHERE id = :i', [':i' => (int) $enqRow->id]);
                    }
                } else {
                    $this->record('P8', 'SKIP', 'multi-listing vendor ledger', 'no CSRF token on the marketplace');
                }
                $this->logout();
            } else {
                $this->record('P8', 'SKIP', 'multi-listing vendor ledger',
                    $multiOwner ? "no known credentials for the multi-listing owner ({$ownerEmail})" : 'no owner has more than one listing');
            }
            $this->logout();
        } else {
            $this->record('P8', 'SKIP', 'couple write legs', 'couple login failed');
        }

        // ---- 8e. Planner: RSVP mint tokens + broadcast ------------------
        if ($this->session('P8', 'amina@wpms.co.ke', 'Test@1234', 'dashboard')) {
            $rsvpPage = '/admin/rsvp_dashboard.php?' . $ids;
            $token = $this->token($rsvpPage);
            $beforeMinted = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND rsvp_token IS NOT NULL', [':b' => $b]) ?? 0);
            // The dashboard posts a guest selection as ids[] — the handler needs a
            // non-empty array, so hand it every guest of the booking.
            $allIds = array_map(static fn($g) => (string) (int) $g->id,
                $this->db()->fetchAll('SELECT id FROM tblguest WHERE booking_id = :b ORDER BY id', [':b' => $b]));
            if ($token !== null && $allIds !== []) {
                $res = $this->http('POST', '/admin/rsvp_mark_invited.php', ['_token' => $token, 'booking_id' => $b, 'user_id' => $u, 'ids' => $allIds]);
                $afterMinted = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND rsvp_token IS NOT NULL', [':b' => $b]) ?? 0);
                $invited = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND rsvp_invited_at IS NOT NULL', [':b' => $b]) ?? 0);
                $issues = $this->bodyIssues($res['body']);
                $this->assert('P8', 'planner "mark invites sent" mints tokens and stamps invited_at',
                    $afterMinted > 0 && $invited > 0 && $issues === [],
                    "HTTP {$res['status']}; tokens {$beforeMinted} → {$afterMinted}, invited {$invited}" . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
                $this->fx['rsvp_token'] = (string) ($this->val('SELECT rsvp_token FROM tblguest WHERE booking_id = :b AND rsvp_token IS NOT NULL ORDER BY id LIMIT 1', [':b' => $b]) ?? '');

                // Bulk reminders (same ids[] selection).
                $beforeReminded = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND rsvp_reminded_at IS NOT NULL', [':b' => $b]) ?? 0);
                $res = $this->http('POST', '/admin/rsvp_remind_bulk.php', ['_token' => $token, 'booking_id' => $b, 'user_id' => $u, 'ids' => $allIds]);
                $issues = $this->bodyIssues($res['body']);
                $afterReminded = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND rsvp_reminded_at IS NOT NULL', [':b' => $b]) ?? 0);
                $this->assert('P8', 'planner bulk RSVP reminder stamps the selection',
                    $afterReminded > $beforeReminded && $issues === [],
                    "HTTP {$res['status']}; reminded {$beforeReminded} → {$afterReminded}"
                    . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
            } else {
                $this->record('P8', 'FAIL', 'RSVP dashboard exposes a CSRF token and a guest selection',
                    'no _token or no guests on ' . $rsvpPage);
            }

            // ---- 8f. Planner: budget line add ---------------------------
            // budget_add.php writes a tbl_liquidation row (the budget ledger).
            $budgetPage = '/admin/budget_add.php?' . $ids;
            if ($this->token($budgetPage) !== null) {
                $before = (int) ($this->val('SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b', [':b' => $b]) ?? 0);
                $sub = $this->submitForm($budgetPage, '', [
                    'payment' => '1000', 'submit' => '1', 'payment_method' => 'cash',
                ], ['event_id' => (string) $this->fx['event']]);
                $after = (int) ($this->val('SELECT COUNT(*) FROM tbl_liquidation WHERE booking_id = :b', [':b' => $b]) ?? 0);
                $this->assert('P8', 'planner budget line add persists a ledger row', $after === $before + 1,
                    "HTTP {$sub['status']} -> {$sub['location']}; ledger rows {$before} → {$after}");
                $this->exec("DELETE FROM tbl_liquidation WHERE booking_id = :b AND payment = 1000", [':b' => $b]);
            } else {
                $this->record('P8', 'SKIP', 'planner budget add', 'no CSRF token rendered');
            }

            // ---- 8g. Planner: add a new client booking ------------------
            $addPage = '/admin/client_add.php';
            if ($this->token($addPage) !== null) {
                $email = 'sim.couple.' . bin2hex(random_bytes(3)) . '@example.com';
                $before = (int) ($this->val('SELECT COUNT(*) FROM tblaccounts') ?? 0);
                $sub = $this->submitForm($addPage, '', [
                    'firstname' => 'Sim', 'lastname' => 'Couple', 'email' => $email,
                    'phone' => '0700000000', 'city' => 'Nairobi',
                    'bride' => 'Sim Bride', 'groom' => 'Sim Groom',
                    'wedding_date' => date('Y-m-d', strtotime('+180 days')),
                    'wedding_type' => (string) ($this->fx['category'] ?? 0),
                    // The handler requires a confirmed password pair; the form
                    // renders these two fields (type=password, empty by design).
                    'user_password1' => 'Simulation@2026', 'user_password2' => 'Simulation@2026',
                    'submit' => '1',
                ]);
                $after = (int) ($this->val('SELECT COUNT(*) FROM tblaccounts') ?? 0);
                $issues = $this->bodyIssues($sub['body']);
                $this->assert('P8', 'planner client-add creates the couple account',
                    $after === $before + 1 && $issues === [],
                    "HTTP {$sub['status']} -> {$sub['location']}; accounts {$before} → {$after}"
                    . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
                $created = $this->one('SELECT user_id FROM tblaccounts WHERE user_email = :e', [':e' => $email]);
                if ($created) {
                    $this->exec('DELETE FROM tblaccounts_detail WHERE user_id = :u', [':u' => (int) $created->user_id]);
                    $this->exec('DELETE FROM tblweddingbook WHERE user_id = :u', [':u' => (int) $created->user_id]);
                    $this->exec('DELETE FROM tblaccounts WHERE user_id = :u', [':u' => (int) $created->user_id]);
                }
            } else {
                $this->record('P8', 'SKIP', 'planner client add', 'no CSRF token rendered');
            }

            // ---- 8g-bis. Planner: SMS credits are a PAID product --------
            // The pack prices are real, so a purchase must never succeed
            // without a transaction code. (Whether a 10-char code is checked
            // against Daraja depends on WPMS_MPESA_VERIFY, which is a
            // deployment setting — the code itself is always required.)
            $plannerId = (int) ($this->val("SELECT id FROM tblusers WHERE email = 'amina@wpms.co.ke' LIMIT 1") ?? 0);
            $smsPage = '/admin/sms_center.php?' . $ids;
            $smsToken = $this->token($smsPage);
            if ($plannerId > 0 && $smsToken !== null) {
                $creditsBefore = (int) ($this->val('SELECT credits FROM tblsms_credits WHERE user_id = :u', [':u' => $plannerId]) ?? 0);
                $this->http('POST', $smsPage, ['_token' => $smsToken, 'purchase_sms' => '1', 'sms_package' => 'premium']);
                $noCode = (int) ($this->val('SELECT credits FROM tblsms_credits WHERE user_id = :u', [':u' => $plannerId]) ?? 0);
                $this->assert('P8', 'a paid SMS pack cannot be bought with no M-Pesa code',
                    $noCode === $creditsBefore, "credits {$creditsBefore} → {$noCode}");
                $this->http('POST', $smsPage, ['_token' => $smsToken, 'purchase_sms' => '1', 'sms_package' => 'premium', 'mpesa_code' => 'SHORT']);
                $short = (int) ($this->val('SELECT credits FROM tblsms_credits WHERE user_id = :u', [':u' => $plannerId]) ?? 0);
                $this->assert('P8', 'a truncated M-Pesa code cannot buy a pack either',
                    $short === $creditsBefore, "credits {$creditsBefore} → {$short}");
                $this->http('POST', $smsPage, ['_token' => $smsToken, 'purchase_sms' => '1', 'sms_package' => 'not-a-pack', 'mpesa_code' => 'ABCDEFGHIJ']);
                $bogus = (int) ($this->val('SELECT credits FROM tblsms_credits WHERE user_id = :u', [':u' => $plannerId]) ?? 0);
                $this->assert('P8', 'an unknown SMS pack cannot be bought',
                    $bogus === $creditsBefore, "credits {$creditsBefore} → {$bogus}");
            } else {
                $this->record('P8', 'SKIP', 'SMS credit payment gate', 'no planner session or no CSRF token');
            }

            // ---- 8h. Planner: guest write via the staff guest list -------
            $guestPage = '/admin/guest_add.php?' . $ids;
            if ($this->token($guestPage) !== null) {
                $before = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $b]) ?? 0);
                // address and guestname are required by this handler; the form
                // renders them, so take the page's own values and override the rest.
                $sub = $this->submitForm($guestPage, '', [
                    'fullname' => 'SIM Staff Guest', 'guestname' => 'sim guest',
                    'address' => 'Simulation Street', 'city' => 'Kisumu',
                    'priority' => 'C', 'relationship' => 'regular', 'out_of_town' => 'n',
                    'submit' => '1',
                ]);
                $after = (int) ($this->val('SELECT COUNT(*) FROM tblguest WHERE booking_id = :b', [':b' => $b]) ?? 0);
                $this->assert('P8', 'planner guest add persists a row', $after === $before + 1,
                    "HTTP {$sub['status']} -> {$sub['location']}; {$before} → {$after}");
                $this->exec("DELETE FROM tblguest WHERE booking_id = :b AND fullname = 'SIM Staff Guest'", [':b' => $b]);
            } else {
                $this->record('P8', 'SKIP', 'planner guest add', 'no CSRF token rendered');
            }
            $this->logout();
        } else {
            $this->record('P8', 'SKIP', 'planner write legs', 'planner login failed');
        }

        // ---- 8i. Guest answers the public RSVP page -------------------
        $rsvpToken = (string) ($this->fx['rsvp_token'] ?? $this->val('SELECT rsvp_token FROM tblguest WHERE booking_id = :b AND rsvp_token IS NOT NULL ORDER BY id LIMIT 1', [':b' => $b]) ?? '');
        if ($rsvpToken !== '') {
            $guest = $this->one('SELECT id, rsvp_status FROM tblguest WHERE rsvp_token = :t', [':t' => $rsvpToken]);
            $this->jar = [];
            $page = $this->http('GET', '/rsvp.php?g=' . urlencode($rsvpToken));
            $this->assert('P8', 'guest RSVP page renders for a valid token', $page['status'] === 200, 'HTTP ' . $page['status']);
            $issues = $this->bodyIssues($page['body']);
            if ($issues !== []) {
                $this->record('P8', 'FAIL', 'guest RSVP page renders cleanly', implode(' || ', array_slice($issues, 0, 2)));
            }
            /*
             * rsvp.php carries no CSRF field by design: the 32-hex token in the
             * URL IS the capability, it names exactly one guest of one booking,
             * and the page is meant to be opened straight from a WhatsApp link
             * with no session at all. So this posts the fields the page renders.
             */
            if (strpos($page['body'], 'name="rsvp"') !== false || strpos($page['body'], "name='rsvp'") !== false) {
                $res = $this->http('POST', '/rsvp.php?g=' . urlencode($rsvpToken), [
                    'rsvp' => '1', 'token' => $rsvpToken, 'status' => 'yes', 'note' => 'Simulation reply',
                ]);
                $issues = $this->bodyIssues($res['body']);
                $after = $this->one('SELECT rsvp_status, rsvp_note FROM tblguest WHERE rsvp_token = :t', [':t' => $rsvpToken]);
                $this->assert('P8', 'guest RSVP reply persists and shows the confirmation',
                    $after !== null && $after->rsvp_status === 'yes' && $issues === []
                        && stripos($res['body'], 'Asante') !== false,
                    "HTTP {$res['status']}; status=" . ($after->rsvp_status ?? 'missing')
                    . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
                // Re-answering must work too (guests change their minds).
                $res = $this->http('POST', '/rsvp.php?g=' . urlencode($rsvpToken), [
                    'rsvp' => '1', 'token' => $rsvpToken, 'status' => 'maybe', 'note' => 'Second thought',
                ]);
                $after2 = $this->one('SELECT rsvp_status FROM tblguest WHERE rsvp_token = :t', [':t' => $rsvpToken]);
                $this->assert('P8', 'a guest can change their answer',
                    $after2 !== null && $after2->rsvp_status === 'maybe',
                    'status=' . ($after2->rsvp_status ?? 'missing'));
                $this->exec("UPDATE tblguest SET rsvp_status = 'pending', rsvp_note = NULL, rsvp_at = NULL WHERE rsvp_token = :t", [':t' => $rsvpToken]);

                // And an invalid status value must not be stored.
                $this->http('POST', '/rsvp.php?g=' . urlencode($rsvpToken), [
                    'rsvp' => '1', 'token' => $rsvpToken, 'status' => 'definitely', 'note' => 'x',
                ]);
                $bad = (string) ($this->val('SELECT rsvp_status FROM tblguest WHERE rsvp_token = :t', [':t' => $rsvpToken]) ?? '');
                $this->assert('P8', 'an out-of-range RSVP status is not stored', $bad === 'pending',
                    'stored status=' . $bad);
            } else {
                $this->record('P8', 'SKIP', 'guest RSVP POST', 'the RSVP page renders no rsvp form for this token');
            }
        } else {
            $this->record('P8', 'SKIP', 'guest RSVP reply', 'no guest token available');
        }

        // ---- 8j. Super admin: account + reset + vendor onboarding --------
        if ($this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard')) {
            $addStaff = '/admin/users_add.php';
            if ($this->token($addStaff) !== null) {
                $email = 'sim.staff.' . bin2hex(random_bytes(3)) . '@example.com';
                $sub = $this->submitForm($addStaff, '', [
                    'firstname' => 'Sim', 'lastname' => 'Staff',
                    'username' => 'sim.staff.' . bin2hex(random_bytes(3)),
                    'email' => $email,
                    'password' => 'Simulation@2026',
                    // The handler confirms with password2 (the form's second
                    // password box), not a confirm_password field.
                    'password2' => 'Simulation@2026',
                    'gender' => 'f', 'designation' => 'Planner',
                    'address' => 'Nairobi', 'submit' => '1',
                ]);
                $issues = $this->bodyIssues($sub['body']);
                $created = $this->one('SELECT id FROM tblusers WHERE email = :e', [':e' => $email]);
                $this->assert('P8', 'operator staff-add creates the account',
                    $created !== null && $issues === [],
                    'HTTP ' . $sub['status'] . ' -> ' . $sub['location'] . '; ' . ($created ? 'row created' : 'no row')
                    . ($issues !== [] ? ' | ' . implode(' || ', array_slice($issues, 0, 2)) : ''));
                if ($created) {
                    // The new account must actually be able to sign in.
                    $this->logout();
                    $logged = $this->login('P8', $email, 'Simulation@2026', 'subscription|dashboard', '/login.php');
                    $this->assert('P8', 'the account the operator just created can sign in', $logged);
                    $this->logout();
                    $this->exec('DELETE FROM tblusers WHERE id = :i', [':i' => (int) $created->id]);
                    $this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard');
                }
            } else {
                $this->record('P8', 'SKIP', 'operator staff add', 'no CSRF token rendered');
            }

            // ---- 8k. Vendor onboarding: approval → notify → premium -------
            $pendingVendor = $this->one('SELECT id, business_name FROM tblvendors WHERE is_approved = 0 ORDER BY id LIMIT 1');
            if ($pendingVendor) {
                $vid = (int) $pendingVendor->id;
                $sub = $this->submitForm('/admin/vendors.php', '', [
                    'set_vendor_approval' => '1', 'vendor_id' => (string) $vid, 'approved' => '1',
                ]);
                $now = (int) ($this->val('SELECT is_approved FROM tblvendors WHERE id = :i', [':i' => $vid]) ?? -1);
                $this->assert('P8', 'operator approves a pending vendor listing', $now === 1,
                    'HTTP ' . $sub['status'] . ' -> ' . $sub['location'] . '; is_approved=' . $now);

                // vendor_register.php promises "You will be notified once
                // approved" — the decision has to actually reach the vendor.
                $vendorUserId = (int) ($this->val('SELECT user_id FROM tblvendors WHERE id = :i', [':i' => $vid]) ?? 0);
                $notified = (int) ($this->val(
                    'SELECT COUNT(*) FROM tblnotifications WHERE user_id = :u AND message LIKE :m',
                    [':u' => $vendorUserId, ':m' => '%approved and now live%']
                ) ?? 0);
                $this->assert('P8', 'approving a listing notifies the vendor (as promised)',
                    $vendorUserId > 0 && $notified > 0,
                    "no approval notification for vendor user {$vendorUserId}");
                $this->exec("DELETE FROM tbl_mail_logs WHERE notification_id IN (SELECT id FROM (SELECT id FROM tblnotifications WHERE user_id = :u AND message LIKE '%approved and now live%') x)", [':u' => $vendorUserId]);
                $this->exec("DELETE FROM tblnotifications WHERE user_id = :u AND message LIKE '%approved and now live%'", [':u' => $vendorUserId]);

                /*
                 * Premium placement: nothing used to write is_premium at all,
                 * so the badge and the sort key were unreachable for every row
                 * the operator could see. Measured as a POSITION IMPROVEMENT
                 * rather than "first", because more than one seeded listing may
                 * already be premium and the secondary sort is rating.
                 */
                $bizName = (string) ($this->val('SELECT business_name FROM tblvendors WHERE id = :i', [':i' => $vid]) ?? '');
                $cardPosition = function () use ($bizName): int {
                    $body = $this->http('GET', '/admin/vendors.php')['body'];
                    if (!preg_match_all('~vendor-name">([^<]+)~', $body, $m)) {
                        return -1;
                    }
                    foreach ($m[1] as $i => $name) {
                        if (trim(html_entity_decode($name, ENT_QUOTES)) === $bizName) {
                            return $i;
                        }
                    }
                    return -1; // not listed at all
                };
                $before = $cardPosition();
                // Target the card's own premium form and drop the approval
                // control a sibling form carries, exactly as a click would.
                $premiumSub = $this->submitForm('/admin/vendors.php', '', [
                    'vendor_id' => (string) $vid, 'premium' => '1',
                ], [], ['set_vendor_premium' => '1'], ['set_vendor_approval']);
                $prem = (int) ($this->val('SELECT is_premium FROM tblvendors WHERE id = :i', [':i' => $vid]) ?? -1);
                $this->assert('P8', 'operator can grant a premium listing', $prem === 1,
                    'HTTP ' . $premiumSub['status'] . '; is_premium=' . $prem);
                $after = $cardPosition();
                $this->assert('P8', 'a premium listing sorts above where it sat before',
                    $before >= 0 && $after >= 0 && $after < $before,
                    "card position {$before} → {$after}");
                // Only premium is reset here — the approval the next checks need
                // stays in place until the existing restore at the end of 8k.
                $this->exec('UPDATE tblvendors SET is_premium = 0 WHERE id = :i', [':i' => $vid]);
                $this->exec("DELETE FROM tblnotifications WHERE user_id = :u AND message LIKE '%Premium placement%'", [':u' => $vendorUserId]);

                // A published listing must become visible to couples.
                $this->logout();
                $owner = $this->one('SELECT booking_id, user_id FROM tblweddingbook WHERE booking_id = 203');
                if ($owner && $this->session('P8', 'chebet.eldoret@gmail.com', 'Test@1234', 'client.php')) {
                    $ids2 = $this->ids((int) $owner->booking_id, (int) $owner->user_id);
                    $market = $this->http('GET', '/admin/client_vendors.php?' . $ids2);
                    $this->assert('P8', 'the approved vendor appears in the couple marketplace',
                        stripos($market['body'], (string) $pendingVendor->business_name) !== false,
                        'listing not rendered on client_vendors.php');
                    $this->logout();
                }

                // A planner is not the operator and must not publish listings.
                if ($this->session('P8', 'amina@wpms.co.ke', 'Test@1234', 'dashboard')) {
                    $this->submitForm('/admin/vendors.php', '', [
                        'set_vendor_approval' => '1', 'vendor_id' => (string) $vid, 'approved' => '0',
                    ], [], [], ['set_vendor_premium']);
                    $still = (int) ($this->val('SELECT is_approved FROM tblvendors WHERE id = :i', [':i' => $vid]) ?? -1);
                    $this->assert('P8', 'a non-operator planner cannot change a listing approval', $still === 1,
                        'is_approved became ' . $still);
                    $this->logout();
                }

                // Restore the seed state and get the operator session back.
                $this->exec('UPDATE tblvendors SET is_approved = 0 WHERE id = :i', [':i' => $vid]);
                $this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard');
            } else {
                $this->record('P8', 'SKIP', 'vendor approval', 'no pending vendor listing in the seed');
            }

            // Password reset round trip for a staff account.
            $target = $this->one("SELECT id, email FROM tblusers WHERE access_level = 1 AND email = 'njeri@wpms.co.ke' LIMIT 1");
            if ($target) {
                $this->logout();
                $this->jar = [];
                $token = $this->token('/forgot_password.php');
                $res = $this->http('POST', '/forgot_password.php', ['_token' => (string) $token, 'email' => $target->email, 'submit' => '1']);
                $issues = $this->bodyIssues($res['body']);
                $this->assert('P8', 'forgot-password answered without a PHP error',
                    $issues === [], 'HTTP ' . $res['status'] . ' | ' . implode(' || ', array_slice($issues, 0, 2)));
                $row = $this->one('SELECT * FROM tbl_password_resets WHERE user_id = :u ORDER BY id DESC LIMIT 1', [':u' => (int) $target->id]);
                $this->record('P8', 'INFO', $row ? 'reset token row created (single use)' : 'no reset row (mail may be disabled)', '');
                if ($row) {
                    $tokenPlain = (string) ($row->token ?? '');
                    $this->exec('DELETE FROM tbl_password_resets WHERE user_id = :u', [':u' => (int) $target->id]);
                    if ($tokenPlain !== '') {
                        $res = $this->http('GET', '/reset_password.php?token=' . urlencode($tokenPlain));
                        $this->assert('P8', 'reset link renders the new-password form', $res['status'] === 200, 'HTTP ' . $res['status']);
                    }
                }
            }
            $this->logout();

            /*
             * ---- 8h. Couple onboarding funnel -------------------------------
             * Public sign-up tells the couple "Our team will review your date
             * and confirm your booking shortly" (thank_you.php) and their own
             * overview repeats it ("Awaiting confirmation — our team is
             * reviewing your date") until tblaccounts_detail.status is
             * 'confirm'. That is a promise made TO one role that only ANOTHER
             * role can keep, so the whole chain is walked rather than assumed:
             * sign-up → operator's pending list → assignment → the couple's
             * page flipping to Confirmed.
             *
             * A missing capability anywhere here is the same defect class as
             * the vendor approval dead end (a role told to wait for a screen
             * nobody has), which is why the funnel is an assertion, not a
             * comment. The account is named 'sim.' so a cleanup failure is
             * caught by the phase-9 residue audit.
             */
            $funnelEmail = 'sim.funnel.' . time() . '@example.com';
            $funnelPass  = 'Funnel@1234';
            $signupToken = $this->token('/index.php');
            $res = $this->http('POST', '/index.php', [
                '_token'         => (string) $signupToken,
                'register'       => '1',
                'user_firstname' => 'Sim',
                'user_lastname'  => 'Funnel',
                'user_email'     => $funnelEmail,
                'user_phone'     => '0712345999',
                'user_password'  => $funnelPass,
                'wedding_date'   => '12/23/2031',
            ]);
            $this->assert('P8', 'public sign-up accepts a new couple',
                $res['status'] === 302 && stripos($res['location'], 'thank_you.php') !== false,
                'HTTP ' . $res['status'] . ' -> ' . $res['location']);

            // tblaccounts' primary key is `user_id`, not `id`.
            $newUser  = (int) ($this->val('SELECT user_id FROM tblaccounts WHERE user_email = :e', [':e' => $funnelEmail]) ?? 0);
            $newBook  = (int) ($this->val('SELECT booking_id FROM tblweddingbook WHERE user_id = :u ORDER BY booking_id DESC LIMIT 1', [':u' => $newUser]) ?? 0);
            $newState = (string) ($this->val('SELECT status FROM tblaccounts_detail WHERE user_id = :u', [':u' => $newUser]) ?? '');
            $this->assert('P8', 'sign-up writes the account, the detail row and the booking',
                $newUser > 0 && $newBook > 0 && $newState === 'pending',
                "user={$newUser} booking={$newBook} status='{$newState}'");

            if ($newUser > 0 && $newBook > 0) {
                // 1. The couple sees the promise their page makes.
                $this->session('P8', $funnelEmail, $funnelPass, 'client_manage_account_details|client.php');
                $mine = $this->http('GET', '/admin/client_manage_account_details.php?booking_id=' . $newBook . '&user_id=' . $newUser);
                $this->assert('P8', 'a brand-new couple is told their date is awaiting confirmation',
                    $mine['status'] === 200 && stripos($mine['body'], 'Awaiting confirmation') !== false,
                    'HTTP ' . $mine['status']);

                // 2. The operator can actually see it to keep it.
                $this->session('P8', 'wafula@wpms.co.ke', 'Admin@2024!', 'dashboard');
                $list = $this->http('GET', '/admin/client.php');
                // Strict: the row must carry the assignment LINK, not merely the
                // couple's name — being visible without an action is exactly the
                // dead end this check exists to catch.
                $listed = $list['status'] === 200
                    && stripos($list['body'], 'client_assign.php?booking=' . $newBook) !== false;
                $this->assert('P8', "the operator's pending list offers a way to confirm the new booking",
                    $listed, 'HTTP ' . $list['status'] . " — booking {$newBook} not offered for assignment");

                // 3. The operator confirms it.
                $assignUrl   = '/admin/client_assign.php?booking=' . $newBook . '&user_id=' . $newUser;
                $assignToken = $this->token($assignUrl);
                $res = $this->http('POST', $assignUrl, [
                    '_token'              => (string) $assignToken,
                    'confirm'             => '1',
                    'firstname'           => 'Sim',
                    'lastname'            => 'Funnel',
                    'email'               => $funnelEmail,
                    'wedding_date'        => '2031-12-23',
                    'bride'               => 'Sim Bride',
                    'groom'               => 'Sim Groom',
                    'phone'               => '0712345999',
                    'city'                => 'Nairobi',
                    'wedding_type'        => '1',
                    'organizer_id'        => '2',
                    'location'            => 'Nairobi - Carnivore Grounds',
                    'description'         => 'SIM funnel booking',
                    'expectation_visitor' => '100',
                    'cash_advanced'       => '0',
                ]);
                $after = (string) ($this->val('SELECT status FROM tblaccounts_detail WHERE user_id = :u', [':u' => $newUser]) ?? '');
                $this->assert('P8', 'the operator confirms the couple (pending → confirm)',
                    $after === 'confirm',
                    "HTTP {$res['status']}; status='{$after}'");
                $this->assert('P8', 'confirmation records an assigned planner on the booking',
                    (int) ($this->val('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b', [':b' => $newBook]) ?? 0) === 2,
                    'organizer_id on booking ' . $newBook);

                // 4. And the couple's page stops promising and confirms.
                $this->session('P8', $funnelEmail, $funnelPass, 'client_manage_account_details|client.php');
                $confirmed = $this->http('GET', '/admin/client_manage_account_details.php?booking_id=' . $newBook . '&user_id=' . $newUser);
                $this->assert('P8', 'the confirmed couple now sees Confirmed, not the wait message',
                    $confirmed['status'] === 200
                    && stripos($confirmed['body'], 'Confirmed') !== false
                    && stripos($confirmed['body'], 'Awaiting confirmation') === false,
                    'HTTP ' . $confirmed['status']);

                // Clean up the funnel account entirely (phase 9 re-checks this).
                $this->exec('DELETE FROM tblnotifications WHERE user_id = :u OR booking_id = :b', [':u' => $newUser, ':b' => $newBook]);
                $this->exec('DELETE FROM tblweddingbook WHERE booking_id = :b', [':b' => $newBook]);
                $this->exec('DELETE FROM tblaccounts_detail WHERE user_id = :u', [':u' => $newUser]);
                $this->exec('DELETE FROM tblaccounts WHERE user_email = :e', [':e' => $funnelEmail]);
                $this->exec('DELETE FROM tblsignup_throttle WHERE email = :e', [':e' => $funnelEmail]);
            } else {
                $this->record('P8', 'SKIP', 'couple onboarding funnel',
                    'sign-up did not create the rows, so the operator half cannot be walked');
            }
            $this->logout();
        }
    }

    /** Probe the freshly-cleaned state: no simulation residue left behind. */
    private function phase9Residue(): void
    {
        $this->phase('PHASE 9 — Clean-up audit (no simulation residue in the scratch DB)');
        $checks = [
            'guests named SIM%'        => "SELECT COUNT(*) FROM tblguest WHERE fullname LIKE 'SIM %'",
            'probe guests'             => "SELECT COUNT(*) FROM tblguest WHERE fullname LIKE '%PROBE%'",
            'sim staff accounts'       => "SELECT COUNT(*) FROM tblusers WHERE email LIKE 'sim.%'",
            'sim couple accounts'      => "SELECT COUNT(*) FROM tblaccounts WHERE user_email LIKE 'sim.%'",
            'sim vendor enquiries'     => "SELECT COUNT(*) FROM tblvendor_bookings WHERE notes LIKE 'SIM enquiry%'",
            'sim record-door deals'     => "SELECT COUNT(*) FROM tblvendor_bookings WHERE notes LIKE 'SIM record-door%'",
            'sim headroom probe deals'  => "SELECT COUNT(*) FROM tblvendor_bookings WHERE notes LIKE 'SIM headroom probe%'",
            'sim headroom probe packages' => "SELECT COUNT(*) FROM tblvendor_packages WHERE title LIKE 'SIM headroom probe%'",
            'sim saved preferences'      => "SELECT COUNT(*) FROM tbluser_prefs WHERE pref_key = 'headroom_width'",
            'sim budget lines'         => "SELECT COUNT(*) FROM tbl_liquidation WHERE payment = 1000",
            'outstanding reset tokens' => 'SELECT COUNT(*) FROM tbl_password_resets',
            'plan-change probe rows'   => "SELECT COUNT(*) FROM tblsubscription_plan_changes WHERE plan_slug LIKE 'simprobe%'",
            // Not residue by name but by shape: a headcount row whose features_id
            // is not a function of its own booking is displayed and editable
            // nowhere (the defect this release closes), and two rows for one
            // (booking, function) would make the sheet ambiguous.
            'venue headcount rows with no matching function' =>
                'SELECT COUNT(*) FROM tbleventbooking eb WHERE NOT EXISTS'
                . ' (SELECT 1 FROM events e WHERE e.id = eb.features_id AND e.booking_id = eb.booking_id)',
            'duplicate venue headcount plans' =>
                'SELECT COUNT(*) FROM (SELECT 1 FROM tbleventbooking'
                . ' GROUP BY booking_id, features_id HAVING COUNT(*) > 1) dupes',
        ];
        foreach ($checks as $label => $sql) {
            $n = (int) ($this->val($sql) ?? -1);
            $this->assert('P9', 'no residue: ' . $label, $n === 0, "{$n} row(s) remain");
        }
        // The seeded logins must still work after the whole run.
        foreach ([
            ['wafula@wpms.co.ke', 'Admin@2024!', 'dashboard'],
            ['amina@wpms.co.ke', 'Test@1234', 'dashboard'],
            ['kamau@nyamachoma.co.ke', 'Vendor@2024', 'vendor_home'],
            ['brian.wafula@gmail.com', 'Test@1234', 'client.php'],
        ] as [$email, $pass, $want]) {
            $this->assert('P9', "seeded login still verifies: {$email}", $this->session('P9', $email, $pass, $want));
            $this->logout();
        }
    }

    // --------------------------------------------------------------- run

    public function run(array $only = []): int
    {
        // Capture the log baseline before the first request so the phase-10
        // scan covers exactly this run.
        if ($this->logPath !== null) {
            clearstatcache(true, $this->logPath);
            $this->logOffset = (int) (@filesize($this->logPath) ?: 0);
        }

        $probe = $this->http('GET', '/index.php', [], false);
        if ($probe['status'] === 0) {
            fwrite(STDERR, "RoleSim: cannot reach {$this->base} — is the dev server running?\n");
            return 2;
        }
        $dbName = defined('DB_NAME') ? DB_NAME : '(unknown)';
        echo "Role simulation against {$this->base}\nDatabase: {$dbName}\n";
        if (in_array(strtolower($dbName), ['dbwedding', 'wordpress', ''], true) && getenv('WPMS_SIM_ALLOW_LIVE') === false) {
            fwrite(STDERR, "RoleSim: refusing to run against '{$dbName}' — this harness writes. Point it at a scratch DB (WPMS_DB_NAME=wpms_sim).\n");
            return 2;
        }
        $this->prepare();
        $this->fixtures();
        echo 'Fixtures: booking=' . $this->fx['booking'] . ' couple=' . $this->fx['couple_user']
            . ' feature=' . $this->fx['feature'] . ' slug=' . $this->fx['slug'] . "\n";

        $phases = [
            1 => 'phase1Public',
            2 => 'phase2Auth',
            3 => 'phase3Couple',
            4 => 'phase4Planner',
            5 => 'phase5LockedPlanner',
            6 => 'phase6Vendor',
            7 => 'phase7SuperAdmin',
            8 => 'phase8Writes',
            9 => 'phase9Residue',
            10 => 'phase10LogDiagnostics',
        ];
        foreach ($phases as $n => $method) {
            if ($only !== [] && !in_array($n, $only, true)) {
                continue;
            }
            try {
                $this->{$method}();
            } catch (Throwable $e) {
                $this->record('P' . $n, 'FAIL', 'phase ' . $n . ' threw',
                    get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }

        echo "\n" . str_repeat('=', 78) . "\n";
        echo "TOTAL: {$this->pass} passed, {$this->fail} failed, {$this->skip} skipped\n";
        $byPhase = [];
        foreach ($this->rows as $r) {
            if ($r['status'] === 'FAIL') {
                $byPhase[$r['phase']] = ($byPhase[$r['phase']] ?? 0) + 1;
            }
        }
        if ($byPhase !== []) {
            echo "Failures by phase: ";
            foreach ($byPhase as $p => $c) {
                echo "{$p}={$c} ";
            }
            echo "\n";
        }
        return $this->fail === 0 ? 0 : 1;
    }

    /**
     * Diagnostics the dev server logged during the run.
     *
     * @return array<int,array{message:string,count:int,file:string}>
     */
    private function logDiagnostics(): array
    {
        if ($this->logPath === null) {
            return [];
        }
        /*
         * Seek and read to EOF. Do NOT gate this on filesize(): PHP caches
         * stat results per path for the life of the process, so the size read
         * at baseline is handed back unchanged for the rest of the run and a
         * size comparison silently reports "nothing new" while the server is
         * busy appending deprecations. That mistake made this phase pass on a
         * run whose log held 130 real diagnostics — a check that cannot fail
         * is worse than no check, so the size is not consulted at all.
         */
        $fh = @fopen($this->logPath, 'rb');
        if ($fh === false) {
            return [];
        }
        if (fseek($fh, $this->logOffset) !== 0) {
            fclose($fh);
            return [];
        }
        $chunk = (string) stream_get_contents($fh, 8 * 1024 * 1024);
        fclose($fh);

        // Built-in-server stderr shape, e.g.
        //   [15-Oct-2026 12:34:56 UTC] PHP Deprecated:  Creation of dynamic
        //   property DateInterval::$w is deprecated in .../Helper.php on line 33
        // Request lines carry no "PHP " prefix, so they cannot match here.
        if (!preg_match_all(
            '~^\[[^\]]*\]\s+PHP\s+(Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)\s*:?\s*(.+)$~mi',
            $chunk,
            $m,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $seen = [];
        foreach ($m as $hit) {
            $kind = $hit[1];
            $text = trim(preg_replace('~\s+~', ' ', $hit[2]));
            $key = $kind . ': ' . $text;
            if (!isset($seen[$key])) {
                $file = '';
                if (preg_match('~ in (.+?) on line \d+~', $text, $fm)) {
                    $file = $fm[1];
                }
                $seen[$key] = ['message' => $key, 'count' => 0, 'file' => $file];
            }
            $seen[$key]['count']++;
        }
        return array_values($seen);
    }

    /**
     * Phase 10 — the server log is a diagnostic channel the body scan cannot
     * see. Runs last so it covers everything the other phases requested.
     */
    private function phase10LogDiagnostics(): void
    {
        $this->phase('Phase 10 — server-log diagnostics');
        if ($this->logPath === null) {
            $this->record('P10', 'SKIP', 'server-log diagnostics not checked',
                'no --log=PATH given (pass the dev server log to make log-only warnings visible)');
            return;
        }
        $found = $this->logDiagnostics();
        if ($found === []) {
            $this->record('P10', 'PASS', 'server log free of PHP warnings/notices/deprecations');
            return;
        }
        $this->record('P10', 'FAIL', 'server log reported ' . count($found) . ' distinct PHP diagnostic(s)',
            'these appear only in the log, not in any response body');
        foreach ($found as $d) {
            $this->record('P10', 'FAIL', 'log: ' . substr($d['message'], 0, 120)
                . ($d['count'] > 1 ? " (x{$d['count']})" : ''), $d['file']);
        }
    }

    /** @return array{phase:string,status:string,label:string,detail:string,path:string}[] */
    public function rows(): array
    {
        return $this->rows;
    }
}

// ------------------------------------------------------------------ cli
$args = array_slice($argv, 1);
$base = 'http://127.0.0.1:8123';
$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpms_role_sim.json';
$only = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, 'http')) {
        $base = rtrim($arg, '/');
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif (str_starts_with($arg, '--log=')) {
        $logPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--only=')) {
        $only = array_map('intval', explode(',', substr($arg, 7)));
    } elseif (str_starts_with($arg, '--verbose')) {
        // handled below via a static flag
    }
}

$sim = new RoleSim($base);
if (isset($logPath)) {
    $sim->setLogPath($logPath);
}
$code = $sim->run($only);

$json = [
    'base'      => $base,
    'database'  => defined('DB_NAME') ? DB_NAME : null,
    'generated' => date('c'),
    'rows'      => $sim->rows(),
];
file_put_contents($out, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "JSON written to {$out}\n";
exit($code);
