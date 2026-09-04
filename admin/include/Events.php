<?php
declare(strict_types=1);

class Events extends DB_Object
{
    protected static $db_table = "events";
    protected static $db_table_fields = array(
        'id',
        'booking_id',
        'title',
        'location',
        'date_created',
        'color',
        'start',
        'end'
    );

    public $id;
    public $booking_id;
    public $title;
    public $location;
    public $start;
    public $end;
    public $color;
    public $date_created;
    public $payment;
    public $date_issue;
    public $cash;
    public $credit;

    public static function find_by_event_all_budget(mixed $id): array
    {
        global $db;
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE booking_id = :id ORDER BY start ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function getLiquidation_by_id(mixed $id): array
    {
        global $db;
        // Select l.* first so `id` resolves to the liquidation PK (a plain
        // SELECT * over the join lets events.id shadow it, breaking edit links).
        $sql  = "SELECT l.*, e.title FROM tbl_liquidation l INNER JOIN events e ON e.id = l.event_id WHERE l.booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function find_by_event_by_all(mixed $id): array
    {
        global $db;
        $sql  = "SELECT * FROM " . static::$db_table . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function get_events_for_calendar(mixed $booking_id): array
    {
        global $db;
        $sql  = "SELECT id, title, location, start, end, color FROM events WHERE booking_id = :bid";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
