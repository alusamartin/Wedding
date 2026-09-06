<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Staff notifications archive (admin/notifications.php) + dashboard unread
 * card against a real php -S server:
 *
 *  1. Access gate — the archive is staff-only (clients bounce).
 *  2. Dashboard card — the unread count renders as the "Unread Alerts"
 *     card, matches the DB, and deep-links to the archive.
 *  3. Archive renders — header count, newest-first rows, pagination footer.
 *  4. Read flow — action=read marks a row read and follows the deep link;
 *     action=readall clears every unread row.
 *
 * Integration ('integration' group) — requires the live DB and a bootable
 * php -S. Probe notifications are created for wafula@wpms.co.ke (the seeded
 * admin, NOT a booking planner, so no real alerts are touched) and always
 * removed in tearDownAfterClass.
 */
final class StaffNotificationsPageTest extends TestCase
{
    private const STAFF_EMAIL  = 'wafula@wpms.co.ke';
    private const STAFF_PASS   = 'Admin@2024!';
    private const CLIENT_EMAIL = 'brian.wafula@gmail.com';
    private const CLIENT_PASS  = 'Test@1234';
    private const PER_PAGE     = 25;

    private static ?Database $db = null;
    private static ?string $base = null;
    private static int $staffId = 0;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe notification ids created by this run */
    private static array $probeIds = [];

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
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        if (self::$db !== null) {
            try {
                self::$staffId = (int) self::$db->fetchValue(
                    'SELECT id FROM tblusers WHERE email = :e LIMIT 1',
                    [':e' => self::STAFF_EMAIL]
                );
            } catch (Throwable $e) {
                self::$staffId = 0;
            }
        }

        self::bootServer();
        if (self::$base === null || self::$db === null || self::$staffId === 0) {
            return; // tests will skip
        }

        self::loginStaff();
        if (self::$jar === []) {
            self::$base = null; // staff login failed — seeded credentials unavailable
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Probe rows only — everything this run created is ours to delete.
        if (self::$db !== null && self::$probeIds !== []) {
            try {
                $stmt = self::$db->prepare(
                    'DELETE FROM tblnotifications WHERE id IN (' . implode(',', self::$probeIds) . ')'
                );
                $stmt->execute();
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probeIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_np_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_np_err');

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
                // Probe a real route — a bound socket alone is not proof the
                // server is serving (and port 80 XAMPP must never be hit).
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
                $probeBody = @file_get_contents(self::$base . '/index.php', false, $ctx);
                if ($probeBody === false) {
                    proc_terminate(self::$serverProc);
                    proc_close(self::$serverProc);
                    self::$serverProc = null;
                    self::$base = null;
                    continue;
                }
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    /**
     * Minimal curl-free HTTP client; redirects are NOT followed.
     *
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        // Never silently hit a foreign server (e.g. XAMPP on :80) when the
        // test server failed to boot — fail loudly instead.
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
        }
        $body = $post === [] ? '' : http_build_query($post);
        $header = 'Host: 127.0.0.1' . "\r\n"
            . $cookieHeader
            . ($post !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close";

        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => $header,
            'content'         => $body,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 10,
        ]]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);
        $headers  = $http_response_header ?? [];

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null || self::$staffId === 0) {
            self::markTestSkipped('php -S server / live DB / seeded staff unavailable — notifications page tests skipped');
        }
    }

    /** Seed $n probe notifications for the wafula admin; last $unread unread. */
    private static function seedProbes(int $n, int $unread): void
    {
        global $db;
        $stmt = $db->prepare(
            'INSERT INTO tblnotifications (user_id, booking_id, message, link, is_read) '
            . 'VALUES (:uid, 0, :msg, "dashboard.php", :read)'
        );
        for ($i = 1; $i <= $n; $i++) {
            $stmt->execute([
                ':uid'  => self::$staffId,
                ':msg'  => '[page-test] probe notification ' . $i,
                ':read' => $i <= ($n - $unread) ? 1 : 0,
            ]);
            self::$probeIds[] = (int) $db->the_insert_id();
        }
    }

    private static function unreadForStaff(): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications WHERE user_id = :uid AND is_read = 0',
            [':uid' => self::$staffId]
        );
    }

    private static function totalForStaff(): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications WHERE user_id = :uid',
            [':uid' => self::$staffId]
        );
    }

    #[Group('integration')]
    public function testArchiveIsStaffOnly(): void
    {
        $this->requireHttp();

        // Fresh client session must be bounced off the staff archive.
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/name="_token" value="([^"]+)"/', $page['body']);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
        $post = self::http('POST', '/login.php', [
            'input_email'    => self::CLIENT_EMAIL,
            'input_password' => self::CLIENT_PASS,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
        self::assertSame(302, $post['status'], 'client login must succeed for the gate test');

        $res = self::http('GET', '/admin/notifications.php');
        self::assertSame(302, $res['status'], 'clients must be bounced off the staff archive');
        self::assertNotSame('', $res['location'], 'the bounce must carry a redirect target');
        self::assertStringStartsWith('../', $res['location'] ?? '', 'the bounce must stay on-site (site root)');

        // Restore the staff session for the remaining tests in this class.
        self::loginStaff();
    }

    #[Group('integration')]
    public function testDashboardShowsUnreadAlertsCardLinkedToArchive(): void
    {
        $this->requireHttp();
        $before  = self::unreadForStaff();
        self::seedProbes(2, 2);
        $expected = $before + 2;

        $dash = self::http('GET', '/admin/dashboard.php');
        self::assertSame(200, $dash['status']);
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $dash['body'], 'dashboard must not leak PHP issues');
        }
        self::assertStringContainsString('card-counter warning', $dash['body'], 'the Unread Alerts card must render');
        self::assertStringContainsString('href="notifications.php"', $dash['body'], 'the card must link to the archive');
        self::assertStringContainsString('Unread Alerts', $dash['body']);
        self::assertMatchesRegularExpression(
            '/card-counter warning.*?count-numbers">\s*' . $expected . '\s*</s',
            $dash['body'],
            "card count must equal the user's unread notifications ({$expected})"
        );
    }

    #[Group('integration')]
    public function testArchiveRendersRowsAndPagination(): void
    {
        $this->requireHttp();
        self::seedProbes(30, 5); // 25 read + 5 unread → >1 page at 25/page

        $total = self::totalForStaff();
        $pages = (int) ceil($total / self::PER_PAGE);
        self::assertGreaterThanOrEqual(2, $pages, '30 probes on top of any existing rows must paginate');

        $page1 = self::http('GET', '/admin/notifications.php');
        self::assertSame(200, $page1['status']);
        foreach (['Fatal error:', 'Parse error:', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            self::assertStringNotContainsString($marker, $page1['body'], 'archive must not leak PHP issues');
        }
        self::assertStringContainsString('Notifications', $page1['body']);
        self::assertStringContainsString($total . ' total', $page1['body'], 'footer must show the DB total');
        self::assertStringContainsString('Page 1 of ' . $pages, $page1['body']);
        self::assertStringContainsString('[page-test] probe notification 30', $page1['body'], 'newest rows render first');
        self::assertStringContainsString('notifications.php?page=2', $page1['body'], 'older link must be offered');

        $last = self::http('GET', '/admin/notifications.php?page=' . $pages);
        self::assertSame(200, $last['status']);
        self::assertStringContainsString('Page ' . $pages . ' of ' . $pages, $last['body']);
        self::assertStringContainsString('[page-test] probe notification 1', $last['body'], 'oldest rows on the last page');
    }

    #[Group('integration')]
    public function testReadActionMarksRowReadAndRedirects(): void
    {
        $this->requireHttp();
        self::seedProbes(1, 1);
        $id = self::$probeIds[count(self::$probeIds) - 1]; // the probe just added (unread)

        $res = self::http('GET', '/admin/notifications.php?action=read&id=' . $id);
        self::assertSame(302, $res['status']);
        self::assertStringContainsString('dashboard.php', $res['location'] ?? '', 'read must follow the notification deep link');

        $isRead = (int) self::$db->fetchValue(
            'SELECT is_read FROM tblnotifications WHERE id = :id',
            [':id' => $id]
        );
        self::assertSame(1, $isRead, 'action=read must persist is_read=1');
    }

    #[Group('integration')]
    public function testReadAllClearsEveryUnreadRow(): void
    {
        $this->requireHttp();
        self::seedProbes(2, 2);
        self::assertGreaterThanOrEqual(2, self::unreadForStaff());

        $res = self::http('GET', '/admin/notifications.php?action=readall');
        self::assertSame(302, $res['status'], 'readall must redirect back');

        self::assertSame(0, self::unreadForStaff(), 'readall must clear every unread row for the user');
    }

    #[Group('integration')]
    public function testUnreadOnlyFilterShowsOnlyUnreadRows(): void
    {
        $this->requireHttp();
        self::seedProbes(5, 2); // 3 read + 2 unread

        $body = self::http('GET', '/admin/notifications.php?filter=unread')['body'];
        self::assertStringContainsString('value="unread" checked', $body, 'the toggle must reflect the active filter');

        // Scope to the archive card: the sidebar dropdown always lists the
        // 8 latest regardless of filter, so whole-body assertions would be
        // meaningless here.
        $posDropdown = strpos($body, 'dropdown-menu');
        $posCard     = strpos($body, 'card shadow-sm');
        self::assertNotSame(false, $posCard, 'archive card must render');
        $card = $posDropdown !== false && $posDropdown < $posCard
            ? substr($body, $posCard)
            : $body;
        self::assertStringContainsString('probe notification 4', $card, 'unread rows must render');
        self::assertStringContainsString('probe notification 5', $card, 'unread rows must render');
        self::assertStringNotContainsString('probe notification 1', $card, 'read rows must be filtered out of the card');
        self::assertStringNotContainsString('probe notification 3', $card, 'read rows must be filtered out of the card');
    }

    #[Group('integration')]
    public function testSearchMatchesMessageSubstringAndEscapesLikeMeta(): void
    {
        $this->requireHttp();
        global $db;
        // One row whose message contains literal LIKE metacharacters.
        $stmt = $db->prepare(
            'INSERT INTO tblnotifications (user_id, booking_id, message, link, is_read) '
            . 'VALUES (:uid, 0, :msg, "dashboard.php", 0)'
        );
        $stmt->execute([':uid' => self::$staffId, ':msg' => '[page-test] deposit 100% confirmed _today_']);
        self::$probeIds[] = (int) $db->the_insert_id();

        // Substring match.
        $page = self::http('GET', '/admin/notifications.php?q=' . urlencode('100% confirmed'));
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('deposit 100% confirmed _today_', $page['body'], 'search must match the literal substring');

        // "%" in the query is a literal percent, not a wildcard: it must NOT match everything.
        $page = self::http('GET', '/admin/notifications.php?q=' . urlencode('[page-test] probe'));
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('probe notification', $page['body']);

        // XSS attempt is escaped in the re-filled input.
        $page = self::http('GET', '/admin/notifications.php?q=' . urlencode('<script>alert(1)</script>'));
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $page['body'], 'search term must be escaped on render');
        self::assertStringContainsString('&lt;script&gt;', $page['body']);
    }

    #[Group('integration')]
    public function testPaginationLinksCarryActiveFilters(): void
    {
        $this->requireHttp();
        // Ensure >25 UNREAD rows so the filtered view spans two pages.
        global $db;
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications WHERE user_id = :uid AND is_read = 0',
            [':uid' => self::$staffId]
        );
        self::seedProbes(max(0, 30 - $count), max(0, 30 - $count)); // top up to 30 unread
        self::assertGreaterThanOrEqual(30, self::unreadForStaff());

        $page1 = self::http('GET', '/admin/notifications.php?filter=unread');
        self::assertSame(200, $page1['status']);
        self::assertMatchesRegularExpression('/Page 1 of 2 \(\d+ total\)/', $page1['body'], '30+ unread rows must paginate');
        self::assertStringContainsString('notifications.php?page=2&filter=unread', $page1['body'], 'older link must carry the filter');

        $page2 = self::http('GET', '/admin/notifications.php?page=2&filter=unread');
        self::assertSame(200, $page2['status']);
        self::assertStringContainsString('Page 2 of 2', $page2['body']);
    }

    /** Re-establish the staff session (after the client-session gate test). */
    private static function loginStaff(): void
    {
        self::$jar = [];
        $page = self::http('GET', '/login.php');
        if ($page['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => self::STAFF_EMAIL,
            'input_password' => self::STAFF_PASS,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }
}
