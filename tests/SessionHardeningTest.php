<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WPMS Kenya v2.1 — Session Hardening Tests (behavioral).
 *
 * Successor to the earlier source-assertion suite: these tests instantiate
 * Session and assert observable behavior (cookie params, session-id
 * rotation, fingerprint enforcement, timeout, CSRF) instead of grepping
 * the source file. Pure PHP — no DB required.
 */
final class SessionHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('Session', false)) {
            require_once __DIR__ . '/../admin/include/Session.php';
        }
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
    }

    private function resetSession(): void
    {
        // Full reset: write_close() alone resumes the same session id in CLI,
        // so previously-bound state would leak into the next test.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    public function testCookieParamsAreHardened(): void
    {
        new Session();

        $params = session_get_cookie_params();
        self::assertSame('/', $params['path'], 'Cookie path must be /');
        self::assertSame('Strict', $params['samesite'] ?? null, 'SameSite must be Strict');
        self::assertNotEmpty($params['httponly'], 'HttpOnly must be on');
        // CLI runs over plain http — secure must be off (auto-detected HTTPS only)
        self::assertFalse((bool) $params['secure'], 'secure must follow the actual protocol');

        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('0', ini_get('session.use_trans_sid'));
        self::assertSame('1', ini_get('session.cookie_httponly'));
        self::assertSame('Strict', ini_get('session.cookie_samesite'));
    }

    public function testSessionIdRotatesOnlyAfterFifteenMinutes(): void
    {
        // Fresh session — second construct within the window must NOT rotate.
        $first = new Session();
        $idFresh = session_id();
        self::assertNotSame('', $idFresh);

        new Session();
        self::assertSame($idFresh, session_id(), 'No rotation within the 15-minute window');

        // Simulate 15+ minutes elapsed, then a new request must rotate.
        $_SESSION['_last_regeneration'] = time() - 901;
        new Session();

        self::assertNotSame($idFresh, session_id(), 'Session must regenerate after 15 minutes');
        self::assertGreaterThan(time() - 5, (int) $_SESSION['_last_regeneration']);
    }

    public function testLoginRotatesSessionIdAndBindsFingerprint(): void
    {
        $session = new Session();
        $before = session_id();

        $session->login((object) [
            'user_id'    => 200,
            'user_email' => 'brian.wafula@gmail.com',
            'access_level' => '0',
        ]);

        self::assertNotSame($before, session_id(), 'login() must regenerate to prevent fixation');
        self::assertNotEmpty($_SESSION['_fingerprint'], 'login() must bind a fingerprint');
        self::assertTrue($session->is_signed_in());
    }

    public function testPrivilegeChangeRotatesSessionId(): void
    {
        $session = new Session();
        $session->login((object) ['id' => 7, 'email' => 'amina@wpms.co.ke', 'designation' => 'Planner']);
        $before = session_id();

        $session->privilege_changed();

        self::assertNotSame($before, session_id(), 'privilege_changed() must rotate the session id');
        self::assertNotEmpty($_SESSION['_fingerprint']);
    }

    public function testFingerprintMismatchForcesLogout(): void
    {
        $session = new Session();
        $session->login((object) ['user_id' => 200, 'user_email' => 'a@b.co.ke', 'access_level' => '0']);
        self::assertTrue($session->is_signed_in());

        // Next request comes from a different client (stale/forged fingerprint).
        $_SESSION['_fingerprint'] = 'forged-fingerprint-value';

        $next = new Session();

        self::assertFalse($next->is_signed_in(), 'Fingerprint mismatch must force logout');
        self::assertArrayNotHasKey('_fingerprint', $_SESSION, 'Fingerprint must be cleared for the next login');
        self::assertStringContainsString('Session invalidated', (string) ($_SESSION['message'] ?? ''));
    }

    public function testInactivityTimeoutLogsOutAfterTwoHours(): void
    {
        $session = new Session();
        $session->login((object) ['id' => 7, 'email' => 'a@b.co.ke', 'designation' => 'Planner']);
        self::assertTrue($session->is_signed_in());

        // Simulate 2h+ of inactivity, then a new request.
        $_SESSION['_last_activity'] = time() - 7201;

        $next = new Session();

        self::assertFalse($next->is_signed_in(), 'Two-hour inactivity must force logout');
        self::assertStringContainsString('expired due to inactivity', (string) ($_SESSION['message'] ?? ''));
    }

    public function testCsrfTokenIsStableVerifiableAndTimeSafe(): void
    {
        $session = new Session();

        $token = $session->csrf_token();
        self::assertSame(64, strlen($token), 'CSRF token must be 32 bytes of random as hex (64 chars)');
        self::assertSame($token, $session->csrf_token(), 'Token must be stable within a session');

        self::assertTrue($session->verify_csrf($token), 'Correct token must verify');
        self::assertFalse($session->verify_csrf(str_repeat('0', 64)), 'Wrong token must not verify');
        self::assertFalse($session->verify_csrf(''), 'Empty token must not verify');

        // verify_csrf() falls back to $_POST['_token']
        $_POST['_token'] = $token;
        try {
            self::assertTrue($session->verify_csrf(), 'POST-supplied token must verify');
        } finally {
            unset($_POST['_token']);
        }

        $field = $session->csrf_field();
        self::assertStringContainsString('name="_token"', $field);
        self::assertStringContainsString(
            htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $field,
            'csrf_field() must render the token HTML-escaped'
        );
    }
}
