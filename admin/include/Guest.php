<?php
declare(strict_types=1);

class Guest extends DB_Object
{
    protected static $db_table = "tblguest";
    protected static $db_table_fields = array(
        'id',
        'booking_id',
        'fullname',
        'guestname',
        'address',
        'state',
        'zipcode',
        'priority',
        'out_of_town',
        'relationship',
        'tracks_and_gifts',
        'city',
        'upcountry_accommodation',
        'harambee_contribution',
        'dietary_requirements',
        'transport_needed'
    );

    public $id;
    public $booking_id;
    public $fullname;
    public $guestname;
    public $address;
    public $state;
    public $zipcode;
    public $priority;
    public $out_of_town;
    public $relationship;
    public $tracks_and_gifts;
    public $city;
    public $upcountry_accommodation;
    public $harambee_contribution;
    public $dietary_requirements;
    public $transport_needed;

    public static function getGuest(mixed $booking_id): array
    {
        global $db;
        $sql  = "SELECT * FROM tblguest WHERE booking_id = :bid ORDER BY priority ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function count_guest(mixed $id): int
    {
        global $db;
        $sql  = "SELECT count(booking_id) FROM " . static::$db_table . " WHERE booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return (int) $stmt->fetchColumn();
    }

    public static function get_upcountry_guests(mixed $booking_id): array
    {
        global $db;
        $sql  = "SELECT * FROM tblguest WHERE booking_id = :bid AND (out_of_town = 'y' OR transport_needed = 1)";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function total_harambee(mixed $booking_id): float
    {
        global $db;
        $sql  = "SELECT SUM(harambee_contribution) FROM " . static::$db_table . " WHERE booking_id = :bid";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }
}
