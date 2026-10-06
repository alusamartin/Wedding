-- ======================================================================
-- WPMS Kenya — Kenyan-market schema additions
-- Run after `DATABASE FILE/dbwedding.sql` (README step b). Statements are
-- guarded (ADD COLUMN IF NOT EXISTS) so they no-op on a db that already
-- carries the Kenyan columns — safe on fresh v2.1 installs and when
-- re-run against an upgraded v2.0 database.
-- ======================================================================

-- 1. tblguest - Kenyan Guest Management
--    (full county names need more room than the legacy US char(4) `state`)
ALTER TABLE `tblguest`
ADD COLUMN IF NOT EXISTS `upcountry_accommodation` TINYINT(1) DEFAULT 0 AFTER `city`,
ADD COLUMN IF NOT EXISTS `harambee_contribution` DECIMAL(12,2) DEFAULT 0.00 AFTER `upcountry_accommodation`,
ADD COLUMN IF NOT EXISTS `dietary_requirements` VARCHAR(100) DEFAULT NULL AFTER `harambee_contribution`,
ADD COLUMN IF NOT EXISTS `transport_needed` TINYINT(1) DEFAULT 0 AFTER `dietary_requirements`,
ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL AFTER `transport_needed`,
ADD COLUMN IF NOT EXISTS `rsvp_token` CHAR(32) DEFAULT NULL AFTER `phone`,
ADD COLUMN IF NOT EXISTS `rsvp_status` ENUM('pending','yes','no','maybe') DEFAULT 'pending' AFTER `rsvp_token`,
ADD COLUMN IF NOT EXISTS `rsvp_at` DATETIME DEFAULT NULL AFTER `rsvp_status`,
ADD COLUMN IF NOT EXISTS `rsvp_note` VARCHAR(255) DEFAULT NULL AFTER `rsvp_at`,
ADD COLUMN IF NOT EXISTS `rsvp_invited_at` DATETIME DEFAULT NULL AFTER `rsvp_note`,
ADD COLUMN IF NOT EXISTS `rsvp_reminded_at` DATETIME DEFAULT NULL AFTER `rsvp_invited_at`,
ADD COLUMN IF NOT EXISTS `rsvp_reminders` TINYINT(3) NOT NULL DEFAULT 0 AFTER `rsvp_reminded_at`,
ADD UNIQUE INDEX IF NOT EXISTS `idx_guest_rsvp_token` (`rsvp_token`),
MODIFY `state` VARCHAR(100) NOT NULL DEFAULT '';

-- 1b. tblweddingbook - payment deadline-band marker (scripts/deadline_sms.php)
--     Stores the SMALLEST band (in days) already texted to the couple. A band
--     fires only when the current band is smaller, so date changes and late
--     registrations never duplicate or skip unfairly.
ALTER TABLE `tblweddingbook`
ADD COLUMN IF NOT EXISTS `pay_band_sms_sent` TINYINT(3) DEFAULT NULL AFTER `organizer_id`;

-- 1b-2. tblusers - staff mobile number (nullable, optional)
--     Lets security alerts (login lockout SMS) reach staff accounts too.
--     Couples already have a phone via tblaccounts_detail; staff had none.
ALTER TABLE `tblusers`
ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL AFTER `address`;

-- 1c. tbl_installment_plans - opt-in month-by-month payment plans
--     A couple spreads the package balance over N monthly installments; the
--     overview nudge then tracks their pace against THEIR plan instead of a
--     generic suggestion. One active plan per booking; deleting the plan
--     returns the nudge to the generic deadline bands.
CREATE TABLE IF NOT EXISTS `tbl_installment_plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL UNIQUE,
  `months` TINYINT(3) NOT NULL,
  `monthly_amount` DECIMAL(12,2) NOT NULL,
  `starting_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'amount already paid when the plan was created — progress counts only payments after this',
  `started_at` DATE NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. tbl_liquidation - M-Pesa Payment Tracking
ALTER TABLE `tbl_liquidation`
ADD COLUMN IF NOT EXISTS `mpesa_code` VARCHAR(20) DEFAULT NULL AFTER `credit`;

-- 3. tblweddingcategories - Kenyan Wedding Packages
INSERT INTO `tblweddingcategories` (`wedding_type`, `price`, `preview_image`) VALUES
('Traditional Ruracio/Dowry', 150000, 'default.jpg'),
('Church Wedding', 250000, 'default.jpg'),
('Civil Registration', 50000, 'default.jpg'),
('Reception Package', 350000, 'default.jpg'),
('After-Party Celebration', 100000, 'default.jpg');

-- 3b. tblnotifications - planner in-app alerts (couple M-Pesa deposits etc.)
CREATE TABLE IF NOT EXISTS `tblnotifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `booking_id` INT NOT NULL DEFAULT 0,
  `message` VARCHAR(255) NOT NULL,
  `link` VARCHAR(255) NOT NULL DEFAULT '',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. tbl_features - Kenyan Features
INSERT INTO `tbl_features` (`category_id`, `title`, `description`) VALUES
(1, 'Nyama Choma & Pilau Catering', 'Traditional Kenyan feast with nyama choma, pilau, and ugali'),
(1, 'Bridal Train Outfits', 'Kitenge and Maasai Shuka outfits for bridal party'),
(1, 'Upcountry Guest Transport', 'Matatu/Bus transport and accommodation for upcountry guests'),
(1, 'M-Pesa Payment Integration', 'Paybill and Till Number payment options'),
(1, 'Professional MC & DJ', 'Experienced Kenyan MC and DJ with sound system'),
(2, 'Church Decor & Flowers', 'Elegant church decoration with fresh flowers'),
(2, 'Bridal Car', 'Luxury car for bride and groom'),
(2, 'Wedding Cake', 'Three-tier customized wedding cake'),
(2, 'Photography & Videography', 'Professional photo and video coverage'),
(3, 'Marriage Certificate', 'Official marriage certificate processing'),
(3, 'Witness Arrangement', 'Witnesses for civil registration'),
(4, 'Venue Hire', 'Garden or hotel venue rental'),
(4, 'Catering Service', 'Full buffet service with Kenyan cuisine'),
(4, 'MC & Entertainment', 'Professional MC, DJ, and live band'),
(4, 'Tents & Chairs', 'Decorative tents and seating for guests'),
(5, 'DJ & Sound System', 'Professional DJ with full sound system'),
(5, 'Drinks & Beverages', 'Full bar and soft drinks service'),
(5, 'Late Night Snacks', 'Chips, smokies, and refreshments');

-- 5. tbl_liquidation - unique M-Pesa confirmation codes
--    (idempotent guard; the code in budget_add.php stores NULL for non-M-Pesa
--     rows, so a UNIQUE key never collides on empty codes)
DELIMITER //
CREATE PROCEDURE wpms21_ensure_mpesa_unique()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN END;
    UPDATE `tbl_liquidation` SET `mpesa_code` = NULL WHERE `mpesa_code` = '';
    ALTER TABLE `tbl_liquidation` ADD UNIQUE KEY `uq_mpesa_code` (`mpesa_code`);
END //
DELIMITER ;
CALL wpms21_ensure_mpesa_unique();
DROP PROCEDURE IF EXISTS wpms21_ensure_mpesa_unique;

-- 6. tbl_mail_logs - outbound email leg for staff notifications
--    Mirrors tblsms_logs: every Notification::create() writes one row here so
--    the push is never lost, and a transport (PHP mail(), opt-in via
--    WPMS_MAIL_ENABLED=1) flips it pending -> sent/failed. Without a
--    transport (dev/demo default) the row stays 'pending' with the reason.
CREATE TABLE IF NOT EXISTS `tbl_mail_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT NOT NULL,
  `booking_id` INT NOT NULL DEFAULT 0,
  `to_email` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `status` ENUM('pending','sent','failed','abandoned') NOT NULL DEFAULT 'pending',
  `note` VARCHAR(255) DEFAULT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_user_status` (`user_id`, `status`),
  KEY `idx_mail_notification` (`notification_id`),
  KEY `idx_mail_retry` (`status`, `attempts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset tokens (client forgot-password flow).
-- Tokens are stored SHA-256 hashed; one live token per account; 60-min TTL.
CREATE TABLE IF NOT EXISTS `tbl_password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_hash` (`token_hash`),
  KEY `idx_reset_user` (`user_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kenyan wedding planning checklist. One row per milestone per booking;
-- seeded from the Kenyan template in Checklist::ensure_seeded() on first
-- open. due_offset_days = days BEFORE the wedding the item should be done
-- (the due date is wedding_date - offset; never recomputed if the wedding
-- moves — offsets stay stable and the view recomputes).
CREATE TABLE IF NOT EXISTS `tblchecklist` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL,
  `phase` VARCHAR(60) NOT NULL DEFAULT '',
  `title` VARCHAR(190) NOT NULL,
  `due_offset_days` INT NOT NULL DEFAULT 0,
  `position` INT NOT NULL DEFAULT 0,
  `is_done` TINYINT(1) NOT NULL DEFAULT 0,
  `done_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_checklist_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Couple wedding websites — the shareable public page (site.php?slug=...).
-- One per booking; slug generated from the couple's names and stable once
-- created. is_published is the only gate the public page checks. Created
-- and edited by the couple in admin/client_website.php.
CREATE TABLE IF NOT EXISTS `tblwedding_websites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL UNIQUE,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `title` VARCHAR(200) NOT NULL,
  `story` TEXT,
  `venue` VARCHAR(200) NOT NULL,
  `venue_address` TEXT,
  `wedding_date` DATE NOT NULL,
  `cover_image` VARCHAR(255) DEFAULT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `views` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
