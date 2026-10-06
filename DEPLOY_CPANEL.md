# Deploying Everafter Planner to cPanel (Shared Hosting)

Total time: 45–60 minutes. Your domain + SSL must already be active (they are).

---

## HostPinnacle quick notes (your host)

- **Getting into cPanel:** client area (hostpinnacle.co.ke/clients) →
  **Services** → your plan → **Login to cPanel** — or browse directly to
  `https://yourdomain.co.ke/cpanel` with the cPanel credentials from your
  welcome email.
- **PHP version:** cPanel → **MultiPHP Manager** (Software section) → select
  your domain → set PHP **8.2 or 8.3** → Apply. The app requires >= 8.2;
  `deploy_check.php` confirms it in step 5.
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
  verification link from the email it queues. The 14-day trial starts
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
3. Expect **ALL CHECKS PASSED**. If something fails, the exact line tells you
   what to fix.
4. **Delete `deploy_check.php` from the server** once green.

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

The **02:15 backup job** writes gzip dumps to `~/backups/everafter/` (outside
the web root, never web-downloadable), keeps 14 days, verifies each archive's
integrity, and logs to `~/backups/everafter/backup.log`. Restore = download a
`.sql.gz`, extract, phpMyAdmin → Import. Tweak retention with
`BACKUP_KEEP_DAYS=30` prepended to the command if you want a month.

Subscription auto-renewal (`scripts/subscription_renewals.php`) is optional —
add it only after enabling `WPMS_SUB_AUTO_RENEW=1` and STK push.

Each script locks itself against overlapping runs and exits 0 when its feature
flag is off, so this crontab is safe to leave as-is.

### No Cron Jobs icon on your plan? Use the cron bridge

`cron_bridge.php` lets a free external pinger (cron-job.org, UptimeRobot)
trigger every job over HTTP:

1. Set a long random key in `.env`: `WPMS_CRON_BRIDGE_KEY=...` (or edit the
   constant at the top of `cron_bridge.php`). With the placeholder still in
   place the endpoint refuses every request.
2. On cron-job.org (free, 1-minute granularity) create one monitor hitting
   every 15 minutes:
   `https://yourdomain.co.ke/cron_bridge.php?key=YOUR_KEY&job=all`
   The bridge's built-in per-job throttles do the daily scheduling — the
   pinger only provides the heartbeat, and an over-eager pinger can't
   double-run anything (SMS jobs cost credits).
3. Check health any time:
   `https://yourdomain.co.ke/cron_bridge.php?key=YOUR_KEY&job=status`
   Manual one-off run: `&job=db_backup&force=1`.
4. If `status` shows `FAIL ... no exec function available`, the host has
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
| **500 on every page** | Check `WPMS_DEBUG=1` in `.env` briefly, reload, read the error; or cPanel → Errors (raw logs). Most often a PHP version below 8.2 — cPanel → Select PHP Version → set 8.2+. |
| **"Database failed to connect"** | `.env` not found or wrong values. Check it's in the account HOME and names match Part 1 exactly. |
| **deploy_check says "missing tables"** | Re-run both SQL imports from Part 2; ensure `schema.sql` ran before `content.sql`. |
| **Uploads fail silently** | `admin/upload/*` needs write permission for the PHP user. In cPanel File Manager: right-click → Change Permissions → 755. |
| **Emails never send** | Expected while `WPMS_MAIL_ENABLED=0` — everything queues in `tbl_mail_logs`. Flip the flag once you've set up a mail route (cPanel Email Deliverability, or an SMTP relay plugin). |
| **CSS/images missing** | Files extracted into a subfolder — see the note in Part 3. |
| **Login says "too many attempts"** | The built-in throttle did its job. Wait, or clear `tbllogin_throttle` in phpMyAdmin. |

---

## Post-deploy checklist

- [ ] `deploy_check.php` deleted from the server
- [ ] `.env` in the account HOME (not inside `public_html`), with real values
- [ ] `WPMS_DEBUG` unset or `0`
- [ ] HTTPS padlock on all pages (the root .htaccess redirects automatically)
- [ ] Super-admin seeded (`seed_admin.sql`) and signed in successfully
- [ ] A test couple registered; a test booking visible on the staff dashboard
- [ ] All 5 cron jobs listed in cPanel Cron Jobs (incl. the nightly backup)
- [ ] `~/backups/everafter/` contains a dump after the first 02:15 run
- [ ] M-Pesa sandbox test payment completed (before production keys)
- [ ] Backup scheduled: cPanel → Backup → Download a full account backup
