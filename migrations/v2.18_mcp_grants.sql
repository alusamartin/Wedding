-- ======================================================================
-- WPMS Kenya v2.18 — the least a database user can be for MCP to work
-- ======================================================================
-- Run as an administrator (cPanel > MySQL, or root on XAMPP):
--   mysql -u root -p dbwedding < migrations/v2.18_mcp_grants.sql
--
-- WHAT THIS IS, AND WHAT IT IS NOT. The MCP layer runs in the same PHP app as
-- the pages, so by default it uses the SAME database credentials and nothing
-- here changes. Pointed at a user of its own — a second vhost, a staging copy,
-- or (later) a read replica with its own WPMS_DB_* overrides — these grants
-- become enforcement rather than documentation: the AI door loses the ability
-- to DELETE a guest, reshape a table, read a password hash, or rewrite the
-- audit trail that records it.
--
-- THE RULE FOR EVERY LINE BELOW: a privilege exists here only because a tool
-- calls a model method that uses it. Nothing is granted "for later".
--
-- Two users, because reading and writing deserve different answers:
--
--   wpms_mcp_ro   SELECT, table by table. Correct for an install with
--                 WPMS_MCP_WRITES unset — the default, and the recommended
--                 state: the assistant answers every question and changes
--                 nothing.
--   wpms_mcp_rw   The same reads, plus exactly the writes the Tier-2 and
--                 Tier-3 tools perform through the models. Tier 3 ALSO needs
--                 WPMS_MCP_WRITES=1 and a human approval; a grant is never a
--                 substitute for either.
--
-- If one user is enough, create only wpms_mcp_rw and leave WPMS_MCP_WRITES=0:
-- the write grants are then dormant and the switch is the only thing that has
-- to be right.
--
-- WHY EVERY TABLE IS NAMED. `GRANT SELECT ON dbwedding.*` would be shorter and
-- would silently undo the one protection that matters most here: MySQL grants
-- are ADDITIVE, so a table-level SELECT re-grants the password columns that
-- tblusers and tblaccounts are deliberately restricted to below. The list is
-- long because the protection is real.
--
-- Replace CHANGE_ME_ONCE with a long random password (twice: both users) and
-- keep it in the endpoint's own .env, never in git. Neither account needs
-- GRANT OPTION, SUPER, or any database other than this one.
-- ======================================================================

USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. READS — every table the thirteen read tools, the resources, the prompts
--    and the request machinery actually touch.
--
--    If a read tool ever reports a table privilege error, the message names
--    the table; add SELECT for it here rather than widening to dbwedding.*.
-- ----------------------------------------------------------------------
-- Weddings, couples and the guest list.
--   tblweddingbook      list_weddings, get_wedding, list_timeline, budget
--   tblguest            list_guests, rsvp_status, headcount_capacity, SMS audiences
--   tblchecklist        list_tasks
--   events              the wedding day's functions (record_payment validates one)
--   tbleventbooking     the per-function plan behind headcount_capacity
-- Payments and money.
--   tbl_liquidation         budget_position, record_payment
--   tbl_installment_plans   budget_position
--   tblweddingcategories    the package price the balance is measured against
-- Vendors.
--   tblvendors, tblvendor_packages, tblvendor_bookings
-- Websites and gallery.
--   tblwedding_websites, tblgallery
-- Platform state a planner's own tools read.
--   tblsubscriptions, tblsubscription_plans, tblnotifications, tbluser_prefs
-- The endpoint's own tables.
--   tblapi_tokens, tblapi_audit, tblapi_approvals, tblpublic_throttle
--
-- tblusers, tblaccounts and tblaccounts_detail are NOT in that list: they get
-- COLUMN-LEVEL reads, so the two columns the hard rules forbid can never be
-- selected, whatever a future tool is written to do.

-- ----------------------------------------------------------------------
-- 2. The read user.
-- ----------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'wpms_mcp_ro'@'localhost' IDENTIFIED BY 'CHANGE_ME_ONCE';

GRANT SELECT ON `dbwedding`.`tblweddingbook`         TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblguest`               TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblchecklist`           TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`events`                 TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tbleventbooking`        TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tbl_liquidation`        TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tbl_installment_plans`  TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblweddingcategories`   TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblvendors`             TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblvendor_packages`     TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblvendor_bookings`     TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblwedding_websites`    TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblgallery`             TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblsubscriptions`       TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblsubscription_plans`  TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblnotifications`       TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tbluser_prefs`          TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblapi_tokens`          TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblapi_audit`           TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblapi_approvals`       TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT ON `dbwedding`.`tblpublic_throttle`     TO 'wpms_mcp_ro'@'localhost';

-- The three identity tables: named columns only. `password` (tblusers) and
-- `user_password` (tblaccounts) are deliberately absent — this is the grant
-- that makes "no tool may ever return a password or a hash" a fact about the
-- database rather than a promise about the code.
GRANT SELECT (`id`, `username`, `email`, `access_level`, `phone`, `created_at`)
    ON `dbwedding`.`tblusers` TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT (`user_id`, `user_email`, `status`, `created_at`)
    ON `dbwedding`.`tblaccounts` TO 'wpms_mcp_ro'@'localhost';
GRANT SELECT (`user_id`, `firstname`, `lastname`, `email`, `phone`, `city`, `location`,
              `status`, `wedding_type`, `organizer_id`, `cash_advanced`, `expectation_visitor`,
              `description`, `created_at`)
    ON `dbwedding`.`tblaccounts_detail` TO 'wpms_mcp_ro'@'localhost';

FLUSH PRIVILEGES;

-- ----------------------------------------------------------------------
-- 3. The read/write user: the same reads, plus each write with the tool that
--    needs it.
-- ----------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'wpms_mcp_rw'@'localhost' IDENTIFIED BY 'CHANGE_ME_ONCE';

GRANT SELECT ON `dbwedding`.`tblweddingbook`         TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblguest`               TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblchecklist`           TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`events`                 TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tbleventbooking`        TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tbl_liquidation`        TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tbl_installment_plans`  TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblweddingcategories`   TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblvendors`             TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblvendor_packages`     TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblvendor_bookings`     TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblwedding_websites`    TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblgallery`             TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblsubscriptions`       TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblsubscription_plans`  TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblnotifications`       TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tbluser_prefs`          TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblapi_audit`           TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblapi_approvals`       TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT ON `dbwedding`.`tblpublic_throttle`     TO 'wpms_mcp_rw'@'localhost';

GRANT SELECT (`id`, `username`, `email`, `access_level`, `phone`, `created_at`)
    ON `dbwedding`.`tblusers` TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT (`user_id`, `user_email`, `status`, `created_at`)
    ON `dbwedding`.`tblaccounts` TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT (`user_id`, `firstname`, `lastname`, `email`, `phone`, `city`, `location`,
              `status`, `wedding_type`, `organizer_id`, `cash_advanced`, `expectation_visitor`,
              `description`, `created_at`)
    ON `dbwedding`.`tblaccounts_detail` TO 'wpms_mcp_rw'@'localhost';

-- Tier 2 — add_guest, update_guest, mark_invited (Guest) and toggle_task (Checklist).
--   INSERT a guest, change one, stamp the invitation that starts the reminder
--   clock (rsvp_token / rsvp_invited_at), and flip one checklist item.
GRANT INSERT, UPDATE ON `dbwedding`.`tblguest`     TO 'wpms_mcp_rw'@'localhost';
GRANT UPDATE         ON `dbwedding`.`tblchecklist` TO 'wpms_mcp_rw'@'localhost';

-- Tier 3 — record_payment: one row in the couple's ledger. INSERT only: a
--   payment, once recorded, is corrected by a human on the ledger page, never
--   by a second call from an assistant.
GRANT INSERT ON `dbwedding`.`tbl_liquidation` TO 'wpms_mcp_rw'@'localhost';

-- Tier 3 — initiate_stk_deposit: the promise row, then the Daraja ids stamped
--   onto it when M-Pesa answers.
GRANT INSERT, UPDATE ON `dbwedding`.`tblmpesa_stk_pushes` TO 'wpms_mcp_rw'@'localhost';

-- Tier 3 — send_bulk_sms: one log row per recipient, marked sent/failed, and
--   charged to the sender's credit balance only when it actually sent.
GRANT INSERT, UPDATE ON `dbwedding`.`tblsms_logs`    TO 'wpms_mcp_rw'@'localhost';
GRANT UPDATE         ON `dbwedding`.`tblsms_credits` TO 'wpms_mcp_rw'@'localhost';

-- Tier 3 — assign_planner: organizer_id on the wedding. MySQL cannot restrict
--   an UPDATE to a single column, so this is the one table-level write grant
--   in the file; the app writes only that column, and the tool states in its
--   own result that it creates no per-planner scoping.
GRANT UPDATE (`organizer_id`) ON `dbwedding`.`tblweddingbook` TO 'wpms_mcp_rw'@'localhost';

-- The endpoint's own tables.
--   tblapi_tokens      read the token, and stamp last_used_at/ip — those two
--                      columns only, so a token can never be re-scoped or
--                      un-revoked by the thing holding it.
--   tblapi_approvals   insert a request, decide it, consume it once.
--   tblapi_audit       INSERT and SELECT ONLY. This is the point of the whole
--                      file: the log cannot be edited or erased by the process
--                      it describes.
--   tblpublic_throttle the per-token rate-limit counter.
GRANT SELECT ON `dbwedding`.`tblapi_tokens` TO 'wpms_mcp_rw'@'localhost';
GRANT UPDATE (`last_used_at`, `last_used_ip`) ON `dbwedding`.`tblapi_tokens` TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT, INSERT, UPDATE ON `dbwedding`.`tblapi_approvals`   TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT, INSERT         ON `dbwedding`.`tblapi_audit`       TO 'wpms_mcp_rw'@'localhost';
GRANT SELECT, INSERT, UPDATE ON `dbwedding`.`tblpublic_throttle` TO 'wpms_mcp_rw'@'localhost';

FLUSH PRIVILEGES;

-- ----------------------------------------------------------------------
-- 4. WHAT IS NEVER GRANTED, AND WHY — kept beside the grants so the reason
--    travels with them.
--
--   DELETE, any table        No MCP tool deletes anything: not a guest, not a
--                            payment, not an audit row. If that is ever
--                            wanted it should be a human on a page.
--   UPDATE tblapi_audit      An audit trail its writer can rewrite answers no
--                            question worth asking. Append-only by permission.
--   UPDATE tblwedding_websites
--                            WeddingWebsite::increment_views() is an explicit
--                            never-expose; with no UPDATE grant, a bug cannot
--                            even attempt it.
--   UPDATE/DELETE tbl_liquidation
--                            Money rows are corrected by a human, on purpose.
--   SELECT tblusers.password, tblaccounts.user_password
--                            The column grants above are the enforcement.
--                            Never "simplify" them into a table-level SELECT.
--   ALTER, CREATE, DROP, INDEX, TRIGGER, CREATE/ALTER ROUTINE, EVENT,
--   REFERENCES, LOCK TABLES, CREATE TEMPORARY TABLES
--                            Structural rights. The endpoint runs no
--                            migration, and an assistant that could reshape a
--                            table is not a scoped assistant.
--   FILE, PROCESS, SUPER, RELOAD, REPLICATION CLIENT, REPLICATION SLAVE,
--   CREATE USER, GRANT OPTION
--                            Server-level rights. SUPER alone would defeat
--                            every grant above.
--   Any other database       `dbwedding` only. There is no reason for the
--                            endpoint to see mysql.* or another application's
--                            tables, and ON *.* is how a scoped user stops
--                            being scoped.
--
--   AND: reading a replica is a READ privilege. The replica itself must be
--   read-only (read_only=ON), so pointing an assistant's reads at one does not
--   put write safety in the replica's hands — that is what wpms_mcp_rw's
--   limited grants are for.
-- ----------------------------------------------------------------------

-- ----------------------------------------------------------------------
-- 5. Verify. From a shell, expect the SELECTs to work and each denied line to
--    fail with "command denied to user":
--
--   SHOW GRANTS FOR 'wpms_mcp_ro'@'localhost';
--   SHOW GRANTS FOR 'wpms_mcp_rw'@'localhost';
--
--   mysql -u wpms_mcp_ro -p dbwedding -e "SELECT COUNT(*) FROM tblguest;"
--   mysql -u wpms_mcp_ro -p dbwedding -e "SELECT password FROM tblusers LIMIT 1;"        -- denied
--   mysql -u wpms_mcp_rw -p dbwedding -e "UPDATE tblapi_audit SET ok = 1 LIMIT 1;"       -- denied
--   mysql -u wpms_mcp_rw -p dbwedding -e "DELETE FROM tblguest LIMIT 1;"                 -- denied
-- ----------------------------------------------------------------------
SELECT 'mcp users' AS check_name, COUNT(*) AS n
  FROM mysql.user
 WHERE User IN ('wpms_mcp_ro', 'wpms_mcp_rw');
