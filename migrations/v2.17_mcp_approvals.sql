-- ======================================================================
-- WPMS Kenya v2.17 — a human in the loop for writing over MCP
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.17_mcp_approvals.sql
--
-- Why this exists:
--   v2.16 let an AI client READ this platform as one of its own accounts. v2.17
--   lets it WRITE a few things — a guest, a checklist tick, an SMS to a guest
--   list, a payment, a deposit prompt, a planner assignment — and the whole
--   design of that is one sentence:
--
--     A WRITE EITHER NEEDS `confirm: true` ON THE CALL, OR A TOKEN A SIGNED-IN
--     HUMAN MINTED IN THE BROWSER FOR THAT EXACT CALL.
--
--   Reading a wedding is a question. Sending 400 guests an SMS is not, and
--   neither is recording a payment: those are money and messages, and an
--   assistant that can do them on its own is an assistant that can do them
--   wrongly at scale. So the money/message tools (Tier 3) refuse on their own
--   authority, record the request HERE, and the planner approves it on
--   admin/mcp_approvals.php — signed in, with CSRF, through the same Session the
--   rest of the admin does. The approval is a short-lived, single-use,
--   HMAC-signed string bound to the tool AND to a hash of the exact payload
--   (that SMS body, that audience, that booking; that amount, that method, that
--   M-Pesa code). Change one byte of the SMS and the approval no longer fits.
--
-- What it holds:
--   tblapi_approvals — ONE ROW PER REQUESTED TIER-3 CALL, and one row per
--   decision about it. State is carried by TIMESTAMPS, never by a status column
--   that someone would be tempted to UPDATE into a lie, and rows are never
--   deleted:
--     pending   = approved_at, denied_at and consumed_at all NULL and expires_at
--                 in the future;
--     approved  = approved_at set (the HMAC string exists; only its sha256 is
--                 stored — the same discipline as tblapi_tokens);
--     consumed  = consumed_at set, exactly once, by the UPDATE that races for it
--                 (consumed_at IS NULL in the WHERE — that is the exactly-once
--                 guarantee for an SMS or an STK push);
--     denied    = denied_at set, with the planner's reason.
--   `payload_note` is written by the SERVER from the data it resolved, never
--   from the assistant's prose: the planner approves a sentence the platform
--   produced out of the same rows the tool will act on, so the thing approved
--   and the thing performed cannot be two different things.
--
--   tblapi_audit (v2.16, extended here) — every call already lands here. Four
--   columns are added so a write is legible afterwards: `tier` (1 read, 2
--   confirm, 3 human-approved), `request_id` (the idempotency key a retrying
--   assistant sends — one row per key, so a retry is visible AS a retry),
--   `approval_id` (which human decision authorised it) and `payload_hash`, plus
--   `outcome_note` (a short, server-written line about what actually changed —
--   never a second copy of the SMS body or the guest's phone number).
--
-- Why the unique key on (token_id, request_id): it is what makes "a retried
-- call cannot add the same guest twice" checkable rather than hoped for. A NULL
-- request_id (every read, and every call that did not change data) is exempt —
-- MySQL allows many NULLs in a unique key, deliberately, which is exactly the
-- behaviour needed here.
--
-- Grants: the MCP database user gets INSERT and SELECT on tblapi_audit and no
-- UPDATE and no DELETE (see migrations/v2.18_mcp_grants.sql) — the log is
-- append-only by permission as well as by convention. tblapi_approvals is the
-- one table the MCP layer may UPDATE, and only to consume or to point at a
-- decision; the page does the approving, and it runs as the web user.
--
-- Retention: both tables are pruned with the audit trail by
-- scripts/notify_prune.php (ApiToken::prune_audit), so nothing here grows
-- forever. A request that expired un-decided is pruned with the rest; that is
-- why an approval must be used inside its window rather than kept for later.
--
-- Verified after migration:
--   SHOW COLUMNS FROM tblapi_approvals;
--   SHOW COLUMNS FROM tblapi_audit;
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/McpWriteToolsTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. The approval record.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblapi_approvals` (
  `id`               int(11)      NOT NULL AUTO_INCREMENT,
  `token_id`         int(11)      DEFAULT NULL COMMENT 'the token that asked for the write; NULL if it could not be resolved',
  `recipient_type`   enum('user','account','none') NOT NULL DEFAULT 'none' COMMENT 'which account table user_id indexes (v2.13 vocabulary)',
  `user_id`          int(11)      NOT NULL DEFAULT 0 COMMENT 'the principal the asking token acts as',
  `tool`             varchar(64)  NOT NULL DEFAULT '' COMMENT 'the Tier-3 tool being authorised, e.g. send_bulk_sms',
  `tier`             tinyint(1)   NOT NULL DEFAULT 3 COMMENT '3 = money / messages / status: always needs a human',
  `booking_id`       int(11)      DEFAULT NULL COMMENT 'the wedding the write concerns',
  `payload_hash`     char(64)     NOT NULL COMMENT 'sha256 of the committed payload — the approval is bound to this',
  `payload_note`     varchar(512) NOT NULL DEFAULT '' COMMENT 'the SERVER''s own sentence saying exactly what will happen',
  `approval_hash`    char(64)     DEFAULT NULL COMMENT 'sha256 of the minted approval string; the string itself is never stored',
  `approval_prefix`  varchar(16)  DEFAULT NULL COMMENT 'display-only prefix, so a planner can tell two approvals apart',
  `approved_by`      int(11)      DEFAULT NULL COMMENT 'tblusers.id of the human who approved it in the browser',
  `approved_at`      datetime     DEFAULT NULL COMMENT 'set only by admin/mcp_approvals.php, with CSRF, on a signed-in session',
  `denied_by`        int(11)      DEFAULT NULL COMMENT 'tblusers.id of the human who refused it',
  `denied_at`        datetime     DEFAULT NULL,
  `deny_reason`      varchar(255) NOT NULL DEFAULT '' COMMENT 'what the planner said when refusing',
  `consumed_at`      datetime     DEFAULT NULL COMMENT 'set exactly once, by the UPDATE that races for consumed_at IS NULL',
  `expires_at`       datetime     NOT NULL COMMENT 'the window; after this the request is dead and gets pruned',
  `created_at`       datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_hash` (`approval_hash`),
  KEY `idx_approval_tool` (`tool`, `booking_id`),
  KEY `idx_approval_owner` (`recipient_type`, `user_id`, `created_at`),
  KEY `idx_approval_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.17 MCP Tier-3 approvals: a signed-in human authorises one exact payload, once';

-- ----------------------------------------------------------------------
-- 2. The audit trail learns what a write is.
--
--    MariaDB (this project's convention) supports ADD COLUMN IF NOT EXISTS;
--    stock MySQL does not — on MySQL 8 wrap each ALTER in the same
--    information_schema-checked procedure v2.13/v2.6 use. The statements
--    themselves are identical either way.
-- ----------------------------------------------------------------------
ALTER TABLE `tblapi_audit`
  ADD COLUMN IF NOT EXISTS `tier` tinyint(1) NOT NULL DEFAULT 1
      COMMENT '1 = read, 2 = reversible write needing confirm, 3 = money/messages needing a human approval';

ALTER TABLE `tblapi_audit`
  ADD COLUMN IF NOT EXISTS `request_id` varchar(64) DEFAULT NULL
      COMMENT 'the caller''s idempotency key — unique per token, so a retry is visible as a retry';

ALTER TABLE `tblapi_audit`
  ADD COLUMN IF NOT EXISTS `approval_id` int(11) DEFAULT NULL
      COMMENT 'tblapi_approvals.id of the human decision that authorised this call';

ALTER TABLE `tblapi_audit`
  ADD COLUMN IF NOT EXISTS `payload_hash` char(64) NOT NULL DEFAULT ''
      COMMENT 'sha256 of the committed payload, matching what the human approved';

ALTER TABLE `tblapi_audit`
  ADD COLUMN IF NOT EXISTS `outcome_note` varchar(255) NOT NULL DEFAULT ''
      COMMENT 'a short server-written line about what changed — never the message body or a phone number';

-- One row per idempotency key, so "the same request again" is a lookup rather
-- than a second guest, a second SMS or a second STK push. NULL keys (every read,
-- and every call that changed nothing) are exempt: MySQL allows many NULLs here.
ALTER TABLE `tblapi_audit`
  ADD UNIQUE KEY IF NOT EXISTS `uq_api_audit_idem` (`token_id`, `request_id`);

-- ----------------------------------------------------------------------
-- Verification — expected: the approvals table (empty on a fresh install), and
-- the five new columns plus the idempotency key on tblapi_audit.
-- ----------------------------------------------------------------------
SELECT 'mcp tables' AS check_name, COUNT(*) AS n
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tblapi_tokens', 'tblapi_audit', 'tblapi_approvals');

SELECT 'audit columns' AS check_name, COUNT(*) AS n
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblapi_audit'
   AND COLUMN_NAME IN ('tier', 'request_id', 'approval_id', 'payload_hash', 'outcome_note');

SELECT 'idempotency key' AS check_name, COUNT(*) AS n
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblapi_audit'
   AND INDEX_NAME = 'uq_api_audit_idem';
