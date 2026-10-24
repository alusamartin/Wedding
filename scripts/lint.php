<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * WPMS Kenya — cross-platform syntax linter.
 * Backs the `composer lint` script (replaces `find -exec php -l`, which
 * breaks on Windows shells). Run:  php scripts/lint.php [dir]
 *
 * Two checks per file:
 *   1. `php -l` — the file is valid PHP at all.
 *   2. HTML comments are CLOSED with `-->`. A comment authored with the PHP
 *      block-comment terminator instead of the HTML one never ends, so
 *      everything after it on the page — the layout, the tables, the forms —
 *      is swallowed as comment text and the browser renders a broken page
 *      while every string-based test still passes. That is not a hypothetical:
 *      it happened to admin/include/sidebar.php, and no sweep, no test and no
 *      `php -l` noticed, because the bytes are all legal.
 *
 * @param string|array $dirs Directory (or dirs) to scan for *.php files.
 */

$defaultDirs = ['admin'];
$dirs = $argv[1] ?? null;
if ($dirs !== null) {
    $dirs = [$dirs];
} else {
    $dirs = $defaultDirs;
}

$checked = 0;
$failed  = 0;
$markup  = 0;

/**
 * HTML comments opened and closed with the wrong terminator.
 *
 * @return array<int, string> "<file>:<line>: remark" per offending comment
 */
function html_comment_faults(string $path): array
{
    $source = (string) file_get_contents($path);
    $faults = [];
    $cursor = 0;

    while (($open = strpos($source, '<!--', $cursor)) !== false) {
        $close = strpos($source, '-->', $open);
        $star  = strpos($source, '*/', $open);
        if ($star !== false && ($close === false || $star < $close)) {
            $line = substr_count(substr($source, 0, $star), "\n") + 1;
            $faults[] = $path . ':' . $line . ': HTML comment closed with */ — it never ends, so the browser swallows the rest of the page';
        }
        $cursor = $open + 4;
    }

    return $faults;
}

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "Not a directory: {$dir}\n");
        exit(2);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $checked++;
        exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $failed++;
            echo implode(PHP_EOL, $output), PHP_EOL;
        }
        $output = [];

        foreach (html_comment_faults($file->getPathname()) as $fault) {
            $markup++;
            echo $fault, PHP_EOL;
        }
    }
}

$problems = $failed + $markup;
echo "Linted {$checked} PHP file(s) — "
    . ($problems > 0
        ? $failed . ' with syntax errors, ' . $markup . ' with unterminated HTML comments'
        : 'all clean')
    . PHP_EOL;
exit($problems > 0 ? 1 : 0);
