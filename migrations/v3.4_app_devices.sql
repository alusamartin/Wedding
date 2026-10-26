-- =====================================================================
-- v3.4 — Android app device tokens
--   mysql -u root dbwedding < migrations/v3.4_app_devices.sql
--
-- WHY A SECOND TOKEN TABLE (and not tblapi_tokens).
--   tblapi_tokens authenticates an AI ASSISTANT that acts on a person's
--   behalf, with scopes, an audit trail and human approvals for writes.
--   An app token authenticates the PERSON: it is the phone's own
--   credential, it carries no scopes, and every request runs with exactly
--   the powers the couple already has in a browser. Keeping the two apart
--   means revoking a lost phone can never disturb an assistant, and a
--   scoped MCP token can never be replayed as an app session.
--
-- WHAT IS STORED.
--   Only the SHA-256 of the secret, never the secret itself. The plaintext
--   is shown once, in the login response, and cannot be recovered — the
--   same discipline as tblapi_tokens and tbl_password_resets. `token_prefix`
--   exists so a person can recognise a row ("the one ending 4f2a") without
--   the server being able to print the credential.
--
-- WHO THE ROW BELONGS TO.
--   `user_id` is always `tblaccounts.user_id` — couples. Staff and vendors
--   use the web on a desk; the app is the couple's companion (v1 scope).
--   The column name is deliberately the couple table's own key name.
--
-- EXPIRY AND REVOCATION.
--   A token lives 60 days from sign-in and is renewed by signing in again.
--   `revoked_at` is what "sign out this device" and "I lost my phone" set;
--   no row is ever deleted by hand, so a stolen token's last use stays
--   readable in `last_used_at` / `last_used_ip`.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS, no drops, no data touched.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `tblapp_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'tblaccounts.user_id — the couple this phone belongs to',
  `device_name` varchar(80) NOT NULL DEFAULT '' COMMENT 'what the phone calls itself, e.g. "Tecno Spark 10"',
  `platform` varchar(20) NOT NULL DEFAULT 'android',
  `app_version` varchar(20) NOT NULL DEFAULT '',
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 hex of the secret; the secret itself is never stored',
  `token_prefix` varchar(16) NOT NULL DEFAULT '' COMMENT 'last 4 characters, display only',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_token_hash` (`token_hash`),
  KEY `idx_app_device_user` (`user_id`,`revoked_at`),
  KEY `idx_app_device_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification:
--   SHOW COLUMNS FROM tblapp_devices;
--   SELECT COUNT(*) FROM tblapp_devices WHERE revoked_at IS NULL AND expires_at > NOW();
