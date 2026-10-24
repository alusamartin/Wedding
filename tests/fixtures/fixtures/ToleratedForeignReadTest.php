<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deliberately broken mini-suite for sweep F's ratchet (F6), the tolerated
 * half. NEVER executed.
 *
 * The read is the same shape as ForeignFixtureReadTest's — a seeded booking this
 * file never writes, no calendar claim — but the key
 * `tests/fixtures/fixtures/ToleratedForeignReadTest.php::booking_id=200` is
 * recorded in TOLERATED_GAPS with its reason, so the sweep passes this one and
 * prints the reason instead of failing. Together the two files are the proof that
 * F6 accepts an argued-for gap and rejects an unargued one.
 */
final class ToleratedForeignReadTest extends TestCase
{
    private const SEED_BOOKING = 200;

    public function testReadsSomebodyElsesBookingOnPurpose(): void
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
