-- ======================================================================
-- WPMS Kenya v2.27 — What a vendor actually sells
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.27_vendor_services.sql
--
-- Why this exists:
--   `tblvendors.category` says a business is a caterer; `tblvendor_packages`
--   says "Full catering — KSh 180,000" in free text with no unit. So two
--   caterers' packages cannot be compared, "per head or flat?" is a phone call,
--   and the planner's question — "what fits this couple's budget and taste?" —
--   has nothing structured behind it. A vendor's real breadth (a decorator who
--   also does flowers) has nowhere to live either.
--
-- What it adds:
--   1. `tblservice_catalog` — the platform's own vocabulary, one row per known
--      service per category, each with a MANDATORY unit (per guest, flat, per
--      day, …). This is what stops a second generation of synonyms: the vendor
--      picks "Buffet, per guest" instead of inventing "buffet service".
--   2. `tblvendor_services` — what THIS business sells. `catalog_id` set means
--      it came from the vocabulary; NULL means the vendor typed their own
--      service. Both are stored in the same shape, with the same unit and
--      price, so the comparison works on either.
--
-- What it deliberately does NOT do:
--   - It does not touch `tblvendor_packages`. A package is a BUNDLE of
--     services, not a service: copying each package row into this table would
--     list the same offering twice (once as a bundle, once as a part) and make
--     every vendor's list lie about its breadth. Packages keep selling exactly
--     as they do today; assembling them from services is the next phase, on
--     top of this table.
--   - It does not backfill. A package's free text cannot be decomposed into
--     per-guest / per-day lines without guessing, and a guessed unit is worse
--     than an empty list. `tblvendor_services` starts empty for every vendor,
--     and the My Services page invites them to fill it.
--   - It stores no currency: the platform quotes in KSh throughout
--     (format_ksh()), and a per-row currency would invite a mixed-currency
--     price list nobody reconciles.
--
-- Verified against code after migration:
--   SHOW TABLES LIKE 'tblvendor_services';
--   SELECT COUNT(*) FROM tblservice_catalog;                 -- 39 seeded
--   vendor/bin/phpunit tests/VendorServiceTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- The vocabulary. Admin-managed; vendors choose from it but cannot edit it.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblservice_catalog` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `vendor_category` enum('catering','decor','photography','music','transport','mc','venue','cakes','flowers','other') NOT NULL COMMENT 'named `vendor_category`, not `category`: a second bare `category` column would make $vendor->category ambiguous to the enum sweep, which then stops judging tblvendors.category at all',
  `name`            varchar(120) NOT NULL,
  `slug`            varchar(120) NOT NULL COMMENT 'stable key, so a seed re-run updates instead of duplicating',
  `default_unit`    enum('flat','per_guest','per_day','per_hour','per_item','per_km','per_staff') NOT NULL DEFAULT 'flat' COMMENT 'the unit this service is normally quoted in — the vendor may still price it otherwise',
  `description`     varchar(255) NOT NULL DEFAULT '',
  `sort_order`      smallint(6)  NOT NULL DEFAULT 100,
  `is_active`       tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_slug` (`slug`),
  KEY `idx_catalog_category` (`vendor_category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.27 the platform''s shared service vocabulary — one row per known service per category';

-- ----------------------------------------------------------------------
-- What one business sells. Ownership is `vendor_id`; every read and write in
-- VendorService is scoped by it.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblvendor_services` (
  `id`             int(11)       NOT NULL AUTO_INCREMENT,
  `vendor_id`      int(11)       NOT NULL,
  `catalog_id`     int(11)       DEFAULT NULL COMMENT 'NULL = the vendor added this service themselves',
  `title`          varchar(160)  NOT NULL,
  `description`    text          DEFAULT NULL,
  `pricing_unit`   enum('flat','per_guest','per_day','per_hour','per_item','per_km','per_staff') NOT NULL DEFAULT 'flat' COMMENT 'named `pricing_unit`, not `unit`: two tables with a bare `unit` make the property ambiguous to the enum sweep',
  `price`          decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'KSh per pricing_unit',
  `min_qty`        int(11)       DEFAULT NULL COMMENT 'e.g. minimum 100 guests',
  `max_qty`        int(11)       DEFAULT NULL COMMENT 'e.g. capacity 250 guests',
  `lead_time_days` smallint(6)   DEFAULT NULL COMMENT 'how far ahead the vendor needs to be booked',
  `includes`       varchar(500)  NOT NULL DEFAULT '' COMMENT 'one short line: staff, fuel, setup, delivery',
  `tags`           varchar(255)  NOT NULL DEFAULT '' COMMENT 'comma-separated taste markers, e.g. rustic,halal,outdoor',
  `is_active`      tinyint(1)    NOT NULL DEFAULT 1,
  `created_at`     timestamp     NOT NULL DEFAULT current_timestamp(),
  `updated_at`     timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vendor_services_vendor` (`vendor_id`, `is_active`, `price`),
  KEY `idx_vendor_services_catalog` (`catalog_id`),
  CONSTRAINT `tblvendor_services_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `tblvendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tblvendor_services_ibfk_2` FOREIGN KEY (`catalog_id`) REFERENCES `tblservice_catalog` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.27 what one vendor sells: catalogue-derived or custom, always with a unit and a price';

-- ----------------------------------------------------------------------
-- The seeded vocabulary. ON DUPLICATE KEY UPDATE keeps a re-run honest: a
-- corrected unit or description lands, and a vendor's own rows are untouched.
-- ----------------------------------------------------------------------
INSERT INTO `tblservice_catalog` (`vendor_category`, `name`, `slug`, `default_unit`, `description`, `sort_order`) VALUES
  ('catering',    'Buffet, per guest',                  'catering-buffet',            'per_guest', 'Hot mains, sides and dessert served buffet-style', 10),
  ('catering',    'Plated three-course, per guest',     'catering-plated',            'per_guest', 'Waiter-served starter, main and dessert', 20),
  ('catering',    'Live grill / choma station',         'catering-live-grill',        'per_guest', 'Nyama choma grilled on site and served hot', 30),
  ('catering',    'Cocktail & mocktail bar',            'catering-cocktail-bar',      'per_guest', 'Bartender, glassware and a cocktail list', 40),
  ('catering',    'Service crew, per waiter',           'catering-service-crew',      'per_staff', 'Trained waiters for plated or buffet service', 50),
  ('catering',    'Cake cutting & plating',             'catering-cake-service',      'flat',      'Cutting, plating and serving the wedding cake', 60),

  ('decor',       'Ceremony arch & aisle',              'decor-arch',                 'flat',      'Arch, aisle runner and ceremony florals', 10),
  ('decor',       'Reception table styling, per table', 'decor-table-styling',        'per_item',  'Centrepiece, linen, glassware and place settings', 20),
  ('decor',       'Draping & lighting',                 'decor-draping-lighting',     'flat',      'Ceiling drapes, uplighting and festoon lights', 30),
  ('decor',       'Stage / backdrop design',            'decor-stage',                'flat',      'Backdrop, stage furniture and signage', 40),
  ('decor',       'Chair covers & sashes, per chair',   'decor-chair-covers',         'per_item',  'Fitted covers with a sash colour of your choice', 50),

  ('photography', 'Full-day coverage, two shooters',    'photo-full-day',             'per_day',   'Lead photographer and second shooter, ceremony to last dance', 10),
  ('photography', 'Half-day coverage',                  'photo-half-day',             'per_day',   'Up to six hours, one photographer', 20),
  ('photography', 'Pre-wedding / engagement shoot',     'photo-prewedding',           'flat',      'Location shoot with edited selects', 30),
  ('photography', 'Same-day slideshow',                 'photo-same-day-slideshow',   'flat',      'Edited highlights shown at the reception', 40),
  ('photography', 'Drone coverage',                     'photo-drone',                'flat',      'Licensed drone operator and aerial edits', 50),
  ('photography', 'Album & prints',                     'photo-album',                'per_item',  'Printed album, parent albums and framed prints', 60),

  ('music',       'DJ & sound system',                  'music-dj',                   'flat',      'DJ, PA, microphones and dance-floor lighting', 10),
  ('music',       'Live band',                          'music-live-band',            'flat',      'Full band with its own backline', 20),
  ('music',       'Ceremony PA',                        'music-ceremony-pa',          'flat',      'Sound for vows, readings and processional music', 30),

  ('transport',   'Bridal car',                         'transport-bridal-car',       'flat',      'Chauffeured car, decorated for the couple', 10),
  ('transport',   'Guest shuttle, per trip',            'transport-shuttle',          'per_item',  'Coach or matatu shuttle between venues', 20),
  ('transport',   'Convoy coordination',                'transport-convoy',           'flat',      'Route planning and marshals for the bridal party', 30),

  ('mc',          'Master of ceremonies',               'mc-host',                    'flat',      'Host for the ceremony and reception programme', 10),
  ('mc',          'Games & entertainment',              'mc-games',                   'flat',      'Planned games, prizes and audience warm-up', 20),

  ('venue',       'Exclusive venue hire',               'venue-exclusive-hire',       'flat',      'The venue for your date, no second event', 10),
  ('venue',       'Garden ceremony setup',              'venue-garden-setup',         'flat',      'Seating, shade and access for an outdoor ceremony', 20),
  ('venue',       'Accommodation, per room',            'venue-accommodation',        'per_item',  'On-site rooms for the couple and bridal party', 30),

  ('cakes',       'Wedding cake, per tier',             'cakes-tiered',               'per_item',  'Fondant or buttercream tiers, designed to order', 10),
  ('cakes',       'Dessert table',                      'cakes-dessert-table',        'flat',      'Assorted desserts styled on a shared table', 20),

  ('flowers',     'Bridal bouquet',                     'flowers-bridal-bouquet',     'per_item',  'Bouquet in the couple''s chosen palette', 10),
  ('flowers',     'Bridesmaid bouquets',                'flowers-bridesmaid',         'per_item',  'Matching smaller bouquets', 20),
  ('flowers',     'Church & reception flowers',         'flowers-church-reception',   'flat',      'Altar arrangements and reception blooms', 30),

  ('other',       'Wedding coordination, per day',      'other-coordination',         'per_day',   'On-the-day coordination from setup to send-off', 10),
  ('other',       'Invitation design & print',          'other-invitations',          'per_item',  'Designed invites, printed and delivered', 20),
  ('other',       'Guest favours',                      'other-favours',              'per_item',  'Wrapped favours per guest', 30),
  ('other',       'Bridal party styling',               'other-bridal-styling',       'flat',      'Hair and make-up for the couple and bridal party', 40),
  ('other',       'Security & ushering',                'other-security',             'per_staff', 'Uniformed security and ushers per event', 50),
  ('other',       'Sanitation & waste removal',         'other-sanitation',           'flat',      'Mobile toilets and end-of-event waste removal', 60)
ON DUPLICATE KEY UPDATE
  `vendor_category` = VALUES(`vendor_category`),
  `name`            = VALUES(`name`),
  `default_unit`    = VALUES(`default_unit`),
  `description`     = VALUES(`description`),
  `sort_order`      = VALUES(`sort_order`);
