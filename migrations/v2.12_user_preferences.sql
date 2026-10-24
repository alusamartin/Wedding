-- ======================================================================
-- WPMS Kenya v2.12 — Per-account preferences (the headroom view's width)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.12_user_preferences.sql
--
-- Why this exists:
--   The capacity headroom page (admin/headroom.php, v2.12) lists the weddings
--   running close to the guest capacity of a package a vendor is confirmed on,
--   and a planner chooses how wide to look — 5%, 10% or 20% of a package's own
--   capacity. The width chosen on Monday is the one wanted on Friday: re-picking
--   it on every visit is exactly the small friction that makes a page stop being
--   opened. Nothing in the application stored a preference per ACCOUNT: the
--   session holds choices that die with the session (Helper's vendor-listing
--   switcher is the only precedent, and it says so in as many words), and
--   `tblusers` is the auth table — credentials and identity, read on every login.
--
-- What it changes:
--   1. Adds `tbluser_prefs`: one row per (account, key). A table rather than a
--      column on `tblusers`, because the next preference must not be another
--      ALTER on the table every login reads, and because a preference is data
--      about a person rather than part of who they are.
--   2. No backfill: an absent row IS the default (the page falls back to its own
--      ten per cent), so nothing changes for any existing account.
--   3. No foreign key on `user_id`. `tblusers` rows are deleted by the operator
--      tooling, and a preference must never be able to block that deletion; the
--      reader is scoped to the signed-in id, so an orphan row is unreachable
--      rather than dangerous.
--   4. The value is stored as text and VALIDATED BY THE READER (include/
--      UserPrefs.php → PackageCapacity::sanitise_share()): an account whose
--      stored width is not one this feature offers — edited by hand, or left
--      behind by an older build — reads as the default instead of widening a
--      page into a query a bigger window would ask the database for. A
--      preference is a hint, never a policy.
--
-- Verified against code after migration:
--   php -l admin/include/UserPrefs.php
--   SHOW TABLES LIKE 'tbluser_prefs';
--   SELECT COUNT(*) FROM tbluser_prefs;   -- 0 on a fresh build and after a run
--   vendor/bin/phpunit tests/VenueHeadcountPlanTest.php
--   php scripts/simulate_roles.php --only=8,9
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. The preference table. MariaDB syntax (project convention): on stock
--    MySQL run this CREATE once by hand — re-running it is what IF NOT
--    EXISTS guards.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbluser_prefs` (
  `user_id` int(11) NOT NULL COMMENT 'tblusers.id — staff and vendor accounts; couples have no rows here',
  `pref_key` varchar(40) NOT NULL COMMENT 'the preference name, e.g. headroom_width',
  `pref_value` varchar(190) NOT NULL COMMENT 'the stored value; the READER validates it (UserPrefs)',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`, `pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Per-account UI preferences (the headroom view remembers its width here)';
