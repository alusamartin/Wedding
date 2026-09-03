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
}
