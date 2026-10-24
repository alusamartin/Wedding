<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OpsPulse — the operator's aggregate statistics and rule-based findings.
 *
 * These tools are the most likely place for customer data to reach a language
 * model, so the tests are as much about the PRIVACY CONTRACT as about the
 * arithmetic:
 *
 *   A. CONSISTENCY — the snapshot's totals add up (upcoming + past = total),
 *      the windows nest, the money identity holds, and a handful of figures are
 *      checked against the database directly.
 *   B. NO PERSONAL DATA — nothing in the snapshot or a finding names a person,
 *      a phone number, an email or a dietary note. This is asserted over keys
 *      and values, so a future contributor cannot quietly add `bride` to a
 *      query and have the test pass.
 *   C. FINDINGS ARE RULES, NOT PROSE — each one has a known severity, the
 *      numbers that fired it, a page to act on, and is sorted worst-first.
 *      Filtering and the limit behave; an unknown severity is refused.
 *   D. ACCESS — both tools are offered to a super admin carrying
 *      `admin:system`, and to nobody else, whatever scope a token claims.
 *   E. MOVEMENT — `system_status` carries the operator's week-over-week block
 *      from the Monday brief's snapshot history: the same fourteen figures, a
 *      stated "no baseline" instead of zero change, and no customer detail in
 *      the new payload.
 *
 * Fixtures: one test writes a snapshot row for its own scratch account and
 * removes it; the rest of the suite reads the seeded test database.
 */
final class OpsPulseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/models.php';
        require_once __DIR__ . '/../admin/include/OpsPulse.php';
        require_once __DIR__ . '/../admin/include/BusinessPulse.php'; // movement, from the Monday brief's history
        require_once __DIR__ . '/../admin/include/McpWrites.php';
        require_once __DIR__ . '/../admin/include/McpTools.php';
        require_once __DIR__ . '/../admin/include/McpPrompts.php';

        global $db;
        if (!($db ?? null) instanceof Database) {
            $db = new Database();
        }
    }

    // ==================================================================
    // A. Consistency
    // ==================================================================

    #[Group('ops')]
    public function testSnapshotTotalsAddUpAndWindowsNest(): void
    {
        $snapshot = OpsPulse::snapshot();
        $weddings = $snapshot['weddings'];

        self::assertSame(
            $weddings['total'],
            $weddings['upcoming'] + $weddings['past'],
            'every wedding is either upcoming or past'
        );
        self::assertLessThanOrEqual($weddings['upcoming'], $weddings['next_90_days']);
        self::assertLessThanOrEqual($weddings['next_90_days'], $weddings['next_45_days']);
        self::assertSame('KSh', $snapshot['currency']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $snapshot['generated_at']);
    }

    #[Group('ops')]
    public function testSnapshotMatchesTheDatabase(): void
    {
        global $db;
        $snapshot = OpsPulse::snapshot();

        self::assertSame(
            (int) $db->fetchValue('SELECT COUNT(*) FROM tblweddingbook'),
            $snapshot['weddings']['total']
        );
        self::assertSame(
            (int) $db->fetchValue('SELECT COUNT(*) FROM tblguest'),
            $snapshot['guests']['total']
        );
        self::assertSame(
            (int) $db->fetchValue('SELECT COUNT(*) FROM tblvendors WHERE is_approved = 1'),
            $snapshot['vendors']['approved']
        );
    }

    #[Group('ops')]
    public function testMoneyIdentityHolds(): void
    {
        $money = OpsPulse::snapshot()['money'];

        foreach ($money as $key => $value) {
            self::assertIsNumeric($value, $key . ' must be numeric');
            self::assertGreaterThanOrEqual(0, $value, $key . ' cannot be negative');
        }
        self::assertEqualsWithDelta(
            max(0, $money['package_value_upcoming'] - $money['collected_upcoming']),
            $money['outstanding_upcoming'],
            0.01,
            'outstanding is what the package price still owes'
        );
    }

    #[Group('ops')]
    public function testRsvpRateIsABoundedRatio(): void
    {
        $guests = OpsPulse::snapshot()['guests'];

        self::assertGreaterThanOrEqual(0, $guests['rsvp_response_rate']);
        self::assertLessThanOrEqual(1, $guests['rsvp_response_rate']);
        self::assertSame(
            $guests['rsvp_answered'] + $guests['rsvp_pending'],
            $guests['total'],
            'every guest is answered or pending'
        );
    }

    // ==================================================================
    // B. No personal data
    // ==================================================================

    #[Group('ops')]
    public function testSnapshotCarriesNoPersonalData(): void
    {
        $forbidden = ['bride', 'groom', 'name', 'fullname', 'guest_name', 'phone', 'email',
                      'dietary', 'dietary_requirements', 'business_name', 'rsvp_note', 'mpesa_code'];

        foreach ($this->leafKeys(OpsPulse::snapshot()) as $key) {
            self::assertNotContains(
                strtolower($key),
                $forbidden,
                'OpsPulse must never return the field "' . $key . '"'
            );
        }
        $this->assertNoContactDetails(OpsPulse::snapshot());
    }

    #[Group('ops')]
    public function testFindingsCarryNoPersonalData(): void
    {
        $forbidden = ['bride', 'groom', 'name', 'fullname', 'phone', 'email', 'dietary', 'business_name'];
        foreach (OpsPulse::insights()['findings'] as $finding) {
            foreach ($this->leafKeys($finding) as $key) {
                self::assertNotContains(strtolower($key), $forbidden);
            }
        }
        $this->assertNoContactDetails(OpsPulse::insights());
    }

    // ==================================================================
    // C. Findings are rules, not prose
    // ==================================================================

    #[Group('ops')]
    public function testFindingsAreWellFormedAndSortedWorstFirst(): void
    {
        $result = OpsPulse::insights();
        $rank   = array_flip(OpsPulse::SEVERITIES);
        $last   = -1;

        self::assertSame(count($result['findings']), $result['finding_count']);
        self::assertSame(array_sum($result['by_severity']), $result['finding_count']);

        foreach ($result['findings'] as $finding) {
            self::assertContains($finding['severity'], OpsPulse::SEVERITIES);
            self::assertGreaterThan(0, $finding['count'], $finding['code'] . ' fired without a count');
            self::assertNotSame('', trim((string) $finding['title']));
            self::assertNotSame('', trim((string) $finding['detail']));
            self::assertNotSame('', trim((string) $finding['suggested_action']));
            self::assertMatchesRegularExpression('/^[a-z_]+\.php$/', (string) $finding['link']);
            self::assertIsArray($finding['evidence']['subjects']);

            $current = $rank[$finding['severity']];
            self::assertGreaterThanOrEqual($last, $current, 'findings must be worst-first');
            $last = $current;
        }
    }

    #[Group('ops')]
    public function testSeverityFilterAndLimit(): void
    {
        $high = OpsPulse::insights('high', 100);
        foreach ($high['findings'] as $finding) {
            self::assertSame('high', $finding['severity']);
        }
        self::assertLessThanOrEqual($high['by_severity']['high'], count($high['findings']));

        $limited = OpsPulse::insights('', 1);
        self::assertLessThanOrEqual(1, count($limited['findings']));

        // An unknown severity is ignored rather than silently producing nothing.
        self::assertSame(
            OpsPulse::insights()['finding_count'],
            OpsPulse::insights('not-a-severity')['finding_count']
        );
    }

    // ==================================================================
    // E. Tuning and mutes — per account, validated on the way in and out
    // ==================================================================

    #[Group('ops')]
    public function testThresholdsRoundTripAndAreValidatedWholesale(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid = 990001;

        $saved = OpsPulse::save_settings($uid, [
            'soon_days'       => 30,
            'watch_days'      => 90,
            'deal_stale_days' => 10,
            'planner_load'    => 4,
            'renewal_days'    => 21,
            'capacity_share'  => 20,
        ]);
        self::assertTrue($saved['ok'], 'a valid set saves');
        self::assertSame([], $saved['errors']);

        $settings = OpsPulse::settings($uid);
        self::assertSame(30, $settings['soon_days']);
        self::assertSame(90, $settings['watch_days']);
        self::assertSame(20, $settings['capacity_share']);

        // One bad field refuses the WHOLE save, and nothing is written.
        $refused = OpsPulse::save_settings($uid, [
            'soon_days'       => 1,      // below the range
            'watch_days'      => 90,
            'deal_stale_days' => 10,
            'planner_load'    => 4,
            'renewal_days'    => 21,
            'capacity_share'  => 15,     // not an offered width
        ]);
        self::assertFalse($refused['ok']);
        self::assertArrayHasKey('soon_days', $refused['errors']);
        self::assertArrayHasKey('capacity_share', $refused['errors']);
        self::assertSame(30, OpsPulse::settings($uid)['soon_days'], 'a refused save changes nothing');

        // A non-numeric field is an error, not a zero.
        self::assertFalse(OpsPulse::save_settings($uid, ['soon_days' => 'soon'])['ok']);
        self::assertSame(30, OpsPulse::settings($uid)['soon_days']);

        UserPrefs::forget($uid, UserPrefs::OPS_THRESHOLDS);
        self::assertSame(
            OpsPulse::DEFAULT_SETTINGS['soon_days'],
            OpsPulse::settings($uid)['soon_days'],
            'a forgotten preference reads as the default again'
        );
    }

    #[Group('ops')]
    public function testAStoredValueOutsideTheRangeReadsAsTheDefault(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid = 990004;

        // Hand-edited / older-build rows: an out-of-range window and a width that
        // is not offered. Both must read as the default rather than widening a
        // query (and the mute mask must be clamped to known bits).
        UserPrefs::remember($uid, UserPrefs::OPS_THRESHOLDS, (string) json_encode([
            'soon_days' => 100000,
            'capacity_share' => 47,
        ]));
        UserPrefs::remember($uid, UserPrefs::OPS_MUTED, '999999');

        $settings = OpsPulse::settings($uid);
        self::assertSame(OpsPulse::DEFAULT_SETTINGS['soon_days'], $settings['soon_days']);
        self::assertSame(OpsPulse::DEFAULT_SETTINGS['capacity_share'], $settings['capacity_share']);
        self::assertSame(0, $settings['muted_mask'] & ~4095, 'unknown bits are dropped');

        UserPrefs::forget($uid, UserPrefs::OPS_THRESHOLDS);
        UserPrefs::forget($uid, UserPrefs::OPS_MUTED);
    }

    #[Group('ops')]
    public function testMutingHidesAFindingFromTheToolsAndUnmuteRestoresIt(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid  = 990002;
        $code = 'renewals_soon';

        self::assertTrue(OpsPulse::mute($uid, $code));
        self::assertContains($code, OpsPulse::settings($uid)['muted_codes']);
        self::assertFalse(OpsPulse::mute($uid, 'not_a_rule'), 'only a known rule can be muted');

        $muted = OpsPulse::insights('', 100, $uid);
        self::assertContains($code, $muted['muted_codes']);
        foreach ($muted['findings'] as $finding) {
            self::assertNotSame($code, $finding['code'], 'a muted rule is not returned as a finding');
        }
        foreach (OpsPulse::muted_findings($uid) as $finding) {
            self::assertSame($code, $finding['code'], 'the accepted finding stays reviewable');
        }

        // Another account is untouched: a mute is personal, never global.
        self::assertNotContains($code, OpsPulse::insights('', 100, 0)['muted_codes']);

        self::assertTrue(OpsPulse::unmute($uid, $code));
        self::assertNotContains($code, OpsPulse::settings($uid)['muted_codes']);
        self::assertSame([], OpsPulse::muted_findings($uid));

        UserPrefs::forget($uid, UserPrefs::OPS_MUTED);
    }

    #[Group('ops')]
    public function testTheToolsUseTheTokenOwnersThresholds(): void
    {
        if (!UserPrefs::available()) {
            self::markTestSkipped('tbluser_prefs is not installed (migrations/v2.12).');
        }
        $uid = 990003;

        $saved = OpsPulse::save_settings($uid, [
            'soon_days'       => 60,
            'watch_days'      => 90,
            'deal_stale_days' => 10,
            'planner_load'    => 4,
            'renewal_days'    => 21,
            'capacity_share'  => 20,
        ]);
        self::assertTrue($saved['ok']);

        $snapshot = OpsPulse::snapshot($uid);
        self::assertArrayHasKey('next_60_days', $snapshot['weddings'], 'the snapshot speaks the tuned window');
        self::assertSame(60, $snapshot['settings']['thresholds']['soon_days']);

        $principal = ['role' => 'super_admin', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => $uid];
        $status    = McpTools::call('system_status', [], $principal);
        self::assertSame(60, $status['data']['settings']['thresholds']['soon_days']);
        self::assertSame(20, $status['data']['settings']['thresholds']['capacity_share']);

        $findings = McpTools::call('list_insights', [], $principal);
        self::assertSame(60, $findings['data']['thresholds']['soon_days']);

        // Clean up so the defaults are what the next test and the next run see.
        UserPrefs::forget($uid, UserPrefs::OPS_THRESHOLDS);
        UserPrefs::forget($uid, UserPrefs::OPS_MUTED);
    }

    // ==================================================================
    // D. Access
    // ==================================================================

    #[Group('ops')]
    public function testSystemStatusCarriesTheSameWeekOverWeekMovement(): void
    {
        if (!BusinessPulse::history_available()) {
            self::markTestSkipped('tblops_snapshots is not installed — run migrations/v2.23_business_pulse_snapshots.sql.');
        }
        $uid   = 990040;
        $other = 990041;
        foreach ([$uid, $other] as $scope) {
            Database::read_handle()->execute('DELETE FROM tblops_snapshots WHERE scope_id = :s', [':s' => $scope]);
        }

        try {
            $principal = ['role' => 'super_admin', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => $uid];

            // With no brief delivered yet, the tool says so — zero would read
            // as a flat week, which is a different (and false) claim.
            $before = McpTools::call('system_status', [], $principal)['data']['movement'];
            self::assertFalse($before['available']);
            self::assertSame([], $before['metrics']);
            self::assertStringContainsString('No weekly business-pulse snapshot exists before this week', $before['note']);

            $previousWeek = BusinessPulse::week_key(
                (new DateTimeImmutable('-7 days', new DateTimeZone(BusinessPulse::TZ)))->format('Y-m-d')
            );
            self::assertTrue(BusinessPulse::save_snapshot($uid, OpsPulse::snapshot($uid), $previousWeek));

            $movement = McpTools::call('system_status', [], $principal)['data']['movement'];
            self::assertTrue($movement['available']);
            self::assertSame($previousWeek, $movement['compared_week']);
            self::assertCount(count(BusinessPulse::METRICS), $movement['metrics']);

            $byKey = array_column($movement['metrics'], null, 'metric');
            self::assertSame('count', $byKey['weddings_upcoming']['unit']);
            self::assertSame(0, $byKey['weddings_upcoming']['change'], 'the same snapshot means no change');

            // The new block is held to the same privacy contract as the rest.
            $json = (string) json_encode($movement);
            self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/', $json);
            self::assertDoesNotMatchRegularExpression('/\+254\d{9}/', $json);

            // Another owner is told there is no baseline of their own, rather
            // than being handed this account's figures.
            $otherPrincipal = ['role' => 'super_admin', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => $other];
            self::assertFalse(McpTools::call('system_status', [], $otherPrincipal)['data']['movement']['available']);
        } finally {
            foreach ([$uid, $other] as $scope) {
                Database::read_handle()->execute('DELETE FROM tblops_snapshots WHERE scope_id = :s', [':s' => $scope]);
            }
        }
    }

    #[Group('ops')]
    public function testOperatorToolsAreSuperAdminOnly(): void
    {
        $superAdmin = ['role' => 'super_admin', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => 1];

        self::assertTrue(McpTools::permitted('system_status', $superAdmin));
        self::assertTrue(McpTools::permitted('list_insights', $superAdmin));

        // A planner cannot hold this scope at mint time; the role gate refuses
        // it even if a principal somehow carries it.
        $plannerWithScope = ['role' => 'staff', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => 2];
        self::assertFalse(McpTools::permitted('system_status', $plannerWithScope));
        self::assertFalse(McpTools::permitted('list_insights', $plannerWithScope));

        $superAdminNoScope = ['role' => 'super_admin', 'scopes' => ['read:weddings'], 'kind' => 'user', 'id' => 1];
        self::assertFalse(McpTools::permitted('system_status', $superAdminNoScope));

        $couple = ['role' => 'couple', 'scopes' => ['admin:system'], 'kind' => 'account', 'id' => 3];
        self::assertFalse(McpTools::permitted('list_insights', $couple));
    }

    #[Group('ops')]
    public function testBusinessPulsePromptUsesTheOperatorTools(): void
    {
        $principal = ['role' => 'super_admin', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => 1];
        $prompt    = McpPrompts::get('business_pulse', [], $principal);
        $text      = (string) ($prompt['messages'][0]['content']['text'] ?? '');

        self::assertStringContainsString('system_status', $text);
        self::assertStringContainsString('list_insights', $text);
        self::assertStringContainsString('movement', $text);
        self::assertStringContainsString('RECOMMENDATIONS', $text);
        self::assertStringContainsString('Do not claim a change was made', $text);
        $this->assertNoContactDetails($text);
    }

    #[Group('ops')]
    public function testBusinessPulsePromptNeedsTheOperatorScope(): void
    {
        // A planner cannot be MINTED admin:system (ApiToken::mint refuses it);
        // the prompt therefore refuses a planner token for the scope it lacks.
        $planner = ['role' => 'staff', 'scopes' => ['read:weddings'], 'kind' => 'user', 'id' => 2];

        $this->expectException(McpPromptError::class);
        McpPrompts::get('business_pulse', [], $planner);
    }

    #[Group('ops')]
    public function testToolsRunThroughTheCatalogue(): void
    {
        $principal = ['role' => 'super_admin', 'scopes' => ['admin:system'], 'kind' => 'user', 'id' => 1];

        $status = McpTools::call('system_status', [], $principal);
        self::assertArrayHasKey('weddings', $status['data']);
        self::assertArrayHasKey('money', $status['data']);
        self::assertNull($status['booking_id']);

        $findings = McpTools::call('list_insights', ['severity' => 'high', 'limit' => 2], $principal);
        self::assertArrayHasKey('findings', $findings['data']);
        self::assertLessThanOrEqual(2, count($findings['data']['findings']));

        $this->expectException(McpToolError::class);
        McpTools::call('list_insights', ['severity' => 'urgent'], $principal);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** @return string[] every key, at any depth, of an array-shaped payload */
    private function leafKeys(mixed $value): array
    {
        $keys = [];
        $this->collectKeys($value, $keys);
        return $keys;
    }

    /** @param string[] $keys */
    private function collectKeys(mixed $value, array &$keys): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $this->collectKeys($child, $keys);
        }
    }

    /** Fail if any string value looks like an email address or a phone number. */
    private function assertNoContactDetails(mixed $value, string $path = ''): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertNoContactDetails($child, $path . '/' . $key);
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }
        self::assertDoesNotMatchRegularExpression(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            $value,
            'an email address appeared at ' . $path
        );
        self::assertDoesNotMatchRegularExpression(
            '/(?:\+254|0)[17]\d{8}\b/',
            $value,
            'a phone number appeared at ' . $path
        );
    }
}
