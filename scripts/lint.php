<?php
declare(strict_types=1);

/**
 * WPMS Kenya — cross-platform syntax linter.
 * Backs the `composer lint` script (replaces `find -exec php -l`, which
 * breaks on Windows shells). Run:  php scripts/lint.php [dir]
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
    }
}

echo "Linted {$checked} PHP file(s) — " . ($failed > 0 ? "{$failed} with syntax errors" : 'all clean') . PHP_EOL;
exit($failed > 0 ? 1 : 0);
