-- ======================================================================
-- WPMS Kenya v2.8 — Plan pricing: buyable keys + who changed what, when
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.8_plan_pricing_audit.sql
--
-- Why this exists (two halves of the same defect):
--
--   1. tblsubscriptions.plan was ENUM('starter','professional','agency','trial')
--      while tblsubscription_plans.plan_slug is VARCHAR(20) and the operator's
--      plan editor accepts ANY 2-20 char key. So a plan the operator defined
--      (v2.1's F9 fix — "Plans will appear here once the team defines them" now
--      has an actor) could be listed for sale but never actually SOLD: the
--      purchase INSERT was coerced. Measured on a live row before this
--      migration: buying plan 'premium' stored plan = '' with no error at all
--      (sql_mode has no STRICT_TRANS_TABLES), so the planner paid and held a
--      subscription attached to no plan. Widening the column to VARCHAR(20)
--      matches plan_slug exactly and makes the key store what the editor chose.
--
--   2. A plan's price is the platform's revenue, and until now nobody could
--      say who last moved it or when: tblsubscription_plans held only the
--      CURRENT price, so every past price was overwritten without a trace.
--      tblsubscription_plan_changes is the ledger — one row per changed field,
--      with the actor and the timestamp.
--
--   HISTORICAL ROWS: a subscription whose plan was coerced to '' before this
--   migration cannot be repaired — the slug was never stored anywhere, so
--   there is nothing to recover. Those rows are NOT touched; the revenue
--   dashboard lists such keys as "(no plan recorded)" rather than hiding the
--   money they represent.
--
--   NO FOREIGN KEY on plan_id, deliberately: an audit trail must not be
--   erasable by deleting its subject. The application never deletes a plan
--   (it retires them — subscription_plans.php), so this only ever affects
--   scratch/test clean-up, which deletes these rows explicitly. plan_slug is
--   stored alongside the id so a history stays readable either way.
-- ======================================================================

-- Ensure target DB exists (same convention as earlier migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. A plan key must store exactly what the plan editor wrote.
--    MODIFY is safe to re-run (same target definition); existing values are
--    preserved because every seeded key already fits in VARCHAR(20).
-- ----------------------------------------------------------------------
ALTER TABLE `tblsubscriptions`
  MODIFY `plan` VARCHAR(20) NOT NULL DEFAULT 'starter';

-- ----------------------------------------------------------------------
-- 2. The plan change ledger.
--    field_name is one of: name | price | wedding_limit | features | is_active
--    old_value is NULL for a definition (the plan did not exist before).
--    user_id is NULL when the change came from a CLI/cron context.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblsubscription_plan_changes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `plan_slug` varchar(20) NOT NULL,
  `field_name` varchar(30) NOT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `plan_id` (`plan_id`),
  KEY `field_name` (`field_name`),
  KEY `changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/Monetization.php
--   SHOW COLUMNS FROM tblsubscriptions LIKE 'plan';          -- varchar(20)
--   SELECT plan_slug, field_name, old_value, new_value, user_id, changed_at
--     FROM tblsubscription_plan_changes ORDER BY id DESC LIMIT 10;
--   vendor/bin/phpunit tests/SubscriptionPlanAdminTest.php tests/RevenueDashboardTest.php
--   php scripts/simulate_roles.php --only=P7
-- A price edit in /admin/subscription_plans.php must leave exactly one
-- field_name = 'price' row here, carrying the operator's user_id.
-- ------------------------------------------------------------------
