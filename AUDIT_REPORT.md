# Everafter Planner — Full Audit Report

**Auditor:** Buffy (Freebuff), senior PHP/MySQL security & reliability pass
**Date:** 2026-10-25
**Scope:** Full system — `SYSTEM_OVERVIEW.md`, `WPMS_KENYA_MANUAL.md`, 302 tracked PHP files (27 root, 94 under `admin/`, 78 test classes, 8 cron scripts)
**Baseline:** commit `74d12ee`, 751 tests / 6,171 assertions green, sweeps A–H clean
**After this pass:** 755 tests / 6,205 assertions green, lint 149 files clean, sweeps A–H clean

---

## Executive Summary

- **Total findings:** 16 (10 fixed in code, 6 informational/verified-clean)
- **Critical:** 2 &nbsp;|&nbsp; **High:** 3 &nbsp;|&nbsp; **Medium:** 2 &nbsp;|&nbsp; **Low:** 3 &nbsp;|&nbsp; **Info:** 6
- **Fixes applied:** 10, each additive and reversible
- **Regressions introduced:** 0 (full suite, lint and static sweeps re-run after every fix)
- **The two that mattered most:** the platform could not run its own payment callbacks or cron jobs on the Linux host it is documented for (`Database.php` vs `database.php`), and **SMS has never actually sent a message** — the Africa's Talking credentials were two literals in the method body, one of them the empty string.

---

## Findings

### F-001: Every M-Pesa callback, cron job and CLI script was a fatal on Linux — include paths differed only by case

- **Severity:** Critical
- **Location:** `mpesa_c2b.php:46`, `mpesa_stk_callback.php:39`, 16 files under `scripts/`, 49 under `tests/` (67 files, one repeated string)
- **Description:** The tracked file is `admin/include/database.php` (lowercase). Sixty-seven files required it as `admin/include/Database.php` (capital D). Windows resolves either spelling to the same file, so every local run — including the full test suite — was green. Linux does not: `require_once` on a path whose case does not match is a **fatal error**, not a catchable exception.
- **Impact:** On the documented cPanel deployment, the first thing to break is `mpesa_c2b.php` and `mpesa_stk_callback.php`. Safaricom's callbacks would have received an HTML fatal instead of `{"ResultCode":0}`, so **no typed M-Pesa code could ever be verified**; `deploy_check.php` would report a down database; `mail_retry.php`, `rsvp_reminders.php`, `deadline_sms.php`, `subscription_renewals.php`, `thread_escalation.php`, `mcp_approval_sweep.php`, `business_pulse_email.php`, `notify_prune.php` and `job_watch.php` would all stop running silently — and `job_watch.php` is the mechanism that is supposed to notice. This is the single highest-consequence defect found.
- **Fix applied:** every occurrence normalised to the on-disk spelling `admin/include/database.php` (67 files, mechanical `sed`, no behaviour change on any platform). Two files in the test harness had the same defect from the other direction — `tests/JobWatchTest.php` built the path from the *class* name, so it asked for `Database.php` and `DB_Object.php`; that block now spells out class → filename explicitly.
- **Regression guard:** new `tests/IncludeCaseTest.php` — walks every project PHP file, resolves each literal `require`/`include`, and compares it against the real directory listing (`scandir` returns the true spelling on Windows too). Proven to fail: re-introducing the old string produces `… includes "/admin/include/Database.php", but on disk it is "admin/include/database.php" — a fatal on Linux/cPanel`.
- **Regression risk:** None. Same files, same order, same classes.

### F-002: SMS has never been sent — the Africa's Talking integration was a stub that reported success

- **Severity:** Critical
- **Location:** `admin/include/Monetization.php:2387` (`africastalking_send`)
- **Description:** The method opened with `$username = 'wpms'; $api_key = '';` — literals, not configuration. `if (empty($api_key)) return ['success' => true, …]` therefore took the "dev mode" branch on **every** call, in every environment, forever. `SMS::send()` treated that as delivered: it stamped `tblsms_logs.status = 'sent'` and deducted the planner's credit. The URL was also `https://api.africastalking.com/version1/message` — Africa's Talking's endpoint is `/version1/messaging`, so even a configured key would have 404'd.
- **Impact:** RSVP reminders, payment chasers, booking confirmations and lockout alerts — the product's actual channel in Kenya, where WhatsApp and SMS are how invitations travel — silently went nowhere while the UI, the log and the credit balance all said they were sent. For a paid feature this is both a broken product and a billing complaint waiting to happen.
- **Fix applied:** credentials now come from `WPMS_AT_USERNAME` / `WPMS_AT_API_KEY`, exactly like the M-Pesa settings; the endpoint is corrected to `/version1/messaging`; the call has connect/read timeouts and a cURL failure branch; and — the part that matters — a 2xx is no longer read as delivery. The response body's `SMSMessageData.Recipients[0].status` is checked, so `InvalidPhoneNumber` / `InvalidSenderId` come back as failures. A missing key still behaves as the documented dev mode (logged, not transmitted) unless `WPMS_AT_REQUIRE=1` is set, which makes it a hard failure.
- **Docs:** `.env.example` and a new **SMS (Africa's Talking)** table in `README.md`.
- **Regression risk:** Low. An install with no key behaves exactly as before; an install with a key starts actually sending — which is the point. Existing tests that snapshot and restore credits are unaffected.

### F-003: `tblsession_audits` was documented, created, and never written to

- **Severity:** High
- **Location:** `admin/include/Session.php` (whole class); claimed in `SYSTEM_OVERVIEW.md:431`
- **Description:** `SYSTEM_OVERVIEW.md` §7 lists "audit rows in `tblsession_audits`" as part of session hardening; the table has existed since `migrations/v2.1_phase1_hardening.sql` with an enum of exactly the events it was meant to record (`login`, `logout`, `regenerate`, `fingerprint_mismatch`, `timeout`, `privilege_change`). Nothing in the codebase ever inserted a row. `deploy_check.php` had even stopped requiring the table, with a comment calling it dead.
- **Impact:** The one question a security incident starts with — "was this account accessed, from where, on what device?" — could only be answered from the rotating PHP error log. A fingerprint mismatch (the hijack signal the class already detects) forced a logout and left no queryable trace.
- **Fix applied:** a private `Session::audit()` (fail-silent: a missing table or a locked row logs and returns, it can never turn a sign-in into a 500) called at login, logout, the 15-minute ID rotation, a privilege change, an inactivity timeout and a fingerprint mismatch. `logout()` reads the actor before the session is emptied, so the row names the account that left.
- **Regression risk:** Low. One small insert per security event; no read path changed. Verified over real HTTP by `tests/UnifiedLoginTest::testSuccessfulLoginLeavesAnAuditRow`, which asserts the row's account id, IP and 64-hex fingerprint and then restores the table.

### F-004: A cron job left on `--dry-run` looked healthy forever — three jobs wrote a heartbeat anyway

- **Severity:** High
- **Location:** `scripts/business_pulse_email.php:217`, `scripts/mcp_approval_sweep.php:116`, `scripts/thread_escalation.php:180`
- **Description:** `JobWatch` is built on one rule, stated in its own docblock and in `SYSTEM_OVERVIEW.md`: "a dry run is not a run". `last_finished_at` is the only field the watchdog ages, and `judge()` treats a fresh `NOOP` row as healthy. Three of the eight jobs nonetheless recorded a `NOOP` heartbeat on `--dry-run`. The other five (and their tests) did not.
- **Impact:** Exactly the failure the watchdog exists to catch. An operator who leaves `--dry-run` in the crontab after testing — a normal thing to do — gets a green Job Health page while no brief is emailed, no expired approval is closed and no silent vendor conversation is escalated, indefinitely.
- **Fix applied:** all three now write no heartbeat at all on a dry run (matching `subscription_renewals`, `rsvp_reminders`, `deadline_sms`). The "not installed" `NOOP` branch above them is untouched: that really is a run, and its summary says what is missing.
- **Regression guard:** `tests/JobWatchTest::testEveryOtherJobLeavesNoHeartbeatOnADryRun` runs all three with `--dry-run` and asserts no row.
- **Regression risk:** None — a dry run has no other side effect.

### F-005: SMS credits could be double-spent (check-then-deduct race)

- **Severity:** High
- **Location:** `admin/include/Monetization.php:2070` (`SMS::send`)
- **Description:** `send()` read the balance, sent the message, then deducted. Two overlapping runs — `rsvp_reminders.php` and `deadline_sms.php` both bill the same assigned planner, and either can overlap with a manual send from the SMS centre — both pass the read with one credit left, both transmit, and the second deduction is clamped by `GREATEST(0, credits - :c)`, so it silently succeeds while taking nothing.
- **Impact:** Paid messages delivered without being paid for; the balance's "total_used" no longer reconciles with the log; a planner near zero credits can keep sending.
- **Fix applied:** deduction moved ahead of the send and made atomic — `UPDATE tblsms_credits SET credits = credits - :c … WHERE user_id = :uid AND credits >= :c` and a `rowCount()` check (`spend_credits()`), so the check and the charge are one statement. A failed send (or an exception while recording it) refunds through `refund_credits()`, so nothing is lost when the gateway refuses.
- **Regression risk:** Low. Same externally observable behaviour on the happy path; the balance can no longer go negative.

### F-006: The public contact form was the only anonymous door with no abuse guard

- **Severity:** Medium
- **Location:** `contact.php:21`; `admin/include/PublicGuard.php`
- **Description:** `PublicGuard` exists precisely because "a visitor with no session can make the system do work", and every anonymous door is behind it (RSVP, wedding-site views, both M-Pesa callbacks, MCP). `contact.php` was missed by that survey because it writes no database row — but it is the loudest of them: each POST runs `mail()` to the operator's inbox and consumes SMTP quota, with a free-text body.
- **Impact:** An unauthenticated loop over one URL turns the product into a mail amplifier pointed at the operator's own mailbox and sending reputation.
- **Fix applied:** a new `PublicGuard::CONTACT` door (5 messages per hour per connection — generous for a person, meaningless for a script), applied after validation so a typo does not spend one of the visitor's five. Windows into the existing `tblpublic_throttle`; no migration needed (`kind` is `VARCHAR(32)`).
- **Regression guard:** `tests/PublicGuardTest::testTheContactFormIsCountedPerCallerAndRefusedPastItsCeiling`, including a check that a full contact door does not silence RSVP.
- **Regression risk:** None for honest traffic; five messages an hour per IP is well above real use.

### F-007: The C2B callback could still return HTTP 500, breaking its one contract with Daraja

- **Severity:** Medium
- **Location:** `mpesa_c2b.php:135`
- **Description:** The entry point is carefully engineered to answer 200 in every expected case (DB down, bad key, bad JSON, wrong short code, flood guard, refused payload). `MpesaC2B::ingest()` was called outside any `try`, relying on its own internal `PDOException` catch. Any other `Throwable` — a `TypeError` in a helper, a driver error outside that catch — escaped as a 500.
- **Impact:** Safaricom treats a non-200 as "retry", so a broken ingest path becomes a retry storm against a broken endpoint, and a real payment notification can be lost in the noise.
- **Fix applied:** `ingest()` wrapped; on a throw the failure is logged with its `TransID` and the notification is accepted (the raw payload is already in `tblmpesa_callback_log` by then, so it stays reconcilable by hand). The contract now holds for unexpected failures too.
- **Regression risk:** None. Refusals still return `ResultCode 1` exactly as before.

### F-008: Four hot-path columns had no index

- **Severity:** Low
- **Location:** `tblguest.booking_id` (29 call sites), `tblweddingbook.user_id`, `tblweddingbook.organizer_id` (every staff list via `planner_scope_sql()`), `events.booking_id` (17 call sites)
- **Description:** The guest list is the busiest table in a wedding planner, and none of these columns was indexed. `tblguest` carried only its PK and the RSVP-token unique key; `tblweddingbook` had only `PRIMARY KEY (booking_id)`.
- **Impact:** Every guest sheet, RSVP dashboard, headcount brief, reminder run, couple "my booking" lookup and scoped planner list read the whole table and threw most of it away. Invisible on the demo's ~3,400 guests, felt on a planner with thirty weddings.
- **Fix applied:** `migrations/v3.2_hot_path_indexes.sql` (idempotent, `information_schema.STATISTICS` guard, index-only — no table, column or row is touched), mirrored into the deploy kit `deploy/schema.sql` and documented in `DEPLOY_CPANEL.md`.
- **Verified:** applied to both `dbwedding` and `wpms_test`; `EXPLAIN SELECT * FROM tblguest WHERE booking_id = 200` now reports `key=idx_guest_booking, type=ref`, and `EXPLAIN … FROM events WHERE booking_id = 200` reports `key=idx_events_booking`.
- **Regression risk:** None. Write cost of four narrow indexes.

### F-009: The login throttle's comment described an order the code does not use

- **Severity:** Low
- **Location:** `login.php:28-32`
- **Description:** The comment claimed the throttle runs "before the CSRF token too, so the lock can't be probed with tokenless requests". The code verifies CSRF first.
- **Impact:** Documentation only — and the code's order is the safer one (a tokenless request never reaches a credential lookup and is never counted as a failure, so the lock cannot be probed *or* filled by tokenless traffic). A reader trusting the comment would have "fixed" working code.
- **Fix applied:** comment corrected to describe the real order and why it is right.
- **Regression risk:** None (no code change).

### F-010: Test harness required two model files by class name

- **Severity:** Low
- **Location:** `tests/JobWatchTest.php` (`setUpBeforeClass`)
- **Description:** The same root cause as F-001, one layer up: a `foreach (['Database', 'DB_Object', …])` built `Database.php` / `DB_Object.php`. On Windows it worked; on the Linux CI runner it would have been a fatal inside `setUpBeforeClass`.
- **Fix applied:** explicit class → filename map.
- **Impact:** This is how F-001 survived — the guard that should have caught it could not start on Linux.

---

## Informational — audited, verified clean, or deliberately not changed

### I-001: SQL injection — clean
Sweep G (`scripts/audit_unbound_sql.php`) passes with zero findings: no legacy `query()`/`setQuery()`/`escape_string()` execution path, no raw-handle `query()`/`exec()` on an HTTP page, and no superglobal interpolated into SQL text. Values go through `Database::prepare()/execute()` with bound parameters throughout, including the newest code (threads, proposals, vendor plan, tiers).

### I-002: Request reads and output escaping — clean where it matters
Sweep H reports 0 unsafe superglobal reads (baseline 0). A 333-hit manual scan of unescaped short-echo output found no user-controlled text: the hits are counters, money formatted through helpers, fixed strings, and CSRF fields. The legacy pages that echo stored values (`client_edit.php`, `blog_events_edit.php`) rely on `clean()` — which is `htmlentities(strip_tags(trim()))` at write time — so they are encoded at the source; the new features (threads, proposals, notifications, services) call `htmlspecialchars()` at the point of output.

### I-003: Role gating and ownership scoping — verified
Every `admin/client_*.php` that reads a `booking_id` calls `may_open_couple_page()` / `booking_visible_to_session()` (checked programmatically: zero exceptions). `planner_scope_guard_request()` in `init.php` applies per-planner scoping to the *request* for every staff page, with the paywall taking precedence; `staff_may_open_organizer()` and `planner_scope_sql()` implement the same "mine, or nobody's yet" rule and are pinned by `PlannerScopingTest`. Staff, couple, vendor and super-admin paths were exercised by the existing HTTP suites.

### I-004: M-Pesa amount tolerance has no KSh 1 floor
`MpesaC2B::tolerance()` is a fraction of the expected amount (`WPMS_MPESA_TOLERANCE`, default 0.01) with no absolute floor. The audit brief assumed a KSh 1 floor; `README.md` documents only the fraction, and `tests/MpesaC2BTest.php` asserts the ratio ("KSh 1 difference within 1% must pass" — on a KSh 100 payment, where 1% *is* KSh 1). **Not changed:** adding a floor would loosen matching for small payments, which is a business decision, not a bug fix. Verified `verify()` refuses both under- and over-payment, matches the account reference, and `mark_consumed()` is a single-use `WHERE verified_at IS NULL` update.

### I-005: Couple portal tools are not gated on `status = confirm`
The brief expected the portal to unlock only for confirmed weddings. No such gate exists, and no document describes one: a couple can use the checklist, guests, budget, vendors and website from signup. **Not changed** — locking planning tools behind an unconfirmed status would remove the convenience that is the product's selling point. Only `client_manage_account_details.php` reads the status, and only to phrase the page.

### I-006: `tblwedding_milestones` and `tblwedding_activity` do not exist
Both are named in the audit brief. Neither is in the schema, the migrations, or any query — the checklist auto-seed lives in `tblchecklist` (with its own phase for proposal-derived tasks, pinned by `ChecklistProgressTest`), and the activity surfaces are the notification feed and the conversation threads. No orphan-row risk to check; nothing to fix.

### I-007: N+1 heuristic sweep
A temporary AST-ish scan for query calls inside `foreach` bodies (26 hits) found no request-path N+1 worth changing. The genuine in-loop queries are deliberate re-reads before money is written (`Proposal::lock()` re-checks each line's vendor approval, service state and availability inside the confirmation transaction — reading them once beforehand is what would be wrong) or bounded sweeps over a handful of rows. The rest are `foreach (fetchAll(...))` iterables and CLI introspection scripts.

### I-008: `events` has no event-type enum
Task/function names are free text, with a suggested list in `admin/task_calendar.php` (Ruracio, Church, Civil, Reception, After-Party, Bridal Shower, VC Meeting, Engagement). The brief describes an enum; the product deliberately does not have one — a Kenyan wedding's functions vary by family, and a closed list would refuse the seventh one. Verified the guest fields the brief lists (priority A–E, harambee, upcountry, transport, dietary) are present and enforced by their column types.

---

## Improvements Suggested (Not Yet Implemented)

1. **Rotate the CSRF token at the authentication boundary.** `Session::login()` regenerates the session id but keeps `_token`, so a token minted for an anonymous visitor remains valid after sign-in. Low practical risk (the token is 32 random bytes bound to the session that was just rotated in place), but a token rotated with the privilege change is the textbook shape, and `privilege_changed()` already exists to carry it.
2. **Read 201 from Africa's Talking as "accepted", then confirm delivery by callback.** The implementation now refuses a non-`Success` recipient status, which covers bad numbers and sender IDs; AT's delivery reports (a separate callback) are the only way to know a handset actually received a text.
3. **Bound the M-Pesa tolerance for small amounts.** If a KSh 1 floor is wanted (the brief assumed one), make it explicit and documented rather than implicit-in-the-ratio; the call site is one function.
4. **A workstation check for the rest of the case-sensitivity class.** `IncludeCaseTest` covers PHP includes; the same defect can hide in CSS/JS/image paths in templates (`images/Logo.png` vs `logo.png`), which fails as a broken asset on Linux and is invisible locally. The scan is the same walk with a different extension list.
5. **Prune `tblsession_audits`.** The trail is append-only and nothing ages it out. One indexed `DELETE … WHERE created_at < NOW() - INTERVAL 180 DAY` in an existing cron (or `PublicGuard::prune()`'s neighbour) keeps it bounded without changing what it records.
6. **Measure the guest-list pages before and after the new indexes on seeded volume.** `EXPLAIN` proves the index is chosen; a synthetic 30-wedding book would quantify the win and catch any future regression in the same way `EXPLAIN` caught the missing index.

---

## Verification

- [x] Existing test suite passes — **755 tests / 6,205 assertions, OK** (`vendor/bin/phpunit --no-coverage`)
- [x] Lint clean — `composer lint`, 149 files
- [x] Static sweeps A–H clean — `composer code-audit` (DB_Object shape, static calls, SQL columns, promises, enum values, fixtures, unbound SQL, request reads)
- [x] New regression tests: include-case (proven to fail on the original defect), session audit row over real HTTP, dry-run heartbeat absence for all three jobs, contact-door rate limiting
- [x] Migrations `v3.2` applied to `dbwedding` and `wpms_test`; `EXPLAIN` confirms the new indexes are chosen
- [x] All four role logins exercised by the HTTP suites (`UnifiedLoginTest`, `SessionLoginTest`, `PlannerScopingHttpTest`, `CouplePagesAccessTest`)
- [ ] **Manual walkthrough of all four role dashboards in a browser** — not performed in this pass (the HTTP suites cover routing and ownership; visual review is yours)
- [ ] **M-Pesa C2B callback with a live sandbox payload** — not performed here; exercised by `tests/MpesaC2BTest.php` against the ingest path, but not against Safaricom's sandbox
- [ ] **STK push in dev mode** — covered by `tests/MpesaStkTest.php`; no live Daraja call made
- [x] **MCP tool calls audited for scope leakage** — `McpEndpointTest`, `McpWriteToolsTest`, `PlannerScopingHttpTest`
- [x] **Planner scoping verified with two planners + one super admin** — `PlannerScopingTest`, `PlannerScopingHttpTest`
