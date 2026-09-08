<?php
declare(strict_types=1);

/**
 * Cron-safe housekeeping sweeper for the notification archive.
 *
 * Alerts accumulate forever otherwise: every deposit, vendor enquiry,
 * invite stamp and reminder batch writes a row, and a busy planner's
 * bell and archive become noise within weeks. This script prunes rows
 * past their retention window via Notification::prune_old():
 *
 *   - read alerts older than 60 days are removed,
 *   - unread alerts get 180 days before they are dropped,
 *   - mail-log rows travel with their notification.
 *
 * Usage:
 *   php scripts/notify_prune.php [--read-days=60] [--unread-days=180] [--quiet]
 *
 * Exit codes: 0 = ran (nothing is a failure), 1 = usage/lock error.
 *
 * Cron example (daily, off-peak):
 *   15 3 * * * php /path/to/scripts/notify_prune.php --quiet
 */

$opts = getopt('', ['read-days::', 'unread-days::', 'quiet']) ?: [];
$quiet      = isset($opts['quiet']);
$readDays   = max(1, (int) ($opts['read-days'] ?? 60));
$unreadDays = max(1, (int) ($opts['unread-days'] ?? 180));

// Bootstrap exactly like the other cron scripts (config → Database → model).
$root = dirname(__DIR__);
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/Database.php';
require_once $root . '/admin/include/db_object.php';
if (!class_exists('Notification', false)) {
    require_once $root . '/admin/include/Notification.php';
}
global $db;
$db = new Database();

// Single-flight lock: pruning is idempotent, but overlapping runs would
// still double-count and double-delete mail-log joins.
$lockFile = sys_get_temp_dir() . '/wpms_notify_prune.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo "another notify_prune run holds the lock — exiting\n";
    }
    exit(1);
}

$before = (int) $db->fetchValue('SELECT COUNT(*) FROM tblnotifications');
$pruned = Notification::prune_old($readDays, $unreadDays);
$after  = (int) $db->fetchValue('SELECT COUNT(*) FROM tblnotifications');

if (!$quiet) {
    echo "pruned {$pruned} notifications (before={$before}, after={$after}); "
        . "read older than {$readDays}d, unread older than {$unreadDays}d\n";
}

flock($lock, LOCK_UN);
fclose($lock);
