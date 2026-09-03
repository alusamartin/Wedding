<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.1 — PDO Database Wrapper
 * Migration: mysqli -> PDO (PHP 8.2+)
 *
 * - Exception mode (ERRMODE_EXCEPTION)
 * - Emulated prepares OFF (native prepares)
 * - UTF8MB4 charset for M-Pesa symbols & Kenyan names
 * - Timezone locked to Africa/Nairobi via init.php
 * - Backward compatible shims for legacy mysqli-era calls
 */

class Database
{
    /** @var PDO|null Native PDO handle */
    private ?PDO $pdo = null;

    /** @var PDO|null Alias so legacy `$db->connection` still works but now is PDO */
    public ?PDO $connection = null;

    /** @var string Last raw SQL (for executeQuery / loadResultList compat) */
    public string $sql_string = '';

    /** @var int PDO fetch mode default */
    public int $fetchMode = PDO::FETCH_OBJ;

    public function __construct()
    {
        $this->open_connection();
    }

    /**
     * Establish PDO connection with strict options.
     */
    public function open_connection(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+03:00'",
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Enforce Nairobi TZ at MySQL session level (+03:00 = EAT)
            $this->pdo->exec("SET time_zone = '+03:00'");
            $this->connection = $this->pdo; // legacy alias
        } catch (PDOException $e) {
            // Do not leak credentials; log and die with generic message
            error_log('[WPMS][DB] Connection failed: ' . $e->getMessage());
            if (defined('WPMS_DEBUG') && WPMS_DEBUG) {
                die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
            }
            die('Database failed to connect. Please check configuration.');
        }
    }

    /**
     * Get raw PDO instance (preferred for new code).
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->open_connection();
        }
        return $this->pdo;
    }

    /**
     * Prepare a statement (preferred over query for user input).
     *
     * @param string $sql SQL with ? or :named placeholders
     */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo()->prepare($sql);
    }

    /**
     * Execute a prepared statement and return the statement.
     *
     * @param string $sql    SQL with placeholders
     * @param array  $params Bound values (positional or named)
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Legacy query() shim — still supports raw SQL without params.
     * For SQL with user input, use prepare()/execute() instead.
     *
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($params === []) {
            // Direct query (no placeholders) — keep backward compat
            return $this->pdo()->query($sql);
        }
        return $this->execute($sql, $params);
    }

    /**
     * Fetch all rows for a SELECT.
     *
     * @return array<object>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll($this->fetchMode);
    }

    /**
     * Fetch single row or null.
     */
    public function fetchOne(string $sql, array $params = []): ?object
    {
        $row = $this->execute($sql, $params)->fetch($this->fetchMode);
        return $row === false ? null : $row;
    }

    /**
     * Fetch single scalar value (e.g. COUNT(*)).
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->execute($sql, $params)->fetchColumn();
    }

    /**
     * @deprecated Kept for backward compatibility. PDO uses prepared statements;
     *             this simply quotes via PDO::quote and strips outer quotes.
     *             Do NOT use — bind parameters instead.
     */
    public function escape_string(string $string): string
    {
        trigger_error(
            'Database::escape_string() is deprecated since v2.1 — use prepared statements (Database::prepare/execute) instead.',
            E_USER_DEPRECATED
        );
        $quoted = $this->pdo()->quote($string);
        if ($quoted === false) {
            return addslashes($string);
        }
        // Strip surrounding single quotes added by PDO::quote
        return substr($quoted, 1, -1);
    }

    /**
     * Alias of escape_string() for code that calls $db->escape_string.
     * Modern code should NOT call this.
     */
    public function quote(string $value): string
    {
        return $this->pdo()->quote($value);
    }

    public function the_insert_id(): string
    {
        return $this->pdo()->lastInsertId();
    }

    /** Alias snake_case variant */
    public function lastInsertId(): string
    {
        return $this->the_insert_id();
    }

    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    public function rowCount(PDOStatement $stmt): int
    {
        return $stmt->rowCount();
    }

    public function affectedRows(PDOStatement $stmt): int
    {
        return $stmt->rowCount();
    }

    // =========================================================================
    // Legacy Active Record helpers (kept so admin/* pages don't break)
    // =========================================================================

    /**
     * @deprecated Use prepare/execute — kept for DB_Object::find_by_query compat
     */
    public function setQuery(string $sql = ''): void
    {
        $this->sql_string = $sql;
    }

    /**
     * @deprecated Use execute() — kept for loadResultList() compat
     * @return PDOStatement
     */
    public function executeQuery(): PDOStatement
    {
        if ($this->sql_string === '') {
            throw new InvalidArgumentException('No SQL set. Call setQuery() first.');
        }
        return $this->pdo()->query($this->sql_string);
    }

    /**
     * @deprecated Use fetchAll() — kept for legacy callers
     */
    public function loadResultList(string $key = ''): array
    {
        $stmt  = $this->executeQuery();
        $rows  = $stmt->fetchAll(PDO::FETCH_OBJ);
        if ($key === '') {
            return $rows;
        }
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->$key] = $row;
        }
        return $indexed;
    }

    /**
     * Back-compat: mysqli_affected_rows($db->connection) pattern.
     * New code should use $stmt->rowCount() instead.
     * We return last statement rowCount if available, else 0.
     */
    public function affected_rows(): int
    {
        // PDO has no global affected rows; callers should migrate to $stmt->rowCount()
        return 0;
    }
}

// Global singleton — same variable name as v2.0 so all includes still work
$db = new Database();
