<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for the WPMS Kenya suite.
 *
 * Three jobs, in order:
 *
 *  1. The Composer autoloader (the classmap covers admin/include, so model
 *     classes load by name).
 *
 *  2. STRICT ERROR REPORTING. The suite runs with failOnWarning /
 *     failOnNotice / failOnDeprecation (phpunit.xml); turning error_reporting
 *     up to E_ALL here makes sure every diagnostic a test triggers is actually
 *     raised for PHPUnit's handler to catch, rather than being filtered out by
 *     the process defaults before the runner ever sees it.
 *
 *  3. ISOLATED TEST DATABASE. The suite writes (deposits, RSVPs, a booking's
 *     date is swapped and restored). It must not do that to the demo database a
 *     developer keeps open in a browser, so phpunit.xml points WPMS_DB_NAME at
 *     a scratch `wpms_test`. This bootstrap builds that database from the same
 *     seed files the demo uses, ONCE, when it does not exist yet — so `phpunit`
 *     is self-sufficient — and never touches it again. `composer test:db`
 *     drops and rebuilds it explicitly for a clean full run.
 *
 * The build is skipped entirely when WPMS_DB_NAME is anything other than the
 * default scratch name (CI brings its own database), and can be forced off with
 * WPMS_SKIP_TEST_DB_BUILD=1.
 */

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

require_once __DIR__ . '/../admin/include/config.php';

const WPMS_TEST_DB = 'wpms_test';

/**
 * Is the scratch database present? Probes the server WITHOUT selecting a
 * database, so a missing schema is a clean false rather than a connection
 * error. Any other failure (server down) is reported and treated as "missing",
 * letting the build attempt surface the real message.
 */
function wpms_test_db_exists(): bool
{
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([DB_NAME]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        fwrite(STDERR, '[bootstrap] could not probe the test database: ' . $e->getMessage() . "\n");
        return false;
    }
}

/**
 * Build the scratch database by delegating to the demo reset (the one place
 * that knows the seed import order and the post-import date shift). Runs in a
 * child PHP process so its own `exit()` and output cannot disturb the runner.
 */
function wpms_build_test_db(): void
{
    $script = __DIR__ . '/../scripts/demo_reset.php';
    fwrite(STDERR, '[bootstrap] isolated test database "' . DB_NAME . "\" not found — building it from the seed files...\n");
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --yes --db=' . escapeshellarg(DB_NAME);
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(
            STDERR,
            "[bootstrap] FAILED to build the test database (exit {$code}).\n"
            . '[bootstrap] Build it by hand, then re-run:  php scripts/demo_reset.php --yes --db=' . DB_NAME . "\n"
        );
    }
}

if (DB_NAME === WPMS_TEST_DB
    && getenv('WPMS_SKIP_TEST_DB_BUILD') !== '1'
    && !wpms_test_db_exists()) {
    wpms_build_test_db();
}
