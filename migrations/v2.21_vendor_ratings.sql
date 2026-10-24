-- ======================================================================
-- WPMS Kenya v2.21 — the numbers on a vendor card become real
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.21_vendor_ratings.sql
--
-- Why this exists:
--   tblvendors has carried `rating` and `total_bookings` since the
--   marketplace was built, and three screens show them: the couple's
--   marketplace (admin/client_vendors.php), the staff marketplace
--   (admin/vendors.php) and the admin vendor profile
--   (admin/vendor_profile.php). Nothing ever assigned either one, so every
--   vendor read "0.0 / 0 bookings" no matter how many deals were confirmed
--   (reported by scripts/audit_promises.php as finding O7).
--
--   v2.21 gives both columns a writer:
--     * admin/vendor_enquiries.php recomputes them when a deal changes state
--       (confirm / complete / cancel / reopen), so `total_bookings` is the
--       count of deals that have been confirmed or delivered;
--     * a couple rates a COMPLETED deal — once — through
--       admin/client_vendor_rate.php, and `rating` becomes the mean of those
--       ratings.
--
--   Vendor::recompute_stats() derives both numbers from tblvendor_bookings on
--   every change, so a card can never drift from the rows behind it.
--
-- What it holds:
--   tblvendor_bookings — two columns, nullable until the couple rates:
--     `rating`   tinyint(1)  the couple's 1..5 score for one delivered deal;
--     `rated_at` datetime    when they left it (the once-only guard is
--                            `rating IS NULL` in the UPDATE that races for it).
--   tblvendors.rating / .total_bookings are not altered — only written now.
--
-- Verified after migration:
--   SHOW COLUMNS FROM tblvendor_bookings;
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/VendorStatsTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- One rating per delivered deal. Both columns are NULL until the couple
-- rates, which is what makes "rate this once" a WHERE clause rather than
-- a status anyone could flip back.
-- ----------------------------------------------------------------------
ALTER TABLE `tblvendor_bookings`
  ADD COLUMN IF NOT EXISTS `rating` tinyint(1) DEFAULT NULL
      COMMENT 'the couple''s 1..5 score for a completed deal; NULL until rated';

ALTER TABLE `tblvendor_bookings`
  ADD COLUMN IF NOT EXISTS `rated_at` datetime DEFAULT NULL
      COMMENT 'when the couple left the rating; set once with the rating itself';

-- ----------------------------------------------------------------------
-- Verification — expected: both columns present, `rating` nullable.
-- ----------------------------------------------------------------------
SELECT 'rating columns' AS check_name, COUNT(*) AS n
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblvendor_bookings'
   AND COLUMN_NAME IN ('rating', 'rated_at');

SELECT 'couples can rate' AS check_name, COUNT(*) AS n
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblvendor_bookings'
   AND COLUMN_NAME = 'rating' AND IS_NULLABLE = 'YES';
