<?php
declare(strict_types=1);

/**
 * Fixture DB_Object for tests/PromisesSweepTest — the obsolete-entry rule.
 *
 * `calendar` is accepted in the sweep's ACCEPTED_STATE with the reason "legacy
 * table: no page reads it". Declaring a persistence class for it here means the
 * acceptance has outlived its debt, and the sweep must say so rather than keep
 * ignoring the table forever. Nothing else in the fixture touches `calendar`,
 * so this file is the only thing that can trip that rule.
 */
class LegacyCalendar extends DB_Object
{
    protected static $db_table = 'calendar';
    protected static $db_table_fields = [];
}
