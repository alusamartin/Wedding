<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * One-command code coverage, with driver detection.
 *
 * Coverage is not installed on every machine — XAMPP ships neither Xdebug in
 * coverage mode nor PCOV, and production images never have either. So this
 * wrapper asks the ONE question that matters first ("is there a driver?") and
 * answers it in a sentence a developer can act on, instead of letting PHPUnit
 * print an unhelpful "No code coverage driver available" from the middle of its
 * own output.
 *
 *   php scripts/coverage.php                 # text summary to the terminal
 *   php scripts/coverage.php --html=build/coverage   # also an HTML report
 *   php scripts/coverage.php --clover=build/clover.xml
 *   php scripts/coverage.php -- --filter HumanDatesTest   # args after -- go to phpunit
 *
 * Exit codes: 0 when the suite ran (with or without a report), 2 when no driver
 * is available (so CI can tell "coverage was not measured" apart from "tests
 * failed"), and whatever PHPUnit returns otherwise.
 *
 * Composer: composer coverage [-- --html=...]
 */

$root = dirname(__DIR__);
$phpunit = $root . '/vendor/bin/phpunit';

// ------------------------------------------------------------- args
$argvIn = array_slice($argv, 1);
$html = null;
$clover = null;
$passthrough = [];
for ($i = 0; $i < count($argvIn); $i++) {
    $a = $argvIn[$i];
    if ($a === '--') {
        $passthrough = array_merge($passthrough, array_slice($argvIn, $i + 1));
        break;
    }
    if (str_starts_with($a, '--html=')) {
        $html = substr($a, 7);
    } elseif (str_starts_with($a, '--clover=')) {
        $clover = substr($a, 9);
    } else {
        $passthrough[] = $a;
    }
}

// ----------------------------------------------------------- driver
$hasPcov   = extension_loaded('pcov');
$hasXdebug = extension_loaded('xdebug');
$xdebugMode = $hasXdebug ? (string) ini_get('xdebug.mode') : '';

$driver = null;
if ($hasPcov && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOLEAN)) {
    $driver = 'pcov';
} elseif ($hasXdebug && str_contains($xdebugMode, 'coverage')) {
    $driver = 'xdebug';
} elseif ($hasXdebug) {
    // Loaded but not collecting: the common XAMPP case. Fixable without a
    // reinstall — XDEBUG_MODE is read at request start, so a re-exec sets it.
    $driver = 'xdebug-needs-mode';
}

if ($driver === null) {
    fwrite(STDERR, "No code coverage driver is available.\n\n");
    fwrite(STDERR, "Install one of:\n");
    fwrite(STDERR, "  PCOV (fast, recommended):  pecl install pcov\n");
    fwrite(STDERR, "    then add to php.ini:     extension=pcov\n                             pcov.enabled=1\n\n");
    fwrite(STDERR, "  Xdebug (already common):   install xdebug, then run coverage in coverage mode:\n");
    fwrite(STDERR, "                             XDEBUG_MODE=coverage php scripts/coverage.php\n");
    fwrite(STDERR, "                             (Windows PowerShell:  \$env:XDEBUG_MODE='coverage'; php scripts\\coverage.php)\n");
    exit(2);
}

if ($driver === 'xdebug-needs-mode') {
    // Re-exec ourselves with coverage mode on (XDEBUG_MODE is read per process).
    $env = getenv();
    $env['XDEBUG_MODE'] = 'coverage';
    fwrite(STDERR, "[coverage] Xdebug is loaded without coverage mode — re-running with XDEBUG_MODE=coverage\n");
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    foreach ($argvIn as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $proc = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $env);
    exit(is_resource($proc) ? (int) proc_close($proc) : 1);
}

if (!is_file($phpunit)) {
    fwrite(STDERR, "[coverage] phpunit not found at $phpunit — run 'composer install' first.\n");
    exit(1);
}

// -------------------------------------------------------------- run
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
    . ' --coverage-text' . ($html !== null ? ' --coverage-html=' . escapeshellarg($html) : '')
    . ($clover !== null ? ' --coverage-clover=' . escapeshellarg($clover) : '');
foreach ($passthrough as $a) {
    $cmd .= ' ' . escapeshellarg($a);
}

// Keep XDEBUG_MODE=coverage set for the child whatever the driver.
putenv('XDEBUG_MODE=coverage');

fwrite(STDERR, "[coverage] driver: {$driver}\n");
$code = 0;
passthru($cmd, $code);
exit($code);
