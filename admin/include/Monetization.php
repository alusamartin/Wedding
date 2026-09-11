<?php
declare(strict_types=1);

class Subscription extends DB_Object
{
    protected static $db_table = "tblsubscriptions";
    protected static $db_table_fields = array(
        'id',
        'user_id',
        'plan',
        'amount',
        'mpesa_code',
        'start_date',
        'expiry_date',
        'status',
        'created_at'
    );

    public $id;
    public $user_id;
    public $plan;
    public $amount;
    public $mpesa_code;
    public $start_date;
    public $expiry_date;
    public $status;
    public $created_at;

    public static function get_active(mixed $user_id): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE user_id = :uid AND status = 'active' AND expiry_date >= CURDATE() ORDER BY expiry_date DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function get_all_for_user(mixed $user_id): array
    {
        global $db;
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE user_id = :uid ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function is_active(mixed $user_id): bool
    {
        return self::get_active($user_id) !== null;
    }

    public static function create_subscription(mixed $user_id, string $plan, mixed $amount, string $mpesa_code): bool
    {
        global $db;
        $start  = date('Y-m-d');
        $expiry = date('Y-m-d', strtotime('+30 days'));
        $sql = "INSERT INTO tblsubscriptions (user_id, plan, amount, mpesa_code, start_date, expiry_date, status) VALUES (:uid, :plan, :amount, :code, :start, :expiry, 'active')";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            ':uid'    => (int) $user_id,
            ':plan'   => $plan,
            ':amount' => $amount,
            ':code'   => $mpesa_code,
            ':start'  => $start,
            ':expiry' => $expiry,
        ]);
    }

    public static function get_plan(string $plan_slug): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblsubscription_plans WHERE plan_slug = :slug LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':slug' => $plan_slug]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function get_all_plans(): array
    {
        global $db;
        $sql = "SELECT * FROM tblsubscription_plans WHERE is_active = 1 ORDER BY price ASC";
        // No user input — safe direct query
        return $db->fetchAll($sql);
    }
}

/**
 * M-Pesa C2B verification (Daraja callback verification)
 *
 * When a customer pays via Paybill / Buy Goods, Safaricom pushes a validation
 * & confirmation notification to the endpoint registered on the Daraja portal
 * (mpesa_c2b.php in this project). Those payloads are stored in
 * tblmpesa_callbacks; the 10-char TransID is exactly the code users type into
 * subscription.php and budget_add.php.
 *
 * Verification gates a code by checking its callbacks:
 *   • amount tolerance  — the reported amount must match the expected within
 *                         WPMS_MPESA_TOLERANCE (default 1%, floor KSh 1)
 *   • account reference — when the expected ref is known (booking #, WPMS),
 *                         the bill_ref_number must contain it; empty DB refs
 *                         pass (payers often leave the reference blank)
 *   • duplicate use     — a callback already verified for another row is
 *                         refused (one payment cannot activate two things)
 *
 * Rejected payloads are KEPT with verified_at = NULL + a reject_reason so an
 * admin can see what was refused. The ledger is append-only (UNIQUE(trans_type,
 * trans_id)) — replays of the same Daraja payload are no-ops.
 */
class MpesaC2B
{
    /** Current Daraja C2B validation/confirmation schema. */
    private const ALLOWED_KEYS = [
        'TransactionType', 'TransID', 'TransTime', 'TransAmount', 'BusinessShortCode',
        'BillRefNumber', 'InvoiceNumber', 'OrgAccountBalance', 'ThirdPartyTransactionID',
        'MSISDN', 'FirstName', 'MiddleName', 'LastName',
    ];

    /** @return array{enabled:bool, tolerance:float} */
    public static function config(): array
    {
        return [
            'enabled'   => self::verification_enabled(),
            'tolerance' => self::tolerance(),
        ];
    }

    public static function verification_enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('WPMS_MPESA_VERIFY'))), ['1', 'true', 'yes', 'on'], true);
    }

    /** Amount tolerance fraction: env override, default 1% (KSh 1 absolute floor). */
    public static function tolerance(): float
    {
        $t = filter_var(getenv('WPMS_MPESA_TOLERANCE'), FILTER_VALIDATE_FLOAT);
        if ($t === false || $t < 0 || $t > 1) {
            return 0.01;
        }
        return $t;
    }

    /**
     * Validate + ingest a Daraja callback payload (from mpesa_c2b.php).
     * Append-only: unique(trans_type, trans_id) makes replays no-ops.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function ingest(array $payload): array
    {
        global $db;

        if (!self::keys_allowed($payload)) {
            return ['ok' => false, 'error' => 'Unknown payload keys — not a Daraja C2B notification'];
        }
        $transId   = strtoupper(trim((string) ($payload['TransID'] ?? '')));
        $time      = trim((string) ($payload['TransTime'] ?? ''));
        $amount    = filter_var($payload['TransAmount'] ?? null, FILTER_VALIDATE_FLOAT);
        $shortcode = trim((string) ($payload['BusinessShortCode'] ?? ''));
        $msisdn    = trim((string) ($payload['MSISDN'] ?? ''));
        if ($transId === '' || $time === '' || $amount === false || $amount <= 0 || $shortcode === '' || $msisdn === '') {
            return ['ok' => false, 'error' => 'Missing or invalid required fields (TransID, TransTime, TransAmount, BusinessShortCode, MSISDN)'];
        }
        if (!preg_match('/^[A-Z0-9]{6,20}$/', $transId)) {
            return ['ok' => false, 'error' => 'Malformed TransID'];
        }
        if (!preg_match('/^\d{14}$/', $time)) { // Daraja format YYYYMMDDHHmmss
            return ['ok' => false, 'error' => 'Malformed TransTime (expected YYYYMMDDHHmmss)'];
        }
        if (!preg_match('/^\d{6,15}$/', $msisdn)) {
            return ['ok' => false, 'error' => 'Malformed MSISDN'];
        }

        $stmt = $db->prepare(
            'INSERT INTO tblmpesa_callbacks
                (trans_type, trans_id, trans_time, amount, business_short_code, bill_ref_number,
                 msisdn, first_name, middle_name, last_name, org_account_balance, invoice_number)
             VALUES
                (:ttype, :tid, :ttime, :amount, :shortcode, :billref, :msisdn, :fname, :mname, :lname, :balance, :invoice)'
        );
        try {
            $stmt->execute([
                ':ttype'     => (($payload['TransactionType'] ?? '') === 'Buy Goods') ? 'Buy Goods' : 'Pay Bill',
                ':tid'       => $transId,
                ':ttime'     => $time,
                ':amount'    => $amount,
                ':shortcode' => substr($shortcode, 0, 12),
                ':billref'   => substr(trim((string) ($payload['BillRefNumber'] ?? '')), 0, 100) ?: null,
                ':msisdn'    => $msisdn,
                ':fname'     => substr(trim((string) ($payload['FirstName'] ?? '')), 0, 100) ?: null,
                ':mname'     => substr(trim((string) ($payload['MiddleName'] ?? '')), 0, 100) ?: null,
                ':lname'     => substr(trim((string) ($payload['LastName'] ?? '')), 0, 100) ?: null,
                ':balance'   => substr(trim((string) ($payload['OrgAccountBalance'] ?? '')), 0, 20) ?: null,
                ':invoice'   => substr(trim((string) ($payload['InvoiceNumber'] ?? '')), 0, 20) ?: null,
            ]);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['ok' => true, 'id' => 0]; // idempotent replay
            }
            error_log('[WPMS][MpesaC2B] ingest failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Storage failure'];
        }
        return ['ok' => true, 'id' => (int) $db->the_insert_id()];
    }

    /**
     * Verify a user-typed code against stored Daraja callbacks.
     *
     * @param string $code       the 10-char M-Pesa transaction code
     * @param float  $amount     expected amount (KSh)
     * @param string $accountRef expected account reference ('' = accept any non-empty ref)
     * @return array{verified:bool, reason:string, callback_id:?int, amount:float|null}
     */
    public static function verify(string $code, float $amount, string $accountRef = ''): array
    {
        global $db;
        $code = strtoupper(trim($code));

        $rows = $db->fetchAll(
            'SELECT * FROM tblmpesa_callbacks WHERE trans_id = :c ORDER BY id ASC',
            [':c' => $code]
        );
        if ($rows === []) {
            return ['verified' => false, 'reason' => 'No matching M-Pesa transaction was received for this code', 'callback_id' => null, 'amount' => null];
        }

        foreach ($rows as $row) {
            // Skip callbacks already consumed by another record — one payment
            // cannot activate two things. (Multiple callbacks per code are
            // possible: validation + confirmation replays.)
            if ($row->verified_at !== null) {
                continue;
            }
            if ((float) $row->amount < $amount * (1 - self::tolerance()) - 0.005) {
                continue; // underpaid beyond tolerance
            }
            if ((float) $row->amount > $amount * (1 + self::tolerance()) + 0.005) {
                continue; // overpaid beyond tolerance (probably a different purchase)
            }
            $ref  = strtoupper(trim((string) $row->bill_ref_number));
            $want = strtoupper(trim($accountRef));
            if ($want !== '' && $ref !== '' && strpos($ref, $want) === false) {
                continue; // wrong account reference
            }
            return ['verified' => true, 'reason' => 'Matched M-Pesa payment of ' . format_ksh((float) $row->amount, 2), 'callback_id' => (int) $row->id, 'amount' => (float) $row->amount];
        }
        return ['verified' => false, 'reason' => 'M-Pesa payment found but amount or account reference does not match', 'callback_id' => null, 'amount' => null];
    }

    /**
     * Mark a callback as consumed by a subscription / payment record.
     */
    public static function mark_consumed(int $callback_id, string $ref_table, int $ref_id): bool
    {
        global $db;
        $stmt = $db->prepare(
            'UPDATE tblmpesa_callbacks SET verified_at = NOW(), ref_table = :t, ref_id = :r WHERE id = :id AND verified_at IS NULL'
        );
        return $stmt->execute([':t' => $ref_table, ':r' => $ref_id, ':id' => $callback_id]);
    }

    /**
     * Record a failed verification attempt (kept for the admin review queue).
     */
    public static function record_reject(string $code, string $reason, float $amount): void
    {
        global $db;
        $stmt = $db->prepare(
            'INSERT INTO tblmpesa_callbacks (trans_type, trans_id, trans_time, amount, business_short_code, msisdn, reject_reason)
             VALUES (:ttype, :tid, :ttime, :amount, :shortcode, :msisdn, :reason)'
        );
        try {
            $stmt->execute([
                ':ttype'     => 'Pay Bill',
                ':tid'       => strtoupper(trim($code)),
                ':ttime'     => date('YmdHis'),
                ':amount'    => $amount,
                ':shortcode' => substr((string) (getenv('WPMS_MPESA_SHORTCODE') ?: '000000'), 0, 12),
                ':msisdn'    => '0',
                ':reason'    => substr($reason, 0, 255),
            ]);
        } catch (PDOException $e) {
            // A duplicate reject is fine — the admin queue shows the first reason.
        }
    }

    /**
     * Pending / rejected rows for the admin review queue (admin/mpesa_ledger.php).
     */
    public static function pending_review(int $limit = 100): array
    {
        global $db;
        return $db->fetchAll(
            'SELECT * FROM tblmpesa_callbacks WHERE verified_at IS NULL ORDER BY created_at DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /**
     * @return array{verified:int, pending:int, rejected:int, total_ksh:float}
     */
    public static function stats(): array
    {
        global $db;
        $row = $db->fetchOne(
            "SELECT
                SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN verified_at IS NULL AND reject_reason IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN verified_at IS NULL AND reject_reason IS NOT NULL THEN 1 ELSE 0 END) AS rejected,
                COALESCE(SUM(CASE WHEN verified_at IS NOT NULL THEN amount ELSE 0 END), 0) AS total_ksh
               FROM tblmpesa_callbacks"
        );
        return [
            'verified'  => (int) ($row->verified ?? 0),
            'pending'   => (int) ($row->pending ?? 0),
            'rejected'  => (int) ($row->rejected ?? 0),
            'total_ksh' => (float) ($row->total_ksh ?? 0),
        ];
    }

    /** Daraja payload key allow-list — rejects junk/forged POSTs to the callback. */
    private static function keys_allowed(array $payload): bool
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                return false;
            }
        }
        return true;
    }
}

/**
 * Vendor class for marketplace — PDO edition
 */
class Vendor extends DB_Object
{
    protected static $db_table = "tblvendors";
    protected static $db_table_fields = array(
        'id', 'user_id', 'business_name', 'category', 'description',
        'phone', 'email', 'location', 'county', 'logo', 'is_premium',
        'is_approved', 'rating', 'total_bookings', 'created_at'
    );

    public $id;
    public $user_id;
    public $business_name;
    public $category;
    public $description;
    public $phone;
    public $email;
    public $location;
    public $county;
    public $logo;
    public $is_premium;
    public $is_approved;
    public $rating;
    public $total_bookings;
    public $created_at;

    public static function get_approved(?string $category = null): array
    {
        global $db;
        if ($category !== null && $category !== '') {
            $sql  = "SELECT * FROM tblvendors WHERE is_approved = 1 AND category = :cat ORDER BY is_premium DESC, rating DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([':cat' => $category]);
        } else {
            $sql  = "SELECT * FROM tblvendors WHERE is_approved = 1 ORDER BY is_premium DESC, rating DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function get_by_user(mixed $user_id): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblvendors WHERE user_id = :uid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /** Active packages of one vendor, cheapest first. (Prepared for the
     *  couple-facing vendor marketplace — not yet wired to a page.) */
    public static function packages_for(int $vendor_id): array
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT * FROM tblvendor_packages WHERE vendor_id = :vid AND is_active = 1 ORDER BY price ASC'
        );
        $stmt->execute([':vid' => $vendor_id]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** One vendor's active packages keyed by id — for validating an enquiry. */
    public static function package_of(int $vendor_id, int $package_id): ?object
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT * FROM tblvendor_packages WHERE id = :pid AND vendor_id = :vid AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':pid' => $package_id, ':vid' => $vendor_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /**
     * Record a couple's vendor enquiry. The vendor's booking row is created
     * pending so staff see the enquiry on the vendor ledger; returns the
     * tblvendor_bookings id or 0 on failure. $booking_id ties the enquiry to
     * the couple's wedding (0 = general enquiry with no wedding attached).
     */
    public static function record_enquiry(
        int $vendor_id,
        int $booking_id,
        int $package_id,
        string $event_date,
        string $message
    ): int {
        global $db;
        $stmt = $db->prepare(
            "INSERT INTO tblvendor_bookings (vendor_id, booking_id, package_id, event_date, amount, commission, status, notes)
             VALUES (:vid, :bid, :pid, :edate, 0, 0, 'pending', :notes)"
        );
        $ok = $stmt->execute([
            ':vid'   => $vendor_id,
            ':bid'   => $booking_id,
            ':pid'   => $package_id > 0 ? $package_id : null,
            ':edate' => $event_date !== '' ? $event_date : null,
            ':notes' => mb_substr($message, 0, 1000),
        ]);
        return $ok ? (int) $db->the_insert_id() : 0;
    }
}

/**
 * SMS helper — PDO + Africa's Talking
 */
class SMS
{
    public static function send(string $phone, string $message, int $user_id = 0, int $booking_id = 0): array
    {
        global $db;

        $credits = self::get_credits($user_id);
        $cost    = self::calculate_cost($message);

        if ($credits < $cost) {
            return ['success' => false, 'error' => 'Insufficient SMS credits. Please purchase more.'];
        }

        $phone = self::format_phone($phone);

        $sql  = "INSERT INTO tblsms_logs (user_id, booking_id, phone, message, cost, status) VALUES (:uid, :bid, :phone, :msg, :cost, 'pending')";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':uid'   => $user_id,
            ':bid'   => $booking_id,
            ':phone' => $phone,
            ':msg'   => $message,
            ':cost'  => $cost,
        ]);
        $sms_id = (int) $db->the_insert_id();

        $response = self::africastalking_send($phone, $message);

        if ($response['success']) {
            $db->execute("UPDATE tblsms_logs SET status = 'sent', sent_at = NOW() WHERE id = :id", [':id' => $sms_id]);
            self::deduct_credits($user_id, $cost);
        } else {
            $db->execute("UPDATE tblsms_logs SET status = 'failed' WHERE id = :id", [':id' => $sms_id]);
        }

        return $response;
    }

    /**
     * Best-effort courtesy SMS for big moments (booking confirmed, vendor
     * confirmed): the couple should hear the news even when logged out, but a
     * missing number, empty planner credits or a gateway failure must never
     * block or fail the request — the in-app bell notification is the
     * reliable channel and is sent by the caller regardless.
     *
     * Charges the given staff user's credits (the couple never pays to
     * receive good news).
     *
     * @return bool true when the SMS actually reported sent
     */
    public static function send_courtesy(string $phone, string $message, int $chargedUserId, int $bookingId): bool
    {
        $phone = trim($phone);
        if ($phone === '' || $chargedUserId <= 0) {
            return false;
        }
        try {
            $r = self::send($phone, $message, $chargedUserId, $bookingId);
            return !empty($r['success']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function send_bulk(mixed $booking_id, string $message, int $user_id): array
    {
        global $db;
        $sql  = "SELECT phone FROM tblguest WHERE booking_id = :bid AND phone IS NOT NULL AND phone != ''";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $response = self::send($row->phone, $message, $user_id, (int) $booking_id);
            if ($response['success']) {
                $sent++;
            } else {
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function calculate_cost(string $message): float
    {
        $length = mb_strlen($message, 'UTF-8');
        if ($length <= 160) return 1.00;
        return ceil($length / 160) * 1.00;
    }

    /**
     * Normalize KE phone to 2547XXXXXXXX (Africa's Talking E.164 without +).
     * Handles: 07XX, 01XX (new KE prefix), +254, 254, 7XX, 1XX, spaces/dashes.
     */
    public static function format_phone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';
        // Already 254...
        if (str_starts_with($phone, '254')) {
            return $phone;
        }
        // 0 leading domestic
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }
        // Bare 7xx or 1xx without leading 0
        if (preg_match('/^[71]\d{8}$/', $phone)) {
            return '254' . $phone;
        }
        return $phone;
    }

    public static function get_credits(mixed $user_id): int
    {
        global $db;
        $sql  = "SELECT credits FROM tblsms_credits WHERE user_id = :uid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => (int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ? (int) $row->credits : 0;
    }

    public static function add_credits(mixed $user_id, int $credits): bool
    {
        global $db;
        $sql = "INSERT INTO tblsms_credits (user_id, credits, total_purchased) VALUES (:uid, :c, :c2)
                ON DUPLICATE KEY UPDATE credits = credits + VALUES(credits), total_purchased = total_purchased + VALUES(total_purchased)";
        $stmt = $db->prepare($sql);
        return $stmt->execute([':uid' => (int) $user_id, ':c' => $credits, ':c2' => $credits]);
    }

    public static function deduct_credits(mixed $user_id, mixed $credits): bool
    {
        global $db;
        $sql = "UPDATE tblsms_credits SET credits = GREATEST(0, credits - :c), total_used = total_used + :c2 WHERE user_id = :uid";
        $stmt = $db->prepare($sql);
        $c = (int) $credits;
        return $stmt->execute([':c' => $c, ':c2' => $c, ':uid' => (int) $user_id]);
    }

    public static function africastalking_send(string $phone, string $message): array
    {
        $username = 'wpms';
        $api_key  = '';

        if (empty($api_key)) {
            return ['success' => true, 'message' => 'Dev mode: SMS logged but not sent'];
        }

        $ch = curl_init('https://api.africastalking.com/version1/message');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . $api_key,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $username,
                'to'       => $phone,
                'message'  => $message
            ])
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 201) {
            return ['success' => true, 'response' => json_decode((string) $response, true)];
        }
        return ['success' => false, 'error' => $response];
    }
}

/**
 * Wedding Website — PDO edition
 */
class WeddingWebsite extends DB_Object
{
    protected static $db_table = "tblwedding_websites";
    protected static $db_table_fields = array(
        'id', 'booking_id', 'slug', 'title', 'story', 'venue',
        'venue_address', 'wedding_date', 'cover_image', 'is_published', 'views', 'created_at'
    );

    public $id;
    public $booking_id;
    public $slug;
    public $title;
    public $story;
    public $venue;
    public $venue_address;
    public $wedding_date;
    public $cover_image;
    public $is_published;
    public $views;
    public $created_at;

    public static function get_by_slug(string $slug): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblwedding_websites WHERE slug = :slug AND is_published = 1 LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function get_by_booking(mixed $booking_id): object|false|null
    {
        global $db;
        $sql  = "SELECT * FROM tblwedding_websites WHERE booking_id = :bid LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public static function generate_slug(string $bride, string $groom): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $bride . '-' . $groom));
        $base = trim($base, '-');
        $slug = $base;
        $counter = 1;
        while (self::get_by_slug($slug) !== null) {
            $slug = $base . '-' . $counter;
            $counter++;
        }
        return $slug;
    }

    public static function increment_views(mixed $id): bool
    {
        global $db;
        $sql  = "UPDATE tblwedding_websites SET views = views + 1 WHERE id = :id";
        $stmt = $db->prepare($sql);
        return $stmt->execute([':id' => (int) $id]);
    }
}
