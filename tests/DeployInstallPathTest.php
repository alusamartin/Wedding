<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Does a fresh install actually COME UP? — the deploy kit, imported into an
 * empty database, judged by the deployment check the operator runs.
 *
 * `DeploySchemaParityTest` proves the kit's SQL FILE contains everything the
 * migrations create. That is a statement about text. This test is a statement
 * about a running install: it does what the cPanel guide tells a human to do,
 * in the documented order —
 *
 *     CREATE DATABASE → schema.sql → content.sql → seed_admin.sql
 *
 * — and then runs `deploy_check.php` (the file the guide's step 7 says must come
 * back all green) against that database. Everything the kit gets wrong shows up
 * here and nowhere else: a table the code reads but the dump forgot, a column
 * added by a migration and never folded back into the dump, a constraint or an
 * ENGINE clause the import rejects, a seed that does not actually seed the
 * operator's login.
 *
 * That is not a hypothetical class of bug. It is exactly what shipped: v2.14's
 * `tblpublic_throttle` and v2.15's `tbljob_heartbeats` were both in
 * `migrations/` and neither was in the kit, so a fresh install ran the
 * anonymous doors with no rate limiter, said "tbljob_heartbeats is not
 * installed" on Job Health, and failed the deployment check on two tables —
 * while every test on the demo database passed, because that database is built
 * from the migrations and was therefore always complete. The check that catches
 * it is the one nobody could run in a test until now, because the destination
 * has to be an EMPTY database: importing into the dev database proves nothing,
 * since every table is already there.
 *
 * WHAT IT TOUCHES: one scratch database, `wpms_install_probe_<random>`, dropped
 * before and after (the suffix is random per run because this checkout is
 * shared — two overlapping suite runs must not drop each other's database). It never reads or writes the app's own database, and the kit's SQL
 * files carry no `USE`/`CREATE DATABASE`, so selecting the scratch database is
 * the whole isolation story. The temporary copy of `deploy_check.php` (needed
 * because the real file refuses to run while its key is still the placeholder)
 * is removed in a finally block.
 *
 * THE ONE EXPECTED FAILURE: `deploy_check.php` requires a `.env` and a checkout
 * has none (this project deliberately keeps no `.env` in the repo — see
 * `.gitignore`). Where a `.env` exists the check must come back with zero
 * failures; where it does not, that single line is allowed to fail and nothing
 * else is. Either way the kit's own checks — all expected tables, the operator
 * account, the database connection — must pass, which is what a forgotten table
 * or column would break.
 *
 * Skips (with the reason) when the kit or a mysql client is unavailable: the
 * kit is gitignored by design, so a plain clone has no fresh install to test.
 */
final class DeployInstallPathTest extends TestCase
{
    /**
     * The scratch database this test owns end to end. The NAME is random per
     * run (the prefix stays recognisable): this checkout is shared, so two suite
     * runs overlapping must not drop each other's database mid-import.
     */
    private const SCRATCH_PREFIX = 'wpms_install_probe_';
    private static string $scratch = '';

    /** The import order the cPanel guide documents. */
    private const KIT_ORDER = ['schema.sql', 'content.sql', 'seed_admin.sql'];

    /** The only deployment check a checkout without a `.env` may fail. */
    private const ENV_FAIL_PREFIX = '[FAIL] .env file found (HOME or project root)';

    private const KIT_DIR       = __DIR__ . '/../deploy';
    private const PROJECT_ROOT  = __DIR__ . '/..';
    private const DEPLOY_CHECK  = __DIR__ . '/../deploy_check.php';

    /** The temporary keyed copy of deploy_check.php, removed whatever happens. */
    private static ?string $probe = null;
    /** Random per run: the probe sits in the document root for a few seconds. */
    private static string $probeKey = '';


    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php'; // DB_HOST / DB_USER / DB_PASS
        if (!is_file(self::DEPLOY_CHECK)) {
            self::markTestSkipped('deploy_check.php is missing — nothing to run');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeProbe();
    }

    protected function setUp(): void
    {
        foreach (self::KIT_ORDER as $file) {
            if (!is_file(self::KIT_DIR . '/' . $file)) {
                self::markTestSkipped('deploy/' . $file . ' is not in this checkout (the deploy kit is gitignored by design)');
            }
        }
        if (self::mysql() === '') {
            self::markTestSkipped('no mysql client found — cannot import the deploy kit');
        }
        if (self::$scratch === '') {
            self::$scratch = self::SCRATCH_PREFIX . bin2hex(random_bytes(4));
        }
    }

    #[Group('integration')]
    public function testTheDeployKitImportsIntoAnEmptyDatabaseAndPassesTheDeploymentCheck(): void
    {
        $mysql = self::mysql();
        try {
            // These two must be asserted, not merely attempted: the client is
            // handed the scratch database by name, so a CREATE that failed
            // surfaces later as the confusing "Unknown database" on the import.
            self::mustRun($mysql, 'DROP DATABASE IF EXISTS `' . self::$scratch . '`');
            self::mustRun($mysql, 'CREATE DATABASE `' . self::$scratch . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            foreach (self::KIT_ORDER as $file) {
                self::import($mysql, self::KIT_DIR . '/' . $file);
            }

            // The kit's own inventory, in the database: one table per CREATE
            // TABLE statement in the dump. A dump that half-imports (a rejected
            // ENGINE clause, a syntax the server refuses) fails here by name.
            $declared = substr_count(
                (string) file_get_contents(self::KIT_DIR . '/schema.sql'),
                'CREATE TABLE IF NOT EXISTS `'
            );
            self::assertGreaterThan(0, $declared, 'schema.sql declares no tables — wrong file?');
            self::assertSame($declared, self::tableCount(),
                'the database the kit built holds a different number of tables than the dump declares');

            // ...and now the check the operator is told must come back green.
            $probe = self::writeProbe();
            $run   = self::php($probe, self::$probeKey, ['WPMS_DB_NAME' => self::$scratch]);

            $hasEnv = is_file(self::PROJECT_ROOT . '/.env') || is_file(dirname(self::PROJECT_ROOT) . '/.env');
            $unexpected = [];
            foreach (preg_split('/\R/', $run['out']) ?: [] as $line) {
                if (!str_starts_with($line, '[FAIL]')) {
                    continue;
                }
                // The one line a checkout is allowed to fail: a `.env` cannot
                // exist in a git-ignored-anyway repo, and the check says so.
                if (!$hasEnv && str_starts_with($line, self::ENV_FAIL_PREFIX)) {
                    continue;
                }
                $unexpected[] = trim($line);
            }
            self::assertSame([], $unexpected,
                "deploy_check.php reported failure(s) on a freshly imported database:\n"
                . $run['out']);
            self::assertSame($hasEnv ? 0 : 1, $run['code'],
                'deploy_check.php exited ' . $run['code'] . " on a fresh install:\n" . $run['out']);

            // The kit checks that a missing table or an unseeded account breaks,
            // asserted by name so a passing run cannot pass vacuously.
            self::assertMatchesRegularExpression('/\[ OK \] All \d+ expected tables present/', $run['out'],
                'the deployment check did not confirm the expected tables');
            self::assertStringContainsString('[ OK ] Staff account(s) exist', $run['out'],
                'seed_admin.sql did not deliver a staff login');
            self::assertStringContainsString('[ OK ] Database connection', $run['out']);
            self::assertMatchesRegularExpression('/RESULT: ok=\d+ fail=' . ($hasEnv ? '0' : '1') . '/', $run['out'],
                'the deployment check did not summarise the run');

            // The scheduler lines are context, never a verdict: a database that
            // was just created has no heartbeats, and that is not a failure.
            self::assertStringContainsString('[INFO] scheduled jobs that have ever run: 0 of 5', $run['out']);
        } finally {
            self::removeProbe();
            self::mysqlExec($mysql, 'DROP DATABASE IF EXISTS `' . self::$scratch . '`');
        }

        // Cleaned up even when an assertion above failed (see the finally): a
        // leak here would be a database nobody remembers creating.
        self::assertSame([], self::leftoverDatabases(), 'the scratch database must not be left behind');
    }

    // ------------------------------------------------------------------
    // The deployment check, run the way the guide runs it
    // ------------------------------------------------------------------

    /**
     * deploy_check.php refuses to run while DEPLOY_CHECK_KEY is still the
     * placeholder — that refusal is a feature, and a test may not edit the file
     * the operator uploads. So it is copied to a temporary name in the project
     * root (every path in it is __DIR__-relative) with only that constant
     * replaced: the checks that run are the real ones.
     */
    private static function writeProbe(): string
    {
        self::removeProbe();
        $source = (string) file_get_contents(self::DEPLOY_CHECK);
        $nonce  = bin2hex(random_bytes(8));
        $keyed  = preg_replace(
            "/const DEPLOY_CHECK_KEY = .*?;/",
            "const DEPLOY_CHECK_KEY = 'install_probe_" . $nonce . "';",
            $source,
            1,
            $count
        );
        if ($count !== 1 || $keyed === null) {
            self::fail('deploy_check.php no longer declares DEPLOY_CHECK_KEY as a const — update this test');
        }
        self::$probeKey = 'install_probe_' . $nonce;
        self::$probe    = self::PROJECT_ROOT . '/.deploy_check_probe_' . $nonce . '.php';
        file_put_contents(self::$probe, $keyed);
        return self::$probe;
    }

    private static function removeProbe(): void
    {
        if (self::$probe !== null && is_file(self::$probe)) {
            @unlink(self::$probe);
        }
        self::$probe    = null;
        self::$probeKey = '';
    }

    // ------------------------------------------------------------------
    // Process and SQL plumbing
    // ------------------------------------------------------------------

    /** Locate a working mysql client (the project's own candidate list). */
    private static function mysql(): string
    {
        static $found = null;
        if ($found !== null) {
            return $found;
        }
        $candidates = [
            'C:/xampp/mysql/bin/mysql.exe',
            'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe',
            'C:/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            'mysql',
        ];
        foreach ($candidates as $bin) {
            if ($bin !== 'mysql' && !is_file($bin)) {
                continue;
            }
            // Probe it: PATH and exec() can disagree, especially on Windows.
            exec(escapeshellarg($bin) . ' --version 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $out, $code);
            if ($code === 0) {
                return $found = $bin;
            }
        }
        return $found = '';
    }

    /**
     * Run one statement against the SERVER (no database selected) — the only
     * form that can create or drop the scratch database itself.
     */
    private static function mysqlExec(string $mysql, string $sql): array
    {
        return self::process(self::mysqlBase($mysql) . ' --execute=' . escapeshellarg($sql));
    }

    /** The same, but a non-zero exit is a failure of this test. */
    private static function mustRun(string $mysql, string $sql): void
    {
        $run = self::mysqlExec($mysql, $sql);
        self::assertSame(0, $run['code'], 'mysql failed: ' . $sql . "\n" . $run['out']);
    }

    /** Import one SQL file into the scratch database, exactly as the guide does. */
    private static function import(string $mysql, string $file): void
    {
        $run = self::process(self::mysqlCommand($mysql) . ' < ' . escapeshellarg($file));
        self::assertSame(0, $run['code'],
            'importing ' . basename($file) . " into an empty database failed:\n" . $run['out']);
    }

    /** A mysql client pointed at the server, with no database selected. */
    private static function mysqlBase(string $mysql): string
    {
        $cmd = escapeshellarg($mysql)
            . ' --host=' . escapeshellarg(DB_HOST)
            . ' --user=' . escapeshellarg(DB_USER);
        if (DB_PASS !== '') {
            $cmd .= ' --password=' . escapeshellarg(DB_PASS);
        }
        // Kenyan names and emoji have to survive the round trip, as on cPanel.
        return $cmd . ' --default-character-set=utf8mb4';
    }

    /** The same client, selecting the scratch database (the import's target).
     *
     * The database is the client's own argument, NOT an SQL identifier: on the
     * command line MySQL takes the name literally, so backticks there become
     * part of the name ("Unknown database '`wpms_install_probe`'").
     */
    private static function mysqlCommand(string $mysql): string
    {
        return self::mysqlBase($mysql) . ' ' . escapeshellarg(self::$scratch);
    }

    private static function tableCount(): int
    {
        $run = self::process(self::mysqlCommand(self::mysql())
            . ' --batch --skip-column-names --execute=' . escapeshellarg('SHOW TABLES'));
        self::assertSame(0, $run['code'], 'SHOW TABLES failed: ' . $run['out']);
        return count(array_filter(preg_split('/\R/', trim($run['out'])) ?: []));
    }

    /** @return string[] every scratch database this test family has ever made */
    private static function leftoverDatabases(): array
    {
        $run = self::process(self::mysqlBase(self::mysql())
            . ' --batch --skip-column-names --execute='
            . escapeshellarg("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '" . self::SCRATCH_PREFIX . "%'"));
        self::assertSame(0, $run['code'], 'could not list databases: ' . $run['out']);
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $run['out']) ?: [])));
    }

    /** Run the keyed copy of the deployment check, in its own process. */
    private static function php(string $script, string $arg, array $env): array
    {
        $childEnv = [];
        foreach (getenv() as $key => $value) {
            $childEnv[$key] = $value;
        }
        // This checkout's own switches must not reach the check: WPMS_DB_NAME is
        // decided here, and a WPMS_SITE_URL would make it probe a real site.
        unset($childEnv['WPMS_SITE_URL']);
        foreach ($env as $key => $value) {
            $childEnv[$key] = $value;
        }
        return self::process(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($arg), $childEnv);
    }

    /**
     * Run a shell command (the kit's own imports are shell redirects, exactly as
     * demo_reset.php and the cPanel guide do them) and collect what it said.
     *
     * @param array<string,string>|null $env null = inherit this process's environment
     * @return array{code:int, out:string}
     */
    private static function process(string $command, ?array $env = null): array
    {
        $proc = @proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::PROJECT_ROOT,
            $env
        );
        if (!is_resource($proc)) {
            self::fail('could not start: ' . $command);
        }
        $out  = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $code = (int) proc_close($proc);
        return ['code' => $code, 'out' => $out];
    }
}
