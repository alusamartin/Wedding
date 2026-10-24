# Planning a wedding as a couple — how you, your planner and your vendors fit together

A plain-English guide for the couple. It answers three questions: **what do I do
in the system**, **what does my planner do**, **what does a vendor do** — and
**where do we meet**. Open [`couple-flow-diagram.html`](couple-flow-diagram.html)
for the same thing as a one-page picture.

---

## 1. The short version

You are the **customer**. The planner is your **account manager inside the
office**. Vendors are **suppliers you choose, but your planner signs the deal**.

```
                 ┌─────────────────── PLATFORM ────────────────────┐
   You ─────────▶│  booking · guest list · budget · checklist ·    │◀──── Vendors
  (couple)       │  calendar · website · notifications · M-Pesa    │  (suppliers)
                 └───────────────────────┬─────────────────────────┘
                                         │
                                   Your planner
                              (staff account, one per wedding)
```

- **You** own the *facts*: the date, the people, the money coming in, the
  preferences.
- **Your planner** owns the *commitments*: the package price, the suppliers,
  the calendar, the reminders — and is the only non-vendor who can turn an
  enquiry into a confirmed booking.
- **Vendors** own *delivery*: they publish packages, respond to your enquiries,
  accept or decline, and get marked complete after the day.
- **The system** does the chasing: notifications, SMS reminders at 60/30/7
  days, M-Pesa verification, and the headcount warnings.

---

## 2. The four accounts (one login door for all)

| Who | Where the account lives | Lands on | Sees |
|---|---|---|---|
| You (couple) | `tblaccounts` (the client table) | `admin/client.php` | Your own wedding only |
| Wedding planner | `tblusers`, `access_level = 1` | `admin/dashboard.php` | Weddings assigned to them, plus unassigned ones |
| Super admin | `tblusers`, `access_level = 2` | `admin/dashboard.php` | Everything; approves vendors |
| Vendor | `tblusers`, `access_level = 0` | `admin/vendor_home.php` | Their own profile, packages and deals |

Couples and vendors both sit at `access_level 0` but in *different tables* —
`Helper::is_vendor()` requires the staff table, so a couple can never be
mistaken for a vendor, and no page ever shows you another couple's data.

---

## 3. The whole wedding, stage by stage

```
REGISTERED ──▶ PROPOSAL ──▶ ASSIGNED ──▶ CONFIRMED ──▶ PLANNING ──▶ WEDDING DAY ──▶ AFTER
 (you fill      (planner      (planner     (package +     (guests,      (running       (gallery,
  the form)      prices it,    takes the     date fixed,    budget,       order)         rating,
                 you choose)   wedding)      vendors booked) vendors)                   showcase)
```

| # | Stage | You do | Your planner does | Vendor / system does |
|---|---|---|---|---|
| 1 | **Register** | Fill the form on the home page: names, phone, email, password, preferred date — and, if you like, the "tell us about the day" block: guests, budget, venue, style, the planner you would like | Nothing yet — you are in the office's *Pending Proposals* queue | System checks the date is free and creates your account + booking + **your proposal** + a draft checklist |
| 2 | **Proposal** | Open **Your Wedding Options** and read at least three priced options side by side, each compared with your budget. Choose one, ask for a change in your own words, or decline | Acknowledges your proposal (you get a bell), prices the options from real vendors, sends them, then locks your choice — which books each vendor and confirms the wedding | System tells you every time the planner moves, blocks a lock that would leave a vendor double-booked, opens a conversation with each booked vendor, and adds a "confirm this vendor" task per supplier to your checklist |
| 3 | **Confirmed** | Get the "date confirmed" bell + SMS; your portal tools unlock | Fills in bride/groom, package (KSh 50k–350k), venue, date, assigns themselves as your organizer | System seeds the Kenyan milestones: ruracio, banns / 21-day registrar notice, eCitizen, committee, final headcount |
| 4 | **Plan** | Build the guest list, watch the budget, tick the checklist (your dashboard shows the same progress bar), open your wedding website | Owns the master calendar, budget and liquidation, headcount per function, venue sheet | System sends RSVP nudges and payment-deadline SMS; flags a vendor package too small for your planned headcount |
| 5 | **Enquire** | Browse the marketplace by category and county, open a package, send a message | Gets a bell with your enquiry and starts negotiating | Vendor gets the enquiry in their ledger as *pending* (plus a best-effort SMS heads-up) |
| 6 | **Book** | Watch the status change: pending → confirmed | Enters the agreed amount and **confirms the deal**; the commission (10%, 15% premium) is recorded | Vendor can accept or decline from their own dashboard; either way you and the planner are notified |
| 7 | **Pay** | Pay by cash, M-Pesa (code recorded) or bank; the budget shows planned vs actual and you download receipts | Records and liquidates each payment, watches the balance and deposit alerts | System verifies the M-Pesa code against what Safaricom reported to the C2B callback |
| 8 | **The day** | Guests already have the website and RSVP links | Runs the running order: ruracio, church, reception, send-off | Vendors deliver the function their deal covers; deals move to *completed* |
| 9 | **After** | Rate each vendor, download the gallery, share your page | Closes the budget, finalises photos, can publish the wedding as a showcase | Ratings feed the marketplace; completed weddings feed the real-weddings blog |

---

## 4. How an enquiry becomes a booking (the one flow worth memorising)

```
You                    Planner                  Vendor
 │  send enquiry  ─────▶│                        │
 │  (status=pending)    │  notified on the bell  │  sees it as pending
 │                      │                        │
 │                      │  negotiates, agrees    │  accepts / declines
 │                      │  the amount            │
 │                      │  confirms the deal ────▶  status=confirmed
 │  🔔 "booked!" ◀──────┤                        │
 │                      │  commission recorded   │
 │                      │  (10% / 15% premium)   │
```

Two rules fall out of that picture:

1. **You cannot confirm a deal yourself.** Only your planner (or the vendor
   accepting it) moves an enquiry to *confirmed*. That is deliberate: the price
   on the deal is what the budget, the receipts and the commission are all
   calculated from, so it has one owner.
2. **Nothing is silent.** Every status change rings the other side's bell, so a
   vendor going quiet is visible to your planner instead of being your problem
   to chase.

---

## 5. Who pays what

| Who | Pays | How much | Where it shows |
|---|---|---|---|
| You | The wedding package | KSh 50,000 – 350,000 | Budget + receipts (`budget.php`, `receipt.php`) |
| You | Vendors you book | Whatever is agreed per service | Recorded on the deal, then on the budget |
| You | Wedding website (optional) | KSh 1,999 per wedding | `admin/client_website.php` |
| Vendor | Platform commission | 10% of each confirmed deal (15% if premium) | `tblvendor_bookings.commission` — never billed to you separately |
| Planner | Their own SaaS subscription | KSh 2,999 – 14,999 / month | Their side of the login, not yours |

Your money is *tracked*; the platform's cut comes from the planner's
subscription and the vendor's commission, so your package price is not inflated
by a hidden booking fee.

---

## 6. What you can open, and what you cannot

| Page | What it is |
|---|---|
| `admin/client.php` | Your hub: booking, package, payment progress, guest count, budget total |
| `admin/client_guests.php` | Guest list with harambee, transport and accommodation flags, priority A–E |
| `admin/client_budget.php` | Budget vs actual, including harambee pledges |
| `admin/client_checklist.php` | Your Kenyan milestone checklist — tick items off; confirming each booked vendor is its own task once your plan is confirmed |
| `admin/client_vendors.php` | The marketplace + the live status of every enquiry you have sent |
| `admin/client_vendor_plan.php` | Everyone doing your wedding, what each is doing, and one total vendor cost |
| `admin/client_proposal.php` | Your three priced options, side by side, with one budget and one Choose button |
| `admin/client_website.php` | Your public wedding page (`site.php?slug=…`) |
| `notifications.php` | The bell — confirmations, vendor updates, payment alerts |

Refused for a couple session: `dashboard.php`, `guest_list.php`, `budget.php`,
`vendor_enquiries.php`, `users.php` and every other staff page. They bounce you
back to the site root (or to `client.php`), which is what keeps one couple's
wedding invisible to the next.

---

## 7. The questions couples actually ask

**"Do I need to hire a planner to use this?"**
The booking is created by the office and a planner is assigned to it; that
assignment is what unlocks the planning tools (guest list, budget, checklist,
website) and it is the person who confirms vendor deals. So yes — the planner is
the human interface to the platform, not an optional add-on.

**"Can I just call a caterer myself and add them?"**
You can *enquire* from the marketplace for any approved vendor. To make it a
booking, your planner confirms it with an agreed amount — that is what puts the
number on your budget instead of in a WhatsApp thread.

**"Who sees my guest phone numbers?"**
Your planner (and the super admin). Vendors never see the guest list; they see
the enquiry and the deal. SMS to guests is sent through the planner's SMS
credits, not handed over.

**"Why does a vendor show three prices?"**
Silver, Gold and Platinum are the same offer at three levels — the entry price,
the one with more included, and the full version — each stating what it includes
and what it leaves out. The figure on a marketplace card is the **Silver**
(entry) price, so two vendors can be compared like for like; your planner can
quote you any level, and the option you are sent says which one it is.

**"What if the vendor's package is too small for my guest count?"**
The system compares the planned headcount for the function with the capacity of
the package the vendor was booked on, and warns both you and your planner — with
that vendor's next package up and the price difference — at the moment the deal
is recorded, not on the wedding day.

**"How do I know a payment went through?"**
M-Pesa transaction codes are checked against the payments Safaricom reports to
the platform's C2B callback; an operator can also accept a pending one by hand
in the M-Pesa Ledger. Either way the payment lands on your budget with a receipt
you can share.
