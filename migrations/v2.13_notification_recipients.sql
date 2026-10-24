-- ======================================================================
-- WPMS Kenya v2.13 — Which account a notification is for
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.13_notification_recipients.sql
--
-- Why this exists:
--   `tblnotifications.user_id` and `tbl_mail_logs.user_id` hold an id from
--   ONE of two account tables, and nothing in the row says which:
--
--     tblusers.id          staff — planners, admins and vendor leads
--     tblaccounts.user_id  couples
--
--   Those are two separate auto-increment sequences, so 203 in tblusers and
--   203 in tblaccounts are different people who merely share a number. Every
--   read of these tables was scoped by that bare number (`WHERE user_id = :uid`),
--   so an account in one table whose id collides with an account in the other
--   could read — and mark read — its feed. Both audit passes recorded this as
--   the remaining open finding:
--     docs/COUPLE_PAGE_OWNERSHIP.md §5, docs/VENDOR_PAGE_OWNERSHIP.md §5.
--
-- What it changes:
--   1. Adds `recipient_type` to both tables — the column that says which of the
--      two tables `user_id` indexes:
--        'user'    -> tblusers.id        (staff and vendor leads)
--        'account' -> tblaccounts.user_id (couples)
--        'none'    -> no account behind the row (a bare address: the password
--                     reset and planner-verification emails are written with
--                     user_id = 0 because there is no signed-in account yet)
--      The invariant is total: 'none' means user_id = 0, and user_id = 0 means
--      'none'. Every feed read names the type, so two rows that share a number
--      can no longer answer the same query.
--   2. No default on the column afterwards (`DROP DEFAULT` below). A column
--      with a default is a column somebody can forget; without one, a forgotten
--      INSERT is an error under strict sql_mode and an unreadable row (never a
--      misdelivered one) otherwise. tests/NotificationRecipientScopeTest holds
--      the writers to it statically, which is the enforcement that does not
--      depend on sql_mode.
--   3. Composite indexes that match the new predicate: every feed query is
--      `recipient_type = ? AND user_id = ? [AND is_read = 0]`, and the retry
--      sweeper reads mail rows by status.
--
-- Backfill, in order (the column starts at 'user', so each rule moves the rows
-- it can prove; whatever is left is a row whose recipient is a tblusers id):
--   1. user_id = 0                          -> 'none'
--   2. id in tblaccounts but not tblusers   -> 'account'
--   3. id in tblusers but not tblaccounts   -> 'user'
--   4. id in BOTH (the collision): the booking the row is about decides —
--      the couple who owns it, else the organizer who runs it
--   5. mail rows additionally use to_email: an address that only tblaccounts
--      carries is the couple's, one only tblusers carries is the staff's
-- Residue — a collision row with no usable booking and no usable address —
-- stays 'user' and is counted by the verification query below. The live
-- database this was written against has no colliding id at all (every row
-- resolves by rules 1-3), so this is defence against a database that grows
-- one, which is exactly the case that used to leak.
--
-- Verified against code after migration:
--   SHOW COLUMNS FROM tblnotifications LIKE 'recipient_type';
--   SHOW COLUMNS FROM tbl_mail_logs   LIKE 'recipient_type';
--   SELECT recipient_type, COUNT(*) FROM tblnotifications GROUP BY recipient_type;
--   SELECT recipient_type, COUNT(*) FROM tbl_mail_logs     GROUP BY recipient_type;
--   -- both must be 0: the invariant is total in both directions
--   SELECT COUNT(*) FROM tblnotifications WHERE (user_id = 0) <> (recipient_type = 'none');
--   SELECT COUNT(*) FROM tbl_mail_logs     WHERE (user_id = 0) <> (recipient_type = 'none');
--   -- no row may sit on the column's removed default
--   SELECT COUNT(*) FROM tblnotifications WHERE recipient_type = '';
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/NotificationRecipientScopeTest.php
--   vendor/bin/phpunit tests/StaffNotificationsPageTest.php tests/ClientDepositFlowTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. The column. Guarded on information_schema (MariaDB has
--    ADD COLUMN IF NOT EXISTS; stock MySQL 5.7/8.0 does not, so the
--    portable idiom is the procedure used by v2.1_phase1_hardening.sql).
--    It is added WITH a default of 'user' so the backfill below can move
--    rows into place, and the default is dropped at the end.
-- ----------------------------------------------------------------------
DELIMITER //
DROP PROCEDURE IF EXISTS wpms213_add_recipient_type //
CREATE PROCEDURE wpms213_add_recipient_type()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tblnotifications'
                      AND COLUMN_NAME  = 'recipient_type') THEN
        ALTER TABLE `tblnotifications`
            ADD COLUMN `recipient_type` ENUM('user','account','none') NOT NULL DEFAULT 'user'
                COMMENT '''user'' = tblusers.id (staff/vendor) | ''account'' = tblaccounts.user_id (couple) | ''none'' = no account (user_id 0)'
                AFTER `booking_id`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tbl_mail_logs'
                      AND COLUMN_NAME  = 'recipient_type') THEN
        ALTER TABLE `tbl_mail_logs`
            ADD COLUMN `recipient_type` ENUM('user','account','none') NOT NULL DEFAULT 'user'
                COMMENT '''user'' = tblusers.id (staff/vendor) | ''account'' = tblaccounts.user_id (couple) | ''none'' = no account (user_id 0)'
                AFTER `booking_id`;
    END IF;
END //
DELIMITER ;
CALL wpms213_add_recipient_type();
DROP PROCEDURE IF EXISTS wpms213_add_recipient_type;

-- ----------------------------------------------------------------------
-- 2. Backfill — tblnotifications
-- ----------------------------------------------------------------------
-- 2.1 No account behind the row.
UPDATE `tblnotifications`
   SET `recipient_type` = 'none'
 WHERE `user_id` = 0;

-- 2.2 An id only a couple account holds.
UPDATE `tblnotifications` n
   SET n.`recipient_type` = 'account'
 WHERE n.`user_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = n.`user_id`)
   AND NOT EXISTS (SELECT 1 FROM `tblusers` u WHERE u.`id` = n.`user_id`);

-- 2.3 An id only a staff/vendor account holds (the column default already
--     says 'user'; this states it, so a re-run over an edited database
--     repairs a hand-changed row instead of trusting it).
UPDATE `tblnotifications` n
   SET n.`recipient_type` = 'user'
 WHERE n.`user_id` > 0
   AND EXISTS (SELECT 1 FROM `tblusers` u WHERE u.`id` = n.`user_id`)
   AND NOT EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = n.`user_id`);

-- 2.4 The collision: the row is about a booking, and the booking says which
--     side held that number. Owner = the couple, organizer = the planner.
UPDATE `tblnotifications` n
   SET n.`recipient_type` = 'account'
 WHERE n.`user_id` > 0 AND n.`booking_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a  WHERE a.`user_id` = n.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u     WHERE u.`id` = n.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblweddingbook` b
                WHERE b.`booking_id` = n.`booking_id` AND b.`user_id` = n.`user_id`);

UPDATE `tblnotifications` n
   SET n.`recipient_type` = 'user'
 WHERE n.`user_id` > 0 AND n.`booking_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a  WHERE a.`user_id` = n.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u     WHERE u.`id` = n.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblweddingbook` b
                WHERE b.`booking_id` = n.`booking_id` AND b.`organizer_id` = n.`user_id`);

-- ----------------------------------------------------------------------
-- 3. Backfill — tbl_mail_logs (same rules, decided by the address the
--    message actually went to wherever the id is ambiguous)
-- ----------------------------------------------------------------------
UPDATE `tbl_mail_logs`
   SET `recipient_type` = 'none'
 WHERE `user_id` = 0;

UPDATE `tbl_mail_logs` l
   SET l.`recipient_type` = 'account'
 WHERE l.`user_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = l.`user_id`)
   AND NOT EXISTS (SELECT 1 FROM `tblusers` u WHERE u.`id` = l.`user_id`);

UPDATE `tbl_mail_logs` l
   SET l.`recipient_type` = 'user'
 WHERE l.`user_id` > 0
   AND EXISTS (SELECT 1 FROM `tblusers` u WHERE u.`id` = l.`user_id`)
   AND NOT EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = l.`user_id`);

-- 3.4 The address is the strongest evidence for a collision: it is a value
--     copied from the account row the message was addressed to.
UPDATE `tbl_mail_logs` l
   SET l.`recipient_type` = 'account'
 WHERE l.`user_id` > 0 AND l.`to_email` <> ''
   AND EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u    WHERE u.`id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_email` = l.`to_email`)
   AND NOT EXISTS (SELECT 1 FROM `tblusers` u WHERE u.`email` = l.`to_email`);

UPDATE `tbl_mail_logs` l
   SET l.`recipient_type` = 'user'
 WHERE l.`user_id` > 0 AND l.`to_email` <> ''
   AND EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u    WHERE u.`id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u    WHERE u.`email` = l.`to_email`)
   AND NOT EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_email` = l.`to_email`);

-- 3.5 Then the booking, exactly as for the bell rows.
UPDATE `tbl_mail_logs` l
   SET l.`recipient_type` = 'account'
 WHERE l.`user_id` > 0 AND l.`booking_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a  WHERE a.`user_id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u     WHERE u.`id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblweddingbook` b
                WHERE b.`booking_id` = l.`booking_id` AND b.`user_id` = l.`user_id`);

UPDATE `tbl_mail_logs` l
   SET l.`recipient_type` = 'user'
 WHERE l.`user_id` > 0 AND l.`booking_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a  WHERE a.`user_id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u     WHERE u.`id` = l.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblweddingbook` b
                WHERE b.`booking_id` = l.`booking_id` AND b.`organizer_id` = l.`user_id`);

-- ----------------------------------------------------------------------
-- 4. The indexes the new predicate reads through, and the removal of the
--    default. Each step is guarded so a re-run is a no-op.
-- ----------------------------------------------------------------------
DELIMITER //
DROP PROCEDURE IF EXISTS wpms213_finish_recipient_type //
CREATE PROCEDURE wpms213_finish_recipient_type()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tblnotifications'
                      AND INDEX_NAME   = 'idx_recipient_read') THEN
        ALTER TABLE `tblnotifications`
            ADD KEY `idx_recipient_read` (`recipient_type`, `user_id`, `is_read`);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tbl_mail_logs'
                      AND INDEX_NAME   = 'idx_mail_recipient') THEN
        ALTER TABLE `tbl_mail_logs`
            ADD KEY `idx_mail_recipient` (`recipient_type`, `user_id`, `status`);
    END IF;

    -- From here on the column has no default: naming it is mandatory.
    ALTER TABLE `tblnotifications` ALTER COLUMN `recipient_type` DROP DEFAULT;
    ALTER TABLE `tbl_mail_logs`   ALTER COLUMN `recipient_type` DROP DEFAULT;
END //
DELIMITER ;
CALL wpms213_finish_recipient_type();
DROP PROCEDURE IF EXISTS wpms213_finish_recipient_type;

-- ----------------------------------------------------------------------
-- 5. Verification — expected: no 'user'/'account' row on user_id 0, no
--    'none' row on a real id, and nothing left on the removed default.
-- ----------------------------------------------------------------------
SELECT 'collision rows the backfill could not attribute (left as ''user'')' AS check_name,
       COUNT(*) AS rows_left
  FROM `tblnotifications` n
 WHERE n.`user_id` > 0
   AND EXISTS (SELECT 1 FROM `tblaccounts` a WHERE a.`user_id` = n.`user_id`)
   AND EXISTS (SELECT 1 FROM `tblusers` u    WHERE u.`id` = n.`user_id`)
   AND NOT EXISTS (SELECT 1 FROM `tblweddingbook` b
                    WHERE b.`booking_id` = n.`booking_id`
                      AND (b.`user_id` = n.`user_id` OR b.`organizer_id` = n.`user_id`));
