<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deliberately broken mini-suite for sweep F's third rule (F3): text asserted
 * about that the file itself generates in more than one place, so the assertion
 * answers about whichever batch ran last. Never executed; tests/FixtureSweepTest
 * drives the sweep over this tree.
 */
final class GeneratedBatchTextTest extends TestCase
{
    public function testTheSmallBatchIsListed(): void
    {
        self::seedProbes(3);
        self::assertStringContainsString('probe item 3', self::http('GET', '/admin/notifications.php')['body'], 'the last seeded item is on the page');
    }

    public function testTheLargeBatchIsListed(): void
    {
        self::seedProbes(30);
        self::assertStringContainsString('probe item 3', self::http('GET', '/admin/notifications.php')['body'], 'the item is on the page');
    }

    public function testAUniqueLabelIsNotAFinding(): void
    {
        // The fixture's own unique banner: generated once, asserted once, and the
        // label carries its own id — nothing to confuse it with.
        self::assertStringContainsString('QDL-ONLY-ONCE-42', self::http('GET', '/admin/notifications.php')['body']);
    }

    /** Seeds $n probe notifications — called twice with different batch sizes. */
    private static function seedProbes(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            self::$db->execute('INSERT INTO tblnotifications (booking_id, message) VALUES (200, :m)', [':m' => 'probe item ' . $i]);
        }
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        return ['status' => 200, 'body' => '', 'location' => ''];
    }
}
