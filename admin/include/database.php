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

    /** @var int PDO fetch mode default */
    public int $fetchMode = PDO::FETCH_OBJ;

    /**
     * Is THIS handle the optional read replica rather than the primary?
     *
     * Only ever true for a handle built by reader(); the global $db and every
     * write path are the primary, always.
     */
    private bool $reader = false;

    /**
     * @param bool $reader build the handle against the read replica
     *                     (WPMS_DB_REPLICA_*, when the operator configured one).
     *                     False — the default, and what `new Database()` means in
     *                     every page, script and test — is the primary.
     */
    public function __construct(bool $reader = false)
    {
        $this->reader = $reader && defined('DB_REPLICA_HOST') && DB_REPLICA_HOST !== '';
        $this->open_connection();
    }

    /** Is this the replica handle? (Nothing that must read its own write may use one.) */
    public function is_reader(): bool
    {
        return $this->reader;
    }

    /**
     * The read handle for work that may be served off a replica, or the primary.
     *
     * NULL means "no replica is configured, use $db" — which is the normal
     * single-database install. The distinction is the caller's, not this
     * class's: what may use a
     * replica is a READ whose answer tolerates replication lag (a guest list, a
     * budget position), and what may NEVER use one is anything that must see its
     * own write — an approval being consumed, an idempotency key being checked,
     * an audit row being written back to its caller. Those read the primary.
     */
    public static function reader(): ?Database
    {
        global $db;

        /*
         * The decision is cached per PRIMARY HANDLE, not per worker process.
         * PHP-FPM reuses a process for many requests, so a static "no replica"
         * (or "replica is down") flag would poison every later request in that
         * worker: a replica that came back would never be retried. `$db` is
         * built fresh in database.php for each request, so object identity is
         * the request boundary — one probe per request, and a new primary means
         * probe again.
         */
        static $reader = null;
        static $owner  = null;

        if ($db instanceof self) {
            if ($db->is_reader()) {
                // Inside with_read_handle(): the global IS the read handle.
                return $db;
            }
            if ($owner === $db) {
                return $reader;
            }
            $owner  = $db;
            $reader = null;
        }

        if (!defined('DB_REPLICA_HOST') || DB_REPLICA_HOST === '') {
            return null; // no replica configured — the normal state
        }
        $candidate = new self(true);
        if (!$candidate->is_reader()) {
            /*
             * The constructor already fell back to the primary (with a log
             * line), so a second primary connection would be pure waste: a
             * DOWN replica means the application's own $db IS the read handle.
             * Null is cached for this request; the next request probes again.
             */
            return null;
        }
        return $reader = $candidate;
    }

    /**
     * The handle a read tool should USE: the replica when one is configured,
     * otherwise the application's own $db.
     */
    public static function read_handle(): Database
    {
        global $db;
        if (!($db instanceof self)) {
            // A caller that runs before the global handle exists (a CLI probe,
            // a unit test loading this file directly) still gets a handle.
            $db = new self();
        }
        return self::reader() ?? $db;
    }

    /**
     * Run READ-ONLY work with `$db` pointing at the read handle, then put the
     * primary back.
     *
     * The MCP read catalogue is written against the models, and the models read
     * `global $db` — so a replica that only served the handful of handlers with
     * hand-written SQL would leave most of an assistant's questions on the
     * primary, which is exactly the traffic this is meant to move. Swapping the
     * global for the duration of one handler reaches every model without
     * threading a second handle through dozens of signatures.
     *
     * THE SCOPE IS THE POINT. Only a handler that cannot write may run inside
     * this: McpTools::call() delegates every write to McpWrites BEFORE it gets
     * here, so nothing inside can be a write by construction. The `finally`
     * restores the primary even when the handler throws a business refusal, so
     * the audit row, the token's `last_used_at` and everything after the tool
     * answer keep going to the primary. A caller that does not know its work is
     * read-only must not use this.
     *
     * When no replica is configured (or the configured one was unreachable and
     * degraded to the primary) the read handle IS the primary, and this runs the
     * work exactly as it would have without the feature.
     *
     * @template T
     * @param callable(Database):T $work
     * @return T
     */
    public static function with_read_handle(callable $work): mixed
    {
        global $db;
        $reader = self::read_handle();
        if ($reader === $db) {
            return $work($reader);
        }

        $primary = $db;
        $db = $reader;
        try {
            return $work($reader);
        } finally {
            $db = $primary;
        }
    }

    /**
     * Establish PDO connection with strict options.
     */
    public function open_connection(): void
    {
        $host = $this->reader ? DB_REPLICA_HOST : DB_HOST;
        $name = $this->reader ? DB_REPLICA_NAME : DB_NAME;
        $user = $this->reader ? DB_REPLICA_USER : DB_USER;
        $pass = $this->reader ? DB_REPLICA_PASS : DB_PASS;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $host,
            $name
        );

        /*
         * PHP 8.5 deprecates PDO::MYSQL_ATTR_INIT_COMMAND in favour of the
         * Pdo\Mysql::ATTR_INIT_COMMAND class constant (8.4+). Resolve the name
         * first so the same file runs on 8.2 — where the class does not exist —
         * without either branch emitting a deprecation. constant() is used for
         * the class constant because a direct fetch is a compile-time constant
         * expression this file cannot guard by version.
         */
        $initCommand = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
            ? (int) constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
            : (int) constant('PDO::MYSQL_ATTR_INIT_COMMAND');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            $initCommand                 => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+03:00'",
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            // Enforce Nairobi TZ at MySQL session level (+03:00 = EAT)
            $this->pdo->exec("SET time_zone = '+03:00'");
            $this->connection = $this->pdo; // legacy alias
        } catch (PDOException $e) {
            /*
             * A REPLICA THAT IS DOWN MUST NOT TAKE READS DOWN. This is an
             * optimisation, not a dependency: the handle degrades to the primary
             * and says so in the log, so a misconfigured replica costs the
             * operator a log line rather than a broken assistant.
             */
            if ($this->reader) {
                error_log('[WPMS][DB] Read replica unreachable, falling back to the primary: ' . $e->getMessage());
                $this->reader = false;
                $this->open_connection();
                return;
            }
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
     * Legacy query() shim — an alias of execute(), and it ALWAYS prepares.
     *
     * This is the last piece of the mysqli-era API, and the reason it is written
     * this way rather than kept as `PDO::query($sql)` is that "no placeholders"
     * and "safe" are not the same statement. A value interpolated into an
     * otherwise constant SQL string executes exactly the same way as one bound
     * to a placeholder, and NOTHING in the calling code can tell the difference —
     * so a call that never went through prepare() was a route to unbound SQL
     * that no audit could catch by reading the SQL. There is no such route now:
     * every call into this class that runs a statement goes prepare() + execute(),
     * placeholders or none.
     *
     * DEPRECATED — call execute()/fetchOne()/fetchAll()/fetchValue() directly.
     * scripts/audit_unbound_sql.php fails the build on any call to this method
     * (or to executeQuery/setQuery/loadResultList/escape_string), so the door is
     * shut statically; this body keeps an uncatalogued runtime caller working
     * through the prepared path rather than breaking it.
     *
     * @param string $sql    SQL, with or without placeholders
     * @param array  $params Bound values (named or positional)
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
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

    /*
     * setQuery() / executeQuery() / loadResultList() used to live here: the
     * mysqli-era "set the SQL, then run it" pair, which executed the stored
     * string with `PDO::query()` — never prepared. They had no callers left in
     * the project (measured, not assumed: scripts/audit_unbound_sql.php reports
     * zero, and the survey that found the last shim callers found these four
     * names only inside this file), so they are gone rather than reimplemented:
     * a method that cannot exist cannot be called unprepared. The same reasoning
     * retired the `$sql_string` property that carried the statement between them.
     */

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
