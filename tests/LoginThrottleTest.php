<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Built-in login rate limiting (tbllogin_throttle + LoginThrottle class).
 *
 * Class-level tests prove the counter mechanics directly: the 5-failure
 * lockout, the ESCALATION ladder for repeat offenders (lock_count survives
 * expired locks, so windows grow 15 min -> 1 h -> 6 h), the exactly-once
 * owner SMS on each lockout (system-billed, via SMS::send_system),
 * success-clears (which also forgives the ladder), the 20-failure per-IP cap
 * shared across accounts, counting per (email, IP) pair, and the 7-day
 * prune. The HTTP tests prove the end-to-end login.php behaviour: 4 wrong
 * passwords still let the correct one through, the 5th lock makes the
 * correct password bounce, the throttle message renders, and a different
 * IP/account is unaffected.
 *
 * Integration ('integration' group) — requires the live DB (and php -S for
 * the HTTP tests). Every touched throttle row is deleted in teardown, so the
 * suite never locks a seeded account for other tests. The suite itself runs
 * with WPMS_LOGIN_THROTTLE_OFF=1 (phpunit.xml) so other tests' intentional
 * bad-password logins can't trip this throttle — these tests unset it.
 */
final class LoginThrottleTest extends TestCase
{
    private const GUINEA_EMAIL = 'wafula@wpms.co.ke'; // seeded Super Admin — the account a brute-forcer would target
    private const GUINEA_PASS  = 'Admin@2024!';
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';

    /** Probe staff account used to prove the owner-SMS path end-to-end. */
    private const OWNER_EMAIL = 'owner.lock@throttle-test.example';
    private const OWNER_PHONE = '0711000222'; // send_system normalizes to 254711000222

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
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
            if (!class_exists('LoginThrottle', false)) {
                require_once __DIR__ . '/../admin/include/LoginThrottle.php';
            }
            if (!function_exists('redirect_to')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                self::$db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);
                self::$db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email = :e', [':e' => self::GUINEA_EMAIL]);
                self::$db->execute('DELETE FROM tblusers WHERE email = :e', [':e' => self::OWNER_EMAIL]);
                self::$db->execute("DELETE FROM tblsms_logs WHERE phone = :p AND message LIKE '%lockout%'", [':p' => '254711000222']);
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    protected function setUp(): void
    {
        if (!self::$db instanceof Database) {
            $this->markTestSkipped('live DB unavailable');
        }
        if (getenv('WPMS_LOGIN_THROTTLE_OFF') !== false) {
            putenv('WPMS_LOGIN_THROTTLE_OFF'); // these tests need the throttle live
        }
    }

    protected function tearDown(): void
    {
        // Never leave the guinea pair locked — other test classes (and their
        // own php -S servers) sign in as this seeded admin.
        self::resetPair();
    }

    private static function resetPair(): void
    {
        if (self::$db !== null) {
            self::$db->execute(
                'DELETE FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i',
                [':e' => self::GUINEA_EMAIL, ':i' => '127.0.0.1']
            );
        }
    }

    /** Insert the probe staff owner (with a phone) for the SMS tests. */
    private function createOwnerProbe(): void
    {
        global $db;
        $db->execute('DELETE FROM tblusers WHERE email = :e', [':e' => self::OWNER_EMAIL]);
        $db->execute(
            "INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, phone, access_level, profile_picture, date_created)
             VALUES ('Lock', 'Probe', 'm', 'lockprobe', 'x', :e, '1', 'Nairobi', :p, '1', '', NOW())",
            [':e' => self::OWNER_EMAIL, ':p' => self::OWNER_PHONE]
        );
    }

    /** Lockout SMS audit rows for the probe owner's phone. */
    private function ownerSmsCount(): int
    {
        global $db;
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tblsms_logs WHERE phone = :p AND message LIKE '%lockout%'",
            [':p' => '254711000222']
        );
    }

    /** Simulate every lock this pair has served having fully expired. */
    private function expireLocks(string $email, string $ip): void
    {
        global $db;
        $db->execute(
            'UPDATE tbllogin_throttle
             SET updated_at   = NOW() - INTERVAL ' . max(LoginThrottle::LOCKOUT_STEPS) . ' SECOND,
                 last_lock_at = NOW() - INTERVAL ' . max(LoginThrottle::LOCKOUT_STEPS) . ' SECOND
             WHERE throttle_email = :e AND throttle_ip = :i',
            [':e' => $email, ':i' => $ip]
        );
    }

    // ------------------------------------------------------------------
    // Class-level mechanics
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testLocksPairAfterMaxFailuresAndReportsRemainingSeconds(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        $email = 'pair@throttle-test.example';
        self::assertSame(0, LoginThrottle::secondsRemaining($email, '203.0.113.10'), 'fresh pair is not blocked');

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, '203.0.113.10');
        }
        $wait = LoginThrottle::secondsRemaining($email, '203.0.113.10');
        self::assertGreaterThan(0, $wait, 'MAX_FAILURES failures must trip the lock');
        self::assertLessThanOrEqual(LoginThrottle::LOCKOUT_SECONDS, $wait);
    }

    #[Group('integration')]
    public function testSuccessClearsThePair(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        $email = 'clear@throttle-test.example';
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, '203.0.113.11');
        }
        self::assertGreaterThan(0, LoginThrottle::secondsRemaining($email, '203.0.113.11'), 'precondition: locked');

        LoginThrottle::clear($email, '203.0.113.11');
        self::assertSame(0, LoginThrottle::secondsRemaining($email, '203.0.113.11'), 'clear() must release the pair');
    }

    #[Group('integration')]
    public function testPerIpCapBlocksUntouchedAccountsFromThatIp(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        // MAX_FAILURES_PER_IP failures spread over several accounts from one IP.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_IP; $i++) {
            LoginThrottle::recordFailure('spray' . $i . '@throttle-test.example', '203.0.113.12');
        }
        // A different IP pair on the same sprayed email is untouched...
        self::assertSame(
            0,
            LoginThrottle::secondsRemaining('spray0@throttle-test.example', '203.0.113.99'),
            'other IPs must not be affected'
        );
        // ...but ANY account from the spraying IP is blocked, even a fresh one.
        self::assertGreaterThan(
            0,
            LoginThrottle::secondsRemaining('never-seen@throttle-test.example', '203.0.113.12'),
            'the per-IP cap must block fresh accounts from the spraying IP'
        );
    }

    #[Group('integration')]
    public function testDifferentIpIsIndependentPerPair(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        $email = 'twoips@throttle-test.example';
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, '203.0.113.13');
        }
        self::assertGreaterThan(0, LoginThrottle::secondsRemaining($email, '203.0.113.13'));
        self::assertSame(0, LoginThrottle::secondsRemaining($email, '203.0.113.14'), 'same account from another IP stays open');
    }

    #[Group('integration')]
    public function testEmailsAreCaseInsensitive(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        $email = 'Case@throttle-test.example';
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, '203.0.113.15');
        }
        self::assertGreaterThan(
            0,
            LoginThrottle::secondsRemaining('cASE@THROTTLE-TEST.EXAMPLE', '203.0.113.15'),
            'an attacker must not evade the lock by changing case'
        );
    }

    public function testEscalationLadderMapping(): void
    {
        // Pure mapping — 1st lock serves steps[0], later locks saturate at the
        // longest window.
        $steps = LoginThrottle::LOCKOUT_STEPS;
        self::assertSame($steps[0], LoginThrottle::lockoutForCount(1));
        self::assertSame($steps[1], LoginThrottle::lockoutForCount(2));
        self::assertSame($steps[2], LoginThrottle::lockoutForCount(3));
        self::assertSame($steps[2], LoginThrottle::lockoutForCount(4), 'must saturate at the longest window');
        self::assertSame($steps[2], LoginThrottle::lockoutForCount(50));
    }

    #[Group('integration')]
    public function testRepeatOffensesServeLongerWindows(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        $email = 'escalate@throttle-test.example';
        $ip    = '203.0.113.21';

        // --- Lock #1: the ordinary 15-minute window.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        $wait1 = LoginThrottle::secondsRemaining($email, $ip);
        self::assertGreaterThanOrEqual(LoginThrottle::LOCKOUT_STEPS[0] - 5, $wait1);
        self::assertLessThanOrEqual(LoginThrottle::LOCKOUT_STEPS[0], $wait1);

        // --- The lock expires; a fresh burst must serve the LONGER window.
        $this->expireLocks($email, $ip);
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        $row = $db->fetchOne('SELECT fail_count, lock_count FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i', [':e' => $email, ':i' => $ip]);
        self::assertSame(2, (int) $row->lock_count, 'the expired lock must be remembered and escalated');
        $wait2 = LoginThrottle::secondsRemaining($email, $ip);
        self::assertGreaterThanOrEqual(LoginThrottle::LOCKOUT_STEPS[1] - 5, $wait2, 'second offense must serve the 1-hour window');
        self::assertLessThanOrEqual(LoginThrottle::LOCKOUT_STEPS[1], $wait2);

        // --- A burst INSIDE an active escalated lock must not reset anything.
        LoginThrottle::recordFailure($email, $ip);
        $row = $db->fetchOne('SELECT fail_count, lock_count FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i', [':e' => $email, ':i' => $ip]);
        self::assertSame(LoginThrottle::MAX_FAILURES + 1, (int) $row->fail_count, 'failures inside an active lock just accumulate');
        self::assertSame(2, (int) $row->lock_count, 'no extra lock while the current one is active');

        // --- Third offense: the longest window.
        $this->expireLocks($email, $ip);
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        $wait3 = LoginThrottle::secondsRemaining($email, $ip);
        self::assertGreaterThanOrEqual(LoginThrottle::LOCKOUT_STEPS[2] - 5, $wait3, 'third offense must serve the 6-hour window');
        self::assertLessThanOrEqual(LoginThrottle::LOCKOUT_STEPS[2], $wait3);
    }

    #[Group('integration')]
    public function testLockSendsOwnerSmsExactlyOncePerLock(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);
        $this->createOwnerProbe();

        $email = self::OWNER_EMAIL;
        $ip    = '203.0.113.22';

        // Lock #1 → exactly one SMS to the owner's phone.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        self::assertSame(1, $this->ownerSmsCount(), 'the first lockout must send exactly one SMS');
        $sms = $db->fetchOne(
            'SELECT phone, message, user_id, cost, status FROM tblsms_logs WHERE phone = :p AND message LIKE :m ORDER BY id DESC LIMIT 1',
            [':p' => '254711000222', ':m' => '%lockout%']
        );
        self::assertNotNull($sms, 'the SMS must be addressed to the normalized owner phone');
        self::assertStringContainsString('1st lockout on your account', (string) $sms->message);
        self::assertStringContainsString('15 min', (string) $sms->message);
        self::assertGreaterThan(0, (int) $sms->user_id, 'the audit row must attribute a real staff id (tblsms_logs.user_id FK)');
        self::assertSame('0.00', (string) $sms->cost, 'security SMS is system-billed — no credits deducted');
        $probeId = (int) $db->fetchValue('SELECT id FROM tblusers WHERE email = :e', [':e' => self::OWNER_EMAIL]);
        self::assertSame($probeId, (int) $sms->user_id, 'staff locks attribute to the staff member themselves');
        self::assertSame('sent', (string) $sms->status, 'dev transport records the audit row as sent');

        // More failures while locked: no additional SMS.
        LoginThrottle::recordFailure($email, $ip);
        LoginThrottle::recordFailure($email, $ip);
        self::assertSame(1, $this->ownerSmsCount(), 'failures inside an active lock must not re-notify');

        // Lock #2 (after expiry) → exactly one more SMS, naming the escalation.
        $this->expireLocks($email, $ip);
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        self::assertSame(2, $this->ownerSmsCount(), 'a second lockout sends exactly one more SMS');
        $sms2 = $db->fetchOne(
            'SELECT message FROM tblsms_logs WHERE phone = :p AND message LIKE :m ORDER BY id DESC LIMIT 1',
            [':p' => '254711000222', ':m' => '%lockout%']
        );
        self::assertStringContainsString('2nd lockout', (string) $sms2->message);
        self::assertStringContainsString('60 min', (string) $sms2->message, 'the SMS must name the escalated window');
    }

    #[Group('integration')]
    public function testUnknownEmailNeverSendsSms(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);
        $before = (int) $db->fetchValue("SELECT COUNT(*) FROM tblsms_logs WHERE message LIKE '%lockout%'");

        $email = 'nobody.home@throttle-test.example';
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, '203.0.113.23');
        }
        self::assertGreaterThan(0, LoginThrottle::secondsRemaining($email, '203.0.113.23'), 'precondition: unknown-email pair still locks');
        self::assertSame($before, (int) $db->fetchValue("SELECT COUNT(*) FROM tblsms_logs WHERE message LIKE '%lockout%'"), 'an email with no account must not text anyone');
    }

    #[Group('integration')]
    public function testClearForgivesTheEscalationLadder(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        $email = 'forgive@throttle-test.example';
        $ip    = '203.0.113.24';

        // Two full lockouts: the pair now serves the 1-hour window.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        $this->expireLocks($email, $ip);
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        self::assertGreaterThanOrEqual(LoginThrottle::LOCKOUT_STEPS[1] - 5, LoginThrottle::secondsRemaining($email, $ip), 'precondition: escalated window active');

        // The owner signs in successfully — the ladder is forgiven.
        LoginThrottle::clear($email, $ip);
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i', [':e' => $email, ':i' => $ip]));

        // A fresh attacker on the same pair starts back at the 15-minute step.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            LoginThrottle::recordFailure($email, $ip);
        }
        $wait = LoginThrottle::secondsRemaining($email, $ip);
        self::assertLessThanOrEqual(LoginThrottle::LOCKOUT_STEPS[0], $wait, 'after a successful sign-in the ladder must restart');
        self::assertGreaterThanOrEqual(LoginThrottle::LOCKOUT_STEPS[0] - 5, $wait);
    }

    #[Group('integration')]
    public function testPruneRemovesStaleRowsOnly(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        LoginThrottle::recordFailure('prune-recent@throttle-test.example', '203.0.113.16');
        LoginThrottle::recordFailure('prune-stale@throttle-test.example', '203.0.113.16');
        $db->execute(
            'UPDATE tbllogin_throttle SET updated_at = NOW() - INTERVAL :d DAY WHERE throttle_email = :e',
            [':d' => LoginThrottle::PRUNE_AFTER_DAYS + 1, ':e' => 'prune-stale@throttle-test.example']
        );

        LoginThrottle::prune();

        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM tbllogin_throttle WHERE throttle_email = :e', [':e' => 'prune-stale@throttle-test.example']),
            'rows older than the prune horizon must be deleted'
        );
        self::assertSame(
            1,
            (int) $db->fetchValue('SELECT COUNT(*) FROM tbllogin_throttle WHERE throttle_email = :e', [':e' => 'prune-recent@throttle-test.example']),
            'recent rows must survive pruning'
        );
    }

    #[Group('integration')]
    public function testKillSwitchDisablesAllOperations(): void
    {
        global $db;
        $db->execute('DELETE FROM tbllogin_throttle WHERE throttle_email LIKE :p', [':p' => '%@throttle-test.example']);

        putenv('WPMS_LOGIN_THROTTLE_OFF=1');
        try {
            LoginThrottle::recordFailure('off@throttle-test.example', '203.0.113.17');
            self::assertSame(
                0,
                (int) $db->fetchValue('SELECT COUNT(*) FROM tbllogin_throttle WHERE throttle_email = :e', [':e' => 'off@throttle-test.example']),
                'recordFailure must be a no-op with the kill switch on'
            );
            self::assertFalse(LoginThrottle::isBlocked('off@throttle-test.example', '203.0.113.17'));
        } finally {
            putenv('WPMS_LOGIN_THROTTLE_OFF'); // unset — setUp restores it per test
        }
    }

    // ------------------------------------------------------------------
    // End-to-end through login.php
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testHttpFourWrongPasswordsStillAllowTheRightOne(): void
    {
        $this->requireHttp();
        self::resetPair();

        // 4 wrong passwords — one below the lockout threshold.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES - 1; $i++) {
            $res = self::postLogin(self::GUINEA_EMAIL, 'Definitely-Wrong-' . $i);
            self::assertSame(302, $res['status'], 'wrong password still redirects to the form');
        }
        self::assertStringContainsString('Invalid email and password', self::flash());

        // The correct password still works.
        $ok = self::postLogin(self::GUINEA_EMAIL, self::GUINEA_PASS);
        self::assertSame(302, $ok['status']);
        self::assertStringStartsWith('admin/', $ok['location'], 'a correct password below the threshold must log in');
        self::assertSame(0, self::failCount(), 'success must clear the pair');
    }

    #[Group('integration')]
    public function testHttpFifthFailureBlocksEvenTheCorrectPassword(): void
    {
        $this->requireHttp();
        self::resetPair();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            self::postLogin(self::GUINEA_EMAIL, 'Definitely-Wrong-' . $i);
        }
        self::assertSame(LoginThrottle::MAX_FAILURES, self::failCount(), '5 wrong passwords must record 5 failures');

        // Correct password — still bounced, with the throttle message.
        $blocked = self::postLogin(self::GUINEA_EMAIL, self::GUINEA_PASS);
        self::assertSame(302, $blocked['status']);
        self::assertSame('login.php', $blocked['location'], 'the correct password must NOT log in while locked');
        $msg = self::flash();
        self::assertStringContainsString('Too many failed login attempts', $msg);
        self::assertMatchesRegularExpression('/\b\d+ minute/', $msg, 'the message must name a real cool-down');

        // The blocked attempt must not count as a new failure...
        self::assertSame(LoginThrottle::MAX_FAILURES, self::failCount());
        // ...and must not have left an authenticated staff session in the jar.
        $still = self::http('GET', '/admin/dashboard.php');
        self::assertSame(302, $still['status'], 'no staff session may exist while the pair is locked');
    }

    #[Group('integration')]
    public function testHttpLockDoesNotAffectOtherAccountsFromSameIp(): void
    {
        $this->requireHttp();
        self::resetPair();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            self::postLogin(self::GUINEA_EMAIL, 'Definitely-Wrong-' . $i);
        }
        self::assertSame(LoginThrottle::MAX_FAILURES, self::failCount(), 'precondition: guinea pair locked');

        // The same seeded couple account, same IP — must still log in fine.
        $ok = self::postLogin(self::COUPLE_EMAIL, self::COUPLE_PASS);
        self::assertStringStartsWith('admin/', $ok['location'], 'a different account from the same IP must be unaffected below the per-IP cap');

        // ...and the guinea pair can still recover afterwards.
        LoginThrottle::clear(self::GUINEA_EMAIL, '127.0.0.1');
        $recovered = self::postLogin(self::GUINEA_EMAIL, self::GUINEA_PASS);
        self::assertStringStartsWith('admin/', $recovered['location'], 'clearing the lock must let the correct password through');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireHttp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('live DB unavailable');
        }
        if (self::$base === null) {
            $this->markTestSkipped('php -S unavailable (proc_open missing or port boot failed)');
        }
    }

    /** Failed attempts recorded for the guinea pair (the test IP is 127.0.0.1). */
    private static function failCount(): int
    {
        global $db;
        $row = $db->fetchOne(
            'SELECT fail_count FROM tbllogin_throttle WHERE throttle_email = :e AND throttle_ip = :i',
            [':e' => self::GUINEA_EMAIL, ':i' => '127.0.0.1']
        );
        return $row === null ? 0 : (int) $row->fail_count;
    }

    /** GET the login form (fresh CSRF token + session), then POST credentials. */
    private static function postLogin(string $email, string $password): array
    {
        self::$jar = []; // every attempt is a fresh visitor — never inherit a logged-in session
        $form = self::http('GET', '/login.php');
        self::assertSame(200, $form['status'], 'login form must render');
        self::assertMatchesRegularExpression('/name="_token" value="([^"]+)"/', $form['body'], 'CSRF token must be present');
        return self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $password,
            '_token'         => html_entity_decode(self::csrfToken($form['body']), ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function csrfToken(string $body): string
    {
        if (preg_match('/name="_token" value="([^"]+)"/', $body, $m)) {
            return $m[1];
        }
        self::fail('could not extract CSRF token from the login form');
    }

    /** Follow the redirect chain to read the flash message (Session stores it server-side). */
    private static function flash(): string
    {
        // The flash lives in the redirected page render; fetch it with the same cookie jar.
        $res = self::http('GET', '/login.php');
        if (preg_match('/<div class="alert alert-danger[^"]*"[^>]*>(.*?)<\/div>/s', $res['body'], $m)) {
            return strip_tags($m[1]);
        }
        return '';
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext/curl required by the test HTTP client');
        }
        $ch = curl_init(self::$base . $path);
        $headers = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ];
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $opts[CURLOPT_HTTPHEADER] = ['Cookie: ' . implode('; ', $parts)];
        }
        if ($post !== []) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 10));
            }
        }

        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_lt_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_lt_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            // Boot the server WITHOUT the suite's throttle kill switch: these
            // HTTP tests need throttling live inside login.php, while the
            // phpunit process keeps the switch on for every other test.
            $env = [];
            foreach (getenv() as $k => $v) {
                if ($k !== 'WPMS_LOGIN_THROTTLE_OFF') {
                    $env[$k] = $v;
                }
            }
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root, $env);
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
}
