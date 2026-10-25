<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stage 2-4 regression — no state-changing delete may run on GET.
 *
 * All six delete endpoints (guest, photo, blog article, user, package,
 * feature) were converted from unguarded GET links to POST + CSRF-token
 * forms. These tests lock that in two ways:
 *
 *  1. Content contract (pure): each endpoint file must gate on
 *     REQUEST_METHOD === 'POST' plus verify_csrf() before touching data.
 *  2. Behaviour over HTTP ('integration' group): against a real php -S
 *     server with a staff session, a GET request bounces with the row
 *     intact, a POST without the token bounces with the row intact, and a
 *     POST carrying the session token deletes it.
 *
 * The HTTP half skips cleanly when php -S cannot boot or the seeded staff
 * login is unavailable. Fixture rows are removed afterwards.
 */
final class DeleteEndpointGuardTest extends TestCase
{
    /** @var string[] Endpoints that must be POST+CSRF-gated */
    public const ENDPOINTS = [
        'guest_delete.php',
        'photo_delete.php',
        'blog_events_delete.php',
        'users_delete.php',
        'package_delete.php',
        'feature_delete.php',
    ];

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static string $csrf = '';
    private static $serverProc = null;
    private static array $serverPipes = [];

    // -----------------------------------------------------------------
    // Content contract (pure — no server or DB needed)
    // -----------------------------------------------------------------

    #[DataProvider('endpoints')]
    public function testEndpointGuardsAgainstGetAndForwardsCsrf(string $endpoint): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/' . $endpoint);

        self::assertStringContainsString(
            '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'',
            $src,
            $endpoint . ' must reject anything that is not a POST request'
        );
        self::assertStringContainsString(
            'verify_csrf',
            $src,
            $endpoint . ' must require a valid CSRF token'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpoints(): array
    {
        $cases = [];
        foreach (self::ENDPOINTS as $endpoint) {
            $cases[$endpoint] = [$endpoint];
        }
        return $cases;
    }

    // -----------------------------------------------------------------
    // Behaviour over HTTP (integration)
    // -----------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        self::bootDatabase();
        self::bootServer();

        if (self::$base === null) {
            return; // tests will skip
        }

        // 1) Staff login via the real unified login flow (CSRF + cookie jar).
        $login = self::http('GET', '/login.php');
        if ($login['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $login['body'], $m)) {
            self::$base = null;
            return;
        }
        $token = html_entity_decode($m[1], ENT_QUOTES);
        $post = self::http('POST', '/login.php', [
            'input_email'    => 'wafula@wpms.co.ke',
            'input_password' => 'Admin@2024!',
            'login'          => '1',
            '_token'         => $token,
        ]);
        if ($post['status'] !== 302 || stripos($post['location'] ?? '', 'dashboard.php') === false) {
            self::$base = null; // seeded staff credentials unavailable — skip
            return;
        }

        // 2) A valid session token for the delete forms (stable per session).
        $page = self::http('GET', '/admin/users.php');
        if ($page['status'] === 200 && preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m2)) {
            self::$csrf = html_entity_decode($m2[1], ENT_QUOTES);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    private static function bootDatabase(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_http_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_http_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 21000 + random_int(0, 8999);
            // Pick a port that is not already accepting connections.
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }

            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            $pipes = [];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root);
            if (!is_resource(self::$serverProc)) {
                continue;
            }

            // Poll until the server answers.
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
            self::stopServer();
        }
        self::$base = null; // could not boot php -S — skip HTTP tests
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    /**
     * Minimal HTTP client (no curl dependency): manual cookie jar,
     * redirects are NOT followed.
     *
     * @param array<string, string> $post
     * @return array{status: int, location: string, body: string}
     */
    private static function http(string $method, string $path, array $post = []): array
    {
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = "Cookie: " . implode('; ', $parts) . "\r\n";
        }

        $body = $post === [] ? '' : http_build_query($post);
        // NOTE: never put a request line in 'header' — the http stream wrapper
        // generates its own from the URL + method. A duplicate request line
        // makes php -S reject every request that carries a query string.
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
        $headers = $http_response_header ?? [];

        $status = 0;
        $location = '';
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            } elseif (stripos($header, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($header, 11), 2)[0];
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
        if (self::$base === null || self::$db === null || self::$csrf === '') {
            self::markTestSkipped('php -S server / seeded staff login unavailable — HTTP delete tests skipped');
        }
    }

    #[Group('integration')]
    public function testGetRequestNeverDeletesAUserAndTokenPostDoes(): void
    {
        $this->requireHttp();

        $email = 'delete-guard-' . bin2hex(random_bytes(4)) . '@example.com';
        self::$db->execute(
            'INSERT INTO tblusers (firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created)
             VALUES (:fn, :ln, :g, :u, :p, :e, :d, :a, :l, :pic, NOW())',
            [':fn' => 'Delete', ':ln' => 'Guard', ':g' => 'm', ':u' => 'deleteguard' . bin2hex(random_bytes(3)),
             ':p' => password_hash('Test@1234', PASSWORD_DEFAULT), ':e' => $email, ':d' => '1',
             ':a' => 'Nairobi', ':l' => '1', ':pic' => '']
        );
        $userId = (int) self::$db->the_insert_id();

        try {
            $exists = fn (): int => (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblusers WHERE id = :id', [':id' => $userId]);

            self::assertSame(1, $exists());
            $get = self::http('GET', '/admin/users_delete.php?id=' . $userId);
            self::assertSame(302, $get['status'], 'GET delete must bounce');
            self::assertStringContainsString('users.php', $get['location'] ?? '', 'GET delete must redirect to the list page');
            self::assertSame(1, $exists(), 'GET delete must leave the row intact');

            $noToken = self::http('POST', '/admin/users_delete.php?id=' . $userId, ['id' => (string) $userId]);
            self::assertSame(302, $noToken['status'], 'Tokenless POST must bounce');
            self::assertSame(1, $exists(), 'Tokenless POST must leave the row intact');

            $withToken = self::http('POST', '/admin/users_delete.php?id=' . $userId, ['id' => (string) $userId, '_token' => self::$csrf]);
            self::assertSame(302, $withToken['status']);
            self::assertSame(0, $exists(), 'POST with a valid CSRF token must delete the row');
        } finally {
            self::$db->execute('DELETE FROM tblusers WHERE id = :id', [':id' => $userId]);
        }
    }

    #[Group('integration')]
    public function testGetRequestNeverDeletesAFeatureAndTokenPostDoes(): void
    {
        $this->requireHttp();

        $title = 'delete-guard-feature-' . bin2hex(random_bytes(4));
        self::$db->execute(
            'INSERT INTO tbl_features (category_id, title, description) VALUES (:c, :t, :d)',
            [':c' => 1, ':t' => $title, ':d' => 'guard test']
        );
        $featureId = (int) self::$db->the_insert_id();

        try {
            $exists = fn (): int => (int) self::$db->fetchValue('SELECT COUNT(*) FROM tbl_features WHERE feature_id = :id', [':id' => $featureId]);

            self::assertSame(1, $exists());
            $get = self::http('GET', '/admin/feature_delete.php?id=' . $featureId);
            self::assertSame(302, $get['status'], 'GET delete must bounce');
            self::assertSame(1, $exists(), 'GET delete must leave the row intact');

            $noToken = self::http('POST', '/admin/feature_delete.php?id=' . $featureId);
            self::assertSame(302, $noToken['status'], 'Tokenless POST must bounce');
            self::assertSame(1, $exists(), 'Tokenless POST must leave the row intact');

            $withToken = self::http('POST', '/admin/feature_delete.php?id=' . $featureId, ['_token' => self::$csrf]);
            self::assertSame(302, $withToken['status']);
            self::assertSame(0, $exists(), 'POST with a valid CSRF token must delete the row');
        } finally {
            self::$db->execute('DELETE FROM tbl_features WHERE feature_id = :id', [':id' => $featureId]);
        }
    }

    /**
     * The remaining endpoints share the same guard; prove a GET (even as a
     * signed-in staff member) bounces and leaves a real seeded row intact.
     */
    #[Group('integration')]
    #[DataProvider('getOnlyEndpoints')]
    public function testGetBouncesWithoutDeletingSeedRows(string $endpoint, string $table, string $idColumn, string $query): void
    {
        $this->requireHttp();

        $rowId = (int) self::$db->fetchValue('SELECT ' . $idColumn . ' FROM ' . $table . ' ORDER BY ' . $idColumn . ' DESC LIMIT 1');
        if ($rowId <= 0) {
            self::markTestSkipped('No seeded row in ' . $table . ' to probe');
        }

        $exists = fn (): int => (int) self::$db->fetchValue('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $idColumn . ' = :id', [':id' => $rowId]);

        $url = '/admin/' . $endpoint . '?' . $query . '&id=' . $rowId;
        $res = self::http('GET', $url);
        self::assertSame(302, $res['status'], 'GET ' . $endpoint . ' must bounce');
        self::assertSame(1, $exists(), 'GET ' . $endpoint . ' must leave the seeded row intact');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function getOnlyEndpoints(): array
    {
        return [
            'guest_delete'  => ['guest_delete.php', 'tblguest', 'id', 'booking_id=200&user_id=200'],
            'photo_delete'  => ['photo_delete.php', 'tblgallery', 'id', ''],
            'blog_delete'   => ['blog_events_delete.php', 'tblpostwedding', 'id', ''],
            'package_delete' => ['package_delete.php', 'tblweddingcategories', 'id', ''],
        ];
    }
}
