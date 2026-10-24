-- ======================================================================
-- WPMS Kenya v2.24 — Which services each wedding function actually needs
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.24_event_vendor_needs.sql
--
-- Why this exists:
--   The vendor coverage board (admin/vendor_coverage.php) drew every calendar
--   function against all ten vendor categories. That answered "is a caterer
--   booked?" but not "does this function need a caterer?" — a ruracio does not
--   need a church choir and a pre-wedding meeting needs none of the ten, so the
--   board counted holes the planner never had to fill and could not say how
--   many services the wedding actually still needed.
--
--   There was no requirement record to read: `events` carries a title and a
--   time, `tblvendor_bookings` carries what was AGREED, and the package feature
--   tables (tbl_features) describe what a package tier includes, not what one
--   function needs. This migration adds the missing statement of intent.
--
-- What it changes:
--   1. Adds `tblevent_vendor_needs`: one row per (wedding, function), holding
--      the categories the planner marked as required, comma-separated in the
--      canonical order (the same ten values tblvendors.category uses).
--   2. `event_id` 0 means the wedding as a whole — the row the board uses when
--      a wedding has no calendar functions yet.
--   3. A stored empty string means "this function needs no vendor service",
--      which is deliberately different from NO row: no row means the planner
--      has not visited the board yet, and the board starts from all ten so an
--      existing wedding does not lose its whole matrix on upgrade.
--   4. A row is written ONLY by an explicit save (POST + CSRF) of the
--      required-services editor. Viewing never writes.
--
-- What is deliberately NOT stored:
--   No prices, no vendor ids, no statuses — the requirements are intent, and a
--   deal's own row remains the only record of what was sold. Deleting a
--   requirement never touches a booking.
--
-- Verified against code after migration:
--   php -l admin/include/VendorCoverage.php
--   SHOW TABLES LIKE 'tblevent_vendor_needs';
--   SELECT * FROM tblevent_vendor_needs;   -- empty on a fresh build
--   vendor/bin/phpunit tests/VendorCoverageBoardTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. One requirement row per function (0 = the wedding as a whole). The
--    key is (booking_id, event_id), so saving replaces that function's
--    list instead of accumulating a second opinion.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblevent_vendor_needs` (
  `booking_id` int(11) NOT NULL COMMENT 'tblweddingbook.booking_id the function belongs to',
  `event_id` int(11) NOT NULL DEFAULT 0 COMMENT 'events.id; 0 = the wedding as a whole (no calendar functions yet)',
  `categories` varchar(255) NOT NULL DEFAULT '' COMMENT 'Required tblvendors.category values, comma-separated in canonical order; empty = nothing needed',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) NOT NULL DEFAULT 0 COMMENT 'tblusers.id who last saved the list',
  PRIMARY KEY (`booking_id`, `event_id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-function vendor service requirements, so the coverage board counts the services this wedding needs';
