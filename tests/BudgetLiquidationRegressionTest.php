<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stage 2 regressions against the seeded database:
 *
 *  1. M-Pesa payments are cash-neutral — an M-Pesa row stores cash = 0 and
 *     must never inflate the cash totals (budget_add.php bug: it used to
 *     write cash = payment for 'mpesa').
 *  2. Events::getLiquidation_by_id() must return rows whose ->id is the
 *     liquidation PK (the join previously let events.id shadow it, so the
 *     "Change" edit link targeted the wrong payment record).
 *
 * Integration ('integration' group) — require a live DB with the v2.1 seed;
 * skipped cleanly when the DB or seed data is unavailable. Fixture rows are
 * created and always removed, so the seed state is preserved.
 */
final class BudgetLiquidationRegressionTest extends TestCase
{
    private function bootstrap(): void
    {
        if (!class_exists('DB_Object', false)) {
            require_once __DIR__ . '/../admin/include/config.php';
            require_once __DIR__ . '/../admin/include/Database.php';
            require_once __DIR__ . '/../admin/include/db_object.php';
        }
        foreach (['Liquidation', 'Events'] as $class) {
            if (!class_exists($class, false)) {
                require_once __DIR__ . '/../admin/include/' . $class . '.php';
            }
        }
    }

    /** @return array{0: Database, 1: int, 2: int} [$db, $eventId, $bookingId] */
    private function fixtures(): array
    {
        $this->bootstrap();

        global $db;
        if (!isset($db) || !($db instanceof Database)) {
            try {
                $db = new Database();
            } catch (Throwable $e) {
                self::markTestSkipped('Live DB unavailable: ' . $e->getMessage());
            }
        }

        try {
            $row = $db->fetchOne('SELECT id, booking_id FROM events WHERE booking_id > 0 ORDER BY id LIMIT 1');
        } catch (Throwable $e) {
            self::markTestSkipped('DB query failed: ' . $e->getMessage());
        }
        if (!$row) {
            self::markTestSkipped('No seeded event found — import the v2.1 seed first');
        }

        return [$db, (int) $row->id, (int) $row->booking_id];
    }

    private function insertLiquidation(
        Database $db,
        int $bookingId,
        int $eventId,
        float $payment,
        float $cash,
        float $credit,
        ?string $mpesaCode
    ): int {
        $db->execute(
            'INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified)
             VALUES (:b, 0, :e, :p, :c, :cr, :m, NOW(), NOW())',
            [':b' => $bookingId, ':e' => $eventId, ':p' => $payment, ':c' => $cash, ':cr' => $credit, ':m' => $mpesaCode]
        );
        return (int) $db->the_insert_id();
    }

    private function totals(int $bookingId): array
    {
        $t = Liquidation::getTotalsByMethod($bookingId);
        return [
            'mpesa'  => (float) ($t['mpesa_total'] ?? 0),
            'cash'   => (float) ($t['cash_total'] ?? 0),
            'grand'  => (float) ($t['grand_total'] ?? 0),
        ];
    }

    #[Group('integration')]
    public function testMpesaPaymentsAreCashNeutralAndStillCountTowardsTotals(): void
    {
        [$db, $eventId, $bookingId] = $this->fixtures();

        $before = $this->totals($bookingId);
        $cashOnlyBefore = Liquidation::getTotalAmountCash($bookingId);
        $mpesaMethodBefore = Liquidation::getTotalAmountMpesa($bookingId);

        $cashRowId = 0;
        $mpesaRowId = 0;
        try {
            // A genuine cash payment: payment = cash, no M-Pesa code.
            $cashRowId = $this->insertLiquidation($db, $bookingId, $eventId, 4500.0, 4500.0, 0.0, null);

            // An M-Pesa payment (Stage 2 semantics): payment recorded, cash = 0,
            // method derived from the unique Safaricom code.
            $mpesaCode = 'RT' . strtoupper(bin2hex(random_bytes(4))); // 10 chars, unique
            $mpesaRowId = $this->insertLiquidation($db, $bookingId, $eventId, 9999.0, 0.0, 0.0, $mpesaCode);

            $after = $this->totals($bookingId);

            // Cash total moves ONLY by the cash row…
            self::assertEqualsWithDelta($before['cash'] + 4500.0, $after['cash'], 0.001, 'Cash payment must add to the cash total');
            // …the M-Pesa row must not leak into Cash…
            self::assertEqualsWithDelta($before['cash'] + 4500.0, Liquidation::getTotalAmountCash($bookingId), 0.001, 'M-Pesa payment must stay cash-neutral');
            // …but it does count as M-Pesa and toward the grand total.
            self::assertEqualsWithDelta($before['mpesa'] + 9999.0, $after['mpesa'], 0.001, 'M-Pesa payment must add to the M-Pesa total');
            self::assertEqualsWithDelta($before['grand'] + 4500.0 + 9999.0, $after['grand'], 0.001, 'Both payments must add to the grand total');
            // The method the admin UI uses must also grow by exactly the M-Pesa row.
            self::assertEqualsWithDelta(
                $mpesaMethodBefore + 9999.0,
                Liquidation::getTotalAmountMpesa($bookingId),
                0.001,
                'getTotalAmountMpesa must include the M-Pesa row'
            );
        } finally {
            foreach ([$cashRowId, $mpesaRowId] as $id) {
                if ($id > 0) {
                    $db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => $id]);
                }
            }
        }
    }

    #[Group('integration')]
    public function testLiquidationRowsExposeTheirOwnPkNotTheShadowedEventId(): void
    {
        [$db, $eventId, $bookingId] = $this->fixtures();

        $liquidationId = 0;
        try {
            $liquidationId = $this->insertLiquidation($db, $bookingId, $eventId, 1234.0, 1234.0, 0.0, null);

            $eventTitle = $db->fetchValue('SELECT title FROM events WHERE id = :id', [':id' => $eventId]);
            self::assertNotSame($eventId, $liquidationId, 'Fixture must keep the liquidation PK distinct from the event id');

            $rows = Events::getLiquidation_by_id($bookingId);
            $match = null;
            foreach ($rows as $row) {
                if ((int) $row->id === $liquidationId) {
                    $match = $row;
                    break;
                }
            }

            self::assertNotNull($match, 'getLiquidation_by_id must return the inserted row');
            self::assertSame($liquidationId, (int) $match->id, '->id must be the liquidation PK (was shadowed by events.id)');
            self::assertNotSame($eventId, (int) $match->id, '->id must NOT resolve to the joined event id');
            self::assertSame($eventTitle, $match->title, 'The join must still bring the event title through');
        } finally {
            if ($liquidationId > 0) {
                $db->execute('DELETE FROM tbl_liquidation WHERE id = :id', [':id' => $liquidationId]);
            }
        }
    }
}
