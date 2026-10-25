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
    public $city; // joined from tblaccounts_detail
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
        $stmt = $db->execute($sql);
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
        $stmt = $db->execute($sql);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    /**
     * How many bookings — on the whole platform, or inside a caller's scope.
     *
     * The optional `$scope` is the shape `planner_scope_sql()` returns
     * (`['sql' => condition, 'params' => [...]]`), empty when nothing is
     * scoped. It is passed IN rather than looked up here on purpose: the model
     * has no business reading $_SESSION, and the scripts and tests that
     * legitimately want the platform-wide number keep calling it with no
     * arguments.
     *
     * @param array{sql?:string, params?:array<string,int>} $scope
     */
    public static function count_booking(array $scope = []): int
    {
        global $db;
        $sql = 'SELECT count(booking_id) FROM ' . self::$db_table
            . (!empty($scope['sql']) ? ' WHERE ' . $scope['sql'] : '');
        return (int) $db->fetchValue($sql, $scope['params'] ?? []);
    }

    /**
     * How many DISTINCT couples have a wedding inside this scope.
     *
     * The dashboard's "Total Customers" tile used the platform-wide
     * `Account_Details::count_user()` (every row in tblaccounts_detail), which
     * told a planner how many couples the WHOLE platform holds while the tile
     * beside it (Total Bookings) was already narrowed to their own weddings.
     * Two tiles, two different universes. Counting distinct `user_id` here —
     * through the same `planner_scope_sql()` predicate every list uses — makes
     * "customers" mean "couples whose wedding I can open".
     */
    public static function count_couples(array $scope = []): int
    {
        global $db;
        $sql = 'SELECT COUNT(DISTINCT user_id) FROM ' . self::$db_table
            . (!empty($scope['sql']) ? ' WHERE ' . $scope['sql'] : '');
        return (int) $db->fetchValue($sql, $scope['params'] ?? []);
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
