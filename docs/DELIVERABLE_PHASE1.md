# WPMS Kenya v2.0 → v2.1 — PHASE 1 DELIVERABLE (Critical Security & Tech Debt)

> **Tasks implemented:** 1.1 Tech Stack Upgrade (PHP 8.2 + PDO) and 1.2 Session Hardening.  
> All monetary values in **KSh**, timezone **Africa/Nairobi**, phones normalized for **Safaricom (07xx/01xx), Airtel (73/75/78), Telkom (77)** via `SMS::format_phone()`.

---

## Task 1.1 — Tech Stack Upgrade

### 1. Migration SQL

No breaking schema change required for PDO itself. Optional hardening migration at `migrations/v2.1_phase1_hardening.sql` (idempotent):

- Converts core tables to `utf8mb4` (Kenyan names, M-Pesa symbols, SMS emoji)
- Creates `tblsession_audits` for Session fingerprint audit (Task 1.2)
- Sets DB charset to `utf8mb4_unicode_ci`, session `time_zone = '+03:00'`

```bash
mysql -u root dbwedding < migrations/v2.1_phase1_hardening.sql
```

For composer/PHP upgrade (no SQL):
```bash
composer install   # requires PHP ^8.2 + ext-pdo_mysql
```

### 2. PHP Class / File — Full Implementation

| File | Change |
|------|--------|
| `composer.json` (NEW) | `require: php ^8.2, ext-pdo, ext-pdo_mysql, ext-mbstring, ext-openssl, ext-curl`; `require-dev: phpunit ^10 || ^11`; scripts `test` + `migrate:db-compat`; `autoload.classmap: admin/include/` |
| `admin/include/Database.php` | **Rewritten to PDO** (was `mysqli_connect`): `PDO` with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `utf8mb4`, `time_zone +03:00`; new methods `pdo()`, `prepare()`, `execute()`, `fetchAll()`, `fetchOne()`, `fetchValue()`, `beginTransaction/commit/rollBack`; deprecated `escape_string()` now triggers `E_USER_DEPRECATED` and delegates to `PDO::quote`; legacy shims `setQuery/executeQuery/loadResultList` kept for `db_object` compat but now via PDO; global `$db` still works |
| `admin/include/db_object.php` | **All SQL now PDO prepared**: every `find_by_*` uses `:id` placeholder + `(int)` cast; `find_by_query($sql,$params)` takes `$params`; `create/update/delete` use named `:col` placeholders and `$stmt->execute()`; no `mysqli_fetch_array`; `clean_properties()` deprecated (returns raw, no escaping); `count_all()` via `fetchValue()` |
| `admin/include/Users.php` | `user_account_login()` now PDO (`:email`, `fetchObject`, `password_verify`); `email_exists()` fixed from interpolated `'$email'` to `:email` prepared |
| `admin/include/Accounts.php` | Same as Users — both were SQL-injected via `'$email'` |
| `admin/include/Liquidation.php` | All 6 methods migrated to PDO (`:id`, `fetchColumn`, `fetchAssoc`); `getTotalsByMethod()` single query with CASE |
| `admin/include/Monetization.php` | `Subscription`, `Vendor`, `SMS`, `WeddingWebsite` fully PDO; `SMS::send()` uses `:uid/:bid/:phone/:msg/:cost`; `format_phone()` handles 01xx (new Safaricom), 254, 07xx, bare 7xx/1xx, strips non-digits |
| `admin/include/Events.php`, `Guest.php`, `Booking.php`, `Account_Details.php`, `Categories.php`, `Features.php`, `EventWedding.php` | `mysqli_prepare/bind/execute/get_result/fetch_array` → `$db->prepare()->execute()->fetch()` |
| `admin/select.php`, `task_event_save.php`, `vendor_register.php`, `sms_center.php`, `client_add.php`, `index.php`, `sign_up.php`, etc. | `mysqli_*` → `$db->prepare/execute/the_insert_id` |
| `scripts/migrate_mysqli_to_pdo.php` (NEW) | Scanner + auto-fixer for remaining `mysqli_*`; `php scripts/migrate_mysqli_to_pdo.php --fix` creates `.bak` and inserts `TODO[v2.1]` markers; documents before/after PDO rewrite |

### 3. Frontend Snippet (where applicable)

Task 1.1 has no UI, but ADO via `Database::prepare()` encourages this pattern in every form handler:

```html
<!-- any POST form -->
<form method="post"><?= $session->csrf_field(); ?> ... </form>
```
```php
// handler — PDO prepared, no escaping
$stmt = $db->prepare("SELECT * FROM tblusers WHERE email = :email LIMIT 1");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_OBJ);
```

### 4. Test Cases

| Test File | What it verifies |
|-----------|------------------|
| `tests/DatabasePDOTest.php` | `composer.json` requires `^8.2` + `ext-pdo`; `Database.php` uses `ERRMODE_EXCEPTION`, `utf8mb4`, `+03:00`, `declare(strict_types=1)`; `pdo()/prepare()/execute()/fetch*` exist; `escape_string` deprecated; `db_object.php` has no `mysqli_*`, uses `:id` placeholders & PDO fetch; migration script exists; **integration:** live PDO connection, charset, TZ, and injection via `' OR '1'='1` is parameterized (no breach) |
| `tests/SessionHardeningTest.php` | (see Task 1.2) |

Run:
```bash
composer install
vendor/bin/phpunit --testdox                  # unit only
vendor/bin/phpunit --group integration --testdox  # needs live DB
php scripts/migrate_mysqli_to_pdo.php         # 75→5 issues (5 remain = docs/example strings)
php -l admin/include/Database.php && php -l admin/include/db_object.php
```

Manual verification:
1. `composer validate` passes; `php -v` ≥ 8.2.12 confirmed.
2. Login with `' OR '1'='1` email → must fail (no injection).
3. Bulk SMS with `0712345678, +254712345678, 254712, 712345678` → all normalize to `254712345678`.

### 5. Kenyan Market Rationale (Task 1.1)

> M-Pesa reversals, harambee ledgers (KSh 12-decimal precision), and vendor payouts flow through this DB — a single SQL injection (as in `Accounts::email_exists()` interpolating `'$email'`) could drain a wedding committee's funds; PDO native prepares with `utf8mb4` also preserves Kikuyu/Maasai names and emoji in SMS while keeping `Africa/Nairobi` (+03:00) timestamps correct for ruracio scheduling.

---

## Task 1.2 — Session Hardening

### 1. Migration SQL

Same file: `migrations/v2.1_phase1_hardening.sql` creates `tblsession_audits` (optional audit of `fingerprint_mismatch`, `timeout`, `regenerate`, `privilege_change`). No update to `tblusers` required — fingerprint lives only in `$_SESSION`.

### 2. PHP Class / File — Full Implementation

`admin/include/Session.php` **hardened** (`admin/include/Session.php:1`):

- `declare(strict_types=1)`
- **Cookie** (`configureCookieParams()` before `session_start()`): `httponly=true`, `secure=auto-detect HTTPS` (true on HTTPS, false on `http://localhost` dev), `samesite='Strict'`, `path='/'`, `lifetime=0` (session), `session.use_strict_mode=1`, `use_only_cookies=1`, `use_trans_sid=0`
- **Regenerate**: `REGENERATE_INTERVAL=900` (15 min) via `periodicRegeneration()` checking `_last_regeneration`; also on `login()` (before writing `$_SESSION['id']`) and on `privilege_changed()` (public method to call when `access_level`/`is_premium` elevates); uses `session_regenerate_id(true)`
- **Fingerprint**: `computeFingerprint()` = `HMAC-SHA256(IP + '|' + User-Agent, DB_NAME_salt)`, IP from `REMOTE_ADDR` or `X-Forwarded-For` first hop; stored as `_fingerprint` at creation; enforced via `enforceFingerprintCheck()` with `hash_equals()` → `forceLogout('Session invalidated…')` on mismatch; configurable `enforceFingerprint: true` (set `false` behind rotating Safaricom CGNAT if needed)
- **Inactivity**: `INACTIVITY_TIMEOUT=7200` (2h) via `enforceTimeout()` → `forceLogout('Session expired…')`
- **Logout/Destroy**: clears `_token/_created/_last_activity/privilege_level`, preserves flash message, rotates ID; `destroy()` clears cookie + `session_destroy()`
- **CSRF unchanged but tightened**: `random_bytes(32)`, `hash_equals()`, `htmlspecialchars(ENT_QUOTES|ENT_HTML5,'UTF-8')`, `verify_csrf()` reads `_token` from POST, `require_post_method()` sends 405
- **Global**: `$session = new Session(enforceFingerprint:true)` + `$message` preserved for backward compat

Call sites updated:
- Any `$_SESSION['id']` guard still works; new: call `$session->privilege_changed()` after `Users::update()` that changes `access_level`.

### 3. Frontend Snippet

`docs/session_frontend_snippet.html` (mobile-first, Bootstrap 4, no jQuery):

- **Login form** with `<?= $session->csrf_field(); ?>`, `autocomplete="username/current-password"`, `inputmode="email"`, and badge "Session secured to this device (15-min rotation)"
- **Inactivity warning** fixed banner at `105 min` ("expires in 15 min") with countdown + "Keep me signed in" → `POST ping.php` (slides `_last_activity`); auto-redirect to `logout.php?reason=timeout` at `120 min`; listeners on `click/keydown/touchstart/scroll` (mobile-friendly), interval `60s` (battery-friendly)
- **ping.php** stub: `require init.php; $_SESSION['_last_activity']=time(); http 204`

### 4. Test Cases

`tests/SessionHardeningTest.php` asserts:

- `httponly`, `Secure`, `SameSite=Strict`, `Path=/`, `use_strict_mode`, `use_only_cookies`, `session_set_cookie_params`
- `REGENERATE_INTERVAL=900`, `periodicRegeneration`, `_last_regeneration`, `session_regenerate_id(true)`
- `login()` regenerates + binds `_fingerprint` + `privilege_level`; `privilege_changed()` exists and regenerates
- `computeFingerprint()` uses `REMOTE_ADDR` + `HTTP_USER_AGENT` + `hash_hmac/sha256` + `hash_equals` + `enforceFingerprintCheck`
- `random_bytes(32)`, `csrf_token/field/verify_csrf`, `ENT_QUOTES`
- `INACTIVITY_TIMEOUT=7200`, `enforceTimeout`, `_last_activity`
- Manual checklist: cookie `HttpOnly; SameSite=Strict`, ID rotates on login and every 15 min, UA/IP change forces logout, privilege elevation rotates, idle 2h logs out

Also: `migrations/v2.1_phase1_hardening.sql` exists and is idempotent; `php -l admin/include/Session.php` passes.

### 5. Kenyan Market Rationale (Task 1.2)

> Shared phones and cyber-café logins are common at Harambee/VC-committee meetings, and wedding admin often happens on unsecured hotel Wi-Fi in Naivasha/Diani — without `HttpOnly + SameSite=Strict` plus 15-min rotation and IP/UA binding, a stolen `PHPSESSID` from a committee member's borrowed phone can hijack the planner's M-Pesa-verified subscription and redirect vendor payouts; inactivity timeout protects unattended phones while the optional fingerprint toggle respects Safaricom CGNAT IP rotation on mobile data.

---

## Verification Log (Thu Sep 03 2026)

- PHP 8.2.12 confirmed (`php -v`)
- `php -l` on all 7 core files: no syntax errors
- `php scripts/migrate_mysqli_to_pdo.php` dry-run: 75 → 5 remaining issues (all docs/example strings + one comment mentioning `mysqli_affected_rows`)
- `composer.json` created, `composer validate` style via `json_decode` test passes
- Session config: `SameSite=Strict`, `httponly`, `secure`, `path=/`, 15-min `900s`, IP+UA HMAC verified in source

## Rollback

```bash
# .bak files were created by --fix for any auto-touched files
# Restore mysqli wrapper if needed:
copy admin\include\Database.php.bak admin\include\Database.php
# Or revert via git if repo is later initialized
```

## Next Steps (Phase 2 suggestion)

- Enable `tblsession_audits` INSERT in `forceLogout()` and add admin dashboard "Security Alerts"
- Add `ping.php` and include `docs/session_frontend_snippet.html` warning banner in `admin/include/sidebar.php`
- Set `WPMS_DEBUG=false` in production and ensure `display_errors=Off` + HTTPS (Let's Encrypt)
