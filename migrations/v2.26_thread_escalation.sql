-- ======================================================================
-- WPMS Kenya v2.26 — Nobody notices the thread that went quiet
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.26_thread_escalation.sql
--
-- Why this exists:
--   v2.25 gave every deal a three-way conversation. It made waiting VISIBLE —
--   the row says "1 new, waiting 3 h" and the queue is served oldest first —
--   but visible is not the same as told. A couple's question to their caterer
--   can sit unanswered for a week and nothing leaves the page: the planner has
--   to be looking at the right ledger, at the right row, on the right day. The
--   couple, who chose this platform for convenience, is the one who finds out.
--
--   This column is what lets a daily job say it once. When the vendor has left
--   the newest message in a thread unanswered for a day, the sweep
--   (scripts/thread_escalation.php) rings the couple's planner and stamps that
--   message here, so the next run is silent. A later message is a new row with
--   no stamp, so a conversation that goes quiet AGAIN is reported again —
--   one alert per unanswered message, never a daily repeat of the same one.
--   The vendor replying needs no reset: their message is the newest one, so
--   nothing is escalated until somebody writes after them.
--
--   Read state is deliberately NOT the trigger. `read_vendor_at` says the
--   vendor opened the thread; the couple's question was still not answered,
--   which is the fact that matters to them.
--
-- What it changes:
--   Adds `escalated_at` to tblvendor_messages. NULL = nobody has been told
--   about this message yet. No backfill: an install upgrading today has all
--   its unanswered messages unstamped, and the first sweep after the upgrade
--   reports the ones that are already a day old — which is the point of
--   installing it.
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

ALTER TABLE `tblvendor_messages`
  ADD COLUMN IF NOT EXISTS `escalated_at` DATETIME DEFAULT NULL
    COMMENT 'when the couple''s planner was told this message has waited too long; NULL = not told'
  AFTER `read_planner_at`;

-- ------------------------------------------------------------------
-- Verified against code after migration:
--   php -l admin/include/VendorThread.php
--   SHOW COLUMNS FROM tblvendor_messages LIKE 'escalated_at';   -- NULL default
--   php scripts/thread_escalation.php --dry-run
--   vendor/bin/phpunit tests/ThreadEscalationTest.php
-- ------------------------------------------------------------------
