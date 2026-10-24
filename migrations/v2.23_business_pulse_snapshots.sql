-- ======================================================================
-- WPMS Kenya v2.23 — Weekly business-pulse snapshots (week-over-week)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.23_business_pulse_snapshots.sql
--
-- Why this exists:
--   The Monday business pulse (admin/include/BusinessPulse.php,
--   scripts/business_pulse_email.php) quoted this week's totals and nothing
--   else, so an owner could not tell KSh 1.24M collected from a good week or
--   from three flat ones. The only way to say "collected +KSh 95,000 since last
--   week" is to keep last week's figures: `tblweddingbook` knows today's dates
--   and payments, not what the aggregate said seven days ago. A preference row
--   cannot hold them either — `tbluser_prefs.pref_value` is varchar(190), and a
--   snapshot is a few kilobytes of nested figures.
--
-- What it changes:
--   1. Adds `tblops_snapshots`: one row per (scope, ISO week) holding the
--      OpsPulse::snapshot() payload that week's brief was rendered from. The
--      next brief reads the most recent row STRICTLY BEFORE its own week and
--      prints each figure's signed change.
--   2. `scope_id` is the account whose thresholds shaped that snapshot (the
--      brief's windows are per owner, which changes one of the keys), or 0 for
--      a shared mailbox (WPMS_PULSE_TO) — so the two never compare against each
--      other's numbers.
--   3. No backfill and no dependency: without the table the brief still sends,
--      it just says the comparison is off and prints no change column. Nothing
--      reads this table on a page load except the Business Pulse card's one
--      indexed lookup.
--   4. History is pruned by the writer: BusinessPulse keeps a quarter of weeks
--      (26) per scope, so the table cannot grow without bound.
--
-- What is deliberately NOT stored:
--   The payload is the aggregate snapshot itself — counts, money, response
--   rates and the thresholds used. It contains no customer name, guest, phone
--   number, email or payment reference, because OpsPulse never returns one
--   (tests/OpsPulseTest.php asserts the same shape for the tools).
--
-- Verified against code after migration:
--   php -l admin/include/BusinessPulse.php
--   SHOW TABLES LIKE 'tblops_snapshots';
--   SELECT COUNT(*) FROM tblops_snapshots;   -- 0 on a fresh build
--   vendor/bin/phpunit tests/BusinessPulseTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. One snapshot per scope per ISO week. The key is (scope, week), so a
--    re-send inside the same week REPLACES that week's row instead of
--    competing with it — the next week still compares against the last
--    completed week, never against a resend of itself.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblops_snapshots` (
  `scope_id` int(11) NOT NULL DEFAULT 0 COMMENT 'tblusers.id whose thresholds shaped the snapshot; 0 = shared/default mailbox',
  `iso_week` varchar(8) NOT NULL COMMENT 'ISO week key, e.g. 2026-W43',
  `payload` mediumtext NOT NULL COMMENT 'OpsPulse::snapshot() as rendered in that week''s brief (aggregates only)',
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scope_id`, `iso_week`),
  KEY `iso_week` (`iso_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Weekly OpsPulse snapshots, so the Monday brief can show week-over-week change';
