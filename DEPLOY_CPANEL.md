# Deploying Everafter Planner to cPanel (Shared Hosting)

Total time: 45–60 minutes. Your domain + SSL must already be active (they are).

---

## HostPinnacle quick notes (your host)

- **Getting into cPanel:** client area (hostpinnacle.co.ke/clients) →
  **Services** → your plan → **Login to cPanel** — or browse directly to
  `https://yourdomain.co.ke/cpanel` with the cPanel credentials from your
  welcome email.
- **PHP version:** cPanel → **MultiPHP Manager** (Software section) → select
  your domain → set PHP **8.4 or 8.5** → Apply. The app requires >= 8.2, but
  8.2 is security-only and **ends 31 Dec 2026** (8.3 on 31 Dec 2027);
  `deploy_check.php` now fails a branch past its EOL and warns on a
  security-only one. See [`SECURITY_PATCHING.md`](SECURITY_PATCHING.md).
- **Server stack:** HostPinnacle shared plans run LiteSpeed/Apache with
  PHP-FPM or LSAPI — exactly the setup the bundled `.htaccess` and
  `.user.ini` were written for. Nothing to change.
- **Cron PHP path:** use `/usr/local/bin/php` (see Part 6). If a job emails
  you "command not found", try `/usr/bin/php` instead.
- **SSL:** already active — the app's `.htaccess` forces HTTPS automatically,
  including behind HostPinnacle's proxy termination.

---

## Part 1 — Create the database (10 min)

1. cPanel → **MySQL® Databases**.
2. Under "Create New Database": name it `everafter` → **Create Database**.
   Full name becomes `cpaneluser_everafter` — note it exactly.
3. Under "MySQL Users → Add New User": username `wpms`, a strong password
   (generate one, save it in your password manager) → **Create User**.
4. "Add User To Database": pick both → **Add** → on the next screen tick
   **ALL PRIVILEGES** → **Make Changes**.

You now have: DB `cpaneluser_everafter`, user `cpaneluser_wpms`, password.

---

## Part 2 — Import the SQL (10 min)

1. cPanel → **phpMyAdmin** → select `cpaneluser_everafter` in the left column.
2. **Import** tab → choose `deploy/schema.sql` → **Go**.
3. Still in Import → choose `deploy/content.sql` → **Go**.
4. Verify: click any table (e.g. `tblsubscription_plans`) → 3 rows expected.
5. **Import your super-admin account:** open `deploy/seed_admin.sql` in a
   text editor FIRST and replace `your.email@example.com` with your real
   email (the login password is written in that file's comments — save it in
   your password manager). Then phpMyAdmin → Import → `seed_admin.sql` → Go.
   This creates your verified super-admin with an active 1-year subscription,
   so you can sign in immediately — no public registration needed.

> **All three files are safe to re-run.** `schema.sql` uses
> `CREATE TABLE IF NOT EXISTS` (it never drops or alters an existing table),
> `content.sql` upserts the catalog rows, and `seed_admin.sql` is guarded. If
> an import reports errors, they are real problems (not "already exists"
> noise) — read the first error line and fix it before continuing.
>
> **Maintainer note:** if you ever regenerate `schema.sql` from a fresh
> `mysqldump`, you MUST strip its `DROP TABLE IF EXISTS` statements — a raw
> dump would wipe the production database on the next import.
>
> **Upgrading a database that was created before v2.8?** `schema.sql` only ever
> creates MISSING tables, so it will not change an existing one. Run the one
> migration: `migrations/v2.8_plan_pricing_audit.sql` (phpMyAdmin → Import).
> It widens `tblsubscriptions.plan` to `VARCHAR(20)` — until then a plan defined
> in the plan editor could be sold but the purchase was stored with an empty
> plan key — and adds `tblsubscription_plan_changes`, the who/when ledger for
> plan price changes. Idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v2.9?** Same reason, one more
> migration: `migrations/v2.9_venue_headcounts.sql`. It adds the unique key
> (`booking_id`, `features_id`) on `tbleventbooking` — the table behind the
> venue sheet's "Headcount plan per function", which is editable from the sheet
> now — so re-saving a function's plan replaces it instead of stacking a second
> row, and it removes plan rows that belong to another wedding's function (those
> were printed under the wrong heading and could be edited nowhere). Idempotent:
> re-running it is harmless. Fresh installs from `deploy/schema.sql` already
> carry the key.
>
> **Upgrading a database that was created before v2.10?** Same reason, one more
> migration: `migrations/v2.10_vendor_function_scope.sql`. It adds
> `tblvendor_bookings.features_id` — the function a vendor's deal covers for the
> headcount brief the couple's edit on the venue sheet sends. `NULL` is not
> "unset": it means the vendor was booked for the whole wedding, which is what
> every existing deal already means, so there is nothing to backfill. Until this
> runs, a booked caterer or decorator hears nothing about a moved headcount; the
> planner and the venue contact are unaffected (v2.9). Idempotent: re-running it
> is harmless, and fresh installs from `deploy/schema.sql` already carry the
> column.
>
> **Upgrading to v2.11 needs no migration at all.** A function planned past the
> guest capacity of the package a vendor was booked on now warns that vendor and
> the planner, and is printed on the venue sheet beside the numbers
> (`admin/include/PackageCapacity.php`). The same check now runs the other way
> too — at every door a deal is *recorded*: the couple's enquiry, the staff
> ledger's confirm, the vendor accepting it on their dashboard, and a confirmed
> deal's headcount scope being widened. It reads columns every database already
> has — `tblvendor_packages.capacity`, `tblvendor_bookings.package_id` — so
> uploading the code is the whole upgrade. Nothing to backfill: a package with
> no capacity stated (NULL or 0) and a deal with no package chosen simply state
> no limit, which is what they have always meant.
>
> **Upgrading a database that was created before v2.13?** One more migration, and
> this one is **required**: `migrations/v2.13_notification_recipients.sql`. It adds
> `recipient_type` to `tblnotifications` and `tbl_mail_logs` — the column that says
> which account table each row's `user_id` belongs to (`tblusers.id` for staff and
> vendor leads, `tblaccounts.user_id` for couples; the two are separate id
> sequences, and without the column an account in one table whose id collides with
> an account in the other can read its feed). It backfills every existing row from
> the two tables (the booking a row is about, and for mail rows the address the
> message went to, decide any collision) and adds the index the new predicate reads
> through. **Upload the code and this migration together** — the v2.13 code names
> the column on every insert and read, so a database without it fails on the first
> notification. Idempotent: re-running it is harmless. Fresh installs from
> `deploy/schema.sql` already carry it.
>
> **Upgrading a database that was created before v2.12?** One more migration,
> and this one is **optional**: `migrations/v2.12_user_preferences.sql`. It adds
> `tbluser_prefs` — one row per (account, preference) — so the capacity headroom
> view (`admin/headroom.php`) remembers the width each planner chose to watch at
> instead of asking again on every visit. Without it the page still works: it
> shows that it cannot remember the width and opens at its own default of ten per
> cent. Nothing to backfill — an account with no row IS the default — and fresh
> installs from `deploy/schema.sql` already carry the table. Idempotent:
> re-running it is harmless.
>
> **Serving the Android app (the couple's companion app)?** One migration, and
> this one is **required for that feature only**: `migrations/v3.4_app_devices.sql`
> adds `tblapp_devices` — one row per phone that has signed in, holding the
> SHA-256 of its bearer, when it expires, the last IP that used it and a
> `revoked_at` column. The website never reads it, so skipping it changes
> nothing on the site; the app's sign-in then fails with a server error and
> `[WPMS][App]` in the PHP error log names the missing table, because an auth
> layer with no table to check a token against must refuse rather than welcome.
> **To revoke a lost phone:** set `revoked_at = NOW()` on its row in phpMyAdmin
> (the `device_name` column is what the phone called itself). Nothing to
> backfill, and it is idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v3.3?** One more migration, and
> this one is **optional**: `migrations/v3.3_deal_answer_note.sql` adds
> `tblvendor_bookings.status_note` (the short reason whoever drops a deal gives)
> and `status_note_by` (who that was — vendor or planner). The vendor writes it
> beside Accept/Decline on **My Bookings** and in a calendar day's drawer; the
> planner writes it beside Confirm/Cancel on **Vendor Enquiries**. The couple
> then read it on **My enquiries** and **Vendor Plan & Total**, the vendor reads
> it on their own ledger, and the planner on theirs — attributed, instead of a
> bare grey badge. Every surface guards the read, so without the migration the
> platform behaves exactly as before and the forms simply store the reason in
> the deal's `notes` trail alone. Nothing to backfill: a cancelled deal from
> before it has no reason, which is the truth. Idempotent: re-running it is
> harmless, and fresh installs from `deploy/schema.sql` already carry the
> columns.
>
> **Upgrading a database that was created before v3.2?** One more migration,
> and this one is **recommended on any real install**:
> `migrations/v3.2_hot_path_indexes.sql` adds three indexes — `tblguest`
> (`booking_id`), `tblweddingbook` (`user_id`) and `tblweddingbook`
> (`organizer_id`). Every guest-list page, the RSVP tools, the reminder jobs,
> the couple's "my booking" lookup and per-planner scoping filter by exactly
> these columns, and none of them was indexed. On the demo's few thousand rows
> the difference is invisible; on a planner's book of thirty weddings it is the
> difference between a page and a pause. Indexes only — no table, column or row
> is touched, and nothing reads differently afterwards. Fresh installs from
> `deploy/schema.sql` already carry them. Idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v3.1?** One more migration,
> and this one is **optional**: `migrations/v3.1_vendor_service_tiers.sql` adds
> `tblvendor_service_tiers` and `tblvendor_package_tiers` — the Silver / Gold /
> Platinum price, inclusions and exclusions a vendor sets on each thing they
> sell — and a `tier` column on `tblproposal_items` so a quote line remembers
> which level it was priced at. Without it nothing changes: every page keeps
> quoting the single price in `tblvendor_services.price` /
> `tblvendor_packages.price`, the vendor's tier editor says which migration is
> missing, and the builder offers one choice per offering. Nothing to backfill
> (a service with no tiers IS an entry-level-only offer). Fresh installs from
> `deploy/schema.sql` already carry both tables and the column. Idempotent:
> re-running it is harmless.
>
> **Upgrading a database that was created before v2.30?** One more migration,
> and this one is **optional**: `migrations/v2.30_proposals.sql` adds
> `tblproposals`, `tblproposal_options` and `tblproposal_items` — the three
> tables behind the proposal lifecycle (register → acknowledge → three priced
> options → couple chooses → lock, which writes the vendor deals and confirms
> the wedding). Without it a couple who registers still gets an account, a
> pending booking and the ordinary dashboard; what they do not get is a proposal,
> and **Pending Proposals**, **Your Wedding Options** and the builder say which
> migration is missing instead of pretending otherwise. Nothing to backfill: a
> wedding confirmed before v2.30 simply has no proposal row, and every page that
> reads one copes (the couple keeps using Vendor Plan, Taste & Budget and the
> rest exactly as before). Fresh installs from `deploy/schema.sql` already carry
> all three tables. Idempotent: re-running it is harmless. To turn the lifecycle
> off on a live install without touching the code, set `WPMS_PROPOSAL_FLOW=0` in
> the environment; sign-up then creates the pending booking it always did and the
> proposal pages say the flow is switched off.
>
> **Upgrading a database that was created before v2.29?** One more migration,
> and this one is **optional**: `migrations/v2.29_vendor_plan.sql` adds
> `service_id` and `service_qty` to `tblvendor_bookings`, so a deal can remember
> WHICH offering it was made for and at what quantity — the "Classic buffet × 180
> guests" behind a figure. Without it **Vendor Plan** and the couple's **Vendor
> Plan & Total** still build and total every package deal and agreed amount, the
> page says which migration is missing, and only "plan a specific service" is
> refused. Nothing to backfill: every existing deal keeps `NULL` in both columns,
> exactly like a custom arrangement. Fresh installs from `deploy/schema.sql`
> already carry both columns. Idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v2.28?** One more migration,
> and this one is **optional**: `migrations/v2.28_vendor_preferences.sql` adds
> `tblvendor_preferences`, the couple's taste tags and per-category budget that
> the marketplaces match vendor services against. Without it the couple's
> **Taste & Budget** page and both marketplace fit marks say the migration is
> missing; every vendor card still renders and sells exactly as before. Nothing
> to backfill (no row means no preference stated). Fresh installs from
> `deploy/schema.sql` already carry the table. Idempotent: re-running is harmless.
>
> **Upgrading a database that was created before v2.27?** One more migration,
> and this one is **optional**: `migrations/v2.27_vendor_services.sql` adds
> `tblservice_catalog` (39 seeded services across the ten vendor categories, each
> with a mandatory unit) and `tblvendor_services` (what one business sells, from
> the catalogue or its own). Without it Vendor → My Services says which
> migration to apply, and the marketplaces and public profiles simply show no
> services. Packages are untouched and keep selling as bundles; nothing to
> backfill. Fresh installs from `deploy/schema.sql` already carry both tables
> AND the catalogue rows, so this is only for an existing database. Idempotent:
> re-running it is harmless.
>
> **Upgrading a database that was created before v2.25?** One more migration,
> and this one is **optional**: `migrations/v2.25_vendor_threads.sql` adds
> `tblvendor_messages`, the conversation each deal carries between the couple,
> the vendor and the couple's planner. Without it the deal ledgers keep working
> and say the conversation cannot be loaded yet; existing enquiries keep their
> text in `tblvendor_bookings.notes` exactly as before, and nothing is
> backfilled. Idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v2.24?** One more migration,
> and this one is **optional**: `migrations/v2.24_event_vendor_needs.sql` adds
> `tblevent_vendor_needs`, where the vendor coverage board
> (`admin/vendor_coverage.php`) keeps each function's list of required services.
> Without it the board keeps working exactly as before — every function counts
> all ten categories — and says the list cannot be edited. Nothing to backfill:
> a function with no row starts from all ten, so no wedding loses its matrix on
> upgrade. Idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v2.23?** One more migration,
> and this one is **optional**: `migrations/v2.23_business_pulse_snapshots.sql`
> adds `tblops_snapshots`, where the Monday business pulse keeps each week's
> aggregate snapshot so the next brief can print `+3` / `+95,000` / `+2.1pp`
> beside every figure. Without it the brief still sends and says the comparison
> is off; nothing else reads the table. It keeps 26 weeks per account by itself —
> there is no pruning cron to add, and nothing to backfill (the first brief after
> applying it is the baseline). Idempotent: re-running it is harmless.
>
> **Upgrading a database that was created before v2.15?** One more migration,
> and this one is **optional**: `migrations/v2.15_job_heartbeats.sql` adds
> `tbljob_heartbeats`, where the scheduled jobs record what they last did so the
> platform can report its own silence (see Part 6). Without it nothing breaks:
> every job runs exactly as before, the heartbeat write is skipped, and **Job
> Health** says the table is missing — but the cron bridge's hourly `job_watch`
> then answers HTTP 500 with "tbljob_heartbeats is not installed", because a
> scheduler whose jobs cannot be watched is exactly what that check exists to
> report. Apply it, or drop `job_watch` from the bridge. Nothing to backfill,
> fresh installs from `deploy/schema.sql` already carry the table, and it is
> idempotent: re-running it is harmless.
>
> **Want to let an AI client (Claude, ChatGPT, Cursor) read the planner over
> MCP?** One more migration, and this one is **optional to the platform and
> required for that feature**: `migrations/v2.16_api_tokens.sql` adds
> `tblapi_tokens` (the hashed tokens, one row each) and `tblapi_audit` (what each
> token asked for). Nothing else on the site changes if you skip it: `mcp.php`
> answers **503** and says which file to apply, and `admin/api_tokens.php` shows
> the same. There is no fail-open path — an auth layer with no table to check a
> token against refuses rather than welcomes. Nothing to backfill, fresh
> installs already carry both tables, and it is idempotent: re-running it is
> harmless. See README §AI access (MCP) for the switches and how to connect a
> client.
>
> **Want that assistant to be able to DO things — without letting it decide
> alone?** One more optional migration: `migrations/v2.17_mcp_approvals.sql`
> adds `tblapi_approvals` (one row per request for a money or message action,
> and one row per decision about it) and five columns to `tblapi_audit` (the
> tier, the idempotency key, the approving decision, the payload hash it was
> bound to, and a short note about what changed). With it in place, and
> `WPMS_MCP_WRITES=1`, an assistant may add a guest, change one, mark invitations
> or tick a checklist item (Tier 2 — it must send `confirm: true`, and otherwise
> gets a preview of exactly what would change), and may ASK for an SMS to a guest
> list, a payment, an M-Pesa deposit prompt or a planner assignment (Tier 3). It
> cannot perform a Tier-3 action by itself: the request waits on **AI Approvals**
> (`admin/mcp_approvals.php`, with a badge in the sidebar) until a signed-in
> planner approves that exact payload — which mints a short-lived, single-use
> approval bound to the tool and a hash of the message, amount or phone — and the
> action then happens exactly once. Skip it and the write tools simply refuse;
> nothing else on the site changes. Set `WPMS_MCP_APPROVAL_SECRET` (32+ random
> characters) once writes are in use, so approvals are signed with a key you can
> rotate. Nothing to backfill, fresh installs already carry the table, and
> re-running it is harmless.
>
> **And the other half of that promise — the deadline.** One more optional
> migration: `migrations/v2.19_mcp_approval_expiry.sql` adds `expired_at` to
> `tblapi_approvals`. A Tier-3 request that nobody answers inside its 30-minute
> window (or one a planner approved that the assistant never used) is closed by
> `scripts/mcp_approval_sweep.php` — the row is marked once, the planner gets one
> bell naming what died, and the assistant's call log records that nothing was
> sent or recorded. Without the migration the platform keeps working exactly as
> before (expired requests are simply refused); the sweep reports the missing
> file and stays a no-op. Add the cron line in Part 6 either way: a deadline that
> passes in silence is how a planner comes to believe an SMS went out.
>
> **And one click to ask again.** One more optional migration:
> `migrations/v2.20_mcp_approval_rerequest.sql` adds `re_request_of` and
> `re_requested_by` to `tblapi_approvals`. An expired request under **Decisions
> and expiries** then carries a **Re-request this payload** button: it creates a
> fresh pending request with a new 30-minute window, same tool and payload, and
> leaves the old row expired. The assistant's next identical call finds the new
> reference instead of starting over; the planner still approves it explicitly,
> and nothing is sent by the click itself. Without the migration the button still
> works (the revived request simply does not record which reference it replaced),
> so applying it is about the audit chain, not the flow.
>
> **Optional: keep an assistant's reads off the primary database.** If your host
> gives you a read replica, set `WPMS_DB_REPLICA_HOST` (plus `USER`, `PASS`,
> `NAME` if the replica's credentials differ) in `.env`. MCP read tools then
> answer from it; every write, token check, audit row and approval decision
> stays on `WPMS_DB_*`. Leave the host empty on a single-database plan — that is
> the default and nothing changes. A replica that cannot be reached falls back
> to the primary and logs one line, so a typo costs a log entry, not the
> assistant. Expect replication lag: a row written a second ago may be absent
> from the next read. `migrations/v2.18_mcp_grants.sql`'s read user is the
> credential to pair with it.

> The catalog dumps contain **no user accounts**; `seed_admin.sql` adds
> exactly one (yours). Staff/couple self-registration stays available for
> everyone else.

---

## Part 3 — Upload the code (15 min)

1. cPanel → **File Manager** → open `public_html` (delete any default
   placeholder `index.html` first).
2. **Upload** the project ZIP → back in File Manager, right-click the ZIP →
   **Extract** → extract **into** `public_html` (not a subfolder) so that
   `index.php`, `login.php`, `admin/` sit directly under `public_html`.
3. Still in File Manager: **Settings** (top right) → tick **Show Hidden Files**.
4. Upload `.env` (from `.env.example`, values filled in) **one level ABOVE
   `public_html`** — i.e. into your account home `/home/USERNAME/`. This keeps
   secrets unreachable from the web.
5. Permissions: leave directories 755, files 644 (cPanel defaults are fine).
   If an upload ever fails to write, set `admin/upload/*` to 755.
6. **Never upload or commit the `deploy/` folder.** It holds
   `deploy/.env.production` (a real database password, the M-Pesa callback
   passes and the cron-bridge key), `deploy/seed_admin.sql` (a live
   super-admin bcrypt hash) and a 5.9 MB full-site tarball. It is listed in
   `.gitignore` as a whole directory precisely so an accidental `git add -A`
   cannot leak it. If it has ever been pushed to a remote, treat every value
   in it as burned and rotate: the DB password (cPanel → MySQL Databases),
   both `WPMS_MPESA_*_CALLBACK_PASS` values, and `WPMS_CRON_BRIDGE_KEY`.
   Only `.env.example` (placeholders) belongs in version control.

7. **Never upload `android/`.** It is the couples' app project, not a page: its
   `keystore/everafter-release.jks` is the release **signing key** and
   `keystore.properties` beside it holds that key's password in plain text.
   Whoever reads both can publish an update that every installed app will
   accept as genuine. The key never leaves the machine that builds the APK.
   `android/keystore.properties` and the whole `keystore/` folder are
   gitignored, and the root `.htaccess` now refuses `/android/` as a second
   layer — but not uploading it is the real protection.

8. **Never upload `.git`, `.freebuff/` or any dot-file.** If you zip the
   working copy instead of the build artifact, `.git/` rides along — and a
   reachable `.git/` leaks the whole source tree plus every secret ever
   committed to it. `.freebuff/` (local agent logs) and
   `.phpunit.result.cache` are dev artifacts with no business on a server.
   The root `.htaccess` denies every dot-path as a safety net, but the real
   fix is not uploading them: from the File Manager, tick **Show Hidden
   Files**, then delete `.git`, `.freebuff`, `.gitignore`,
   `.phpunit.result.cache` and `.gitattributes` from `public_html`.

> If your host forces the site into a subfolder (e.g. `public_html/app/`),
> everything still works — the app derives all paths at runtime. The public
> URL just becomes `yourdomain.co.ke/app/`.

---

## Part 4 — First sign-in (2 min)

1. Visit `https://yourdomain.co.ke/login.php`.
2. Sign in with the email you put in `seed_admin.sql` and the password from
   that file's comments. You land on the staff dashboard as Super Admin.
3. **Change the password now** via Users → your profile (optional but wise
   if the seeded file ever left your machine).

Other accounts, if you want them:

- A **couple/client** account: use the public sign-up hero on the homepage.
- An extra **planner/staff** account: visit
  `https://yourdomain.co.ke/planner_register.php`, register, then click the
  verification link from the email it queues. The 6-month trial starts
  immediately (payment can come later via M-Pesa).

> With `WPMS_MAIL_ENABLED=0` (recommended at first), verification emails are
> queued in `tbl_mail_logs` instead of sent. Either turn the flag on, or grab
> the verification URL from the `tbl_mail_logs` table in phpMyAdmin
> (`body` column contains the link).

---

## Part 5 — Health check, then lock down (5 min)

1. Edit `deploy_check.php` before upload: set `DEPLOY_CHECK_KEY` to any long
   random string.
2. Visit `https://yourdomain.co.ke/deploy_check.php?key=YOUR_KEY`.
3. Expect **ALL CHECKS PASSED**. Each failure prints `[FAIL]` with the exact
   reason; the final line is a machine-readable `RESULT: ok=N fail=M`. A
   `[SKIP]` line means a check could not run (e.g. the host can't self-request
   its own pages) — it is not a failure, so read the FAIL lines, not the skip.
4. **Re-run it as often as you like** while fixing problems — every check is
   read-only and changes nothing.
5. If any check fails the page answers **HTTP 500** (200 when all pass), so
   you can point an uptime monitor at it until you delete it.
6. **Delete `deploy_check.php` from the server** once green.
7. **Confirm the internal tree is unreachable.** The project root *is* the web
   root, so every one of these must answer **403** (or a styled 404). A 200 on
   any of them means `.htaccess` is not being read (`AllowOverride`) or the
   file was overwritten — fix that before going live:

   ```bash
   for p in /scripts/demo_reset.php /scripts/rsvp_reminders.php /deploy/schema.sql /migrations/v2.1_phase1_hardening.sql /.env /vendor/autoload.php /admin/include/config.php /.git/config /.git/HEAD /.gitignore /.phpunit.result.cache /.freebuff/; do
     printf '%-46s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "https://yourdomain.co.ke$p")"
   done
   ```

   **The `.git` row is the one people miss.** `.git/config`, `.git/HEAD` and
   `.git/index` have no dot-extension, so a `<FilesMatch>` rule can never cover
   them (`<FilesMatch>` only ever sees the last path segment) — they were
   served **200** until the dot-path rule was added. A readable `.git/` means a
   visitor can fetch `.git/objects` and reconstruct the entire repository, so
   always upload the site *contents*, never the working copy.

8. **Confirm directory listings are off.** A folder with no index file must
   answer **403**, not a browsable `Index of …` page:

   ```bash
   for p in /DESIGN/ /images/ /css/ /js/ /admin/calendar/ /admin/upload/; do
     printf '%-20s %s\n' "$p" "$(curl -s "https://yourdomain.co.ke$p" | grep -qi 'Index of' && echo 'LISTED — FIX ME' || echo ok)"
   done
   ```

   `DESIGN/` holds the homepage artwork SVGs, `/images/`, `/css/` and `/js/`
   hold live assets — they must keep answering **200 for direct file URLs**
   (e.g. `/DESIGN/checklist-ea2533….svg`). Only the listing disappears.
   `Options -Indexes` in the root `.htaccess` does this. If the host returns
   **500** for it, it denies `AllowOverride Options` — replace that block with
   `IndexIgnore *` inside `<IfModule mod_autoindex.c>` (same effect, weaker
   override requirement).

   **`/api/v1/` is the one directory that must stay reachable** — it is the
   Android app's door, and it refuses every call without a valid bearer. Check
   it answers JSON rather than a 403:

   ```bash
   curl -s https://yourdomain.co.ke/api/v1/?r=ping
   # {"ok":true,"data":{"api":"1.0",...}}

   # The money and message routes must refuse a stranger with 401 — not 404
   # (route missing) and not 500 (a class or table the server cannot load):
   curl -s -o /dev/null -w '%{http_code}\n' https://yourdomain.co.ke/api/v1/?r=budget    # 401
   curl -s -o /dev/null -w '%{http_code}\n' https://yourdomain.co.ke/api/v1/?r=messages  # 401
   ```

   Nothing under `scripts/` is a web page: those files back up the database,
   send SMS/email and prune rows. They are protected by two independent layers
   — the `.htaccess` deny rule and `scripts/cli_guard.php`, which makes every
   one of them refuse to run outside the CLI. The second layer is what covers a
   host that ignores `.htaccess` (Nginx, or Apache with `AllowOverride None`),
   which is how the exposure was originally found.

---

## Part 6 — Cron jobs (10 min)

cPanel → **Cron Jobs** → add each of these (HostPinnacle: `/usr/local/bin/php`;
if that fails try `/usr/bin/php` or plain `php`):

| Schedule | Command |
|---|---|
| Every 15 min | `php /home/USERNAME/public_html/scripts/mail_retry.php --quiet` |
| Daily 02:15 | `/bin/bash /home/USERNAME/public_html/scripts/db_backup.sh --quiet` |
| Daily 09:00 | `php /home/USERNAME/public_html/scripts/rsvp_reminders.php --quiet` |
| Daily 10:00 | `php /home/USERNAME/public_html/scripts/deadline_sms.php --quiet` |
| Daily 04:30 | `php /home/USERNAME/public_html/scripts/notify_prune.php --quiet` |
| Every 10 min | `php /home/USERNAME/public_html/scripts/mcp_approval_sweep.php --quiet` |
| Daily 06:00 | `php /home/USERNAME/public_html/scripts/business_pulse_email.php --quiet` |
| Daily 08:30 | `php /home/USERNAME/public_html/scripts/thread_escalation.php --quiet` |
| Every 30 min | `php /home/USERNAME/public_html/scripts/job_watch.php --quiet` |

The **06:00 business pulse** emails the owner's weekly brief — the platform's
figures, the findings worth acting on and the five things to fix first, rendered
from the same aggregates the AI tools read. Each figure carries its change since
the last snapshot taken before that week (`tblops_snapshots`, v2.23), so a flat
week reads as flat rather than as a good one. It runs daily and sends only on
Monday (Africa/Nairobi), stamping the ISO week per owner so a week goes out
once. With `WPMS_MAIL_ENABLED=0` the brief is queued in `tbl_mail_logs` and
delivered by `mail_retry.php`; set `WPMS_PULSE_TO` to send it to a shared
mailbox instead of every super admin — that mailbox gets its own comparison
history (scope 0), separate from every owner's. Preview without sending:
`php scripts/business_pulse_email.php --dry-run` (a preview writes no snapshot).

The **08:30 reply escalation** is the one that protects the couple's
convenience directly: when a vendor has left the newest message on a deal
unanswered for a day, the couple's planner gets one bell (and the usual email)
— "Linda & Brian have been waiting 2 days for Nyama Choma Masters to reply" —
with a link straight into the thread. It never messages the couple, never chases
a completed or cancelled deal, ignores read state (opening a question is not
answering it), and stamps each message it reports, so a re-run or a second
pinger cannot ring the same bell twice. Tune the grace with `--hours=48`; the
couple's own ledger keeps showing the wait in the meantime.

The **02:15 backup job** writes gzip dumps to `~/backups/everafter/` (outside
the web root, never web-downloadable), keeps 14 days, verifies each archive's
integrity, and logs to `~/backups/everafter/backup.log`. Restore = download a
`.sql.gz`, extract, phpMyAdmin → Import. Tweak retention with
`BACKUP_KEEP_DAYS=30` prepended to the command if you want a month.

Subscription auto-renewal (`scripts/subscription_renewals.php`) is optional —
add it only after enabling `WPMS_SUB_AUTO_RENEW=1` and STK push. (The cron
bridge already includes it in `job=all`; it stays a no-op until the flag is on.)

Each script locks itself against overlapping runs and exits 0 when its feature
flag is off, so this crontab is safe to leave as-is.

**The watchdog (v2.15).** The last line above is the one that notices when the
others stop. All eight scheduled jobs (`mail_retry.php`, `notify_prune.php`,
`subscription_renewals.php`, `rsvp_reminders.php`, `deadline_sms.php`,
`mcp_approval_sweep.php`, `business_pulse_email.php`, `thread_escalation.php`)
leave a
heartbeat in `tbljob_heartbeats` every time they really run — what they did, and
how much work their own selection offered them — and `scripts/job_watch.php`
reads those heartbeats against the schedule each job is deployed with: it exits
`1` (and tells the super admins in the bell) when a job that HAS run has gone
silent, is failing (a non-zero exit, or a run that delivered nothing while rows
waited), or reported success while the queue it reported had work waiting. It exits `0` when everything reported in time, and `2`
on a database that has not run `migrations/v2.15_job_heartbeats.sql` yet (the
jobs still run — they just have nowhere to leave a heartbeat). The operator can
read the same table any time under **Job Health** in the sidebar, which also
re-checks the moment it is opened, and the cron bridge runs the watchdog hourly
as its `job_watch` job (a finding there makes the bridge answer **HTTP 500**,
so an UptimeRobot-style monitor catches it). No extra configuration: no key, no
flag, nothing to enable — an install that would rather not receive the bells
and emails sets `WPMS_JOB_ALERTS=0` in `.env`, which silences those two
channels only (the report, the exit code and the Job Health page are
unaffected, so the silence is still visible where it matters).

### No Cron Jobs icon on your plan? Use the cron bridge

`cron_bridge.php` lets a free external pinger (cron-job.org, UptimeRobot)
trigger every job over HTTP:

1. Set a long random key in `.env`: `WPMS_CRON_BRIDGE_KEY=...` (or edit the
   constant at the top of `cron_bridge.php`). With the placeholder still in
   place the endpoint refuses every request. Optionally set
   `WPMS_SUB_AUTO_RENEW=1` to also run subscription renewals from `job=all`
   (it is a no-op while the flag is off).
2. On cron-job.org (free, 1-minute granularity) create one monitor hitting
   every 15 minutes:
   `https://yourdomain.co.ke/cron_bridge.php?key=YOUR_KEY&job=all`
   The bridge's built-in per-job throttles do the daily scheduling — the
   pinger only provides the heartbeat, and an over-eager pinger can't
   double-run anything (SMS jobs cost credits).
3. Check health any time:
   `https://yourdomain.co.ke/cron_bridge.php?key=YOUR_KEY&job=status`
   Manual one-off run: `&job=db_backup&force=1`.
4. **Reading the result.** Each run prints one line per job — `OK`, `SKIP`
   (not due yet, or another run held the lock — benign) or `FAIL` with the
   real exit code and the first line of output. `job=all` ends with
   `RESULT: ok=N skip=M fail=K`, and the response is **HTTP 500 if any job
   FAILED** (200 otherwise) so a status monitor alerts on real breakage
   only. A second request that arrives while one is already running returns
   `BUSY` (200) and does nothing — safe to re-run freely.
5. If a job shows `FAIL ... no exec function available`, the host has
   disabled command execution — ask HostPinnacle support to enable cron on
   the plan (they will), or run the scripts manually from cPanel Terminal.

> Note: the bridge state file `.cron_bridge_state.json` lives in the web
> root but is blocked from download by the root `.htaccess` (`*.json` deny).

---

## Part 7 — M-Pesa go-live (only when you're ready)

1. Get production Daraja credentials (consumer key/secret, passkey, short
   code) from the Safaricom Daraja portal.
2. Set in `.env`: `WPMS_MPESA_STK=1`, the credentials,
   `WPMS_MPESA_STK_ENV=production`, and a strong `WPMS_MPESA_STK_CALLBACK_PASS`.
3. On the Daraja portal, register your callback URLs:
   - `https://yourdomain.co.ke/mpesa_stk_callback.php?key=YOUR_CALLBACK_PASS`
   - `https://yourdomain.co.ke/mpesa_c2b.php?key=YOUR_CALLBACK_PASS`
4. Also set `WPMS_MPESA_SHORTCODE` and optionally `WPMS_MPESA_VERIFY=1` so
   typed transaction codes are auto-verified against real Paybill payments.
5. Sandbox first (default `WPMS_MPESA_STK_ENV=sandbox`), then flip to
   production.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| **500 on every page** | Check `WPMS_DEBUG=1` in `.env` briefly, reload, read the error; or cPanel → Errors (raw logs). Most often a PHP version below 8.2 — cPanel → MultiPHP Manager → set 8.4 or 8.5. |
| **"Database failed to connect"** | `.env` not found or wrong values. Check it's in the account HOME and names match Part 1 exactly. |
| **deploy_check says "missing tables"** | Re-run both SQL imports from Part 2; ensure `schema.sql` ran before `content.sql`. If only `tblsubscription_plan_changes` is missing, the database predates v2.8 — import `migrations/v2.8_plan_pricing_audit.sql`. If `tbljob_heartbeats` or `tblpublic_throttle` is missing, the database was imported from a `schema.sql` older than v2.15 — import `migrations/v2.15_job_heartbeats.sql` and `migrations/v2.14_public_abuse_guard.sql` (both optional to the page, neither optional to the check). |
| **Cron bridge answers 500 with "tbljob_heartbeats is not installed"** | The bridge's hourly `job_watch` cannot judge jobs on a database that has no heartbeat table. Import `migrations/v2.15_job_heartbeats.sql`, or remove `job_watch` from `ALL_JOBS` in `cron_bridge.php` if you would rather not monitor the jobs at all. |
| **The bell is quiet about jobs but Job Health shows findings** | `WPMS_JOB_ALERTS=0` is set in `.env` (or `WPMS_LOGIN_THROTTLE_OFF=1` is). The findings are still reported, exited on and shown — only the bell and the email are silenced. |
| **Uploads fail silently** | `admin/upload/*` needs write permission for the PHP user. In cPanel File Manager: right-click → Change Permissions → 755. |
| **Emails never send** | Expected while `WPMS_MAIL_ENABLED=0` — everything queues in `tbl_mail_logs`. Flip the flag once you've set up a mail route (cPanel Email Deliverability, or an SMTP relay plugin). |
| **CSS/images missing** | Files extracted into a subfolder — see the note in Part 3. |
| **Login says "too many attempts"** | The built-in throttle did its job. Wait, or clear `tbllogin_throttle` in phpMyAdmin. |
| **`/mcp.php` answers 503** | Either `WPMS_MCP=0` is set in `.env`, or `migrations/v2.16_api_tokens.sql` has not been applied. `admin/api_tokens.php` says which, in words, at the top of the page. |
| **An AI client says 401 unauthorized** | The token was mistyped, revoked, or expired — the page `admin/api_tokens.php` lists every token with its state and when it was last used, and mints a new one. Tokens are shown once, by design: nothing can re-display a secret, because only its hash is stored. |
| **An AI client says 403 "Origin not allowed"** | A browser-based client (claude.ai, ChatGPT connectors) sends an `Origin`, and a self-hosted install has to be told that origin is trusted: set `WPMS_MCP_ORIGINS=https://claude.ai` in `.env`. Server-side clients (Claude Code, curl, an internal agent) send no Origin and never need this. |
| **The assistant can read but refuses to change anything** | Writes are off unless `WPMS_MCP_WRITES=1` is in `.env` — deliberately, because an assistant may read a wedding but should not edit one without the operator saying so. The token also needs the matching write scope, and `whoami` reports both facts to the client. |
| **The assistant says an action is "awaiting approval"** | That is a Tier-3 action (an SMS to a guest list, a payment, an M-Pesa deposit prompt, a planner assignment). It has been done NOTHING about: the request is on `admin/mcp_approvals.php` with the exact wording, audience or amount, and a signed-in planner approves or refuses it there. The badge in the sidebar counts what is waiting. An approval is single-use and expires in 15 minutes; if the request's own 30-minute window expires, press **Re-request this payload** under **Decisions and expiries** (v2.20) and approve the fresh one — the assistant does not need to ask again. |
| **"that approval does not verify" / "minted for a different payload"** | The approval string authorises ONE payload, and the message, amount, audience or planner id has changed since it was minted — or `WPMS_MCP_APPROVAL_SECRET` was rotated, which invalidates every approval outstanding at that moment. Mint a fresh one for the exact call. |
| **AI Approvals is empty but the assistant is waiting** | The request expires 30 minutes after it is asked for and is only pruned later; a request for a booking the planner cannot open is shown but not actionable. If nothing is listed at all, `migrations/v2.17_mcp_approvals.sql` has not been applied — Tier-3 tools then refuse outright. An expired request is listed under **Decisions and expiries** as soon as its window closes (and stays there after the sweep, v2.19), with **Re-request this payload** (v2.20) to open a fresh one; the sweep's bell says which references died. |

---

## Post-deploy checklist

- [ ] `deploy_check.php` deleted from the server
- [ ] `/scripts/`, `/deploy/`, `/migrations/`, `/tests/`, `/vendor/`,
      `/admin/include/` and `.env` all answer **403**, and a missing page shows
      the styled 404 (not Apache's default page)
- [ ] `/.git/config`, `/.git/HEAD`, `/.gitignore`, `.phpunit.result.cache` and
      `/.freebuff/` all answer **403** (the dot-path rule) — never upload the
      `.git` folder at all
- [ ] `curl -s https://yourdomain.co.ke/images/ | grep -i 'Index of'` prints
      nothing (directory listings are off), while
      `https://yourdomain.co.ke/images/logo/everafter-crest.png` still loads
- [ ] `deploy/` was never committed or pushed (if it was: rotate every secret in
      `deploy/.env.production`)
- [ ] `/mcp.php` is reachable (it is deliberately NOT in the `.htaccess`
      denylist) and answers **401 with `WWW-Authenticate: Bearer`** to a POST
      with no token: `curl -s -i -X POST https://yourdomain.co.ke/mcp.php -d '{}'`
- [ ] A GET on `/mcp.php` answers **405** (no SSE stream is offered) and
      `admin/include/ApiToken.php` answers 403
- [ ] `admin/api_tokens.php` is reachable while signed in, and one token minted
      there really works:
      `curl -s https://yourdomain.co.ke/mcp.php -H 'Authorization: Bearer wpms_…' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'`
- [ ] `.env` in the account HOME (not inside `public_html`), with real values
- [ ] `WPMS_DEBUG` unset or `0`
- [ ] PHP set to **8.4/8.5** in MultiPHP Manager, and `deploy_check` shows no
      `[INFO] PHP branch is security-only` line (see
      [`SECURITY_PATCHING.md`](SECURITY_PATCHING.md))
- [ ] HTTPS padlock on all pages (the root .htaccess redirects automatically)
- [ ] Super-admin seeded (`seed_admin.sql`) and signed in successfully
- [ ] A test couple registered; a test booking visible on the staff dashboard
- [ ] All 6 cron jobs listed in cPanel Cron Jobs (incl. the nightly backup)
- [ ] `~/backups/everafter/` contains a dump after the first 02:15 run
- [ ] M-Pesa sandbox test payment completed (before production keys)
- [ ] Backup scheduled: cPanel → Backup → Download a full account backup
