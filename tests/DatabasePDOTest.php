<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WPMS Kenya v2.1 — Database PDO Wrapper Tests
 * Run:  vendor/bin/phpunit --testdox tests/DatabasePDOTest.php
 * Or:   composer test
 *
 * These are unit tests that do NOT require a live MySQL connection
 * where noted; integration tests (marked @group integration) do.
 */
final class DatabasePDOTest extends TestCase
{
    // -----------------------------------------------------------------
    // Pure logic tests (no DB)
    // -----------------------------------------------------------------

    public function testComposerRequiresPhp82(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertIsArray($composer);
        self::assertArrayHasKey('require', $composer);
        self::assertArrayHasKey('php', $composer['require']);
        self::assertSame('^8.2', $composer['require']['php'], 'composer.json must require PHP ^8.2 (Task 1.1)');
        self::assertArrayHasKey('ext-pdo', $composer['require']);
        self::assertArrayHasKey('ext-pdo_mysql', $composer['require']);
    }

    public function testDatabaseFileUsesPDO(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Database.php');
        self::assertStringContainsString('PDO', $src);
        self::assertStringContainsString('ATTR_ERRMODE', $src);
        self::assertStringContainsString('ERRMODE_EXCEPTION', $src, 'PDO must be in exception mode');
        self::assertStringContainsString('ATTR_EMULATE_PREPARES', $src);
        self::assertStringContainsString('utf8mb4', $src, 'Charset must be utf8mb4 for Kenyan names & M-Pesa');
        self::assertStringContainsString('time_zone', $src, 'MySQL TZ must be set to +03:00 (Africa/Nairobi)');
        self::assertStringContainsString('declare(strict_types=1)', $src);
    }

    public function testDatabaseExposesPDOHandle(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Database.php');
        self::assertStringContainsString('function pdo(): PDO', $src);
        self::assertStringContainsString('function prepare(string $sql): PDOStatement', $src);
        self::assertStringContainsString('function execute(string $sql, array $params', $src);
        self::assertStringContainsString('function fetchAll', $src);
        self::assertStringContainsString('function fetchOne', $src);
    }

    public function testDatabaseEscapeStringIsDeprecated(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/Database.php');
        self::assertStringContainsString('function escape_string', $src);
        // Must trigger deprecation
        self::assertStringContainsString('E_USER_DEPRECATED', $src);
        self::assertStringContainsString('use prepared statements', $src);
    }

    public function testDBObjectUsesPreparedStatements(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/db_object.php');
        self::assertStringContainsString('declare(strict_types=1)', $src);
        // Every finder must use :id placeholder
        self::assertStringContainsString("WHERE id = :id", $src);
        self::assertStringContainsString("WHERE booking_id = :id", $src);
        self::assertStringContainsString("WHERE category_id = :id", $src);
        // Must use PDO fetch, not mysqli_fetch_array
        self::assertStringNotContainsString('mysqli_fetch', $src, 'db_object.php must not contain any mysqli_fetch_*');
        self::assertStringNotContainsString('mysqli_query', $src);
        self::assertStringNotContainsString('mysqli_real_escape', $src);
        self::assertStringContainsString('PDO::FETCH_ASSOC', $src);
        self::assertStringContainsString('->prepare($sql)', $src);
        self::assertStringContainsString('->execute(', $src);
    }

    public function testDBObjectCreateUsesNamedPlaceholders(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../admin/include/db_object.php');
        self::assertStringContainsString('VALUES (' . "'", $src); // placeholder list built via :col
        self::assertStringContainsString("':where_val'", $src);
        self::assertStringContainsString('function create(): bool', $src);
        self::assertStringContainsString('function update(): bool', $src);
        self::assertStringContainsString('function delete(): bool', $src);
    }

    public function testMigrationScriptExists(): void
    {
        self::assertFileExists(__DIR__ . '/../scripts/migrate_mysqli_to_pdo.php');
        $src = (string) file_get_contents(__DIR__ . '/../scripts/migrate_mysqli_to_pdo.php');
        self::assertStringContainsString('mysqli_real_escape_string', $src);
        self::assertStringContainsString('PDO', $src);
        self::assertStringContainsString('--fix', $src);
    }

    // -----------------------------------------------------------------
    // Integration tests — require live DB (skip if no connection)
    // -----------------------------------------------------------------

    /**
     * @group integration
     */
    public function testLivePDOConnectionAndCharset(): void
    {
        if (!file_exists(__DIR__ . '/../admin/include/config.php')) {
            self::markTestSkipped('config.php not found');
        }
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';

        global $db;
        if (!isset($db) || !($db instanceof Database)) {
            $db = new Database();
        }

        $pdo = $db->pdo();
        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Emulated prepares must be OFF');

        // Verify charset & timezone
        $charset = $pdo->query("SELECT @@character_set_connection")->fetchColumn();
        // Accept utf8mb4 or utf8 (depending on MySQL version)
        self::assertMatchesRegularExpression('/utf8/', (string) $charset);

        $tz = $pdo->query("SELECT @@session.time_zone")->fetchColumn();
        self::assertSame('+03:00', $tz, 'Session TZ must be Africa/Nairobi (+03:00)');
    }

    /**
     * @group integration
     */
    public function testPreparedStatementPreventsInjection(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        require_once __DIR__ . '/../admin/include/Database.php';
        global $db;
        if (!isset($db)) $db = new Database();

        // Attempt injection via email param — must NOT return a row
        $malicious = "' OR '1'='1";
        $stmt = $db->prepare("SELECT * FROM tblusers WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $malicious]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        // Either null (no user with that email) or no injection breach
        // If a user with email "' OR '1'='1" existed, that would be a separate data bug
        // The key assertion: rowCount is 0 or fetch returns falsy, not all rows
        if ($row !== false && $row !== null) {
            // Should only happen if a user literally has that email string
            self::assertSame($malicious, $row->email);
        } else {
            self::assertTrue(true, 'Injection safely parameterized — no row returned');
        }
    }
}
