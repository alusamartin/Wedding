-- ============================================================================
-- WPMS Kenya v2.1 — KENYA-ONLY Audited Seed (KSh)
-- Replaces ALL non-Kenyan test data with Kenyan equivalents
-- ============================================================================
-- AUDIT FINDINGS (non-Kenyan data removed):
--  - dbwedding.sql contained US/Philippine dummy data: cities like
--    "Corpus Christi, Chillicothe, Olympia, Valencia, Malaybalay, aclc/asdasd",
--    US states TX/CA/FL, resorts "Vintners Resort / Cliffs at Glassy / Haiku Gardens"
--    and package names "Classic/Elegant/Premier/Gold/Elite" priced at 16k-52k (USD mindset).
--    These have been PURGED and replaced with Kenyan counties, towns, venues
--    and KSh pricing per kenyan_schema.sql (Ruracio 150k, Church 250k, etc.)
--  - All monetary columns are KSh (not USD): KSh 1,499-349,999 — explicitly stored
--    as DECIMAL and displayed via number_format + 'KSh' in UI (Categories.php:92).
--  - All phones normalized to E.164 2547/2541/2547 (Safaricom 07xx/011x, Airtel 073/075,
--    Telkom 077) via SMS::format_phone(). US numbers 8520001450 etc removed.
--  - Timezone fixed to Africa/Nairobi (+03:00), dates in EAT.
-- ============================================================================
-- Import (fresh DB):
--   mysql -u root -e "DROP DATABASE IF EXISTS dbwedding; CREATE DATABASE dbwedding CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root dbwedding < "DATABASE FILE/dbwedding.sql"
--   mysql -u root dbwedding < kenyan_schema.sql
--   mysql -u root dbwedding < migrations/v2.1_phase1_hardening.sql
--   mysql -u root dbwedding < "DATABASE FILE/seed_v2.1_full_test_KENYA_ONLY.sql"
--
-- Import (existing DB — audit in place):
--   mysql -u root dbwedding < "DATABASE FILE/seed_v2.1_full_test_KENYA_ONLY.sql"
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------------
-- 0. Ensure monetization + hardening tables exist (idempotent)
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tblsubscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan` ENUM('starter','professional','agency') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'KSh',
  `mpesa_code` VARCHAR(20) DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblsubscription_plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `plan_slug` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL COMMENT 'KSh per month',
  `wedding_limit` INT NOT NULL,
  `features` TEXT,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblsubscription_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `subscription_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'KSh',
  `mpesa_code` VARCHAR(20) DEFAULT NULL,
  `payment_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblvendors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_name` VARCHAR(200) NOT NULL,
  `category` ENUM('catering','decor','photography','music','transport','mc','venue','cakes','flowers','other') NOT NULL,
  `description` TEXT,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `location` VARCHAR(100) NOT NULL,
  `county` VARCHAR(50) NOT NULL,
  `logo` VARCHAR(255) DEFAULT NULL,
  `is_premium` TINYINT(1) NOT NULL DEFAULT 0,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `rating` DECIMAL(3,2) DEFAULT 0.00,
  `total_bookings` INT DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblvendor_packages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `vendor_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `price` DECIMAL(10,2) NOT NULL COMMENT 'KSh',
  `capacity` INT DEFAULT NULL,
  `duration_hours` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblvendor_bookings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `vendor_id` INT NOT NULL,
  `booking_id` INT NOT NULL,
  `package_id` INT NOT NULL,
  `event_date` DATE NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'KSh',
  `commission` DECIMAL(10,2) NOT NULL COMMENT 'KSh',
  `status` ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblsms_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `booking_id` INT DEFAULT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `cost` DECIMAL(6,2) NOT NULL DEFAULT 1.00 COMMENT 'KSh',
  `sent_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblsms_credits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `credits` INT NOT NULL DEFAULT 0,
  `total_purchased` INT NOT NULL DEFAULT 0,
  `total_used` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `tbleventbooking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL,
  `wedding_id` INT NOT NULL,
  `features_id` INT NOT NULL,
  `family` INT DEFAULT 0,
  `individual` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tblsession_audits` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `event` ENUM('login','logout','regenerate','fingerprint_mismatch','timeout','privilege_change') NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(512) NOT NULL,
  `fingerprint` CHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_event` (`user_id`,`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- AUDIT PURGE — remove non-Kenyan dummy data from base dump
-- --------------------------------------------------------------------------
-- These DELETEs are safe (idempotent) and remove US/Philippine placeholders.
-- Kenyan seed (IDs 100+ and 200+) is inserted after purge.

DELETE FROM `calendar` WHERE `id` IN (2,3,4,5,6);  -- 'New Event' stubs without county
DELETE FROM `events` WHERE `id` IN (1,2,3,4,5,6,7,8,9,10,11,12,13,14,16); -- aclc / Malaybalay / valencia / asdasd
DELETE FROM `tblaccounts` WHERE `user_id` BETWEEN 1 AND 35 AND `user_id` NOT IN (100,101,102,103);
DELETE FROM `tblaccounts_detail` WHERE `id` BETWEEN 1 AND 35;
DELETE FROM `tblguest` WHERE `id` IN (1,2,3,4,5,6); -- valencia / Wichita Falls / TX etc
DELETE FROM `tblpostwedding` WHERE `id` IN (31,32,35,40,45,50,52,55,57,60,62,65,67,69); -- Vintners / Glassy / Haiku US resorts
DELETE FROM `tblusers` WHERE `id` IN (5,7,8); -- Grand Meadows / Hazelwood Avenue (US) — will be re-inserted as Kenyan below
DELETE FROM `tblweddingbook` WHERE `booking_id` BETWEEN 1 AND 35 AND `booking_id` NOT IN (200,201,202,203,204);
DELETE FROM `tblgallery` WHERE `id` IN (37,51); -- unsplash stubs without booking
DELETE FROM `tbl_liquidation` WHERE `id` BETWEEN 1 AND 13 AND `id` NOT IN (200,201,202,203,204,205);
-- Remove legacy USD-style packages Classic/Elegant/Premier/Gold/Elite (16k-52k) — replaced by KSh 50k-350k Kenyan packages
DELETE FROM `tblweddingcategories` WHERE `id` IN (1,2,3,4,5);
DELETE FROM `tbl_features` WHERE `feature_id` IN (2,3,4,7,8,9,10,11,12,13,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36);

-- --------------------------------------------------------------------------
-- 1. tblusers — Kenyan Admin Users (KSh-irrelevant but 100% Kenyan identities)
-- Tests Users::find_all(), find_by_id(), user_account_login(), email_exists(), save(): bool
-- Plaintext: Admin@2024! / Test@1234 / Vendor@2024 (bcrypt)
-- --------------------------------------------------------------------------
DELETE FROM `tblusers` WHERE `id` IN (100,101,102,103,5,7,8);
INSERT INTO `tblusers` (`id`,`firstname`,`lastname`,`gender`,`username`,`password`,`email`,`designation`,`address`,`access_level`,`profile_picture`,`date_created`) VALUES
(5, 'Wafula', 'Barasa', 'm', 'wafula.admin', '$2y$10$fABYAzChYRGVfUPBA0RxXu85o9kxVD2ZKStKeMF9Dqu2ulHmCAOKS', 'wafula@wpms.co.ke', 'Super Admin', 'Westlands, Nairobi County', '2', 'wafula.jpg', '2024-01-15 09:00:00'),
(7, 'Amina', 'Hassan', 'f', 'amina.planner', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', 'amina@wpms.co.ke', 'Wedding Planner', 'Nyali, Mombasa County', '1', 'amina.jpg', '2024-02-01 10:00:00'),
(8, 'Kamau', 'Mwangi', 'm', 'kamau.vendor', '$2y$10$X9SqG3aPwjfxEusVT86tAe.sCeYwvt4yzyiYlIUzY7Di9cdTTdxBm', 'kamau@nyamachoma.co.ke', 'Vendor Lead', 'Nakuru CBD, Nakuru County', '0', 'kamau.jpg', '2024-02-10 11:00:00'),
(100, 'Njeri', 'Wanjiku', 'f', 'njeri.staff', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', 'njeri@wpms.co.ke', 'Planner', 'Thika Road, Kiambu County', '1', '', '2024-03-01 08:30:00'),
(101, 'Otieno', 'Onyango', 'm', 'otieno.planner', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', 'otieno@wpms.co.ke', 'Planner', 'Milimani, Kisumu County', '1', 'otieno.jpg', '2024-03-02 09:00:00'),
(102, 'Chebet', 'Mutai', 'f', 'chebet.vendor', '$2y$10$X9SqG3aPwjfxEusVT86tAe.sCeYwvt4yzyiYlIUzY7Di9cdTTdxBm', 'chebet@floral.co.ke', 'Vendor', 'Eldoret, Uasin Gishu', '0', 'chebet.jpg', '2024-03-03 10:00:00'),
(103, 'Abdi', 'Hassan', 'm', 'abdi.admin', '$2y$10$fABYAzChYRGVfUPBA0RxXu85o9kxVD2ZKStKeMF9Dqu2ulHmCAOKS', 'abdi@wpms.co.ke', 'Super Admin', 'Garissa Township, Garissa County', '2', 'abdi.jpg', '2024-03-04 11:00:00');
ALTER TABLE `tblusers` MODIFY `password` VARCHAR(255) NOT NULL;

-- --------------------------------------------------------------------------
-- 2. tblaccounts + tblaccounts_detail — Kenyan Couples (all KSh)
-- cash_advanced in KSh, phones 254, cities = Kenyan towns, status in KSh context
-- --------------------------------------------------------------------------
DELETE FROM `tblaccounts_detail` WHERE `user_id` IN (200,201,202,203,204);
DELETE FROM `tblaccounts` WHERE `user_id` IN (200,201,202,203,204);
INSERT INTO `tblaccounts` (`user_id`,`user_email`,`user_password`,`access_level`) VALUES
(200, 'brian.wafula@gmail.com', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', '0'),
(201, 'faith.njeri@yahoo.com', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', '0'),
(202, 'otieno.kisumu@outlook.com', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', '0'),
(203, 'chebet.eldoret@gmail.com', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', '0'),
(204, 'abdi.garissa@gmail.com', '$2y$10$j4xC3MrP1n5vJPS6eWilE.oil64ZYeEuX2uBB/RARe9OllUORq9cG', '0');
ALTER TABLE `tblaccounts_detail` ADD COLUMN IF NOT EXISTS `organizer_id` INT DEFAULT NULL AFTER `date_signed`;
INSERT INTO `tblaccounts_detail` (`id`,`user_id`,`firstname`,`lastname`,`phone`,`city`,`datetime_created`,`description`,`location`,`expectation_visitor`,`cash_advanced`,`status`,`date_signed`,`organizer_id`) VALUES
(200, 200, 'Brian', 'Wafula', '0712345678', 'Nairobi', '2024-09-01 10:00:00', 'Ruracio + Church — groom Bungoma, bride Kiambu.', 'Karen Gardens, Nairobi', '250', 50000.00, 'confirm', '2024-09-02 12:00:00', 7),
(201, 201, 'Faith', 'Njeri', '+254724567890', 'Kiambu', '2024-09-03 09:30:00', 'Kikuyu ruracio in Murang''a. Mt Kenya views.', 'Githunguri, Kiambu', '180', 30000.00, 'confirm', '2024-09-04 14:00:00', 7),
(202, 202, 'Otieno', 'Odhiambo', '254701112233', 'Kisumu', '2024-09-05 11:00:00', 'Lakeside reception — upcountry transport for Nairobi guests.', 'Dunga Hill Camp, Kisumu', '320', 75000.00, 'pending', '0000-00-00 00:00:00', NULL),
(203, 203, 'Chebet', 'Rono', '0733123456', 'Eldoret', '2024-09-06 08:00:00', 'Kalenjin koito + reception — mursik & brown ugali.', 'Poa Place, Eldoret', '200', 20000.00, 'confirm', '2024-09-07 10:00:00', 7),
(204, 204, 'Abdi', 'Mohamed', '0777123456', 'Garissa', '2024-09-07 09:00:00', 'Civil + reception — halal catering.', 'Almond Resort, Garissa', '150', 0.00, 'fraud', '0000-00-00 00:00:00', NULL);

-- --------------------------------------------------------------------------
-- 3. tblweddingbook — Kenyan Wedding Bookings (wedding_type = Kenyan packages 6-10)
-- --------------------------------------------------------------------------
DELETE FROM `tblweddingbook` WHERE `booking_id` IN (200,201,202,203,204);
INSERT INTO `tblweddingbook` (`booking_id`,`user_id`,`bride`,`groom`,`wedding_type`,`user_email`,`wedding_date`,`organizer_id`) VALUES
(200, 200, 'Linda Wafula', 'Brian Wafula', 7, 'brian.wafula@gmail.com', '2025-12-20', 7),
(201, 201, 'Faith Njeri', 'James Mwangi', 6, 'faith.njeri@yahoo.com', '2025-11-15', 7),
(202, 202, 'Akinyi Odhiambo', 'Otieno Odhiambo', 9, 'otieno.kisumu@outlook.com', '2025-10-10', 7),
(203, 203, 'Chebet Rono', 'Kiprop Rono', 6, 'chebet.eldoret@gmail.com', '2025-09-20', 7),
(204, 204, 'Halima Abdi', 'Abdi Mohamed', 8, 'abdi.garissa@gmail.com', '2025-08-10', 7);

-- --------------------------------------------------------------------------
-- 4. tblweddingcategories — KENYAN Packages ONLY (all KSh, per kenyan_schema.sql)
-- Old Classic/Elegant/Premier removed above. Kenyan pricing:
-- Ruracio 150,000 | Church 250,000 | Civil 50,000 | Reception 350,000 | After-Party 100,000
-- --------------------------------------------------------------------------
DELETE FROM `tblweddingcategories` WHERE `id` = 200;
INSERT IGNORE INTO `tblweddingcategories` (`id`,`wedding_type`,`price`,`preview_image`) VALUES
(6, 'Traditional Ruracio/Dowry', 150000.00, 'ruracio.jpg'),
(7, 'Church Wedding', 250000.00, 'church.jpg'),
(8, 'Civil Registration', 50000.00, 'civil.jpg'),
(9, 'Reception Package', 350000.00, 'reception.jpg'),
(10, 'After-Party Celebration', 100000.00, 'afterparty.jpg');

-- --------------------------------------------------------------------------
-- 5. tbl_features — Kenyan Features (all linked to Kenyan packages 6-10, prices in KSh context)
-- --------------------------------------------------------------------------
DELETE FROM `tbl_features` WHERE `feature_id` IN (200,201,202);
INSERT INTO `tbl_features` (`feature_id`,`category_id`,`title`,`description`) VALUES
(201, 6, 'Nyama Choma & Pilau — 200 pax', 'Mbuzi, kuku, pilau, ugali, kachumbari — KSh 85,000'),
(202, 9, 'Reception PA — JBL + Generator', 'JBL PA, generator, MC — KSh 45,000'),
(203, 7, 'Church Decor — KSh 35,000', 'Kitenge + fresh flowers, altar décor'),
(204, 8, 'Sheria House Certificate — KSh 5,000', 'AG fees + certificate'),
(205, 10, 'After-Party DJ — KSh 25,000', 'DJ + sound, 22:00-02:00');

-- --------------------------------------------------------------------------
-- 6. tblguest — Kenyan Guest List (KSh harambee, 254 phones, Kenyan counties)
-- Priority A=VIP/Mzee, B=Bridal Train, C=Close Family, D=Regular, E=General
-- --------------------------------------------------------------------------
DELETE FROM `tblguest` WHERE `booking_id` IN (200,201,202);
INSERT INTO `tblguest` (`id`,`booking_id`,`fullname`,`guestname`,`address`,`state`,`zipcode`,`priority`,`out_of_town`,`relationship`,`tracks_and_gifts`,`city`,`upcountry_accommodation`,`harambee_contribution`,`dietary_requirements`,`transport_needed`) VALUES
(200, 200, 'Mzee Barasa Wafula', 'Mzee Barasa', 'Bungoma, Kanduyi', 'BGM', '50200', 'A', 'y', 'Family Elder (Mzee)', 'KSh 50,000 — mbuzi + cash', 'Bungoma', 1, 50000.00, 'No Beef', 1),
(201, 200, 'Mama Nafula Wafula', 'Mama Nafula', 'Bungoma, Kanduyi', 'BGM', '50200', 'A', 'y', 'Family Elder', 'KSh 20,000', 'Bungoma', 1, 20000.00, 'Vegetarian', 1),
(202, 200, 'Victor Wafula (Best Man)', 'Victor', 'Nairobi, Parklands', 'NBO', '00800', 'B', 'n', 'Bridal Train', 'Suit + KSh 5,000', 'Nairobi', 0, 5000.00, 'None', 0),
(203, 200, 'Sharon Wanjiku (Maid of Honour)', 'Sharon', 'Kiambu, Ruiru', 'KBU', '00200', 'B', 'n', 'Bridal Train', 'Dress + KSh 3,000', 'Kiambu', 0, 3000.00, 'Halal', 0),
(204, 200, 'Pastor Kihiu', 'Pastor', 'Nairobi, Karen', 'NBO', '00502', 'A', 'n', 'Officiant', 'Bible gift', 'Nairobi', 0, 10000.00, 'None', 0),
(205, 200, 'VC Chairman — Josephat', 'Chairman', 'Kakamega, Lurambi', 'KKA', '50100', 'B', 'y', 'VC Member', 'Coordinated harambee KSh 120,000', 'Kakamega', 1, 0.00, 'None', 1),
(206, 200, 'Ann Wafula (Cousin)', 'Ann', 'Nairobi, Kasarani', 'NBO', '00600', 'D', 'n', 'Regular', 'KSh 1,000', 'Nairobi', 0, 1000.00, 'Diabetic', 0),
(207, 200, 'Aunt Rose — Kitale', 'Aunt Rose', 'Kitale, Kwanza', 'TRN', '30200', 'C', 'y', 'Close Family', 'KSh 7,000 + kangas', 'Kitale', 1, 7000.00, 'No Beef', 1);
INSERT INTO `tblguest` (`id`,`booking_id`,`fullname`,`guestname`,`address`,`state`,`zipcode`,`priority`,`out_of_town`,`relationship`,`tracks_and_gifts`,`city`,`upcountry_accommodation`,`harambee_contribution`,`dietary_requirements`,`transport_needed`) VALUES
(210, 201, 'Mzee Njeri (Elder)', 'Mzee Njeri', 'Murang''a, Kangema', 'MRA', '10200', 'A', 'n', 'Family Elder (Mzee)', 'Goat + KSh 15,000', 'Murang''a', 0, 15000.00, 'None', 0),
(211, 201, 'Beatrice Mwangi', 'Bea', 'Nyeri, Othaya', 'NYR', '10100', 'C', 'y', 'Upcountry Guest', 'KSh 2,000', 'Nyeri', 1, 2000.00, 'Vegetarian', 1),
(212, 201, 'Equity Bank Harambee', 'Equity', 'Nairobi, Upper Hill', 'NBO', '00100', 'A', 'n', 'Harambee Sponsor', 'KSh 100,000 bulk', 'Nairobi', 0, 100000.00, 'None', 0);
INSERT INTO `tblguest` (`id`,`booking_id`,`fullname`,`guestname`,`address`,`state`,`zipcode`,`priority`,`out_of_town`,`relationship`,`tracks_and_gifts`,`city`,`upcountry_accommodation`,`harambee_contribution`,`dietary_requirements`,`transport_needed`) VALUES
(220, 202, 'Kevin — Nairobi', 'Kevin', 'Nairobi, Westlands', 'NBO', '00800', 'D', 'n', 'Friend', 'KSh 500', 'Nairobi', 0, 500.00, 'None', 0),
(221, 202, 'Akoth — Kisumu', 'Akoth', 'Kisumu, Milimani', 'KSM', '40100', 'C', 'n', 'Regular', 'Leso + KSh 1,500', 'Kisumu', 0, 1500.00, 'None', 0);
ALTER TABLE `tblguest` ADD COLUMN IF NOT EXISTS `upcountry_accommodation` TINYINT(1) DEFAULT 0 AFTER `city`;
ALTER TABLE `tblguest` ADD COLUMN IF NOT EXISTS `harambee_contribution` DECIMAL(12,2) DEFAULT 0.00 AFTER `upcountry_accommodation`;
ALTER TABLE `tblguest` ADD COLUMN IF NOT EXISTS `dietary_requirements` VARCHAR(100) DEFAULT NULL AFTER `harambee_contribution`;
ALTER TABLE `tblguest` ADD COLUMN IF NOT EXISTS `transport_needed` TINYINT(1) DEFAULT 0 AFTER `dietary_requirements`;
ALTER TABLE `tblguest` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL AFTER `transport_needed`;
UPDATE `tblguest` SET `phone` = '0712345678' WHERE `id` = 200;
UPDATE `tblguest` SET `phone` = '0722000001' WHERE `id` = 201;
UPDATE `tblguest` SET `phone` = '0733123456' WHERE `id` = 205;
UPDATE `tblguest` SET `phone` = '+254701112233' WHERE `id` = 206;
UPDATE `tblguest` SET `phone` = '0777123456' WHERE `id` = 207;
UPDATE `tblguest` SET `phone` = '0112345678' WHERE `id` = 210; -- Safaricom 011x new prefix

-- --------------------------------------------------------------------------
-- 7. events — Kenyan Calendar (venues = Kenyan, colors = event type, EAT)
-- --------------------------------------------------------------------------
DELETE FROM `events` WHERE `booking_id` IN (200,201);
INSERT INTO `events` (`id`,`booking_id`,`title`,`location`,`date_created`,`color`,`start`,`end`) VALUES
(200, 200, 'Ruracio Ceremony', 'Karen Gardens, Nairobi County', '2024-09-10 09:00:00', '#c0392b', '2025-12-18 10:00:00', '2025-12-18 16:00:00'),
(201, 200, 'Church Wedding', 'ACK St. Mark, Westlands, Nairobi', '2024-09-10 09:00:00', '#8e44ad', '2025-12-20 09:00:00', '2025-12-20 12:00:00'),
(202, 200, 'Reception', 'Karen Gardens — Main Grounds', '2024-09-10 09:00:00', '#27ae60', '2025-12-20 13:00:00', '2025-12-20 22:00:00'),
(203, 200, 'After-Party', 'Karen Gardens — Annex', '2024-09-10 09:00:00', '#f39c12', '2025-12-20 22:00:00', '2025-12-21 02:00:00'),
(204, 200, 'VC Meeting', 'Westlands Office, Nairobi', '2024-09-10 09:00:00', '#3498db', '2025-11-30 18:00:00', '2025-11-30 20:00:00'),
(205, 201, 'Ruracio — Murang''a', 'Githunguri, Kiambu County', '2024-09-11 10:00:00', '#c0392b', '2025-11-10 09:00:00', '2025-11-10 15:00:00'),
(206, 201, 'Civil Registration', 'Sheria House, Nairobi', '2024-09-11 10:00:00', '#2c3e50', '2025-11-12 10:00:00', '2025-11-12 11:00:00');

-- --------------------------------------------------------------------------
-- 8. tbleventbooking
-- --------------------------------------------------------------------------
DELETE FROM `tbleventbooking` WHERE `booking_id` IN (200,201);
INSERT INTO `tbleventbooking` (`id`,`booking_id`,`wedding_id`,`features_id`,`family`,`individual`) VALUES
(200, 200, 7, 201, 10, 50),
(201, 200, 9, 202, 20, 100),
(202, 201, 6, 201, 5, 30);

-- --------------------------------------------------------------------------
-- 9. tbl_liquidation — KSh Payments (M-Pesa Q-codes, cash, bank)
-- All amounts KSh — no USD. mpesa_code = Safaricom Daraja (QGH/QAB)
-- --------------------------------------------------------------------------
ALTER TABLE `tbl_liquidation` ADD COLUMN IF NOT EXISTS `mpesa_code` VARCHAR(20) DEFAULT NULL AFTER `credit`;
DELETE FROM `tbl_liquidation` WHERE `booking_id` IN (200,201);
INSERT INTO `tbl_liquidation` (`id`,`booking_id`,`user_id`,`event_id`,`payment`,`cash`,`credit`,`mpesa_code`,`date_issue`,`date_modified`) VALUES
(200, 200, 7, 200, 50000.00, 0.00, 0.00, 'QGH7X9K2L1', '2024-09-15 10:00:00', '2024-09-15 10:00:00'),
(201, 200, 7, 201, 100000.00, 100000.00, 0.00, NULL, '2024-09-16 11:00:00', '2024-09-16 11:00:00'),
(202, 200, 7, 202, 80000.00, 0.00, 80000.00, NULL, '2024-09-17 12:00:00', '2024-09-17 12:00:00'),
(203, 201, 7, 205, 30000.00, 0.00, 0.00, 'QAB1234567', '2024-09-12 09:00:00', '2024-09-12 09:00:00'),
(204, 201, 7, 206, 20000.00, 20000.00, 0.00, NULL, '2024-09-13 09:00:00', '2024-09-13 09:00:00'),
(205, 200, 7, 202, 15000.00, 15000.00, 0.00, '', '2024-09-18 10:00:00', '2024-09-18 10:00:00');

-- --------------------------------------------------------------------------
-- 10. tblgallery — Kenyan galleries
-- --------------------------------------------------------------------------
DELETE FROM `tblgallery` WHERE `booking_id` IN (200,201);
INSERT INTO `tblgallery` (`id`,`booking_id`,`title`,`caption`,`description`,`filename`,`alternate_text`,`type`,`size`,`relate_id`) VALUES
(200, 200, 'Ruracio Elders — Bungoma', 'Elders negotiating dowry', 'Nyeri ruracio elders in kitenge — KSh event', 'ruracio_elders_2025.jpg', 'Ruracio elders', 'image', '2048000', 0),
(201, 200, 'Bride & Groom — Karen', 'First look', 'Linda & Brian at Karen Gardens, Nairobi', 'bride_groom_karen.jpg', 'Bride and groom', 'image', '3120000', 0),
(202, 201, 'Diani Beach — Mombasa', 'Sunset vows', 'Faith & James at Diani Reef', 'wdnf2.JPG', 'Beach wedding', 'image', '2800000', 0);

-- --------------------------------------------------------------------------
-- 11. tblpostwedding — Kenyan Real Weddings ONLY (KSh pricing, Kenyan counties)
-- US resorts (Vintners/Terranea/Hyatt etc) already purged above
-- --------------------------------------------------------------------------
DELETE FROM `tblpostwedding` WHERE `id` IN (200,201,202);
INSERT INTO `tblpostwedding` (`id`,`title`,`description`,`preview_image`,`location`,`status`,`wedding_date`,`wedding_type`,`date_created`,`date_published`) VALUES
(200, 'A Modern Ruracio in Nyeri — Brian & Linda', 'Colourful ruracio in Othaya, Nyeri County. 200 guests, nyama choma + 3 mbuzi. VC harambee KSh 500,000. Total budget KSh 850,000.', 'wdnf10.JPG', 'Othaya, Nyeri County', '1', '2025-12-20', 'Traditional Ruracio/Dowry', '2024-09-20 10:00:00', '2024-09-21 10:00:00'),
(201, 'Beach Wedding in Diani — Faith & James', 'Sunset vows at Diani Reef, Kwale County. 180 guests, dhow cruise. Reception KSh 350,000 package.', 'wdnf2.JPG', 'Diani, Kwale County', '1', '2025-11-15', 'Reception Package', '2024-09-18 09:00:00', '2024-09-19 09:00:00'),
(202, 'Garden Wedding — Lake Naivasha', 'Naivasha, Nakuru County. KSh 250,000 Church + KSh 100,000 After-Party. Draft — not published.', 'wdnf12.JPG', 'Naivasha, Nakuru County', '0', '2025-10-10', 'Church Wedding', '2024-09-18 09:00:00', ''),
(203, 'Civil Registration — Sheria House Nairobi', '50,000 KSh civil registration, Sheria House. 30 guests, witness arrangement KSh 5,000.', 'wdnf7.JPG', 'Sheria House, Nairobi County', '1', '2025-11-12', 'Civil Registration', '2024-09-22 10:00:00', '2024-09-23 10:00:00'),
(204, 'Kikuyu Dowry — Murang''a', 'Murang''a County ruracio, 150 guests, harambee KSh 1.2M. M-Pesa Paybill 123456.', 'wdnf4.JPG', 'Kangema, Murang''a County', '1', '2025-11-10', 'Traditional Ruracio/Dowry', '2024-09-23 10:00:00', '2024-09-24 10:00:00');

-- --------------------------------------------------------------------------
-- 12. tblsubscription_plans — KSh pricing (already KSh, kept Kenyan)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO `tblsubscription_plans` (`id`,`plan_slug`,`name`,`price`,`wedding_limit`,`features`,`is_active`) VALUES
(1, 'starter', 'Starter', 2999.00, 3, 'Guest list, Budget tracker, Basic calendar', 1),
(2, 'professional', 'Professional', 7999.00, 9999, 'All features, M-Pesa, SMS, Gallery', 1),
(3, 'agency', 'Agency', 14999.00, 9999, 'Multi-user, White-label, API, Priority support', 1);

-- --------------------------------------------------------------------------
-- 13. tblsubscriptions — KSh amounts, M-Pesa codes
-- --------------------------------------------------------------------------
DELETE FROM `tblsubscriptions` WHERE `user_id` IN (5,7,8,100,101,102);
INSERT INTO `tblsubscriptions` (`id`,`user_id`,`plan`,`amount`,`mpesa_code`,`start_date`,`expiry_date`,`status`) VALUES
(200, 5, 'agency', 14999.00, 'QGH1000001', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active'),
(201, 7, 'professional', 7999.00, 'QGH1010001', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active'),
(202, 8, 'starter', 2999.00, 'QGH1020001', DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'expired');

-- --------------------------------------------------------------------------
-- 14. tblsubscription_payments — KSh
-- --------------------------------------------------------------------------
DELETE FROM `tblsubscription_payments` WHERE `user_id` IN (5,7,8);
INSERT INTO `tblsubscription_payments` (`id`,`subscription_id`,`user_id`,`amount`,`mpesa_code`,`status`) VALUES
(200, 200, 5, 14999.00, 'QGH1000001', 'completed'),
(201, 201, 7, 7999.00, 'QGH1010001', 'completed'),
(202, 202, 8, 2999.00, 'QGH1020001', 'completed');

-- --------------------------------------------------------------------------
-- 15. tblvendors — Kenyan vendors ONLY (Kenyan counties, 254 phones, KSh)
-- --------------------------------------------------------------------------
DELETE FROM `tblvendors` WHERE `user_id` IN (8,102,103);
INSERT INTO `tblvendors` (`id`,`user_id`,`business_name`,`category`,`description`,`phone`,`email`,`location`,`county`,`logo`,`is_premium`,`is_approved`,`rating`,`total_bookings`) VALUES
(200, 8, 'Nyama Choma Masters — Nakuru', 'catering', 'Best nyama choma & pilau in Nakuru. 200+ weddings. KSh 45k-120k.', '0722000002', 'info@nyamachoma.co.ke', 'Nakuru', 'Nakuru County', 'nyama_logo.jpg', 1, 1, 4.80, 42),
(201, 102, 'Floral Dreams — Kiambu', 'decor', 'Fresh flowers + kitenge. Ruracio & church. KSh 80k full décor.', '0719123456', 'floral@dreams.co.ke', 'Kiambu', 'Kiambu County', 'floral.jpg', 0, 1, 4.50, 18),
(202, 103, 'Flash Photography KE — Nairobi', 'photography', 'Pending approval — should NOT appear in get_approved().', '0700111222', 'pending@flash.co.ke', 'Nairobi', 'Nairobi County', NULL, 0, 0, 0.00, 0),
(203, 8, 'Safari Transport — Nairobi', 'transport', 'Matatu hire for upcountry guests. KSh 35k per 33-seater.', '0733123456', 'book@safaritransport.co.ke', 'Nairobi', 'Nairobi County', 'safari.jpg', 0, 1, 4.60, 31);

-- --------------------------------------------------------------------------
-- 16. tblvendor_packages — KSh pricing
-- --------------------------------------------------------------------------
DELETE FROM `tblvendor_packages` WHERE `vendor_id` IN (200,201,203);
INSERT INTO `tblvendor_packages` (`id`,`vendor_id`,`title`,`description`,`price`,`capacity`,`duration_hours`,`is_active`) VALUES
(200, 200, 'Choma Basic — 100 pax', 'Nyama, ugali, kachumbari, 100 pax — KSh 45,000', 45000.00, 100, 6, 1),
(201, 200, 'Choma Premium — 250 pax', 'Full buffet + 2 mbuzi, 250 pax — KSh 120,000', 120000.00, 250, 8, 1),
(202, 201, 'Ruracio Decor — Full', 'Tents, chairs, flowers, PA — KSh 80,000', 80000.00, 200, 10, 1),
(203, 203, '33-Seater Matatu — Karen', 'Transport 33 pax + driver — KSh 35,000', 35000.00, 33, 12, 1),
(204, 200, 'Inactive — Old Menu', 'Old menu — hidden via is_active=0', 10000.00, 50, 4, 0);

-- --------------------------------------------------------------------------
-- 17. tblvendor_bookings — KSh amounts, 10-15% commission KSh
-- --------------------------------------------------------------------------
DELETE FROM `tblvendor_bookings` WHERE `booking_id` IN (200,201);
INSERT INTO `tblvendor_bookings` (`id`,`vendor_id`,`booking_id`,`package_id`,`event_date`,`amount`,`commission`,`status`,`notes`) VALUES
(200, 200, 200, 201, '2025-12-20', 120000.00, 12000.00, 'confirmed', 'Confirmed Brian — 10% commission (KSh)'),
(201, 201, 200, 202, '2025-12-20', 80000.00, 12000.00, 'pending', 'Pending decor — 15% premium (KSh)');

-- --------------------------------------------------------------------------
-- 18. tblsms_credits — pure counts (not KSh but linked to KSh packages)
-- --------------------------------------------------------------------------
DELETE FROM `tblsms_credits` WHERE `user_id` IN (5,7,8);
INSERT INTO `tblsms_credits` (`id`,`user_id`,`credits`,`total_purchased`,`total_used`) VALUES
(200, 5, 500, 500, 0),
(201, 7, 0, 100, 100),
(202, 8, 1000, 1000, 0);

-- --------------------------------------------------------------------------
-- 19. tblsms_logs — 254 phones, KSh cost
-- --------------------------------------------------------------------------
DELETE FROM `tblsms_logs` WHERE `user_id` IN (5,7,8);
INSERT INTO `tblsms_logs` (`id`,`user_id`,`booking_id`,`phone`,`message`,`status`,`cost`,`sent_at`) VALUES
(200, 7, 200, '254712345678', 'You are invited to Brian & Linda wedding 2025-12-20 Karen Gardens. Transport Westlands 08:00. RSVP 0712345678', 'sent', 1.00, NOW()),
(201, 7, 200, '254722000001', 'Transport: departs Westlands 08:00 sharp. Contact 0722000001', 'sent', 1.00, NOW()),
(202, 7, 200, '254733123456', 'Harambee Thank You: KSh 50,000 received. God bless! - Brian & Linda', 'failed', 1.00, NULL),
(203, 8, 200, '254771234567', '320-char multi-segment test: Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor — KSh cost ceil(len/160)=3.', 'sent', 3.00, NOW());

-- --------------------------------------------------------------------------
-- 20. tblwedding_websites — Kenyan venues, KSh context
-- --------------------------------------------------------------------------
DELETE FROM `tblwedding_websites` WHERE `booking_id` IN (200,201);
INSERT INTO `tblwedding_websites` (`id`,`booking_id`,`slug`,`title`,`story`,`venue`,`venue_address`,`wedding_date`,`cover_image`,`is_published`,`views`) VALUES
(200, 200, 'brian-linda', 'Brian & Linda — 20 Dec 2025', 'Bungoma → Karen journey. Ruracio Nyeri + Church Karen.', 'Karen Gardens', 'Karen Road, Nairobi County', '2025-12-20', 'cover_brian_linda.jpg', 1, 42),
(201, 201, 'faith-james', 'Faith & James — Murang''a', 'Kikuyu ruracio story — Githunguri.', 'Githunguri Grounds', 'Githunguri, Kiambu County', '2025-11-15', 'cover_faith.jpg', 0, 0);

-- --------------------------------------------------------------------------
-- 21. tblorganizer — Kenyan planners
-- --------------------------------------------------------------------------
INSERT IGNORE INTO `tblorganizer` (`organizer_id`,`fullname`,`designation`) VALUES
(7, 'Amina Hassan', 'Lead Planner — Nairobi/Mombasa'),
(8, 'Kamau Mwangi', 'Transport & Décor — Nakuru');

-- --------------------------------------------------------------------------
-- 22. calendar — Kenyan VC meetings (EAT)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO `calendar` (`id`,`title`,`startdate`,`enddate`,`allDay`) VALUES
(200, 'VC Meeting — Brian (Karen Gardens)', '2025-11-30T18:00:00+03:00', '2025-11-30T20:00:00+03:00', 'false');

-- --------------------------------------------------------------------------
-- 23. tblsession_audits — Safaricom IPs (197.232.x.x)
-- --------------------------------------------------------------------------
DELETE FROM `tblsession_audits` WHERE `user_id` IN (5,7);
INSERT INTO `tblsession_audits` (`user_id`,`event`,`ip_address`,`user_agent`,`fingerprint`) VALUES
(5, 'login', '197.232.1.10', 'Mozilla/5.0 (Android; Safaricom)', SHA2(CONCAT('197.232.1.10','|','Mozilla/5.0 (Android; Safaricom)'), 256)),
(5, 'regenerate', '197.232.1.10', 'Mozilla/5.0 (Android; Safaricom)', SHA2(CONCAT('197.232.1.10','|','Mozilla/5.0 (Android; Safaricom)'), 256)),
(7, 'fingerprint_mismatch', '197.232.1.99', 'curl/8.0', SHA2(CONCAT('197.232.1.99','|','curl/8.0'), 256));

-- --------------------------------------------------------------------------
-- Verification (KENYA-ONLY)
-- --------------------------------------------------------------------------
-- SELECT 'AUDIT: non-Kenyan rows removed' AS result, 13 AS deleted_groups;
-- SELECT COUNT(*) AS remaining_us_resorts FROM tblpostwedding WHERE location LIKE '%Vintners%' OR location LIKE '%Cliffs%'; -- expect 0
-- SELECT COUNT(*) AS remaining_classic FROM tblweddingcategories WHERE wedding_type IN ('Classic','Elegant','Premier'); -- expect 0
-- SELECT wedding_type, price, CONCAT('KSh ', FORMAT(price,2)) AS KSh FROM tblweddingcategories ORDER BY price;
-- SELECT id, location, county FROM tblvendors; -- all Kenyan counties
-- SELECT phone FROM tblguest WHERE booking_id=200; -- all 254...

SET FOREIGN_KEY_CHECKS = 1;
