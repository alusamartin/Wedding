-- ======================================================================
-- WPMS Kenya v2.14 — One shared abuse guard for the unauthenticated doors
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.14_public_abuse_guard.sql
--
-- Why this exists:
--   Three endpoints answer an anonymous request and, in doing so, WRITE:
--
--     rsvp.php?g=<token>          POST  -> tblguest.answer + Notification (one
--                                          tblnotifications row and one
--                                          tbl_mail_logs row per answer)
--     site.php?slug=<slug>        GET   -> tblwedding_websites.views + 1
--     mpesa_c2b.php               POST  -> tblmpesa_callback_log row, then
--     mpesa_stk_callback.php      POST     fulfilment (bell + mail when a push
--                                          succeeds)
--
--   Each already authorises its caller (a 32-hex token, a published slug, the
--   optional Daraja shared secret), and none of them was rate-limited: a loop
--   over one guest's invite link produced a bell and an email per iteration.
--   The login door (tbllogin_throttle) and the signup door (tblsignup_throttle)
--   have their own counters; this is the third and last one, and it is shared
--   rather than three tables, so one policy answers for all of them.
--
-- What it holds:
--   ONE ROW PER (kind, subject, ip) — not one row per request. `kind` is the
--   door ('rsvp', 'site', 'callback'), `subject` is what is being protected
--   (the RSVP token's sha256, the slug, the callback endpoint; '' for the
--   caller-only row) and `ip` is the address the web server observed.
--
--   The row carries a TUMBLING window: `window_started_at` + `attempts`. The
--   first request in a window starts it; requests inside it increment the
--   counter; when the window has passed the next request resets it to 1. So a
--   flood of ten thousand iterations is still two tiny indexed rows (the
--   subject row and the caller row), pruned after seven days — it cannot turn
--   into a row-per-request table of its own.
--
--   `blocked_count` is the flood meter: how many attempts this window refused.
--   It is a counter, not a log, and it is what an operator reads to see that a
--   door is under pressure (SELECT kind, subject, ip, blocked_count …).
--
-- Indexes: the unique key is the guard's lookup and its concurrency control
-- (the UPSERT path cannot double-insert a window), and updated_at is the index
-- prune() deletes through. `subject` is VARCHAR(96) — tokens are 64 hex chars,
-- slugs are shorter, and the narrow column keeps the unique key inside the
-- 767-byte index limit of every InnoDB row format this project runs on.
--
-- Verified after migration:
--   SHOW COLUMNS FROM tblpublic_throttle;
--   SHOW INDEX FROM tblpublic_throttle;
--   SELECT kind, COUNT(*) FROM tblpublic_throttle GROUP BY kind;
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/PublicGuardTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

CREATE TABLE IF NOT EXISTS `tblpublic_throttle` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind`              VARCHAR(32)  NOT NULL
        COMMENT 'the anonymous door: rsvp | site | callback',
    `subject`           VARCHAR(96)  NOT NULL DEFAULT ''
        COMMENT 'what is protected: the RSVP token hash, the slug, the callback endpoint; "" = the caller row',
    `ip`                VARCHAR(45)  NOT NULL
        COMMENT 'REMOTE_ADDR the web server observed — never X-Forwarded-For',
    `window_started_at` DATETIME     NOT NULL
        COMMENT 'the tumbling window this counter belongs to',
    `attempts`          INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'requests counted in this window',
    `blocked_count`     INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'requests this window refused — the flood meter',
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_public_door` (`kind`, `subject`, `ip`),
    KEY `idx_public_ip` (`ip`, `updated_at`),
    KEY `idx_public_prune` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.14 shared rate limiter for the anonymous doors (rsvp, site, mpesa callbacks)';

-- ----------------------------------------------------------------------
-- Verification — expected: the table exists with its three indexes, and no
-- row is older than the prune window on a healthy install.
-- ----------------------------------------------------------------------
SELECT 'indexes on tblpublic_throttle' AS check_name, COUNT(DISTINCT INDEX_NAME) AS n
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpublic_throttle';
