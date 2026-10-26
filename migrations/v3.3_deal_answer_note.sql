-- ======================================================================
-- WPMS Kenya v3.3 — the words that come with a "no".
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v3.3_deal_answer_note.sql
--
-- Why this exists
--   A vendor answers an enquiry with Accept or Decline, and until now the
--   DECLINE said only "cancelled". The couple read a grey badge, the planner
--   read a grey badge, and the only place the reason ever lived was a phone
--   call that nobody recorded. For a wedding that is the difference between
--   "we were turned down" and "they were already booked that Saturday, try
--   their sister studio" — the second is still a booking, the first is a
--   dead end.
--
--   So the vendor gets a short reason field when they decline, and the column
--   below is where it is kept. It is shown to the couple on their enquiries
--   list and their vendor plan, and to their planner in the enquiry ledger,
--   in the vendor's own words, escaped.
--
--   v3.3b — the planner gets the same field when they drop a deal, because the
--   couple and the vendor read the same grey badge and deserve to know WHO
--   said no: "the caterer is already booked" is a different phone call from
--   "your planner found a better price". `status_note_by` is the one word that
--   tells them apart ('vendor' | 'planner'); NULL means no reason was given.
--
-- WHAT THIS DOES NOT DO
--   - No row is rewritten. `notes` keeps its job as the deal's append-only
--     audit trail (the reason is ALSO written there, in the dated line, so
--     the history says what was said at the time).
--   - Nothing is backfilled: every existing cancelled deal simply has no
--     reason, which is the truth.
--   - `status_note` holds the answer that is CURRENT: the application clears
--     it when either side answers differently (confirm, complete, reopen), so
--     a stale "already booked that day" can never sit next to a confirmed
--     booking.
--   - Nothing is attributed retroactively: pre-v3.3 reasons were all written
--     by vendors, which is what a NULL `status_note_by` reads as.
--
-- Verified against code after migration:
--   SHOW COLUMNS FROM tblvendor_bookings LIKE 'status_note%';
--   SELECT id, status, status_note, status_note_by FROM tblvendor_bookings WHERE status = 'cancelled' LIMIT 5;
--   vendor/bin/phpunit tests/VendorSelfServiceTest.php tests/VendorEnquiriesLedgerTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. tblvendor_bookings(status_note) — why the vendor said no.
-- ----------------------------------------------------------------------
SET @wpms_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tblvendor_bookings'
     AND COLUMN_NAME  = 'status_note'
);
SET @wpms_sql := IF(@wpms_col = 0,
  'ALTER TABLE `tblvendor_bookings` ADD COLUMN `status_note` varchar(255) DEFAULT NULL COMMENT ''v3.3 the decline/cancel reason a vendor gives; shown to the couple and their planner; cleared on any other answer'' AFTER `status`',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;

-- ----------------------------------------------------------------------
-- 2. tblvendor_bookings(status_note_by) — who gave the reason above.
-- ----------------------------------------------------------------------
SET @wpms_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tblvendor_bookings'
     AND COLUMN_NAME  = 'status_note_by'
);
SET @wpms_sql := IF(@wpms_col = 0,
  'ALTER TABLE `tblvendor_bookings` ADD COLUMN `status_note_by` varchar(12) DEFAULT NULL COMMENT ''v3.3b who gave status_note: vendor or planner; NULL when no reason was given'' AFTER `status_note`',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;
