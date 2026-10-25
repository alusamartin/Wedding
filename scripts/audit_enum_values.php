<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Audit sweep E — every status/enum value code reacts to but nothing writes.
 *
 * Sweep D asks the column-level question: is this column read but never written
 * (D1b), and is every branch that compares it dead (D2)? D2 stops at the first
 * dead value, only understands `=`/`==` against a literal, and says nothing
 * about the values a column's own declaration advertises.
 *
 * This sweep asks the same question one level down and across the WHOLE live
 * schema, value by value. For every status- or enum-shaped column it builds two
 * sets:
 *
 *   reacted — literals the code tests the column against: SQL filters
 *             (`= 'x'`, `<> 'x'`, `IN ('x','y')`, a filter value bound by name),
 *             PHP comparisons (`===`, `!==`), allow-lists
 *             (`in_array($row->col, [...])`, a switch case, a match arm) and
 *             label maps (`['yes' => 'Attending'][$row->col]`).
 *   written — literals the code stores into the column: INSERT values, UPDATE
 *             SET values (inline, or bound by name to a literal), and
 *             object-property assignments. The column's own DEFAULT counts,
 *             because the database fills it in itself.
 *
 * A reacted value outside the written set is a value the code is prepared for
 * and no code path can produce — a heading, a badge, a filter or an entire
 * branch reserved for a state that cannot occur. Every such value is reported,
 * not just the first one in a column.
 *
 * Which columns count. The value universe comes from the schema, so this is
 * schema-wide and needs no hand list: every ENUM or SET column, declared values
 * and all. Columns whose NAME is status-shaped (…_status, …_type, priority,
 * purpose, is_*, …) are included too, because most of this schema's state flags
 * are tinyint or varchar rather than ENUM. A column with no reaction is not
 * reported: nothing reacts, so there is nothing to be unreachable.
 *
 * Resolving a "dynamic" write. This codebase writes literals through variables,
 * through terminating allow-list guards, and through bound parameters —
 * `$status = "confirm"; $detail->status = $status;`, `if (!in_array($s,
 * ['yes','no'])) return; $g->rsvp_status = $s;`, and `SET status = :s` with
 * `[':s' => 'active']`. Treating all of that as unknowable would leave most of
 * the schema unjudged, so a small, deliberately-conservative constant
 * propagation runs first, per function: a variable resolves to the finite set of
 * values it can hold when every path to the use stores one of a known set — a
 * literal, a ternary/coalesce whose branches are all closed, a cast of one,
 * another such variable, or a value a terminating allow-list guard proved. A
 * `$_POST` read, a function call, a concatenation, a parameter with no guard, or
 * any other non-closed assignment makes the variable unknown from that point on.
 *
 * Precision over recall, deliberately, and the same refusals D makes:
 *
 *   - A column with any unresolved write — a value from a form, a bound
 *     parameter whose expression is not closed — is NOT judged. Calling those
 *     values unreachable would be a false positive. Those values are listed
 *     under "not judged" with the write site, so the gap is visible rather than
 *     silent, and they never fail the build.
 *   - A column nothing writes at all is left to sweep D1b, which already
 *     reports the column as a whole; repeating its every value here would be
 *     the same finding four times over. Counted, not reported.
 *   - A PHP property is only attributed to a column when exactly one table in
 *     the schema has a column of that name AND a class declares that property
 *     for that table. `status` exists in eight tables and `is_active` in three,
 *     so a bare `$row->status === 'x'` is skipped rather than guessed at; SQL is
 *     attributed through the statement's own alias map, so a qualified
 *     reference (`tblaccounts_detail.status = 'pending'`) always is.
 *
 * What it does not attempt, and why: bound parameters are only resolved when the
 * SQL and its parameter array are arguments of the SAME call (`$db->execute(sql,
 * [...])`). The dominant `$sql = …; $stmt = $db->prepare($sql);
 * $stmt->execute([...])` shape is deliberately left unresolved rather than
 * paired by guesswork: a wrong pairing would credit a value the column cannot
 * hold, and a sweep that hides the values it exists to find is worse than one
 * that names what it could not decide. Interprocedural values (what a caller
 * passes a parameter) are out of scope for the same reason. Guards only count as
 * DIRECT statements of a function body, where they demonstrably dominate the
 * write; nested ones are ignored.
 *
 * Whatever is knowingly left standing lives in ACCEPTED_VALUES with a reason,
 * is PRINTED on every run with every value it excuses, and FAILS the build the
 * moment it excuses nothing (E4) — an acceptance must not outlive its debt.
 *
 * usage: php scripts/audit_enum_values.php [--verbose] [--root=<dir>]
 *
 * --root scans a different tree (default: this repository) while the value
 * universe still comes from the live database named by WPMS_DB_NAME. That is
 * how tests/EnumValueSweepTest points it at tests/fixtures/enums and proves it
 * fails on a deliberately unreachable value — the negative half of its own
 * test.
 */

require __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/database.php';
require_once __DIR__ . '/../admin/include/db_object.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\ParserFactory;

/*
 * ---------------------------------------------------------------------------
 * E1 registry — values knowingly reacted to with no writer, and why.
 *
 * Keyed `table.column=value`. A bare `table.column` key covers every value of
 * that column. Every entry is printed on each run; E4 fails the build once the
 * value it excuses becomes writable, so the list cannot silently rot into a
 * blind spot.
 * ---------------------------------------------------------------------------
 */
const ACCEPTED_VALUES = [
    'tblguest.rsvp_status' => 'The RSVP answer is the only value a guest writes, and it arrives from their own '
        . 'tokenised page: Guest::record_rsvp() validates it against the enum before the UPDATE. '
        . 'The write is therefore real but not statically enumerable, and the flow is covered end to end '
        . 'by the role harness (rsvp.php → record_rsvp → tblguest.rsvp_status).',
];

/**
 * The registry key covering this (table, column, value), or null. The exact
 * `table.column=value` is preferred, then the whole column, then the table.
 */
function accepted_key(string $table, string $col, string $value): ?string
{
    foreach ([$table . '.' . $col . '=' . $value, $table . '.' . $col, $table] as $key) {
        if (isset(ACCEPTED_VALUES[$key])) {
            return $key;
        }
    }
    return null;
}

/* Column names that carry state even when they are not declared ENUM. */
const STATUS_NAME_PATTERN = '/(^|_)(status|stage|type|category|priority|purpose|level|mode|gender|kind|result)$/';

$verbose = in_array('--verbose', $argv, true);
$rootArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $rootArg = substr($arg, 7);
    }
}

// ------------------------------------------------------------------- schema
global $db;
$db = new Database();

/** Values declared by an ENUM/SET column, or [] for any other type. */
function enum_values(string $type): array
{
    if (!preg_match('/^(enum|set)\((.*)\)$/is', trim($type), $m)) {
        return [];
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[2], $v);
    return array_map(static fn (string $s): string => str_replace("''", "'", $s), $v[1]);
}

$schema = [];        // tbl => col => ['enum'=>?array,'status_like'=>bool,'generated'=>bool,'default'=>?string]
$columnTables = [];  // col => [tables that have it] — a property is only attributed when this has one entry
$declaredValues = 0;
$universeColumns = 0;
foreach ($db->fetchAll('SHOW TABLES') as $row) {
    $arr = (array) $row;
    $t = (string) current($arr);
    $schema[$t] = [];
    foreach ($db->fetchAll("SHOW COLUMNS FROM `{$t}`") as $col) {
        $name    = strtolower((string) $col->Field);
        $type    = (string) $col->Type;
        $extra   = strtolower((string) ($col->Extra ?? ''));
        $default = strtolower((string) ($col->Default ?? ''));
        $enum    = enum_values($type);
        $like    = $enum !== [] || preg_match(STATUS_NAME_PATTERN, $name) === 1 || str_starts_with($name, 'is_');
        $schema[$t][$name] = [
            // Written by the database itself, not by a page: id, timestamp
            // columns, anything auto-updated. None carries a value a role
            // chooses, so none belongs in this sweep.
            'generated'   => str_contains($extra, 'auto_increment')
                || str_contains($extra, 'default_generated')
                || str_contains($extra, 'on update')
                || str_starts_with($default, 'current_timestamp')
                || in_array($name, ['created_at', 'updated_at', 'updated_on', 'last_attempt_at'], true),
            'enum'        => $enum === [] ? null : $enum,
            'status_like' => $like,
            'default'     => $col->Default === null ? null : (string) $col->Default,
        ];
        $columnTables[$name][] = $t;
        if ($like && !$schema[$t][$name]['generated']) {
            $universeColumns++;
            $declaredValues += count($enum);
        }
    }
}
foreach ($columnTables as $col => $tables) {
    $columnTables[$col] = array_values(array_unique($tables));
}

// ------------------------------------------------------- project file lists
$root = realpath($rootArg ?? __DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "!! --root={$rootArg} does not exist\n");
    exit(2);
}
// Exclusions are relative to the scanned root, not the filesystem: with
// --root=<fixture> the whole point is to scan a tree that lives under tests/.
$rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
$isExcluded = static function (string $p) use ($rootPrefix): bool {
    $rel = '/' . str_replace($rootPrefix, '', str_replace('\\', '/', $p));
    foreach (['/vendor/', '/tests/', '/.git/', '/node_modules/', '/migrations/', '/docs/'] as $skip) {
        if (str_contains($rel, $skip)) {
            return true;
        }
    }
    // The sweeps quote schema names, enum values and comparisons by
    // construction, so their own source would be read as application state
    // (`match ($node->type)` once attributed itself to tblgallery.type).
    if (str_contains($rel, '/scripts/audit_')) {
        return true;
    }
    return !str_ends_with($rel, '.php');
};

$appFiles = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $f) {
    $abs = str_replace('\\', '/', (string) $f->getPathname());
    if (!$isExcluded($abs)) {
        $appFiles[] = $f->getPathname();
    }
}

// class => table (for resolving `self::$db_table` in concat SQL), and
// table => the field names a class declares for it — the second half is what
// stops an unrelated object that happens to own a `type` property from being
// read as a write to tblgallery.type.
$classNames = [];
$classFields = [];
foreach (array_merge(glob($root . '/admin/include/*.php') ?: [], glob($root . '/admin/include/**/*.php') ?: []) as $file) {
    $code = file_get_contents($file) ?: '';
    if (!preg_match_all('/class\s+(\w+)(\s+extends\s+DB_Object)?\s*\{(.*?)\n\}/s', $code, $cm, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($cm as $c) {
        if (!preg_match('/\$db_table\s*=\s*["\'](\w+)["\']/', $c[3], $tm)) {
            continue;
        }
        $table = $tm[1];
        $classNames[$c[1]] = $table;
        $fields = [];
        if (preg_match('/\$db_table_fields\s*=\s*(?:array\(|\[)(.*?)(?:\)|\])/s', $c[3], $fm)) {
            preg_match_all('/["\'](\w+)["\']/', $fm[1], $fn);
            $fields = $fn[1];
        }
        if (preg_match_all('/public\s+(?:[?\\\w|]+\s+)?\$(\w+)\s*;/', $c[3], $pm)) {
            $fields = array_merge($fields, $pm[1]);
        }
        $classFields[$table] = array_values(array_unique(array_merge(
            $classFields[$table] ?? [],
            array_map('strtolower', $fields)
        )));
    }
}

// --------------------------------------------------------------- collectors
$writes = [];   // "tbl.col" => ['literals'=>[],'open'=>bool,'openAt'=>?string,'at'=>string]
$reacts = [];   // "tbl.col" => value => ['at'=>string,'how'=>string,'n'=>int]

function norm(mixed $v): string
{
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if ($v === null) { return 'NULL'; }
    if (is_numeric($v)) { return (string) (0 + $v); }
    return (string) $v;
}

function note_write(array &$writes, string $key, mixed $literal, bool $isLiteral, string $at): void
{
    $writes[$key] ??= ['literals' => [], 'open' => false, 'openAt' => null, 'at' => $at];
    if ($isLiteral) {
        $writes[$key]['literals'][] = norm($literal);
    } else {
        $writes[$key]['open'] = true;
        $writes[$key]['openAt'] ??= $at;
    }
}

function note_react(array &$reacts, string $key, mixed $value, string $at, string $how): void
{
    $value = norm($value);
    if ($value === '') {
        return;
    }
    if (!isset($reacts[$key][$value])) {
        $reacts[$key][$value] = ['at' => $at, 'how' => $how, 'n' => 0];
    }
    $reacts[$key][$value]['n']++;
}

/** Split a top-level "a, b, c" list, ignoring commas inside quotes/parens. */
function split_columns(string $list): array
{
    $out = [];
    $depth = 0;
    $cur = '';
    $q = '';
    $len = strlen($list);
    for ($i = 0; $i < $len; $i++) {
        $ch = $list[$i];
        if ($q !== '') {
            $cur .= $ch;
            if ($ch === $q && ($i === 0 || $list[$i - 1] !== '\\')) { $q = ''; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; }
        if ($ch === ')') { $depth--; }
        if ($ch === ',' && $depth === 0) { $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') { $out[] = trim($cur); }
    return $out;
}

/** [isLiteral, value] for a SQL fragment. */
function literal_of(string $frag): array
{
    $frag = trim($frag, " \t\n\r()");
    if (preg_match('/^-?\d+(\.\d+)?$/', $frag)) {
        return [true, str_contains($frag, '.') ? (float) $frag : (int) $frag];
    }
    if (preg_match("/^'(.*)'$/s", $frag, $m) || preg_match('/^"(.*)"$/s', $frag, $m)) {
        return [true, str_replace("''", "'", $m[1])];
    }
    return [false, null];
}

/** Every table named after FROM/JOIN/INTO/UPDATE, lowercased → real name. */
function tables_in(string $sql, array $schema): array
{
    $out = [];
    if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            foreach ($schema as $known => $_) {
                if (strcasecmp($known, $mm[1]) === 0) { $out[strtolower($known)] = $known; }
            }
        }
    }
    return $out;
}

/** alias => real table, from FROM/JOIN/INTO/UPDATE. */
function alias_map(string $sql, array $schema): array
{
    $map = [];
    if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?(\s+(?:AS\s+)?(\w+))?/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $real = null;
            foreach ($schema as $known => $_) {
                if (strcasecmp($known, $mm[1]) === 0) { $real = $known; }
            }
            if ($real === null) { continue; }
            $map[strtolower($mm[1])] = $real;
            if (!empty($mm[3]) && !in_array(strtoupper($mm[3]), ['SET', 'VALUES', 'WHERE', 'ON', 'GROUP', 'ORDER', 'LIMIT', 'LEFT', 'RIGHT', 'INNER', 'AS', 'AND', 'OR', 'HAVING'], true)) {
                $map[strtolower($mm[3])] = $real;
            }
        }
    }
    return $map;
}

/**
 * What a variable can hold at line $line, or null when that cannot be known.
 *
 * Three kinds of evidence, in the order they can be believed:
 *   - the last closed assignment before the use (only when no non-closed
 *     assignment follows it);
 *   - a terminating allow-list guard, `if (!in_array($x, [...literals...]))
 *     { return; }`, that sits AFTER the last assignment and BEFORE the use —
 *     the value must satisfy every such guard, so the guards intersect;
 *   - both, in which case the value must satisfy both.
 */
function resolve_variable(string $name, array $scope, int $line): ?array
{
    $lastClosed  = $scope['closed'][$name] ?? null;
    $lastDynamic = $scope['dynamic'][$name] ?? null;
    $lastAssign  = max($lastClosed['line'] ?? 0, $lastDynamic ?? 0);

    $guardedSet = null;
    foreach ($scope['guards'][$name] ?? [] as $guard) {
        if ($guard['line'] <= $lastAssign || $guard['line'] >= $line) { continue; }
        $guardedSet = $guardedSet === null
            ? $guard['values']
            : array_values(array_intersect($guardedSet, $guard['values']));
    }

    // An assignment after the guard re-opens the value; only assignments that
    // are still the newest one count.
    $assigned = $lastDynamic !== null && $lastDynamic > ($lastClosed['line'] ?? 0)
        ? null
        : ($lastClosed['values'] ?? null);

    if ($assigned === null) {
        return $guardedSet === [] ? null : $guardedSet;
    }
    if ($guardedSet === null) {
        return $assigned;
    }
    $both = array_values(array_intersect($assigned, $guardedSet));
    return $both === [] ? null : $both;
}

/**
 * The values a write expression can produce, exactly. Null means "not closed" —
 * something in the expression (a form read, a call, an ungated parameter) can
 * hold any value of the column's type, and the caller must treat the column as
 * unjudged.
 *
 * A closed expression contributes ALL of its literals: the branch is not
 * guessed, it is enumerated. `$state = $row->is_published ? 0 : 1` writes 0 and
 * 1, and nothing else, so both are written values.
 */
function closed_literals(Node $n, array $scope, int $line): ?array
{
    if ($n instanceof String_) { return [$n->value]; }
    if ($n instanceof LNumber) { return [$n->value]; }
    if ($n instanceof Node\Expr\UnaryMinus && $n->expr instanceof LNumber) { return [-$n->expr->value]; }
    if ($n instanceof Node\Expr\ConstFetch) {
        $name = strtolower($n->name->toString());
        if ($name === 'true') { return [1]; }
        if ($name === 'false') { return [0]; }
        return null;
    }
    if ($n instanceof Node\Expr\Variable && is_string($n->name)) {
        return resolve_variable($n->name, $scope, $line);
    }
    if ($n instanceof Node\Expr\Cast) {
        $inner = closed_literals($n->expr, $scope, $line);
        if ($inner === null) { return null; }
        $class = get_class($n);
        $kind = match (substr($class, (int) strrpos($class, '\\') + 1)) {
            'Int_' => 'int', 'Double' => 'double', 'String_' => 'string', 'Bool_' => 'bool',
            default => null,   // array/object/unset casts: no comparable value
        };
        if ($kind === null) { return null; }
        $out = [];
        foreach ($inner as $v) {
            $out[] = match ($kind) {
                'int' => (int) $v,
                'double' => (float) $v,
                'string' => (string) $v,
                'bool' => (bool) $v,
            };
        }
        return $out;
    }
    if ($n instanceof Node\Expr\Ternary) {
        // Only the branches matter: whatever the condition is, the result is one
        // of them. `$a ?: $b` takes its value from the condition, so both count.
        $left  = $n->if !== null ? closed_literals($n->if, $scope, $line) : closed_literals($n->cond, $scope, $line);
        $right = closed_literals($n->else, $scope, $line);
        return ($left === null || $right === null) ? null : array_merge($left, $right);
    }
    if ($n instanceof Node\Expr\Coalesce) {
        $left  = closed_literals($n->left, $scope, $line);
        $right = closed_literals($n->right, $scope, $line);
        return ($left === null || $right === null) ? null : array_merge($left, $right);
    }
    return null;
}

/**
 * Pass 1 of constant propagation. Per function (notes are never shared between
 * functions, so a parameter in one is not the same variable as a local in
 * another):
 *
 *   closed  — the last assignment whose value set could be enumerated
 *   dynamic — the last assignment whose value set could not, which invalidates
 *             an earlier `closed` entry
 *   guards  — terminating allow-list checks on a variable
 *
 * Guards are what let this sweep see a validated form field at all:
 * `if (!in_array($status, ['yes','no','maybe'], true)) { return false; }` proves
 * that every path past it stores one of those three, even though `$status`
 * itself arrived from a request. Only DIRECT statements of the body count —
 * inside a nested block a check may not dominate the write, and a sweep that
 * guesses there would hide the dead values it exists to find.
 */
final class ScopeVisitor extends NodeVisitorAbstract
{
    public array $scopes = [0 => ['closed' => [], 'dynamic' => [], 'guards' => []]];
    private array $stack = [0];
    private int $next = 1;

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\FunctionLike) {
            $id = $this->next++;
            $this->scopes[$id] = ['closed' => [], 'dynamic' => [], 'guards' => []];
            $this->stack[] = $id;
            foreach ($node->getStmts() ?? [] as $stmt) {
                $guard = self::guard_of($stmt);
                if ($guard !== null) {
                    $this->scopes[$id]['guards'][$guard[0]][] = ['values' => $guard[1], 'line' => $guard[2]];
                }
            }
            return;
        }
        if ($node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
        ) {
            $id   = end($this->stack);
            $name = $node->var->name;
            $line = $node->getStartLine();
            $values = closed_literals($node->expr, $this->scopes[$id], $line);
            if ($values === null) {
                $this->scopes[$id]['dynamic'][$name] = $line;
                unset($this->scopes[$id]['closed'][$name]);
                return;
            }
            if (($this->scopes[$id]['dynamic'][$name] ?? 0) > $line) {
                return;   // a newer dynamic assignment already spoke for this name
            }
            $this->scopes[$id]['closed'][$name] = ['values' => array_values(array_unique($values)), 'line' => $line];
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\FunctionLike && count($this->stack) > 1) {
            array_pop($this->stack);
        }
    }

    /**
     * `if (!in_array($x, ['a','b'], true)) { return; }` → past it, $x is one of
     * those literals. Null for any other statement shape: this must not fire on
     * `if (in_array($x, [...])) { … }`, where the opposite is proven.
     *
     * @return array{0:string,1:array,2:int}|null  [variable, values, line]
     */
    private static function guard_of(Node $stmt): ?array
    {
        if (!$stmt instanceof Node\Stmt\If_) {
            return null;
        }
        $cond = $stmt->cond;
        $isFalse = static fn (Node $n): bool => $n instanceof Node\Expr\ConstFetch
            && strtolower($n->name->toString()) === 'false';
        if ($cond instanceof Node\Expr\BinaryOp\Equal || $cond instanceof Node\Expr\BinaryOp\Identical) {
            if ($isFalse($cond->right)) { $cond = $cond->left; }
            elseif ($isFalse($cond->left)) { $cond = $cond->right; }
        }
        if ($cond instanceof Node\Expr\BooleanNot) {
            $cond = $cond->expr;
        }
        if (!$cond instanceof Node\Expr\FuncCall
            || !$cond->name instanceof Node\Name
            || strtolower($cond->name->toString()) !== 'in_array'
            || !isset($cond->args[0], $cond->args[1])
            || !$cond->args[0] instanceof Node\Arg
            || !$cond->args[1] instanceof Node\Arg
        ) {
            return null;
        }
        $var  = $cond->args[0]->value;
        $list = $cond->args[1]->value;
        if (!$var instanceof Node\Expr\Variable || !is_string($var->name) || !$list instanceof Node\Expr\Array_) {
            return null;
        }
        $values = [];
        foreach ($list->items as $item) {
            if ($item === null || $item->value === null) { return null; }
            if ($item->value instanceof String_) { $values[] = $item->value->value; continue; }
            if ($item->value instanceof LNumber) { $values[] = $item->value->value; continue; }
            return null;   // a non-literal member cannot be enumerated
        }
        // The failing path must end here, or a later statement still runs with
        // an unconstrained value.
        $body = $stmt->stmts;
        if ($body === []) {
            return null;
        }
        foreach ($body as $inner) {
            if ($inner instanceof Node\Stmt\Return_
                || $inner instanceof Node\Stmt\Throw_
                || $inner instanceof Node\Stmt\Break_
                || $inner instanceof Node\Stmt\Continue_
                || $inner instanceof Node\Expr\Exit_
                || ($inner instanceof Node\Stmt\Expression && $inner->expr instanceof Node\Expr\Exit_)
            ) {
                continue;
            }
            return null;
        }
        return [$var->name, array_values(array_unique($values)), $stmt->getStartLine()];
    }
}

/** The property a value-test reads, unwrapping the coalesce this codebase wraps it in. */
function prop_name(Node $n): ?string
{
    if ($n instanceof PropertyFetch || $n instanceof NullsafePropertyFetch) {
        return $n->name instanceof Node\Identifier ? $n->name->toString() : null;
    }
    if ($n instanceof Node\Expr\Coalesce) {
        return prop_name($n->left);
    }
    if ($n instanceof Node\Expr\Cast) {
        return prop_name($n->expr);
    }
    return null;
}

/** The SQL text of a string/concat expression, with class tables resolved. */
function sql_text_of(Node $n, array $classNames, array $classStack): ?string
{
    $parts = [];
    $walk = static function (Node $node) use (&$walk, &$parts, $classNames, $classStack): void {
        if ($node instanceof String_) { $parts[] = $node->value; return; }
        if ($node instanceof Node\Expr\BinaryOp\Concat) { $walk($node->left); $walk($node->right); return; }
        if ($node instanceof Node\Expr\StaticPropertyFetch) {
            $name = $node->name instanceof Node\VarLikeIdentifier ? $node->name->toString() : '';
            if ($name === 'db_table') {
                $class = $classStack !== [] ? end($classStack) : '';
                $parts[] = $classNames[$class] ?? '?';
                return;
            }
            $parts[] = '?';
            return;
        }
        $parts[] = '?';
    };
    if (!$n instanceof String_ && !$n instanceof Node\Expr\BinaryOp\Concat) {
        return null;
    }
    $walk($n);
    $sql = implode('', $parts);
    return preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql) ? $sql : null;
}

final class ValueVisitor extends NodeVisitorAbstract
{
    /** @var array<string, array{sql:string,line:int,params:array}> keyed line|text, so a call and its own string don't double-report */
    public array $sql = [];
    public array $assign = [];     // prop => ['values'=>?array,'line'=>int]  (null values = not closed)
    public array $react = [];      // prop => [['value'=>..,'line'=>int,'how'=>..], ...]
    private array $scopeStack = [0];   // scope ids, matching ScopeVisitor's numbering
    private array $classStack = [];    // enclosing class names, for 'self::$db_table'
    private int $nextScope = 1;

    public function __construct(private array $classNames, private array $scopes) {}

    private function scope(): array
    {
        return $this->scopes[end($this->scopeStack)] ?? ['closed' => [], 'dynamic' => [], 'guards' => []];
    }

    private function add_sql(string $sql, int $line, array $params): void
    {
        $key = $line . '|' . $sql;
        if (isset($this->sql[$key]) && $this->sql[$key]['params'] !== [] && $params === []) {
            return;   // keep the richer entry
        }
        $this->sql[$key] = ['sql' => $sql, 'line' => $line, 'params' => $params];
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->classStack[] = $node->name !== null ? $node->name->toString() : '';
            return;
        }
        if ($node instanceof Node\FunctionLike) {
            $this->scopeStack[] = $this->nextScope++;
            return;
        }

        // ---- a call that carries both SQL and a literal parameter array
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\FuncCall) {
            $sqlArg = null;
            $sqlText = null;
            $params = [];
            foreach ($node->args as $arg) {
                if (!$arg instanceof Node\Arg) { continue; }
                if ($sqlArg === null) {
                    $text = sql_text_of($arg->value, $this->classNames, $this->classStack);
                    if ($text !== null) {
                        $sqlArg = $arg->value;
                        $sqlText = $text;
                        continue;
                    }
                }
                if ($arg->value instanceof Node\Expr\Array_) {
                    foreach ($arg->value->items as $i => $item) {
                        if ($item === null || $item->value === null) { continue; }
                        $values = closed_literals($item->value, $this->scope(), $item->value->getStartLine());
                        $key = is_string($item->key) && $item->key instanceof String_
                            ? ltrim($item->key->value, ':')
                            : (string) $i;
                        $params[$key] = $values;
                    }
                }
            }
            if ($sqlText !== null) {
                $this->add_sql($sqlText, $node->getStartLine(), $params);
            }
        }

        // ---- a bare SQL string (its own statement, no parameters in sight)
        if ($node instanceof String_ && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $node->value)) {
            $this->add_sql($node->value, $node->getStartLine(), []);
            return;
        }

        // ---- writes: property assignment, valued through the resolved set
        if ($node instanceof Node\Expr\Assign && $node->var instanceof PropertyFetch) {
            $name = $node->var->name instanceof Node\Identifier ? $node->var->name->toString() : null;
            if ($name !== null) {
                $this->assign[$name][] = [
                    'values' => closed_literals($node->expr, $this->scope(), $node->getStartLine()),
                    'line'   => $node->getStartLine(),
                ];
            }
            return;
        }

        // ---- reactions: $row->col === 'x' / !== / == / !=
        if ($node instanceof Node\Expr\BinaryOp) {
            $isTest = $node instanceof Node\Expr\BinaryOp\Equal
                || $node instanceof Node\Expr\BinaryOp\Identical
                || $node instanceof Node\Expr\BinaryOp\NotEqual
                || $node instanceof Node\Expr\BinaryOp\NotIdentical;
            if ($isTest) {
                foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$propSide, $litSide]) {
                    $prop = prop_name($propSide);
                    if ($prop === null || !$litSide instanceof String_ && !$litSide instanceof LNumber) { continue; }
                    $this->react[$prop][] = [
                        'value' => $litSide instanceof String_ ? $litSide->value : $litSide->value,
                        'line'  => $node->getStartLine(),
                        'how'   => 'comparison',
                    ];
                }
            }
            return;
        }

        // ---- reactions: in_array($row->col, [...]) allow-lists
        if ($node instanceof Node\Expr\FuncCall) {
            $fn = $node->name instanceof Node\Name ? strtolower($node->name->toString()) : '';
            if (in_array($fn, ['in_array', 'array_key_exists', 'array_search'], true)) {
                $prop = isset($node->args[0]) && $node->args[0] instanceof Node\Arg ? prop_name($node->args[0]->value) : null;
                $list = isset($node->args[1]) && $node->args[1] instanceof Node\Arg ? $node->args[1]->value : null;
                if ($prop !== null && $list instanceof Node\Expr\Array_) {
                    foreach ($list->items as $item) {
                        if ($item === null || $item->value === null) { continue; }
                        $scalar = $fn === 'array_key_exists' ? ($item->key ?? $item->value) : $item->value;
                        if (!$scalar instanceof String_ && !$scalar instanceof LNumber) { continue; }
                        $this->react[$prop][] = [
                            'value' => $scalar instanceof String_ ? $scalar->value : $scalar->value,
                            'line'  => $node->getStartLine(),
                            'how'   => 'allow-list',
                        ];
                    }
                }
            }
            return;
        }

        // ---- reactions: switch ($row->col) { case 'x': }
        if ($node instanceof Node\Stmt\Switch_) {
            $prop = prop_name($node->cond);
            if ($prop !== null) {
                foreach ($node->cases as $case) {
                    if ($case->cond === null || (!$case->cond instanceof String_ && !$case->cond instanceof LNumber)) { continue; }
                    $this->react[$prop][] = [
                        'value' => $case->cond instanceof String_ ? $case->cond->value : $case->cond->value,
                        'line'  => $node->getStartLine(),
                        'how'   => 'switch case',
                    ];
                }
            }
            return;
        }

        // ---- reactions: match ($row->col) { 'x' => … }
        if ($node instanceof Node\Expr\Match_) {
            $prop = prop_name($node->cond);
            if ($prop !== null) {
                foreach ($node->arms as $arm) {
                    foreach ($arm->conds ?? [] as $cond) {
                        if (!$cond instanceof String_ && !$cond instanceof LNumber) { continue; }
                        $this->react[$prop][] = [
                            'value' => $cond instanceof String_ ? $cond->value : $cond->value,
                            'line'  => $node->getStartLine(),
                            'how'   => 'match arm',
                        ];
                    }
                }
            }
            return;
        }

        // ---- reactions: ['yes' => …, 'no' => …][$row->col] label maps
        if ($node instanceof Node\Expr\ArrayDimFetch && $node->var instanceof Node\Expr\Array_) {
            $prop = prop_name($node->dim);
            if ($prop !== null) {
                foreach ($node->var->items as $item) {
                    if ($item === null || $item->key === null || !$item->key instanceof String_) { continue; }
                    $this->react[$prop][] = [
                        'value' => $item->key->value,
                        'line'  => $node->getStartLine(),
                        'how'   => 'label map',
                    ];
                }
            }
            return;
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_ && $this->classStack !== []) {
            array_pop($this->classStack);
        }
        if ($node instanceof Node\FunctionLike && count($this->scopeStack) > 1) {
            array_pop($this->scopeStack);
        }
    }
}

/**
 * One SQL statement → writes and reactions, attributed to real tables.
 * $params maps a bound name to the closed value set behind it (null = unknown).
 */
function scan_sql_values(string $sql, string $at, array $schema, array &$writes, array &$reacts, array $params = []): void
{
    $tables   = tables_in($sql, $schema);
    $resolved = static function (string $name) use ($schema): ?string {
        foreach ($schema as $known => $_) {
            if (strcasecmp($known, $name) === 0) { return $known; }
        }
        return null;
    };
    /** A bound name → its value set, or null when the binding is not closed. */
    $bound = static function (string $frag) use ($params): ?array {
        $frag = trim($frag);
        if (!preg_match('/^:(\w+)$/', $frag, $m)) { return null; }
        return $params[$m[1]] ?? null;
    };
    /** Record one write of $frag to $key: literal, bound-literal set, or open. */
    $write = static function (string $key, string $frag, string $at) use (&$writes, $bound): void {
        [$isLit, $val] = literal_of($frag);
        if ($isLit) {
            note_write($writes, $key, $val, true, $at);
            return;
        }
        $values = $bound($frag);
        if ($values !== null) {
            foreach ($values as $v) { note_write($writes, $key, $v, true, $at); }
            return;
        }
        note_write($writes, $key, null, false, $at);
    };

    // ---- writes: INSERT INTO t (cols) VALUES (vals)
    if (preg_match('/\bINSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES\s*\((.*?)\)/is', $sql, $im)) {
        $table = $resolved($im[1]);
        if ($table !== null) {
            $cols = split_columns($im[2]);
            $vals = split_columns($im[3]);
            foreach ($cols as $i => $col) {
                $col = trim($col, "` \t\n\r");
                if (!preg_match('/^\w+$/', $col)) { continue; }
                $key = $table . '.' . strtolower($col);
                // A multi-row VALUES list puts more than one tuple in $vals[$i].
                if (!isset($vals[$i]) || str_contains($vals[$i], '(')) {
                    note_write($writes, $key, null, false, $at);
                    continue;
                }
                $write($key, $vals[$i], $at);
            }
        }
    }

    // ---- writes: UPDATE t SET a = 1, b = :b [WHERE …]
    if (preg_match('/\bUPDATE\s+`?(\w+)`?\s+SET\s+(.*?)(?:\s+WHERE\b|$)/is', $sql, $um)) {
        $table = $resolved($um[1]);
        if ($table !== null) {
            foreach (split_columns($um[2]) as $assignment) {
                if (!preg_match('/^`?(\w+)`?\s*=\s*(.+)$/s', trim($assignment), $am)) { continue; }
                $write($table . '.' . strtolower($am[1]), $am[2], $at);
            }
        }
    }

    // ---- reactions. An UPDATE's SET clause is a write, not a test, so drop it
    //      before looking for comparisons.
    $test = preg_replace('/\bSET\b.*?(?=\bWHERE\b|$)/is', 'SET ', $sql, 1) ?? $sql;
    $aliases = alias_map($sql, $schema);
    /** Attribute a column reference to a table, or null when it is ambiguous. */
    $column = static function (string $alias, string $col) use ($aliases, $tables, $schema): ?string {
        if ($alias !== '') {
            $table = $aliases[strtolower($alias)] ?? null;
        } else {
            $table = count($tables) === 1 ? reset($tables) : null;
        }
        if ($table === null) { return null; }
        return isset($schema[$table][strtolower($col)]) ? $table . '.' . strtolower($col) : null;
    };

    // Comparisons against a literal, qualified or in a single-table statement.
    if (preg_match_all('/\b(?:(\w+)\.)?(\w+)\s*(?:<=>|=|<>|!=|LIKE)\s*(\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|-?\d+)/i', $test, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $key = $column($mm[1] ?? '', $mm[2]);
            if ($key === null) { continue; }
            [$isLit, $val] = literal_of($mm[3]);
            if ($isLit) { note_react($reacts, $key, $val, $at, 'SQL filter'); }
        }
    }
    // Comparisons against a value bound by name: credited when the bound
    // expression is closed, ignored when it is not (an unknown filter is not
    // evidence that a value is reachable).
    if (preg_match_all('/\b(?:(\w+)\.)?(\w+)\s*(?:<=>|=|<>|!=)\s*(:\w+)/i', $test, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $key = $column($mm[1] ?? '', $mm[2]);
            if ($key === null) { continue; }
            $values = $bound($mm[3]);
            if ($values === null) { continue; }
            foreach ($values as $v) { note_react($reacts, $key, $v, $at, 'SQL filter (bound)'); }
        }
    }
    // IN / NOT IN lists.
    if (preg_match_all('/\b(?:(\w+)\.)?(\w+)\s+(?:NOT\s+)?IN\s*\(([^)]*)\)/i', $test, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $key = $column($mm[1] ?? '', $mm[2]);
            if ($key === null) { continue; }
            foreach (split_columns($mm[3]) as $frag) {
                [$isLit, $val] = literal_of($frag);
                if ($isLit) { note_react($reacts, $key, $val, $at, 'SQL IN list'); }
            }
        }
    }
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();

// A PHP property is attributed only when exactly one table in the schema has a
// column of that name AND a class declares that property for that table.
// `status` lives in eight tables and `is_active` in three, so a `$row->status
// === 'x'` is left to the SQL pass rather than guessed at; and an unrelated
// object with a `type` property is not read as a write to tblgallery.type.
$propTable = static function (string $prop) use ($columnTables, $classFields): ?string {
    $name = strtolower($prop);
    $tables = $columnTables[$name] ?? [];
    if (count($tables) !== 1) {
        return null;
    }
    return in_array($name, $classFields[$tables[0]] ?? [], true) ? $tables[0] : null;
};

foreach ($appFiles as $file) {
    $rel = str_replace('\\', '/', str_replace($root . DIRECTORY_SEPARATOR, '', $file));
    try {
        $ast = $parser->parse(file_get_contents($file) ?: '');
    } catch (Throwable $e) {
        echo "!! PARSE ERROR: {$rel}: {$e->getMessage()}\n";
        continue;
    }
    if ($ast === null) { continue; }

    // Pass 1: what can each variable in each function hold?
    $scopes = new ScopeVisitor();
    $t1 = new NodeTraverser();
    $t1->addVisitor($scopes);
    $t1->traverse($ast);

    // Pass 2: writes, reactions, SQL.
    $visitor = new ValueVisitor($classNames, $scopes->scopes);
    $t2 = new NodeTraverser();
    $t2->addVisitor($visitor);
    $t2->traverse($ast);

    foreach ($visitor->sql as $entry) {
        scan_sql_values($entry['sql'], $rel . ':' . $entry['line'], $schema, $writes, $reacts, $entry['params']);
    }
    foreach ($visitor->assign as $prop => $hits) {
        $table = $propTable($prop);
        if ($table === null) { continue; }
        $key = $table . '.' . strtolower($prop);
        if (!isset($schema[$table][strtolower($prop)])) { continue; }
        foreach ($hits as $hit) {
            if ($hit['values'] === null) {
                note_write($writes, $key, null, false, $rel . ':' . $hit['line']);
                continue;
            }
            foreach ($hit['values'] as $v) {
                note_write($writes, $key, $v, true, $rel . ':' . $hit['line']);
            }
        }
    }
    foreach ($visitor->react as $prop => $hits) {
        $table = $propTable($prop);
        if ($table === null) { continue; }
        foreach ($hits as $h) {
            note_react($reacts, $table . '.' . strtolower($prop), $h['value'], $rel . ':' . $h['line'], $h['how']);
        }
    }
}

// ------------------------------------------------------------- E1 + E2 + E3
$findings     = [];
$acceptedHits = [];
$acceptedUsed = [];   // which ACCEPTED_VALUES key excused something (E4)
$unproven     = [];   // [key, value, how+site, why it could not be proven dead]
$writtenSet   = [];   // "tbl.col" => [normalized values] once judged
$literalsByKey = [];  // "tbl.col" => everything known to be written, judged or not
$reactedTotal = 0;
$judgedColumns = 0;
$openColumns  = [];
$unwrittenColumns = [];

foreach ($schema as $table => $cols) {
    foreach ($cols as $col => $meta) {
        if ($meta['generated']) { continue; }
        if (!$meta['status_like'] && $meta['enum'] === null) { continue; }
        $key = $table . '.' . $col;
        $reacted = $reacts[$key] ?? [];
        if ($reacted === []) { continue; }
        $reactedTotal += count($reacted);

        // Written values this sweep can account for: literals the code stores,
        // plus the column's own DEFAULT, which the database fills in itself.
        $evidence = $writes[$key] ?? null;
        $literals = $evidence === null ? [] : array_values(array_unique($evidence['literals']));
        if ($meta['default'] !== null && $meta['default'] !== '') {
            $literals[] = norm($meta['default']);
        }
        $literals = array_values(array_unique($literals));

        $why = null;
        if ($evidence === null) {
            // Nothing this sweep can attribute writes the column, for one of two
            // reasons — and they are worth telling apart, because one of them is
            // a limit of this sweep and the other is a finding of its own.
            $unwrittenColumns[] = $key;
            $shared = count($columnTables[$col] ?? []) > 1;
            $why = $shared
                ? 'written only through a `' . $col . '` property name that ' . count($columnTables[$col])
                    . ' tables share, so this sweep will not attribute a writer to it'
                : 'nothing in the project writes it (sweep D checks the column as a whole)';
        } elseif ($evidence['open']) {
            // A write resolves to a value the sweep cannot enumerate, so a
            // reacted value outside the literal set is not proof of anything.
            $openColumns[] = $key;
            $why = 'a write resolves to a value this sweep cannot enumerate, at '
                . ($evidence['openAt'] ?? $evidence['at']);
        }

        if ($why === null) {
            $writtenSet[$key] = $literals;
            $judgedColumns++;
        }
        $literalsByKey[$key] = $literals;

        foreach ($reacted as $valueKey => $site) {
            // PHP turns a numeric-string key into an int, so normalize it back:
            // every comparison in this sweep is made on normalized strings.
            $value = (string) $valueKey;
            if (in_array($value, $literals, true)) { continue; }
            $how = "{$site['how']} at {$site['at']}"
                . ($site['n'] > 1 ? " ({$site['n']} sites)" : '');

            // An acceptance covers a value a human has already looked at —
            // whether the sweep proved it dead or only could not account for
            // its writer. Either way it is reported, never fatal.
            $accept = accepted_key($table, $col, $value);
            if ($accept !== null) {
                $acceptedHits[] = [$key, $value, $how, ACCEPTED_VALUES[$accept]];
                $acceptedUsed[$accept] = true;
                continue;
            }
            if ($why !== null) {
                $unproven[] = [$key, $value, $how, $why];
                continue;
            }
            $findings[] = [
                $key,
                "column {$key}: value " . json_encode($value) . ' is reacted to but never written',
                "reacted by {$how}; writers write " . json_encode($literals) . ' at ' . $evidence['at'],
            ];
        }
    }
}

// ------------------------------------ E4: accepted entries that outlived it
// An acceptance documents a debt. When the debt is paid the entry has to go, or
// the list quietly turns into a blind spot — so an entry that excused nothing on
// this run (its value gained a writer, or nothing reacts to it any more) fails
// the build instead of being ignored forever.
foreach (ACCEPTED_VALUES as $key => $reason) {
    if (isset($acceptedUsed[$key])) {
        continue;
    }
    $findings[] = ['accepted', "accepted entry {$key} is obsolete — it excused no value on this run "
        . '(the value now has a writer, or nothing reacts to it any more), so delete the entry and let the '
        . 'rule guard it again', $reason];
}

// ------------------------------------------------------------------- report
echo "=== SWEEP E — status/enum values code reacts to but nothing writes ===\n";
printf(
    "schema: %d tables | %d status/enum columns (%d declared enum values) | writers and readers indexed from %d files\n\n",
    count($schema),
    $universeColumns,
    $declaredValues,
    count($appFiles)
);

if ($verbose) {
    foreach ($schema as $table => $cols) {
        foreach ($cols as $col => $meta) {
            if ($meta['generated'] || (!$meta['status_like'] && $meta['enum'] === null)) { continue; }
            $key = $table . '.' . $col;
            if (!isset($reacts[$key])) { continue; }
            $written = isset($writtenSet[$key])
                ? json_encode($writtenSet[$key])
                : (($writes[$key]['open'] ?? false) ? 'dynamic writer (not judged)' : 'no writer (sweep D)');
            printf(
                "%s  %-46s reacted %-3d written %s\n",
                isset($writtenSet[$key]) ? '  ' : '??',
                $key,
                count($reacts[$key]),
                $written
            );
            if ($meta['enum'] !== null) {
                $untouched = array_values(array_diff($meta['enum'], array_map('strval', array_keys($reacts[$key]))));
                if ($untouched !== []) {
                    printf("     declared but never reacted to: %s\n", json_encode($untouched));
                }
            }
        }
    }
    echo "\n";
}

// Accepted values are grouped by the entry that covers them: one reason, every
// value it excuses, so the list stays readable as it grows.
$acceptedByKey = [];
foreach ($acceptedHits as [$key, $value, $how, $reason]) {
    $acceptedByKey[$key] ??= ['reason' => $reason, 'hits' => []];
    $acceptedByKey[$key]['hits'][] = json_encode($value) . " ({$how})";
}
foreach ($acceptedByKey as $key => $group) {
    printf("accepted  %s: %s\n          %s\n", $key, implode(', ', $group['hits']), $group['reason']);
}
foreach ($findings as [$key, $msg, $detail]) {
    printf("!! %s: %s\n     %s\n", $key, $msg, $detail);
}
if ($unproven !== []) {
    echo "-- not judged: a reacted value whose writer this sweep cannot enumerate. Listed, never fatal.\n";
    foreach ($unproven as [$key, $value, $how, $why]) {
        printf("?  %s = %s: reacted by %s — %s\n", $key, json_encode($value), $how, $why);
    }
}

echo "\n";
printf(
    "reacted values: %d in %d judged columns | not judged: %d values in %d columns | columns skipped: %d with a dynamic writer, %d with no attributable writer\n",
    $reactedTotal,
    $judgedColumns,
    count($unproven),
    count(array_unique(array_column($unproven, 0))),
    count($openColumns),
    count($unwrittenColumns)
);
echo $findings === []
    ? 'SWEEP E CLEAN (' . $judgedColumns . ' columns judged, ' . count($acceptedHits) . ' accepted with a reason, '
        . count($unproven) . " values not judgeable)\n"
    : 'SWEEP E: ' . count($findings) . " PROBLEM(S)\n";
exit($findings === [] ? 0 : 1);
