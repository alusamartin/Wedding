<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Audit sweep D — role-facing promises vs the state behind them.
 *
 * Sweeps A and C prove the tables and columns a query NAMES exist. This one
 * proves something different: that the state a role is TOLD TO WAIT FOR can
 * actually be reached. It exists because this defect class was found three
 * separate times by hand —
 *
 *   F2  vendor_register.php told vendors to "wait until an administrator
 *       approves your listing", and is_approved could only ever be written 0.
 *   F9  subscription.php promised "Plans will appear here once the team defines
 *       them", and tblsubscription_plans was read twice and written nowhere.
 *   O7  the vendor rating and booking count were shown to couples, operators and
 *       the vendor, and no code ever assigned either one (fixed by v2.21, which
 *       gave both columns a writer — see Vendor::recompute_stats).
 *
 * Three checks:
 *
 *   D1  State nobody can write.
 *       a) a table that is read but has no row-creator at all (no INSERT and no
 *          DB_Object class);
 *       b) a column that is read but has no writer — never in an INSERT column
 *          list, never a target of UPDATE … SET, never assigned as an object
 *          property by a class that declares it.
 *   D2  State written only to unreachable values. When every write to a column
 *       is a literal and the code compares that column, with an EQUALITY, to a
 *       literal it is never written with, the branch is dead (is_approved
 *       written 0, read `WHERE is_approved = 1`). Any non-literal write — a
 *       bound parameter, a ternary, a variable — marks the column "open" and
 *       disables this check for it, so a value arriving from anywhere but a
 *       literal is never called unreachable.
 *   D3  Copy that promises without an actor. Every promise phrase (PROMISE_PATTERNS)
 *       in staff- or public-facing copy must be covered by a REGISTERED promise
 *       for that file, and a registered promise's dependency must pass D1 and
 *       D2. A phrase with no registration fails the sweep, so a new
 *       "…will appear here once…" line cannot be added without saying who makes
 *       it appear and naming the table or column they write.
 *
 * Precision over recall, deliberately. Object-property writes are only credited
 * to a table whose DB_Object class declares that column; a comparison is only
 * attributed when the SQL names one table or qualifies the column with a known
 * alias; and anything genuinely undecidable is skipped rather than reported.
 * Whatever is knowingly left alone lives in ACCEPTED_*, and those entries are
 * PRINTED on every run — visible debt, never a silent pass.
 *
 * usage: php scripts/audit_promises.php [--verbose] [--root=<dir>]
 *
 * --root scans a different tree (default: this repository). The database is
 * always the live one named by WPMS_DB_NAME, so --root exists to point the
 * sweep at tests/fixtures/promises and prove it fails on a deliberately
 * broken project — the negative half of its own test.
 */

require __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/Database.php';
require_once __DIR__ . '/../admin/include/db_object.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\ParserFactory;

/*
 * ---------------------------------------------------------------------------
 * D3 registry — every role-facing promise, the state it depends on, and the
 * actor that supplies it. `depends` is a table or table.column. A registered
 * copy also COVERS every promise phrase it contains, so extending a sentence
 * does not require a second entry.
 * ---------------------------------------------------------------------------
 */
/**
 * Accepted-state lookup. Exact `table.column` first, then the bare table name,
 * so a single entry can cover every column of an inert table.
 */
function accepted_reason(string $key): ?string
{
    if (isset(ACCEPTED_STATE[$key])) {
        return ACCEPTED_STATE[$key];
    }
    $table = str_contains($key, '.') ? explode('.', $key, 2)[0] : null;
    return $table !== null && isset(ACCEPTED_STATE[$table]) ? ACCEPTED_STATE[$table] : null;
}

const PROMISES = [
    [
        'copy'    => 'Awaiting assignment',
        'file'    => 'admin/client.php',
        'depends' => 'tblaccounts_detail.status',
        'actor'   => 'an operator assigns a planner (client_assign.php)',
    ],
    [
        'copy'    => 'Plans will appear here once the team defines them.',
        'file'    => 'admin/subscription.php',
        'depends' => 'tblsubscription_plans',
        'actor'   => 'the operator defines a plan (subscription_plans.php), so the sentence now has a keeper',
    ],
    [
        'copy'    => 'Photos are coming soon',
        'file'    => 'gallery.php',
        'depends' => 'tblgallery',
        'actor'   => 'the operator uploads photos (photos_add.php)',
    ],
    [
        'copy'    => 'Awaiting confirmation — our team is reviewing your date and will reach out shortly.',
        'file'    => 'admin/client_manage_account_details.php',
        'depends' => 'tblaccounts_detail.status',
        'actor'   => 'an operator assigns a planner (client_assign.php), which writes status = confirm',
    ],
    [
        'copy'    => 'Awaiting confirmation',
        'file'    => 'admin/client.php',
        'depends' => 'tblaccounts_detail.status',
        'actor'   => 'an operator assigns a planner (client_assign.php)',
    ],
    [
        'copy'    => 'Our team will review your date and confirm your booking shortly — watch your email for updates.',
        'file'    => 'thank_you.php',
        'depends' => 'tblaccounts_detail.status',
        'actor'   => 'an operator assigns a planner (client_assign.php)',
    ],
    [
        'copy'    => 'Your planner will be in touch to plan every detail.',
        'file'    => 'admin/client_assign.php',
        'depends' => 'tblweddingbook.organizer_id',
        'actor'   => 'this page itself records the planner against the booking',
    ],
    [
        'copy'    => 'your planner will call you soon',
        'file'    => 'admin/client_assign.php',
        'depends' => 'tblweddingbook.organizer_id',
        'actor'   => 'this page itself records the planner, then SMSes the couple',
    ],
    [
        'copy'    => 'You will be notified once approved',
        'file'    => 'admin/vendor_register.php',
        'depends' => 'tblvendors.is_approved',
        'actor'   => 'the operator approves the listing (vendors.php), which notifies the vendor',
    ],
    [
        'copy'    => 'Your inquiry has been sent to the vendor. They will contact you shortly.',
        'file'    => 'admin/vendors.php',
        'depends' => 'tblvendor_bookings',
        'actor'   => 'this page records the inquiry as a pending tblvendor_bookings row for a chosen wedding, then notifies the vendor',
    ],
    [
        'copy'    => 'Awaiting approval',
        'file'    => 'admin/vendors.php',
        'depends' => 'tblvendors.is_approved',
        'actor'   => 'the operator approves the listing on this same page',
    ],
    [
        'copy'    => 'Vendors in this category will appear here once they are approved.',
        'file'    => 'admin/vendors.php',
        'depends' => 'tblvendors.is_approved',
        'actor'   => 'the operator approves the listing on this same page',
    ],
    [
        'copy'    => 'Vendors in this category will appear here once they are approved.',
        'file'    => 'admin/client_vendors.php',
        'depends' => 'tblvendors.is_approved',
        'actor'   => 'the operator approves the listing (vendors.php)',
    ],
    [
        'copy'    => 'Plans will appear here once the team defines them.',
        'file'    => 'admin/subscription.php',
        'depends' => 'tblsubscription_plans',
        'actor'   => 'the operator defines a plan (subscription_plans.php)',
    ],
    [
        'copy'    => 'it appears here the moment it is saved',
        'file'    => 'admin/subscription.php',
        'depends' => 'tblsubscription_plans',
        'actor'   => 'the operator defines a plan (subscription_plans.php)',
    ],
    [
        'copy'    => 'your payment history will appear here.',
        'file'    => 'admin/subscription.php',
        'depends' => 'tblsubscriptions',
        'actor'   => 'the planner buys or renews a plan (Monetization::create_subscription writes the row)',
    ],
    [
        'copy'    => 'Planner sign-ups and renewals will appear here.',
        'file'    => 'admin/revenue.php',
        'depends' => 'tblsubscriptions',
        'actor'   => 'planner sign-up/renewal writes a row (Monetization::create_subscription)',
    ],
    [
        'copy'    => 'Successful sends and their KSh cost will appear here.',
        'file'    => 'admin/revenue.php',
        'depends' => 'tblsms_logs',
        'actor'   => 'a real SMS send writes a row (SMS::send)',
    ],
    [
        'copy'    => 'Real weddings from our couples will appear here',
        'file'    => 'gallery.php',
        'depends' => 'tblgallery',
        'actor'   => 'the operator uploads photos (photos_add.php)',
    ],
    [
        'copy'    => 'Awaiting Reply',
        'file'    => 'admin/rsvp_dashboard.php',
        'depends' => 'tblguest.rsvp_status',
        'actor'   => 'the guest answers from their tokenised RSVP link (rsvp.php)',
    ],
    [
        'copy'    => "'Awaiting reply'",
        'file'    => 'admin/rsvp_export.php',
        'depends' => 'tblguest.rsvp_status',
        'actor'   => 'the guest answers from their tokenised RSVP link (rsvp.php)',
    ],
    // The vendor plan shows every planned vendor as "Awaiting reply" until the
    // deal itself moves. The state behind the words is the deal's status column,
    // and both people who can move it are named: the vendor answering from their
    // own dashboard, and the planner confirming an agreed figure in Enquiries.
    [
        'copy'    => 'Awaiting reply',
        'file'    => 'admin/vendor_plan.php',
        'depends' => 'tblvendor_bookings.status',
        'actor'   => 'the vendor accepts or declines from their dashboard (vendor_home.php), or the planner confirms the agreed figure (vendor_enquiries.php)',
    ],
    [
        'copy'    => 'Awaiting reply',
        'file'    => 'admin/client_vendor_plan.php',
        'depends' => 'tblvendor_bookings.status',
        'actor'   => 'the vendor accepts or declines from their dashboard (vendor_home.php), or the planner confirms the agreed figure (vendor_enquiries.php)',
    ],
    [
        'copy'    => 'Awaiting reply',
        'file'    => 'admin/include/VendorPlan.php',
        'depends' => 'tblvendor_bookings.status',
        'actor'   => 'the vendor accepts or declines from their dashboard (vendor_home.php), or the planner confirms the agreed figure (vendor_enquiries.php)',
    ],
    [
        'copy'    => 'Package features are being finalised — our team will share the full list with you.',
        'file'    => 'admin/client_manage_account_details.php',
        'depends' => 'tbl_features',
        'actor'   => 'the operator defines the features (feature_add.php)',
    ],
    [
        'copy'    => 'Our wedding packages will be listed here shortly',
        'file'    => 'index.php',
        'depends' => 'tblweddingcategories',
        'actor'   => 'the operator publishes a package (package_add.php)',
    ],
    [
        'copy'    => 'Our wedding packages will be listed here shortly',
        'file'    => 'pricing.php',
        'depends' => 'tblweddingcategories',
        'actor'   => 'the operator publishes a package (package_add.php)',
    ],
    // The planner's proposal queue says why it is empty on a database that
    // predates v2.30: the tables are the prerequisite, and no page creates
    // them — applying the migration does. Registered so the sentence is
    // audited rather than silently reassuring.
    [
        'copy'    => 'Install the proposal tables and new registrations will appear here.',
        'file'    => 'admin/proposals.php',
        'depends' => 'tblproposals',
        'actor'   => 'the operator applies migrations/v2.30_proposals.sql on the database (no page creates the table)',
    ],
    // The confirming bell after a lock tells the couple the planner will be in
    // touch with next steps. The state that makes that true is the deals the
    // lock just wrote — one per vendor, each already notified — and the person
    // behind "in touch" is the planner whose queue the wedding is now in.
    [
        'copy'    => 'Your planner will be in touch with next steps.',
        'file'    => 'admin/include/Proposal.php',
        'depends' => 'tblvendor_bookings',
        'actor'   => 'the planner locks the chosen option (proposal_view.php), which creates one pending deal per vendor and hands the wedding to their own queue',
    ],
];

/*
 * Promises knowingly left standing, each with the reason. Printed every run.
 */
const ACCEPTED_PROMISES = [
    [
        'file'   => 'contact.php',
        'copy'   => 'We&rsquo;ll get back to you soon',
        'reason' => 'The actor is a human mailbox, not a table: contact.php sends through raw mail() and stores nothing, so '
                  . 'no database state can prove a reply. Unverifiable by this sweep.',
    ],
    [
        'file'   => 'admin/subscription_plans.php',
        'copy'   => '"Plans will appear here once the team defines them" with nothing to buy',
        'reason' => 'Not a promise to the operator, who is looking at this screen precisely because that sentence is true: '
                  . 'it quotes back to them what planners see. The registration for the planner-facing sentence itself lives '
                  . 'in admin/subscription.php.',
    ],
];

/*
 * O11 (tbleventbooking.family / .individual / .features_id — the venue sheet's
 * headcount plan) USED TO BE ACCEPTED HERE, and was removed the moment the
 * debt was paid: v2.9 made BookingEvent a real active record and gave the sheet
 * an editor (admin/event_booking_save.php), so D1 finds a writer for all three
 * columns. This is exactly what D4 is for — leaving the entries in place failed
 * the build with `accepted entry tbleventbooking.family is obsolete`, which is
 * the message a future author should expect when they fix an accepted state.
 */

/*
 * State knowingly read but not writable, each with a reason. Printed every run,
 * and D4 fails the build once the debt behind an entry is paid.
 */
const ACCEPTED_STATE = [
    'tblsubscription_payments'  => 'seeded only by design: Revenue documents tblsubscriptions.created_at as the source of truth',
    'calendar'                  => 'legacy table: no page reads it; its only mention is seed-date tooling (scripts/shift_seed_dates.php)',
    'tblservice_catalog'        => 'reference data seeded by v2.27 (and shipped by deploy/schema.sql): vendors pick from the vocabulary and never create it, because that is what stops each vendor inventing a synonym; adding a service is an admin migration, by design',
];

/* Phrases that mark copy as a promise. Kept narrow: every extra phrase is
 * another line some file has to justify. */
const PROMISE_PATTERNS = [
    'will appear here', 'will be listed here', 'will be shown here',
    'will contact you', 'will get back to you', 'will reach out', 'will call you', 'will be in touch',
    'you will be notified', 'will be notified',
    'once approved', 'once they are approved', 'once the team',
    'awaiting approval', 'awaiting confirmation', 'awaiting assignment', 'awaiting reply',
    'being finalised', 'coming soon', 'watch your email for updates',
];

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

$schema = [];   // tbl => [col => ['generated' => bool]]
foreach ($db->fetchAll('SHOW TABLES') as $row) {
    $arr = (array) $row;
    $t = (string) current($arr);
    $schema[$t] = [];
    foreach ($db->fetchAll("SHOW COLUMNS FROM `{$t}`") as $col) {
        $extra   = strtolower((string) ($col->Extra ?? ''));
        $default = strtolower((string) ($col->Default ?? ''));
        $name    = strtolower((string) $col->Field);
        $schema[$t][$name] = [
            // Written by the database itself, not by any page: id, DEFAULT
            // CURRENT_TIMESTAMP columns, and anything auto-updated. Reporting
            // those as "never written" would drown the sweep in noise.
            'generated' => str_contains($extra, 'auto_increment')
                || str_contains($extra, 'default_generated')
                || str_contains($extra, 'on update')
                || str_starts_with($default, 'current_timestamp')
                || in_array($name, ['created_at', 'updated_at', 'updated_on', 'last_attempt_at'], true),
            // Values the database itself supplies for rows that omit the column.
            // A comparison against one of these is reachable, so it must count
            // as written evidence (tblnotifications.is_read = 0 is exactly this).
            'default' => $col->Default === null ? null : (string) $col->Default,
        ];
    }
}

// ------------------------------------------------------- project file lists
$root = realpath($rootArg ?? __DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "!! --root={$rootArg} does not exist\n");
    exit(2);
}
// Exclusions are relative to the scanned root, not to the filesystem: with
// --root=<fixture> the whole point is to scan a tree that lives under tests/.
$rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
$isExcluded = static function (string $p) use ($rootPrefix): bool {
    $rel = '/' . str_replace($rootPrefix, '', str_replace('\\', '/', $p));
    foreach (['/vendor/', '/tests/', '/.git/', '/node_modules/', '/migrations/', '/docs/'] as $skip) {
        if (str_contains($rel, $skip)) {
            return true;
        }
    }
    return !str_ends_with($rel, '.php');
};

$appFiles  = [];   // anywhere a writer may live (admin, root, scripts, cron_bridge)
$copyFiles = [];   // user-facing copy only
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $f) {
    $abs = str_replace('\\', '/', (string) $f->getPathname());
    if ($isExcluded($abs)) {
        continue;
    }
    $appFiles[] = $f->getPathname();
    $rel = str_replace('\\', '/', str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname()));
    if (str_starts_with($rel, 'admin/') || !str_contains($rel, '/')) {
        $copyFiles[] = $f->getPathname();
    }
}

// class => table, table => fields, column => [tables declaring it]
$classTables  = [];
$classNames   = [];
$columnOwners = [];
foreach (array_merge(glob($root . '/admin/include/*.php') ?: [], glob($root . '/admin/include/**/*.php') ?: []) as $file) {
    $code = file_get_contents($file) ?: '';
    if (!preg_match_all('/class\s+(\w+)(\s+extends\s+DB_Object)?\s*\{(.*?)\n\}/s', $code, $cm, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($cm as $c) {
        if (!preg_match('/\$db_table\s*=\s*["\'](\w+)["\']/', $c[3], $tm)) {
            /*
             * Not a DB_Object, but it may still own a table and name it in a
             * constant — ApiToken does (`const TABLE = 'tblapi_tokens'`), because
             * its rows are written by the mint/audit paths rather than by the
             * active record. Recorded for CONSTANT RESOLUTION only (no fields,
             * no writer claims of its own): without this, the statement
             * `'INSERT INTO ' . self::TABLE . ' …'` cannot be resolved to a
             * table, and tblapi_tokens is reported as read-but-never-written —
             * a false positive about the class that mints every token.
             */
            if (preg_match('/const\s+TABLE\s*=\s*[\'"](\w+)[\'"]/', $c[3], $ct)) {
                $classNames[$c[1]] = $ct[1];
            }
            continue;
        }
        $table = $tm[1];
        $fields = [];
        if (preg_match('/\$db_table_fields\s*=\s*(?:array\(|\[)(.*?)(?:\)|\])/s', $c[3], $fm)) {
            preg_match_all('/["\'](\w+)["\']/', $fm[1], $fn);
            $fields = array_map('strtolower', $fn[1]);
        }
        // Declared public properties count too: a column can be object-managed
        // without appearing in $db_table_fields (Account_Details::$organizer_id
        // is written that way). What makes it WRITTEN is still the assignment.
        if (preg_match_all('/public\s+(?:[?\\\w|]+\s+)?\$(\w+)\s*;/', $c[3], $pm)) {
            $fields = array_merge($fields, array_map('strtolower', $pm[1]));
        }
        $fields = array_values(array_unique($fields));
        $classNames[$c[1]] = $table;
        $classTables[$table] = $fields;
        foreach ($fields as $f) {
            $columnOwners[$f][] = $table;
        }
    }
}
foreach ($columnOwners as $col => $tables) {
    $columnOwners[$col] = array_values(array_unique($tables));
}

// --------------------------------------------------------------- index pass
$writes      = [];   // "tbl.col" => ['literals'=>[], 'open'=>bool, 'at'=>string]
$rowCreators = [];   // tbl => true
$references  = [];   // "tbl.col" => 'file:line' (first read-ish mention)
$sqlCompare  = [];   // "tbl.col" => [['value'=>scalar,'at'=>string], ...]
$propAssign  = [];   // prop => ['literals'=>[], 'open'=>bool, 'at'=>string]
$propCompare = [];   // prop => [['value'=>scalar,'at'=>string], ...]

function note_write(array &$writes, string $key, mixed $literal, bool $isLiteral, string $at): void
{
    $writes[$key] ??= ['literals' => [], 'open' => false, 'at' => $at];
    if ($isLiteral) {
        $writes[$key]['literals'][] = $literal;
    } else {
        $writes[$key]['open'] = true;
    }
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
        return [true, $m[1]];
    }
    if (strcasecmp($frag, 'NULL') === 0) {
        return [true, null];
    }
    return [false, null];
}

function norm(mixed $v): string
{
    if (is_bool($v)) { return $v ? '1' : '0'; }
    if ($v === null) { return 'NULL'; }
    if (is_numeric($v)) { return (string) (0 + $v); }
    return (string) $v;
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
    if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $real = null;
            foreach ($schema as $known => $_) {
                if (strcasecmp($known, $mm[1]) === 0) { $real = $known; }
            }
            if ($real === null) { continue; }
            $map[strtolower($mm[1])] = $real;
            if (!empty($mm[2]) && !in_array(strtoupper($mm[2]), ['SET', 'VALUES', 'WHERE', 'ON', 'GROUP', 'ORDER', 'LIMIT', 'LEFT', 'RIGHT', 'INNER', 'AS', 'AND', 'OR'], true)) {
                $map[strtolower($mm[2])] = $real;
            }
        }
    }
    return $map;
}

/** One SQL string → writes, row-creators, references, comparisons. */
function scan_sql(string $sql, string $at, array $schema, array &$writes, array &$rowCreators, array &$references, array &$sqlCompare): void
{
    $tables = tables_in($sql, $schema);

    // INSERT INTO tbl (cols) VALUES (vals)
    if (preg_match('/\bINSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES\s*\((.*?)\)/is', $sql, $im)) {
        $table = null;
        foreach ($schema as $known => $_) {
            if (strcasecmp($known, $im[1]) === 0) { $table = $known; }
        }
        if ($table !== null) {
            $rowCreators[$table] = true;
            $cols = split_columns($im[2]);
            $vals = split_columns($im[3]);
            foreach ($cols as $i => $col) {
                $col = trim($col, "` \t\n\r");
                if (!preg_match('/^\w+$/', $col)) { continue; }
                $key = $table . '.' . strtolower($col);
                // A multi-row VALUES list puts more than one tuple in $vals[$i]:
                // the set is open rather than guessed.
                if (!isset($vals[$i]) || str_contains($vals[$i], '(')) {
                    note_write($writes, $key, null, false, $at);
                    continue;
                }
                [$isLit, $val] = literal_of($vals[$i]);
                note_write($writes, $key, $val, $isLit, $at);
            }
        }
    } elseif (preg_match('/\bINSERT\s+INTO\s+`?(\w+)`?/i', $sql, $im)) {
        foreach ($schema as $known => $_) {
            if (strcasecmp($known, $im[1]) === 0) { $rowCreators[$known] = true; }
        }
    }

    // UPDATE tbl SET a = 1, b = :b [WHERE …]
    if (preg_match('/\bUPDATE\s+`?(\w+)`?\s+SET\s+(.*?)(?:\s+WHERE\b|$)/is', $sql, $um)) {
        $table = null;
        foreach ($schema as $known => $_) {
            if (strcasecmp($known, $um[1]) === 0) { $table = $known; }
        }
        if ($table !== null) {
            foreach (split_columns($um[2]) as $assignment) {
                if (!preg_match('/^`?(\w+)`?\s*=\s*(.+)$/s', trim($assignment), $am)) { continue; }
                [$isLit, $val] = literal_of($am[2]);
                note_write($writes, $table . '.' . strtolower($am[1]), $val, $isLit, $at);
            }
        }
    }

    // References (is anything reading this?) — qualified refs only, plus any
    // column of a table the statement names unambiguously.
    $aliases = alias_map($sql, $schema);
    foreach ($schema as $table => $cols) {
        if (!isset($tables[strtolower($table)])) { continue; }
        foreach ($cols as $col => $__) {
            $key = $table . '.' . $col;
            if (preg_match('/\b' . preg_quote($col, '/') . '\b/i', $sql)) {
                $references[$key] ??= $at;
            }
        }
    }

    // Equality comparisons → table-qualified only, or a single-table statement.
    if (preg_match_all('/\b(\w+)\.(\w+)\s*=\s*(-?\d+|\'[^\']*\')\s/i', $sql . ' ', $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $table = $aliases[strtolower($mm[1])] ?? null;
            if ($table === null) { continue; }
            $col = strtolower($mm[2]);
            if (!isset($schema[$table][$col])) { continue; }
            [$isLit, $val] = literal_of($mm[3]);
            if ($isLit) { $sqlCompare[$table . '.' . $col][] = ['value' => $val, 'at' => $at]; }
        }
    }
    if (count($tables) === 1) {
        $only = reset($tables);
        if (preg_match_all('/\b(\w+)\s*=\s*(-?\d+|\'[^\']*\')\s/i', $sql . ' ', $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $col = strtolower($mm[1]);
                if (!isset($schema[$only][$col])) { continue; }
                [$isLit, $val] = literal_of($mm[2]);
                if ($isLit) { $sqlCompare[$only . '.' . $col][] = ['value' => $val, 'at' => $at]; }
            }
        }
    }
}

final class SqlVisitor extends NodeVisitorAbstract
{
    public array $sql = [];        // [[text, line]]
    public array $assign = [];     // prop => ['literals'=>[], 'open'=>bool, 'line'=>int]
    public array $compare = [];    // prop => [['value'=>.., 'line'=>int]]
    public array $stack = [];      // class names, innermost last

    public function __construct(private array $classNames) {}

    /**
     * 'INSERT INTO ' . self::$db_table . ' (…)' — the table name is a class
     * constant, so it can only be resolved with the enclosing class in hand.
     * Resolved here, during traversal, while the class stack is still populated
     * (it is empty once traversal finishes). Null = give the statement up.
     */
    private function resolve(string $sql): ?string
    {
        if (!str_contains($sql, "\x00TBL\x00")) {
            return $sql;
        }
        $class = $this->stack !== [] ? end($this->stack) : '';
        $table = $this->classNames[$class] ?? null;
        return $table === null ? null : str_replace("\x00TBL\x00", $table, $sql);
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->stack[] = $node->name !== null ? $node->name->toString() : '';
            return;
        }
        if ($node instanceof String_ && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $node->value)) {
            $this->sql[] = [$node->value, $node->getStartLine()];
            return;
        }
        if ($node instanceof Concat) {
            $parts = [];
            $dyn = [];
            $walk = static function (Node $n) use (&$walk, &$parts): void {
                if ($n instanceof String_) { $parts[] = $n->value; return; }
                if ($n instanceof Concat) { $walk($n->left); $walk($n->right); return; }
                // 'INSERT INTO ' . self::$db_table . ' (…)' — the table name is a
                // class constant, so leave a marker to resolve against the class.
                if ($n instanceof Node\Expr\StaticPropertyFetch) {
                    $name = $n->name instanceof Node\VarLikeIdentifier ? $n->name->toString() : '';
                    $parts[] = $name === 'db_table' ? "\x00TBL\x00" : '?';
                    return;
                }
                // 'INSERT INTO ' . self::TABLE . ' (…)' — a class CONSTANT
                // naming the table, resolved against the enclosing class the
                // same way $db_table is (see the class-map pass above).
                if ($n instanceof Node\Expr\ClassConstFetch
                    && $n->name instanceof Node\Identifier
                    && $n->name->toString() === 'TABLE') {
                    $parts[] = "\x00TBL\x00";
                    return;
                }
                // Any other dynamic part (a variable, a class const) becomes a
                // neutral token so the rest of the statement stays parseable.
                $parts[] = '?';
            };
            $walk($node);
            $joined = implode('', $parts);
            if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $joined)) {
                $resolved = $this->resolve($joined);
                if ($resolved !== null) {
                    $this->sql[] = [$resolved, $node->getStartLine()];
                }
            }
            return;
        }
        if ($node instanceof Node\Expr\Assign && $node->var instanceof PropertyFetch) {
            $name = $node->var->name instanceof Node\Identifier ? $node->var->name->toString() : null;
            if ($name !== null) {
                $isLit = false;
                $val = null;
                if ($node->expr instanceof LNumber) { $isLit = true; $val = $node->expr->value; }
                elseif ($node->expr instanceof String_) { $isLit = true; $val = $node->expr->value; }
                $this->assign[$name] ??= ['literals' => [], 'open' => false, 'line' => $node->getStartLine()];
                if ($isLit) { $this->assign[$name]['literals'][] = $val; } else { $this->assign[$name]['open'] = true; }
            }
            return;
        }
        if ($node instanceof Node\Expr\BinaryOp && $node->left instanceof PropertyFetch) {
            $name = $node->left->name instanceof Node\Identifier ? $node->left->name->toString() : null;
            $right = $node->right;
            $isEq = $node instanceof Node\Expr\BinaryOp\Equal || $node instanceof Node\Expr\BinaryOp\Identical;
            if ($name !== null && $isEq && ($right instanceof LNumber || $right instanceof String_)) {
                $this->compare[$name][] = [
                    'value' => $right instanceof LNumber ? $right->value : $right->value,
                    'line'  => $node->getStartLine(),
                ];
            }
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_ && $this->stack !== []) {
            array_pop($this->stack);
        }
    }
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();

foreach ($appFiles as $file) {
    $rel = str_replace('\\', '/', str_replace($root . DIRECTORY_SEPARATOR, '', $file));
    try {
        $ast = $parser->parse(file_get_contents($file) ?: '');
    } catch (Throwable $e) {
        echo "!! PARSE ERROR: {$rel}: {$e->getMessage()}\n";
        continue;
    }
    if ($ast === null) { continue; }

    $visitor = new SqlVisitor($classNames);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    foreach ($visitor->sql as [$sql, $line]) {
        scan_sql($sql, $rel . ':' . $line, $schema, $writes, $rowCreators, $references, $sqlCompare);
    }
    foreach ($visitor->assign as $prop => $info) {
        $propAssign[$prop] ??= ['literals' => [], 'open' => false, 'at' => $rel . ':' . $info['line']];
        $propAssign[$prop]['literals'] = array_merge($propAssign[$prop]['literals'], $info['literals']);
        $propAssign[$prop]['open'] = $propAssign[$prop]['open'] || $info['open'];
    }
    foreach ($visitor->compare as $prop => $hits) {
        foreach ($hits as $h) {
            $propCompare[$prop][] = ['value' => $h['value'], 'at' => $rel . ':' . $h['line']];
        }
    }
}

// --------------------------------------------------- D1a: uncreatable tables
$findings = [];
$acceptedHits = [];
$writtenColumns = [];   // column keys with a real writer (for D4)
$readTables = [];
foreach ($references as $key => $_) {
    $readTables[explode('.', $key, 2)[0]] = true;
}

foreach ($schema as $table => $cols) {
    if (isset($rowCreators[$table]) || isset($classTables[$table])) {
        continue;
    }
    $acceptedWhy = accepted_reason($table);
    if (!isset($readTables[$table]) && $acceptedWhy === null) {
        continue;   // nothing reads it and nothing writes it: inert schema
    }
    $msg = "table {$table} is read but has no row-creator (no INSERT, no DB_Object class)";
    if ($acceptedWhy !== null) {
        $acceptedHits[] = [$table, $acceptedWhy];
    } else {
        $findings[] = [$table, $msg, 'first reference at ' . ($references[$table . '.id'] ?? 'n/a')];
    }
}

// ------------------------------------------------- D1b + D2: column level
foreach ($schema as $table => $cols) {
    $hasClass = isset($classTables[$table]);
    if (!$hasClass && !isset($rowCreators[$table])) {
        continue;   // already covered by the table-level finding
    }
    foreach ($cols as $col => $meta) {
        $key = $table . '.' . $col;
        if ($meta['generated']) {
            continue;   // the database writes this, not a page
        }

        $readAt     = $references[$key] ?? null;
        $ownerTables = $columnOwners[$col] ?? [];
        $phpCompare = (count($ownerTables) === 1 && $ownerTables[0] === $table) ? ($propCompare[$col] ?? []) : [];
        $compared   = array_merge($sqlCompare[$key] ?? [], $phpCompare);
        if ($readAt === null && $compared === []) {
            continue;   // nothing looks at it at all
        }

        // Writer evidence: SQL writes for THIS table, plus a property assignment
        // by a class that declares the column for THIS table.
        $evidence = $writes[$key] ?? null;
        $classDeclares = $hasClass && in_array($col, $classTables[$table], true);
        if ($classDeclares && isset($propAssign[$col])) {
            $evidence ??= ['literals' => [], 'open' => false, 'at' => $propAssign[$col]['at']];
            $evidence['literals'] = array_merge($evidence['literals'], $propAssign[$col]['literals']);
            $evidence['open'] = $evidence['open'] || $propAssign[$col]['open'];
            $evidence['at'] ??= $propAssign[$col]['at'];
        }
        if ($evidence !== null) {
            $writtenColumns[$key] = true;
        }

        if ($evidence === null) {
            $acceptedWhy = accepted_reason($key);
            if ($acceptedWhy !== null) {
                $acceptedHits[] = [$key, $acceptedWhy];
            } else {
                $findings[] = [$key, "column {$key} is read but never written", 'referenced at ' . ($readAt ?? $compared[0]['at'])];
            }
            continue;
        }

        // D2 — only meaningful when every write is a literal.
        if ($evidence['open'] === true) {
            continue;
        }
        $literals = $evidence['literals'];
        if (($cols[$col]['default'] ?? null) !== null) {
            $literals[] = (string) $cols[$col]['default'];   // written by the column default
        }
        if ($literals === []) {
            continue;
        }
        $written = array_values(array_unique(array_map('norm', $literals)));
        $dead = [];
        foreach ($compared as $h) {
            if (!in_array(norm($h['value']), $written, true)) {
                $dead[norm($h['value']) . '@' . $h['at']] = $h;
            }
        }
        if ($dead === []) {
            continue;
        }
        $first = reset($dead);
        $msg = "column {$key} is only ever written " . json_encode(array_values($written))
             . ' but compared for equality against ' . json_encode(norm($first['value']))
             . ' (at ' . $first['at'] . ') — that branch can never be taken';
        $acceptedWhy = accepted_reason($key);
        if ($acceptedWhy !== null) {
            $acceptedHits[] = [$key, $acceptedWhy];
        } else {
            $findings[] = [$key, $msg, 'writes at ' . ($evidence['at'] ?? 'n/a')];
        }
    }
}

// ------------------------------------ D4: accepted entries that outlived it
// An acceptance documents a debt. When the debt is paid the entry has to go,
// or the list quietly becomes a blind spot that hides the next regression — so
// a fixed entry fails the build instead of being ignored forever.
foreach (ACCEPTED_STATE as $key => $reason) {
    $obsolete = str_contains($key, '.')
        ? isset($writtenColumns[$key])
        : (isset($rowCreators[$key]) || isset($classTables[$key]));
    if ($obsolete) {
        $findings[] = ['accepted', "accepted entry {$key} is obsolete — that state now has a writer, so delete the entry "
            . 'and let the rule guard it again', $reason];
    }
}

// ------------------------------------------------------- D3: promise registry
$coveredPhrases = [];   // file => [lowercased phrase => true]
$allEntries = array_merge(PROMISES, ACCEPTED_PROMISES);
foreach ($allEntries as $entry) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['file']);
    if (!is_file($path)) {
        $findings[] = ['promise', "registry entry for {$entry['file']} points at a file that no longer exists", "covers \"{$entry['copy']}\""];
        continue;
    }
    if (stripos(file_get_contents($path) ?: '', $entry['copy']) === false) {
        $findings[] = ['promise', "registry entry is stale: \"{$entry['copy']}\" is no longer in {$entry['file']}", $entry['actor'] ?? $entry['reason']];
        continue;
    }
    foreach (PROMISE_PATTERNS as $phrase) {
        if (stripos($entry['copy'], $phrase) !== false) {
            $coveredPhrases[$entry['file']][strtolower($phrase)] = true;
        }
    }
}

// Registered promises whose dependency cannot be reached.
foreach (PROMISES as $p) {
    $dep = (string) $p['depends'];
    $depTable = str_contains($dep, '.') ? explode('.', $dep, 2)[0] : $dep;
    $depCol   = str_contains($dep, '.') ? explode('.', $dep, 2)[1] : null;

    $known = null;
    foreach (array_keys($schema) as $knownTable) {
        if (strcasecmp($knownTable, $depTable) === 0) { $known = $knownTable; }
    }
    if ($known === null) {
        $findings[] = ['promise', "promise \"{$p['copy']}\" in {$p['file']} depends on {$dep}, which is not a table in this database", $p['actor']];
        continue;
    }
    if (!isset($rowCreators[$known]) && !isset($classTables[$known])) {
        $findings[] = ['promise', "promise \"{$p['copy']}\" in {$p['file']} depends on {$known}, which nothing can create", $p['actor']];
        continue;
    }
    if ($depCol === null) {
        continue;
    }
    $col = strtolower($depCol);
    $written = isset($writes[$known . '.' . $col])
        || (in_array($col, $classTables[$known] ?? [], true) && isset($propAssign[$col]));
    if (!$written && accepted_reason($known . '.' . $col) === null) {
        $findings[] = ['promise', "promise \"{$p['copy']}\" in {$p['file']} depends on {$dep}, which nothing writes", $p['actor'] . " — but no code writes {$dep}"];
    }
}

// Every promise phrase in user-facing copy must be covered by a registration.
foreach ($copyFiles as $file) {
    $rel = str_replace('\\', '/', str_replace($root . DIRECTORY_SEPARATOR, '', $file));
    $src = file_get_contents($file) ?: '';
    // Blank out comments IN PLACE so byte offsets (and therefore line numbers)
    // stay exact across multi-line statements.
    $clean = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) {
            $clean .= preg_replace('/[^\n]/', ' ', $tok[1]);
        } elseif (is_array($tok)) {
            $clean .= $tok[1];
        } else {
            $clean .= $tok;
        }
    }
    foreach (PROMISE_PATTERNS as $phrase) {
        $offset = 0;
        $lower = strtolower($clean);
        $needle = strtolower($phrase);
        while (($pos = strpos($lower, $needle, $offset)) !== false) {
            $offset = $pos + strlen($needle);
            if (isset($coveredPhrases[$rel][$needle])) {
                continue;
            }
            $line = substr_count(substr($clean, 0, $pos), "\n") + 1;
            $snippet = trim(preg_replace('/\s+/', ' ', substr($clean, max(0, $pos - 60), 130)));
            $findings[] = ['copy', "unregistered promise phrase \"{$phrase}\" in {$rel}:{$line}", $snippet];
        }
    }
}

// ------------------------------------------------------------------- report
echo "=== SWEEP D — role-facing promises vs the state behind them ===\n";
printf("schema: %d tables | writers indexed from %d files | %d promises registered, %d accepted\n\n",
    count($schema), count($appFiles), count(PROMISES), count(ACCEPTED_PROMISES));

if ($verbose) {
    foreach (PROMISES as $p) {
        printf("ok  %s: \"%s\" <- %s (%s)\n", $p['file'], $p['copy'], $p['depends'], $p['actor']);
    }
    echo "\n";
}

foreach ($acceptedHits as [$key, $reason]) {
    printf("accepted  %s — %s\n", $key, $reason);
}
foreach (ACCEPTED_PROMISES as $ap) {
    printf("accepted  %s — \"%s\": %s\n", $ap['file'], $ap['copy'], $ap['reason']);
}
if ($acceptedHits !== [] || ACCEPTED_PROMISES !== []) {
    echo "\n";
}

foreach ($findings as [$key, $msg, $detail]) {
    printf("!! %s: %s\n     %s\n", $key, $msg, $detail);
}

echo "\n";
echo $findings === []
    ? 'SWEEP D CLEAN (' . count(PROMISES) . ' promises verified, ' . (count($acceptedHits) + count(ACCEPTED_PROMISES)) . " accepted with a reason)\n"
    : 'SWEEP D: ' . count($findings) . " PROBLEM(S)\n";
exit($findings === [] ? 0 : 1);
