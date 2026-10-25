<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.1 — Hardened Session Manager
 *
 * Security guarantees:
 *  - Cookie: httponly=1, secure (auto-detect HTTPS), samesite=Strict, path=/, lifetime=0 (session)
 *  - Regenerate ID on login, privilege change, and every 15 min (900s)
 *  - Fingerprint: hash(IP + User-Agent) stored at session creation; enforced on each request
 *                (configurable via SESSION_ENFORCE_FINGERPRINT; behind CGNAT/proxy you can set false)
 *  - Inactivity timeout: 2 hours (configurable)
 *  - CSRF via random_bytes(32) + hash_equals
 *  - Compatible with PDO Database wrapper
 */

class Session
{
    private bool $signed_in = false;
    public mixed $user_id = null;
    public int $count = 0;
    public string $message = '';
    public mixed $stay = null;

    // Tunables
    private const REGENERATE_INTERVAL = 900; // 15 min
    private const INACTIVITY_TIMEOUT  = 7200; // 2 hours
    private const COOKIE_LIFETIME     = 0;    // session cookie
    private const COOKIE_PATH         = '/';
    private const COOKIE_SAMESITE     = 'Strict';

    private bool $enforceFingerprint;

    public function __construct(bool $enforceFingerprint = true)
    {
        $this->enforceFingerprint = $enforceFingerprint;

        $this->configureCookieParams();
        $this->startSession();
        $this->visitor_count();
        $this->check_message();
        $this->check_the_login();

        // Harden after session started
        $this->enforceTimeout();
        $this->enforceFingerprintCheck();
        $this->periodicRegeneration();
    }

    /**
     * Configure cookie params BEFORE session_start().
     * Uses PHP 7.3+ array signature for samesite support.
     */
    private function configureCookieParams(): void
    {
        if (headers_sent()) {
            return;
        }
        // Session ini settings cannot be changed once a session is active;
        // params were already applied at first start (one Session per request).
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = $this->isHttps();

        // PHP 8.2: session_set_cookie_params(array) is preferred
        $params = [
            'lifetime' => self::COOKIE_LIFETIME,
            'path'     => self::COOKIE_PATH,
            'domain'   => '', // current host
            'secure'   => $isHttps, // true on HTTPS, false on http://localhost dev
            'httponly' => true,
            'samesite' => self::COOKIE_SAMESITE,
        ];

        session_set_cookie_params($params);

        // Hardening ini (defense in depth)
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', self::COOKIE_SAMESITE);
        // session.cookie_secure set dynamically via params above
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Suppress warning if session already manipulated; but log
            if (!@session_start()) {
                error_log('[WPMS][Session] session_start failed');
            }
        }

        // Initialize fingerprint + created markers if new session
        if (!isset($_SESSION['_fingerprint'])) {
            $_SESSION['_fingerprint'] = $this->computeFingerprint();
        }
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        }
        if (!isset($_SESSION['_last_activity'])) {
            $_SESSION['_last_activity'] = time();
        }
        if (!isset($_SESSION['_last_regeneration'])) {
            $_SESSION['_last_regeneration'] = time();
        }
    }

    /**
     * Fingerprint = HMAC-SHA256(IP + User-Agent, server salt)
     * IP: REMOTE_ADDR + X-Forwarded-For first hop if trusted proxy
     */
    private function computeFingerprint(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // If behind trusted proxy (XAMPP local), prefer X-Forwarded-For first IP
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($forwarded[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        // Use a per-install salt if available; fallback to DB_NAME + static string
        $salt = defined('DB_NAME') ? DB_NAME : 'wpms_kenya_v2.1_salt';
        return hash_hmac('sha256', $ip . '|' . $ua, $salt);
    }

    private function enforceFingerprintCheck(): void
    {
        if (!$this->enforceFingerprint) {
            return;
        }
        if (!isset($_SESSION['_fingerprint'])) {
            return;
        }
        $current = $this->computeFingerprint();
        if (!hash_equals($_SESSION['_fingerprint'], $current)) {
            error_log('[WPMS][Session] Fingerprint mismatch — possible hijack. IP/UA changed. Forcing logout.');
            $this->audit('fingerprint_mismatch');
            $this->forceLogout('Session invalidated for security. Please log in again.');
        }
    }

    /**
     * One row in tblsession_audits — who signed in, from where, on what.
     *
     * SYSTEM_OVERVIEW §7 promises this trail ("audit rows in tblsession_audits")
     * and the table has existed since v2.1, but nothing ever wrote to it: the
     * platform could tell you an account was breached only from the PHP error
     * log, which rotates and is not queryable. Every security-relevant event in
     * this class now lands here: login, logout, the 15-minute ID rotation, a
     * privilege change, an inactivity timeout and a fingerprint mismatch (the
     * hijack signal).
     *
     * FAIL SILENT, ALWAYS. This class is on the path of every request; a missing
     * table (a fresh install that never ran v2.1, a test database) or a locked
     * row must never turn a sign-in into a 500. The event still reaches the
     * error log, which is the fallback the trail is meant to replace.
     *
     * user_id is NULL for an event that happens before authentication (the
     * login row passes its own id explicitly, because $_SESSION is not bound
     * yet at that moment).
     */
    private function audit(string $event, ?int $user_id = null): void
    {
        global $db;
        if (!isset($db) || !is_object($db)) {
            return;
        }
        try {
            $db->execute(
                'INSERT INTO tblsession_audits (user_id, event, ip_address, user_agent, fingerprint)
                 VALUES (:uid, :event, :ip, :ua, :fp)',
                [
                    ':uid'   => $user_id ?? (isset($_SESSION['id']) ? (int) $_SESSION['id'] : null),
                    ':event' => $event,
                    ':ip'    => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45),
                    ':ua'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
                    ':fp'    => (string) ($_SESSION['_fingerprint'] ?? $this->computeFingerprint()),
                ]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][Session] audit row not written (' . $event . '): ' . $e->getMessage());
        }
    }

    private function enforceTimeout(): void
    {
        if (isset($_SESSION['_last_activity']) && (time() - (int) $_SESSION['_last_activity'] > self::INACTIVITY_TIMEOUT)) {
            $this->audit('timeout');
            $this->forceLogout('Session expired due to inactivity. Please log in again.');
            return;
        }
        $_SESSION['_last_activity'] = time();
    }

    /**
     * Regenerate ID every 15 minutes (sliding window).
     */
    private function periodicRegeneration(): void
    {
        $last = (int) ($_SESSION['_last_regeneration'] ?? $_SESSION['_created'] ?? time());
        if (time() - $last >= self::REGENERATE_INTERVAL) {
            $this->regenerate();
            $this->audit('regenerate');
        }
    }

    /**
     * Regenerate session ID and update timers.
     */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_last_regeneration'] = time();
            $_SESSION['_created'] = time();
        }
    }

    /**
     * Force logout due to security violation or timeout.
     */
    private function forceLogout(string $msg = ''): void
    {
        $this->logout();
        if ($msg !== '') {
            $_SESSION['message'] = $msg;
        }
        // Clear fingerprint so next login gets fresh one
        unset($_SESSION['_fingerprint']);
    }

    // =========================================================================
    // Public API — kept compatible with v2.0
    // =========================================================================

    public function message(string $msg = ""): string
    {
        if (!empty($msg)) {
            $_SESSION['message'] = $msg;
            return $msg;
        }
        return $this->message;
    }

    public function check_message(): void
    {
        if (isset($_SESSION['message'])) {
            $this->message = (string) $_SESSION['message'];
            unset($_SESSION['message']);
        } else {
            $this->message = "";
        }
    }

    public function visitor_count(): int
    {
        if (isset($_SESSION['count'])) {
            $this->count = (int) $_SESSION['count']++;
            return $this->count;
        }
        $_SESSION['count'] = 1;
        $this->count = 1;
        return $this->count;
    }

    private function check_the_login(): void
    {
        if (isset($_SESSION['id'])) {
            $this->user_id   = $_SESSION['id'];
            $this->signed_in = true;
        } else {
            unset($this->user_id);
            $this->signed_in = false;
        }
    }

    public function is_signed_in(): bool
    {
        return $this->signed_in;
    }

    /**
     * Call on successful authentication — regenerates ID, binds fingerprint.
     */
    public function login(object $user): void
    {
        // Accounts (tblaccounts) key their primary key as user_id; Users (tblusers) use id.
        $userId = $user->id ?? $user->user_id ?? null;
        if (!$user || $userId === null) {
            throw new InvalidArgumentException('Session::login() requires user object with ->id or ->user_id');
        }

        // Prevent fixation: regenerate BEFORE writing auth state
        $this->regenerate();

        // Bind auth state
        $this->user_id   = $_SESSION['id'] = $userId;
        $_SESSION['user_email'] = $user->email ?? $user->user_email ?? '';
        $_SESSION['_fingerprint'] = $this->computeFingerprint();
        $_SESSION['_last_activity'] = time();
        $_SESSION['privilege_level'] = $user->access_level ?? $user->designation ?? 'user';

        $this->signed_in = true;
        $this->audit('login', (int) $userId);
    }

    /**
     * Call when privilege elevates (e.g. admin grants vendor premium, role change).
     * Forces immediate regeneration.
     */
    public function privilege_changed(): void
    {
        $this->regenerate();
        $_SESSION['_fingerprint'] = $this->computeFingerprint();
        if (isset($_SESSION['privilege_level'])) {
            // Re-read from DB if needed — caller should update session value
        }
        $this->audit('privilege_change');
    }

    public function logout(): void
    {
        // Clear auth keys but keep flash message slot if set by forceLogout
        $flash = $_SESSION['message'] ?? null;
        // Read the actor BEFORE the session is emptied — the logout row is about
        // the account that is leaving, and after the unset there is nobody left
        // to name.
        $actor = isset($_SESSION['id']) ? (int) $_SESSION['id'] : null;
        if ($actor !== null) {
            $this->audit('logout', $actor);
        }

        unset($_SESSION['id']);
        unset($_SESSION['user_email']);
        unset($_SESSION['_token']);
        unset($_SESSION['_created']);
        unset($_SESSION['_last_activity']);
        unset($_SESSION['_last_regeneration']);
        unset($_SESSION['privilege_level']);
        // Keep fingerprint until next login so hijack detection still works briefly

        if ($flash !== null) {
            $_SESSION['message'] = $flash;
        }

        unset($this->user_id);
        $this->signed_in = false;

        // Regenerate to avoid fixation on next login
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Complete session destruction (e.g. on password change).
     */
    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->signed_in = false;
    }

    // =========================================================================
    // CSRF PROTECTION
    // =========================================================================

    public function csrf_token(): string
    {
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_token'];
    }

    public function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->csrf_token(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
    }

    public function verify_csrf(?string $token = null): bool
    {
        if ($token === null && isset($_POST['_token'])) {
            $token = (string)($_POST['_token'] ?? '');
        }
        if (empty($token) || empty($_SESSION['_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_token'], $token);
    }

    // =========================================================================
    // PASSWORD HASHING (bcrypt — keep for centralized policy)
    // =========================================================================

    public static function hash_password(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify_password(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function require_post_method(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Method Not Allowed');
        }
    }

    /**
     * Helper for templates: show one-time flash then clear.
     */
    public function flash(string $key = 'message'): ?string
    {
        if (isset($_SESSION[$key])) {
            $val = (string) $_SESSION[$key];
            unset($_SESSION[$key]);
            return $val;
        }
        return null;
    }
}

/*
 * Global singleton — same as v2.0, but NOT for a headless entry point.
 *
 * `new Session()` calls session_start(), which sends a PHPSESSID cookie and
 * writes a server-side session file. That is right for a page and wrong for
 * mcp.php: the endpoint is documented as stateless (a machine has no cookies),
 * and because this file is loaded by models.php the constructor ran there too —
 * so every MCP call minted a session file it would never read again and set a
 * cookie the client ignores. A headless entry point defines WPMS_NO_SESSION
 * before requiring the model set; nothing in the MCP layer uses $session
 * (ApiToken::bind_principal() fills $_SESSION as a plain array), so leaving the
 * classes loaded and the singleton unbuilt is all that is needed.
 */
if (!defined('WPMS_NO_SESSION')) {
    $session = new Session(
        // Set false if you operate behind rotating mobile IPs (Safaricom CGNAT) and users complain
        enforceFingerprint: true
    );
    $message = $session->message();
}
