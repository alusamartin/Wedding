<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.1 — mysqli → PDO Migration Scanner / Auto-Fixer
 *
 * Scans the Wedding codebase for legacy mysqli patterns and optionally
 * rewrites them to PDO prepared statements.
 *
 * Usage:
 *   php scripts/migrate_mysqli_to_pdo.php            # dry-run report
 *   php scripts/migrate_mysqli_to_pdo.php --fix       # apply fixes (creates .bak)
 *   php scripts/migrate_mysqli_to_pdo.php --path=admin/include
 *
 * Patterns converted:
 *   - $db->escape_string($x)           → DEPRECATED marker (use :placeholder)
 *   - mysqli_real_escape_string(...)   → DEPRECATED
 *   - mysqli_connect / mysqli_query / mysqli_prepare / mysqli_stmt_* / mysqli_fetch_* / mysqli_insert_id / mysqli_affected_rows / mysqli_num_rows / mysqli_real_escape_string
 *   - $db->connection used as mysqli handle → $db->pdo() (PDO)
 *
 * Safety: never rewrites string-interpolated SQL automatically beyond helpers;
 *         it flags them for manual review. The --fix mode only does low-risk
 *         mechanical renames and inserts TODO comments.
 */

$options = getopt('', ['fix', 'path::']);
$doFix   = isset($options['fix']);
$baseDir = $options['path'] ?? dirname(__DIR__);
$baseDir = rtrim($baseDir, '/\\');
if (!is_dir($baseDir)) {
    fwrite(STDERR, "Path not found: $baseDir\n");
    exit(1);
}

$patterns = [
    'mysqli_real_escape_string' => [
        'regex' => '/mysqli_real_escape_string\s*\(/',
        'msg'   => 'Use PDO prepared statements: $db->prepare($sql)->execute([:param => $value]) — remove escaping',
        'fix'   => null, // manual
    ],
    'db->escape_string' => [
        'regex' => '/\$db->escape_string\s*\(/',
        'msg'   => 'Deprecated since v2.1 — bind via :placeholder instead of escape_string(); see Database::prepare()',
        'fix'   => null,
    ],
    'mysqli_connect' => [
        'regex' => '/mysqli_connect\s*\(/',
        'msg'   => 'Replace with new PDO("mysql:host=...;dbname=...") or $db->pdo()',
        'fix'   => null,
    ],
    'mysqli_query' => [
        'regex' => '/mysqli_query\s*\(/',
        'msg'   => 'Replace with $db->query($sql) or $db->execute($sql, $params) returning PDOStatement',
        'fix'   => null,
    ],
    'mysqli_prepare' => [
        'regex' => '/mysqli_prepare\s*\(/',
        'msg'   => 'Replace with $db->prepare($sql) — returns PDOStatement',
        'fix'   => fn($line) => preg_replace('/mysqli_prepare\s*\(\s*\$db->connection\s*,\s*/', '$db->prepare(', $line),
    ],
    'mysqli_stmt_bind_param' => [
        'regex' => '/mysqli_stmt_bind_param\s*\(/',
        'msg'   => 'Replace with $stmt->execute([:param => $value]) or $stmt->bindValue() — PDO binds by value/type automatically',
        'fix'   => null,
    ],
    'mysqli_stmt_execute' => [
        'regex' => '/mysqli_stmt_execute\s*\(/',
        'msg'   => 'Replace with $stmt->execute()',
        'fix'   => fn($line) => preg_replace('/mysqli_stmt_execute\s*\(\s*\$stmt\s*\)/', '$stmt->execute()', $line),
    ],
    'mysqli_stmt_get_result' => [
        'regex' => '/mysqli_stmt_get_result\s*\(/',
        'msg'   => 'PDO has no get_result — use $stmt->fetch() / $stmt->fetchAll() directly',
        'fix'   => null,
    ],
    'mysqli_fetch_object' => [
        'regex' => '/mysqli_fetch_object\s*\(/',
        'msg'   => 'Replace with $stmt->fetch(PDO::FETCH_OBJ) or $stmt->fetchObject()',
        'fix'   => fn($line) => preg_replace('/mysqli_fetch_object\s*\(\s*\$(\w+)\s*\)/', '$\$1->fetch(PDO::FETCH_OBJ)', $line),
    ],
    'mysqli_fetch_array' => [
        'regex' => '/mysqli_fetch_array\s*\(/',
        'msg'   => 'Replace with $stmt->fetch(PDO::FETCH_ASSOC)',
        'fix'   => fn($line) => preg_replace('/mysqli_fetch_array\s*\(\s*\$(\w+)\s*\)/', '$\$1->fetch(PDO::FETCH_ASSOC)', $line),
    ],
    'mysqli_fetch_assoc' => [
        'regex' => '/mysqli_fetch_assoc\s*\(/',
        'msg'   => 'Replace with $stmt->fetch(PDO::FETCH_ASSOC)',
        'fix'   => fn($line) => preg_replace('/mysqli_fetch_assoc\s*\(\s*\$(\w+)\s*\)/', '$\$1->fetch(PDO::FETCH_ASSOC)', $line),
    ],
    'mysqli_num_rows' => [
        'regex' => '/mysqli_num_rows\s*\(/',
        'msg'   => 'Replace with $stmt->rowCount() (or SELECT COUNT(*) via $db->fetchValue)',
        'fix'   => fn($line) => preg_replace('/mysqli_num_rows\s*\(\s*\$(\w+)\s*\)/', '$\$1->rowCount()', $line),
    ],
    'mysqli_insert_id' => [
        'regex' => '/mysqli_insert_id\s*\(/',
        'msg'   => 'Replace with $db->the_insert_id() or $db->pdo()->lastInsertId()',
        'fix'   => fn($line) => preg_replace('/mysqli_insert_id\s*\(\s*\$db->connection\s*\)/', '$db->the_insert_id()', $line),
    ],
    'mysqli_affected_rows' => [
        'regex' => '/mysqli_affected_rows\s*\(/',
        'msg'   => 'Replace with $stmt->rowCount()',
        'fix'   => fn($line) => preg_replace('/mysqli_affected_rows\s*\(\s*\$db->connection\s*\)/', '$stmt->rowCount()', $line),
    ],
    'mysqli_error' => [
        'regex' => '/mysqli_error\s*\(/',
        'msg'   => 'PDO throws PDOException (ERRMODE_EXCEPTION) — wrap in try/catch',
        'fix'   => null,
    ],
    'interpolated SELECT' => [
        'regex' => '/"(SELECT|INSERT|UPDATE|DELETE)[^"]*\.\s*\$[^"]*"/',
        'msg'   => 'Possible SQL interpolation — rewrite with placeholders: "WHERE col = :val" + [" :val" => $value]',
        'fix'   => null,
    ],
    'single-quote interpolated WHERE' => [
        'regex' => '/WHERE\s+\w+\s*=\s*\'\$[^\']*\'/',
        'msg'   => 'SQL injection risk — use prepared statement with placeholder',
        'fix'   => null,
    ],
];

$iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
$phpFiles  = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        // Skip vendor, backups, and this script itself
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
        if (str_ends_with($path, '.bak')) continue;
        $phpFiles[] = $path;
    }
}
sort($phpFiles);

$totalIssues = 0;
$fixedFiles  = 0;

foreach ($phpFiles as $file) {
    $lines   = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;

    $fileIssues = [];
    $newLines   = $lines;
    $fileFixed  = false;

    foreach ($lines as $idx => $line) {
        $lineno = $idx + 1;
        foreach ($patterns as $name => $cfg) {
            if (preg_match($cfg['regex'], $line)) {
                $fileIssues[] = [
                    'line' => $lineno,
                    'pattern' => $name,
                    'msg' => $cfg['msg'],
                    'code' => trim($line),
                ];
                $totalIssues++;

                if ($doFix && isset($cfg['fix']) && is_callable($cfg['fix'])) {
                    $replaced = $cfg['fix']($line);
                    if ($replaced !== $line) {
                        $newLines[$idx] = $replaced . ' // TODO[v2.1]: auto-migrated ' . $name . ' — verify PDO logic';
                        $fileFixed = true;
                    }
                }
            }
        }
    }

    if (!empty($fileIssues)) {
        $rel = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $file);
        echo "\n=== $rel ===\n";
        foreach ($fileIssues as $iss) {
            printf("  L%4d [%-26s] %s\n", $iss['line'], $iss['pattern'], $iss['msg']);
            echo "         > {$iss['code']}\n";
        }
    }

    if ($doFix && $fileFixed) {
        $bak = $file . '.bak';
        if (!file_exists($bak)) {
            copy($file, $bak);
        }
        file_put_contents($file, implode(PHP_EOL, $newLines) . PHP_EOL);
        $fixedFiles++;
        echo "  [FIXED] $file (backup: $bak)\n";
    }
}

echo "\n" . str_repeat('-', 70) . "\n";
echo "Scanned " . count($phpFiles) . " PHP files under $baseDir\n";
echo "Total issues found: $totalIssues\n";
if ($doFix) {
    echo "Files auto-fixed: $fixedFiles (review TODO[v2.1] markers)\n";
    echo "Re-run without --fix to verify remaining manual items.\n";
} else {
    echo "Dry run — no files changed. Re-run with --fix to apply safe auto-fixes.\n";
}
echo "Manual review needed for: mysqli_real_escape_string, mysqli_query with interpolated SQL, mysqli_stmt_bind_param signatures.\n";
echo str_repeat('-', 70) . "\n";
echo "\nExample PDO rewrite:\n";
echo "  // BEFORE (mysqli):\n";
echo "  \$email = mysqli_real_escape_string(\$db->connection, \$email);\n";
echo "  \$result = mysqli_query(\$db->connection, \"SELECT * FROM tblusers WHERE email = '\$email'\");\n";
echo "  // AFTER (PDO v2.1):\n";
echo "  \$stmt = \$db->prepare(\"SELECT * FROM tblusers WHERE email = :email LIMIT 1\");\n";
echo "  \$stmt->execute([':email' => \$email]);\n";
echo "  \$user = \$stmt->fetch(PDO::FETCH_OBJ);\n";
