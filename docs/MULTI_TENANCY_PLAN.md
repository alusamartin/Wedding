# Multi-Tenancy Plan — Everafter Planner (Kenyan SaaS edition)

**Status:** implementation plan · **Owner:** Buffy (Codebuff) · **Revised:** 2026-10-07
**Supersedes:** the 2026-09-15 design proposal. This revision is grounded in the
current working tree (`migrations/` up to v2.5, `deploy/schema.sql`, 35 tables)
and names the exact files and functions each step touches.

---

## 0. How to read this document

- **§1–§4** are the model, the table inventory, and the isolation boundaries —
  the decisions. Read these before writing code.
- **§5–§8** are the concrete artefacts: migration SQL, the scoping layer with
  real signatures, provisioning, and the cutover order.
- **§9 is the first shippable increment.** If you only read one section, read
  §9. It is deliberately behavior-preserving and cannot leak data.
- **§10–§13** are the follow-on increments, test plan, and out-of-scope items.

The goal of this revision is that a developer can pick up §9 and ship it this
week without making a single product-level decision.

---

## 1. Why this exists

Today every planner, vendor, couple, booking, guest list and M-Pesa receipt lives
in one shared `dbwedding` database. That is fine for one company running the
product; it is fatal for selling the product. One tenant's planner must never be
able to read another tenant's couples, guest phone numbers, harambee pledges or
payment history — and a single forgotten `WHERE` clause must not leak them.

The change converts the single-tenant schema into a **shared-schema,
discriminator-column** multi-tenant system (the model used by most PHP/MySQL SaaS
at this scale):

- a `tbltenants` registry (one row per subscribing planner/agency),
- a `tenant_id` on every tenant-owned table,
- a **small number of real choke points** that enforce scoping — chosen from the
  code that exists today, not an idealized query builder (§5),
- a mechanical audit that fails the build when a new query forgets the boundary
  (§6.4).

---

## 2. Where isolation actually has to be enforced (grounding)

This is the correction that matters most over the old proposal. The codebase does
**not** have a query builder, and models do not compose queries. It has exactly
three places all data access funnels through:

| # | Choke point | File | Why it is the boundary |
|---|---|---|---|
| **C1** | `DB_Object::find_by_query($sql, $params)` | [admin/include/db_object.php](../admin/include/db_object.php) | Every model read ends here. `find_all()`, `find_by_id()`, `find_by_booking_id()`, `find_by_user_id()`, `find_by_query()` all call it; models (Booking, Guest, Liquidation, Events, Notification, Account_Details …) expose no other read path. |
| **C2** | `Database::execute()` / `query()` / `fetchOne()` / `fetchAll()` / `fetchValue()` | [admin/include/Database.php](../admin/include/Database.php) | Every direct-SQL call in pages and scripts ends here, and C1 itself calls `$db->query()`/`$db->execute()`. |
| **C3** | `DB_Object::create()` / `updateBy()` / `delete()` | [admin/include/db_object.php](../admin/include/db_object.php) | Every model write. INSERT builds its column list from `static::$db_table_fields`; UPDATE/DELETE build `WHERE <key> = :where_val`. |

Supporting surfaces that also carry tenancy but are **not** query choke points:

- `Session::login()` — [admin/include/Session.php](../admin/include/Session.php) — where `tenant_id`/`tenant_role` enter the session.
- `is_staff()` / `require_staff()` / `login_redirect_target()` — [admin/include/init.php](../admin/include/init.php) — role semantics and post-login routing.
- `require_staff_subscribed()` / `subscription_state_for_session()` / `register_client()` — [admin/include/Helper.php](../admin/include/Helper.php) — the gate, the banner, and couple creation.
- `Subscription` — [admin/include/Monetization.php](../admin/include/Monetization.php) — the money model that becomes tenant-level.

**Consequence for the design:** scoping is implemented as a *predicate injector
+ stamping helper* invoked from C1/C3 and a scoped wrapper for C2 — **not** a
`ScopedQuery` factory. The old document's `Database::scoped($table)->prepare(...)`
API does not fit callers that pass fully-formed SQL strings; it is dropped.

---

## 3. Tenancy model & roles

The unit of tenancy is the **planner business** (agency or solo planner), not the
individual staff user. Subscriptions are sold per planner today
(`tblsubscriptions.user_id` → staff); the Agency plan ("multi-user, white-label")
means several staff under one paying tenant.

```
tbltenants (the business)
    ├── staff:   tblusers      rows with tenant_id = X   (tenant admin, planners)
    ├── vendors: tblvendors    rows with tenant_id = X   (per-tenant marketplace)
    └── couples: tblaccounts   rows with tenant_id = X   (per-tenant clients)
                       └── bookings, guests, budgets … all inherit tenant_id
```

| Decision | Choice | Why |
|---|---|---|
| Tenancy unit | planner business (`tbltenants`) | matches the Agency plan; couples/vendors belong to a business, not a person |
| Isolation model | shared schema + `tenant_id` | smallest diff; no per-tenant DB provisioning |
| Tenant resolution | from the session (`$_SESSION['tenant_id']`) | one domain, one app in v1 |
| Platform staff | `tenant_id = 0` | super admins are "the SaaS operator" and see across tenants |
| Marketplace | per-tenant vendor pool (v1) | vendors approved per tenant; shared pool is a product decision (§13) |

### 3.1 Role matrix (behavior after the full migration)

| Role (today) | After | `tenant_id` | Sees |
|---|---|---|---|
| Super Admin (`access_level 2`) | **Platform Admin** | `0` | everything, via the audited cross-tenant path |
| Planner (`access_level 1`) | **Tenant Admin** or **Planner** | `N` | all bookings of tenant `N` |
| Vendor (`access_level 0`, `account_type=staff`) | **Vendor** | `N` | own vendor row + bookings, scoped to tenant `N` |
| Couple (`tblaccounts`) | **Client** | `N` | own bookings only (unchanged) |

Nuance to hold onto: today `require_staff()` alone lets **any** planner see **all**
bookings. Post-migration, staff visibility is bounded by `tenant_id` first; the
existing organizer filter becomes an intra-tenant preference, not a security
boundary. This is the one genuine behavioral change (see §10 Increment 3).

---

## 4. Isolation boundaries (the rules)

These are the rules the code must satisfy when the migration is complete. Each
rule names the mechanism that enforces it.

**B1 — Default deny on TENANT tables.** A read against a table in
`Tenant::TABLES` with no `tenant_id` predicate, off the platform path, is a bug.
Enforced by `Tenant::assertScoped()` in dev/test (throws) and an `error_log`
line in production, plus the static audit in §6.4. Same philosophy as
`MpesaC2B::ALLOWED_KEYS`.

**B2 — Platform reads are loud.** The only way to read across tenants is
`Tenant::asPlatform(function () { … })`, which sets `Tenant::id() = 0` for the
duration, logs a `cross_tenant_read` row to `tblsession_audits`, and is the only
symbol the audit whitelists. Greppable and reviewable by construction.

**B3 — Writes are stamped, never trusted.** `DB_Object::create()` adds
`tenant_id` from `Tenant::id()`; an explicit `$obj->tenant_id` is overwritten
unless `Tenant::id() === 0`. Pages cannot forget the column.

**B4 — A couple belongs to exactly one tenant.** `tblaccounts.tenant_id` is
stamped at registration and never moves. RSVP/wedding-site lookups resolve the
tenant from the row, then operate entirely inside it.

**B5 — Public surface stays public but tenant-resolved.** `site.php?slug=…` and
`rsvp.php?token=…` need no session; they do one indexed lookup, read the row's
`tenant_id`, and open a scoped context for that request only. Slugs stay globally
unique (`UNIQUE` on `tblwedding_websites.slug`); tokens are already global.

**B6 — Cron iterates tenants explicitly.** `mail_retry.php`, `rsvp_reminders.php`,
`deadline_sms.php`, `subscription_renewals.php`, `notify_prune.php` run
`foreach (Tenant::activeIds() as $tid) { Tenant::set($tid); … }`. Reminder logic
is otherwise unchanged.

**B7 — Uploads follow rows in v2.** Files get a tenant path prefix
(`admin/upload/gallery/t<tid>/…`). Scheduled with Increment 5, not the migration:
rows carry `tenant_id` immediately; files lag by one increment.

**B8 — The gate is tenant-level.** `login_redirect_target()` /
`require_staff_subscribed()` evaluate the *tenant's* subscription. A lapsed tenant
locks all its staff (with the grace behavior already in
[Monetization.php](../admin/include/Monetization.php)); platform admins are
unaffected.

---

## 5. Table inventory (authoritative — all 35 tables)

Classified from `deploy/schema.sql`. `tenant_id` source is the column the
backfill uses to derive the value (all existing rows → tenant #1 in Increment 1).

### 5.1 TENANT — gets `tenant_id` (24 tables)

| Table | Tenant anchor for backfill | Notes |
|---|---|---|
| `tblusers` | — (all → tenant #1) | + `tenant_role` column |
| `tblaccounts` | — (all → tenant #1) | couples; stamped at registration |
| `tblaccounts_detail` | parent `accounts.user_id` | |
| `tblweddingbook` | all → tenant #1 | **the central scoped row** |
| `tblguest` | via `booking_id` | denormalized for list queries |
| `tbl_liquidation` | via `booking_id` | money ledger — highest sensitivity |
| `tbl_installment_plans` | via `booking_id` | |
| `tblchecklist` | via `booking_id` | |
| `events` | via `booking_id` | calendar |
| `calendar` | via `booking_id` | legacy widget table |
| `tbleventbooking` | via `booking_id` | |
| `tblgallery` | via `booking_id` | |
| `tblwedding_websites` | via `booking_id` | public `slug`, globally unique |
| `tblvendors` | via `user_id` | per-tenant marketplace (v1) |
| `tblvendor_packages` | via `vendor_id` | |
| `tblvendor_bookings` | via `vendor_id` / `booking_id` | commission ledger |
| `tblsms_credits` | via `user_id` | tenant wallet in v2 (§8.4) |
| `tblsms_logs` | sender `user_id` | audit trail |
| `tblnotifications` | `user_id` | recipient-scoped |
| `tbl_mail_logs` | recipient | audit trail |
| `tbl_password_resets` | via `user_id` | |
| `tblmpesa_stk_pushes` | `user_id` | payment attempts |
| `tblsubscriptions` | `user_id` → tenant | becomes the tenant's subscription |
| `tblsubscription_payments` | via subscription/user | **missing from the old doc** |

### 5.2 PLATFORM — operator-owned, no `tenant_id` (7 existing)

`tblsubscription_plans`, `tblmpesa_callbacks`, `tblmpesa_callback_log`,
`tbllogin_throttle`, `tblsignup_throttle`, `tblplanner_verifications`,
`tblsession_audits` (gains an *optional* `tenant_id` for forensics only).

> `tblplanner_verifications` and `tblsignup_throttle` were added by v2.4 and were
> absent from the old inventory. They stay platform-owned: verification tokens
> and signup throttles are keyed by email/user, not tenant.

### 5.3 GLOBAL / SHARED — reference data (4 tables)

`tblweddingcategories`, `tbl_features`, `tblorganizer`, `tblpostwedding`.
Operator-owned catalog/marketing; read-only to tenants in v1.

> Rule of thumb: **if showing the row to another planner's staff would be wrong,
> it is TENANT; if pricing/reference/marketing owned by the operator, it is
> PLATFORM or GLOBAL.**

---

## 6. Schema

### 6.1 The registry — `migrations/v3.0_tenants.sql`

```sql
CREATE TABLE IF NOT EXISTS `tbltenants` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(150) NOT NULL,                 -- "Amina Weddings Ltd"
  `slug`            VARCHAR(80)  NOT NULL,                 -- public ref, UNIQUE
  `owner_user_id`   INT UNSIGNED NOT NULL DEFAULT 0,       -- tenant admin (tblusers.id)
  `plan`            VARCHAR(30)  NOT NULL DEFAULT 'starter',
  `status`          ENUM('trial','active','past_due','suspended') NOT NULL DEFAULT 'trial',
  `trial_ends_at`   DATE         NULL,
  `county`          VARCHAR(60)  NULL,
  `phone`           VARCHAR(20)  NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_slug` (`slug`),
  KEY `idx_tenant_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.2 Per-table `tenant_id` — idempotent, portable

MariaDB supports `ADD COLUMN IF NOT EXISTS`; stock MySQL 5.7/8.0 does not. Ship
the information_schema-guarded variant already used by
[`migrations/v2.1_phase1_hardening.sql`](../migrations/v2.1_phase1_hardening.sql)
(a stored procedure that checks `information_schema.COLUMNS` then `ALTER`s). One
block per TENANT table:

```sql
ALTER TABLE `tblweddingbook`
  ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `booking_id`;
ALTER TABLE `tblweddingbook`
  ADD KEY `idx_weddingbook_tenant` (`tenant_id`);
```

Default `0` = platform/unassigned. `tenant_id` is **never** used as a foreign key
with `ON DELETE CASCADE` — deleting a tenant must be an explicit, reviewed
operation, not a cascade that can silently remove a business's history.

### 6.3 `tblusers` role column

```sql
ALTER TABLE `tblusers`
  ADD COLUMN `tenant_role`
    ENUM('platform_admin','tenant_admin','planner','vendor') NOT NULL DEFAULT 'planner'
    AFTER `access_level`;
```

Backfill mapping (Increment 1, in `scripts/backfill_tenants.php`):

| `access_level` | `tenant_role` |
|---|---|
| `2` | `platform_admin` |
| `1` | `tenant_admin` for tenant #1's owner, else `planner` |
| `0` | `vendor` |

`access_level` stays authoritative for the existing gates (`is_staff()`,
`is_vendor()`); `tenant_role` is additive metadata so nothing breaks when this
column appears.

### 6.4 The mechanical guard — `scripts/audit_tenancy.php`

The repo already runs static audits (`composer code-audit` →
[scripts/audit_sql_columns.php](../scripts/audit_sql_columns.php).
Add a fourth sweep, wired into the same composer script:

- walk every SQL string passed to `Database::*` / `PDO::prepare`,
- if its target table is in `Tenant::TABLES`, assert it either mentions
  `tenant_id`, or is inside a `Tenant::asPlatform()` block (detected by file/
  function heuristic),
- exit non-zero listing offenders.

This turns "did someone forget the boundary?" into a build failure rather than a
code-review hope.

---

## 7. The scoping layer (concrete)

### 7.1 `admin/include/Tenant.php` (new)

```php
final class Tenant
{
    /** Tables that carry tenant_id. Single source of truth for scoping + audit. */
    public const TABLES = [ /* 24 names from §5.1 */ ];

    /** Active tenant for this process. 0 = platform / unscoped. */
    public static function id(): int;
    public static function set(int $id): void;
    public static function isPlatform(): bool;

    /** Read $_SESSION in web context; no-op in CLI unless set(). */
    public static function fromSession(): void;

    /** Run a callable at cross-tenant scope (audited). Returns the callable's value. */
    public static function asPlatform(callable $fn): mixed;

    /** Tenant ids to iterate (cron). Excludes suspended. */
    public static function activeIds(): array;

    /** Create a tenant + stamp its owner. Transactional. Returns the new id. */
    public static function provision(string $name, int $ownerUserId, string $plan): int;

    /** True when $table is tenant-owned AND enforcement is on. */
    public static function owns(string $table): bool;

    /** Append "AND <alias>.tenant_id = :__tid" (SELECT/UPDATE) or throw in dev. */
    public static function scope(string $sql): string;
}
```

Enforcement is behind one flag so the whole layer can be switched off in one
place: `WPMS_TENANCY_OFF=1` → `owns()` returns false, `scope()` is a no-op,
`id()` returns 0. **Increment 1 ships with enforcement OFF (default).** Increment 2
flips the default to ON for production once the tests cover it.

### 7.2 C1 — model reads (`DB_Object::find_by_query`)

One edit at the read funnel, so every model finder is covered at once:

```php
public static function find_by_query(string $sql, array $params = []): array
{
    global $db;
    if (Tenant::owns(static::$db_table)) {
        $sql = Tenant::scope($sql);            // adds the predicate + param
        $params[':__tid'] = Tenant::id();
    }
    // …existing prepare/execute/fetch loop unchanged…
}
```

### 7.3 C3 — model writes (`create` / `updateBy` / `delete`)

```php
public function create(): bool
{
    $props = $this->properties();
    if (Tenant::owns(static::$db_table) && !Tenant::isPlatform()) {
        $props['tenant_id'] = Tenant::id();     // stamped, page cannot forget it
    }
    // …existing INSERT build unchanged…
}
```

`updateBy()` and `delete()` additionally append `AND tenant_id = :__tid` when
enforced, so a stale in-memory object cannot update across a boundary.

### 7.4 C2 — direct SQL (pages & scripts)

Add scoped wrappers rather than rewriting every call:

```php
$db->fetchOneScoped(string $sql, array $params = []): ?object;   // scopes, then calls fetchOne
$db->fetchAllScoped(string $sql, array $params = []): array;
```

Callers keep writing their normal table SQL; the wrapper injects the predicate.
Direct `$db->fetchOne()`/`execute()` calls that touch a TENANT table remain legal
only on the platform path (`Tenant::asPlatform()`), and the audit (§6.4) flags
the rest during the per-table cutover.

### 7.5 Row-level authorization — `assert_booking_access()`

The only way a page touches a booking; replaces the copy-paste ownership checks
(and the subtle `$user_id !== $_SESSION['id']` query-string guard in
`client_budget.php` and siblings), so no page can omit it:

```php
function assert_booking_access(int $booking_id): object
{
    $booking = Booking::find_by_booking_id($booking_id);   // C1 → scoped
    if (!$booking) { redirect_to('client.php'); exit; }

    if (Tenant::isPlatform() || is_staff()) return $booking;       // same-tenant staff
    if ((int) $booking->user_id === (int) $_SESSION['id']) return $booking;
    redirect_to('client.php'); exit;
}
```

---

## 8. Touchpoints that need explicit care

### 8.1 Money
- **Subscriptions become tenant-level.** `Subscription::get_active($user_id)` →
  add `Subscription::get_active_for_tenant($tenant_id)`; `login_redirect_target()`
  and `require_staff_subscribed()` gate on the tenant's subscription (rule B8).
- **M-Pesa STK/C2B stay PLATFORM ledgers.** `MpesaStk::fulfil()` must stamp the
  resulting subscription/deposit row through C3 so `tenant_id` is correct; the
  deposit path already resolves the booking, which carries the tenant.
- **SMS credits** stay per-user wallets in v1 but gain `tenant_id`; v2 moves to a
  tenant wallet with admin allocation. `SMS::deduct_credits()` gets the
  tenant-balance check behind its own flag.

### 8.2 Notifications, SMS, email
`Notification::create_for_staff()` / `create_for_vendor()` / `create_for_couple()`
and `SMS::send()` resolve recipients by an id and are unchanged (v2.13 also records
WHICH account table the id belongs to — `recipient_type` — which is orthogonal to
tenancy: the type says user-vs-couple, `tenant_id` would say whose tenant). Bell
lists are read through C1 (→ scoped). Courtesy/deadline SMS keep charging the
assigned planner, who is same-tenant by construction. The `LoginThrottle` lockout
SMS is platform-level and unchanged.

### 8.3 Public surface
`site.php?slug=…` and `rsvp.php?token=…` require no session. They resolve the
tenant from the row, then `Tenant::set($row->tenant_id)` for the request
(rule B5). `register_client()` in
[Helper.php](../admin/include/Helper.php) gains a `$tenant_id` parameter
(defaulting to the resolved context) so the public hero form stamps couples into
the right tenant; v1 keeps the operator's tenant #1 as the default funnel.

### 8.4 Uploads
Deferred to Increment 5 (rule B7): rows get `tenant_id` now; files move to
`upload/<kind>/t<tid>/…` when the marketplace work lands.

---

## 9. First shippable increment — Increment 1 (behavior-preserving)

**Goal:** land the data model, the registry, the backfill and the verification —
with **zero change to any query or page** — so the risky query layer (Increment 2)
is a small, testable diff on top of data that is already correct.

**Why this is the right first slice:** it is purely additive (new table, new
nullable-defaulted columns), reversible with one `ALTER … DROP COLUMN` per table,
and it establishes the compatibility guarantee the rest depends on. It cannot leak
data because it does not filter anything yet.

### Deliverables

| File | Change |
|---|---|
| `migrations/v3.0_tenants.sql` | `tbltenants` (§6.1) + `tenant_id` + indexes on the 24 TENANT tables + `tenant_role` (§6.2/6.3). Idempotent, information_schema-guarded. |
| `scripts/backfill_tenants.php` | Create tenant #1 (`slug='everafter-hq'`, `status='active'`, no trial); stamp `tenant_id = 1` on every row of every TENANT table; set `tenant_role` per §6.3; **verify** and exit non-zero if any TENANT row is still `tenant_id = 0` (same philosophy as `demo_reset.php`). Supports `--dry-run`. |
| `admin/include/Tenant.php` | The class from §7.1 — **enforcement default OFF**; `owns()` returns false until Increment 2. `activeIds()`, `provision()`, `fromSession()`, `asPlatform()` implemented; `scope()` present but inert. |
| `admin/include/config.php` | Document/parse `WPMS_TENANCY_OFF` (default `1` in this increment) via the existing `wpms_db_env`-style reader. |
| `admin/include/init.php` | `require_once` `Tenant.php`; call `Tenant::fromSession()` after the session starts. No gate changes. |
| `scripts/demo_reset.php` | Append `migrations/v3.0_tenants.sql` to the install chain. |
| `tests/TenantBackfillTest.php` | New. See acceptance. |
| `docs/MULTI_TENANCY_PLAN.md` | This document. |

### Explicitly NOT in Increment 1
No changes to `Database.php`, `db_object.php`, any model, any page, the
subscription gate, or the cron scripts. No `WHERE tenant_id` anywhere yet.
`audit_tenancy.php` is written but not yet wired into `code-audit` (it would fail
on the un-migrated callers; it becomes a gate in Increment 2).

### Acceptance criteria

1. `php scripts/backfill_tenants.php --dry-run` prints the planned tenant #1 and
   per-table row counts, writes nothing.
2. `php scripts/backfill_tenants.php` on the live DB exits 0; a re-run is a no-op.
3. `SELECT COUNT(*) FROM <each TENANT table> WHERE tenant_id = 0` is **0** for all
   24 tables.
4. `tblusers.tenant_role` is populated: exactly one `platform_admin` per
   `access_level = 2` row, `vendor` for `access_level = 0`, the rest
   `tenant_admin`/`planner`.
5. `php scripts/demo_reset.php --yes` still completes (chain includes v3.0).
6. **`composer test` stays 256/264-green** (the 8 known seed-age failures aside) —
   nothing should change because nothing is filtered.
7. `composer lint` clean.

### Revert
Drop `tbltenants`, drop the 24 `tenant_id` columns/indexes and `tenant_role`
(all nullable/defaulted, so removal is non-destructive), delete `Tenant.php`'s
`init.php` include. No data is altered, so revert is exact.

### Effort
~1 day: SQL + backfill script + one test. No product decisions required.

---

## 10. Follow-on increments

| # | Scope | Enforces | Acceptance |
|---|---|---|---|
| **2** | Scoping layer on the 3 highest-sensitivity tables: `tblweddingbook`, `tblguest`, `tbl_liquidation`. Implement C1/C3 hooks + `Tenant::scope()`; flip `WPMS_TENANCY_OFF` default to `0`; wire `audit_tenancy.php` into `code-audit` for those tables. | B1, B3 | `TenantScopingTest`: as tenant-A, reads for tenant-B ids return empty and writes are refused. Legacy suite green with `WPMS_TENANCY_OFF=1`. |
| **3** | Convert the remaining TENANT models at C1/C3; page cutover to `assert_booking_access()`; extend `audit_tenancy.php` to all 24 tables. | B1–B3 | `assert_booking_access()` replaces the per-page ownership checks; audit clean. |
| **4** | Money: tenant-level subscription gate (`login_redirect_target`, `require_staff_subscribed`, `Subscription::get_active_for_tenant`); STK fulfilment stamps tenant. | B8 | `SubscriptionGateTest`: lapsed tenant locks all its staff; platform admin unaffected. |
| **5** | Public surface (`site.php`/`rsvp.php` tenant resolution, `register_client($tenant_id)`) + per-tenant upload paths. | B4–B7 | `PublicSurfaceTest`: slug/token resolve across tenants without a session and leak nothing else. |
| **6** | Platform console: tenants list + audited `Tenant::asPlatform()` cross-tenant views/impersonation. | B2 | Cross-tenant reads appear in `tblsession_audits`. |
| **7** | Cron: iterate `Tenant::activeIds()` in the five reminder scripts. | B6 | Reminder tests pass per-tenant. |

---

## 11. Provisioning a new tenant

```php
Tenant::provision($name, $ownerUserId, $plan);   // transactional
//   INSERT tbltenants (name, slug, owner_user_id, plan, status='trial', trial_ends_at=+14d)
//   UPDATE tblusers   SET tenant_id = :tid, tenant_role = 'tenant_admin' WHERE id = :owner
//   INSERT tblsms_credits (user_id = :owner, credits = 10)    -- welcome bundle, tenant_id stamped
```

Slug = `strtolower(slugify($name))` + collision suffix; also the future public
wedding-page namespace (§8.3). This is the seam the self-serve planner signup
(`register_planner()` / `verify_planner.php`) plugs into: verification calls
`Tenant::provision()` instead of `Subscription::start_trial()` for a solo planner.

---

## 12. Test plan (PHPUnit, `tests/`)

- `TenantBackfillTest` (Increment 1): zero residual `tenant_id = 0`; `tenant_role`
  mapping; `--dry-run` writes nothing.
- `TenantScopingTest` (Increment 2): cross-tenant reads empty / writes refused;
  `asPlatform()` succeeds and audits.
- `TenancyAuditTest`: `scripts/audit_tenancy.php` exits 0 on the migrated tree and
  non-zero on a deliberately-unscoped fixture query.
- `PublicSurfaceTest` (Increment 5): slug/RSVP token resolve without a session.
- `SubscriptionGateTest` (Increment 4): lapsed tenant locks all staff.
- **Compatibility guarantee:** the existing suite stays green with
  `WPMS_TENANCY_OFF=1` at every increment — the escape hatch is tested, not just
  documented.

---

## 13. What deliberately changes, and what is out of scope

**Deliberate product changes**
- Planner→client stays 1:1 inside a tenant; sharing a client means sharing a
  tenant (agency/partnership).
- A vendor belongs to one tenant in v1; multi-tenant vendor membership
  (`tblvendor_memberships`) is noted, not built.
- `Revenue` reporting splits: operator dashboard = all tenants; each tenant gets
  its own scoped view. Same queries, different scope.

**Out of scope (recorded for the roadmap)**
- Shared/cross-tenant vendor marketplace.
- Per-tenant subdomains (`amina.everafter.co.ke`) — cookie/session scoping work.
- Row-level DB enforcement (per-tenant views/proxies) — the C1–C3 choke points
  plus the audit suffice and stay debuggable.
- Dedicated tenant DBs (silo) for enterprise/white-label buyers — maps onto a
  per-tenant deployment of the same codebase later.

---

## 14. Summary checklist

1. **Increment 1:** `migrations/v3.0_tenants.sql` (registry + 24 `tenant_id` +
   `tenant_role`, idempotent) → `scripts/backfill_tenants.php` (tenant #1 absorbs
   everything, verifies zero residuals) → `Tenant.php` (enforcement OFF) →
   `TenantBackfillTest` → `demo_reset` chain. **Behavior-preserving.**
2. **Increment 2:** C1/C3 scoping hooks + `Tenant::scope()` on
   `tblweddingbook`/`tblguest`/`tbl_liquidation`; enable enforcement; wire
   `audit_tenancy.php` into `code-audit`.
3. **Increment 3:** remaining models + `assert_booking_access()` page cutover.
4. **Increment 4:** tenant-level subscription gate + STK stamping.
5. **Increment 5:** public surface + per-tenant uploads.
6. **Increment 6:** platform console (audited cross-tenant).
7. **Increment 7:** cron iterates tenants.
8. Every increment: `composer lint` + `composer test` green, legacy suite green
   under `WPMS_TENANCY_OFF=1`.
