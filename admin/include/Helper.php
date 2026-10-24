<?php
/**
 * Created by PhpStorm.
 * User: johnpaulgabule
 * Date: 2/9/2018
 * Time: 9:32 PM
 */
/**
 * Send a Location header AND STOP EXECUTION.
 *
 * The historical version only sent the header, so every caller that forgot a
 * trailing die() kept running: gated pages leaked their full HTML into the
 * redirect response body (a logged-out visitor curl-ing an admin URL saw the
 * page), and POST handlers continued past their redirect into further writes.
 * Terminating here makes every call site safe by construction. Flash messages
 * must therefore be set BEFORE calling this — Session::message() writes to
 * $_SESSION immediately, so message-then-redirect is the house order now.
 */
function redirect_to($location): never {
    header("Location: {$location}");
    exit;
}

function clean($string) {
    /*
     * Null-safe: a missing form field (or any legacy caller that reads
     * $_POST['x'] without a default) reaches here as null, and PHP 8.2
     * deprecates passing null to trim(). Casting first keeps every caller
     * honest without each one needing its own `?? ''`.
     */
    return htmlentities(strip_tags(trim((string) $string)));
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    /*
     * DateInterval is a built-in class and has NO `w` (week) part, so the
     * historical `$diff->w = floor($diff->d / 7)` attached a dynamic property
     * to it. That is deprecated in PHP 8.2 and was firing on every page that
     * prints a relative time (44 hits during one simulation run) — while PHP
     * 8.2 gives no public `w`, so the value is derived into locals instead of
     * being stuffed onto the interval. Same output, no dynamic property.
     */
    $weeks = (int) floor($diff->d / 7);
    $days  = $diff->d - ($weeks * 7);

    $parts = array(
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => $weeks,
        'd' => $days,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    );

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );

    foreach ($string as $k => &$v) {
        if (!empty($parts[$k])) {
            $v = $parts[$k] . ' ' . $v . ($parts[$k] > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    unset($v);

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

/**
 * Compact human age for tooltips, e.g. "5 days ago" / "2 hours ago".
 * Thin wrapper over time_elapsed_string() kept for readable call-sites.
 */
function human_age(string $datetime): string
{
    return time_elapsed_string($datetime);
}

function trim_body($text, $max_length = 30, $tail = '...') {

    $tail_len = strlen($tail);

    if (strlen($text) >  $max_length) {

        $tmp_text = substr($text, 0, $max_length - $tail_len);

        if (substr($text, $max_length - $tail_len, 1) == ' ') {

            $text = $tmp_text;

        } else {

            $pos = strrpos($tmp_text, ' ');

            $text = substr($text, 0, $pos);

        }

        $text = $text . $tail;

    }

    return $text;
}

function formatBytes($size, $precision = 2)
{
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'MB', 'GB', 'TB');   

    return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
}

/**
 * Format an amount as Kenyan Shillings, e.g. KSh 1,500,000.
 * Decimals default to 0 (KSh amounts rarely need cents).
 */
function format_ksh($amount, $decimals = 0)
{
    return 'KSh ' . number_format((float) $amount, $decimals);
}

/**
 * Sanitize one value for a CSV export cell (anti CSV-injection).
 *
 * Spreadsheet apps (Excel, Google Sheets, LibreOffice) may execute cells
 * that begin with = + - @ as formulas — a classic exfiltration vector when
 * exported rows contain user-typed text ("=HYPERLINK(...)", "+cmd|...").
 * Such values are prefixed with a single quote so they render as literal
 * text; control characters (newlines excepted) are stripped.
 */
function csv_cell($value): string
{
    $value = (string) $value;
    // Strip control chars except tab/newline/return (fputcsv handles quoting).
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Human, Kenyan-style date: "Sat 20 Dec 2025" (optionally ", 6:00 PM").
 *
 * Every user-facing date should go through this so raw DB values
 * ("2025-12-20 18:00:00") never reach a couple's screen. Non-parseable
 * input is returned unchanged (callers escape it like any string), and
 * empty input returns an empty string.
 */
function format_date_kd($value, bool $withTime = false): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value; // garbage in, same garbage out — caller escapes it
    }
    return date('D j M Y', $ts) . ($withTime ? ', ' . date('g:i A', $ts) : '');
}

/**
 * The public base URL of the site (no trailing slash).
 *
 * Order: WPMS_SITE_URL env (set in production) -> auto-detect from the
 * request so dev servers on any port keep working.
 */
function site_base_url(): string
{
    $env = getenv('WPMS_SITE_URL');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Absolute URL for a site-relative path — a page, an asset or an upload.
 *
 * Built on site_base_url() with one correction that page links need and API
 * base URLs must not have: with NO WPMS_SITE_URL configured (dev, tests, a
 * fresh XAMPP copy) the install FOLDER is read from SCRIPT_NAME, so a site
 * living in http://localhost/Wedding/ produces
 * http://localhost/Wedding/vendor.php?id=200 rather than a link to the web
 * server's root. A configured WPMS_SITE_URL is the site's own base and is
 * used as given — on cPanel it already carries the folder, and appending it
 * again would double it.
 *
 * Query values are encoded here so callers pass raw ids and get a URL that is
 * safe to put in an attribute (and still escape it when printing).
 */
function public_url(string $path, array $query = []): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = site_base_url();

    if (trim((string) getenv('WPMS_SITE_URL')) === '') {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $dir = rtrim($dir, '/');
        if ($dir !== '' && $dir !== '.') {
            $base .= $dir;
        }
    }

    $url = rtrim($base, '/') . '/' . $path;
    return $query === [] ? $url : $url . '?' . http_build_query($query);
}

/**
 * Normalize a Kenyan phone number to the international form WhatsApp
 * deep links need (2547XXXXXXXX / 2541XXXXXXXX). Returns '' when the
 * input holds no plausible number — callers then hide the button.
 */
function wa_number_ke(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '' || $digits === null) {
        return '';
    }
    if (str_starts_with($digits, '0')) {
        $digits = '254' . substr($digits, 1);      // 0712... -> 254712...
    } elseif (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
        $digits = '254' . $digits;                  // 712.../112... -> 254...
    } elseif (!str_starts_with($digits, '254')) {
        return ''; // foreign or malformed — do not guess
    }
    return (strlen($digits) >= 12 && strlen($digits) <= 15) ? $digits : '';
}

/**
 * Absolute RSVP link for a guest token, e.g.
 *   https://everafterplanner.co.ke/rsvp.php?g=<32-hex>
 */
function rsvp_url_for(?string $token): string
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) $token);
    if ($token === '' || $token === null) {
        return '';
    }
    return site_base_url() . '/rsvp.php?g=' . $token;
}

/**
 * wa.me deep link that opens WhatsApp with a pre-filled invitation for
 * the guest. Returns '' when the guest has no usable number.
 */
function whatsapp_invite_link(?string $phone, string $guestName, string $weddingDate, string $rsvpUrl): string
{
    $num = wa_number_ke($phone);
    if ($num === '') {
        return '';
    }
    $msg = 'Habari ' . $guestName . "! You are warmly invited to our wedding"
        . ($weddingDate !== '' ? ' on ' . $weddingDate : '') . '. '
        . 'Please find the details and confirm your attendance here: ' . $rsvpUrl
        . ' — Karibu! 🎉';
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
}

/**
 * WhatsApp "share via chooser" link — no recipient number, WhatsApp lets the
 * user pick the chat (family group, harambee committee, the planner…).
 * Used for deposit receipts; invite links use whatsapp_invite_link() with an
 * explicit number instead.
 */
/**
 * Shared reminder text for nudging a guest who hasn't replied. Used by the
 * RSVP dashboard's per-guest WhatsApp links, the bulk WhatsApp queue and the
 * bulk SMS sender — one wording everywhere.
 */
function rsvp_reminder_text(string $guestName, string $couple, string $weddingHuman, string $rsvpUrl): string
{
    return 'Habari ' . $guestName . '! Gentle reminder from ' . $couple
        . ($weddingHuman !== '' ? ' — our wedding is on ' . $weddingHuman : '')
        . '. Kindly confirm your attendance here: ' . $rsvpUrl . ' — Asante!';
}

function whatsapp_share_link(string $text): string
{
    return 'https://wa.me/?text=' . rawurlencode($text);
}

/**
 * Human label for a scheduled ceremony/event row: "Church Blessing — Sat 20 Dec 2025".
 *
 * Replaces every "Event #202"-style machine label in ledgers and dropdowns.
 * Falls back to "Unassigned" for orphaned ids (e.g. the event was deleted),
 * so ledgers never render a bare number. Results are cached per request.
 */
function event_label($eventId): string
{
    static $cache = [];
    $id = (int) $eventId;
    if ($id <= 0) {
        return 'Unassigned';
    }
    if (!array_key_exists($id, $cache)) {
        global $db;
        $label = 'Unassigned';
        try {
            $stmt = $db->prepare('SELECT title, start FROM events WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $title = trim((string) $row['title']);
                $label = ($title !== '' ? $title : 'Ceremony') . ' — ' . format_date_kd($row['start']);
            }
        } catch (Throwable $e) {
            $label = 'Unassigned';
        }
        $cache[$id] = $label;
    }
    return $cache[$id];
}

/**
 * Shared empty-state block: icon circle + title + muted text + optional CTA.
 * Used by every list page so empty states look identical across the admin.
 */
function empty_state(string $title, string $text, string $icon = 'mdi-inbox-outline', string $ctaHref = '', string $ctaLabel = '', string $ctaIcon = 'mdi-plus'): void
{
    echo '<div class="empty-state">';
    echo '<div class="empty-icon"><i class="mdi ' . $icon . '"></i></div>';
    echo '<h5>' . htmlspecialchars($title) . '</h5>';
    echo '<p class="text-muted mb-0">' . htmlspecialchars($text) . '</p>';
    if ($ctaHref !== '' && $ctaLabel !== '') {
        echo '<a href="' . htmlspecialchars($ctaHref) . '" class="btn btn-success btn-sm mt-3"><i class="mdi ' . $ctaIcon . ' mr-1"></i> ' . htmlspecialchars($ctaLabel) . '</a>';
    }
    echo '</div>';
}

/**
 * Where the PUBLIC vendor pages (vendors.php, vendor.php) send someone who is
 * ready to enquire.
 *
 * One answer for both pages, so a card and a profile cannot disagree about
 * where "Enquire" goes. An anonymous visitor has one door — login.php — and
 * that page already offers the "join" route; a signed-in account is sent to
 * the portal its role actually works in. Vendors are not customers here: a
 * vendor session browsing another business gets its own bookings ledger, which
 * is the only thing that link could usefully mean for them.
 *
 * @return array{href:string,label:string}
 */
function vendor_enquiry_cta(): array
{
    if (!isset($_SESSION['id']) && !isset($_SESSION['user_email'])) {
        return ['href' => 'login.php', 'label' => 'Sign in to enquire'];
    }
    if (is_staff()) {
        return ['href' => 'admin/dashboard.php', 'label' => 'Enquire from your dashboard'];
    }
    if (is_vendor()) {
        return ['href' => 'admin/vendor_home.php', 'label' => 'Go to your bookings'];
    }
    return ['href' => 'admin/client.php', 'label' => 'Enquire for your wedding'];
}

/**
 * Resolve the vendor listing a session is currently working on, remembering it.
 *
 * The chosen listing is kept in the session because a vendor page is a mix of
 * links and forms: resolving from the query string alone would make every POST
 * fall back to the account's first listing, so a vendor who switched to their
 * second business and saved a package would write it — or try to — against the
 * wrong one. `Vendor::resolve_for_session()` still validates the id against
 * ownership on every request, so a stale or foreign session value can only ever
 * fall back to the caller's own listing.
 *
 * @param object[] $listings every listing the signed-in account owns
 */
function current_vendor_listing(int $user_id, array $listings): object|false|null
{
    $requested = (int) ($_REQUEST['vendor_id'] ?? 0);
    if ($requested > 0) {
        $_SESSION['vendor_listing_id'] = $requested;
    } elseif (!empty($_SESSION['vendor_listing_id'])) {
        $requested = (int) $_SESSION['vendor_listing_id'];
    }
    return Vendor::resolve_for_session($user_id, $requested);
}

/**
 * Listing switcher for vendor accounts that run more than one business.
 *
 * A vendor account can own several `tblvendors` rows — the Kenyan seed ships
 * one owner with two. Every vendor page acts on ONE listing at a time, so
 * without a way to pick it the owner of a second business could not read that
 * business's enquiries, edit its profile or manage its packages: the pages
 * resolved the listing with `Vendor::get_by_user()`, which is `LIMIT 1` with no
 * `ORDER BY`, and silently acted on the first one only.
 *
 * Renders nothing for the ordinary single-listing vendor. The chosen id is
 * validated against ownership by `Vendor::resolve_for_session()` on arrival,
 * so this only ever offers listings the account actually owns.
 *
 * @param object[]             $listings every listing the signed-in account owns
 * @param array<string,scalar> $query    extra query params to carry across
 */
function vendor_listing_switcher(array $listings, int $currentId, string $page, array $query = []): void
{
    if (count($listings) < 2) {
        return;
    }
    echo '<form method="get" action="' . htmlspecialchars($page) . '" class="form-inline mt-2">';
    foreach ($query as $name => $value) {
        echo '<input type="hidden" name="' . htmlspecialchars((string) $name)
            . '" value="' . htmlspecialchars((string) $value) . '">';
    }
    echo '<label class="mr-2 mb-0 text-muted" style="font-size:12px;">Business</label>';
    echo '<select name="vendor_id" class="form-control form-control-sm" onchange="this.form.submit()">';
    foreach ($listings as $listing) {
        $id = (int) $listing->id;
        $label = (string) ($listing->business_name ?? '');
        if ((int) ($listing->is_approved ?? 0) !== 1) {
            $label .= ' (pending approval)';
        }
        echo '<option value="' . $id . '"' . ($id === $currentId ? ' selected' : '') . '>'
            . htmlspecialchars($label) . '</option>';
    }
    echo '</select>';
    echo '<noscript><button type="submit" class="btn btn-sm btn-outline-secondary ml-2">Switch</button></noscript>';
    echo '</form>';
}

/** Empty-state rendered as a full-width table row (keeps DataTables happy). */
function empty_state_row(int $colspan, string $title, string $text, string $icon = 'mdi-inbox-outline', string $ctaHref = '', string $ctaLabel = '', string $ctaIcon = 'mdi-plus'): void
{
    echo '<tr class="empty-state-row"><td colspan="' . $colspan . '" class="p-0">';
    empty_state($title, $text, $icon, $ctaHref, $ctaLabel, $ctaIcon);
    echo '</td></tr>';
}

/**
 * Atomically create a client account + booking from the public sign-up flow.
 *
 * - Hashes the password (PASSWORD_DEFAULT) and stamps access_level '0'
 * - Canonicalizes the wedding date to Y-m-d and rejects invalid input
 * - Runs all three inserts inside one transaction
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => message].
 */
function register_client(
    string $firstname,
    string $lastname,
    string $email,
    string $phone,
    string $password,
    string $weddingDate,
    ?int $weddingType = null
): array {
    global $db;

    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    if (Accounts::email_exists($email)) {
        return ['ok' => false, 'error' => 'That email is already registered.'];
    }

    // Datepicker sends m/d/Y; canonicalize so availability + reports sort correctly.
    $date = DateTime::createFromFormat('m/d/Y', $weddingDate);
    if (!$date) {
        $date = DateTime::createFromFormat('Y-m-d', $weddingDate);
    }
    if (!$date) {
        return ['ok' => false, 'error' => 'Please enter a valid wedding date.'];
    }
    $canonical = $date->format('Y-m-d');

    $check = new Booking();
    if ($check->check_wedding_date($canonical)) {
        return ['ok' => false, 'error' => 'That wedding date is already booked. Please choose another.'];
    }

    $db->execute('START TRANSACTION');
    try {
        $account = new Accounts();
        $account->user_email    = $email;
        $account->user_password = password_hash($password, PASSWORD_DEFAULT);
        $account->access_level  = '0';
        if (!$account->create()) {
            throw new RuntimeException('accounts insert failed');
        }
        $userId = (int) $db->the_insert_id();

        $detail = new Account_Details();
        $detail->user_id          = $userId;
        $detail->firstname        = $firstname;
        $detail->lastname         = $lastname;
        $detail->phone            = $phone;
        $detail->status           = 'pending';
        $detail->datetime_created = date('Y-m-d H:i:s');
        // Explicit defaults for the remaining NOT NULL columns
        $detail->city                = '';
        $detail->description         = '';
        $detail->location            = '';
        $detail->expectation_visitor = '0';
        $detail->cash_advanced       = 0.0;
        $detail->date_signed         = date('Y-m-d H:i:s');
        if (!$detail->create()) {
            throw new RuntimeException('accounts_detail insert failed');
        }

        $booking = new Booking();
        $booking->user_id      = $userId;
        $booking->user_email   = $email;
        $booking->wedding_date = $canonical;
        $booking->wedding_type = (int) ($weddingType ?? 0);
        $booking->bride        = '';
        $booking->groom        = '';
        $booking->organizer_id = 0;
        if (!$booking->create()) {
            throw new RuntimeException('tblweddingbook insert failed');
        }
        $bookingId = (int) $db->the_insert_id();

        $db->execute('COMMIT');
        // The ids are returned so the caller can immediately attach what
        // belongs to this registration — the proposal (v2.30) is created in the
        // same request, from the brief the couple typed on the same form.
        return ['ok' => true, 'error' => '', 'user_id' => $userId, 'booking_id' => $bookingId];
    } catch (Throwable $e) {
        try {
            $db->execute('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        error_log('[WPMS][Register] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Registration could not be completed. Please try again.'];
    }
}

/**
 * Self-serve planner registration: create the staff account UNVERIFIED, in
 * ONE transaction. The trial does NOT start here — it starts when the
 * planner proves the mailbox with the verification token (SignupGuard +
 * verify_planner.php), so trial farming needs one working inbox per account.
 *
 * Guards: name/email/password required, valid email format, password >= 8
 * chars, email unique across BOTH tables (clients and staff share the login
 * door — the same address must never exist twice with two passwords).
 *
 * Password hashing is bcrypt via password_hash() — the account is created
 * self-serve, never through the legacy seed path.
 *
 * @return array{ok:bool, user_id?:int, error?:string}
 */
function register_planner(string $firstname, string $lastname, string $email, string $phone, string $password): array
{
    global $db;
    $firstname = trim($firstname);
    $lastname  = trim($lastname);
    $email     = strtolower(trim($email));
    $phone     = trim($phone);

    if ($firstname === '' || $lastname === '') {
        return ['ok' => false, 'error' => 'Please enter your first and last name.'];
    }
    if (mb_strlen($firstname) > 32 || mb_strlen($lastname) > 32) {
        return ['ok' => false, 'error' => 'Names are limited to 32 characters.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    if (Accounts::email_exists($email) || Users::email_exists($email)) {
        return ['ok' => false, 'error' => 'That email is already registered. Sign in instead, or reset your password.'];
    }

    try {
        $db->execute('START TRANSACTION');

        // Staff row: access_level 1 (planner), bcrypt password, phone saved
        // so the renewal prompt + dunning texts can reach them from day one.
        // email_verified = 0: no trial and no sign-in until the mailbox is
        // proven via the SignupGuard token.
        $stmt = $db->prepare(
            'INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created, phone, email_verified)
             VALUES (:fn, :ln, :g, :un, :pw, :em, :des, :ad, :lvl, :pic, NOW(), :ph, 0)'
        );
        $stmt->execute([
            ':fn'  => $firstname,
            ':ln'  => $lastname,
            ':g'   => 'f',
            ':un'  => 'planner_' . strtolower(bin2hex(random_bytes(4))),
            ':pw'  => password_hash($password, PASSWORD_DEFAULT),
            ':em'  => $email,
            ':des' => 'Wedding Planner',
            ':ad'  => '',
            // access_level is a STRING enum ('0','1','2') — binding an int
            // makes MySQL interpret it as the enum INDEX and store '0'.
            ':lvl' => '1',
            ':pic' => '',
            ':ph'  => substr($phone, 0, 20),
        ]);
        $userId = (int) $db->the_insert_id();

        $db->execute('COMMIT');
        return ['ok' => true, 'user_id' => $userId];
    } catch (Throwable $e) {
        try {
            $db->execute('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        error_log('[WPMS][PlannerRegister] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Registration could not be completed. Please try again.'];
    }
}

/*
 * ---------------------------------------------------------------------------
 * Role predicates — pure $_SESSION reads.
 *
 * These live HERE, not in init.php, because init.php is web-request-only while
 * this file is also loaded by CLI scripts, PHPUnit and composer's files
 * autoload. A predicate left in init.php looks identical from a browser and
 * then fatals with "Call to undefined function is_staff()" the first time a
 * command-line caller (a cron script, a test, `php -r`) touches anything that
 * reads it — staff_paywall_locked() did exactly that. Everything in this file
 * must be usable with nothing but this file loaded; anything that HTTP-
 * redirects (require_staff / require_vendor) stays in init.php.
 * ---------------------------------------------------------------------------
 */

/**
 * Is the current session a staff (planner/admin) account?
 * Client accounts (tblaccounts) are stamped privilege '0'; staff (tblusers)
 * carry a numeric access_level (1 = planner, 2 = super admin) or a legacy
 * designation string.
 */
function is_staff(): bool
{
    if (!isset($_SESSION['id'])) {
        return false;
    }
    $level = $_SESSION['privilege_level'] ?? 'user';
    if ((string) $level === '0') {
        return false;
    }
    if (is_numeric($level)) {
        return (int) $level >= 1;
    }
    return in_array(
        strtolower((string) $level),
        ['super admin', 'admin', 'planner', 'wedding planner', 'staff'],
        true
    );
}

/**
 * Is the current session a vendor account?
 * Vendors are staff rows (tblusers) with access_level 0 — planners/admins
 * are 1/2. Clients (tblaccounts) are ALSO stamped '0', so the account_type
 * flag set at login is the only reliable discriminator: clients are never
 * vendors even though the numeric level matches.
 */
function is_vendor(): bool
{
    if (!isset($_SESSION['id']) || is_staff()) {
        return false;
    }
    if (($_SESSION['account_type'] ?? '') !== 'staff') {
        return false; // client session (tblaccounts) — never a vendor
    }
    $level = $_SESSION['privilege_level'] ?? 'user';
    return is_numeric($level) && (int) $level === 0;
}

/**
 * Is the signed-in session a COUPLE account (a tblaccounts row)?
 *
 * Couples live in tblaccounts (primary key `user_id`); planners, admins and
 * vendors live in tblusers (primary key `id`). Those are two separate
 * auto-increment sequences, so their ids are two number spaces that merely
 * look alike — 200 in tblusers and 200 in tblaccounts are different people.
 *
 * This matters because a VENDOR is a tblusers row with access_level 0, and
 * is_staff() is therefore FALSE for it. Any ownership test written as
 * `!is_staff() && $booking->user_id === $_SESSION['id']` compares a
 * tblaccounts id (the booking's owner) against a tblusers id (the vendor's
 * session) and hands the vendor the bookings of whichever couple happens to
 * hold that number: every couple-facing page, until this predicate existed.
 */
function is_client_account(): bool
{
    if (!isset($_SESSION['id'])) {
        return false;
    }
    // Vendor rows are tblusers rows too (access_level 0 + account_type staff).
    return !is_staff() && !is_vendor();
}

/**
 * The bell feed THIS session owns: which account table its id belongs to, and
 * the id itself.
 *
 * `tblusers.id` (staff and vendor leads) and `tblaccounts.user_id` (couples)
 * are two separate auto-increment sequences, so an id alone does not say which
 * of them it came from — 203 in one table and 203 in the other are different
 * people. `Notification` keys every read and write on the pair, and this is the
 * one place a session resolves to one: `is_client_account()` names the table,
 * and `$_SESSION['id']` is the id either way (login binds it from the account
 * row it authenticated).
 *
 * A signed-out session gets id 0, which no account holds, so the feed is empty
 * rather than somebody's.
 *
 * @return array{0: string, 1: int} [Notification::FOR_USER|FOR_ACCOUNT, id]
 */
function notification_recipient_for_session(): array
{
    $id = (int) ($_SESSION['id'] ?? 0);
    if ($id <= 0) {
        return [Notification::FOR_USER, 0];
    }
    return [is_client_account() ? Notification::FOR_ACCOUNT : Notification::FOR_USER, $id];
}

/*
 * ---------------------------------------------------------------------------
 * Per-planner scoping — who owns a wedding, and what that hides.
 *
 * Until this existed, `organizer_id` was a label: `client.php`,
 * `Booking::getBooking()` and `vendor_enquiries.php` had no organizer
 * predicate, so EVERY planner saw EVERY booking and every enquiry, and the
 * dashboard counted them all. This is the one place that answers the question,
 * so the pages, the write gates and the MCP tools cannot answer it differently.
 *
 * THE RULE, in one sentence: a planner sees the weddings assigned to them
 * (`tblweddingbook.organizer_id`) PLUS the ones nobody has been assigned yet; a
 * super admin sees everything; a couple sees its own booking; a vendor sees
 * none of it.
 *
 * Why unassigned counts as visible: `organizer_id` starts at 0 — admin/client_add.php
 * creates a booking before anyone owns it, and Helper.php's own booking builder
 * sets 0 explicitly. If an unassigned wedding were hidden from every planner, a
 * new couple's booking would be invisible to the whole team until an operator
 * assigned it, and the assignment page (which needs someone to open the booking
 * first) would be reachable only by super admins. "Unassigned is everyone's to
 * pick up" is the only rule that does not create that hole; assigning it is what
 * takes it away from everyone else.
 *
 * The switch: `WPMS_PLANNER_SCOPING=0` turns the whole thing off (a
 * single-planner install, or a staging box where the seed assigns everything to
 * one account and the operator wants the old view back). It defaults ON — the
 * behaviour asked for is the behaviour you get, and the escape hatch is one
 * environment variable rather than a code change.
 * ---------------------------------------------------------------------------
 */

/**
 * Is per-planner scoping on for this install? On unless explicitly switched off.
 *
 * Reads the environment on every call (no static cache): a CLI script that
 * `putenv()`s the switch before including anything must not be able to be told
 * "already read" by a page-shaped value cached earlier in the same process.
 */
function planner_scoping_enabled(): bool
{
    $value = strtolower(trim((string) getenv('WPMS_PLANNER_SCOPING')));
    return !in_array($value, ['0', 'off', 'no', 'false'], true);
}

/**
 * The `tblusers.id` whose weddings this session is limited to — 0 when nothing
 * is limited (scoping off, a super admin, a couple, a vendor, signed out).
 *
 * Super admins are exempt exactly as they are from the paywall: they run the
 * platform, and an operator who cannot open a wedding a planner left half-
 * assigned cannot fix it.
 */
function planner_scope_planner_id(): int
{
    if (!planner_scoping_enabled() || !is_staff() || is_super_admin()) {
        return 0;
    }
    return (int) ($_SESSION['id'] ?? 0);
}

/**
 * May a STAFF session open the wedding owned by this `organizer_id`?
 *
 * This is the rule; `booking_visible_to_session()` is its front door for a
 * resolved row, and `planner_scope_sql()` is the same rule as SQL for the
 * queries that must not FETCH what a page would then refuse. An id <= 0 means
 * unassigned, which is visible to every planner (see the block comment above).
 */
function staff_may_open_organizer(int $organizer_id): bool
{
    $me = planner_scope_planner_id();
    if ($me <= 0) {
        return true; // scoping off, or an exempt session
    }
    $organizer_id = (int) $organizer_id;
    return $organizer_id === $me || $organizer_id <= 0;
}

/**
 * The scoping rule as a bound SQL CONDITION, for list queries.
 *
 * `sql` is a complete, parenthesised condition — never a bare fragment a caller
 * has to splice — and an empty string when nothing is scoped, so a caller can
 * always `if ($scope['sql'] !== '')` and add it to its own WHERE list. It is
 * deliberately the SAME rule as `staff_may_open_organizer()` — "owns it, or
 * nobody owns it yet" — and `PlannerScopingTest` asserts the two agree case by
 * case (own, another planner's, unassigned, NULL, super admin, switch off),
 * because a list that disagrees with the page it links to is exactly how this
 * kind of feature leaks quietly.
 *
 * `$column` is the already-aliased column to test, e.g. `b.organizer_id`; it is
 * never taken from request data, only written into the calling query by hand.
 *
 * @return array{sql:string, params:array<string,int>}
 */
function planner_scope_sql(string $column = 'organizer_id'): array
{
    $me = planner_scope_planner_id();
    if ($me <= 0) {
        return ['sql' => '', 'params' => []];
    }
    return [
        'sql'    => '(' . $column . ' = :planner_scope_me OR ' . $column . ' IS NULL OR ' . $column . ' = 0)',
        'params' => [':planner_scope_me' => $me],
    ];
}

/**
 * May this session open this booking at all? Staff may preview/assist the
 * weddings they are scoped to (a planner: their own, plus the unassigned ones;
 * a super admin: all of them); a couple only the booking their own account row
 * owns; a vendor or any other session, nothing. The booking is passed resolved,
 * and a missing one is never open to anybody (staff included) — a false row
 * would otherwise fall through to the page body and warn on every property.
 *
 * A row that did not carry `organizer_id` reads as 0 (unassigned) and is
 * therefore open, which is the permissive side on purpose: every caller here
 * passes a Booking resolved from `tblweddingbook` (`Booking::find_by_booking_id()`
 * selects `*`, and the field is declared on the model), so a missing
 * `organizer_id` means the row was not shaped like a booking — not that the
 * wedding belongs to somebody who must be kept out.
 */
function booking_visible_to_session(object|false $booking): bool
{
    if (!is_object($booking)) {
        return false;
    }
    if (is_staff()) {
        return staff_may_open_organizer((int) ($booking->organizer_id ?? 0));
    }
    return is_client_account() && (int) $booking->user_id === (int) ($_SESSION['id'] ?? 0);
}

/**
 * The couple-facing page rule, in ONE place and ONE shape: `?booking_id=N`
 * must resolve to a booking this session may open, and `?user_id=N` must be
 * the session's own id (staff may pass any couple's — that is the documented
 * preview/assist path). Every couple page and every couple-scoped endpoint
 * calls this instead of re-deriving the comparison, so the vendor hole above
 * cannot be re-introduced one page at a time.
 */
function may_open_couple_page(object|false $booking, int $user_id): bool
{
    if (!booking_visible_to_session($booking)) {
        return false;
    }
    return is_staff() || $user_id === (int) ($_SESSION['id'] ?? 0);
}

/**
 * May this STAFF session manage a proposal (v2.30)?
 *
 * The same rule as a booking, expressed on the proposal's own owner because a
 * proposal is claimed before the wedding is assigned: a planner works the ones
 * they own plus the ones nobody has claimed (`planner_id = 0`), a super admin
 * everything, and a couple or vendor nothing — pricing a wedding is not theirs
 * to do. `Proposal::queue()` applies exactly this rule in SQL, so the list and
 * the page it links to cannot disagree.
 */
function staff_may_open_proposal(object|false $proposal): bool
{
    if (!is_object($proposal) || !is_staff()) {
        return false;
    }
    $me = planner_scope_planner_id();
    if ($me <= 0) {
        return true; // scoping off, or a super admin
    }
    $owner = (int) ($proposal->planner_id ?? 0);
    return $owner === $me || $owner <= 0;
}

/**
 * Is this session the account that owns this vendor listing?
 *
 * Vendors are staff rows (tblusers, access_level 0) and `tblvendors.user_id`
 * holds a tblusers id, so — unlike the couple rule above — both sides of this
 * comparison come from the SAME table and there is no cross-table id confusion
 * to defend against. What it defends is the two ways it can still go wrong: a
 * session that is not a vendor at all (planner, admin, couple) working on a
 * listing, and a listing that did not resolve (`false`) being treated as
 * harmless. Staff get no bypass on purpose: they manage vendors through
 * vendor_enquiries.php and vendors.php, while the self-service pages are the
 * vendor's own.
 *
 * Resolution already validates ownership (Vendor::resolve_for_session), so this
 * is the predicate every vendor-facing page can be held to — one rule, one
 * shape, instead of each page testing for null and calling that a guard.
 */
function vendor_listing_visible_to_session(object|false $listing): bool
{
    if (!is_object($listing)) {
        return false; // nothing resolved is never "harmless"
    }
    if (!is_vendor()) {
        return false; // planners, admins and couples are not the owner
    }
    return (int) $listing->user_id === (int) ($_SESSION['id'] ?? 0);
}

/**
 * Turn a set of ids into a BOUND `IN (...)` fragment — the shared builder for a
 * scoped id list.
 *
 * Vendor pages scope their queries to every listing the account owns, which
 * means an `IN` list per page; Guest::mark_invited() needs the same thing for a
 * set of guests. Inlining those ids (the old vendor_enquiries.php did
 * `implode(',', ...)`) is safe only for as long as every id was cast first, so
 * the ids are bound instead. Returns the ids (de-duped, positive only), the
 * placeholder fragment to interpolate, and the parameters to execute with.
 *
 * An empty list yields an empty fragment, so callers must skip the query in that
 * case (as vendor_enquiries.php does) rather than emit `IN ()`.
 *
 * (The RSVP bulk pages still build positional `?` lists inline with
 * array_fill(): their statements mix positional and named parameters, which PDO
 * refuses to do in one statement.)
 *
 * @param int[] $ids
 * @return array{ids: int[], sql: string, params: array<string, int>}
 */
function bound_id_list(array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[$id] = true; // int keys de-dupe for free
        }
    }
    $clean = array_map('intval', array_keys($clean));

    $holders = [];
    $params  = [];
    foreach ($clean as $i => $id) {
        $key       = ':own' . $i;
        $holders[] = $key;
        $params[$key] = $id;
    }
    return ['ids' => $clean, 'sql' => implode(', ', $holders), 'params' => $params];
}

/** The session user's trial row, or null (convenience for banners/pages). */
function trial_for_session(): ?object
{
    if (!isset($_SESSION['id']) || !is_staff()) {
        return null;
    }
    return Subscription::get_trial((int) $_SESSION['id']);
}

/**
 * Is the current session the SaaS operator?
 *
 * Super admins (tblusers.access_level 2) own the platform, so subscription
 * rules never apply to them: an operator whose own plan lapsed must still
 * be able to reach every page, or nobody is left who can renew anyone's plan.
 * Used by the paywall gate and by login routing, which must agree — otherwise
 * a super admin gets greeted by the paywall on every sign-in.
 *
 * Lives here (not init.php) because init.php is web-request-only while this
 * file is also loaded by CLI scripts, tests and composer's files-autoload;
 * a gate defined here would otherwise fatal with "undefined function".
 */
function is_super_admin(): bool
{
    return isset($_SESSION['id']) && (int) ($_SESSION['privilege_level'] ?? 0) === 2;
}

/**
 * Where does this staff member stand on their subscription right now?
 * ONE answer for the login redirect, the gate and every banner:
 *   'active' — an active row exists
 *   'grace'  — lapsed within the grace window (or an unpaid renewal promise
 *              exists): READ-ONLY banners, no hard lockout yet
 *   'locked' — no subscription / grace expired → hard redirect
 *
 * The gate (require_staff_subscribed / login_redirect_target) and every
 * banner call this so the dashboard, subscription page and redirect can
 * never disagree about the state. Lives in Helper.php (loaded by init.php
 * AND composer's files autoload) so CLI scripts and tests get it too.
 */
function subscription_state_for_session(): string
{
    $s = Subscription::state((int) ($_SESSION['id'] ?? 0));
    return $s['state'];
}

/** True when the session user's plan is lapsed but still inside the grace window. */
function subscription_in_grace(): bool
{
    return subscription_state_for_session() === 'grace';
}

/**
 * Staff gate WITH the grace window: a lapsed staff member inside grace is
 * let through (pages render read-only banners); past grace or never paid,
 * they are diverted to the subscription page as before. Skipped on the
 * subscription page itself (and the STK status page) so a 'grace' user can
 * always reach the payment screen.
 */
function require_staff_subscribed(): void
{
    if (!is_staff()) {
        redirect_to('../');
        exit;
    }

    /*
     * Super admins (access_level 2) are the SaaS operator and are never locked
     * out of their own back office by an expired plan — see is_super_admin()
     * in init.php, which login routing uses as well so the two never disagree.
     */
    if (is_super_admin()) {
        return;
    }

    $page = basename($_SERVER['PHP_SELF'] ?? '');
    if ($page === 'subscription.php' || $page === 'stk_status.php') {
        return; // always reachable — this is where a lapsed user pays
    }
    if (staff_paywall_locked()) {
        redirect_to('subscription.php');
        exit;
    }
}

/**
 * The paywall's single decision: is this a STAFF session to hold back?
 *
 * Lives here so the full-page gate and the pages couples share have one
 * source of truth. The shared ones — client.php, the client_* previews, the
 * printable sheets — let a staff session open ANY couple's booking, so a
 * lapsed planner could otherwise read the whole CRM by deep-linking around
 * the gate. Couples and vendors are never affected: their sessions are not
 * staff, and neither has ever been billed a subscription.
 */
function staff_paywall_locked(): bool
{
    return is_staff() && !is_super_admin() && subscription_state_for_session() === 'locked';
}

/** Render the grace/urgency banner (call once per staff page, after the header include). */
function subscription_banner(): string
{
    if (!is_staff()) {
        return '';
    }
    $s = Subscription::state((int) ($_SESSION['id'] ?? 0));
    if ($s['state'] === 'active') {
        if (!empty($s['trial'])) {
            // Trial urgency beats the paid-plan ladder. Nothing shows for the
            // bulk of a six-month trial — this is the end-of-window nudge only
            // (the halfway check-in is an email, not a banner; see
            // Subscription::trial_nurture_targets).
            $left = $s['days_left'];
            if ($left > 4) {
                return '';
            }
            $msg = $left === 0
                ? 'Your free trial ends TODAY — activate a plan to keep full access. '
                : 'Your free trial ends ' . ($left === 1 ? 'TOMORROW' : 'in ' . $left . ' days') . ' — activate a plan to keep full access. ';
            return '<div class="alert ' . ($left <= 1 ? 'alert-danger' : 'alert-warning') . ' text-center mb-0" role="alert">'
                . htmlspecialchars($msg)
                . '<a href="subscription.php">Choose your plan</a></div>';
        }
        $left = $s['days_left'];
        if ($left > 7) {
            return '';
        }
        $msg = $left > 3
            ? 'Your subscription expires in ' . $left . ' days. '
            : 'Your subscription expires ' . ($left === 1 ? 'tomorrow' : 'in ' . $left . ' days') . '. ';
        $msg .= $s['sub'] !== null && (int) ($s['sub']->auto_renew ?? 1) === 1
            ? 'Auto-renewal is ON — keep your M-Pesa phone reachable.'
            : 'Auto-renewal is OFF — renew manually to avoid losing access.';
        $cls = $left <= 3 ? 'alert-warning' : 'alert-info';
        return '<div class="alert ' . $cls . ' alert-dismissible fade show text-center mb-0" role="alert">'
            . htmlspecialchars($msg)
            . ' <a href="subscription.php">Manage</a>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>';
    }
    if ($s['state'] === 'grace') {
        return '<div class="alert alert-danger mb-0 text-center" role="alert">'
            . '<strong>Your subscription has lapsed.</strong> You have '
            . max(0, $s['grace_days_left']) . ' day(s) of read-only grace left — pay before then to keep full access. '
            . '<a href="subscription.php">Renew now via M-Pesa</a></div>';
    }
    return '';
}
