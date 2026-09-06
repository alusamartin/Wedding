<?php
declare(strict_types=1);

/**
 * Cron-safe retry sweeper for the notification email outbox.
 *
 * Rows written to tbl_mail_logs while WPMS_MAIL_ENABLED was off (or while
 * the MTA was down) sit as 'pending'. This script re-attempts them through
 * the same opt-in transport as Notification::create():
 *
 *   - With WPMS_MAIL_ENABLED=1: sends up to --limit pending rows (oldest
 *     first), incrementing attempts; a row that fails on its final allowed
 *     attempt is marked 'abandoned' so it can never loop forever.
 *   - Without the flag: a deliberate no-op (exit 0) — safe to leave in the
 *     crontab across environments.
 *
 * Usage:
 *   php scripts/mail_retry.php [--limit=50] [--max-attempts=5] [--quiet]
 *
 * Exit codes: 0 = ran (or no-op), 1 = usage/lock error, 2 = transport off
 * when --strict is passed.
 *
 * Cron example (every 15 minutes):
 *   *\/15 * * * * php /path/to/scripts/mail_retry.php --quiet
 */

$opts = getopt('', ['limit::', 'max-attempts::', 'quiet', 'strict']) ?: [];
$quiet  = isset($opts['quiet']);
$strict = isset($opts['strict']);
$limit  = max(1, (int) ($opts['limit'] ?? 50));
$cap    = max(1, (int) ($opts['max-attempts'] ?? 5));

// Bootstrap exactly like the test harness does (config → Database → model).
$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/Database.php';
require_once $root . '/admin/include/db_object.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
global $db;
$db = new Database();

// Single-flight lock: overlapping cron runs would double-send.
$lockFile = sys_get_temp_dir() . '/wpms_mail_retry.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another mail_retry run holds the lock — exiting\n";
    }
    exit(1);
}

$transportOn = getenv('WPMS_MAIL_ENABLED') === '1';
if (!$transportOn) {
    if ($strict) {
        if (!$quiet) {
            echo "WPMS_MAIL_ENABLED is not set — pending rows left untouched\n";
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(2);
    }
    if (!$quiet) {
        echo "WPMS_MAIL_ENABLED is not set — nothing to do (pending rows preserved)\n";
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$pending = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM tbl_mail_logs WHERE status = 'pending' AND attempts < :cap",
    [':cap' => $cap]
);
if ($pending === 0) {
    if (!$quiet) {
        echo "no pending mail rows below the attempt cap ({$cap})\n";
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$r = Notification::retry_pending($limit, $cap);
if (!$quiet) {
    echo "pending queued: {$pending}, attempted: {$r['attempted']}, sent: {$r['sent']}, "
        . "failed: {$r['failed']}, abandoned: {$r['abandoned']}\n";
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
