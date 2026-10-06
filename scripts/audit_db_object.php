<?php
declare(strict_types=1);

/**
 * Audit sweep A — DB_Object subclasses vs the live schema.
 *
 * For every class extending DB_Object: does the declared table exist, and does
 * every declared $db_table_fields entry exist as a column (typo/legacy-name
 * detector — the Users::email_exists bug class, caught mechanically)?
 *
 * usage: php scripts/audit_db_object.php
 */

require __DIR__ . '/../admin/include/config.php';
require_once __DIR__ . '/../admin/include/Database.php';
require_once __DIR__ . '/../admin/include/db_object.php';

global $db;
$db = new Database();

// 1. Parse every include/*.php for classes extending DB_Object.
$files = array_merge(
    glob(__DIR__ . '/../admin/include/*.php') ?: [],
    glob(__DIR__ . '/../admin/include/**/*.php') ?: []
);
$classes = [];
foreach (array_unique($files) as $file) {
    $code = file_get_contents($file);
    if (!preg_match('/class\s+(\w+)[^{]*\{/', $code, $m)) {
        continue;
    }
    // Grab each class block crudely: name, db_table, db_table_fields.
    if (!preg_match_all('/class\s+(\w+)(\s+extends\s+DB_Object)?\s*\{(.*?)\n\}/s', $code, $cm, PREG_SET_ORDER)) {
        continue;
    }
    // Grab each class block: name, db_table, db_table_fields (all classes —
    // BookingEvent-style tables are managed with raw SQL too, so a missing
    // table/column is worth flagging for any class that declares them).
    foreach ($cm as $c) {
        $table = null;
        if (preg_match('/\$db_table\s*=\s*["\'](\w+)["\']/', $c[3], $tm)) {
            $table = $tm[1];
        }
        $fields = [];
        if (preg_match('/\$db_table_fields\s*=\s*(?:array\(|\[)(.*?)(?:\)|\])/s', $c[3], $fm)) {
            preg_match_all('/["\'](\w+)["\']/', $fm[1], $fn);
            $fields = $fn[1];
        }
        $classes[] = [
            'class'  => $c[1],
            'file'   => basename($file),
            'table'  => $table,
            'fields' => $fields,
        ];
    }
}

// 2. Live schema.
$tables = [];
foreach ($db->fetchAll('SHOW TABLES') as $row) {
    $arr = (array) $row;
    $tables[strtolower((string) current($arr))] = true;
}

$errors = 0;
foreach ($classes as $c) {
    if ($c['table'] === null) {
        echo "?? {$c['class']} ({$c['file']}): no db_table found\n";
        continue;
    }
    if (!isset($tables[strtolower($c['table'])])) {
        echo "!! {$c['class']} ({$c['file']}): table {$c['table']} DOES NOT EXIST\n";
        $errors++;
        continue;
    }
    $cols = [];
    foreach ($db->fetchAll("SHOW COLUMNS FROM `{$c['table']}`") as $col) {
        $cols[strtolower($col->Field)] = true;
    }
    foreach ($c['fields'] as $f) {
        if (!isset($cols[strtolower($f)])) {
            echo "!! {$c['class']} ({$c['file']}): {$c['table']}.{$f} in db_table_fields but NOT a column\n";
            $errors++;
        }
    }
    echo "ok {$c['class']}: {$c['table']} (" . count($c['fields']) . " fields)\n";
}

echo $errors === 0 ? "\nSWEEP A CLEAN\n" : "\nSWEEP A: {$errors} PROBLEM(S)\n";
exit($errors === 0 ? 0 : 1);
