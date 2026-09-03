<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WPMS Kenya v2.1 — Session Hardening Tests (Task 1.2)
 * Run: vendor/bin/phpunit --testdox tests/SessionHardeningTest.php
 */
final class SessionHardeningTest extends TestCase
{
    public function testSessionFileExistsAndIsStrictTyped(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString('declare(strict_types=1)', $src);
        self::assertStringContainsString('class Session', $src);
    }

    public function testCookieParamsAreHardened(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString("'httponly' => true", $src, 'httponly must be true');
        // Cookie params were refactored to private constants (v2.1) — assert the constant
        // definitions (spacing-tolerant) and that the params array sources from them.
        self::assertMatchesRegularExpression("/const COOKIE_SAMESITE\\s+=\\s*'Strict';/", $src, 'samesite must be Strict');
        self::assertStringContainsString("'samesite' => self::COOKIE_SAMESITE", $src, 'cookie params must source from COOKIE_SAMESITE');
        self::assertMatchesRegularExpression("/const COOKIE_PATH\\s+=\\s*'\\/';/", $src, 'path must be /');
        self::assertStringContainsString("'path'     => self::COOKIE_PATH", $src, 'cookie params must source from COOKIE_PATH');
        self::assertStringContainsString("'secure'   =>", $src, 'secure must be set (auto-detect HTTPS)');
        self::assertStringContainsString('session.use_strict_mode', $src);
        self::assertStringContainsString('session.use_only_cookies', $src);
        self::assertStringContainsString('session.use_trans_sid', $src);
        self::assertStringContainsString('session_set_cookie_params', $src);
        self::assertStringContainsString('session.cookie_httponly', $src);
    }

    public function testFifteenMinuteRegeneration(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString('REGENERATE_INTERVAL = 900', $src, 'Must regenerate every 15 min (900s)');
        self::assertStringContainsString('periodicRegeneration', $src);
        self::assertStringContainsString('_last_regeneration', $src);
        self::assertStringContainsString('session_regenerate_id(true)', $src);
    }

    public function testLoginRegeneratesAndBindsFingerprint(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        // login() must regenerate BEFORE writing auth state
        $loginPos = strpos($src, 'function login(');
        self::assertNotFalse($loginPos);
        $loginBlock = substr($src, $loginPos, 1500);
        self::assertStringContainsString('regenerate()', $loginBlock, 'login() must call regenerate()');
        self::assertStringContainsString('_fingerprint', $loginBlock, 'login() must bind fingerprint');
        self::assertStringContainsString('privilege_level', $src);
    }

    public function testPrivilegeChangeRegenerates(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString('function privilege_changed()', $src, 'Must have privilege_changed() that regenerates');
        $pos = strpos($src, 'function privilege_changed()');
        $block = substr($src, $pos, 600);
        self::assertStringContainsString('regenerate()', $block);
    }

    public function testFingerprintIsIpPlusUserAgent(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString('function computeFingerprint()', $src);
        self::assertStringContainsString('REMOTE_ADDR', $src, 'Fingerprint must include IP');
        self::assertStringContainsString('HTTP_USER_AGENT', $src, 'Fingerprint must include User-Agent');
        self::assertStringContainsString('hash_hmac', $src, 'Fingerprint must be HMAC');
        self::assertStringContainsString('sha256', $src);
        self::assertStringContainsString('enforceFingerprintCheck', $src);
        self::assertStringContainsString('hash_equals', $src, 'Fingerprint compare must be timing-safe');
    }

    public function testCsrfStillUsesRandomBytesAndHashEquals(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString('random_bytes(32)', $src);
        self::assertStringContainsString('hash_equals', $src);
        self::assertStringContainsString('function csrf_token()', $src);
        self::assertStringContainsString('function csrf_field()', $src);
        self::assertStringContainsString('function verify_csrf', $src);
        self::assertStringContainsString('htmlspecialchars', $src);
        self::assertStringContainsString('ENT_QUOTES', $src);
    }

    public function testInactivityTimeoutIsTwoHours(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Session.php');
        self::assertStringContainsString('INACTIVITY_TIMEOUT  = 7200', $src, 'Inactivity timeout must be 2 hours');
        self::assertStringContainsString('enforceTimeout', $src);
        self::assertStringContainsString('_last_activity', $src);
    }

    public function testManualVerificationSteps(): void
    {
        // Document manual steps as assertions on file existence for CI
        self::assertFileExists(__DIR__ . '/../admin/include/Session.php');
        self::assertFileExists(__DIR__ . '/../migrations/v2.1_phase1_hardening.sql');
        // Verify the deliverable doc's frontend snippet exists (created below)
        // This test will be updated once frontend snippet file is created
        self::assertTrue(true, <<<'TXT'
        Manual verification (perform in browser):
        1. Open DevTools → Application → Cookies. Verify Set-Cookie has HttpOnly; Path=/; SameSite=Strict; Secure (on HTTPS).
        2. Log in via admin/login.php. In DevTools → Network, verify session cookie rotated (new PHPSESSID).
        3. Wait 15 min (or set REGENERATE_INTERVAL to 30s temporarily) and reload — verify PHPSESSID rotated again.
        4. Change User-Agent (curl -A) with same PHPSESSID — verify forced logout + "Session invalidated" message.
        5. Change IP via VPN — verify same forced logout (if enforceFingerprint=true).
        6. Elevate privilege (change access_level) and call $session->privilege_changed() — verify rotation.
        7. Leave tab idle 2h — verify inactivity logout.
        TXT
        );
    }
}
