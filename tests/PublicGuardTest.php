<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The shared abuse guard for the anonymous doors — tblpublic_throttle and
 * PublicGuard (v2.14), the counter behind rsvp.php, site.php and the two M-Pesa
 * callbacks.
 *
 * The class-level half proves the mechanics directly, because they are what the
 * three call sites depend on: the ceiling is a ceiling (allowed up to it, refused
 * past it, and the refusal reports the seconds to wait without moving the
 * counter), the CALLER row counts every target at that door and has its own
 * ceiling, one door filling up does not silence another, an RSVP token is counted
 * as its hash and never in the clear, the window rolls over into a fresh counter,
 * prune() only removes rows past the window, the kill switch stops every write,
 * an unknown door throws instead of guessing a ceiling, and a missing counter
 * table lets the request through rather than taking a public page down.
 *
 * The HTTP half is the requirement itself: a flood that a real attacker could
 * run must not become rows for a person. Six RSVP answers from one connection to
 * one invitation produce FIVE bells and FIVE emails and then stop — the sixth is
 * refused with the page still rendering rather than a 500 or a silent success.
 * Eleven views of one published slug raise its counter by TEN and no more, with
 * the page still served to every one of them. Thirty-three Daraja callbacks stop
 * writing the forensic log at THIRTY, answered 200 throughout — and a C2B
 * notification still lands afterwards, because one door filling up must not take
 * the payment path with it.
 *
 * Integration ('integration' group) — needs the live DB and php -S for the HTTP
 * half. The suite runs with WPMS_LOGIN_THROTTLE_OFF=1 (phpunit.xml) so no other
 * test's deliberate flooding is counted; this class clears that switch for its
 * own process (restoring whatever it found) and boots its server WITHOUT it, so
 * the guard is live where it is being measured. Every row and every field this
 * class touches is restored in teardown — throttle rows by id, bells and outbox
 * rows by id, the probe guest's answer to its pre-test value, the site's view
 * count to its exact value, ingest rows by transaction code — so the suite never
 * inherits a full counter or a half-answered guest.
 */
final class PublicGuardTest extends TestCase
{
    /** Guest 202 of seeded booking 200 — owns a live RSVP token. */
    private const FIX_GUEST_ID = 202;
    private const FIX_BOOKING  = 200;

    /** The one PUBLISHED seeded wedding site (faith-james is unpublished). */
    private const FIX_SLUG = 'brian-linda';

    /** The caller the test server presents to the guard. */
    private const SERVER_IP = '127.0.0.1';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

    /** The kill switch's value before this class touched it ('' = was unset). */
    private static string|false $envBefore = false;

    private static int $throttleMark = 0;
    private static int $notifMark = 0;
    private static int $mailMark = 0;
    private static int $logMark = 0;
    private static string $guestToken = '';
    private static ?object $guestBefore = null;
    private static int $viewsBefore = 0;
    /** @var string[] transaction codes this class ingested */
    private static array $probeCodes = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('PublicGuard', false)) {
                require_once __DIR__ . '/../admin/include/PublicGuard.php';
            }
            if (!class_exists('Guest', false)) {
                require_once __DIR__ . '/../admin/include/Guest.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        // The kill switch is cleared for the class-level half...
        self::$envBefore = getenv('WPMS_LOGIN_THROTTLE_OFF');
        putenv('WPMS_LOGIN_THROTTLE_OFF');

        // ...and left out of the server's environment, so the guard is live in
        // the requests the HTTP half measures (the suite sets it globally for
        // every other class — see phpunit.xml).
        self::bootServer();

        if (self::$db === null) {
            return;
        }
        self::$throttleMark = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblpublic_throttle');
        self::$notifMark    = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblnotifications');
        self::$mailMark     = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tbl_mail_logs');
        self::$logMark      = (int) self::$db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblmpesa_callback_log');

        $guest = self::$db->fetchOne(
            'SELECT id, rsvp_token, rsvp_status, rsvp_note, rsvp_at FROM tblguest WHERE id = :id',
            [':id' => self::FIX_GUEST_ID]
        );
        if ($guest !== null && trim((string) $guest->rsvp_token) !== '') {
            self::$guestBefore = $guest;
            self::$guestToken  = (string) $guest->rsvp_token;
        }
        self::$viewsBefore = (int) self::$db->fetchValue(
            'SELECT views FROM tblwedding_websites WHERE slug = :s',
            [':s' => self::FIX_SLUG]
        );
    }

    /**
     * Undo everything this class has done since setUpBeforeClass, so the counter
     * table — and every table a flood touches — is exactly as it was found.
     * Runs after EVERY test, not only at the end: a class that measures floods
     * must leave no flood behind for the next one, whatever order the suite runs
     * in.
     */
    private static function clearProbeState(): void
    {
        if (self::$db === null) {
            return;
        }
        try {
            // The guard's own rows: anything above the mark is this class's.
            self::$db->execute('DELETE FROM tblpublic_throttle WHERE id > :m', [':m' => self::$throttleMark]);
            // Bells and outbox rows the RSVP flood produced.
            self::$db->execute('DELETE FROM tbl_mail_logs WHERE id > :m', [':m' => self::$mailMark]);
            self::$db->execute('DELETE FROM tblnotifications WHERE id > :m', [':m' => self::$notifMark]);
            // Forensic rows the callback probe produced, and any ingested code.
            self::$db->execute('DELETE FROM tblmpesa_callback_log WHERE id > :m', [':m' => self::$logMark]);
            foreach (self::$probeCodes as $code) {
                self::$db->execute('DELETE FROM tblmpesa_callbacks WHERE trans_id = :c', [':c' => $code]);
            }
            // The probe guest's answer, exactly as it was.
            if (self::$guestBefore !== null) {
                self::$db->execute(
                    'UPDATE tblguest SET rsvp_status = :s, rsvp_note = :n, rsvp_at = :a WHERE id = :id',
                    [
                        ':s'  => (string) self::$guestBefore->rsvp_status,
                        ':n'  => self::$guestBefore->rsvp_note,
                        ':a'  => self::$guestBefore->rsvp_at,
                        ':id' => self::FIX_GUEST_ID,
                    ]
                );
            }
            // The seeded site's view count, exactly as it was.
            self::$db->execute(
                'UPDATE tblwedding_websites SET views = :v WHERE slug = :s',
                [':v' => self::$viewsBefore, ':s' => self::FIX_SLUG]
            );
        } catch (Throwable $e) {
            // leave state untouched on failure
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::clearProbeState();
        self::$probeCodes = [];

        // Restore the switch exactly as this class found it.
        if (self::$envBefore === false) {
            putenv('WPMS_LOGIN_THROTTLE_OFF');
        } else {
            putenv('WPMS_LOGIN_THROTTLE_OFF=' . self::$envBefore);
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    protected function setUp(): void
    {
        // Class-level tests need the counter live whatever order they run in.
        putenv('WPMS_LOGIN_THROTTLE_OFF');
    }

    protected function tearDown(): void
    {
        // No test may inherit another's flood, and no other class may inherit
        // this one's — see clearProbeState().
        self::clearProbeState();
    }

    private function requireDb(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB unavailable — PublicGuard tests skipped');
        }
    }

    private function requireHttp(): void
    {
        $this->requireDb();
        if (self::$base === null) {
            self::markTestSkipped('test HTTP server unavailable');
        }
    }

    /** A subject unique to one test, so counters never collide across tests. */
    private static function subject(string $tag): string
    {
        return $tag . '-' . bin2hex(random_bytes(6));
    }

    // ==================================================================
    // The counter
    // ==================================================================

    public function testItAllowsUpToTheCeilingAndRefusesBeyondIt(): void
    {
        $this->requireDb();
        $subject = self::subject('ceiling');
        $cap     = PublicGuard::LIMITS[PublicGuard::RSVP]['subject'];
        $window  = PublicGuard::LIMITS[PublicGuard::RSVP]['window'];

        for ($i = 1; $i <= $cap; $i++) {
            self::assertSame(0, PublicGuard::attempt(PublicGuard::RSVP, $subject), "attempt {$i} is under the ceiling");
        }

        $wait = PublicGuard::attempt(PublicGuard::RSVP, $subject);
        self::assertGreaterThan(0, $wait, 'the attempt past the ceiling must be refused');
        self::assertLessThanOrEqual($window, $wait, 'the wait is a remainder of the window, not a fresh one');

        // The refusal is counted as BLOCKED, never as an attempt: a flood must
        // not be able to push its own window further out by flooding harder.
        $row = $this->row(PublicGuard::RSVP, $subject);
        self::assertNotNull($row, 'the counter row exists');
        self::assertSame($cap, (int) $row->attempts, 'the counter stops at the ceiling');
        self::assertSame(1, (int) $row->blocked_count, 'the refusal is metered');

        // secondsRemaining() answers the same question without counting.
        self::assertGreaterThan(0, PublicGuard::secondsRemaining(PublicGuard::RSVP, $subject));
        self::assertSame(1, (int) $this->row(PublicGuard::RSVP, $subject)->blocked_count, 'a peek is not an attempt');
    }

    public function testTheCallerRowCountsEveryTargetAndHasItsOwnCeiling(): void
    {
        $this->requireDb();
        $ipCap  = PublicGuard::LIMITS[PublicGuard::RSVP]['ip'];
        $before = $this->row(PublicGuard::RSVP, '');

        // A flood over many subjects: each subject row is fresh (its own ceiling
        // untouched), and the caller row is what fills.
        for ($i = 1; $i <= $ipCap; $i++) {
            self::assertSame(0, PublicGuard::attempt(PublicGuard::RSVP, self::subject('spray-' . $i)), 'attempt ' . $i);
        }

        $caller = $this->row(PublicGuard::RSVP, '');
        self::assertNotNull($caller, 'the caller row exists');
        self::assertSame(
            ($before !== null ? (int) $before->attempts : 0) + $ipCap,
            (int) $caller->attempts,
            'every target at this door is counted against the caller too'
        );

        $fresh = self::subject('spray-after');
        self::assertGreaterThan(0, PublicGuard::attempt(PublicGuard::RSVP, $fresh), 'a brand-new target is refused: the caller is out');
        self::assertNull($this->row(PublicGuard::RSVP, $fresh), 'and the refused attempt left the new target no row at all');

        // Leave the caller row as it was, so no other test inherits a full one.
        self::$db->execute(
            'DELETE FROM tblpublic_throttle WHERE kind = :k AND subject = :s AND ip = :i',
            [':k' => PublicGuard::RSVP, ':s' => '', ':i' => PublicGuard::clientIp()]
        );
    }

    public function testOneDoorDoesNotSilenceAnother(): void
    {
        $this->requireDb();
        $subject = self::subject('doors');
        for ($i = 0; $i <= PublicGuard::LIMITS[PublicGuard::RSVP]['subject']; $i++) {
            PublicGuard::attempt(PublicGuard::RSVP, $subject);
        }
        self::assertGreaterThan(0, PublicGuard::secondsRemaining(PublicGuard::RSVP, $subject), 'the RSVP door is full');

        self::assertSame(0, PublicGuard::attempt(PublicGuard::SITE, $subject), 'the site door is not: one door never silences another');
        self::assertSame(0, PublicGuard::attempt(PublicGuard::CALLBACK, $subject));
    }

    /**
     * The public contact form is a door too.
     *
     * It was the one anonymous POST the guard missed, because it writes no
     * database row and the original survey of "places a visitor can make the
     * database write" did not list it. It is still the loudest amplification in
     * the product: every submission runs mail() against the operator's inbox
     * and SMTP quota. Its subject is deliberately empty — every honest visitor
     * is a different person, so the thing worth bounding is one connection.
     */
    public function testTheContactFormIsCountedPerCallerAndRefusedPastItsCeiling(): void
    {
        $this->requireDb();
        $ip = PublicGuard::clientIp();
        self::$db->execute(
            'DELETE FROM tblpublic_throttle WHERE kind = :k AND ip = :i',
            [':k' => PublicGuard::CONTACT, ':i' => $ip]
        );

        $cap = PublicGuard::LIMITS[PublicGuard::CONTACT]['ip'];
        self::assertGreaterThan(0, $cap, 'the door has a ceiling at all');

        for ($i = 1; $i <= $cap; $i++) {
            self::assertSame(0, PublicGuard::attempt(PublicGuard::CONTACT), "message {$i} is inside the ceiling");
        }
        self::assertGreaterThan(0, PublicGuard::attempt(PublicGuard::CONTACT), 'the message past the ceiling is refused');
        self::assertGreaterThan(0, PublicGuard::secondsRemaining(PublicGuard::CONTACT), 'and the refusal reports a wait');

        // A full contact door must not take the RSVP or payment paths with it.
        self::assertSame(0, PublicGuard::attempt(PublicGuard::RSVP, self::subject('contact-door')), 'one door never silences another');

        self::$db->execute(
            'DELETE FROM tblpublic_throttle WHERE kind = :k AND ip = :i',
            [':k' => PublicGuard::CONTACT, ':i' => $ip]
        );
    }

    public function testAnRsvpTokenIsCountedAsItsHashAndNeverInTheClear(): void
    {
        $this->requireDb();
        $token = self::$guestToken !== '' ? self::$guestToken : bin2hex(random_bytes(16));
        $hash  = PublicGuard::subjectHash($token);

        self::assertSame($hash, PublicGuard::subjectHash($token), 'the hash is deterministic for a call site');
        self::assertSame(64, strlen($hash), 'sha256 hex');
        self::assertStringNotContainsString($token, $hash, 'the capability never appears in the counter');

        PublicGuard::attempt(PublicGuard::RSVP, $hash);
        $row = self::$db->fetchOne(
            'SELECT subject FROM tblpublic_throttle WHERE kind = :k AND subject = :s AND ip = :i',
            [':k' => PublicGuard::RSVP, ':s' => $hash, ':i' => PublicGuard::clientIp()]
        );
        self::assertNotNull($row, 'the counter row is keyed by the hash');
        self::assertStringNotContainsString($token, (string) $row->subject);
    }

    public function testTheWindowRollsOverIntoAFreshCounter(): void
    {
        $this->requireDb();
        $subject = self::subject('window');
        $cap     = PublicGuard::LIMITS[PublicGuard::SITE]['subject'];
        $window  = PublicGuard::LIMITS[PublicGuard::SITE]['window'];

        for ($i = 1; $i <= $cap + 1; $i++) {
            PublicGuard::attempt(PublicGuard::SITE, $subject);
        }
        self::assertGreaterThan(0, PublicGuard::secondsRemaining(PublicGuard::SITE, $subject), 'full now');

        // Age the window past its length — the state time produces, without the wait.
        self::$db->execute(
            'UPDATE tblpublic_throttle SET window_started_at = NOW() - INTERVAL :s SECOND
              WHERE kind = :k AND subject = :sub AND ip = :i',
            [':s' => $window + 5, ':k' => PublicGuard::SITE, ':sub' => $subject, ':i' => PublicGuard::clientIp()]
        );

        self::assertSame(0, PublicGuard::attempt(PublicGuard::SITE, $subject), 'the next window opens for the caller');
        $row = $this->row(PublicGuard::SITE, $subject);
        self::assertSame(1, (int) $row->attempts, 'the counter restarts at one');
        self::assertSame(0, (int) $row->blocked_count, 'and the flood meter belongs to the window it metered');
    }

    public function testPruneRemovesOnlyRowsPastTheirWindow(): void
    {
        $this->requireDb();
        $stale = self::subject('stale');
        $fresh = self::subject('fresh');
        PublicGuard::attempt(PublicGuard::SITE, $stale);
        PublicGuard::attempt(PublicGuard::SITE, $fresh);

        self::$db->execute(
            'UPDATE tblpublic_throttle SET updated_at = NOW() - INTERVAL :d DAY WHERE subject = :s',
            [':d' => PublicGuard::PRUNE_AFTER_DAYS + 1, ':s' => $stale]
        );
        PublicGuard::prune();

        self::assertNull($this->row(PublicGuard::SITE, $stale), 'a row untouched past the window is deleted');
        self::assertNotNull($this->row(PublicGuard::SITE, $fresh), 'and a live one is not');
    }

    public function testTheKillSwitchStopsCountingAndWritesNothing(): void
    {
        $this->requireDb();
        $subject = self::subject('killswitch');
        $cap     = PublicGuard::LIMITS[PublicGuard::CALLBACK]['subject'];

        putenv('WPMS_LOGIN_THROTTLE_OFF=1');
        try {
            self::assertFalse(PublicGuard::enabled(), 'the shared switch turns the guard off');
            for ($i = 0; $i <= $cap + 5; $i++) {
                self::assertSame(0, PublicGuard::attempt(PublicGuard::CALLBACK, $subject), 'nothing is counted and nothing is refused');
            }
            self::assertSame(0, PublicGuard::secondsRemaining(PublicGuard::CALLBACK, $subject));
            self::assertNull($this->row(PublicGuard::CALLBACK, $subject), 'a disabled guard writes no counter row at all');
        } finally {
            putenv('WPMS_LOGIN_THROTTLE_OFF');
        }
        self::assertTrue(PublicGuard::enabled(), 'restoring the switch re-arms it');
    }

    public function testAnUnknownDoorIsRefusedRatherThanGuessed(): void
    {
        $this->requireDb();
        $this->expectException(InvalidArgumentException::class);
        PublicGuard::attempt('not-a-door', 'x');
    }

    /**
     * The fail-open half: a counter table that is missing (its migration not run)
     * must not 500 a public page or drop a payment callback — the request is
     * allowed, and the miss is logged. Proven by moving the table aside, the way
     * the optional-migration path is proven elsewhere in this suite.
     */
    #[Group('integration')]
    public function testAMissingCounterTableLetsTheRequestThrough(): void
    {
        $this->requireDb();
        $probe = self::subject('failopen');
        self::$db->execute('RENAME TABLE tblpublic_throttle TO tblpublic_throttle_aside');
        try {
            self::assertSame(0, PublicGuard::attempt(PublicGuard::RSVP, $probe), 'allowed, not refused');
            self::assertSame(0, PublicGuard::secondsRemaining(PublicGuard::RSVP, $probe));
            // Read the moved-aside table directly: the question is whether the
            // failed-open attempt wrote a counter row anywhere.
            self::assertNull(
                self::$db->fetchOne(
                    'SELECT attempts FROM tblpublic_throttle_aside WHERE kind = :k AND subject = :s',
                    [':k' => PublicGuard::RSVP, ':s' => $probe]
                ),
                'and nothing was counted, because nothing could be'
            );
        } finally {
            self::$db->execute('RENAME TABLE tblpublic_throttle_aside TO tblpublic_throttle');
        }

        // The counter is live again: a fresh attempt writes its row.
        self::assertSame(0, PublicGuard::attempt(PublicGuard::RSVP, $probe));
        self::assertNotNull($this->row(PublicGuard::RSVP, $probe), 'the counter table is back in service');
    }

    // ==================================================================
    // The doors (HTTP) — the requirement, end to end
    // ==================================================================

    #[Group('integration')]
    public function testAnRsvpFloodStopsAtTheCeilingAndFillsNoInbox(): void
    {
        $this->requireHttp();
        if (self::$guestToken === '') {
            self::markTestSkipped('no seeded guest with an RSVP token');
        }
        $cap  = PublicGuard::LIMITS[PublicGuard::RSVP]['subject'];
        $hash = PublicGuard::subjectHash(self::$guestToken);
        $this->resetCounter(PublicGuard::RSVP, self::SERVER_IP, $hash);

        $bells = 0;
        $mails = 0;
        $statuses = ['yes', 'no', 'maybe'];

        // Every allowed answer CHANGES the guest's answer (alternating status),
        // which is what makes each one a bell and an email — the amplification
        // this guard exists to bound.
        for ($i = 1; $i <= $cap; $i++) {
            $res = $this->rsvp(self::$guestToken, $statuses[($i - 1) % 3]);
            self::assertSame(200, $res['status'], "answer {$i} renders");
            $bells = $this->bellCount();
            $mails = $this->mailCount();
            self::assertSame($i, $bells, "answer {$i} alerted the planner exactly once");
            self::assertSame($i, $mails, "answer {$i} queued exactly one email");
        }

        // The flood attempt: over the ceiling, nothing at all is written.
        $flood = $this->rsvp(self::$guestToken, 'yes');
        self::assertSame(200, $flood['status'], 'a refused flood still renders the page, not an error');
        self::assertStringContainsString(
            'a lot of answers from this connection',
            $flood['body'],
            'and the guest is told why, rather than being silently ignored'
        );
        self::assertStringNotContainsString('Asante!', $flood['body'], 'a refused answer is never reported as accepted');
        self::assertSame($bells, $this->bellCount(), 'the refused answer added no bell');
        self::assertSame($mails, $this->mailCount(), 'the refused answer added no outbox row');

        $row = $this->row(PublicGuard::RSVP, $hash, self::SERVER_IP);
        self::assertNotNull($row, 'the token is counted by its hash');
        self::assertSame($cap, (int) $row->attempts);
        self::assertSame(1, (int) $row->blocked_count, 'the refused flood attempt is metered');
        self::assertStringNotContainsString(self::$guestToken, (string) $row->subject, 'the link itself is never stored');
    }

    #[Group('integration')]
    public function testASlugFloodStopsCountingViewsButStillServesTheGuest(): void
    {
        $this->requireHttp();
        $cap    = PublicGuard::LIMITS[PublicGuard::SITE]['subject'];
        $this->resetCounter(PublicGuard::SITE, self::SERVER_IP, self::FIX_SLUG);
        $before = $this->views();

        for ($i = 1; $i <= $cap; $i++) {
            $res = self::http('GET', '/site.php?slug=' . rawurlencode(self::FIX_SLUG));
            self::assertSame(200, $res['status'], "view {$i} renders the wedding site");
        }
        self::assertSame($before + $cap, $this->views(), 'each visitor view is counted once, up to the ceiling');

        // Past the ceiling the page still renders — a guest must never be
        // refused their own invitation page — and the counter simply stops.
        for ($i = 0; $i < 3; $i++) {
            $res = self::http('GET', '/site.php?slug=' . rawurlencode(self::FIX_SLUG));
            self::assertSame(200, $res['status'], 'the page still renders for the visitor');
            self::assertStringContainsString('Powered by', $res['body'], 'and is the site itself, not a refusal page');
            self::assertStringNotContainsString('a lot of answers', $res['body'], 'a guest is never told to come back later for looking at the site');
        }
        self::assertSame($before + $cap, $this->views(), 'the flood stopped counting views; it did not stop the page');

        $row = $this->row(PublicGuard::SITE, self::FIX_SLUG, self::SERVER_IP);
        self::assertNotNull($row);
        self::assertSame($cap, (int) $row->attempts);
        self::assertSame(3, (int) $row->blocked_count, 'the three uncounted views are metered');
    }

    #[Group('integration')]
    public function testACallbackFloodStopsAtTheCeilingAndLeavesThePaymentPathAlive(): void
    {
        $this->requireHttp();
        $cap = PublicGuard::LIMITS[PublicGuard::CALLBACK]['subject'];
        $this->resetCounter(PublicGuard::CALLBACK, self::SERVER_IP, 'stk');

        // A Daraja STK result for a CheckoutRequestID nothing knows: the endpoint
        // logs the payload for forensics and then rejects it. The flood is aimed
        // at that log write — the row per anonymous POST.
        for ($i = 1; $i <= $cap; $i++) {
            $res = $this->stkCallback('ws_CO_probe_' . bin2hex(random_bytes(4)));
            self::assertSame(200, $res['status'], 'every callback is answered 200 — Safaricom retries anything else');
        }
        self::assertSame($cap, $this->logCount(), 'the forensic log stops at the ceiling, not one row per request');

        for ($i = 0; $i < 2; $i++) {
            $res = $this->stkCallback('ws_CO_probe_' . bin2hex(random_bytes(4)));
            self::assertSame(200, $res['status'], 'the flood is still answered 200');
        }
        self::assertSame($cap, $this->logCount(), 'and writes no more rows');

        // The other callback endpoint is a different door: a real C2B
        // notification must still be logged and ingested while STK is full.
        $transId = 'QGT' . strtoupper(bin2hex(random_bytes(4)));
        self::$probeCodes[] = $transId;
        $res = self::http('POST', '/mpesa_c2b.php', self::c2bPayload($transId), true);
        self::assertSame(200, $res['status']);
        self::assertStringContainsString('"ResultCode":0', str_replace(' ', '', $res['body']), 'a genuine C2B notification is still accepted');
        self::assertSame($cap + 1, $this->logCount(), 'and is still logged');
        self::assertSame(
            1,
            (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblmpesa_callbacks WHERE trans_id = :t', [':t' => $transId]),
            'and still reaches the ledger'
        );

        $row = $this->row(PublicGuard::CALLBACK, 'stk', self::SERVER_IP);
        self::assertNotNull($row);
        self::assertSame($cap, (int) $row->attempts);
        self::assertSame(2, (int) $row->blocked_count);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Start a door from zero for one caller. The counters are shared state and
     * one class in this suite leaves the kill switch cleared behind it, so a
     * test that measures a ceiling must not depend on nobody having rung the
     * door first — it clears its own two rows and counts from there.
     */
    private function resetCounter(string $kind, string $ip, string $subject = ''): void
    {
        $this->requireDb();
        self::$db->execute(
            "DELETE FROM tblpublic_throttle WHERE kind = :k AND ip = :i AND subject = ''",
            [':k' => $kind, ':i' => $ip]
        );
        if ($subject !== '') {
            self::$db->execute(
                'DELETE FROM tblpublic_throttle WHERE kind = :k AND ip = :i AND subject = :s',
                [':k' => $kind, ':i' => $ip, ':s' => $subject]
            );
        }
    }

    /**
     * The counter row for one (door, subject, caller), or null. $ip defaults to
     * this process's address — the HTTP tests pass the server's ('127.0.0.1'),
     * because that is the caller the guard counted.
     */
    private function row(string $kind, string $subject, ?string $ip = null): ?object
    {
        $this->requireDb();
        return self::$db->fetchOne(
            'SELECT kind, subject, ip, attempts, blocked_count, window_started_at
               FROM tblpublic_throttle WHERE kind = :k AND subject = :s AND ip = :i',
            [':k' => $kind, ':s' => $subject, ':i' => $ip ?? PublicGuard::clientIp()]
        );
    }

    private function bellCount(): int
    {
        return (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications
              WHERE id > :m AND booking_id = :b AND message LIKE '%their invitation (RSVP)%'",
            [':m' => self::$notifMark, ':b' => self::FIX_BOOKING]
        );
    }

    private function mailCount(): int
    {
        return (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tbl_mail_logs WHERE id > :m AND subject LIKE 'RSVP %'",
            [':m' => self::$mailMark]
        );
    }

    private function views(): int
    {
        return (int) self::$db->fetchValue('SELECT views FROM tblwedding_websites WHERE slug = :s', [':s' => self::FIX_SLUG]);
    }

    private function logCount(): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblmpesa_callback_log WHERE id > :m', [':m' => self::$logMark]);
    }

    /** One answer on the public RSVP form, as a guest submits it. */
    private function rsvp(string $token, string $status): array
    {
        return self::http('POST', '/rsvp.php', [
            'token'  => $token,
            'rsvp'   => '1',
            'status' => $status,
            'note'   => 'probe',
        ]);
    }

    /** One Daraja STK result callback. */
    private function stkCallback(string $checkout): array
    {
        return self::http('POST', '/mpesa_stk_callback.php', [
            'Body' => ['stkCallback' => [
                'MerchantRequestID'   => 'probe-' . bin2hex(random_bytes(3)),
                'CheckoutRequestID'   => $checkout,
                'ResultCode'          => 0,
                'ResultDesc'          => 'The service request is processed successfully.',
                'CallbackMetadata'    => ['Item' => [['Name' => 'MpesaReceiptNumber', 'Value' => 'QGT' . strtoupper(bin2hex(random_bytes(4)))]]],
            ]],
        ], true);
    }

    /** A valid Daraja C2B confirmation payload. */
    private static function c2bPayload(string $transId): array
    {
        return [
            'TransactionType'         => 'Pay Bill',
            'TransID'                 => $transId,
            'TransTime'               => date('YmdHis'),
            'TransAmount'             => '7999.00',
            'BusinessShortCode'       => getenv('WPMS_MPESA_SHORTCODE') ?: '123456',
            'BillRefNumber'           => 'WPMS',
            'InvoiceNumber'           => '',
            'OrgAccountBalance'       => '0.00',
            'ThirdPartyTransactionID' => '',
            'MSISDN'                  => '254700000888',
            'FirstName'               => 'Probe',
            'MiddleName'              => '',
            'LastName'                => 'Guard',
        ];
    }

    /**
     * One request against the test server. $json posts a JSON body (the Daraja
     * endpoints read php://input) instead of a form.
     *
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = [], bool $json = false): array
    {
        $ch = curl_init();
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::$base . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_COOKIE         => implode('; ', array_map(
                static fn(string $k, string $v): string => $k . '=' . $v,
                array_keys(self::$jar),
                array_values(self::$jar)
            )),
            CURLOPT_HTTPHEADER     => $json ? ['Content-Type: application/json'] : ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($post) : http_build_query($post));
        }
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $head = substr($raw, 0, $hsize);
        if (preg_match('/^Location:\s*(.+)$/mi', $head, $m)) {
            $location = trim($m[1]);
        } else {
            $location = '';
        }
        if (preg_match_all('/^Set-Cookie:\s*([^;=]+)=([^;]*)/mi', $head, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                self::$jar[trim($c[1])] = trim($c[2]);
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => substr($raw, $hsize)];
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_pg_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_pg_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 32000 + random_int(0, 999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            // The suite's kill switch is stripped from the child: these requests
            // are the ones the counter has to be measured in.
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
