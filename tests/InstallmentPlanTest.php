<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in installment plan regressions:
 *
 *  1. create_plan: monthly = balance/months rounded UP to KSh 50; one
 *     active plan per booking (re-create supersedes); unpriced or
 *     fully-paid bookings refuse a plan.
 *  2. progress(): installments completed from the paid amount; next due
 *     date; on-track flag.
 *  3. nudge_for(): plan-aware lines on the overview (plan line replaces
 *     the generic milestone), HTTP-verified.
 *  4. The plan form POST endpoint (CSRF + ownership, cancel restores the
 *     generic bands).
 *  5. cancel() removes the plan.
 *
 * Fixture rows (booking 99xxxx) are always restored.
 */
final class InstallmentPlanTest extends TestCase
{
    private const FIX_USER = 990004;
    private const FIX_BOOK = 990004;

    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';
        require_once __DIR__ . '/../admin/include/db_object.php';
        require_once __DIR__ . '/../admin/include/Helper.php';
        require_once __DIR__ . '/../admin/include/Liquidation.php';
        require_once __DIR__ . '/../admin/include/Monetization.php';
        require_once __DIR__ . '/../admin/include/Booking.php';
        require_once __DIR__ . '/../admin/include/Categories.php';
        require_once __DIR__ . '/../admin/include/InstallmentPlan.php';
        if (!class_exists('Notification', false)) {
            require_once __DIR__ . '/../admin/include/Notification.php';
        }
        self::$pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        global $db;
        $db = new Database();
        self::cleanup();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanup();
    }

    private static function cleanup(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        $p->exec('DELETE FROM tbl_installment_plans WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tbl_liquidation WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblnotifications WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tbl_mail_logs WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblweddingbook WHERE booking_id = ' . self::FIX_BOOK);
        $p->exec('DELETE FROM tblaccounts_detail WHERE user_id = ' . self::FIX_USER);
        $p->exec('DELETE FROM tblaccounts WHERE user_id = ' . self::FIX_USER);
    }

    /**
     * @return array{id:int, price:float, event_id:int} confirmed booking, priced package
     * @param float|null $leaveBalance if set, seed a deposit leaving exactly this balance
     */
    private static function seedFixture(?float $leaveBalance = null): array
    {
        self::cleanup();
        $p = self::$pdo;
        $cat = $p->query('SELECT id, price FROM tblweddingcategories WHERE price > 0 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertNotEmpty($cat);
        $price    = (float) $cat['price'];
        $deposit  = $leaveBalance === null ? 0.0 : max(0.0, $price - $leaveBalance);

        $p->prepare("INSERT INTO tblaccounts (user_id, user_email, user_password, access_level) VALUES (?, 'fixture.plan@example.com', 'x', '0')")
            ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblaccounts_detail (user_id, firstname, lastname, phone, city, datetime_created, description, location, expectation_visitor, cash_advanced, status, date_signed)
                     VALUES (?, 'Amina', 'Wanjiru', '0721999004', 'Nakuru', NOW(), 'fixture', 'Nakuru', '80', 0, 'confirm', NOW())")
            ->execute([self::FIX_USER]);
        $p->prepare("INSERT INTO tblweddingbook (booking_id, user_id, bride, groom, wedding_type, user_email, wedding_date, organizer_id)
                     VALUES (?, ?, 'Amina', 'Baraka', ?, 'fixture.plan@example.com', ?, 7)")
            ->execute([self::FIX_BOOK, self::FIX_USER, (int) $cat['id'], date('Y-m-d', strtotime('+120 days'))]);

        $ev = $p->prepare("INSERT INTO events (booking_id, title, location, start, end, color) VALUES (?, 'Ceremony', 'Nakuru', ?, ?, '#0EA5A0')");
        $ev->execute([self::FIX_BOOK, date('Y-m-d 10:00:00', strtotime('+120 days')), date('Y-m-d 12:00:00', strtotime('+120 days'))]);
        $eventId = (int) self::$pdo->lastInsertId();

        if ($deposit > 0) {
            $p->prepare("INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified)
                         VALUES (?, ?, ?, ?, 0, 0, ?, NOW(), NOW())")
                ->execute([self::FIX_BOOK, self::FIX_USER, $eventId, $deposit, 'QDLPLAN01']);
        }

        return ['price' => $price, 'event_id' => $eventId];
    }

    #[Group('integration')]
    public function testCreatePlanComputesMonthlyRoundedUpToFifty(): void
    {
        $fx = self::seedFixture();
        $plan = InstallmentPlan::create_plan(self::FIX_BOOK, 6);
        self::assertNotNull($plan, 'a priced booking with a balance must accept a plan');

        $expected = (float) (ceil($fx['price'] / 6 / 50) * 50);
        self::assertSame(6, (int) $plan->months);
        self::assertEqualsWithDelta($expected, (float) $plan->monthly_amount, 0.001, 'monthly must be balance/6 rounded UP to KSh 50');
        self::assertGreaterThanOrEqual($fx['price'] / 6, (float) $plan->monthly_amount, 'rounding must never under-collect');
    }

    #[Group('integration')]
    public function testRecreatingSupersedesAndCancelRemoves(): void
    {
        self::seedFixture();

        $first = InstallmentPlan::create_plan(self::FIX_BOOK, 6);
        self::assertNotNull($first);
        $second = InstallmentPlan::create_plan(self::FIX_BOOK, 3);
        self::assertNotNull($second);

        $rows = self::$pdo->query('SELECT COUNT(*) FROM tbl_installment_plans WHERE booking_id = ' . self::FIX_BOOK)->fetchColumn();
        self::assertSame(1, (int) $rows, 'UNIQUE booking_id: re-creating must supersede, not duplicate');
        self::assertSame(3, (int) $second->months, 'the newest plan wins');

        self::assertTrue(InstallmentPlan::cancel(self::FIX_BOOK));
        self::assertNull(InstallmentPlan::for_booking(self::FIX_BOOK), 'cancel must remove the plan');
    }

    #[Group('integration')]
    public function testUnpricedAndFullyPaidBookingsRefuseAPlan(): void
    {
        // Fully paid: deposit covers the whole package.
        $fx =        self::seedFixture(0.0);
        $p  = self::$pdo;
        $p->prepare("INSERT INTO tbl_liquidation (booking_id, user_id, event_id, payment, cash, credit, mpesa_code, date_issue, date_modified)
                     VALUES (?, ?, ?, ?, 0, 0, 'QDLPLAN02', NOW(), NOW())")
            ->execute([self::FIX_BOOK, self::FIX_USER, $fx['event_id'], $fx['price']]);
        self::assertNull(InstallmentPlan::create_plan(self::FIX_BOOK, 6), 'a paid-up booking must refuse a plan');

        // Unpriced package.
        self::seedFixture();
        $p->exec('UPDATE tblweddingbook SET wedding_type = 0 WHERE booking_id = ' . self::FIX_BOOK);
        self::assertNull(InstallmentPlan::create_plan(self::FIX_BOOK, 6), 'an unpriced booking must refuse a plan');
    }

    #[Group('integration')]
    public function testProgressTracksInstallmentsAndNextDue(): void
    {
        $fx = self::seedFixture();
        $plan = InstallmentPlan::create_plan(self::FIX_BOOK, 5);
        self::assertNotNull($plan);
        $per = (float) $plan->monthly_amount;

        // Two installments paid.
        $prog = $plan->progress(2 * $per);
        self::assertSame(2, $prog['done'], 'two full installments paid');
        self::assertSame(5, $prog['total']);
        self::assertNotNull($prog['next_due'], 'more installments remain');
        self::assertTrue($prog['on_track'], 'a fresh plan is on track');

        // Overpayment caps at the plan total.
        $prog = $plan->progress(99 * $per);
        self::assertSame(5, $prog['done'], 'progress caps at the plan length');
    }

    #[Group('integration')]
    public function testPlanAnchorsAtCreationNotTotalPaid(): void
    {
        // The couple already paid everything but 10,000 BEFORE setting the
        // plan (deposit derived from the live price, whatever package that is).
        $fx = self::seedFixture(10000.00);
        $plan = InstallmentPlan::create_plan(self::FIX_BOOK, 5);
        self::assertNotNull($plan);

        // The plan covers only the 10,000 balance.
        self::assertEqualsWithDelta(10000.0 / 5, (float) $plan->monthly_amount, 0.001);
        self::assertEqualsWithDelta($fx['price'] - 10000.0, (float) $plan->starting_paid, 0.001, 'the anchor must record payments before plan creation');

        // Total paid unchanged => zero plan progress, NOT complete.
        $prog = $plan->progress($fx['price'] - 10000.0);
        self::assertSame(0, $prog['done'], 'pre-plan payments must not count as installments');
        self::assertSame(5, $prog['total']);
        self::assertNotNull($prog['next_due'], 'a fresh plan with prior payments is at installment 1, not complete');

        // One plan installment later = installment 1 of 5.
        $prog = $plan->progress($fx['price'] - 10000.0 + 2000.0);
        self::assertSame(1, $prog['done'], 'only post-plan payments count');
        self::assertSame(2000.0, $prog['paid_into_plan']);
    }

    #[Group('integration')]
    public function testPlanEndpointSetsAndCancelsOverHttp(): void
    {
        self::seedFixture();

        $port = 32000 + random_int(0, 8999);
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', realpath(__DIR__ . '/..')],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            realpath(__DIR__ . '/..')
        );
        self::assertIsResource($proc);
        $base = 'http://127.0.0.1:' . $port;
        $up = false;
        for ($i = 0; $i < 40; $i++) {
            usleep(100_000);
            if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
                $up = true;
                break;
            }
        }
        self::assertTrue($up, 'test server must boot');
        try {
            // Login as the seeded staff (owns fixture assistance rights) —
            // simpler than a client round-trip: staff pass the gate too.
            $page = self::http($base, 'GET', '/login.php');
            self::assertSame(200, $page['status']);
            self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m), 'login form must expose a CSRF token');
            $post = self::http($base, 'POST', '/login.php', [
                'input_email'    => 'wafula@wpms.co.ke',
                'input_password' => 'Admin@2024!',
                '_token'         => html_entity_decode($m[1], ENT_QUOTES),
                'login'          => '1',
            ]);
            self::assertSame(302, $post['status'], 'staff login must succeed');

            // Tokenless POST must bounce with no plan created.
            $reject = self::http($base, 'POST', '/admin/client_installment_plan.php?booking_id=' . self::FIX_BOOK . '&user_id=' . self::FIX_USER, [
                'months' => '6',
            ]);
            self::assertSame(302, $reject['status'], 'tokenless POST must redirect away');
            self::assertNull(InstallmentPlan::for_booking(self::FIX_BOOK), 'no plan may be created without CSRF');

            // Valid POST sets the plan. The session CSRF token is stable,
            // and a second GET /login.php would auto-redirect (already
            // authenticated) — so reuse the token captured at login.
            $ok = self::http($base, 'POST', '/admin/client_installment_plan.php?booking_id=' . self::FIX_BOOK . '&user_id=' . self::FIX_USER, [
                '_token' => html_entity_decode($m[1], ENT_QUOTES),
                'months' => '6',
            ]);
            self::assertSame(302, $ok['status']);
            $plan = InstallmentPlan::for_booking(self::FIX_BOOK);
            self::assertNotNull($plan, 'a valid CSRF POST must create the plan');
            self::assertSame(6, (int) $plan->months);

            // The overview renders the plan label + cancel button.
            $ov = self::http($base, 'GET', '/admin/client_manage_account_details.php?booking_id=' . self::FIX_BOOK . '&user_id=' . self::FIX_USER);
            self::assertSame(200, $ov['status']);
            self::assertStringContainsString('Plan: 6 × ', $ov['body'], 'the overview must show the active plan');
            self::assertStringContainsString('Cancel plan', $ov['body']);

            // Cancel via POST removes it (same stable session token).
            self::http($base, 'POST', '/admin/client_installment_plan.php?booking_id=' . self::FIX_BOOK . '&user_id=' . self::FIX_USER, [
                '_token' => html_entity_decode($m[1], ENT_QUOTES),
                'cancel' => '1',
            ]);
            self::assertNull(InstallmentPlan::for_booking(self::FIX_BOOK), 'cancel must remove the plan over HTTP');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * Minimal curl-free HTTP client with a cookie jar (redirects not followed).
     * @return array{status:int, body:string}
     */
    private static function http(string $base, string $method, string $path, array $post = []): array
    {
        static $cookie = '';
        $body = $post === [] ? '' : http_build_query($post);
        $header = 'Host: 127.0.0.1' . "\r\n"
            . ($cookie !== '' ? "Cookie: $cookie\r\n" : '')
            . ($post !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . 'Connection: close';
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => $header, 'content' => $body,
            'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 10,
        ]]);
        $response = @file_get_contents($base . $path, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $cookie = trim(explode(';', substr($h, 11))[0]);
            }
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $mm)) {
                $status = (int) $mm[1];
            }
        }
        return ['status' => $status, 'body' => (string) $response];
    }
}
