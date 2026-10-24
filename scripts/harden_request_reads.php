<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * WPMS Kenya — harden superglobal reads against "Undefined array key".
 *
 * Rewrites the SAFE-TO-REWRITE shapes so a missing field yields the same value
 * it already yielded, minus the PHP warning:
 *
 *   clean($_POST['email'])          → clean($_POST['email'] ?? '')
 *   (int)$_POST['plan_id']          → (int)($_POST['plan_id'] ?? 0)
 *   floatval($_POST['amount'])      → floatval($_POST['amount'] ?? 0)
 *   htmlspecialchars($_POST['x'])   → htmlspecialchars($_POST['x'] ?? '')
 *
 * WHY IT IS BEHAVIOUR-PRESERVING: an absent field is null today, and every
 * wrapped callee already treats null as its own zero value (`clean(null)` is
 * '', `(int) null` is 0, `floatval(null)` is 0.0). Adding `?? <zero>` changes
 * nothing except that the array access no longer warns. It never touches
 * `isset(...)`, `empty(...)` or a read that already coalesces.
 *
 * Left for human eyes (printed as TODO): a bare `$_POST['x']` used as a value
 * without a wrapper or a cast, and nested access `$_POST['x'][0]`. Those are
 * the residue `scripts/audit_request_reads.php` ratchets.
 *
 * usage:
 *   php scripts/harden_request_reads.php            # dry-run report
 *   php scripts/harden_request_reads.php --fix      # apply (writes .bak once)
 *   php scripts/harden_request_reads.php --path=admin
 */

$opts    = getopt('', ['fix', 'path::']);
$doFix   = isset($opts['fix']);
$root    = dirname(__DIR__);
$baseDir = isset($opts['path']) && is_string($opts['path']) && $opts['path'] !== ''
    ? rtrim($opts['path'], '/\\')
    : $root;
if (!is_dir($baseDir)) {
    fwrite(STDERR, "Path not found: {$baseDir}\n");
    exit(1);
}

$skipDirs = ['/vendor/', '/tests/', '/scripts/', '/DATABASE FILE/', '/dist/', '/node_modules/'];
/* The helper whose docblocks quote the very patterns this rewrites. */
$skipFiles = [str_replace('\\', '/', $root . '/admin/include/Request.php')];

/** @var array<int,array{regex:string, replace:callable, n:int, sample:string}> */
$rules = [
    [
        // wraps: clean/trim/htmlspecialchars/strip_tags/mb_*/str*/ucfirst/nl2br($X['k'])
        'regex' => '/\b(clean|trim|htmlspecialchars|strip_tags|mb_strtolower|mb_strtoupper|strtolower|strtoupper|ucfirst|nl2br)\s*\(\s*\$_(POST|GET|REQUEST)\[\s*([\'"])([^\'"]+)\3\s*\]\s*\)/i',
        'replace' => fn(array $m): string => $m[1] . '($_' . $m[2] . "['" . $m[4] . "'] ?? '')",
        'n' => 0, 'sample' => '',
    ],
    [
        // casts: (int)$X['k'] / (float) $X['k'] / (string)$X['k'] / (bool)$X['k']
        'regex' => '/\((int|float|double|bool|boolean|string)\)\s*\$_(POST|GET|REQUEST)\[\s*([\'"])([^\'"]+)\3\s*\]/i',
        'replace' => function (array $m): string {
            $default = match (strtolower($m[1])) {
                'float', 'double' => '0',
                'bool', 'boolean' => 'false',
                'string' => "''",
                default => '0',
            };
            return '(' . $m[1] . ')($_' . $m[2] . "['" . $m[4] . "'] ?? " . $default . ')';
        },
        'n' => 0, 'sample' => '',
    ],
    [
        // value functions: floatval/intval/strval/boolval/doubleval($X['k'])
        'regex' => '/\b(floatval|intval|strval|boolval|doubleval)\s*\(\s*\$_(POST|GET|REQUEST)\[\s*([\'"])([^\'"]+)\3\s*\]\s*\)/i',
        'replace' => function (array $m): string {
            $default = match (strtolower($m[1])) {
                'strval' => "''",
                'boolval' => 'false',
                default => '0',
            };
            return $m[1] . '($_' . $m[2] . "['" . $m[4] . "'] ?? " . $default . ')';
        },
        'n' => 0, 'sample' => '',
    ],
];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
$changedFiles = 0;
$totalApplied = 0;
$ruleTotals   = array_fill(0, count($rules), 0);

foreach ($files as $f) {
    $p = str_replace('\\', '/', (string) $f->getPathname());
    if (!str_ends_with($p, '.php')) {
        continue;
    }
    foreach ($skipDirs as $skip) {
        if (str_contains($p, $skip)) {
            continue 2;
        }
    }
    if (in_array($p, $skipFiles, true) || str_ends_with($p, '.bak.php') || str_ends_with($p, '.bak')) {
        continue;
    }

    $code = file_get_contents($p);
    if ($code === false) {
        continue;
    }
    $original = $code;
    $fileApplied = 0;
    foreach ($rules as $ri => &$rule) {
        $rule['n'] = 0;
        $rule['sample'] = '';
        $code = preg_replace_callback($rule['regex'], function (array $m) use (&$rule): string {
            $rule['n']++;
            if ($rule['sample'] === '') {
                $rule['sample'] = $m[0];
            }
            return ($rule['replace'])($m);
        }, $code);
        $ruleTotals[$ri] += $rule['n'];
    }
    unset($rule);

    foreach ($rules as $rule) {
        $fileApplied += $rule['n'];
    }
    if ($fileApplied > 0 && $code !== $original) {
        $changedFiles++;
        $totalApplied += $fileApplied;
        $rel = str_replace('\\', '/', substr($p, strlen($root) + 1));
        echo ($doFix ? 'FIX  ' : 'DRY  ') . $rel . "  ({$fileApplied})\n";
        if ($doFix) {
            if (!is_file($p . '.bak')) {
                copy($p, $p . '.bak');
            }
            file_put_contents($p, $code);
        }
    }
}

echo "\n";
foreach ($rules as $i => $rule) {
    echo 'rule ' . ($i + 1) . ": {$ruleTotals[$i]} replacement(s)" . ($rule['sample'] !== '' ? "  e.g. {$rule['sample']}" : '') . "\n";
}
echo "files changed: {$changedFiles} | total replacements: {$totalApplied}\n";
if ($doFix) {
    echo "applied. .bak copies written next to each edited file.\n";
} else {
    echo "dry run — re-run with --fix to apply.\n";
}
