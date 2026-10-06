-- ======================================================================
-- WPMS Kenya v2.4 — Registration abuse protection (throttle + verification)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.4_signup_abuse_guard.sql
--
-- What this enables:
--   planner_register.php is now gated like the login door:
--   1. tblsignup_throttle — per-IP and per-email rate limiting on signup
--      POSTs (mirrors tbllogin_throttle, but successes COUNT — a flood of
--      cheap free-trial accounts is the attack here, not password guessing).
--   2. Email verification before the trial starts: new self-serve planners
--      are created with tblusers.email_verified = 0 and receive a single-use
--      token (tblplanner_verifications); verify_planner.php exchanges it for
--      the 14-day trial + auto-login. Tokens are stored HASHED (a DB leak
--      must not leak usable links) and expire.
--
--   Backfill: every account that exists before this migration is verified
--   (email_verified = 1). Admin-created staff and vendors keep defaulting
--   to verified — only register_planner() stamps 0.
-- ======================================================================

-- Ensure target DB exists (same convention as earlier migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- MariaDB syntax (project convention); on stock MySQL run each statement
-- once manually — they fail harmlessly when already applied.
ALTER TABLE `tblusers`
  ADD COLUMN IF NOT EXISTS `email_verified` TINYINT(1) NOT NULL DEFAULT 1;

-- Backfill: existing accounts are trusted. (ADD COLUMN IF NOT EXISTS does
-- not backfill on re-run when the column already exists with rows added
-- later; this stays correct because the DEFAULT is 1.)
UPDATE `tblusers` SET `email_verified` = 1 WHERE `email_verified` IS NULL;

CREATE TABLE IF NOT EXISTS `tblplanner_verifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblsignup_throttle` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_created` (`ip`, `created_at`),
  KEY `idx_email_created` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/SignupGuard.php
--   vendor/bin/phpunit tests/PlannerTrialSignupTest.php tests/SignupAbuseGuardTest.php
-- ------------------------------------------------------------------
