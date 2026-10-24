<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Audit sweep G — SQL is bound, or it does not run.
 *
 * Sweeps A–F check what the SQL says (columns, enum values, promises, fixtures).
 * This one checks how it gets to the server, because that is the part a reader
 * of the SQL cannot see: a value interpolated into an otherwise constant
 * statement executes exactly like a bound one, and the call site looks the same.
 *
 * The three rules, and the door each one closes:
 *
 *   G1 — THE LEGACY DOOR.  A call to `query()`, `executeQuery()`, `setQuery()`,
 *        `loadResultList()` or `escape_string()` on the Database wrapper fails
 *        the build. These are the mysqli-era execution APIs: `query()` used to
 *        run a parameterless string through `PDO::query()` (never prepared), the
 *        setQuery/executeQuery pair did the same with a statement stored on the
 *        object, and escape_string() exists to paste a quoted value into SQL by
 *        hand. Every one of them is now either retired (the setQuery trio, which
 *        had no callers left) or an alias of execute() — and the alias is not to
 *        be used either: execute()/fetchOne()/fetchAll()/fetchValue() say what
 *        the caller wants without ever naming the unprepared path.
 *
 *   G2 — THE REQUEST SURFACE.  On a page that answers an HTTP request —
 *        `admin/**.php` and the public root — a call to `query()` or `exec()` on
 *        a RAW handle (`$pdo`, `$this->pdo`, `$x->pdo()`, `$x->connection`) fails.
 *        That is the route that survives every wrapper change, so it is named
 *        here explicitly. `scripts/*` are excluded: they are CLI maintenance
 *        tools that build their own handle for introspection and backups, they
 *        never serve a request, and G1 still holds them to the wrapper's door.
 *
 *   G3 — REQUEST DATA INSIDE SQL TEXT.  A superglobal ($_GET, $_POST, $_REQUEST,
 *        $_COOKIE, $_SESSION, $_SERVER) appearing inside the SQL string handed to
 *        prepare/execute/query/fetchAll/fetchOne/fetchValue/exec fails anywhere in
 *        the project. This is the shape of an injection rather than a wrapper
 *        decision, and it is the one an audit can see regardless of which door
 *        the statement took. (It cannot see a value routed through a local
 *        variable first; that is what binding is for, and what review is for.)
 *
 * One reasoned exception is carried in the code below rather than in a list of
 * line numbers: the connection's own `SET time_zone` command, whose statement is
 * a fixed literal set before any caller exists.
 *
 * WHAT IS NOT WALKED. The exclusion list is relative to the scanned root, which
 * is what lets `--root` point the walk at a fixture tree that lives under tests/
 * (sweep F's convention, so the negative half can be proved by running the same
 * code). `vendor/` is third-party code nobody here edits, and `tests/` holds both
 * this sweep's deliberately broken fixtures — data, not pages — and the harness
 * that asserts on them, which is not the request surface either. The shipping
 * surface (admin/**, the public root, admin/include, scripts/) is fully covered,
 * and G1 holes there are the build failure this sweep exists to raise.
 *
 * usage: php scripts/audit_unbound_sql.php [--root=<dir>] [--verbose]
 *         --root points the walk at another tree (tests/UnboundSqlSweepTest
 *         points it at tests/fixtures/unbound and asserts the findings there).
 * exit:   0 clean, 1 findings
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

$opts    = getopt('', ['root::', 'verbose']) ?: [];
$root    = isset($opts['root']) && is_string($opts['root']) && trim($opts['root']) !== ''
    ? rtrim(str_replace('\\', '/', (string) $opts['root']), '/')
    : str_replace('\\', '/', dirname(__DIR__));
$verbose = array_key_exists('verbose', $opts);

if (!is_dir($root)) {
    fwrite(STDERR, "!! --root is not a directory: {$root}\n");
    exit(1);
}

/* ---------------------------------------------------------------------------
 * The names each rule reacts to. Kept as data at the top so the report can name
 * them without repeating itself.
 * ------------------------------------------------------------------------ */
const LEGACY_DOOR = ['query', 'executeQuery', 'setQuery', 'loadResultList', 'escape_string'];
const RAW_RUNNER  = ['query', 'exec', 'execQuery'];
const SQL_ARG0    = ['query', 'executeQuery', 'prepare', 'fetchAll', 'fetchOne', 'fetchValue', 'exec'];

/*
 * `execute()` is two different methods with two different signatures: the
 * WRAPPER's takes the statement first and the values second, while
 * PDOStatement::execute() takes THE VALUES FIRST and no statement at all. So the
 * wrapper receiver decides whether arg 0 of an execute() call is SQL or a bind
 * array — treating a bind array as SQL is how an audit invents findings (the
 * values in it are request data by nature, which is exactly what it is looking
 * for in the wrong place). */

/**
 * The one reasoned exception: the connection's own timezone command.
 *
 * Written as the statement text rather than a file:line so that moving the line
 * does not silently re-allow something else — the reason is the SQL itself, and
 * a second raw command in the same file does not inherit it.
 */
const RAW_ALLOW_LIST = [
    ["SET time_zone = '+03:00'"],
];

/* ---------------------------------------------------------------------------
 * Walk
 * ------------------------------------------------------------------------ */
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$print  = new Standard();
$finder = new NodeFinder();

/**
 * Relative to the scanned root, not to the filesystem: with --root=<fixture>
 * the whole point is to scan a tree that lives under tests/.
 */
$isExcluded = static function (string $p) use ($root): bool {
    $norm = str_replace('\\', '/', $p);
    $rel  = str_starts_with($norm, $root . '/')
        ? '/' . substr($norm, strlen($root) + 1)
        : '/' . ltrim($norm, '/');
    foreach (['/vendor/', '/tests/', '/.git/', '/node_modules/', '/migrations/', '/docs/'] as $skip) {
        if (str_contains($rel, $skip)) {
            return true;
        }
    }
    return !str_ends_with($rel, '.php');
};

$files = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($walk as $f) {
    $p = str_replace('\\', '/', (string) $f->getPathname());
    if ($isExcluded($p)) {
        continue;
    }
    $files[] = $p;
}
sort($files);

/** The wrapper itself: its definitions and the allow-listed init live there. */
const WRAPPER_FILE = 'admin/include/database.php';

/** Is this receiver the Database wrapper (rather than a raw PDO handle)? */
$isWrapperReceiver = static function (string $recv): bool {
    $recv = trim($recv);
    if (in_array($recv, ['$db', '$this->db', 'self::$db', 'static::$db'], true)) {
        return true;
    }
    // $smokeDb, $smoke_db, $adminDB …  a simple variable whose name ends in "db".
    return (bool) preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*_?db$/i', $recv);
};

/**
 * The SQL text of an argument, taken from the literal's own value where there is
 * one: the pretty-printer re-quotes string literals (and escapes what is inside
 * them), so comparing its output against an allow-list compares escaping habits
 * rather than statements.
 */
$sql_text = static function (?Node $node) use ($print): string {
    if ($node instanceof Node\Scalar\String_) {
        return $node->value;
    }
    return $node === null ? '' : $print->prettyPrintExpr($node);
};

/** Is this receiver a raw driver handle? */
$isRawReceiver = static function (string $recv): bool {
    $recv = trim($recv);
    if (preg_match('/^\$pdo$/i', $recv)) {
        return true;
    }
    if (preg_match('/^(\$this|\$[A-Za-z_][A-Za-z0-9_]*|self|static)\s*->\s*(pdo|connection)\s*(\(\s*\))?$/i', $recv)) {
        return true;
    }
    return (bool) preg_match('/->\s*pdo\s*\(\s*\)$/', $recv);
};

$findings = [];      // each: ['rule' => 'G1', 'file' => …, 'line' => …, 'what' => …, 'fix' => …]
$statements = 0;     // SQL args inspected (G3)
$parsed = 0;

foreach ($files as $file) {
    $rel = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    try {
        $ast = $parser->parse((string) file_get_contents($file));
    } catch (Throwable $e) {
        $findings[] = [
            'rule' => 'G0', 'file' => $rel, 'line' => 0,
            'what' => 'file could not be parsed — ' . $e->getMessage(),
            'fix'  => 'a file the audit cannot read is a file it cannot guard',
        ];
        continue;
    }
    $parsed++;

    foreach ($finder->findInstanceOf($ast ?? [], Node\Expr\MethodCall::class) as $call) {
        if (!$call->name instanceof Node\Identifier) {
            continue;
        }
        $method = $call->name->toString();
        $recv   = $pretty = $print->prettyPrintExpr($call->var);

        // ---- G1: the wrapper's legacy door -----------------------------
        if (in_array($method, LEGACY_DOOR, true) && $isWrapperReceiver($recv) && $rel !== WRAPPER_FILE) {
            $findings[] = [
                'rule' => 'G1', 'file' => $rel, 'line' => $call->getStartLine(),
                'what' => "{$recv}->{$method}() — the legacy wrapper API",
                'fix'  => $method === 'escape_string'
                    ? 'bind the value (:placeholder) instead of quoting it into the SQL'
                    : 'use execute()/fetchOne()/fetchAll()/fetchValue() — a prepared statement with no placeholders is still prepared',
            ];
        }

        // ---- G2: the raw driver, on the request surface ----------------
        $onRequestSurface = str_starts_with($rel, 'admin/') || !str_contains($rel, '/');
        if ($onRequestSurface && in_array($method, RAW_RUNNER, true) && $isRawReceiver($recv)) {
            $sqlArg = $call->args[0]->value ?? null;
            $sql    = $sql_text($sqlArg);
            $allowed = false;
            foreach (RAW_ALLOW_LIST as $entry) {
                if (in_array($sql, $entry, true)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $findings[] = [
                    'rule' => 'G2', 'file' => $rel, 'line' => $call->getStartLine(),
                    'what' => "{$recv}->{$method}() runs SQL without preparing it",
                    'fix'  => 'prepare() + execute() on the handle — the wrapper\'s execute() is the same statement with the binding kept',
                ];
            }
        }

        // ---- G3: request data inside the SQL text ----------------------
        // arg 0 is the statement for every method here except execute(), where it
        // is the statement only on the wrapper (see SQL_ARG0 above).
        if (in_array($method, SQL_ARG0, true) || ($method === 'execute' && $isWrapperReceiver($recv))) {
            $sqlArg = $call->args[0]->value ?? null;
            if ($sqlArg === null) {
                continue;
            }
            $statements++;
            $superglobal = $finder->findFirst($sqlArg, static function (Node $n): bool {
                if (!$n instanceof Node\Expr\Variable) {
                    return false;
                }
                return in_array(is_string($n->name) ? $n->name : '', ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SESSION', '_SERVER'], true);
            });
            if ($superglobal !== null) {
                $findings[] = [
                    'rule' => 'G3', 'file' => $rel, 'line' => $call->getStartLine(),
                    'what' => "request data (\${$superglobal->name}) is inside the SQL of {$recv}->{$method}()",
                    'fix'  => 'bind it (:placeholder) — the statement text must be independent of the request',
                ];
            }
        }
    }
}

/* ---------------------------------------------------------------------------
 * Report
 * ------------------------------------------------------------------------ */
echo "=== SWEEP G — SQL is bound, or it does not run ===\n";
printf(
    "root: %s | parsed: %d of %d file(s) | SQL args inspected: %d | allow-listed raw commands: %d\n\n",
    $root, $parsed, count($files), $statements, count(RAW_ALLOW_LIST)
);

if ($verbose) {
    foreach (RAW_ALLOW_LIST as $entry) {
        printf("allow-listed  %s\n", implode(' | ', $entry));
    }
    echo "\n";
}

if ($findings === []) {
    echo "-- no call to the legacy door, no raw driver on the request surface, and no request data inside SQL text.\n";
    echo "SWEEP G CLEAN\n";
    exit(0);
}

foreach ($findings as $f) {
    // The fix is printed with every finding rather than behind --verbose: a
    // build failure nobody can act on from its own output is a second failure.
    printf("!! %s:%d  [%s] %s\n     fix: %s\n", $f['file'], $f['line'], $f['rule'], $f['what'], $f['fix']);
}
printf("\nSWEEP G: %d finding(s) — %s\n",
    count($findings),
    implode(', ', array_map(
        static fn(string $r): string => $r . '×' . count(array_filter($findings, static fn($f) => $f['rule'] === $r)),
        array_values(array_unique(array_column($findings, 'rule')))
    ))
);
exit(1);
