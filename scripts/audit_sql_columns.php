<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Audit sweep C — SQL column references vs the live schema.
 *
 * Walks every SQL string passed to Database::execute/fetchOne/fetchAll/
 * fetchValue/prepare (plus PDO prepare) across the project, maps table
 * aliases from FROM/JOIN/INTO/UPDATE clauses, and validates every qualified
 * alias.column / table.column reference against the live schema.
 *
 * Heuristic: catches real bugs (the historical tblusers.user_email drift)
 * with low false-positive noise (keywords, functions and bare identifiers
 * are skipped; only qualified references are checked).
 *
 * usage: php scripts/audit_sql_columns.php
 */

require __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Expr\BinaryOp\Concat;

global $db;
$db = new Database();

// Live schema: table => [lowercase column => true]
$schema = [];
foreach ($db->fetchAll('SHOW TABLES') as $row) {
    $arr = (array) $row;
    $t = (string) current($arr);
    $schema[$t] = [];
    foreach ($db->fetchAll("SHOW COLUMNS FROM `{$t}`") as $col) {
        $schema[$t][strtolower($col->Field)] = true;
    }
}
$tableLookup = [];
foreach (array_keys($schema) as $t) {
    $tableLookup[strtolower($t)] = $t;
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();

$projectFiles = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));
foreach ($files as $f) {
    $p = str_replace('\\', '/', (string) $f->getPathname());
    if (str_contains($p, '/vendor/') || str_contains($p, '/tests/') || !str_ends_with($p, '.php')) {
        continue;
    }
    $projectFiles[] = $p;
}

/** Flatten a node's string parts (plain strings + concatenations). */
function sql_strings(Node $node): array
{
    $out = [];
    if ($node instanceof String_) {
        $out[] = $node->value;
    } elseif ($node instanceof Concat) {
        return array_merge(sql_strings($node->left), sql_strings($node->right));
    } elseif ($node instanceof Node\Expr\BinaryOp) {
        // heredoc-ish or other binops with a string on one side
        foreach ([$node->left, $node->right] as $side) {
            if ($side instanceof String_) {
                $out[] = $side->value;
            }
        }
    }
    return $out;
}

$errors = 0;
$checked = 0;

$visitor = new class($schema, $tableLookup) extends NodeVisitorAbstract {
    public function __construct(private array $schema, private array $tableLookup) {}

    public function enterNode(Node $node): void
    {
        // Any string node: cheap pre-filter — does it look like SQL?
        $parts = sql_strings($node);
        if ($parts === []) {
            return;
        }
        $sql = implode('', $parts);
        if (!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) {
            return;
        }
        if (count($parts) === 1 && !$node instanceof String_ && !$node instanceof Concat) {
            return; // only leaf strings / concats carry full SQL text
        }
        $this->audit($sql, $node);
    }

    private function audit(string $sql, Node $node): void
    {
        global $errors, $checked;
        $checked++;

        // 1. alias => table map from FROM/JOIN/INTO/UPDATE clauses.
        $aliasMap = [];
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $t = $this->tableLookup[strtolower($mm[1])] ?? null;
                if ($t === null) {
                    continue;
                }
                $aliasMap[$mm[1]] = $t;          // table itself
                if (!empty($mm[2]) && !in_array(strtoupper($mm[2]), ['SET', 'VALUES', 'WHERE', 'ON', 'GROUP', 'ORDER', 'LIMIT', 'LEFT', 'RIGHT', 'INNER', 'AS'], true)) {
                    $aliasMap[$mm[2]] = $t;      // its alias
                }
            }
        }

        // 2. qualified column refs: alias.column (skip aliases we couldn't map,
        //    skip .* , skip numeric/string literals after a dot in strings).
        if (preg_match_all('/\b(\w+)\.(\w+)\b/', $sql, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                [, $alias, $col] = $c;
                $table = $aliasMap[$alias] ?? $aliasMap[strtolower($alias)] ?? null;
                if ($table === null) {
                    continue; // unknown alias (subquery alias, etc.) — skip
                }
                if ($col === '*' || str_contains($col, '*')) {
                    continue;
                }
                if (!isset($this->schema[$table][strtolower($col)])) {
                    $file = $GLOBALS['current_file'] ?? '?';
                    $line = $node->getStartLine();
                    echo "!! {$file}:{$line}: `{$alias}.{$col}` — {$table} has no column {$col}\n";
                    $errors++;
                }
            }
        }

        // 3. INSERT column lists: INSERT INTO tbl (col, col, ...) — unqualified
        //    by nature, validated directly against the target table.
        if (preg_match('/\bINSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]*)\)/is', $sql, $im)) {
            $table = $this->tableLookup[strtolower($im[1])] ?? null;
            if ($table !== null) {
                foreach (array_map('trim', explode(',', $im[2])) as $col) {
                    $col = trim($col, "` \t\n\r");
                    if ($col === '' || !preg_match('/^\w+$/', $col)) {
                        continue;
                    }
                    if (!isset($this->schema[$table][strtolower($col)])) {
                        $file = $GLOBALS['current_file'] ?? '?';
                        $line = $node->getStartLine();
                        echo "!! {$file}:{$line}: INSERT INTO {$table} ({$col}) — {$table} has no column {$col}\n";
                        $errors++;
                    }
                }
            }
        }
    }
};

$GLOBALS['current_file'] = '';
foreach ($projectFiles as $file) {
    $GLOBALS['current_file'] = $file;
    try {
        $ast = $parser->parse(file_get_contents($file));
    } catch (Throwable $e) {
        echo "!! PARSE ERROR: {$file}: {$e->getMessage()}\n";
        $errors++;
        continue;
    }
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);
}

echo "\nChecked {$checked} SQL statement(s).\n";
echo $errors === 0 ? "SWEEP C CLEAN\n" : "SWEEP C: {$errors} BAD COLUMN REFERENCE(S)\n";
exit($errors === 0 ? 0 : 1);
