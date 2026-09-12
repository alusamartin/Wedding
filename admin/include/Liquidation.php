<?php
declare(strict_types=1);

class Liquidation extends DB_Object
{
    protected static $db_table = "tbl_liquidation";
    protected static $db_table_fields = array(
        'id',
        'event_id',
        'booking_id',
        'user_id',
        'payment',
        'cash',
        'credit',
        'mpesa_code',
        'date_modified',
        'date_issue'
    );

    public $id;
    public $event_id;
    public $booking_id;
    public $user_id;
    public $payment;
    public $cash;
    public $credit;
    public $mpesa_code;
    public $date_issue;
    public $date_modified;
    public $title;

    public static function find_by_liquadate_all(mixed $id): array
    {
        global $db;
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE booking_id = :id ORDER BY id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function getTotalAmount(mixed $id): float
    {
        global $db;
        $sql  = "SELECT SUM(payment) FROM " . static::$db_table . " WHERE booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    public static function getTotalAmountCash(mixed $id): float
    {
        global $db;
        $sql  = "SELECT SUM(cash) FROM " . static::$db_table . " WHERE booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    public static function getTotalAmountCredit(mixed $id): float
    {
        global $db;
        $sql  = "SELECT SUM(credit) FROM " . static::$db_table . " WHERE booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    public static function getTotalAmountMpesa(mixed $id): float
    {
        global $db;
        $sql  = "SELECT SUM(payment) FROM " . static::$db_table . " WHERE booking_id = :id AND mpesa_code IS NOT NULL AND mpesa_code != ''";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * Returns breakdown: mpesa_total, cash_total, credit_total, grand_total (KSh).
     */
    public static function getTotalsByMethod(mixed $id): array
    {
        global $db;
        $sql = "SELECT
                    SUM(CASE WHEN mpesa_code IS NOT NULL AND mpesa_code != '' THEN payment ELSE 0 END) as mpesa_total,
                    SUM(CASE WHEN cash > 0 AND (mpesa_code IS NULL OR mpesa_code = '') THEN cash ELSE 0 END) as cash_total,
                    SUM(CASE WHEN credit > 0 THEN credit ELSE 0 END) as credit_total,
                    SUM(payment) as grand_total
                FROM " . static::$db_table . " WHERE booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['mpesa_total' => 0, 'cash_total' => 0, 'credit_total' => 0, 'grand_total' => 0];
        }
        return $row;
    }

    /**
     * Generate a realistic 10-char M-Pesa transaction reference (e.g. QDL7K2M5N4)
     * matching the /^[A-Z0-9]{10}$/ format the staff flow validates and the
     * uq_mpesa_code unique key. Fictional — never a real Safaricom reference.
     * Alphabet skips 0/O/1/I to avoid human transcription ambiguity.
     */
    public static function generate_mpesa_reference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix   = '';
        for ($i = 0; $i < 7; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'QDL' . $suffix; // 3 + 7 = 10 chars
    }

    /**
     * Record a couple's self-serve M-Pesa deposit into the liquidation ledger.
     *
     * - Generates a unique 10-char STK-style reference (retries on collision)
     * - Cash-neutral: the row stores payment > 0 with cash = 0 / credit = 0,
     *   the M-Pesa method is derived from the non-empty mpesa_code
     * - Verifies the chosen event actually belongs to the booking so the row
     *   stays visible in the staff join reports (getLiquidation_by_id)
     *
     * @return array{ok: bool, mpesa_code?: string, payment?: float, error?: string}
     */
    public static function create_mpesa_deposit(
        int $bookingId,
        int $userId,
        int $eventId,
        float $amount
    ): array {
        global $db;

        if ($amount < 50 || $amount > 150000) {
            return ['ok' => false, 'error' => 'Deposit must be between KSh 50 and KSh 150,000.'];
        }

        // The event must belong to this booking (keeps staff reconciliation true).
        $own = $db->prepare('SELECT id FROM events WHERE id = :id AND booking_id = :bid LIMIT 1');
        $own->execute([':id' => $eventId, ':bid' => $bookingId]);
        if ($eventId <= 0 || $own->fetch() === false) {
            return ['ok' => false, 'error' => 'Selected event does not belong to this booking.'];
        }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $code = self::generate_mpesa_reference();
            try {
                // Two distinct placeholders for the same timestamp — a repeated
                // :now is rejected by native prepares (HY093).
                $now = date('Y-m-d H:i:s');
                $stmt = $db->prepare(
                    'INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified)
                     VALUES (:bid, :uid, :eid, :pay, 0, 0, :code, :now1, :now2)'
                );
                $stmt->execute([
                    ':bid'  => $bookingId,
                    ':uid'  => $userId,
                    ':eid'  => $eventId,
                    ':pay'  => $amount,
                    ':code' => $code,
                    ':now1' => $now,
                    ':now2' => $now,
                ]);
                return ['ok' => true, 'mpesa_code' => $code, 'payment' => $amount, 'id' => (int) $db->the_insert_id()];
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'Duplicate entry') === false) {
                    throw $e;
                }
                // Unlucky collision — generate a fresh reference and retry.
            }
        }

        return ['ok' => false, 'error' => 'Could not generate a unique M-Pesa reference. Please retry.'];
    }
}
