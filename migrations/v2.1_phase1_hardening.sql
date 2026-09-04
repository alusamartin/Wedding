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
-- 4. Guest relationship vocabulary (Stage 2 audit)
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
