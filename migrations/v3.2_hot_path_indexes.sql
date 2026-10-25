-- ======================================================================
-- WPMS Kenya v3.2 — indexes on the three columns every page filters by.
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v3.2_hot_path_indexes.sql
--
-- Why this exists
--   The guest list is the busiest table in a wedding planner: 29 distinct
--   queries filter `tblguest` by `booking_id` — the guest sheet, the RSVP
--   dashboard, the headcount brief, the reminder job, the printable seating
--   sheet, the MCP read tools. `tblguest` had no index on it at all, so every
--   one of them scanned the table and then filtered. One wedding with 400
--   guests is fine; a planner with thirty weddings is not, and the demo's
--   seeded ~3,400 rows hide it completely.
--
--   The same shape applies to the two columns on `tblweddingbook`: the couple's
--   portal resolves "my booking" by `user_id`, and per-planner scoping
--   (`planner_scope_sql()` / `staff_may_open_organizer()`) filters every staff
--   list by `organizer_id`. Both were unindexed, so a scoped planner's bookings
--   page read the whole table to throw most of it away.
--
-- WHAT THIS DOES NOT DO
--   - No table, column or row is rewritten; indexes only.
--   - Four indexes, not a composite: `events` was added to the sweep after the
--     count (17 call sites) made the same point as the guest list.
--   - No composite index is added speculatively. Each of these is on a column
--     a real query filters by today, measured by grepping the call sites —
--     not by guessing what might become hot.
--
-- Verified against code after migration:
--   SHOW INDEX FROM tblguest;
--   SHOW INDEX FROM tblweddingbook;
--   EXPLAIN SELECT * FROM tblguest WHERE booking_id = 200;
--   vendor/bin/phpunit tests/CoupleGuestRsvpTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. tblguest(booking_id) — every guest-list read.
-- ----------------------------------------------------------------------
SET @wpms_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tblguest'
     AND INDEX_NAME   = 'idx_guest_booking'
);
SET @wpms_sql := IF(@wpms_idx = 0,
  'ALTER TABLE `tblguest` ADD KEY `idx_guest_booking` (`booking_id`)',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;

-- ----------------------------------------------------------------------
-- 2. tblweddingbook(user_id) — the couple's own booking(s).
-- ----------------------------------------------------------------------
SET @wpms_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tblweddingbook'
     AND INDEX_NAME   = 'idx_book_user'
);
SET @wpms_sql := IF(@wpms_idx = 0,
  'ALTER TABLE `tblweddingbook` ADD KEY `idx_book_user` (`user_id`)',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;

-- ----------------------------------------------------------------------
-- 3. tblweddingbook(organizer_id) — per-planner scoping on every staff list.
-- ----------------------------------------------------------------------
SET @wpms_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tblweddingbook'
     AND INDEX_NAME   = 'idx_book_organizer'
);
SET @wpms_sql := IF(@wpms_idx = 0,
  'ALTER TABLE `tblweddingbook` ADD KEY `idx_book_organizer` (`organizer_id`)',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;

-- ----------------------------------------------------------------------
-- 4. events(booking_id) — the function timeline, read 17 ways.
--
--    `events` is the wedding-day schedule (Ruracio, church, reception,
--    after-party) and every reader slices it by booking: the calendar, the
--    venue sheet's headcount plan, the coverage board, the couple's details
--    page, BookingEvent's brief. Unindexed, each was a table scan under a
--    sort.
-- ----------------------------------------------------------------------
SET @wpms_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'events'
     AND INDEX_NAME   = 'idx_events_booking'
);
SET @wpms_sql := IF(@wpms_idx = 0,
  'ALTER TABLE `events` ADD KEY `idx_events_booking` (`booking_id`)',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;
