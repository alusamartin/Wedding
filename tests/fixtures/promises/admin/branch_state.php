<?php
declare(strict_types=1);

/**
 * Fixture page for tests/PromisesSweepTest — the unreachable equality branch.
 *
 * The only write to tblgallery.type anywhere in this tree sets it to 1, and
 * the page then looks for 2. Rows can be created, the column can be written:
 * that branch still can never be taken, which is what sweep D2 must report.
 */

global $db;

$db->execute('UPDATE tblgallery SET type = 1 WHERE id = :id', [':id' => 1]);
$featured = $db->fetchAll('SELECT id FROM tblgallery WHERE type = 2');
