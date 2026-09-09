<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/config.php';

/**
 * Couple notification loop — regression tests for closing the status loop
 * back to the couple:
 *
 *  1. Staff confirming / cancelling a booking (client_assign.php) must drop
 *     an in-app notification for the COUPLE (user_id = booking owner), not
 *     just the planner.
 *  2. The notifications archive page must render for a signed-in COUPLE
 *     (it used to be staff-only, so couple alerts had nowhere to land).
 *  3. Anonymous visitors are still bounced off the archive.
 *
 * Runs against the live DB + a php -S server (same harness as the other
 * integration suites). Probe notifications are removed in tearDownAfterClass;
 * seed rows are never modified.
 */
final class CoupleNotificationLoopTest extends TestCase
{
    private const STAFF_EMAIL = 'wafula@wpms.co.ke';
    private const STAFF_PASS  = 'Admin@2024!';
    // Chebet & Kiprop: seed booking 203 is pending with a full account row we
    // can flip and restore — booking 215 is a REAL user and never touched.
    private const COUPLE_EMAIL = 'chebet.eldoret@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const BOOKING_ID   = 203;
    private const COUPLE_ID    = 203;

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe notification ids created by this run */
    private static array $probeNotifIds = [];
    private static ?string $originalStatus = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            global $db;
            $db = self::$db = new Database();
            $row = self::$db->fetchOne(
                'SELECT status FROM tblaccounts_detail WHERE user_id = :u LIMIT 1',
                [':u' => self::COUPLE_ID]
            );
            self::$originalStatus = $row ? (string) $row->status : null;
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null && self::$probeNotifIds !== []) {
            try {
                self::$db->execute(
                    'DELETE FROM tbl_mail_logs WHERE notification_id IN (' . implode(',', self::$probeNotifIds) . ')'
                );
                self::$db->execute(
                    'DELETE FROM tblnotifications WHERE id IN (' . implode(',', self::$probeNotifIds) . ')'
                );
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeNotifIds = [];

        if (self::$serverProc !== null && is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }

        // Restore the account status in case a test bailed mid-swap.
        if (self::$db !== null && self::$originalStatus !== null) {
            try {
                self::$db->execute(
                    'UPDATE tblaccounts_detail SET status = :s WHERE user_id = :u',
                    [':s' => self::$originalStatus, ':u' => self::COUPLE_ID]
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
        self::$originalStatus = null;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testBookingConfirmDropsCoupleNotification(): void
    {
        $this->requireHttp();
        self::loginStaff();
        $token = self::fetchCsrfToken('/admin/client_assign.php?booking=' . self::BOOKING_ID . '&user_id=' . self::COUPLE_ID);

        // Confirm (the endpoint saves the whole account form; supply seed-equal values).
        $res = self::http('POST', '/admin/client_assign.php?booking=' . self::BOOKING_ID . '&user_id=' . self::COUPLE_ID, [
            'confirm'            => '1',
            '_token'             => $token,
            'firstname'          => 'Chebet',
            'lastname'           => 'Rono',
            'email'              => self::COUPLE_EMAIL,
            'wedding_date'       => '2026-09-19',
            'bride'              => 'Chebet Rono',
            'groom'              => 'Kiprop Rono',
            'phone'              => '0733123456',
            'city'               => 'Eldoret',
            'wedding_type'       => '6',
            'organizer_id'       => '7',
            'description'        => "Kalenjin koito + reception — mursik & brown ugali.",
            'location'           => 'Poa Place, Eldoret',
            'expectation_visitor' => '200',
            'cash_advanced'      => '20000.00',
        ]);
        self::assertSame(302, $res['status'], 'confirm POST must redirect');

        $notif = self::latestCoupleNotification();
        self::assertNotNull($notif, 'confirm must create a couple-facing notification');
        self::assertStringContainsString('confirmed', strtolower((string) $notif->message));
        self::assertStringContainsString('Sat 19 Sep 2026', (string) $notif->message, 'the human wedding date must appear in the alert');
    }

    #[Group('integration')]
    public function testBookingCancelDropsCoupleNotification(): void
    {
        $this->requireHttp();
        self::loginStaff();
        $token = self::fetchCsrfToken('/admin/client_assign.php?booking=' . self::BOOKING_ID . '&user_id=' . self::COUPLE_ID);

        $res = self::http('POST', '/admin/client_assign.php?booking=' . self::BOOKING_ID . '&user_id=' . self::COUPLE_ID, [
            'cancel' => '1',
            '_token' => $token,
        ]);
        self::assertSame(302, $res['status']);

        $notif = self::latestCoupleNotification();
        self::assertNotNull($notif, 'cancel must create a couple-facing notification');
        self::assertStringContainsString('cancelled', strtolower((string) $notif->message));
    }

    #[Group('integration')]
    public function testNotificationsArchiveRendersForTheCouple(): void
    {
        $this->requireHttp();

        // Anonymous visitors are still bounced.
        self::$jar = [];
        $anon = self::http('GET', '/admin/notifications.php');
        self::assertSame(302, $anon['status'], 'anonymous visitors must bounce off the archive');

        // The signed-in couple sees THEIR feed (with at least the probe row).
        self::loginCouple();
        $seed = self::$db->prepare(
            'INSERT INTO tblnotifications (user_id, booking_id, message, link) VALUES (:u, :b, :m, :l)'
        );
        $seed->execute([
            ':u' => self::COUPLE_ID,
            ':b' => self::BOOKING_ID,
            ':m' => '[loop-test] probe couple alert',
            ':l' => 'client.php',
        ]);
        self::$probeNotifIds[] = (int) self::$db->the_insert_id();

        $page = self::http('GET', '/admin/notifications.php');
        self::assertSame(200, $page['status'], 'the archive must render for a signed-in couple');
        self::assertStringContainsString('probe couple alert', $page['body'], 'the couple must see their own alert');
        self::assertStringNotContainsString('deposits from your couples', $page['body'], 'staff copy must not leak into the couple view');

        // A foreign couple's alert never leaks.
        self::assertStringNotContainsString('Faith Njeri', $page['body'], 'another couple\'s alerts must not appear');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            $this->markTestSkipped('php -S server / live DB unavailable');
        }
    }

    private static function latestCoupleNotification(): ?object
    {
        return self::$db->fetchOne(
            'SELECT id, message FROM tblnotifications WHERE user_id = :u AND booking_id = :b ORDER BY id DESC LIMIT 1',
            [':u' => self::COUPLE_ID, ':b' => self::BOOKING_ID]
        );
    }

    private static function loginStaff(): void
    {
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
    }

    private static function loginCouple(): void
    {
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS);
    }

    private static function login(string $email, string $pass): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function fetchCsrfToken(string $path): string
    {
        $res = self::http('GET', $path);
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail("could not extract CSRF token from $path");
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $ch = curl_init(self::$base . $path);
        $headers = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ];
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $opts[CURLOPT_HTTPHEADER] = ['Cookie: ' . implode('; ', $parts)];
        }
        if ($post !== []) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        curl_close($ch);

        $status = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_cn_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_cn_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root);
            if (!is_resource(self::$serverProc)) {
                continue;
            }
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
            }
            if ($ready) {
                self::$base = 'http://127.0.0.1:' . $port;
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }
}
