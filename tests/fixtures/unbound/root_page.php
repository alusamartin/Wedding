<?php
/**
 * Deliberately broken fixture for sweep G (scripts/audit_unbound_sql.php):
 * the raw handle in the public root, plus the two shapes the sweep must NOT
 * report — a raw handle that prepares, and request data in a bind array.
 *
 * DATA, NOT A PAGE. The `$_SESSION` below is deliberately in the wrong place for
 * one rule and the right place for another: PDOStatement::execute() takes the
 * bind array first, so it is a value being bound, not SQL. An audit that cannot
 * tell those apart invents findings, which is why this file exists.
 */
$pdo = new PDO('mysql:host=localhost;dbname=fixture');
$pdo->query('SELECT 1');

// ---- NOT a finding: the raw handle, preparing -----------------------------
$pdo->prepare('SELECT 1');

// ---- NOT a finding: the wrapper, binding ----------------------------------
$db->execute('SELECT * FROM tblusers WHERE id = :id', [':id' => 7]);
$stmt = $db->prepare('SELECT * FROM tblusers WHERE id = :id');
$stmt->execute([':id' => $_SESSION['id']]);
