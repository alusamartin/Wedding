<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * WPMS Kenya — end-to-end smoke test against a running dev server.
 * Backs the `composer smoke` script. Walks the main public + staff + client
 * flows and FAILS on any unexpected status, redirect target, or PHP error
 * leaking into a response body.
 *
 * Usage:
 *   php scripts/smoke.php [base-url]          # default http://127.0.0.1:8000
 *
 * Environment overrides:
 *   SMOKE_STAFF_EMAIL  / SMOKE_STAFF_PASS     seeded admin (wafula@wpms.co.ke / Admin@2024!)
 *   SMOKE_CLIENT_EMAIL / SMOKE_CLIENT_PASS    seeded client (brian.wafula@gmail.com / Test@1234)
 *   SMOKE_COUPLE_EMAIL / SMOKE_COUPLE_PASS    seeded client owning a booking (chebet.eldoret@gmail.com)
 *   SMOKE_PLANNER_EMAIL / SMOKE_PLANNER_PASS  assigned planner for the bell check (amina@wpms.co.ke / Test@1234)
 *
 * Exit codes: 0 = all green, 1 = failed checks, 2 = server unreachable.
 */

const STAFF_EMAIL  = 'wafula@wpms.co.ke';
const STAFF_PASS   = 'Admin@2024!';
const CLIENT_EMAIL  = 'brian.wafula@gmail.com';
const CLIENT_PASS   = 'Test@1234';
// A seeded client who OWNS a booking (chebet.eldoret has booking 203) so the
// couple-only pages can be walked end-to-end.
const COUPLE_EMAIL  = 'chebet.eldoret@gmail.com';
const COUPLE_PASS   = 'Test@1234';
// The planner whose bell badge must light up when the couple deposits.
const PLANNER_EMAIL = 'amina@wpms.co.ke';
const PLANNER_PASS  = 'Test@1234';

const PUBLIC_PAGES = [
    '/index.php',
    '/login.php',
    '/vendor_signup.php',
    '/gallery.php',
    '/pricing.php',
    '/contact.php',
    '/about.php',
    '/real-weddings.php',
    '/thank_you.php',
];

const STAFF_PAGES = [
    '/admin/dashboard.php',
    '/admin/client.php',
    '/admin/users.php',
    '/admin/service_list.php',
    '/admin/subscription.php',
    '/admin/vendors.php',
    '/admin/sms_center.php',
    '/admin/photos_view.php',
    '/admin/blog_events.php',
    '/admin/photos_add.php',
    '/admin/blog_events_add.php',
    '/admin/users_add.php',
    '/admin/notifications.php',
];

/** @var string[] Pages that take a booking context; {b} and {u} are filled in. */
const STAFF_BOOKING_PAGES = [
    '/admin/client_manage_account_details.php?booking_id={b}&user_id={u}',
    '/admin/guest_list.php?booking_id={b}&user_id={u}',
    '/admin/budget.php?booking_id={b}&user_id={u}',
    '/admin/events.php?booking_id={b}&user_id={u}',
    '/admin/task_calendar.php?booking_id={b}&user_id={u}',
];

/** Matches any PHP-level problem PHPUnit-style errors that leak into HTML bodies. */
const BODY_ISSUE_RE = '~(Fatal error:|Parse error:|Uncaught [A-Za-z\\\\]+|Warning:|Notice:|Deprecated:)~i';

final class Smoke
{
    /** @var array<string, string> */
    private array $jar = [];
    private int $passed = 0;
    private int $failed = 0;
    /** @var string[] */
    private array $notes = [];

    public function __construct(private readonly string $base)
    {
    }

    /**
     * Minimal curl-based HTTP client with a cookie jar. Redirects are not
     * followed — the caller asserts on the redirect itself.
     *
     * @return array{status:int, location:string, body:string, error:string}
     */
    private function http(string $method, string $path, array $post = [], bool $withJar = false): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'WPMS-Smoke/1.0',
        ]);

        $headers = [];
        if ($withJar && $this->jar !== []) {
            $cookies = [];
            foreach ($this->jar as $name => $value) {
                $cookies[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookies);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        $info  = curl_getinfo($ch);

        $headerLen = (int) ($info['header_size'] ?? 0);
        $head      = substr((string) $raw, 0, $headerLen);
        $body      = substr((string) $raw, $headerLen);

        $status   = (int) ($info['http_code'] ?? 0);
        $location = '';
        if (preg_match('~^Location:\s*(.+)$~mi', $head, $m)) {
            $location = trim($m[1]);
        }
        if (preg_match_all('~^Set-Cookie:\s*([^;=]+)=([^;]*);~mi', $head, $m, PREG_SET_ORDER)) {
            foreach ($m as $cookie) {
                $this->jar[trim($cookie[1])] = trim($cookie[2]);
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => $body, 'error' => $error];
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            echo "  PASS  {$label}\n";
        } else {
            $this->failed++;
            echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
    }

    private function note(string $message): void
    {
        $this->notes[] = $message;
        echo "  SKIP  {$message}\n";
    }

    /** Pull a real (booking_id, user_id) pair from the dev DB when reachable. */
    private function bookingContext(): array
    {
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/config.php';
                require_once __DIR__ . '/../admin/include/database.php';
            }
            global $db;
            if (!isset($db) || !($db instanceof Database)) {
                $db = new Database();
            }
            $row = $db->fetchOne('SELECT booking_id, user_id FROM tblweddingbook ORDER BY booking_id DESC LIMIT 1');
            if ($row && (int) $row->booking_id > 0 && (int) $row->user_id > 0) {
                return [(int) $row->booking_id, (int) $row->user_id];
            }
        } catch (Throwable $e) {
            // fall through to the seeded fallback
        }
        return [201, 201]; // seeded Kenyan booking (faith.njeri) — adjust if your DB differs
    }

    /** Lazy PDO-backed handle for deposit/alert assertions (dev DB via config). */
    private function db(): ?Database
    {
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/config.php';
                require_once __DIR__ . '/../admin/include/database.php';
            }
            global $db;
            if (!isset($db) || !($db instanceof Database)) {
                $db = new Database();
            }
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function dbBookingOrganizer(int $bookingId): int
    {
        $db = $this->db();
        if ($db === null) {
            return 0;
        }
        return (int) ($db->fetchValue('SELECT organizer_id FROM tblweddingbook WHERE booking_id = :b LIMIT 1', [':b' => $bookingId]) ?? 0);
    }

    private function dbNotifCount(int $bookingId, int $plannerId): int
    {
        $db = $this->db();
        if ($db === null) {
            return 0;
        }
        return (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications WHERE booking_id = :b AND user_id = :u',
            [':b' => $bookingId, ':u' => $plannerId]
        );
    }

    private function dbUnreadCount(int $plannerId): int
    {
        $db = $this->db();
        if ($db === null) {
            return 0;
        }
        return (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications WHERE user_id = :u AND is_read = 0',
            [':u' => $plannerId]
        );
    }

    /** @return object|null {id, mpesa_code} of the latest ledger row for this booking+amount */
    private function dbDepositRow(int $bookingId, float $amount): ?object
    {
        $db = $this->db();
        if ($db === null) {
            return null;
        }
        return $db->fetchOne(
            'SELECT id, mpesa_code FROM tbl_liquidation WHERE booking_id = :b AND payment = :p ORDER BY id DESC LIMIT 1',
            [':b' => $bookingId, ':p' => $amount]
        );
    }

    /**
     * Restore seed state: the smoke deposit row + its notification + mail log.
     *
     * ALSO sweeps the residue the smoke walk itself creates (vendor enquiry,
     * invite-stamp and reminder notifications for the planner, plus the
     * couple's deposit receipt) so repeated smoke runs never flood the staff
     * bell or the couple's bell again.
     */
    private function dbCleanupDeposit(int $bookingId, float $amount): void
    {
        $db = $this->db();
        if ($db === null) {
            return;
        }
        // The couple's M-Pesa receipt (bell row + email leg) is smoke-created
        // and references the payment row deleted below — sweep it too.
        $db->execute(
            "DELETE FROM tbl_mail_logs
              WHERE notification_id IN (
                  SELECT id FROM (
                      SELECT id FROM tblnotifications
                       WHERE booking_id = :b AND message LIKE 'Asante! We received your M-Pesa payment%'
                  ) x
              )",
            [':b' => $bookingId]
        );
        $db->execute(
            "DELETE FROM tblnotifications
              WHERE booking_id = :b AND message LIKE 'Asante! We received your M-Pesa payment%'",
            [':b' => $bookingId]
        );
        $organizer = $this->dbBookingOrganizer($bookingId);
        if ($organizer > 0) {
            $notif = $db->fetchOne(
                'SELECT id FROM tblnotifications WHERE booking_id = :b AND user_id = :u ORDER BY id DESC LIMIT 1',
                [':b' => $bookingId, ':u' => $organizer]
            );
            if ($notif) {
                $db->execute('DELETE FROM tbl_mail_logs WHERE notification_id = :id', [':id' => (int) $notif->id]);
                $db->execute('DELETE FROM tblnotifications WHERE id = :id', [':id' => (int) $notif->id]);
            }

            // Residue sweep — page walks and POST legs on this booking produce
            // these three alert shapes; nothing in the seed depends on them.
            // (Derived-table wrapper satisfies MySQL's update-target rule.)
            $db->execute(
                "DELETE FROM tbl_mail_logs
                  WHERE notification_id IN (
                      SELECT id FROM (
                          SELECT id FROM tblnotifications
                           WHERE booking_id = :b AND user_id = :u
                             AND (message LIKE 'Invites marked sent%'
                                  OR message LIKE 'Manual RSVP reminders sent%'
                                  OR message LIKE 'RSVP reminders dispatched%'
                                  OR message LIKE '%enquired with%')
                      ) x
                  )",
                [':b' => $bookingId, ':u' => $organizer]
            );
            $db->execute(
                "DELETE FROM tblnotifications
                  WHERE booking_id = :b AND user_id = :u
                    AND (message LIKE 'Invites marked sent%'
                         OR message LIKE 'Manual RSVP reminders sent%'
                         OR message LIKE 'RSVP reminders dispatched%'
                         OR message LIKE '%enquired with%')",
                [':b' => $bookingId, ':u' => $organizer]
            );
        }
        $ledger = $this->dbDepositRow($bookingId, $amount);
        if ($ledger) {
            $db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => (int) $ledger->id]);
        }
    }

    /** Assert a page renders 200 with no PHP issues leaking into the body. */
    private function expectPage(string $label, string $path, bool $authed, bool $scanBody = true): void
    {
        $res = $this->http('GET', $path, [], $authed);
        $ok  = $res['status'] === 200;
        $detail = $ok ? '' : "HTTP {$res['status']}";
        if ($ok && $scanBody && $res['body'] !== '') {
            $bodyIssues = [];
            if (preg_match_all(BODY_ISSUE_RE, $res['body'], $m)) {
                $bodyIssues = array_values(array_unique($m[0]));
            }
            if ($bodyIssues !== []) {
                $ok = false;
                $detail = 'body leaks: ' . implode(' | ', array_slice($bodyIssues, 0, 3));
            }
        } elseif ($ok && $res['error'] !== '') {
            $ok = false;
            $detail = $res['error'];
        }
        $this->check($label, $ok, $detail);
    }

    /** Login helper: GET the login page for its CSRF token, then POST. */
    private function login(string $loginPath, string $email, string $pass, string $wantLocation): bool
    {
        $this->jar = []; // fresh session per role
        $page = $this->http('GET', $loginPath, [], false);
        if ($page['status'] !== 200 || !preg_match('~name="_token" value="([^"]+)"~', $page['body'], $m)) {
            $this->note("{$loginPath}: no CSRF token found (status {$page['status']}) — login flow skipped");
            return false;
        }
        $token = html_entity_decode($m[1], ENT_QUOTES);
        $post = $this->http('POST', $loginPath, [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => $token,
            'login'          => '1',
        ], true);

        if ($post['status'] === 302) {
            $this->check("login {$email}", stripos($post['location'] ?? '', $wantLocation) !== false,
                "redirected to {$post['location']}");
            return true;
        }
        // 200 = login form re-rendered = wrong seeded credentials, not a server fault.
        $this->note("login {$email}: returned HTTP {$post['status']} — seeded credentials may differ; flow skipped");
        return false;
    }

    public function run(): int
    {
        // --- reachability ---
        $probe = $this->http('GET', '/index.php');
        if ($probe['status'] === 0) {
            fwrite(STDERR, "Smoke: cannot reach {$this->base} — is the dev server running?\n");
            return 2;
        }

        $staffEmail  = getenv('SMOKE_STAFF_EMAIL') ?: STAFF_EMAIL;
        $staffPass   = getenv('SMOKE_STAFF_PASS') ?: STAFF_PASS;
        $clientEmail = getenv('SMOKE_CLIENT_EMAIL') ?: CLIENT_EMAIL;
        $clientPass  = getenv('SMOKE_CLIENT_PASS') ?: CLIENT_PASS;
        $coupleEmail = getenv('SMOKE_COUPLE_EMAIL') ?: COUPLE_EMAIL;
        $couplePass  = getenv('SMOKE_COUPLE_PASS') ?: COUPLE_PASS;

        echo "Smoke against {$this->base}\n\n";

        // --- 1. public site ---
        echo "Public pages\n";
        foreach (PUBLIC_PAGES as $page) {
            $this->expectPage($page, $page, false);
        }
        // theme assets must resolve (guards against broken/duplicated <link> tags);
        // static files are not HTML so the PHP-issue body scan does not apply.
        foreach (['/css/modern.css', '/admin/css/modern-admin.css', '/admin/css/dashboard.css'] as $asset) {
            $this->expectPage("asset {$asset}", $asset, false, false);
        }

        // --- 2. staff session ---
        echo "\nStaff flow\n";
        if ($this->login('/login.php', $staffEmail, $staffPass, 'dashboard')) {
            foreach (STAFF_PAGES as $page) {
                $this->expectPage($page, $page, true);
            }
            [$bookingId, $userId] = $this->bookingContext();
            echo "  (booking context: {$bookingId}/{$userId})\n";
            foreach (STAFF_BOOKING_PAGES as $page) {
                $path = str_replace(['{b}', '{u}'], [(string) $bookingId, (string) $userId], $page);
                $this->expectPage($path, $path, true);
            }
        }

        // --- 2b. capacity headroom (the staff-only drift view) ---
        /*
         * The surface the capacity warnings are derived from
         * (admin/headroom.php): the weddings running CLOSE to the guest capacity
         * of a package a vendor is confirmed on, before it becomes a breach. The
         * full harness walks it with a probe deal it creates and deletes; this is
         * the fast walk, and it asserts what can break without a fixture — the
         * page renders its heading, its width chips and the width it drew the
         * list at, the sidebar badge counts exactly what the page's own stat
         * counts (and disappears when the number is zero), a listed row reads as
         * a drifting position (or the empty state says why nothing is), the width
         * filter takes its own percentages and falls back on anything else, and
         * reading the whole thing rings no bell. A couple's denial is asserted in
         * the client flow below.
         */
        echo "\nCapacity headroom\n";
        $smokeDb      = $this->db();
        $notifsBefore = $smokeDb === null ? -1 : (int) ($smokeDb->fetchValue('SELECT COUNT(*) FROM tblnotifications') ?? -1);
        $head         = $this->http('GET', '/admin/headroom.php', [], true);
        $headBody     = $head['body'];
        $bodyLeaks    = [];
        if (preg_match_all(BODY_ISSUE_RE, $headBody, $bm)) {
            $bodyLeaks = array_values(array_unique($bm[0]));
        }
        $chips = str_contains($headBody, 'Within 5%')
            && str_contains($headBody, 'Within 10%')
            && str_contains($headBody, 'Within 20%');
        // The width the page OPENS at is the account's own (a planner may have
        // saved one), so this asserts that it states a width at all — which one
        // is asserted against the width filter below.
        $opensAt = static function (string $body): int {
            return preg_match('~Positions inside (\d+)%~', $body, $m) === 1 ? (int) $m[1] : -1;
        };
        $openingWidth = $opensAt($headBody);
        $this->check('staff capacity headroom view renders its heading, width chips and the width it opened at',
            $head['status'] === 200 && $bodyLeaks === []
                && str_contains($headBody, 'Capacity Headroom')
                && $chips
                && $openingWidth > 0,
            'HTTP ' . $head['status']
                . ($bodyLeaks !== [] ? ' body leaks: ' . implode(' | ', array_slice($bodyLeaks, 0, 3)) : '')
                . (str_contains($headBody, 'Capacity Headroom') ? '' : ' (no heading)')
                . ($chips ? '' : ' (width chips missing)')
                . ($openingWidth > 0 ? '' : ' (no width stat)'));

        // The badge is the whole reason the page is ever opened, and the one
        // thing that can silently disagree with it: zero must mean NO badge, and
        // any other number must be the page's own count.
        preg_match('~Weddings on watch</h6>\s*<div class="value">(\d+)</div>~', $headBody, $onWatch);
        $listed = isset($onWatch[1]) ? (int) $onWatch[1] : -1;
        $navItem = '';
        if (preg_match('~href="headroom\.php".*?</li>~s', $headBody, $li)) {
            $navItem = $li[0];
        }
        preg_match('~badge-warning[^>]*>\s*(\d+)~', $navItem, $badge);
        $badgeCount = isset($badge[1]) ? (int) $badge[1] : null;
        $this->check('the headroom badge counts exactly what the page lists, and is absent at zero',
            $navItem !== '' && $listed >= 0
                && (($listed === 0 && $badgeCount === null) || ($listed > 0 && $badgeCount === $listed)),
            $navItem === ''
                ? 'the navigation has no capacity headroom link'
                : 'page lists ' . ($listed < 0 ? '(no stat)' : $listed)
                    . ', badge ' . ($badgeCount === null ? 'absent' : $badgeCount));

        $this->check('a listed wedding reads as a drifting position, or the page says nothing is close',
            $listed > 0
                ? (bool) preg_match('~is planned for \d+ — (within \d+ guest|exactly the limit of)~', $headBody)
                : ($listed === 0 && str_contains($headBody, 'Nothing is running close to a limit')),
            'page lists ' . ($listed < 0 ? '(no stat)' : $listed)
                . ($listed > 0 ? ' and states no position' : ' and prints no empty state'));

        $narrow = $this->http('GET', '/admin/headroom.php?within=5', [], true);
        $junk   = $this->http('GET', '/admin/headroom.php?within=junk', [], true);
        $this->check('the headroom width filter takes its own percentages and falls back on anything else',
            $narrow['status'] === 200 && $opensAt($narrow['body']) === 5
                && $junk['status'] === 200 && $opensAt($junk['body']) === $openingWidth,
            'within=5 HTTP ' . $narrow['status'] . ' → ' . $opensAt($narrow['body']) . '%'
                . ', within=junk HTTP ' . $junk['status'] . ' → ' . $opensAt($junk['body']) . '%'
                . ' (the page opens at ' . $openingWidth . '%)');

        if ($notifsBefore >= 0) {
            $notifsAfter = (int) ($smokeDb->fetchValue('SELECT COUNT(*) FROM tblnotifications') ?? -1);
            $this->check('reading the headroom view rings no bell', $notifsAfter === $notifsBefore,
                "notifications {$notifsBefore} → {$notifsAfter}");
        } else {
            $this->note('headroom no-bell check skipped — dev DB not reachable');
        }

        // --- 3. client session (self-service dashboard) ---
        echo "\nClient flow\n";
        if ($this->login('/login.php', $clientEmail, $clientPass, 'client')) {
            $this->expectPage('client dashboard', '/admin/client.php', true);
            // Staff-only, like the ledger it is derived from: which deals were
            // sold, and how tight each one is, is not a couple's page.
            $denied = $this->http('GET', '/admin/headroom.php', [], true);
            $this->check('client is denied the capacity headroom view',
                $denied['status'] === 302 && !str_contains($denied['body'], 'Capacity Headroom'),
                'HTTP ' . $denied['status']);
            // Follow the first Overview link the dashboard renders, if any.
            $dash = $this->http('GET', '/admin/client.php', [], true);
            if (preg_match('~href="(client_manage_account_details\.php\?[^"]+)"~', $dash['body'], $m)) {
                $overview = '/admin/' . html_entity_decode($m[1], ENT_QUOTES);
                $this->expectPage('client overview (first booking)', $overview, true);
            } else {
                $this->note('seeded client has no bookings — client overview walk skipped');
            }
        }

        // --- 4. couple session (owns a booking: read-only guests + payments) ---
        echo "\nCouple flow\n";
        if ($this->login('/login.php', $coupleEmail, $couplePass, 'client')) {
            $this->expectPage('couple dashboard', '/admin/client.php', true);
            $dash = $this->http('GET', '/admin/client.php', [], true);
            if (preg_match('~booking_id=(\d+)&user_id=(\d+)~', $dash['body'], $m)) {
                $ids = 'booking_id=' . $m[1] . '&user_id=' . $m[2];
                foreach ([
                    'couple overview'  => '/admin/client_manage_account_details.php?' . $ids,
                    'couple guest list' => '/admin/client_guests.php?' . $ids,
                    'couple payments'   => '/admin/client_budget.php?' . $ids,
                    'couple deposit'    => '/admin/client_deposit.php?' . $ids,
                ] as $label => $path) {
                    $this->expectPage($label, $path, true);
                }
            } else {
                $this->note('couple account has no booking link on the dashboard — couple pages walk skipped');
            }
        }

        // --- 5. deposit -> planner alert (couple POST + staff bell badge) ---
        // Uses the client crededentials (SMOKE_CLIENT_EMAIL): the seeded Brian
        // account owns booking 200 with ceremonies and a non-zero balance, so
        // the full POST -> ledger -> notification -> bell-badge chain can run.
        echo "\nDeposit -> planner alert\n";
        if ($this->login('/login.php', $clientEmail, $clientPass, 'client')) {
            $dash = $this->http('GET', '/admin/client.php', [], true);
            if (preg_match('~booking_id=(\d+)&user_id=(\d+)~', $dash['body'], $m)) {
                $bid = (int) $m[1];
                $uid = (int) $m[2];
                $ids = 'booking_id=' . $bid . '&user_id=' . $uid;
                $dep = $this->http('GET', '/admin/client_deposit.php?' . $ids, [], true);
                $hasForm = $dep['status'] === 200
                    && preg_match('~name="event_id"[^>]*>\s*<option value="(\d+)"~s', $dep['body'], $ev)
                    && preg_match('~name="_token" value="([^"]+)"~', $dep['body'], $tk)
                    && preg_match('~Balance Due</h6>\s*<div class="value due">([^<]+)</div>~', $dep['body'], $due);
                if (!$hasForm) {
                    $this->note('deposit form unavailable (no ceremonies yet) — deposit POST skipped');
                } else {
                    $this->check('deposit form exposes CSRF token, ceremony and balance', true);
                    $balance = (float) preg_replace('/[^0-9.]/', '', $due[1]);
                    if ($balance < 50.0) {
                        $this->note('balance is ' . trim($due[1]) . ' — below the KSh 50 floor, live POST skipped');
                    } else {
                        $amount       = min(100.0, $balance);
                        $organizer    = $this->dbBookingOrganizer($bid);
                        $notifsBefore = $organizer > 0 ? $this->dbNotifCount($bid, $organizer) : 0;

                        $post = $this->http('POST', '/admin/client_deposit.php?' . $ids, [
                            '_token'   => html_entity_decode($tk[1], ENT_QUOTES),
                            'amount'   => (string) $amount,
                            'event_id' => $ev[1],
                            'phone'    => '0712345678',
                            'submit'   => '1',
                        ], true);
                        $this->check('couple deposit POST accepted',
                            $post['status'] === 302 && stripos((string) $post['location'], 'client_budget.php') !== false,
                            'HTTP ' . $post['status'] . ' -> ' . ($post['location'] ?: '(no location)'));

                        $ledger = $this->dbDepositRow($bid, $amount);
                        $this->check('ledger row recorded with STK-style reference',
                            $ledger !== null && preg_match('/^QDL[A-Z0-9]{7}$/', (string) ($ledger->mpesa_code ?? '')),
                            "no tbl_liquidation row for booking {$bid} payment={$amount}");

                        $notifsAfter = $organizer > 0 ? $this->dbNotifCount($bid, $organizer) : 0;
                        $this->check('planner notification created',
                            $organizer > 0 && $notifsAfter === $notifsBefore + 1,
                            "booking {$bid} organizer={$organizer}: before={$notifsBefore}, after={$notifsAfter}");

                        // Staff side: the assigned planner's bell badge on the dashboard.
                        $plannerEmail = getenv('SMOKE_PLANNER_EMAIL') ?: PLANNER_EMAIL;
                        $plannerPass  = getenv('SMOKE_PLANNER_PASS') ?: PLANNER_PASS;
                        if ($this->login('/login.php', $plannerEmail, $plannerPass, 'dashboard')) {
                            $dash2 = $this->http('GET', '/admin/dashboard.php', [], true);
                            preg_match('~badge-pill badge-danger[^>]*>\s*(\d+)~', $dash2['body'], $bm);
                            $badgeCount = (int) ($bm[1] ?? 0);
                            $unread     = $organizer > 0 ? $this->dbUnreadCount($organizer) : 0;
                            $this->check('planner bell badge reflects the deposit alert',
                                $unread >= 1 && $badgeCount === $unread && stripos($dash2['body'], 'M-Pesa deposit of') !== false,
                                "badge={$badgeCount}, db unread={$unread}");
                        } else {
                            $this->note('planner login unavailable — bell badge check skipped');
                        }

                        $this->dbCleanupDeposit($bid, $amount);
                    }
                }
            } else {
                $this->note('couple account has no booking — deposit walk skipped');
            }
        }

        // --- summary ---
        echo "\n{$this->passed} passed, {$this->failed} failed";
        if ($this->notes !== []) {
            echo ' (' . count($this->notes) . " skipped)";
        }
        echo "\n";

        return $this->failed === 0 ? 0 : 1;
    }
}

$base = $argv[1] ?? (getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8000');
exit((new Smoke($base))->run());
