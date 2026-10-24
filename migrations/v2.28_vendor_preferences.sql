-- ======================================================================
-- WPMS Kenya v2.28 — What THIS couple wants, and what they can spend
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.28_vendor_preferences.sql
--
-- Why this exists:
--   v2.27 gave a vendor's services a unit, a price and taste tags, so two
--   caterers can be compared with each other. It said nothing about what the
--   COUPLE wants: a planner scrolling 39 line items still had to hold the
--   couple's number and their taste in their head. "Fits KSh 250,000, halal,
--   rustic, 180 guests" was a conversation, not a filter.
--
-- What it adds:
--   `tblvendor_preferences`: one row per (wedding, vendor category) — the
--   couple's taste tags and the budget they have in mind for that category,
--   plus a short note. A wedding with no row for a category has stated no
--   preference there, which is deliberately different from a row with nothing
--   filled in (that one is cleared on save, so "no preference" stays one
--   state, not two).
--
-- WHY PER CATEGORY
--   A couple does not have "a budget" in the abstract: they have KSh 250,000
--   for catering, KSh 60,000 for flowers and whatever the venue costs. One
--   number per wedding could not tell a planner which line to trade down.
--   Taste is per category for the same reason — "rustic" is a decor note, not
--   a photography note.
--
-- WHAT IT DOES NOT DO
--   - It does not write `tbl_liquidation` or the package price. That table is
--     what has actually been PAID, and the package is what the planner sells;
--     this is the couple's INTENT for the vendor market. Mixing the two would
--     turn a wish into a receipt.
--   - It stores no matched vendors. Fit is computed on read (VendorFit), from
--     the service's unit price and the couple's tags, so a changed budget or a
--     re-priced service can never leave a stale "fits" badge behind.
--   - It does not invent a total for a service whose quantity is unknowable
--     (per hour, per km, per item without a minimum): the fit says "quote
--     needed" rather than a number nobody quoted.
--
-- Verified against code after migration:
--   SHOW TABLES LIKE 'tblvendor_preferences';
--   vendor/bin/phpunit tests/VendorFitTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblvendor_preferences` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `booking_id`       int(11)       NOT NULL,
  `service_category` enum('catering','decor','photography','music','transport','mc','venue','cakes','flowers','other') NOT NULL COMMENT 'named service_category: two tables already carry a category-like column and sweep E only attributes a PHP property when the name is unique',
  `taste_tags`       varchar(255)  NOT NULL DEFAULT '' COMMENT 'comma-separated, same vocabulary the vendor tags services with (rustic, halal, outdoor)',
  `budget_min`       decimal(12,2) DEFAULT NULL COMMENT 'optional floor — a couple who does not want the cheapest option',
  `budget_max`       decimal(12,2) DEFAULT NULL COMMENT 'the cap this category must stay under',
  `notes`            varchar(500)  NOT NULL DEFAULT '' COMMENT 'the couple''s own words, shown to their planner',
  `updated_by`       int(11)       DEFAULT NULL COMMENT 'tblusers.id (planner) or tblaccounts.user_id (couple) that last saved',
  `created_at`       timestamp     NOT NULL DEFAULT current_timestamp(),
  `updated_at`       timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_pref` (`booking_id`, `service_category`),
  KEY `idx_vendor_pref_category` (`service_category`),
  CONSTRAINT `tblvendor_preferences_ibfk_1` FOREIGN KEY (`booking_id`)
    REFERENCES `tblweddingbook` (`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.28 what one couple wants per vendor category: taste tags, budget window, a note';
