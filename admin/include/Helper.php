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
