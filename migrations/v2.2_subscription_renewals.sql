-- ======================================================================
-- WPMS Kenya v2.2 — Subscription auto-renewal, 7-day grace, SMS dunning
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.2_subscription_renewals.sql
--
-- What this enables (all guarded by env switches in code):
--   * WPMS_SUB_AUTO_RENEW  — cron (scripts/subscription_renewals.php) STK-pushes
--                            the renewal amount before expiry; a success
--                            callback extends the same subscription row.
--   * WPMS_SUB_GRACE       — a lapsed staff member still sees READ-ONLY
--                            banners for 7 days (WPMS_SUB_GRACE_DAYS)
--                            before the hard dashboard lockout returns.
--   * SMS dunning          — courtesy texts at 7/3/0 days before expiry and
--                            day 3 of grace, charged to the staff member.
--
-- Columns:
--   tblsubscriptions.auto_renew     — per-staff opt-out (on by default).
--   tblsubscriptions.renewal_sms_band — smallest dunning band already
--                                       texted (mirrors the proven
--                                       tblweddingbook.pay_band_sms_sent
--                                       marker semantics: a band fires only
--                                       when the current one is smaller).
--   tblusers.phone                  — where renewal prompts + texts go for
--                                       staff (couples keep their own path).
-- ======================================================================

-- Ensure target DB exists (same convention as v2.1_phase1_hardening.sql)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- MariaDB supports ADD COLUMN IF NOT EXISTS (project convention: the seed
-- files already use it). On stock MySQL, run each ALTER once manually —
-- the statements below fail harmlessly if the column already exists.
ALTER TABLE `tblsubscriptions`
  ADD COLUMN IF NOT EXISTS `auto_renew` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`;
ALTER TABLE `tblsubscriptions`
  ADD COLUMN IF NOT EXISTS `renewal_sms_band` INT UNSIGNED DEFAULT NULL AFTER `auto_renew`;
ALTER TABLE `tblusers`
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL AFTER `email`;

-- Extend the status ENUM with the renewal "promise" state (pending_renewal).
-- MODIFY is safe to re-run: the target definition is idempotent.
ALTER TABLE `tblsubscriptions`
  MODIFY `status` ENUM('active','expired','cancelled','pending_renewal') NOT NULL DEFAULT 'active';

-- Uniqueness guard for the extend-in-place path: one live extension per
-- (user, reference). The mpesa_code uniqueness is NOT global (upgrades
-- legitimately create rows beside renewals with the same receipt string is
-- still prevented; different receipts coexist).
-- (No index strictly required — renewal lookup keys on user_id + expiry,
-- which the existing idx on user_id serves.)

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/Monetization.php
--   php scripts/subscription_renewals.php --dry-run --limit=5
-- ------------------------------------------------------------------
