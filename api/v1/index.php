<?php
/**
 * WPMS — Android API, v1
 * =====================================================================
 * The JSON door the couple's Android app talks to. It is NOT a second
 * backend: every query here reads the same tables, through the same
 * ownership predicates, that `admin/client.php` and the couple's other
 * pages read. If this file and those pages ever disagree about who may
 * see a wedding, one of them is a bug — and the app must never be the
 * one that is right.
 *
 *   GET  https://your-domain/api/v1/            → ping (no auth)
 *   POST https://your-domain/api/v1/?r=auth/login          {email,password,device,version}
 *   POST https://your-domain/api/v1/?r=auth/logout
 *   GET  https://your-domain/api/v1/?r=me
 *   GET  https://your-domain/api/v1/?r=home
 *   GET  https://your-domain/api/v1/?r=checklist
 *   POST https://your-domain/api/v1/?r=checklist/toggle    {id,done}
 *   GET  https://your-domain/api/v1/?r=guests&rsvp=&limit=&offset=
 *   GET  https://your-domain/api/v1/?r=budget
 *   GET  https://your-domain/api/v1/?r=messages
 *   GET  https://your-domain/api/v1/?r=messages/thread&deal_id=
 *   POST https://your-domain/api/v1/?r=messages/send    {deal_id,body}
 *   GET  https://your-domain/api/v1/?r=notifications&limit=
 *   POST https://your-domain/api/v1/?r=notifications/read  {id}   (no id = all)
 *
 * AUTHENTICATION
 *   `Authorization: Bearer wpmsapp_<64 hex>` on every call except login and
 *   ping. The bearer is stored only as a SHA-256 hash in `tblapp_devices`
 *   (migrations/v3.4_app_devices.sql) and belongs to exactly one couple:
 *   the app has no way to name a wedding, so an id typed into a request can
 *   never widen what it sees. Tokens live 60 days and are revoked by
 *   signing out; a lost phone is revoked by deleting its row (or setting
 *   `revoked_at`) in phpMyAdmin.
 *
 * RESPONSES
 *   Every answer is JSON, always with the same envelope:
 *     {"ok":true,"data":…}      {"ok":false,"error":{"code":"…","message":"…"}}
 *   HTTP status carries the truth as well (200/201, 400, 401, 403, 404, 405,
 *   429, 503), so the app can branch without parsing prose.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 *   - No session, no cookies: `WPMS_NO_SESSION` stops models.php starting
 *     one, exactly as mcp.php does. A phone that sends no cookie back would
 *     otherwise leave a session file per request.
 *   - No HTML, ever. A warning printed into a JSON body is a parse error on
 *     the phone, so display_errors is off and the shutdown hook answers 503
 *     with a machine-readable body if PHP dies mid-request.
 *   - No writes the couple could not make in a browser. Today that is
 *     ticking a checklist item, marking a notification read, and replying to a
 *     vendor on a deal their own wedding owns.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Africa/Nairobi');

// No PHP session for a machine that sends no cookie back (see mcp.php).
define('WPMS_NO_SESSION', true);

require_once __DIR__ . '/../../admin/include/models.php';
require_once __DIR__ . '/../../admin/include/LoginThrottle.php';
require_once __DIR__ . '/../../admin/include/VendorThread.php';

/** What this build answers as, and the oldest app that may call it. */
const APP_API_VERSION = '1.0';
const APP_API_MIN_BUILD = 1;

/** How long a phone stays signed in before it must sign in again. */
const APP_TOKEN_TTL_DAYS = 60;

/** Prefix on every secret, so a leaked string is recognisable. */
const APP_TOKEN_PREFIX = 'wpmsapp_';

/** Guest / notification page sizes the app may ask for. */
const APP_MAX_LIMIT = 200;
const APP_DEFAULT_LIMIT = 100;

/** `tblaccounts_detail.status` values that mean the wedding is real. */
const APP_LIVE_STATUSES = ['confirm', 'pending'];

$GLOBALS['api_answered'] = false;

/**
 * A failure that killed PHP before it could answer still leaves JSON.
 * Without this, a phone shows "Unexpected end of input" and the operator
 * cannot tell an outage from a bad token.
 */
register_shutdown_function(static function (): void {
    if ($GLOBALS['api_answered']) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => 'server_error', 'message' => 'this install could not serve the request; see the PHP error log'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

// ---------------------------------------------------------------------
// Envelope, input, output
// ---------------------------------------------------------------------

/** Send the one shape every successful answer takes, and stop. */
function api_ok(array $data, int $status = 200): void
{
    $GLOBALS['api_answered'] = true;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Send a refusal. `code` is stable for the app; `message` is for a human. */
function api_fail(int $status, string $code, string $message, array $extra = []): void
{
    $GLOBALS['api_answered'] = true;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $error = ['code' => $code, 'message' => $message];
    if ($extra) {
        $error += $extra;
    }
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** The decoded JSON body, or an empty array when the app sent none. */
function api_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        api_fail(400, 'bad_json', 'the request body is not valid JSON');
    }
    return $decoded;
}

/** Require a verb, so a GET can never mutate and a POST never reads. */
function api_require_method(string ...$allowed): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_fail(405, 'method_not_allowed', 'this endpoint answers ' . implode(' or ', $allowed) . ' only');
    }
}

/** Clamp a paging request into what one phone screen can use. */
function api_limit(array $query, string $key = 'limit'): int
{
    $value = (int) ($query[$key] ?? APP_DEFAULT_LIMIT);
    if ($value <= 0) {
        return APP_DEFAULT_LIMIT;
    }
    return min($value, APP_MAX_LIMIT);
}

/** The phone's IP, without trusting a spoofable header chain blindly. */
function api_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------

/** The shared PDO handle (models.php's own Database), created once. */
function api_db(): Database
{
    if (empty($GLOBALS['db']) || !($GLOBALS['db'] instanceof Database)) {
        $GLOBALS['db'] = new Database();
    }
    return $GLOBALS['db'];
}

/** One row, or null. */
function api_row(string $sql, array $params = []): ?array
{
    $stmt = api_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Every row. */
function api_rows(string $sql, array $params = []): array
{
    $stmt = api_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** One scalar. */
function api_value(string $sql, array $params = [])
{
    $stmt = api_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// ---------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------

/**
 * The couple's wedding date as a unix timestamp, or null.
 *
 * `tblweddingbook.wedding_date` is a varchar, and every install has at
 * least one row whose value is '' or '0000-00-00' (the booking existed
 * before a date was agreed). strtotime() happily turns '0000-00-00' into
 * a 1999 timestamp, so it is filtered here rather than in eight callers.
 */
function api_wedding_ts(?string $value): ?int
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : $ts;
}

/** Whole days from today to the wedding. Negative once it has passed. */
function api_days_to_go(?int $weddingTs): ?int
{
    if ($weddingTs === null) {
        return null;
    }
    $today = strtotime(date('Y-m-d') . ' 00:00:00');
    $day   = strtotime(date('Y-m-d', $weddingTs) . ' 00:00:00');
    return (int) round(($day - $today) / 86400);
}

/** ISO date for a moment, or null. */
function api_date(?int $ts): ?string
{
    return $ts === null ? null : date('Y-m-d', $ts);
}

// ---------------------------------------------------------------------
// The couple behind a bearer
// ---------------------------------------------------------------------

/** The bearer from whatever header the host actually passes through. */
function api_bearer(): string
{
    $header = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            $header = (string) $_SERVER[$key];
            break;
        }
    }
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) !== 1) {
        return '';
    }
    return $m[1];
}

/**
 * The couple this request speaks for: ['id'=>int,'email'=>…,'row'=>…,
 * 'device_id'=>int]. Refuses with 401 otherwise — every caller below runs
 * only after this succeeded.
 */
function api_auth(): array
{
    $token = api_bearer();
    if ($token === '' || !str_starts_with($token, APP_TOKEN_PREFIX)) {
        api_fail(401, 'unauthenticated', 'sign in to continue');
    }

    $hash = hash('sha256', $token);
    $row  = api_row(
        'SELECT d.id, d.user_id, d.device_name, d.expires_at, a.user_email
           FROM tblapp_devices d
           JOIN tblaccounts a ON a.user_id = d.user_id
          WHERE d.token_hash = :h AND d.revoked_at IS NULL
          LIMIT 1',
        [':h' => $hash]
    );

    if ($row === null) {
        api_fail(401, 'invalid_token', 'this device is not signed in; sign in again');
    }

    $expires = strtotime((string) $row['expires_at']);
    if ($expires !== false && $expires < time()) {
        api_fail(401, 'token_expired', 'this device has been signed in for too long; sign in again');
    }

    // Best-effort, and never a reason to fail the request: the write is a
    // convenience for the operator reading the table, not part of auth.
    try {
        api_db()->prepare('UPDATE tblapp_devices SET last_used_at = NOW(), last_used_ip = :ip WHERE id = :id')
            ->execute([':ip' => api_ip(), ':id' => (int) $row['id']]);
    } catch (Throwable $e) {
        error_log('[WPMS][App] touch failed: ' . $e->getMessage());
    }

    return [
        'id'        => (int) $row['user_id'],
        'email'     => (string) $row['user_email'],
        'device_id' => (int) $row['id'],
    ];
}

/**
 * The couple's wedding booking, resolved EXACTLY as admin/include/sidebar.php
 * resolves it: a wedding still to come wins, then the nearest date, then the
 * newest booking. Two doors, one ordering, so the app and the web can never
 * open different weddings for the same couple.
 */
function api_booking(int $userId): ?array
{
    return api_row(
        "SELECT booking_id, user_id, bride, groom, wedding_type, user_email,
                wedding_date, organizer_id
           FROM tblweddingbook
          WHERE user_id = :me
          ORDER BY (wedding_date IS NULL OR wedding_date < CURDATE()), wedding_date ASC, booking_id DESC
          LIMIT 1",
        [':me' => $userId]
    );
}

/** The couple's own detail row (name, phone, town, booking status). */
function api_detail(int $userId): ?array
{
    return api_row(
        'SELECT firstname, lastname, phone, city, location, status, date_signed,
                expectation_visitor, organizer_id
           FROM tblaccounts_detail
          WHERE user_id = :uid
          LIMIT 1',
        [':uid' => $userId]
    );
}

/** The couple's display name, never empty. */
function api_couple_name(?array $detail, string $email): string
{
    $first = trim((string) ($detail['firstname'] ?? ''));
    $last  = trim((string) ($detail['lastname'] ?? ''));
    $name  = trim($first . ' ' . $last);
    return $name !== '' ? $name : $email;
}

/** The wedding's name as the couple sees it: "Brian & Faith". */
function api_wedding_label(?array $booking): ?string
{
    if ($booking === null) {
        return null;
    }
    $bride = trim((string) ($booking['bride'] ?? ''));
    $groom = trim((string) ($booking['groom'] ?? ''));
    if ($bride !== '' && $groom !== '') {
        return $groom . ' & ' . $bride;
    }
    return $bride !== '' ? $bride : ($groom !== '' ? $groom : null);
}

// ---------------------------------------------------------------------
// Checklist (read + tick)
// ---------------------------------------------------------------------

/** Progress for one booking, the same arithmetic Checklist::progress uses. */
function api_checklist_progress(int $bookingId): array
{
    $row = api_row(
        'SELECT COUNT(*) AS total, COALESCE(SUM(is_done = 1), 0) AS done
           FROM tblchecklist WHERE booking_id = :b',
        [':b' => $bookingId]
    );
    $total = (int) ($row['total'] ?? 0);
    $done  = (int) ($row['done'] ?? 0);
    return [
        'total'   => $total,
        'done'    => $done,
        'percent' => $total > 0 ? (int) round($done * 100 / $total) : 0,
    ];
}

/**
 * The checklist as phases, with each item's real due date worked out from
 * the wedding date. Items keep the order the page uses: phase first seen,
 * then position, then id.
 */
function api_checklist(int $bookingId, ?int $weddingTs): array
{
    $rows = api_rows(
        'SELECT id, phase, title, due_offset_days, position, is_done, done_at
           FROM tblchecklist
          WHERE booking_id = :b
          ORDER BY position ASC, id ASC',
        [':b' => $bookingId]
    );

    $today  = strtotime(date('Y-m-d'));
    $phases = [];
    foreach ($rows as $row) {
        $due = null;
        if ($weddingTs !== null) {
            $due = $weddingTs - ((int) $row['due_offset_days'] * 86400);
        }
        $item = [
            'id'        => (int) $row['id'],
            'title'     => (string) $row['title'],
            'is_done'   => (int) $row['is_done'] === 1,
            'done_at'   => $row['done_at'] !== null ? (string) $row['done_at'] : null,
            'due_on'    => api_date($due),
            'overdue'   => $due !== null && (int) $row['is_done'] !== 1 && $due < $today,
            'days_left' => $due === null ? null : (int) round(($due - $today) / 86400),
        ];
        $phase = trim((string) $row['phase']);
        if ($phase === '') {
            $phase = 'General';
        }
        $phases[$phase][] = $item;
    }

    $out = [];
    foreach ($phases as $name => $items) {
        $out[] = ['name' => $name, 'items' => $items];
    }
    return $out;
}

// ---------------------------------------------------------------------
// Guests (read only in v1)
// ---------------------------------------------------------------------

/** Counts by RSVP answer, for the home screen's one-line summary. */
function api_guest_counts(int $bookingId): array
{
    $row = api_row(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(rsvp_status = 'yes'), 0)     AS yes,
                COALESCE(SUM(rsvp_status = 'no'), 0)      AS no,
                COALESCE(SUM(rsvp_status = 'maybe'), 0)   AS maybe,
                COALESCE(SUM(rsvp_status = 'pending'), 0) AS pending
           FROM tblguest WHERE booking_id = :b",
        [':b' => $bookingId]
    );
    return [
        'total'   => (int) ($row['total'] ?? 0),
        'yes'     => (int) ($row['yes'] ?? 0),
        'no'      => (int) ($row['no'] ?? 0),
        'maybe'   => (int) ($row['maybe'] ?? 0),
        'pending' => (int) ($row['pending'] ?? 0),
    ];
}

// ---------------------------------------------------------------------
// Notifications (the same bell the web shows)
// ---------------------------------------------------------------------

function api_unread_count(int $userId, int $bookingId): int
{
    return (int) api_value(
        "SELECT COUNT(*) FROM tblnotifications
          WHERE recipient_type = 'account' AND user_id = :u AND is_read = 0",
        [':u' => $userId]
    );
}

// ---------------------------------------------------------------------
// Money: what was paid, what is left, what is due next
// ---------------------------------------------------------------------

/** "mpesa"/"cash"/"credit" for one liquidation row, from how it was paid. */
function api_payment_method(array $row): string
{
    if (trim((string) ($row['mpesa_code'] ?? '')) !== '') {
        return 'mpesa';
    }
    if ((float) ($row['cash'] ?? 0) > 0) {
        return 'cash';
    }
    if ((float) ($row['credit'] ?? 0) > 0) {
        return 'credit';
    }
    return 'other';
}

/**
 * Everything the Budget screen paints, in one call.
 *
 * The arithmetic is the website's, not a second opinion: the package price is
 * the wedding category's price, "paid" is `Liquidation::getTotalsByMethod`
 * over `tbl_liquidation` (the same ledger `admin/client_budget.php` prints),
 * and the plan is `InstallmentPlan::progress()` — the very method the overview
 * nudge uses. A couple comparing the app to the site must never find two
 * different balances.
 *
 * Two figures are deliberately kept apart, as `VendorPlan` keeps them on the
 * web: the PACKAGE balance (what is still owed on the booking) and the sum of
 * vendor deals COMMITTED (what has been agreed with vendors). Adding them
 * would count the same shilling twice on most weddings.
 */
function api_budget(array $booking): array
{
    $bookingId = (int) $booking['booking_id'];
    $weddingTs = api_wedding_ts((string) $booking['wedding_date']);
    $today     = strtotime(date('Y-m-d'));

    // The package the couple booked. A wedding with no category (or a category
    // deleted after booking) has no price — 0 means "not priced", and the app
    // shows the payments made rather than an invented balance.
    $price = 0.0;
    $label = (string) ($booking['wedding_type'] ?? '');
    try {
        $category = Category::find_by_id((int) $booking['wedding_type']);
        if ($category !== null) {
            $price = (float) $category->price;
            $label = (string) $category->wedding_type;
        }
    } catch (Throwable $e) {
        error_log('[WPMS][App] category price failed: ' . $e->getMessage());
    }

    $totals = ['mpesa_total' => 0, 'cash_total' => 0, 'credit_total' => 0, 'grand_total' => 0];
    try {
        $totals = Liquidation::getTotalsByMethod($bookingId);
    } catch (Throwable $e) {
        error_log('[WPMS][App] payment totals failed: ' . $e->getMessage());
    }
    $paid = (float) ($totals['grand_total'] ?? 0);

    $ledger = api_row(
        'SELECT COUNT(*) AS n, MAX(date_issue) AS last_at
           FROM tbl_liquidation WHERE booking_id = :b',
        [':b' => $bookingId]
    );

    $rows = api_rows(
        'SELECT id, payment, cash, credit, mpesa_code, date_issue
           FROM tbl_liquidation
          WHERE booking_id = :b
          ORDER BY id DESC
          LIMIT 20',
        [':b' => $bookingId]
    );
    $payments = [];
    foreach ($rows as $row) {
        $payments[] = [
            'id'        => (int) $row['id'],
            'amount'    => (float) $row['payment'],
            'method'    => api_payment_method($row),
            'reference' => trim((string) ($row['mpesa_code'] ?? '')),
            'on'        => substr(trim((string) $row['date_issue']), 0, 10),
        ];
    }

    // Harambee: the committee's pledges are money the couple is counting on,
    // so they belong next to the balance rather than in a separate story.
    $pledged = 0.0;
    $pledgedGuests = 0;
    try {
        $pledged       = Guest::total_harambee($bookingId);
        $pledgedGuests = Guest::count_pledged($bookingId);
    } catch (Throwable $e) {
        error_log('[WPMS][App] harambee failed: ' . $e->getMessage());
    }

    $vendors = api_row(
        "SELECT COUNT(*) AS deals,
                COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN amount ELSE 0 END), 0) AS committed,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS planned
           FROM tblvendor_bookings WHERE booking_id = :b",
        [':b' => $bookingId]
    );

    $balance = max(0.0, $price - $paid);

    // The monthly plan, when the couple set one on the website. Without it the
    // "next" figure falls back to the settlement pace the overview nudges:
    // the balance cleared a month before the wedding, which is when Kenyan
    // vendors expect to be settled.
    $plan = null;
    $next = null;
    try {
        $active = InstallmentPlan::for_booking($bookingId);
        if ($active !== null) {
            $prog = $active->progress($paid);
            $plan = [
                'months'         => (int) $active->months,
                'monthly_amount' => (float) $active->monthly_amount,
                'done'           => (int) $prog['done'],
                'total'          => (int) $prog['total'],
                'paid_into_plan' => (float) $prog['paid_into_plan'],
                'on_track'       => (bool) $prog['on_track'],
                'next_due'       => $prog['next_due'],
                'next_amount'    => (float) $prog['next_amount'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[WPMS][App] installment plan failed: ' . $e->getMessage());
    }

    if ($plan !== null && $plan['next_due'] !== null) {
        $next = [
            'kind'    => 'installment',
            'label'   => 'Installment ' . ($plan['done'] + 1) . ' of ' . $plan['total'],
            'amount'  => $plan['next_amount'],
            'due_on'  => $plan['next_due'],
            'overdue' => !$plan['on_track'],
        ];
    } elseif ($balance > 0) {
        $dueTs = $weddingTs !== null ? $weddingTs - (30 * 86400) : null;
        $next  = [
            'kind'    => 'balance',
            'label'   => 'Balance to settle',
            'amount'  => $balance,
            'due_on'  => api_date($dueTs),
            'overdue' => $dueTs !== null && $dueTs < $today,
        ];
    }

    return [
        'currency' => 'KES',
        'package'  => ['label' => $label, 'price' => round($price, 2)],
        'paid'     => [
            'total'    => round($paid, 2),
            'mpesa'    => round((float) ($totals['mpesa_total'] ?? 0), 2),
            'cash'     => round((float) ($totals['cash_total'] ?? 0), 2),
            'credit'   => round((float) ($totals['credit_total'] ?? 0), 2),
            'count'    => (int) ($ledger['n'] ?? 0),
            'last_at'  => isset($ledger['last_at']) ? substr((string) $ledger['last_at'], 0, 10) : null,
        ],
        'balance'  => round($balance, 2),
        'pledged'  => ['amount' => round($pledged, 2), 'guests' => (int) $pledgedGuests],
        'vendors'  => [
            'deals'     => (int) ($vendors['deals'] ?? 0),
            'committed' => round((float) ($vendors['committed'] ?? 0), 2),
            'planned'   => round((float) ($vendors['planned'] ?? 0), 2),
        ],
        'plan'     => $plan,
        'next'     => $next,
        'payments' => $payments,
    ];
}

// ---------------------------------------------------------------------
// Vendor deals and their message threads
// ---------------------------------------------------------------------

/**
 * Every vendor deal on this wedding, for the messages list.
 *
 * The booking must be the couple's own (`b.user_id`), which is the same
 * predicate `VendorThread::authorize()` applies to a couple on the website —
 * re-stated in SQL because there is no session here to ask.
 */
function api_couple_deals(int $bookingId, int $userId): array
{
    return api_rows(
        'SELECT vb.id, vb.amount, vb.status, vb.created_at, vb.event_date,
                v.business_name, v.category,
                p.title AS package_title
           FROM tblvendor_bookings vb
           JOIN tblvendors v ON v.id = vb.vendor_id
           JOIN tblweddingbook b ON b.booking_id = vb.booking_id
           LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
          WHERE vb.booking_id = :b AND b.user_id = :u
          ORDER BY vb.id DESC',
        [':b' => $bookingId, ':u' => $userId]
    );
}

/** The human name of a vendor category slug ("catering" → "Catering"). */
function api_category_label(string $slug): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach (VendorCoverage::categories() as $key => $meta) {
                $map[(string) $key] = (string) ($meta[0] ?? $key);
            }
        } catch (Throwable $e) {
            $map = [];
        }
    }
    $slug = trim($slug);
    return $map[$slug] ?? ($slug !== '' ? ucfirst($slug) : 'Vendor');
}

/**
 * The deal this couple may act on, or a refusal.
 *
 * Two predicates, both required: the deal is on the wedding this phone's
 * bearer resolved to, AND that wedding belongs to this account. The second is
 * redundant today — api_booking() only ever returns the caller's own booking —
 * and is kept because "redundant" is exactly what a future change to
 * api_booking() would make false.
 */
function api_deal_for_couple(int $dealId, int $userId, int $bookingId): object
{
    if ($dealId <= 0) {
        api_fail(400, 'missing_deal', 'which vendor deal is this about?');
    }
    if (!class_exists('VendorThread')) {
        api_fail(503, 'messaging_unavailable', 'messaging is not installed on this site');
    }
    $deal = VendorThread::find($dealId);
    if ($deal === null
        || (int) $deal->couple_user_id !== $userId
        || (int) $deal->booking_id !== $bookingId
    ) {
        // One answer for "no such deal" and "not yours": a couple must not be
        // able to probe which deal numbers exist on other weddings.
        api_fail(404, 'not_found', 'there is no vendor deal with that number on your wedding');
    }
    return $deal;
}

/** One message row as the app renders it. */
function api_message(?object $row): ?array
{
    if ($row === null) {
        return null;
    }
    return [
        'id'     => (int) $row->id,
        'from'   => (string) $row->author_role === VendorThread::COUPLE ? 'you' : 'vendor',
        'role'   => (string) $row->author_role,
        'author' => (string) $row->author_name,
        'body'   => (string) $row->body,
        'at'     => (string) $row->created_at,
    ];
}

/** The deal header both the list and the thread show. */
function api_deal_summary(object $deal): array
{
    return [
        'deal_id'    => (int) $deal->id,
        'vendor'     => (string) $deal->business_name,
        'category'   => api_category_label((string) $deal->category),
        'amount'     => (float) $deal->amount,
        'status'     => (string) $deal->status,
        'event_date' => ($deal->event_date ?? null) ? (string) $deal->event_date : null,
    ];
}

// =====================================================================
// ROUTES
// =====================================================================

$route  = (string) ($_GET['r'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    switch ($route) {

        // -------------------------------------------------------------
        // ping — no auth, so the app can prove the base URL is right
        // -------------------------------------------------------------
        case '':
        case 'ping':
            api_require_method('GET');
            api_ok([
                'api'        => APP_API_VERSION,
                'min_build'  => APP_API_MIN_BUILD,
                'server_time' => date('c'),
                'timezone'   => date_default_timezone_get(),
            ]);

        // -------------------------------------------------------------
        // auth/login — email + password for a COUPLE account
        // -------------------------------------------------------------
        case 'auth/login':
            api_require_method('POST');

            $body     = api_body();
            $email    = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');
            $device   = substr(trim((string) ($body['device'] ?? 'Android')), 0, 80);
            $version  = substr(trim((string) ($body['version'] ?? '')), 0, 20);

            if ($email === '' || $password === '') {
                api_fail(400, 'missing_credentials', 'enter both your email and your password');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                api_fail(400, 'bad_email', 'that does not look like an email address');
            }

            // The same lockout ladder the website uses: five wrong passwords,
            // then 15 minutes, then 1 hour, then 6 — and the account owner is
            // texted on each lockout. The app must not be a way around it.
            $wait = LoginThrottle::secondsRemaining($email, api_ip());
            if ($wait > 0) {
                api_fail(429, 'too_many_attempts', 'too many wrong passwords; try again in ' . (int) ceil($wait / 60) . ' minute(s)', [
                    'retry_after' => $wait,
                ]);
            }

            $account = api_row(
                'SELECT user_id, user_email, user_password FROM tblaccounts WHERE LOWER(user_email) = :e LIMIT 1',
                [':e' => $email]
            );

            // One answer for "no such account" and "wrong password": telling
            // them apart turns login into a way to enumerate your customers.
            if ($account === null || !password_verify($password, (string) $account['user_password'])) {
                LoginThrottle::recordFailure($email, api_ip());
                api_fail(401, 'bad_credentials', 'that email and password do not match');
            }

            LoginThrottle::clear($email, api_ip());

            $secret  = APP_TOKEN_PREFIX . bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + (APP_TOKEN_TTL_DAYS * 86400));

            api_db()->prepare(
                'INSERT INTO tblapp_devices
                    (user_id, device_name, platform, app_version, token_hash, token_prefix, expires_at)
                 VALUES (:uid, :dev, :plat, :ver, :hash, :pre, :exp)'
            )->execute([
                ':uid'  => (int) $account['user_id'],
                ':dev'  => $device !== '' ? $device : 'Android',
                ':plat' => 'android',
                ':ver'  => $version,
                ':hash' => hash('sha256', $secret),
                ':pre'  => substr($secret, -4),
                ':exp'  => $expires,
            ]);

            $detail  = api_detail((int) $account['user_id']);
            $booking = api_booking((int) $account['user_id']);

            api_ok([
                'token'      => $secret,
                'expires_at' => date('c', strtotime($expires)),
                'couple'     => [
                    'id'         => (int) $account['user_id'],
                    'name'       => api_couple_name($detail, (string) $account['user_email']),
                    'first_name' => trim((string) ($detail['firstname'] ?? '')),
                    'email'      => (string) $account['user_email'],
                ],
                'wedding'    => $booking === null ? null : [
                    'booking_id'   => (int) $booking['booking_id'],
                    'label'        => api_wedding_label($booking),
                    'date'         => api_date(api_wedding_ts((string) $booking['wedding_date'])),
                    'days_to_go'   => api_days_to_go(api_wedding_ts((string) $booking['wedding_date'])),
                    'status'       => (string) ($detail['status'] ?? 'pending'),
                    'has_booking'  => true,
                ],
            ]);

        // -------------------------------------------------------------
        // auth/logout — revoke THIS device's token
        // -------------------------------------------------------------
        case 'auth/logout':
            api_require_method('POST');
            $me = api_auth();
            api_db()->prepare('UPDATE tblapp_devices SET revoked_at = NOW() WHERE id = :id')
                ->execute([':id' => $me['device_id']]);
            api_ok(['signed_out' => true]);

        // -------------------------------------------------------------
        // me — who this phone is, and their wedding
        // -------------------------------------------------------------
        case 'me':
            api_require_method('GET');
            $me      = api_auth();
            $detail  = api_detail($me['id']);
            $booking = api_booking($me['id']);
            $weddingTs = $booking !== null ? api_wedding_ts((string) $booking['wedding_date']) : null;

            api_ok([
                'id'       => $me['id'],
                'name'     => api_couple_name($detail, $me['email']),
                'email'    => $me['email'],
                'phone'    => (string) ($detail['phone'] ?? ''),
                'city'     => (string) ($detail['city'] ?? ''),
                'wedding'  => $booking === null ? null : [
                    'booking_id' => (int) $booking['booking_id'],
                    'label'      => api_wedding_label($booking),
                    'bride'      => (string) $booking['bride'],
                    'groom'      => (string) $booking['groom'],
                    'date'       => api_date($weddingTs),
                    'days_to_go' => api_days_to_go($weddingTs),
                    'status'     => (string) ($detail['status'] ?? 'pending'),
                    'venue'      => (string) ($detail['location'] ?? ''),
                ],
            ]);

        // -------------------------------------------------------------
        // home — one call, everything the first screen paints
        // -------------------------------------------------------------
        case 'home':
            api_require_method('GET');
            $me      = api_auth();
            $detail  = api_detail($me['id']);
            $booking = api_booking($me['id']);

            if ($booking === null) {
                api_ok([
                    'couple'        => api_couple_name($detail, $me['email']),
                    'wedding'       => null,
                    'has_booking'   => false,
                    'checklist'     => ['total' => 0, 'done' => 0, 'percent' => 0, 'next' => []],
                    'guests'        => ['total' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0, 'pending' => 0],
                    'notifications' => ['unread' => 0],
                    'messages'      => ['unread' => 0],
                ]);
            }

            $bookingId = (int) $booking['booking_id'];
            $weddingTs = api_wedding_ts((string) $booking['wedding_date']);
            $progress  = api_checklist_progress($bookingId);

            // Next three things to do: not done, soonest due first. An item
            // with no date sorts last rather than first — "no deadline" is
            // not "urgent".
            $next = [];
            foreach (api_checklist($bookingId, $weddingTs) as $phase) {
                foreach ($phase['items'] as $item) {
                    if ($item['is_done']) {
                        continue;
                    }
                    $next[] = [
                        'id'        => $item['id'],
                        'title'     => $item['title'],
                        'phase'     => $phase['name'],
                        'due_on'    => $item['due_on'],
                        'overdue'   => $item['overdue'],
                        'days_left' => $item['days_left'],
                    ];
                }
            }
            usort($next, static function (array $a, array $b): int {
                $ad = $a['days_left'] ?? PHP_INT_MAX;
                $bd = $b['days_left'] ?? PHP_INT_MAX;
                return $ad <=> $bd;
            });

            $messagesUnread = 0;
            if (class_exists('VendorThread')) {
                try {
                    // The role is passed explicitly: null would make
                    // unread_total() ask session_role(), and there is no
                    // session here — the badge would always read 0.
                    $messagesUnread = (int) VendorThread::unread_total(VendorThread::COUPLE, $me['id'], $bookingId);
                } catch (Throwable $e) {
                    error_log('[WPMS][App] unread_total failed: ' . $e->getMessage());
                }
            }

            api_ok([
                'couple'  => api_couple_name($detail, $me['email']),
                'wedding' => [
                    'booking_id' => $bookingId,
                    'label'      => api_wedding_label($booking),
                    'date'       => api_date($weddingTs),
                    'days_to_go' => api_days_to_go($weddingTs),
                    'status'     => (string) ($detail['status'] ?? 'pending'),
                    'venue'      => (string) ($detail['location'] ?? ''),
                ],
                'has_booking'   => true,
                'checklist'     => $progress + ['next' => array_slice($next, 0, 3)],
                'guests'        => api_guest_counts($bookingId),
                'notifications' => ['unread' => api_unread_count($me['id'], $bookingId)],
                'messages'      => ['unread' => $messagesUnread],
            ]);

        // -------------------------------------------------------------
        // checklist — read
        // -------------------------------------------------------------
        case 'checklist':
            api_require_method('GET');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            $bookingId = (int) $booking['booking_id'];
            $weddingTs = api_wedding_ts((string) $booking['wedding_date']);

            api_ok([
                'progress' => api_checklist_progress($bookingId),
                'phases'   => api_checklist($bookingId, $weddingTs),
            ]);

        // -------------------------------------------------------------
        // checklist/toggle — the one write v1 allows here
        // -------------------------------------------------------------
        case 'checklist/toggle':
            api_require_method('POST');
            $me      = api_auth();
            $body    = api_body();
            $id      = (int) ($body['id'] ?? 0);
            $done    = !empty($body['done']);

            if ($id <= 0) {
                api_fail(400, 'missing_id', 'which checklist item?');
            }

            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            $bookingId = (int) $booking['booking_id'];

            // Ownership is checked by the SELECT, not by the UPDATE's
            // affected-row count: MySQL reports 0 rows changed when the item
            // already holds the value being written, which is exactly what
            // happens when the app re-sends a tick (a retried request, or a
            // doubled tap). Reading first makes "not yours" and "already
            // like that" two different answers instead of one wrong one.
            $existing = api_row(
                'SELECT id FROM tblchecklist WHERE id = :id AND booking_id = :b LIMIT 1',
                [':id' => $id, ':b' => $bookingId]
            );
            if ($existing === null) {
                api_fail(404, 'not_found', 'that checklist item is not on your wedding');
            }

            api_db()->prepare(
                'UPDATE tblchecklist
                    SET is_done = :done, done_at = :at
                  WHERE id = :id AND booking_id = :b'
            )->execute([
                ':done' => $done ? 1 : 0,
                ':at'   => $done ? date('Y-m-d H:i:s') : null,
                ':id'   => $id,
                ':b'    => $bookingId,
            ]);

            api_ok([
                'id'       => $id,
                'is_done'  => $done,
                'progress' => api_checklist_progress($bookingId),
            ]);

        // -------------------------------------------------------------
        // guests — read only; answering RSVPs is the guest's link, not this
        // -------------------------------------------------------------
        case 'guests':
            api_require_method('GET');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            $bookingId = (int) $booking['booking_id'];

            $limit  = api_limit($_GET);
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $rsvp   = strtolower(trim((string) ($_GET['rsvp'] ?? '')));
            $allowed = ['yes', 'no', 'maybe', 'pending'];

            $where  = 'booking_id = :b';
            $params = [':b' => $bookingId];
            if (in_array($rsvp, $allowed, true)) {
                $where .= ' AND rsvp_status = :rsvp';
                $params[':rsvp'] = $rsvp;
            }

            $rows = api_rows(
                "SELECT id, fullname, guestname, phone, relationship, priority,
                        rsvp_status, rsvp_at, rsvp_note, dietary_requirements,
                        transport_needed, upcountry_accommodation
                   FROM tblguest
                  WHERE $where
                  ORDER BY fullname ASC, id ASC
                  LIMIT $limit OFFSET $offset",
                $params
            );

            $guests = [];
            foreach ($rows as $row) {
                $guests[] = [
                    'id'             => (int) $row['id'],
                    'name'           => trim((string) $row['fullname']) !== '' ? (string) $row['fullname'] : (string) $row['guestname'],
                    'phone'          => (string) ($row['phone'] ?? ''),
                    'relationship'   => (string) $row['relationship'],
                    'priority'       => (string) $row['priority'],
                    'rsvp'           => (string) ($row['rsvp_status'] ?? 'pending'),
                    'rsvp_at'        => $row['rsvp_at'] !== null ? (string) $row['rsvp_at'] : null,
                    'note'           => (string) ($row['rsvp_note'] ?? ''),
                    'dietary'        => (string) ($row['dietary_requirements'] ?? ''),
                    'transport'      => (int) $row['transport_needed'] === 1,
                    'accommodation'  => (int) $row['upcountry_accommodation'] === 1,
                ];
            }

            api_ok([
                'counts' => api_guest_counts($bookingId),
                'offset' => $offset,
                'limit'  => $limit,
                'guests' => $guests,
            ]);

        // -------------------------------------------------------------
        // budget — what was paid, what is left, what is due next
        // -------------------------------------------------------------
        case 'budget':
            api_require_method('GET');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            api_ok(api_budget($booking));

        // -------------------------------------------------------------
        // messages — every vendor thread on this wedding, newest first
        // -------------------------------------------------------------
        case 'messages':
            api_require_method('GET');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            $bookingId = (int) $booking['booking_id'];
            $deals     = api_couple_deals($bookingId, $me['id']);
            $ids       = array_map(static fn(array $d): int => (int) $d['id'], $deals);

            // One query per question, never one per deal: a couple with twelve
            // enquiries would otherwise make the phone wait twelve times.
            $threads  = VendorThread::for_deals($ids);
            $unread   = VendorThread::unread_counts($ids, VendorThread::COUPLE);
            $waiting  = VendorThread::waiting_since($ids, $threads, VendorThread::COUPLE);

            $out = [];
            foreach ($deals as $deal) {
                $dealId  = (int) $deal['id'];
                $messages = $threads[$dealId] ?? [];
                $last     = $messages !== [] ? $messages[count($messages) - 1] : null;
                $lastFrom = $last !== null ? (string) $last->author_role : '';

                $out[] = [
                    'deal_id'    => $dealId,
                    'vendor'     => (string) $deal['business_name'],
                    'category'   => api_category_label((string) $deal['category']),
                    'amount'     => (float) $deal['amount'],
                    'status'     => (string) $deal['status'],
                    'unread'     => (int) ($unread[$dealId] ?? 0),
                    'messages'   => count($messages),
                    'last'       => $last === null ? null : [
                        'body' => mb_substr(trim((string) $last->body), 0, 160),
                        'from' => $lastFrom === VendorThread::COUPLE ? 'you' : 'vendor',
                        'at'   => (string) $last->created_at,
                    ],
                    // Whose move it is, in the couple's terms: they wrote last,
                    // so they are waiting; the vendor wrote last, so it is their
                    // turn. A deal with no messages is an enquiry awaiting reply.
                    'waiting_on' => $lastFrom === VendorThread::COUPLE || $last === null ? 'vendor' : 'you',
                    'waiting_for' => isset($waiting[$dealId])
                        ? VendorThread::waiting_for((string) $waiting[$dealId][0])
                        : null,
                    'sort_at'    => $last !== null ? (string) $last->created_at : (string) $deal['created_at'],
                ];
            }

            usort($out, static fn(array $a, array $b): int => strcmp($b['sort_at'], $a['sort_at']));
            foreach ($out as &$row) {
                unset($row['sort_at']);
            }
            unset($row);

            api_ok([
                'unread'  => VendorThread::unread_total(VendorThread::COUPLE, $me['id'], $bookingId),
                'threads' => $out,
            ]);

        // -------------------------------------------------------------
        // messages/thread — one thread, and reading it is the read receipt
        // -------------------------------------------------------------
        case 'messages/thread':
            api_require_method('GET');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            $bookingId = (int) $booking['booking_id'];
            $deal      = api_deal_for_couple((int) ($_GET['deal_id'] ?? 0), $me['id'], $bookingId);

            // Opening the thread is opening the message, exactly as the website
            // does when the couple lands on their ledger row.
            VendorThread::mark_read((int) $deal->id, VendorThread::COUPLE);

            $messages = [];
            foreach (VendorThread::for_deal((int) $deal->id) as $row) {
                $messages[] = api_message($row);
            }

            api_ok([
                'deal'     => api_deal_summary($deal),
                'messages' => $messages,
                'unread'   => VendorThread::unread_total(VendorThread::COUPLE, $me['id'], $bookingId),
            ]);

        // -------------------------------------------------------------
        // messages/send — the couple's reply
        // -------------------------------------------------------------
        case 'messages/send':
            api_require_method('POST');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            if ($booking === null) {
                api_fail(404, 'no_wedding', 'there is no wedding on this account yet');
            }
            $bookingId = (int) $booking['booking_id'];
            $body      = api_body();
            $dealId    = (int) ($body['deal_id'] ?? 0);
            $text      = (string) ($body['body'] ?? '');

            $deal = api_deal_for_couple($dealId, $me['id'], $bookingId);

            // Length is judged the way the server will store it, so a message
            // that arrives as 2,001 characters fails loudly here instead of
            // being silently cut short on the way in.
            $clean = VendorThread::clean_body($text);
            if ($clean === '') {
                api_fail(400, 'empty_message', 'write a message first');
            }
            if (mb_strlen($clean) < mb_strlen(trim($text))) {
                api_fail(400, 'message_too_long', 'keep it under ' . VendorThread::MAX_BODY . ' characters');
            }

            $messageId = VendorThread::post($dealId, VendorThread::COUPLE, $me['id'], $clean);
            if ($messageId <= 0) {
                api_fail(500, 'not_sent', 'your message was not saved; please try again');
            }

            $stored = api_row('SELECT * FROM tblvendor_messages WHERE id = :id LIMIT 1', [':id' => $messageId]);

            api_ok([
                'deal_id' => $dealId,
                'message' => $stored === null ? null : api_message((object) $stored),
                'unread'  => VendorThread::unread_total(VendorThread::COUPLE, $me['id'], $bookingId),
            ], 201);

        // -------------------------------------------------------------
        // notifications — the same bell the website rings
        // -------------------------------------------------------------
        case 'notifications':
            api_require_method('GET');
            $me      = api_auth();
            $booking = api_booking($me['id']);
            $bookingId = $booking !== null ? (int) $booking['booking_id'] : 0;
            $limit   = api_limit($_GET, 'limit');

            $rows = api_rows(
                "SELECT id, message, link, is_read, created_at
                   FROM tblnotifications
                  WHERE recipient_type = 'account' AND user_id = :u
                  ORDER BY id DESC
                  LIMIT $limit",
                [':u' => $me['id']]
            );

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'      => (int) $row['id'],
                    'message' => (string) $row['message'],
                    'link'    => (string) $row['link'],
                    'is_read' => (int) $row['is_read'] === 1,
                    'at'      => (string) $row['created_at'],
                ];
            }

            api_ok([
                'unread'        => api_unread_count($me['id'], $bookingId),
                'notifications' => $out,
            ]);

        case 'notifications/read':
            api_require_method('POST');
            $me   = api_auth();
            $body = api_body();
            $id   = (int) ($body['id'] ?? 0);

            if ($id > 0) {
                $stmt = api_db()->prepare(
                    "UPDATE tblnotifications SET is_read = 1
                      WHERE id = :id AND recipient_type = 'account' AND user_id = :u"
                );
                $stmt->execute([':id' => $id, ':u' => $me['id']]);
                $changed = $stmt->rowCount();
            } else {
                $stmt = api_db()->prepare(
                    "UPDATE tblnotifications SET is_read = 1
                      WHERE recipient_type = 'account' AND user_id = :u AND is_read = 0"
                );
                $stmt->execute([':u' => $me['id']]);
                $changed = $stmt->rowCount();
            }

            $booking   = api_booking($me['id']);
            $bookingId = $booking !== null ? (int) $booking['booking_id'] : 0;
            api_ok(['marked' => $changed, 'unread' => api_unread_count($me['id'], $bookingId)]);

        // -------------------------------------------------------------
        default:
            api_fail(404, 'unknown_route', 'there is no endpoint called "' . substr($route, 0, 60) . '"');
    }
} catch (Throwable $e) {
    error_log('[WPMS][App] ' . $route . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_fail(500, 'server_error', 'something went wrong on our side; please try again');
}
