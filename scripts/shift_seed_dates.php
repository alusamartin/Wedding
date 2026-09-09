<?php
declare(strict_types=1);

/**
 * Shift every seeded wedding forward by whole 52-week (364-day) multiples so
 * demo data always shows FUTURE weddings: countdowns tick, RSVP reminders
 * fire, calendars open on the right month, and no booking looks stale in
 * front of a client. 364-day steps preserve weekdays, so "Sat …" formats
 * and weekend-venue logic stay true.
 *
 * Per booking (bookings 200-204 only — 215 is a real self-registered user):
 *   k = smallest whole number of 364-day shifts that puts wedding_date in
 *   the future (k=0 for bookings already future, so the script is safe to
 *   re-run). The same k is applied to that booking's events, website row
 *   and vendor bookings. The calendar widget row (no booking_id column) is
 *   recomputed as 20 days before booking 200's wedding.
 *
 * Payments are rebased (independently of k) so the LATEST payment per
 * booking lands 30 days before that booking's wedding date, preserving
 * inter-payment spacing and KSh amounts — payment history only makes sense
 * near the wedding, whatever year it is.
 *
 * NOT touched: booking 215, tblchecklist (relative due_offset_days),
 * tblguest RSVP timestamps (recent and valid), tblpostwedding.
 *
 * Safety: dumps the affected tables to
 * DATABASE FILE/pre_date_shift_backup.sql and verifies every seed wedding
 * is in the future before declaring success.
 *
 * Usage: php scripts/shift_seed_dates.php
 */

require __DIR__ . '/../admin/include/config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const STEP_DAYS = 364;
const SEED_BOOKINGS = [200, 201, 202, 203, 204]; // 215 = real user, untouched
const CALENDAR_LEAD_DAYS = 20; // the VC Meeting widget row sits 20 days before booking 200's wedding

function show(PDO $pdo, string $label, string $sql): void
{
    echo "--- $label\n";
    foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
        echo '  ' . implode(' | ', $row) . "\n";
    }
}

// ---------------------------------------------------------------- backup
$backupFile = __DIR__ . '/../DATABASE FILE/pre_date_shift_backup.sql';
$dump = [];
$dump[] = '-- Pre-shift backup of seed tables before scripts/shift_seed_dates.php';
$dump[] = '-- Generated ' . date('c');
foreach (['tblweddingbook', 'events', 'tblwedding_websites', 'tblvendor_bookings', 'calendar', 'tbl_liquidation'] as $table) {
    $dump[] = "\n-- $table";
    foreach ($pdo->query("SELECT * FROM `$table`", PDO::FETCH_ASSOC) as $row) {
        $cols = '`' . implode('`,`', array_keys($row)) . '`';
        $vals = implode(',', array_map(
            fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
            $row
        ));
        $dump[] = "INSERT INTO `$table` ($cols) VALUES ($vals);";
    }
}
file_put_contents($backupFile, implode("\n", $dump) . "\n");
echo "Backup written: $backupFile\n\n";

// ---------------------------------------------------------------- compute shifts
$shifts = []; // booking_id => days to add
echo "--- Planned shifts (364-day multiples until the wedding is in the future)\n";
$st = $pdo->query('SELECT booking_id, wedding_date FROM tblweddingbook WHERE booking_id IN (' . implode(',', SEED_BOOKINGS) . ')');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $id = (int) $row['booking_id'];
    $wedding = new DateTimeImmutable($row['wedding_date']);
    $tomorrow = new DateTimeImmutable('tomorrow');
    $k = 0;
    if ($wedding < $tomorrow) {
        $k = (int) ceil(($tomorrow->getTimestamp() - $wedding->getTimestamp()) / 86400 / STEP_DAYS);
    }
    $shifts[$id] = $k * STEP_DAYS;
    echo sprintf("  booking %d: %s -> +d%d (k=%d)\n", $id, $row['wedding_date'], $shifts[$id], $k);
}
echo "\n";

// ---------------------------------------------------------------- apply
$pdo->beginTransaction();
try {
    foreach ($shifts as $id => $days) {
        if ($days === 0) {
            continue;
        }
        $pdo->prepare("UPDATE tblweddingbook
                       SET wedding_date = DATE_FORMAT(DATE_ADD(wedding_date, INTERVAL :d DAY), '%Y-%m-%d')
                       WHERE booking_id = :id")->execute([':d' => $days, ':id' => $id]);
        $pdo->prepare("UPDATE events
                       SET start = DATE_ADD(start, INTERVAL :d DAY),
                           end   = DATE_ADD(end,   INTERVAL :d DAY)
                       WHERE booking_id = :id")->execute([':d' => $days, ':id' => $id]);
        $pdo->prepare("UPDATE tblwedding_websites
                       SET wedding_date = DATE_FORMAT(DATE_ADD(wedding_date, INTERVAL :d DAY), '%Y-%m-%d')
                       WHERE booking_id = :id")->execute([':d' => $days, ':id' => $id]);
        $pdo->prepare("UPDATE tblvendor_bookings
                       SET event_date = DATE_FORMAT(DATE_ADD(event_date, INTERVAL :d DAY), '%Y-%m-%d')
                       WHERE booking_id = :id")->execute([':d' => $days, ':id' => $id]);
    }

    // Calendar widget row: recomputed deterministically from booking 200's wedding.
    $pdo->exec("UPDATE calendar
                SET startdate = CONCAT(DATE_FORMAT(DATE_ADD((SELECT wedding_date FROM tblweddingbook WHERE booking_id = 200), INTERVAL " . (-CALENDAR_LEAD_DAYS) . " DAY), '%Y-%m-%d'), 'T18:00:00+03:00'),
                    enddate   = CONCAT(DATE_FORMAT(DATE_ADD((SELECT wedding_date FROM tblweddingbook WHERE booking_id = 200), INTERVAL " . (-CALENDAR_LEAD_DAYS) . " DAY), '%Y-%m-%d'), 'T20:00:00+03:00')");

    // Payments: anchor the latest payment per seed booking at wedding-30d.
    $pdo->exec("
        UPDATE tbl_liquidation l
        JOIN (
            SELECT l2.id,
                   (SELECT DATE_FORMAT(DATE_ADD(w.wedding_date, INTERVAL -30 DAY), '%Y-%m-%d')
                      FROM tblweddingbook w WHERE w.booking_id = l2.booking_id) AS anchor,
                   (SELECT MAX(DATE(l2b.date_issue)) FROM tbl_liquidation l2b
                     WHERE l2b.booking_id = l2.booking_id) AS max_date
            FROM tbl_liquidation l2
            WHERE l2.booking_id IN (" . implode(',', SEED_BOOKINGS) . ")
        ) calc ON calc.id = l.id
        SET l.date_issue    = TIMESTAMP(DATE_ADD(DATE(l.date_issue), INTERVAL DATEDIFF(calc.anchor, calc.max_date) DAY), TIME(l.date_issue)),
            l.date_modified = TIMESTAMP(DATE_ADD(DATE(l.date_modified), INTERVAL DATEDIFF(calc.anchor, calc.max_date) DAY), TIME(l.date_modified))
    ");

    $pdo->commit();
    echo "Shift applied.\n\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- verify
show($pdo, 'AFTER bookings', 'SELECT booking_id, bride, groom, wedding_date FROM tblweddingbook WHERE booking_id IN (' . implode(',', SEED_BOOKINGS) . ') ORDER BY booking_id');
show($pdo, 'events', 'SELECT id, booking_id, title, DATE(start) d, DATE(end) de FROM events ORDER BY id');
show($pdo, 'websites', 'SELECT id, booking_id, slug, wedding_date FROM tblwedding_websites');
show($pdo, 'vendor_bookings', 'SELECT id, booking_id, event_date, status FROM tblvendor_bookings');
show($pdo, 'calendar', 'SELECT id, startdate, enddate FROM calendar');
show($pdo, 'payments', 'SELECT id, booking_id, payment, DATE(date_issue) d FROM tbl_liquidation ORDER BY booking_id, date_issue');

$stale = $pdo->query('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id IN (' . implode(',', SEED_BOOKINGS) . ') AND wedding_date < CURDATE()')->fetchColumn();
if ($stale > 0) {
    fwrite(STDERR, "VERIFY FAILED: $stale seed bookings are still in the past. Restore from the backup if needed.\n");
    exit(1);
}
echo "\nVERIFY OK: all seed weddings are in the future. Booking 215 untouched.\n";
