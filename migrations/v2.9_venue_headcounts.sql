-- ======================================================================
-- WPMS Kenya v2.9 — Venue headcount plan: one row per function
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.9_venue_headcounts.sql
--
-- Why this exists:
--   tbleventbooking holds the planned headcount (family / individual) per
--   function, which the venue sheet prints as a brief for the venue and the
--   caterers. Nothing in the application could write it: the class describing
--   the table (admin/include/BookingEvent.php) did not extend DB_Object and had
--   no create()/update() at all, and no screen offered the fields. The table is
--   a real write path now — admin/event_booking_save.php, called from
--   sheet_venue.php — and this migration makes the rule that writer relies on
--   (one plan per booking+function) a database rule.
--
--   A plan row is keyed by (booking_id, features_id), where features_id is an
--   `events` id of the SAME booking. The sheet used to resolve features_id
--   against tblpostwedding — the public "real weddings" blog — so with the
--   shipped rows it printed other couples' blog posts, title and location, as
--   this wedding's functions. The seeded values line up with the booking's own
--   events, including the per-function package in wedding_id (7 = "Church
--   Wedding" for the Church Wedding event, 9 = "Reception Package" for the
--   Reception event), which is what confirms features_id has always been an
--   events id.
--
-- What it changes:
--   1. Deletes plan rows that cannot be resolved to one of their OWN booking's
--      functions. Under the corrected join such a row is displayed nowhere and
--      editable nowhere — dead state of exactly the kind this change removes.
--      In the shipped data this is one row: booking 201 carries a copy of
--      booking 200's Church Wedding counts (features_id 201 belongs to booking
--      200). Those numbers never described that wedding's functions.
--   2. Keeps the oldest row where a (booking, function) has duplicates.
--   3. Adds the unique key that makes "one plan per function" enforceable, so a
--      re-save replaces the plan instead of stacking a second row — which is
--      what BookingEvent::set_counts() writes against (ON DUPLICATE KEY UPDATE).
--
-- Nothing is added to the schema: every column the sheet needs already existed;
-- what was missing was the writer.
-- ======================================================================

-- Ensure target DB exists (same convention as the earlier migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. Drop rows keyed to another wedding's function (or to no function at all).
--    These can never be displayed or corrected, so keeping them would only
--    preserve the defect this migration exists to close.
-- ----------------------------------------------------------------------
DELETE `eb`
  FROM `tbleventbooking` `eb`
 WHERE NOT EXISTS (
       SELECT 1 FROM `events` `e`
        WHERE `e`.`id` = `eb`.`features_id`
          AND `e`.`booking_id` = `eb`.`booking_id`
 );

-- ----------------------------------------------------------------------
-- 2. One row per (booking, function): keep the oldest.
-- ----------------------------------------------------------------------
DELETE `newer`
  FROM `tbleventbooking` `newer`
  JOIN `tbleventbooking` `older`
    ON `older`.`booking_id` = `newer`.`booking_id`
   AND `older`.`features_id` = `newer`.`features_id`
   AND `older`.`id` < `newer`.`id`;

-- ----------------------------------------------------------------------
-- 3. And make it a rule. MariaDB syntax (project convention): on stock MySQL
--    run this ALTER once by hand — re-running it is what IF NOT EXISTS guards.
-- ----------------------------------------------------------------------
ALTER TABLE `tbleventbooking`
  ADD UNIQUE KEY IF NOT EXISTS `booking_function` (`booking_id`, `features_id`);

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/BookingEvent.php
--   SHOW INDEX FROM tbleventbooking;   -- booking_function, Non_unique 0
--   -- no unreadable rows left:
--   SELECT COUNT(*) FROM tbleventbooking eb
--    WHERE NOT EXISTS (SELECT 1 FROM events e
--                       WHERE e.id = eb.features_id AND e.booking_id = eb.booking_id);
--   -- the sheet and the writer agree on one row per function:
--   SELECT booking_id, features_id, COUNT(*) FROM tbleventbooking
--    GROUP BY booking_id, features_id HAVING COUNT(*) > 1;
--   vendor/bin/phpunit tests/VenueHeadcountPlanTest.php
--   php scripts/simulate_roles.php --only=1,8,9
-- ------------------------------------------------------------------
