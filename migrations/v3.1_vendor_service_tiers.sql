-- ======================================================================
-- WPMS Kenya v3.1 — tiered pricing: Silver / Gold / Platinum.
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v3.1_vendor_service_tiers.sql
--
-- Why this exists
--   A Kenyan vendor does not sell one buffet: they sell a buffet at three
--   levels — the working menu, the one with a live grill, and the one with
--   imported crockery and two chefs. Until now `tblvendor_services.price` and
--   `tblvendor_packages.price` were a single number, so a planner comparing
--   caterers compared the cheapest thing against the best thing and called the
--   difference a bargain. Three tiers with their own inclusions and exclusions
--   put the comparison back on the same level.
--
-- THE RULE THAT KEEPS EVERYTHING ELSE WORKING
--   `price` on the service/package row STAYS the Silver price and is kept in
--   sync whenever tiers are saved (VendorTier::save_*). Everything that
--   already reads a price — the marketplace cards, VendorFit's arithmetic,
--   the coverage board, the vendor plan, the proposal builder — keeps working
--   unchanged and now means "the entry level". The tier tables carry the full
--   record, including Silver's own inclusions/exclusions.
--
-- WHAT THIS DOES NOT DO
--   - No existing row is rewritten: a service that never had tiers simply has
--     no tier rows, and every surface falls back to its single price.
--   - No price is copied INTO a deal. A proposal line stores the tier it was
--     priced at and the amount that produced, exactly like v2.30.
--
-- Verified against code after migration:
--   SHOW TABLES LIKE 'tblvendor%tiers';
--   SHOW COLUMNS FROM tblproposal_items LIKE 'tier';
--   vendor/bin/phpunit tests/VendorTierTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. Service tiers — one row per (service, tier).
--
--    ON DELETE CASCADE: a deleted service cannot leave orphan pricing behind.
--    The UNIQUE key is what makes "save the three tiers" an upsert rather than
--    a delete-and-reinsert that would churn ids the couple's quote references.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblvendor_service_tiers` (
  `id`         int(11)       NOT NULL AUTO_INCREMENT,
  `service_id` int(11)       NOT NULL COMMENT 'the tblvendor_services row this price belongs to',
  `tier`       enum('silver','gold','platinum') NOT NULL,
  `price`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'per the service''s own unit (per guest, per day, flat…)',
  `includes`   varchar(500)  NOT NULL DEFAULT '' COMMENT 'what this tier buys, in the vendor''s words',
  `excludes`   varchar(500)  NOT NULL DEFAULT '' COMMENT 'what it leaves out — the half of the deal couples discover late',
  `created_at` timestamp     NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_tier` (`service_id`,`tier`),
  KEY `idx_service_tier_tier` (`tier`),
  CONSTRAINT `tblvendor_service_tiers_ibfk_service` FOREIGN KEY (`service_id`)
    REFERENCES `tblvendor_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v3.1 silver/gold/platinum price and inclusions for one vendor service';

-- ----------------------------------------------------------------------
-- 2. Package tiers — the same three levels for a bundle.
--
--    Kept separate from the service table on purpose: a package is sold as a
--    whole, so its tiers price a whole wedding, not a per-guest line. A
--    polymorphic (kind, id) table would have looked tidier and lost the
--    foreign keys, which is the one thing this schema relies on to stop
--    orphaned money.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblvendor_package_tiers` (
  `id`         int(11)       NOT NULL AUTO_INCREMENT,
  `package_id` int(11)       NOT NULL COMMENT 'the tblvendor_packages row this price belongs to',
  `tier`       enum('silver','gold','platinum') NOT NULL,
  `price`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'the whole package at this level, in KSh',
  `includes`   varchar(500)  NOT NULL DEFAULT '',
  `excludes`   varchar(500)  NOT NULL DEFAULT '',
  `created_at` timestamp     NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_package_tier` (`package_id`,`tier`),
  KEY `idx_package_tier_tier` (`tier`),
  CONSTRAINT `tblvendor_package_tiers_ibfk_package` FOREIGN KEY (`package_id`)
    REFERENCES `tblvendor_packages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v3.1 silver/gold/platinum price and inclusions for one vendor package';

-- ----------------------------------------------------------------------
-- 3. The tier a proposal line was priced at.
--
--    NULL means "no tier chosen" — a custom arrangement, or a line from before
--    this migration. The AMOUNT on the line is still the truth about money; the
--    tier only says which level of the vendor's offer produced it, so a couple
--    reading their quote can see that they are being offered the Gold buffet
--    and not guess from the figure.
-- ----------------------------------------------------------------------
ALTER TABLE `tblproposal_items`
  ADD COLUMN IF NOT EXISTS `tier` enum('silver','gold','platinum') DEFAULT NULL
    COMMENT 'v3.1 which tier of the offering this line was priced at; NULL for custom lines and pre-v3.1 rows'
  AFTER `kind`;
