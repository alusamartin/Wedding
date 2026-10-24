-- ======================================================================
-- WPMS Kenya v2.7 — Six-month free trial (was 14 days)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.7_extend_trial_window.sql
--
-- Why this exists:
--   The trial was 14 days (Subscription::trial_days(), env WPMS_TRIAL_DAYS).
--   A Kenyan planner cannot judge this back office in a fortnight — they need
--   a real wedding run through it: enquiries, RSVPs, deposits, an M-Pesa
--   payment, the printable sheets. The shipped default is now 180 days (six
--   months), which is one full planning season.
--
--   Changing the default only affects planners who sign up FROM NOW ON, so
--   this migration extends the trials already in flight: every live trial row
--   is re-pointed at start_date + 180 days.
--
-- Self-limiting by construction: the expiry is recomputed from the row's OWN
-- start_date, so a trial that began more than 180 days ago is STILL expired
-- afterwards. It revives planners who are mid-evaluation; it never resurrects
-- a long-dead account.
--
-- What it will not touch:
--   * paid rows (status 'active') — only status = 'trial'
--   * any row whose expiry is already further out, so re-running is a no-op
--     and a manual extension is never shortened
--
-- NOTE: 180 is the shipped default. If an operator has overridden
-- WPMS_TRIAL_DAYS, adjust the INTERVAL below to match before running.
-- ======================================================================

-- Ensure target DB exists (same convention as the earlier migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

UPDATE `tblsubscriptions`
   SET `expiry_date` = DATE_ADD(`start_date`, INTERVAL 180 DAY)
 WHERE `status`      = 'trial'
   AND `start_date` IS NOT NULL
   AND `expiry_date` < DATE_ADD(`start_date`, INTERVAL 180 DAY);

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   SELECT status, COUNT(*) FROM tblsubscriptions GROUP BY status;
--   SELECT user_id, start_date, expiry_date,
--          DATEDIFF(expiry_date, CURDATE()) AS days_left
--     FROM tblsubscriptions WHERE status = 'trial';
-- A live trial should now show ~180 days_left; a paid plan is unchanged.
-- ------------------------------------------------------------------
