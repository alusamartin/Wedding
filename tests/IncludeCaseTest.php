<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every literal require/include must match the file's case ON DISK.
 *
 * WHY THIS EXISTS. Windows resolves `Database.php` to `database.php`; Linux
 * does not. The project is deployed to cPanel — a case-sensitive filesystem —
 * and nineteen runtime/CLI files (both M-Pesa callbacks, every cron job, the
 * audits) required `admin/include/Database.php` while the tracked file was
 * `admin/include/database.php`. Local development was green and production
 * would have been a fatal on every one of them: no payment verification, no
 * scheduled job, no email retry. `scripts/*` and the test harness had it too.
 *
 * A grep for "Database.php" cannot catch this class of bug on a Windows
 * machine, which is why the check compares each include against the directory
 * listing (scandir returns the real spelling on every platform) rather than
 * asking the filesystem whether the path exists.
 *
 * Scope: literal paths only. A path built from a variable is resolved at
 * runtime and is not this test's business — the class-name-to-file mapping
 * that the test harness needs is spelled out where it is used.
 */
final class IncludeCaseTest extends TestCase
{
    /** Directories never scanned: third-party code, history, dependencies. */
    private const SKIP_DIRS = ['vendor', '.git', 'node_modules'];

    public function testEveryLiteralIncludeResolvesWithTheCaseOnDisk(): void
    {
        $root  = dirname(__DIR__);
        $index = $this->diskIndex($root);   // lowercased relative path => exact relative path

        self::assertNotSame([], $index, 'the disk index is empty — the scan is broken, not the code');

        $mismatches = [];
        foreach ($this->phpFiles($root) as $abs) {
            $rel   = $this->relative($root, $abs);
            $lines = @file($abs, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $i => $line) {
                if (!preg_match('/\b(?:require|include)(?:_once)?\b/', $line)) {
                    continue;
                }
                // A commented-out include is not a dependency.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (!preg_match_all('/[\'"]([^\'"]*\.php)[\'"]/', $line, $m)) {
                    continue;
                }
                foreach ($m[1] as $literal) {
                    if (str_contains($literal, '$')) {
                        continue;                       // built at runtime
                    }
                    $resolved = $this->resolve($root, dirname($abs), $literal);
                    if ($resolved === null) {
                        continue;                       // outside the project (vendor/, a docroot path)
                    }
                    $onDisk = $index[strtolower($resolved)] ?? null;
                    if ($onDisk === null || $onDisk === $resolved) {
                        // Absent is a different sweep (a missing file), and an
                        // exact match is the happy path.
                        continue;
                    }
                    $mismatches[] = sprintf(
                        '%s:%d includes "%s", but on disk it is "%s" — a fatal on Linux/cPanel',
                        $rel,
                        $i + 1,
                        $literal,
                        $onDisk
                    );
                }
            }
        }

        self::assertSame(
            [],
            $mismatches,
            "Includes must match the file's case exactly:\n  " . implode("\n  ", $mismatches)
        );
    }

    /**
     * Resolve one literal include to a project-relative path, or null when it
     * is not a project path at all.
     *
     * Two shapes exist in this codebase:
     *   require_once __DIR__ . '/../admin/include/database.php';  → relative to the file
     *   require_once $root . '/admin/include/Helper.php';         → root-relative
     * The leading-slash form is treated as root-relative, which is what every
     * caller means by it.
     */
    private function resolve(string $root, string $fileDir, string $literal): ?string
    {
        $literal = str_replace('\\', '/', $literal);
        $base    = str_starts_with($literal, '/') ? '' : str_replace('\\', '/', $fileDir);
        $parts   = [];
        foreach (explode('/', $base . '/' . $literal) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    return null;        // climbed above the project root
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $relative = implode('/', $parts);
        // The root itself is not part of the relative path.
        $rootRel = ltrim(str_replace('\\', '/', $root), '/');
        if ($rootRel !== '' && str_starts_with($relative, $rootRel . '/')) {
            $relative = substr($relative, strlen($rootRel) + 1);
        }
        foreach (self::SKIP_DIRS as $skip) {
            if (str_starts_with($relative, $skip . '/')) {
                return null;
            }
        }
        return $relative === '' ? null : $relative;
    }

    private function relative(string $root, string $abs): string
    {
        $abs  = str_replace('\\', '/', $abs);
        $root = str_replace('\\', '/', $root);
        return ltrim(str_starts_with($abs, $root) ? substr($abs, strlen($root)) : $abs, '/');
    }

    /**
     * Lowercased relative path => the path exactly as the directory lists it.
     *
     * @return array<string, string>
     */
    private function diskIndex(string $root): array
    {
        $index = [];
        $stack = [''];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $abs = $dir === '' ? $root : $root . '/' . $dir;
            $entries = @scandir($abs);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $rel = $dir === '' ? $entry : $dir . '/' . $entry;
                if (is_dir($abs . '/' . $entry) && !is_link($abs . '/' . $entry)) {
                    if (in_array($entry, self::SKIP_DIRS, true)) {
                        continue;
                    }
                    $stack[] = $rel;
                    continue;
                }
                $index[strtolower($rel)] = $rel;
            }
        }
        return $index;
    }

    /** @return string[] absolute paths of project PHP files */
    private function phpFiles(string $root): array
    {
        $files = [];
        $stack = [''];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $abs = $dir === '' ? $root : $root . '/' . $dir;
            $entries = @scandir($abs);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $rel = $dir === '' ? $entry : $dir . '/' . $entry;
                if (is_dir($abs . '/' . $entry) && !is_link($abs . '/' . $entry)) {
                    if (in_array($entry, self::SKIP_DIRS, true)) {
                        continue;
                    }
                    $stack[] = $rel;
                    continue;
                }
                if (str_ends_with($entry, '.php')) {
                    $files[] = $abs . '/' . $entry;
                }
            }
        }
        return $files;
    }
}
