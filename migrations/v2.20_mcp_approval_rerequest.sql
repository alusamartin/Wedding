-- ======================================================================
-- WPMS Kenya v2.20 — an expired request can be revived without the assistant
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.20_mcp_approval_rerequest.sql
--
-- Why this exists:
--   v2.19 made an expired Tier-3 request audible: the sweep closed it, told the
--   planner and recorded it for the assistant. But the only way to act on that
--   bell was to go back to the assistant and ask for the same thing again. A
--   planner who had already read the payload and decided it was right had no way
--   to say so — the request was dead, and the work of describing it started over.
--
--   admin/mcp_approvals.php now has ONE button on an expired row: Re-request.
--   It creates a NEW pending request for the same tool and the same
--   `payload_hash` (so the assistant's identical call still fits), with a fresh
--   30-minute window. The expired row is NOT reopened: its history stays true —
--   "this one died waiting" — and the new row is the live question.
--
-- What these two columns hold:
--   re_request_of    tblapi_approvals.id of the expired request this one was
--                    revived from. NULL for a request the assistant made itself.
--   re_requested_by  tblusers.id of the planner who pressed the button, so the
--                    record says a human chose to ask again rather than a model
--                    silently repeating itself.
--
--   They are lineage, not state. state_of() still derives the state from the
--   timestamps exactly as before, and the approval is still bound to the payload
--   hash rather than to either column.
--
-- Retention: these rows are pruned with the rest of the approval record by
-- scripts/notify_prune.php, so the chain does not grow forever either.
--
-- Verified after migration:
--   SHOW COLUMNS FROM tblapi_approvals LIKE 're_request%';
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/McpApprovalsPageTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- MariaDB supports IF NOT EXISTS; on stock MySQL 8 wrap this in the same
-- information_schema-checked procedure v2.13/v2.6 use. The statements are
-- identical either way.
ALTER TABLE `tblapi_approvals`
  ADD COLUMN IF NOT EXISTS `re_request_of` int(11) DEFAULT NULL
      COMMENT 'the expired approval request this one was revived from; NULL when the assistant asked'
      AFTER `consumed_at`;

ALTER TABLE `tblapi_approvals`
  ADD COLUMN IF NOT EXISTS `re_requested_by` int(11) DEFAULT NULL
      COMMENT 'tblusers.id of the planner who pressed Re-request'
      AFTER `re_request_of`;

-- Looking up "what did apr-57 become?" is a single indexed read on the page.
ALTER TABLE `tblapi_approvals`
  ADD KEY IF NOT EXISTS `idx_approval_rerequest` (`re_request_of`);

-- ----------------------------------------------------------------------
-- Verification — expected: two rows for the re-request columns.
-- ----------------------------------------------------------------------
SELECT 'approval re-request columns' AS check_name, COUNT(*) AS n
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblapi_approvals'
   AND COLUMN_NAME IN ('re_request_of', 're_requested_by');
