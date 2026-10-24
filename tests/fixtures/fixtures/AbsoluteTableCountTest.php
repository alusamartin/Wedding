<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deliberately broken mini-suite for sweep F's second rule (F2): a literal
 * compared against an aggregate over a whole table — the number describes the
 * seed, not the behaviour. Never executed; tests/FixtureSweepTest drives the
 * sweep over this tree.
 */
final class AbsoluteTableCountTest extends TestCase
{
    public function testTheWholeDealTableHasSevenRows(): void
    {
        self::assertSame(7, self::dealCount(), 'the seed builds seven deals');
    }

    public function testTheWholeTableHasTwoRows(): void
    {
        self::assertSame(2, (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblvendor_bookings'), 'two deals');
    }

    /** Counts every deal on the installation — no WHERE, nothing of its own. */
    private static function dealCount(): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblvendor_bookings');
    }

    public function testFixtureBoundCountIsNotAFinding(): void
    {
        // Constrained to this class's own record: a real assertion, not a claim
        // about the table, and the sweep must leave it alone.
        self::assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblvendor_bookings WHERE id = :i', [':i' => 5]));
    }
}
