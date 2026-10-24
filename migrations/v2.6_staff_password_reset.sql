-- ======================================================================
-- WPMS Kenya v2.6 — Password reset for staff, not just couples
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.6_staff_password_reset.sql
--
-- Why this exists:
--   login.php is ONE sign-in for everyone: it tries tblaccounts (couples)
--   first, then tblusers (planners / admins / vendors). PasswordReset only
--   ever looked in tblaccounts, so a planner who forgot their password got
--   the same neutral "check your inbox" message and no email, forever — a
--   locked-out operator had no self-serve way back in.
--
-- Columns / schema:
--   tbl_password_resets.user_table — which table the token's row lives in
--     ('tblaccounts' or 'tblusers'). It is required, not cosmetic: the two
--     tables have INDEPENDENT id sequences, so a couple with user_id 200 and
--     a planner with id 200 both exist. Without this column a token would
--     resolve to whichever row the join happened to hit, and a reset could
--     change the wrong person's password.
--
-- Existing rows are backfilled to 'tblaccounts' — every token issued before
-- this migration came from the couple-only flow.
-- ======================================================================

-- Ensure target DB exists (same convention as the earlier migrations)
CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

/*
 * Portable idempotency: MySQL 8 has no ADD COLUMN IF NOT EXISTS (MariaDB
 * does), so check information_schema through DATABASE() — which also keeps
 * this file correct when demo_reset.php retargets the database.
 */
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tbl_password_resets'
      AND COLUMN_NAME  = 'user_table'
);

SET @ddl := IF(@has_col = 0,
    'ALTER TABLE `tbl_password_resets`
        ADD COLUMN `user_table` VARCHAR(16) NOT NULL DEFAULT ''tblaccounts'' AFTER `user_id`',
    'DO 0');

PREPARE wpms_stmt FROM @ddl;
EXECUTE wpms_stmt;
DEALLOCATE PREPARE wpms_stmt;

-- Tokens minted before this migration were couple-only; make that explicit.
UPDATE `tbl_password_resets`
   SET `user_table` = 'tblaccounts'
 WHERE `user_table` IS NULL OR `user_table` = '';

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/PasswordReset.php
--   vendor/bin/phpunit tests/PasswordResetFlowTest.php
-- ------------------------------------------------------------------
