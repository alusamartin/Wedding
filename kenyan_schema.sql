-- 1. tblguest - Kenyan Guest Management
ALTER TABLE `tblguest` 
ADD COLUMN `upcountry_accommodation` TINYINT(1) DEFAULT 0 AFTER `city`,
ADD COLUMN `harambee_contribution` DECIMAL(12,2) DEFAULT 0.00 AFTER `upcountry_accommodation`,
ADD COLUMN `dietary_requirements` VARCHAR(100) DEFAULT NULL AFTER `harambee_contribution`,
ADD COLUMN `transport_needed` TINYINT(1) DEFAULT 0 AFTER `dietary_requirements`;

-- 2. tbl_liquidation - M-Pesa Payment Tracking
ALTER TABLE `tbl_liquidation` 
ADD COLUMN `mpesa_code` VARCHAR(20) DEFAULT NULL AFTER `credit`;

-- 3. tblweddingcategories - Kenyan Wedding Packages
INSERT INTO `tblweddingcategories` (`wedding_type`, `price`, `preview_image`) VALUES
('Traditional Ruracio/Dowry', 150000, 'default.jpg'),
('Church Wedding', 250000, 'default.jpg'),
('Civil Registration', 50000, 'default.jpg'),
('Reception Package', 350000, 'default.jpg'),
('After-Party Celebration', 100000, 'default.jpg');

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
