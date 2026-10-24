-- ======================================================================
-- WPMS Kenya v2.10 — Vendor deal function scope
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.10_vendor_function_scope.sql
--
-- Why this exists:
--   A couple editing the venue sheet's "Headcount plan per function" table now
--   briefs the planner and the venue contact about just the functions whose
--   numbers moved (v2.9, admin/include/HeadcountBrief.php). The caterer and the
--   decorator work off the same numbers — the sheet even says so ("the numbers
--   the venue and the caterers work from") — and had no way to hear about them.
--
--   "Which functions does this vendor's deal cover?" had no answer anywhere in
--   the database: tblvendor_bookings is a wedding-level row (vendor, amount,
--   commission, status), so the only honest scopes were "the whole wedding" for
--   everyone or a rule invented in code from the vendor's CATEGORY — which is
--   guesswork that reads plausible and is wrong for the first caterer who only
--   does the reception. This column is that answer, as data the planner sets on
--   the deal itself (admin/vendor_enquiries.php) and the vendor can see on their
--   own ledger (admin/vendor_home.php).
--
-- What it changes:
--   1. Adds `features_id` to tblvendor_bookings: the function (an `events` id of
--      the SAME booking) the deal covers. NULL is not "unset" — it is "booked
--      for the whole wedding", which is a real and common scope, and is why
--      there is NO backfill: every existing deal already means the whole
--      wedding, and the brief reads it that way (that vendor hears about every
--      moved function, exactly like the venue).
--   2. No foreign key. `events` rows are the couple's own calendar, and they
--      delete and re-add functions; a deleted function would leave the scope
--      pointing at nothing. The reader (HeadcountBrief::booked_deals) joins
--      `events` scoped to the same booking and reports such a deal as pinned to
--      "a function no longer on the calendar" — visible to the planner instead
--      of silently becoming a whole-wedding vendor, or silently unreachable.
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. The scope column. MariaDB syntax (project convention): on stock MySQL
--    run this ALTER once by hand — re-running it is what IF NOT EXISTS guards.
-- ----------------------------------------------------------------------
ALTER TABLE `tblvendor_bookings`
  ADD COLUMN IF NOT EXISTS `features_id` INT DEFAULT NULL
    COMMENT 'the function (events.id) this deal covers; NULL = the whole wedding'
  AFTER `package_id`;

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/HeadcountBrief.php
--   SHOW COLUMNS FROM tblvendor_bookings LIKE 'features_id';   -- NULL default
--   -- every deal starts as "the whole wedding" (no scope set):
--   SELECT COUNT(*) FROM tblvendor_bookings WHERE features_id IS NULL;
--   -- and no deal is pinned to another wedding's function:
--   SELECT COUNT(*) FROM tblvendor_bookings vb
--    WHERE vb.features_id IS NOT NULL
--      AND NOT EXISTS (SELECT 1 FROM events e
--                       WHERE e.id = vb.features_id AND e.booking_id = vb.booking_id);
--   vendor/bin/phpunit tests/VenueHeadcountPlanTest.php
--   php scripts/simulate_roles.php --only=1,8,9
-- ------------------------------------------------------------------
