<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression: Session::login() must accept user_id-keyed rows (tblaccounts)
 * as well as id-keyed rows (tblusers).
 *
 * v2.1 bug: the public client login flow threw InvalidArgumentException
 * ("requires user object with ->id") because Accounts::login_user() returns
 * rows whose primary key is ->user_id, not ->id — so client logins could
 * never complete.
 */
final class SessionLoginTest extends TestCase
{
    protected function setUp(): void
    {
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

    public function testLoginAcceptsUserIdKeyedAccountObject(): void
    {
        // Shape returned by Accounts::login_user() (tblaccounts).
        $account = (object) [
            'user_id'       => 200,
            'user_email'    => 'brian.wafula@gmail.com',
            'user_password' => '$2y$10$hash', // hash is not read by login()
            'access_level'  => '0',
        ];

        $session = new Session();
        self::assertFalse($session->is_signed_in());

        $session->login($account);

        self::assertSame(200, $session->user_id);
        self::assertSame(200, $_SESSION['id']);
        self::assertSame('brian.wafula@gmail.com', $_SESSION['user_email'], 'user_email must be captured for Accounts rows');
        self::assertSame('0', $_SESSION['privilege_level']);
        self::assertTrue($session->is_signed_in());
    }

    public function testLoginStillAcceptsIdKeyedUserObject(): void
    {
        // Shape used by the admin flow (tblusers via Users).
        $user = (object) [
            'id'          => 7,
            'email'       => 'amina@wpms.co.ke',
            'designation' => 'Wedding Planner',
        ];

        $session = new Session();
        $session->login($user);

        self::assertSame(7, $session->user_id);
        self::assertSame(7, $_SESSION['id']);
        self::assertSame('amina@wpms.co.ke', $_SESSION['user_email'], 'email must be captured for Users rows');
        self::assertSame('Wedding Planner', $_SESSION['privilege_level']);
    }

    public function testLoginRejectsObjectWithoutIdOrUserId(): void
    {
        $session = new Session();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('->id or ->user_id');

        $session->login((object) ['name' => 'not-a-user']);
    }
}
