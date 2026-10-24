# Couple-facing pages — booking-ownership audit

Date: 16 October 2026. Scope: **every page a signed-in couple can reach**, and the
question asked of each one: *can a request parameter make it act for a booking the
session does not own?*

Verdict: **one real vulnerability found and fixed** (a cross-table id confusion that
let a vendor account open and write to a couple's booking). Every other page was
already correct; the fix moved the rule into one shared pair of predicates so no
page can quietly re-implement it.

---

## 1. The rule

One statement, in one place — `admin/include/Helper.php`:

| Predicate | Meaning |
| --- | --- |
| `is_client_account()` | The session is a **couple** (`tblaccounts`), not a planner, admin or vendor (`tblusers`). |
| `booking_visible_to_session($booking)` | staff → any wedding (preview/assist); a couple → only the booking their own account row owns; **a missing booking → nobody**. |
| `may_open_couple_page($booking, $user_id)` | The above **plus** the `?user_id` shape check: staff may carry any couple's id, a couple only their own. |

Every couple page and couple-scoped endpoint calls one of these instead of comparing
ids itself.

---

## 2. Finding (fixed): a vendor could open a couple's booking

**What.** Couples live in `tblaccounts` (primary key `user_id`); planners, admins and
vendors live in `tblusers` (primary key `id`). Two tables, two independent
auto-increment sequences — 200 in `tblusers` and 200 in `tblaccounts` are different
people. A **vendor** is a `tblusers` row with `access_level = 0`, so `is_staff()` is
`false` for it, and the guard every couple page hand-rolled was:

```php
if (!is_staff()) {
    if (!$booking || (int) $booking->user_id !== (int) $_SESSION['id'] || $user_id !== (int) $_SESSION['id']) {
        redirect_to('client.php');
    }
}
```

That compares a `tblaccounts` id (the booking's owner) against a `tblusers` id (the
vendor's session id) as if they were one namespace. A vendor whose id happened to
equal a couple's account id was therefore *that couple* to all of these pages.

**Measured, before the fix.** A throwaway vendor row carrying the couple's account id,
signed in through the ordinary `login.php` door, then:

```
200 /admin/client_guests.php?booking_id=200&user_id=200               LEAKS couple names
200 /admin/client_budget.php?booking_id=200&user_id=200               LEAKS couple names
200 /admin/client_checklist.php?booking_id=200&user_id=200            LEAKS couple names
200 /admin/client_deposit.php?booking_id=200&user_id=200              LEAKS couple names
200 /admin/client_manage_account_details.php?booking_id=200&user_id=200  LEAKS couple names
200 /admin/client_website.php?booking_id=200&user_id=200              LEAKS couple names
200 /admin/sheet_guests.php?booking_id=200&user_id=200                LEAKS couple names
200 /admin/sheet_venue.php?booking_id=200&user_id=200                 LEAKS couple names
```

Read access is measured above; the write endpoints used the identical comparison, so
they were authorized the same way — the after-fix run asserts all nine of them are
now refused, with the booking's state compared before and after.

**Measured, after the fix.** Same session, same URLs: `302 → client.php` on all eight
pages, and `302 → client.php` on every write endpoint, with no row on the booking
changed.

**Why it was reachable at all.** `tblusers` and `tblaccounts` id ranges overlap by
construction (couples sign up into one sequence, planners/vendors into the other), and
nothing in the code ever proved which table a session's id came from. The seed's two
ranges do not collide today, which is exactly why this stayed invisible.

---

## 3. Inventory: every couple-facing page

"Couple-facing" = reachable by a couple (gated by the couple-friendly paywall, not
bounced to staff) and resolving a booking or user from the request.

| Page | Request input | Ownership decision |
| --- | --- | --- |
| `client.php` | none (session) | queries scoped to `$_SESSION['id']`; vendors are sent to their own home |
| `client_budget.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_checklist.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_guests.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_manage_account_details.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_vendors.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_vendor_plan.php` (read-only) | `booking_id`, `user_id` | `may_open_couple_page()`; writes nothing, so opening it cannot change what a vendor was promised |
| `client_proposal.php` (write) | `booking_id`, `user_id`, `option_id`, `action` | `may_open_couple_page()`, then `Proposal::for_couple()` re-checks the row belongs to that booking **and** that couple; the option is resolved inside the proposal, so a posted `option_id` from another wedding finds nothing, and a state the couple cannot move (`locked`, `declined`) is refused rather than overwritten |
| `client_website.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_deposit.php` | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_guest_save.php` (write) | `booking_id`, `user_id`, `guest_id` | `may_open_couple_page()` **and** the guest row re-checked against the booking |
| `client_checklist_toggle.php` (write) | `booking_id`, `user_id`, `item_id` | `may_open_couple_page()` **and** `Checklist::toggle()` scopes its `UPDATE` by booking |
| `client_installment_plan.php` (write) | `booking_id`, `user_id` | `may_open_couple_page()` |
| `client_vendor_enquiry.php` (write) | `booking_id`, `user_id`, `vendor_id`, `package_id` | `may_open_couple_page()`; vendor must be approved, package must belong to that vendor |
| `event_booking_save.php` (write) | `booking_id` (POST/GET) | `booking_visible_to_session()`; iterates the booking's own functions, so a form cannot invent one |
| `receipt.php` | `id` (a liquidation row) | `booking_visible_to_session()` on the row's booking — the query string is never the owner |
| `sheet_guests.php`, `sheet_venue.php` | `booking_id` | `sheet_boot()` → `booking_visible_to_session()` |
| `notifications.php` | `id` (a notification) | scoped to the session id — see §5 |

Staff-only pages that also read `user_id` (`budget*.php`, `guest_*.php`, `events.php`,
`task_*.php`, `rsvp_*.php`, `sms_center.php`, `client_edit.php`, `client_add.php`,
`client_assign.php`) call `require_staff_subscribed()`, which bounces a couple to `../`
before the page body runs, so a couple cannot reach them at all.

### Checked and not defects

* `package_detail.php` — reads a request `booking_id`, but on the public sign-up page
  that value is the **package** (a `Category`) id travelling through the form;
  `register_client()` turns it into `wedding_type`. No booking is involved.
* `rsvp_mark_invited.php`, `rsvp_remind_bulk.php`, `rsvp_wa_queue.php` — compare a
  posted `user_id` with the booking's owner, but each is staff-gated (`!is_staff()`
  bounce / `require_staff_subscribed()`), so only the documented staff "assist" path
  reaches them.
* `rsvp.php` (guest token), `site.php` (published website by slug), `wedding_details.php`
  (a published love story), `gallery.php`, `pricing.php` — public by design, no session
  identity involved.

---

## 4. Verification performed

| Check | Result |
| --- | --- |
| Exploit reproduction, before the fix (scratch DB, HTTP) | 8/8 pages returned 200 with the couple's data |
| Same run after the fix | 8/8 pages `302 → client.php` |
| `CoupleBookingOwnershipTest` — every couple page, both sheets, all 7 couple write endpoints in 13 request shapes, foreign-booking and colliding-vendor sessions (HTTP, against the **dev DB**) | 23 tests, 201 assertions, pass |
| `CoupleOwnershipRuleTest` — predicate matrix + source sweep | 8 tests, 38 assertions, pass |
| Full suite (freshly seeded scratch DB) | **408 tests, 2911 assertions, pass** |
| `composer lint` | 111 files clean |
| `composer code-audit` (sweeps A–G) | all clean |
| Seeded couple + seeded staff over HTTP on the dev DB, every couple page | 200 everywhere, no fatals |
| Fixture residue on the dev DB after the sweep | 0 rows in every probe query |

The tests own what they assert: the two probe couples are created through the
application's own `register_client()`, the colliding vendor row is created and deleted
by the test, and the cleanup is verified above.

---

## 5. Found here, fixed in v2.13: the feed that was keyed by a bare id

`tblnotifications` rows were keyed by a **bare numeric `user_id`** in one shared table,
and every read (`Notification::unread_count/latest/count_for`, `mark_read`,
`mark_all_read`) was scoped by that number alone. Vendors *do* receive notifications
(`admin/vendor_enquiries.php`, `admin/vendor_home.php` create them with a `tblusers`
id), couples receive them with a `tblaccounts` id — so the same collision let a vendor
whose id equals a couple's account id read that couple's notification feed and mark it
read. The email leg had the same shape (it looked the address up in `tblusers` first,
then fell back to `tblaccounts`), so the couple's message could also be *sent* to the
staff address.

**Closed** by `migrations/v2.13_notification_recipients.sql` and
[admin/include/Notification.php](../admin/include/Notification.php): both tables now
carry `recipient_type` (`'user'` = `tblusers.id`, `'account'` = `tblaccounts.user_id`,
`'none'` = no account behind the row, with `user_id` 0), written from the account that
the caller names — `create_for_staff()`, `create_for_vendor()`, `create_for_couple()` —
and required by every read, which now takes the pair instead of the number. The email
lookup reads exactly one table, the one the type names; a recipient with no address on
file still gets no outbox row, as before.

Proven both ways in `tests/NotificationRecipientScopeTest.php`, which builds the
collision deliberately — a probe `tblusers` account and a probe `tblaccounts` couple
sharing one number that is free in both tables — and then shows each of them holding
their own feed and only their own, and refusing the other's row, at the model and
through `admin/notifications.php` over HTTP. The same class sweeps the source: no page
under `admin/` or the public root may name either feed table, and none may call the
untyped `create()`.

This was the last piece of the two id spaces: everything else the two audits touched
compares ids that both come from one table, or names the type.

---

## 6. How the invariant is kept

* `tests/CoupleOwnershipRuleTest.php`
  * the predicate matrix — staff/super admin/couple/vendor/signed-out, against an owned
    booking, someone else's, and none;
  * a **discovery** sweep: any `admin/*.php` that is couple-gated, reads a booking or
    user id from the request, and does not bounce non-staff away must call the shared
    rule — a page added later is covered without editing the test;
  * a **source** sweep: no file may compare a booking owner id with `$_SESSION['id']`
    again.
* `tests/CoupleBookingOwnershipTest.php` — the behavioural sweep over the whole surface,
  including the manufactured colliding-vendor session and a before/after state
  comparison around every refused write.

The other half of this property — a vendor acting on a listing that is not theirs — is
audited in [`VENDOR_PAGE_OWNERSHIP.md`](VENDOR_PAGE_OWNERSHIP.md), which holds the vendor
pages to `vendor_listing_visible_to_session()` and `bound_id_list()` the same way.
