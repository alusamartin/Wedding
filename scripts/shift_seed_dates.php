<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

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
 * Runnable directly (shifts DB_NAME):
 *   php scripts/shift_seed_dates.php
 * Or called as a function from demo_reset.php with an explicit database:
 *   shift_seed_dates('some_db');
 */

/**
 * Run the seed date shift against $dbName.
 *
 * @param string $dbName Target database (config credentials are reused).
 * @param bool   $echo   Print progress/verification output.
 */
function shift_seed_dates(string $dbName, bool $echo = true): void
{
    $say = static function (string $m) use ($echo): void {
        if ($echo) {
            echo $m . "\n";
        }
    };

    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stepDays = 364;
    $seedBookings = [200, 201, 202, 203, 204]; // 215 = real user, untouched
    $calendarLeadDays = 20; // the VC Meeting widget row sits 20 days before booking 200's wedding
    $bookingList = implode(',', $seedBookings);

    $show = static function (string $label, string $sql) use ($pdo, $say): void {
        $say("--- $label");
        foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
            $say('  ' . implode(' | ', $row));
        }
    };

    // ---------------------------------------------------------------- backup
    // Only meaningful for the app's live database — a fresh demo_reset import
    // is reproducible from the seed files, so backing it up would just clobber
    // the last real backup.
    if ($dbName === DB_NAME) {
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
        $say("Backup written: $backupFile");
    }

    // ---------------------------------------------------------------- compute shifts
    $shifts = []; // booking_id => days to add
    $say('--- Planned shifts (364-day multiples until the wedding is in the future)');
    $st = $pdo->query('SELECT booking_id, wedding_date FROM tblweddingbook WHERE booking_id IN (' . $bookingList . ')');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['booking_id'];
        $wedding = new DateTimeImmutable($row['wedding_date']);
        $tomorrow = new DateTimeImmutable('tomorrow');
        $k = 0;
        if ($wedding < $tomorrow) {
            $k = (int) ceil(($tomorrow->getTimestamp() - $wedding->getTimestamp()) / 86400 / $stepDays);
        }
        $shifts[$id] = $k * $stepDays;
        $say(sprintf('  booking %d: %s -> +%dd (k=%d)', $id, $row['wedding_date'], $shifts[$id], $k));
    }

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
            // Two placeholders for the same shift: PDO here does not emulate
            // prepares, and one name used twice in a statement is "Invalid
            // parameter number".
            $pdo->prepare("UPDATE events
                           SET start = DATE_ADD(start, INTERVAL :d1 DAY),
                               end   = DATE_ADD(end,   INTERVAL :d2 DAY)
                           WHERE booking_id = :id")->execute([':d1' => $days, ':d2' => $days, ':id' => $id]);
            $pdo->prepare("UPDATE tblwedding_websites
                           SET wedding_date = DATE_FORMAT(DATE_ADD(wedding_date, INTERVAL :d DAY), '%Y-%m-%d')
                           WHERE booking_id = :id")->execute([':d' => $days, ':id' => $id]);
            $pdo->prepare("UPDATE tblvendor_bookings
                           SET event_date = DATE_FORMAT(DATE_ADD(event_date, INTERVAL :d DAY), '%Y-%m-%d')
                           WHERE booking_id = :id")->execute([':d' => $days, ':id' => $id]);
        }

        // Calendar widget row: recomputed deterministically from booking 200's wedding.
        $pdo->exec("UPDATE calendar
                    SET startdate = CONCAT(DATE_FORMAT(DATE_ADD((SELECT wedding_date FROM tblweddingbook WHERE booking_id = 200), INTERVAL " . (-$calendarLeadDays) . " DAY), '%Y-%m-%d'), 'T18:00:00+03:00'),
                        enddate   = CONCAT(DATE_FORMAT(DATE_ADD((SELECT wedding_date FROM tblweddingbook WHERE booking_id = 200), INTERVAL " . (-$calendarLeadDays) . " DAY), '%Y-%m-%d'), 'T20:00:00+03:00')");

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
                WHERE l2.booking_id IN ($bookingList)
            ) calc ON calc.id = l.id
            SET l.date_issue    = TIMESTAMP(DATE_ADD(DATE(l.date_issue), INTERVAL DATEDIFF(calc.anchor, calc.max_date) DAY), TIME(l.date_issue)),
                l.date_modified = TIMESTAMP(DATE_ADD(DATE(l.date_modified), INTERVAL DATEDIFF(calc.anchor, calc.max_date) DAY), TIME(l.date_modified))
        ");

        $pdo->commit();
        $say('Shift applied.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Date shift failed, rolled back: ' . $e->getMessage(), 0, $e);
    }

    // ---------------------------------------------------------------- verify
    $show('AFTER bookings', 'SELECT booking_id, bride, groom, wedding_date FROM tblweddingbook WHERE booking_id IN (' . $bookingList . ') ORDER BY booking_id');
    $show('events', 'SELECT id, booking_id, title, DATE(start) d, DATE(end) de FROM events ORDER BY id');
    $show('websites', 'SELECT id, booking_id, slug, wedding_date FROM tblwedding_websites');
    $show('vendor_bookings', 'SELECT id, booking_id, event_date, status FROM tblvendor_bookings');
    $show('calendar', 'SELECT id, startdate, enddate FROM calendar');
    $show('payments', 'SELECT id, booking_id, payment, DATE(date_issue) d FROM tbl_liquidation ORDER BY booking_id, date_issue');

    $stale = $pdo->query('SELECT COUNT(*) FROM tblweddingbook WHERE booking_id IN (' . $bookingList . ') AND wedding_date < CURDATE()')->fetchColumn();
    if ($stale > 0) {
        throw new RuntimeException("VERIFY FAILED: $stale seed bookings are still in the past. Restore from the backup if needed.");
    }
    $say('VERIFY OK: all seed weddings are in the future. Booking 215 untouched.');
}

// Direct execution entry (no-op when included from demo_reset.php).
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    require __DIR__ . '/../admin/include/config.php';
    try {
        shift_seed_dates(DB_NAME);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
