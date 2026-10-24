<?php
/**
 * Deliberately narrow fixture for sweep G (scripts/audit_unbound_sql.php): the
 * ONE raw command the sweep allows.
 *
 * DATA, NOT A PAGE. The connection's own timezone command is a fixed literal set
 * before any caller exists, and it is allow-listed by its statement text rather
 * than by this file's name — so a second raw command added here, with other SQL,
 * is still a finding. This file proves the exception is keyed on the statement.
 */
$pdo->exec("SET time_zone = '+03:00'");
