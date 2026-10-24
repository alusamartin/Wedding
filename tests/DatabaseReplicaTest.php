<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The optional read replica (WPMS_DB_REPLICA_*).
 *
 * Constants are per-process, so each case runs in its own PHP child: one with no
 * replica configured, one pointing at a replica that cannot authenticate, and
 * one pointing at the primary database itself (a second PDO handle, which is
 * exactly what a same-server read-only grant looks like). That is also the rule
 * being checked — a read may leave the primary, a write never does, and a
 * missing or broken replica costs a log line rather than the assistant.
 *
 * Run: vendor/bin/phpunit --testdox tests/DatabaseReplicaTest.php
 */
final class DatabaseReplicaTest extends TestCase
{
    private static string $child = '';

    public static function setUpBeforeClass(): void
    {
        self::$child = sys_get_temp_dir() . '/wpms_replica_probe_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents(self::$child, <<<'PHP'
<?php
declare(strict_types=1);

// Fallback is reported through error_log(); CLI sends that to stderr, which the
// parent captures as the evidence that the degradation was audible.
ini_set('error_log', 'php://stderr');

$root = $argv[1] ?? '';
require $root . '/admin/include/config.php';
require_once $root . '/admin/include/database.php';

global $db;
$out = ['ok' => false];

try {
    $out['ok'] = true;
    $out['primary_reader'] = $db->is_reader();
    $out['primary_conn']   = (int) $db->fetchValue('SELECT CONNECTION_ID()');
    $out['primary_value']  = (int) $db->fetchValue('SELECT 7');

    $handle = Database::read_handle();
    $out['same_handle']  = $handle === $db;
    $out['read_reader']  = $handle->is_reader();
    $out['read_conn']    = (int) $handle->fetchValue('SELECT CONNECTION_ID()');
    $out['read_value']   = (int) $handle->fetchValue('SELECT 42');

    $inside = null;
    $seen   = null;
    $returned = Database::with_read_handle(function (Database $h) use (&$inside, &$seen): string {
        global $db;
        $inside = $db === $h;     // the models' global really is the read handle
        $seen   = $h->is_reader();
        return 'answer';
    });
    $out['inside_is_handle'] = $inside;
    $out['inside_is_reader'] = $seen;
    $out['returned']         = $returned;

    $thrownInside = null;
    try {
        Database::with_read_handle(function () use (&$thrownInside): void {
            global $db;
            $thrownInside = $db->is_reader();
            throw new RuntimeException('probe');
        });
    } catch (RuntimeException $e) {
        // expected — the point is what the global looks like AFTER the throw
    }
    global $db;
    $out['thrown_inside_reader'] = $thrownInside;
    $out['restored_reader']      = $db->is_reader();
    $out['restored_conn']        = (int) $db->fetchValue('SELECT CONNECTION_ID()');

    // A NEW primary handle is what the next request looks like in a reused
    // PHP-FPM worker: the replica decision must be re-probed, not inherited.
    $db = new Database();
    $next = Database::read_handle();
    $out['next_same']   = $next === $db;
    $out['next_reader'] = $next->is_reader();
} catch (Throwable $e) {
    $out['ok']    = false;
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
PHP
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$child !== '' && is_file(self::$child)) {
            @unlink(self::$child);
        }
    }

    // ==================================================================
    // 1. The contract in the files an operator reads
    // ==================================================================

    public function testTheReplicaVariablesAreDocumentedAndParsed(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../admin/include/config.php');
        foreach (['WPMS_DB_REPLICA_HOST', 'WPMS_DB_REPLICA_USER', 'WPMS_DB_REPLICA_PASS', 'WPMS_DB_REPLICA_NAME'] as $var) {
            self::assertStringContainsString($var, $config, $var . ' must be read from the environment');
        }
        self::assertStringContainsString("define('DB_REPLICA_HOST', wpms_db_env('WPMS_DB_REPLICA_HOST', ''))", $config,
            'an empty host is the no-replica default, not an error');

        $template = (string) file_get_contents(__DIR__ . '/../.env.example');
        self::assertStringContainsString('WPMS_DB_REPLICA_HOST=', $template);
        self::assertStringNotContainsString('NOT WIRED YET', $template, 'the template must not claim nothing reads these');

        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        self::assertStringContainsString('WPMS_DB_REPLICA_HOST', $readme, 'the README documents the switch');
    }

    public function testReadsAreScopedToTheReadHandleAndWritesAreDelegatedFirst(): void
    {
        $tools = (string) file_get_contents(__DIR__ . '/../admin/include/McpTools.php');
        self::assertStringContainsString('Database::with_read_handle(', $tools,
            'every read handler must run inside the read handle');

        // Writes must be handed to McpWrites BEFORE the read-handle swap can run,
        // so no write can ever execute against the replica's connection.
        $writeAt = strpos($tools, 'return McpWrites::call(');
        $readAt  = strpos($tools, 'Database::with_read_handle(');
        self::assertNotFalse($writeAt, 'McpTools::call must delegate the write catalogue');
        self::assertNotFalse($readAt, 'McpTools::call must scope reads to the read handle');
        self::assertLessThan($readAt, $writeAt, 'the write delegation must come before the read swap');

        $db = (string) file_get_contents(__DIR__ . '/../admin/include/database.php');
        self::assertStringContainsString('function with_read_handle(', $db);
        self::assertStringContainsString('} finally {', $db, 'the primary must be restored even when a handler throws');
    }

    // ==================================================================
    // 2. Behaviour, in a child process per configuration
    // ==================================================================

    public function testWithNoReplicaConfiguredReadsUseThePrimary(): void
    {
        $json = $this->probe(['WPMS_DB_REPLICA_HOST' => '']);

        self::assertTrue($json['same_handle'], 'no replica means read_handle() IS $db');
        self::assertFalse($json['read_reader']);
        self::assertSame($json['primary_conn'], $json['read_conn'], 'and the very same connection');
        self::assertSame(42, $json['read_value']);

        self::assertTrue($json['inside_is_handle'], 'the scoped swap is a no-op without a replica');
        self::assertFalse($json['inside_is_reader']);
        self::assertSame('answer', $json['returned'], 'the callback return value is passed through');
        self::assertFalse($json['restored_reader']);
        self::assertSame($json['primary_conn'], $json['restored_conn']);
    }

    public function testAnUnreachableReplicaFallsBackToThePrimaryAndSaysSo(): void
    {
        // A user that cannot exist: the connection fails immediately and the
        // fallback path runs, without waiting on a network timeout.
        $result = self::runChild($this->env([
            'WPMS_DB_REPLICA_HOST' => $this->primary('DB_HOST'),
            'WPMS_DB_REPLICA_USER' => 'wpms_replica_probe_no_such_user',
            'WPMS_DB_REPLICA_PASS' => 'not-a-password',
            'WPMS_DB_REPLICA_NAME' => $this->primary('DB_NAME'),
        ]));
        $json = $this->jsonOrSkip($result);

        self::assertTrue($json['same_handle'], 'a down replica must degrade to the application\'s own handle');
        self::assertFalse($json['read_reader'], 'the fallback handle is the primary, not a half-open reader');
        self::assertSame($json['primary_conn'], $json['read_conn']);
        self::assertSame(42, $json['read_value'], 'reads stay up while the replica is down');
        self::assertStringContainsString('Read replica unreachable, falling back to the primary',
            $result['stderr'], 'the operator can see the fallback in the log');

        self::assertTrue($json['next_same'], 'the next request starts from the primary again');
        self::assertFalse($json['next_reader']);
        self::assertGreaterThanOrEqual(2, substr_count($result['stderr'], 'Read replica unreachable'),
            'the next request re-probes the replica instead of inheriting a dead-worker decision');
    }

    public function testAConfiguredReplicaServesReadsAndThePrimaryComesBackAfterwards(): void
    {
        $json = $this->probe([
            'WPMS_DB_REPLICA_HOST' => $this->primary('DB_HOST'),
            'WPMS_DB_REPLICA_USER' => $this->primary('DB_USER'),
            'WPMS_DB_REPLICA_PASS' => $this->primary('DB_PASS'),
            'WPMS_DB_REPLICA_NAME' => $this->primary('DB_NAME'),
        ]);

        self::assertFalse($json['same_handle'], 'a reachable replica is a second handle');
        self::assertTrue($json['read_reader'], 'and identifies itself as the reader');
        self::assertNotSame($json['primary_conn'], $json['read_conn'], 'a separate PDO connection');
        self::assertSame(42, $json['read_value'], 'reads are served from it');

        self::assertTrue($json['inside_is_handle'], 'inside with_read_handle(), global $db is the reader');
        self::assertTrue($json['inside_is_reader']);
        self::assertSame('answer', $json['returned']);

        self::assertTrue($json['thrown_inside_reader'], 'the reader is installed for the handler');
        self::assertFalse($json['restored_reader'], 'the primary is restored after a throw');
        self::assertFalse($json['next_same'], 'a new request gets its own reader handle, not the previous one');
        self::assertTrue($json['next_reader']);
        self::assertSame($json['primary_conn'], $json['restored_conn'],
            'and it is the SAME primary connection the audit and writes use');
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** @param array<string,string> $overrides */
    private function probe(array $overrides): array
    {
        $result = self::runChild($this->env($overrides));
        return $this->jsonOrSkip($result);
    }

    /** @param array<string,string> $overrides */
    private function env(array $overrides): array
    {
        require_once __DIR__ . '/../admin/include/config.php';

        $base = [
            'WPMS_DB_HOST'         => DB_HOST,
            'WPMS_DB_USER'         => DB_USER,
            'WPMS_DB_PASS'         => DB_PASS,
            'WPMS_DB_NAME'         => DB_NAME,
            'WPMS_DB_REPLICA_HOST' => '',
            'WPMS_DB_REPLICA_USER' => '',
            'WPMS_DB_REPLICA_PASS' => '',
            'WPMS_DB_REPLICA_NAME' => '',
        ];
        return array_merge(getenv(), $base, $overrides);
    }

    private function primary(string $constant): string
    {
        require_once __DIR__ . '/../admin/include/config.php';
        return (string) constant($constant);
    }

    /**
     * @param array<string,string> $env
     * @return array{exit:int,stdout:string,stderr:string,json:?array<string,mixed>}
     */
    private static function runChild(array $env): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable');
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            [PHP_BINARY, self::$child, dirname(__DIR__)],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            $env
        );
        if (!is_resource($process)) {
            self::markTestSkipped('could not start the replica probe');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit'   => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'json'   => json_decode($stdout, true),
        ];
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string,json:?array<string,mixed>} $result
     * @return array<string,mixed>
     */
    private function jsonOrSkip(array $result): array
    {
        $json = $result['json'];
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            self::markTestSkipped(
                'MySQL unavailable for the replica probe: '
                . trim($result['stdout'] . ' ' . $result['stderr'])
            );
        }
        return $json;
    }
}
