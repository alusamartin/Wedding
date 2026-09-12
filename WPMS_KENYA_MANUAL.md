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
- **Vendors** — List services, receive bookings, manage packages
- **Couples** — Register weddings, track planning, share wedding websites

## Key Features

| Feature | Description |
|---------|-------------|
| Client Management | Full CRM for wedding clients with booking lifecycle |
| Guest List Management | Kenyan-specific: harambee tracking, upcountry guests, transport |
| Budget & Payments | M-Pesa integration, payment tracking by method |
| Event Calendar | FullCalendar with Kenyan wedding event types |
| Vendor Marketplace | Categorized vendor listings with booking system |
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
│   ├── vendor_register.php       ← Vendor registration form
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
In `admin/include/init.php`, ensure:
```php
$webroot = "C:/xampp/htdocs/";
```

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
| Wedding Planner | `amina@wpms.co.ke` | `Test@1234` | `admin/dashboard.php` |
| Couple (client) | `brian.wafula@gmail.com` | `Test@1234` | `admin/client.php` |
| Couple (client) | `faith.njeri@yahoo.com` | `Test@1234` | `admin/client.php` |

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

## Core Tables (11)

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

## Monetization Tables (11)

### tblsubscriptions — User Subscriptions
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| user_id | INT FK | Admin user ID |
| plan | ENUM('starter','professional','agency') | Plan type |
| amount | DECIMAL(10,2) | Amount paid |
| mpesa_code | VARCHAR(20) | M-Pesa code |
| start_date | DATE | Subscription start |
| expiry_date | DATE | Subscription expiry |
| status | ENUM('active','expired','cancelled') | Status |
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
| is_active | TINYINT(1) | Active status |

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
| capacity | INT | Guest capacity |
| duration_hours | INT | Service duration |
| is_active | TINYINT(1) | Active status |
| created_at | TIMESTAMP | Creation time |

### tblvendor_bookings — Vendor Bookings
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| vendor_id | INT FK | Vendor ID |
| booking_id | INT FK | Wedding booking ID |
| package_id | INT FK | Package ID |
| event_date | DATE | Event date |
| amount | DECIMAL(10,2) | Booking amount |
| commission | DECIMAL(10,2) | Everafter Planner commission |
| status | ENUM('pending','confirmed','completed','cancelled') | Status |
| notes | TEXT | Notes |
| created_at | TIMESTAMP | Creation time |

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

| Feature | Super Admin | Planner | Vendor | Couple |
|---------|-------------|---------|--------|--------|
| Dashboard | ✅ | ✅ | ❌ | ❌ |
| Client Management | ✅ All | ✅ Own | ❌ | ❌ |
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
- `admin/vendors.php` — Marketplace UI
- `admin/vendor_register.php` — Registration form

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

### Registration (self-service)
1. Log in at `login.php` with your vendor account — first-time vendors land
   on `admin/vendor_register.php` automatically
2. Fill business information (name, category, phone, location, county)
3. Add one or more service packages (name + price, KSh)
4. Submit — the listing enters **pending approval**; an administrator
   approves it from the staff Vendor Marketplace

While unapproved you can already edit your profile and packages on
`admin/vendor_profile.php` and `admin/vendor_packages.php`; couples simply
cannot see or enquire with you yet.

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
| Brute Force | Built-in throttle on login.php: 5 failed attempts per (email, IP) pair locks it for 15 minutes; per-IP cap of 20 catches distributed guessing; success clears; `WPMS_LOGIN_THROTTLE_OFF=1` disables (§11.7) |

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
4. **Error Display** — Set display_errors=Off in production
5. **Database User** — Use limited-privilege MySQL user
6. **Admin Path** — Consider renaming /admin/ to custom path

## 11.7 Login Rate Limiting (built in)

Failed sign-ins on `login.php` are throttled by the app itself (`LoginThrottle`
class, storage `tbllogin_throttle` — run `migrations/v2.1_phase1_hardening.sql`):

- **5 failed attempts** for one (email, IP) pair locks that pair for
  **15 minutes**; the login form shows a message naming the wait. The lock is
  keyed on the email as typed (case-insensitive), so it also covers attempts
  against accounts that don't exist.
- **Per-IP cap of 20 recent failures** across all accounts — a host spraying
  many addresses is locked out too, even for untouched accounts.
- A **successful sign-in clears** that pair's counters — a user who mistyped a
  few times is not haunted by stale failures.
- Rows older than **7 days** are pruned automatically.
- Set `WPMS_LOGIN_THROTTLE_OFF=1` (environment or `SetEnv`) to disable the
  whole throttle — maintenance mode or shared test suites. The client IP comes
  from `REMOTE_ADDR` only; behind a reverse proxy, rate-limit at the proxy or
  set the kill switch.
- Staff monitor it under **Login Security** (`admin/login_activity.php`, staff
  sidebar): currently **locked pairs** with their remaining cool-down and a
  one-click **Unlock** (forgives that pair's counters — use for a genuine user
  you recognize, not an attacker mid-campaign), **IP pressure** per source
  address toward the cap with **Release** for shared-office NATs, the raw
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
```php
// admin/include/init.php
$webroot = "C:/xampp/htdocs/";  // Change for production
define('DS', DIRECTORY_SEPARATOR);
define('SITE_ROOT', $webroot.DS.'Wedding'.DS.'admin');
define('INCLUDES_PATH', SITE_ROOT.DS.'include');
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
3. Verify file permissions on upload/ directories
4. Check database connection in config.php
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
           └── tblvendors ─── tblvendor_packages
                            └── tblvendor_bookings

tblaccounts ─── tblaccounts_detail ─── tblweddingbook ──┬── tblguest
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
