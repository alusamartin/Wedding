# Everafter Planner — Wedding Planning Management System

Kenyan-market edition of the Wedding Planner Management System (v2.1): package
catalog + sign-up hero, client/couple portal, planner & vendor admin area,
wedding bookings, guest lists (with token RSVP links + automatic SMS
reminders), budgets (KSh), branded shareable M-Pesa receipts, calendar,
subscriptions, and M-Pesa-ready pricing. Modernized 2026 theme
(`css/modern.css`), PDO-based data layer, hardened sessions (CSRF,
fingerprinting, audit log).

> 📖 The full operator manual lives in [`WPMS_KENYA_MANUAL.md`](WPMS_KENYA_MANUAL.md)
> (feature walkthrough, directory map, API/troubleshooting). This README is the
> quick start.

---

## Requirements

| Dependency | Version | Notes |
|---|---|---|
| PHP | **^8.2** | `pdo`, `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl` extensions (see `composer.json`) |
| MySQL / MariaDB | 5.7+ | utf8mb4 collation used throughout |
| Web server | Apache (XAMPP) | Project hard-codes the XAMPP path — see below |
| Composer | any recent | Only needed for dev tooling/tests |

**Location doesn't matter any more.** `admin/include/init.php` resolves the
install root from its own location, so the project runs from any folder name,
drive, or host — `C:\xampp\htdocs\Wedding\`, a vhost docroot,
`/home/user/public_html`, a Docker container — with **zero path edits**.
(XAMPP at `C:\xampp\htdocs\Wedding\` still works exactly as before.)

---

## Installation

### 1. Start XAMPP
Start **Apache** and **MySQL** from the XAMPP Control Panel.

### 2. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE dbwedding CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 3. Import schema + data (fresh install — order matters)

```bash
# (a) Base schema & original sample data  — creates all core tables
mysql -u root -p dbwedding < "DATABASE FILE/dbwedding.sql"

# (b) Kenyan schema additions — KSh categories, Kenyan guest columns, M-Pesa fields
mysql -u root -p dbwedding < kenyan_schema.sql

# (c) Kenya-only audited seed — purges US/legacy demo rows, inserts Kenyan
#     data + v2.1-only tables (subscriptions, vendors, SMS, session audits)
mysql -u root -p dbwedding < "DATABASE FILE/seed_v2.1_full_test_KENYA_ONLY.sql"

# (c2) Freshen demo dates — idempotent; shifts every seeded wedding forward in
#      whole 52-week steps until it is in the future, rebases payment history
#      to wedding−30d, and recomputes the calendar widget row. Without this,
#      a fresh import shows past weddings: countdowns, RSVP reminders and
#      calendars all demo wrong. Safe to re-run any time (k=0 when already
#      future). A pre-shift backup is written to
#      DATABASE FILE/pre_date_shift_backup.sql on every run.
php scripts/shift_seed_dates.php

# (d) Optional but recommended — idempotent v2.1 hardening helpers
mysql -u root -p dbwedding < migrations/v2.1_phase1_hardening.sql

# (e) Optional — subscription auto-renewal, 7-day grace + SMS dunning columns
mysql -u root -p dbwedding < migrations/v2.2_subscription_renewals.sql

# (f) Optional — self-serve planner registration + 14-day trial ('trial' ENUM,
#     wider password column for bcrypt)
mysql -u root -p dbwedding < migrations/v2.3_planner_trial.sql

# (g) Optional — signup abuse protection: per-IP/per-email signup damper and
#     email verification before the trial starts
mysql -u root -p dbwedding < migrations/v2.4_signup_abuse_guard.sql

# (h) Optional — trial nurture email band marker (check-in + last-call)
mysql -u root -p dbwedding < migrations/v2.5_trial_nurture.sql
```

**Already running a v2.0 database?** Apply (b), (d), (e), (f), (g) and (h) only. Skip (a)/(c),
which reset demo data to the Kenyan seed set.

`DATABASE FILE/dbwedding.sql` is the original phpMyAdmin dump; the Kenya seed
purges its US/Philippine-era rows and replaces them with Kenyan counties,
venues and KSh pricing — don't be surprised if those disappear after (c).

### 3b. One-command demo reset (rebuild + freshen in a single step)

```bash
composer demo:reset              # rebuilds dbwedding from seed, date-shifts, NO prompt
composer demo:reset -- --db=demo_scratch   # build a scratch DB instead, live DB untouched
php scripts/demo_reset.php       # plain invocation: asks you to type the DB name first
php scripts/demo_reset.php --dry-run       # show the plan, change nothing
```

This chains the whole install order above (base schema → Kenyan schema →
Kenyan seed → hardening migration → v2.2 renewal migration) and finishes with
the date-shift so a
fresh demo always has future weddings. Requires the `mysql` CLI (found in
PATH or the XAMPP defaults). Every run verifies 5/5 seed weddings are
future-dated and prints the demo logins; it fails loudly otherwise.

### 4. Database credentials (no edit needed on XAMPP)

`admin/include/config.php` reads credentials from environment variables and
falls back to the stock XAMPP dev defaults (`localhost` / `root` / empty
password / `dbwedding`):

| Env var | Default |
|---|---|
| `WPMS_DB_HOST` | `localhost` |
| `WPMS_DB_USER` | `root` |
| `WPMS_DB_PASS` | *(empty)* |
| `WPMS_DB_NAME` | `dbwedding` |

For staging/production set these in the server environment (Apache `SetEnv`,
CLI `export`, Docker `environment:`). Never commit real credentials — see
section 12.1 of the manual for full examples.

Staff notification emails (the planner deposit alert) use the same pattern:

| Env var | Default | Effect |
|---|---|---|
| `WPMS_MAIL_ENABLED` | *(off)* | Set to `1` to actually deliver via PHP `mail()` — off, every alert is only logged |
| `WPMS_MAIL_FROM` | `no-reply@everafterplanner.co.ke` | From header on notification emails |
| `WPMS_SITE_URL` | *(empty)* | Base URL used to build absolute deep links in email bodies |

### M-Pesa Daraja C2B verification (automatic code checking)

Subscription purchases (`subscription.php`) and payment recording
(`budget_add.php`) accept M-Pesa transaction codes. With the callback
verification enabled, a code is only accepted when it matches a real Paybill
payment that Safaricom reported to the public callback endpoint
(`mpesa_c2b.php`) — no admin copy-pasting:

| Env var | Default | Effect |
|---|---|---|
| `WPMS_MPESA_VERIFY` | *(off)* | `1` = reject unverified codes at entry (dev/demo stays off) |
| `WPMS_MPESA_SHORTCODE` | *(any)* | Your Paybill/Till number — callbacks for other short codes are refused |
| `WPMS_MPESA_CALLBACK_PASS` | *(off)* | Optional shared secret; endpoint must be called as `mpesa_c2b.php?key=<secret>` |
| `WPMS_MPESA_TOLERANCE` | `0.01` | Amount match tolerance (fraction; default 1%) |

### M-Pesa STK push (pay without leaving the app)

Beyond passively verifying typed codes, the app can **initiate** payments:
Daraja prompts the customer's phone ("Enter M-Pesa PIN"), the customer pays,
and Daraja reports the result to `mpesa_stk_callback.php`. The result is
applied exactly once — a successful push creates the subscription or books
the booking deposit automatically, with the Daraja receipt as the reference.
Planners get a **Pay by M-Pesa prompt** button on the subscription page;
couples get **Pay now** on their deposit page.

| Env var | Default | Effect |
|---|---|---|
| `WPMS_MPESA_STK` | *(off)* | `1` = show the STK buttons and enable `MpesaStk::initiate()` |
| `WPMS_MPESA_STK_CONSUMER_KEY` / `_SECRET` | *(off)* | Daraja app credentials; **without them the transport is simulated** (dev/demo: rows behave identically, no phone is really prompted) |
| `WPMS_MPESA_STK_SHORTCODE` | falls back to `WPMS_MPESA_SHORTCODE` | Business short code the prompt collects into |
| `WPMS_MPESA_STK_PASSKEY` | *(off)* | Daraja Lipa na M-Pesa Online passkey |
| `WPMS_MPESA_STK_ENV` | `sandbox` | `production` switches to `api.safaricom.co.ke` |
| `WPMS_MPESA_STK_CALLBACK_PASS` | *(off)* | Optional shared secret; callback must be called as `mpesa_stk_callback.php?key=<secret>` |

Setup: run the migration (`tblmpesa_stk_pushes`), set the credentials, and
register `https://your-domain/mpesa_stk_callback.php` as the push CallBackURL
(the app sends it per-request). Pending pushes that are never answered are
swept to `timeout` (5 minutes) — but a success callback arriving after a
sweep is still honoured and fulfilled: money actually moved. The staff
M-Pesa Ledger shows every push and what each fulfilled one created.

With `WPMS_MPESA_STK` off (dev/demo default) the buttons disappear and the
code-entry flow behaves exactly as before.

### Subscription auto-renewal, 7-day grace & SMS dunning

A lapsed planner used to hit a hard wall at login. v2.2 adds the standard SaaS
middle ground — remind first, lock out last:

| Env var | Default | Effect |
|---|---|---|
| `WPMS_SUB_AUTO_RENEW` | *(off)* | `1` = cron STK-pushes the renewal before expiry (`scripts/subscription_renewals.php`) |
| `WPMS_SUB_RENEW_DAYS` | `3` | Renewal window: prompt when expiry is this many days out |
| `WPMS_SUB_GRACE` | *(off)* | `1` = lapsed staff keep READ-ONLY access for a grace window (banners, pay-any-time) |
| `WPMS_SUB_GRACE_DAYS` | `7` | Grace window length in days |

How it fits together:

- **Auto-renew (STK):** each run, staff whose newest subscription row is
  active and inside the renewal window get an M-Pesa prompt on their saved
  number (`tblusers.phone` — set it on the subscription page). Before the
  prompt, the row is cloned as a `pending_renewal` promise; the success
  callback extends the SAME row +30 days from its own expiry (never from
  today — a payment landing mid-grace never shortens entitlement) and flips
  the promise back to active. Failed/missing-phone prompts fall back to a bell
  notification; dead promises are swept so nothing pins the state.
- **SMS dunning:** texts at 7/3/0 days before expiry (and on day 3 of grace)
  with the plan, amount and a renew pointer — one text per band ever
  (`renewal_sms_band` marker, same semantics as the couple deadline bands),
  plus a bell notification per text. Auto-renew-OFF planners are reminded too.
- **Grace instead of a wall:** with `WPMS_SUB_GRACE=1`, `login_redirect_target()`
  and `require_staff_subscribed()` let a lapsed staff member into the staff
  area with a red read-only banner (days remaining + Renew link). Past grace
  (or never paid) the hard redirect to `subscription.php` returns, unchanged.
  `subscription.php` and `stk_status.php` are always reachable.
- **Toggle:** the subscription page has an auto-renew ON/OFF switch (per-row,
  on by default) and a phone field — both CSRF-protected.

Setup: run the migration (`migrations/v2.2_subscription_renewals.sql` — adds
`tblsubscriptions.auto_renew`, `tblsubscriptions.renewal_sms_band`,
`tblusers.phone`), then enable the env vars and add the cron:

```cron
30 8 * * * php /path/to/Wedding/scripts/subscription_renewals.php --quiet
```

The trial nurture leg needs `WPMS_TRIAL_NURTURE=1` (plus a reachable
`WPMS_SITE_URL` for its links); emails queue as `pending` in
`tbl_mail_logs` until `WPMS_MAIL_ENABLED=1`, then `scripts/mail_retry.php`
or the next cron run delivers them.

Both legs are single-flight locked, `--dry-run`-able, and no-ops (exit 0)
when their switches are off — safe to leave in the crontab across
environments. Requires `WPMS_MPESA_STK=1` with Daraja credentials for the
prompt leg; the dunning leg rides the normal SMS outbox.

### Self-serve planner registration & 14-day trial

Planners no longer need an admin to seed an account. The public page
`planner_register.php` (linked from the homepage hero and the login form)
creates the staff account and opens a **full-feature trial in one
transaction**, then signs the new planner straight into the activation page.

- **The trial is a zero-amount subscription row** (`plan='trial'`,
  `status='trial'`, amount 0.00) — so every existing gate, banner and expiry
  calculation treats it as ordinary access with no special-casing in the
  pages themselves. `Subscription::state()` flags `trial: true` for the UI.
- **Funnel:** sign up → **verify your email** (the activation link emailed at
  signup) → auto-login → `subscription.php` (trial banner + plan cards) →
  M-Pesa purchase (code entry or STK push) → dashboard. Routing is
  centralised in `login_redirect_target()`: a trial on its **final day** also
  lands on the activation page, and an expired trial (after grace) locks the
  same way a lapsed subscription always did.
- **Welcome email:** the moment verification opens the trial, the planner
  also gets a getting-started email through the `tbl_mail_logs` outbox —
  their trial end date and the first three steps (add a client, build the
  guest list + budget, send a vendor enquiry). Same outbox discipline as the
  verification email: `pending` and retryable by `scripts/mail_retry.php`
  when `WPMS_MAIL_ENABLED` is off.
- **Nurture emails (cron LEG 3):** with `WPMS_TRIAL_NURTURE=1`, the renewal
  cron emails every planner on a live trial a **check-in at the halfway
  point** (7 days left on the default 14-day trial) with getting-started
  tips, and a **last-call the day before expiry**. One email per band ever
  (`trial_nurture_band` marker, stamp-first), converted planners are never
  emailed, lapsed trials are skipped, and every email also rings the in-app
  bell. Expiry day itself stays with the SMS dunning leg (WPMS_SUB_GRACE).
- **One trial per account, ever** — enforced by `start_trial()` (a live
  subscription or existing trial refuses a second), so deleting the trial row
  cannot farm free months.
- **Trials stay out of the renewals machine:** they are excluded from the
  STK auto-renew leg and the 7/3-day dunning bands; they get exactly ONE
  expiry-eve text (conversion nudge with the entry price) plus a bell
  notification, charged to the planner's SMS credits like any other text.
- **Cross-table email uniqueness:** registration refuses an email already
  present in `tblusers` OR `tblaccounts` — couples and staff share one login
  door, and one address must never hold two passwords.

Setup: run the migration (`migrations/v2.3_planner_trial.sql` — adds the
`'trial'` ENUM members and widens `tblusers.password` to VARCHAR(255) so
bcrypt hashes fit; the legacy seed's truncated storage would otherwise break
self-serve sign-ins). Trial length is tunable:

| Env var | Default | Effect |
|---|---|---|
| `WPMS_TRIAL_DAYS` | `14` | Trial length in days granted at verification |

### Signup abuse protection (throttle + email verification)

The registration page mints paid-product entitlements with no human in the
loop, so v2.4 gates it like the login door:

- **Per-IP / per-email signup damper** (`tblsignup_throttle`): EVERY signup
  POST is counted — successes included, because the flood itself is the
  attack. Per email **3** per rolling hour, per IP **10**; tripped, the page
  shows the same backoff message as the login throttle. Shares the
  `WPMS_LOGIN_THROTTLE_OFF` kill switch and the 7-day prune.
- **Email verification before the trial** (`tblplanner_verifications`):
  `register_planner()` creates the account **unverified and without a trial**;
  a single-use, 24-hour, stored-hashed token link (delivered through the
  `tbl_mail_logs` outbox — 'pending' rows are retried by
  `scripts/mail_retry.php` just like reset emails) starts the 14-day trial and
  signs the planner in when clicked on `verify_planner.php`.
- **No trial farming:** entitlements exist only after a working mailbox is
  proven; re-sends are rate-limited (5/hour/account) and happen through a
  no-enumeration resend form on the verify page; correct credentials on an
  unverified account never create a session and never count as a throttle
  failure — login just points at verification.
- **Backfill safety:** every account created before this feature (admin-made
  staff, vendors, clients) is treated as verified; only the self-serve path
  stamps `email_verified = 0`.

Setup: run the migration (`migrations/v2.4_signup_abuse_guard.sql` — adds
`tblusers.email_verified`, `tblplanner_verifications`, `tblsignup_throttle`).
No new env vars: the damper honours the existing
`WPMS_LOGIN_THROTTLE_OFF` switch.

### Trial-funnel report (super admins)

The super admin's dashboard (`access_level 2`) carries a **Trial Funnel**
panel over a selectable window (7 / 30 / 90 / 365 days via `?days=`):

- **Stage counters** — Signups, Verified, Active Trials, Converted to Paid,
  Expired-Never-Paid, plus the verification and conversion rates and a
  per-plan breakdown of what converting planners bought.
- **Signups per day** — a lightweight bar chart (no JS dependency) of the
  most recent 21 active days.
- **Planner table** — everyone in the window with their current stage badge
  (Unverified / Trial / plan name / Expired).

Self-serve accounts are identified by the `planner_` username prefix that
`register_planner()` generates; admin-created staff and vendors are excluded
from the funnel by design. The classification is committed to the same
one-stage-per-planner rule the tests enforce: a paid row supersedes a live
trial, and "expired" means verified + lapsed + never paid + signed up before
today (so a brand-new signup is never misread as churn on day zero).

### Login rate limiting (built-in brute-force throttle)

`login.php` throttles failed sign-ins itself — no server modules needed:

| Env var | Default | Effect |
|---|---|---|
| `WPMS_LOGIN_THROTTLE_OFF` | *(off)* | `1` = disable all throttling (maintenance / shared test suites) |

Defaults: **5** failed attempts for one (email, IP) pair locks it — the
window **escalates** for repeat offenders (**15 minutes → 1 hour → 6 hours**;
the message names the wait). A per-IP cap of **20** recent failures blocks
further attempts from that address even for fresh accounts; success clears
the pair (and its escalation memory) and rows older than 7 days are pruned.

Every lockout also **texts the account owner** — couples on their registered
couple phone, staff on the optional `tblusers.phone` column — as a system SMS
(cost 0.00, never billed to planner credits, audited in `tblsms_logs`). The
message names the lockout count and cool-down and points to a password reset.
Attempts against unknown emails notify nobody.

Setup: run the migration (`tbllogin_throttle`) — that's it. SetEnv works too,
e.g. `SetEnv WPMS_LOGIN_THROTTLE_OFF 1` in an .htaccess to disable it
temporarily.

Staff can watch it live under **Login Security** (`admin/login_activity.php`):
locked pairs with their lock number, escalation window and remaining
cool-down (one-click unlock), per-IP pressure toward the cap (with release
for shared-office NATs), the recent failed-attempt list, and a prune button
for idle rows.

Setup: run `migrations/v2.1_phase1_hardening.sql` (creates `tblmpesa_callbacks`
+ `tblmpesa_callback_log`), register `https://your-domain/mpesa_c2b.php` as the
Validation **and** Confirmation URL on the Daraja portal, set the env vars, and
done. Codes are matched on TransID + amount (within tolerance) + account
reference, and a verified payment can only ever be consumed once. Every
reported payment — matched or not — is visible in the staff **M-Pesa Ledger**
(`admin/mpesa_ledger.php`), where an admin can also accept a pending payment
manually after a phone check. With `WPMS_MPESA_VERIFY` off, entry behaves as
before (dev/demo mode) while the ledger still records everything.

With the transport off (dev/demo default), every `Notification::create()` still
writes an audit row to the `tbl_mail_logs` outbox (`status = 'pending'` with the
reason), mirroring how `tblsms_logs` records SMS sends — nothing is silently
lost when you later flip `WPMS_MAIL_ENABLED=1` on a host with an MTA.

**Delivering the backlog:** rows logged while the transport was off stay
`pending` forever unless retried. Run the cron-safe sweeper on a host with the
flag enabled:

```bash
php scripts/mail_retry.php            # one pass: sends up to 50 pending rows
php scripts/mail_retry.php --quiet    # cron-friendly output
php scripts/mail_retry.php --limit=100 --max-attempts=5
```

- Each retry increments the row's `attempts`; a row that fails on its final
  allowed attempt is marked `abandoned` so it can never loop forever.
- With `WPMS_MAIL_ENABLED` off the script is a deliberate no-op (exit 0), so it
  is safe to leave in the crontab across environments.
- A single-flight file lock prevents overlapping cron runs from double-sending.

**Who gets emailed:** notifications address staff from `tblusers` and couples
from `tblaccounts` — so the couple's deposit receipt (bell + email, subject
leading with the M-Pesa reference they screenshot and share) lands in the same
outbox with the same transport rules as staff alerts. Deposit receipts are
dropped in `client_deposit.php` at the moment the planner records the payment.

Cron example (every 15 minutes):

```cron
*/15 * * * * php /path/to/Wedding/scripts/mail_retry.php --quiet
```

### Notification retention (prune old alerts)

Every deposit alert, vendor enquiry and reminder lands in `tblnotifications`
and the bell counts the unread ones — without retention a busy demo bell
drowns in stale alerts. `scripts/notify_prune.php` deletes read notifications
older than 60 days and unread ones older than 180 (giving the owner time to
see them), removing any matching orphaned `tbl_mail_logs` rows in the same
pass. Single-flight lock, `--dry-run`, tunable windows; safe to leave in the
crontab across environments.

```bash
php scripts/notify_prune.php --dry-run                 # preview counts
php scripts/notify_prune.php                           # defaults: 60d read / 180d unread
php scripts/notify_prune.php --read-days=30 --unread-days=90
```

Cron example (daily at 04:30):

```cron
30 4 * * * php /path/to/Wedding/scripts/notify_prune.php --quiet
```

### RSVP reminders (SMS to non-responders)

`scripts/rsvp_reminders.php` finds guests who haven't replied on **confirmed**
bookings and texts them their personal RSVP link through the SMS outbox
(`tblsms_logs`, Africa's Talking-ready; sends charge credits to the assigned
organizer). Timing is **invite-relative**: the clock starts when the guest
was invited (`rsvp_invited_at`, stamped automatically when their RSVP token
is minted or the list is marked invited) — not at the wedding date — so a
couple planning a year out gets gentle nudges on their own schedule, and
late-added invitees get a fair countdown of their own. Weddings already past
(beyond a grace window) are never chased. Re-remind at most every N days,
lifetime cap per guest, single-flight lock, `--dry-run` to preview.

```bash
php scripts/rsvp_reminders.php --dry-run        # preview, writes nothing
php scripts/rsvp_reminders.php                  # defaults: 7d after invite, 7d resend, cap 3
php scripts/rsvp_reminders.php --after=3 --resend=5 --max=2 --grace=10
```

Cron example (daily at 09:00):

```cron
0 9 * * * php /path/to/Wedding/scripts/rsvp_reminders.php --quiet
```

### Payment deadline-band texts to couples

`scripts/deadline_sms.php` texts the COUPLE (not guests) when a **confirmed**
booking with a priced package and an open balance crosses a deadline band:
60, 30 and 7 days before the wedding (configurable `--bands=60,30,7`). Each
text carries the couple's names, their balance, and the same pace/settlement
guidance the overview nudge shows — so the SMS and the portal never disagree.
Kenyan vendors expect final settlement a month out; the copy mirrors that.

One text per band, ever: `tblweddingbook.pay_band_sms_sent` stores the
smallest band already texted, and a band fires only when the current one is
smaller — date changes and late registrations never duplicate or re-send
missed bands. Fully-paid bookings are skipped (marker untouched, so a later
reversal still gets future bands), as are pending bookings and past weddings.

Delivery rides `SMS::send()` (outbox row in `tblsms_logs`, credits charged to
the assigned organizer, dev mode logs without sending) plus a couple bell
notification + email copy via `Notification::create()` — the text is never
the only channel — and the planner gets one notification per booking texted.

```bash
php scripts/deadline_sms.php --dry-run            # preview, writes nothing
php scripts/deadline_sms.php                      # bands 60/30/7
php scripts/deadline_sms.php --bands=30,14,3 --limit=50
```

Cron example (daily at 10:00):

```cron
0 10 * * * php /path/to/Wedding/scripts/deadline_sms.php --quiet
```

Every send is recorded on the guest (`rsvp_reminded_at` + count, visible on
the staff RSVP dashboard) **only after** the SMS reports success — a credits
or gateway failure retries naturally on the next run. Without an Africa's
Talking API key the messages are logged to the outbox in dev mode without
delivery, exactly like the SMS center.

### Big-moment SMS to couples

Two moments matter enough to reach a couple **when they are not logged in**:

- a booking is **confirmed** (staff saves the client form with Confirm) —
  "Your wedding date is CONFIRMED for Sat 19 Dec 2026…"
- a vendor enquiry is **confirmed** or **cancelled** on the staff ledger —
  the deal amount is quoted in the confirmation text.

Each text is sent through `SMS::send_courtesy()` (best-effort: a missing
number, empty credits or a gateway failure never blocks the action — the
in-app bell notification always fires first and is the reliable channel),
charged to the booking's **assigned planner** credits, and logged in
`tblsms_logs` like every other send. Without an Africa's Talking API key
the messages are logged as `sent` in dev mode without delivery.

Planners can also remind guests **manually** from the RSVP dashboard: tick
pending guests and either *Send SMS to selected* (staff-only, CSRF-protected
endpoint that re-checks every guest belongs to the booking and skips
already-replied / phoneless guests with a reason) or *Open WhatsApp queue*,
which walks each selected guest's chat pre-filled, one at a time.

When physical or digital invites go out, *Mark invites sent* stamps the
selected guests' `rsvp_invited_at` (the automatic-reminder clock starts from
that moment, so un-invited guests are never nagged) and mints missing RSVP
tokens on the spot. Re-running is a no-op — existing invite dates are never
overwritten.

### Couple engagement tools (checklist + wedding page)

Two couple-facing features keep clients coming back between planner meetings:
- **Planning Checklist** (`admin/client_checklist.php`) — every booking gets
  the standard Kenyan milestone plan seeded on first open: ruracio and family
  introductions, church banns (three Sundays) plus the Registrar's 21-day
  notice, eCitizen registration, committee formation, final-month vendor
  confirmations. Couples tick items off themselves; the page shows progress,
  overdue flags against the wedding date (silenced once the wedding has
  passed) and the next item up. Staff can preview any couple's checklist.
- **Our Wedding Page** (`admin/client_website.php`) — couples create and
  publish one shareable public page (`site.php?slug=...`) with their story,
  venue, address, live countdown and gallery. The share box has the URL, a
  WhatsApp share button and a publish/unpublish toggle; unpublished pages
  bounce visitors home. Guests RSVP through their personal token links (the
  website page explains this instead of offering a form that goes nowhere).
  Slugs are generated from the couple's names and stay stable once created.
- **Budget vs Actual** (on `admin/client_budget.php`) — the funding panel
  Kenyan couples actually plan around: package price, payments to date, and
  the committee's harambee pledges side by side. A stacked bar shows paid vs
  pledged vs still-to-raise, the panel counts pledged guests, projects the
  total once pledges land (or shows the over-collection when pledges +
  payments exceed the package), and coaches the couple — add pledges on My
  Guests, collect early over M-Pesa, or celebrate when the package is
  covered. The panel hides entirely for bookings with no priced package.
- **Vendor Marketplace** (`admin/client_vendors.php`) — couples browse
  approved vendors (catering, decor, photography, transport, MC and more)
  with live KSh package prices, filtered by category and county. The
  Enquire button opens a modal pre-filled with the wedding date and the
  vendor's packages; sending records the enquiry (pending on the vendor
  ledger) and notifies the assigned planner so the couple never negotiates
  alone. Unknown vendors, foreign bookings and tokenless posts are refused.
- **Vendor Enquiries ledger** (`admin/vendor_enquiries.php`, staff) — every
  couple's enquiry across all vendors, pending rows first, with status
  filters and tallies. Planners confirm deals (amount defaults to the
  package price, 10% commission recorded), mark them completed after the
  wedding, or cancel; every transition stamps who moved it into the notes
  trail. Enquiry notifications deep-link here.

### 5. Access the system

**One login door for every role:** http://localhost/Wedding/login.php

Sign in with any account — client, planner, or admin — and the system routes
you to the page your role needs (see “Where each role lands” below). Already
signed in? Both login pages skip the form and take you straight to your home.

| Entry | URL |
|---|---|
| Public site (home + sign-up hero) | http://localhost/Wedding/ |
| **Single login door (all roles)** | http://localhost/Wedding/login.php |
| Old admin login (`admin/login.php`) | retired — `301` redirect to the single door |

> Registration happens on the **index.php hero form** — the single
> client-facing entry point. `sign_up.php` is a legacy stub that permanently
> redirects there (`301 → /#sign-up`).

Ensure Apache can write to `admin/upload/{categories,events,gallery,users}/`.

---

## Demo logins

Passwords are stored as bcrypt hashes in the seed — these plaintext values were
verified against the seed hashes. **All of them sign in through the same door**
(`login.php`; the old `admin/login.php` permanently redirects there).

### Where each role lands

| Account | Role | Lands on |
|---|---|---|
| Couple/client (`tblaccounts`) | Client | `admin/client.php` — their bookings dashboard |
| `access_level 2` staff | Super Admin | `admin/dashboard.php` |
| `access_level 1` staff | Wedding Planner | `admin/dashboard.php` — or `admin/subscription.php` first when there is no active subscription |
| `access_level 0` staff (vendor) | Vendor Lead | `admin/vendor_home.php` — their own bookings ledger; unregistered vendors start at `admin/vendor_register.php` (vendor self-service) |

### Seeded accounts

| Role | Email | Password |
|---|---|---|
| Super Admin | `wafula@wpms.co.ke` | `Admin@2024!` |
| Super Admin (alt) | `abdi@wpms.co.ke` | `Admin@2024!` |
| Wedding Planner | `amina@wpms.co.ke` | `Test@1234` |
| Vendor Lead | `kamau@nyamachoma.co.ke` | `Vendor@2024` |
| Couple (client) | `brian.wafula@gmail.com` | `Test@1234` |
| Couple (client) | `faith.njeri@yahoo.com` | `Test@1234` |

> The legacy template login (`admin@mail.com` / `Password@123`, listed in the
> bundled `01 LOGIN DETAILS & PROJECT INFO.txt`) belongs to the original
> pre-bcrypt dump and **will not authenticate** on v2.1 — use the Kenyan seed
> accounts above.

---

## Development

```bash
composer install                 # dev tooling (PHPUnit)
composer test                    # run the PHPUnit suite (tests/)
composer lint                    # php -l over every admin/*.php file
composer migrate:db-compat       # mysqli -> PDO compat helper (scripts/)
```

Project layout at a glance:

```
index.php, login.php …                public site pages (sign_up.php → 301 to index.php)
admin/                                client + staff backend (login, dashboard, …)
admin/include/                        models + PDO Database wrapper + config
css/, js/, images/                    shared front-end assets
DATABASE FILE/                        base dump + v2.1 seeds
kenyan_schema.sql                     Kenyan schema additions
migrations/                           v2.1 hardening migration (idempotent)
tests/                                PHPUnit tests (DB + session hardening)
```

---

## Notes & cautions

- **Security hardening is in place** — PDO prepared statements everywhere,
  CSRF tokens on login/register, session regeneration + fingerprint binding,
  and audit logging (`tblsession_audits`). Don't regress them.
- **Live integrations** (Africa's Talking SMS, M-Pesa Paybill/Till) require
  your own accounts; see the manual before enabling. M-Pesa codes can be
  verified automatically via the Daraja C2B callback (`mpesa_c2b.php`) —
  see the configuration section above.
- This is a starter product, not turnkey production software — review
  `WPMS_KENYA_MANUAL.md` §11 (production checklist) before going live.
