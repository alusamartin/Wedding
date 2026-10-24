<?php
declare(strict_types=1);

/**
 * Fixture page for tests/PromisesSweepTest — reads state the fixture project
 * can never write:
 *
 *   tblgallery.title   a column of a creatable table, read here and written
 *                      nowhere in this tree
 *   tblvendors         a table with no DB_Object class and no INSERT here, so
 *                      nothing in this tree can create a row in it
 */

global $db;

$rows    = $db->fetchAll('SELECT title FROM tblgallery ORDER BY id DESC LIMIT 5');
$vendors = $db->fetchAll('SELECT id FROM tblvendors LIMIT 1');

foreach ($rows as $row) {
    echo htmlspecialchars((string) $row->title);
}
