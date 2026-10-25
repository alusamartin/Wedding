<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sweep D — role-facing promises vs the state behind them
 * (scripts/audit_promises.php, wired into `composer code-audit`).
 *
 * A static check that never fails is worse than none: it advertises a guard
 * that is not guarding. So this test drives both halves through the same CLI
 * the build uses:
 *
 *  1. the real tree is clean (exit 0, "SWEEP D CLEAN");
 *  2. tests/fixtures/promises — a deliberately broken mini-project — fails
 *     (exit 1) once per rule: an unregistered promise phrase, a table read
 *     with no row-creator, a column read but never written, an equality branch
 *     whose value is never written, and an accepted-with-a-reason entry whose
 *     debt has since been paid.
 *
 * --root points the sweep at the fixture tree; the database is always the live
 * one, so the whole class skips cleanly when it is unreachable. The fixture
 * run is expected to be noisy beyond those four lines (its files are not the
 * project the promise registry describes); each test asserts on its own line.
 */
final class PromisesSweepTest extends TestCase
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
     * Run the sweep exactly as `composer code-audit` does, optionally against
     * the fixture tree. Cached per root: five tests, two processes.
     *
     * @param bool $fixture true to scan tests/fixtures/promises instead of the repo
     * @return array{code:int, out:string}
     */
    private static function sweep(bool $fixture): array
    {
        $key = $fixture ? 'fixture' : 'repo';
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }
        if (!self::$dbOk) {
            self::markTestSkipped('live database unavailable — sweep D has no schema to check against');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable — cannot run the sweep as the build does');
        }

        $root = realpath(__DIR__ . '/..');
        $cmd  = [PHP_BINARY, $root . '/scripts/audit_promises.php'];
        if ($fixture) {
            $cmd[] = '--root=' . $root . '/tests/fixtures/promises';
        }

        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($proc)) {
            self::fail('could not start scripts/audit_promises.php');
        }
        fclose($pipes[0]);
        $out  = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return self::$runs[$key] = ['code' => $code, 'out' => $out];
    }

    public function testRepositoryHasNoUnbackedPromise(): void
    {
        $run = self::sweep(false);

        self::assertSame(0, $run['code'], "sweep D failed on this repository:\n" . $run['out']);
        self::assertStringContainsString('SWEEP D CLEAN', $run['out']);
    }

    public function testFailsOnUnregisteredPromiseCopy(): void
    {
        $run = self::sweep(true);

        self::assertSame(1, $run['code'], $run['out']);
        self::assertMatchesRegularExpression(
            '/unregistered promise phrase "will get back to you" in admin\/promise_copy\.php:\d+/',
            $run['out'],
            'a promise nobody registered must be refused'
        );
    }

    public function testFailsOnATableNothingCanCreate(): void
    {
        $run = self::sweep(true);

        self::assertStringContainsString(
            'tblvendors: table tblvendors is read but has no row-creator',
            $run['out']
        );
    }

    public function testFailsOnAColumnNothingWrites(): void
    {
        $run = self::sweep(true);

        self::assertStringContainsString(
            'tblgallery.title: column tblgallery.title is read but never written',
            $run['out']
        );
    }

    public function testFailsOnAnUnreachableComparison(): void
    {
        $run = self::sweep(true);

        self::assertMatchesRegularExpression(
            '/tblgallery\.type: column tblgallery\.type is only ever written \["1"\] but compared for equality against "2"/',
            $run['out'],
            'a branch whose value is never written must be reported'
        );
    }

    public function testFailsOnAnAcceptedEntryThatOutlivedItsDebt(): void
    {
        $run = self::sweep(true);

        // The fixture gives `calendar` (accepted as "no page reads it") a
        // persistence class. The acceptance is then obsolete, and leaving it
        // in place would hide exactly the regressions it was written for.
        self::assertMatchesRegularExpression(
            '/accepted entry calendar is obsolete/',
            $run['out'],
            'an acceptance whose debt is paid must fail the build'
        );
    }
}
