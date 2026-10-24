<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * BusinessPulse — the Monday brief that goes out without anybody opening a page.
 *
 * The email is the one place aggregate figures leave the platform, so these
 * tests cover the four things that would make it wrong rather than merely
 * ugly:
 *
 *   A. THE CALENDAR — an ISO week key and a Monday–Sunday span that do not
 *      shift with the server's timezone, and a due check that is true on Monday
 *      and false on every other day.
 *   B. THE CONTRACT — the brief quotes the SAME aggregate calls the AI tools
 *      use, and carries no customer name, phone number, email or payment
 *      reference. This is asserted over the rendered text, not over the class's
 *      intentions.
 *   C. THE RECIPIENTS — super admins only (the ENUM comparison that selects them
 *      is easy to get wrong), an env override that drops invalid addresses, and
 *      a manual run that ignores the calendar while a scheduled one obeys it.
 *   D. THE SEND — one week is stamped only when the transport accepted the
 *      message, and a queued row lands in tbl_mail_logs with the brief in it.
 *   E. THE COMPARISON — every figure shows its signed change against the most
 *      recent snapshot before its own week: counts, KSh, percentage points,
 *      "not comparable" for a missing key, and no change column at all when
 *      there is no baseline. Week keys are compared as calendar weeks, so a
 *      resend inside a week never becomes its own baseline.
 *   F. THE TOOL'S VIEW — movement() gives the operator's AI tool the same
 *      fourteen figures and the same signs as the email, in their own units; a
 *      missing baseline is reported as unavailable rather than as zero change,
 *      and one account's history never becomes another account's baseline.
 *
 * The tests that write (D, E, F) remove their outbox rows, preference and
 * snapshot rows, so the suite leaves the database as it found it.
 */
final class BusinessPulseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/models.php';
        require_once __DIR__ . '/../admin/include/OpsPulse.php';
        require_once __DIR__ . '/../admin/include/BusinessPulse.php';

        global $db;
        if (!($db ?? null) instanceof Database) {
            $db = new Database();
        }
    }

    // ==================================================================
    // A. The calendar
    // ==================================================================

    #[Group('pulse')]
    public function testWeekKeyRangeAndDueDay(): void
    {
        self::assertSame('2026-W43', BusinessPulse::week_key('2026-10-20'));

        $range = BusinessPulse::week_range('2026-10-20'); // a Tuesday
        self::assertSame('2026-10-19', $range['start'], 'the week starts on Monday');
        self::assertSame('2026-10-25', $range['end']);
        self::assertSame('19–25 Oct 2026', $range['label']);

        self::assertTrue(BusinessPulse::is_due('2026-10-19'), 'Monday is the send day');
        self::assertFalse(BusinessPulse::is_due('2026-10-20'));
        self::assertFalse(BusinessPulse::is_due('2026-10-25'), 'Sunday is not Monday');
        // ISO years are not calendar years: the Friday 1 Jan 2027 still belongs
        // to 2026's last week, and the Monday after starts 2027's first one.
        self::assertSame('2026-W53', BusinessPulse::week_key('2027-01-01'));
        self::assertSame('2027-W01', BusinessPulse::week_key('2027-01-04'));
    }

    // ==================================================================
    // B. The contract
    // ==================================================================

    #[Group('pulse')]
    public function testBriefQuotesTheSameAggregatesTheToolsReturn(): void
    {
        $brief    = BusinessPulse::build(0, '2026-10-20');
        $snapshot = OpsPulse::snapshot(0);
        $insights = OpsPulse::insights('', 100, 0);

        self::assertStringContainsString('week 43', $brief['subject']);
        self::assertStringContainsString('19–25 Oct 2026', $brief['subject']);
        self::assertSame('2026-W43', $brief['week']);
        self::assertSame((int) $insights['finding_count'], $brief['findings']);

        foreach ([
            'EVERAFTER PLANNER — WEEKLY BUSINESS PULSE',
            'THE WEEK IN NUMBERS',
            'WHAT NEEDS ATTENTION',
            'WHAT I WOULD FIX FIRST (suggestions, not facts)',
            'WHAT THE DATA CANNOT SEE',
            'THRESHOLDS USED',
            'Business Pulse:',
        ] as $heading) {
            self::assertStringContainsString($heading, $brief['text']);
        }

        // The figures, exactly as the snapshot records them.
        self::assertStringContainsString((string) $snapshot['weddings']['upcoming'], $brief['text']);
        self::assertStringContainsString('KSh ' . number_format((float) $snapshot['money']['package_value_upcoming']), $brief['text']);
        self::assertStringContainsString($snapshot['guests']['rsvp_answered'] . ' of ' . $snapshot['guests']['total'] . ' guests', $brief['text']);
        self::assertStringContainsString('soon ' . $insights['thresholds']['soon_days'] . 'd', $brief['text']);

        // Every finding returned is in the email (up to the phone-sized cap).
        foreach (array_slice($insights['findings'], 0, BusinessPulse::MAX_FINDINGS) as $finding) {
            self::assertStringContainsString((string) $finding['title'], $brief['text']);
            self::assertStringContainsString((string) $finding['link'], $brief['text']);
        }
    }

    #[Group('pulse')]
    public function testBriefCarriesNoCustomerDetail(): void
    {
        $brief = BusinessPulse::build(0, '2026-10-20');
        $this->assertNoContactDetails($brief['text']);

        // The names on the bookings must not be in it — the whole point of the
        // aggregate tools is that the email can be read on a phone in a taxi.
        $rows = Database::read_handle()->fetchAll(
            'SELECT bride, groom FROM tblweddingbook WHERE bride <> \'\' OR groom <> \'\' LIMIT 20'
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = (array) $row;
            foreach (['bride', 'groom'] as $key) {
                $name = trim((string) ($row[$key] ?? ''));
                if ($name !== '') {
                    self::assertStringNotContainsStringIgnoringCase($name, $brief['text'], 'a couple name reached the email');
                }
            }
        }
    }

    // ==================================================================
    // C. The recipients
    // ==================================================================

    #[Group('pulse')]
    public function testRecipientsAreSuperAdminsOnly(): void
    {
        $recipients = BusinessPulse::recipients();
        self::assertNotSame([], $recipients, 'the seeded install has a super admin with an address');

        foreach ($recipients as $recipient) {
            self::assertNotSame('', $recipient['email']);
            self::assertTrue((bool) filter_var($recipient['email'], FILTER_VALIDATE_EMAIL));
            $level = Database::read_handle()->fetchValue(
                'SELECT access_level FROM tblusers WHERE id = ' . (int) $recipient['user_id']
            );
            // access_level is ENUM('0','1','2'); compare as the string it is.
            self::assertSame('2', (string) $level, 'only a super admin may receive the platform brief');
        }
    }

    #[Group('pulse')]
    public function testEnvOverrideWinsAndDropsInvalidAddresses(): void
    {
        putenv(BusinessPulse::RECIPIENTS_ENV . '=owner@example.com, not-an-address, second@example.com');
        try {
            $recipients = BusinessPulse::recipients();
        } finally {
            putenv(BusinessPulse::RECIPIENTS_ENV);
        }

        self::assertCount(2, $recipients);
        self::assertSame(['owner@example.com', 'second@example.com'], array_column($recipients, 'email'));
        foreach ($recipients as $recipient) {
            self::assertSame(0, $recipient['user_id'], 'a mailbox has no thresholds of its own');
        }
    }

    #[Group('pulse')]
    public function testScheduledRunObeysMondayAndTheWeekStamp(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid        = 990010;
        $recipients = [['user_id' => $uid, 'email' => 'owner@example.com', 'name' => 'Owner']];
        UserPrefs::forget($uid, UserPrefs::PULSE_WEEK); // a failed earlier run must not poison this one

        self::assertCount(1, BusinessPulse::due_recipients(false, '2026-10-19', $recipients), 'Monday sends');
        self::assertCount(0, BusinessPulse::due_recipients(false, '2026-10-20', $recipients), 'Tuesday does not');
        self::assertCount(1, BusinessPulse::due_recipients(true, '2026-10-20', $recipients), 'a manual run ignores the day');

        self::assertTrue(BusinessPulse::mark_sent($uid, '2026-W43'));
        self::assertTrue(BusinessPulse::already_sent($uid, '2026-W43'));
        self::assertSame('2026-W43', BusinessPulse::last_sent($uid));
        self::assertCount(0, BusinessPulse::due_recipients(false, '2026-10-19', $recipients), 'one brief per owner per week');
        self::assertCount(1, BusinessPulse::due_recipients(false, '2026-10-26', $recipients), 'the next Monday is a new week');

        UserPrefs::forget($uid, UserPrefs::PULSE_WEEK);
        self::assertFalse(BusinessPulse::already_sent($uid, '2026-W43'));
        self::assertCount(1, BusinessPulse::due_recipients(false, '2026-10-19', $recipients));
    }

    #[Group('pulse')]
    public function testEachOwnerGetsTheirOwnThresholds(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid = 990012;
        $saved = OpsPulse::save_settings($uid, [
            'soon_days'       => 21,
            'watch_days'      => 90,
            'deal_stale_days' => 10,
            'planner_load'    => 4,
            'renewal_days'    => 30,
            'capacity_share'  => 20,
        ]);
        self::assertTrue($saved['ok']);

        $brief = BusinessPulse::build($uid, '2026-10-20');
        self::assertStringContainsString('soon 21d', $brief['text']);
        self::assertStringContainsString('capacity 20%', $brief['text']);

        UserPrefs::forget($uid, UserPrefs::OPS_THRESHOLDS);
        self::assertStringContainsString('soon ' . OpsPulse::DEFAULT_SETTINGS['soon_days'] . 'd', BusinessPulse::build($uid, '2026-10-20')['text']);
    }

    // ==================================================================
    // D. The send — queued by the outbox, stamped only when accepted
    // ==================================================================

    #[Group('pulse')]
    public function testSendQueuesInTheOutboxAndStampsTheWeek(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid   = 990013;
        $email = 'business-pulse-test@example.com';

        // Transport is off in tests, so the expected status is 'pending': the
        // brief is accepted by the outbox, which is exactly what stamps the week.
        try {
            $sent = BusinessPulse::send($uid, $email, '2026-10-20');

            self::assertTrue($sent['ok'], (string) $sent['note']);
            self::assertSame('pending', $sent['status']);
            self::assertSame('2026-W43', $sent['week']);
            self::assertStringContainsString('WEEKLY BUSINESS PULSE', $sent['text']);
            self::assertSame('2026-W43', BusinessPulse::last_sent($uid));

            $row = Database::read_handle()->fetchOne(
                'SELECT to_email, subject, body, status FROM tbl_mail_logs WHERE to_email = '
                . Database::read_handle()->quote($email) . ' ORDER BY id DESC LIMIT 1'
            );
            self::assertNotNull($row, 'the brief must be in the outbox');
            self::assertSame('pending', (string) $row->status);
            self::assertStringContainsString('WEEKLY BUSINESS PULSE', (string) $row->body);
            self::assertStringContainsString('week 43', (string) $row->subject);
            $this->assertNoContactDetails((string) $row->body);
        } finally {
            Database::read_handle()->execute(
                'DELETE FROM tbl_mail_logs WHERE to_email = :email',
                [':email' => $email]
            );
            UserPrefs::forget($uid, UserPrefs::PULSE_WEEK);
            self::forgetSnapshots($uid);
        }
    }

    #[Group('pulse')]
    public function testSendRefusesAnAddressItCannotUse(): void
    {
        $uid  = 990014;
        $sent = BusinessPulse::send($uid, 'not-an-address', '2026-10-20');

        self::assertFalse($sent['ok']);
        self::assertSame('skipped', $sent['status']);
        self::assertSame('', BusinessPulse::last_sent($uid), 'a refused send must not stamp the week');
    }

    #[Group('pulse')]
    public function testTheJobIsRegisteredWithTheWatchdog(): void
    {
        self::assertArrayHasKey(JobWatch::BUSINESS_PULSE, JobWatch::JOBS);
        self::assertSame(86400, JobWatch::JOBS[JobWatch::BUSINESS_PULSE]['every']);
        self::assertSame('Weekly business pulse', JobWatch::JOBS[JobWatch::BUSINESS_PULSE]['label']);

        $report = JobWatch::report();
        self::assertArrayHasKey(JobWatch::BUSINESS_PULSE, $report['jobs']);
    }

    // ==================================================================
    // E. The comparison — each figure against last week's snapshot
    // ==================================================================

    #[Group('pulse')]
    public function testEveryFigureCarriesItsSignedChange(): void
    {
        $this->requireHistory();
        $uid = 990020;
        self::forgetSnapshots($uid);

        $current = OpsPulse::snapshot($uid);
        $previous = $current;
        // Deliberately mutated AWAY from what the test expects the delta to be,
        // so the sign is exercised in both directions and cannot pass by luck.
        $previous['weddings']['upcoming']     = (int) $current['weddings']['upcoming'] + 5;
        $previous['weddings']['awaiting_confirmation'] = (int) $current['weddings']['awaiting_confirmation'];
        $previous['money']['collected_upcoming'] = (float) $current['money']['collected_upcoming'] - 95000;
        $previous['tasks']['overdue']         = (int) $current['tasks']['overdue'] - 12;
        $previous['guests']['rsvp_response_rate'] = (float) $current['guests']['rsvp_response_rate'] + 0.021;
        $previous['operations']['jobs_troubled'] = (int) $current['operations']['jobs_troubled'] + 1;

        try {
            self::assertTrue(BusinessPulse::save_snapshot($uid, $previous, '2026-W42'));
            $brief = BusinessPulse::build($uid, '2026-10-20'); // week 43

            self::assertSame('2026-W42', $brief['compared_week'], 'the most recent week before this one is the baseline');
            self::assertStringContainsString('Change since week 42 (12–18 Oct 2026)', $brief['text']);
            self::assertStringContainsString('money in KSh', $brief['text']);
            self::assertStringContainsString('percentage points', $brief['text']);

            // Signed in both directions, and each unit formatted as the brief
            // prints it: a count, KSh without the prefix, and points.
            self::assertMatchesRegularExpression('/\s-5\s/', $this->rowLine($brief['text'], 'Weddings upcoming'));
            self::assertMatchesRegularExpression('/\s0\s/', $this->rowLine($brief['text'], 'Couple accounts pending'));
            self::assertStringContainsString(' +95,000 ', $this->rowLine($brief['text'], 'Collected'));
            self::assertStringContainsString(' +12 ', $this->rowLine($brief['text'], 'Overdue tasks'));
            self::assertStringContainsString(' -2.1pp ', $this->rowLine($brief['text'], 'RSVP response'));
            self::assertMatchesRegularExpression('/\s-1\s/', $this->rowLine($brief['text'], 'Jobs troubled'));

            $this->assertNoContactDetails($brief['text']);
        } finally {
            self::forgetSnapshots($uid);
        }
    }

    #[Group('pulse')]
    public function testNoBaselineMeansNoChangeColumnAndSaysWhy(): void
    {
        $this->requireHistory();
        $uid = 990021;
        self::forgetSnapshots($uid);

        $brief = BusinessPulse::build($uid, '2026-10-20');

        self::assertNull($brief['compared_week']);
        self::assertStringContainsString('No earlier snapshot is on record yet', $brief['text']);
        self::assertStringNotContainsString('Change since week', $brief['text']);
        // A zero would claim "nothing changed" where the truth is "nothing to
        // compare against"; the cell is absent, not zeroed.
        $collected = $this->rowLine($brief['text'], 'Collected');
        self::assertNotSame('0', trim(substr($collected, 44, 8)));
    }

    #[Group('pulse')]
    public function testEveryFigureGetsTheChangeCellEvenWhenNothingMoved(): void
    {
        $this->requireHistory();
        $uid = 990027;
        self::forgetSnapshots($uid);

        $current = OpsPulse::snapshot($uid);
        try {
            self::assertTrue(BusinessPulse::save_snapshot($uid, $current, '2026-W42'));
            $brief = BusinessPulse::build($uid, '2026-10-20');

            // Every row has the same 8-char change cell between its 14-char
            // value and its note. With an unchanged baseline the cell is '0'
            // ('0pp' for the one rate) for ALL fourteen figures — this is the
            // "each figure" claim, not just the six with signs above.
            $expected = [
                'Weddings upcoming' => '0', 'Couple accounts pending' => '0', 'No planner assigned' => '0',
                'Package value (upcoming)' => '0', 'Collected' => '0', 'Outstanding' => '0',
                'Subscription MRR' => '0', 'Renewals due' => '0', 'RSVP response' => '0pp',
                'Overdue tasks' => '0', 'Vendor deals' => '0', 'AI calls (7 days)' => '0',
                'Approvals waiting' => '0', 'Jobs troubled' => '0',
            ];
            foreach ($expected as $label => $cell) {
                self::assertSame(
                    $cell,
                    trim(substr($this->rowLine($brief['text'], $label), 44, 8)),
                    $label . ' must carry its change since week 42'
                );
            }
        } finally {
            self::forgetSnapshots($uid);
        }
    }

    #[Group('pulse')]
    public function testBaselineIsTheLastCompletedWeekAndNeverItself(): void
    {
        $this->requireHistory();
        $uid = 990022;
        self::forgetSnapshots($uid);

        try {
            $snapshot = OpsPulse::snapshot($uid);
            foreach (['2026-W41' => 11, '2026-W42' => 42, '2026-W43' => 43] as $week => $stamp) {
                $copy = $snapshot;
                $copy['weddings']['total'] = $stamp;
                self::assertTrue(BusinessPulse::save_snapshot($uid, $copy, $week));
            }

            // Building IN week 43 compares against week 42, not against the row
            // written moments ago for the same week (a resend).
            self::assertSame('2026-W42', BusinessPulse::baseline($uid, '2026-W43')['week']);
            self::assertSame(42, BusinessPulse::baseline($uid, '2026-W43')['snapshot']['weddings']['total']);

            // Monday of week 44 compares against week 43.
            self::assertSame('2026-W43', BusinessPulse::baseline($uid, '2026-W44')['week']);
            self::assertSame(43, BusinessPulse::baseline($uid, '2026-W44')['snapshot']['weddings']['total']);

            // A resend inside week 43 REPLACES the row: still one, still not a baseline.
            $copy = $snapshot;
            $copy['weddings']['total'] = 430;
            self::assertTrue(BusinessPulse::save_snapshot($uid, $copy, '2026-W43'));
            self::assertSame(1, $this->snapshotRows($uid, '2026-W43'));
            self::assertSame(430, BusinessPulse::baseline($uid, '2026-W44')['snapshot']['weddings']['total']);
            self::assertSame('2026-W42', BusinessPulse::baseline($uid, '2026-W43')['week']);

            // ISO ordering across a year boundary, in its own scope so the
            // pruning in the writer cannot remove the weeks above: 2026-W53 is
            // the week before 2027-W01.
            self::assertTrue(BusinessPulse::save_snapshot(990028, $snapshot, '2026-W53'));
            self::assertSame('2026-W53', BusinessPulse::baseline(990028, '2027-W01')['week']);
        } finally {
            self::forgetSnapshots($uid);
            self::forgetSnapshots(990028);
        }
    }

    #[Group('pulse')]
    public function testMalformedHistoryIsIgnoredRatherThanHalfRead(): void
    {
        $this->requireHistory();
        $uid = 990023;
        self::forgetSnapshots($uid);

        try {
            Database::read_handle()->execute(
                'INSERT INTO tblops_snapshots (scope_id, iso_week, payload) VALUES (:s, :w, :p)',
                [':s' => $uid, ':w' => '2026-W42', ':p' => '{"oops":true}']
            );
            self::assertNull(BusinessPulse::baseline($uid, '2026-W43'));

            $brief = BusinessPulse::build($uid, '2026-10-20');
            self::assertNull($brief['compared_week']);
            self::assertStringContainsString('No earlier snapshot is on record yet', $brief['text']);
        } finally {
            self::forgetSnapshots($uid);
        }
    }

    #[Group('pulse')]
    public function testHistoryWriterRefusesBadInputAndPrunesOldWeeks(): void
    {
        $this->requireHistory();
        $uid = 990024;
        self::forgetSnapshots($uid);

        try {
            $snapshot = OpsPulse::snapshot($uid);
            self::assertFalse(BusinessPulse::save_snapshot($uid, $snapshot, '43-2026'), 'a week key is not free text');
            self::assertFalse(BusinessPulse::save_snapshot(-1, $snapshot, '2026-W43'));
            self::assertSame(0, $this->snapshotRows($uid));

            Database::read_handle()->execute(
                'INSERT INTO tblops_snapshots (scope_id, iso_week, payload) VALUES (:s, :w, :p)',
                [':s' => $uid, ':w' => '2020-W01', ':p' => json_encode($snapshot)]
            );
            self::assertTrue(BusinessPulse::save_snapshot($uid, $snapshot, '2026-W43'));
            self::assertSame(1, $this->snapshotRows($uid), 'a whole quarter is kept, not forever');
            self::assertSame(0, $this->snapshotRows($uid, '2020-W01'));
        } finally {
            self::forgetSnapshots($uid);
        }
    }

    #[Group('pulse')]
    public function testSendRecordsTheSnapshotForNextWeekAndOnlyAfterAcceptance(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $this->requireHistory();
        $uid   = 990025;
        $email = 'business-pulse-compare@example.com';
        self::forgetSnapshots($uid);
        self::forgetSnapshots(990026);

        try {
            $sent = BusinessPulse::send($uid, $email, '2026-10-20');
            self::assertTrue($sent['ok'], (string) $sent['note']);
            self::assertSame(1, $this->snapshotRows($uid, '2026-W43'), 'an accepted send becomes next week\'s baseline');

            // Next Monday compares against it, without re-reading this week's.
            $next = BusinessPulse::baseline($uid, '2026-W44');
            self::assertSame('2026-W43', $next['week']);
            self::assertSame(
                OpsPulse::snapshot($uid)['weddings']['upcoming'],
                $next['snapshot']['weddings']['upcoming']
            );

            // A refused address writes nothing: no mail row, no baseline.
            self::assertFalse(BusinessPulse::send(990026, 'not-an-address', '2026-10-20')['ok']);
            self::assertSame(0, $this->snapshotRows(990026));
        } finally {
            Database::read_handle()->execute('DELETE FROM tbl_mail_logs WHERE to_email = :email', [':email' => $email]);
            UserPrefs::forget($uid, UserPrefs::PULSE_WEEK);
            self::forgetSnapshots($uid);
            self::forgetSnapshots(990026);
        }
    }

    // ==================================================================
    // F. The tool's view — the same figures, for the assistant
    // ==================================================================

    #[Group('pulse')]
    public function testMovementGivesTheToolsTheSameChangesAsTheEmail(): void
    {
        $this->requireHistory();
        $uid = 990030;
        self::forgetSnapshots($uid);

        $current  = OpsPulse::snapshot($uid);
        $previous = $current;
        $previous['weddings']['upcoming']            = (int) $current['weddings']['upcoming'] + 5;
        $previous['money']['collected_upcoming']     = (float) $current['money']['collected_upcoming'] - 95000;
        $previous['guests']['rsvp_response_rate']    = (float) $current['guests']['rsvp_response_rate'] + 0.021;
        $previous['operations']['jobs_troubled']     = (int) $current['operations']['jobs_troubled'] + 1;

        try {
            self::assertTrue(BusinessPulse::save_snapshot($uid, $previous, '2026-W42'));
            $movement = BusinessPulse::movement($uid, $current, '2026-10-20');

            self::assertTrue($movement['available']);
            self::assertSame('2026-W43', $movement['week']);
            self::assertSame('2026-W42', $movement['compared_week']);
            self::assertSame('12–18 Oct 2026', $movement['compared_range']);
            self::assertSame([], $movement['not_comparable']);

            // The tool and the brief read ONE metric table: same fourteen
            // figures, same order, and no second list to drift.
            self::assertSame(
                array_column(BusinessPulse::METRICS, 'key'),
                array_column($movement['metrics'], 'metric')
            );
            $byKey = array_column($movement['metrics'], null, 'metric');
            self::assertSame(-5, $byKey['weddings_upcoming']['change']);
            self::assertSame('count', $byKey['weddings_upcoming']['unit']);
            self::assertEqualsWithDelta(95000, $byKey['collected_upcoming']['change'], 0.001);
            self::assertSame('KSh', $byKey['collected_upcoming']['unit']);
            self::assertSame('ratio', $byKey['rsvp_response_rate']['unit']);
            self::assertEqualsWithDelta(-2.1, $byKey['rsvp_response_rate']['change_pp'], 0.001);
            self::assertEqualsWithDelta(-0.021, $byKey['rsvp_response_rate']['change'], 0.0001);
            self::assertSame(-1, $byKey['jobs_troubled']['change']);

            // "Same figures" is not a slogan: the email prints the same change
            // against the same week the tool names.
            $brief = BusinessPulse::build($uid, '2026-10-20');
            self::assertSame($movement['compared_week'], $brief['compared_week']);
            self::assertStringContainsString(' +95,000 ', $this->rowLine($brief['text'], 'Collected'));
        } finally {
            self::forgetSnapshots($uid);
        }
    }

    #[Group('pulse')]
    public function testMovementIsUnavailableRatherThanZeroWhenThereIsNoBaseline(): void
    {
        $this->requireHistory();
        $uid   = 990031;
        $other = 990032;
        self::forgetSnapshots($uid);
        self::forgetSnapshots($other);

        try {
            $current = OpsPulse::snapshot($uid);

            // Another account's history is not this account's baseline.
            self::assertTrue(BusinessPulse::save_snapshot($other, $current, '2026-W42'));

            $movement = BusinessPulse::movement($uid, $current, '2026-10-20');
            self::assertFalse($movement['available']);
            self::assertNull($movement['compared_week']);
            self::assertSame([], $movement['metrics']);
            self::assertStringContainsString('No weekly business-pulse snapshot exists before this week', $movement['note']);

            self::assertTrue(BusinessPulse::movement($other, $current, '2026-10-20')['available']);
        } finally {
            self::forgetSnapshots($uid);
            self::forgetSnapshots($other);
        }
    }

    #[Group('pulse')]
    public function testAMetricMissingFromAnOlderSnapshotIsNamedNotZeroed(): void
    {
        $this->requireHistory();
        $uid = 990033;
        self::forgetSnapshots($uid);

        try {
            $current  = OpsPulse::snapshot($uid);
            $previous = $current;
            unset($previous['operations']['jobs_troubled']);
            self::assertTrue(BusinessPulse::save_snapshot($uid, $previous, '2026-W42'));

            $movement = BusinessPulse::movement($uid, $current, '2026-10-20');
            self::assertContains('jobs_troubled', $movement['not_comparable']);
            self::assertNotContains('jobs_troubled', array_column($movement['metrics'], 'metric'));
            self::assertCount(count(BusinessPulse::METRICS) - 1, $movement['metrics']);
        } finally {
            self::forgetSnapshots($uid);
        }
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** The history table is optional (v2.23); a database without it skips E. */
    private function requireHistory(): void
    {
        if (!BusinessPulse::history_available()) {
            self::markTestSkipped('tblops_snapshots is not installed — run migrations/v2.23_business_pulse_snapshots.sql.');
        }
    }

    /** One numbers row, found by its label (which is padded to 26). */
    private function rowLine(string $text, string $label): string
    {
        $prefix = '  ' . str_pad($label, 26);
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }
        self::fail('the brief has no row labelled ' . $label);
    }

    /** How many snapshots this scope has, optionally for one week. */
    private function snapshotRows(int $scopeId, ?string $week = null): int
    {
        $sql    = 'SELECT COUNT(*) FROM tblops_snapshots WHERE scope_id = :s';
        $params = [':s' => $scopeId];
        if ($week !== null) {
            $sql .= ' AND iso_week = :w';
            $params[':w'] = $week;
        }
        return (int) Database::read_handle()->fetchValue($sql, $params);
    }

    /** Remove one test scope's history, whatever a failed earlier run left. */
    private static function forgetSnapshots(int $scopeId): void
    {
        if (!BusinessPulse::history_available()) {
            return;
        }
        Database::read_handle()->execute('DELETE FROM tblops_snapshots WHERE scope_id = :s', [':s' => $scopeId]);
    }

    /** No email address, phone number or M-Pesa code may appear in the brief. */
    private function assertNoContactDetails(string $text): void
    {
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/', $text, 'an email address reached the brief');
        self::assertDoesNotMatchRegularExpression('/\+254\d{9}/', $text, 'a phone number reached the brief');
        self::assertDoesNotMatchRegularExpression('/\b0[17]\d{8}\b/', $text, 'a phone number reached the brief');
        self::assertDoesNotMatchRegularExpression('/\b[A-Z]{3}\d{6,}[A-Z0-9]*\b/', $text, 'an M-Pesa code reached the brief');
    }
}
