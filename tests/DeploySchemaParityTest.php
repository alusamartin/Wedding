<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Does a FRESH production install get everything the code expects?
 *
 * The cPanel deploy kit (`deploy/schema.sql`) is the only schema a new install
 * ever sees, and it is a hand-maintained dump — it is not generated from the
 * migrations. Every later feature therefore has to be added to it by hand, and
 * the day someone forgets, nothing in the codebase notices: the app keeps
 * working on the machine the developer deploys FROM, while a fresh install
 * comes up missing a table.
 *
 * That is not hypothetical. v2.14 (`tblpublic_throttle`) and v2.15
 * (`tbljob_heartbeats`) both shipped in `migrations/` and neither was added to
 * the kit, so a fresh install ran the anonymous doors without a rate limiter,
 * said "tbljob_heartbeats is not installed" on Job Health, and failed
 * `deploy_check.php` on two tables — all of it invisible on the demo database,
 * which is built from the migrations and therefore always complete.
 *
 * This test is the missing alarm. Read-only, no database, no fixtures:
 *
 *   1. every table `migrations/*.sql` CREATES exists in `deploy/schema.sql`;
 *   2. every column a migration ADDs to a table exists there too (a table that
 *      arrived without the column a later feature reads is the same bug one
 *      size smaller — v2.13's `recipient_type`, v2.15's `last_queue`);
 *   3. every table `deploy_check.php` demands on a live install is in the kit,
 *      because that list is what "the deployment is complete" means;
 *   4. the count `deploy/README.md` advertises matches the kit, so the
 *      documentation cannot quietly become the last stale thing.
 *
 * The kit is GITIGNORED on purpose (`.gitignore` → `deploy/`, which also holds
 * a real DB password and a super-admin hash), so a checkout without it skips
 * rather than fails: the guard belongs where the kit is, not on every clone.
 * When the file is absent this test says so out loud instead of passing as if
 * it had checked something.
 */
final class DeploySchemaParityTest extends TestCase
{
    private const KIT      = __DIR__ . '/../deploy/schema.sql';
    private const KIT_README = __DIR__ . '/../deploy/README.md';
    private const MIGRATIONS = __DIR__ . '/../migrations';
    private const DEPLOY_CHECK = __DIR__ . '/../deploy_check.php';

    /** Table names the kit defines, as written (backticks stripped). */
    private static array $kitTables = [];
    /** table => lowercased column names, parsed out of the kit's CREATE TABLE bodies. */
    private static array $kitColumns = [];
    private static string $kit = '';

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::KIT)) {
            return;
        }
        self::$kit = (string) file_get_contents(self::KIT);
        preg_match_all(
            '/CREATE TABLE IF NOT EXISTS\s+`([a-zA-Z0-9_]+)`\s*\((.*?)\n\)\s*ENGINE/ms',
            self::$kit,
            $blocks,
            PREG_SET_ORDER
        );
        foreach ($blocks as $block) {
            $table = $block[1];
            self::$kitTables[] = $table;
            $columns = [];
            // Column definitions are the backticked identifiers that START a
            // line inside the body; PRIMARY KEY / UNIQUE KEY / KEY / CONSTRAINT
            // lines start with their own keyword and fall out on their own.
            if (preg_match_all('/^\s*`([a-zA-Z0-9_]+)`\s/m', $block[2], $cols)) {
                foreach ($cols[1] as $col) {
                    $columns[strtolower($col)] = true;
                }
            }
            self::$kitColumns[$table] = $columns;
        }
    }

    protected function setUp(): void
    {
        if (!is_file(self::KIT)) {
            self::markTestSkipped('deploy/schema.sql is not in this checkout (the deploy kit is gitignored by design)');
        }
    }

    #[Group('integration')]
    public function testEveryTableAMigrationCreatesIsInTheDeployKit(): void
    {
        $missing = [];
        foreach (self::migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`([a-zA-Z0-9_]+)`/i', $sql, $m);
            foreach (array_unique($m[1]) as $table) {
                if (!in_array($table, self::$kitTables, true)) {
                    $missing[] = $table . ' (' . basename($file) . ')';
                }
            }
        }
        self::assertSame([], $missing,
            "deploy/schema.sql is missing table(s) the migrations create — a fresh install would not have them:\n  "
            . implode("\n  ", $missing));
    }

    #[Group('integration')]
    public function testEveryColumnAMigrationAddsIsInTheDeployKit(): void
    {
        $missing = [];
        foreach (self::migrationFiles() as $file) {
            $sql = (string) file_get_contents($file);
            // One ALTER TABLE … its own statement: bound the search to the next
            // semicolon (or a generous window, for the migrations that build the
            // ALTER inside a quoted string for PREPARE), so a column can never be
            // judged against the wrong table.
            preg_match_all('/ALTER\s+TABLE\s+`([a-zA-Z0-9_]+)`(.{0,600}?)(?:;|\z)/is', $sql, $alters, PREG_SET_ORDER);
            foreach ($alters as $alter) {
                $table = $alter[1];
                if (!isset(self::$kitColumns[$table])) {
                    continue; // a table the kit does not have at all is caught by the test above
                }
                preg_match_all(
                    '/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`([a-zA-Z0-9_]+)`/i',
                    $alter[2],
                    $cols
                );
                foreach (array_unique($cols[1]) as $col) {
                    if (!isset(self::$kitColumns[$table][strtolower($col)])) {
                        $missing[] = $table . '.' . $col . ' (' . basename($file) . ')';
                    }
                }
            }
        }
        self::assertSame([], $missing,
            "deploy/schema.sql is missing column(s) the migrations add — a fresh install would lack them:\n  "
            . implode("\n  ", $missing));
    }

    #[Group('integration')]
    public function testEveryTableTheDeploymentCheckDemandsIsInTheDeployKit(): void
    {
        $check = (string) file_get_contents(self::DEPLOY_CHECK);
        self::assertSame(1, preg_match('/\$need\s*=\s*\[(.*?)\];/s', $check, $m),
            'deploy_check.php no longer has its $need table list — update this test with it');
        preg_match_all("/'([a-zA-Z0-9_]+)'/", $m[1], $needed);

        $missing = array_values(array_diff(array_unique($needed[1]), self::$kitTables));
        self::assertSame([], $missing,
            "deploy_check.php demands table(s) deploy/schema.sql never creates — a fresh install cannot pass:\n  "
            . implode("\n  ", $missing));
    }

    #[Group('integration')]
    public function testDeployReadmeAdvertisesTheRealTableCount(): void
    {
        if (!is_file(self::KIT_README)) {
            self::markTestSkipped('deploy/README.md is not in this checkout');
        }
        $readme = (string) file_get_contents(self::KIT_README);
        self::assertSame(1, preg_match('/All (\d+) tables/i', $readme, $m),
            'deploy/README.md no longer states the table count — keep the claim or drop this check');
        self::assertSame(count(self::$kitTables), (int) $m[1],
            'deploy/README.md advertises a different number of tables than deploy/schema.sql defines');
    }

    /** @return string[] every migrations/*.sql, in version order */
    private static function migrationFiles(): array
    {
        $files = glob(self::MIGRATIONS . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        self::assertNotSame([], $files, 'no migrations found — the guard is looking in the wrong place');
        return $files;
    }
}
