<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sweep E — status/enum values code reacts to but nothing writes
 * (scripts/audit_enum_values.php, wired into `composer code-audit`).
 *
 * A static check that never fails is worse than none: it advertises a guard that
 * is not guarding. So this test drives both halves through the same CLI the
 * build uses:
 *
 *  1. the real tree is clean (exit 0, "SWEEP E CLEAN");
 *  2. tests/fixtures/enums — a deliberately broken mini-project — reports EVERY
 *     reacted-but-unwritten value of tblvendors.category (four of them, so the
 *     sweep cannot pass by stopping at the first), credits the two values a
 *     terminating allow-list guard proves, and excuses the one value the
 *     repository's own ACCEPTED_VALUES entry covers;
 *  3. tests/fixtures/enums_obsolete — where a writer has since appeared for the
 *     value that acceptance excused — fails with the obsolete-entry message.
 *
 * --root points the sweep at the fixture trees; the value universe always comes
 * from the live database, so the whole class skips cleanly when it is
 * unreachable. The fixture runs are expected to be noisy beyond the lines each
 * test asserts; every test checks its own line.
 *
 * Both fixture trees reference the repository's own `tblguest.rsvp_status`
 * acceptance, because that is the value the two acceptance rules are about. If
 * that entry is ever removed — because the writer became provable, or because
 * the value stopped being reacted to — these tests have to be updated with it,
 * exactly as tests/PromisesSweepTest does with sweep D's `calendar` entry.
 */
final class EnumValueSweepTest extends TestCase
{
    private static bool $dbOk = false;

    /** @var array<string, array{code:int, out:string}> runs, keyed by root */
    private static array $runs = [];

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
            new Database();
            self::$dbOk = true;
        } catch (Throwable $e) {
            self::$dbOk = false;
        }
    }

    /**
     * Run the sweep exactly as `composer code-audit` does, optionally against a
     * fixture tree. Cached per root: four tests, three processes.
     *
     * @param string $key  'repo' or a directory under tests/fixtures
     * @return array{code:int, out:string}
     */
    private static function sweep(string $key): array
    {
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }
        if (!self::$dbOk) {
            self::markTestSkipped('live database unavailable — sweep E has no schema to check against');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable — cannot run the sweep as the build does');
        }

        $root = realpath(__DIR__ . '/..');
        $cmd  = [PHP_BINARY, $root . '/scripts/audit_enum_values.php'];
        if ($key !== 'repo') {
            $cmd[] = '--root=' . $root . '/tests/fixtures/' . $key;
        }

        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($proc)) {
            self::fail('could not start scripts/audit_enum_values.php');
        }
        fclose($pipes[0]);
        $out  = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return self::$runs[$key] = ['code' => $code, 'out' => $out];
    }

    public function testRepositoryHasNoUnwrittenEnumValue(): void
    {
        $run = self::sweep('repo');

        self::assertSame(0, $run['code'], "sweep E failed on this repository:\n" . $run['out']);
        self::assertStringContainsString('SWEEP E CLEAN', $run['out']);
    }

    public function testReportsEveryReactedValueThatNothingWrites(): void
    {
        $run = self::sweep('enums');

        self::assertSame(1, $run['code'], $run['out']);
        // Four values, one column, three kinds of reaction: the sweep reports
        // each one rather than stopping at the first dead branch it finds.
        foreach (['decor', 'venue', 'flowers', 'other'] as $value) {
            self::assertStringContainsString(
                'column tblvendors.category: value "' . $value . '" is reacted to but never written',
                $run['out'],
                "the reacted-but-unwritten value {$value} must be reported"
            );
        }
    }

    public function testCreditsTheValuesATerminatingGuardProves(): void
    {
        $run = self::sweep('enums');

        // guarded_rsvp.php refuses anything but 'yes' and 'no' before storing
        // the answer, so those two are written values and must not be reported.
        // Only 'maybe' is left, and the repository's acceptance covers it.
        self::assertStringContainsString(
            'accepted  tblguest.rsvp_status: "maybe"',
            $run['out'],
            'the accepted value must be excused and printed with its reason'
        );
        self::assertStringNotContainsString('"yes"', $run['out'], 'a value a guard proves must not be reported');
        self::assertStringNotContainsString('"no"', $run['out'], 'a value a guard proves must not be reported');
    }

    public function testForgivesAValueOnlyTheAcceptanceCanExcuse(): void
    {
        $run = self::sweep('enums');

        // 'maybe' arrives from a request that no literal can enumerate, so it is
        // not a proven finding — it is excused by the registry entry, printed
        // with the reason that entry gives. Fatal findings are only the four
        // category values.
        self::assertStringNotContainsString(
            'tblguest.rsvp_status: value "maybe" is reacted to but never written',
            $run['out'],
            'an accepted value must not also be a finding'
        );
        self::assertSame(4, substr_count($run['out'], 'is reacted to but never written'), $run['out']);
    }

    public function testFailsOnAnAcceptedEntryThatOutlivedItsDebt(): void
    {
        $run = self::sweep('enums_obsolete');

        self::assertSame(1, $run['code'], $run['out']);
        self::assertStringContainsString(
            'accepted entry tblguest.rsvp_status is obsolete',
            $run['out'],
            'an acceptance its debt has been paid for must fail the build'
        );
    }
}
