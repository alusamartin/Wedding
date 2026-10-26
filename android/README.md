# Everafter — Android app (couples)

The couple's companion app: sign in, see the countdown, tick the checklist, watch
the guest list answer, see what has been paid and reply to vendors from the phone.
Native Kotlin + Jetpack Compose, ~1.5 MB as a release APK.

It is a **thin client**. Every number on screen comes from `api/v1/` on your own
server, through the same ownership rules the website uses — the app has no way to
name a wedding, so a tampered request cannot widen what it sees.

---

## What is built (v1)

| Screen | What it does |
|---|---|
| Sign in | Email + password against `tblaccounts`, the same bcrypt hashes and the same lockout ladder as the website. The bearer is stored in EncryptedSharedPreferences (Android Keystore). |
| Home | Days to go, wedding date and venue, checklist progress, the three nearest tasks (tickable), guest counts, unread vendor messages. |
| Checklist | The Kenyan milestone template, grouped by phase with real due dates, overdue in red, tick/un-tick. |
| Money | Package price, paid to date broken down by M-Pesa / cash / credit, balance due, the next payment (installment or final settlement), vendor commitments, harambee pledges, and the payment ledger. |
| Messages | Every vendor deal on the wedding, newest activity first, with the last message, unread count and whose move it is. Tapping opens the thread. |
| Thread | The conversation with one vendor — your messages on the right, the vendor's and your planner's on the left — with a reply box that clears only after the server has the message. |
| Guests | The list with RSVP answers, dietary notes, transport and accommodation needs; filters for Coming / Waiting / Declined. Read-only — an RSVP is the guest's own answer. Reached from the Home guest card or from More. |
| More | Profile, wedding, guest list shortcut, the server this install talks to, sign out. |

**Five tabs, deliberately.** Home · Checklist · Money · Messages · More is the
most a bottom bar can hold before the labels compete for space on a 360 dp
phone. Guests is one tap deeper from the Home card, which is where the counts
already are.

Not in v1: notifications screen, RSVP editing, sending a new enquiry from the
phone, push notifications. Those are the next slices, in that order.

---

## Offline: what works without a signal

Home, the checklist and the guest list are saved on the phone after every
successful load, so the app paints them on the next start before the network
answers — no spinner over an empty screen on a 3G connection.

What is cached is exactly those three screens. **Money, messages and
notifications are not**, on purpose: a balance or a vendor reply that is quietly
a day old, shown as current, is worse than an honest "connect to see it". Those
screens say so when offline instead of showing an empty list that reads like
"no payments".

When a request fails because nothing reached a server, the cached screens grow a
banner — *"You are offline"* or *"Cannot reach your server"*, then *"showing
your wedding as it was 3 h ago"* — with a Retry button. It disappears as soon as
any request succeeds again.

The two headings are told apart with one connectivity check
(`data/NetworkState.kt`), because a wrong server address typed into Settings and
a phone in a basement are different problems, and only one of them is the
couple's to fix. The check is optimistic: if it cannot tell, it says connected.

- **Where it lives.** `EncryptedSharedPreferences` under the same Keystore key
  as the session (`data/CacheStore.kt`). It holds names, phone numbers and
  dietary notes, so it is encrypted like the token is.
- **Cleared whenever the identity can change** — sign out, an expired token, a
  new sign-in, a different server — so the next person to open the app can never
  be shown the previous couple's wedding.
- **Only the unfiltered guest list is written.** Caching "only the guests who
  said yes" would leave a partial list wearing the whole list's label.
- **Ticking needs a connection.** Offline is read-only: a tick is put back if
  the server refuses it, so the app never shows a tick the database lacks.
- **Fails soft.** If the Keystore is unavailable the cache turns itself off and
  the app behaves exactly as it did before — a convenience must never be the
  reason a couple cannot open their wedding.

---

## How the money screen stays honest

It does no arithmetic of its own. The package price is the wedding category's
price, "paid" is the same `tbl_liquidation` ledger the website prints, and the
installment plan is `InstallmentPlan::progress()` — the very method the overview
nudge uses. A couple comparing the app to the site cannot find two balances.

Two totals are kept apart on purpose, as `VendorPlan` keeps them on the web: the
**package balance** (still owed on the booking) and **committed to vendors**
(agreed with vendors). Adding them would count the same shilling twice on most
weddings. Harambee pledges sit beside the balance, never inside it.

## How the messages screen stays honest

A couple can only open a deal their own wedding owns — the same predicate
`VendorThread::authorize()` applies to a couple on the website, restated in SQL
because the API has no session. A deal from another wedding answers **404**, not
403, so deal numbers cannot be probed.

Opening a thread marks the vendor's messages read for that couple, exactly as
landing on the ledger row does on the web; there is no separate "mark read"
call for the app to forget. Replying posts through `VendorThread::post()`, which
is what the website uses, so the vendor sees it in the same place, with the same
notification, and your planner is still told when a vendor goes quiet.

---

## Build it

Requirements: JDK 17, Android SDK with **platform 35** and **build-tools 35.0.0**.

```bash
cd android

# 1. Point the build at your install (the app can also change this at runtime
#    from More → Change server, so this is a convenience, not a lock-in).
./gradlew assembleDebug -PapiBase=https://your-domain.co.ke

# 2. Install on a phone connected over USB (Developer options → USB debugging)
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

`-PapiBase` accepts a bare domain (`your-domain.co.ke`, becomes HTTPS and gets
`/api/v1/`) or a full URL. With no `-PapiBase` the app opens on the sign-in
screen's **Server** row, where the address can be typed once and remembered.

### Release build

```bash
./gradlew assembleRelease -PapiBase=https://your-domain.co.ke
# → app/build/outputs/apk/release/app-release.apk   (~1.5 MB, signed)
```

R8 shrinking and resource shrinking are on; the release APK is a quarter of the
debug build. **Signing is already wired** — the build reads the key and signs
automatically, so the file that comes out is the one you install:

```bash
adb install -r app/build/outputs/apk/release/app-release.apk
```

(or copy the `.apk` to the phone and open it, allowing "install unknown apps"
for the file manager).

### The release gate

`assembleRelease` (and `bundleRelease`) will not produce an APK until the PHP side
has proved itself. Gradle runs [`scripts/release_preflight.php`](../scripts/release_preflight.php),
which:

1. rebuilds an isolated database from the seed files (`wpms_preflight`), so nothing
   is judged against a half-edited demo database;
2. boots a throwaway `php -S` and walks every page and write path as every role
   with `scripts/simulate_roles.php`, then scans the server log for PHP
   diagnostics that never reach a response body;
3. walks the flows a person actually performs with `scripts/smoke.php` — public
   doors, a couple's pages, a deposit that must reach the ledger and ring the
   planner's bell — against the same server and database;
4. runs the full PHPUnit suite against its own `wpms_test` database;
5. installs the signed APK on the attached device with `scripts/device_smoke.php`,
   signs in as the seeded couple and opens every core screen — failing on a screen
   that is stuck loading, shows the offline banner, shows an error banner, or is
   empty where the seed has data, plus any crash in logcat during the run.

It exists because every defect that reached this app was invisible from a debug
build: a login table the seeder never created, a navigation badge the simulator
still asserted against, and a request read on the main thread. A failure here
stops the build before an APK exists.

**Step 5 runs the other way round from the rest.** It judges an APK, so it cannot
run before one exists: steps 1-4 run before packaging (a normal Gradle
dependency), and step 5 runs *after* it through `releaseDeviceCheck`, which
`assembleRelease` is finalized by. No device attached, no adb, or no APK on disk
is reported as `[--] skipped` with the reason — never as a pass — and
`-PrequireDevice=true` turns that into a build failure for CI.

```bash
./gradlew assembleRelease -PapiBase=https://your-domain.co.ke
# → releasePreflight runs first; a FAIL leaves the previous APK untouched
# → then releaseDeviceCheck installs the APK it just signed and signs in with it
```

Run it on its own (no Gradle):

```bash
composer preflight               # all five steps
composer preflight -- --keep-db --port=8138
composer device                  # just step 5, against the APK on disk
php scripts/release_preflight.php --only=sim      # simulation only
php scripts/release_preflight.php --only=device   # on-device only
php scripts/release_preflight.php --only=device --require-device   # CI
php scripts/release_preflight.php --skip-device   # steps 1-4 only
```

`--skip-device` is what the Gradle release build passes to `releasePreflight`:
steps 1-4 there, then `releaseDeviceCheck` after packaging, so the install and
sign-in run once, against the APK this build produced.

Step 5 needs a seeded database to sign in to. On its own it starts a throwaway
server (`wpms_preflight` by default) and stops it again; inside a full gate run it
reuses the one that is already serving. The emulator reaches this machine's
loopback as `10.0.2.2`; a USB phone is tunnelled with `adb reverse` and talks to
its own localhost.

| Flag | Effect |
|---|---|
| `-PphpBin=/path/to/php` | which PHP runs the gate (default `$PHP_BINARY`, else `php` on PATH) |
| `-PpreflightArgs="--keep-db --port=8138"` | forwarded to the script |
| `-PskipPreflight=true` | break glass: builds anyway and prints `RELEASE GATE BYPASSED` |
| `-PskipDeviceCheck=true` | skip only step 5 (builds and ships without an on-device run) |
| `-PrequireDevice=true` | no device attached fails the build instead of skipping step 5 |

| `device_smoke.php` flag | Effect |
|---|---|
| `--serial=ID` | which device, when more than one is attached |
| `--apk=PATH` | the APK to install (default the release output) |
| `--api-base=URL` | the address the app should use (default `http://10.0.2.2:<port>`) |
| `--port=N`, `--db=NAME` | the seeded install it signs in to |
| `--email=`, `--password=` | the account it signs in as (default the seeded couple) |
| `--require-device` | no device is a failure, not a skip |
| `--json=PATH` | write the check results as JSON |

### The signing key

| What | Where | In git? |
|---|---|---|
| The key | `android/keystore/everafter-release.jks` — RSA 4096, PKCS12, alias `everafter`, valid 10,000 days | **never** |
| Its password | `android/keystore.properties` (`storeFile`, `storePassword`, `keyAlias`, `keyPassword`) | **never** |
| A backup copy | somewhere offline and outside this project — see **Back it up** below | — |

Both are in `android/.gitignore`. If a machine has no key, `assembleRelease`
still succeeds and writes an **unsigned** APK instead of failing, which is what
CI and anyone merely compiling should get — only a machine holding the key can
produce something a phone accepts as an update.

The certificate fingerprinted by `apksigner` (use it to check that an update was
signed with this key and not a copy):

```
SHA-256  EA:27:3B:34:73:3A:E2:92:FC:52:29:A0:AE:97:C5:FC:
         37:2A:A0:26:C6:B0:B5:B5:B2:B9:FE:BA:5B:F9:DB:20
```

Verify any build yourself:

```bash
"$ANDROID_HOME/build-tools/35.0.0/apksigner" verify --print-certs \
    app/build/outputs/apk/release/app-release.apk
```

The APK is signed with the **v2 scheme** only, which is safe here because
`minSdk` is 24 — the first release where v2 is always verified. The old JAR (v1)
signature would add bytes and protect nobody.

### Back it up — this is the one irreversible step

Android will not install an update signed with a different key. Lose the `.jks`
or its password and every future version has to ship as a **new app under a new
package name**, with no upgrade path for anyone who already installed it.

Copy both files somewhere that is not this repo and not this server — a password
manager's file store, or an encrypted USB stick kept offline:

```bash
# to a password manager / encrypted archive, not to git, not to public_html
mkdir -p ~/.everafter && cp android/keystore/everafter-release.jks \
    android/keystore.properties ~/.everafter/
```

Never upload `android/` — or the folder holding the key — to the web host. The
site's `.htaccess` already refuses `/android/`, but not uploading it is the real
protection.

---

## Test it

```bash
./gradlew testDebugUnitTest
```

Two kinds of test run here. `ApiContractTest` signs in against a locally running
server, reads every screen (including budget and vendor threads), ticks a task
twice (a retried request must not 404) and checks a revoked token is refused; it
**skips** rather than fails when nothing is listening, so it is safe in CI.
`SnapshotCodecTest` and `FormatTest` are pure JVM tests — the cache merge rule
(saving one section must not wipe another), the JSON round-trip, and the money
and time formatting the screens rely on — so they run anywhere, no device:

```bash
./gradlew testDebugUnitTest      # 16 tests, no emulator needed
```

For the contract test, start the server first:

```bash
# from the project root, in another terminal
php -S 127.0.0.1:8099 -t .
```

Override the target with `-Dwpms.api=https://staging.example.co.ke/api/v1/`.
The test needs a seeded couple — the XAMPP seed's
`brian.wafula@gmail.com` / `Test@1234` is what it uses.

Sending a message is the one write a test cannot undo, so it is opt-in and off
by default. Against a scratch database:

```bash
./gradlew testDebugUnitTest -Dwpms.api.writes=true
```

(Both flags are forwarded to the test JVM by `app/build.gradle.kts`; Gradle does
not pass the command line's `-D` values through by itself.)

---

## Layout

```
android/
├── app/src/main/java/ke/everafter/couple/
│   ├── MainActivity.kt          activity, nav graph, bottom bar
│   ├── data/
│   │   ├── ApiClient.kt         OkHttp + kotlinx.serialization, one retry, typed errors
│   │   ├── Models.kt            the wire shapes of api/v1, field for field
│   │   ├── CacheStore.kt        the offline snapshot, encrypted, on the phone
│   │   ├── Snapshot.kt          cached sections + the pure merge/JSON codec
│   │   ├── NetworkState.kt      is this a dead signal or a dead server?
│   │   └── SessionStore.kt      encrypted bearer, server URL, normalisation
│   └── ui/
│       ├── AppViewModel.kt      one UiState, one set of actions
│       ├── LoginScreen.kt  HomeScreen.kt  ChecklistScreen.kt
│       ├── BudgetScreen.kt  MessagesScreen.kt  ThreadScreen.kt
│       ├── GuestsScreen.kt  MoreScreen.kt  Components.kt  Format.kt
│       ├── Icons.kt             the two icons Material's core set lacks
│       └── theme/Theme.kt       Material 3, colours from admin/css/design-tokens.css
└── app/src/test/java/…/ApiContractTest.kt
```

## Security decisions worth knowing

- **No secret in the APK.** The bearer is issued at sign-in and stored in the
  Keystore; there is no API key to extract.
- **HTTPS only.** `network_security_config.xml` allows cleartext for `10.0.2.2`
  and `localhost` only, so a release build cannot talk to a plain-HTTP server.
- **One phone, one credential.** Signing out sets `revoked_at` on that device's
  row; other phones stay signed in. A lost phone is revoked from phpMyAdmin.
- **The server picks the data.** `booking_id` is resolved on the server from the
  bearer. There is no `booking_id` parameter anywhere in this API.
- **No tracking SDKs, no analytics, no ads.** The only network destination is
  the server you configure.
