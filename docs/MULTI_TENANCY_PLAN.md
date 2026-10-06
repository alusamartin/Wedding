# Multi-Tenancy Plan — Everafter Planner (Kenyan SaaS edition)

**Status:** design proposal · **Author:** Buffy (Codebuff) · **Date:** 2026-09-15

---

## 1. Why this exists

Today every planner, vendor, couple, booking, guest list and M-Pesa receipt lives
in one shared `dbwedding` database. That is fine for one company running the
product; it is fatal for selling the product. One tenant's planner must never be
able to read another tenant's couples, guest phone numbers, harambee pledges or
payment history — and a single forgotten `WHERE` clause must not leak them.

This plan converts the existing single-tenant schema into a **shared-schema,
discriminator-column** multi-tenant system (the model used by most PHP/MySQL
SaaS at this scale), with:

- a `tbltenants` registry (one row per subscribing planner/agency),
- a `tenant_id` on every tenant-owned table,
- a **single scoping layer** enforced in one place (the PDO wrapper), not
  copy-pasted into 40 page scripts,
- role semantics preserved: Super Admin (platform), Tenant Admin, Planner,
  Vendor, Couple — exactly the roles the system has today, re-homed.

---

## 2. Tenancy model — who is a tenant?

The unit of tenancy is the **planner business** (an agency, or a solo planner),
not the individual staff user. This matches the money: subscriptions are sold
per planner today (`tblsubscriptions.user_id` → staff), but an agency plan
("multi-user, white-label") implies several staff under one paying tenant.

```
tbltenants (the business)
    ├── staff: tblusers rows with tenant_id = X        (tenant admin, planners)
    ├── vendors: tblvendors rows with tenant_id = X    (per-tenant marketplace)
    └── couples: tblaccounts rows with tenant_id = X   (per-tenant clients)
                      └── bookings, guests, budgets … all inherit tenant_id
```

Key decisions:

| Decision | Choice | Why |
|---|---|---|
| Tenancy unit | planner business (`tbltenants`) | matches Agency plan; couples/vendors belong to a business, not a person |
| Isolation model | shared schema + `tenant_id` discriminator | smallest diff from the current codebase; no per-tenant DB provisioning |
| Tenant resolution | from the logged-in session (`$_SESSION['tenant_id']`) | no subdomains/paths in v1 — one domain, one app |
| Platform staff | `tenant_id = 0` | super admins are "the SaaS operator", see across tenants |
| Marketplace | **per-tenant** vendor pool (v1) | vendors are approved by each tenant's admin; a shared pool is a bigger product decision (§9) |

---

## 3. Table classification (the full inventory)

Every table in the schema (core dump + v2.1 seed + hardening migration) falls
into one of three classes. The class decides what the migration does to it.

### 3.1 TENANT — rows belong to exactly one tenant; gets `tenant_id`

| Table | Natural tenant anchor (today) | Notes |
|---|---|---|
| `tblusers` | — (becomes tenant member) | staff + vendor leads; `tenant_id` + `tenant_role` columns added |
| `tblaccounts` | via its bookings | couples; `tenant_id` stamped at registration |
| `tblaccounts_detail` | parent account | follows `tblaccounts` |
| `tblweddingbook` | `organizer_id` → planner | **the central tenant-scoped row** |
| `tblguest` | via `booking_id` | denormalize `tenant_id` for fast list queries |
| `tbl_liquidation` | via `booking_id` | money ledger — highest sensitivity |
| `tbl_installment_plans` | via `booking_id` | |
| `tblchecklist` | via `booking_id` | |
| `events` | via `booking_id` | calendar |
| `calendar` | via `booking_id` | legacy widget table |
| `tbleventbooking` | via booking/event links | |
| `tblgallery` | via `booking_id` | |
| `tblwedding_websites` | via `booking_id` | public slugs must stay globally unique (§6.3) |
| `tblvendors` | owner `user_id` | per-tenant marketplace (v1) |
| `tblvendor_packages` | via `vendor_id` | |
| `tblvendor_bookings` | via `vendor_id`/`booking_id` | commission ledger |
| `tblsms_credits` | `user_id` (planner wallet) | becomes a **tenant wallet** in v2 (§8) |
| `tblsms_logs` | sender `user_id` | audit trail; tenant_id for filtering/billing |
| `tblnotifications` | `user_id` | recipient-scoped; tenant_id for admin views |
| `tbl_mail_logs` | recipient | audit trail |
| `tbl_password_resets` | `user_id` | |
| `tblmpesa_stk_pushes` | `user_id` | payment attempts |
| `tblsubscriptions` | `user_id` (planner) | **becomes the tenant's subscription** (§8) |

### 3.2 PLATFORM — operator-owned; **no** `tenant_id`

| Table | Notes |
|---|---|
| `tbltenants` | new — the registry itself |
| `tblsubscription_plans` | global price list (per-tenant plan rows live in `tblsubscriptions`) |
| `tblmpesa_callbacks` | single-use payment ledger; keyed by TransID + shortcode, not by tenant |
| `tblmpesa_callback_log` | transport log |
| `tbllogin_throttle` | (email, IP) based — global by nature |
| `tblsession_audits` | gains an optional `tenant_id` for forensics, but is platform-owned |

### 3.3 GLOBAL / SHARED — reference data; stays as-is in v1

| Table | Notes |
|---|---|
| `tblweddingcategories` | the 5 Kenyan packages — platform catalog, read-only to tenants |
| `tbl_features` | package features |
| `tblorganizer` | legacy placeholder |
| `tblpostwedding` | real-weddings blog — platform marketing content |
| `tblsms_credits_packages` (if present in seed) | SMS price list |

> Rule of thumb applied: **if a row would be wrong to show to another planner's
> staff, it is TENANT. If it is pricing/reference/marketing owned by the
> operator, it is PLATFORM or GLOBAL.**

---

## 4. The migration (one idempotent SQL file + one PHP backfill)

Follows the project's existing migration discipline
(`migrations/v2.1_phase1_hardening.sql` is idempotent; `composer demo:reset`
chains the full install order). New file: `migrations/v3_multi_tenant.sql`,
plus `scripts/backfill_tenants.php`.

### 4.1 New registry table

```sql
CREATE TABLE IF NOT EXISTS `tbltenants` (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150) NOT NULL,                -- "Amina Weddings Ltd"
  slug          VARCHAR(80)  NOT NULL,                -- URL/public ref, UNIQUE
  owner_user_id INT UNSIGNED NOT NULL DEFAULT 0,      -- tenant admin (tblusers.id)
  plan          VARCHAR(30)  NOT NULL DEFAULT 'starter',
  status        ENUM('trial','active','past_due','suspended') NOT NULL DEFAULT 'trial',
  trial_ends_at DATE         NULL,
  county        VARCHAR(60)  NULL,
  phone         VARCHAR(20)  NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_slug (slug),
  KEY idx_tenant_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.2 `tenant_id` columns (idempotent pattern used throughout)

```sql
-- One block per table; repeat for every TENANT table in §3.1.
ALTER TABLE `tblweddingbook`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `booking_id`,
  ADD KEY IF NOT EXISTS `idx_weddingbook_tenant` (`tenant_id`);
```

> MariaDB supports `ADD COLUMN IF NOT EXISTS`; stock MySQL 5.7/8.0 does not —
> the migration file should ship the information_schema-guarded variant
> (same technique the hardening migration already uses) so it stays re-runnable
> on both. Default `0` = "platform/unassigned"; the backfill then stamps real
> values and a final verification query asserts no business row remains `0`.

### 4.3 Backfill strategy (single-tenant → one tenant)

The running install has exactly one implicit tenant: the operator's business.
`scripts/backfill_tenants.php`:

1. Creates tenant #1 (`slug = 'everafter-hq'`, status `active`, no trial) —
   the operator's own tenant.
2. Stamps `tenant_id = 1` on every row of every TENANT table (all current rows
   belong to the operator).
3. Sets `tenant_role` on `tblusers`: `access_level 2 → 'platform_admin'`,
   `1 → 'tenant_admin'` (the owner of tenant #1) or `'planner'`,
   `0 → 'vendor'` unchanged.
4. Verifies: `SELECT COUNT(*) FROM <each TENANT table> WHERE tenant_id = 0`
   must be 0 for every table except genuinely-new rows created mid-migration;
   fails loudly otherwise (same philosophy as `demo_reset.php`).

After this, the existing single-tenant deployment behaves identically — all
data sits in tenant #1 — and new tenant provisioning becomes possible.

### 4.4 Provisioning a new tenant (used by planner self-signup later)

```php
Tenant::provision($name, $ownerUserId, $plan):
  BEGIN;
    INSERT tbltenants (name, slug, owner_user_id, plan, status='trial', trial_ends_at=+14d);
    UPDATE tblusers SET tenant_id = :tid, tenant_role = 'tenant_admin' WHERE id = :owner;
    INSERT tblsms_credits (user_id = owner, credits = 10);   -- welcome bundle
  COMMIT;
```

Slug generation: `strtolower(slug of business name)` + collision suffix; also
used for the future public wedding-page namespace (§6.3).

---

## 5. The scoping layer (the part that actually makes it safe)

Adding columns does nothing by itself. The safety comes from **one choke point**
that every query passes through. Today the codebase has two patterns:

- ~30 model methods with hand-written `WHERE booking_id = :bid` /
  `WHERE user_id = :uid` (Guest, Liquidation, Events, Booking, Notification…),
- per-page ownership checks copy-pasted into client pages
  (`client_budget.php` re-derives `Booking::find_by_booking_id()` + session
  comparison on every page).

Both are replaced by a **tenant-aware query builder** injected at the
`Database` wrapper level, plus **one authorization helper** for row access.

### 5.1 Session carries tenancy

At login (`login.php` → the unified door), after authentication:

```php
$_SESSION['tenant_id']  = (int) $user->tenant_id;   // 0 for platform admins
$_SESSION['tenant_role'] = $user->tenant_role;      // platform_admin|tenant_admin|planner|vendor|client
```

Client sessions (`tblaccounts`) get their tenant from their own row — couples
are stamped at registration and never move between tenants.

### 5.2 Automatic query scoping — `Database::scoped()`

`admin/include/Database.php` gains one method; every model read/write for a
TENANT table goes through it:

```php
/**
 * Return a prepared-statement factory for $table that silently ANDs every
 * query with tenant_id = <current session tenant>.
 *  - platform admins (tenant_id 0) get NO filter (cross-tenant console)
 *  - writes stamp tenant_id automatically on INSERT
 *  - refusing to scope a TENANT table without a session is a hard error
 */
public static function scoped(string $table): ScopedQuery;
```

Behavior:

```php
// Before (Booking.php):
$sql = "SELECT * FROM tblweddingbook WHERE organizer_id = :oid";

// After:
$sql = Database::scoped('tblweddingbook')   // → SELECT * FROM tblweddingbook WHERE tenant_id = 7 AND organizer_id = :oid
    ->prepare($sql_without_tenant_predicate);
```

Rules encoded in the wrapper:

1. **Default deny.** A query against a TENANT table without `scoped()` throws
   in non-dev environments (enforced by a table→class registry constant, same
   style as `MpesaC2B::ALLOWED_KEYS`).
2. **INSERT stamping.** `scoped()` INSERTs automatically add `tenant_id` from
   the session — no page script can forget it.
3. **Cross-tenant reads are impossible via the ORM path**; any deliberate
   cross-tenant operation (platform console, backfill, cron) must use
   `Database::scoped_as_platform()` which is loud, greppable, and audited
   (`tblsession_audits` row with action `cross_tenant_read`).
4. **Cron scripts** (`rsvp_reminders.php`, `deadline_sms.php`, `mail_retry.php`)
   iterate tenants explicitly: `foreach (Tenant::active_ids() as $tid)` and set
   the scope context per tenant — reminder logic itself is unchanged.

### 5.3 Row-level authorization — one helper replaces the copy-paste

```php
/**
 * The ONLY way a page script touches a booking. Resolves role + tenancy in
 * one place; every client_*.php / guest_*.php / budget_*.php calls this
 * instead of re-deriving ownership.
 */
function assert_booking_access(int $booking_id): object {
    $booking = Booking::find_by_booking_id($booking_id);   // scoped() read
    if (!$booking) { redirect_to('client.php'); exit; }

    if (is_platform_admin())  return $booking;             // tenant 0
    if (is_staff())           return $booking;             // same-tenant staff
    // couple: must own the booking, and $user_id param must match session
    if ((int) $booking->user_id === (int) $_SESSION['id']) return $booking;
    redirect_to('client.php'); exit;
}
```

This subsumes the check currently duplicated in `client_budget.php` (and its
siblings) — including the subtle `$user_id !== (int) $_SESSION['id']`
query-string guard, which moves into the helper so no page can omit it.

### 5.4 Role semantics after the change

| Role (today) | Becomes | Sees |
|---|---|---|
| Super Admin (`access_level 2`) | **Platform Admin** (`tenant_id 0`) | everything, cross-tenant console |
| Wedding Planner (`access_level 1`) | **Tenant Admin** or **Planner** (`tenant_id N`) | all bookings of their tenant (planner-level "own clients" can stay as an optional organizer filter inside the tenant) |
| Vendor (`access_level 0`) | **Vendor** (`tenant_id N`) | their own vendor row + bookings, scoped to their tenant's marketplace |
| Couple (`tblaccounts`) | **Client** (`tenant_id N`) | their own bookings only — unchanged behavior |

Important nuance: today `require_staff()` alone lets **any** planner see **all**
bookings. In the tenant model, staff visibility is bounded by `tenant_id`
first, and the existing organizer filter becomes an intra-tenant preference,
not a security boundary. This is the single biggest behavioral change — see §7.

---

## 6. Touchpoints that need explicit care

### 6.1 Money paths

- **Subscriptions become tenant-level.** `Subscription::get_active($user_id)`
  → `Subscription::get_active_for_tenant($tenant_id)`; the login gate
  (`login_redirect_target()` in `init.php`) checks the tenant's subscription,
  not the individual planner's. A lapsed tenant locks *everyone* in that
  tenant out of the dashboard (with the grace-period behavior from the SaaS
  roadmap — out of scope here, but the hook is the same place).
- **M-Pesa STK/C2B** are tenant-agnostic ledgers (PLATFORM tables) — but
  fulfilment (`MpesaStk::fulfil()`) must stamp the resulting
  subscription/deposit row through `scoped()` so `tenant_id` is correct. The
  deposit path already resolves the booking; the booking carries the tenant.
- **SMS credits** move from per-planner wallets to a **tenant wallet** in v2
  (one balance per business, admins allocate). v1 keeps per-user wallets but
  stamps `tenant_id` so the migration is non-breaking; `SMS::deduct_credits()`
  gains a tenant-balance check behind the same env flag.

### 6.2 Notifications, SMS, email

`Notification::create()` and `SMS::send()` resolve recipients by `user_id`
today. After the change, recipient resolution stays user-level, but:

- bell lists are scoped via `scoped()` (a tenant's planner never sees another
  tenant's alerts even by guessing IDs),
- courtesy/deadline SMS keep charging the **assigned planner** (who is now
  guaranteed same-tenant because the booking is tenant-scoped),
- the lockout SMS path (`LoginThrottle`) is platform-level and unchanged.

### 6.3 Public surface

- `site.php?slug=...` (wedding websites) and `rsvp.php?token=...` are public
  and must **not** require a session. Slugs/tokens stay globally unique
  (already true for tokens; add UNIQUE on slug) and resolve the tenant from
  the row itself — public pages do one indexed lookup, then operate entirely
  inside that row's tenant context.
- The registration hero form (`index.php`) currently creates couples with no
  tenant. In the tenant model, registration needs a tenant context:
  **v1** — the operator's tenant (tenant #1) keeps the public funnel, exactly
  as today; **v2** — per-tenant sign-up links (`/?t=<slug>` or
  `<slug>.everafter.co.ke`) stamp the couple into the right tenant. The
  `register_client()` helper in `Helper.php` gains a `$tenant_id` parameter
  (defaulting to the resolved context).

### 6.4 Uploads

`admin/upload/{categories,events,gallery,users}/` is a shared directory tree.
Per-tenant isolation for files means path prefixing: `upload/gallery/t<tid>/…`
— a storage-layer change, scheduled with the v2 marketplace work, not the
migration itself (rows carry `tenant_id`; files follow later).

---

## 7. Migration order & rollout

| Phase | What | Risk |
|---|---|---|
| **0. Prereq** | `composer test` green; full DB backup; `demo:reset` reproducible | — |
| **1. Schema** | `migrations/v3_multi_tenant.sql` (registry + columns + indexes; idempotent) | low — additive only |
| **2. Backfill** | `scripts/backfill_tenants.php` → tenant #1 owns everything | low — one UPDATE per table |
| **3. Session** | login stamps `tenant_id`/`tenant_role`; `is_staff()` untouched | low |
| **4. Scoping layer** | `Database::scoped()` + table registry + `assert_booking_access()`; convert models **table by table**, starting with `tblweddingbook`, `tblguest`, `tbl_liquidation` | **medium — the real work** |
| **5. Page cutover** | client pages switch to `assert_booking_access()`; staff pages rely on scoped models | medium |
| **6. Money** | subscription gate → tenant-level; STK fulfilment stamps tenant | medium |
| **7. Cron** | reminder scripts iterate tenants | low |
| **8. Platform console** | super admin gets cross-tenant views (tenants list, impersonation via audited `scoped_as_platform()`) | optional, last |

Each phase is independently deployable: after phase 3 the app is unchanged in
behavior (everything is tenant #1); phases 4–7 progressively make the
discriminator load-bearing. A `WPMS_TENANCY_OFF=1` escape hatch keeps the
scoping layer in "single-tenant mode" (all queries unscoped, tenant #1
implied) for local dev and a fast rollback path.

### Tests to add (PHPUnit, `tests/`)

- `TenantScopingTest`: as tenant-A planner, every model read for tenant-B ids
  returns empty / throws (booking, guests, payments, notifications).
- `BackfillTest`: after `backfill_tenants.php`, zero TENANT rows with
  `tenant_id = 0`.
- `PublicSurfaceTest`: wedding-site slug + RSVP token resolve across tenants
  without a session, and leak nothing else.
- `SubscriptionGateTest`: lapsed tenant locks all its staff; platform admin
  unaffected.
- Existing suite must stay green with `WPMS_TENANCY_OFF=1` (compat guarantee).

---

## 8. What deliberately changes in the product

- **The planner↔client relationship stays 1:1 inside a tenant** — no couple is
  shared between businesses. If two planners share a client, that is a business
  decision (partnership/agency) expressed by both planners being in one tenant.
- **Vendors choose their tenant** (v1: they register through a tenant's
  marketplace link; the vendor's `tenant_id` is that tenant). A vendor working
  across multiple planners is modelled later as multi-tenant vendor membership
  (`tblvendor_memberships`) — noted, not built, in v1.
- **Reporting changes meaning**: `Revenue::monthly_totals()` currently reports
  *platform* revenue from single-tenant tables. Post-migration it becomes the
  **operator's** dashboard (all tenants), and each tenant gets its own scoped
  revenue view (their commissions + SMS spend) — same queries, different scope.

---

## 9. Explicitly out of scope (recorded for the roadmap)

- **Shared/cross-tenant vendor marketplace** — a couple of tenant A seeing
  tenant B's approved vendors. Bigger product question (network vs. per-business
  marketplace); the schema above does not preclude it (vendor rows are just
  rows; a membership table generalizes them).
- **Per-tenant subdomains** (`amina.everafter.co.ke`) — session/cookie scoping
  work; v1 runs one domain.
- **Row-level DB enforcement** (MySQL views/proxies per tenant) — unnecessary
  at this scale; the application choke point suffices and stays debuggable.
- **Dedicated tenant DBs** (silo model) — for enterprise/white-label buyers
  later; the white-label stream in the manual (KSh 150k–500k) maps naturally
  onto a per-tenant DB deployment of the same codebase.

---

## 10. Summary checklist

1. `migrations/v3_multi_tenant.sql` — registry table + `tenant_id` on 22 TENANT tables + indexes (idempotent).
2. `scripts/backfill_tenants.php` — tenant #1 absorbs all existing rows; verification gates.
3. `Database::scoped()` + `TENANT_TABLES` registry — the single choke point; default-deny outside dev.
4. `assert_booking_access()` — one helper replaces every per-page ownership check.
5. Login stamps `tenant_id`/`tenant_role`; `login_redirect_target()` gates on the tenant's subscription.
6. Public pages (slug/RSVP token) resolve tenant from the row; registration gains tenant context.
7. Cron scripts iterate tenants; platform admin gets audited cross-tenant access.
8. PHPUnit: scoping, backfill, public-surface, and subscription-gate tests; legacy suite green under `WPMS_TENANCY_OFF=1`.
