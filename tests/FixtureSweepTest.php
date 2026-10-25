<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sweep F — test fixtures a class claims but does not own
 * (scripts/audit_fixtures.php, wired into `composer code-audit`).
 *
 * A static check that never fails is worse than none: it advertises a guard that
 * is not guarding. So this test drives the sweep through the same CLI the build
 * uses and asserts BOTH halves:
 *
 *  1. the real tree is clean — every record a test asserts about is one its own
 *     file writes, or a seeded read the sweep refuses to guess about;
 *  2. tests/fixtures/fixtures — a deliberately broken mini-suite — reports the
 *     four rules, each with its own evidence: a record no database contains (F1,
 *     once unguarded and once behind a status check, where the finding says the
 *     read passes vacuously), a calendar claim about a seeded record the file
 *     never writes (F4), a literal compared against a whole table (F2, in both
 *     the inline and the helper form), and text the file generates in two places
 *     (F3).
 *
 *  3. the ratchet (F6) on the not-judged list: a foreign read with no allowance
 *     fails the build with the key to argue about, its tolerated twin passes and
 *     prints the reason instead — and the repository run must report **zero**
 *     gaps, tolerated or new.
 *
 * Then the halves that must NOT fire, because a rule that reports everything
 * guards nothing: a nonexistent id used to prove a refusal (status-only), a
 * count constrained to the test's own record, and a label generated exactly once.
 *
 * --root points the sweep at the fixture tree; the database it checks ids
 * against is the live one named by WPMS_DB_NAME, so the class skips cleanly when
 * either is unreachable.
 */
final class FixtureSweepTest extends TestCase
{
    private static bool $dbOk = false;

    /** @var array<string, array{code:int, out:string}> runs, keyed by root */
    private static array $runs = [];

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
            new Database();
            self::$dbOk = true;
        } catch (Throwable $e) {
            self::$dbOk = false;
        }
    }

    /**
     * Run the sweep exactly as `composer code-audit` does, optionally against a
     * fixture tree. Cached per root: eight tests, two processes.
     *
     * @param string $key 'repo' or a directory under tests/fixtures
     * @return array{code:int, out:string}
     */
    private static function sweep(string $key): array
    {
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }
        if (!self::$dbOk) {
            self::markTestSkipped('live database unavailable — sweep F cannot check whether an id exists');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable — cannot run the sweep as the build does');
        }

        $root = realpath(__DIR__ . '/..');
        $cmd  = [PHP_BINARY, $root . '/scripts/audit_fixtures.php'];
        if ($key !== 'repo') {
            $cmd[] = '--root=' . $root . '/tests/fixtures/' . $key;
        }

        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($proc)) {
            self::fail('could not start scripts/audit_fixtures.php');
        }
        fclose($pipes[0]);
        $out  = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return self::$runs[$key] = ['code' => $code, 'out' => $out];
    }

    public function testRepositoryHasNoUnownedFixture(): void
    {
        $run = self::sweep('repo');

        self::assertSame(0, $run['code'], "sweep F failed on this repository:\n" . $run['out']);
        self::assertStringContainsString('SWEEP F CLEAN', $run['out']);
        self::assertStringContainsString('findings: 0', $run['out']);
        // The ratchet's own state: the repository has no gap to tolerate and no
        // new one, which is what F6 is holding in place.
        self::assertStringContainsString('not judged: 0 (0 tolerated, 0 new)', $run['out']);
    }

    public function testReportsARecordNoDatabaseContains(): void
    {
        $run = self::sweep('fixtures');

        self::assertSame(1, $run['code'], $run['out']);
        self::assertStringContainsString(
            'asserts on the body of booking_id=999215, which does not exist',
            $run['out'],
            'a claimed record the database does not have must be reported'
        );
        // Both halves of the method are reported: the plain read, and the one
        // behind `if ($res['status'] === 200)`, which must say it passes
        // vacuously rather than quietly succeeding.
        self::assertStringContainsString('testCountdownForABookingNoSeedBuilds', $run['out']);
        self::assertStringContainsString('testGuardedReadOfTheSameMissingRecord', $run['out']);
        self::assertStringContainsString(
            'The read is guarded by a status check, so it passes vacuously instead of failing.',
            $run['out'],
            'a guarded read of a missing record must be named as vacuous'
        );
    }

    public function testReportsACalendarClaimAboutSomebodyElsesFixture(): void
    {
        $run = self::sweep('fixtures');

        self::assertStringContainsString(
            'F4 tests/fixtures/fixtures/MissingRecordClaimTest.php::testCalendarClaimAboutSomebodyElsesBooking: asserts '
            . 'the calendar text Saturday 19 December 2026 about',
            $run['out'],
            'a date-shaped claim about a record the file never writes must be reported'
        );
    }

    public function testFailsAGapTheRatchetDoesNotTolerate(): void
    {
        $run = self::sweep('fixtures');

        self::assertStringContainsString(
            'F6 tests/fixtures/fixtures/ForeignFixtureReadTest.php:25: the not-judged list grew — booking_id=200 is a '
            . 'claimed record this sweep will not judge',
            $run['out'],
            'an unargued-for gap in the not-judged list must fail the build'
        );
        self::assertStringContainsString(
            'own the fixture, or record the key in TOLERATED_GAPS with the reason this gap is tolerated',
            $run['out'],
            'the failure must say how to argue for the gap'
        );
        self::assertStringContainsString('not judged: 2 (1 tolerated, 1 new)', $run['out'], 'the ratchet counts both halves');
    }

    public function testPrintsAToleratedGapInsteadOfFailing(): void
    {
        $run = self::sweep('fixtures');

        self::assertStringContainsString(
            'tolerated  tests/fixtures/fixtures/ToleratedForeignReadTest.php::booking_id=200 (line 24)',
            $run['out'],
            'an allowance must be printed with the key it covers'
        );
        self::assertStringContainsString(
            'A fixture tree that reads a seeded booking without writing it is the point of that file',
            $run['out'],
            'an allowance must be printed with its reason, every run'
        );
        // The tolerated read is a gap, never a finding — and its twin, which no
        // allowance covers, is the only F6 finding in the run.
        self::assertStringNotContainsString('F6 tests/fixtures/fixtures/ToleratedForeignReadTest.php', $run['out']);
        self::assertSame(1, substr_count($run['out'], '::F6::'), $run['out']);
    }

    public function testReportsALiteralComparedAgainstAWholeTable(): void
    {
        $run = self::sweep('fixtures');

        self::assertStringContainsString(
            'compares the literal 7 against dealCount(), which counts a whole table with no WHERE',
            $run['out'],
            'the helper form of an unconstrained count must be reported'
        );
        self::assertStringContainsString(
            'compares the literal 2 against an unconstrained COUNT()',
            $run['out'],
            'the inline form of an unconstrained count must be reported'
        );
        // …while the count constrained to the fixture's own row is left alone:
        // exactly the two above, no third.
        self::assertSame(2, substr_count($run['out'], '::F2::'), $run['out']);
    }

    public function testReportsTextTheFileGeneratesMoreThanOnce(): void
    {
        $run = self::sweep('fixtures');

        self::assertStringContainsString(
            'asserts the text "probe item 3" that the file generates in more than one place ("probe item " + a '
            . 'variable, in seedProbes(), called 2 times)',
            $run['out'],
            'text a twice-called seeding helper generates must be reported'
        );
    }

    public function testLeavesRefusalProbesAndUniqueLabelsAlone(): void
    {
        $run = self::sweep('fixtures');

        // A nonexistent id proving a refusal: the assertion is about the status,
        // never the body, so nothing is claimed about the record.
        self::assertStringNotContainsString('booking_id=99999999', $run['out'], 'a refusal probe is not a finding');
        // A label generated once and asserted once has nothing to confuse it.
        self::assertStringNotContainsString('QDL-ONLY-ONCE-42', $run['out'], 'a unique label is not a finding');
        // The fixture tree's own findings and the ratchet's, and nothing else.
        self::assertSame(11, substr_count($run['out'], '!! '), $run['out']);
    }
}
