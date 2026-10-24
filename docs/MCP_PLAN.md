# MCP integration plan — an AI-accessible planning assistant

Date: 17 October 2026. Status: **Phases 1, 2 and 3 complete and verified** — the
endpoint, the token page, the thirteen read tools, weddings as **resources**,
the server-side **prompts**, the audit trail, the rate limit, the two write
tiers with their human-approved money/message boundary, the approvals page,
tests and the operator docs are all on disk and exercised over real HTTP.
Phase 4 is partly built: the operator's own surface (`system_status` and
`list_insights`, over `OpsPulse`) shipped 20 October 2026; OAuth metadata,
quotas and a live third-party client remain. The AI-copilot design and the
Kenya DPA checklist are in `docs/AI_COPILOT_PLAN.md`.

### Phase 1 — what exists, and how it was checked

| Artifact | What it is |
|---|---|
| `migrations/v2.16_api_tokens.sql` | `tblapi_tokens` (hashed tokens, scopes, optional narrowing, expiry) + `tblapi_audit` (one row per call) |
| `admin/include/ApiToken.php` | mint / revoke / authenticate / bind_principal / audit / the owner's audit reads |
| `admin/include/McpTools.php` | the catalogue: 9 read tools, each resolved through the models and the ownership predicates |
| `admin/include/McpServer.php` | the protocol: both MCP eras, auth, Origin, rate limit, dispatch, error shaping |
| `admin/include/models.php` | the shared class include set (extracted from `init.php`) so a headless entry point loads exactly the models a page does |
| `mcp.php` | the endpoint: transport only, twelve lines of reading a body and writing an answer |
| `admin/api_tokens.php` | mint / list / revoke / audit page, with the once-only secret + paste-ready client config |
| `tests/McpEndpointTest.php` | 26 tests / 87 assertions over a real `php -S` server and the seeded DB |

Verified, in the order it was checked: the legacy handshake and the current
stateless shape; **every tool the catalogue advertises actually runs** against
real data (this assertion is what caught a missing model include on the couples'
path the first time `get_wedding` was called with a couple's token); a couple's
token reading its own wedding and being refused another's, with the refusal
audited; a narrowed token refused everything but its one wedding; no/
bogus/revoked/expired tokens all 401; a foreign Origin 403 and the site's own
Origin allowed; an unsupported protocol version refused with the spec's -32022
and its `data`; notifications answered 202 with an empty body; GET 405 with
`Allow: POST`; one audit row per call, refusals included, and no argument VALUE
anywhere in the table; the `mcp` door on the shared guard refusing past its
ceiling; and the whole mint → call → revoke cycle driven through the page.

### Phase 2 — what exists, and how it was checked

| Artifact | What it is |
|---|---|
| `admin/include/McpTools.php` | four more read tools: `list_timeline`, `headcount_capacity`, `list_notifications`, `wedding_website` (thirteen in all) |
| `admin/include/McpResources.php` | weddings as documents: `everafter://me`, `everafter://weddings`, `wedding://{booking_id}/{view}` — URI parsing, the filtered index, the templates, and the read that delegates |
| `admin/include/McpPrompts.php` | the prompts (`weekly_wedding_brief`, `business_pulse`, `rsvp_chase_list`, `budget_watch`, `vendor_shortlist`) that gather their own context and carry the platform's rules |
| `admin/include/McpServer.php` | `resources/list`, `resources/templates/list`, `resources/read`, `prompts/list`, `prompts/get`; `Mcp-Name` for a read and a prompt; `ttlMs`/`cacheScope` on those results; the era-correct not-found code; capabilities for all three surfaces |
| `tests/McpEndpointTest.php` | 40 tests / 193 assertions (14 new: resources, prompts, both eras' rules for them, and the search filters that hid the placeholder bug below) |

Verified, in the order it was checked: the handshake and `server/discover`
advertising all three surfaces; `resources/list` and `resources/templates/list`
filtered to the token's scopes (a `read:guests` token is offered the guest and
capacity documents and nothing else — browsing is not a map of weddings it may
not open); **a document returning the same data as the tool behind it** (the
overview URI and `get_wedding` compared field by field, so the two doors cannot
drift apart); a foreign wedding and a nonexistent one answering with the same
sentence, differing only by the id the caller sent, in both eras (-32602 today,
-32002 for a legacy client) and leaking no name; a missing scope for a readable
wedding named plainly, because that one is fixable; unknown URIs refused as
not-found rather than crashing; `ttlMs`/`cacheScope: private` on every index and
document; the prompt list filtered by scope, a brief that arrives with the real
figures and the couple's name, and the three ways a prompt refuses (no argument,
no scope, a wedding it cannot open) all reading as sentences rather than silence;
`Mcp-Name` enforced for both a resource read and a prompt get; and one audit row
per read and per prompt, naming the URI or the prompt, with the scope the
equivalent tool call would have used.

**Two Phase 1 bugs the prompt layer found, and why they had survived.** The
moment `vendor_shortlist` passed a county to `search_vendors`, the call died with
`SQLSTATE[HY093] Invalid parameter number` — because the SQL bound ONE value to a
placeholder used twice (`v.county LIKE :county OR v.location LIKE :county`). This
connection uses native prepares (`ATTR_EMULATE_PREPARES => false`), so a repeated
name is not a style question, it is a statement that cannot execute. The same bug
sat in two more places: `list_weddings`' search (three uses of `:q`) and
`Guest::filter_clause()` (four uses, so **every guest search in the application**
— page and tool — failed while every unfiltered list worked). All three now bind
one placeholder per use, and `McpEndpointTest` calls each of those filters with a
term, because a test that never passes a filter cannot see this class of bug.The sweeps do not catch it either: sweep G checks that a placeholder is BOUND,
not that one value covers every use.

---

### Phase 3 — what exists, and how it was checked

| Artifact | What it is |
|---|---|
| `migrations/v2.17_mcp_approvals.sql` | `tblapi_approvals` (one row per Tier-3 request, one per decision) + five columns on `tblapi_audit`: `tier`, `request_id`, `approval_id`, `payload_hash`, `outcome_note`, with a unique key on `(token_id, request_id)` |
| `admin/include/McpWrites.php` | the write catalogue: Tier 2 (`add_guest`, `update_guest`, `mark_invited`, `toggle_task`) and Tier 3 (`send_bulk_sms`, `record_payment`, `initiate_stk_deposit`, `assign_planner`), each with its tier, its schema and its handler |
| `admin/include/McpApprovals.php` | request → mint → consume: the HMAC-signed, single-use, payload-bound approval, plus the pending/decided reads the page uses |
| `admin/mcp_approvals.php` | the human half, on the existing `Session`: `require_staff_subscribed()`, CSRF on every button, Approve (mints once, shows once) and Refuse (with a reason the assistant is told) |
| `migrations/v2.18_mcp_grants.sql` | the least-privilege database users: a read user, and a write user whose only writes are the ones the tools need — no DELETE, no UPDATE on the audit table, no SELECT on a password column |
| `admin/include/McpServer.php` | idempotency (a repeated `request_id` gets the first outcome back), the tier in every audit row, and an approval refusal reported as a readable business refusal rather than an internal error |
| `admin/include/McpTools.php`, `ApiToken.php`, `Monetization.php` | the merged catalogue and tier lookup; `write:sms` and `write:planners` scopes and the extended audit writer; `SMS::audience_phones()` / `send_bulk_audience()` so a count, a cost and a send all come from the platform's own rules |
| `migrations/v2.19_mcp_approval_expiry.sql` | `expired_at` on `tblapi_approvals`: the sweep's write-once marker. A marker, not a state — `state_of()` still derives expiry from `expires_at`, so there is no status column to fall out of step |
| `scripts/mcp_approval_sweep.php` | the 10-minute cron job (v2.19): closes every request past its window — unanswered, or approved and never used — records it for the assistant and rings ONE digest bell per planner. Registered with `JobWatch` like every other job, so a sweep that stops sweeping is itself a finding |
| `tests/McpWriteToolsTest.php` | 15 tests over real HTTP with `WPMS_MCP_WRITES=1`, against the seeded database, cleaning up every row it writes |
| `tests/McpApprovalSweepTest.php` | the deadline: closed once, alerted once, recorded for the assistant, a refusal never overwritten, a retry naming the dead reference, a dry run writing nothing, and the job actually wired into the watchdog and bridge |
| `migrations/v2.20_mcp_approval_rerequest.sql`, `McpApprovals::revive()` | the planner's answer to that bell: `re_request_of` / `re_requested_by` lineage, and a NEW pending request for the same tool and payload with a fresh window. The expired row is not reopened, so the record stays true; the asking token must still be live; a second click (or an assistant retry) returns the request already waiting instead of a duplicate |
| `tests/McpApprovalsPageTest.php` | the human half over a real signed-in session, including the v2.20 revival: CSRF, a new reference, the old row still expired, the assistant's identical retry landing on it, and a double-click producing only one pending row |
| `admin/include/config.php`, `database.php` | the optional read replica (v2.19): `WPMS_DB_REPLICA_HOST/USER/PASS/NAME`, resolved into `DB_REPLICA_*` with the primary's user/password/name as defaults, and `Database::reader()` / `read_handle()` / `with_read_handle()` — a second PDO handle, a lazy singleton, and a scoped swap that restores the primary in a `finally` |
| `tests/DatabaseReplicaTest.php` | the replica contract, exercised in child PHP processes (constants are per-process): no host = reads use the primary; an unreachable host = fallback to the primary plus one log line; a reachable replica = a distinct reader handle, a read served from it, and the primary back in place after the call — including when the work throws |

**Reads may leave the primary; writes never do (v2.19).** On a single-database
host — XAMPP, most cPanel plans — `WPMS_DB_REPLICA_HOST` is empty and every read
and write is the primary, exactly as before. Set it and `McpTools::call()` runs
each READ handler inside `Database::with_read_handle()`: the global `$db` points
at a second PDO handle for the length of that handler, so the model classes
(`Guest`, `Checklist`, `Liquidation`, …) are covered too, not only the handlers
with hand-written SQL. The scope is the write boundary: `McpWrites::call()`
takes every write before this point, the `finally` puts the primary back even on
a business refusal, and the token check, the audit row and a Tier-3 approval
still read and write the primary. `DB_REPLICA_USER/PASS/NAME` default to the
primary's, which covers a separate server and a read-only grant on the same one;
`migrations/v2.18_mcp_grants.sql`'s read user is the intended pairing. A
replica that cannot be reached degrades to the primary with one `error_log`
line rather than taking the assistant down, and replication lag is the accepted
cost: a row written a moment ago may be missing from the next read.

Verified, in the order it was checked: a read-only token is never SHOWN the write
tools and gets them the moment it carries the scope; `add_guest` without
`confirm` returns a preview and the guest count is asserted unchanged; with
`confirm` the guest row carries the priority band and RSVP token the couple's own
form would have given it; a couple's token cannot write to another wedding; a
Tier-3 call with no approval reports `approval_required` and the booking is
untouched; asking twice about the same payload is ONE request; a refused request
can no longer be approved; an approval minted for one planner is refused for
another (a VALID one — an invalid id is refused for its own reason first); an
approved call applies once, names the approval in the audit row, records the
payload hash the human approved, and cannot be replayed; a repeated `request_id`
reports `already_applied` instead of adding a second guest; the audit row for a
payment carries the tier, the approval and the idempotency key and still carries
no M-Pesa code and no argument values; and with `WPMS_MCP_WRITES` unset, a
token that carries a write scope still cannot write and no write tool is
advertised.

**Three bugs this phase found, and why the design caught them.**

* **The approval window was computed in PHP's clock.** `mint()` built the expiry
  with `time() + 15 minutes` while the row's own `expires_at > NOW()` check ran
  in SQL. On a host whose PHP timezone differs from MySQL's — which is the norm,
  and which the rest of this codebase avoids by keeping time arithmetic in SQL —
  every approval was dead on arrival: the string signed an expiry the database
  had already passed. The window is now computed BY MySQL, the epoch the HMAC
  signs is that same instant, and the state a page prints comes from the
  database's own verdict (`live_now`) rather than `strtotime()`.
* **A refused approval looked like a server crash.** `McpApprovalError` was not
  the error type the tool dispatcher treats as a business refusal, so "that
  approval was minted for a different payload" reached the assistant as "the
  platform could not complete that request". That is exactly the failure mode the
  protocol split exists to prevent, and it is now a readable `isError` result.
* **A no-op UPDATE read as a failed save.** The ORM reports changed rows, so
  re-assigning the planner a wedding already had — or re-sending an update whose
  values were identical — returned false and was reported as "could not be
  saved". Both handlers now read the row back before believing it, and an
  already-satisfied request answers `unchanged` instead of failing.

**Three more, found by driving one Tier-3 payment approval end to end** (curl as the
MCP client, a real `login.php` session and a real `admin/mcp_approvals.php` POST as
the human, which is the only way to exercise both processes at once).

* **The page and the endpoint derived different approval keys.** With
  `WPMS_MCP_APPROVAL_SECRET` unset — the XAMPP default — `McpApprovals::key()`
  hashed `SITE_ROOT` when it was defined and `__DIR__` when it was not.
  `admin/include/init.php` defines `SITE_ROOT` as `…/admin`; `mcp.php` never
  defines it. So the page minted with one key and the endpoint verified with
  another, and EVERY approval failed with "does not verify" — the one failure the
  tests could not see, because every suite minted and consumed inside the
  headless context. The fallback now hashes `dirname(__DIR__)`, which is the same
  path in both processes, and
  `McpApprovalsPageTest::testAnApprovalMintedByThePageVerifiesAtTheEndpoint`
  mints in a child process that defines `SITE_ROOT` exactly as `init.php` does.
  (Set the real secret in production; this only fixes the derived fallback.)
* **A refusal never reached the assistant.** The Approvals page said the model
  was told; it was not. Nothing in the MCP layer read `deny_reason`, and
  `McpApprovals::request()` reused only PENDING rows — so the identical call
  after a denial minted a fresh request (`apr-62`) and the planner was asked the
  same question again, forever, with no reason ever reaching the model. A
  refusal now stands for that exact payload for 24 hours and the tool answers
  `status: refused` with the planner's own words; a changed message, amount or
  booking has a different hash and is a new question.
* **The endpoint started a session and set a cookie.** `Session.php` builds its
  singleton at include time and `models.php` loads it, so every MCP call called
  `session_start()`: a `PHPSESSID` cookie on a documented stateless endpoint and
  a server-side session file per call that nobody would ever read. `mcp.php` now
  defines `WPMS_NO_SESSION` before the model set; the class is still loaded, the
  singleton is not, and no MCP code used `$session` (the principal arrives
  through `ApiToken::bind_principal()`, which writes `$_SESSION` as a plain
  array).

**Deliberately NOT in this phase**, each for a stated reason: `add_task` (the
platform has no model method for a custom checklist item, and writing one inside
the MCP layer would be the raw SQL the design forbids), `set_installment_plan`
(payment terms, composed on the couple's own page), `record_rsvp_answer` (an RSVP
is the guest's answer, not an assistant's), and `system_status` (the operator's
surface — Phase 4). All four are absent from `tools/list`, not stubbed.

---

## 1. What this is, and why it is shaped this way

An MCP (Model Context Protocol) endpoint lets an AI client — Claude, ChatGPT,
Cursor, an internal agent — query and act on this platform's **real** wedding
data: guests, budgets, vendors, tasks, timelines. The AI is the client. **PHP
stays the authority**: every call is authenticated as one of this app's own
accounts, scoped by the permissions that account already has, and nothing
happens that the same person could not do by hand in the browser.

That last sentence is the whole design:

| Concern | Who decides |
| --- | --- |
| Who is asking | `tblapi_tokens` → one principal: a `tblusers.id` (staff/super admin/vendor) **or** a `tblaccounts.user_id` (couple) |
| What they may see | the account's own row-level rules — `booking_visible_to_session()`, `may_open_couple_page()`, `vendor_listing_visible_to_session()` — the *same functions the pages call* |
| What they may do | the token's **scopes** (a subset of the role's powers) plus the environment's write switch |
| What actually happened | every call written to `tblapi_audit`, and rate-limited by the shared `tblpublic_throttle` guard |

The AI never writes SQL, never names a table, and never supplies an id that is
trusted as identity. Tools take typed arguments; the layer resolves them through
the models (`Guest`, `Checklist`, `Liquidation`, `Booking`, `PackageCapacity`, …)
exactly as the pages do.

### Protocol shape (verified against the current spec)

MCP has two eras in the wild, and a server that wants today's clients on shared
hosting must speak both:

| Era | Handshake | Transport |
| --- | --- | --- |
| `2026-07-28` (current) | none — stateless; version travels in `_meta` and the `MCP-Protocol-Version` header; `Mcp-Method` (and `Mcp-Name` for a call) must agree with the body | POST only, JSON responses, no session id |
| `2025-11-25` and earlier | `initialize` → `notifications/initialized` | POST (the spec's GET/SSE stream is optional; we answer `405`) |

We implement both, statelessly: no server-side session, no SSE, `application/json`
responses only. That is spec-legal in both revisions and is the only shape that
survives shared hosting (no long-lived processes, no sticky session storage).

---

## 2. Database tables

Three tables, two migrations — the wedding data is already where it belongs.

| Table | Holds | Why it looks like this |
| --- | --- | --- |
| `tblapi_tokens` | one row per API/MCP token: `recipient_type` + `user_id` (which account table the id indexes), `name`, `token_hash` (sha256, unique), `token_prefix` (display only), `scopes`, optional `booking_id` (narrow a token to one wedding), `expires_at`, `last_used_at`, `last_used_ip`, `revoked_at` | The plaintext secret is **never stored** — it is shown once at mint time. `recipient_type` is the v2.13 vocabulary, reused deliberately: two id spaces, so an id alone never says who it is. |
| `tblapi_audit` | one row per tool call: `token_id`, principal, `tool`, `scope`, `ok`, `error`, `args_digest`, `booking_id`, `ip`, `ms`, `created_at` — plus, since v2.17, `tier`, `request_id` (idempotency key, unique per token), `approval_id`, `payload_hash` and `outcome_note` | Answers "what has the AI been doing with my data" for the operator and the user. It stores a **digest** of the arguments (key names + sizes), never their values — a guest's phone number must not be duplicated into a log — and the v2.17 columns keep a WRITE legible without copying its payload: which tier it was, which human approved it, and one server-written line about what changed. |
| `tblapi_approvals` | one row per Tier-3 request and per decision about it (`v2.17_mcp_approvals.sql`): the asking token, the tool and tier, the booking, `payload_hash`, the agent-proof `payload_note`, the approval's `approval_hash`/`approval_prefix`, `approved_by`/`approved_at`, `denied_by`/`denied_at`/`deny_reason`, `consumed_at`, `expires_at` | The human half of a write that touches money or messages. State is carried by **timestamps**, never a status column someone could UPDATE into a lie, and rows are never deleted (pruned with the audit trail). `consumed_at` is set by the single UPDATE that races for `consumed_at IS NULL` — that row count IS the exactly-once guarantee for an SMS or an STK push. Only sha256 of the minted string is stored. |

Rate limiting reuses `tblpublic_throttle` (v2.14) with a new door kind, `mcp`,
keyed by the **hash** of the token and the caller's IP — the same discipline the
RSVP tokens already use: a capability must never sit in a counter table.

The wedding tables an AI tool reads, by domain:

| Domain | Tables |
| --- | --- |
| Wedding / couple | `tblweddingbook`, `tblaccounts`, `tblaccounts_detail`, `tblorganizer`, `tbluser_prefs` |
| Guests & RSVP | `tblguest` (incl. `rsvp_*`, dietary, transport, `harambee_contribution`) |
| Tasks & timeline | `tblchecklist`, `events`, `calendar`, `tbleventbooking` (functions) |
| Budget & payments | `tbl_liquidation` (payments), `tbl_installment_plans` |
| Vendors | `tblvendors`, `tblvendor_packages`, `tblvendor_bookings` |
| Website / gallery | `tblwedding_websites`, `tblgallery` |
| Platform (operator) | `tblsubscriptions`, `tblsubscription_plans`, `tblnotifications`, `tbljob_heartbeats` |

---

## 3. The tool catalogue

Scopes are the contract: a token carries a subset, and a tool whose scope the
token lacks is refused before any query runs. Read scopes are implemented in
Phase 1; write scopes are implemented in Phase 3 (defined now so the contract is
stable and tokens can be minted ahead of the tools).

### Phase 1 — read tools (implemented)

| Tool | Scope | Arguments | Backed by |
| --- | --- | --- | --- |
| `whoami` | *(none)* | — | `tblapi_tokens` + role predicates |
| `list_weddings` | `read:weddings` | `when` (upcoming/past/all), `mine`, `search`, `limit` | `tblweddingbook`, `tblaccounts_detail`, `tblaccounts` |
| `get_wedding` | `read:weddings` | `booking_id` | the overview aggregate (see below) |
| `list_guests` | `read:guests` | `booking_id`, `rsvp_status`, `priority`, `out_of_town`, `dietary`, `search`, `limit`, `offset` | `tblguest` |
| `rsvp_status` | `read:guests` | `booking_id` | `Guest::rsvp_tally()` + pending/reminder state |
| `list_tasks` | `read:tasks` | `booking_id`, `phase`, `done`, `due_within_days` | `Checklist::find_for_booking()` |
| `budget_position` | `read:budget` | `booking_id` | `Liquidation` totals, `InstallmentPlan::for_booking()` |
| `list_vendor_deals` | `read:vendors` | `booking_id` | `tblvendor_bookings` + packages + vendors |
| `search_vendors` | `read:vendors` | `category`, `county`, `max_price`, `limit` | `tblvendors`, `tblvendor_packages` |

`get_wedding` is deliberately one aggregate call: an agent asked "how is Amina's
wedding going?" should not need eight round-trips. It returns couple, date, venue,
guest totals, RSVP tally, task progress, budget position, vendor-deal count and
capacity breaches.

### Phase 2 — breadth (implemented)

| Tool | Scope | Adds |
| --- | --- | --- |
| `list_timeline` | `read:weddings` | the running order of the wedding day, from `BookingEvent::plan_for()` (the venue sheet's own source) with each function's venue, planned headcount and the vendors covering it |
| `headcount_capacity` | `read:guests` | `PackageCapacity::limits()/plan_totals()/breaches()/headroom()/way_out()` — sold capacity against the per-function plan, with the platform's own way-out sentence |
| `list_notifications` | *(own feed only)* | `Notification::page()` for the token's own account — the AI can summarise what needs attention |
| `wedding_website` | `read:weddings` | `tblwedding_websites` state: published, slug, views, story |
| `system_status` | `admin:system` (super admin) | job heartbeats (`JobWatch`), subscription/revenue pulse — **moved to Phase 4**: it is the first tool that would read the operator's own surface rather than a wedding, and it deserves the same review as the write tools, not a place in the read sweep |

Two decisions from this phase worth keeping:

* **`calendar` is legacy and the timeline does not use it.** Sweep D classes
  `calendar` as legacy — no page reads it, its only reference is the seed-date
  tooling. A timeline built on it would be a second, silent answer to a question
  `events` already answers, so `list_timeline` reads `events` through
  `BookingEvent`, exactly as the venue sheet does.
* **A column nothing writes is not reported.** `wedding_website` deliberately
  omits `cover_image`: no statement on this platform writes it, so telling a
  couple whether they have one would be a promise with no screen behind it. Sweep
  D flags that class of thing, and it is right.

Plus MCP **resources** — `everafter://me`, `everafter://weddings` and
`wedding://{booking_id}/{overview,timeline,guests,tasks,budget,capacity,vendors,website}`
— for clients that prefer documents to tools. Every URI runs the tool behind the
same question (`McpResources::VIEWS` maps one to the other), with the same scope
check and the same `booking_visible_to_session()` rule, and returns the identical
JSON, so a document can never become a second answer. A wedding this account may
not open is reported exactly as one that does not exist, because a distinguishable
refusal would let an assistant walk the id space; a scope the token lacks is said
plainly, because the caller can fix that. And MCP **prompts** —
`weekly_wedding_brief`, `rsvp_chase_list`, `budget_watch`, `vendor_shortlist` —
reusable, server-side instructions that gather their own context through the read
tools and carry this platform's rules (KSh, exact figures, never invent a guest or
a payment, quote the capacity way-out sentence rather than paraphrasing it).

### Phase 3 — writes (implemented)

A write needs a write scope on the token **and** `WPMS_MCP_WRITES=1` in the
environment, and then its TIER decides the rest. Tier 2 runs on `confirm: true`
and otherwise returns a preview; Tier 3 records a request and performs nothing
until a signed-in human approves that exact payload (McpApprovals).

**Tier 2 — reversible, previewed, confirmable**

| Tool | Scope | What it wraps |
| --- | --- | --- |
| `add_guest` | `write:guests` | a `Guest` row built exactly as `client_guest_save.php` builds it: the relationship→priority band, `rsvp_status = 'pending'` and a fresh RSVP token, so the guest is invitable the moment they exist |
| `update_guest` | `write:guests` | the named fields of one `Guest`, re-checked against the booking; only what is sent changes, and a payload that matches what is already there answers `unchanged` |
| `mark_invited` | `write:guests` | `Guest::mark_invited($ids, $booking)` — the invite stamp the reminder clock counts from; already-invited guests keep their timestamp, which is why repeating it is safe |
| `toggle_task` | `write:tasks` | `Checklist::toggle($item_id, $booking_id)` after the item is found on THIS booking |

**Tier 3 — money, messages, status: a human approves the payload**

| Tool | Scope | What it wraps | Why a human |
| --- | --- | --- | --- |
| `send_bulk_sms` | `write:sms` | `SMS::audience_phones()` for the count and the cost (KSh 1 per 160 characters, the platform's own rule), then `SMS::send_bulk_audience()` — every recipient logged in `tblsms_logs`, charged to the asking planner's credits | 400 real people, and credits somebody paid for |
| `record_payment` | `write:budget` | a `Liquidation` row built as `budget_add.php` builds it: cash / M-Pesa (10-character code, verified through `MpesaC2B::verify()` when verification is on) / bank, owned by the COUPLE's account, with the verified callback consumed so the code cannot be reused | it changes what a couple owes |
| `initiate_stk_deposit` | `write:budget` | `MpesaStk::initiate('deposit', …)` — every rule (the switch, the phone shape, the balance guard) stays in the model | it prompts a stranger's phone, and a push is a promise, not a payment |
| `assign_planner` | `write:planners` | `organizer_id` on `tblweddingbook`, read back to prove it landed | who the couple is told about, whose credits the courtesy SMS uses, and — with per-planner scoping on — who else can open the wedding |

Every write also carries an optional **idempotency key** (`request_id`): it is
recorded only when the call actually changed data, `(token_id, request_id)` is
unique, and a retry gets the first outcome back as `already_applied` rather than
a second guest, a second SMS or a second payment. The audit row keeps the tier,
the key, the approving decision, the payload hash and a short server-written note
— and the note names IDs, counts and amounts, never a guest's name, a phone
number, a message body or an M-Pesa code, all of which belong to the approval row
the human actually read.

**Approval mechanics.** Request → the row in `tblapi_approvals` with a payload
hash and a sentence the SERVER wrote from the resolved data; decision →
`admin/mcp_approvals.php`, signed in, CSRF, and the same
`booking_visible_to_session()` rule every page uses (which is how per-planner
scoping reached this page without a line of new code in it); minting → an
HMAC-signed string over the tool,
the tier, the booking, the asking token, the account, the payload hash and the
expiry, computed against MySQL's clock, shown once, valid 15 minutes; use →
verified, then consumed by the single UPDATE that races for
`consumed_at IS NULL`. A changed message, amount, audience or planner id no
longer fits, a spent approval cannot be replayed, and a refusal is a decision the
assistant is told about.

**And a window that closes is a fact, not a gap (v2.19).** The 30-minute request
window was always enforced, but enforcement was silent: `expires_at` passing made
the row unusable without telling anyone. The planner never learned the SMS did not
go out (worse when they had approved it and the assistant never used the string),
the assistant's retry minted a fresh reference as if the old one had not existed,
and the call log had no line about it. `scripts/mcp_approval_sweep.php` closes
those rows every 10 minutes: `expired_at` set once by the UPDATE that races on
`expired_at IS NULL`; one `tblapi_audit` row with `ok=0` saying nothing was sent
or recorded; one digest notification per planner naming the dead references; and
`previous_request_expired` on the retry, so the model stops waiting on a
reference that can no longer be approved. Expired rows join the page's
**Decisions and expiries** list instead of vanishing from both tables.

**And the planner can answer the bell without the model (v2.20).** Being told
that an SMS died is only half an answer; the other half is being able to say
"ask me again". Each expired row now carries **Re-request this payload**: one
CSRF-protected click inserts a NEW pending request for the same token, tool and
`payload_hash`, with a fresh 30-minute window and its own `apr-` reference, and
records `re_request_of` / `re_requested_by` so the chain is readable afterwards.
The expired row is deliberately NOT reopened — its history ("this one died,
nothing ran") stays true — and the assistant's identical retry is matched to the
new row by `McpApprovals::request()`'s existing reuse lookup, so it does not mint
a third reference. The request is not approved by the click: it joins the inbox
and the planner still makes the explicit, single-payload decision. Only an
expired row can be revived (not pending, approved, refused or used), and only
while the asking token is still active, because an approval is spendable by that
token alone.

### Phase 4 — hardening and operations (designed)

* OAuth 2.1 resource-server metadata (RFC 9728) so browser-based clients
  (claude.ai, ChatGPT connectors) can authorise — note `.htaccess` denies `.json`
  at the root, so the metadata document must be served by a `.php` route.
* Per-token quotas and a usage view (calls, errors, last used) on the token page,
  and a usage line per approval on the approvals page.
* Token rotation flow, short-lived tokens, and per-booking token narrowing UI.* ~~`system_status` (`admin:system`, super admin).~~ **Implemented 20 Oct 2026**
  as `OpsPulse` (`admin/include/OpsPulse.php`) plus two super-admin tools:
  `system_status` (the platform's aggregate pulse — weddings, money, RSVPs,
  tasks, vendors, subscriptions, AI usage, job health, plus `movement`: each
  headline figure against the last weekly brief's snapshot, with value, previous
  and signed change) and `list_insights`
  (eleven deterministic rules producing severity-ranked findings with the
  numbers that fired them and a page to act on). No names, phones, emails,
  dietary notes or payment references by construction; `tests/OpsPulseTest.php`
  asserts that contract, the arithmetic and the super-admin-only gate. Both
  tools apply the **calling account's** tuned thresholds and muted rules
  (`admin/ops_pulse.php` → `tbluser_prefs`), so the assistant answers with the
  same horizon the owner sees. The same calls also render the **Monday brief**
  email (`admin/include/BusinessPulse.php`,
  `scripts/business_pulse_email.php`) — deterministic, no provider, one per ISO
  week per owner, with `tests/BusinessPulseTest.php` covering the calendar, the
  no-PII contract, recipients and the outbox. The rationale, the legal checklist
  and the in-app copilot design are in `docs/AI_COPILOT_PLAN.md`.
* ~~Off-primary reads.~~ **Done in v2.19** (see the table above): the second
  handle, the read-only scope in `McpTools::call()`, the fallback to the primary
  and the tests are in place. What remains is operational rather than
  architectural: a real replica to point it at, and a lag budget the operator is
  willing to accept for an assistant's answers.
* `LoginThrottle` under a synthetic `mcp:<key_id>` identity, so a leaked or
  revoked token cannot be ground through the catalogue from rotating addresses —
  today the per-token guard (`PublicGuard`, door `mcp`) is keyed by the token's
  hash and the caller's IP.
* The two write tools left out of Phase 3 rather than stubbed: `add_task` (needs
  a model method for a custom checklist item, so the MCP layer need not write SQL)
  and `set_installment_plan` (payment terms, composed on the couple's own page).
* `deploy_check.php` + runbook entries, and a live end-to-end run against a real
  MCP client (Claude Code) as the release gate — including one Tier-3 approval
  driven from the page to a completed action, which is the only part of this plan
  no test can finish on its own.

---

## 4. Security model in one page

1. **Identity comes from the token, never from the arguments.** A tool that takes
   `booking_id` resolves it and then asks `booking_visible_to_session()` — the
   same predicate the pages use. A couple's token can therefore only ever reach
   its own booking, and a vendor token reaches no couple data at all.
2. **Scopes are a ceiling, not a replacement for the row rules.** Even with
   `read:guests`, a token sees only the bookings its principal may open.
3. **The two id spaces stay separate.** Tokens, audit rows and principals all
   carry `recipient_type`, so a `tblusers` id can never be read as a couple.
4. **No dynamic SQL.** Tools take typed, validated parameters; every statement is
   prepared and bound (the existing `code-audit` sweep G enforces this).
5. **Writes are double-gated** (scope + environment), previewed by default, and
   idempotent — and the money/message ones are TRIPLE-gated, by a human
   (McpApprovals), whose approval is bound to one payload and spent once.
6. **The endpoint can be given its own database credentials.**
   `migrations/v2.18_mcp_grants.sql` defines a read user and a write user whose
   only writes are the ones the tools perform: no DELETE anywhere, no UPDATE on
   `tblapi_audit`, no `UPDATE` on the websites table (so the never-expose
   `increment_views()` cannot even be attempted), and column-level reads on
   `tblusers`/`tblaccounts` so `password` and `user_password` are not selectable
   — which makes "no tool may ever return a hash" a fact about the database
   rather than a promise about the code. Applied to a deployment whose WPMS_DB_*
   point at those users, it is enforcement; in a single-user install it is the
   documented intent.
7. **Everything is visible afterwards.** `tblapi_audit` answers "what did the AI
   do", and the token page shows when each token was last used and from where;
   `tblapi_approvals` shows what a human authorised, refused or never answered.
8. **Fail closed on auth, fail open never on ownership.** An unreadable token
   table means 503, not "allow"; a booking that does not resolve is never treated
   as harmless. The same applies to the approval table: without it, Tier-3 tools
   refuse rather than act unapproved.
