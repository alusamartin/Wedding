# Everafter Planner — Wedding Planning Management System

Kenyan-market edition of the Wedding Planner Management System (v2.1): package
catalog + sign-up hero, client/couple portal, planner & vendor admin area,
wedding bookings, guest lists (with token RSVP links + automatic SMS
reminders), budgets (KSh), branded shareable M-Pesa receipts, calendar,
subscriptions, and M-Pesa-ready pricing. Vendors list themselves from the
public site (`vendor_signup.php`: account, business, priced services and an
optional package), and their listing goes live once an operator approves it. Modernized 2026 theme
(`css/modern.css`), PDO-based data layer, hardened sessions (CSRF,
fingerprinting, audit log).

> 📖 The full operator manual lives in [`WPMS_KENYA_MANUAL.md`](WPMS_KENYA_MANUAL.md)
> (feature walkthrough, directory map, API/troubleshooting). This README is the
> quick start. Security maintenance — PHP branch upgrades and the bundled
> jQuery/Bootstrap versions — is covered in
> [`SECURITY_PATCHING.md`](SECURITY_PATCHING.md).

---

## Requirements

| Dependency | Version | Notes |
|---|---|---|
| PHP | **8.4 or 8.5** (minimum ^8.2) | `pdo`, `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl` extensions (see `composer.json`). PHP 8.2 is security-only and ends 31 Dec 2026 — see [`SECURITY_PATCHING.md`](SECURITY_PATCHING.md) |
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

# (f) Optional — self-serve planner registration + 6-month trial ('trial' ENUM,
#     wider password column for bcrypt)
mysql -u root -p dbwedding < migrations/v2.3_planner_trial.sql

# (g) Optional — signup abuse protection: per-IP/per-email signup damper and
#     email verification before the trial starts
mysql -u root -p dbwedding < migrations/v2.4_signup_abuse_guard.sql

# (h) Optional — trial nurture email band marker (check-in + last-call)
mysql -u root -p dbwedding < migrations/v2.5_trial_nurture.sql

# (i) Optional — password reset for staff, not just couples
mysql -u root -p dbwedding < migrations/v2.6_staff_password_reset.sql

# (j) Optional — extend the trials already in flight to six months (the new
#     default). Only touches status='trial' rows; recomputes each expiry from
#     its own start_date, so a long-dead trial is never revived.
mysql -u root -p dbwedding < migrations/v2.7_extend_trial_window.sql

# (k) Optional — plan pricing: make an operator-defined plan key buyable
#     (tblsubscriptions.plan was an ENUM of the three seeded keys, so a new plan
#     was listed for sale but the purchase was silently stored as ''), and add
#     tblsubscription_plan_changes — the who/when ledger behind each plan's price
mysql -u root -p dbwedding < migrations/v2.8_plan_pricing_audit.sql

# (l) Optional — venue headcount plan: the "Headcount plan per function" table
#     on the printed venue sheet is now editable (staff for any wedding, a couple
#     for their own — sheet_venue.php). Adds the unique key the writer relies on
#     (booking_id, features_id) so re-saving a function's plan replaces it instead
#     of stacking a second row, and drops any plan row that belongs to another
#     wedding's function (it was printed under a wrong heading and editable
#     nowhere). Without this key a re-save still wins on read, but the table is
#     only guaranteed one row per function with it. A COUPLE's save also briefs
#     the assigned planner and the venue contact booked on the wedding with just
#     the functions whose numbers moved (include/HeadcountBrief.php); no schema
#     change is needed for that — it uses tblnotifications/tbl_mail_logs.
mysql -u root -p dbwedding < migrations/v2.9_venue_headcounts.sql

# (m) Optional — the rest of the vendors the headcount numbers are for: a booked
#     caterer or decorator now hears about a moved function too, scoped to the
#     functions their own deal covers (tblvendor_bookings.features_id; NULL means
#     the whole wedding, which is what every existing deal means, so there is no
#     backfill). The planner sets that scope on the deal in Vendor Enquiries, and
#     the vendor sees it on their own ledger; the venue sheet lists who is briefed
#     and for which functions. See the v2.9 note above for the base brief.
mysql -u root -p dbwedding < migrations/v2.10_vendor_function_scope.sql

# (n) Nothing to run — the plan outgrowing the package it was sold on. A vendor
#     package states a guest capacity (tblvendor_packages.capacity) and a deal
#     records the package it was bought on (tblvendor_bookings.package_id), so a
#     function planned past one is a deal that no longer covers the day: the
#     vendor holding it and the planner are warned, the venue sheet says it
#     beside the numbers, and a couple's save carries it in the same brief as the
#     change (admin/include/PackageCapacity.php). The warning names the way out
#     too — the SAME vendor's next package up that covers the crowd, with the
#     price difference — and the planner re-prices the deal onto it in one step
#     from the deal row in Vendor Enquiries (the sheet's "Re-price this deal"
#     link lands on it): the deal's package, amount and 10% commission change and
#     the notes trail records who moved it, while its status and its function
#     scope are left alone; both sides are told, and the vendor never sees the
#     deal value. All of that is code-only too — no migration, nothing to
#     backfill.
#
#     The same mismatch is caught at the OTHER end of the wedding, where it
#     begins: a couple can just as easily pick a package too small for numbers
#     that never moved — a 150-pax caterer for a reception planned for 300 — and
#     the next headcount save that would have said so may be weeks away, or
#     never. So the deal is judged at every door it is recorded at: the couple's
#     enquiry (the page they land on names the package they asked for, which
#     functions it cannot hold and the vendor's next package up, and the planner
#     gets the short form on the enquiry alert they already receive), the staff
#     ledger's confirm, the vendor accepting it on their own dashboard (their
#     copy states the limit and not the planner's price difference), and a
#     confirmed deal's headcount scope being widened onto functions it cannot
#     cover. A deal recorded on a package that fits says nothing at all.
#
#     Where all of that is the last resort, drift is the first: admin/headroom.php
#     lists the weddings whose planned headcounts are within a share (5%, 10% or
#     20% — ten by default) of the guest capacity of a package a vendor is
#     confirmed on. A reception planned for 240 against a 250-pax package is not a
#     breach and may never become one; the planner sees it a month out, with every
#     option still open. One row per wedding, tightest first, stating the function,
#     the count, the package and the limit it is closing on, linked to the venue
#     sheet the numbers live on; a wedding already OVER a limit stays listed and is
#     told apart there and in red, so the list can never be read as "nothing wrong
#     here". It notifies nobody — a bell per wobble of a guest count is noise, and
#     both announced events are facts rather than forecasts — so the page, reached
#     from the sidebar badge that counts the same weddings the page lists, is where
#     a planner looks on purpose. Staff only, like the ledger it is derived from.
#     The page itself is code-only; the width each planner chooses is remembered
#     per ACCOUNT and that one preference is installable on its own — see (o).
#
# (o) Optional — remembering the headroom width per planner. The view above lists
#     the weddings running close to a booked package's limit at 5%, 10% or 20%,
#     and now keeps the width an account chose so it opens there next visit
#     instead of asking again (admin/include/UserPrefs.php). Adds tbluser_prefs:
#     one row per (account, preference), a table rather than a column because the
#     next preference must not be another ALTER on tblusers, the table every login
#     reads. It is OPTIONAL — without it the page says it cannot remember the
#     width and opens at its default of ten per cent — and there is nothing to
#     backfill, because an account with no row IS the default. Idempotent.
mysql -u root -p dbwedding < migrations/v2.12_user_preferences.sql

# (p) REQUIRED — which account a bell/outbox row is for. `tblnotifications.user_id`
#     and `tbl_mail_logs.user_id` each hold an id from ONE of two account tables —
#     `tblusers.id` (staff and vendor leads) or `tblaccounts.user_id` (couples) —
#     and the two sequences are independent auto-increments, so the number alone
#     does not say which account it came from. An account in one table whose id
#     collided with an account in the other could therefore read (and mark read,
#     and be emailed) the other's feed. Adds `recipient_type` to both tables
#     ('user' / 'account' / 'none' for a row with no account behind it, user_id 0)
#     and backfills it: each row's id is matched against the two tables, and where
#     it exists in both, the booking the row is about — and, for mail rows, the
#     address the message actually went to — decides. Also adds the composite
#     index the new predicate reads through. The column is left with NO default,
#     so a writer has to name it. REQUIRED together with the v2.13 code: every
#     insert and read names the column. Idempotent.
mysql -u root -p dbwedding < migrations/v2.13_notification_recipients.sql

# (q) Optional — the scheduled jobs reporting their own silence. Every job in
#     the crontab below — mail_retry.php, notify_prune.php,
#     subscription_renewals.php, rsvp_reminders.php, deadline_sms.php — keeps a
#     promise to people who are not watching, and each is SILENT when it stops:
#     a dead pinger, a renamed crontab entry, a lock left behind by a killed
#     process all look exactly like a job with nothing to do, and the platform
#     finds out days later from a planner whose invoice email never arrived.
#     Each job now leaves a HEARTBEAT when it really runs (include/JobWatch.php →
#     tbljob_heartbeats: ONE row per job, not one row per run — a counter, not a
#     log), carrying what it did AND how much work its own selection offered it
#     (the queue the watchdog compares against, reported by the job so the two
#     can never disagree). `php scripts/job_watch.php` judges those heartbeats
#     against the schedule each job is deployed with: never run, silent for
#     longer than its interval plus grace, failing (a non-zero exit, or a run
#     that delivered nothing while rows waited), or 'ok' while its own queue had
#     work waiting (the sweeper that reports success while the queue grows). A job that HAS
#     run and went quiet rings the operator's bell — super admins only, once per
#     finding per half-day, and the memory is forgotten when the job recovers so
#     the next silence is news again; a job that has NEVER run is reported and
#     exits non-zero instead of alerting, because on install day every job is in
#     that state (the cron bridge runs the watchdog hourly as `job_watch` and
#     turns a finding into HTTP 500, so a pinger sees it too). The operator reads
#     the same table on admin/job_watch.php, which audits as it opens. OPTIONAL
#     in the same sense as (o) — without it the jobs keep running and simply have
#     nowhere to leave a heartbeat, and the page says exactly that. Idempotent.
mysql -u root -p dbwedding < migrations/v2.15_job_heartbeats.sql

# (r) Optional — letting an AI client read the planner. `mcp.php` is a Model
#     Context Protocol endpoint: point Claude, ChatGPT, Cursor or an internal
#     agent at it with a token and it can read the weddings that token's account
#     may open — guests, RSVP answers, checklist progress, the budget position,
#     booked vendor deals, the wedding-day timeline, headcount against the
#     capacity of what was sold, the couple's public page — and summarise them,
#     open a wedding as a DOCUMENT (wedding://booking_id/overview and friends)
#     for clients that browse rather than call, or run one of the server's own
#     prompts (weekly_wedding_brief, rsvp_chase_list, budget_watch,
#     vendor_shortlist). Every call is checked
#     against the SAME row rules and role predicates the pages use (it never
#     names a table, never writes SQL, and cannot reach a booking its account
#     could not open in a browser). Adds tblapi_tokens (one row per token, the
#     secret stored only as sha256 — shown once, at mint) and tblapi_audit (what
#     each token asked for, including what it was refused, with the SHAPE of the
#     arguments and never their values). Writes are OFF by default: they need a
#     write scope AND WPMS_MCP_WRITES=1. Applying it is optional to the rest of
#     the platform and REQUIRED for the endpoint — without it `mcp.php` answers
#     503 and admin/api_tokens.php says which file to apply, because an auth
#     layer with no table to check a token against must refuse, not welcome.
#     Nothing to backfill, and idempotent. See README §AI access (MCP).
mysql -u root -p dbwedding < migrations/v2.16_api_tokens.sql

# (s) Optional — letting an AI client CHANGE things, without letting it decide
#     alone. `migrations/v2.17_mcp_approvals.sql` adds tblapi_approvals (one row
#     per Tier-3 request, one row per decision about it) and five columns to
#     tblapi_audit (the tier, the idempotency key, the approving decision, the
#     payload hash it was bound to, and a short server-written note about what
#     changed). It turns on the second half of the tool catalogue: Tier 2 (add a
#     guest, change one, mark invitations, tick a checklist item) runs on
#     `confirm: true` and otherwise returns a preview of exactly what would
#     change; Tier 3 (an SMS to a guest list, a recorded payment, an M-Pesa
#     deposit prompt, a planner assignment) refuses on its own authority, records
#     a request, and waits for a signed-in planner to approve that exact payload
#     on **AI Approvals** (admin/mcp_approvals.php) — which mints a short-lived,
#     single-use, HMAC-signed string bound to the tool and a hash of the payload,
#     so a changed message or amount no longer fits. Writes still need
#     WPMS_MCP_WRITES=1 as well. Optional to the rest of the platform, and
#     without it the write tools simply refuse. Nothing to backfill, idempotent.
mysql -u root -p dbwedding < migrations/v2.17_mcp_approvals.sql

# (t) Optional — the deadline that goes with (s).
#     `migrations/v2.19_mcp_approval_expiry.sql` adds `expired_at` to
#     tblapi_approvals, the marker scripts/mcp_approval_sweep.php sets ONCE when a
#     Tier-3 request's window closes with no answer (or with an approval the
#     assistant never used). The sweep then rings the planner once and records the
#     outcome in the assistant's call log; without the column the platform behaves
#     as before and the sweep reports the missing file instead of failing.
mysql -u root -p dbwedding < migrations/v2.19_mcp_approval_expiry.sql

# (u) Optional — one-click revival of an expired request (v2.20).
#     `migrations/v2.20_mcp_approval_rerequest.sql` adds re_request_of and
#     re_requested_by to tblapi_approvals. An expired row on AI Approvals gets
#     a Re-request button that creates a NEW pending request for the same tool
#     and payload with a fresh 30-minute window; the old row stays expired, and
#     the new one records which reference it replaced and which planner asked.
#     The button works without the columns (the lineage is simply not shown);
#     apply it so "what did apr-57 become?" is answerable.
mysql -u root -p dbwedding < migrations/v2.20_mcp_approval_rerequest.sql

# (v) Optional — week-over-week change in the Monday brief (v2.23).
#     `migrations/v2.23_business_pulse_snapshots.sql` adds `tblops_snapshots`:
#     one row per (account, ISO week) holding the aggregate snapshot that week's
#     brief was rendered from. The next brief reads the most recent earlier week
#     and prints each figure's signed change ("KSh 1.24M collected (+95,000)"),
#     with money and percentage-point formatting. A row is written only after a
#     send is accepted, and a resend inside a week replaces that week's row, so
#     nobody ever compares against themselves. Kept to 26 weeks per account.
#     The brief still sends without it — it just says the comparison is off.
mysql -u root -p dbwedding < migrations/v2.23_business_pulse_snapshots.sql

# (w) Optional — per-function vendor service requirements (v2.24).
#     `migrations/v2.24_event_vendor_needs.sql` adds `tblevent_vendor_needs`:
#     one row per (wedding, function) holding the categories the planner marked
#     as required. The coverage board (`admin/vendor_coverage.php`) then judges
#     only those cells — booked, pending or missing — instead of treating every
#     category as needed. Without the table the board still renders, counting all
#     ten per function, and says the list cannot be edited. Nothing to backfill:
#     a function with no row starts from all ten, so an existing wedding keeps
#     its whole matrix. Idempotent.
mysql -u root -p dbwedding < migrations/v2.24_event_vendor_needs.sql

# (x) Optional — the couple / vendor / planner conversation (v2.25).
#     `migrations/v2.25_vendor_threads.sql` adds `tblvendor_messages`: one row
#     per message on a vendor deal, with the author's role and the three read
#     stamps the unread counts come from. The panels are hidden and the deal
#     ledgers say why until the table exists; nothing to backfill, because
#     `tblvendor_bookings.notes` stays the deal's audit trail and the thread
#     starts empty on every existing deal. Idempotent.
mysql -u root -p dbwedding < migrations/v2.25_vendor_threads.sql

# (y) Optional — telling the planner when that conversation goes quiet (v2.26).
#     `migrations/v2.26_thread_escalation.sql` adds a stamp to
#     `tblvendor_messages` so `scripts/thread_escalation.php` can ring the
#     couple's planner once when a vendor leaves the newest message unanswered
#     for a day, and stay silent on every later run. Nothing a couple sees
#     depends on it (the waiting labels work without it); only the alert does.
#     Idempotent.
mysql -u root -p dbwedding < migrations/v2.26_thread_escalation.sql

# (z) Optional — what a vendor actually sells, as distinct from the package they
#     bundle (v2.27). `migrations/v2.27_vendor_services.sql` adds
#     `tblservice_catalog` (39 seeded services across the ten categories, each
#     with a MANDATORY unit: per guest, flat, per day…) and `tblvendor_services`
#     (what one business sells — from the catalogue or their own, always with a
#     unit and a price). Vendor → My Services adds, prices, tags and hides them;
#     the staff marketplace, the couple marketplace and the public profile show
#     them with their unit, so two vendors can finally be compared. Packages are
#     untouched and keep selling as bundles. Without it the page says the
#     migration is missing and every other page simply shows no services.
mysql -u root -p dbwedding < migrations/v2.27_vendor_services.sql

# (aa) Optional — what THIS couple wants, and can spend, per vendor category (v2.28).
#      `migrations/v2.28_vendor_preferences.sql` adds `tblvendor_preferences`:
#      one row per (wedding, vendor category) holding taste tags, an optional
#      floor, a cap and a note. The couple edits it at Taste & Budget; the
#      couple's marketplace and the planner's marketplace then mark every
#      service Fits / Partly fits / Over budget / Off your taste / Quote needed,
#      with the arithmetic stated ("KSh 1,200 x 8 guests = KSh 9,600 of your
#      KSh 250,000"). Estimates are only made for units that can be multiplied
#      honestly; per hour, per km or an unstated quantity says "quote needed".
#      Nothing is stored per matched vendor, so a changed budget or a re-priced
#      service cannot leave a stale badge. The same page can also SPLIT one
#      total (the package price, or any figure the couple prefers) across the
#      categories from editable shares: it previews the caps, places them in
#      whole KSh 500 blocks so they add up to the total, leaves out any
#      category whose share is 0 without touching its cap, and uses the
#      couple's own floor where that is higher than the share. Without it the
#      marketplace works as before and both pages say the migration is missing.
mysql -u root -p dbwedding < migrations/v2.28_vendor_preferences.sql

# (ab) Optional — the proposal lifecycle: a registration becomes a priced wedding
#      the couple chooses from (v2.30). `migrations/v2.30_proposals.sql` adds
#      `tblproposals` (one row per wedding: the couple's brief, the owning planner,
#      and the state of the conversation), `tblproposal_options` (the >= 3 priced
#      options a planner offers) and `tblproposal_items` (the vendors and services
#      each option is built from). The public sign-up then creates a PROPOSAL
#      beside the pending booking — with the couple's guests, budget, venue, style
#      and preferred planner when they fill in the optional brief block — the
#      planner acknowledges it in Pending Proposals and prices three options, the
#      couple chooses on Your Wedding Options, and Lock turns the chosen option
#      into real vendor deals and confirms the wedding. Money is stored once: an
#      option's total is the quote the couple saw, and the amounts are copied into
#      `tblvendor_bookings` at lock. Without it the couple still registers
#      (an ordinary pending booking) and the proposal pages name this migration.
#      `WPMS_PROPOSAL_FLOW=0` switches the whole lifecycle off. Idempotent.
mysql -u root -p dbwedding < migrations/v2.30_proposals.sql

# (ac) Optional — Silver / Gold / Platinum pricing on what a vendor sells (v3.1).
#      `migrations/v3.1_vendor_service_tiers.sql` adds `tblvendor_service_tiers`
#      and `tblvendor_package_tiers` (each tier's price, what it includes and
#      what it leaves out) plus a `tier` column on `tblproposal_items`. A vendor
#      prices three levels on **My Services** and **My Packages**; the base
#      `price` column stays the Silver level and is kept in sync, so every card,
#      fit calculation and plan line keeps working and now means "entry level".
#      The planner's marketplace, the couple's marketplace and the public profile
#      print the three levels; the proposal builder lists each priced tier as its
#      own choice, and a line records which level it was quoted at. Without it
#      everything works exactly as before and every surface shows one price.
#      Idempotent.
mysql -u root -p dbwedding < migrations/v3.1_vendor_service_tiers.sql
```**Already running a v2.0 database?** Apply (b), (d), (e), (f), (g), (h), (i), (j), (k), (l), (m), (p) and (q) only —
plus (o) if you want the capacity headroom view to remember each planner's width (the page works without it),and (r) if you want an AI client to be able to reach the platform at all, and (s) if you want it to be able to
change anything — writes need `WPMS_MCP_WRITES=1` too, and the money/message tools wait for an approval. Add (t)
with (s), and the 10-minute `mcp_approval_sweep.php` line in the cron table of
[`DEPLOY_CPANEL.md`](DEPLOY_CPANEL.md): an approval window that closes in silence is how a planner comes to believe
an SMS went out. Add (u) too if you want the planner to be able to revive an expired request without the
assistant asking again.
Add (v) if you want the Monday brief to show week-over-week change — without it the
brief still sends and states that the comparison is off.
Add (x) if you want the couple, vendor and planner to talk to each other on a deal;
without it the conversation panels stay hidden and the ledgers explain why. When you add (x), also add (y) and
the daily `thread_escalation.php` line in the cron table of [`DEPLOY_CPANEL.md`](DEPLOY_CPANEL.md) — otherwise
nobody is told when a vendor leaves a couple's question unanswered.
Add (ab) if you want a registration to become a priced, chosen, confirmed wedding instead of a booking somebody
assigns by hand; without it sign-up still works and simply creates the pending booking it always did.
Add (ac) if vendors should sell three levels of the same offer (Silver / Gold / Platinum) with what each includes
and leaves out; without it every page keeps quoting the single price it always had.
Add (w) if you want the vendor coverage board to judge only the services each function needs —
without it the board counts all ten per function and its editor is read-only.
Add (z) if you want vendors to list the individual services they sell — the priced pieces behind
a package. Without it the My Services page says which migration to apply, and packages keep
selling exactly as before.
Add (aa) if you want each couple's taste and per-category vendor budget to filter and annotate
the marketplaces. Without it the pages still work, just without the fit marks.
Skip (a)/(c), which reset demo data to the Kenyan seed set.

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
| `WPMS_DB_REPLICA_HOST` | *(empty = no replica)* |
| `WPMS_DB_REPLICA_USER` | same as `WPMS_DB_USER` |
| `WPMS_DB_REPLICA_PASS` | same as `WPMS_DB_PASS` |
| `WPMS_DB_REPLICA_NAME` | same as `WPMS_DB_NAME` |

For staging/production set these in the server environment (Apache `SetEnv`,
CLI `export`, Docker `environment:`). Never commit real credentials — see
section 12.1 of the manual for full examples.

The optional `WPMS_DB_REPLICA_*` pair moves AI (MCP) **reads** to a replica while
every write, the token check, the audit row and an approval decision stay on the
primary. Leaving the host empty — the normal setting — changes nothing: reads
and writes both use the primary, exactly as before. An unreachable replica also
falls back to the primary (one line in the PHP error log) rather than breaking
the assistant. Replication lag means a row written a moment ago may not appear
in the next read; pair the credentials with the read-only user from
`migrations/v2.18_mcp_grants.sql`.

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

### Self-serve planner registration & 6-month trial

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
  point** (90 days left on the default 180-day / six-month trial) with getting-started
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
| `WPMS_TRIAL_DAYS` | `180` | Trial length in days granted at verification (180 = the six-month free trial; the nurture bands below derive from it) |

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
  `scripts/mail_retry.php` just like reset emails) starts the 6-month trial and
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

With the transport off (dev/demo default), every `Notification::create_for_staff()`
/ `create_for_vendor()` / `create_for_couple()` still writes an audit row to the
`tbl_mail_logs` outbox (`status = 'pending'` with the reason), mirroring how
`tblsms_logs` records SMS sends — nothing is silently lost when you later flip
`WPMS_MAIL_ENABLED=1` on a host with an MTA. (A recipient with no address on file
gets no outbox row; the in-app alert is the only channel. The row also records
which account table the recipient id belongs to — see migration (p).)

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

Every deposit alert, vendor enquiry, headcount change (a couple moving the
planned numbers on their venue sheet, or a plan that has outgrown the guest
capacity of the package a vendor was booked on) and reminder lands in `tblnotifications`
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

### Watching the jobs themselves (the watchdog)

Everything above is a promise kept by a process nobody is standing next to, and
all of them fail the same quiet way — nothing runs, so nothing prints. The five
scheduled jobs (mail retry, notification prune, subscription renewals, RSVP
reminders, payment deadline texts) each write a heartbeat when they really run
(`tbljob_heartbeats`, one row per job, carrying what they did and how much work
their own selection offered them; a `--dry-run` deliberately writes none), and
`scripts/job_watch.php` is the reader: it judges every judged job against the
schedule it is deployed with and says which ones are silent, failing, or ran on
time without doing their work.

```bash
php scripts/job_watch.php              # the report; exit 1 when something is wrong
php scripts/job_watch.php --quiet      # one line per finding — cron/bridge form
php scripts/job_watch.php --json       # the whole picture, machine-readable
php scripts/job_watch.php --no-alert   # inspect without telling the operator
```

- Exit codes: `0` = every job reported in time with nothing waiting, `1` = at
  least one finding (the operator has been told), `2` = the v2.15 table is not
  installed. A status- or keyword-monitoring pinger can watch that exit code.
- The operator is told once per (job, finding) per 12 hours, in the bell (and
the outbox), never by SMS; a job that recovers forgets its alert, so the next
  silence is news again.
- `WPMS_JOB_ALERTS=0` silences that bell and that email on a live install — and
  nothing else, deliberately: the report, the exit code a pinger watches and the
  Job Health page are all unchanged, so the silence stays visible. Absent or
  empty means alerts on. (Before this switch existed the only way to quiet the
  watchdog was `WPMS_LOGIN_THROTTLE_OFF`, which also disables brute-force
  protection on the login door.)
- `cron_bridge.php` runs it hourly as the `job_watch` job, so the scheduler that
  is supposed to run the other jobs reports them missing — a silence finding
  turns the response into HTTP 500 there.
- Super admins also read it as **Job Health** (`admin/job_watch.php`, sidebar),
  which audits on open: a watchdog that only runs when the scheduler is healthy
  could never report a dead scheduler.

Cron example (every 30 minutes):

```cron
*/30 * * * * php /path/to/Wedding/scripts/job_watch.php --quiet
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
notification + email copy via `Notification::create_for_couple()` — the text is
never the only channel — and the planner gets one notification per booking texted
(`create_for_staff()`).

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
- **Capacity Headroom** (`admin/headroom.php`, staff) — the drift list: the
  weddings whose planned headcounts are within a share of the guest limit on a
  package a vendor is confirmed on (within 10% by default, or 5%/20%), caught
  before it becomes a breach. One row per wedding, tightest first, naming the
  function, the crowd, the package and its limit, and linked straight to the
  venue sheet the numbers live on. The sidebar badge counts the same weddings the
  page lists, and a wedding already over a limit is listed and flagged rather
  than dropped. Nothing is emailed or belled from here.

### Per-planner scoping — which weddings a planner sees

With more than one planner on the account, each one sees **their own weddings and
the unassigned ones** — not their colleague's. `tblweddingbook.organizer_id` is
the assignment, and it is now what decides visibility rather than just who the
couple is told about:

- the bookings list, the enquiries ledger (rows, status tallies and every deal
  transition), the dashboard count, the MCP tools and the AI Approvals page all
  apply it, and any other staff page that names a `booking_id` is refused by
  `admin/include/init.php` before it renders;
- a **super admin** still sees every wedding, and an **unassigned** wedding stays
  visible to every planner so somebody can pick it up — assigning it is what
takes it away from everyone else;
- a planner cannot approve another planner's AI request or move their vendor
  deal, even with a valid CSRF token and the id in hand;
- set `WPMS_PLANNER_SCOPING=0` to turn it all off (single-planner installs, or a
  staging seed where one account owns everything). Left empty, it is ON.

### AI access (MCP) — let an assistant read the planner

`mcp.php` is a **Model Context Protocol** endpoint, so an AI client — Claude,
ChatGPT, Cursor, an internal agent — can read this platform's real wedding data
and answer questions about it: *which weddings are close, how many guests still
haven't RSVP'd, what is overdue on the checklist, where the budget stands, which
vendor deals are only pending*. The AI is the **client**; PHP stays the
authority.

**How to connect:** sign in → **AI Assistant** in the sidebar
(`admin/api_tokens.php`) → create a token, choosing what it may do and when it
expires. The secret is shown **once** (only its sha256 is stored), together with
the endpoint URL and a ready-to-paste config block for the common clients. The
same page lists every token with its state and last use, revokes one in a click,
and shows **what the assistant asked for** — including what it was refused.

**What it can see is what YOU can see.** A token resolves to one of this
platform's own accounts (a planner, a vendor, or a couple) and every call runs
with that account's session, through the same ownership predicates the pages
use — `booking_visible_to_session()`, `may_open_couple_page()`,`is_staff()`. A
couple's token reaches its own wedding and no other; a vendor's token reaches no
couple data at all; a booking id the AI invents is a request, not permission, and
is refused in a sentence it can read and explain. Tool **scopes** are a second
ceiling under the account's own powers (a token with `read:guests` and nothing
else cannot reach the budget, however the request is phrased).

**Reads are always on. A write has a tier.** Changing data needs a write scope on
the token **and** `WPMS_MCP_WRITES=1` on the install, and `whoami` tells the
client which of those is missing. The read catalogue is thirteen tools
(`whoami`, `list_weddings`, `get_wedding`, `list_guests`, `rsvp_status`,
`list_tasks`, `budget_position`, `list_vendor_deals`, `search_vendors`, and —
Phase 2 — `list_timeline`, `headcount_capacity`, `list_notifications`,
`wedding_website`). The write tools are the other half of the catalogue, and none of them happens quietly:
a **write** is one of two kinds, and neither is a surprise:
**Tier 2** — a guest, a guest edit, an invitation, a checklist tick — runs on
`confirm: true`, and WITHOUT it answers with a *preview* of exactly what would
change and writes nothing. **Tier 3** — an SMS to a guest list, a recorded
payment, an M-Pesa deposit prompt, a planner assignment — is money or messages,
so the tool refuses on its own authority: it resolves the real data, records a
request, and answers `approval_required` with a reference and the sentence the
planner will read. A signed-in planner then approves **that exact payload** on
**AI Approvals** (`admin/mcp_approvals.php`, with a sidebar badge for what is
waiting), which mints a short-lived, single-use, HMAC-signed approval bound to
the tool and to a hash of the payload — change one character of the SMS and it no
longer fits. What lands is that one action, once: an approval cannot be replayed
and a repeated `request_id` cannot repeat the write. The rest of the design lives
in [`docs/MCP_PLAN.md`](docs/MCP_PLAN.md).

**An approval is a deadline, and a deadline that passes in silence is a lie.**
The 30-minute window has always been enforced, but nothing used to SAY when it
closed: the planner was not told, the assistant's next attempt minted a fresh
reference as though the first had never existed, and the call log had no line
about it. `scripts/mcp_approval_sweep.php` (cron, every 10 minutes) closes every
request whose window ended — unanswered, or approved and never used, which is
the nastier one because the planner believes the message went out. Each closure
is marked once (`expired_at`, v2.19), produces one audit row the assistant can
read (`ok=0`: nothing was sent, nothing was recorded) and ONE digest bell per
planner naming the dead references. A retry of the identical call is answered
with `previous_request_expired`, so the model stops waiting on a reference that
can no longer be approved. The closed request is also listed under **Decisions
and expiries** on the approvals page, so a reference quoted days later can still
be looked up.

**And the planner can ask again without the assistant (v2.20).** An expired row
carries a **Re-request** button: one click creates a NEW pending request for the
same tool and the same payload hash, with a fresh 30-minute window, and leaves
the old row expired — its history stays true, and the new row records that it was
revived from `apr-N` by that planner. The assistant's identical retry then lands
on the new reference instead of minting a third one, and a double-click returns
the request already waiting rather than queueing the same question twice. Only an
expired request can be revived; a pending, approved, refused or used one cannot,
and the asking token must still be live or the button is refused with the reason.

**Weddings are also documents, and the server brings the expertise.** Besides
tools, the same endpoint answers the other two MCP surfaces:

* **Resources** — a wedding is openable by URI, for clients that browse rather
  than call: `everafter://me`, `everafter://weddings`, and
  `wedding://{booking_id}/{overview|timeline|guests|tasks|budget|capacity|vendors|website}`.
  A document is not a second query: each URI runs the very tool that answers the
  same question, through the same scope check and the same row rule, and returns
  the identical JSON — so the two doors cannot disagree. A wedding this account
  may not open is reported **exactly** as one that does not exist (no id space to
  walk), while a missing scope is named, because the caller can fix that.
* **Prompts** — ready-made instructions a client shows as slash commands:
  `weekly_wedding_brief`, `rsvp_chase_list`, `budget_watch`, `vendor_shortlist`.
  Each one gathers its own context through the read tools (real figures, one
  turn) and carries this platform's rules — KSh, exact amounts, never invent a
  guest, a vendor or a payment, quote the capacity way-out sentence rather than
  paraphrasing it.

**Switches** (all optional, in `.env`):

| Variable | Effect |
|---|---|
| `WPMS_MCP` | `0` switches the whole endpoint off (503 to every client) |
| `WPMS_MCP_WRITES` | `1` allows data-changing tools; off by default. Tier 3 needs a planner's approval either way |
| `WPMS_MCP_APPROVAL_SECRET` | the HMAC key behind a Tier-3 approval; set it to 32+ random characters (unset, it is derived from the DB credentials) |
| `WPMS_MCP_ORIGINS` | comma-separated browser origins allowed to call it (claude.ai etc.). Server-side clients send no Origin and need nothing; the site's own origin is always allowed |

**Every call is counted and remembered.** Each one is rate-limited through the
shared abuse guard on its own door (`mcp`, keyed by the token's hash and the
caller's IP) and written to `tblapi_audit` with the tool, scope, outcome and the
**shape** of the arguments — never their values, so a guest's phone number is not
duplicated into a log. `admin/api_tokens.php` is where the account owner reads
that trail (the same pruning job that trims the other logs trims it, after 90
days).

> The protocol core is `admin/include/McpServer.php` (both MCP eras: the legacy
> `initialize` handshake and the current stateless shape, with
> `MCP-Protocol-Version`/`Mcp-Method`/`Mcp-Name` required to agree with the
> body — `Mcp-Name` naming the tool for a call, the URI for a resource read and
> the prompt for a `prompts/get`). The catalogue is
> `admin/include/McpTools.php`, the documents are
> `admin/include/McpResources.php`, the instructions are
> `admin/include/McpPrompts.php`, and identity is
> `admin/include/ApiToken.php`; what it may DO is
> `admin/include/McpWrites.php` (the tiers, the previews, the approval
> boundary) and `admin/include/McpApprovals.php` (the signed, single-use
> approval), with the human end of that on `admin/mcp_approvals.php`. Storage:
> `migrations/v2.16_api_tokens.sql` (identity and the log) and
> `migrations/v2.17_mcp_approvals.sql` (the write tiers), with
> `migrations/v2.18_mcp_grants.sql` for an endpoint with database credentials of
> its own, `migrations/v2.19_mcp_approval_expiry.sql` for the sweep's marker, and
> `migrations/v2.20_mcp_approval_rerequest.sql` for a revived request's lineage.
>
> Tests: `tests/McpWriteToolsTest.php` proves the write half the same way — a
> preview really writes nothing, an unapproved Tier-3 call sends nothing, an
> approval fits one payload and is spent once, a retried `request_id`
> reports the first outcome instead of repeating it, and a retry after an expiry
> names the reference that died. `tests/McpApprovalSweepTest.php` proves the
> other end of the deadline: closed once, alerted once, recorded for the
> assistant, left alone when a planner actually decided. `tests/McpApprovalsPageTest.php`
> drives the one-click revival over a signed-in session: CSRF-protected, a new
> reference with a fresh window, the old row still expired, the assistant's
> identical retry landing on the new request, and a double-click creating only one.
> Tests: `tests/McpEndpointTest.php` (over real HTTP, including "every tool
> advertised actually runs", "a couple's token cannot open another wedding",
> "a document and the tool behind it are one answer", and "a foreign wedding is
> reported exactly like one that does not exist").

### Business pulse — the owner's numbers, findings and Monday email

`admin/ops_pulse.php` (super admins) puts the platform's own statistics on one
screen: weddings upcoming and how many are unconfirmed, package value against
money collected, MRR and renewals, RSVP response, overdue checklist work, vendor
supply, AI activity and job health. Under the figures are **findings** from
fixed rules — a wedding close to the day with an unconfirmed account, a planner
carrying too much, a balance under 60%, a stale vendor deal, a failing job — each
with the numbers that fired it and the page where a human acts. Nothing on the
page changes data, and none of it names a customer: the evidence is internal ids
and figures, by construction (`admin/include/OpsPulse.php`).

The same two calls are what an AI assistant reads through the MCP tools
`system_status` and `list_insights`, so the screen and the assistant cannot
disagree about the business. `system_status` also returns `movement` — each
headline figure against the last Monday brief's snapshot, with its value,
previous value and signed change (and `available: false` with a reason when no
earlier snapshot exists) — so "is the business improving?" is answered from the
delivered brief's own history rather than from a model's impression.

**Tuning is per account.** The thresholds (how many days counts as "soon", the
RSVP/balance window, stale-deal age, planner load, renewal warning, capacity
band) are saved on the Business Pulse page in `tbluser_prefs` (v2.12) and used
by both the page and the AI tools your token reaches. A rule you have accepted
can be **muted**: it leaves the findings and the assistant's answers, is counted
as `muted_count`, and stays listed on the page with an Unmute button so the
decision is reversible. Nothing is clamped — an out-of-range value is refused,
and a hand-edited row reads as the default.

**The Monday email.** `scripts/business_pulse_email.php` renders the same
aggregates as a plain-text brief and emails it to every super admin with an
address on file (`WPMS_PULSE_TO=a@x,b@y` overrides that for a shared mailbox).
No model writes it, so no customer data leaves the platform and it needs no
provider contract. Every figure carries its change since the most recent
snapshot taken **before** that week (`tblops_snapshots`, v2.23): counts as
`+3`, money as `+95,000`, and the RSVP rate in percentage points (`+2.1pp`).
The comparison is per account, so two owners with different windows are never
compared against each other, and a resend inside a week replaces that week's row
rather than becoming its own baseline. Without the table the brief sends and
says the comparison is off. The cron entry may run daily; the script sends only on Monday
in Africa/Nairobi and stamps the ISO week per owner (`pulse_week`) so a week is
delivered once. A week is stamped only when the mail transport *accepted* the
message — delivered or queued in `tbl_mail_logs` for `mail_retry.php` — so a
hard failure is retried on the next run. The run leaves a `business_pulse_email`
heartbeat, so Job Health reports it if it stops.

```bash
php scripts/business_pulse_email.php --dry-run          # print this week's brief, send nothing
php scripts/business_pulse_email.php --force            # resend to every owner, even if already sent
php scripts/business_pulse_email.php --to=me@x.co.ke    # one address, no week stamp
```

The page also has **Email my brief now** (with the CSRF token every write uses)
and a preview of exactly what the email contains. Each owner's brief uses their
own thresholds and mutes.

```cron
0 6 * * * php /path/to/Wedding/scripts/business_pulse_email.php --quiet
```

The day is decided in **Africa/Nairobi**, not by the host's clock: on a server in
another timezone, pick a cron hour that lands on Monday in Nairobi (the script
also runs daily, so the next day's run catches a week that was skipped).

Tests: `tests/BusinessPulseTest.php` (calendar and ISO week, same-figures
contract, no personal data, super-admin recipients, one-brief-per-week,
per-owner thresholds, outbox row, and the week-over-week comparison: signed
counts/KSh/points, every figure's change cell, no baseline, resend, pruning and
malformed history) and `tests/OpsPulseTest.php` (totals, money
identity, no-PII contract, access gates, tuning and mutes).

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
| Planner | `njeri@wpms.co.ke` | `Test@1234` |
| Planner | `otieno@wpms.co.ke` | `Test@1234` |
| Vendor | `chebet@floral.co.ke` | `Vendor@2024` |
| Vendor Lead | `kamau@nyamachoma.co.ke` | `Vendor@2024` |
| Couple (client) | `brian.wafula@gmail.com` | `Test@1234` |
| Couple (client) | `faith.njeri@yahoo.com` | `Test@1234` |
| Couple (client) | `otieno.kisumu@outlook.com` | `Test@1234` |
| Couple (client) | `chebet.eldoret@gmail.com` | `Test@1234` |
| Couple (client) | `abdi.garissa@gmail.com` | `Test@1234` |

> The legacy template login (`admin@mail.com` / `Password@123`, listed in the
> bundled `01 LOGIN DETAILS & PROJECT INFO.txt`) belongs to the original
> pre-bcrypt dump and **will not authenticate** on v2.1 — use the Kenyan seed
> accounts above.
>
> Where each role actually lands, the page-by-page access map and the day-one
> gotchas (login throttle, the planner paywall, vendor registration) are in
> [`docs/XAMPP_LOGINS_AND_ROLES.md`](docs/XAMPP_LOGINS_AND_ROLES.md).

---

## Development

```bash
composer install                 # dev tooling (PHPUnit)
composer test                    # run the PHPUnit suite (tests/)
composer lint                    # php -l over every admin/*.php file
composer code-audit              # static sweeps A-G: schema, calls, SQL columns, promises, enum values, fixtures, unbound SQL
composer migrate:db-compat       # mysqli -> PDO compat helper (scripts/)
```

The dashboard skin — the warm-stone palette, the token sheet, the three CSS
layers and their load order, the contrast rules and how to add a screen — is
documented in [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md).

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

- **`composer code-audit` is the static gate** — it fails the build on a drifted schema, a
  call to a method that does not exist, a SQL column that does not exist, (sweep D) a
  role-facing promise backed by state no code can write, and (sweep E) a status or enum value
  the code reacts to — a badge, a filter, a branch — that nothing ever writes. Sweep D's
  registry and its accepted-with-a-reason list live in `scripts/audit_promises.php`; if you add a
  sentence that promises a wait ("awaiting…", "will appear here", "we will contact you"), the
  sweep will ask you which table or column keeps it, and the build will fail until you answer.
  Sweep E's acceptance list is in `scripts/audit_enum_values.php`; it prints every value each
  entry excuses, on every run, and fails the build once an entry excuses nothing.
  Sweep G is the one that guards **how** SQL reaches the server: the PDO wrapper's legacy
  `query()` shim always prepares now, and `scripts/audit_unbound_sql.php` fails the build on any
  call that would run a statement unprepared — the shim, the retired `setQuery`/`executeQuery`
  pair, `escape_string()` pasted into SQL, a raw `$pdo->query()`/`exec()` on a page that answers a
  request, or request data appearing inside the statement text. Reach for
  `execute()`/`fetchOne()`/`fetchAll()`/`fetchValue()` with placeholders and it stays quiet.
- **Security hardening is in place** — PDO prepared statements everywhere,
  CSRF tokens on login/register, session regeneration + fingerprint binding,
  and audit logging (`tblsession_audits`). Don't regress them.
- **Live integrations** (Africa's Talking SMS, M-Pesa Paybill/Till) require
  your own accounts; see the manual before enabling. M-Pesa codes can be
  verified automatically via the Daraja C2B callback (`mpesa_c2b.php`) —
  see the configuration section above.
- This is a starter product, not turnkey production software — review
  `WPMS_KENYA_MANUAL.md` §11 (production checklist) before going live.
