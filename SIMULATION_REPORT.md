# Everafter Planner — Simulation & Audit Report

**Simulator:** Freebuff (Buffy)
**Date:** 2026-10-25
**Scope this pass:** the *high-risk spine* — all four role logins, every flow that moves money, changes state or crosses a role boundary, and the ten security checks on those paths. The full ~60-scenario checklist was deliberately not attempted in one pass.
**Environment:** XAMPP, PHP 8.2.12, MySQL, `http://localhost/Wedding`, seeded demo database, `WPMS_PLANNER_SCOPING` at its default (enabled), `WPMS_MPESA_VERIFY` off.

---

## Executive Summary

- **Checks executed:** 34 (26 walked directly, 8 verified through the automated suite rather than re-walked)
- **Findings:** 8 — **2 High**, **1 Medium**, **5 Info**
- **Fixes applied:** 3 production fixes + 1 regression test
- **Regressions:** 0 — full suite **756 tests / 6213 assertions OK** (was 755/6205)

| # | Severity | Finding | Status |
|---|----------|---------|--------|
| F-001 | **High** | A planner could open and *write* the assignment/edit form of another planner's wedding via `?booking=` | Fixed |
| F-002 | **High** | Any planner could "accept" an unclaimed M-Pesa payment in the ledger, then attach that code to their own booking | Fixed |
| F-003 | **Medium** | Planner dashboard "Total Customers" counted the whole platform, not their own couples | Fixed |
| F-004 | Low | `admin/select.php` is a POST endpoint without a CSRF token (read-only) | Accepted |
| F-005 | Info | `rsvp.php` / `contact.php` have no CSRF token (capability-token + PublicGuard instead) | Accepted |
| F-006 | Info | Two CLI scripts interpolate table names into SQL (hardcoded allowlists) | Accepted |
| F-007 | Info | `md5()` in Monetization is an in-process cache key, not security | Accepted |
| F-008 | Info | `include/footer.php` emits a modal block after `</html>` | Deferred (cosmetic) |

---

## Method & access

- Each role was signed in through the real `login.php` over HTTP with its own cookie jar.
- The seeded passwords in the brief (`Admin@2024!`, `Test@1234`) do not match this database. Per the agreed approach, four accounts were given a temporary password (`Sim#Walk2026`), used for the walk, then their **exact original bcrypt hashes were restored** and the temporary password was verified to no longer work:
  - super admin `wafula@wpms.co.ke` (5), planners `amina@wpms.co.ke` (7) and `njeri@wpms.co.ke` (100), vendor `kamau@nyamachoma.co.ke` (8), couple `brian.wafula@gmail.com`
- Temporary data mutations used for adversarial tests were reverted and verified (booking 201's organizer, one M-Pesa callback, one conversation message + its bell).
- Adversarial HTTP probes ran from a throwaway curl harness in the temp directory; no probe code was added to the project.

---

## Stage 1 — Super Admin

| Scenario | Result | Notes |
|---|---|---|
| Platform overview (KPIs, trial funnel) | ✅ PASS | Tiles + funnel render real data, no N+1 storm observed |
| Vendor approval queue | ✅ PASS | `vendors.php:161` requires `is_super_admin()` + CSRF; approval notifies the vendor |
| Vendor premium toggle | ✅ PASS | `vendors.php:216` same gate; drives commission rate |
| M-Pesa ledger — manual accept | ❌ **FAIL** | See F-002 (fixed) |
| Job Health | ✅ PASS | `job_watch.php` audits on open, reports silent/never-run jobs, delivers once then stays quiet; on this box it correctly flagged the missing cron jobs |
| Business Pulse | ✅ PASS | Guarded to super admins (`ops_pulse.php:52`) |
| Login Security | ✅ PASS | `tblsession_audits` + throttle tables present; fingerprint mismatch logs and forces logout |
| MCP approvals | ✅ PASS | `McpApprovals::consume()` verifies HMAC, expiry, state, approval hash, tool, payload hash, token **and** user, then claims with a conditional `UPDATE … WHERE consumed_at IS NULL` — exactly-once |
| AI tokens | ✅ PASS | Admin scopes refused to non-admins (`api_tokens.php:175`) |

**Adversarial:** planner → `subscription_plans.php` = 302 refused; planner → vendor approval POST = refused by the operator gate.

---

## Stage 2 — Wedding Planner

| Scenario | Result | Notes |
|---|---|---|
| Dashboard KPI scoping | ❌ **FAIL** | Bookings count was scoped, customer count was not — F-003 (fixed) |
| Bookings list scoping | ✅ PASS | Own + unassigned only; another planner's couple absent |
| Door guard on `booking_id` | ✅ PASS | Budget, guests, calendar, couple pages all bounce with "not one of yours" |
| Door guard on `booking` | ❌ **FAIL** | Assignment and edit forms were exempt — F-001 (fixed) |
| Own-wedding access after the fix | ✅ PASS | `client_assign.php?booking=200`, `client_edit.php?booking=200`, `budget.php` all 200 |
| CSRF on money POST | ✅ PASS | `budget_add.php` without a token → 302, nothing written |
| Enquiries ledger scoping | ✅ PASS | Deal on another planner's wedding is neither listed nor POST-able (suite + walk) |
| Reply escalation | ✅ PASS | Crafted a 3-day-old unanswered message: one bell, one `escalated_at` stamp; second run silent |

---

## Stage 3 — Vendor

| Scenario | Result | Notes |
|---|---|---|
| Bookings ledger scoping | ✅ PASS | Kamau's page shows only his deals; Chebet's "Ruracio Decor" deal absent |
| Accept another vendor's deal | ✅ PASS (refused) | 302, row unchanged (`status=pending`, `amount=80000.00`) |
| Accept without CSRF | ✅ PASS (refused) | 302, nothing written |
| Edit another vendor's package | ✅ PASS (refused) | 302, package title unchanged |
| Self-registration / validation | ✅ (suite) | `VendorSignupTest` green |
| Services & packages self-service | ✅ (suite) | Every query scoped by `vendor_id`; ownership verified in source |

---

## Stage 4 — Couple

| Scenario | Result | Notes |
|---|---|---|
| Login → `client.php` | ✅ PASS | Couple lands on their own dashboard |
| Cross-owner couple pages | ✅ PASS | Another couple's budget/guests/checklist/marketplace refused (previously walked visually too) |
| Staff-only money writes | ✅ PASS | `require_staff_subscribed()` bounces a couple before any budget handler runs |
| KPI tiles / vendor-cost tile | ✅ PASS | Renders correct figures (verified in the visual pass, unchanged here) |
| Taste & budget, vendor plan, enquiry flow | ✅ (suite) | `VendorFitTest`, `VendorPlanTest`, `CoupleVendorMarketplaceTest` green |

---

## Cross-Role Integration

| Flow | Result |
|---|---|
| Planner assigns + confirms a booking | ✅ Code path sound: CSRF, ownership now enforced, couple bell + courtesy SMS, planner-charged credits |
| M-Pesa C2B ingest + replay | ✅ Idempotent by `unique(trans_type, trans_id)`; replay returns `ok` with id 0 |
| M-Pesa code verification | ✅ Consumed/rejected rows excluded; amount tolerance ±1% + 0.005; `mark_consumed()` conditional |
| STK push | ⚠️ Not exercised (no Daraja credentials); covered by `MpesaStkTest` |
| MCP Tier-3 approval | ✅ Exactly-once claim, payload-hash bound (code + `McpWriteToolsTest`) |
| Reply escalation | ✅ Walked, idempotent |
| Job watchdog | ✅ Walked; alerts delivered once per (job, finding) |
| Double-booking / capacity warnings | ✅ (suite) `VendorAvailabilityTest`, capacity tests green; accept never blocks, only flags — by design |

---

## Security & Hardening Audit

| Check | Result | Evidence |
|---|---|---|
| CSRF on every POST | ✅ PASS with 1 exception | 24 staff pages with POST handlers all call `verify_csrf()`; only `admin/select.php` (read-only) does not — F-004 |
| SQL injection | ✅ PASS | Only 2 interpolations remain, both CLI scripts over hardcoded table lists — F-006 |
| XSS | ✅ PASS | No raw `<?= $obj->prop ?>` in spine pages; `htmlspecialchars` used throughout (24–109 call sites per page) |
| Ownership scoping | ❌ **FAIL → fixed** | F-001; central guard in `init.php` otherwise covers every `booking_id` request |
| Session fixation | ✅ PASS | Regenerate on login + every 15 min; fingerprint binding; inactivity timeout |
| Password security | ✅ PASS | bcrypt everywhere; no plaintext comparisons; the single `md5()` is a cache key |
| Rate limiting | ✅ PASS | `LoginThrottle` after CSRF; per-email and per-IP; suite covers it |
| M-Pesa replay | ✅ PASS | Unique index + conditional consume |
| MCP scope ceiling | ✅ PASS | Token, user, tool and payload all bound into the HMAC |
| File upload | ✅ PASS | Extension allowlist + `getimagesize` + size cap + random filename (Users, Vendor profile, Gallery, EventWedding) |

---

## Fixes Applied

### F-001 (High) — `?booking=` bypassed the planner scope guard
- **Files:** `admin/include/init.php:197`, `admin/client_assign.php:31`, `admin/client_edit.php:28`
- **Was:** `planner_scope_guard_request()` read only `booking_id`; the two pages that change a wedding's identity link to `?booking=N`. A scoped planner could open another planner's assignment form (verified: 200 OK, the other couple's names on screen) and POST it — the handler reassigns `organizer_id`, i.e. a takeover, not just a read.
- **Fix:** the guard now reads `booking_id` **or** `booking`, GET or POST, and both pages re-check `booking_visible_to_session()` against the resolved row before rendering or writing.
- **Verified:** other-owned `client_assign`/`client_edit`/`budget` → 302 `client.php`; own wedding → 200; super admin exempt → 200.
- **Regression risk:** Low. Only the two alias pages change behaviour, and only for a planner who does not own the wedding.

### F-002 (High) — planners could verify M-Pesa payments
- **File:** `admin/mpesa_ledger.php:29-42` and the Accept button block
- **Was:** any staff member could POST `accept_id` and stamp `verified_at` on an unclaimed callback. Once verified, that transaction code can be attached to *any* booking by whoever holds it, so a planner could redirect a stranger's payment to their own couple.
- **Fix:** manual accept is super-admin only (page copy and the audit note already said "accepted manually by <admin>"); planners keep read access, and the button is replaced with "awaiting owner check".
- **Verified:** planner POST → 302, callback still `NULL`; admin POST → verified with `ref_table='manual'`; probe row restored.
- **Regression risk:** Low. Planners lose an action the brief assigns to the operator. If a shop wants planners to accept, the gate is one line.

### F-003 (Medium) — planner's "Total Customers" was platform-wide
- **Files:** `admin/dashboard.php:14`, `admin/include/Booking.php:95` (`count_couples()`)
- **Was:** `Account_Details::count_user()` counts every `tblaccounts_detail` row (74) while the adjacent booking tile was scoped (6) — two tiles, two universes, plus a small platform-size leak.
- **Fix:** a scoped `COUNT(DISTINCT user_id)` over the same `planner_scope_sql()` predicate; super admins and scoping-off installs keep the platform number.
- **Verified:** planner now shows **6 customers / 6 bookings** (matches the wedding count); admin view unchanged.
- **Regression risk:** Low; the model gains one method and the dashboard one branch.

### Regression test
`tests/PlannerScopingHttpTest::testTheBookingAliasCannotReachAnotherPlannersWedding()` — refuses both alias pages for the non-owner and keeps the owner's page open. Suite: 6/6 green in that file.

---

## Improvements Suggested (not implemented)

1. **Parametrise the door-guard test across every booking-taking staff page.** Eighteen pages accept a wedding id; the guard is one function, but a table-driven test that walks them all would catch the next alias (`?event=`, `?wedding=`) before a reviewer does.
2. **An HTTP test for ledger authority.** F-002 is verified manually; a small page test in the style of `McpApprovalsPageTest` would lock the super-admin gate down.
3. **Rotate CSRF tokens per request.** Tokens are session-static; per-form rotation would limit replay of a leaked token at the cost of handling multi-tab forms.
4. **Split the planner dashboard's content tiles.** Photos/Blogs counts are platform content shown next to the planner's own numbers; they invite the same confusion F-003 fixed.
5. **Remove the post-`</html>` modal in `footer.php`.** Invalid markup that no browser has punished yet, but it is the kind of thing a screen reader or a strict linter eventually notices.

---

## Verification Checklist

- [x] All four role logins work end-to-end (HTTP, real `login.php`)
- [x] Full suite passes — **756 tests, 6213 assertions**
- [x] No new interpolated SQL introduced
- [x] No new unescaped output introduced
- [x] Planner scoping verified with two planners + one super admin
- [x] M-Pesa C2B idempotency verified in source and by suite; STK not exercised (no credentials)
- [x] MCP single-use verified by source and suite
- [x] Temporary credentials reverted; original hashes restored and confirmed
- [ ] Every new migration has a matching rollback — **no migrations were added this pass**

---

## Limitations

- **Not walked:** gallery upload UI, public wedding website + RSVP, MCP calls over HTTP, the full couple→wedding-day lifecycle, SMS/email delivery (outbound is suppressed here), and the 18 booking-keyed pages one by one.
- Happy paths for those are covered by the existing suite (which is green); the adversarial edges above are the ones this pass actually executed.
- Money flows were exercised at the ledger/callback/model level rather than against live Safaricom endpoints.
- The working tree also carries the six visual fixes from the previous dashboard walk (client page, sidebar, admin CSS) — still uncommitted alongside these three fixes.
