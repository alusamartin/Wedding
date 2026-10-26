<?php
declare(strict_types=1);

require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * WPMS Kenya — pre-release gate.
 *
 * One command that answers "is this build allowed to ship?", in the order that
 * finds breakage cheapest first:
 *
 *   1. rebuild an ISOLATED database from the seed files (scripts/demo_reset.php)
 *      so the run can write freely and never touches the demo database a
 *      developer has open in a browser;
 *   2. boot a throwaway `php -S` against that database and drive the REAL
 *      application through every role with scripts/simulate_roles.php — pages,
 *      access control, write paths, and the server log for diagnostics that
 *      never reach a response body;
 *   3. walk the flows a person performs with scripts/smoke.php — including a
 *      deposit that must reach the ledger and ring the planner's bell;
 *   4. run the full PHPUnit suite (which uses its own wpms_test database);
 *   5. install the APK on the attached device with scripts/device_smoke.php,
 *      sign in as the seeded couple, and open every core screen — failing on a
 *      screen that throws, shows the offline banner, or is empty where the
 *      seed has data.
 *
 * It exists because the things that broke the Android release were exactly the
 * things this sequence covers: an app login table the seeder never created, a
 * navigation badge whose markup the simulator still asserted against, and a
 * screen that only failed on a real device. None is visible from a debug build.
 *
 * Step 5 needs an APK on disk, so it is the one step that judges the build it
 * finds rather than the sources: run it after packaging. The Gradle release flow
 * does exactly that — steps 1-4 run before the APK is produced, and
 * `releaseDeviceCheck` runs step 5 against the APK that was just signed. With
 * no device attached (or no APK yet) it reports [--] and says so; it never
 * silently counts as a pass. Pass --require-device to make its absence a failure.
 *
 * Usage:
 *   php scripts/release_preflight.php
 *   php scripts/release_preflight.php --db=wpms_gate --port=8137
 *   php scripts/release_preflight.php --keep-db         # reuse the scratch DB
 *   php scripts/release_preflight.php --only=sim        # skip PHPUnit and the device
 *   php scripts/release_preflight.php --only=test       # skip the simulation
 *   php scripts/release_preflight.php --only=device     # step 5 alone, on the APK on disk
 *   php scripts/release_preflight.php --apk=path/to/app-release.apk
 *
 * Exit codes: 0 = gate passed, 1 = a step failed, 2 = environment problem
 * (port busy, database unreachable, PHP binary missing) — 2 is never a pass.
 *
 * `composer preflight` runs it; `./gradlew :app:assembleRelease` depends on it
 * for steps 1-4 and runs step 5 afterwards.
 */

const DEFAULT_DB   = 'wpms_preflight';
const DEFAULT_PORT = 8137;
const DEFAULT_APK  = 'android/app/build/outputs/apk/release/app-release.apk';
const STEP_TIMEOUT = 1200; // seconds per step: the suite is ~3 min, the sim ~2
const READY_TIMEOUT = 60;  // seconds to wait for the dev server to answer

// ------------------------------------------------------------------ args
$opts = getopt('', ['db::', 'port::', 'only::', 'apk::', 'keep-db', 'skip-reset', 'require-device', 'skip-device', 'help']) ?: [];
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Pre-release gate - rebuilds an isolated database, runs the role simulator and the full\n"
        . "PHPUnit suite, then installs the APK on the attached device and signs in.\n\n"
        . "  --db=NAME       scratch database to build and use (default " . DEFAULT_DB . ")\n"
        . "  --port=N        port for the throwaway web server (default " . DEFAULT_PORT . ")\n"
        . "  --only=sim|test|device  run one part only (default: everything)\n"
        . "  --apk=PATH      APK the device step installs (default " . DEFAULT_APK . ")\n"
        . "  --require-device  no device attached is a FAILURE, not a skip\n"
        . "  --skip-device   run steps 1-4 only (the Gradle release build then runs step 5\n"
        . "                  after packaging, on the APK it just signed)\n"
        . "  --keep-db       reuse the scratch database instead of rebuilding it\n"
        . "  --skip-reset    same as --keep-db\n");
    exit(0);
}

$db        = isset($opts['db']) && is_string($opts['db']) && trim($opts['db']) !== ''
    ? trim($opts['db'])
    : DEFAULT_DB;
$port      = isset($opts['port']) ? (int) $opts['port'] : DEFAULT_PORT;
$only      = isset($opts['only']) && is_string($opts['only']) ? strtolower(trim($opts['only'])) : 'both';
$keepDb    = array_key_exists('keep-db', $opts) || array_key_exists('skip-reset', $opts);
$apkPath   = isset($opts['apk']) && is_string($opts['apk']) && trim($opts['apk']) !== '' ? trim($opts['apk']) : null;
$requireDevice = array_key_exists('require-device', $opts);
$skipDevice = array_key_exists('skip-device', $opts);
$runSim    = in_array($only, ['both', 'sim'], true);
$runTests  = in_array($only, ['both', 'test'], true);
$runDevice = !$skipDevice && in_array($only, ['both', 'device'], true);

if (!in_array($only, ['both', 'sim', 'test', 'device'], true)) {
    fwrite(STDERR, "--only must be 'sim', 'test', 'device' or omitted\n");
    exit(2);
}
if ($port < 1024 || $port > 65535) {
    fwrite(STDERR, "--port must be between 1024 and 65535\n");
    exit(2);
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    fwrite(STDERR, "--db must be a plain database name (letters, digits, underscore)\n");
    exit(2);
}
if (in_array($db, ['dbwedding', 'wpms_test', 'mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
    fwrite(STDERR, "Refusing to use \"{$db}\": this gate DROPS the database it works on.\n"
        . "Use a scratch name (default " . DEFAULT_DB . ").\n");
    exit(2);
}

$root     = dirname(__DIR__);
$php      = PHP_BINARY;
$dbLabel  = str_pad('database ' . $db, 34);
$tmpBase  = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$simJson  = $tmpBase . 'wpms_preflight_sim.json';
$simLog   = $tmpBase . 'wpms_preflight_server.log';
$runLog   = $tmpBase . 'wpms_preflight_run.log';

$steps = [];   // [label, ok|null, detail]
$exit  = 0;
$deviceSkipped = false;

/*
 * One line per step, numbered against how many steps this run actually has.
 * The count is computed, not hard-coded, because --only and --keep-db both
 * change which steps report: a stale "[3/4]" is how a log starts lying about
 * what ran.
 */
$stepTotal = ($runSim ? ($keepDb ? 2 : 3) : 0) + ($runTests ? 1 : 0) + ($runDevice ? 1 : 0);
$stepNo    = 0;

function step(string $line): void
{
    global $stepNo, $stepTotal;
    $stepNo++;
    say('[' . $stepNo . '/' . $stepTotal . '] ' . $line);
}

// ------------------------------------------------------------------ utils

/**
 * Run a child process and capture its combined output.
 *
 * The working directory is the PROJECT ROOT, not wherever the caller stood:
 * PHPUnit reads ./phpunit.xml and the simulator resolves its own paths, so a
 * gate started from another directory must still judge the same project.
 *
 * @param list<string> $argv
 */
function run(array $argv, array $env, int $timeout, ?string &$out = null): int
{
    global $root;

    /*
     * OUTPUT GOES TO A FILE, NOT TO PIPES — and that is not a style choice.
     *
     * The suite starts its own `php -S` servers and other grandchildren. A
     * grandchild inherits the parent's pipe handles, so EOF never arrives on a
     * pipe even after PHPUnit itself has exited: the reader below would wait
     * forever on a process that finished minutes ago. A file has no such
     * notion of "still open by somebody", so we poll the child's state and read
     * the file when it is done.
     */
    $capture = tempnam(sys_get_temp_dir(), 'wpms-gate-') ?: (sys_get_temp_dir() . '/wpms-gate.log');
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $capture, 'w'], 2 => ['file', $capture, 'w']];
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows has no /dev/null; "NUL" is the equivalent sink.
        $descriptors[0] = ['file', 'NUL', 'r'];
    }
    $proc = @proc_open($argv, $descriptors, $pipes, $root, $env);
    if (!is_resource($proc)) {
        @unlink($capture);
        $out = 'could not start: ' . implode(' ', $argv);
        return 127;
    }

    $deadline = microtime(true) + $timeout;
    $code     = null;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            @proc_terminate($proc, 9);
            usleep(200000);
            @proc_close($proc);
            $out = (string) @file_get_contents($capture) . "\n[preflight] timed out after {$timeout}s\n";
            @unlink($capture);
            return 124;
        }
        usleep(150000);
    }
    @proc_close($proc);
    $out = (string) @file_get_contents($capture);
    @unlink($capture);
    return $code;
}

function port_open(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
    if (is_resource($socket)) {
        fclose($socket);
        return true;
    }
    return false;
}

/** @return string|false the body, or false when the request did not answer 200 */
function http_probe(string $url): string|false
{
    $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return false;
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    return $status === 200 ? $body : false;
}

function tail(string $text, int $lines = 12): string
{
    $parts = preg_split('/\R/', trim($text)) ?: [];
    return implode("\n", array_slice($parts, -$lines));
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

/** Run a child process with this script's environment plus overrides. */
function env_for(array $overrides): array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    foreach ($overrides as $key => $value) {
        $env[$key] = (string) $value;
    }
    return $env;
}

// A PHP without the PDO MySQL driver cannot talk to the database at all; say so
// once, clearly, instead of failing three steps later inside a seeder.
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "This PHP has no pdo_mysql extension ({$php}).\n"
        . "Point the gate at a PHP that does, e.g. C:\\xampp\\php\\php.exe.\n");
    exit(2);
}
// The device step reuses a server that is already listening (it starts its own
// when nothing is), so a busy port is only fatal for the steps that must bind it.
if (port_open($port) && $runSim) {
    fwrite(STDERR, "Port {$port} is already in use, so the throwaway server cannot start.\n"
        . "Run with --port=<free port>, or stop whatever is bound there.\n");
    exit(2);
}

say('');
say('Everafter Planner — pre-release gate');
say('  php      ' . $php);
say('  database ' . $db . ($keepDb ? ' (kept)' : ' (rebuilt)'));
say('  port     ' . $port);
if ($runDevice) {
    say('  apk      ' . ($apkPath ?? DEFAULT_APK));
}
say('');

// ------------------------------------------------------------------ 1. seed
$dbOk = $keepDb;
if (!$runSim) {
    // With the simulation skipped there is nothing to point a scratch database
    // at, and rebuilding one would only cost a minute and drop someone's DB.
    $steps[] = ['isolated database', null, 'not needed (--only=' . $only . ')'];
} elseif ($keepDb) {
    $steps[] = ['isolated database', null, 'reused ' . $db . ' (--keep-db)'];
} else {
    step('Rebuilding the isolated database...');
    $code = run([$php, $root . '/scripts/demo_reset.php', '--yes', '--db=' . $db], env_for([]), STEP_TIMEOUT, $out);
    file_put_contents($runLog, "== demo_reset ==\n" . $out . "\n", FILE_APPEND);
    $dbOk = $code === 0;
    $steps[] = ['isolated database', $dbOk, $dbOk ? 'seeded ' . $db : 'demo_reset failed (exit ' . $code . ')'];
    if (!$dbOk) {
        say(tail($out, 15));
        say('');
        say('RESULT: FAIL — the seed could not be built, so nothing after it is meaningful.');
        exit(1);
    }
}

// ------------------------------------------------------------- 2. simulation
$simRan = false;
if ($runSim) {
    step('Starting the throwaway server and walking every role...');
    @unlink($simLog);
    $serverEnv = env_for(['WPMS_DB_NAME' => $db]);
    $server = @proc_open(
        [$php, '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $simLog, 'a'], 2 => ['file', $simLog, 'a']],
        $serverPipes,
        $root,
        $serverEnv
    );
    if (!is_resource($server)) {
        fwrite(STDERR, "Could not start php -S on port {$port}.\n");
        exit(2);
    }
    // The server reads no stdin; closing it keeps the child from waiting on us.
    if (isset($serverPipes[0]) && is_resource($serverPipes[0])) {
        fclose($serverPipes[0]);
    }

    $ready = false;
    $readyUntil = microtime(true) + READY_TIMEOUT;
    while (microtime(true) < $readyUntil) {
        if (http_probe('http://127.0.0.1:' . $port . '/api/v1/?r=ping') !== false) {
            $ready = true;
            break;
        }
        usleep(400000);
    }

    try {
        if (!$ready) {
            $steps[] = ['role simulation', false, 'the dev server never answered on port ' . $port];
            say('  the server never answered; see ' . $simLog);
            $exit = 1;
        } else {
            $code = run([
                $php, $root . '/scripts/simulate_roles.php', 'http://127.0.0.1:' . $port,
                '--log=' . $simLog, '--out=' . $simJson,
            ], env_for(['WPMS_DB_NAME' => $db]), STEP_TIMEOUT, $out);
            file_put_contents($runLog, "== simulate_roles ==\n" . $out . "\n", FILE_APPEND);
            $simRan = true;

            // The simulator writes machine-readable rows; read the tally from
            // those rather than scraping its console decoration.
            $pass = $fail = $skip = 0;
            $json = is_file($simJson) ? json_decode((string) file_get_contents($simJson), true) : null;
            if (is_array($json) && isset($json['rows']) && is_array($json['rows'])) {
                foreach ($json['rows'] as $row) {
                    $status = strtoupper((string) ($row['status'] ?? ''));
                    if ($status === 'PASS') {
                        $pass++;
                    } elseif ($status === 'FAIL') {
                        $fail++;
                    } elseif ($status === 'SKIP') {
                        $skip++;
                    }
                }
                $detail = $pass . ' passed / ' . $fail . ' failed / ' . $skip . ' skipped';
                if ($fail > 0) {
                    $detail .= "\n" . tail((string) preg_replace('/^\s+/m', '  ', $out), 20);
                }
                $steps[] = ['role simulation', $fail === 0, $detail];
                if ($fail > 0) {
                    $exit = 1;
                }
            } else {
                $steps[] = ['role simulation', false, 'the simulator wrote no result JSON (exit ' . $code . ')'];
                $exit = 1;
            }
            if ($code !== 0 && $fail === 0) {
                // Exit 2 means the harness could not reach the server or refused
                // the database; that is an environment failure, not a pass.
                $steps[] = ['role simulation exit', false, 'simulate_roles exited ' . $code];
                $exit = 1;
            }

            /*
             * The flows a person actually performs, on the same server and the
             * same database: public doors, the couple's pages, a deposit that
             * must reach the ledger and ring the planner's bell. Cheap, and it
             * is the check that caught a bell-badge selector the app shell had
             * left behind.
             */
            step('Walking the browser flows a person performs...');
            $code = run(
                [$php, $root . '/scripts/smoke.php', 'http://127.0.0.1:' . $port],
                env_for(['WPMS_DB_NAME' => $db]),
                STEP_TIMEOUT,
                $out
            );
            file_put_contents($runLog, "== smoke ==\n" . $out . "\n", FILE_APPEND);
            $smokeDetail = '';
            if (preg_match('/^(\d+) passed, (\d+) failed\s*$/m', $out, $m)) {
                $smokeDetail = $m[1] . ' passed / ' . $m[2] . ' failed';
            }
            if ($code !== 0) {
                $smokeDetail .= ($smokeDetail !== '' ? "\n" : '')
                    . tail((string) preg_replace('/^\s+/m', '  ', $out), 20);
            }
            $steps[] = ['browser flows (smoke)', $code === 0, $smokeDetail !== '' ? $smokeDetail : 'exit ' . $code];
            if ($code !== 0) {
                $exit = 1;
            }
        }
    } finally {
        @proc_terminate($server, 9);
        @proc_close($server);
        // Wait for the port to be released so a re-run in the same terminal is safe.
        for ($i = 0; $i < 25 && port_open($port); $i++) {
            usleep(200000);
        }
    }
}

// ------------------------------------------------------------------ 3. suite
if ($runTests) {
    step('Running the full PHPUnit suite...');
    // Deliberately NO WPMS_DB_NAME here: phpunit.xml points the suite at its own
    // wpms_test database, and the suite mutates what it touches. Inheriting this
    // gate's scratch database would make it mutate a different one by accident.
    $code = run(
        [$php, $root . '/vendor/bin/phpunit', '--testsuite', 'WPMS Kenya', '--no-coverage'],
        env_for([]),
        STEP_TIMEOUT,
        $out
    );
    file_put_contents($runLog, "== phpunit ==\n" . $out . "\n", FILE_APPEND);
    // "OK (761 tests, 6262 assertions)" or "Tests: 761, Assertions: … , Failures: 1"
    // PHPUnit writes CRLF on Windows, so a bare `$` after `)` never matches;
    // `\s*$` eats the carriage return instead of losing the summary line.
    $summary = '';
    if (preg_match('/^(?:OK \([^)]*\)|Tests: .*)\s*$/m', $out, $m)) {
        $summary = trim($m[0]);
    }
    if ($code !== 0) {
        $summary .= ($summary !== '' ? "\n" : '') . tail((string) preg_replace('/^\s+/m', '  ', $out), 20);
    }
    $steps[] = ['PHPUnit suite', $code === 0, $summary !== '' ? $summary : 'exit ' . $code];
    if ($code !== 0) {
        $exit = 1;
    }
}

// --------------------------------------------------------------- 5. device
if ($runDevice) {
    step('Installing the APK on the device and signing in...');
    $devJson = $tmpBase . 'wpms_preflight_device.json';
    @unlink($devJson);

    /*
     * The app talks to this machine as 10.0.2.2 (the emulator's alias for the
     * host's loopback); device_smoke starts its own server on the port when
     * nothing is listening there, which is why this step can also run alone
     * (`--only=device`) after a build.
     */
    $argv = [
        $php, $root . '/scripts/device_smoke.php',
        '--port=' . $port,
        '--db=' . $db,
        '--api-base=http://10.0.2.2:' . $port,
        '--json=' . $devJson,
    ];
    if ($apkPath !== null) {
        $argv[] = '--apk=' . $apkPath;
    }
    if ($requireDevice) {
        $argv[] = '--require-device';
    }

    $code = run($argv, env_for([]), STEP_TIMEOUT, $out);
    file_put_contents($runLog, "== device_smoke ==\n" . $out . "\n", FILE_APPEND);

    $dev = is_file($devJson) ? json_decode((string) file_get_contents($devJson), true) : null;
    $detail = '';
    if (is_array($dev)) {
        $detail = (int) ($dev['passed'] ?? 0) . ' checks passed / ' . (int) ($dev['failed'] ?? 0) . ' failed';
        if ((string) ($dev['serial'] ?? '') !== '') {
            $detail .= ' on ' . $dev['serial'];
        }
    }

    if ($code === 2) {
        // Nothing to test: no adb, no device or no APK. Shown as [--] and
        // repeated under RESULT, because a release that was never installed on
        // anything must not look like one that was.
        $deviceSkipped = true;
        $reason = is_array($dev) && is_array($dev['notes'] ?? null) && $dev['notes'] !== []
            ? (string) end($dev['notes'])
            : 'no device and no APK to test';
        $steps[] = ['device smoke', null, $reason];
    } else {
        if ($code !== 0) {
            $detail .= ($detail !== '' ? "\n" : '')
                . tail((string) preg_replace('/^\s+/m', '  ', $out), 24);
        }
        $steps[] = ['device smoke', $code === 0, $detail !== '' ? $detail : 'exit ' . $code];
        if ($code !== 0) {
            $exit = 1;
        }
    }
} elseif ($skipDevice) {
    // Only ever the Gradle release flow: it runs this half BEFORE the APK
    // exists and hands step 5 to `releaseDeviceCheck` afterwards.
    $steps[] = ['device smoke', null, 'deferred (--skip-device): releaseDeviceCheck installs the APK after packaging'];
}
say('');
say('-------------------------------------------------------------');
foreach ($steps as [$label, $ok, $detail]) {
    $mark = $ok === true ? '[ok]  ' : ($ok === null ? '[--]  ' : '[FAIL]');
    say('  ' . $mark . ' ' . str_pad($label, 22) . $detail);
}
say('-------------------------------------------------------------');
if ($exit === 0) {
    say('RESULT: PASS - this build is cleared to ship.');
    if (!$runTests) {
        say('        (PHPUnit was skipped: --only=' . $only . ')');
    }
    if (!$runSim) {
        say('        (the role simulation was skipped: --only=' . $only . ')');
    }
    if ($deviceSkipped) {
        say('        (the device check was skipped - no APK or no attached device, so this');
        say('         build was never installed on anything: --require-device makes it a failure)');
    }
    if ($skipDevice) {
        say('        (the device check was deferred: --skip-device - run --only=device once the APK exists)');
    }
    say('');
    exit(0);
}
say('RESULT: FAIL - fix the step(s) marked FAIL above; nothing was missed by accident.');
say('        Full output: ' . $runLog);
say('        Server log: ' . $simLog);
say('');
exit($exit === 0 ? 0 : 1);
