<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deliberately broken mini-suite for sweep F — NEVER executed.
 *
 * Both halves of the booking-215 bug live here: a record the database does not
 * contain at all (F1), and a seeded record this file never writes carrying a
 * calendar claim (F4). tests/FixtureSweepTest points scripts/audit_fixtures.php
 * at this tree and asserts the sweep reports each one.
 */
final class MissingRecordClaimTest extends TestCase
{
    private const BOOKING_ID = 999215;   // no database builds this
    private const USER_ID    = 999215;
    private const SEED_BOOKING = 200;    // exists, but not this file's fixture

    public function testCountdownForABookingNoSeedBuilds(): void
    {
        $body = self::http('GET', '/admin/client_manage_account_details.php?booking_id=' . self::BOOKING_ID . '&user_id=' . self::USER_ID)['body'];
        self::assertMatchesRegularExpression('/\d+ days? to go/', $body, 'a future wedding shows its countdown');
    }

    public function testGuardedReadOfTheSameMissingRecord(): void
    {
        $res = self::http('GET', '/admin/client_budget.php?booking_id=' . self::BOOKING_ID . '&user_id=' . self::USER_ID);
        if ($res['status'] === 200) {
            self::assertStringNotContainsString('Budget vs Actual', $res['body'], 'no package = no funding panel');
        }
    }

    public function testCalendarClaimAboutSomebodyElsesBooking(): void
    {
        $body = self::http('GET', '/admin/client_manage_account_details.php?booking_id=' . self::SEED_BOOKING . '&user_id=' . self::SEED_BOOKING)['body'];
        self::assertStringContainsString('Saturday 19 December 2026', $body, 'the wedding date is human-formatted');
    }

    public function testRefusalProbeIsNotAFinding(): void
    {
        // A nonexistent id used to prove a refusal is legitimate and must not be
        // reported: the assertion is about the status, not the body.
        self::assertSame(302, self::http('GET', '/admin/sheet_guests.php?booking_id=99999999&user_id=1')['status']);
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        return ['status' => 200, 'body' => '', 'location' => ''];
    }
}
