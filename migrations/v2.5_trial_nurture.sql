-- ======================================================================
-- WPMS Kenya v2.5 — Trial nurture emails (day-7 check-in, day-13 last call)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.5_trial_nurture.sql
--
-- What this enables:
--   The subscription_renewals cron gains LEG 3: planners on a live trial get
--   a check-in email at the halfway point of their trial and a last-call
--   email the day before it expires (bands 7 and 1 for the default 14-day
--   trial — computed relative to WPMS_TRIAL_DAYS). Emails ride the shared
--   tbl_mail_logs outbox like the welcome/verification emails.
--
-- Columns / schema:
--   tblsubscriptions.trial_nurture_band — marker-first semantics, identical
--     to renewal_sms_band: NULL = never emailed; the smallest band emailed
--     is stored, and a band fires only when the current one is smaller, so
--     a crash between stamp and send can never double-email.
-- ======================================================================

-- Ensure target DB exists (same convention as earlier migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- MariaDB syntax (project convention); on stock MySQL run each statement
-- once manually — they fail harmlessly when already applied.
ALTER TABLE `tblsubscriptions`
  ADD COLUMN IF NOT EXISTS `trial_nurture_band` INT UNSIGNED DEFAULT NULL;

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/SignupGuard.php
--   vendor/bin/phpunit tests/TrialNurtureEmailTest.php
-- ------------------------------------------------------------------
