<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deliberately broken mini-suite for sweep F's ratchet (F6). NEVER executed.
 *
 * This file reads a seeded booking it never writes, with no calendar claim, so
 * the sweep cannot decide whether the seeded state matches the assertion. That
 * is a gap rather than a finding — and because TOLERATED_GAPS does not cover it,
 * the ratchet must fail the build and say which key to argue about.
 *
 * Its pair, ToleratedForeignReadTest.php, makes the same read and IS covered by
 * an allowance, so the two together prove both halves: the list cannot grow, and
 * an argued-for entry passes.
 */
final class ForeignFixtureReadTest extends TestCase
{
    private const SEED_BOOKING = 200;

    public function testReadsSomebodyElsesBooking(): void
    {
        $body = self::http(
            'GET',
            '/admin/client_budget.php?booking_id=' . self::SEED_BOOKING
        )['body'];
        self::assertStringContainsString('Budget vs Actual', $body, 'the funding panel renders for a priced package');
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        return ['status' => 200, 'body' => '', 'location' => ''];
    }
}
