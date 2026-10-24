<?php
declare(strict_types=1);

/**
 * Fixture DB_Object for tests/EnumValueSweepTest.
 *
 * category is a ten-value ENUM in the live schema, so it gives the sweep a
 * column with declared values it never reacts to and a set of reacted values
 * nothing writes.
 */
class Vendor extends DB_Object
{
    protected static $db_table = 'tblvendors';
    protected static $db_table_fields = ['id', 'user_id', 'business_name', 'category'];

    public $id;
    public $category;
}
