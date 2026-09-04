<?php
declare(strict_types=1);

class Booking extends DB_Object
{
    protected static $db_table = "tblweddingbook";
    protected static $db_table_fields = array(
        'booking_id',
        'user_id',
        'bride',
        'groom',
        'wedding_type',
        'user_email',
        'wedding_date',
        'organizer_id'
    );

    public $booking_id;
    public $user_id;
    public $bride;
    public $groom;
    public $wedding_date;
    public $firstname;
    public $lastname;
    public $user_email;
    public $wedding_type;
    public $phone;
    public $organizer_id;

    public function check_wedding_date(string $date): bool
    {
        global $db;
        $sql  = "SELECT 1 FROM " . self::$db_table . " WHERE wedding_date = :date LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':date' => $date]);
        return $stmt->fetch() !== false;
    }

    public static function getBooking(): array
    {
        global $db;
        $sql = "SELECT * FROM tblaccounts_detail INNER JOIN tblweddingbook ON tblweddingbook.user_id = tblaccounts_detail.user_id WHERE tblaccounts_detail.status = 'pending' ORDER BY booking_id ASC";
        $stmt = $db->query($sql);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function ConfirmedBooking(): array
    {
        global $db;
        $sql = "SELECT * FROM tblaccounts_detail INNER JOIN tblweddingbook ON tblweddingbook.user_id = tblaccounts_detail.user_id WHERE tblaccounts_detail.status = 'confirm' ORDER BY booking_id ASC";
        $stmt = $db->query($sql);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function count_booking(): int
    {
        global $db;
        $sql = "SELECT count(booking_id) FROM " . self::$db_table;
        return (int) $db->fetchValue($sql);
    }

    /** Pending bookings owned by one client account (client portal scope). */
    public static function getBookingForUser(int $userId): array
    {
        global $db;
        $sql = "SELECT * FROM tblaccounts_detail INNER JOIN tblweddingbook ON tblweddingbook.user_id = tblaccounts_detail.user_id WHERE tblaccounts_detail.user_id = :uid AND tblaccounts_detail.status = 'pending' ORDER BY booking_id ASC";
        $stmt = $db->execute($sql, [':uid' => $userId]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    /** Confirmed bookings owned by one client account (client portal scope). */
    public static function ConfirmedBookingForUser(int $userId): array
    {
        global $db;
        $sql = "SELECT * FROM tblaccounts_detail INNER JOIN tblweddingbook ON tblweddingbook.user_id = tblaccounts_detail.user_id WHERE tblaccounts_detail.user_id = :uid AND tblaccounts_detail.status = 'confirm' ORDER BY booking_id ASC";
        $stmt = $db->execute($sql, [':uid' => $userId]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }
}
