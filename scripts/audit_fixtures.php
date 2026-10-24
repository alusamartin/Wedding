<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Audit sweep F — test fixtures a class claims but does not own.
 *
 * The suite got a real bug from this shape: HumanDatesTest asserted a live
 * countdown for booking 215. The scratch seed builds bookings 200–204, so the
 * booking was not there at all; on the development database it *was* there, with
 * a wedding date already past, so the countdown branch was unreachable in both
 * directions — and the failure was carried as a "known baseline" instead of
 * being fixed. Nothing about the test's own setup said 215 was its fixture: the
 * id was a literal in a URL, every other fixture in the file was booking 200,
 * and the class never wrote 215.
 *
 * That is a decidable question, so this sweep asks it of every test file.
 *
 * WHAT COUNTS AS A CLAIM. A method claims a record when it asserts on the
 * response BODY of a request that carries that record's id — directly
 * (`assertStringContainsString(…, self::http(…booking_id=215…)['body'])`) or
 * through a variable assigned from one (`$res = self::http(…215…); …
 * assertStringContainsString(…, $res['body'])`). Status-only assertions are not
 * claims: `assertSame(302, self::http(…booking_id=99999999…)['status'])` is a
 * refusal probe, which is exactly what a nonexistent id is for, and dozens of
 * them are legitimate. Attributing a claim per method instead of per statement
 * buries those real cases in noise.
 *
 * WHAT COUNTS AS OWNERSHIP. A file owns a record when it writes any row keyed by
 * that id — the tables are discovered from the live schema (every column named
 * booking_id / user_id / event_id, not a hand list, so a write through
 * tblaccounts_detail or tbl_liquidation counts). The id's own constant is not
 * ownership: `private const BOOKING_ID = 200` says the fixture is seeded, which
 * is a legitimate thing to read, just not something the file can be blamed for
 * keeping alive.
 *
 * The rules:
 *
 *   F1 the claimed record does not exist in the database at all. Nothing the
 *      assertion says can be true — the page under test is rendering an empty
 *      fixture. If the read is guarded by a status check, the finding says so:
 *      that variant passes vacuously instead of failing, which is worse.
 *   F4 the claimed record exists but the file never writes it, and the assertion
 *      is a CALENDAR claim about it (a month name, a four-digit year, "to go").
 *      The record is somebody else's fixture and the claim is about the one
 *      field that rots with the clock: this is the dbwedding half of the 215
 *      bug, where the row was present and its date was stale.
 *   F2 a literal compared against an aggregate over a whole table
 *      (`assertSame(2, enquiryCount())` where the helper counts every row of
 *      tblvendor_bookings). The number describes the seed, not the behaviour:
 *      any other class that leaves a row behind changes it. A count bound to a
 *      fixture id, or compared against a baseline read in the test, is not
 *      reported, and `substr_count()` on a response body is not a table at all.
 *   F3 text asserted about that is generated dynamically in more than one place,
 *      so the assertion answers about whichever batch ran last — "probe
 *      notification 4" while two calls of the same seeding helper write "probe
 *      notification <n>". The literal must be text (not a URL or markup), the
 *      generator's stem must be the literal's own digit-stripped tail, and the
 *      helper containing the generator must be called from more than one place —
 *      otherwise the text is a fixture's own unique label, which is fine.
 *
 *   F6 the not-judged list itself is a ratchet. An entry that TOLERATED_GAPS does
 *      not cover fails the build, and a tolerated entry that excused nothing
 *      fails it too — so the sweep's own blind spot cannot widen without somebody
 *      writing down why, in the same file as the rules.
 *
 * What it deliberately does not judge, printed on every run so the gap is
 * visible rather than silent — and, since F6, fatal unless tolerated:
 *
 *   - A foreign record that exists and carries no calendar claim. Whether its
 *     seeded *state* matches the assertion (a paid balance, a status, a package
 *     price) is not decidable from the test file, and guessing would flag every
 *     legitimate read of a seeded fixture. These are listed with their reference
 *     counts; the calendar case is fatal because the clock guarantees the state
 *     changes.
 *   - Ids whose entity this sweep has no table for (`id=…` on an arbitrary page,
 *     `feature_id=…`). Without a table there is no existence check to make.
 *   - Writes made through a variable the sweep cannot resolve. Ownership is
 *     credited from the statement around an INSERT/UPDATE/DELETE on a table that
 *     carries the id — a write three calls away is not credited, and that shows
 *     up as a finding rather than as silence, which is the safe direction for an
 *     audit that fails the build.
 *
 * The ledger conventions are sweep E's: findings fail the build (exit 1),
 * ACCEPTED_FINDINGS excuses a finding only with a written reason, every
 * acceptance is printed on each run, and an acceptance that excused nothing —
 * because the finding is gone — fails the build too, so the registry cannot rot
 * into a blind spot. Acceptance and obsolete rules apply to the repository scan
 * only: a tree passed with --root exists to be broken.
 *
 * usage: php scripts/audit_fixtures.php [--verbose] [--root=<dir>]
 */

require __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/Database.php';
require_once __DIR__ . '/../admin/include/db_object.php';

/** Entity → [table, column] used when the schema cannot be read. */
const ENTITY_TABLES = [
    'booking_id' => ['tblweddingbook', 'booking_id'],
    'user_id'    => ['tblaccounts', 'user_id'],
    'event_id'   => ['tbleventbooking', 'id'],
];

/** Id-shaped columns that make a write to their table a claim of ownership. */
const ID_COLUMNS = ['booking_id', 'user_id', 'event_id'];

/**
 * Findings knowingly left standing, with the reason each one is not a bug.
 * Key: `<relative file>::<rule>::<entity>=<id>` (or `::<rule>` when the finding
 * is file-wide). Printed on every run; a key that no longer matches a finding
 * fails the build (F5).
 */
const ACCEPTED_FINDINGS = [
    // 'tests/XTest.php::F1::booking_id=215' => 'why this is not a finding',
];

/**
 * Gaps the ratchet tolerates, with the reason each one is not worth judging.
 * Key: `<repo-relative file>::<entity>=<id>`. Printed with its reason on every
 * run; an entry that excused nothing — because the file stopped reading that
 * record, or started writing it — fails the build (F6), so an allowance cannot
 * outlive the gap it was written for. Keys are repository-relative even when
 * --root scans a fixture tree, so the key in the output is the key to paste here.
 */
const TOLERATED_GAPS = [
    'tests/fixtures/fixtures/ToleratedForeignReadTest.php::booking_id=200' => 'A fixture tree that reads a seeded '
        . 'booking without writing it is the point of that file: it proves the sweep reports a foreign read, and its '
        . 'pair ForeignFixtureReadTest proves the ratchet fails one that is not tolerated here.',
];

/** Calendar claims: state the passage of time changes by itself. */
const CALENDAR_TOKENS = [
    'to go', 'january', 'february', 'march', 'april', 'may', 'june', 'july',
    'august', 'september', 'october', 'november', 'december',
    'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
];

$verbose = in_array('--verbose', $argv, true);
$rootArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $rootArg = rtrim(substr($arg, 7), '/');
    }
}

$repo = realpath(__DIR__ . '/..');
$root = $rootArg === null ? $repo : (realpath($rootArg) ?: $rootArg);
if (!is_dir($root)) {
    fwrite(STDERR, "no such root: {$root}\n");
    exit(2);
}
$scanRepo = $rootArg === null;

$pattern = is_dir($root . '/tests') ? $root . '/tests/*Test.php' : $root . '/*Test.php';
$files = glob($pattern) ?: [];
sort($files);
if ($files === []) {
    fwrite(STDERR, "no *Test.php files under {$pattern}\n");
    exit(2);
}

// ------------------------------------------------------------------ database
$db = null;
$dbWhy = null;
$dbName = (string) (getenv('WPMS_DB_NAME') ?: 'the default database');
try {
    $db = new Database();
    $db->fetchValue('SELECT COUNT(*) FROM tblweddingbook');
} catch (Throwable $e) {
    $db = null;
    $dbWhy = $e->getMessage();
}

/**
 * Every (entity => [[table, column], …]) the schema knows, so ownership follows
 * the database rather than a hand list that goes stale.
 *
 * @return array<string, array<int, array{0:string,1:string}>>
 */
function id_tables(?Database $db): array
{
    $map = [];
    if ($db !== null) {
        try {
            $rows = $db->fetchAll(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('
                . implode(', ', array_map(static fn (string $c): string => "'" . $c . "'", ID_COLUMNS))
                . ')'
            );
            foreach ($rows as $row) {
                $entity = (string) $row->c === 'event_id' && (string) $row->t === 'tbleventbooking' ? 'event_id' : (string) $row->c;
                $map[$entity][] = [(string) $row->t, (string) $row->c];
            }
        } catch (Throwable $e) {
            $map = [];
        }
    }
    foreach (ENTITY_TABLES as $entity => [$table, $col]) {
        $map[$entity] ??= [];
        if (!in_array([$table, $col], $map[$entity], true)) {
            $map[$entity][] = [$table, $col];
        }
    }
    return $map;
}

$idTables = id_tables($db);

$exists = static function (string $entity, $id) use ($db): ?bool {
    if ($db === null || !isset(ENTITY_TABLES[$entity])) {
        return null;
    }
    $id = (string) $id; // PHP turns a numeric-string array key into an int
    [$table, $col] = ENTITY_TABLES[$entity];
    try {
        return (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $col . ' = :i', [':i' => (int) $id]) > 0;
    } catch (Throwable $e) {
        return null;
    }
};

// ------------------------------------------------------------------ helpers

function line_of(string $src, int $pos): int
{
    return substr_count(substr($src, 0, $pos), "\n") + 1;
}

/** The `(...)`-balanced text of the call whose `(` follows $pos. */
function call_text(string $src, int $pos): string
{
    $open = strpos($src, '(', $pos);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    $quote = null;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];
        if ($quote !== null) {
            if ($c === '\\') { $i++; continue; }
            if ($c === $quote) { $quote = null; }
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; continue; }
        if ($c === '(') { $depth++; continue; }
        if ($c === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }
    return substr($src, $open, 600);
}

/**
 * The `{…}` body of the function whose declaration starts at $pos.
 *
 * A fixed-size window is not good enough for the F2 rule: it runs past the end
 * of a short helper into whatever function follows, and a `WHERE` belonging to
 * that next function would look like this helper's own filter.
 */
function function_body(string $src, int $pos): string
{
    $open = strpos($src, '{', $pos);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    $quote = null;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];
        if ($quote !== null) {
            if ($c === '\\') { $i++; continue; }
            if ($c === $quote) { $quote = null; }
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; continue; }
        if ($c === '{') { $depth++; continue; }
        if ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }
    return substr($src, $open, 1200);
}

/**
 * Read a source the way a human does: constants folded in and literal `.` number
 * chains merged, both strictly within a line so line numbers survive.
 */
function rewrite(string $src, array $consts): string
{
    $out = [];
    foreach (preg_split('/\r?\n/', $src) as $line) {
        $line = (string) preg_replace_callback(
            '/\b(?:self|static)::([A-Z0-9_]+)\b/',
            static fn (array $m): string => isset($consts[$m[1]]) ? (string) $consts[$m[1]] : $m[0],
            $line
        );
        for ($pass = 0; $pass < 12; $pass++) {
            $before = $line;
            $line = (string) preg_replace('/(["\'])((?:[^"\'\\\\]|\\\\.)*)\1[ \t]*\.[ \t]*(\d+)(?!\.\d)/', '$1$2$3$1', $line);
            $line = (string) preg_replace('/(\d+)(?!\.\d)[ \t]*\.[ \t]*(["\'])((?:[^"\'\\\\]|\\\\.)*)\2/', '$2$1$3$2', $line);
            if ($line === $before) {
                break;
            }
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/** Every method body: [name, start line, body]. */
function methods(string $src, string $class): array
{
    $out = [];
    $cur = null;
    foreach (preg_split('/\r?\n/', $src) as $i => $line) {
        if ($cur === null) {
            if (preg_match('/\bfunction\s+(\w+)\s*\(/', $line, $m)) {
                $cur = ['name' => $class . '::' . $m[1], 'line' => $i + 1, 'body' => ''];
            }
            continue;
        }
        $cur['body'] .= $line . "\n";
        if (preg_match('/^    \}[ \t]*$/', $line)) {
            $out[] = $cur;
            $cur = null;
        }
    }
    if ($cur !== null) {
        $out[] = $cur;
    }
    return $out;
}

/** Statements (`;`-terminated, quotes respected) with their start line. */
function statements(string $body, int $startLine): array
{
    $out = [];
    $buf = '';
    $start = $startLine;
    $line = $startLine;
    $quote = null;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $c = $body[$i];
        if ($quote !== null) {
            if ($c === '\\') { $buf .= $c . ($body[$i + 1] ?? ''); $i++; continue; }
            if ($c === $quote) { $quote = null; }
            $buf .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; $buf .= $c; continue; }
        if ($c === "\n") { $line++; }
        if ($c === ';') {
            $out[] = [$buf, $start];
            $buf = '';
            $start = $line;
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = [$buf, $start];
    }
    return $out;
}

/** Literal string contents, as [value, offset]. */
function literals(string $src): array
{
    $out = [];
    $len = strlen($src);
    for ($i = 0; $i < $len; $i++) {
        $c = $src[$i];
        if ($c !== "'" && $c !== '"') {
            continue;
        }
        $buf = '';
        $j = $i + 1;
        for (; $j < $len; $j++) {
            if ($src[$j] === '\\') { $buf .= $src[$j + 1] ?? ''; $j++; continue; }
            if ($src[$j] === $c) { break; }
            $buf .= $src[$j];
        }
        $out[] = [$buf, $i];
        $i = $j;
    }
    return $out;
}

/** entity => id => [line, …] referenced in a statement. */
function references(string $stmt, int $line): array
{
    $found = [];
    foreach (literals($stmt) as [$text, $off]) {
        if (preg_match_all('/(?<![a-z_])(booking_id|user_id|event_id)=(\d+)/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[$hit[1]][$hit[2]] = $line + substr_count(substr($stmt, 0, $off), "\n");
            }
        }
    }
    if (preg_match_all("/'(booking_id|user_id|event_id)'\s*=>\s*(?:\((?:int|string)\)\s*)?(\d+)/", $stmt, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $found[$hit[1]][$hit[2]] = $line;
        }
    }
    return $found;
}

/**
 * entity => id => ['assert' => line, 'ref' => line, 'guarded' => bool] for every
 * record this method asserts about.
 *
 * Two passes, because the response and the assertion about it are usually two
 * statements (`$page = self::http(…); …assertStringContainsString('x',
 * $page['body'])`) and sometimes one. Pass one maps each variable to the records
 * its request named; pass two treats every assertion that reads a response body
 * — directly or through one of those variables — as claiming them.
 */
function claims(string $body, int $startLine): array
{
    $varIds = [];      // $var => entity => id => request line
    $varBody = [];     // $var => true when the variable holds a response body
    $varGuarded = [];  // $var => true when the read is behind a status check
    $out = [];

    foreach (statements($body, $startLine) as [$stmt, $line]) {
        // A response assignment: the request itself, or a body read off one.
        $isResponse = str_contains($stmt, 'self::http(') || str_contains($stmt, 'body');
        if (!$isResponse) {
            continue;
        }
        if (preg_match('/\$(\w+)\s*=/', $stmt, $am)) {
            foreach (references($stmt, $line) as $entity => $byId) {
                foreach ($byId as $id => $refLine) {
                    $varIds[$am[1]][$entity][$id] = $refLine;
                }
            }
            if (str_contains($stmt, 'body')) {
                $varBody[$am[1]] = true;
            }
            // A read behind `if ($res['status'] === 200)` passes vacuously on a
            // record that is not there, so the finding has to say so.
            if (str_contains($body, '$' . $am[1] . "['status']") || str_contains($body, '$' . $am[1] . '["status"]')) {
                $varGuarded[$am[1]] = true;
            }
        }
    }

    foreach (statements($body, $startLine) as [$stmt, $line]) {
        if (!preg_match('/\bassert\w*\s*\(/', $stmt, $cm, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $call = call_text($stmt, (int) $cm[0][1]);
        // A body assertion reads the body — `['body']` or `->body` — or reads a
        // variable that holds one. Merely mentioning the word is not a read:
        // an assertion about a message that happens to say "body of …" reads a
        // string of the audit's own making and claims nothing about a record.
        $readsBody = (bool) preg_match('/\[[\'"]body[\'"]\]|->body/', $call);
        foreach (array_keys($varBody) as $var) {
            if (preg_match('/\$' . preg_quote($var, '/') . '\b/', $call)) {
                $readsBody = true;
            }
        }
        if (!$readsBody) {
            continue;
        }
        $claim = static function (array $out, string $entity, $id, int $refLine, int $assertLine, bool $guarded): array {
            $id = (string) $id; // PHP turns a numeric-string array key into an int
            $prev = $out[$entity][$id] ?? null;
            $out[$entity][$id] = [
                'assert' => $assertLine,
                'ref' => $prev['ref'] ?? $refLine,
                'guarded' => $guarded || ($prev['guarded'] ?? false),
            ];
            return $out;
        };
        // Records named in the assertion itself…
        foreach (references($stmt, $line) as $entity => $byId) {
            foreach ($byId as $id => $refLine) {
                $out = $claim($out, $entity, $id, $refLine, $line, false);
            }
        }
        // …and records named by a request whose response the assertion reads.
        foreach ($varIds as $var => $byEntity) {
            if (!preg_match('/\$' . preg_quote($var, '/') . '\b/', $call)) {
                continue;
            }
            foreach ($byEntity as $entity => $byId) {
                foreach ($byId as $id => $refLine) {
                    $out = $claim($out, $entity, $id, $refLine, $line, (bool) ($varGuarded[$var] ?? false));
                }
            }
        }
    }
    return $out;
}

/** Does this source write any table that carries one of the id columns? */
function writes_id_table(string $src, array $idTables): bool
{
    foreach ($idTables as $tables) {
        foreach ($tables as [$table]) {
            if (preg_match('/(?:UPDATE|INSERT INTO|DELETE FROM)\s+' . preg_quote($table, '/') . '\b/', $src)) {
                return true;
            }
        }
    }
    return false;
}

/** Entities the file writes: entity => id => true, from statement windows. */
function written(string $src, array $idTables): array
{
    $out = [];
    foreach ($idTables as $entity => $tables) {
        foreach ($tables as [$table, $idCol]) {
            $pattern = '/(?:UPDATE|INSERT INTO|DELETE FROM)\s+' . preg_quote($table, '/') . '\b/';
            if (!preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [, $pos]) {
                // The statement, plus the parameter array that usually follows it.
                $window = substr($src, $pos, 1200);
                if (preg_match_all('/[=>(]\s*(\d+)\b/', $window, $ids)) {
                    foreach ($ids[1] as $id) {
                        $out[$entity][$id] = true;
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * Ids handed to a helper that writes an id-bearing table.
 *
 * A fixture written through the file's own helper — `self::swapWeddingType(
 * self::OWN_BOOKING, 0)` — is owned, yet the write statement itself never
 * mentions the literal, so the statement window cannot see it. Crediting every
 * integer argument of such a call over-credits by entity on purpose: the argument
 * carries no field name, and erring the other way would report a file's own
 * fixture as somebody else's.
 */
function helper_owned_ids(string $src, string $class, array $idTables): array
{
    $out = [];
    foreach (methods($src, $class) as $method) {
        if (!writes_id_table($method['body'], $idTables)) {
            continue;
        }
        $name = (string) preg_replace('/^.*::/', '', $method['name']);
        if (preg_match_all('/self::' . preg_quote($name, '/') . '\s*\(([^)]*)\)/', $src, $m)) {
            foreach ($m[1] as $args) {
                if (preg_match_all('/\b(\d+)\b/', $args, $ids)) {
                    foreach ($ids[1] as $id) {
                        $out[$id] = true;
                    }
                }
            }
        }
    }
    return $out;
}

/** Assertion literals that are a COMPLETE argument (not a concatenated fragment). */
function asserted_literals(string $body, int $startLine): array
{
    $out = [];
    $pattern = '/\bassert(?:StringContainsString|StringNotContainsString|StringContainsStringIgnoringCase|MatchesRegularExpression|StringStartsWith|StringEndsWith)\s*\(\s*([\'"])((?:[^\'"\\\\]|\\\\.)*)\1\s*[,)]/';
    if (preg_match_all($pattern, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $out[] = [$hit[2][0], $startLine + substr_count(substr($body, 0, (int) $hit[2][1]), "\n")];
        }
    }
    return $out;
}

/**
 * [stem, line, enclosing method] for every literal the source generates
 * dynamically.
 *
 * The method is tracked as the lines go by, because the stem's first appearance
 * in a file is usually the ASSERTION that names it, not the generator — asking
 * "which function is this stem inside?" afterwards would answer about the test
 * method that reads it, whose call count is zero.
 */
function generated_stems(string $src): array
{
    $out = [];
    $owner = '';
    foreach (preg_split('/\r?\n/', $src) as $i => $line) {
        if (preg_match('/\bfunction\s+(\w+)\s*\(/', $line, $fm)) {
            $owner = $fm[1];
        }
        $sets = [];
        preg_match_all('/([\'"])((?:[^\'"\\\\]|\\\\.)*)\1[ \t]*\.[ \t]*[$A-Za-z_]/', $line, $a);
        $sets[] = $a[2];
        preg_match_all('/[$A-Za-z_][\w\$\->\[\]\'"]*[ \t]*\.[ \t]*([\'"])((?:[^\'"\\\\]|\\\\.)*)\1/', $line, $b);
        $sets[] = $b[2];
        preg_match_all('/sprintf\s*\(\s*([\'"])((?:[^\'"\\\\]|\\\\.)*)\1/', $line, $c);
        $sets[] = $c[2];
        foreach ($sets as $set) {
            foreach ($set as $stem) {
                if (strlen($stem) >= 4) {
                    $out[] = [$stem, $i + 1, $owner];
                }
            }
        }
    }
    return $out;
}

/**
 * The stem of a generator: prose, judged by what it becomes once a value is
 * appended. The digits are in the variable the stem is concatenated with, so the
 * stem alone would never look like the text an assertion names ('probe item ' +
 * $i → 'probe item 4'), and a URL or a path is still not prose either way.
 */
function is_prose_stem(string $s): bool
{
    return is_prose($s . ' 1');
}

/** A literal stripped of digits, collapsed to words, for stem matching. */
function stem(string $s): string
{
    $s = (string) preg_replace('/\d+/', '', $s);
    $s = (string) preg_replace('/\s+/', ' ', $s);
    return trim($s, " \t\n\r\0\x0B·%:-[]");
}

/** Text a human would read: no URL, markup or format syntax. */
function is_prose(string $s): bool
{
    return (bool) preg_match('/^[^\/=?<>&{}%$*\\\\|]+$/', $s)
        && (bool) preg_match('/[A-Za-z]/', $s)
        && (bool) preg_match('/\d/', $s)
        && str_contains($s, ' ');
}

function is_calendar_claim(string $literal): bool
{
    $low = strtolower($literal);
    if (preg_match('/\b(?:19|20)\d{2}\b/', $low)) {
        return true;
    }
    foreach (CALENDAR_TOKENS as $token) {
        if (str_contains($low, $token)) {
            return true;
        }
    }
    return false;
}

/** How many times a method is invoked in this file (definitions excluded). */
function call_sites(string $src, string $name): int
{
    return (int) preg_match_all('/\b' . preg_quote($name, '/') . '\s*\(/', $src) - 1;
}

/**
 * Does an allowance key belong to the tree being scanned?
 *
 * Repository keys are judged on a repository scan and a fixture tree's on its own
 * scan; each ignores the other's. `tests/fixtures/` is the boundary, which is the
 * directory phpunit.xml excludes for the same reason: it is data, not tests.
 */
function gap_belongs_to_scan(string $key, bool $scanRepo, string $root): bool
{
    $repo = realpath(__DIR__ . '/..');
    $inFixtures = str_starts_with($key, 'tests/fixtures/');
    if ($scanRepo) {
        return !$inFixtures;
    }
    if ($repo === false || !str_starts_with($root, $repo)) {
        return false;
    }
    $prefix = str_replace('\\', '/', substr($root, strlen($repo) + 1)) . '/';
    return str_starts_with($key, $prefix);
}

function accepted_key(string $file, string $rule, string $detail = ''): ?string
{
    foreach ([$file . '::' . $rule . ($detail !== '' ? '::' . $detail : ''), $file . '::' . $rule] as $key) {
        if (isset(ACCEPTED_FINDINGS[$key])) {
            return $key;
        }
    }
    return null;
}

// ------------------------------------------------------- file pass: stems etc.
$generated = [];   // [stem, relFile, line, rawStem, owner, call sites]
$sources = [];
foreach ($files as $file) {
    // Keys are repository-relative even when --root scans a fixture tree: both
    // registries are keyed by those paths, and a key you can copy out of the
    // output is a key you can act on.
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    if ($repo !== false && str_starts_with($file, $repo . DIRECTORY_SEPARATOR)) {
        $rel = str_replace('\\', '/', substr($file, strlen($repo) + 1));
    }
    $src = (string) file_get_contents($file);
    $sources[$file] = ['rel' => $rel, 'src' => $src];
    foreach (generated_stems($src) as [$s, $line, $owner]) {
        $norm = stem($s);
        if ($norm !== '' && is_prose_stem($s)) {
            $generated[] = [$norm, $rel, $line, $s, $owner, call_sites($src, $owner)];
        }
    }
}

// ---------------------------------------------------------------- the sweeps
$findings = [];
$notJudged = [];
$acceptedHits = [];
$acceptedUsed = [];
$refsTotal = 0;
$filesClean = 0;

foreach ($sources as $file => $info) {
    $rel = $info['rel'];
    $raw = $info['src'];
    $consts = [];
    if (preg_match_all('/\bconst\s+([A-Z0-9_]+)\s*=\s*(\d+)\s*;/', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $consts[$hit[1]] = (int) $hit[2];
        }
    }
    $src = rewrite($raw, $consts);
    $class = preg_match('/\bclass\s+(\w+)/', $src, $cm) ? $cm[1] : 'Test';
    $writes = written($src, $idTables);
    $ownedIds = helper_owned_ids($src, $class, $idTables);
    $fileFindings = 0;
    $methodList = methods($src, $class);

    // ---- F1 / F4: records claimed but not owned
    foreach ($methodList as $method) {
        foreach (claims($method['body'], $method['line']) as $entity => $byId) {
            [$table, $idCol] = ENTITY_TABLES[$entity];
            foreach ($byId as $id => $claim) {
                $refsTotal++;
                if (isset($writes[$entity][$id]) || isset($ownedIds[$id])) {
                    continue;
                }
                $key = $entity . '=' . $id;
                $present = $exists($entity, $id);
                $guarded = $claim['guarded']
                    ? ' The read is guarded by a status check, so it passes vacuously instead of failing.'
                    : '';
                if ($present === false) {
                    $msg = "F1 {$rel}::" . str_replace($class . '::', '', $method['name'])
                        . ": asserts on the body of {$key}, which does not exist in {$dbName} — nothing the assertion "
                        . "says about it can be true.{$guarded}";
                    if (($acc = accepted_key($rel, 'F1', $key)) !== null) {
                        $acceptedHits[$acc][] = $msg;
                        $acceptedUsed[$acc] = true;
                    } else {
                        $findings[] = [$rel . '::F1::' . $key, $msg, 'asserted at line ' . $claim['assert'] . ', requested at line ' . $claim['ref']];
                        $fileFindings++;
                    }
                    continue;
                }
                $calendar = null;
                foreach ($methodList as $other) {
                    if ($other['name'] !== $method['name']) {
                        continue;
                    }
                    foreach (asserted_literals($other['body'], $other['line']) as [$literal, $aLine]) {
                        if (is_calendar_claim($literal)) {
                            $calendar = [$literal, $aLine];
                            break 2;
                        }
                    }
                }
                if ($calendar !== null) {
                    $msg = "F4 {$rel}::" . str_replace($class . '::', '', $method['name'])
                        . ": asserts the calendar text {$calendar[0]} about {$key}, a record this file never writes — "
                        . 'the expectation belongs to a seeded fixture whose date moves with the clock.';
                    if (($acc = accepted_key($rel, 'F4', $key)) !== null) {
                        $acceptedHits[$acc][] = $msg;
                        $acceptedUsed[$acc] = true;
                    } else {
                        $findings[] = [$rel . '::F4::' . $key, $msg, 'asserted at line ' . $calendar[1] . ', requested at line ' . $claim['ref']];
                        $fileFindings++;
                    }
                } else {
                    // A gap, not a finding — unless F6's ratchet does not
                    // tolerate it, which is where it becomes one.
                    $notJudged[] = [
                        'file' => $rel,
                        'key'  => $key,
                        'line' => (int) $claim['ref'],
                        'why'  => $present === null
                            ? 'no database to check this id against'
                            : 'the row exists but the file never writes it: whether its seeded state matches this '
                              . 'assertion is not decidable from the test file — the calendar case is what sweeps F1/F4 make fatal',
                    ];
                }
            }
        }
    }

    // ---- F2: literals compared against a whole-table aggregate
    if (preg_match_all('/assert(?:Same|Equals|Count)\s*\(\s*(\d+)\s*,/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $pos = (int) $hit[1][1] - strlen('assertSame(');
            $call = call_text($src, max(0, $pos));
            $line = line_of($src, max(0, $pos));
            $aggregate = null;
            if (preg_match('/(?<![a-z_])COUNT\s*\(/i', $call) && !preg_match('/\bWHERE\b/i', $call)) {
                $aggregate = 'an unconstrained COUNT()';
            } elseif (preg_match('/(?<![a-z_])(\w+)\s*\(/', $call, $hm)
                && preg_match('/function\s+' . preg_quote($hm[1], '/') . '\s*\(/', $src, $dm, PREG_OFFSET_CAPTURE)) {
                $window = function_body($src, (int) $dm[0][1]);
                if (preg_match('/(?<![a-z_])COUNT\s*\(/i', $window) && !preg_match('/\bWHERE\b/i', $window)) {
                    $aggregate = $hm[1] . '(), which counts a whole table with no WHERE';
                }
            }
            if ($aggregate === null) {
                continue;
            }
            $msg = "F2 {$rel}:{$line}: compares the literal {$hit[1][0]} against {$aggregate} — the number describes the "
                . 'seed, not the behaviour under test.';
            if (($acc = accepted_key($rel, 'F2')) !== null) {
                $acceptedHits[$acc][] = $msg;
                $acceptedUsed[$acc] = true;
            } else {
                $findings[] = [$rel . '::F2::line' . $line, $msg, 'compare against a baseline read in the test, or constrain the query to fixture ids'];
                $fileFindings++;
            }
        }
    }

    // ---- F3: asserted text the file generates more than once
    foreach ($methodList as $method) {
        $methodName = substr($method['name'], strlen($class) + 2);
        foreach (asserted_literals($method['body'], $method['line']) as [$literal, $line]) {
            if (!is_prose($literal)) {
                continue;
            }
            $norm = stem($literal);
            if (strlen($norm) < 6) {
                continue;
            }
            foreach ($generated as [$gen, $genFile, $genLine, $rawStem, $owner, $sites]) {
                if (!str_ends_with($gen, $norm)) {
                    continue;
                }
                // The generator's own helper must run more than once, or the text
                // is a single batch's label and one assertion about it is honest.
                if ($owner === '' || $sites < 2) {
                    continue;
                }
                $msg = "F3 {$rel}:{$line}: asserts the text \"{$literal}\" that the file generates in more than one place "
                    . "(\"{$rawStem}\" + a variable, in {$owner}(), called {$sites} times) — the assertion answers "
                    . 'about whichever batch ran last.';
                if (($acc = accepted_key($rel, 'F3')) !== null) {
                    $acceptedHits[$acc][] = $msg;
                    $acceptedUsed[$acc] = true;
                } else {
                    $findings[] = [$rel . '::F3::line' . $line, $msg, 'key the assertion on an id the generator returned, or make each batch\'s text unique'];
                    $fileFindings++;
                }
                break 2;
            }
        }
    }

    if ($fileFindings === 0) {
        $filesClean++;
    }
}

// ------------------------------------------------ F5: acceptances that outlived
$regSkipped = [];
foreach (ACCEPTED_FINDINGS as $key => $reason) {
    if (isset($acceptedUsed[$key])) {
        continue;
    }
    if (!$scanRepo && !gap_belongs_to_scan($key, $scanRepo, $root)) {
        // An allowance for another tree: this scan is not the one that judges it.
        $regSkipped[] = $key;
        continue;
    }
    $findings[] = [
        'accepted',
        "accepted finding {$key} is obsolete — it excused nothing on this run, so delete the entry and let the rule guard it again",
        $reason,
    ];
}

// ------------------------------------- F6: the not-judged list is a ratchet
// A gap list that grows quietly is a blind spot with a progress bar. Every entry
// no allowance covers fails the build, and every allowance that excused nothing
// fails it too — so an unjudgeable pattern has to be argued in writing, in this
// file, before the sweep passes again.
$gapNew = [];
$gapTolerated = [];
$gapUsed = [];
foreach ($notJudged as $gap) {
    $key = $gap['file'] . '::' . $gap['key'];
    if (isset(TOLERATED_GAPS[$key])) {
        $gapTolerated[$key][] = $gap['line'];
        $gapUsed[$key] = true;
        continue;
    }
    $gapNew[] = $gap;
}
foreach ($gapNew as $gap) {
    $findings[] = [
        $gap['file'] . '::F6::' . $gap['key'],
        "F6 {$gap['file']}:{$gap['line']}: the not-judged list grew — {$gap['key']} is a claimed record this sweep "
            . "will not judge ({$gap['why']})",
        'own the fixture, or record the key in TOLERATED_GAPS with the reason this gap is tolerated',
    ];
}

// An allowance belongs to a tree: the repository's gaps are judged when the
// repository is scanned, a fixture tree's when that tree is.
foreach (TOLERATED_GAPS as $key => $reason) {
    if (!isset($gapUsed[$key]) && gap_belongs_to_scan($key, $scanRepo, $root)) {
        $findings[] = [
            'tolerated',
            "tolerated gap {$key} is obsolete — nothing was left unjudged under it on this run, so delete the entry "
                . 'and let the ratchet hold',
            $reason,
        ];
    }
}

// ------------------------------------------------------------------- report
echo "=== SWEEP F — test fixtures a class claims but does not own ===\n";
printf(
    "scanned: %d test file(s) | records claimed: %d | %d file(s) with no finding\n",
    count($sources),
    $refsTotal,
    $filesClean
);
printf(
    "database: %s%s\n",
    $db !== null ? $dbName . ' — existence checks live' : 'unavailable — F1 cannot check ids',
    $db === null && $dbWhy !== null ? ' (' . $dbWhy . ')' : ''
);
printf("ownership: %d id-shaped column(s) discovered from the schema\n\n", array_sum(array_map('count', $idTables)));

if ($verbose) {
    foreach ($sources as $info) {
        $w = written(rewrite($info['src'], []), $idTables);
        $flat = [];
        foreach ($w as $entity => $ids) {
            foreach (array_keys($ids) as $id) {
                $flat[] = $entity . '=' . $id;
            }
        }
        printf("   %-44s writes %s\n", $info['rel'], $flat === [] ? '(no id-keyed rows)' : implode(', ', $flat));
    }
    echo "\n";
}

foreach ($acceptedHits as $key => $hits) {
    printf("accepted  %s\n          %s\n", $key, ACCEPTED_FINDINGS[$key]);
    foreach ($hits as $hit) {
        printf("            · %s\n", $hit);
    }
}

foreach ($findings as [$key, $msg, $detail]) {
    printf("!! %s: %s\n     %s\n", $key, $msg, $detail);
}

if ($notJudged !== []) {
    echo "-- not judged: a claimed record this sweep will not call a bug. F6 says the list may not grow.\n";
    foreach ($notJudged as $gap) {
        printf("?  %s:%d: %s — %s\n", $gap['file'], $gap['line'], $gap['key'], $gap['why']);
    }
}
foreach ($gapTolerated as $key => $lines) {
    printf(
        "tolerated  %s (line%s %s)\n           %s\n",
        $key,
        count($lines) === 1 ? '' : 's',
        implode(', ', array_unique($lines)),
        TOLERATED_GAPS[$key]
    );
}
if ($regSkipped !== []) {
    printf("registry:   %d entr%s belong(s) to another tree and was not judged on this scan\n",
        count($regSkipped), count($regSkipped) === 1 ? 'y' : 'ies');
}

echo "\n";
printf(
    "findings: %d | not judged: %d (%d tolerated, %d new) | accepted: %d\n",
    count($findings),
    count($notJudged),
    count($gapTolerated),
    count($gapNew),
    count($acceptedUsed)
);

if ($findings === []) {
    echo "\nSWEEP F CLEAN — every record a test asserts about is one its own file writes, or a seeded read the ratchet\n"
        . "has been told about on purpose (see the tolerated gaps above).\n";
}

exit($findings === [] ? 0 : 1);
