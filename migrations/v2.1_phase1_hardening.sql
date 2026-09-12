-- ======================================================================
-- WPMS Kenya v2.1 — Phase 1: Critical Security & Tech Debt
-- Migration: v2.0 → v2.1
-- Tasks 1.1 (PDO) + 1.2 (Session Hardening)
-- ======================================================================
-- NOTE: No breaking schema change is required for PDO / Session itself.
--       This file is idempotent — safe to run via:  mysql -u root dbwedding < migrations/v2.1_phase1_hardening.sql
--       It creates OPTIONAL audit helpers that enhance the hardened session.
-- ======================================================================

-- Ensure target DB exists (no-op if already imported via kenyan_schema.sql)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. Enforce utf8mb4 everywhere (needed for PDO charset + Kenyan names,
--    M-Pesa symbols, emoji in SMS templates)
-- ----------------------------------------------------------------------
ALTER DATABASE `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Convert core tables that store user-generated text (idempotent)
-- Wrapping in procedure to avoid errors if already utf8mb4
DELIMITER //
CREATE PROCEDURE wpms21_ensure_utf8mb4()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN END;
    ALTER TABLE `tblusers`             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblaccounts`          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblaccounts_detail`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblweddingbook`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblguest`             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `events`               CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tbl_liquidation`      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblsubscriptions`     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblsms_logs`          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ALTER TABLE `tblvendors`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
END //
DELIMITER ;
CALL wpms21_ensure_utf8mb4();
DROP PROCEDURE IF EXISTS wpms21_ensure_utf8mb4;

-- ----------------------------------------------------------------------
-- 2. OPTIONAL: session audit table
--    The hardened Session.php (Task 1.2) enforces IP+UA fingerprint
--    and regenerates every 15 min. This table lets admins audit
--    fingerprint mismatches / hijack attempts and inactivity logouts.
--    No code change required — Session.php error_logs by default.
--    If you want DB logging, uncomment the INSERT in Session::forceLogout().
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblsession_audits` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT          NULL COMMENT 'NULL = unauthenticated / attacker',
    `event`        ENUM('login','logout','regenerate','fingerprint_mismatch','timeout','privilege_change') NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL COMMENT 'IPv4 or IPv6',
    `user_agent`   VARCHAR(512) NOT NULL,
    `fingerprint`  CHAR(64)     NOT NULL COMMENT 'HMAC-SHA256 hex',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_event` (`user_id`, `event`),
    INDEX `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- 3. OPTIONAL: enforce strict MySQL sql_mode (recommended for PDO
--    exception mode to surface truncation / invalid dates early)
--    Run as privileged user or add to my.cnf:
--      sql_mode = STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
-- ----------------------------------------------------------------------
-- SET GLOBAL sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ----------------------------------------------------------------------
-- 4a. M-Pesa C2B verification ledger (Daraja callback verification)
--     Every code entered on subscription.php / budget_add.php is checked
--     against transactions Daraja reported via the public callback endpoint
--     (mpesa_c2b.php). NULL verified_at = not yet verified (still usable in
--     dev where WPMS_MPESA_VERIFY=off); 'rejected' rows are stored so an
--     admin can see what failed and why.
--     Idempotent: re-running this file is safe.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblmpesa_callbacks` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `trans_type`    ENUM('Pay Bill','Buy Goods') NOT NULL DEFAULT 'Pay Bill',
    `trans_id`      VARCHAR(20)  NOT NULL COMMENT 'Daraja TransID == the 10-char code users type',
    `trans_time`    VARCHAR(20)  NOT NULL COMMENT 'Daraja YYYYMMDDHHmmss (EAT)',
    `amount`        DECIMAL(12,2) NOT NULL,
    `business_short_code` VARCHAR(12) NOT NULL,
    `bill_ref_number` VARCHAR(100) DEFAULT NULL COMMENT 'Account number the payer typed',
    `msisdn`        VARCHAR(15)  NOT NULL COMMENT 'Payer phone, 2547.../2541...',
    `first_name`    VARCHAR(100) DEFAULT NULL,
    `middle_name`   VARCHAR(100) DEFAULT NULL,
    `last_name`     VARCHAR(100) DEFAULT NULL,
    `org_account_balance` VARCHAR(20) DEFAULT NULL,
    `invoice_number` VARCHAR(20) DEFAULT NULL,
    `verified_at`   DATETIME     DEFAULT NULL COMMENT 'NULL = pending review',
    `ref_table`     VARCHAR(40)  DEFAULT NULL COMMENT 'tblsubscriptions | tbl_liquidation',
    `ref_id`        INT UNSIGNED DEFAULT NULL,
    `reject_reason` VARCHAR(255) DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_trans` (`trans_type`, `trans_id`),
    KEY `idx_verified` (`verified_at`),
    KEY `idx_ref` (`ref_table`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblmpesa_callback_log` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `direction`  VARCHAR(10)  NOT NULL DEFAULT 'in' COMMENT 'in = from Safaricom',
    `payload`    TEXT         NOT NULL COMMENT 'raw JSON body for forensics',
    `note`       VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- 4a-bis. STK push (initiated-from-app payment) ledger
--     The app asks Daraja to prompt the customer's phone (STK push); Daraja
--     answers asynchronously on the callback URL with the result. Push rows
--     are the promise, callback rows the verdict; only success+unconsumed
--     fulfils the linked subscription or booking payment — exactly once.
--     Idempotent: re-running this file is safe.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblmpesa_stk_pushes` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `checkout_request_id` VARCHAR(50)  NOT NULL COMMENT 'Daraja CheckoutRequestID — the handle for the async result',
    `merchant_request_id` VARCHAR(50)  DEFAULT NULL,
    `purpose`        ENUM('subscription','deposit') NOT NULL,
    `plan`           VARCHAR(30)  DEFAULT NULL COMMENT 'subscription purpose: plan slug',
    `booking_id`     INT UNSIGNED DEFAULT NULL COMMENT 'deposit purpose: booking number',
    `event_id`       INT UNSIGNED DEFAULT NULL COMMENT 'deposit purpose: ceremony the payment settles against',
    `user_id`        INT UNSIGNED NOT NULL COMMENT 'who the push was raised for (staff or couple)',
    `phone`          VARCHAR(15)  NOT NULL COMMENT 'E.164 without +, 2547.../2541... — the phone prompted',
    `amount`         DECIMAL(12,2) NOT NULL,
    `status`         ENUM('pending','success','failed','cancelled','timeout') NOT NULL DEFAULT 'pending',
    `result_code`    INT          DEFAULT NULL COMMENT 'Daraja ResultCode: 0 = success',
    `result_desc`    VARCHAR(255) DEFAULT NULL,
    `mpesa_receipt`  VARCHAR(20)  DEFAULT NULL COMMENT 'MpesaReceiptNumber from a successful callback',
    `ref_table`      VARCHAR(40)  DEFAULT NULL COMMENT 'fulfilment target: tblsubscriptions | tbl_liquidation',
    `ref_id`         INT UNSIGNED DEFAULT NULL COMMENT 'fulfilled row id (NULL until success)',
    `requested_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `answered_at`    DATETIME     DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_checkout` (`checkout_request_id`),
    KEY `idx_stk_user` (`user_id`),
    KEY `idx_stk_status` (`status`, `requested_at`),
    KEY `idx_stk_ref` (`ref_table`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- 4b. Optional automatic verification gate (off by default)
--     Set WPMS_MPESA_VERIFY=1 in the environment (Apache SetEnv, CLI export,
--     Docker environment:) to make unverified codes rejected at entry.
-- ----------------------------------------------------------------------
-- (environment-only switch — documented here, nothing to execute)

-- ----------------------------------------------------------------------
-- 4c. Login rate limiting (built-in brute-force throttle for login.php)
--     One row per (email, IP) pair: consecutive failed attempts, refreshed
--     on every failure. >= MAX_FAILURES within the lockout window blocks
--     further attempts for that pair; a per-IP total catches distributed
--     guessing across many accounts. Success clears the pair; prune()
--     removes aged rows. Idempotent: re-running this file is safe.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbllogin_throttle` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `throttle_email` VARCHAR(191) NOT NULL COMMENT 'the email ATTEMPTED (as typed, lowercased) — regardless of whether the account exists',
    `throttle_ip`   VARCHAR(45)  NOT NULL COMMENT 'client IP (IPv6-capable)',
    `fail_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`    DATETIME     NOT NULL COMMENT 'last failed attempt — the lockout clock resets with it',
    UNIQUE KEY `uniq_throttle_pair` (`throttle_email`, `throttle_ip`),
    KEY `idx_throttle_ip` (`throttle_ip`),
    KEY `idx_throttle_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- 5. Guest relationship vocabulary (Stage 2 audit)
--    The guest forms store code keys (family_elder, vc_member, ...) but the
--    seed inserts human labels ('Family Elder (Mzee)', 'Officiant', ...),
--    so seeded guests rendered the 'Guest' fallback in the list. Normalise
--    labels to the code vocabulary (runs after the Kenya seed — idempotent).
-- ----------------------------------------------------------------------
UPDATE `tblguest` SET `relationship` = 'family_elder'     WHERE `relationship` IN ('Family Elder (Mzee)', 'Family Elder');
UPDATE `tblguest` SET `relationship` = 'bridal_train'     WHERE `relationship` = 'Bridal Train';
UPDATE `tblguest` SET `relationship` = 'upcountry_guest'  WHERE `relationship` = 'Upcountry Guest';
UPDATE `tblguest` SET `relationship` = 'family_friend'    WHERE `relationship` IN ('Family Friend', 'Friend');
UPDATE `tblguest` SET `relationship` = 'colleague'        WHERE `relationship` IN ('Colleague', 'Colleague / Workmate');
UPDATE `tblguest` SET `relationship` = 'pastor_clergy'    WHERE `relationship` IN ('Pastor / Clergy', 'Officiant');
UPDATE `tblguest` SET `relationship` = 'vc_member'        WHERE `relationship` IN ('VC / Committee Member', 'VC Member');
UPDATE `tblguest` SET `relationship` = 'harambee_sponsor' WHERE `relationship` = 'Harambee Sponsor';
UPDATE `tblguest` SET `relationship` = 'close_family'     WHERE `relationship` = 'Close Family';
UPDATE `tblguest` SET `relationship` = 'regular'          WHERE `relationship` = 'Regular';

-- ----------------------------------------------------------------------
-- 5. Verify migration
-- ----------------------------------------------------------------------
-- SELECT 'v2.1_phase1_hardening applied' AS status, NOW() AS at;
-- SHOW CREATE TABLE tblsession_audits;
