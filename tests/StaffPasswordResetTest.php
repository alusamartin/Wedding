<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Staff (tblusers) password reset — the half of the flow a planner needs to
 * recover their own back office.
 *
 * ExperienceP0Test already covers the COUPLE round trip, the single-use
 * replay, the unknown-address silence and the per-address rate limit. What is
 * only covered here is the staff path and the table-scoping that makes the two
 * audiences safe to share one form:
 *
 *  1. login.php signs in from tblaccounts (couples) first, then tblusers
 *     (planners/admins/vendors), so a planner who forgets their password has
 *     no other way back in — the reset must reach tblusers.password, not
 *     tblaccounts.user_password.
 *  2. The two tables have INDEPENDENT id sequences, so a token row must carry
 *     the table it belongs to (migration v2.6). Without it, a token minted for
 *     "tblusers id 200" resolves against tblaccounts.user_id 200 — i.e. a
 *     planner's reset link would change a stranger couple's password. That is
 *     the exact bug this class exists to keep shut.
 *  3. A consumed or expired link must be refused, and a POST with no token at
 *     all must change nothing.
 *
 * Fixtures: otieno@wpms.co.ke (id 101, planner, Test@1234) and couple
 * otieno.kisumu@outlook.com (user_id 202, Test@1234) — neither is used by any
 * other test class, and BOTH passwords are snapshotted and restored byte-for-
 * byte in setUp()/tearDownAfterClass() so a failure mid-test cannot leave a
 * seeded login rotated for the rest of the suite.
 *
 * Integration ('integration' group) — needs a live DB with the v2.1 seed and a
 * bootable php -S. Skipped cleanly when either is unavailable.
 */
final class StaffPasswordResetTest extends TestCase
{
    private const STAFF_EMAIL = 'otieno@wpms.co.ke';          // id 101, planner
    private const STAFF_PASS  = 'Test@1234';
    private const COUPLE_EMAIL = 'otieno.kisumu@outlook.com';  // user_id 202
    private const COUPLE_PASS  = 'Test@1234';

    private const NEW_STAFF_PASS  = 'Planner#Reset2026';
    private const TAKE2_STAFF_PASS = 'Hijack#Attempt2026';

    private static ?Database $db = null;
    private static ?string $base = null;
    private static ?int $staffId = null;
    private static ?int $coupleId = null;
    /** @var array<string, string> */
    private static array $jar = [];
    /** @var array{0:string,1:string} original password hashes to restore */
    private static array $originalHashes = ['', ''];
    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('PasswordReset', false)) {
                require_once __DIR__ . '/../admin/include/PasswordReset.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
        if (self::$db === null || self::$base === null) {
            return; // tests will skip
        }

        try {
            self::$staffId  = self::idBy('tblusers', 'id', 'email', self::STAFF_EMAIL);
            self::$coupleId = self::idBy('tblaccounts', 'user_id', 'user_email', self::COUPLE_EMAIL);

            // v2.6 adds the scoping column; without it the whole class is moot.
            $hasColumn = (int) self::$db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_password_resets' AND COLUMN_NAME = 'user_table'"
            );
        } catch (Throwable $e) {
            self::$base = null;
            return;
        }
        if ($hasColumn < 1) {
            self::$base = null; // migration v2.6 not applied — skip
            return;
        }

        // Snapshot the seeded hashes so a mid-test failure cannot leave a
        // rotated password behind for the rest of the suite.
        self::$originalHashes = [
            (string) self::$db->fetchValue('SELECT password FROM tblusers WHERE id = :i', [':i' => self::$staffId]),
            (string) self::$db->fetchValue('SELECT user_password FROM tblaccounts WHERE user_id = :i', [':i' => self::$coupleId]),
        ];

        if (self::$staffId === null
            || self::$coupleId === null
            || !password_verify(self::STAFF_PASS, self::$originalHashes[0])
            || !password_verify(self::COUPLE_PASS, self::$originalHashes[1])) {
            self::$base = null; // seeded fixtures unavailable — skip
            return;
        }

        // Prove the staff login works before asserting on a recovered one.
        $probe = self::loginFresh(self::STAFF_EMAIL, self::STAFF_PASS);
        if ($probe['status'] !== 302 || stripos($probe['location'], 'admin/') === false) {
            self::$base = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreFixtures();
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    /** Every test starts from the seeded password and a clean token table. */
    protected function setUp(): void
    {
        parent::setUp();
        self::$jar = [];
        self::restoreFixtures();
    }

    private static function restoreFixtures(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            if (self::$staffId !== null && self::$originalHashes[0] !== '') {
                self::$db->execute('UPDATE tblusers SET password = :p WHERE id = :i', [':p' => self::$originalHashes[0], ':i' => self::$staffId]);
            }
            if (self::$coupleId !== null && self::$originalHashes[1] !== '') {
                self::$db->execute('UPDATE tblaccounts SET user_password = :p WHERE user_id = :i', [':p' => self::$originalHashes[1], ':i' => self::$coupleId]);
            }
            if (self::$staffId !== null) {
                self::$db->execute("DELETE FROM tbl_password_resets WHERE user_table = 'tblusers' AND user_id = :i", [':i' => self::$staffId]);
            }
            if (self::$coupleId !== null) {
                self::$db->execute("DELETE FROM tbl_password_resets WHERE user_table = 'tblaccounts' AND user_id = :i", [':i' => self::$coupleId]);
            }
            foreach ([self::STAFF_EMAIL, self::COUPLE_EMAIL] as $email) {
                self::$db->execute('DELETE FROM tbl_mail_logs WHERE to_email = :e', [':e' => $email]);
            }
        } catch (Throwable $e) {
            // left clean enough; the assertions will speak for themselves
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve a fixture id. tblusers keys on `id`, tblaccounts on `user_id` —
     * querying the wrong one THROWS, and a swallowed throw would silently skip
     * the whole class (a green suite that tests nothing), so the caller names
     * the column.
     */
    private static function idBy(string $table, string $idColumn, string $emailColumn, string $email): ?int
    {
        $id = self::$db->fetchValue("SELECT {$idColumn} FROM {$table} WHERE {$emailColumn} = :e LIMIT 1", [':e' => $email]);
        return ($id === false || $id === null) ? null : (int) $id;
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_reset_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_reset_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 51000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root);
            if (!is_resource(self::$serverProc)) {
                continue;
            }
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
            }
            if ($ready) {
                self::$base = 'http://127.0.0.1:' . $port;
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    /**
     * Minimal curl-free HTTP client; redirects are NOT followed.
     *
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
        }
        $body = $post === [] ? '' : http_build_query($post);
        $header = 'Host: 127.0.0.1' . "\r\n"
            . $cookieHeader
            . ($post !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close";

        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => $header,
            'content'         => $body,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 10,
        ]]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);
        $headers  = $http_response_header ?? [];

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    /** A CSRF token from any page that renders a form in the current session. */
    private static function csrfFrom(string $path): string
    {
        $page = self::http('GET', $path);
        if (!preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::fail('no CSRF token found on ' . $path);
        }
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /**
     * Request a reset link through the real public form (CSRF + session).
     *
     * @return array{status:int, location:string, body:string}
     */
    private static function requestReset(string $email): array
    {
        $token = self::csrfFrom('/forgot_password.php');
        return self::http('POST', '/forgot_password.php', [
            'input_email' => $email,
            '_token'      => $token,
            'forgot'      => '1',
        ]);
    }

    /**
     * Exchange a token for a new password through the real form. $csrfFrom is
     * where to steal a CSRF token when the reset page itself has no form (a
     * dead token renders the "link expired" state with no fields).
     *
     * @return array{status:int, location:string, body:string}
     */
    private static function submitReset(string $plain, string $newPassword, string $csrfFrom = ''): array
    {
        $path  = '/reset_password.php?token=' . urlencode($plain);
        $token = $csrfFrom !== '' ? self::csrfFrom($csrfFrom) : self::csrfFrom($path);
        return self::http('POST', '/reset_password.php', [
            'token'           => $plain,
            'input_password'  => $newPassword,
            'input_password2' => $newPassword,
            '_token'          => $token,
            'reset'           => '1',
        ]);
    }

    /** The most recent plain token mailed to an address, or null. */
    private static function latestToken(string $email): ?string
    {
        $body = (string) self::$db->fetchValue(
            'SELECT body FROM tbl_mail_logs WHERE to_email = :e ORDER BY id DESC LIMIT 1',
            [':e' => $email]
        );
        if (!preg_match('/token=([a-f0-9]{64})/', $body, $m)) {
            return null;
        }
        return $m[1];
    }

    private static function latestRow(string $table, int $id): ?object
    {
        $row = self::$db->fetchOne(
            'SELECT * FROM tbl_password_resets WHERE user_table = :t AND user_id = :i ORDER BY id DESC LIMIT 1',
            [':t' => $table, ':i' => $id]
        );
        return $row === false ? null : $row;
    }

    private static function staffHash(): string
    {
        return (string) self::$db->fetchValue('SELECT password FROM tblusers WHERE id = :i', [':i' => self::$staffId]);
    }

    private static function coupleHash(): string
    {
        return (string) self::$db->fetchValue('SELECT user_password FROM tblaccounts WHERE user_id = :i', [':i' => self::$coupleId]);
    }

    /** Sign in on a FRESH session (login.php bounces anyone already signed in). */
    private static function loginFresh(string $email, string $pass): array
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::fail('could not load /login.php for a fresh sign-in');
        }
        return self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / seeded reset fixtures unavailable — staff reset tests skipped');
        }
    }

    // -------------------------------------------------------------- the tests

    /**
     * The link a planner gets must be scoped to tblusers. Storing only a
     * user_id is what let a staff token resolve against the couple table.
     */
    #[Group('integration')]
    public function testStaffResetIssuesATokenScopedToTblUsers(): void
    {
        $this->requireHttp();

        $res = self::requestReset(self::STAFF_EMAIL);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('forgot_password.php?sent=1', $res['location'], 'the caller always gets the neutral "check your inbox" answer');

        $row = self::latestRow('tblusers', self::$staffId);
        self::assertNotNull($row, 'a planner must get a reset row keyed to tblusers');
        self::assertSame('tblusers', $row->user_table, 'a planner token must be stamped tblusers');
        self::assertSame(self::$staffId, (int) $row->user_id);

        $plain = self::latestToken(self::STAFF_EMAIL);
        self::assertNotNull($plain, 'the reset email must contain a 64-hex token');
        self::assertSame(hash('sha256', $plain), $row->token_hash, 'the DB must store the token hashed, never in the clear');
        self::assertSame(64, strlen($plain));

        self::assertSame(1, (int) self::$db->fetchValue(
            'SELECT expires_at > NOW() FROM tbl_password_resets WHERE id = :i',
            [':i' => (int) $row->id]
        ), 'a fresh token must still be valid');
        self::assertNull($row->used_at, 'a fresh token must be unused');
    }

    /**
     * The whole point: a planner who forgot their password can sign back in.
     */
    #[Group('integration')]
    public function testStaffCanSetANewPasswordAndTheOldOneStopsWorking(): void
    {
        $this->requireHttp();

        self::requestReset(self::STAFF_EMAIL);
        $plain = self::latestToken(self::STAFF_EMAIL);
        self::assertNotNull($plain);

        $ok = self::submitReset($plain, self::NEW_STAFF_PASS);
        self::assertSame(302, $ok['status']);
        self::assertStringContainsString('login.php', $ok['location'], 'a successful reset sends the planner to the sign-in page');

        $hash = self::staffHash();
        self::assertTrue(password_verify(self::NEW_STAFF_PASS, $hash), 'tblusers.password must accept the new password');
        self::assertFalse(password_verify(self::STAFF_PASS, $hash), 'the old password must stop working');
        self::assertStringNotContainsString(self::NEW_STAFF_PASS, $hash, 'the password must be stored hashed, never in the clear');

        $row = self::latestRow('tblusers', self::$staffId);
        self::assertNotNull($row->used_at, 'exchanging the token must burn it');

        // And it really works through the real sign-in door.
        $login = self::loginFresh(self::STAFF_EMAIL, self::NEW_STAFF_PASS);
        self::assertSame(302, $login['status']);
        self::assertStringContainsString('admin/', $login['location'], 'the planner must be signed into the back office');
        self::assertStringNotContainsString('login.php', $login['location'], 'a recovered planner is not bounced back to the form');

        $old = self::loginFresh(self::STAFF_EMAIL, self::STAFF_PASS);
        self::assertStringNotContainsString('admin/', $old['location'], 'the retired password must not sign anyone in');
    }

    /** A used link is dead — it must not be a second bite at the account. */
    #[Group('integration')]
    public function testAConsumedTokenCannotBeReplayed(): void
    {
        $this->requireHttp();

        self::requestReset(self::STAFF_EMAIL);
        $plain = self::latestToken(self::STAFF_EMAIL);
        self::assertNotNull($plain);
        self::submitReset($plain, self::NEW_STAFF_PASS);
        self::assertTrue(password_verify(self::NEW_STAFF_PASS, self::staffHash()));

        // The dead link renders no form, so any CSRF token will do here.
        $replay = self::submitReset($plain, self::TAKE2_STAFF_PASS, '/login.php');
        self::assertStringNotContainsString('login.php', $replay['location'], 'a replayed token must not be accepted');

        $hash = self::staffHash();
        self::assertTrue(password_verify(self::NEW_STAFF_PASS, $hash), 'the password set by the first use must survive');
        self::assertFalse(password_verify(self::TAKE2_STAFF_PASS, $hash), 'a replayed token must not change the password');
    }

    /** Expired links are refused — and the page says so with no form. */
    #[Group('integration')]
    public function testExpiredTokenIsRefusedAndThePageSaysSo(): void
    {
        $this->requireHttp();

        self::requestReset(self::STAFF_EMAIL);
        $plain = self::latestToken(self::STAFF_EMAIL);
        self::assertNotNull($plain);

        self::$db->execute(
            'UPDATE tbl_password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = :t',
            [':t' => hash('sha256', $plain)]
        );

        $page = self::http('GET', '/reset_password.php?token=' . urlencode($plain));
        self::assertSame(200, $page['status'], 'an expired link is a page, not an error');
        self::assertStringContainsString('expired', strtolower($page['body']), 'the page must tell the planner the link expired');
        self::assertStringNotContainsString('name="reset"', $page['body'], 'an expired link must not render the password form');

        self::submitReset($plain, self::NEW_STAFF_PASS, '/login.php');
        self::assertFalse(password_verify(self::NEW_STAFF_PASS, self::staffHash()), 'an expired token must change nothing');
        self::assertTrue(password_verify(self::STAFF_PASS, self::staffHash()), 'the seeded password must still stand');
    }

    /** No token, no change — the endpoint is not a password field for anyone. */
    #[Group('integration')]
    public function testResetWithoutATokenChangesNothing(): void
    {
        $this->requireHttp();

        $res = self::submitReset('', self::NEW_STAFF_PASS, '/login.php');
        self::assertStringNotContainsString('login.php', $res['location'], 'there is no success path without a token');
        self::assertTrue(password_verify(self::STAFF_PASS, self::staffHash()), 'the seeded password must be untouched');
    }

    /**
     * The id-collision guard. A token row is only meaningful together with the
     * table it belongs to: tblusers id 200 does not exist, while a couple with
     * user_id 200 does. Looking a token up by user_id alone (the original bug)
     * resolved that staff row to the couple — so this asserts the token is
     * dead, and that the couple's own token still resolves to the couple.
     */
    #[Group('integration')]
    public function testTokenSelectionIsScopedToItsOwnTable(): void
    {
        $this->requireHttp();

        // Precondition: the numeric id must exist in tblaccounts but NOT in
        // tblusers, which is what makes it a collision. (fetchValue returns
        // false — not null — when no row matches.)
        self::assertFalse(
            self::$db->fetchValue('SELECT id FROM tblusers WHERE id = :i', [':i' => self::$coupleId]),
            'fixture: no staff row may share the couple\'s id, or this test proves nothing'
        );
        $coupleEmail = (string) self::$db->fetchValue('SELECT user_email FROM tblaccounts WHERE user_id = :i', [':i' => self::$coupleId]);
        self::assertNotSame('', $coupleEmail);

        $staffPlain  = bin2hex(random_bytes(32));  // a token claiming to be staff
        $couplePlain = bin2hex(random_bytes(32));  // a token claiming to be this couple
        $hashes = [hash('sha256', $staffPlain), hash('sha256', $couplePlain)];

        try {
            foreach ([[
                'tblusers', self::$coupleId, $hashes[0],
            ], [
                'tblaccounts', self::$coupleId, $hashes[1],
            ]] as [$table, $id, $hash]) {
                self::$db->execute(
                    'INSERT INTO tbl_password_resets (user_table, user_id, token_hash, expires_at)'
                    . ' VALUES (:t, :i, :h, NOW() + INTERVAL 30 MINUTE)',
                    [':t' => $table, ':i' => $id, ':h' => $hash]
                );
            }

            self::assertFalse(
                PasswordReset::select_valid($staffPlain),
                'a tblusers token whose user_id happens to match a couple must never resolve to that couple'
            );

            $resolved = PasswordReset::select_valid($couplePlain);
            self::assertNotFalse($resolved, 'the couple\'s own token must still resolve');
            self::assertSame('tblaccounts', $resolved->user_table);
            self::assertSame($coupleEmail, $resolved->user_email);
        } finally {
            foreach ($hashes as $hash) {
                self::$db->execute('DELETE FROM tbl_password_resets WHERE token_hash = :h', [':h' => $hash]);
            }
        }
    }

    /** The couple half must keep working (same form, different table). */
    #[Group('integration')]
    public function testCoupleResetIsScopedToTblAccountsAndStillWorks(): void
    {
        $this->requireHttp();

        self::requestReset(self::COUPLE_EMAIL);
        $row = self::latestRow('tblaccounts', self::$coupleId);
        self::assertNotNull($row, 'a couple must get a reset row keyed to tblaccounts');
        self::assertSame('tblaccounts', $row->user_table);

        $plain = self::latestToken(self::COUPLE_EMAIL);
        self::assertNotNull($plain);
        self::submitReset($plain, self::NEW_STAFF_PASS);

        self::assertTrue(password_verify(self::NEW_STAFF_PASS, self::coupleHash()), 'tblaccounts.user_password must accept the new password');
        self::assertFalse(password_verify(self::COUPLE_PASS, self::coupleHash()), 'the couple\'s old password must stop working');

        $login = self::loginFresh(self::COUPLE_EMAIL, self::NEW_STAFF_PASS);
        self::assertStringContainsString('admin/client.php', $login['location'], 'the couple must be signed into their own portal');
    }

    /**
     * An address nobody owns must be indistinguishable from a real one and
     * leave no token behind — staff addresses included, since a planner's
     * email is the more tempting thing to enumerate.
     */
    #[Group('integration')]
    public function testStaffShapedUnknownAddressLeavesNoTrace(): void
    {
        $this->requireHttp();

        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbl_password_resets');

        $ghost = 'nobody-' . bin2hex(random_bytes(4)) . '@wpms.co.ke';
        $unknown = self::requestReset($ghost);
        $known   = self::requestReset(self::STAFF_EMAIL);

        self::assertSame(302, $unknown['status']);
        self::assertSame($unknown['status'], $known['status'], 'the answer must not depend on whether the address exists');
        self::assertSame($unknown['location'], $known['location'], 'a real and a bogus address must be answered identically');

        $ghostRows = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tbl_mail_logs WHERE to_email = :e',
            [':e' => $ghost]
        );
        self::assertSame(0, $ghostRows, 'no mail may be queued for an address nobody owns');

        // Only the known address added a row.
        self::assertSame($before + 1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbl_password_resets'));
    }
}
