-- ======================================================================
-- WPMS Kenya v2.16 — AI access, on this platform's own terms (API tokens)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.16_api_tokens.sql
--
-- Why this exists:
--   An MCP endpoint (mcp.php) lets an AI client — Claude, ChatGPT, Cursor, an
--   internal agent — read and (later) act on real wedding data. The AI is the
--   CLIENT. This platform stays the authority: every call is authenticated as
--   one of its own accounts, scoped by the permissions that account already
--   has, and nothing happens that the same person could not do by hand.
--
--   That needs two things the schema did not have: a way to authenticate a
--   machine (a token, not a browser session with its cookies and CSRF), and a
--   record of what the machine did with it.
--
-- What it holds:
--   tblapi_tokens — ONE ROW PER TOKEN. `recipient_type` + `user_id` name the
--   principal in the v2.13 vocabulary, and for the same reason: `tblusers.id`
--   (staff, super admins and vendor leads) and `tblaccounts.user_id` (couples)
--   are two separate auto-increment sequences, so an id on its own does not say
--   who it is — a vendor whose id equals a couple's account id must never be
--   read as that couple. Every token therefore carries the pair, and the MCP
--   layer resolves it before it does anything.
--
--   The SECRET IS NEVER STORED. `token_hash` is sha256(secret) — the plaintext
--   is shown once, at mint time, and then only its hash exists. A dump of this
--   table (or the audit table, or a log) hands out nothing usable.
--   `token_prefix` is display-only, so an owner can tell two tokens apart.
--
--   `scopes` is the token's ceiling (csv of the catalogue in
--   admin/include/ApiToken.php): a token with `read:guests` and nothing else
--   cannot reach the budget, however the AI phrases the request. `booking_id`,
--   when set, NARROWS a token to one wedding — the agent that only ever needs
--   Amina's wedding gets a token that cannot see anybody else's.
--
--   `expires_at` (90 days by default), `revoked_at`, and `last_used_at` /
--   `last_used_ip` make a token a thing that can be audited and switched off
--   rather than a permanent password.
--
--   tblapi_audit — ONE ROW PER CALL. Which token, which principal, which tool,
--   which scope, whether it succeeded, why it failed, which booking, how long it
--   took, from which IP. `args_digest` records the SHAPE of the arguments (names
--   and sizes), never their values: a guest's phone number must not be copied
--   into a log that outlives the request.
--
--   Retention: 90 days, pruned by scripts/notify_prune.php — the job that
--   already owns retention is the job that prunes this, so there is no new
--   crontab entry and no table that grows forever (see JobWatch: that sweep is
--   monitored, so a broken prune is visible).
--
-- Indexes: the unique key on token_hash IS the authentication lookup (and its
-- concurrency control); (recipient_type, user_id) is the "my tokens" page; the
-- audit indexes are the two questions asked of it — "what did this token do"
-- and "what touched this account".
--
-- Verified after migration:
--   SHOW COLUMNS FROM tblapi_tokens;
--   SHOW COLUMNS FROM tblapi_audit;
--   SELECT COUNT(*) FROM tblapi_tokens WHERE revoked_at IS NULL;
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/McpProtocolTest.php tests/McpAuthTokenTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. Tokens. MariaDB syntax (project convention): on stock MySQL run this
--    CREATE once by hand — re-running it is what IF NOT EXISTS guards.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblapi_tokens` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `recipient_type` enum('user','account') NOT NULL DEFAULT 'user'
      COMMENT 'which account table user_id indexes: user = tblusers.id (staff, super admin, vendor), account = tblaccounts.user_id (couple)',
  `user_id`        int(11)      NOT NULL COMMENT 'the principal this token acts as — never a value the caller supplies',
  `name`           varchar(96)  NOT NULL DEFAULT '' COMMENT 'what the owner called it, e.g. "Claude — guest chase"',
  `token_hash`     char(64)     NOT NULL COMMENT 'sha256 of the secret; the secret itself is never stored',
  `token_prefix`   varchar(12)  NOT NULL DEFAULT '' COMMENT 'first characters of the secret, for the owner to tell tokens apart',
  `scopes`         varchar(255) NOT NULL DEFAULT '' COMMENT 'csv of the scope catalogue in ApiToken — the token''s ceiling',
  `booking_id`     int(11)      DEFAULT NULL COMMENT 'when set, this token is narrowed to one wedding',
  `expires_at`     datetime     DEFAULT NULL COMMENT 'NULL = does not expire (discouraged; the UI sets 90 days)',
  `last_used_at`   datetime     DEFAULT NULL,
  `last_used_ip`   varchar(45)  DEFAULT NULL COMMENT 'REMOTE_ADDR of the last call — informational, never a check',
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  `created_by`     int(11)      DEFAULT NULL COMMENT 'tblusers.id of whoever minted it; NULL = the owner',
  `revoked_at`     datetime     DEFAULT NULL COMMENT 'set by the owner (or an operator) to switch the token off',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_token_hash` (`token_hash`),
  KEY `idx_api_owner` (`recipient_type`, `user_id`),
  KEY `idx_api_expiry` (`expires_at`),
  KEY `idx_api_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.16 AI/API tokens: one principal each, hashed secret, scopes, optional wedding narrowing';

-- ----------------------------------------------------------------------
-- 2. The audit trail. What the AI did, with what, and how it went.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblapi_audit` (
  `id`             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_id`       int(11)      DEFAULT NULL COMMENT 'NULL when the call was refused before a token resolved',
  `recipient_type` enum('user','account','none') NOT NULL DEFAULT 'none',
  `user_id`        int(11)      NOT NULL DEFAULT 0,
  `tool`           varchar(64)  NOT NULL DEFAULT '' COMMENT 'the MCP tool name; '' for protocol-level calls',
  `scope`          varchar(48)  NOT NULL DEFAULT '' COMMENT 'the scope the tool required',
  `ok`             tinyint(1)   NOT NULL DEFAULT 0,
  `error`          varchar(255) NOT NULL DEFAULT '' COMMENT 'the refusal reason, verbatim enough to debug',
  `args_digest`    varchar(255) NOT NULL DEFAULT '' COMMENT 'argument NAMES and sizes only — never their values',
  `booking_id`     int(11)      DEFAULT NULL COMMENT 'the wedding the call resolved to, when it did',
  `ip`             varchar(45)  NOT NULL DEFAULT '',
  `ms`             int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_api_audit_token` (`token_id`, `created_at`),
  KEY `idx_api_audit_owner` (`recipient_type`, `user_id`, `created_at`),
  KEY `idx_api_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.16 MCP/API call audit: token, principal, tool, scope, outcome (pruned after 90 days by notify_prune)';

-- ----------------------------------------------------------------------
-- Verification — expected: 2 tables, 0 tokens on a fresh install, and the
-- audit table empty until an MCP client connects.
-- ----------------------------------------------------------------------
SELECT 'api tables' AS check_name, COUNT(*) AS n
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tblapi_tokens', 'tblapi_audit');
