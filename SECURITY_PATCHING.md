# Security patching & PHP upgrades

This app is plain PHP with no runtime Composer packages (PHPUnit is the only
dependency and it is dev-only). The things that need patching, in order of
importance, are:

1. **The PHP runtime** — supplied by the host, not by this repository.
2. **Bundled front-end libraries** — jQuery, Bootstrap and Popper, shipped in
   `js/` and `admin/js/`.
3. **This repository's own code** — reviewed by the static sweeps in
   `composer code-audit`, but a new class of bug still needs a code change.

Nothing here replaces the host's OS/control-panel updates, TLS renewal or
backups. Those stay with the hosting account.

---

## Status after the October 2026 patch

| Component | Version now in the repo | Why it changed |
|---|---|---|
| PHP (runtime) | Host must run **8.4 or 8.5** (verified on 8.4.27 and 8.5.12) | PHP 8.2 is security-only and **ends 31 Dec 2026**; 8.3 is security-only. `deploy_check.php` now fails a branch past its published EOL and warns on a security-only one. |
| jQuery (slim + full) | **3.7.1** (`js/jquery-3.7.1*.js`, `admin/js/jquery-3.7.1*.js`) | 3.2.1 carried CVE-2019-11358 (prototype pollution) and CVE-2020-11022 / CVE-2020-11023 (XSS via DOM manipulation). |
| jQuery in `admin/calendar/` | **3.7.1** (`admin/calendar/jquery-3.7.1.min.js`) | Was 3.3.1, affected by the same DOM XSS advisories. The three FullCalendar pages already pointed at the versioned name, so the file was renamed to match — until then those pages loaded no jQuery at all and Bootstrap/FullCalendar threw `$ is not defined`. |
| Bootstrap CSS/JS | **4.6.2** | 4.0.0 predates the tooltip/popover XSS fixes; 4.6.2 is the last patched 4.x release. |
| Popper | **1.16.1** | Matches Bootstrap 4.6.2's supported Popper line. |
| DataTables (+ Bootstrap 4 integration) | **1.13.11** | 1.10.16 carried CVE-2021-23445 (XSS through the HTML-escape helper, fixed 1.11.3) and predates the prototype-pollution fixes in 1.10.22/1.10.23. 1.13.11 is the last 1.x release, so the existing 1.10-era API and Bootstrap 4 integration needed no rewrite; DataTables 2.x/3.x are a migration, not a drop-in. |
| bootstrap-datepicker | **1.10.1** | Replaced the 2017 v1.6.4 bundle. No CVE was identified for 1.6.4, but it is nine years old and sits on an input that accepts a date from every admin form. |
| blueimp Gallery | **3.4.0** | Replaced the unversioned v2-era bundle and **removed jQuery Lightbox 2.6** (2015, unmaintained) from `gallery.php` and `admin/photos_view.php`. Both pages now use `blueimp.Gallery(links, options)` with the dialog markup version 3 requires and the control images in `img/` and `admin/img/`. No CVE was identified for the old gallery; Lightbox had none published either, but neither was maintained. |
| Composer runtime deps | **none** | `composer audit --locked` is clean; PHPUnit is dev-only and never uploaded (`.htaccess` denies `vendor/`). |

Two mechanical details matter when reading the diff:

- The jQuery files were **renamed with their version**. That also busts the
  7-day JS cache in `.htaccess`, so a browser that cached the vulnerable file
  fetches the patched one on the next page load. Do not rename them back to
  version-less names.
- Bootstrap, DataTables and the datepicker have version-less filenames, so
  every include carries a cache-busting query: `?v=4.6.2`,
  `?v=1.13.11` and `?v=1.10.1`. **Bump that string whenever you replace the
  files**, or returning visitors keep the old copy for a week.

Browser verification for this patch: DataTables 1.13.11 on `admin/users.php`
(6 rows, live search filtering, Bootstrap 4 wrapper, length select), and
bootstrap-datepicker 1.10.1 on `admin/client_add.php` (42-day, 12-month calendar
opens on the wedding-date field), and the lightbox on both `gallery.php` and
`admin/photos_view.php` (clicking a photo opens the slide at that index, Next
titles the correct photo, Close returns to the page, and the admin Edit button
still navigates to the editor instead of opening the gallery). The dialog markup
and control images (`img/`, `admin/img/`) are required by Gallery 3 and were
verified to load.

### Dead front-end assets removed

Once Lightbox was gone, a reference sweep over every PHP, HTML, JS, CSS and
Markdown file found a second layer of bundles that no page loaded. They were
deleted rather than left in the web root, where an unmaintained script is one
stray include away from being live again:

| Removed | What it was |
|---|---|
| `lightbox/` (CSS, JS, 4 images) | jQuery Lightbox 2.6 — the directory itself, not just its includes |
| `css/lightbox.css`, `js/lightbox-2.6.min.js` | Duplicate copies of the same 2015 build in the shared asset folders |
| `dist/`, `admin/dist/` | PhotoSwipe 4.1.2 (2017) and its default skin; the app moved to blueimp Gallery and no live page includes it |
| `js/picker.date.js`, `css/classic.date.css` | pickadate's date picker, superseded by bootstrap-datepicker; its `picker.js` core was already absent |
| `js/sb-admin-2.js`, `admin/js/sb-admin-2.js` | SB Admin 2 theme script, unused since the dashboards were rebuilt |
| `js/superbox.js`, `admin/js/superbox.js` | SuperBox gallery script, unused after the photo pages moved to blueimp Gallery |
| `js/lazyload.js`, `admin/js/lazyload.js` | 2015 lazy-loader; nothing calls it |
| `js/gallery-v2.demo.min.js`, `admin/js/gallery-v2.demo.min.js` | blueimp Gallery's demo configuration, not app code |
| `js/v.3bootstrap.min.js` | Bootstrap 3 JS kept next to the 4.6.2 bundle; no page loaded it |
| `css/carousel.css` | Hand-written carousel styles for markup the site no longer has |
| `css/font-awesome.min.css`, `css/materialdesignicons.min.css` | Root copies of stylesheets every page actually links as `admin/css/…` or from the MDI CDN; the MDI copy was byte-identical to `admin/css/materialdesignicons.min.css`, and both pulled icon fonts from a root `fonts/` folder that never existed |
| `admin/css/materialdesignicons.min.css` | The last bundled icon stylesheet. No page ever linked it — every admin page loads Material Design Icons from the CDN — so its font was never fetched. `admin/include/sidebar.php` described it as the layout's icon source, a claim now corrected to name the CDN builds the pages really use |
| `calendar/` (root, 6 files) | A byte-for-byte duplicate of `admin/calendar/`; every FullCalendar page is an admin page and loads `calendar/…` relative to `admin/`, so nothing ever reached the root copy |

The sweep is repeatable: list a folder's files and grep the rest of the
codebase for each basename. The only remaining mention of the PhotoSwipe paths
is inside `admin/photos_view._testphp`, a stale copy of the page that the server
never executes; it was left in place.

### Fonts the remaining stylesheets reference

Two stylesheets still ship Font Awesome, and both pointed at folders that were
never uploaded — the FullCalendar toolbar therefore drew its prev/next arrows as
empty boxes on `admin/task_all_calendar.php` and
`admin/client_manage_account_details.php`:

| Stylesheet | Faces it asks for | Where the files now live |
|---|---|---|
| `admin/css/font-awesome.min.css` (4.7.0) — dashboard, revenue, couple-account pages | `fontawesome-webfont` | `admin/fonts/fontawesome-webfont.woff2`, `.woff` |
| `admin/calendar/all.css` (Font Awesome Free 5.0.6) — the FullCalendar pages | `fa-solid-900`, `fa-regular-400`, `fa-brands-400` | `admin/webfonts/*.woff2`, `*.woff` |

Two deliberate choices:

- **Only woff2 and woff are shipped, and the stylesheets now say so.** The
  upstream `src` lists also offered eot (IE 8/9), ttf and svg; those files were
  never in the repo, and keeping the entries meant a browser landing on a
  format we do not have would 404. The eot/ttf/svg URLs are removed from all
  four `@font-face` blocks, leaving `url(…woff2) format("woff2"), url(…woff)
  format("woff")`, and each edited file carries a one-line
  `/* local trim: … */` note after its licence header so the divergence from
  upstream is obvious when the next upgrade lands. Untouched: icon glyph rules,
  `.fab`/`.far`/`.fas`/`.fa` family mappings. Adding the missing formats
  instead would have cost ~1.5 MB for glyphs nobody requests.
- **The Font Awesome 5 fonts are 5.15.4**, the last 5.x, while the CSS is
  5.0.6: the 5.0.6 webfonts are not published on npm or jsDelivr, and Font
  Awesome keeps codepoints stable across 5.x, so 5.15.4 covers every icon the
  older stylesheet defines. Verified in the browser: the prev/next arrows render
  as real chevrons, `document.fonts.check('900 16px "Font Awesome 5 Free"')` is
  true, and both font files answer 200.

The root `.htaccess` now declares `font/woff2` and `font/woff` under
`<IfModule mod_mime.c>` and caches them for 30 days, so a host whose
`mime.types` lacks the modern mappings cannot fall back to
`application/octet-stream`.

Two PHP 8.5 deprecations were fixed so the app is clean on the newest branch:
`PDO::MYSQL_ATTR_INIT_COMMAND` is now resolved through
`Pdo\Mysql::ATTR_INIT_COMMAND` when it exists (with the old constant as the
8.2/8.3 fallback) in `admin/include/database.php`, and the `curl_close()` calls
— a no-op since PHP 8.0 — were removed from the app, scripts and tests.

No database migration was involved.

---

## Moving production to a supported PHP

Production is cPanel (HostPinnacle). The code is unchanged by the version
selection — the host decides the runtime.

1. cPanel → **MultiPHP Manager** (Software section).
2. Tick the domain, choose **PHP 8.4** (or 8.5 once the suite is green on it),
   **Apply**.
3. Open `deploy_check.php?key=…` and confirm:
   - `[ OK ] PHP >= 8.2 (minimum this codebase supports)`
   - `[ OK ] PHP branch still supported`
   - no `[INFO] PHP branch is security-only` line
4. Delete `deploy_check.php` again when green — it is a temporary tool.
5. Sign in and open a few real pages (dashboard, a client, the vendor coverage
   board, a public page) and check the browser console for errors.

PHP 8.2 disappears from support on **31 December 2026**. Date the upgrade before
then; a stale MultiPHP selection is the most common way this app ends up running
an unpatched interpreter.

### Verifying a new PHP locally before the host

The CI workflow (`.github/workflows/ci.yml`) runs the full suite on **8.2, 8.4
and 8.5**, so a push already proves the floor and both supported targets. At the
October 2026 patch the suite was also run locally on PHP **8.2.12, 8.4.27 and
8.5.12**: 645 tests, 5,128 assertions, no failures and no deprecations. To
reproduce a target run on a workstation, install that PHP beside XAMPP's 8.2
(or in a container), point it at the same database and run:

```bash
composer test:db
vendor/bin/phpunit --no-coverage
php deploy_check.php YOUR_KEY
```

Do not skip `composer code-audit`; it reads the code, not the interpreter, but
it is the cheapest way to catch a patch that broke a call signature.

---

## Monthly patch routine (10 minutes)

Once a month, and immediately when a critical advisory lands:

1. Check [php.net/supported-versions.php](https://www.php.net/supported-versions.php)
   for the newest patch release of the running branch (e.g. 8.4.x). A cPanel
   host normally picks these up through its PHP packages; if MultiPHP offers no
   newer patch, ask the host. Security fixes in 8.2/8.3 stop on their EOL dates.
2. Run `composer audit --locked` — it must stay clean. There are no runtime
   packages, so a finding here is a dev-tool finding and still worth fixing.
3. Check the current releases of the bundled plugins:
   - [jquery.com/download](https://jquery.com/download/)
   - [getbootstrap.com](https://getbootstrap.com/)
   - [popper.js.org](https://popper.js.org/)
   - [datatables.net/download](https://datatables.net/download/) (we stay on the
     last **1.x** for the Bootstrap 4 integration; 2.x/3.x need a migration)
   - [bootstrap-datepicker](https://github.com/uxsolutions/bootstrap-datepicker/releases)
   - [blueimp Gallery](https://github.com/blueimp/Gallery/releases)
   Only the last patch of a supported line is worth taking; Bootstrap 4 is
   end-of-life, so a jump to Bootstrap 5 would be a redesign, not a patch.
4. If anything moved, apply it with the procedure below and deploy through the
   normal release process (upload, `deploy_check`, smoke pages).
5. Record what changed and when in this file's status table.

### Applying a front-end patch

1. Download the official distribution file over HTTPS. Never take a minified
   file from a random CDN mirror.
2. Save it under the versioned name (`js/jquery-<version>.min.js`) for jQuery,
   or overwrite the existing file for Bootstrap/Popper.
3. Update every include. The jQuery names are referenced from PHP only:

   ```bash
   grep -rl 'jquery-3.7.1.slim.min.js' --include='*.php' . | xargs sed -i 's/jquery-3\.7\.1\.slim\.min\.js/jquery-<new>.slim.min.js/g'
   grep -rl 'jquery-3.7.1.min.js'       --include='*.php' . | xargs sed -i 's/jquery-3\.7\.1\.min\.js/jquery-<new>.min.js/g'
   ```

   For Bootstrap, replace the files and bump every `?v=` string:

   ```bash
   grep -rl 'bootstrap.min.css?v=4.6.2' --include='*.php' . | xargs sed -i 's/bootstrap\.min\.css?v=4\.6\.2/bootstrap.min.css?v=<new>/g'
   grep -rl 'bootstrap.min.js?v=4.6.2'  --include='*.php' . | xargs sed -i 's/bootstrap\.min\.js?v=4\.6\.2/bootstrap.min.js?v=<new>/g'
   ```

4. Delete the superseded jQuery files and confirm nothing still points at them:

   ```bash
   grep -rn --binary-files=without-match 'jquery-3.7.1' --include='*.php' .
   ```

   Every hit must resolve to a file that exists (remember `../js/` from
   `admin/` subpages resolves to the root `js/`).
5. Read the library's upgrade notes for APIs removed between the two versions.
   The current code is clear of the jQuery APIs removed in 3.x (`$.browser`,
   `.size()`, `.live()` and friends); `client_manage_account_details.php` uses
   the still-supported `.bind()`.
6. Run `composer lint`, `composer code-audit` and the suite, then load an admin
   page, the public homepage and a DataTables page with the browser console
   open. A missing library shows up there immediately.

### Rolling back

Keep the previous `js/` and `admin/js/` files until the new version has been
live for a day. Rollback is restoring those files and the include names —
there is no database change and no data migration in an asset patch. Roll back
PHP itself in the same MultiPHP Manager screen.

---

## What is already handled in the application

Do not remove these while patching; they are layered on purpose:

- `.htaccess` denies `.env`, `.git`, `vendor/`, `scripts/`, `tests/`, `deploy/`,
  `migrations/`, `admin/include/` and any dot-path, forces HTTPS outside
  localhost, sets `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy` and `Permissions-Policy`, and disables directory listings.
- Sessions use HttpOnly/SameSite cookies, id regeneration, fingerprinting and
  an audit log (`admin/include/Session.php`); every state-changing form carries
  a CSRF token.
- SQL is prepared and bound; `composer code-audit` fails the build on a raw
  driver call, an unbound statement, an unvalidated `$_REQUEST` read, a
  column that does not exist or a test that asserts about a record it did not
  create.
- Uploads are validated and stored outside the executable path; error display
  is off unless `WPMS_DEBUG=1`.
- `deploy_check.php` fails on an EOL PHP branch, a missing extension, a missing
  table, exposed debug output or a page printing a PHP error.

What it does **not** cover: a compromised hosting account, an expired TLS
certificate, an outdated OS package on the host, or a phishing link a staff
member clicks. Those are account and operations controls, not code.

---

## If a vulnerability is reported

1. Reproduce it against the test database, never against production data.
2. Write the failing test first; the suite is the regression gate.
3. Patch, run `composer lint && composer code-audit` and the full suite.
4. Deploy, run `deploy_check`, and confirm the exact request from the report is
   refused.
5. If credentials or tokens may have been exposed, rotate them in `.env`
   (`WPMS_MCP_APPROVAL_SECRET`, API tokens on `admin/api_tokens.php`, database
   password, mail credentials) and tell affected users to reset passwords.
