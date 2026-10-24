<?php
declare(strict_types=1);

/**
 * Fixture DB_Object for tests/PromisesSweepTest.
 *
 * Makes tblgallery a table the fixture project can create rows in, so the
 * sweep reaches its column-level rules instead of stopping at the table.
 * Declaring title/type without a writer is the point: the fixture only reads
 * them, so the sweep must say so.
 */
class Gallery extends DB_Object
{
    protected static $db_table = 'tblgallery';
    protected static $db_table_fields = ['title', 'type'];
}
