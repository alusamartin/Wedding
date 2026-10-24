# Running it in XAMPP — login details, roles, and what each role can actually do

A hands-on operator guide for the stock Windows/XAMPP install
(`C:\xampp\htdocs\Wedding`). Every login, landing page and page gate below was
executed against the running Apache/PHP 8.2.12 + MySQL on this machine; the
commands are in [Verification](#verification) so any line can be re-run.

For the full feature walkthrough see [`README.md`](../README.md) (quick start)
and [`WPMS_KENYA_MANUAL.md`](../WPMS_KENYA_MANUAL.md) (reference manual). This
document is the *access* half: who logs in, where they land, what they may open.

---

## 1. Start it

| Step | What to do |
|---|---|
| 1 | XAMPP Control Panel → **Start Apache** → **Start MySQL** |
| 2 | Public site → <http://localhost/Wedding/> |
| 3 | **The only login door** → <http://localhost/Wedding/login.php> |

Nothing else is required: `admin/include/config.php` falls back to the stock
XAMPP values (`localhost` / `root` / empty password / `dbwedding`) when
`WPMS_DB_*` is unset, and `admin/include/init.php` resolves the install root
from its own location — so the folder may be renamed or a vhost docroot without
editing a path.

`admin/login.php` is **retired**: it answers `301` and redirects to the single
door. `sign_up.php` likewise `301`s to the hero form on the home page, which is
the one client-facing registration entry point.

Before the app will upload anything, these folders must be writable by Apache:
`admin/upload/{categories,events,gallery,users}/`.

---

## 2. Login details (all verified against the running app)

One door, one form, every role. Passwords are bcrypt hashes in the seed; the
plaintexts below were checked with `password_verify()` against the live
`dbwedding` rows: all seven staff accounts and all five couples (the couples
share one bcrypt hash, and `password_verify()` accepts it for every one of
them).

### Staff (`tblusers`)

| Role | `access_level` | Email | Password |
|---|---|---|---|
| Super Admin | 2 | `wafula@wpms.co.ke` | `Admin@2024!` |
| Super Admin (second operator) | 2 | `abdi@wpms.co.ke` | `Admin@2024!` |
| Wedding Planner | 1 | `amina@wpms.co.ke` | `Test@1234` |
| Planner | 1 | `njeri@wpms.co.ke` | `Test@1234` |
| Planner | 1 | `otieno@wpms.co.ke` | `Test@1234` |
| Vendor Lead | 0 | `kamau@nyamachoma.co.ke` | `Vendor@2024` |
| Vendor | 0 | `chebet@floral.co.ke` | `Vendor@2024` |

### Couples (`tblaccounts` — the client table, `access_level '0'`)

| Role | Email | Password |
|---|---|---|
| Couple | `brian.wafula@gmail.com` | `Test@1234` |
| Couple | `faith.njeri@yahoo.com` | `Test@1234` |
| Couple | `otieno.kisumu@outlook.com` | `Test@1234` |
| Couple | `chebet.eldoret@gmail.com` | `Test@1234` |
| Couple | `abdi.garissa@gmail.com` | `Test@1234` |

> ⚠️ The legacy template login (`admin@mail.com` / `Password@123`, from the
> pre-bcrypt dump) **does not authenticate** on this build — its hash predates
> bcrypt. Use the accounts above.

### Where each one lands (measured, not read off the code)

| Account | Lands on | Why |
|---|---|---|
| `wafula@wpms.co.ke` (super admin) | `admin/dashboard.php` | operator; never sent to the paywall |
| `abdi@wpms.co.ke` (super admin) | `admin/dashboard.php` | same |
| `amina@wpms.co.ke` (planner) | `admin/subscription.php` | her seeded plan expired 2026-10-03 |
| `njeri@wpms.co.ke` (planner) | `admin/subscription.php` | no subscription row at all |
| `otieno@wpms.co.ke` (planner) | `admin/subscription.php` | no subscription row at all |
| `kamau@nyamachoma.co.ke` (vendor) | `admin/vendor_home.php` | has a vendor profile |
| `chebet@floral.co.ke` (vendor) | `admin/vendor_home.php` | has a vendor profile |
| `brian.wafula@gmail.com` (couple) | `admin/client.php` | the couple's own bookings |
| `faith.njeri@yahoo.com` (couple) | `admin/client.php` | same |

`dbwedding` is a database seeded on 2026-09-03, and the seeded plans run 30
days: every plan in it has since expired, which is exactly why the three
planners above stop at the subscription page. A database rebuilt with
`scripts/demo_reset.php` today has `active` plans (`start 2026-10-16`,
`expiry 2026-11-15` for users 5 and 7), so `amina@wpms.co.ke` lands on
`admin/dashboard.php` as expected. See [§6](#6-resetting-to-a-clean-demo-state).

Routing rules, in the order the login page applies them (all in
[`login.php`](../login.php) and [`login_redirect_target()`](../admin/include/init.php)):

1. **Client** (`tblaccounts`) → `admin/client.php`.
2. **Vendor** (`tblusers`, `access_level 0`) → `admin/vendor_home.php`, or
   `admin/vendor_register.php` when the account has no vendor profile yet.
   Subscription rules never apply to vendors — they are paid by commission.
3. **Staff** (`access_level 1` or `2`) → `admin/dashboard.php`, **except** when
   the plan is locked (never paid, or expired past the grace window) or the
   trial is on its last day: then `admin/subscription.php` first. Super admins
   are exempt from this gate — the operator must always be able to reach the
   screen that renews everyone else.
4. An unverified mailbox (self-serve planner signup) stops at
   `verify_planner.php` instead of starting a session.

**A planner stuck on the subscription page:** the seed's subscription dates are
relative to the day it was imported, so a database seeded weeks ago shows every
plan expired and planners cannot reach the dashboard. Renew from
`admin/subscription.php` with the **Activate Plan** form. On this dev box
`MpesaC2B::verification_enabled()` and `MpesaStk::enabled()` are both `false`,
so the form accepts any 10-character code (e.g. `QGH1234567`) — turn
`WPMS_MPESA_VERIFY=1` in `.env` to require a code Safaricom actually reported.

---

## 3. What the roles are

The whole model is one column and one table:

| | Account row | `access_level` | Session marker |
|---|---|---|---|
| Super Admin | `tblusers` | `2` | `account_type=staff`, `privilege_level=2` |
| Wedding Planner | `tblusers` | `1` | `account_type=staff`, `privilege_level=1` |
| Vendor | `tblusers` | `0` | `account_type=staff`, `privilege_level=0` |
| Couple / client | `tblaccounts` | `'0'` | `account_type=client` |

The predicates live in [`Helper.php`](../admin/include/Helper.php) and are
deliberately picky, because clients and vendors share `access_level 0`:

- `is_staff()` — true for `privilege_level >= 1`.
- `is_vendor()` — only when `account_type` is `staff` **and** the level is `0`,
  so a couple is never mistaken for a vendor.
- `is_super_admin()` — level `2`. Bypasses the subscription paywall.

### 3.1 Super Admin — the SaaS operator

The same back office as a planner, plus the things a planner must not be able to
do. Measured on the vendor directory: the pending-listing block and the approve
control appear for the operator and for nobody else (the page body carries the
unapproved vendor 1× and the word "approve" 5×; the guards behind that are
`if (!is_super_admin())` on both actions and the same call gating the
pending-listings query, so there is no path for any other role).

- **Plan Definitions** (`admin/subscription_plans.php`) — the price list
  `subscription.php` sells. Operator-only on purpose: a plan's price is platform
  revenue, not a planner's setting, and every edit is written to
  `tblsubscription_plan_changes` with the field, old/new value and operator id.
  A planner asking for the page is refused (`302 → /`).
- **Vendor approval and premium placement** (`vendors.php`) — the actions
  answer `Only the operator can approve a vendor listing` / `…change a vendor's
  placement` for anyone else, and the pending-listings block is shown to the
  operator alone.
- **Paywall exemption** — never locked out by subscription state, so the person
  who renews everyone else's plan can always reach the screen that does it.
- **User Management** (`users.php`), **Revenue**, **M-Pesa Ledger**, **Login
  Security** and the rest are shared staff pages: they are guarded by
  `require_staff_subscribed()`, not by `is_super_admin()` (so a *locked* planner
  is diverted from them to `subscription.php`, see [§4](#4-page-map-who-is-refused-what)).

### 3.2 Wedding Planner — the working account

The full staff back office:

- **Dashboard**, **Clients** (`client.php`), client onboarding (`client_add.php`),
  assignment of an organizer (`client_assign.php`).
- **Budgets** (`budget.php`, `budget_liquidate.php`, `receipt.php` — KSh,
  branded shareable M-Pesa receipts) and **installment plans**.
- **Guest list** (`guest_list.php`), **RSVP dashboard**, WhatsApp queue,
  bulk SMS reminders and **SMS Center** (needs credits).
- **Task calendar** and the venue sheet (`sheet_venue.php`), including the
  per-function **headcount plan**.
- **Vendor Marketplace**, **Vendor Enquiries** (confirm a deal, 10%
  commission, mark completed, cancel), **Capacity Headroom** (`headroom.php`).
- **Blogs & Events**, **Services**, **Gallery**, **Upload Photos**.
- **Revenue**, **Subscription Plans** (their own subscription and renewals),
  **M-Pesa Ledger**, **Login Security**.

A planner is billed: no active plan (or one past the one-day grace window) turns
the back office read-only and then redirects them to `admin/subscription.php`.

### 3.3 Vendor — self-service, scoped to their own business

Vendors get a *different* four-item sidebar, not a trimmed copy of the planner's:

| Page | What it is |
|---|---|
| `vendor_home.php` | their own bookings ledger and deal transitions |
| `vendor_profile.php` | their business profile (name, category, county, logo) |
| `vendor_packages.php` | the packages they sell |
| `vendors.php` | the marketplace, read-only |

They are paid by commission, so no subscription applies; a new vendor account
without a profile is routed to `vendor_register.php` first. Their ledger is
scoped to their own rows — a vendor does not see other vendors' deals, and
`client.php` bounces them straight back to `vendor_home.php`.

### 3.4 Couple / client — the customer

One page is the hub: **`admin/client.php`** — their wedding booking, package,
payment progress, guest count and budget total, with the links into everything
else they may see:

- `client_budget.php`, `client_guests.php`, `client_checklist.php`,
  `client_vendors.php` / `client_vendor_enquiry.php` (their enquiries),
  `client_website.php` (their public wedding page),
  `client_manage_account_details.php`, `client_installment_plan.php`.
- `notifications.php` — the bell, shared with staff.
- **Public, no login at all:** their wedding website (`site.php?slug=…` —
  verified public for the seeded `brian-linda`) and the token RSVP form
  (`rsvp.php?token=…`).
- The **receipt** is *not* public: `admin/receipt.php?id=…` needs a session and
  checks the booking against the signed-in user (a couple may open only their
  own wedding's receipt; staff may open any). It is shared the way the family
  actually shares it — screenshot it or forward the page.

**These sub-pages are per-booking.** Opened bare they redirect back to
`client.php`; they need `?booking_id=…&user_id=…`, and the page checks that the
booking belongs to the signed-in session. Verified: Brian (user 200) opens
`client_budget.php?booking_id=200&user_id=200` → `200`, but the same page for
Faith's booking 201 → `302` back to `client.php`, even when he hands in
`user_id=201`. His own dashboard renders his name and never Faith's.

Couples cannot reach the back office at all: `budget.php`, `guest_list.php`,
`rsvp_dashboard.php`, `users.php` and `dashboard.php` all `302` to the site root
for a client session.

---

## 4. Page map: who is refused what

Measured with a real signed-in session per role. `302` targets are the gate
that refused the request.

| Page | Super Admin | Planner | Vendor | Couple | Anonymous |
|---|---|---|---|---|---|
| `dashboard.php` | ✅ | ✅ (or `subscription.php` when locked) | → `/` | → `/` | → `/` |
| `client.php` | ✅ | ✅ | → `vendor_home.php` | ✅ (own only) | → `/` |
| `subscription_plans.php` (Plan Definitions) | ✅ | → `/` | → `/` | → `/` | → `/` |
| `vendor_home.php` / `vendor_profile.php` / `vendor_packages.php` | → `/` | → `/` | ✅ | → `/` | → `/` |
| `vendors.php` (marketplace) | ✅ | ✅ | ✅ | → `/` | → `/` |
| `users.php`, `budget.php`, `vendor_enquiries.php` | ✅ | ✅ | → `/` | → `/` | → `/` |
| `client_budget.php` etc. (with own booking context) | ✅ | ✅ | → `/` | ✅ | → `/` |

Guards behind that table: `require_staff_subscribed()` on the planner/admin
pages, `require_vendor()` on the vendor pages, and `staff_paywall_locked()`
inside the couple-facing pages, all in
[`init.php`](../admin/include/init.php) / [`Helper.php`](../admin/include/Helper.php).

**A planner without an active plan is diverted from every staff page it owns.**
Measured on `dbwedding`: `vendors.php` and `users.php` answer
`302 → admin/subscription.php` for `njeri@wpms.co.ke`. That is by design — the
paywall — and it is why the planner rows above were exercised on a database
whose plans are live (`docs/ROLE_SIMULATION_REPORT.md` P4, 62 checks: a paying
planner reaches the staff pages, P5 records the locked planner being turned away
from 10 of them, P7 records the operator-only capabilities).

> **Per-planner scoping is now enforced** (`Helper.php`,
> `WPMS_PLANNER_SCOPING`, on by default). A planner sees the weddings assigned
> to them (`tblweddingbook.organizer_id`) plus the unassigned ones; a super
> admin sees every wedding. `client.php` filters its list, `vendor_enquiries.php`
> scopes its ledger, its tallies and its POST handlers, and
> `admin/include/init.php` refuses any other staff page that names a
> `booking_id` outside the planner's scope. `Booking::getBooking()` itself stays
> unscoped — the model records the platform. Earlier simulation reports written
> against the pre-scoping build are historical, not current.

---

## 5. Gotchas that stop people on day one

| Symptom | Cause / fix |
|---|---|
| "Invalid email and password" on an account that exists | Passwords are case-sensitive; wrong hashes in a DB restored from the *pre-bcrypt* dump never authenticate |
| "Too many failed login attempts … try again in N minutes" | `LoginThrottle`: 5 failures per (email, IP) pair, 20 per IP, first window 15 minutes, repeating offenders get 1 h then 6 h. A *successful* login clears the counters. `WPMS_LOGIN_THROTTLE_OFF=1` disables it (maintenance only) |
| Planner keeps getting bounced to the subscription page | Plan locked — renew on that page (see §2). Super admins are exempt |
| Vendor sees the planner sidebar / a couple sees nothing after login | Check `tblusers.access_level` (`0` = vendor) and that `account_type` was stamped at login; a missing `tblvendors` row sends a vendor to `vendor_register.php` |
| A page renders an error and no SQL detail | `WPMS_DEBUG` is off (correct for a shared machine) — set `WPMS_DEBUG=1` in `.env` for a dev box, and read PHP's error log either way |
| Emails/SMS silently do nothing | `WPMS_MAIL_ENABLED=0` (messages land in `tbl_mail_logs`, retried by the mail cron) and SMS needs `tblsms_credits` |

Environment knobs (`.env` in the project root, or one level above the web root —
real environment variables win): `WPMS_DB_*`, `WPMS_DEBUG`, `WPMS_SITE_URL`,
`WPMS_MAIL_ENABLED` / `WPMS_MAIL_FROM`, `WPMS_MPESA_*` (C2B verify and STK),
`WPMS_SUB_AUTO_RENEW` / `WPMS_SUB_GRACE`, `WPMS_TRIAL_DAYS` / `WPMS_TRIAL_NURTURE`,
`WPMS_LOGIN_THROTTLE_OFF`, `WPMS_CRON_BRIDGE_KEY`. See
[`.env.example`](../.env.example).

---

## 6. Resetting to a clean demo state

If a session has left the demo data unusable (a probe account, a stray
subscription), rebuild it rather than patching rows:

```bash
php scripts/demo_reset.php --yes --db=wpms_sim   # scratch DB for tests
php scripts/demo_reset.php --yes                 # rebuild dbwedding itself
```

`demo_reset.php` drops and recreates the target database, imports the base
schema, the Kenyan schema, the Kenya-only seed and the migrations in README
order, then **date-shifts every seeded wedding forward in whole 52-week steps
until it is in the future** and rebases payment history to wedding−30d. Without
that shift a fresh import shows past weddings: countdowns, RSVP reminders and
calendars all demo wrong. It finishes by verifying the bcrypt hashes are intact
(60 chars) and that a seeded login really authenticates, so it fails loudly
instead of leaving you with an unloggable demo.

---

## Verification

Run on this machine, PHP 8.2.12, Apache serving `http://localhost/Wedding`.

| Check | Command | Result |
|---|---|---|
| The app is being served | `curl -s -o /dev/null -w '%{http_code}' http://localhost/Wedding/login.php` | `200` (root also `200`) |
| The retired admin door | `curl` `admin/login.php` | `301 → http://localhost/Wedding/login.php` |
| Every seeded password | `password_verify()` against `tblusers` / `tblaccounts` on `dbwedding` | **12/12 accounts VERIFIED** — 7 staff (`Admin@2024!`, `Test@1234`, `Vendor@2024`) and 5 couples (`Test@1234`) |
| Real login per role | CSRF token + cookie jar, POST to `login.php`, follow the redirect | super admins → `admin/dashboard.php` (200); 3 planners → `admin/subscription.php` (200); 2 vendors → `admin/vendor_home.php` (200); 2 couples → `admin/client.php` (200); a wrong password returns to `login.php` |
| Couple isolation | `client_budget.php?booking_id=200&user_id=200` vs `booking_id=201…` as Brian | own booking `200`; the other couple's `302 → admin/client.php`; the rendered dashboard contains "Brian" and not "Faith" |
| Vendor scoping | vendor session on the page set | `vendor_{home,profile,packages}.php` + `vendors.php` `200`; `client.php` `302 → vendor_home.php`; `dashboard.php`, `users.php`, `budget.php`, `vendor_enquiries.php` `302 → /` |
| Couple refusals | couple session on staff pages | `budget.php`, `guest_list.php`, `rsvp_dashboard.php`, `users.php`, `dashboard.php` all `302 → /` |
| Operator-only gate | planner session on `subscription_plans.php` | `302 → /` |
| Operator-only actions | `vendors.php` body as operator vs planner | pending listing + approve control present for the operator only (1/5 vs 0/0) — the planner session was itself paywalled out of the page (`302 → admin/subscription.php`) |
| Fresh demo is not paywalled | `tblsubscriptions` on `wpms_sim` (rebuilt 2026-10-16) | users 5 and 7 `active`, `2026-10-16 → 2026-11-15`; user 8 `expired` |
| M-Pesa switches in this dev env | `MpesaC2B::verification_enabled()`, `MpesaStk::enabled()` | both `false`; `LoginThrottle` active |
| Public pages really are public | anonymous `rsvp.php?token=x`, `site.php?slug=brian-linda` | `200` (and `site.php` with no slug `302 → /`) |
| Receipts are not public | anonymous `admin/receipt.php?booking_id=200&user_id=200` | `302 → /` |
| Seeded plans on `dbwedding` | `tblsubscriptions` | user 5 `agency` and user 7 `professional` both expired 2026-10-03 (hence the planner paywall); user 8 `starter` expired 2026-08-24 |

### Limitations and what this pass changed

- The planner "own clients" filter described in `WPMS_KENYA_MANUAL.md` §5 was
  NOT enforced when this pass was measured; it is now (see the caveat at the
  top of §4). The behaviour recorded above is the pre-scoping behaviour and is
  kept as the record of that pass.
- Docs updated alongside this guide: `WPMS_KENYA_MANUAL.md` §3.9 (four demo
  accounts → all twelve, with the landing-page caveat) and §5 (the "Own"
  caveat), plus the README's seeded-accounts table (six → twelve rows).
- Landing pages were measured on `dbwedding` with its current subscription
  dates. On a DB whose plans are active, planners land on `admin/dashboard.php`
  instead of the subscription page — the role simulation's P4/P5/P7 phases cover
  both states and are the authority for a live plan.
