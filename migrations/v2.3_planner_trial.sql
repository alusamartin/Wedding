-- ======================================================================
-- WPMS Kenya v2.3 — Self-serve planner registration + 14-day trial
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.3_planner_trial.sql
--
-- What this enables:
--   * A planner signs up on the public site (planner_register.php), gets a
--     full-featured 'trial' plan for WPMS_TRIAL_DAYS days (default 14) and
--     lands on subscription.php to activate a paid plan before the trial
--     ends. No admin seeds the account.
--
-- Columns / schema:
--   tblsubscriptions.plan gains the 'trial' ENUM member (the label the
--     zero-amount entitlement row carries) and status gains 'trial' so the
--     row is recognisable before/after expiry without extra columns.
--   tblusers.password is widened VARCHAR(50) -> VARCHAR(255) so bcrypt
--     hashes ($2y$10$... = 60 chars) fit: self-serve accounts are created
--     by register_planner() with password_hash(), not by the legacy seed.
-- ======================================================================

-- Ensure target DB exists (same convention as the v2.1/v2.2 migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- MariaDB syntax (project convention); on stock MySQL run each statement
-- once manually — they fail harmlessly when already applied.
ALTER TABLE `tblsubscriptions`
  MODIFY `plan` ENUM('starter','professional','agency','trial') NOT NULL DEFAULT 'starter';
ALTER TABLE `tblsubscriptions`
  MODIFY `status` ENUM('active','expired','cancelled','pending_renewal','trial') NOT NULL DEFAULT 'active';
ALTER TABLE `tblusers`
  MODIFY `password` VARCHAR(255) NOT NULL;

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/Monetization.php
--   vendor/bin/phpunit tests/PlannerTrialSignupTest.php
-- ------------------------------------------------------------------
