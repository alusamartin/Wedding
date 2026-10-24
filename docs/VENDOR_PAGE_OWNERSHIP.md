# Vendor-facing pages — listing-ownership audit

Date: 16 October 2026. Scope: **every page a signed-in vendor account can reach**
(`tblusers`, `access_level = 0`), and the question asked of each one: *can a request
parameter make it read or write a listing the session does not own?*

Companion to [`COUPLE_PAGE_OWNERSHIP.md`](COUPLE_PAGE_OWNERSHIP.md), which audits the
same property from the couple's side. Verdict: **no reachable leak found**; the surface
was carrying the *quiet* version of the defect — ownership proven once when the listing
was resolved, and then carried by statements that said nothing about whose row they
were writing, plus one id list interpolated into SQL instead of bound. Both are fixed
and both are now forbidden by a source sweep.

---

## 1. The rule

Same principle as the couple side, one table: a vendor session is a `tblusers` row, and
`tblvendors.user_id` names it. Both sides of the comparison come from the **same**
table, which is why the cross-table id confusion of §2 in the couple report cannot
happen here — what can happen is a page that resolves "the vendor", tests it for null,
calls that a guard, and then writes without re-stating ownership.

One statement, in one place — `admin/include/Helper.php`:

| Shared piece | Meaning |
| --- | --- |
| `vendor_listing_visible_to_session($listing)` | The session is a **vendor** (`is_vendor()`: `account_type = 'staff'` and `privilege_level = 0`) and this listing's `user_id` is the session id. A missing listing is nobody's. |
| `current_vendor_listing($user_id, $listings)` | The listing being **viewed**: honours `?vendor_id` only when the account really owns it (via `Vendor::resolve_for_session()`), otherwise the account's own default. |
| `bound_id_list($ids)` | The one place an id list becomes SQL: named placeholders per id, de-duped, non-positive dropped, empty in → empty fragment out. |
| `Vendor::all_for_user($user_id)` | Every listing the account owns — the span the ledger and the switcher work over. |

A vendor account can own **more than one** listing (the Kenyan seed ships one owner with
two), so the unit of ownership is the **set** of listings the account owns, not a single
row: an enquiry sent to a vendor's second business is still an enquiry to that vendor.

---

## 2. Finding (fixed): ownership proven at resolution, then not restated

**What.** Every vendor page opens by resolving the listing it is acting for. Before this
pass a vendor page would load its listing (or one package row) through an
ownership-filtered query, treat a non-null result as proof of ownership, and then run its
`UPDATE`/`DELETE` with only the row id in the `WHERE` clause:

```php
$row = $stmt->fetch(PDO::FETCH_OBJ);        // SELECT ... WHERE vb.id = :id AND vb.vendor_id = :vid
if ($row === null) { /* "not yours" */ }    // the check everyone read as "the guard"
...
$db->prepare('UPDATE tblvendor_bookings SET status = :st WHERE id = :id')->execute(...);  // ← says nothing
```

That is correct only for as long as the id in the second statement is the id in the first.
The difference between *"the row we loaded was ours"* and *"the row this statement
targets is ours"* is exactly the distance a refactor closes, and nothing in the source
looked wrong.

**Same pass, second shape.** The vendor ledger's scope was built by mapping ids to
strings and interpolating them into an `IN (...)`:

```php
$ids = implode(',', array_map('intval', $vendor_ids));
... "WHERE vendor_id IN ({$ids})"
```

Safe only while every id was cast first — a property no reader can see from the call
site, and one that `bound_id_list()` makes unnecessary.

**Fixed.**

* `admin/vendor_home.php` — both booking transitions (confirm, and every other status)
  now end `WHERE id = :id AND vendor_id IN ({$ownedIn})`, with `$ownedIn`/`$ownedParams`
  from `bound_id_list()` over `Vendor::all_for_user()`. The ledger read, the status
  tally and the row re-check before a write all use the same bound scope.
* `admin/vendor_packages.php` — add/toggle/delete/edit each carry `AND vendor_id = :vid`,
  re-proving ownership in the statement itself rather than trusting `$own_package()` one
  step earlier.
* `admin/vendor_profile.php` — the profile `UPDATE` carries `AND user_id = :uid`, and so
  does the **logo** `UPDATE`, which previously relied on the listing having been resolved
  safely a moment before.
* `admin/vendor_enquiries.php` (the staff ledger) — its active-package pool for the
  re-price control is now bound through `bound_id_list()` instead of an interpolated list.
* `admin/include/Guest.php` — `mark_invited()` uses the same binder, so the shared
  builder has one implementation rather than two.
* Every vendor page now gates through `vendor_listing_visible_to_session()` rather than a
  bare null check on its own resolved listing.

**Measured.** Each claim above was verified against the database, not read off the source
(§4).

---

## 3. Inventory: every vendor-facing page

"Vendor-facing" = reachable by a vendor account and acting on a listing.

| Page | Gate | Request input | Ownership decision |
| --- | --- | --- | --- |
| `vendor_home.php` | `require_vendor()` | `vendor_id` (switcher), `booking_row_id` (write) | `vendor_listing_visible_to_session()` for the listing; ledger + tally + both transitions scoped by `vendor_id IN (bound id list)`; a `?vendor_id` the account does not own is ignored, not honoured |
| `vendor_packages.php` | `require_vendor()` | `vendor_id`, `package_id` (write) | `$own_package()` resolves within the owned scope **and** every statement repeats `AND vendor_id = :vid` |
| `vendor_profile.php` | `require_vendor()` | `vendor_id` | profile `UPDATE ... AND user_id = :uid`; logo `UPDATE ... AND user_id = :uid` |
| `vendor_register.php` | `!is_staff() && !is_vendor() → ../` | — | inserts `tblvendors.user_id = $_SESSION['id']`; the package it seeds is created for the listing just inserted |
| `vendors.php` (marketplace) | staff **or** vendor + paywall gate | `category`, `vendor_id` | read-only directory of approved listings; vendor sessions reach it for the same marketplace their couples see; the only writes are operator-only (`is_super_admin()`) approval/placement |
| `vendor_enquiries.php` (staff ledger) | `require_staff_subscribed()` | `deal_id`, `features_id`, `package_id` | staff authority is global by design; the *function* scope is restricted to the deal's own wedding (`BookingEvent::function_belongs_to`), and the package pool to vendors on the page (bound id list) |
| `notifications.php` | any signed-in user | `id` | scoped to the session id — see §5 |

Couple-side vendor pages (`client_vendors.php`, `client_vendor_enquiry.php`) are audited
in the couple report; their vendor and package ids are checked there.

### Checked and not defects

* `vendor_home.php?vendor_id=<someone else's>` — honoured nowhere: the switcher is
  rendered from `$myListings`, and `resolve_for_session()` returns the requested listing
  only when `user_id` matches the session, otherwise the account's own default.
* `vendors.php` approval/placement writes — operator-only by design
  (`is_super_admin()`), and they notify the affected vendor's owner.
* `vendor_register.php` — self-registration writes `is_approved = 0`, so a new listing is
  invisible in the marketplace until an operator approves it; approval notifies the owner.
* The marketplace's couple-facing enquiry handler (`vendors.php` "Send Inquiry") writes
  nothing at all — tracked in sweep D as a product decision, not an ownership defect.

---

## 4. Verification performed

| Check | Result |
| --- | --- |
| Predicate on a **real** vendor session (not a synthetic one): own listing vs. another vendor's, and a planner's session against a vendor listing | own `true`; foreign `false`; planner `false`; signed-out `false` |
| Scope proven against the SQL, in a **rolled-back transaction**, on both the dev DB and a freshly seeded scratch DB: insert one probe booking for vendor A and one for vendor B, then run each side's ledger | A's ledger returns only `vendor_id = 200`; B's only `201`; the other's probe **absent in both directions** |
| The ownership re-check every write runs, aimed at the foreign probe id | refused in both directions |
| `Vendor::resolve_for_session(owner, <foreign listing>)` | returns the account's **own** listing, never the foreign one |
| Logo `UPDATE` clause (the one path no test covers) | owner session → 1 row written; foreign account → **0 rows**; rolled back, stored value unchanged |
| `VendorSelfServiceTest` — vendor-only gate (staff/anonymous/client bumped with no HTML leaked in the redirect body), own vs. foreign booking visibility, foreign transition refused **and** reported to the vendor, own transition + planner notification, foreign package delete refused, own package add/toggle, profile saves own row only (HTTP, **dev DB**) | 7 tests, 32 assertions, pass |
| `VendorOwnershipRuleTest` — predicate matrix, the binder's contract, and four source sweeps | 8 tests, 31 assertions, pass |
| Full suite, freshly seeded scratch DB | **416 tests, 2942 assertions, pass** |
| `composer lint` | 111 files clean |
| `composer code-audit` (sweeps A–G) | all clean, `findings: 0` |
| Fixture residue on the dev DB after these runs | 0 probe bookings / packages; 2 `[vstest]` packages left behind by an **earlier** run were removed |

**One failure seen, and what it was.** A full-suite run reported
`VendorSelfServiceTest:342` — "the vendor must NOT see another vendor's booking" —
asserting that the page body contained the foreign id `214`. It was a **false positive
of the assertion, not a leak**: the check was a bare substring search over ~20 KB of
HTML, and two of that page's other numbers can spell the probe's digits by chance — a
64-hex-digit CSRF token (drawn once per session and written into *every* form on the
page, hex letters and digits together), a Kenyan phone such as `+2547214…`, or a longer
row id. The scoped SQL was re-verified directly (the rollback probe above) and the
assertion now blanks the token fields out and matches the id as a **whole** number, which
still fails loudly for a genuinely rendered foreign row. The helper was falsified in both
directions (6/6 designed cases: rendered row → `true`, `1214` / `+254721412345` /
token-spelled `214` → `false`). The same run was also issued concurrently with a second
test process against the same MySQL server; every figure in this table comes from
sequential runs.

---

## 5. Found here, fixed in v2.13: the feed that was keyed by a bare id

`tblnotifications.user_id` was a **bare numeric id in one shared table**, and every read
was scoped by that number alone — so a vendor whose `tblusers.id` equalled a couple's
`tblaccounts.user_id` could read that couple's notification feed (and a vendor notices
files: `admin/vendor_enquiries.php`, `admin/vendor_home.php`, `admin/vendors.php` all
notify a `tblusers` id, sharing the space with the couple feeds).

**Closed** in v2.13: `recipient_type` on both `tblnotifications` and `tbl_mail_logs`
(`'user'` = `tblusers.id`, `'account'` = `tblaccounts.user_id`, `'none'` = no account),
written through `Notification::create_for_staff()/create_for_vendor()/
create_for_couple()` and required by every read. The vendor pages above now say which
side a recipient is on where the recipient is chosen. Both directions are proven in
`tests/NotificationRecipientScopeTest.php`; see §5 of the couple report for the shape of
the fix.

---

## 6. How the invariant is kept

* `tests/VendorOwnershipRuleTest.php`
  * the **predicate matrix** — own listing, an account's own second listing, another
    vendor's listing, planner, super admin, couple, signed out, and the number
    coincidence between the two account tables;
  * the **binder contract** — de-dupes, drops non-positive ids, casts, yields one named
    placeholder per id, and yields an empty fragment for an empty list;
  * a **discovery** sweep: every `admin/*.php` calling `require_vendor()` must call
    `vendor_listing_visible_to_session()` — a page added later is covered without editing
    the test;
  * a **write** sweep: every `UPDATE`/`DELETE` a vendor page runs against a
    `tblvendor*` table must name `vendor_id` or `user_id` in that statement;
  * an **id-list** sweep: the vendor pages may not `implode()` an id list; the pages that
    scope by a list must call `bound_id_list()`;
  * a **repo-wide** sweep: the removed idiom
    (`implode(',', array_map(`) may not return anywhere in `admin/`, `admin/include/` or
    the public root.
* `tests/VendorSelfServiceTest.php` — the behavioural sweep over the surface, over HTTP,
  including the foreign-id writes and the "that booking is not yours" report a vendor
  must actually see.
