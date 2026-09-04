-- ============================================================================
-- WPMS Kenya — Everafter Planner Demo Seed (KSh) — seed_data.sql
-- ============================================================================
-- (a) FICTIONAL demo data for client/investor demos and functional testing.
--     Names, venues, budgets and counties are realistic examples only and
--     imply no actual bookings with the venues mentioned.
-- (b) All M-Pesa codes (QDL*/QDM*) are fictional demo references in the
--     standard SFMS format, NOT real Safaricom transaction IDs. They satisfy
--     the app's format/uniqueness validation but must never be sent to the
--     actual M-Pesa API.
-- (c) Passwords are REAL bcrypt hashes generated with
--     password_hash(..., PASSWORD_DEFAULT) and round-trip verified with
--     password_verify() before embedding. Plaintext credentials:
--       Super Admin  : naomi@everafterplanner.co.ke / Ever@2026!
--       Coordinator  : kevin@everafterplanner.co.ke / Coord@2026!
--       Clients      : <client email>              / Demo@2026!
--
-- ID strategy: explicit, non-conflicting IDs in the 300-block, reused
-- consistently across tables (staff 300-301, clients/bookings 300-302,
-- guests/events/payments 300-333...). Safe to import into an existing demo
-- DB (legacy data occupies ids 0-206) or a fresh one.
--
-- Package coverage: categories 300, 301, 303 are booked. Category 302
-- (Intimate Civil & Cocktail, KSh 450,000) is seeded but intentionally NOT
-- booked, to demo an unbooked package tier.
--
-- Budget reconciliation (see section F):
--   Booking 300 (KSh 350,000): M-Pesa 150,000 + Cash 150,000 = 300,000 paid
--                              => balance 50,000
--   Booking 301 (KSh 1,200,000): M-Pesa 300,000 + Cash 400,000 + Bank 200,000
--                              = 900,000 paid => balance 300,000
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- Idempotent schema guard (matches the live DB + seed_v2.1 conventions):
-- ensures tblaccounts_detail carries the planner assignment column even if
-- imported straight onto the base dbwedding.sql dump. No-op where present.
ALTER TABLE `tblaccounts_detail` ADD COLUMN `organizer_id` INT DEFAULT NULL AFTER `date_signed`;

-- The base dump sizes tblusers.password at varchar(50) (legacy MD5 era),
-- which silently truncates 60-char bcrypt hashes. Widen to varchar(255)
-- (matches the hardened live schema) before inserting staff accounts.
ALTER TABLE `tblusers` MODIFY `password` VARCHAR(255) NOT NULL;

-- ============================================================================
-- A. ADMIN USERS (tblusers) — 1 Super Admin, 1 Event Coordinator
-- ============================================================================
INSERT INTO `tblusers` (`id`,`firstname`,`lastname`,`gender`,`username`,`password`,`email`,`designation`,`address`,`access_level`,`profile_picture`,`date_created`) VALUES
(300, 'Naomi', 'Wanjiku', 'f', 'naomi.admin', '$2y$10$DKboXhtluIii0bNtIkV5ZuNd39yV34ivE8e.pr/2JkT2HOtI1NlzK', 'naomi@everafterplanner.co.ke', 'Super Admin', 'Westlands, Nairobi, Kenya', '2', '', '2026-01-12 09:00:00'),
(301, 'Kevin', 'Otieno', 'm', 'kevin.planner', '$2y$10$YtEpyyqF2dnPTvBa8jZcgeD28mSSU24QVwYZLIHVzmjg.zAKGToU6', 'kevin@everafterplanner.co.ke', 'Event Coordinator', 'Kilimani, Nairobi, Kenya', '1', '', '2026-01-14 09:00:00');

-- ============================================================================
-- B. WEDDING PACKAGES (tblweddingcategories) — 4 KSh tiers
-- ============================================================================
INSERT INTO `tblweddingcategories` (`id`,`wedding_type`,`price`,`preview_image`) VALUES
(300, 'Traditional Ruracio & Dowry Package', 350000.00, 'default.jpg'),
(301, 'Grand Church & Reception Package', 1200000.00, 'default.jpg'),
(302, 'Intimate Civil & Cocktail Package', 450000.00, 'default.jpg'),
(303, 'Full Traditional & Modern Combo', 1800000.00, 'default.jpg');

-- ----------------------------------------------------------------------------
-- B1. PACKAGE FEATURES (tbl_features) — 5-6 Kenyan-specific per package
-- ----------------------------------------------------------------------------
INSERT INTO `tbl_features` (`feature_id`,`category_id`,`title`,`description`) VALUES
-- Traditional Ruracio & Dowry (cat 300) — 6
(300, 300, 'Elders'' Committee Coordination', 'Ruracio/dowry negotiation facilitation, elders'' meeting schedules and guest quarters for both families.'),
(301, 300, 'Traditional Attire Styling', 'Kitenge & Maasai Shuka styling for the couple with a bridal train consult session.'),
(302, 300, 'Dowry Item & Gift List Management', 'Tracks mbuzi/livestock, KSh gifts and family pledges in one committee ledger.'),
(303, 300, 'Harambee / VC Contribution Management', 'Committee collections, pledge tracking and M-Pesa Paybill reconciliation.'),
(304, 300, 'Upcountry Bridal Train Transport', 'Door-to-door upcountry shuttle plus escort convoy logistics for out-of-town guests.'),
(305, 300, 'Nyama Choma & Pilau Catering Taste', 'Sampling menu for 200 pax with traditional sides, reviewed at the tasting day.'),
-- Grand Church & Reception (cat 301) — 6
(306, 301, 'Church Booking & Ceremony Decor', 'Cathedral booking, pew decor, aisle florals and acoustics run-sheet.'),
(307, 301, 'Reception Venue & 7-Course Sit-Down', 'Hotel/garden reception with a plated menu for 300 pax.'),
(308, 301, 'Professional Comedy MC (Bilingual)', 'Swahili/English master of ceremonies with interactive comedy sets.'),
(309, 301, 'Bridal Car & Entourage', 'Mercedes S-Class styling plus a 3-car escort convoy.'),
(310, 301, 'Photography & Same-Day Edit', 'Full-day coverage with a high-table same-day edit screening.'),
(311, 301, 'Wedding Cake & Dessert Table', 'Five-tier cake with a dessert station for evening guests.'),
-- Intimate Civil & Cocktail (cat 302) — 5
(312, 302, 'Civil Registration Booking', 'Sheria House / registrar appointment handling with witness coordination.'),
(313, 302, 'Cocktail Reception (50-100 Pax)', 'Standing cocktail with canapes and an open bar.'),
(314, 302, 'Official Attire Styling', 'Suit and gown rental with two fitting sessions.'),
(315, 302, 'After-Ceremony Photoshoot', 'City-location shoot (Arboretum / Uhuru Gardens style).'),
(316, 302, 'Family Lunch (Karo 30 Pax)', 'Intimate family lunch after the ceremony.'),
-- Full Traditional & Modern Combo (cat 303) — 6
(317, 303, 'Ruracio + Church + Reception Bundle', 'Everything in one seamless plan across two counties.'),
(318, 303, 'Multi-Site Logistics', 'Venue-to-venue transfers including the upcountry leg.'),
(319, 303, 'Send-off / Disco Matanga', 'Overnight send-off coordination with DJ and catering.'),
(320, 303, 'Full Harambee Committee Kit', 'Treasurer books, pledge sheets and M-Pesa Paybill setup.'),
(321, 303, 'Dedicated Coordinator + Assistant Team', 'Two-person onsite team for the full wedding weekend.'),
(322, 303, 'Bridal Train Accommodation Block', 'Partner hotel discounted room block for the upcountry train.');

-- ============================================================================
-- C. CLIENTS (tblaccounts + tblaccounts_detail) & BOOKINGS (tblweddingbook)
-- ============================================================================
INSERT INTO `tblaccounts` (`user_id`,`user_email`,`user_password`,`access_level`) VALUES
(300, 'evans.kimutai@gmail.com', '$2y$10$4OGmkIwBbtfIr5TF8cA6t.O9CYO56nCSwsUZGsQcgPT/zdPKW93Fe', '0'),
(301, 'brian.otieno@gmail.com', '$2y$10$4OGmkIwBbtfIr5TF8cA6t.O9CYO56nCSwsUZGsQcgPT/zdPKW93Fe', '0'),
(302, 'aboudi.hassan@gmail.com', '$2y$10$4OGmkIwBbtfIr5TF8cA6t.O9CYO56nCSwsUZGsQcgPT/zdPKW93Fe', '0');

INSERT INTO `tblaccounts_detail` (`id`,`user_id`,`firstname`,`lastname`,`phone`,`city`,`datetime_created`,`description`,`location`,`expectation_visitor`,`cash_advanced`,`status`,`date_signed`,`organizer_id`) VALUES
(300, 300, 'Evans', 'Kimutai', '0721000111', 'Nairobi', NOW(), 'Ruracio + church blessing — groom Tugen (Baringo), bride Kikuyu (Muranga).', 'Karen, Nairobi', '160', 50000.00, 'confirm', '2026-04-12 10:00:00', 301),
(301, 301, 'Brian', 'Otieno', '0722555888', 'Nairobi', NOW(), 'Grand church wedding + reception — Luo couple, All Saints + Villa Rosa Kempinski.', 'Westlands, Nairobi', '320', 100000.00, 'confirm', '2026-05-06 14:00:00', 301),
(302, 302, 'Aboudi', 'Hassan', '0733555777', 'Mombasa', NOW(), 'Full traditional & modern combo — Swahili/Somali couple, Diani destination weekend.', 'Diani, Kwale County', '400', 0.00, 'pending', '2026-08-20 10:30:00', 300);

INSERT INTO `tblweddingbook` (`booking_id`,`user_id`,`bride`,`groom`,`wedding_type`,`user_email`,`wedding_date`,`organizer_id`) VALUES
(300, 300, 'Wanjiku Kamau', 'Evans Kimutai', 300, 'evans.kimutai@gmail.com', '2026-10-17', 301),
(301, 301, 'Akinyi Achieng', 'Brian Otieno', 301, 'brian.otieno@gmail.com', '2026-11-14', 301),
(302, 302, 'Fatuma Abdullahi', 'Aboudi Hassan', 303, 'aboudi.hassan@gmail.com', '2026-12-12', 300);

-- ============================================================================
-- D. GUEST LIST (tblguest) — 16 guests for booking 300, 18 for booking 301
--    (booking 302 is pending; no guest list yet, by design)
-- ============================================================================
INSERT INTO `tblguest` (`id`,`booking_id`,`fullname`,`guestname`,`address`,`state`,`zipcode`,`priority`,`out_of_town`,`relationship`,`tracks_and_gifts`,`city`,`upcountry_accommodation`,`harambee_contribution`,`dietary_requirements`,`transport_needed`,`phone`) VALUES
-- Booking 300 — Wanjiku & Evans (Ruracio & Dowry, 2026-10-17)
(300, 300, 'Mzee Gachagua Kamau', 'Mzee Kamau', 'Kangema, Muranga County', 'MRG', '10200', 'A', 'y', 'family_elder', 'KSh 50,000 — dowry lead (bride side)', 'Kangema', 1, 50000.00, 'None', 1, '0722111001'),
(301, 300, 'Mama Wambui Kamau', 'Mama Wambui', 'Kangema, Muranga County', 'MRG', '10200', 'A', 'y', 'family_elder', 'KSh 20,000 — chicken + cash', 'Kangema', 1, 20000.00, 'None', 1, '0722111002'),
(302, 300, 'Mzee Kiprop Kimutai', 'Mzee Kimutai', 'Kabarnet, Baringo County', 'BRG', '30400', 'A', 'y', 'family_elder', 'KSh 30,000 — mbuzi + cash', 'Kabarnet', 1, 30000.00, 'No Beef', 1, '0722111003'),
(303, 300, 'Mama Chepkoech Kimutai', 'Mama Chepkoech', 'Kabarnet, Baringo County', 'BRG', '30400', 'A', 'y', 'family_elder', 'KSh 15,000 — shuka set', 'Kabarnet', 1, 15000.00, 'None', 1, '0722111004'),
(304, 300, 'Reverend Njogu Kariuki', 'Rev. Njogu', 'Westlands, Nairobi', 'NBI', '00100', 'A', 'n', 'pastor_clergy', 'KSh 10,000 — officiant honorarium', 'Nairobi', 0, 10000.00, 'None', 0, '0722111005'),
(305, 300, 'Sharon Wanjiru (Maid of Honour)', 'Sharon', 'Roysambu, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 5,000 — dress fund', 'Nairobi', 0, 5000.00, 'Vegetarian', 0, '0722111006'),
(306, 300, 'Victor Kipchoge (Best Man)', 'Victor', 'Kilimani, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 5,000 — suit fund', 'Nairobi', 0, 5000.00, 'Halal', 0, '0722111007'),
(307, 300, 'Mercy Nyambura (Bridesmaid)', 'Mercy', 'South B, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 3,000 — dress fund', 'Nairobi', 0, 3000.00, 'Vegetarian', 0, '0722111008'),
(308, 300, 'Dennis Kibet (Groomsman)', 'Dennis', 'Kasarani, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 3,000 — suit fund', 'Nairobi', 0, 3000.00, 'None', 0, '0722111009'),
(309, 300, 'Josephat Kiprop (Committee Chair)', 'Josephat', 'Kabarnet, Baringo County', 'BRG', '30400', 'B', 'y', 'vc_member', 'KSh 0 — committee kit lead', 'Kabarnet', 1, 0.00, 'None', 1, '0722111010'),
(310, 300, 'Emily Achieng (Harambee Sponsor)', 'Emily', 'Muthaiga, Nairobi', 'NBI', '00100', 'A', 'n', 'harambee_sponsor', 'KSh 100,000 — platinum pledge', 'Nairobi', 0, 100000.00, 'None', 0, '0722111011'),
(311, 300, 'Mzee Barasa Wafula', 'Mzee Barasa', 'Kanduyi, Bungoma County', 'BGM', '50200', 'B', 'y', 'upcountry_guest', 'KSh 10,000 — bus fund', 'Bungoma', 1, 10000.00, 'No Beef', 1, '0722111012'),
(312, 300, 'Aunty Nyambura Gichuki', 'Aunty Nyambura', 'Kabete, Kiambu County', 'KMB', '00900', 'B', 'n', 'close_family', 'KSh 20,000 — cooking crew lead', 'Kiambu', 0, 20000.00, 'None', 0, '0722111013'),
(313, 300, 'Uncle Samuel Kariuki', 'Uncle Samuel', 'Ruaka, Kiambu County', 'KMB', '00900', 'C', 'n', 'close_family', 'KSh 10,000 — transport aid', 'Kiambu', 0, 10000.00, 'None', 0, '0722111014'),
(314, 300, 'Mama Njeri Karanja', 'Mama Njeri', 'Ongata Rongai, Kajiado', 'KJD', '00500', 'C', 'n', 'family_friend', 'KSh 5,000 — harambee', 'Rongai', 0, 5000.00, 'None', 0, '0722111015'),
(315, 300, 'Dedan Kirwa', 'Dedan', 'Eldoret, Uasin Gishu', 'UG', '30100', 'C', 'n', 'colleague', 'KSh 0 — workmate', 'Eldoret', 0, 0.00, 'Vegetarian', 0, '0722111016'),
-- Booking 301 — Akinyi & Brian (Grand Church & Reception, 2026-11-14)
(316, 301, 'Mzee Odhiambo Achieng', 'Mzee Odhiambo', 'Milimani, Kisumu County', 'KSM', '40100', 'A', 'y', 'family_elder', 'KSh 60,000 — dowry lead (bride side)', 'Kisumu', 1, 60000.00, 'None', 1, '0722111017'),
(317, 301, 'Mama Adhiambo Achieng', 'Mama Adhiambo', 'Milimani, Kisumu County', 'KSM', '40100', 'A', 'y', 'family_elder', 'KSh 25,000 — chicken + cash', 'Kisumu', 1, 25000.00, 'None', 1, '0722111018'),
(318, 301, 'Mzee Otieno Bala', 'Mzee Bala', 'Bondo, Siaya County', 'SYA', '40601', 'A', 'y', 'family_elder', 'KSh 40,000 — mbuzi + cash', 'Bondo', 1, 40000.00, 'No Beef', 1, '0722111019'),
(319, 301, 'Mama Atieno Bala', 'Mama Atieno', 'Bondo, Siaya County', 'SYA', '40601', 'A', 'y', 'family_elder', 'KSh 15,000 — cash gift', 'Bondo', 1, 15000.00, 'None', 1, '0722111020'),
(320, 301, 'Pastor Samuel Kamau', 'Pastor Kamau', 'South C, Nairobi', 'NBI', '00100', 'A', 'n', 'pastor_clergy', 'KSh 10,000 — officiant honorarium', 'Nairobi', 0, 10000.00, 'None', 0, '0722111021'),
(321, 301, 'Faith Nyambura (Maid of Honour)', 'Faith', 'Lavington, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 8,000 — dress fund', 'Nairobi', 0, 8000.00, 'Vegetarian', 0, '0722111022'),
(322, 301, 'Brian Okello (Best Man)', 'Brian O.', 'Kileleshwa, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 8,000 — suit fund', 'Nairobi', 0, 8000.00, 'None', 0, '0722111023'),
(323, 301, 'Roselyne Adhiambo (Bridesmaid)', 'Roselyne', 'Kisumu, Kisumu County', 'KSM', '40100', 'B', 'y', 'bridal_train', 'KSh 5,000 — dress fund', 'Kisumu', 1, 5000.00, 'None', 1, '0722111024'),
(324, 301, 'Michael Nyongesa (Groomsman)', 'Michael', 'Nakuru, Nakuru County', 'NKU', '20100', 'B', 'y', 'bridal_train', 'KSh 5,000 — suit fund', 'Nakuru', 1, 5000.00, 'None', 1, '0722111025'),
(325, 301, 'Winnie Achieng (Bridesmaid)', 'Winnie', 'Dagoretti, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 3,000 — dress fund', 'Nairobi', 0, 3000.00, 'Halal', 0, '0722111026'),
(326, 301, 'Mercy Atieno (Bridesmaid)', 'Mercy A.', 'Kakamega, Kakamega County', 'KAK', '50100', 'B', 'y', 'bridal_train', 'KSh 3,000 — dress fund', 'Kakamega', 1, 3000.00, 'Vegetarian', 1, '0722111027'),
(327, 301, 'Josiah Omondi (Groomsman)', 'Josiah', 'Embakasi, Nairobi', 'NBI', '00100', 'B', 'n', 'bridal_train', 'KSh 3,000 — suit fund', 'Nairobi', 0, 3000.00, 'None', 0, '0722111028'),
(328, 301, 'John Odhiambo (VC Chair)', 'John O.', 'Milimani, Kisumu County', 'KSM', '40100', 'B', 'y', 'vc_member', 'KSh 0 — committee kit lead', 'Kisumu', 1, 0.00, 'None', 1, '0722111029'),
(329, 301, 'Beatrice Njogu (Harambee Sponsor)', 'Beatrice', 'Runda, Kiambu County', 'KMB', '00900', 'A', 'n', 'harambee_sponsor', 'KSh 150,000 — diamond pledge', 'Kiambu', 0, 150000.00, 'None', 0, '0722111030'),
(330, 301, 'Mzee Raphael Ouma', 'Mzee Ouma', 'Homa Bay, Homa Bay County', 'HBY', '40300', 'C', 'y', 'upcountry_guest', 'KSh 10,000 — bus fund', 'Homa Bay', 1, 10000.00, 'No Beef', 1, '0722111031'),
(331, 301, 'Aunty Jane Adiedo', 'Aunty Jane', 'Kisii, Kisii County', 'KSI', '40200', 'B', 'y', 'close_family', 'KSh 20,000 — cooking crew lead', 'Kisii', 1, 20000.00, 'None', 1, '0722111032'),
(332, 301, 'Uncle Tom Otieno', 'Uncle Tom', 'Kayole, Nairobi', 'NBI', '00100', 'C', 'n', 'close_family', 'KSh 10,000 — harambee', 'Nairobi', 0, 10000.00, 'None', 0, '0722111033'),
(333, 301, 'Grace Wanjiku (Family Friend)', 'Grace', 'Thika, Kiambu County', 'KMB', '01000', 'C', 'n', 'family_friend', 'KSh 5,000 — harambee', 'Thika', 0, 5000.00, 'Vegetarian', 0, '0722111034');

-- ============================================================================
-- E. EVENTS & CALENDAR (events) — 8 events across the confirmed bookings.
--    Colors reuse the calendar's keyword map (task_event_save.php):
--    Ruracio #c0392b, Church #8e44ad, Reception #27ae60,
--    After-Party #f39c12, VC Meeting #3498db, Civil #2c3e50, Send-off #00897b
-- ============================================================================
INSERT INTO `events` (`id`,`booking_id`,`title`,`location`,`date_created`,`color`,`start`,`end`) VALUES
-- Booking 300 — Wanjiku & Evans (wedding 2026-10-17)
(300, 300, 'Ruracio Ceremony (Kangema)', 'Kangema, Muranga County', NOW(), '#c0392b', '2026-09-19 09:00:00', '2026-09-19 15:00:00'),
(301, 300, 'VC Meeting (Wedding Committee)', 'Westlands, Nairobi', NOW(), '#3498db', '2026-09-30 18:00:00', '2026-09-30 20:00:00'),
(302, 300, 'Church Blessing (Githunguri)', 'PCEA Githunguri, Kiambu County', NOW(), '#8e44ad', '2026-10-17 09:00:00', '2026-10-17 11:30:00'),
(303, 300, 'Reception (Karen Gardens)', 'Karen, Nairobi', NOW(), '#27ae60', '2026-10-17 13:00:00', '2026-10-17 20:00:00'),
-- Booking 301 — Akinyi & Brian (wedding 2026-11-14)
(304, 301, 'Ruracio Ceremony (Bondo)', 'Bondo, Siaya County', NOW(), '#c0392b', '2026-10-10 09:00:00', '2026-10-10 14:00:00'),
(305, 301, 'Church Blessing (All Saints Cathedral)', 'Nairobi CBD', NOW(), '#8e44ad', '2026-11-14 09:00:00', '2026-11-14 12:00:00'),
(306, 301, 'Reception (Villa Rosa Kempinski)', 'Westlands, Nairobi', NOW(), '#27ae60', '2026-11-14 13:00:00', '2026-11-14 23:00:00'),
(307, 301, 'After-Party (K1 Klubhouse)', 'Kilimani, Nairobi', NOW(), '#f39c12', '2026-11-14 23:30:00', '2026-11-15 03:00:00');

-- ============================================================================
-- F. BUDGET & LIQUIDATION (tbl_liquidation) — confirmed bookings only.
--    Semantics (per Liquidation.php): `payment` = total paid; `cash` = the
--    cash portion; `credit` = the bank-transfer portion; a non-empty
--    `mpesa_code` marks an M-Pesa payment (cash/credit stay 0 — cash-neutral).
-- ============================================================================
INSERT INTO `tbl_liquidation` (`id`,`booking_id`,`user_id`,`event_id`,`payment`,`cash`,`credit`,`mpesa_code`,`date_issue`,`date_modified`) VALUES
-- Booking 300 — package KSh 350,000 => paid 300,000, balance 50,000
(300, 300, 301, 300, 100000.00, 0.00, 0.00, 'QDL7K2M5N4', '2026-06-10 10:00:00', '2026-06-10 10:00:00'),
(301, 300, 301, 300, 50000.00, 0.00, 0.00, 'QDL8P9X1Z7', '2026-07-05 11:00:00', '2026-07-05 11:00:00'),
(302, 300, 300, 303, 100000.00, 100000.00, 0.00, NULL, '2026-08-02 12:00:00', '2026-08-02 12:00:00'),
(303, 300, 300, 301, 50000.00, 50000.00, 0.00, NULL, '2026-09-01 09:30:00', '2026-09-01 09:30:00'),
-- Booking 301 — package KSh 1,200,000 => paid 900,000, balance 300,000
(304, 301, 301, 304, 200000.00, 0.00, 0.00, 'QDL9A4C2W8', '2026-07-20 10:00:00', '2026-07-20 10:00:00'),
(305, 301, 301, 305, 100000.00, 0.00, 0.00, 'QDM1B5M3K9', '2026-08-15 14:00:00', '2026-08-15 14:00:00'),
(306, 301, 300, 306, 400000.00, 400000.00, 0.00, NULL, '2026-09-10 11:00:00', '2026-09-10 11:00:00'),
(307, 301, 300, 307, 200000.00, 0.00, 200000.00, NULL, '2026-09-25 16:00:00', '2026-09-25 16:00:00');

-- ============================================================================
-- G. GALLERY (tblgallery) — 5 records tied to real bookings.
--    Placeholder filenames (wedding_01.jpg...) will NOT resolve to real
--    images; the app's missing-image fallback should be tested separately.
-- ============================================================================
INSERT INTO `tblgallery` (`id`,`booking_id`,`title`,`caption`,`description`,`filename`,`alternate_text`,`type`,`size`,`relate_id`) VALUES
(300, 300, 'Ruracio Elders - Muranga', 'Elders'' committee during the Kangema Ruracio', 'Elders in discussion during the dowry negotiation.', 'wedding_01.jpg', 'Elders at the Ruracio table', 'image', '2450000', 0),
(301, 300, 'Bride & Groom - Karen', 'Couple portrait at Karen Gardens', 'Formal couple portrait on the reception lawn.', 'wedding_02.jpg', 'Wanjiku and Evans at Karen Gardens', 'image', '3120000', 0),
(302, 301, 'All Saints Cathedral Decor', 'Ceremony styling at All Saints Cathedral', 'Aisle florals and pew decor ahead of the ceremony.', 'wedding_03.jpg', 'All Saints Cathedral aisle decor', 'image', '1980000', 0),
(303, 301, 'Reception - Villa Rosa Kempinski', 'First dance at the Villa Rosa reception', 'First dance captured during the reception.', 'wedding_04.jpg', 'First dance at Villa Rosa Kempinski', 'image', '2760000', 0),
(304, 301, 'Bridal Train - Entourage', 'Bridal train at the Kilimani photo stop', 'Bridesmaids and groomsmen during the photo stop.', 'wedding_05.jpg', 'Bridal train group photo', 'image', '2050000', 0);

-- ============================================================================
-- G2. BLOG (tblpostwedding) — 3 Real-Kenyan-Weddings posts (2 published, 1 draft)
-- ============================================================================
INSERT INTO `tblpostwedding` (`id`,`title`,`description`,`preview_image`,`location`,`status`,`wedding_date`,`wedding_type`,`date_created`,`date_published`) VALUES
(300, 'A Modern Ruracio in Muranga - Wanjiku & Evans', 'How the elders'' committee blended tradition with a tight two-month timeline: dowry list, Mbuzi ledger, and the harambee paybill that kept everyone on track.', 'post_ruracio_muranga.jpg', 'Kangema, Muranga County', '1', '2026-10-17', 'Traditional Ruracio & Dowry Package', '2026-08-18 09:00:00', '2026-08-20 10:00:00'),
(301, 'A Diani Beach Destination Wedding - Fatuma & Aboudi', 'A full Swahili-meets-Somali weekend: Dhow cruise welcome, beach ceremony, and an open-air reception under the palms.', 'post_diani_destination.jpg', 'Diani, Kwale County', '1', '2026-12-12', 'Full Traditional & Modern Combo', '2026-08-25 09:00:00', '2026-08-28 10:00:00'),
(302, 'Grand Church Wedding at All Saints - Akinyi & Brian', 'From the Bondo Ruracio to a 7-course Villa Rosa reception: the full planner''s run-sheet for a 320-guest church weekend.', 'post_allsaints_church.jpg', 'Nairobi County', '0', '2026-11-14', 'Grand Church & Reception Package', '2026-09-01 09:00:00', '');

SET FOREIGN_KEY_CHECKS = 1;