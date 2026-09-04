<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Full client login round-trip against the seeded database:
 *
 *     Accounts::login_user(email, password)  ->  Session::login($user)
 *
 * Regression: in v2.1 the flow died inside Session::login() because seeded
 * tblaccounts rows are keyed ->user_id, not ->id. These integration tests
 * exercise the exact production path end-to-end.
 *
 * Integration ('integration' group) — require a live DB with the v2.1 seed;
 * skipped cleanly when the DB or seed account is unavailable.
 */
final class LoginRoundTripTest extends TestCase
{
    private const SEED_EMAIL    = 'brian.wafula@gmail.com';
    private const SEED_PASSWORD = 'Test@1234';

    private function bootstrap(): void
    {
        if (!class_exists('DB_Object', false)) {
            require_once __DIR__ . '/../admin/include/config.php';
            require_once __DIR__ . '/../admin/include/Database.php';
            require_once __DIR__ . '/../admin/include/db_object.php';
        }
        if (!class_exists('Accounts', false)) {
            require_once __DIR__ . '/../admin/include/Accounts.php';
        }
        if (!class_exists('Session', false)) {
            require_once __DIR__ . '/../admin/include/Session.php';
        }
        // Full reset: write_close() alone resumes the same session id in CLI,
        // so previously-bound auth state would leak into the next test.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    #[Group('integration')]
    public function testSeededClientCanCompleteFullLoginRoundTrip(): void
    {
        $this->bootstrap();

        global $db;
        if (!isset($db) || !($db instanceof Database)) {
            try {
                $db = new Database();
            } catch (Throwable $e) {
                self::markTestSkipped('Live DB unavailable: ' . $e->getMessage());
            }
        }

        try {
            $seeded = Accounts::email_exists(self::SEED_EMAIL);
        } catch (Throwable $e) {
            self::markTestSkipped('DB query failed: ' . $e->getMessage());
        }
        if (!$seeded) {
            self::markTestSkipped('Seeded client ' . self::SEED_EMAIL . ' not found — import the v2.1 seed first');
        }

        // 1) Credential check (production path)
        $user = Accounts::login_user(self::SEED_EMAIL, self::SEED_PASSWORD);
        self::assertNotFalse($user, 'Seeded credentials must authenticate via Accounts::login_user()');
        self::assertSame(self::SEED_EMAIL, $user->user_email);

        // 2) Session binding (the historical failure point)
        $session = new Session();
        self::assertFalse($session->is_signed_in());

        $session->login($user);

        self::assertSame((int) $user->user_id, $session->user_id);
        self::assertSame((int) $user->user_id, $_SESSION['id'], 'Session must bind the tblaccounts user_id');
        self::assertSame(self::SEED_EMAIL, $_SESSION['user_email']);
        self::assertTrue($session->is_signed_in());
    }

    #[Group('integration')]
    public function testWrongPasswordNeverBindsASession(): void
    {
        $this->bootstrap();

        global $db;
        if (!isset($db) || !($db instanceof Database)) {
            try {
                $db = new Database();
            } catch (Throwable $e) {
                self::markTestSkipped('Live DB unavailable: ' . $e->getMessage());
            }
        }

        try {
            $seeded = Accounts::email_exists(self::SEED_EMAIL);
        } catch (Throwable $e) {
            self::markTestSkipped('DB query failed: ' . $e->getMessage());
        }
        if (!$seeded) {
            self::markTestSkipped('Seeded client ' . self::SEED_EMAIL . ' not found — import the v2.1 seed first');
        }

        $result = Accounts::login_user(self::SEED_EMAIL, 'DefinitelyWrong99!');
        self::assertFalse($result, 'Wrong password must be rejected by Accounts::login_user()');

        $session = new Session();
        self::assertFalse($session->is_signed_in(), 'No session may be bound after a failed login');
    }
}
