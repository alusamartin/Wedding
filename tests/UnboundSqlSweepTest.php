<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/config.php';

/**
 * Sweep G — SQL is bound, or it does not run (scripts/audit_unbound_sql.php,
 * wired into `composer code-audit`).
 *
 * A sweep that never fails is worse than none, so this class drives it twice and
 * then tests the thing the sweep exists to protect:
 *
 *  1. the real tree is clean (exit 0, "SWEEP G CLEAN") — no call to the wrapper's
 *     legacy door, no raw driver on the request surface, no request data inside a
 *     statement;
 *  2. tests/fixtures/unbound — a deliberately broken mini-project — reports each
 *     rule, with the counts pinned (G1×5, G2×3, G3×2) so a sweep that stopped
 *     reporting part-way cannot pass by printing something else. The fixture also
 *     carries the shapes that must stay SILENT: a value bound to a prepared
 *     statement, a raw handle that prepares, and request data in a bind array
 *     (which is where binding puts it);
 *  3. the allow-list is keyed on the statement, not the file: the connection's own
 *     `SET time_zone` is allowed, and the fixture that contains it is never named;
 *  4. and the RUNTIME half, which no source scan can prove — the wrapper's
 *     query() goes through prepare() + execute() even when there is nothing to
 *     bind. A counting subclass settles it: the old implementation reached
 *     PDO::query() directly for that call, so prepares and executes both came back
 *     zero. It counts 1 and 1 now.
 *
 * The retired setQuery()/executeQuery()/loadResultList() trio is asserted gone by
 * name, because "no caller" and "cannot be called" are different guarantees and
 * this change is worth the second one.
 */
final class UnboundSqlSweepTest extends TestCase
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
     * fixture tree. Cached per root.
     *
     * @param string $key 'repo' or a directory under tests/fixtures
     * @return array{code:int, out:string}
     */
    private static function sweep(string $key): array
    {
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable — cannot run the sweep as the build does');
        }

        $root = realpath(__DIR__ . '/..');
        $cmd  = [PHP_BINARY, $root . '/scripts/audit_unbound_sql.php'];
        if ($key !== 'repo') {
            $cmd[] = '--root=' . $root . '/tests/fixtures/' . $key;
        }

        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($proc)) {
            self::fail('could not start scripts/audit_unbound_sql.php');
        }
        fclose($pipes[0]);
        $out  = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return self::$runs[$key] = ['code' => $code, 'out' => $out];
    }

    // ------------------------------------------------------------------ the tree

    public function testTheRepositoryHasNoUnboundRoute(): void
    {
        $run = self::sweep('repo');

        self::assertSame(0, $run['code'], "sweep G failed on this repository:\n" . $run['out']);
        self::assertStringContainsString('SWEEP G CLEAN', $run['out']);
        self::assertStringContainsString('no call to the legacy door', $run['out']);
    }

    // ------------------------------------------------------------------ the rules

    public function testItReportsTheLegacyDoor(): void
    {
        $run = self::sweep('unbound');

        self::assertSame(1, $run['code'], $run['out']);
        self::assertStringContainsString('[G1] $db->query()', $run['out'], 'a parameterless query() is the route itself');
        self::assertStringContainsString('[G1] $db->executeQuery()', $run['out'], 'the setQuery/executeQuery pair must be named too');
        self::assertStringContainsString(
            '[G1] $db->escape_string()',
            $run['out'],
            'quoting a value into SQL by hand is the same mistake in a different costume'
        );
        self::assertStringContainsString('G1×5', $run['out'], 'every legacy call in the fixture must be reported, not the first one');
    }

    public function testItReportsTheRawHandleOnTheRequestSurface(): void
    {
        $run = self::sweep('unbound');

        self::assertStringContainsString('[G2] $pdo->query()', $run['out'], 'the wrapper is not the only door to the driver');
        self::assertStringContainsString('[G2] $pdo->exec()', $run['out']);
        self::assertStringContainsString('admin/leaky_page.php', $run['out'], 'the admin surface is in scope');
        self::assertStringContainsString('root_page.php', $run['out'], 'so is the public root');
        self::assertStringContainsString('G2×3', $run['out']);
    }

    public function testItReportsRequestDataInsideStatementText(): void
    {
        $run = self::sweep('unbound');

        self::assertStringContainsString('request data ($_GET) is inside the SQL', $run['out']);
        self::assertStringContainsString('G3×2', $run['out'], 'both shapes are reported: concatenated and interpolated');
        self::assertStringContainsString('admin/leaky_page.php:27', $run['out'], 'the concatenated form');
        self::assertStringContainsString('admin/leaky_page.php:28', $run['out'], 'the interpolated form');
    }

    /**
     * The half that keeps the sweep honest: the bound shapes in both fixtures are
     * the same statements written correctly, and must not be reported. The total
     * is pinned so a rule that started firing on them would fail here rather than
     * look like thoroughness.
     */
    public function testItLeavesTheBoundShapesAndTheAllowListAlone(): void
    {
        $run = self::sweep('unbound');

        self::assertStringContainsString('SWEEP G: 10 finding(s)', $run['out'], 'exactly the designed findings');
        self::assertStringNotContainsString(
            'admin/allowed_init.php',
            $run['out'],
            'the connection\'s own timezone command is allow-listed by its statement text'
        );
        // PDOStatement::execute() takes the BIND ARRAY first: request data there is
        // a value being bound, not a statement being built. Misreading it is how
        // an audit invents findings, so the exact line is asserted absent.
        self::assertStringNotContainsString(
            'root_page.php:21',
            $run['out'],
            'request data in a bind array must not be reported as SQL'
        );
        self::assertStringNotContainsString(
            'root_page.php:16',
            $run['out'],
            'preparing on a raw handle is the fix, not a finding'
        );
        // The report says `$pdo->query()` for the raw runner; a finding on
        // prepare() would have to appear as `[G2] $pdo->prepare()`, which is the
        // fix itself.
        self::assertStringNotContainsString('$pdo->prepare()', $run['out']);
    }

    // ------------------------------------------- the runtime half (no source scan can prove it)

    public function testTheWrapperAlwaysPreparesEvenWithNothingToBind(): void
    {
        if (!self::$dbOk) {
            $this->markTestSkipped('live database unavailable — the wrapper cannot be driven');
        }

        $probe = new CountingQueryProbe();

        // No placeholders at all: this is the call that used to reach
        // PDO::query() directly, counting zero prepares.
        $row = $probe->query('SELECT 1 AS v')->fetch(PDO::FETCH_OBJ);
        self::assertSame(1, (int) $row->v, 'query() must still run the statement');
        self::assertSame(1, $probe->prepares, 'query() must prepare the statement even with no placeholders');
        self::assertSame(1, $probe->executes, 'and it must run it through execute(), not beside it');

        // With values: the same route, binding them.
        $stmt = $probe->query('SELECT :n AS v', [':n' => 7]);
        self::assertSame('7', (string) $stmt->fetchColumn());
        self::assertSame(2, $probe->prepares);
        self::assertSame(2, $probe->executes);
    }

    /**
     * An uncatalogued caller — one the sweep cannot see, or code outside the
     * repository — must go through the prepared path rather than break or bypass
     * it. The method is kept for exactly that reason, and the three methods with
     * no callers left are gone rather than kept.
     */
    public function testAnUncataloguedCallerIsServedAndTheDeadApisAreGone(): void
    {
        if (!self::$dbOk) {
            $this->markTestSkipped('live database unavailable — the wrapper cannot be driven');
        }
        global $db;
        $db = $db instanceof Database ? $db : new Database();

        self::assertSame('5', (string) $db->fetchValue('SELECT :n AS v', [':n' => 5]));
        self::assertSame(1, (int) $db->query('SELECT 1 AS v')->fetchColumn());

        self::assertFalse(method_exists($db, 'setQuery'), 'setQuery() had no callers and stored SQL to run unprepared');
        self::assertFalse(method_exists($db, 'executeQuery'), 'executeQuery() was the unprepared runner for that stored SQL');
        self::assertFalse(method_exists($db, 'loadResultList'), 'loadResultList() existed only to read through executeQuery()');

        // The escape hatch stays gone: no direct driver execution in the wrapper
        // except the one allow-listed init command sweep G carries.
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/database.php');
        self::assertStringNotContainsString('pdo()->query(', $src, 'the wrapper must have no unprepared execution path');
        self::assertStringNotContainsString('->pdo->query(', $src);
        self::assertSame(
            1,
            substr_count($src, '->pdo->exec('),
            'exactly one direct driver command: the connection\'s own SET time_zone'
        );
    }
}

/**
 * A Database that counts the statements it prepares. `prepare()` and `execute()`
 * are not final, and `query()` reaches them through `$this->execute()`, so the
 * counts say which route the call took rather than which method was called.
 */
final class CountingQueryProbe extends Database
{
    public int $prepares = 0;
    public int $executes = 0;

    public function prepare(string $sql): PDOStatement
    {
        $this->prepares++;
        return parent::prepare($sql);
    }

    public function execute(string $sql, array $params = []): PDOStatement
    {
        $this->executes++;
        return parent::execute($sql, $params);
    }
}
