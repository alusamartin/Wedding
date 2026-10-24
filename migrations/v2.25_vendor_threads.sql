-- ======================================================================
-- WPMS Kenya v2.25 — The conversation on a vendor deal
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.25_vendor_threads.sql
--
-- Why this exists:
--   A couple's enquiry, the vendor's reply and the planner's follow-up were
--   all written into `tblvendor_bookings.notes` — one text column, no author,
--   no timestamp, no way to answer. The couple could ask "is the 14th still
--   free?" only by ringing the planner, and nobody could tell whose turn it
--   was to reply. Messaging is the product: convenience for the couple IS the
--   reason the planner and the vendor are both on the platform.
--
-- What it changes:
--   1. Adds `tblvendor_messages`: one row per message on a deal, carrying its
--      author's ROLE and NAME as well as the id, so the thread still reads
--      correctly after an account is renamed or removed.
--   2. Read state is three nullable stamps (`read_couple_at`,
--      `read_vendor_at`, `read_planner_at`) on the message itself. A role's
--      unread count is "messages by other roles whose stamp for me is NULL" —
--      no per-viewer join table, no rows for people who never opened it.
--   3. `tblvendor_bookings.notes` is untouched. It remains the deal's audit
--      trail (confirmations, availability flags, staff edits); the thread is
--      the conversation. Nothing is migrated or rewritten, so an existing
--      deal still shows exactly what it showed before.
--
-- What is deliberately NOT stored:
--   No "stage" column. The next step is derived from the deal's status and
--   who spoke last (VendorThread::stage()), so it can never go stale: a
--   confirmed deal cannot keep advertising "waiting on the vendor", and a
--   reply moves the ball the instant it is written.
--
-- Verified against code after migration:
--   php -l admin/include/VendorThread.php
--   SHOW TABLES LIKE 'tblvendor_messages';
--   vendor/bin/phpunit tests/VendorThreadTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

CREATE TABLE IF NOT EXISTS `tblvendor_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deal_id` int(11) NOT NULL COMMENT 'tblvendor_bookings.id this message belongs to',
  `author_role` enum('couple','vendor','planner') NOT NULL COMMENT 'which side of the deal wrote it',
  `author_id` int(11) NOT NULL DEFAULT 0 COMMENT 'tblaccounts.user_id for a couple, tblusers.id for a vendor or planner',
  `author_name` varchar(120) NOT NULL DEFAULT '' COMMENT 'the name shown on the message, kept even if the account is later renamed',
  `body` text NOT NULL COMMENT 'the message itself, plain text',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_couple_at` datetime DEFAULT NULL COMMENT 'when the couple last opened this thread; NULL = unread for them',
  `read_vendor_at` datetime DEFAULT NULL COMMENT 'when the vendor last opened this thread; NULL = unread for them',
  `read_planner_at` datetime DEFAULT NULL COMMENT 'when the planner last opened this thread; NULL = unread for them',
  PRIMARY KEY (`id`),
  KEY `deal_thread` (`deal_id`, `id`),
  KEY `deal_unread` (`deal_id`, `read_couple_at`, `read_vendor_at`, `read_planner_at`),
  CONSTRAINT `tblvendor_messages_ibfk_1` FOREIGN KEY (`deal_id`) REFERENCES `tblvendor_bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Three-way conversation on a vendor deal: couple, vendor and the couple''s planner';
