<?php
/**
 * Deliberately broken fixture for sweep G (scripts/audit_unbound_sql.php).
 *
 * DATA, NOT A PAGE: nothing includes it and nothing routes to it. Every reported
 * line is one the sweep must name, and the last block is the shape it must leave
 * alone — a value bound to a prepared statement, which is what the same SQL
 * looks like when it is written correctly.
 */
$db = new Database();

// ---- G1: the legacy door ------------------------------------------------
$db->query('SELECT * FROM tblusers');
$db->query($sql);
$rows = $db->executeQuery();

// ---- G1: quoting a value into the statement instead of binding it --------
$name = $db->escape_string($_POST['name']);
$db->query("SELECT * FROM tblusers WHERE fullname = '{$name}'");

// ---- G2: the raw handle on a page that answers a request ----------------
$pdo = new PDO('mysql:host=localhost;dbname=fixture');
$pdo->query('SELECT * FROM tblusers');
$pdo->exec('DELETE FROM tblusers');

// ---- G3: request data inside the statement text -------------------------
$db->prepare('SELECT * FROM tblusers WHERE id = ' . $_GET['id']);
$db->prepare("SELECT * FROM tblusers WHERE email = '{$_GET['email']}'");

// ---- NOT a finding: the same value, bound ---------------------------------
$stmt = $db->prepare('SELECT * FROM tblusers WHERE id = :id');
$stmt->execute([':id' => $_GET['id']]);
