-- ======================================================================
-- WPMS Kenya v2.29 — The wedding's vendor plan: which offering a deal is for
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.29_vendor_plan.sql
--
-- Why this exists:
--   A planner's supplier work is not "which vendors exist" (the marketplace),
--   not "what does each function still need" (the coverage board) and not
--   "what has been paid" (the budget's liquidation ledger). It is: WHICH vendor,
--   for WHICH thing they sell, at WHAT price — summed into one number the couple
--   can read. `tblvendor_bookings` already records the vendor, the wedding, the
--   agreed amount and the status, which is what a deal is. What it could not
--   record is the OFFERING: a package could be named (`package_id`), but a
--   vendor's individual service — "Classic buffet, KSh 1,200 per guest" — had
--   nowhere to live on the deal. So a planner writing a plan could only say
--   "Nyama Choma Masters, KSh 216,000", never "…for the 180-guest buffet", and
--   six weeks later nobody could tell what the number had been for.
--
-- What it adds (both nullable, so every existing deal is untouched):
--   service_id   the tblvendor_services row this deal is for. NULL is normal:
--                a package deal names its package instead, a custom arrangement
--                has no catalogue row, and every pre-v2.29 deal has neither.
--   service_qty  the quantity the amount was planned at — guests, days, items,
--                hours. Stored because it is the multiplier behind `amount`:
--                without it the plan cannot say "× 180 guests" or notice that
--                the vendor has since re-priced the service.
--
-- What it deliberately does NOT do:
--   - It does not add a plan table. A second list of chosen vendors would be a
--     second truth next to `tblvendor_bookings`, and the first price change
--     would make the two disagree. The plan is a VIEW of the wedding's deals
--     (model: VendorPlan::build); this migration only lets a deal remember
--     which offering it bought.
--   - It does not change `amount`. The amount stays what the planner entered
--     (an estimate when planning, the agreed figure when confirming) — the plan
--     never recomputes money behind the planner's back. Where a service's
--     current price would produce a different number, VendorPlan reports it as
--     "their price is now X" instead of silently rewriting the deal.
--   - It does not backfill `service_qty` from `amount` ÷ price. That division
--     guesses a quantity for every historical deal, and a guessed multiplier is
--     exactly what the plan is supposed to stop doing.
--
-- Verified against code after migration:
--   SHOW COLUMNS FROM tblvendor_bookings LIKE 'service_id';
--   SHOW COLUMNS FROM tblvendor_bookings LIKE 'service_qty';
--   SELECT COUNT(*) FROM tblvendor_bookings WHERE service_id IS NULL;  -- all of them
--   vendor/bin/phpunit tests/VendorPlanTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. The offering on a deal. MariaDB syntax (project convention): on stock
--    MySQL run this ALTER once by hand — re-running it is what IF NOT EXISTS
--    guards.
-- ----------------------------------------------------------------------
ALTER TABLE `tblvendor_bookings`
  ADD COLUMN IF NOT EXISTS `service_id` INT DEFAULT NULL
    COMMENT 'v2.29 the tblvendor_services row this deal is for; NULL for a package deal, a custom arrangement, or a pre-v2.29 deal'
  AFTER `package_id`,
  ADD COLUMN IF NOT EXISTS `service_qty` INT DEFAULT NULL
    COMMENT 'v2.29 the quantity amount was planned at: guests, days, items, hours — the multiplier behind amount'
  AFTER `service_id`;

-- ----------------------------------------------------------------------
-- 2. Lookup by service (the plan asks "which deals bought this offering?"),
--    and a foreign key that lets a deleted service leave the deal standing
--    with its recorded amount rather than taking money off the ledger.
-- ----------------------------------------------------------------------
SET @wpms_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tblvendor_bookings'
     AND INDEX_NAME   = 'idx_vendor_bookings_service'
);
SET @wpms_sql := IF(@wpms_idx = 0,
  'ALTER TABLE `tblvendor_bookings` ADD KEY `idx_vendor_bookings_service` (`service_id`)',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;

SET @wpms_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME        = 'tblvendor_bookings'
     AND CONSTRAINT_NAME   = 'tblvendor_bookings_ibfk_service'
);
SET @wpms_sql := IF(@wpms_fk = 0,
  'ALTER TABLE `tblvendor_bookings` ADD CONSTRAINT `tblvendor_bookings_ibfk_service` FOREIGN KEY (`service_id`) REFERENCES `tblvendor_services` (`id`) ON DELETE SET NULL',
  'DO 0');
PREPARE wpms_stmt FROM @wpms_sql;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;
