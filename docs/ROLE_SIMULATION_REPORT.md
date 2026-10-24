# WPMS Kenya — Role Simulation Report

> **Historical (pre-scoping).** This report was produced before per-planner
> scoping existed. Where it says a planner "sees every booking" or that
> `vendor_enquiries.php` is unscoped, that was true of the build measured and is
> not true now: a planner is limited to their own weddings plus the unassigned
> ones (`Helper.php`, `WPMS_PLANNER_SCOPING`). Re-run
> [`scripts/simulate_roles.php`](../scripts/simulate_roles.php) before quoting
> any planner behaviour from this document.

**Date:** 15 October 2026
**Method:** every role driven through the *running application over HTTP* (not unit tests), against a
pristine copy of the Kenyan seed database.
**Harness:** [`scripts/simulate_roles.php`](../scripts/simulate_roles.php) (new, repeatable)
**Result:** **342 checks, 0 failures, 2 skips** — after finding and fixing twelve real defects (F1–F12)
and closing the drift gap this pass found (F13).
**Also:** an audit of every "wait for someone" workflow (section 5). It found **four** dead ends of that
class — the vendor approval (F2, fixed), an owner's second business (F6, fixed), subscription-plan
definitions (F9, fixed) and the staff marketplace's vendor contact (F8, still open). A later pass added
**F10**: the plan editor F9 shipped could define a plan that could never actually be *sold*, because the
column that stores a subscription's plan key was an ENUM of the three seeded keys — and the same pass put
plan-level subscriber counts and revenue on the revenue dashboard, with a who/when ledger behind every
price change.

---

## 1. Environment

| Item | Value |
|---|---|
| Application | `C:\xampp\htdocs\Wedding`, PHP 8.2.12, MariaDB 10.4.32 |
| Server | `php -S 127.0.0.1:8123 -d display_errors=1 -d error_reporting=E_ALL`, stderr captured to a log and passed to the harness with `--log` — **warnings are visible in the response *and* in the log** |
| Database | `wpms_sim`, built fresh by `scripts/demo_reset.php --db=wpms_sim` (base dump → Kenyan schema → v2.1 seed → migrations v2.1–v2.7) |
| Live database | **never touched.** `dbwedding` was not written to at any point; the harness refuses to run against it |
| Auth | login throttling **left ON** (real behaviour); only the leftover counters from a previous run are cleared so repeated runs are deterministic |

## 2. Roles simulated

Every seeded account was exercised, not a sample.

| Role | Accounts | Landing page verified |
|---|---|---|
| Guest / anonymous | — | public site, RSVP, wedding website, webhooks |
| Couple / client | 5 (`brian`, `faith`, `otieno`, `chebet`, `abdi`) | `admin/client.php` |
| Planner / staff | 3 (`amina` active plan, `njeri` + `otieno.planner` no plan) | `dashboard.php`, or `subscription.php` when paywalled |
| Vendor | 2 (`kamau` with **two** businesses, `chebet`) | `vendor_home.php` |
| Super admin | 2 (`wafula` with a plan, `abdi` with none) | `dashboard.php` |

Cross-role access was probed in both directions: a couple asking for another couple's wedding, a couple
asking for staff pages, a vendor asking for the back office, a non-operator asking to publish a listing,
and an anonymous visitor asking for every gated page.

## 3. Phases and results

| Phase | What was simulated | Checks | Result |
|---|---|---|---|
| P1 | Guest / anonymous: 11 public pages, 4 static assets, package + wedding detail pages, public wedding website, RSVP (valid token, bogus token, no token), 404/500 pages, `deploy_check`, `cron_bridge`, both M-Pesa webhooks | 35 | ✅ |
| P2 | Authentication matrix: all 12 seeded accounts (landing page asserted), 3 rejection cases, CSRF-less login, already-signed-in redirect, logout, forgot/reset password, `verify_planner` | 27 | ✅ |
| P3 | Couple: dashboard + 9 couple pages for the owning couple, the other 4 couples, cross-tenant read probes, staff-page probes (including the capacity headroom view) | 42 | ✅ |
| P4 | Planner: 22 unparameterised staff pages, 16 booking-scoped pages, 20 detail/guard/action URLs, AJAX fragment endpoint, WhatsApp queue | 62 | ✅ |
| P5 | Paywalled planner: 10 gated pages must divert to `subscription.php` | 12 | ✅ |
| P6 | Vendor: ledger / packages / profile, marketplace, 7 denied back-office pages (including the capacity headroom view), operator exemption with no plan of their own, **and the vendor's own write path in both directions** | 25 | ✅ |
| P7 | Super admin: revenue, user admin, login audit, M-Pesa ledger (+filter), vendor directory, enquiries, subscription, **and the whole plan-definition capability — define → it is on sale → edit price/limit → the price change is recorded with who made it and what it replaced → retire → off the purchase list *and* the purchase path → revive → refused to a planner** | 28 | ✅ |
| P8 | **Write paths** — real mutations, each asserted in the database, including the couple onboarding funnel end to end, the headcount brief the couple's edit sends to the planner **and the venue contact**, the booked caterer/decorator the same edit reaches **scoped to the functions their deal covers**, the plan that outgrows a booked package **with the way out it carries and the one step that re-prices the deal**, **the deal that is RECORDED on a package too small for the plan — the earlier, quieter half of the same mismatch**, and **the drift view: the wedding running close to a booked limit is listed with its function, count and limit, the badge counts exactly what the page lists, a 7%-margin row is narrowed out of a 5% window, reading it rings no bell, and the row disappears with its own probe deal** | 89 (2 skips) | ✅ |
| P9 | Clean-up audit: no simulation residue (including the plan-change ledger, the venue headcount plan, the recording-door probe deal and the drift probe's deal and package), all seeded logins still verify | 21 | ✅ |
| P10 | **Server-log diagnostics** — scans whatever this run appended to the dev server's stderr log | 1 | ✅ (see F5) |

Counts are status rows (pass/fail/skip); the run also prints non-counting `INFO` lines, which is why a
phase can show more printed lines than checks. Every number here is re-derived from the run's JSON
output rather than transcribed.

### Write paths (P8) — all verified by reading the database back

Guest add / edit / delete-by-GET refusal · cross-tenant guest write refused · checklist toggle ·
installment plan create + unsupported length refused + cancel · **venue headcount plan set on the venue
sheet itself (F11): the sheet offers a field per function, the save writes one row per function, the sheet
prints the number back, a re-save replaces the row instead of stacking a second one, an existing plan is
corrected, a function of another wedding is refused, and the whole-form save leaves the functions the user
did not touch bit-for-bit as they were** · **and that save BRIEFS both recipients: the assigned planner and
the venue contact booked on the wedding are each notified with the moved numbers, the brief names the
function that moved and no other function of the wedding, the sheet names the contact the brief goes to,
and the couple are told which recipients were reached (F11)** · **the other vendors too: a booked vendor
whose deal covers the moved function is briefed with the numbers, the brief names the scope it was sent
under, one change is one alert per ACCOUNT however many listings that login holds (two probe listings, one
bell), the sheet lists the vendor brief with the functions it covers, and the planner is told which vendor
the same numbers went to (F11)** · **and the plan that outgrows what was sold (F12): the function is pushed
past the guest capacity of the package the caterer was booked on, the vendor holding it hears the limit in
the SAME bell as the change (one save is one alert, not two), the planner's brief names that vendor and
package **and names the next package up with its price difference — inside the bell column, which is what
the dropped duplication pays for — while the vendor's copy carries the limit and not the suggestion, the
couple's copy carries neither, and the sheet read afterwards — flash gone — still prints `Over capacity:`
beside the next package up and the `Re-price this deal` link, whose target row offers that vendor's own
packages to re-price onto** · **and the drift view (F13): a probe package a few guests above this
wedding's biggest planned function is sold and confirmed, the headroom page lists the wedding with the
function, the count, the package and the limit it is closing on plus the venue sheet link, the navigation
badge reads the same number the page displays, a hand-narrowed `?within=5` window drops that row, no
notification row appears from any of those reads, and deleting the probe leaves the page empty again — so the
row was the probe's and not the seed's** · M-Pesa deposit →
ledger row with a
`QDL…` reference → planner notification (before/after counts) · vendor enquiry row → planner notified ·
**an enquiry to the second business of a two-listing owner is recorded against that listing and is
visible to its vendor** · "mark invites sent" mints RSVP tokens and stamps `rsvp_invited_at` · bulk RSVP
reminder stamps the selection · budget line creates a ledger row · new client account created · staff
account created **and then signed in** · guest RSVP reply, answer change, and an out-of-range status
refused · vendor register → operator approval → visible in the couple marketplace → non-operator blocked ·
SMS credit purchase refused with no code, a short code and a bogus code (balance asserted unchanged) ·
forgot-password token issued and rendered.

### Vendor write path (P6) — the gap this pass closed

The vendor's only write capability is confirming/completing/cancelling their own bookings on
`vendor_home.php`. **No check in this harness ever POSTed it** — P6 fetched the vendor's pages and
stopped there. That blind spot is why a 500 that broke the path for *every* vendor shipped through a
281-green run (F7). P6 now POSTs the transition in both directions: the vendor's own booking must answer
`302` and transition with a notes-trail entry; another vendor's booking must be refused with
"That booking is not yours." and stay `pending`.

## 4. Defects found

**F1–F7 and F9–F13 were fixed and re-verified. F8 is reported and deliberately unchanged** — it needs a
product decision the codebase does not make (see section 5).

### F1 — The vendor directory paywalled the operator out of their own back office
**Severity: high (production, for a super admin without a plan)**

`admin/vendors.php` hand-rolled its own subscription test (`Subscription::get_active()`) instead of using
the shared gate. Every other staff page uses `require_staff_subscribed()`, which explicitly exempts
`access_level 2` ("the SaaS operator is never locked out of their own back office"). The consequence was
reproduced live: `abdi@wpms.co.ke` (super admin, no plan of their own) was bounced from the vendor
directory to `subscription.php` while `dashboard.php`, `users.php` and `revenue.php` all let them in. The
hand-rolled check also ignored the grace window, so a lapsed-but-in-grace planner was hard-locked here
and nowhere else.

**Fix:** use `staff_paywall_locked()` — the same gate as every other page. A vendor session still passes
(`is_staff()` is false for them) and the documented two-audience behaviour is preserved.
**Verified:** operator reaches `vendors.php` / `users.php` / `revenue.php`; the paywalled planner is still
diverted from `vendors.php` (P5), and the paying planner still gets in (P4).

### F2 — There was no way to approve a vendor anywhere in the application
**Severity: high (dead-end onboarding)**

`vendor_register.php` always inserts `is_approved = 0`. `Vendor::get_approved()` only returns
`is_approved = 1`, and `client_vendor_enquiry.php` refuses enquiries to anything else — so a
self-registered vendor is invisible in the marketplace and takes no enquiries, while `vendor_home.php`
tells them to wait for "an administrator [to] approve your listing". There was no such screen: a
codebase-wide search found **no writer of `is_approved` at all** except the hardcoded `0` on insert.
`vendor_profile.php` documents the field as admin-controlled. Publishing a vendor required manual SQL.

**Fix:** an operator-only approval action on the vendor directory (POST + CSRF, super admin only) with an
"Awaiting approval" panel listing pending businesses and an "Unpublish" control on live ones.
**Verified end to end:** operator approves → `is_approved = 1` → the listing appears on the couple's
marketplace → a planner POSTing the same action is refused and the flag is unchanged.

### F3 — Creating a staff account either 500'd or silently created nothing
**Severity: critical (the feature was completely unusable)**

Two independent faults in `admin/users_add.php`:

1. **HTTP 500.** `tblusers.access_level` is `enum('0','1','2') NOT NULL` with no default, and the page
   never set it — so the INSERT always failed with
   `SQLSTATE[23000] 1048 Column 'access_level' cannot be null` (`db_object.php:236`).
2. **Silent data loss.** With a picture supplied, fault 1 was reachable; without one, `set_file()`
   recorded "There was no file uploaded here" and `Users::save()` refused the INSERT — while the page
   still printed *"…is successfully added"* and redirected to the user list. It also emitted
   `PHP Warning: Undefined array key "profile_picture"` on line 63.

In other words the operator was told an account existed that was never written, or got a 500 page. There
was no input that produced a working account.

**Fix:** the form now has the Role selector it was missing (`access_level`, validated against the enum,
defaulting to planner); `set_file()` is only called when a file was actually supplied; `Users::save()`
treats the avatar as genuinely optional for creation (empty `profile_picture`, no pending error); and the
page reports the real outcome instead of unconditional success.
**Verified:** no-picture create → `302 → users.php`, row present with `access_level = 1` and an empty
avatar; with-picture create → row present, `access_level = 0`, file moved to `admin/upload/users/`; the
new planner then signs in successfully. The two sibling pages (`users_edit.php`, `users_profile.php`)
were re-tested without a picture and still update correctly, with no new warnings.

### F4 — SMS credits were handed out with no payment at all
**Severity: critical (a paid product, given away)**

`admin/sms_center.php` advertised the button as *"Buy via M-Pesa"* and printed *"Paybill: 123456 | Account:
SMS"*, but the `purchase_sms` handler never read a transaction code: it looked up the package, credited the
account and wrote no payment record. One POST bought the pack, and the same POST could be repeated without
limit. Reproduced live against the seed: a planner's balance went **10,499 → 20,498** on an unauthenticated
code, with no M-Pesa row anywhere — while the identical purchase on `subscription.php` has always required a
verified Daraja callback. The intent was M-Pesa; only the confirmation step had never been written.

**Fix:** mirror `subscription.php` — the 10-character code is mandatory, it is verified against the Daraja
callback for the pack price and the `SMS` account reference, the callback is marked consumed so one payment
cannot be replayed for a second pack, and a reject is recorded. **Verified:** no code, a 5-character code and
a bogus code each leave the balance untouched (asserted back against `tblsms_credits` in P8); a planted
genuine callback grants the pack once, a replayed callback does not, and a second real purchase is not
blocked.

### F5 — Every page that printed a relative time emitted a PHP 8.2 deprecation, and the harness could not see it
**Severity: medium (noise today, a hard error in PHP 9)**

`time_elapsed_string()` in `admin/include/Helper.php` did `$diff->w = floor($diff->d / 7)`. `DateInterval` is a
built-in class with no `w` part, so that line attached a **dynamic property** to it — deprecated since PHP
8.2 and removed in 9.0. It fired on every call, on every page rendering a relative time
(`client_checklist.php`, `login_activity.php`, …).

**Fix:** derive weeks and days into locals instead of onto the interval. Output is unchanged — the old and
new implementations were run side by side over 56 cases (both `$full` modes; seconds through multi-year
spans, including the week+day remainder cases) and every string matched byte for byte.

**Why the simulation missed it — and what was changed so it cannot:** the deprecation never reached a
response body. `php -S` writes diagnostics to its own log, and with `html_errors` off they are not in the HTML
the harness reads. Every body scan was legitimately clean while the log held 44 warnings. The harness now has
a **second diagnostic channel**: `--log=PATH` records the log's size at the start of the run and phase 10 scans
everything appended after that for PHP warnings, notices, deprecations, fatals and parse errors, failing the
run on any of them.

That check was itself tested against a deliberately reintroduced defect: with the dynamic property planted
back, phase 10 failed and it was the **only** failure in the run — precisely the blind spot it exists to
close. Two mistakes in the check were caught the same way and fixed: it first reported clean because it gated
on `filesize()`, whose result PHP caches per path for the life of the process (so a size comparison silently
sees "nothing new"); it now seeks and reads to EOF and consults no size at all.

### F6 — An owner with two businesses could never see part of their own business
**Severity: high (a promise made to a couple that could not be kept)**

`tblusers` 8 owns two approved listings — #200 Nyama Choma Masters and #203 Safari Transport. Every vendor
page resolved "my listing" with `Vendor::get_by_user()`:
`SELECT * FROM tblvendors WHERE user_id = :uid LIMIT 1`, with no `ORDER BY` — pinning the account to one
arbitrary business.

Reproduced live: a couple's enquiry sent to listing #203 created a valid `pending` row in
`tblvendor_bookings` and the couple was told *"They will contact you shortly"*, while `vendor_home.php`
resolved to listing #200 and could never display it. The promise dies at the vendor's own screen: the
staff ledger (`vendor_enquiries.php`) is unscoped and does list that row, so a planner can see the lead
and chase it — but the party the couple was promised a call from is the vendor, and the vendor can
neither see the enquiry nor accept the booking, because their ledger is scoped to the one listing the
session happens to resolve to. The same pinning left listing #203's own packages and profile unreachable
to manage.

**Fix:** `Vendor::all_for_user()` (every listing, in id order) and `Vendor::resolve_for_session()`, which
honours a requested `vendor_id` **only when this account really owns it** and otherwise falls back to the
first listing — so the parameter cannot be used to reach another vendor's rows. `current_vendor_listing()`
in `Helper.php` remembers the viewed listing in the session, so a page's other forms (add package, save
profile) do not silently snap back to the first. The vendor ledger, its status tally and the POST
ownership guard now span **every** listing the account owns, with the ids bound as named parameters.

**Verified:** the enquiry to the *later* listing is recorded against that listing (asserted by
`vendor_id`, not by the page loading); the vendor's ledger displays it — the assertion requires the
enquiry's own text to appear, because "the page rendered" is exactly the assertion that would have passed
while the lead was invisible; and the transition note names the business the row belongs to, not the one
being viewed.

### F7 — Every vendor's booking transition returned HTTP 500
**Severity: critical (the vendor's only write capability was dead) — introduced while fixing F6, and
caught by the unit suite, not by this harness**

The ownership scope added for F6 was interpolated into a **single-quoted** SQL string:

```php
'… WHERE vb.id = :id AND vb.vendor_id IN ({$ownedIn}) LIMIT 1'
```

`{$ownedIn}` cannot interpolate inside `'…'`, so the literal text reached MariaDB and every POST to
`vendor_home.php` died with `SQLSTATE[42000] … syntax … near '}) LIMIT 1'`. Confirming, completing or
cancelling a booking was a 500 page for every vendor in the system.

**How it was found:** PHPUnit's `VendorSelfServiceTest` reported `Failed asserting that 500 is identical to
302` on both of its vendor-transition tests. Reproduced over HTTP against the dev server, which named the
query immediately. Reverting my change was not the answer — the F6 bug was real — so the query was fixed
and the path is now covered by checks instead.

**Fix and coverage:** the query is double-quoted (and the sibling UPDATE statements already were). A
tokenizer sweep for `{$var}` / `${var}` inside single-quoted strings across every PHP file in the project
confirms this was the only instance. P6 gained the three vendor write-path checks described in
section 3.

**Note on the harness:** the run before the fix reported 281 passed / 0 failed while that path was dead,
because nothing in it POSTed a vendor transition. A harness that reports an *unexercised* path as a pass
is the failure mode this report keeps running into, which is why the coverage gap was closed rather than
just the query.

### F8 — The staff marketplace promises a vendor contact that never happens
**Severity: high (an active false success message on a live page) — reported, not changed**

`admin/vendors.php` (the staff-side marketplace, open to planners and vendors) renders a **"Contact
Vendor"** button per listing that opens a modal with *Event date*, *Your message* and a **"Send Inquiry"**
submit. The `contact_vendor` handler reads all three fields, carries the comment `// Log the inquiry` —
and writes nothing:

```php
$vendor_id = (int)$_POST['vendor_id'];
$message   = clean($_POST['message']);
$event_date = clean($_POST['event_date']);
// Log the inquiry
$session->message('… Your inquiry has been sent to the vendor. They will contact you shortly.');
```

**Proven live, not read:** logged in as a planner, loaded the directory (button and submit both present,
`HTTP 200`), posted the modal exactly as the browser does (`HTTP 302 → vendors.php`), and the response
carried the promise — while `tblvendor_bookings`, `tblnotifications` and `tblsms_logs` were **all
unchanged**. Nobody has ever been contacted through this control; the role is told to wait for a contact
that cannot come.

**Why it is reported rather than fixed:** the working equivalent already exists — the couple-facing
marketplace (`client_vendors.php` → `client_vendor_enquiry.php`) records a real pending row, notifies the
assigned planner and alerts the vendor, and it explicitly accepts staff enquiries on a couple's behalf.
The gap on the staff page is that an enquiry there has **no wedding to attach to**, and both ledgers
(`vendor_home.php`, `vendor_enquiries.php`) `INNER JOIN tblweddingbook` — so a `booking_id = 0`
"general enquiry", which `Vendor::record_enquiry()`'s own docblock documents, would be invisible to
everyone. Closing it properly means choosing between (a) making the operator pick the couple/booking
before sending, (b) supporting booking-less general enquiries by left-joining both ledgers, or (c)
retiring the staff modal in favour of the couple-scoped flow. That is a product decision, so it is left
to the owner with the evidence above.

### F9 — No role could define a subscription plan
**Severity: medium-high (the paywall's promise had no actor; prices could not change without SQL)**

`subscription.php` tells its reader: *"No subscription plans yet — Plans will appear here once the team
defines them."* Nothing in the application could define one. `tblsubscription_plans` was referenced exactly
three times in code: two `SELECT`s in `admin/include/Monetization.php` (list the active plans, look one up
by slug) and the expected-table list in `deploy_check.php`. There was **no INSERT, no UPDATE, and no admin
screen** — rows existed only in the seed dump. So on a fresh install without the seed the message pointed
at an action no role could perform, and even with the seed no plan's price, wedding limit, feature list or
`is_active` flag could change — a plan could be neither retired nor activated. Same shape as F2: a role
told to wait for a screen nobody has.

**Fix:** a new operator-only screen, [`admin/subscription_plans.php`](../admin/subscription_plans.php),
reached from the Monetization nav (super admins only) and from the empty state on `subscription.php`
itself. It lists every definition — retired ones included — with its key, live/retired state and how many
subscriptions have been bought on it, and it can create a plan, edit its name, price, wedding limit and
features, retire it and put it back on sale. All of it is POST + CSRF; the operator gate sits **before**
the handlers, so a planner cannot write even by posting the form directly.

Three decisions are worth stating, because they are the difference between a screen and a working feature:

- **Retire, don't delete.** `tblsubscriptions.plan` stores the plan key, so a plan somebody bought has to
  keep resolving — for their own history, for the renewal texts and for the M-Pesa callback that activates
  it. Retirement flips `is_active`, which is exactly what the purchase list already filtered on.
  `Subscription::get_plan()` therefore still ignores `is_active` (deliberate), and there is no delete.
- **Retirement had to close the purchase *path*, not just the card.** `get_plan()` was the only lookup the
  purchase handlers used, so a retired plan would have stayed purchasable by posting its key. A new
  `Subscription::get_purchasable_plan()` (`… AND is_active = 1`) now gates both purchase paths in
  `subscription.php`, and the refusal it produces is reached *before* any M-Pesa code is examined — so
  "refused" cannot be confused with "bad code".
- **The key is immutable.** A key that has been sold cannot be quietly redefined as a different product,
  so the editor refuses a duplicate and leaves the existing plan untouched.

**Verified live, end to end (11 harness checks and 5 integration tests):** the operator defines
`simprobe…` → a row is written live with the right price and limit → it appears on the planner's
subscription page → the operator edits the price and limit and the row changes → retiring it removes it
from the planner's purchase list, and posting its key straight at the purchase handler buys nothing
(`tblsubscriptions` count asserted unchanged) while the planner is told why → putting it back on sale
restores it → a planner asking for the editor is refused with the reason → the probe definition is
removed and phase 9 confirms no residue. The integration tests in
[`tests/SubscriptionPlanAdminTest.php`](../tests/SubscriptionPlanAdminTest.php) cover the same capability
without the harness — five of them now, the extra two being F10's ledger and buy-a-defined-plan checks.

The new page also had two faults of its own in its first run, both caught by this suite rather than by
review: it included the sidebar without defining `$users_profile` (which every other page sets), and it
refused a planner with a redirect to `dashboard.php`, which does not render session messages — so the
refusal was silent. The first was found by phase 10's log channel as
`Warning: Undefined variable $users_profile`, the second by an assertion that the reason is *shown*; both
are fixed and asserted now.

### F10 — A plan the operator defines could be listed for sale, but never sold
**Severity: high (money taken, and the subscription stored on no plan) — found while building the plan-level revenue report, fixed**

F9 gave the paywall's promise an actor: the operator's plan editor can define a plan under any key it
accepts. It could not *sell* one. `tblsubscriptions.plan` was
`ENUM('starter','professional','agency','trial')` — the three seeded keys plus the trial marker — while
`tblsubscription_plans.plan_slug` is `VARCHAR(20)` and the editor accepts any 2–20 character key. This
environment's `sql_mode` has no `STRICT_TRANS_TABLES`, so the purchase INSERT did not fail at all: it
stored `plan = ''`.

**Measured, not inferred.** In a transaction against the scratch database (rolled back afterwards),
inserting a subscription on a key the operator had just defined returned success and reading the row back
gave `plan = ''` — no error, no warning. So a planner paid, was told *"Subscription activated! Plan:
Premium"*, and held a subscription attached to no plan: their own history rendered an empty plan, the
renewal path looks a key up and finds nothing, and no subscriber could ever appear on the plan the operator
had created minutes earlier.

**Why F9's verification missed it.** F9 asserted the halves it could see — a defined plan *appears* on the
purchase page, a retired one is *refused* — and the untested leg was completing a purchase of a **new**
key. The schema sweep could not catch it either: it asks "does this column have a writer?", and `plan` has
one. The defect was the column's *type*, one layer below the layer every earlier check looked at.

**Fix:** `migrations/v2.8_plan_pricing_audit.sql` widens `tblsubscriptions.plan` to `VARCHAR(20)`, matching
`plan_slug` so the key stored is the key chosen. It is idempotent, it is in `demo_reset.php`'s build list
(a fresh scratch build was verified to come out with the widened column), and `deploy/schema.sql` ships
the widened column for fresh installs, with the migration named in `DEPLOY_CPANEL.md` for databases created
before v2.8. Rows whose key was already coerced to `''` are **not** rewritten — the slug was never stored,
so there is nothing to recover — and the revenue report lists such keys as "(no plan recorded)" rather than
hiding the money they represent.

**The same question, one dimension over: which price was in force, and who moved it?** A plan's price *is*
the platform's revenue, and the table held only the current value: every earlier price was overwritten in
place, so "who changed this, when, and from what" had no answer for any plan — seeded or newly defined.
The migration adds `tblsubscription_plan_changes`, and the editor writes one entry per FIELD that actually
changed, with the old value, the new value and the signed-in operator. A save that changes nothing writes
nothing, so the ledger answers "who changed this", not "who opened the page". There is no foreign key, on
purpose: an audit trail must not be erasable by deleting its subject (which is why `simulate_roles.php`,
which deletes its probe plan, removes its ledger rows explicitly and phase 9 now checks for them).

**What the operator sees now.** `/admin/revenue.php` gains a **Revenue by plan** table — subscribers (people,
not payments: a renewal is the same planner paying again), live subscriptions, revenue, share and last-paid
date per plan, retired plans included, totalled to the same subscription figure as the card above it. A plan
nobody has bought shows 0 rather than vanishing, so "no sales" and "no plan" cannot be confused. The plan
editor shows each plan's last price change — who, and when, and from what — with the full history one click
away.

**The whole-suite run caught one more thing, and it is the same trap in miniature.** The new purchase
assertion passed when its file ran alone and failed in the full suite (`the plan key must survive the
INSERT — Expected 'sptest…', Actual ''`). The cause: `tests/PlannerTrialSignupTest.php` and
`tests/TrialNurtureEmailTest.php` each open with a "repair the columns if the migrations have not been run"
helper, and both applied the *historical* type — `MODIFY plan ENUM('starter','professional','agency',
'trial')` — which, after v2.8, put the shared scratch database straight back into the state that silently
discards a plan key. Two lessons, both already in this report: a test that mutates the shared schema leaves
the next test's environment undefined (§7's residue effect, now with a schema example), and a helper whose
stated job is "ensure the columns exist as the app needs them" has to apply the CURRENT shape or it becomes
a downgrade. Both now widen the column to `VARCHAR(20)`, and the assertion stands unchanged.

**Verified live, end to end:** the operator defines a probe plan at KSh 6,123 → a planner buys it through
the purchase page with an M-Pesa code → the stored `plan` is the slug (this is the assertion that fails on
an ENUM build) → the breakdown reports exactly 1 subscriber / 1 live / KSh 6,123.00 on it → both the
dashboard and the editor render it → cleanup removes the plan, the subscription and the ledger rows, and a
query of both databases confirms no residue. The harness asserts the ledger independently in phase 7
(price 4321.00 → 9999.00, actor = the signed-in operator, exactly two price entries) and phase 9 now fails
on a leftover ledger probe row. Both new checks were shown able to fail: disabling the recorder made the
harness report `FAIL the price change is recorded with who made it and what it replaced — old= new=4321.00
actor=5 price entries=1`, after which the file was restored byte-for-byte (md5-verified).

### F11 — The venue sheet printed planned headcounts no role could enter
**Severity: high (a brief handed to a venue and its caterers, frozen at whatever the seed dump contained) — found by sweep D as O11, fixed**

The venue sheet's "Headcount plan per function" table is the document a couple gives the venue and the
caterers: how many *family* and how many *individual* guests to cater for, per function. Those numbers live
in `tbleventbooking.family` / `.individual`, and **nothing in the application could write them.**
`admin/include/BookingEvent.php` named the table and declared every field as a property — but did not extend
`DB_Object`, so it had no `create()` and no `update()` at all, and no screen anywhere offered the fields.
That also made sweep D's first question answer itself wrongly: a table with a class *looks* accounted for.

**The rows were also resolved against the wrong table.** The sheet read `features_id` through a left join on
`tblpostwedding` — the public "real weddings" *blog* — so with the shipped data it printed other couples'
blog posts, title and location included, as this wedding's functions ("Beach Wedding in Diani — Faith &
James" appeared as a headcount row). `features_id` has always been an `events` id of the same booking, which
the seeded values themselves confirm: booking 200's Church Wedding row carries package 7 and its Reception
row package 9, the per-function packages of those very events.

**Fix, and where the editor lives.** The sheet now builds its table from the wedding's own `events`
(`BookingEvent::plan_for()`), so a plan row can never borrow another wedding's function, and the same
table renders an **editor** for the numbers above it — on the page and under the figure it controls, because
the sheet is the only place these numbers are used and staff already open it for any wedding while a couple
opens their own (`sheet_boot`'s rule, unchanged). The editor is hidden when the sheet is printed. The writer
is `admin/event_booking_save.php`, and it is the whole access model in one file: staff may plan any wedding
they can open, a couple only their own; only functions on THIS wedding's calendar are writable (the
submitted keys are matched against the booking's own function list, so a crafted POST cannot park counts
where no page would show them); a count must be a whole number ≥ 0, so `(int)` cannot turn `abc` into a
silent `0` that wipes a plan; and a bad row is reported rather than dropped quietly.

**`migrations/v2.9_venue_headcounts.sql`** makes "one plan per function" a database rule — the unique key
`(booking_id, features_id)` that `BookingEvent::set_counts()` writes against with `ON DUPLICATE KEY UPDATE`
— and deletes plan rows that resolve to another wedding's function (dead state: displayed nowhere and
editable nowhere). On the shipped seed that is exactly one row: booking 201 carried a copy of booking 200's
Church Wedding counts. It is idempotent, it is in `demo_reset.php`'s build list, and `deploy/schema.sql`
ships the key for fresh installs with the migration named in `DEPLOY_CPANEL.md` for databases created
before v2.9.

**Who is told, and why that was the other half of the defect.** Entering the numbers only helps if the people
who cater from them hear about a change. The sheet opens for staff and the couple only, so before v2.9 a venue
learned of a moved headcount when a planner noticed and picked up the phone. A COUPLE's save now sends the
changed functions to the **assigned planner** (`tblweddingbook.organizer_id`) and to the **venue contact** — the
venue-category vendor whose deal on this wedding is confirmed or completed (a pending enquiry is an enquiry,
not an assignment). `include/HeadcountBrief.php` builds it, and it says what MOVED and nothing else: the editor
submits every function it prints, so "Reception — family 20 → 25" with the untouched functions absent is the
difference between a brief that is read and a table that is skimmed. Delivery is `Notification::create()` —
the bell plus the queued email this codebase already uses — with the numbers in the body, so a recipient never
has to open a page they cannot reach; the planner's link is the sheet, the venue's is their own dashboard. No
SMS: a list is not urgent to the minute, and a courtesy text is charged to the planner's credits. Two saves
stay silent by design — one that moves nothing, and the planner's own correction, which must not echo to the
venue as if the couple had changed their mind. A venue with no portal account (`user_id` 0) cannot be reached
at all, so the planner is told to pass it on, and the sheet's Key contacts row says so where the numbers live.

**Verified live:** the harness sets a plan on the sheet and reads the row back, sees the sheet print the
number, re-saves to prove the row is replaced rather than stacked, corrects an existing plan, and is refused
on another wedding's function; `tests/VenueHeadcountPlanTest.php` covers the same ground plus the couple's
path, the cross-tenant refusal, a non-numeric count, a missing CSRF token and an anonymous POST (5 tests /
66 assertions). A fresh `demo_reset.php --db=<scratch>` build comes out with the key and 2 plan rows instead
of 3, and the venue tests pass against it.

The brief is covered the same two ways. The harness books a venue on the wedding (the seed ships no
venue-category listing, which is why nothing had ever walked this path), has the couple move a function, and
asserts both bells, that only the moved function is named, that the sheet prints the recipient, that the couple
are told who was reached — then sweeps the rows it caused. The suite does it in `php -S` HTTP: it creates its
own venue fixture, and covers what the harness cannot from inside the couple's session — the wording of the
brief without a database (`HeadcountBrief::lines()` / `notice()`), a save that moves nothing, a staff edit, and
a venue with no portal account (10 tests / 117 assertions). Every row either writes — plan rows, the venue
listing and its booking, the notifications and their outbox copies — is deleted again in `tearDownAfterClass`,
so the scratch database comes out of a run exactly as it went in.

**The other vendors the numbers are for (v2.10).** The venue and the planner are not the only people who
cater from that table, and the sheet says so itself ("the numbers the venue and the caterers work from"). A
booked caterer, decorator or cake-maker heard nothing about a moved headcount. Which *functions* a vendor's
deal covers had no answer anywhere: `tblvendor_bookings` is a wedding-level row, so the only honest scopes
were "everything" for everyone or a rule invented in code from the vendor's **category** — guesswork that
reads plausible and is wrong for the first caterer who only does the reception. So the scope is data now:
`tblvendor_bookings.features_id` (v2.10) holds the function a deal covers, `NULL` meaning the whole
wedding — which is what every existing deal already means, so there is no backfill. The planner sets it on
the deal itself, in **Vendor Enquiries** (a select of that wedding's own functions, refused if it names
another wedding's — such a scope could never match a change and would look set while reaching nobody), and
the vendor sees it on their own ledger; the venue sheet lists every vendor the brief goes to, with the
functions it covers, beside the numbers that decide it. A venue hosts the whole day, so the venue contact
still receives the whole brief; every other vendor receives **only the functions their deal covers** — a
change to the reception must not wake up the photographer booked for the ceremony, or the alert gets muted.
One more rule fell out of the data: an account can hold several listings on the same wedding (the seed's
vendor login owns a caterer and a transport company), so the lines every listing is entitled to arrive as
**one message per account**, not one per listing. And because `tblnotifications.message` is varchar(255),
where a non-strict MySQL clips a long body mid-number without a word, every message is now fitted to that
column: the optional prose goes first, then the moved functions are counted ("and 2 more moved functions")
instead of half-printed. Verified the same two ways as the base brief, and live: as the planner, scoping the
seeded caterer to Reception reads back on the deal and on the sheet; as the couple, moving Reception's
individual count sends `Reception — individual 100 → 106` to the planner and to the caterer ("Your deal
covers Reception"), while the next save — Church Wedding 50 → 55 — reaches the planner and **not** the
caterer; as the caterer, their ledger shows `Headcount brief: Reception` on that deal.

**A finding inside the fix: the scope control was on a page no browser could render.** The new select sits
in the enquiries ledger, so the ledger was checked in a real browser rather than by string assertions — and
the whole table was missing: `<table>` never existed in the parsed DOM, the page's own `<main>` and every
cell were gone, and a form from the table had been reparented into the sidebar's `<ul>`. The cause was an
HTML comment in `admin/include/sidebar.php`, added earlier in this same uncommitted work: it opened as an
HTML comment but was closed with the PHP block-comment terminator, so it never ended and everything after it
was swallowed as comment text. It only affected super-admins (the sidebar block is gated on
`is_super_admin()`), and **every check in this repository passed anyway** — `php -l`, all five sweeps, the
harness (which asserts response bodies as strings) and the suites. Closed with `-->`, and then covered:
`scripts/lint.php` now also fails on an HTML comment that a PHP terminator leaves open, with the file and
line, and the guard was shown able to fail on a probe file before the probe was removed.

**A side effect the harness caught, not the unit tests.** The editor submits *every* function it prints, so
the first version re-stamped each row's package column on every save — the seeded Reception row's 9 became
the booking's 7 without anyone touching it. The suite had missed it because it posts crafted field sets (the
function being edited only), which is not what a browser sends. `set_counts()` now writes the packageonly when it creates the row, the harness compares whole rows rather than row counts, and the test posts the form
the page actually renders.

### F12 — The plan could outgrow the package a vendor was booked on, and nobody was told
**Severity: high (a caterer orders for the number they were sold, not the number the couple plans) — found by reading the data the brief already had, fixed**

A vendor package states a guest capacity — `tblvendor_packages.capacity`, the "Choma Premium — 250 pax" a
caterer sells, a venue's seats, a matatu's 33 — and a deal records the package it was sold on
(`tblvendor_bookings.package_id`). The couple sets the per-function headcounts on the venue sheet. **Nothing
connected the two.** After F11 and v2.10 the numbers reached the venue, the planner and every vendor booked on
the wedding — correctly, promptly, and with the scope each deal covers — while a function planned for 400
under a 250-pax package was announced as a routine update. Every page, bell and email agreed the day was
ready. The gap was not in the plumbing that F11 built; it was that no one ever asked whether what moved still
**fits**. That is a defect of the same kind as F8: a promise the data made and no code kept.

**What is compared, and why one function at a time.** The plan is per function and a package covers what its
deal covers, so each function is judged against the capacity of the package on every deal that covers it. The
day's functions are deliberately **not** summed: the sheet says itself that the same guest attends several
functions, so adding them invents a crowd larger than the wedding and warns about a limit nobody is near. A
whole-wedding deal (the common case — `features_id` NULL) is therefore judged function by function too. The
rules that keep a warning worth reading are asserted, not assumed: a deal with **no package chosen** states no
limit (nothing is guessed), a package with `capacity` NULL or 0 states none either, a **pending** enquiry is an
enquiry rather than an assignment (the same rule the brief uses), an unplanned function (0 guests) is over
nothing, and a scope that does not reach the function is never asked about it — a warning about a limit that
is not that vendor's is how a vendor learns to ignore warnings.

**Who is told, and how it rides along.** `include/PackageCapacity.php` owns the check and the wording; the
sending stays in `include/HeadcountBrief.php`, which already owns the recipients, the scope rules and the
255-character fit. A vendor's capacity warning is **folded into the same message as the change** — one save,
one bell — because two bells from one save is noise a person cannot act on; the planner's message gains the
same sentence naming the vendor whose package no longer covers the day, so whoever has to re-price the deal
knows whose call it is. A save that moves a **staff** member's number is the one case where the brief stays
silent by design (F11) and the capacity warning does not: the package no longer fitting is a fact about the
deal, not about who typed the number — the caterer has ordered against 250 either way — so the vendors holding
it are warned and the assigned planner is warned too unless they are the one who saved. The saver always sees
it in words, and the venue sheet prints the position **beside the numbers** for whoever opens it next: a
warning that only ever appears in a bell can be dismissed, or arrive after the caterer has ordered. A save
that falls back **under** the limit announces nothing — churn on a guest count is not news, and the sheet
carries the current position.

**No migration.** Every column this needs already exists (`tblvendor_packages.capacity`,
`tblvendor_bookings.package_id`, `tbleventbooking.family`/`.individual`), so an upgraded database gets the
behaviour by uploading the code alone; `deploy/schema.sql` needs nothing new and there is no v2.11 SQL file.

**The second half of the finding: the warning had no way out.** A message that only says *this no longer fits*
leaves the planner with the same phone call they would have made anyway — and **a deal's package could not be
changed anywhere in the application**: `package_id` was set when the deal was recorded and no screen offered
to revise it. So the vendor would have been warned and nothing could have been done about it, which is the
same dead end F8 describes in a different room. Two pieces close it. `PackageCapacity::upgrades()` reads the
SAME vendor's active packages for the next one up that covers the crowd — the smallest capacity that fits,
cheapest first when two are equal — with what it costs against the package already sold, and when nothing
fits it says so instead of going blank: either their biggest package, named **only when it is actually bigger
than the one on the deal** (naming a smaller one as an improvement is worse than saying nothing), or "no
bigger package on file — ask them for a quote", which is the honest answer and the one that turns into a phone
call. The planner's copy of the warning then carries that sentence, and `admin/vendor_enquiries.php` grew the
one step that acts on it: a staff-only **Re-price** control on the deal's own row, offered the vendor's active
packages only, which writes `package_id`, `amount` and the 10% commission and appends the notes trail with who
moved it and both amounts — while leaving `status` (a re-price is not a re-negotiation of whether the vendor is
booked) and the headcount scope alone. Both sides are told: the couple because their money changed, the vendor
because the package they are delivering changed — and the **vendor's copy never states the deal value**, which
is between the couple and the planner. The loop then closes where it started: the confirmation says whether
this deal now covers the plan.

**What the suggestion cost, measured rather than assumed.** `tblnotifications.message` is `varchar(255)`, and
the way out is the longest candidate in the message, so the bell was measured before the copy was believed: on
the seed the planner's message was **50 characters of lead + 137 of warning + 70 of suggestion + prose**, and
it never fit. Two duplications paid for it. The warning quoted the count the function moved *from* (`254 (was
253)`) in a bell whose own moved line already said `family 253 → 254` — the parenthesis is gone from the bell
copy (`planner_brief`; the sheet's copy keeps it, since there the reader is looking at the numbers). And a
breaching function was printed **twice**: once by that moved line and once by the warning that says the same
thing — so the moved line for a function the warning speaks about is dropped from the planner's copy (every
other function keeps its line, the sheet prints every split, and the vendor's own copy still carries theirs).
The result on the seed, measured after the change: **240 characters** on a couple's save and **237** on the
staff check, both carrying the warning and the way out, with the vendor's copy at 193/228 and carrying the
limit but never the suggestion. A message that runs out of room still drops the suggestion before the warning;
a check message with no moved function no longer says "and 1 more moved function" about nothing, which is the
phantom the old shape printed when it overflowed.

**Verified live, in both directions.** The harness's P8 leg buys the seeded caterer's 250-pax deal on the
function it is already editing, gives that vendor a package big enough for the crowd, pushes the plan past the
limit, and asserts the vendor's one bell carries both the moved numbers and `over the package you are booked
on`, that the save produced exactly one alert for that account rather than two, that the planner's brief names
the vendor and the package **and carries the way out with its price difference inside the 255-character column**,
that the vendor's copy does not, that the sheet — read a second time, after the save's flash has gone — still
prints `Over capacity:` beside the numbers **and the next package up with the link that re-prices the deal on
the row that offers that vendor's own packages**, and that the couple's view of the sheet carries the breach
without the planner's shopping list. `tests/VendorEnquiriesLedgerTest` owns the re-price write itself over
HTTP: the deal moves, the amount falls back to the package's price, the commission is re-derived, the status
and the trail are asserted (appended, never replaced), both bells are read back, and each refusal leaves the
deal untouched — a tokenless POST, another vendor's package, a retired package, a package with no price and
nothing typed, a deal that is not confirmed — plus the one correction it does allow (`0` = no package, with the
agreed amount). `tests/VenueHeadcountPlanTest`
adds the wording as unit tests (both readers of the same fact, the "(was 120)" only when it is known, a title
that does not state its own limit, one account breaching twice, one package breaching two functions, and the
breaching vendor nobody can reach) and walks the loop over HTTP as both roles: the couple's save warns the
vendor inside the brief and prints the position on the sheet, and the staff save warns the vendor and the
planner while saying nothing about the change itself — plus the three ways a deal stays out of it, on
fixtures the test creates and removes.

**A wording bug the tests caught, worth recording.** The first version's body read `Capacity check — …`,
while every residue sweep in this repository (and the suites' own bell counter) finds headcount messages by
the word *headcount*. It was not a string assertion that failed but the tidy-up: the new notifications would
have survived a run as orphaned rows in the scratch database. The body now leads `Headcount check — …`, so one
word still finds every message this feature writes.

**The same mismatch begins at the other end of the wedding.** Everything above judges the plan against what
is already sold, and it runs when the NUMBERS move. The trap opens just as easily when the DEAL is recorded,
weeks earlier: the plan is already on the sheet, the couple ask a caterer for the 150-pax package, the caterer
accepts, and the first word anyone hears about the mismatch is the next headcount save — which may be weeks
away, or never, because by then the guest count is settled and nobody edits it again. On the shipped seed the
shape is one dropdown away (120 planned for the reception, the transport account's 33-seat package on the
same wedding). So `PackageCapacity::deal_position()` judges **one deal** — by id, whatever its status —
against the plan as it stands, through the same `judge()` loop the whole-wedding check runs, so a single deal
and the sheet can never disagree about what "over" means; `plan_totals()` became the one reader of
`BookingEvent::plan_for()`, which is the sheet's own source. Four doors call it, each stating the position
where the person who opened it is standing: the couple's **enquiry** (`admin/client_vendor_enquiry.php`) — the
earliest, and the only one where nothing is sold, so the copy says which side of the deal the reader is on
(*"the package you asked X for"*, `requested_clause()`) and names every function the package cannot hold,
with the way out, because the marketplace is one page back and the choice is still free; the **staff confirm**
on the ledger and the **vendor's own accept** on their dashboard, where the deal genuinely becomes what the
wedding is sold on — the page carries the warning and the way out (`position()`), the assigned planner and the
account holding the package are belled through the same `announce_capacity()` the headcount save uses, and the
vendor's copy states the limit and never the planner's price difference; and a confirmed deal's headcount
**scope being widened** onto a function its package cannot cover (the ledger's scope control — one dropdown,
no number moved, and a deliberate act, so it is judged too, and only when the scope actually changed). Two
rules keep it quiet where it should be: a deal recorded on a package that COVERS the plan says nothing at all
(no clause, no bell), and the warning fires only on the transition INTO `confirmed`, because re-confirming or
re-saving is not a new sale. A *pending* enquiry is still never counted as an assignment — its note is advice
to the two people who are still choosing, not a breach. The vendor's own page reads the same sentence the
sheet does, deliberately: the fact is which of their packages does not cover the day, the price difference is
the planner's conversation with them, and a "…have been warned" tail counts a planner from a staff seat and
means nothing on a vendor's.

**One thing the column decided.** The planner's enquiry alert is written by the enquiry page and already
carries the couple, the vendor and the package, so a full clause pair plus the suggestion never fits beside
it. `HeadcountBrief::append_note()` — the one place the 255-character column is applied to a message that
class did not compose — appends the **short** form (`requested_summary()`: the biggest crowd the package
would have to serve, which is the number any bigger package must cover) and drops it whole rather than cutting
it when there is no room. The fact rides on the bell that already told the planner the enquiry exists; the way
out stays on the page the couple just left and on every later bell, where there is room for it.

**The recording doors, verified over HTTP as each role.** `tests/VenueHeadcountPlanTest` drives three of the
four: the couple's enquiry (the flash names the package they asked for, every function it cannot hold and the
way out; the planner's existing enquiry bell carries the short form inside the column; and the wording never
claims a booking), the vendor's own accept (the flash states the limit on the page they just pressed, carries
no suggestion, and the planner's bell is the warning — which is the floor, never what gets dropped), and a
scope widened onto a bigger function (warned once, silent when the same scope is saved again).
`tests/VendorEnquiriesLedgerTest` owns the staff confirm: the deal is recorded, the page states the plan the
package cannot serve **with the way out at the package's real price difference**, the planner's bell carries
the warning *and* the suggestion inside 255 characters, the vendor account hears the limit and no suggestion,
and a second deal on a package that COVERS the plan produces exactly one bell — the couple's. Both suites
assert the measured facts rather than the code's shape: the plan is read from the database, and the two ways a
bell can lose a sentence (dropped, never cut) are asserted on the copy itself. The first run of the staff-door
test caught a real defect in the enquiry copy — it printed only the FIRST breaching function, so a whole-
wedding request hid the reception behind the ceremony — which is why the copy now has one clause per function
from one method (`requested_position()`).

### F13 — The capacity check could only speak after the fact
**Severity: medium (nothing breaks; the planner loses the month of warning that every option depends on) — found by asking what the capacity check cannot say, fixed**

F12 gave the capacity mismatch a voice, and every one of those voices waits for the breach: the plan is
already over the limit, or the deal is already recorded on a package too small for it. Both are correct — a
fact about the deal, and the moment somebody has to act — and both are late. By the time a caterer is briefed
on a jump from 240 to 260 under a 250-pax package, the phone call happens with the deposit paid, the menu
set, and every alternative (split the function, add a package, move the crowd) more expensive than it was a
month earlier. Nothing in the application showed the wedding that was *approaching* a limit: the venue sheet
prints the position beside the numbers, which is read by the person already editing those numbers.

**What is listed, and why nothing is announced.** `PackageCapacity::watch()` answers one question per
wedding — the planned functions sitting within a share (a tenth by default) of the guest capacity of a
package a vendor is confirmed on — over the same `limits()` / `plan_totals()` / `covers()` rules the sheet
and both warnings use, so "close" and "over" can never disagree about what a deal covers. "Close" is a share
of the package's OWN capacity rather than a head count, so the answer means the same thing on a 33-seat
matatu and a 400-seat hall: the margin (`capacity − planned`) inside 5%, 10% or 20% of the capacity, ten by
default and chosen with the chips at the top of the page — three widths rather than a free number, because a
filter that accepts anything is a filter nobody can compare a screenshot of, and anything else in the URL is
the default rather than a 500. It **notifies nobody**, which is the deliberate half of the design: a bell per
wobble of a guest count is noise nobody can act on, and both events this application does announce are
**facts** (the breach, the deal recorded on too small a package) rather than **forecasts**. So the page is
where a planner looks on purpose, and the reason they look is the navigation badge.

**The badge is the feature.** A page nobody has a reason to visit does not exist, so the sidebar carries a
count of the weddings on the list — as **one row of SQL** (`watch_count()`) rather than a walk over every
wedding on every staff page load, written to the same rule as the PHP the page lists with and asserted
against it (`watch()` and `watch_count()` must agree; a badge that disagrees with the page it links to is
worse than no badge). No badge when there is nothing close, so the number keeps meaning something.

**The over ones are not dropped, and not called drift.** A wedding that is already over a limit is listed
too, counted separately in the statistics and flagged on its row, and the page says in red how many weddings
are already over — an empty list must never be read as "nothing wrong here", and the wedding that needs the
phone call most is exactly the one a drift-only filter would hide. Those breaches were announced when they
were created and are printed on their venue sheets; the page says so rather than re-deciding them.

**Staff only, for the reason the ledger is.** Which deals were sold, and how tight each one is, is not a
couple's information — and *"your caterer's package is nearly full"* is a negotiation the planner has not
framed yet, exactly as F12's price difference is. A couple and a vendor are both refused the page.

**Verified.** `tests/VenueHeadcountPlanTest` asserts the facts layer and the rendered page on a fixture whose
margin is inside 10% and **outside 5% by construction**, so the width filter is proven to be doing the
arithmetic rather than decorating the page: the row states the function, the count, the package and the limit
it is closing on and links the venue sheet, the chips widen and narrow it, a hand-edited `?within=junk` is
an error-free no-op (it opens where the page would have opened anyway), the badge and the page agree in the
same run, the same fixture pushed past
the limit is listed as `1 over` with the red line said first and is no longer claimed as drift, **no
notification row is created by any of those reads**, and both a couple and a vendor get a 302. The harness's
P8 leg does the same against the running application with its own confirmed probe deal, then deletes it and
asserts the row is gone — the row was the probe's, not the seed's — while phase 9 audits that probe's residue
by name. The copy is asserted without a database in the same suite (a function planned for exactly the limit
reads as *exactly the limit of* rather than "within 0 guests of"; an unnamed vendor, an untitled function and
a title that already states its own capacity are each read once). The **fast smoke walk** covers the page
too, without a fixture, because that is the check a developer runs while working: it asserts the rendered
heading, the three width chips and the width the list was drawn at, the sidebar badge equal to the page's own
count (and, at zero, absent altogether — the one thing that can silently disagree with the list), a listed row
reading as a drift position or the empty state standing in for it, `?within=5` and `?within=junk` answering
with the right width and the default respectively, no notification row created by any of those reads, and a
couple refused with a 302. It was shown able to fail twice over: hiding the page produced five failures and
exit 1, and forcing the badge to render at zero produced exactly the badge assertion and nothing else.

**The width is the planner's own, and it is remembered (the other half of this pass).** A page whose whole
point is being looked at cannot ask the same question on every visit: how wide to watch is a judgement about
the planner's own patch — one wants the day's problems, another wants a month of lead time — and it does not
change between visits. So the width an account chose is stored per ACCOUNT
(`include/UserPrefs.php` → `tbluser_prefs`, `migrations/v2.12_user_preferences.sql`) and the page opens
there, with a Remember/Forget control under the chips. Four decisions are worth recording, because each one
is a way this feature is usually got wrong:

- **The database, not the session.** A session preference dies with the session, which is the difference
  between "remembered" and "remembered until the tab closes". The one precedent in this codebase (Helper's
  vendor-listing switcher) is session-scoped and says so; this one had to survive a logout, so it is stored.
  It is a table rather than a column on `tblusers` because that is the auth table, read on every login, and
  the next preference must not be another ALTER on it.
- **A GET never writes.** `?within=N` is what a shared link, a bookmark or a colleague's URL carries: it
  changes the view that request renders and nothing else. Only the explicit Remember/Forget POST (with the
  same CSRF token every other write in this application carries) changes what is stored. The chips now each
  carry their own `?within=`, too — with a saved default, a bare 10% link would otherwise have stopped meaning
  ten per cent.
- **The stored value is a hint, not a policy.** It is validated on the way OUT (against the widths the feature
  offers), so a row edited by hand — or left behind by a future build that dropped a width — reads as the
  default rather than widening every page load into a query nobody asked for. An off-menu POST is refused
  rather than clamped: a caller offering a width the page cannot render has a bug, and storing it would hide
  it.
- **A missing table is not an error.** The migration is optional: a site that has not run it opens at the
  default and the page says it cannot remember the width yet, instead of failing or silently pretending. An
  absent row IS the default, so there is nothing to backfill.

**Verified.** `tests/VenueHeadcountPlanTest` walks the preference over HTTP as two planners: an account that
never chose opens at the feature default and is told the width the control would store; choosing one (POST +
CSRF) stores it for that account alone and the next bare visit opens there; an explicit `?within=` shows that
view while the stored width is untouched; an off-menu width and a tokenless POST both write nothing; the
second planner's own page still opens at the default with the first planner's row left alone; a stored value
the feature does not offer reads as the default; and Forget deletes the row and puts the page back. The
harness's P8 leg does the same against the running application (save → the page opens at it → an explicit
width writes nothing → forget → back to the default), and its phase 9 audits that no preference row was left
behind. And it was shown able to fail, twice: with the page made to ignore the stored width, its "a later visit
opens at the saved width" assertion went red (`Failed asserting that 10 is identical to 20`); and with the
reader made to CLAMP an off-menu stored value to the nearest width instead of refusing it, its "a stored width
this feature does not offer reads as the default" assertion went red (`20 is identical to 10`). Both runs
exited 1 with exactly that one failure, and both files were restored byte-identical.

**A warning the log scan caught, in this very page.** The first harness run of the new page failed phase 10
with `Warning: Undefined variable $users_profile in admin/include/sidebar.php` — four times, once per request
to the page. Every staff page defines that variable before including the sidebar and this one did not: the
page rendered correctly in a browser (the sidebar fell back to its client chip) and every assertion in the
run passed, which is exactly the class of defect F5 exists to catch. Fixed by defining it the way the ledger
does, and the re-run is clean — the badge itself is read on two pages in that run, so the count is exercised,
not just rendered.

## 5. Multi-role workflow audit — every "wait for someone" promise, checked

**Method.** Three passes, because reading the copy alone is what produced the wrong answer in an earlier
pass.

1. **An inventory of the promises.** 42 matches for a set of promise phrases ("shortly", "will contact
   you", "awaiting", "will appear here once…", "will be notified") across the codebase. Each was paired
   with the state change it depends on, and that state change was searched for a writer.2. **A writer sweep over the schema**, because a promise can also be broken by a missing *capability* rather
   than missing copy: for all 12 gate-shaped columns (`is_*`, `status`, `rating`, …) the codebase
   was searched for assignment writes, `UPDATE … SET` writes and `INSERT` column lists; then for all 36
   tables (36 after v2.8 adds the plan-change ledger), for any application row-creator at all.
3. **A scoping sweep**, because F6 showed a promise can also be broken by rows that exist but are
   unreachable: every user-scoped `LIMIT 1` resolution was listed and checked.

The writer search has to include object writes (`$row->field = …`) and not just SQL: this codebase
persists through `DB_Object` subclasses, and a SQL-only grep reports a false "no writer" — it did, during
the first pass. `client_assign.php` sets the couple's status through `$account_detail->status = "confirm"`,
which no `UPDATE tblaccounts_detail` search will ever show.

| # | Who is told to wait | The action it depends on | Can anyone actually perform it? |
|---|---|---|---|
| 1 | Couple: "Awaiting confirmation — our team is reviewing your date and will reach out shortly", and "watch your email for updates" (`thank_you.php`, `client_manage_account_details.php`) | An operator assigning a planner, which writes `tblaccounts_detail.status = 'confirm'` (`client_assign.php`), notifies the couple and SMSes them | **Yes** — asserted end to end rather than assumed |
| 2 | Vendor: "wait until an administrator approves your listing" / "You will be notified once approved" | A writer of `is_approved` plus an operator screen | **No — dead end; found and fixed (F2)** |
| 3 | Couple: "They will contact you shortly" (enquiry sent) | The vendor's own enquiry ledger — `vendor_home.php` (the *staff* ledger is `vendor_enquiries.php`) | **Yes now** — but only after F6 was fixed; the ledger was previously pinned to one arbitrary listing |
| 4 | Planner: "we will M-Pesa prompt you before each expiry" (`subscription.php`) | `subscription_renewals.php` cron issuing an STK push | Yes — cron exits 0 (its flag is off in the seed) |
| 5 | Couple: "Package features are being finalised — our team will share the full list" | Operator feature CRUD (`feature_add/edit`) | Yes |
| 6 | Visitor: "Packages will be listed here shortly" (`index.php`, `pricing.php`) | Operator package CRUD (`package_add/edit/delete`) | Yes |
| 7 | Visitor: "we will get back to you soon" (`contact.php`) | Operator mailbox — the form mails the site recipient with `Reply-To` set to the visitor | Yes (see O8) |
| 8 | Couple: the "Awaiting Reply" RSVP board | The guest replying with their token | Yes |
| 9 | Anyone looking at a payment "Pending review" (`mpesa_ledger.php`) | The operator verifying or rejecting it | Yes |
| 10 | Visitor: "Photos are coming soon — real weddings … will appear here" (`gallery.php`) | Operator photo upload (`photos_add.php`) | Yes |
| 11 | Operator, on the vendor directory: "Your inquiry has been sent to the vendor. They will contact you shortly" | A writer of an enquiry from that form | **No — dead end; found, reported (F8)** |
| 12 | Planner: "No subscription plans yet — Plans will appear here once the team defines them" (`subscription.php`) | A writer of `tblsubscription_plans`, and a schema that can store the key it defines | **Yes now — found and fixed (F9); the operator's own view gets the button that proves it. F10 then found that the *sale* of a newly defined key was still broken, and fixed that too** |

**What the sweeps returned.**

- **Gate-shaped columns (12).** Every one has a writer except `rating` and `total_bookings` — the only two
  columns in the schema with *zero* writes of any kind (O7, below). Notably `access_level`, `email_verified`,
  `is_active`, `is_approved`, `is_premium`, `is_published`, `is_read`, `is_done`, `organizer_id` and
  `status` all have real writers, so the earlier "no writer for `is_approved`" finding (F2) is now the
  exception rather than the pattern.
- **Tables with no application row-creator (all 36).** Four when the sweep ran — the fourth,
  `tblsubscription_plans` (**F9**), now has one, which leaves `tblsubscription_payments` (seeded by design —
  `Revenue` documents `tblsubscriptions.created_at` as the real source) and `tblorganizer` /
  `tblsession_audits`, which are **dead**: each is referenced exactly once in the whole application, in
  `deploy_check.php`'s expected-table list. No page reads either, so no role waits on them (O10). Every
  other "no literal INSERT" table is written through a `DB_Object` subclass (`Guest`, `Booking`,
  `Gallery`, `Notification`, …), which a SQL-only sweep cannot see.
- **User-scoped `LIMIT 1` resolutions.** Three, and only one was a defect: the vendor one (F6). The other
  two read a couple's phone and email by `user_id` where the relation is genuinely 1:1, and the seed
  contains no couple with more than one booking or detail row — the vendor case was unique precisely
  because the seed *does* ship one owner with two businesses.
- **Dead interpolations.** A tokenizer sweep (`token_get_all`, single-quoted string literals containing
  `{$var}`) across all 184 PHP files: 1 found — mine (F7) — and 0 remaining after the fix.

**Verdict.** Three of the four dead ends in this class are now closed (F2, F6, F9) and asserted; the
fourth (F8) is reported with live evidence and a recommended remedy. Every other "wait for someone" step
has an actor with somewhere to act.

#### The audit this section describes is now a permanent check (sweep D)

A pass that happens once by hand is a story; the same question asked on every commit is a guard. This
section's third pass — "for every promise, find the writer of the state it depends on" — is now
`scripts/audit_promises.php`, wired into `composer code-audit` after sweeps A/B/C, so a promise with no
actor fails the build:

```
composer code-audit
  SWEEP A CLEAN   DB_Object subclasses vs the live schema
  SWEEP B CLEAN   every static call resolves to a declared method
  SWEEP C CLEAN   every SQL column reference exists (661 statements)
  SWEEP D CLEAN (23 promises verified, 8 accepted with a reason)
  SWEEP E CLEAN (3 columns judged, 3 accepted with a reason, 15 values not judgeable)
```

It answers one question three ways, and only reports what it can prove:

- **D1 — is the state reachable at all?** Each table a page reads must have a row-creator (an `INSERT`, or
a `DB_Object` subclass that can persist one), and each column it reads or compares must have a writer —
a SQL write for that table, or a property assignment by a class that declares the column *for* that table.
The property half matters: `client_assign.php` sets `$account_detail->status = "confirm"`, which no
`UPDATE tblaccounts_detail` search will ever find, and F2's `is_approved` is written the same way.
- **D2 — is the branch reachable?** When every write to a column is a literal, the values compared for
equality must be among them; otherwise the branch can never be taken.
- **D3 — is the sentence accounted for?** Every promise phrase in user-facing copy has a registry entry
in the script naming the state it depends on and the actor that supplies it. A registry entry whose copy
has been edited or deleted anywhere is itself an error, so the registry cannot rot quietly.
- **D4 — has an acceptance outlived its debt?** Every entry in the accepted-with-a-reason list is checked
against the writers the same run found. The moment the debt is paid — the column gains a writer, the table
gains a row-creator — the entry **fails the build** instead of being ignored forever. This is the rule
that keeps the list itself honest: without it, permission once granted is permission retained.

**Precision over recall, deliberately.** Object writes are credited only to a table whose class declares
that column; a comparison is attributed only when its SQL names one table or qualifies the column with a
known alias; dynamically built SQL that cannot be resolved (a table name in a variable, a `FROM` built by
concatenation) is skipped rather than guessed at. Columns the database writes itself (`AUTO_INCREMENT`,
`DEFAULT CURRENT_TIMESTAMP`, `ON UPDATE`) and column defaults are treated as writes. Whatever is
knowingly left alone lives in `ACCEPTED_STATE` / `ACCEPTED_PROMISES` — never a silent pass: **all 8
entries are printed on every run**, with the reason, so the debt is visible in CI output.

**The check is verified against its own failure modes.** A static check that cannot fail is worse than
none, so `tests/PromisesSweepTest.php` drives both halves through the same CLI the build uses (6 tests,
8 assertions, `OK`): the real tree must be clean (exit 0, `SWEEP D CLEAN`), and
`tests/fixtures/promises/` — a deliberately broken five-file mini-project, scanned via the sweep's new
`--root` flag — must fail once per rule: an unregistered promise phrase, a table read with no row-creator,
a column read but never written, an equality branch whose value is never written, and an accepted entry
whose debt has since been paid (the fixture gives `calendar` the persistence class the sweep's own reason
says it lacks). The same gate was
also exercised against the real tree by injecting faults and restoring them byte-for-byte (md5-verified):
removing the only row-creator of `tblsubscription_plans` fails the build with `promise "Plans will appear
here once the team defines them." in admin/subscription.php depends on tblsubscription_plans, which
nothing can create`; removing the `is_approved` writer fails it with `column tblvendors.is_approved is
only ever written ["0"] but compared for equality against "1" … that branch can never be taken`. D4's own
negative case was proven the same way — an accepted entry added for a column that *does* have a writer
fails the build with `accepted entry tblvendors.is_approved is obsolete` — and then covered permanently by
the fixture, so the rule is asserted rather than trusted.

**What the sweep found that the hand pass missed.** It reported `tbleventbooking` — the venue sheet's
planned headcounts — as read-but-never-written, which the hand sweep had not caught because that table
does have a class. It became O11 below; it is now fixed, and written up as F11 in section 4. D4 then
enforced its own acceptance: once the writer existed, the entry failed the build until it was removed.

#### The same question across the whole schema, one value at a time (sweep E)

Sweep D asks the column-level question and stops at the first dead value. `scripts/audit_enum_values.php`
(wired into the same `composer code-audit`) asks it value by value, for **every** status- or
enum-shaped column in the live schema — 30 columns and 65 declared ENUM values on this build — with the
value universe read from the schema rather than from a hand list, so a new ENUM member is covered the day
it is added.

It builds two sets per column: the literals code **reacts to** (a SQL filter, an `IN` list, a PHP
comparison, an allow-list, a switch case, a match arm, a label map) and the literals code **writes**
(INSERT and UPDATE values, property assignments, the column's own DEFAULT). A reacted value outside the
written set is a badge, a heading, a filter or a whole branch reserved for a state that cannot occur — and
every such value is reported, not just the first one found.

Verdict on this build: **no provable dead enum value**, and the boundary the sweep could not cross is
printed in the same output. Of 41 reacted values, 3 columns could be judged; 15 values were *not judged*
because their writer is something the sweep declines to guess at — a `$_POST` field (`tblguest.priority`,
`tblusers.gender`), a request value validated inside `Guest::record_rsvp` (`tblguest.rsvp_status`), a bound
parameter whose `prepare` and `execute` are separate statements (`tblmpesa_stk_pushes.status`), or a
property name eight tables share (`tblaccounts_detail.status`). Each is printed with the write site and the
reason, so a gap in the analysis can be read as what it is instead of being mistaken for a pass. Three
values (`tblguest.rsvp_status`) are excused by an `ACCEPTED_VALUES` entry, printed with the reason on every
run, which fails the build the moment it excuses nothing.

What it can prove, it proves with constants rather than guesses: literal assignments, ternaries of literals
(`$new_state = $row->is_published ? 0 : 1`) and terminating allow-list guards
(`if (!in_array($status, ['yes','no'], true)) { return false; }`), which is how `yes` and `no` are credited
for `tblguest.rsvp_status` even though the answer arrives from the guest's own page. It does not attempt
interprocedural values, and it does not pair `$sql = …; $stmt = $db->prepare($sql); $stmt->execute([...])`
by guesswork — a wrong pairing would credit a value the column cannot hold, and a sweep that hides the
values it exists to find is worse than one that names what it could not decide.

`tests/EnumValueSweepTest.php` drives both halves through the same CLI the build uses (5 tests,
14 assertions, `OK`): the real tree is clean, and `tests/fixtures/enums/` — a deliberately broken
six-file mini-project — reports **all four** reacted-but-unwritten values of `tblvendors.category` (one by
comparison, one by switch case, two by label map), credits the two values a guard proves, and reaches the
accepted line for `maybe`; `tests/fixtures/enums_obsolete/` then pays that debt by writing the value, and
the sweep fails with `accepted entry tblguest.rsvp_status is obsolete`.

### The couple onboarding funnel is now asserted, not assumed (P8)

Because it is the mirror image of F2, the harness walks it end to end and fails if any link is missing:
public sign-up (`index.php`) → the account, the detail row (`status = 'pending'`) and the booking are written
→ the couple's own page shows the "Awaiting confirmation" promise → the booking appears in the operator's
pending list **with the assignment link** (the assertion requires the *link*, not merely the couple's name —
being visible with no action available is exactly the dead end being hunted) → the operator assigns →
`status` becomes `'confirm'` and `organizer_id` is recorded → the couple's page flips to "Confirmed".

Three things about these checks are deliberate: they run inside the write-path phase so the residue audit
covers their cleanup; the funnel account is created under a `sim.` address so a cleanup failure fails
phase 9 loudly rather than lingering; and they caught a real mistake while being written — the first
version looked the account up by `tblaccounts.id`, but the primary key is `user_id`, so the row was never
found and the check failed until it was fixed.

## 6. Observations — examined, deliberately not changed

| # | Observation | Why it was left alone |
|---|---|---|
| O1 | A couple flagged `status = 'fraud'` gets an empty dashboard (the listing filters on `tblaccounts_detail.status`) but can still open their own booking by direct URL, because couple pages check ownership only. | The flag is a dashboard filter, not an access gate. Turning it into one is a product/security decision, not a defect fix — flagging it here so it is a choice. |
| O2 | `.env.example` ships `WPMS_MPESA_CALLBACK_PASS` and `WPMS_MPESA_STK_CALLBACK_PASS` **empty**, and with an empty shared secret the two Daraja webhooks accept any well-formed payload. `deploy/.env.production` does set both secrets, so production is closed. | Documented, deliberate ("optional shared secret"). Worth generating secrets in any new environment. |
| O3 | `users_edit.php` / `users_profile.php` test `empty($_FILES['profile_picture'])` to decide whether a picture was uploaded — a browser always sends the key, so the check never means what it reads as. The update still succeeds. | Latent, currently harmless. Noted rather than changed, to keep this pass minimal. |
| O4 | `rsvp.php` verifies no CSRF token. | By design and documented: the 32-hex URL token *is* the capability and the page is opened from a WhatsApp link with no session. An out-of-range status was confirmed to be rejected. |
| O5 | Both M-Pesa webhooks answer HTTP 200 to a GET probe. | Required by the Daraja contract ("any non-200 makes Safaricom retry-spam the endpoint"). Verified that a GET writes nothing and a malformed POST is rejected with `ResultCode: 1`. |
| O6 | Every delete endpoint refuses a bare GET and keeps its row (`guest`, `photo`, `blog`, `feature`, `package`, `staff`). | This is the hardened behaviour the simulation was built to confirm — reported as a pass, not a finding. |
| O7 | **A vendor's rating and booking count can never change.** `vendor_profile.php` documents `rating` and `total_bookings` as *admin-controlled*, and both are shown to couples in the marketplace, to the operator in the vendor directory ("(N bookings)" with a five-star row) and to the vendor on their own profile — and the marketplace orders by `is_premium DESC, rating DESC`. The schema sweep confirms these are the **only two columns in the database with no writer of any kind** (no assignment, no `UPDATE … SET`, no `INSERT` column list): they hold their seeded value forever. | Not a "wait for someone" dead end, so it is a missing capability rather than a broken promise — but the same shape as F2 and F9. The natural writer is the vendor-booking confirm path (a completed deal is what a booking count and rating are made of). Left alone because choosing *what* a rating means — who may rate, when, and whether a couple's score is public — is a product decision, not a repair. |
| O8 | `contact.php` sends through the raw `mail()` function with `From`/`Reply-To` headers it builds itself (CR/LF stripped), instead of the application's `Mail` queue. | It works and fails honestly (the visitor is told to email/call directly if `mail()` returns false), but it is the one outbound message that bypasses `tbl_mail_logs`, so it is invisible to `mail_retry.php` and to the revenue/ops reporting that reads that table. |
| O9 | ~~`tblsubscription_plans.is_active` is read but never written … no role-facing promise attached~~ — **superseded by F9.** The claim that this table carries no role-facing promise was wrong: `subscription.php` promises exactly that "the team" will define the plans, and no role can. `tblsubscription_payments` remains seeded-and-never-written, which `Revenue` documents as deliberate (`tblsubscriptions.created_at` is the source of truth). | Corrected here rather than deleted, because the earlier reasoning — "configuration table, nothing to fix" — is precisely how a dead end stays hidden: the promise was in a different file from the table. |
| O11 | ~~**A venue's planned headcounts can never be set.**~~ — **superseded by F11.** The venue sheet printed a per-function table of *family* and *individual* counts from `tbleventbooking`, which nothing in the application could write (its class declared every field but did not extend `DB_Object`), and it resolved those rows against `tblpostwedding` — the public blog — so the brief showed other couples' posts. | Fixed rather than left alone: the decision it needed was a small one (the number is entered where it is shown — the sheet — by staff for any wedding and by a couple for their own), and the alternative was a printed brief frozen at the seed's values. Kept here, struck through, so the entry's arrival (sweep D) and its resolution (F11) are both on the record. |
| O10 | `tblorganizer` and `tblsession_audits` exist in the schema, hold rows, and are referenced **once each** in the entire application — in `deploy_check.php`'s expected-table list. No page reads either, no code writes either. (`tblorganizer` also carries the pre-rename "organizer" vocabulary the rest of the app calls "planner".) | Dead tables with no promise attached, so there is nothing to fix; noted so the next person does not hunt for the missing screen — and, more usefully, so the preflight list can be treated as "these tables exist" rather than "these tables are used". |

## 7. Verification summary

| Check | Command | Result |
|---|---|---|
| Syntax, whole project | `php -l` over **207** files (everything outside `vendor/`, incl. sweeps D/E and their fixtures) | **0 failures** |
| Lint, admin | `php scripts/lint.php` | 111 files, **clean** — `php -l` plus the HTML-comment terminator check added below (F11's second finding) |
| HTML-comment terminators | `php scripts/lint.php admin` | **clean at 111 files, exit 0** — and shown able to fail: a probe file whose HTML comment ends with the PHP block-comment terminator is reported by file and line and flips the exit code to 1 |
| Role simulation | `WPMS_DB_NAME=wpms_sim php scripts/simulate_roles.php http://127.0.0.1:8137 --log=<server log>` (on a port nothing else holds — see Limitations; 8123 was held by another thread's server during this pass) | **346 passed, 0 failed, 2 skipped** (exit 0) — includes the plan-change ledger assertion (P7), the venue headcount write path, the headcount brief to the planner and the venue contact, the scoped vendor brief, the capacity warning to the outgrown package's vendor and its planner, **the way out reaching the planner's bell inside the 255-character column with the vendor's copy carrying no suggestion**, the sheet's own `Over capacity:` line, next package up and re-price link (P8), **and the recording door: staff confirm a probe deal on a package smaller than the wedding's planned headcounts (the transport account's 33-seat package against a 120-guest reception), and the run asserts the deal is recorded, the page states the plan it cannot serve, it names the way out, and the assigned planner's bell carries the warning**, **and the drift view: the wedding running close to a booked limit listed with its function, count, package and limit and linked to the venue sheet, the navigation badge counting exactly what the page lists, a wider-margin row narrowed out of a 5% window, no bell rung by any of those reads, and the row gone with the leg's own probe deal**, **and the width the account watches at (v2.12): a planner saves the width the view opens at over the page's own POST, the next bare visit opens there and says so, an explicit `?within=` shows that view while the stored width is untouched, and Forget puts the page back to the default for that account** — plus the phase-9 residue checks, which now also look for that probe's deal, its package and any preference row left behind. (The 2 skips are the couple checklist and deposit legs, which skip on a scratch DB whose rendered form carries no CSRF token / no ceremony balance.) |
| Venue headcount plan + brief (F11) + capacity warning & way out (F12), both directions | `WPMS_DB_NAME=wpms_sim phpunit tests/VenueHeadcountPlanTest.php` | **OK, 26 tests / 412 assertions** (exit 0) — plus the drift view (F13): **the row for a margin inside 10% and outside 5% with the function, count, package and limit stated and the venue sheet linked, the chips narrowing and widening it, `?within=junk` falling back to the default, the badge and the page agreeing in the same run, the same fixture pushed past the limit listed as `1 over` with the red line and no longer claimed as drift, no notification created by any read, a couple and a vendor both refused (302), and the copy as unit tests (exactly the limit of / within 1 guest / within 12 guests, an unnamed vendor, an untitled function, a title that states its own capacity)** — plus **the recording doors: the couple's enquiry (the package they asked for, every function it cannot hold, the way out on the page and the short form on the planner's enquiry bell, and copy that never claims a booking), the vendor's own accept (the limit on the page they pressed, no suggestion, the warning as the floor of the planner's bell), a scope widened onto a bigger function (warned once; silent when the same scope is saved again), and the copy rules as unit tests (both sides of the deal, the page line's order, a bell note dropped whole rather than cut)** — — sheet contents, staff and couple writes, one row per function, cross-tenant and malformed input refused, the whole-form save leaving untouched functions untouched, and the brief: both recipients, moved functions only, a silent save, a planner's own edit, a venue with no portal account, **a vendor told about its own function and no other, one alert per account, a booked vendor nobody can reach, and the scope written from the staff ledger with another wedding's function refused** — plus the capacity warning: **both readers of the same fact as unit tests, the clause folded into one bell with the change, the way out (next package up, the same price, a fallback price, no bigger package on file, a smaller one not mistaken for bigger), the same warning on a staff save that briefs nobody about the numbers, the planner left out of their own save, and the three ways a deal stays out of it (no package, pending, scope)** — and the planner's bell asserted to carry the suggestion on **both** saves while the vendor's never does, with the breaching function stated once — plus **the width remembered per planner (v2.12): the account that never chose opening at the feature default with the control stating the width it would store, choosing one by POST + CSRF storing it for that account alone and the next bare visit opening there, an explicit `?within=` rendering that view while the stored width is untouched, an off-menu width and a tokenless POST both writing nothing, a second planner's page unaffected with the first planner's row left alone, a stored value the feature does not offer reading as the default rather than widening the page, and Forget deleting the row and putting the page back** |
| The re-price step out of a breach (F12) + the staff recording door | `WPMS_DB_NAME=wpms_sim phpunit tests/VendorEnquiriesLedgerTest.php` | **OK, 7 tests / 110 assertions** (exit 0) — plus **the staff confirm of a pending deal on a package too small for the plan: the deal is recorded, the page states the plan the package cannot serve with the way out at the real price difference, the planner's bell carries the warning AND the suggestion inside 255 characters, the vendor account hears the limit and no suggestion, and a second deal on a package that covers the plan produces exactly one bell (the couple's)** — and the one step that acts on the warning: **the deal moves onto the chosen package, a blank amount takes the package's price, the 10% commission is re-derived rather than carried over, the notes trail is appended with who moved it and both amounts, `status` stays `confirmed`, the couple and the vendor are the only two bells (and the vendor's copy states no deal value), the page says whether the deal now covers the plan, and five refusals leave the deal untouched (tokenless, another vendor's package, a retired package, no price and nothing typed, a deal that is not confirmed) with `0` = no package allowed as a correction when an amount is given** |
| Headcount migrations on a fresh build | `php scripts/demo_reset.php --yes --db=<scratch>` (v2.9 + v2.10 in the build list) | **exit 0** — the fresh DB has the `booking_function` unique key on `tbleventbooking`, 2 plan rows where the seed inserts 3 (the orphan belonging to another wedding was removed), and `tblvendor_bookings.features_id` with every deal unscoped ("the whole wedding", as before); the venue tests pass against it |
| Time-formatting equivalence | old vs new `time_elapsed_string()` over 56 cases | **0 mismatches**, 0 diagnostics from the new code |
| Dead interpolations | tokenizer sweep, `{$var}` inside single-quoted strings, every project file | **1 found (F7), 0 remaining** |
| Plan definitions (F9 + F10) | `phpunit tests/SubscriptionPlanAdminTest.php` | **OK, 5 tests / 65 assertions** (exit 0) — includes the ledger (who/when) and the buy-an-operator-defined-plan regression |
| Plan-level revenue (F10) | `phpunit tests/RevenueDashboardTest.php` | **OK, 10 tests / 195 assertions** (exit 0) — includes the reconciliation of the plan table against the subscription lifetime total |
| Plan pricing migration | `php scripts/demo_reset.php --yes --db=wpms_sim` (v2.8 in the build list) | **exit 0** — the freshly built scratch DB has `tblsubscriptions.plan` as `varchar(20)` and 36 tables including `tblsubscription_plan_changes` |
| PHPUnit regression | `WPMS_DB_NAME=wpms_sim php vendor/bin/phpunit` | **367 tests / 2635 assertions / 0 failures** (exit 0) — re-measured 2026-10-16 after `demo_reset.php --yes --db=wpms_sim`, in the default order and under `--order-by=random --random-order-seed=31337`. The four names this row used to carry as the documented baseline are gone, and gone by repair rather than by explanation. **`HumanDatesTest::testOverviewShowsCountdownAndStatusBanner`** drove its pending/future half off booking **215**, which does not exist in `wpms_sim` at all (the seed builds 200–204) and whose `wedding_date` on `dbwedding` is `2026-09-25` — already past, so the countdown branch was unreachable in either database; the class now drives both halves off the pair it owns (booking 200 / user 200: 250,000 package, 245,000 paid, 2 ceremonies, identical in both databases) with the date read live, and reaches Past/Confirmed and Pending/Future by transient date and status swaps restored in `finally`. Proven in both directions on `dbwedding`, where 215 does exist: HEAD's version of that one method fails there (`Failed asserting that '…</html>' matches PCRE pattern "/\d+ days? to go/"`, at its own line 211) while the current version passes and leaves the database exactly as it found it — wedding date, account status, zero probe rows. The same class carried a second, quieter 215 artifact: its closed-balance branch already *ran* on booking 200, but the comment above it still described a probe on 215 — whose package price is `NULL` and which has no ceremony rows, so there is no progress cell there to assert against at all. The comment now says what the code does, and the claim it had only narrated is asserted on **200's own row fragment**: the Fully-paid label present, the raw percent form absent. The other three names (`RsvpReminderFlowTest::testUninvitedGuestsNeverQualify`, `SubscriptionRenewalFlowTest` ×2) were ordering and residue effects of the shared scratch database, not seed claims; those suites now isolate their fixtures per method, as do all eleven classes this pass touched. Each is green run alone, under random method order, and inside the full suite under seven distinct random orders. Zero `Warning:`/`Notice:`/`Deprecated:` lines (`failOnWarning` is on, so any of those would have been reported as a failure) |
| F12's copy + F13's page, flushed through the running app | `WPMS_DB_NAME=wpms_sim php scripts/smoke.php http://127.0.0.1:<port>` | **52 passed, 0 failed** (exit 0) — the public, staff and couple flows unchanged, including the deposit → planner-bell leg, plus the fast walk of the capacity headroom page (rendered heading, width chips and default width; badge equal to the page's own count and absent at zero; a listed row reading as a drift position or the empty state standing in; `?within=5` and `?within=junk`; no bell rung by any of it; a couple refused with 302). **Both halves were shown able to fail**: with `admin/headroom.php` moved aside the walk reported 5 failures and exit 1 (HTTP 404 in every leg, including the denial one, which needs a real 302), and with the sidebar badge forced on at zero it reported exactly one failure — `page lists 0, badge 0` — then exit 1. Both files were restored byte-identical; the bell-column measurement itself (240/237 characters on the seed) was taken with a throwaway probe against the scratch DB, which is recorded in F12 rather than kept as a script |
| The remembered width, in the running app | Staff login at `http://127.0.0.1:8137/admin/headroom.php` in the browser panel, driven as a person would (chips, then login out and back in) | **the round trip observed end to end** — a bare visit opened at the default 10% with *Remember 10% as my default* offered; `Within 5%` rendered that view and left `tbluser_prefs` **empty** (a GET writes nothing); *Remember 5% as my default* wrote exactly one row (`user_id=5`, `pref_key=headroom_width`, `pref_value=5`), and the redirect landed on a page opening at 5% with *Opening at 5% — your saved default* and a Forget control; a **logout followed by a fresh login** then opened a bare visit at **5%**, which is the claim itself (the value is not in the session) — the 5% chip came back active and both other chips still carried their own `?within=`; *Forget* deleted the row and the page returned to 10% with the Remember control back. The scratch table was left empty. **The optional-migration path was checked the same way**, because F13 claims it: with `tbluser_prefs` renamed aside for the length of one page load, a bare visit still rendered at the default 10% and said *This page cannot remember a width yet — run migrations/v2.12_user_preferences.sql*, no Remember control was offered, and a crafted POST of the same form reported *That width could not be saved. This database has not run migrations/v2.12_user_preferences.sql yet* instead of claiming a save; the table was then renamed back and re-checked (composite `PRIMARY KEY (user_id, pref_key)`, InnoDB, `utf8mb4_general_ci`, 0 rows — identical to the migration), after which the class suite (26/412) and the smoke walk (52/0) were re-run green. The panel's screenshot capture produced no frames in this environment, so the evidence is the page's own DOM (width stat, control labels, stored row) rather than an image |
| Static audits | `audit_db_object`, `audit_static_calls`, `audit_sql_columns` | **SWEEP A/B/C CLEAN** (751 SQL statements checked) |
| Promise audit (sweep D) | `composer code-audit` → `php scripts/audit_promises.php` | **SWEEP D CLEAN** — 23 promises verified, 8 accepted with a printed reason (exit 0), F11's three entries now verified writers |
| Sweep D's own failure modes | `phpunit tests/PromisesSweepTest.php` | **OK, 6 tests / 8 assertions** — clean on the repo; fails once per rule on `tests/fixtures/promises` |
| Enum-value audit (sweep E) | `composer code-audit` → `php scripts/audit_enum_values.php` | **SWEEP E CLEAN** — 30 status/enum columns, 65 declared ENUM values, 41 reacted values, 3 columns judged, 15 values not judgeable and printed with the write site and the reason (exit 0) |
| Sweep E's own failure modes | `phpunit tests/EnumValueSweepTest.php` | **OK, 5 tests / 14 assertions** — clean on the repo; all four dead values of a fixture column, the guard-credited values and an acceptance that outlived its debt are each asserted on `tests/fixtures/enums` / `enums_obsolete` |
| Unbound-SQL audit (sweep G) | `composer code-audit` → `php scripts/audit_unbound_sql.php` | **SWEEP G CLEAN** — 157 files parsed, 436 SQL arguments inspected, one allow-listed raw command (the connection's own `SET time_zone`): no call to the legacy wrapper door, no raw driver on the request surface, no request data inside SQL text (exit 0) |
| Sweep G's own failure modes | `phpunit tests/UnboundSqlSweepTest.php` | **OK, 7 tests / 36 assertions** — clean on the repo; `tests/fixtures/unbound` reports G1×5, G2×3, G3×2 (10 findings, exit 1) while the bound shapes, the raw handle that prepares and the allow-listed statement stay unreported, and a counting `Database` subclass shows `query()` prepares and executes even with nothing to bind |
| CLI/cron jobs | `mail_retry`, `notify_prune`, `rsvp_reminders`, `deadline_sms`, `subscription_renewals` | all exit 0, all refuse correctly when their feature flag is off |

The suite's exit code is now **0**, and the baseline it carried is empty rather than known. The four failures
this row recorded while the pass that produced it was written — `HumanDatesTest::testOverviewShowsCountdownAndStatusBanner`,
`RsvpReminderFlowTest::testUninvitedGuestsNeverQualify`, `SubscriptionRenewalFlowTest` ×2 — were not pre-existing
regressions to live with: one was a fixture pinned to a booking and a calendar the seed does not own, and the other
three were ordering and residue effects of the shared scratch database. Each was repaired in the suite that owned it
(the row above records the evidence in both directions, including the reproduction of the old failure), so there is
no baseline left to explain. Running against a database that has *not* been date-shifted still shows the paywall
suites' extra failures of the same kind — their positive control is "amina has a live plan", and a stale `dbwedding`
has her plan expired by the calendar — so a fresh `demo_reset.php` is still how a run is given a known starting
point. The four tests added for F10 (two in `SubscriptionPlanAdminTest`, two in `RevenueDashboardTest`) pass both in
isolation and in the full suite; the one that initially passed only in isolation is written up in F10, and the fix
was in two other suites' schema-repair helpers rather than in the assertion. No failures, no errors, and zero
`Warning:`/`Notice:`/`Deprecated:` lines in the whole run.

The same residue effect was observed once more while landing sweep D: a run after a series of ad-hoc
scratch-DB probes failed `PlannerTrialSignupTest::testTrialIsNeverARenewalCandidateAndGetsOneTextOnly`
("a trial 2 days out must NOT be texted" — an extra candidate row) in place of the RSVP failure, and a
fresh `demo_reset.php --db=wpms_sim` returned the set to exactly the four names above — a set that no longer
exists, since those four were repaired afterwards and the baseline is empty (see the PHPUnit row above). The
counts in this table are from that post-reset run, and sweep D's six tests passed in both.

Three results are worth recording because they were **not** clean on the first attempt. The vendor-transition
tests (`VendorSelfServiceTest`) failed in the first post-change run — a real regression, F7, fixed. A
later run showed five failures including `PlannerTrialSignupTest` and `VendorEnquiriesLedgerTest`, which a
fresh `demo_reset.php` removed: the integration suites share the scratch database, so residue left by
ad-hoc probes between runs (a confirmed probe booking, in that instance) perturbs them. And the plan
editor's first harness run failed twice — phase 10 reported `Warning: Undefined variable $users_profile in
include/sidebar.php` from the new page, and one assertion failed because the refusal of a planner was
redirected to a page that does not render session messages, making it silent. Both were fixed and are now
asserted. The baseline above is the post-fix, freshly-reset result, and the same reset reproduced it
identically.

## 8. Limitations

- The simulation drives the `php -S` development server, so `.htaccess` rules (the 403/404
  `ErrorDocument` mapping, directory blocks) were **not** exercised — only PHP-level routing and guards.
- **The dev server must be on a port nothing else is serving.** On Windows two `php -S` processes can both
  hold `127.0.0.1:<port>` and requests are then answered by either, so a harness run against a port that a
  leftover server was already holding reported **41 failures** — every write "not persisting" — because the
  writes were landing in that other server's database. A fresh port (and a one-line check that the server's
  own log records the requests) reproduced the documented green run exactly. Recorded because the failure
  signature looks like broken application code rather than a colon and four digits.
- M-Pesa is simulated at the ledger level (no Daraja round trip, no STK push to a handset); real
  gateway behaviour and the SMS transport are out of scope — SMS lands in `tblsms_logs` as a local row
  because no gateway credentials are configured.
- Email is queued through `tbl_mail_logs` (no SMTP configured), so message *content* was verified, not
  delivery.
- Uploads were exercised with one PNG; the image validator's reject paths (wrong type, oversize) are
  covered by `ImageUploadValidationTest`, not by this harness.
- The seeded plan states were used as-is (active / locked / expired); billing-period boundary maths is
  covered by the unit suite.
- PHPUnit's integration suites and this harness share one scratch database, so they must not be run
  concurrently and a reset is still the way to hand a suite run a known starting point (the suites are order-independent since the repairs recorded in section 7, but they still write to the same scratch database). This is a property of the existing suites, not of the changes made here.
- F8 remains a **known live defect** in this build: the staff marketplace's "Send Inquiry" still reports
  a send that does not happen. It is described in section 4 with live evidence and two candidate
  remedies, neither of which is a repair — one of them decides who owns a booking-less enquiry.
- The venue headcount plan is a count of *family* and *individual* guests per function. It deliberately does
  not itemise which guests, and it does not try to add up to the RSVP tally (the same guest can attend
  several functions); the two numbers stay separate on the sheet and the page says so.
- The capacity check (F12) judges **one function at a time**, never the day's total, for that same reason, and
  it warns only on the save that creates or widens the breach: a plan already over a limit is printed on the
  sheet and reported by the save that put it there, not re-announced on every later save, and a plan that
  falls back under a limit is not announced at all. A capacity is read as sold — an *inactive* package's
  capacity still applies while a deal is confirmed on it, and a vendor's own dashboard does not (yet) print
  the limit beside their booking, so the bell, the email and the sheet are where the position is stated.
- The drift view (F13) is **silent by design**: it is a page a planner opens, reached from a badge, and it
  sends no bell and no email. A planner who never looks is not told — the trade is deliberate (a forecast per
  wobble of a guest count is noise, and the two announced events are facts), but it means the feature is only
  as good as the habit of reading the badge. The width is a share of a package's own capacity rather than a
  head count, so a 20-guest margin on a 400-seat hall (5%) is on the list while the same 20 guests on the
  250-pax package the seed sells (8%, inside the default tenth) is only inside the 10% list — the two are
  comparable, not identical. It reads a package's capacity at the moment it is asked, so it inherits F12's
  one silent direction: a package's capacity being edited or retired under confirmed deals changes what those
  deals cover without anyone being told.
- The recording doors (F12) warn where the choice is still free, and that is all they do: nothing is
  blocked, and nothing is re-priced on the strength of a guest count. The couple's enquiry is **advice to the
  two people who are still choosing** — the row is `pending`, so it is not counted as an assignment anywhere,
  and if they never confirm it, no bell was sent to the vendor at all. The warning fires only on the
  transition INTO `confirmed` (a re-confirm from a crafted POST is not a new sale) and, on the scope control,
  only when the scope actually changed — so a plan that has been over a package since before this release is
  not re-announced by saving the deal again.
- The same check reads a package's capacity **at the moment it is asked**, so the one direction still silent is
  a package being *edited* after deals sit on it: a vendor lowering `tblvendor_packages.capacity`, or retiring
  a package that confirmed deals still hold, changes what those deals cover without anyone being told
  (`admin/vendor_packages.php` counts the deals on a package before letting it go, but does not judge them
  against the plan). The venue sheet and the next headcount save still print the position; the recording-time
  warning has already happened by then, which is why this is recorded rather than fixed here.
- On the planner's **enquiry** bell the capacity note is the short form — the biggest crowd the package cannot
  serve and nothing else — and it is dropped whole when the message that already carries the couple, the
  vendor and the package has no room left for it. `tblnotifications.message` is `varchar(255)`, so a note that
  does not fit is never cut mid-number; the couple's own page always states every function and the way out.
- The way out of a breach (F12) is a **suggestion from list prices, not a quote**: it names the same vendor's
  next package up and the difference between the two packages' prices, while the deal's own amount may have
  been negotiated below either of them — which is exactly why the sheet shows it **to staff only** and why
  applying it is a step a planner takes, not one the application takes for them. The alternative — re-pricing
  the deal automatically on the strength of a guest count — would move a couple's money without the phone call
  a re-price is. And the planner's bell has a hard 255-character column, so the suggestion is the first thing
  dropped when a wedding's package and vendor names are long; the sheet, which has no such limit, always
  states it.
- The headcount brief reaches the planner, the venue contact and every booked vendor as an in-app alert plus
  a queued email (`tblnotifications` / `tbl_mail_logs`) — the same two channels every other alert in this
  application uses. It is not sent by SMS (a list is not urgent to the minute, and a courtesy text is charged
  to the planner's credits), so a recipient with no portal account and no email cannot be reached at all: the
  planner is told to pass the numbers on, and the sheet says so beside the vendor. Delivery itself is out of
  scope here for the same reason as every other email in this build (no SMTP configured).
- A vendor's scope is one function or the whole wedding, because that is what a deal row can say. A vendor
  booked for two of five functions is described by two deals (it is two purchases, with two amounts) or by
  the whole-wedding scope, which sends them every move; the brief never guesses a middle ground from the
  vendor's category. And a pending enquiry is still not an assignment: booking a caterer to hear the numbers
  requires the deal to be confirmed, the same rule the venue contact already followed.
- Plan rows whose `features_id` was never one of their own booking's functions cannot be repaired by
  v2.9 — there is no way to know which function they meant — so the migration deletes them. On the shipped
  seed that is one row (booking 201's copy of booking 200's Church Wedding counts); a database with more
  loses them too, which is the point: they were displayed under a wrong heading and editable nowhere.
- The venue sheet is not covered by `.htaccess` either, for the same reason as everything else here (the
  harness drives `php -S`).

## 9. Re-running

```bash
php scripts/demo_reset.php --yes --db=wpms_sim
WPMS_DB_NAME=wpms_sim php -S 127.0.0.1:8137 -d display_errors=1 -d error_reporting=E_ALL -t . 2> /tmp/wpms_sim.log
WPMS_DB_NAME=wpms_sim php scripts/simulate_roles.php http://127.0.0.1:8137 --log=/tmp/wpms_sim.log
```

**Pass `--log`.** Without it the run loses half its signal — the built-in server writes PHP diagnostics to that
log and they never appear in the response bodies the harness reads, which is exactly how F5 stayed invisible
through a run whose every body scan was clean. Phase 10 is a `SKIP`, not a `PASS`, when no log is given, so a
run cannot quietly look green by omission.

The harness refuses to run against `dbwedding` unless `WPMS_SIM_ALLOW_LIVE` is set, cleans its own
residue, and restores every row it touches. Nothing in this work was committed.
