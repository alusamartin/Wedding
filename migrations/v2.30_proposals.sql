-- ======================================================================
-- WPMS Kenya v2.30 — The proposal lifecycle: a registration becomes a
-- priced wedding the couple chooses from, then a confirmed plan.
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.30_proposals.sql
--
-- Why this exists
--   A couple registers on the public site and the platform creates a pending
--   booking. From there the planner works in a spreadsheet and a WhatsApp
--   group: pricing options, checking availability, chasing milestones. Every
--   piece exists (vendor marketplace, vendor plan, taste & budget, coverage,
--   notifications, M-Pesa) but nothing connects registration to a priced,
--   chosen, confirmed wedding. These three tables are that connection.
--
-- The three tables:
--   tblproposals        ONE row per wedding: the couple's brief, the planner who
--                       owns it, and where the conversation stands.
--   tblproposal_options A priced option the planner offers (the couple must be
--                       given at least three). Totals are stored because an
--                       option is a QUOTE — a moment in time — not a live sum.
--   tblproposal_items   What one option is made of: one vendor per line, for a
--                       package or a single service, with the quantity and the
--                       amount. On lock these become tblvendor_bookings rows.
--
-- WHY A SEPARATE PROPOSAL TABLE, AND NOT A NEW BOOKING STATUS
--   `tblaccounts_detail.status` is the platform's own two-state record
--   ('pending' / 'confirm') and several pages, reports and tests read it as
--   exactly that. Re-labelling every new registration would silently move rows
--   out of the planner's Pending queue and the couple's dashboard. So the
--   proposal lifecycle lives here: the booking stays `pending` while the couple
--   is deciding, and becomes `confirm` only at LOCK — the same state
--   client_assign.php has always written. Nothing that reads a booking status
--   had to change.
--
-- WHY A STATUS ENUM WITH THESE SEVEN VALUES
--   proposal          the couple has registered; nobody has acknowledged it
--   acknowledged      the planner has picked it up (the couple is told)
--   options           the planner has published >= 3 priced options
--   tweak_requested   the couple asked for a change instead of choosing
--   chosen            the couple picked an option; waiting for the lock
--   locked            the deals exist and the wedding is confirmed
--   declined          the couple walked away (a note says why)
--   Every transition is a POST + CSRF, authorises the role that owns it, and
--   notifies the other side. There is no transition back out of `locked`:
--   cancelling a confirmed wedding is the deal ledger's job (deals carry
--   `status = cancelled`), not this table's.
--
-- WHAT IT DELIBERATELY DOES NOT DO
--   - It does not store money twice. An option's `total` is the sum of its
--     items at the moment the planner saved it; the items are the detail. When
--     the option is locked the amounts are copied ONCE into
--     tblvendor_bookings, which stays the single record of what was dealt.
--   - It does not create vendor accounts, offerings or availability rows.
--     An item points at an existing tblvendor_services / tblvendor_packages
--     row (or `custom` when the arrangement is not in the catalogue), exactly
--     like v2.29's vendor plan.
--   - It does not touch a wedding that was confirmed before this migration:
--     those bookings simply have no proposal row, and every page copes.
--
-- Verified against code after migration:
--   SHOW TABLES LIKE 'tblproposal%';
--   SELECT COUNT(*) FROM tblproposals;                       -- whatever exists
--   vendor/bin/phpunit tests/ProposalFlowTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. One proposal per wedding.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblproposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL COMMENT 'the wedding this proposal is for (tblweddingbook.booking_id); one row per booking',
  `planner_id` int(11) NOT NULL DEFAULT 0 COMMENT 'tblusers id of the planner who owns it; 0 = in the shared queue, visible to every planner',
  `preferred_planner_id` int(11) NOT NULL DEFAULT 0 COMMENT 'the planner the couple asked for at sign-up, kept even after reassignment',
  `status` enum('proposal','acknowledged','options','tweak_requested','chosen','locked','declined') NOT NULL DEFAULT 'proposal',
  `guests` int(11) DEFAULT NULL COMMENT 'headcount the couple stated; NULL = not stated (never guessed)',
  `budget` decimal(12,2) DEFAULT NULL COMMENT 'the couple''s total budget in KSh; NULL = not stated',
  `venue` varchar(160) NOT NULL DEFAULT '' COMMENT 'venue or town the couple has in mind',
  `taste_tags` varchar(255) NOT NULL DEFAULT '' COMMENT 'comma-separated taste words, the same vocabulary vendors tag services with',
  `couple_note` varchar(500) NOT NULL DEFAULT '' COMMENT 'the couple''s own words about their wedding',
  `tweak_note` varchar(500) NOT NULL DEFAULT '' COMMENT 'what the couple asked to change, in their words',
  `decline_note` varchar(500) NOT NULL DEFAULT '' COMMENT 'why the couple declined, in their words',
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledged_by` int(11) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL COMMENT 'when the planner sent the options (>= 3) to the couple',
  `chosen_option_id` int(11) DEFAULT NULL,
  `chosen_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL COMMENT 'when the chosen option became real vendor deals and the booking was confirmed',
  `locked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proposal_booking` (`booking_id`),
  KEY `idx_proposals_planner` (`planner_id`,`status`),
  KEY `idx_proposals_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.30 one wedding proposal: brief, owning planner, lifecycle state';

-- ----------------------------------------------------------------------
-- 2. The options the planner offers. At least three must exist before the
--    couple can be asked to choose.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblproposal_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proposal_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL COMMENT 'what the couple reads, e.g. "The Garden Wedding"',
  `tier` varchar(40) NOT NULL DEFAULT 'recommended' COMMENT 'planner''s label: essential | recommended | premium (free text, shown as a chip)',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT 'one paragraph: what this option buys and what it leaves out',
  `total` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'sum of this option''s items when it was saved — a quote, not a live sum',
  `is_chosen` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proposal_options_proposal` (`proposal_id`),
  CONSTRAINT `tblproposal_options_ibfk_proposal` FOREIGN KEY (`proposal_id`)
    REFERENCES `tblproposals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.30 one priced option presented to the couple';

-- ----------------------------------------------------------------------
-- 3. What an option is made of: one vendor per line.
--
--    `kind` mirrors the vendor plan (v2.29): package, service, or custom (an
--    arrangement that is not in the catalogue and carries an agreed amount).
--    `service_qty` is the multiplier behind `amount`, so the couple can read
--    "Classic buffet x 180 guests" rather than a bare figure.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblproposal_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `option_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `kind` enum('service','package','custom') NOT NULL DEFAULT 'custom',
  `service_id` int(11) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `service_qty` int(11) DEFAULT NULL,
  `label` varchar(160) NOT NULL DEFAULT '' COMMENT 'what was bought, frozen at save time (a service may be renamed later)',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) NOT NULL DEFAULT '',
  `deal_id` int(11) DEFAULT NULL COMMENT 'tblvendor_bookings row created when the option was locked',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proposal_items_option` (`option_id`),
  KEY `idx_proposal_items_vendor` (`vendor_id`),
  CONSTRAINT `tblproposal_items_ibfk_option` FOREIGN KEY (`option_id`)
    REFERENCES `tblproposal_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tblproposal_items_ibfk_service` FOREIGN KEY (`service_id`)
    REFERENCES `tblvendor_services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tblproposal_items_ibfk_package` FOREIGN KEY (`package_id`)
    REFERENCES `tblvendor_packages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.30 the vendors and services one proposal option is built from';
