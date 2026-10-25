<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Scheduled-job watchdog (admin/include/JobWatch.php, scripts/job_watch.php,
 * migrations/v2.15) — the platform reporting its own silence:
 *
 *  1. record() writes ONE row per job, whatever the job is started by, and
 *     counts runs instead of growing a table. An unknown job or status is a
 *     programming error and is refused.
 *  2. A job that HAS run and stopped is 'silent'; a run that died is 'failing';
 *     a run that reported success while the queue IT reported had work waiting
 *     is 'nothing to show'. A job that has NEVER run is reported and never
 *     alerted.
 *  3. The operator is told ONCE per (job, finding) per half-day, and a job that
 *     recovers forgets its alert so the next silence is news again.
 *  4. The queue is the JOB's own selection, reported by the job and never
 *     recomputed here: a pending mail row below the attempt cap counts, one at
 *     the cap does not; an alert past its retention window counts; a job that
 *     reports no queue at all is never credited with one. The two counters the
 *     sweeps themselves use live in Notification and are tested here too.
 *  5. All five judged jobs leave heartbeats when they really run — and a
 *     --dry-run leaves none, because a dry run must not make a silent job look
 *     alive.
 *  6. scripts/job_watch.php prints the report and exits non-zero on a finding,
 *     so the pinger that runs the jobs can watch their silence too.     *  7. admin/job_watch.php is the operator's page: super admins only, and it
     *     IS the check (opening it audits).
     *  8. Two switches silence the alerts and neither is the other's job:
     *     WPMS_JOB_ALERTS (the operator's, on by default) and
     *     WPMS_LOGIN_THROTTLE_OFF (the suite's). Both leave the heartbeat, the
     *     report and the CLI's non-zero exit exactly as they were.
 *
 * Integration ('integration' group) — requires the live DB with v2.15 applied
 * and a bootable php -S. Heartbeats, probe mail/notification rows and the bells
 * this class causes are all removed in tearDownAfterClass.
 */
final class JobWatchTest extends TestCase
{
    private const SUPER_EMAIL   = 'wafula@wpms.co.ke';
    private const SUPER_PASS    = 'Admin@2024!';
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';

    private const CLI            = __DIR__ . '/../scripts/job_watch.php';
    private const MAIL_RETRY     = __DIR__ . '/../scripts/mail_retry.php';
    private const NOTIFY_PRUNE   = __DIR__ . '/../scripts/notify_prune.php';
    private const RENEWALS       = __DIR__ . '/../scripts/subscription_renewals.php';
    private const RSVP_REMINDERS = __DIR__ . '/../scripts/rsvp_reminders.php';
    private const DEADLINE_SMS   = __DIR__ . '/../scripts/deadline_sms.php';
    private const BUSINESS_PULSE = __DIR__ . '/../scripts/business_pulse_email.php';
    private const MCP_APPROVALS  = __DIR__ . '/../scripts/mcp_approval_sweep.php';
    private const ESCALATION     = __DIR__ . '/../scripts/thread_escalation.php';

    /** Every leg of subscription_renewals switched off — the safe way to run it. */
    private const NO_LEGS = ['WPMS_SUB_AUTO_RENEW' => '0', 'WPMS_SUB_GRACE' => '0', 'WPMS_TRIAL_NURTURE' => '0'];

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    private static int $superId = 0;
    /** @var int[] probe tbl_mail_logs ids */
    private static array $probeMails = [];
    /** @var int[] probe tblnotifications ids */
    private static array $probeNotifs = [];
    /** Whether the shell had the alert kill switch on (restored on teardown). */
    private static bool $killSwitch = true;
    /** @var array<string, string|null> the flags this class flips, as the shell had them */
    private static array $envBase = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            /*
             * Class name => file name, spelled out. Two of these files are not
             * named after their class (`Database` lives in database.php,
             * `DB_Object` in db_object.php), so building the path from the class
             * name worked on Windows only and was a fatal on the Linux CI runner
             * this suite is supposed to guard.
             */
            $requires = [
                'Database'     => 'database.php',
                'DB_Object'    => 'db_object.php',
                'Helper'       => 'Helper.php',
                'Monetization' => 'Monetization.php',
                'Notification' => 'Notification.php',
                'JobWatch'     => 'JobWatch.php',
            ];
            foreach ($requires as $class => $file) {
                if (!class_exists($class, false)) {
                    require_once __DIR__ . '/../admin/include/' . $file;
                }
            }
            global $db;
            $db = self::$db = new Database();
            if (!JobWatch::available()) {
                self::$db = null; // v2.15 not applied — skip cleanly
            }
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::snapshotEnv();

        if (self::$db !== null) {
            // '2' quoted: tblusers.access_level is enum('0','1','2') and MySQL
            // compares an enum to an INTEGER by ordinal — `= 2` is every '1'.
            self::$superId = (int) self::$db->fetchValue(
                "SELECT id FROM tblusers WHERE email = :e AND access_level = '2' LIMIT 1",
                [':e' => self::SUPER_EMAIL]
            );
            if (self::$superId === 0) {
                self::$db = null;
            }
        }

        self::cleanProbeState();
        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanProbeState();
        if (self::$db !== null) {
            try {
                // The heartbeats this class wrote are its own; other suites do
                // not read them, and a stale silent row would only mislead the
                // next run of the page.
                self::$db->execute('DELETE FROM tbljob_heartbeats');
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::restoreEnv();
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB with tbljob_heartbeats unavailable — JobWatch tests skipped');
        }
        self::cleanProbeState();
        self::$db->execute('DELETE FROM tbljob_heartbeats');
        self::restoreEnv(); // every test starts from the shell's own flags
    }

    protected function tearDown(): void
    {
        self::cleanProbeState();
        self::restoreEnv();
    }

    /** Remember the flags this class flips so each test can be handed them back. */
    private static function snapshotEnv(): void
    {
        foreach (['WPMS_LOGIN_THROTTLE_OFF', 'WPMS_JOB_ALERTS', 'WPMS_MAIL_ENABLED', 'WPMS_SUB_AUTO_RENEW', 'WPMS_SUB_GRACE', 'WPMS_TRIAL_NURTURE'] as $key) {
            $value = getenv($key);
            self::$envBase[$key] = $value === false ? null : (string) $value;
        }
        self::$killSwitch = self::$envBase['WPMS_LOGIN_THROTTLE_OFF'] !== null;
    }

    private static function restoreEnv(): void
    {
        foreach (self::$envBase as $key => $value) {
            putenv($value === null ? $key : $key . '=' . $value);
        }
    }

    /** Remove probe rows and any bell the watchdog delivered. */
    private static function cleanProbeState(): void
    {
        $db = self::$db;
        if ($db === null) {
            return;
        }
        try {
            if (self::$probeMails !== []) {
                $db->execute('DELETE FROM tbl_mail_logs WHERE id IN (' . implode(',', self::$probeMails) . ')');
            }
            if (self::$probeNotifs !== []) {
                $db->execute('DELETE FROM tblnotifications WHERE id IN (' . implode(',', self::$probeNotifs) . ')');
            }
            // Bells the watchdog itself delivered: the link is the marker.
            $db->execute("DELETE FROM tbl_mail_logs WHERE notification_id IN (SELECT id FROM (SELECT id FROM tblnotifications WHERE link = 'job_watch.php') x)");
            $db->execute("DELETE FROM tblnotifications WHERE link = 'job_watch.php'");
        } catch (Throwable $e) {
            // leave state untouched on failure
        }
        self::$probeMails  = [];
        self::$probeNotifs = [];
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Alerts ON for this process (the suite turns them off globally). */
    private function alertsOn(): void
    {
        putenv('WPMS_LOGIN_THROTTLE_OFF');
    }

    /** A pending mail row; attempts at the cap means "finished", not "waiting". */
    private function seedPendingMail(int $attempts = 0): int
    {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, recipient_type, to_email, subject, body, status, note, attempts) "
            . "VALUES (NULL, :uid, 0, 'user', 'amina@wpms.co.ke', '[jobwatch-test] subject', 'body', 'pending', 'test seed', :attempts)"
        );
        $stmt->execute([':uid' => self::$superId, ':attempts' => $attempts]);
        $id = (int) $db->the_insert_id();
        self::$probeMails[] = $id;
        return $id;
    }

    /** A notification old enough for notify_prune to want it gone. */
    private function seedStaleNotification(int $ageDays, int $read = 0): int
    {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tblnotifications (user_id, booking_id, recipient_type, message, link, is_read, created_at) "
            . "VALUES (:uid, 0, 'user', '[jobwatch-test] stale alert', '', :read, NOW() - INTERVAL :days DAY)"
        );
        $stmt->execute([':uid' => self::$superId, ':read' => $read, ':days' => $ageDays]);
        $id = (int) $db->the_insert_id();
        self::$probeNotifs[] = $id;
        return $id;
    }

    /**
     * A healthy heartbeat for every judged job except $exclude — the state the
     * CLI has to call clean (each job handled something, nothing was queued).
     *
     * @param string[] $exclude
     */
    private static function recordHealthy(array $exclude = []): void
    {
        foreach (array_keys(JobWatch::JOBS) as $job) {
            if (in_array($job, $exclude, true)) {
                continue;
            }
            JobWatch::record($job, JobWatch::OK, 1, 'one item handled', 0, null, 0);
        }
    }

    /** Backdate one job's last run so the watchdog sees it as missing. */
    private function backdate(string $job, string $interval): void
    {
        self::$db->execute(
            'UPDATE tbljob_heartbeats SET last_finished_at = NOW() - INTERVAL :s SECOND WHERE job = :j',
            [':s' => $interval, ':j' => $job]
        );
    }

    private function finding(string $job): ?string
    {
        $report = JobWatch::report();
        return $report['jobs'][$job]['finding'] ?? null;
    }

    private function bellsFor(string $needle): int
    {
        return (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php' AND message LIKE :n",
            [':n' => '%' . $needle . '%']
        );
    }

    /**
     * Run one of the real scripts in its own process.
     *
     * @param array<string,string> $env     flags this test decides (the child's,
     *                                      never the shell's)
     * @param string[]             $phpIni  -d settings (a fake MTA, for the
     *                                      "nothing could be delivered" path)
     * @return array{code:int, out:string}
     */
    private static function runScript(string $script, string $args = '', array $env = [], array $phpIni = []): array
    {
        $childEnv = [];
        foreach (getenv() as $k => $v) {
            $childEnv[$k] = $v;
        }
        unset($childEnv['WPMS_LOGIN_THROTTLE_OFF']); // each child decides for itself
        foreach ($env as $k => $v) {
            $childEnv[$k] = $v;
        }
        $ini = '';
        foreach ($phpIni as $setting) {
            $ini .= ' -d ' . escapeshellarg($setting);
        }
        $cmd = escapeshellarg(PHP_BINARY) . $ini . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '');
        $proc = @proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $childEnv // explicit: the child's flags are this test's decision, not the shell's
        );
        if (!is_resource($proc)) {
            self::fail('could not start ' . basename($script));
        }
        $out  = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $code = (int) proc_close($proc);
        return ['code' => $code, 'out' => $out];
    }

    private function requireDb(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('live DB with tbljob_heartbeats unavailable — JobWatch tests skipped');
        }
    }

    // ------------------------------------------------------------------
    // 1. The heartbeat
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRecordKeepsOneRowPerJobAndCountsRuns(): void
    {
        $this->requireDb();
        self::assertTrue(JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 3, 'sent 3'));
        self::assertTrue(JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 5, 'sent 5'));

        $row = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]);
        self::assertNotNull($row, 'one row per job');
        self::assertSame(2, (int) $row->runs, 'runs counts heartbeats');
        self::assertSame(5, (int) $row->last_items, 'the newest run wins');
        self::assertSame('sent 5', (string) $row->last_summary);
        self::assertSame('ok', (string) $row->last_status);
        self::assertSame('', (string) $row->alert_reason, 'a healthy job carries no alert memory');
        self::assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]));
    }

    #[Group('integration')]
    public function testAnUnknownJobOrStatusIsRefused(): void
    {
        $this->requireDb();
        try {
            JobWatch::record('not_a_job', JobWatch::OK);
            self::fail('an unregistered job must be refused, not silently recorded');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not_a_job', $e->getMessage());
        }
        try {
            JobWatch::record(JobWatch::MAIL_RETRY, 'probably');
            self::fail('an unknown status must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('probably', $e->getMessage());
        }
    }

    #[Group('integration')]
    public function testAFailureCountsItsStreakAndAHealthyRunClearsIt(): void
    {
        $this->requireDb();
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::FAILED, 0, 'smtp refused connection', 7);
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::FAILED, 0, 'smtp refused connection', 7);

        $row = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]);
        self::assertSame(2, (int) $row->fail_streak);
        self::assertSame(7, (int) $row->last_exit_code);
        self::assertSame(JobWatch::F_FAILED, $this->finding(JobWatch::MAIL_RETRY), 'a dead run is a finding');

        $sentence = (string) JobWatch::report()['jobs'][JobWatch::MAIL_RETRY]['sentence'];
        self::assertStringContainsString('failed', $sentence);
        self::assertStringContainsString('2 in a row', $sentence);

        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 4, 'sent 4');
        self::assertSame(0, (int) self::$db->fetchValue('SELECT fail_streak FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]));
        self::assertNull($this->finding(JobWatch::MAIL_RETRY), 'a clean run is not a finding');
    }

    // ------------------------------------------------------------------
    // 2. Judging
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testAJobThatStoppedIsSilent(): void
    {
        $this->requireDb();
        JobWatch::record(JobWatch::NOTIFY_PRUNE, JobWatch::OK, 12, 'pruned 12');
        self::assertNull($this->finding(JobWatch::NOTIFY_PRUNE), 'a fresh run is healthy');

        // 3 days: past the daily interval plus the half-day grace.
        $this->backdate(JobWatch::NOTIFY_PRUNE, '259200');
        self::assertSame(JobWatch::F_SILENT, $this->finding(JobWatch::NOTIFY_PRUNE));
        self::assertStringContainsString('has not run for 3 d', (string) JobWatch::report()['jobs'][JobWatch::NOTIFY_PRUNE]['sentence']);

        // Inside the grace it is late, not an incident.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 2, 'ok');
        $this->backdate(JobWatch::MAIL_RETRY, '1200'); // 20 min: past 15, inside +30
        self::assertNull($this->finding(JobWatch::MAIL_RETRY), 'lateness inside the grace is not a finding');
        self::assertSame('late', JobWatch::states()[JobWatch::MAIL_RETRY]['state']);
    }

    #[Group('integration')]
    public function testAJobThatHasNeverRunIsReportedButNeverAlerts(): void
    {
        $this->requireDb();
        $this->alertsOn();
        $run = JobWatch::audit(true);

        self::assertSame(JobWatch::F_NEVER, $run['report']['jobs'][JobWatch::NOTIFY_PRUNE]['finding']);
        self::assertArrayHasKey(JobWatch::NOTIFY_PRUNE, $run['findings'], 'never-run is a finding for a pinger to see');
        self::assertSame(0, $run['alerted'], 'install day must not fill the operator bell');
        self::assertSame(0, $this->bellsFor('has never run'));
        self::assertSame(0, JobWatch::troubled_count(), 'the sidebar badge stays off for jobs that never ran');
    }

    #[Group('integration')]
    public function testAnIdleRunIsAFindingOnlyOverAQueueTheRunItselfReported(): void
    {
        $this->requireDb();

        // Ran on time, handled nothing, and its own selection offered it three
        // rows: the sweep that reports success while its queue grows.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 0, 'ran, handled nothing', 0, null, 3);
        self::assertSame(JobWatch::F_IDLE, $this->finding(JobWatch::MAIL_RETRY));
        $entry = JobWatch::report()['jobs'][JobWatch::MAIL_RETRY];
        self::assertSame(3, JobWatch::queued($entry));
        self::assertStringContainsString('handled nothing', (string) $entry['sentence']);
        self::assertStringContainsString('3 queued email(s)', (string) $entry['sentence']);

        // The same run, this time actually handling a row: not idle.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 1, 'attempted 1', 0, null, 3);
        self::assertNull($this->finding(JobWatch::MAIL_RETRY), 'a job that worked its queue is not idle');

        // A quiet day: nothing handled, and the job says nothing was waiting.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 0, 'nothing queued', 0, null, 0);
        self::assertNull($this->finding(JobWatch::MAIL_RETRY));

        // A job that reports NO queue (-1) is never credited with one it did
        // not mention: the watchdog does not second-guess a job's selection.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 0, 'no queue reported');
        $entry = JobWatch::report()['jobs'][JobWatch::MAIL_RETRY];
        self::assertNull(JobWatch::queued($entry), 'an unreported queue is a state of its own, not a zero');
        self::assertNull($this->finding(JobWatch::MAIL_RETRY));

        // And a 'noop' run is never idle, however full its queue looks: a job
        // cannot fail to do what it was told not to do (transport off).
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::NOOP, 0, 'transport off', 0, null, 9);
        self::assertNull($this->finding(JobWatch::MAIL_RETRY));
        self::assertSame('noop', JobWatch::states()[JobWatch::MAIL_RETRY]['state']);
    }

    #[Group('integration')]
    public function testTheQueueCountersMirrorTheSweepsOwnSelections(): void
    {
        $this->requireDb();
        // These two live in Notification (the model that owns the selection), so
        // the queue a job reports and the rows the sweep picks up cannot drift.
        //
        // mail_retry: pending BELOW the attempt cap waits; a row at the cap is
        // finished business and is never counted.
        // Other suites leave pending rows behind (every alert written while the
        // transport is off is one), so the queue is measured relatively: this
        // test owns the difference it makes.
        $before = Notification::pending_mail_count(5);
        $this->seedPendingMail(0);
        self::assertSame($before + 1, Notification::pending_mail_count(5), 'a pending row below the cap joins the queue');
        $this->seedPendingMail(5);
        self::assertSame($before + 1, Notification::pending_mail_count(5), 'a row at the attempt cap is finished, not waiting');

        // notify_prune: retention is read 60 days / unread 180 — one day inside
        // each window is not waiting, one day outside it is.
        self::assertSame(0, Notification::prunable_count(60, 180));
        $this->seedStaleNotification(59, 1);
        $this->seedStaleNotification(179, 0);
        self::assertSame(0, Notification::prunable_count(60, 180), 'inside the retention window nothing waits');
        $this->seedStaleNotification(61, 1);
        $this->seedStaleNotification(181, 0);
        self::assertSame(2, Notification::prunable_count(60, 180));
    }

    // ------------------------------------------------------------------
    // 3. Telling the operator
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheOperatorIsToldOncePerFindingPerCooldown(): void
    {
        $this->requireDb();
        $this->alertsOn();
        JobWatch::record(JobWatch::NOTIFY_PRUNE, JobWatch::OK, 4, 'pruned 4');
        $this->backdate(JobWatch::NOTIFY_PRUNE, '259200');

        $first = JobWatch::audit(true);
        self::assertSame(JobWatch::F_SILENT, $first['findings'][JobWatch::NOTIFY_PRUNE]['reason']);
        self::assertGreaterThan(0, $first['alerted'], 'a job that stopped must reach the operator');

        $bells = (int) self::$db->fetchValue("SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php'");
        self::assertSame($first['alerted'], $bells, 'one bell per operator, no more');
        $recipients = self::$db->fetchAll(
            "SELECT DISTINCT n.user_id, u.access_level FROM tblnotifications n JOIN tblusers u ON u.id = n.user_id WHERE n.link = 'job_watch.php'"
        );
        foreach ($recipients as $r) {
            self::assertSame('2', (string) $r->access_level, 'only the platform operator is told');
        }
        self::assertGreaterThan(0, $this->bellsFor('has not run'), 'the message says what happened');

        // Inside the cooldown the same finding is not repeated...
        $second = JobWatch::audit(true);
        self::assertSame(0, $second['alerted'], 'the same finding is not repeated inside the cooldown');
        self::assertSame($bells, (int) self::$db->fetchValue("SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php'"));

        // ...but a DIFFERENT problem is news immediately: the job came back and
        // died. A green-ish run cannot mask that, and the cooldown cannot hide it.
        JobWatch::record(JobWatch::NOTIFY_PRUNE, JobWatch::FAILED, 0, 'table locked', 1);
        $third = JobWatch::audit(true);
        self::assertSame(JobWatch::F_FAILED, $third['findings'][JobWatch::NOTIFY_PRUNE]['reason'], 'the run that just died is the finding now');
        self::assertGreaterThan(0, $third['alerted'], 'a new problem is not suppressed by the old one');

        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::FAILED, 0, 'smtp refused', 7);
        $this->backdate(JobWatch::MAIL_RETRY, '600');
        $fourth = JobWatch::audit(true);
        self::assertSame(JobWatch::F_FAILED, $fourth['findings'][JobWatch::MAIL_RETRY]['reason']);
        self::assertGreaterThan(0, $fourth['alerted'], 'a different job failing is news');
    }

    #[Group('integration')]
    public function testRecoveryForgetsTheAlertSoTheNextSilenceIsNewsAgain(): void
    {
        $this->requireDb();
        $this->alertsOn();
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 1, 'sent 1');
        $this->backdate(JobWatch::MAIL_RETRY, '7200'); // 2 h: past 15 min + 30 min grace
        $this->assertGreaterThan(0, JobWatch::audit(true)['alerted']);

        // The job comes back: the finding clears AND the memory is dropped.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 2, 'sent 2');
        $recovered = JobWatch::audit(true);
        self::assertArrayNotHasKey(JobWatch::MAIL_RETRY, $recovered['findings'], 'the recovered job is no longer a finding');
        self::assertSame('', (string) self::$db->fetchValue('SELECT alert_reason FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]));

        // A fresh silence after recovery is reported again, immediately.
        $this->backdate(JobWatch::MAIL_RETRY, '7200');
        self::assertGreaterThan(0, JobWatch::audit(true)['alerted'], 'the next incident is not suppressed by the last one');
    }

    #[Group('integration')]
    public function testAnInspectionCanBeTakenWithoutTellingAnyone(): void
    {
        $this->requireDb();
        $this->alertsOn();
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 0, 'quiet');
        $this->backdate(JobWatch::MAIL_RETRY, '7200');

        $run = JobWatch::audit(false);
        self::assertArrayHasKey(JobWatch::MAIL_RETRY, $run['findings'], 'the inspection still reports the finding');
        self::assertSame(0, $run['alerted']);
        self::assertSame(0, (int) self::$db->fetchValue("SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php'"));
    }

    #[Group('integration')]
    public function testTheKillSwitchSilencesTheAlertButNotTheHeartbeat(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF=1');
        self::assertTrue(JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 1, 'sent 1'), 'a heartbeat is not an operator side effect');
        $this->backdate(JobWatch::MAIL_RETRY, '7200');

        $run = JobWatch::audit(true);
        self::assertArrayHasKey(JobWatch::MAIL_RETRY, $run['findings']);
        self::assertSame(0, $run['alerted'], 'the kill switch stops the bell, not the report');
        self::assertSame(0, (int) self::$db->fetchValue("SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php'"));
    }

    /**
     * The operator's OWN switch (WPMS_JOB_ALERTS=0) — what a live install sets
     * when it wants a quieter feed. It must silence the alert and NOTHING else:
     * the report, the exit code the pinger watches, and the login door's
     * brute-force protection all have to survive it. (Before this switch the
     * only way to quiet the watchdog was WPMS_LOGIN_THROTTLE_OFF, which also
     * unlocked every account an attacker was guessing at.)
     */
    #[Group('integration')]
    public function testTheOperatorsOwnSwitchSilencesAlertsAndLeavesTheLoginDoorGuarded(): void
    {
        $this->requireDb();
        putenv('WPMS_LOGIN_THROTTLE_OFF');   // the suite's switch is NOT what is under test
        putenv('WPMS_JOB_ALERTS=0');
        self::assertFalse(JobWatch::alerts_enabled());
        self::assertTrue(LoginThrottle::enabled(), 'silencing job alerts must not unlock the login door');

        // The heartbeat and the finding survive: only the interruption is gone.
        self::assertTrue(JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 1, 'sent 1'));
        $this->backdate(JobWatch::MAIL_RETRY, '7200');
        $run = JobWatch::audit(true);
        self::assertArrayHasKey(JobWatch::MAIL_RETRY, $run['findings'], 'a silenced install still reports the finding');
        self::assertSame(0, $run['alerted']);
        self::assertSame(0, (int) self::$db->fetchValue("SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php'"));

        // ...and the pinger still sees it: the CLI exits 1 on that same finding
        // with the switch set and the suite's kill switch NOT set.
        $cli = self::runScript(self::CLI, '--quiet', ['WPMS_JOB_ALERTS' => '0']);
        self::assertSame(1, $cli['code'], 'a silenced install still fails its own watchdog: ' . $cli['out']);
        self::assertStringContainsString('has not run for', $cli['out']);
        self::assertSame(0, (int) self::$db->fetchValue("SELECT COUNT(*) FROM tblnotifications WHERE link = 'job_watch.php'"));

        // Read per call, so turning it back on needs no restart — and an empty
        // value means ON, which is what an untouched install has.
        putenv('WPMS_JOB_ALERTS=1');
        self::assertTrue(JobWatch::alerts_enabled());
        putenv('WPMS_JOB_ALERTS=');
        self::assertTrue(JobWatch::alerts_enabled(), 'an empty WPMS_JOB_ALERTS is the default: alerts on');
        self::assertGreaterThan(0, JobWatch::audit(true)['alerted'], 'with the switch back on the operator is told');
    }

    // ------------------------------------------------------------------
    // 4. The jobs leave heartbeats
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testNotifyPruneLeavesAHeartbeatWhenItReallyRuns(): void
    {
        $this->requireDb();
        $r = self::runScript(self::NOTIFY_PRUNE, '--quiet');
        self::assertSame(0, $r['code'], 'a clean prune exits 0: ' . $r['out']);

        $row = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::NOTIFY_PRUNE]);
        self::assertNotNull($row, 'the job must leave a heartbeat');
        self::assertSame(JobWatch::OK, (string) $row->last_status);
        self::assertSame(1, (int) $row->runs);
        self::assertStringContainsString('pruned', (string) $row->last_summary);
    }

    #[Group('integration')]
    public function testMailRetryReportsANoopWhenTheTransportIsOff(): void
    {
        $this->requireDb();
        $r = self::runScript(self::MAIL_RETRY, '--quiet', ['WPMS_MAIL_ENABLED' => '0']);
        self::assertSame(0, $r['code'], $r['out']);

        $row = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]);
        self::assertNotNull($row);
        self::assertSame(JobWatch::NOOP, (string) $row->last_status, 'a deliberate no-op is not a failure');
        self::assertNull($this->finding(JobWatch::MAIL_RETRY), 'a no-op with a queue waiting is configuration, not idleness');
    }

    #[Group('integration')]
    public function testRenewalsRecordsOnlyRealRunsAndNeverADryRun(): void
    {
        $this->requireDb();
        $dry = self::runScript(self::RENEWALS, '--quiet --dry-run', self::NO_LEGS);
        self::assertSame(0, $dry['code'], $dry['out']);
        self::assertNull(
            self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::RENEWALS]),
            'a dry run wrote nothing, so it must not make the job look alive'
        );

        $real = self::runScript(self::RENEWALS, '--quiet', self::NO_LEGS);
        self::assertSame(0, $real['code'], $real['out']);
        $row = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::RENEWALS]);
        self::assertNotNull($row);
        self::assertSame(JobWatch::NOOP, (string) $row->last_status, 'every leg off: it says it had nothing to do');
        self::assertStringContainsString('auto-renew: off', (string) $row->last_summary);
    }

    #[Group('integration')]
    public function testBothSmsJobsAreJudgedAndLeaveAHeartbeatOnARealRun(): void
    {
        $this->requireDb();
        // Both are invoked for real, with flags that guarantee nothing is due:
        // no guest was invited 273 years ago, and the nearest seeded wedding is
        // weeks out (asserted, so a seed change turns this into a skip rather
        // than a surprise text). What is under test is the wiring: a real run of
        // each script records the job, its status and the queue it looked at.
        $rsvp = self::runScript(self::RSVP_REMINDERS, '--quiet --after=99999');
        self::assertSame(0, $rsvp['code'], $rsvp['out']);
        $beat = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::RSVP_REMINDERS]);
        self::assertNotNull($beat, 'rsvp_reminders must leave a heartbeat');
        self::assertSame(JobWatch::OK, (string) $beat->last_status);
        self::assertSame(0, (int) $beat->last_items);
        self::assertSame(0, (int) $beat->last_queue, 'no guest was due, and the job said so');
        self::assertNull($this->finding(JobWatch::RSVP_REMINDERS));

        $daysOut = (int) self::$db->fetchValue(
            'SELECT COALESCE(MIN(DATEDIFF(wedding_date, CURDATE())), 9999) FROM tblweddingbook WHERE wedding_date >= CURDATE()'
        );
        if ($daysOut <= 1) {
            self::markTestSkipped('a seeded wedding is inside the first day — refusing to text it from a watchdog test');
        }
        $deadline = self::runScript(self::DEADLINE_SMS, '--quiet --bands=1');
        self::assertSame(0, $deadline['code'], $deadline['out']);
        $beat = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::DEADLINE_SMS]);
        self::assertNotNull($beat, 'deadline_sms must leave a heartbeat');
        self::assertSame(JobWatch::OK, (string) $beat->last_status);
        self::assertSame(0, (int) $beat->last_queue, 'no booking is inside a band and reachable, so nothing waited');
        self::assertNull($this->finding(JobWatch::DEADLINE_SMS));

        // Both jobs are judged, so the registry, the report and the CLI carry
        // all eight (v2.19 added the MCP approval expiry sweep, the weekly
        // business pulse joined with the Monday brief, and v2.26 added the
        // vendor reply escalation).
        self::assertCount(8, JobWatch::JOBS);
        self::assertArrayHasKey(JobWatch::RSVP_REMINDERS, JobWatch::report()['jobs']);
        self::assertArrayHasKey(JobWatch::DEADLINE_SMS, JobWatch::report()['jobs']);
        self::assertArrayHasKey(JobWatch::THREAD_ESCALATION, JobWatch::report()['jobs']);
    }

    #[Group('integration')]
    public function testNeitherSmsJobLeavesAHeartbeatOnADryRun(): void
    {
        $this->requireDb();
        $rsvp = self::runScript(self::RSVP_REMINDERS, '--quiet --after=99999 --dry-run');
        self::assertSame(0, $rsvp['code'], $rsvp['out']);
        $deadline = self::runScript(self::DEADLINE_SMS, '--quiet --bands=1 --dry-run');
        self::assertSame(0, $deadline['code'], $deadline['out']);

        self::assertNull(
            self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::RSVP_REMINDERS]),
            'a dry run wrote nothing, so it must not make rsvp_reminders look alive'
        );
        self::assertNull(
            self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::DEADLINE_SMS]),
            'a dry run wrote nothing, so it must not make deadline_sms look alive'
        );
    }

    /**
     * The other three jobs obey the same rule: a dry run is not a run.
     *
     * These three used to record a NOOP heartbeat on `--dry-run`, which stamps
     * `last_finished_at` — the only field the watchdog ages — so a crontab left
     * on the flag reported a healthy job forever while nothing was ever sent,
     * closed or escalated. That is precisely the silence JobWatch exists to
     * report, so the invariant is asserted for every job that has the flag.
     */
    #[Group('integration')]
    public function testEveryOtherJobLeavesNoHeartbeatOnADryRun(): void
    {
        $this->requireDb();

        $pulse = self::runScript(self::BUSINESS_PULSE, '--quiet --dry-run', ['WPMS_MAIL_ENABLED' => '0']);
        self::assertSame(0, $pulse['code'], $pulse['out']);
        self::assertNull(
            self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::BUSINESS_PULSE]),
            'a dry business pulse sent nothing, so it must not make the job look alive'
        );

        $sweep = self::runScript(self::MCP_APPROVALS, '--quiet --dry-run --limit=5');
        self::assertSame(0, $sweep['code'], $sweep['out']);
        self::assertNull(
            self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MCP_APPROVALS]),
            'a dry sweep closed nothing, so it must not make the job look alive'
        );

        $escalation = self::runScript(self::ESCALATION, '--quiet --dry-run --limit=5');
        self::assertSame(0, $escalation['code'], $escalation['out']);
        self::assertNull(
            self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::THREAD_ESCALATION]),
            'a dry escalation alerted nobody, so it must not make the job look alive'
        );
    }

    #[Group('integration')]
    public function testMailRetryReportsAFailureWhenItDeliversNothing(): void
    {
        $this->requireDb();
        // Transport on, a real row waiting, and no MTA at all: the script exits
        // 0 (it did its work) but delivered nothing — that is not a healthy
        // sweep, and the watchdog must say so rather than report a quiet day.
        $this->seedPendingMail(0);
        $r = self::runScript(
            self::MAIL_RETRY,
            '--quiet --limit=1', // one attempt: this test is not a load test
            ['WPMS_MAIL_ENABLED' => '1'],
            ['smtp_port=1', 'SMTP=127.0.0.1']
        );
        self::assertSame(0, $r['code'], $r['out']);

        $beat = self::$db->fetchOne('SELECT * FROM tbljob_heartbeats WHERE job = :j', [':j' => JobWatch::MAIL_RETRY]);
        self::assertNotNull($beat);
        self::assertSame(JobWatch::FAILED, (string) $beat->last_status, 'nothing delivered is not success');
        self::assertGreaterThan(0, (int) $beat->last_items, 'it did attempt a row');
        self::assertSame(JobWatch::F_FAILED, $this->finding(JobWatch::MAIL_RETRY));
        self::assertStringContainsString('sent: 0', (string) $beat->last_summary);
    }

    // ------------------------------------------------------------------
    // 5. The CLI the pinger watches
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheCliIsQuietAndExitsZeroWhenEveryJobReported(): void
    {
        $this->requireDb();
        self::recordHealthy();

        $quiet = self::runScript(self::CLI, '--quiet');
        self::assertSame(0, $quiet['code'], $quiet['out']);
        self::assertSame('', trim($quiet['out']), 'a pinger must get silence when nothing is wrong');

        $json = self::runScript(self::CLI, '--json');
        self::assertSame(0, $json['code'], $json['out']);
        $decoded = json_decode($json['out'], true);
        self::assertIsArray($decoded, 'the CLI must emit parseable JSON: ' . $json['out']);
        self::assertSame([], $decoded['findings']);
        self::assertSame(0, $decoded['alerted']);
        self::assertSame(count(JobWatch::JOBS), count($decoded['jobs']), 'every judged job is in the report');
    }

    #[Group('integration')]
    public function testTheCliExitsNonZeroOnAFindingAndNamesIt(): void
    {
        $this->requireDb();
        self::recordHealthy([JobWatch::NOTIFY_PRUNE]);
        JobWatch::record(JobWatch::NOTIFY_PRUNE, JobWatch::OK, 9, 'pruned 9');
        $this->backdate(JobWatch::NOTIFY_PRUNE, '259200');

        $quiet = self::runScript(self::CLI, '--quiet');
        self::assertSame(1, $quiet['code'], 'a finding is a non-zero exit so a pinger can watch it');
        self::assertStringContainsString('Notification prune has not run for 3 d', $quiet['out']);
        $told = $this->bellsFor('has not run');
        self::assertGreaterThan(0, $told, 'the pinger run also reached the operator');

        // --no-alert inspects without telling anyone.
        $noAlert = self::runScript(self::CLI, '--no-alert --json');
        self::assertSame(1, $noAlert['code']);
        self::assertSame(0, (int) json_decode($noAlert['out'], true)['alerted']);
        self::assertSame($told, $this->bellsFor('has not run'), '--no-alert told nobody');
    }

    #[Group('integration')]
    public function testAnIdleQueueReachesTheOperatorThroughTheCli(): void
    {
        $this->requireDb();
        // The heartbeat is the job's own report: ran on time, handled nothing,
        // nine rows behind it. The CLI has to catch that and tell the operator.
        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 0, 'ran, handled nothing', 0, null, 9);
        self::recordHealthy([JobWatch::MAIL_RETRY]);

        $r = self::runScript(self::CLI, '--quiet');
        self::assertSame(1, $r['code'], $r['out']);
        self::assertStringContainsString('but handled nothing', $r['out']);
        self::assertStringContainsString('9 queued email(s)', $r['out']);
        self::assertGreaterThan(0, $this->bellsFor('handled nothing'), 'the operator hears about a sweep that sweeps nothing');
    }

    #[Group('integration')]
    public function testTheCliDoesNotClaimTheTableIsMissingOnARealInstall(): void
    {
        $this->requireDb();
        // Probed on a database where the table exists, so the contract is
        // asserted from the other side: the CLI must NOT claim 'not installed'
        // on the real install (exit 2 is reserved for that one condition).
        $r = self::runScript(self::CLI, '--no-alert --json');
        self::assertNotSame(2, $r['code'], 'exit 2 means v2.15 is not applied, and here it is');
    }

    // ------------------------------------------------------------------
    // 6. The operator's page
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheOperatorSeesThePageAndThePageIsTheCheck(): void
    {
        $this->requireHttp();
        self::loginStaff(self::SUPER_EMAIL, self::SUPER_PASS);
        $this->requireHttp(); // the login helper drops the base when it cannot form a session

        JobWatch::record(JobWatch::MAIL_RETRY, JobWatch::OK, 2, 'sent 2');
        $page = self::http('GET', '/admin/job_watch.php');
        self::assertSame(200, $page['status'], 'the operator must be able to open their own page');
        foreach (['Job Health', 'Mail retry', 'Notification prune', 'Subscription renewals', 'RSVP reminders', 'Payment deadline texts', 'Waiting'] as $needle) {
            self::assertStringContainsString($needle, $page['body'], 'the page must state ' . $needle);
        }
        $checked = (int) self::$db->fetchValue(
            'SELECT runs FROM tbljob_heartbeats WHERE job = :j',
            [':j' => JobWatch::AUDIT]
        );
        self::assertGreaterThan(0, $checked, 'opening the page IS the check — it must leave its own heartbeat');

        // "Run the check now" writes nothing but its own heartbeat, and needs
        // the CSRF token like every other write in this application.
        $token = '';
        if (preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            $token = html_entity_decode($m[1], ENT_QUOTES);
        }
        self::assertNotSame('', $token, 'the page must offer a CSRF-protected check');
        $post = self::http('POST', '/admin/job_watch.php', ['action' => 'check', '_token' => $token]);
        self::assertSame(302, $post['status']);
        self::assertStringContainsString('job_watch.php', $post['location']);

        $again = self::http('GET', '/admin/job_watch.php');
        self::assertSame(200, $again['status']);
        self::assertStringContainsString('what the scheduled jobs last did', $again['body']);
    }

    #[Group('integration')]
    public function testAPlannerCannotOpenTheOperatorsPage(): void
    {
        $this->requireHttp();
        self::loginStaff(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $this->requireHttp();

        $page = self::http('GET', '/admin/job_watch.php');
        self::assertSame(302, $page['status'], 'running the platform is not a planner\'s page');
        self::assertStringNotContainsString('Job Health', $page['body']);
    }

    // ------------------------------------------------------------------
    // HTTP harness (same shape as the other page tests)
    // ------------------------------------------------------------------

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / live DB unavailable — job_watch page tests skipped');
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_jw_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_jw_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 31000 + random_int(0, 8999);
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
                $up = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($up) {
                    fclose($up);
                    $ready = true;
                    break;
                }
            }
            if ($ready) {
                self::$base = 'http://127.0.0.1:' . $port;
                $ctx  = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
                $body = @file_get_contents(self::$base . '/index.php', false, $ctx);
                if ($body !== false) {
                    return; // a real route answered — the server is serving
                }
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
                self::$serverProc = null;
            } else {
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
                self::$serverProc = null;
            }
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
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
        }
        $body   = $post === [] ? '' : http_build_query($post);
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

        $status   = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq   = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    private static function loginStaff(string $email, string $pass): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }
}
