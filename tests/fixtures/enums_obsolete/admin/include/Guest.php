<?php
declare(strict_types=1);

/**
 * Fixture DB_Object for tests/EnumValueSweepTest.
 *
 * Declaring rsvp_status for tblguest is what lets the sweep read
 * `$guest->rsvp_status = …` as a write to that column at all: the property name
 * is unique in the schema AND a class declares it for the table.
 */
class Guest extends DB_Object
{
    protected static $db_table = 'tblguest';
    protected static $db_table_fields = [
        'id', 'booking_id', 'fullname', 'rsvp_status', 'rsvp_at', 'rsvp_note',
    ];

    public $id;
    public $booking_id;
    public $fullname;
    public $rsvp_status;
}
