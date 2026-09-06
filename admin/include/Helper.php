<?php
/**
 * Created by PhpStorm.
 * User: johnpaulgabule
 * Date: 2/9/2018
 * Time: 9:32 PM
 */
function redirect_to($location) {
    header("Location: {$location}");
}

function clean($string) {
    return htmlentities(strip_tags(trim($string)));
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

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
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
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

        $db->execute('COMMIT');
        return ['ok' => true];
    } catch (Throwable $e) {
        try {
            $db->execute('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        error_log('[WPMS][Register] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Registration could not be completed. Please try again.'];
    }
}
