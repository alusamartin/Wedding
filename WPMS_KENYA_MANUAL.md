# Everafter Planner — Complete System Manual
## Wedding Planning Management System v2.0 (Kenyan Market Edition)

---

# TABLE OF CONTENTS

1. [System Overview](#1-system-overview)
2. [Architecture & Technology Stack](#2-architecture--technology-stack)
3. [Installation & Setup](#3-installation--setup)
4. [Database Schema](#4-database-schema)
5. [User Roles & Access](#5-user-roles--access)
6. [Core Modules](#6-core-modules)
7. [Monetization Module](#7-monetization-module)
8. [Admin Workflows](#8-admin-workflows)
9. [Vendor Workflows](#9-vendor-workflows)
10. [Couple/Client Workflows](#10-coupleclient-workflows)
11. [Security Features](#11-security-features)
12. [Configuration Reference](#12-configuration-reference)
13. [Cron Jobs & Automation](#13-cron-jobs--automation)
14. [Troubleshooting](#14-troubleshooting)

---

# 1. SYSTEM OVERVIEW

## What is Everafter Planner?

Everafter Planner is a comprehensive wedding planning management system designed specifically for the Kenyan wedding industry. It serves three primary user types:

- **Wedding Planners/Organizers** — Manage clients, events, guests, budgets
- **Vendors** — Sign up from the public site (`vendor_signup.php`), list services, receive bookings, manage packages
- **Couples** — Register weddings, track planning, share wedding websites

## Key Features

| Feature | Description |
|---------|-------------|
| Client Management | Full CRM for wedding clients with booking lifecycle |
| Guest List Management | Kenyan-specific: harambee tracking, upcountry guests, transport |
| Budget & Payments | M-Pesa integration, payment tracking by method |
| Event Calendar | FullCalendar with Kenyan wedding event types |
| Vendor Marketplace | Categorized vendor listings with booking system |
| Vendor Services | Each vendor lists what it sells — from a seeded 39-item catalogue or their own — always with a unit (per guest, flat, per day…), a price, quantity window, inclusions and taste tags, so two vendors can be compared and a couple's budget checked |
| Taste & Budget per Category | Each couple states, per vendor category, the taste they want (free-text tags) and what they can spend (an optional floor and a cap), with a note for their planner. The marketplaces then mark every service **Fits / Partly fits / Over budget / Off your taste / Quote needed**, with the estimate shown and an explicit "quote needed" wherever a total cannot be worked out honestly. The couple's marketplace can filter to what fits; the planner sees the same marks for the wedding they are shopping for. **Split a total** turns one figure — the package price, or any number they prefer — into a cap per category from editable shares: it previews the result, places caps in whole KSh 500 blocks so they add up to the total, leaves out a category whose share is 0 without touching the cap it already had, and prefers the couple's own floor where that is higher than the share. Nothing is written until "Apply these caps", and applying changes caps only — tags, floors and notes stay |
| SMS Center | Bulk SMS via Africa's Talking for invitations/reminders |
| Wedding Websites | Public-facing pages with RSVP, gallery, countdown |
| Subscription System | SaaS monetization with 3 tiers |
| Gallery Management | Photo uploads linked to bookings |
| Blog/Events | Showcase real Kenyan weddings |

## Kenyan Market Customizations

- **Currency**: All prices in KSh (Kenyan Shillings)
- **Packages**: Traditional Ruracio/Dowry, Church Wedding, Civil Registration, Reception, After-Party
- **Guest Features**: Harambee contributions, upcountry accommodation, transport tracking
- **Payment Methods**: Cash, M-Pesa (with transaction codes), Bank Transfer
- **Locations**: All Kenyan counties and major towns
- **SMS**: Africa's Talking integration for bulk messaging
- **Vendor Categories**: Catering, Decor, Photography, Music, Transport, MC, Venue, Cakes, Flowers

---

# 2. ARCHITECTURE & TECHNOLOGY STACK

## Technology Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 7.4+ (Procedural + OOP) |
| **Database** | MySQL 5.7+ via mysqli extension |
| **Frontend** | Bootstrap 4, jQuery 3.x |
| **Calendar** | FullCalendar v3 + Moment.js |
| **SMS** | Africa's Talking API |
| **Payments** | M-Pesa (manual code entry, Daraja API ready) |
| **Server** | XAMPP / Apache (Windows/Linux) |

## Architecture Pattern

Everafter Planner uses a **custom MVC-lite pattern** with Active Record ORM:

```
┌─────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐ │
│  │ index.php   │  │ admin/*.php │  │ site.php (public)   │ │
│  │ (frontend)  │  │ (backend)   │  │ (wedding websites)  │ │
│  └─────────────┘  └─────────────┘  └─────────────────────┘ │
├─────────────────────────────────────────────────────────────┤
│                         CONTROLLER LAYER                     │
│  ┌─────────────────────────────────────────────────────────┐│
│  │ include/init.php — Bootstrap, load all models           ││
│  │ include/Session.php — Auth, CSRF, Flash messages       ││
│  │ include/Helper.php — Utility functions                 ││
│  └─────────────────────────────────────────────────────────┘│
├─────────────────────────────────────────────────────────────┤
│                           MODEL LAYER                        │
│  ┌─────────────────────────────────────────────────────────┐│
│  │ include/db_object.php — Active Record ORM base class    ││
│  │ include/Database.php — MySQLi connection wrapper        ││
│  │                                                         ││
│  │ Domain Models:                                          ││
│  │ • Accounts, Account_Details, Booking, BookingEvent      ││
│  │ • Guest, Categories, Features, Events, Gallery          ││
│  │ • Users, EventWedding, Liquidation, Organizer           ││
│  │                                                         ││
│  │ Monetization Models:                                    ││
│  │ • Subscription, Vendor, SMS, WeddingWebsite             ││
│  └─────────────────────────────────────────────────────────┘│
├─────────────────────────────────────────────────────────────┤
│                         DATABASE LAYER                       │
│  ┌─────────────────────────────────────────────────────────┐│
│  │ MySQL Database: dbwedding                               ││
│  │ 22 tables (11 core + 11 monetization)                   ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
```

## File Structure

```
Wedding/
├── index.php                     ← Frontend landing + registration
├── site.php                      ← Public wedding website viewer
├── thankyou.php                  ← Post-registration confirmation
├── login.php                     ← Single login door (all roles)
├── register.php                  ← Client registration
├── pricing.php                   ← Package pricing page
├── gallery.php                   ← Public gallery
├── contact.php                   ← Contact form
├── about.php                     ← About page
├── include/
│   └── nav.php                   ← Frontend navigation
├── admin/
│   ├── index.php                 ← Admin landing
│   ├── login.php                 ← Retired (301 → ../login.php)
│   ├── logout.php                ← Admin logout
│   ├── dashboard.php             ← Admin dashboard with stats
│   ├── subscription.php          ← SaaS subscription management
│   ├── vendors.php               ← Vendor marketplace
│   ├── vendor_signup.php         ← Public vendor self-registration (account + listing)
│   ├── vendor_register.php       ← Vendor profile form (invited vendors)
│   ├── sms_center.php            ← SMS credits & sending
│   ├── client.php                ← Client list (pending/confirmed)
│   ├── client_add.php            ← Add new client
│   ├── client_edit.php           ← Edit client
│   ├── client_assign.php         ← Assign/confirm booking
│   ├── client_manage_*.php       ← Client management sub-pages
│   ├── budget.php                ← Budget overview
│   ├── budget_add.php            ← Record payment
│   ├── budget_edit.php           ← Edit budget item
│   ├── budget_liquidate.php      ← Liquidate budget
│   ├── events.php                ← Events list
│   ├── events_add.php            ← Add event
│   ├── guest_list.php            ← Guest list
│   ├── guest_add.php             ← Add guest
│   ├── guest_edit.php            ← Edit guest
│   ├── guest_delete.php          ← Delete guest
│   ├── task_calendar.php         ← FullCalendar task view
│   ├── task_event_save.php       ← Save calendar event
│   ├── task_editEvent*.php       ← Edit calendar event
│   ├── gallery.php               ← Gallery management
│   ├── photos_add.php            ← Upload photo
│   ├── photos_edit.php           ← Edit photo
│   ├── photos_view.php           ← View photos
│   ├── photo_delete.php          ← Delete photo
│   ├── service_list.php          ← Services list
│   ├── services_add.php          ← Add service
│   ├── package_add.php           ← Add package
│   ├── package_edit.php          ← Edit package
│   ├── package_delete.php        ← Delete package
│   ├── feature_add.php           ← Add feature
│   ├── feature_edit.php          ← Edit feature
│   ├── feature_delete.php        ← Delete feature
│   ├── blog_events.php           ← Blog/Event posts
│   ├── blog_events_add.php       ← Add blog post
│   ├── blog_events_edit.php      ← Edit blog post
│   ├── blog_events_delete.php    ← Delete blog post
│   ├── users.php                 ← Admin users list
│   ├── users_add.php             ← Add admin user
│   ├── users_edit.php            ← Edit admin user
│   ├── users_delete.php          ← Delete admin user
│   ├── users_profile.php         ← User profile
│   ├── include/
│   │   ├── init.php              ← Bootstrap: loads all classes
│   │   ├── config.php            ← DB credentials
│   │   ├── database.php          ← Database connection class
│   │   ├── db_object.php         ← Base ORM class (Active Record)
│   │   ├── Session.php           ← Session, CSRF, Password hashing
│   │   ├── Helper.php            ← Utility functions
│   │   ├── Accounts.php          ← Admin accounts model
│   │   ├── Account_Details.php   ← Client details model
│   │   ├── Booking.php           ← Wedding booking model
│   │   ├── BookingEvent.php      ← Event booking model
│   │   ├── Guest.php             ← Guest model (Kenyan fields)
│   │   ├── Categories.php        ← Wedding package categories
│   │   ├── Features.php          ← Package features model
│   │   ├── EventWedding.php      ← Blog/Event posts model
│   │   ├── Events.php            ← Calendar events model
│   │   ├── Gallery.php           ← Gallery/photos model
│   │   ├── Users.php             ← Admin users model
│   │   ├── Liquidation.php       ← Budget liquidation model
│   │   ├── Organizer.php         ← Organizer (placeholder)
│   │   ├── Monetization.php      ← Subscription, Vendor, SMS, Website
│   │   ├── sidebar.php           ← Admin sidebar navigation
│   │   ├── footer.php            ← Admin footer
│   │   └── nav.php               ← Admin top nav
│   ├── upload/
│   │   ├── categories/           ← Category preview images
│   │   ├── events/               ← Event blog images
│   │   ├── gallery/              ← Gallery photos
│   │   └── users/                ← User profile pictures
│   ├── css/                      ← Admin stylesheets
│   ├── js/                       ← Admin JS files
│   └── calendar/                 ← FullCalendar assets
└── sql/
    └── kenyan_schema.sql          ← Kenyan market schema + seed data
```

---

# 3. INSTALLATION & SETUP

## Prerequisites

- XAMPP (PHP 7.4+, MySQL 5.7+) or similar Apache/MySQL/PHP stack
- Web browser (Chrome, Firefox, Edge)
- Africa's Talking account (for SMS features)
- M-Pesa Paybill/Till (for payment collection)

## Step-by-Step Installation

### 1. Copy Files
```
Copy the Wedding/ folder to C:\xampp\htdocs\Wedding\
```

### 2. Start XAMPP
```
Start Apache and MySQL from XAMPP Control Panel
```

### 3. Create Database
```sql
CREATE DATABASE dbwedding;
```

### 4. Import Schema
```bash
mysql -u root dbwedding < C:\xampp\htdocs\Wedding\kenyan_schema.sql
```

### 5. Configure Database
`admin/include/config.php` reads credentials from environment variables and falls back to stock XAMPP dev values (`localhost` / `root` / empty pass / `dbwedding`) when unset, so no edit is needed on a fresh XAMPP install. For any other environment (staging, production, Docker) set:
```bash
WPMS_DB_HOST=localhost
WPMS_DB_USER=db_user
WPMS_DB_PASS=secret
WPMS_DB_NAME=dbwedding
```
See section 12.1 for examples per server setup.

### 6. Set Timezone
In `admin/include/init.php`, ensure:
```php
date_default_timezone_set('Africa/Nairobi');
```

### 7. Set Base Path
**No edit needed.** Since v2.4 `admin/include/init.php` resolves the install
root from its own location, so the project runs from any folder name, drive
or host with zero path edits. The old hard-coded
`$webroot = "C:/xampp/htdocs/"` is gone.

### 8. Access the System
```
Public site:  http://localhost/Wedding/
Single login: http://localhost/Wedding/login.php   ← every role signs in here
```

The old `admin/login.php` is **retired**: it issues a permanent `301` redirect
to the single door, so old bookmarks still work. A visitor who is already
signed in never sees the form — login.php redirects straight to their role's
landing page.

### 9. Default Logins (all roles, one door)

Sign in at `login.php` with any of the seeded accounts; the system lands you on
the page your role needs automatically:

| Role | Email | Password | Lands on |
|---|---|---|---|
| Super Admin | `wafula@wpms.co.ke` | `Admin@2024!` | `admin/dashboard.php` |
| Super Admin (alt) | `abdi@wpms.co.ke` | `Admin@2024!` | `admin/dashboard.php` |
| Wedding Planner | `amina@wpms.co.ke` | `Test@1234` | `admin/dashboard.php` — `admin/subscription.php` first while the plan is locked |
| Planner | `njeri@wpms.co.ke` | `Test@1234` | same |
| Planner | `otieno@wpms.co.ke` | `Test@1234` | same |
| Vendor Lead | `kamau@nyamachoma.co.ke` | `Vendor@2024` | `admin/vendor_home.php` |
| Vendor | `chebet@floral.co.ke` | `Vendor@2024` | `admin/vendor_home.php` |
| Couple (client) | `brian.wafula@gmail.com` | `Test@1234` | `admin/client.php` |
| Couple (client) | `faith.njeri@yahoo.com` | `Test@1234` | `admin/client.php` |
| Couple (client) | `otieno.kisumu@outlook.com` | `Test@1234` | `admin/client.php` |
| Couple (client) | `chebet.eldoret@gmail.com` | `Test@1234` | `admin/client.php` |
| Couple (client) | `abdi.garissa@gmail.com` | `Test@1234` | `admin/client.php` |

> The seeded subscription dates are relative to the day the seed was imported,
> so a database seeded weeks ago has every plan expired: planners then land on
> the subscription page instead of the dashboard until they renew (super admins
> are exempt). The full per-role access map — what each role may actually open,
> measured with a signed-in session per role — is in
> [`docs/XAMPP_LOGINS_AND_ROLES.md`](docs/XAMPP_LOGINS_AND_ROLES.md).

Routing rules:

- **Clients** (`tblaccounts`) always land on `admin/client.php` — their
  bookings dashboard.
- **Staff** (`tblusers`, access_level 1/2) land on `admin/dashboard.php`,
  except on a first login or after a subscription lapses, when they are
  routed to `admin/subscription.php` to activate before anything else.
- Vendor accounts (`tblusers`, access_level 0) land on
  `admin/vendor_home.php` — their own bookings ledger (vendor self-service).
  A vendor without a vendor profile is routed to `admin/vendor_register.php`
  first; profile, packages and booking transitions are scoped to their own
  rows. Staff continue to manage vendor records (approval, premium,
  commission) from an admin login.

> The legacy template login (`admin@mail.com` / `Password@123`, from the
> original pre-bcrypt dump) **will not authenticate** on v2.1.

## Directory Permissions

Ensure these folders are writable by Apache:
```
admin/upload/categories/
admin/upload/events/
admin/upload/gallery/
admin/upload/users/
```

---

# 4. DATABASE SCHEMA

## Core Tables (12)

### tblusers — Admin Users
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| firstname | VARCHAR(100) | First name |
| lastname | VARCHAR(100) | Last name |
| gender | ENUM('male','female') | Gender |
| username | VARCHAR(50) | Login username |
| password | VARCHAR(255) | Bcrypt hash |
| email | VARCHAR(100) | Login email |
| designation | VARCHAR(50) | Role designation |
| address | TEXT | Physical address |
| access_level | VARCHAR(20) | RBAC level |
| profile_picture | VARCHAR(255) | Profile photo path |
| date_created | VARCHAR(50) | Creation timestamp |

### tblaccounts — Client Accounts
| Column | Type | Description |
|--------|------|-------------|
| user_id | INT PK | Links to tblaccounts_detail |
| user_email | VARCHAR(100) | Login email |
| user_password | VARCHAR(255) | Bcrypt hash |
| access_level | VARCHAR(20) | Access level |

### tblaccounts_detail — Client Details
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| user_id | INT FK | Links to tblaccounts |
| firstname | VARCHAR(100) | First name |
| lastname | VARCHAR(100) | Last name |
| phone | VARCHAR(20) | Phone number |
| city | VARCHAR(100) | City/Town |
| datetime_created | VARCHAR(50) | Registration date |
| description | TEXT | Notes |
| location | VARCHAR(200) | Wedding location |
| expectation_visitor | VARCHAR(50) | Expected guest count |
| cash_advanced | DECIMAL(12,2) | Initial payment |
| status | ENUM('pending','confirm','cancel','fraud') | Booking status |
| date_signed | VARCHAR(50) | Date signed |
| organizer_id | INT | Assigned organizer |

### tblweddingbook — Wedding Bookings
| Column | Type | Description |
|--------|------|-------------|
| booking_id | INT PK | Auto-increment |
| user_id | INT FK | Client user ID |
| bride | VARCHAR(100) | Bride's name |
| groom | VARCHAR(100) | Groom's name |
| wedding_type | INT FK | Category ID |
| user_email | VARCHAR(100) | Contact email |
| wedding_date | VARCHAR(50) | Wedding date |
| organizer_id | INT | Assigned organizer |

### tblweddingcategories — Wedding Packages
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| wedding_type | VARCHAR(100) | Package name |
| price | DECIMAL(10,2) | Package price (KSh) |
| preview_image | VARCHAR(255) | Preview image path |

### tbl_features — Package Features
| Column | Type | Description |
|--------|------|-------------|
| feature_id | INT PK | Auto-increment |
| category_id | INT FK | Package ID |
| title | VARCHAR(200) | Feature name |
| description | TEXT | Feature description |

### tblguest — Guest List
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT FK | Booking ID |
| fullname | VARCHAR(100) | Full name |
| guestname | VARCHAR(100) | Display name |
| address | TEXT | Physical address |
| state | CHAR(4) | County |
| zipcode | CHAR(10) | Postal code |
| priority | ENUM('A','B','C','D','E') | Priority level |
| out_of_town | ENUM('y','n') | Upcountry guest |
| relationship | VARCHAR(32) | Relationship/group |
| tracks_and_gifts | TEXT | Gift tracking notes |
| city | VARCHAR(100) | City/Town |
| upcountry_accommodation | TINYINT(1) | Needs accommodation |
| harambee_contribution | DECIMAL(12,2) | Harambee amount (KSh) |
| dietary_requirements | VARCHAR(100) | Dietary needs |
| transport_needed | TINYINT(1) | Needs transport |

### events — Calendar Events
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT FK | Booking ID |
| title | VARCHAR(200) | Event name |
| location | VARCHAR(200) | Venue |
| date_created | VARCHAR(50) | Creation date |
| color | VARCHAR(10) | Calendar color code |
| start | VARCHAR(50) | Start datetime |
| end | VARCHAR(50) | End datetime |

### tbleventbooking — Event Booking Link
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT FK | Booking ID |
| wedding_id | INT | Wedding ID |
| features_id | INT | Feature ID |
| family | INT | Family count |
| individual | INT | Individual count |

### tblgallery — Gallery Photos
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT FK | Booking ID |
| title | VARCHAR(200) | Photo title |
| caption | VARCHAR(200) | Photo caption |
| description | TEXT | Description |
| filename | VARCHAR(255) | File name |
| alternate_text | VARCHAR(200) | Alt text |
| type | VARCHAR(50) | MIME type |
| size | VARCHAR(50) | File size |

### tblpostwedding — Blog/Event Posts
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| title | VARCHAR(200) | Post title |
| description | TEXT | Post content |
| preview_image | VARCHAR(255) | Featured image |
| location | VARCHAR(200) | Location |
| status | TINYINT(1) | Published status |
| wedding_date | VARCHAR(50) | Wedding date |
| wedding_type | VARCHAR(100) | Wedding type |
| date_created | VARCHAR(50) | Creation date |
| date_published | VARCHAR(50) | Publish date |

### tbl_liquidation — Budget Payments
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| event_id | INT FK | Event ID |
| booking_id | INT FK | Booking ID |
| user_id | INT FK | User ID |
| payment | DECIMAL(10,2) | Total amount |
| cash | DECIMAL(10,2) | Cash portion |
| credit | DECIMAL(10,2) | Bank transfer portion |
| mpesa_code | VARCHAR(20) | M-Pesa transaction code |
| date_issue | VARCHAR(100) | Payment date |
| date_modified | VARCHAR(100) | Last modified |

### tbluser_prefs — Per-Account Preferences (v2.12)
| Column | Type | Description |
|--------|------|-------------|
| user_id | INT PK | `tblusers.id` — staff and vendor accounts (couples have no rows) |
| pref_key | VARCHAR(40) PK | Preference name, e.g. `headroom_width` |
| pref_value | VARCHAR(190) | Stored value; the READER validates it (`admin/include/UserPrefs.php`) |
| updated_at | TIMESTAMP | Last write |

> One row per (account, preference). A table rather than a column on `tblusers`
> because that table is read on every login and the next preference must not be
> another `ALTER` on it. The first preference is the capacity headroom view's
> width: the page (`admin/headroom.php`) lists the weddings running within 5%,
> 10% or 20% of a booked package's guest limit, and a planner's choice is stored
> here so the page opens at it again — chosen with the Remember button under the
> width chips, dropped with Forget, both of which are CSRF-protected POSTs. An
> explicit `?within=` in the address bar changes that one view and never writes,
> and an account with no row is simply on the page's default of ten per cent.
> The stored value is a hint, not a policy: it is validated against the widths
> the feature offers when it is read, so a row edited by hand reads as the
> default instead of widening every page load. Installing this table is optional
> (`migrations/v2.12_user_preferences.sql`): the page works without it and says
> it cannot remember the width.

### tblops_snapshots — Weekly Business-Pulse History (v2.23)
| Column | Type | Description |
|--------|------|-------------|
| scope_id | INT PK | `tblusers.id` whose thresholds shaped the snapshot; `0` = shared/default mailbox |
| iso_week | VARCHAR(8) PK | ISO week key, e.g. `2026-W43` |
| payload | MEDIUMTEXT | The `OpsPulse::snapshot()` figures that week's brief was rendered from (aggregates and internal ids only) |
| captured_at | TIMESTAMP | Last write |

> One row per (account, week). The Monday brief (`scripts/business_pulse_email.php`,
> `admin/include/BusinessPulse.php`) reads the most recent row **strictly before**
> its own week and prints each figure's signed change — counts as `+3`, money as
> `+95,000`, the RSVP rate as `+2.1pp`. The row is written only after the mail
> transport accepted the message, so a preview or a failed send never becomes the
> next baseline; a resend inside the same week replaces that week's row instead of
> competing with it. The writer keeps 26 weeks per account, so there is no pruning
> cron. Optional (`migrations/v2.23_business_pulse_snapshots.sql`): without the
> table the brief still sends and says the comparison is off.

### tblevent_vendor_needs — Required Vendor Services per Function (v2.24)
| Column | Type | Description |
|--------|------|-------------|
| booking_id | INT PK | `tblweddingbook.booking_id` the function belongs to |
| event_id | INT PK | `events.id`; `0` = the wedding as a whole (no calendar functions yet) |
| categories | VARCHAR(255) | Required `tblvendors.category` values, comma-separated in canonical order; empty = nothing needed |
| updated_at | TIMESTAMP | Last write |
| updated_by | INT | `tblusers.id` who saved the list |

> One row per (wedding, function). The coverage board
> (`admin/vendor_coverage.php`, `admin/include/VendorCoverage.php`) draws every
> calendar function against the ten vendor categories and judges only the cells
> this list marks as required — booked when a deal is confirmed or completed,
> pending while the only deal is an enquiry, missing when nothing is recorded.
> A function with **no row** starts from all ten, so an existing wedding keeps
> its whole matrix and a hole is never hidden by an empty default; a stored empty
> string is the planner's decision that the function needs no vendor service.
> A deal in a category that was unticked stays visible but muted, because the
> platform must never hide money already committed. Requirements are intent, not
> agreements: deals and amounts stay in `tblvendor_bookings`, and changing a
> requirement never touches a deal. The only writer is the board's editor — an
> explicit POST with the session's CSRF token — and a function that is not this
> wedding's is refused even with a valid token. Optional
> (`migrations/v2.24_event_vendor_needs.sql`): without the table the board still
> renders, counting all ten per function, and says the list cannot be edited.

## Monetization Tables (11)

### tblsubscriptions — User Subscriptions
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| user_id | INT FK | Admin user ID |
| plan | VARCHAR(20) | Plan key — matches `tblsubscription_plans.plan_slug` |
| amount | DECIMAL(10,2) | Amount paid |
| mpesa_code | VARCHAR(20) | M-Pesa code |
| start_date | DATE | Subscription start |
| expiry_date | DATE | Subscription expiry |
| status | ENUM('active','expired','cancelled','pending_renewal','trial') | Status |
| created_at | TIMESTAMP | Creation time |
| updated_at | TIMESTAMP | Last update |

### tblsubscription_plans — Plan Definitions
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| plan_slug | VARCHAR(20) | Unique slug |
| name | VARCHAR(100) | Display name |
| price | DECIMAL(10,2) | Monthly price |
| wedding_limit INT | Annual wedding limit |
| features | TEXT | Feature list |
| is_active | TINYINT(1) | Active status (0 = retired, still resolves for existing subscribers) |

### tblsubscription_plan_changes — Plan Change Ledger (v2.8)
Each edit to a plan definition writes one row per changed FIELD, so "who moved
this price, and when" is a query rather than a guess. Written by
`Subscription::create_plan() / update_plan() / set_plan_active()`; read by the
plan editor (last price change + full history) . No foreign key, deliberately:
history must not be erasable by deleting its subject.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| plan_id | INT | The plan definition that changed (indexed, not a FK) |
| plan_slug | VARCHAR(20) | Key at the time of the change |
| field_name | VARCHAR(30) | name \| price \| wedding_limit \| features \| is_active |
| old_value | VARCHAR(255) | Previous value; NULL when the plan was defined |
| new_value | VARCHAR(255) | New value |
| user_id | INT | Operator who made the change; NULL for CLI/cron |
| changed_at | TIMESTAMP | When |

### tblsubscription_payments — Payment History
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| subscription_id | INT FK | Subscription ID |
| user_id | INT FK | User ID |
| amount | DECIMAL(10,2) | Amount |
| mpesa_code | VARCHAR(20) | M-Pesa code |
| payment_date | TIMESTAMP | Payment time |
| status | ENUM('pending','completed','failed') | Status |

### tblvendors — Vendor Profiles
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| user_id | INT FK | Owner user ID |
| business_name | VARCHAR(200) | Business name |
| category | ENUM(...) | Service category |
| description | TEXT | Business description |
| phone | VARCHAR(20) | Contact phone |
| email | VARCHAR(100) | Contact email |
| location | VARCHAR(100) | Town |
| county | VARCHAR(50) | County |
| logo | VARCHAR(255) | Logo image |
| is_premium | TINYINT(1) | Premium listing |
| is_approved | TINYINT(1) | Approval status |
| rating | DECIMAL(3,2) | Average rating |
| total_bookings | INT | Total bookings |
| created_at | TIMESTAMP | Registration time |

### tblvendor_packages — Vendor Service Packages
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| vendor_id | INT FK | Vendor ID |
| title | VARCHAR(200) | Package name |
| description | TEXT | Description |
| price | DECIMAL(10,2) | Price (KSh) |
| capacity | INT | Guest capacity (NULL/0 = no limit stated) |
| duration_hours | INT | Service duration |
| is_active | TINYINT(1) | Active status |
| created_at | TIMESTAMP | Creation time |

### tblservice_catalog — The Shared Service Vocabulary (v2.27)
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| vendor_category | ENUM(...) | Same ten values as `tblvendors.category` (named `vendor_category` so a bare `category` stays unique in the schema) |
| name | VARCHAR(120) | Human name, e.g. "Buffet, per guest" |
| slug | VARCHAR(120) | Stable key, so re-running the seed updates instead of duplicating |
| default_unit | ENUM('flat','per_guest','per_day','per_hour','per_item','per_km','per_staff') | **Mandatory** — how the service is normally quoted |
| description | VARCHAR(255) | Default wording |
| sort_order | SMALLINT | Picker order inside a category |
| is_active | TINYINT(1) | A retired item is not offered to new rows |
| created_at | TIMESTAMP | Creation time |

> Platform-owned, not vendor-editable: that is what stops "buffet", "buffet service"
> and "self-service buffet" becoming three different things.

### tblvendor_services — What One Vendor Sells (v2.27)
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| vendor_id | INT FK | Vendor ID (cascades with the listing) |
| catalog_id | INT FK NULL | Catalogue item, or NULL when the vendor added their own |
| title | VARCHAR(160) | Service name |
| description | TEXT | Details |
| pricing_unit | ENUM(...) | How the price is quoted (named `pricing_unit`, not `unit`, to keep the property name unique) |
| price | DECIMAL(12,2) | KSh per pricing unit |
| min_qty / max_qty | INT NULL | e.g. minimum 100 guests / capacity 250 |
| lead_time_days | SMALLINT NULL | How far ahead the vendor must be booked |
| includes | VARCHAR(500) | Short inclusions line (staff, fuel, setup) |
| tags | VARCHAR(255) | Comma-separated taste markers (rustic, halal, outdoor) |
| is_active | TINYINT(1) | Published / hidden |
| created_at / updated_at | TIMESTAMP | Lifecycle stamps |

> A service is a **piece**, not a bundle: `tblvendor_packages` stays the thing a
> couple buys and is unaffected by this table. Vendor → My Services
> (`admin/vendor_services.php`) writes it; the staff marketplace, the couple
> marketplace and the public profile read it with `VendorService::price_line()`,
> which always states the unit ("KSh 1,200 / guest (min 100)").

### tblvendor_service_tiers / tblvendor_package_tiers — Silver, Gold, Platinum (v3.1)
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| service_id / package_id | INT FK | The offering this level belongs to (cascades with it) |
| tier | ENUM('silver','gold','platinum') | The level; UNIQUE with the offering |
| price | DECIMAL(12,2) | KSh, in the offering's own unit |
| includes / excludes | VARCHAR(500) | What this level buys, and what it leaves out |
| created_at / updated_at | TIMESTAMP | Lifecycle stamps |

> `tblvendor_services.price` / `tblvendor_packages.price` stay the **Silver**
> price and are written whenever tiers are saved, so everything that reads a
> single price keeps working and means "entry level". See §7.9 for the rules
> (all three or none, Silver < Gold < Platinum) and where the levels show.

### tblvendor_preferences — The Couple's Taste & Budget (v2.28)
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT FK | Wedding (cascades with the booking) |
| service_category | ENUM(ten) | Which kind of vendor this applies to |
| taste_tags | VARCHAR(255) | Comma-separated, the same words vendors tag services with |
| budget_min | DECIMAL(12,2) NULL | Optional floor |
| budget_max | DECIMAL(12,2) NULL | The cap for this category |
| notes | VARCHAR(500) | The couple's own words, shown to their planner |
| updated_by | INT NULL | Who last saved it |
| created_at / updated_at | TIMESTAMP | Lifecycle stamps |

> One row per (wedding, category); an empty form deletes the row, so "no
> preference" is one state. Fit is **computed on read** (`VendorFit`) and never
> stored per vendor: per-guest prices use the couple's guest list (or the
> vendor's stated minimum), flat takes the price, per-item/per-staff use the
> vendor's minimum quantity, and per-hour/per-km say **Quote needed** rather
> than inventing a total.

### tblproposals — One Wedding's Proposal (v2.30)
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT UNIQUE FK | The wedding; one proposal per booking |
| planner_id | INT | tblusers id of the planner who owns it; 0 = waits in the shared queue |
| preferred_planner_id | INT | The planner the couple asked for at sign-up, kept after reassignment |
| status | ENUM | proposal → acknowledged → options → chosen → locked, plus tweak_requested and declined |
| guests / budget | INT / DECIMAL NULL | What the couple stated; NULL means "not stated", never 0 |
| venue / taste_tags / couple_note | VARCHAR | The rest of their brief, in their words |
| tweak_note / decline_note | VARCHAR | Their request for a change, or why they walked away |
| acknowledged_at/by, published_at, chosen_at, locked_at/by | stamps | When each move happened and who made it |

### tblproposal_options / tblproposal_items — The Priced Options (v2.30)
| Column | Type | Description |
|--------|------|-------------|
| options.title / tier / summary | VARCHAR | What the couple reads: "The Garden Wedding", recommended, and one paragraph |
| options.total | DECIMAL | The quote as it was saved — the sum of its items at that moment |
| options.is_chosen | TINYINT | Set when the couple picks it (exactly one per proposal) |
| items.option_id | INT FK | The option this line belongs to (cascades) |
| items.vendor_id | INT | The vendor doing it |
| items.kind | ENUM | service / package / custom (an agreed arrangement outside the catalogue) |
| items.service_id / package_id | INT NULL FK | The catalogue row bought; NULL for a custom line |
| items.service_qty | INT NULL | The multiplier behind the amount (guests, hours, items) |
| items.label / note | VARCHAR | What it is, frozen at save time, plus the planner's note |
| items.deal_id | INT NULL | The tblvendor_bookings row it became when the option was locked |

> At least **three** priced options must exist before the couple can be asked
to choose. An option's total is stored because it is a QUOTE the couple was
shown; live prices do not rewrite it. Locking copies the amounts once into
`tblvendor_bookings`, which stays the single record of what was dealt.

### tblvendor_bookings — Vendor Bookings
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| vendor_id | INT FK | Vendor ID |
| booking_id | INT FK | Wedding booking ID |
| package_id | INT FK | The package this deal was sold on |
| service_id | INT FK NULL | (v2.29) The individual service the deal is for; NULL for a package deal, a custom arrangement, or a pre-v2.29 row |
| service_qty | INT NULL | (v2.29) The quantity the amount was planned at — guests, days, items, hours: the multiplier behind `amount` |
| features_id | INT FK | The function the deal covers (NULL = the whole wedding) |
| event_date | DATE | Event date |
| amount | DECIMAL(10,2) | Booking amount |
| commission | DECIMAL(10,2) | Everafter Planner commission |
| status | ENUM('pending','confirmed','completed','cancelled') | Status |
| notes | TEXT | Notes |
| created_at | TIMESTAMP | Creation time |

> A confirmed or completed deal is what the headcount brief is built from, and its
> `package_id` is what the capacity warning is built from: the package's `capacity`
> (its guest limit) is checked against the planned headcount of every function the
> deal covers, one function at a time. A function planned past that limit warns the
> vendor holding the deal and the wedding's planner, and the venue sheet prints it
> beside the numbers. A pending enquiry is not an assignment and is never warned
> about; a deal with no package, or a package with no capacity stated, has no limit
> to outgrow.
>
> The warning carries the way out as well: the same vendor's active packages are
> read for the smallest one that covers the crowd (cheapest first when two are
> equal), and the planner is told what it costs against the package already sold —
> "Next package up: … — KSh 60,000 more". When the vendor has nothing bigger, that
> is said plainly (`their biggest is …`, or `ask them for a quote`) rather than left
> blank. `package_id`, `amount` and `commission` are then re-priced in one step from
> the deal's row in Vendor Enquiries — the staff-only "Re-price" control, which the
> sheet's "Re-price this deal" link opens with that row focused — and the notes
> trail records the move, who made it, and both amounts. What a re-price never
> touches: `status` (a re-price is not a re-negotiation of whether the vendor is
> booked) and `features_id` (the scope is set on the deal's own scope control).
>
> **The same limit is checked when the deal is recorded**, not only when the
> numbers move — a couple can pick a package too small for a plan that has not
> changed since, and the next headcount save may never come. Four doors judge the
> single deal they are writing with `PackageCapacity::deal_position()`: the
> couple's enquiry (`admin/client_vendor_enquiry.php`, where nothing is sold yet,
> so the copy reads *"the package you asked X for"* and names every function it
> cannot hold plus the way out; the planner gets the short form on the enquiry
> alert they already receive), the staff confirm and the vendor's own accept
> (`admin/vendor_enquiries.php` / `admin/vendor_home.php`, where the deal is
> genuinely recorded — the page states the warning with the way out, the planner
> and the vendor holding the package are belled, and the vendor's copy never
> carries the planner's price difference), and a confirmed deal's `features_id`
> being widened onto a function the package cannot cover (the ledger's scope
> control, only when the scope actually changed). The warning fires only on the
> transition INTO `confirmed`: re-saving a confirmed deal is not a new sale. A
> deal recorded on a package that covers the plan says nothing at all, and a
> *pending* enquiry is still never counted as an assignment — the enquiry's own
> note is advice to the two people choosing, not a breach.
>
> **Drift, the step before all of it** (`admin/headroom.php`, staff only). A
> wedding whose plan is running *close* to a booked package's limit is not over
> it, and may never be: nobody is warned and nothing is emailed, because a bell
> per wobble of a guest count is noise. The page lists those weddings instead —
> one row each, tightest first, stating the function, the crowd, the package and
> the limit it is closing on, with the venue sheet linked so the planner can act
> where the numbers are. "Close" is a share of the package's OWN capacity rather
> than a head count, so it means the same thing on a 33-seat matatu and a
> 400-seat hall: the margin (`capacity − planned`) inside 5%, 10% or 20% of the
> capacity, ten by default, chosen with the chips at the top of the page. The
> sidebar badge counts exactly the weddings the page lists (one query, not a walk
> over every wedding), and a wedding already OVER a limit is counted separately
> and said in red on the page rather than dropped from it — an empty list must
> never be read as "nothing wrong here". A deal with no package chosen, a package
> with no capacity stated, an unplanned function and a pending enquiry all state
> no limit to run out of and are never listed. The width is remembered per
> account (`tbluser_prefs`, v2.12): Remember stores the width that is on screen
> and the page opens at it next visit; Forget drops it. A `?within=` in the URL
> changes one view without touching either, so a shared link never edits anyone's
> settings.

### tblvendor_messages — Deal Conversation (v2.25)
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| deal_id | INT FK | `tblvendor_bookings.id` the message belongs to (cascades with the deal) |
| author_role | ENUM('couple','vendor','planner') | Which side of the deal wrote it |
| author_id | INT | `tblaccounts.user_id` for a couple, `tblusers.id` for a vendor or planner |
| author_name | VARCHAR(120) | The name shown on the bubble, kept if the account is renamed |
| body | TEXT | The message, plain text, up to 2,000 characters |
| created_at | TIMESTAMP | When it was written |
| read_couple_at | DATETIME | NULL = this couple has not read it |
| read_vendor_at | DATETIME | NULL = this vendor has not read it |
| read_planner_at | DATETIME | NULL = this couple's planner has not read it |
| escalated_at | DATETIME | v2.26 — when the couple's planner was told this message has waited too long. NULL = not told |

> **One conversation, three readers.** Every deal now carries its own thread
> instead of a single `notes` string that each side prepended to, so authorship
> and order survive and anybody can answer. `notes` is untouched and remains the
> deal's audit trail (confirmations, availability flags, staff edits); the thread
> starts empty on an existing deal and shows the couple's original enquiry above
> the composer as **Original enquiry**, recovered from `notes`.
>
> **Read state is per message, not per viewer.** A role's unread count is
> "messages by the other two roles whose stamp for me is NULL", so there is no
> per-viewer join table and no rows for people who never opened the thread. A
> message stamps its own author as having read it. Reading a thread goes through
> `admin/vendor_thread_open.php`, which authorises again, clears only that role's
> badge and returns to the ledger with the panel open; bells point at the same
> door, so one click both opens the conversation and clears the badge.
>
> **"Whose move is it" is derived, never stored** (`VendorThread::stage()`), from
> the deal's status and the last message's author: the couple reads *"the vendor
> replied — your turn"* or *"waiting on X"*, the vendor reads *"reply needed"* or
> *"waiting on the couple"*, the planner reads *"follow up with X"*, *"loop the
> couple in"* or *"introduce yourself"*, and a confirmed deal reads **Booked** for
> everyone. A cancelled deal reads **Closed**, a completed one **Wrapped up**.
> Because nothing is stored, a stage cannot go stale after a confirmation or a
> reply. Each role also gets four quick replies, so a vendor answers availability
> from a phone in one tap and a couple never has to compose *"how do I ask
> this?"*.
>
> **The badge follows the reader, not the platform.** `VendorThread::unread_total()`
> counts the unread messages on the deals ONE role may open — a planner's scoped
> weddings, a vendor's own listings, a couple's own wedding — and the sidebar
> shows that count on the ledger the badge links to: **Vendor Enquiries** for
> staff, **My Bookings** for a vendor, and a **Vendor enquiries** entry for a
> couple that opens their own wedding's marketplace (the count is limited to
> that wedding, so the number and the page always agree). Clicking a badged
> entry does not land on the list: it opens the OLDEST unread thread
> (`VendorThread::oldest_unread()`), because a badge is a queue and the message
> that has waited longest should be answered first. With nothing waiting, the
> same entry is the ledger list it always was, and the badge disappears.
>
> **Working the queue.** Vendor Enquiries carries an **Unread (N)** chip beside
> the status chips: it counts threads with messages the planner has not read and
> filters the ledger to just those, so the queue can be worked in one place. It
> is served **longest waiting first** — the rows are re-ordered by the oldest
> unread message on each deal (`VendorThread::waiting_first`), which is the same
> order the sidebar badge walks, so the first row is the conversation the badge
> would have opened and the couple who has been ignored longest is answered
> first. A row kept on screen only because its panel is open has nothing
> waiting, so it drops to the bottom rather than blocking the top.
> Every row with unread words also says **how long** it has been waiting beside
> its *N new* pill — *waiting 8 min*, *waiting 3 h*, *waiting 2 days* — with the
> exact time since the message arrived in the tooltip. The duration is read
> from the same message the order sorts on, so "top of the queue" and
> "waiting longest" can never tell two different stories.
> The chip combines with a status chip rather than replacing it, and the chip toggles
> back to the whole ledger. Opening a thread from the queue keeps the filter
> (the row's link and the opener both carry `status`/`unread`), and the deal
> that was just read stays on screen even though it has left the filter — the
> panel the planner asked for is never pulled out from under them. When the
> queue is clear the page says **Nothing waiting** instead of showing a blank
> table.
>
> **The vendor works the same queue.** My Bookings carries the same **Unread
> (N)** chip, counting the threads on the vendor's own bookings that hold a
> message from the couple or their planner they have not read — the vendor's
> answer to "who is waiting on me?". It behaves exactly like the planner's:
> counts threads rather than messages, orders the queue longest waiting first,
> dates every waiting row ("waiting 3 h"), combines with the status chips, keeps
> the listing being read, carries
> `status`/`unread` into the row's link, the opener and the Accept/Decline form
> (so acting on a deal returns to the queue), and keeps the just-read row on
> screen while its panel is open.
>
> **Nobody has to be looking.** A labelled row still needs somebody at the
> ledger. Once a day (08:30), `scripts/thread_escalation.php` finds every deal
> whose NEWEST message is not the vendor's and is at least a day old, and rings
> the couple's planner — bell plus the platform's usual email — with one
> sentence: *"Linda & Brian have been waiting 2 days for Nyama Choma Masters to
> reply. Open the thread and give them a nudge."* The link opens the thread, so
> the next step is a phone call rather than a search. Reading the question does
> not stop the alert (a vendor who opened it and said nothing is the case most
> worth reporting), it is sent once per unanswered message (so a re-run is
> silent, and a later message is escalated in its own turn), and completed
> deals, cancelled deals and weddings already held are left alone. The couple is
> never told their vendor is silent — that conversation belongs to the planner.

### tblsms_logs — SMS History
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| user_id | INT FK | Sender user ID |
| booking_id | INT FK | Related booking |
| phone | VARCHAR(20) | Recipient phone |
| message | TEXT | Message content |
| status | ENUM('pending','sent','failed') | Send status |
| cost | DECIMAL(6,2) | SMS cost (KSh) |
| sent_at | TIMESTAMP | Send time |
| created_at | TIMESTAMP | Creation time |

### tblsms_credits — SMS Credit Balances
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| user_id | INT FK | User ID |
| credits | INT | Available credits |
| total_purchased | INT | Lifetime purchased |
| total_used | INT | Lifetime used |

### tblwedding_websites — Public Wedding Pages
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| booking_id | INT FK | Booking ID |
| slug | VARCHAR(100) UNIQUE | URL slug |
| title | VARCHAR(200) | Page title |
| story | TEXT | Couple's story |
| venue | VARCHAR(200) | Venue name |
| venue_address | TEXT | Venue address |
| wedding_date | DATE | Wedding date |
| cover_image | VARCHAR(255) | Cover photo |
| is_published | TINYINT(1) | Published |
| views | INT | View count |
| created_at | TIMESTAMP | Creation time |

---

# 5. USER ROLES & ACCESS

## Role Hierarchy

```
┌─────────────────────────────────────────────────────────────┐
│                      SUPER ADMIN                            │
│  • Full system access                                       │
│  • Manage admin users                                       │
│  • Approve vendors                                          │
│  • View all analytics                                       │
│  • Configure system settings                                │
├─────────────────────────────────────────────────────────────┤
│                   WEDDING PLANNER                           │
│  • Manage own clients                                       │
│  • Manage own bookings                                      │
│  • Access vendor marketplace                                │
│  • Send SMS (with credits)                                  │
│  • Create wedding websites                                  │
├─────────────────────────────────────────────────────────────┤
│                        VENDOR                               │
│  • Manage own profile                                       │
│  • Manage own packages                                      │
│  • Receive bookings                                         │
│  • View booking requests                                    │
├─────────────────────────────────────────────────────────────┤
│                        COUPLE                               │
│  • Register wedding                                         │
│  • View wedding website                                     │
│  • Submit RSVP                                              │
│  • Track planning progress                                  │
└─────────────────────────────────────────────────────────────┘
```

## Access Control

> **Enforced, with one switch.** The "Own" / "All" columns are now the gate:
>
> - The per-planner split **is** implemented (`Helper.php`:
>   `staff_may_open_organizer()`, `planner_scope_sql()`). A planner sees the
>   weddings whose `tblweddingbook.organizer_id` is their own id, plus the ones
>   nobody has been assigned yet; a super admin sees every wedding; a couple
>   sees its own booking; a vendor sees none of it. That applies to the
>   booking list (`client.php`), the enquiries ledger (`vendor_enquiries.php`,
>   including its status tallies and its POST handlers), the dashboard count,
>   the MCP tools and documents, and every other staff page that names a
>   `booking_id` — `init.php` refuses the request before the page body runs.
> - The escape hatch is `WPMS_PLANNER_SCOPING=0`, which restores the old
>   whole-platform view for a single-planner install. "Unassigned is visible to
>   every planner" is deliberate: a new booking must be reachable so somebody
>   can take it.
> - The `❌` cells mark surfaces an operator uses, not pages a planner is
>   refused. `users.php`, the blog/gallery/service pages and the rest are gated
>   by `require_staff_subscribed()` / `require_staff()`, so a planner on an
>   active plan reaches them (the role simulation's P4 walks 22 of them as a
>   planner). The exceptions that really are operator-only are **Plan
>   Definitions** (`subscription_plans.php`, page-level) and **vendor approval /
>   premium placement** (`vendors.php` actions, plus the pending-listings block).
>
> `docs/XAMPP_LOGINS_AND_ROLES.md` §4 has the measured page-by-page gate table;
> `docs/ROLE_SIMULATION_REPORT.md` P4–P7 covers the planner-vs-operator split.

| Feature | Super Admin | Planner | Vendor | Couple |
|---------|-------------|---------|--------|--------|
| Dashboard | ✅ | ✅ | ❌ | ❌ |
| Client Management | ✅ All | ✅ Own + unassigned (scoped by `organizer_id` — see note) | ❌ | ❌ |
| Vendor Marketplace | ✅ | ✅ | ✅ Own | ❌ |
| SMS Center | ✅ | ✅ | ❌ | ❌ |
| Subscription | ✅ | ✅ Own | ❌ | ❌ |
| User Management | ✅ | ❌ | ❌ | ❌ |
| Vendor Approval | ✅ | ❌ | ❌ | ❌ |
| Blog Management | ✅ | ❌ | ❌ | ❌ |
| Gallery | ✅ All | ✅ Own | ❌ | ❌ |
| Budget | ✅ All | ✅ Own | ❌ | ❌ |
| Guest List | ✅ All | ✅ Own | ❌ | ❌ |
| Calendar | ✅ All | ✅ Own | ❌ | ❌ |

---

# 6. CORE MODULES

## 6.1 Authentication Module

### Login Flow (unified)
```
1. User visits /login.php (the retired /admin/login.php 301-redirects here)
2. Already signed in? → redirect to role home, form never renders
3. Submits email + password + CSRF token
4. System validates CSRF token
5. Accounts::login_user()     → client table (tblaccounts) first
6. Users::user_account_login() → staff table  (tblusers) next
   (both use prepared statements + password_verify on bcrypt hashes)
7. On success: Session::login() sets $_SESSION['id'] + privilege_level,
   regenerates the session ID, and binds the fingerprint
8. Landing resolved by login_redirect_target() in admin/include/init.php:
     client (tblaccounts)          → admin/client.php
     staff with active sub         → admin/dashboard.php
     staff without active sub      → admin/subscription.php
9. Wrong credentials → back to login.php with an error alert
```

Role gating after login: staff pages call `require_staff()` (planner/admin
only); client pages check `$_SESSION['id']` ownership and scope every query
to the signed-in account. A planner who opens the couple view of their own
booking sees the couple dashboard chrome via `is_staff()` checks in each
page.

### Password Security
- All passwords stored as bcrypt hashes (password_hash)
- Verification via password_verify()
- Legacy MD5 support removed
- Session regeneration every 15 minutes (plus on every login/privilege change)

### CSRF Protection
- Token generated per session via random_bytes(32)
- Token embedded in every POST form via $session->csrf_field()
- Token verified on every POST request via $session->verify_csrf()
- Timing-safe comparison via hash_equals()

## 6.2 Client Management Module

### Client Lifecycle
```
┌──────────┐    ┌───────────┐    ┌───────────┐    ┌──────────┐
│ PENDING  │───→│ ASSIGNED  │───→│ CONFIRMED │───→│ COMPLETED│
│          │    │           │    │           │    │          │
│ Registered│    │ Admin fills│    │ Ready for │    │ Wedding  │
│ via form │    │ details   │    │ planning  │    │ done     │
└──────────┘    └───────────┘    └───────────┘    └──────────┘
      │
      └────→ CANCELLED / FRAUD
```

### Registration (Frontend)
1. Couple fills form on index.php
2. System validates: empty fields, email format, date availability, email uniqueness
3. Creates Account_Detail → Accounts → Booking records
4. Status set to 'pending'
5. Redirects to thank_you.php

### Assignment (Admin)
1. Admin reviews pending client in client.php
2. Clicks "Assign" → client_assign.php
3. Fills: bride, groom, wedding_type, organizer, location, phone, etc.
4. Status changes to 'confirm'
5. Client now appears in confirmed list

### Key Files
- `index.php` — Frontend registration
- `admin/client.php` — Client list (pending/confirmed tabs)
- `admin/client_assign.php` — Assignment form
- `admin/client_edit.php` — Edit client details
- `admin/client_manage_*.php` — Sub-pages for each client

## 6.3 Guest List Module

### Kenyan-Specific Features

| Feature | Description |
|---------|-------------|
| **Harambee Tracking** | Per-guest contribution amount + total per wedding |
| **Upcountry Guests** | Flag for guests needing accommodation |
| **Transport Tracking** | Flag for guests needing transport |
| **Priority Levels** | A=VIP/Elders, B=Bridal Train, C=Close Family, D=Regular, E=General |
| **Relationship Types** | Family Elder (Mzee), Bridal Train, Upcountry Guest, VC Member, etc. |
| **Dietary Requirements** | Halal, Vegetarian, No Beef, Diabetic, etc. |
| **Town/County Dropdowns** | All major Kenyan locations |

### Guest Statistics Dashboard
- Total guest count
- Upcountry guest count
- Total harambee contributions
- Transport-needed count

### Key Files
- `admin/include/Guest.php` — Model with Kenyan fields
- `admin/guest_add.php` — Add guest form
- `admin/guest_edit.php` — Edit guest form
- `admin/guest_list.php` — Guest list with stats
- `admin/guest_delete.php` — Delete guest

## 6.4 Budget & Payment Module

### Payment Methods
| Method | Tracking |
|--------|----------|
| **Cash** | Amount recorded in `cash` column |
| **M-Pesa** | Amount in `payment`, code in `mpesa_code` |
| **Bank Transfer** | Amount in `credit` column |

### Budget Views
- **Summary Cards**: Package price, total paid, balance due, item count
- **Payment Breakdown**: M-Pesa (green), Cash (blue), Bank (orange)
- **Detailed Table**: Date, event, method, M-Pesa code, amount

### Key Methods
```php
Liquidation::getTotalsByMethod($booking_id)
// Returns: ['mpesa_total', 'cash_total', 'credit_total', 'grand_total']
```

### Key Files
- `admin/include/Liquidation.php` — Model with M-Pesa support
- `admin/budget.php` — Budget overview with breakdown
- `admin/budget_add.php` — Record payment form
- `admin/budget_edit.php` — Edit payment
- `admin/budget_liquidate.php` — Liquidation view

## 6.5 Events & Calendar Module

### Kenyan Wedding Event Types
| Event | Color | Description |
|-------|-------|-------------|
| Ruracio Ceremony | 🔴 Red | Dowry negotiation ceremony |
| Church Wedding | 🟣 Purple | Church blessing/ceremony |
| Reception | 🟢 Green | Main reception (garden/hotel) |
| After-Party | 🟠 Orange | Late-night celebration |
| VC Meeting | 🔵 Blue | Wedding committee meeting |
| Civil Registration | ⚫ Dark | Government registration |
| Bridal Shower | 🩷 Pink | Pre-wedding bridal event |
| Engagement Party | 🟧 Orange | Engagement celebration |

### Venue Options
- Nairobi: Carnivore Grounds, Garden Wedding (Karen), Hotel (Serena/Sankara/Villa Rosa), KICC
- Mombasa: Diani Beach, Nyali/English Point
- Nakuru: Elementaita, Lake Naivasha
- Nyeri: Upcountry Home
- Kisumu: Lakeside Hotel
- Eldoret: Farm/Agricultural Society
- Kiambu: Church + Reception Hall
- Machakos: Open Air

### Key Files
- `admin/include/Events.php` — Model with prepared statements
- `admin/task_calendar.php` — FullCalendar with Kenyan events
- `admin/task_event_save.php` — Save event (CSRF-protected)
- `admin/task_editEventTitle.php` — Edit event title
- `admin/task_editEventDate.php` — Edit event date

## 6.6 Gallery Module

### Features
- Upload photos linked to bookings
- Image validation (type, size, duplicates)
- Automatic file naming
- Photo deletion (DB + physical file)
- Gallery view with DataTables

### Key Files
- `admin/include/Gallery.php` — Model with image handling
- `admin/photos_add.php` — Upload form
- `admin/photos_edit.php` — Edit photo details
- `admin/photos_view.php` — View gallery
- `admin/photo_delete.php` — Delete photo

## 6.7 Blog/Events Module

### Features
- Showcase real Kenyan weddings
- Card-based display on frontend
- Status: draft/published
- Location and date tracking

### Sample Content
- "A Modern Ruracio in Nyeri"
- "Beach Wedding in Diani, Mombasa"
- "Garden Wedding at Lake Naivasha"
- "Traditional Kikuyu Wedding in Murang'a"

### Key Files
- `admin/include/EventWedding.php` — Blog model
- `admin/blog_events.php` — Blog list
- `admin/blog_events_add.php` — Add post
- `admin/blog_events_edit.php` — Edit post
- `admin/blog_events_delete.php` — Delete post

## 6.8 Packages Module

### Kenyan Wedding Packages
| Package | Price (KSh) | Features |
|---------|-------------|----------|
| Traditional Ruracio/Dowry | 150,000 | Nyama Choma, Bridal Train, Upcountry Transport, M-Pesa, MC/DJ |
| Church Wedding | 250,000 | Church Decor, Bridal Car, Wedding Cake, Photography |
| Civil Registration | 50,000 | Marriage Certificate, Witness Arrangement |
| Reception Package | 350,000 | Venue Hire, Catering, MC & Entertainment, Tents & Chairs |
| After-Party Celebration | 100,000 | DJ & Sound, Drinks & Beverages, Late Night Snacks |

### Key Files
- `admin/include/Categories.php` — Package model
- `admin/include/Features.php` — Feature model
- `admin/package_add.php` — Add package
- `admin/package_edit.php` — Edit package
- `admin/package_delete.php` — Delete package
- `admin/feature_add.php` — Add feature
- `admin/feature_edit.php` — Edit feature
- `admin/feature_delete.php` — Delete feature

---

# 7. MONETIZATION MODULE

## 7.1 Subscription System (SaaS)

### Plans

| Plan | Price (KSh/mo) | Weddings | Key Features |
|------|----------------|----------|--------------|
| **Starter** | 2,999 | 3/year | Guest list, Budget tracker, Basic calendar |
| **Professional** | 7,999 | Unlimited | All features, M-Pesa, SMS, Gallery |
| **Agency** | 14,999 | Unlimited | Multi-user, White-label, API, Priority support |

### Subscription Lifecycle
```
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│  SIGNUP  │───→│  ACTIVE  │───→│  EXPIRED │───→│ RENEWED  │
│          │    │          │    │          │    │          │
│ User picks│    │ Full     │    │ Grace    │    │ Back to  │
│ plan     │    │ access   │    │ period   │    │ active   │
└──────────┘    └──────────┘    └──────────┘    └──────────┘
```

### Payment Flow
1. User selects plan on subscription.php
2. Modal shows M-Pesa payment instructions
3. User pays via Paybill 123456, Account: WPMS
4. User enters M-Pesa transaction code (10 chars)
5. System creates subscription record (30-day validity)
6. Code verified automatically via the Daraja C2B callback
   (see §7.6 M-Pesa Verification) — with `WPMS_MPESA_VERIFY=1` an
   unverified/wrong-amount code is refused at entry; without it, entry
   behaves as before and an admin reviews the M-Pesa Ledger

### Subscription Check on Login
Staff landings resolve through the shared helper in `admin/include/init.php`,
which both login pages use for the POST redirect and the signed-in GET guard:
```php
// admin/include/init.php
function login_redirect_target(bool $staff, int $user_id, bool $from_admin_dir = false): string
{
    if ($staff) {
        $page = Subscription::get_active($user_id) ? 'dashboard.php' : 'subscription.php';
        return $from_admin_dir ? $page : 'admin/' . $page;
    }
    return $from_admin_dir ? 'client.php' : 'admin/client.php';
}
```

### Key Methods
```php
Subscription::get_active($user_id)     // Get current active subscription
Subscription::is_active($user_id)      // Boolean check
Subscription::create_subscription(...) // Create new subscription
Subscription::get_plan($plan_slug)     // Get plan details
Subscription::get_all_plans()          // Get all active plans
```

### Key Files
- `admin/include/Monetization.php` — Subscription class
- `admin/subscription.php` — Subscription UI

---

## 7.2 Vendor Marketplace

### Vendor Categories
| Category | Icon | Examples |
|----------|------|----------|
| Catering | 🍽️ | Nyama Choma Masters, Pilau Plus |
| Decor | 🌸 | Floral Dreams, Elegant Decor |
| Photography | 📷 | Flash Photography KE, Lens Masters |
| Music | 🎵 | DJ Mix, Live Band Kenya |
| Transport | 🚌 | Safari Transport, Matatu Hire |
| MC | 🎤 | MC Ken, Master of Ceremonies |
| Venue | 🏨 | Diani Beach, Carnivore Grounds |
| Cakes | 🎂 | Sweet Cakes Nairobi |
| Flowers | 🌹 | Flower Palace |
| Other | 📦 | Miscellaneous |

### Vendor Registration Flow
```
1. User clicks "Register as Vendor" in marketplace
2. Fills business info, category, location
3. Adds service packages (name, price, description)
4. Submits for admin approval
5. Admin approves/rejects
6. Approved vendors appear in marketplace
```

### Commission Structure
- **Standard**: 10% commission on bookings through Everafter Planner
- **Premium Vendors**: 15% commission (higher visibility)
- Commission stored in `tblvendor_bookings.commission`

### Vendor Availability (Double-Booking Check)
A vendor booked for two weddings on one Saturday is a wedding-day failure, so
every door a deal is made at asks it: **is this vendor already committed that
day?**

- The day a deal occupies is its own `event_date`, else the day of the function
  it is scoped to, else the wedding's date.
- Only **confirmed** or **completed** deals at **another** wedding count. A
  pending enquiry is not an assignment, a cancelled deal is history, and two
  deals at the same wedding are one wedding's supplier working one day.
- **Planner confirm** (`admin/vendor_enquiries.php`) refuses the confirmation
  until *Book them anyway* is ticked, then writes the acknowledgement and the
  other wedding into the notes trail.
- **Couple enquiry** (`admin/client_vendor_enquiry.php`) is still recorded, but
  the couple sees the warning and the planner's bell leads with it.
- **Planner inquiry** (`admin/vendors.php`) saves the warning on the deal's
  notes as well as the toast.
- **Vendor dashboard** (`admin/vendor_home.php`) shows "You are also booked" on
  the row before Accept. Vendors are never blocked: a studio with two teams can
  cover two weddings, and they are the ones who know their capacity. The
  planner is told when they accept anyway.
- **Coverage board** (`admin/vendor_coverage.php`) marks a booked cell whose only
  covering vendor is committed elsewhere that day: red chip with a warning icon,
  red cell, and a list above the board naming the function, service, vendor and
  the other wedding, each linking to the deal. The headline cards carry a
  **Double-booked cover** count (grey at zero, red with the number of booked
  services it comes from when not) so the risk is visible before scrolling; click
  it to open the board filtered to just the functions at risk, with a **Show all
  functions** link to get the full matrix back. The cell still counts as booked
  (the deal exists); a cell covered by two confirmed
  vendors where only one clashes is not marked, because somebody unencumbered is
  covering it.

No table is involved — the check is computed from `tblvendor_bookings` with one
query per page (`VendorAvailability::commitments()`).

### The Wedding's Vendor Plan (v2.29)
The coverage board answers "what does this wedding still need", the enquiry
ledger answers "what has been asked of whom", and the budget records what has
been **paid**. None of them answers the question a couple asks first: *who is
doing our wedding, and what does it all come to?* **Vendor Plan**
(`admin/vendor_plan.php`, staff only) is that answer for one wedding.

- **It is a view, not a table.** `tblvendor_bookings` already means "this
  wedding chose this vendor", so `VendorPlan::build()` groups the wedding's
  deals by the ten categories and totals them. A second plan table would be a
  second truth about the same money.
- **Three figures, kept apart.** *Committed* is confirmed or completed deals;
  *Awaiting reply* is pending ones; *cancelled* deals stay on the record with
  their amount and are never added. The total is committed + awaiting reply, so
  an unanswered enquiry never reads as a cost the couple has taken on.
- **Against what the couple said.** Each category shows the cap from their
  Taste & Budget (v2.28) and how much is left, the headline compares the total
  with the package price, and a category over its cap is red.
- **A row says what the money bought.** v2.29 adds `service_id` and
  `service_qty`, so a line reads *Classic buffet × 180 guests — KSh 1,200 /
  guest*, not just a figure. Package deals name their package; a custom
  arrangement reads "custom arrangement".
- **Adding a vendor sends the enquiry.** The picker lists every approved
  vendor's active services and packages in category order, priced with the same
  unit line the marketplaces use. Submitting (POST + CSRF) creates the same
  **pending** deal a marketplace enquiry creates and bells the vendor, so the
  vendor sees it on My Bookings and the planner confirms the amount in Vendor
  Enquiries as usual. Availability clashes are checked at the same moment and
  written into the deal's notes.
- **Money is never recomputed.** An amount the planner types stands, and where
  the vendor has since re-priced a *pending* service the row says "their price
  is now X" instead of rewriting the deal. A confirmed amount is an agreement,
  not arithmetic.
- **No total is invented.** An offering with no published price needs an agreed
  amount typed in, and a per-hour or per-km service needs the hours or the
  distance — the page asks for the number rather than counting the deal at zero.
- **The couple reads the same plan.** **Vendor Plan & Total**
  (`admin/client_vendor_plan.php`) is read-only and shows their caps, their
  confirmed and waiting figures, and the same total; nothing on it can change
  what a vendor was promised.
- **And it is on their dashboard.** The same total is a **Vendor Costs** tile on
  `admin/client.php`: the cost, what is left of the couple's caps (or of the
  package price until they set caps), and "over your budget" stated in words in
  red rather than left for them to work out. The tile links to the plan. Staff
  dashboards carry no such tile — they list every wedding, so one vendor figure
  there would mix couples together.
- **Without v2.29** package deals and agreed amounts still build and total, the
  page says which migration is missing, and only "plan a specific service" is
  refused.

No table is involved. The only storage is the two columns on the deal
(`migrations/v2.29_vendor_plan.sql`).

### Premium Vendor Features
- Priority listing (appears first)
- "Premium" badge on profile
- Unlimited photos
- Analytics dashboard
- Price: KSh 3,000/month

### Key Methods
```php
Vendor::get_approved($category)  // Get approved vendors
Vendor::get_by_user($user_id)    // Get vendor by owner
```

### Key Files
- `admin/include/Monetization.php` — Vendor class
- `admin/include/VendorAvailability.php` — same-day commitment check and its wording
- `admin/include/VendorSignup.php` — public self-registration (account + unapproved listing + services + package, one transaction)
- `vendor_signup.php` — the public sign-up page
- `admin/vendor_register.php` — profile form for invited vendors
- `admin/vendors.php` — Marketplace UI
- `admin/vendor_enquiries.php` — Deal ledger, confirm gate

---

## 7.3 SMS Center

### SMS Packages
| Package | Credits | Price (KSh) |
|---------|---------|-------------|
| Basic | 100 | 999 |
| Standard | 500 | 2,499 |
| Premium | Unlimited/mo | 4,999 |

### SMS Cost Calculation
- 1 SMS = 160 characters
- Cost: KSh 1 per SMS
- Long messages split: ceil(length / 160) × KSh 1

### Quick Templates
1. **Wedding Invitation**: "You are invited to [Bride] & [Groom] wedding on [Date] at [Venue]..."
2. **Transport Reminder**: "Your wedding transport departs [Pickup] at [Time]..."
3. **Harambee Thanks**: "Thank you for your generous contribution of KSh [Amount]..."
4. **Day Before Reminder**: "[Bride] & [Groom] wedding is tomorrow!..."

### Send Options
- **All Guests**: Bulk send to entire guest list
- **Upcountry Only**: Guests with out_of_town = 'y'
- **VIP Only**: Guests with priority = 'A'

### Phone Formatting
- Accepts: 0712345678, +254712345678, 254712345678
- Normalizes to: 254712345678 (Africa's Talking format)

### Africa's Talking Integration
```php
// In production, set API key:
SMS::africastalking_send($phone, $message);
// Uses: https://api.africastalking.com/version1/message
```

### Key Methods
```php
SMS::send($phone, $message, $user_id, $booking_id)
SMS::send_bulk($booking_id, $message, $user_id)
SMS::calculate_cost($message)
SMS::format_phone($phone)
SMS::get_credits($user_id)
SMS::add_credits($user_id, $credits)
SMS::deduct_credits($user_id, $credits)
```

### Key Files
- `admin/include/Monetization.php` — SMS class
- `admin/sms_center.php` — SMS UI

---

## 7.4 Wedding Websites

### Features
- Public-facing pages at `/wedding/site.php?slug=bride-groom`
- Hero section with cover image
- Countdown timer
- Couple's story section
- Photo gallery grid
- RSVP form
- View tracking

### URL Structure
```
http://localhost/Wedding/site.php?slug=john-mary
```

### Slug Generation
```php
// Auto-generated from bride + groom names
WeddingWebsite::generate_slug('John', 'Mary');
// Returns: john-mary (or john-mary-1 if exists)
```

### RSVP Flow
1. Guest visits wedding website
2. Fills RSVP form (name, attending, guest count, dietary)
3. Submission stored (can be extended to email admin)

### Key Methods
```php
WeddingWebsite::get_by_slug($slug)
WeddingWebsite::get_by_booking($booking_id)
WeddingWebsite::generate_slug($bride, $groom)
WeddingWebsite::increment_views($id)
```

### Key Files
- `admin/include/Monetization.php` — WeddingWebsite class
- `site.php` — Public wedding website template

---

## 7.6 M-Pesa Verification (Daraja C2B Callback)

M-Pesa transaction codes entered on `subscription.php` (plan purchases) and
`budget_add.php` (couple payments) are verified **automatically** against the
payments Safaricom reports to the public callback endpoint `mpesa_c2b.php` —
no admin copy-pasting.

### How it works
```
Customer pays Paybill 123456 / Account WPMS
        │
        ▼
Safaricom POSTs the payment (TransID, amount, MSISDN, BillRefNumber…)
        │
        ▼
mpesa_c2b.php  — validates + stores it in tblmpesa_callbacks
        │
        ▼
User types the TransID (10-char code) into the app
        │
        ▼
MpesaC2B::verify() — matches code + amount (±1%) + account reference,
marks the callback consumed, and the subscription/payment is accepted
```

### Rules
- **Amount match** within `WPMS_MPESA_TOLERANCE` (default 1%, floor KSh 1) —
  a KSh 5,000 payment cannot buy a KSh 7,999 plan.
- **Account reference** — subscriptions expect the ref to mention `WPMS`;
  booking payments expect the booking number. Empty payer refs pass
  (payers often leave the account field blank).
- **Single use** — a verified payment can activate exactly one subscription
  or payment row; reuse is refused.
- **Append-only ledger** — every reported payment is kept forever; replays of
  the same Daraja notification are no-ops.

### Setup
1. Run `migrations/v2.1_phase1_hardening.sql` (creates `tblmpesa_callbacks`
   + `tblmpesa_callback_log`).
2. Register `https://your-domain/mpesa_c2b.php` as the Validation **and**
   Confirmation URL on the Daraja portal.
3. Set the environment variables (see README §M-Pesa Daraja C2B verification):
   `WPMS_MPESA_VERIFY=1`, `WPMS_MPESA_SHORTCODE`, optionally
   `WPMS_MPESA_CALLBACK_PASS` and `WPMS_MPESA_TOLERANCE`.

### Admin review — the M-Pesa Ledger
`admin/mpesa_ledger.php` shows every reported payment: **verified** (what it
activated), **pending** (nobody claimed it yet — an admin can Accept it
manually after a phone check), **rejected** (the user-typed code failed:
wrong amount, wrong account, already used, or unknown code). The underlying
Safaricom payment is never deleted. With `WPMS_MPESA_VERIFY` off (dev/demo),
entry behaves as before while the ledger still records everything — flip the
flag in production without a code change.

### Key Methods
```php
MpesaC2B::ingest(array $payload)                  // store a Daraja notification
MpesaC2B::verify(string $code, float $amount, string $ref)  // gate a typed code
MpesaC2B::mark_consumed(int $callback_id, string $table, int $id)
MpesaC2B::record_reject(string $code, string $reason, float $amount)
MpesaC2B::pending_review() / MpesaC2B::stats()
MpesaC2B::verification_enabled()                  // WPMS_MPESA_VERIFY
```

### Key Files
- `mpesa_c2b.php` — public callback endpoint (Daraja contract: HTTP 200 +
  `ResultCode` JSON; never 500s, so Safaricom never retry-spams)
- `admin/include/Monetization.php` — MpesaC2B class
- `admin/mpesa_ledger.php` — staff review queue
- `tests/MpesaC2BTest.php` — 14 tests (ingest, verify, replay, endpoint)

---

## 7.7 M-Pesa STK Push (Initiated-from-App Payments)

Beyond verifying typed codes, the app can start the payment itself: Daraja
prompts the customer's phone, the customer enters their M-Pesa PIN, and
Daraja reports the result to `mpesa_stk_callback.php`. No code typing at all.

### How it works
```
User clicks "Pay by M-Pesa prompt" (subscription) / "Pay now" (deposit)
        │
        ▼
MpesaStk::initiate() — row in tblmpesa_stk_pushes (status pending,
                        the PROMISE) + Daraja processrequest call
        │
        ▼
Customer's phone shows the prompt → they enter their PIN
        │
        ▼
Daraja POSTs the result to mpesa_stk_callback.php
        │
        ▼
MpesaStk::apply_callback() — stamps status + receipt, and on success
FULFILS: creates the subscription row (purpose=subscription) or books the
deposit on the couple's ledger (purpose=deposit) — exactly once
```

The push is a promise, not a payment. Only a success callback (ResultCode 0)
moves anything. Cancelled (1032), timed-out (1037) and failed prompts are
recorded and fulfil nothing.

### Rules
- **Exactly once** — fulfilment is guarded by the push status; replays of the
  same Daraja callback are no-ops.
- **Late success wins** — if the sweeper timed a push out (5 min, no callback)
  and Daraja then reports success, the push is reopened and fulfilled: the
  money actually moved, so the payment is never dropped.
- **Balance-guarded deposits** — a deposit push can't exceed the booking's
  remaining balance, and events are validated to belong to the booking.
- **Transport simulation** — without Daraja credentials the ledger and
  callback flow behave identically, but no phone is really prompted
  (dev/demo/test mode).

### Setup
1. Run `migrations/v2.1_phase1_hardening.sql` (creates `tblmpesa_stk_pushes`).
2. Set `WPMS_MPESA_STK=1` plus the Daraja credentials
   (`WPMS_MPESA_STK_CONSUMER_KEY`/`_SECRET`, `WPMS_MPESA_STK_PASSKEY`,
   shortcode via `WPMS_MPESA_STK_SHORTCODE`) — see README §M-Pesa STK push.
3. The CallBackURL is sent per request; it must be reachable at
   `https://your-domain/mpesa_stk_callback.php`.

### Key Methods
```php
MpesaStk::initiate(string $purpose, int $userId, string $phone, float $amount, array $context)
MpesaStk::apply_callback(string $checkoutRequestId, int $resultCode, string $resultDesc, ?string $mpesaReceipt)
MpesaStk::find(int $id) / latest_for_user(int $userId) / find_for_user(...)
MpesaStk::cancel_stale(int $minutes = 5): int
MpesaStk::enabled(): bool
```

### Key Files
- `mpesa_stk_callback.php` — public result callback (Daraja contract, never 500s)
- `admin/include/Monetization.php` — MpesaStk class
- `admin/stk_status.php` — polling status page ("Check your phone")
- `admin/mpesa_ledger.php` — STK pushes section (stats + recent pushes)
- `tests/MpesaStkTest.php` — 10 tests (initiate, fulfilment, replays, endpoint)

---

## 7.5 Revenue Summary

| Revenue Stream | Model | Price Range |
|----------------|-------|-------------|
| SaaS Subscriptions | Monthly recurring | KSh 2,999 – 14,999/mo |
| Vendor Commission | Per booking | 10-15% of booking value |
| SMS Credits | Per message | KSh 1/SMS (bulk packages) |
| Vendor Premium | Monthly recurring | KSh 3,000/mo |
| Wedding Websites | Per wedding | KSh 1,999 (premium feature) |
| White-label License | Setup + annual | KSh 150,000 – 500,000 |
| Data/Analytics | Per report or subscription | KSh 5,000 – 45,000/yr |

---

# 8. ADMIN WORKFLOWS

## 7.8 The Proposal Lifecycle (v2.30)

A couple's registration is the start of a conversation, not a booking. The
lifecycle turns it into a priced wedding they choose from and a confirmed plan:

```
registration → proposal → acknowledged → options (>= 3, priced) → chosen → locked (confirmed)
```

| Step | Who | Where | What happens |
|------|-----|-------|--------------|
| 1. Register | couple (public) | `index.php` (hero form) | Account, booking and **proposal** are created in one request. The optional "Tell us about the day" block adds guests, budget, venue, style, a line in their words and a preferred planner |
| 2. Pick it up | planner | `admin/proposals.php` → `admin/proposal_view.php` | **Acknowledge** claims it and tells the couple (bell + queued email) that a planner is on it |
| 3. Price it | planner | `admin/proposal_view.php` | Build **at least three options** from the same vendor offerings the marketplace and vendor plan use — a package, a service × quantity, or a custom agreed amount. Each option shows its own total |
| 4. Send them | planner | same page | **Send the options**; refused below three priced options, because one option is not a choice |
| 5. Choose | couple | `admin/client_proposal.php` | The three options side by side, each compared with their budget. **Choose**, **ask for a change** (in their words) or **decline** |
| 6. Lock it in | planner | `admin/proposal_view.php` | Re-checks every vendor's calendar and listing, creates one **pending deal per line** through the vendor plan's own door (so each vendor is notified), and confirms the wedding |

The seven states are `proposal`, `acknowledged`, `options`, `tweak_requested`,
`chosen`, `locked`, `declined`. Only the owner moves each one: the planner
acknowledges, prices and locks; the couple chooses, asks for a tweak or
declines. Every move is a POST + CSRF, authorises the role that owns it, and
rings the other side's bell.

**Why the proposal is not a booking status.** `tblaccounts_detail.status` stays
the platform's two-state record (`pending` / `confirm`). A proposal row lives
beside the booking: the booking stays `pending` while the couple decides and is
set to `confirm` only at lock — the same state `client_assign.php` has always
written. So nothing that reads a booking status changed, and a wedding confirmed
before v2.30 simply has no proposal.

**Two things the lock refuses.** A refused line (a vendor no longer approved, a
service hidden after the couple chose) rolls the whole thing back — no
half-confirmed wedding. A vendor already booked on another wedding that day
**blocks** the lock until the planner ticks "book them anyway" (the clash is then
recorded on the deal), because a double-booked wedding is the one failure the
couple would discover on the day.

**The checklist follows the choice.** Locking also seeds the wedding's
checklist: the Kenyan milestone template, plus one **"Confirm *vendor* —
*service*"** line per vendor in the chosen option (`Checklist::ensure_from_option`,
in its own *Your Vendors* phase, due three weeks out). The template's single
"Confirm every vendor in writing" line cannot stand for six suppliers — a couple
chasing them needs the supplier's name on the task. Seeding happens at **lock**,
not on first opening the checklist page, so the couple's dashboard can show real
progress the moment they are confirmed; both seeders are idempotent by title, so
a re-run, a late template addition or a couple who already ticked items loses
nothing.

**One progress calculation.** `Checklist::progress()` returns done, total, the
percentage, the next open item and the overdue count, and both the checklist
page and the couple's dashboard card render those numbers — they cannot
disagree. Overdue needs a wedding date to be late against (a wedding without a
date has nothing overdue, not everything), and once the day has passed nothing
nags. The function only reads: opening the dashboard never seeds, ticks or
resets anything, and the card is couple-only (staff dashboards list every
wedding, so one bar would mix couples up).

**Switching it off.** `WPMS_PROPOSAL_FLOW=0` in the environment turns the whole
lifecycle off: sign-up creates an ordinary pending booking and the proposal
pages say so. It is on by default, because a flow nobody can find is not a flow.
The migration is separate — without `migrations/v2.30_proposals.sql` the signup
still registers the couple and the pages name the missing migration.

## 7.9 Tiered Pricing on What a Vendor Sells (v3.1)

A vendor does not sell "catering at KSh 1,200": they sell a working buffet, one
with a live grill, and one with imported crockery and two chefs. With a single
price column, a planner comparing two caterers was comparing one vendor's
cheapest tier with the other's best, and calling the difference a bargain.

| Table | What it holds |
|-------|---------------|
| `tblvendor_service_tiers` | One row per (service, tier): `price`, `includes`, `excludes`. Cascades with the service |
| `tblvendor_package_tiers` | The same three levels for a whole-wedding bundle, kept as its own table so both keep a real foreign key |
| `tblproposal_items.tier` | Which level a quote line was priced at — `NULL` for custom arrangements and pre-v3.1 rows |

**The one rule that keeps everything working.** `tblvendor_services.price` and
`tblvendor_packages.price` stay the **Silver** price and are written whenever
tiers are saved (`VendorTier::save_service()` / `save_package()`). Every surface
that already reads a single price — marketplace cards, `VendorFit`'s
arithmetic, the coverage board, the vendor plan, the proposal picker — keeps
working and now means "entry level". A plain edit that posts one price is
followed by `VendorTier::sync_base()`, so the card can never drift away from the
cheapest level the vendor is actually offering.

**The rules on saving.** All three prices or none (a two-tier offer cannot be
lined up against another vendor's three); every price above zero; Silver <
Gold < Platinum. A tier a vendor has not priced cannot be chosen in the
builder — the line is refused rather than quietly charged at Silver while the
couple is told they bought Gold. Inclusions and exclusions are optional per
tier, and the "Leaves out" column is where the half of the deal couples usually
discover late finally gets written down.

**Where it shows.** Vendor → **My Services** and **My Packages** edit the three
levels; the planner's marketplace, the couple's marketplace and the public
profile print them under the offering's own unit; the proposal builder lists
each priced tier as its own choice ("Nyama Choma Masters — Classic buffet —
Gold KSh 1,500"), and the couple's options page reads the level back on the
line.

## 8.1 Daily Operations

### Morning Checklist
1. **Check Dashboard** — Review pending clients, upcoming weddings
2. **Review New Registrations** — client.php → Pending tab
3. **Assign Planners** — client_assign.php for new bookings
4. **Check SMS Credits** — Ensure sufficient balance
5. **Review Vendor Applications** — Approve/reject new vendors

### Client Management Workflow
```
1. New client registers on frontend
2. Admin sees notification on dashboard
3. Reviews client in client.php (Pending tab)
4. Clicks "Assign" → client_assign.php
5. Fills: bride, groom, wedding type, date, location, organizer
6. Sets status to "confirm"
7. Client moves to Confirmed tab
8. Now accessible for: guest list, budget, events, gallery
```

### Event Planning Workflow
```
1. Navigate to client → "Task Calendar"
2. Add events: Ruracio, Church, Reception, After-Party
3. Set dates, times, venues
4. Color-code by event type
5. View in month/week/day format
6. Edit events as needed
```

### Guest Management Workflow
```
1. Navigate to client → "Guest List"
2. Click "Add New Guest"
3. Fill: name, relationship, location, priority
4. Set: upcountry, transport, accommodation, harambee
5. View stats: total, upcountry, harambee total, transport
6. Send bulk SMS to all/upcountry/VIP
```

### Budget Tracking Workflow
```
1. Navigate to client → "Budget"
2. View summary: package price, paid, balance
3. Click "Record Payment"
4. Select event, method (Cash/M-Pesa/Bank)
5. Enter M-Pesa code if applicable
6. View payment breakdown by method
```

## 8.2 Monthly Tasks

### Subscription Management
1. Review expiring subscriptions
2. Send renewal reminders
3. Process M-Pesa payments
4. Update subscription records

### Vendor Management
1. Review new vendor applications
2. Approve/reject with notes
3. Set premium status for top vendors
4. Review commission reports

### Analytics
1. Total weddings this month
2. Revenue by source (subscriptions, commissions, SMS)
3. Top vendors by bookings
4. Average wedding budget by region

---

# 9. VENDOR WORKFLOWS

## 9.1 Getting Started

Vendors are staff accounts with `access_level 0` and sign in through the same
`login.php` door as everyone else. They land on their own self-service area,
not the staff dashboard.

### Registration (self-service, no invitation needed)
1. Open **`vendor_signup.php`** — linked from the "Are you a wedding vendor?"
   band on the public Vendor Marketplace, or send the link directly
2. Fill your account (name, email, phone, password), your business (name,
   category, description, town, county) and up to three services with the unit
   each price is quoted in (flat, per guest, per day…), plus one optional package
3. Submit — **your account works immediately** and you are signed in; the
   listing itself is created **pending approval**, and an administrator approves
   it from the staff Vendor Marketplace ("Awaiting approval")

Until it is approved, couples cannot see or enquire with you. You can still
prepare everything from your dashboard: add services on
`admin/vendor_services.php`, packages on `admin/vendor_packages.php`, and your
logo and details on `admin/vendor_profile.php`.

An invited vendor who already has a login but no business profile yet lands on
`admin/vendor_register.php` instead — same form, one step fewer.

### Your pages
| Page | What you do there |
|---|---|
| `admin/vendor_home.php` | Your bookings ledger: every couple enquiry, with Accept / Decline / Complete transitions and a notes trail |
| `admin/vendor_profile.php` | Business details + logo. Approval, premium status and ratings are admin-managed and read-only here |
| `admin/vendor_packages.php` | Add, edit, hide/publish and delete your packages (packages with past bookings are hidden, not deleted, to keep history) |
| `admin/vendor_register.php` | One-time registration for vendors who have no profile yet |

Everything is ownership-scoped: queries always join on your vendor id, so no
vendor can view or mutate another vendor's bookings or packages. Confirming
a booking stamps your agreed amount and notifies the couple's planner; the
staff ledger still records the 10-15% commission — vendors never touch it.

## 9.2 Managing Packages

### Package Structure
- **Name**: e.g. "Premium Catering Package"
- **Description**: What's included
- **Price**: In KSh
- **Capacity**: Max guests (optional)
- **Duration**: Hours of service (optional)

### Best Practices
- Offer 3 tiers (Basic, Standard, Premium)
- Include all costs (no hidden fees)
- Update seasonal pricing
- Add photos to each package

## 9.3 Receiving Bookings

### Booking Notification
1. Couple browses marketplace (approved vendors only)
2. Clicks "Enquire" and picks one of your active packages (or general enquiry)
3. The enquiry lands as a **pending** row on your `vendor_home.php` ledger and
   the couple's planner is notified
4. You accept (entering the agreed amount) or decline; the planner and the
   couple (bell + best-effort SMS) are told either way
5. After the wedding, mark the booking completed from the same ledger

### Commission
- Everafter Planner takes 10-15% commission
- Deducted automatically from booking amount
- Vendor receives net payout

---

# 10. COUPLE/CLIENT WORKFLOWS

## 10.1 Registration

### Step 1: Visit Website
```
http://localhost/Wedding/
```

### Step 2: Fill Registration Form
- First name, last name
- Email address
- Phone number
- Wedding date

### Step 3: Confirmation
- System checks date availability
- Creates pending booking
- Shows thank you page
- Admin reviews and contacts you

## 10.2 After Assignment

### What Happens Next
1. Admin assigns wedding planner
2. Planner contacts you for consultation
3. Full details entered into system
4. You get access to planning tools

### Planning Tools Available
- Guest list management
- Budget tracking
- Event calendar
- Vendor marketplace
- SMS invitations
- Wedding website

## 10.3 Wedding Website

### Access
```
http://localhost/Wedding/site.php?slug=your-names
```

### Features
- Share with guests
- RSVP tracking
- Photo gallery
- Countdown timer
- Venue details

---

# 11. SECURITY FEATURES

## 11.1 Authentication

| Feature | Implementation |
|---------|---------------|
| Password Hashing | bcrypt via password_hash() |
| Password Verification | password_verify() |
| Session Management | PHP sessions with regeneration |
| Session Fixation | Regenerate ID every 30 minutes |
| Brute Force | Built-in throttle on login.php: 5 failed attempts per (email, IP) pair locks it — 15 min, escalating to 1 h then 6 h for repeat offenders; owner texted on every lockout; per-IP cap of 20 catches distributed guessing; success clears; `WPMS_LOGIN_THROTTLE_OFF=1` disables (§11.7) |

## 11.2 CSRF Protection

| Feature | Implementation |
|---------|---------------|
| Token Generation | random_bytes(32) → bin2hex |
| Token Storage | $_SESSION['_token'] |
| Form Embedding | $session->csrf_field() hidden input |
| Verification | hash_equals() timing-safe comparison |
| Coverage | All POST forms |

## 11.3 SQL Injection Prevention

| Feature | Implementation |
|---------|---------------|
| Prepared Statements | mysqli_prepare + bind_param |
| Input Escaping | mysqli_real_escape_string (legacy) |
| Input Casting | (int) for all ID parameters |
| Affected Methods | Login, Guest queries, Events, Liquidation, Vendors |

## 11.4 XSS Prevention

| Feature | Implementation |
|---------|---------------|
| Output Encoding | htmlspecialchars() on all output |
| Input Sanitization | clean() → htmlentities(strip_tags(trim())) |
| CSRF Token Output | htmlspecialchars() on token value |

## 11.5 Access Control

| Feature | Implementation |
|---------|---------------|
| Session Check | isset($_SESSION['id']) on every admin page |
| Subscription Check | Subscription::get_active() on login |
| Redirect to Login | redirect_to("../") if not authenticated |

## 11.6 Security Recommendations

1. **Enable HTTPS** — Install SSL certificate
2. **Backup** — Daily database backups
3. **File Uploads** — Validate MIME types, limit size
4. **Error Display** — Already handled: errors are hidden from visitors unless
   `WPMS_DEBUG=1` is set in the environment (everything still goes to the
   PHP error log). Never set `WPMS_DEBUG=1` on a public host.
5. **Database User** — Use limited-privilege MySQL user
6. **Admin Path** — Consider renaming /admin/ to custom path

## 11.7 Login Rate Limiting (built in)

Failed sign-ins on `login.php` are throttled by the app itself (`LoginThrottle`
class, storage `tbllogin_throttle` — run `migrations/v2.1_phase1_hardening.sql`):

- **5 failed attempts** for one (email, IP) pair locks that pair; the login
  form shows a message naming the wait. The lock is keyed on the email as
  typed (case-insensitive), so it also covers attempts against accounts that
  don't exist.
- **Lockouts escalate for repeat offenders**: the first lock serves
  **15 minutes**, the second **1 hour**, and every lock after that **6 hours**
  (`LoginThrottle::LOCKOUT_STEPS`). When a lock expires a fresh run of 5
  failures is needed to lock again — but the escalation memory survives until
  the account actually signs in successfully (which forgives the ladder) or
  the row is pruned after 7 days.
- **The account owner is texted on every lockout** (system SMS, cost 0.00 —
  never billed to planner credits): couples on their `tblaccounts_detail`
  number, staff on the optional `tblusers.phone` column (add it in the staff
  profile). The message names the lockout count and the cool-down and points
  to a password reset. Attempts against unknown emails notify nobody, and
  every send is audited in `tblsms_logs`.
- **Per-IP cap of 20 recent failures** across all accounts — a host spraying
  many addresses is locked out too, even for untouched accounts.
- A **successful sign-in clears** that pair's counters and its escalation
  memory — a user who mistyped a few times is not haunted by stale failures.
- Rows older than **7 days** are pruned automatically.
- Set `WPMS_LOGIN_THROTTLE_OFF=1` (environment or `SetEnv`) to disable the
  whole throttle — maintenance mode or shared test suites. The client IP comes
  from `REMOTE_ADDR` only; behind a reverse proxy, rate-limit at the proxy or
  set the kill switch.
- Staff monitor it under **Login Security** (`admin/login_activity.php`, staff
  sidebar): currently **locked pairs** with their lock number, escalation
  window and remaining cool-down, and a one-click **Unlock** (forgives that
  pair's counters — use for a genuine user you recognize, not an attacker
  mid-campaign), **IP pressure** per source address toward the cap with
  **Release** for shared-office NATs, the raw
  **recent failed attempts** list, and a **Prune** button for idle rows. Every
  unlock/release is written to the PHP error log with the acting admin's
  email. With `WPMS_LOGIN_THROTTLE_OFF=1` the page becomes read-only.

---

# 12. CONFIGURATION REFERENCE

## 12.1 Database Configuration
`admin/include/config.php` no longer hard-codes credentials — it reads environment variables with XAMPP dev fallbacks (host `localhost`, user `root`, empty password, db `dbwedding`):
```php
// admin/include/config.php
// Effective constants resolve as: env value -> fallback default
// WPMS_DB_HOST -> 'localhost'
// WPMS_DB_USER -> 'root'
// WPMS_DB_PASS -> ''
// WPMS_DB_NAME -> 'dbwedding'
```
Set the variables per environment (never commit real credentials):
```bash
# CLI / cron / tests
export WPMS_DB_HOST=localhost WPMS_DB_USER=db_user WPMS_DB_PASS=secret WPMS_DB_NAME=dbwedding
```
```apache
# Apache vhost or .htaccess (mod_env)
SetEnv WPMS_DB_HOST "localhost"
SetEnv WPMS_DB_USER "db_user"
SetEnv WPMS_DB_PASS "secret"
SetEnv WPMS_DB_NAME "dbwedding"
```
```yaml
# Docker Compose
environment:
  - WPMS_DB_HOST=mysql
  - WPMS_DB_USER=db_user
  - WPMS_DB_PASS=secret
  - WPMS_DB_NAME=dbwedding
```

## 12.2 Path Configuration
**Nothing to change.** `admin/include/init.php` derives the paths from
`__DIR__`, so any deployment layout works as-is:
```php
// admin/include/init.php (automatic — do not edit)
define('SITE_ROOT', dirname(__DIR__));   // …/Wedding/admin
define('INCLUDES_PATH', __DIR__);        // …/Wedding/admin/include
```

## 12.3 Timezone
```php
// admin/include/init.php
date_default_timezone_set('Africa/Nairobi');
```

## 12.4 M-Pesa Configuration
```php
// For subscription payments
Paybill: 123456
Account: WPMS

// For Daraja API (future)
Consumer Key: [from Safaricom Developer Portal]
Consumer Secret: [from Safaricom Developer Portal]
Passkey: [from Safaricom Developer Portal]
Shortcode: 174379 (sandbox) or your Till/Paybill
```

## 12.5 Africa's Talking Configuration
```php
// In SMS::africastalking_send()
$username = 'wpms';  // Your AT username
$api_key = '';       // Your AT API key (get from dashboard)
```

## 12.6 File Upload Limits
```php
// php.ini settings
upload_max_filesize = 10M
post_max_size = 10M
max_file_uploads = 20
```

---

# 13. CRON JOBS & AUTOMATION

## 13.1 Subscription Expiry Check
```bash
# Run daily at midnight
0 0 * * * /usr/bin/php /path/to/Wedding/cron/subscription_expiry.php
```

```php
<?php
// cron/subscription_expiry.php
include '../admin/include/init.php';

// Mark expired subscriptions
$db->query("UPDATE tblsubscriptions SET status = 'expired' WHERE expiry_date < CURDATE() AND status = 'active'");

// Log the run
error_log("Subscription expiry check completed at " . date('Y-m-d H:i:s'));
```

## 13.2 SMS Credit Reminder
```bash
# Run weekly on Mondays
0 9 * * 1 /usr/bin/php /path/to/Wedding/cron/sms_reminder.php
```

```php
<?php
// cron/sms_reminder.php
include '../admin/include/init.php';

// Find users with low credits
$sql = "SELECT u.id, u.email, sc.credits FROM tblusers u 
        JOIN tblsms_credits sc ON sc.user_id = u.id 
        WHERE sc.credits < 10";
$result = $db->query($sql);

while ($row = mysqli_fetch_object($result)) {
    // Send reminder email (implement email sending)
    // mail($row->email, "Low SMS Credits", "You have {$row->credits} credits remaining.");
}
```

## 13.3 Database Backup
```bash
# Run daily at 2 AM
0 2 * * * /usr/bin/mysqldump -u root dbwedding > /backups/wedding_$(date +\%Y\%m\%d).sql
```

## 13.4 Analytics Aggregation
```bash
# Run monthly on 1st
0 3 1 * * /usr/bin/php /path/to/Wedding/cron/analytics.php
```

---

# 14. TROUBLESHOOTING

## 14.1 Common Issues

### White Screen / 500 Error
```
1. Check PHP error log: C:\xampp\logs\php_error_log
2. Check Apache error log: C:\xampp\logs\error.log
3. Verify file permissions on admin/upload/ directories
4. Check database connection (admin/include/config.php)
5. Temporarily set WPMS_DEBUG=1 in the environment to see the real error
   on screen (dev machines only — turn it off afterwards)
```

### Database Connection Failed
```
1. Verify MySQL is running in XAMPP
2. Confirm the WPMS_DB_* environment variables are set (see section 12.1); fallbacks assume XAMPP defaults
3. Test the connection manually: mysql -h HOST -u USER -pPASS dbwedding
```

### Session Not Persisting
```
1. Check session.save_path in php.ini
2. Verify cookies are enabled in browser
3. Check if output before session_start()
```

### File Upload Fails
```
1. Check upload_max_filesize in php.ini
2. Check folder permissions (writable by Apache)
3. Check file type (only images allowed)
4. Check for duplicate filenames
```

### SMS Not Sending
```
1. Verify Africa's Talking API key is set
2. Check username is 'wpms' (sandbox) or your production username
3. Verify phone format (254XXXXXXXXX)
4. Check SMS credits balance
```

### Subscription Not Activating
```
1. Verify M-Pesa code is 10 characters
2. Check tblsubscriptions table for new record
3. Verify expiry_date is set correctly
4. Check for PHP errors in error log
```

## 14.2 Performance Optimization

### Database
```sql
-- Add indexes for frequently queried columns
CREATE INDEX idx_booking_id ON tblguest(booking_id);
CREATE INDEX idx_booking_id ON tbl_liquidation(booking_id);
CREATE INDEX idx_booking_id ON events(booking_id);
CREATE INDEX idx_user_id ON tblsubscriptions(user_id);
CREATE INDEX idx_status ON tblsubscriptions(status);
CREATE INDEX idx_slug ON tblwedding_websites(slug);
```

### PHP
```php
// php.ini optimizations for production
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
realpath_cache_size=4096K
```

### Apache
```apache
# Enable compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript
</IfModule>

# Enable caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
</IfModule>
```

## 14.3 Support Contacts

| Issue | Contact |
|-------|---------|
| Technical Support | support@wpms.co.ke |
| Billing/Subscription | billing@wpms.co.ke |
| Vendor Applications | vendors@wpms.co.ke |
| M-Pesa Integration | payments@wpms.co.ke |
| Africa's Talking | support@africastalking.com |

---

# APPENDIX

## A. Database ERD (Entity Relationship)

```
tblusers ──┬── tblsubscriptions ─── tblsubscription_payments
           ├── tblsms_credits
           ├── tblsms_logs
           └── tblvendors ──┬── tblservice_catalog  (the platform's vocabulary)
                            ├── tblvendor_services (what this business sells)
                            ├── tblvendor_packages (bundles it sells)
                            └── tblvendor_bookings

tblaccounts ─── tblaccounts_detail ─── tblweddingbook ──┬── tblguest
                                                        ├── tblvendor_preferences
                                                        ├── tbl_liquidation
                                                        ├── events
                                                        ├── tbleventbooking
                                                        ├── tblgallery
                                                        └── tblwedding_websites

tblweddingcategories ─── tbl_features

tblpostwedding (standalone blog)
```

## B. API Endpoints (Future)

| Endpoint | Method | Description |
|----------|--------|-------------|
| /api/v1/bookings | GET | List all bookings |
| /api/v1/bookings/:id | GET | Get booking details |
| /api/v1/guests | GET | List guests |
| /api/v1/vendors | GET | List vendors |
| /api/v1/payments | POST | Record payment |
| /api/v1/sms | POST | Send SMS |

## C. Glossary

| Term | Definition |
|------|------------|
| **Harambee** | Kenyan tradition of community contribution/gathering |
| **Ruracio** | Traditional Kikuyu/Kamba dowry negotiation ceremony |
| **Mzee** | Respectful term for elder (Swahili) |
| **Mgeni wa Mbali** | Guest from far/upcountry |
| **VC** | Wedding committee/organizing group |
| **Kitenge** | Colorful African fabric worn at weddings |
| **Shuka** | Maasai traditional cloth, popular at Kenyan weddings |
| **Nyama Choma** | Roasted meat, staple at Kenyan celebrations |
| **Pilau** | Spiced rice dish served at celebrations |
| **Paybill** | M-Pesa business payment number |
| **Till Number** | M-Pesa merchant payment number |
| **STK Push** | M-Pesa prompt sent to customer's phone |
| **Daraja** | Safaricom's API platform for M-Pesa integration |

---

**Document Version**: 2.0
**Last Updated**: September 2026
**Author**: Everafter Planner Development Team
**License**: Proprietary — All rights reserved
