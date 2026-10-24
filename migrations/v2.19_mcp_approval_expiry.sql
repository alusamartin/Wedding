-- ======================================================================
-- WPMS Kenya v2.19 — an unanswered approval request is closed, not forgotten
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.19_mcp_approval_expiry.sql
--
-- Why this exists:
--   v2.17 gave a Tier-3 MCP write a 30-minute window in which a signed-in
--   planner can approve it. When nobody answers, the row simply became unusable
--   (expires_at passed) and nothing else happened: the planner was never told,
--   the assistant's next attempt minted a fresh `apr-` reference as though the
--   first one had never existed, and the call log had no line saying "this one
--   died waiting". A window that closes in silence is a window that might as
--   well not exist — an assistant reads the silence as "still pending", and a
--   planner only learns the SMS never went out when the guests ask.
--
--   So one timestamp is added, written by scripts/mcp_approval_sweep.php:
--
--     expired_at  set ONCE, by the sweep, when the window closed with no
--                 decision. The sweep is the only writer, and its UPDATE races
--                 on `expired_at IS NULL` exactly as consume() races on
--                 `consumed_at IS NULL` — so a request is reported, audited and
--                 alerted about exactly once, however often cron runs, and a
--                 planner deciding at the same instant wins the race.
--
--   It is a MARKER, not a new state. A row is still "expired" because
--   expires_at passed (state_of() derives it from the timestamps, as before);
--   expired_at says "the sweep has already told everyone", which is what makes
--   re-running the job silent instead of a second bell.
--
--   The sweep writes one tblapi_audit row per expiry — the record the ASSISTANT
--   reads on its next call — and one digest notification per planner, so the
--   closure is audible on both sides:
--     * assistant: the audit row says the request expired unanswered and that
--       nothing was sent or recorded; a retry of the identical call is answered
--       with the old reference and an explicit "this is a NEW request".
--     * planner: one bell (+ email, when mail is on) per sweep, naming the
--       references that died — never one alert per row.
--
-- Verified after migration:
--   SHOW COLUMNS FROM tblapi_approvals LIKE 'expired_at';
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/McpApprovalSweepTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- MariaDB supports IF NOT EXISTS; on stock MySQL 8 wrap this in the same
-- information_schema-checked procedure v2.13/v2.6 use. The statement itself is
-- identical either way.
ALTER TABLE `tblapi_approvals`
  ADD COLUMN IF NOT EXISTS `expired_at` datetime DEFAULT NULL
      COMMENT 'set once by scripts/mcp_approval_sweep.php when the window closed unanswered — the alert/audit marker'
      AFTER `consumed_at`;

-- The sweep's own selection: undecided rows past their window that it has not
-- closed yet. The existing idx_approval_expiry covers expires_at; this one keeps
-- the "already swept" filter off a growing table's hot path.
ALTER TABLE `tblapi_approvals`
  ADD KEY IF NOT EXISTS `idx_approval_expired` (`expired_at`);

-- ----------------------------------------------------------------------
-- Verification — expected: one row for expired_at.
-- ----------------------------------------------------------------------
SELECT 'approval expiry marker' AS check_name, COUNT(*) AS n
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblapi_approvals'
   AND COLUMN_NAME = 'expired_at';
