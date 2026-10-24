<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Welcome email for newly activated self-serve planners.
 *
 * Fired at the moment the trial goes live (verify_planner.php -> 
 * SignupGuard::send_welcome), through the shared tbl_mail_logs outbox, with
 * the trial end date and getting-started steps. Integration ('integration'
 * group) — live DB.
 *
 * Covered:
 *  1. Content + shape: end date, the three getting-started steps, real page
 *     links, and an outbox row that mail_retry.php can deliver
 *     (status 'pending' with attempts = 0 when the transport is off).
 *  2. Activation fires it: verify + activate_trial end-to-end over HTTP
 *     produces exactly one welcome row (not two, not zero).
 */
final class WelcomeEmailTest extends TestCase
{
    private const FIX_EMAIL  = 'probe.welcome.planner@example.com';
    private static ?PDO $pdo = null;
    private static int $staffId = 0;

    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/init.php';
        try {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            global $db;
            $db = new Database();
        } catch (Throwable $e) {
            self::$pdo = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
        self::cleanup();
    }

    private static function cleanup(): void
    {
        $p = self::$pdo;
        if ($p === null) {
            return;
        }
        $uid = (int) ($p->query("SELECT id FROM tblusers WHERE email = '" . self::FIX_EMAIL . "'")->fetchColumn() ?: 0);
        if ($uid > 0) {
            $p->exec('DELETE FROM tblsubscriptions WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblplanner_verifications WHERE user_id = ' . $uid);
            $p->exec('DELETE FROM tblusers WHERE id = ' . $uid);
            self::$staffId = 0;
        }
        // Welcome rows carry user_id = 0 (outbox convention) — key on the
        // probe address instead.
        $p->prepare('DELETE FROM tbl_mail_logs WHERE to_email = ?')->execute([self::FIX_EMAIL]);
    }

    private function requireDb(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('live DB unavailable — welcome email tests skipped');
        }
        self::cleanup();
        self::$staffId = 0;
    }

    // ------------------------------------------------------------------
    // 1. send_welcome directly
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testWelcomeRowContentAndRetryableShape(): void
    {
        $this->requireDb();
        putenv('WPMS_MAIL_ENABLED='); // transport off — the demo/default path

        SignupGuard::send_welcome(self::FIX_EMAIL, 'Asha', '29 Sep 2026');

        $row = self::$pdo->query(
            "SELECT * FROM tbl_mail_logs WHERE to_email = '" . self::FIX_EMAIL . "' AND subject LIKE 'Karibu!%'"
        )->fetch(PDO::FETCH_OBJ);
        $this->assertNotFalse($row, 'the welcome email must land in the outbox');
        $this->assertSame('pending', (string) $row->status, 'transport off => pending, not sent');
        $this->assertSame(0, (int) $row->attempts, 'attempts = 0 so mail_retry.php will pick it up');

        // Content: end date + all three getting-started steps with real pages.
        $this->assertStringContainsString('29 Sep 2026', (string) $row->body);
        $this->assertStringContainsString('client_add.php', (string) $row->body);
        $this->assertStringContainsString('vendors.php', (string) $row->body);
        $this->assertStringContainsString('subscription.php', (string) $row->body);
        $this->assertStringContainsString('guest', (string) $row->body);
    }

    // ------------------------------------------------------------------
    // 2. Activation fires it end-to-end over HTTP
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testActivationSendsExactlyOneWelcome(): void
    {
        $this->requireDb();
        putenv('WPMS_MAIL_ENABLED='); // outbox-only: assertable, no real mail
        putenv('WPMS_TRIAL_DAYS=14');
        if (self::bootServer() === null) {
            self::markTestSkipped('test HTTP server unavailable');
        }

        // Register through the real funnel.
        self::$jar = [];
        $page = self::http('GET', '/planner_register.php');
        $this->assertSame(200, $page['status']);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);
        $this->assertNotEmpty($m);
        $res = self::http('POST', '/planner_register.php', [
            'firstname'        => 'Asha',
            'lastname'         => 'Welcome',
            'email'            => self::FIX_EMAIL,
            'phone'            => '0722999001',
            'password'         => 'Sup3rSecret!',
            '_token'           => html_entity_decode($m[1], ENT_QUOTES),
            'register_planner' => '1',
        ]);
        $this->assertSame(302, $res['status']);
        self::$staffId = (int) self::$pdo->query(
            "SELECT id FROM tblusers WHERE email = '" . self::FIX_EMAIL . "'"
        )->fetchColumn();
        $this->assertGreaterThan(0, self::$staffId);

        // Pull the verification link from the outbox and click it.
        $body = (string) self::$pdo->query(
            "SELECT body FROM tbl_mail_logs WHERE to_email = '" . self::FIX_EMAIL . "' AND subject LIKE 'Verify%' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        preg_match('/\?token=([0-9a-f]{64})/', $body, $tm);
        $this->assertNotEmpty($tm, 'the verification email must carry the token link');
        $click = self::http('GET', '/verify_planner.php?token=' . $tm[1]);
        $this->assertSame(302, $click['status']);
        $this->assertSame('admin/subscription.php', $click['location']);

        // Exactly ONE welcome, with the actual trial end date.
        $welcomes = self::$pdo->query(
            "SELECT body FROM tbl_mail_logs WHERE to_email = '" . self::FIX_EMAIL . "' AND subject LIKE 'Karibu!%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $welcomes, 'activation must send exactly one welcome email');
        $this->assertStringContainsString(
            date('j M Y', strtotime('+14 days')),
            (string) $welcomes[0],
            'the welcome must carry the real trial end date'
        );
        $this->assertStringContainsString('LIVE', (string) $welcomes[0]);
    }

    // ------------------------------------------------------------------
    // HTTP harness (mirrors UnifiedLoginTest)
    // ------------------------------------------------------------------

    private static function bootServer(): ?string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        if (!function_exists('proc_open')) {
            return null;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_we_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_we_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port  = 34000 + random_int(0, 999);
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
                return self::$base;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        return null;
    }

    /**
     * @param array<string, string> $post
     * @return array{status:int, location:string, body:string}
     */
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
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

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
}
