<?php
/**
 * WPMS Kenya — database configuration.
 *
 * Credentials are resolved from environment variables so real secrets never
 * have to live in (or be committed with) this file. The fallbacks keep the
 * stock XAMPP dev setup working out of the box.
 *
 * Environment variables (set all four outside of dev):
 *   WPMS_DB_HOST  default: localhost
 *   WPMS_DB_USER  default: root
 *   WPMS_DB_PASS  default: (empty)
 *   WPMS_DB_NAME  default: dbwedding
 *
 * Examples:
 *   - CLI / cron:    export WPMS_DB_PASS='secret'
 *   - Apache vhost:  SetEnv WPMS_DB_PASS "secret"   (or in .htaccess)
 *   - Docker:        environment: - WPMS_DB_PASS=secret
 */

/**
 * Load a simple KEY=VALUE .env file into the process environment.
 *
 * Shared hosting (cPanel) has no reliable per-app env-var UI, so secrets are
 * kept in a `.env` file placed ABOVE the web root (never web-readable) or in
 * the project root (never commit it). Real environment variables always win
 * over file values, so a `SetEnv` in .htaccess or a crontab export overrides
 * the file — same precedence as before this loader existed.
 */
function wpms_load_env_file(string $path): void
{
    static $loaded = [];
    $real = realpath($path);
    if ($real === false || isset($loaded[$real]) || !is_file($real) || !is_readable($real)) {
        return;
    }
    $loaded[$real] = true;
    foreach (file($real, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        $key = trim(substr($line, 0, strpos($line, '=')));
        $val = trim(substr($line, strpos($line, '=') + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key === '' || getenv($key) !== false) {
            continue; // malformed or already set in the real environment
        }
        putenv("$key=$val");
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}

/*
 * Load .env from the project root, then from its parent directory.
 * On cPanel the recommended layout uploads `.env` to the account HOME
 * (e.g. /home/user/.env) where it is unreachable from the web; the project
 * root copy is the fallback for CLI/dev use.
 */
$wpms_root = dirname(__DIR__);
wpms_load_env_file($wpms_root . '/.env');
wpms_load_env_file(dirname($wpms_root) . '/.env');
unset($wpms_root);

function wpms_db_env(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

define('DB_HOST', wpms_db_env('WPMS_DB_HOST', 'localhost'));
define('DB_USER', wpms_db_env('WPMS_DB_USER', 'root'));
define('DB_PASS', wpms_db_env('WPMS_DB_PASS', ''));
define('DB_NAME', wpms_db_env('WPMS_DB_NAME', 'dbwedding'));

/*
 * Optional READ REPLICA (v2.19) — where an AI client's READ traffic can go so an
 * assistant asking a hundred questions is not competing with the couple signing
 * in. Set WPMS_DB_REPLICA_HOST and the MCP layer's own reads use it
 * (Database::reader(), docs/MCP_PLAN.md §4); user, password and database name
 * default to the primary's, which is what a same-server second schema or a
 * read-only MySQL grant looks like.
 *
 * EMPTY HOST = NO REPLICA, and that is the normal state: XAMPP and most cPanel
 * plans have one database, every read stays on the primary, and nothing about
 * the behaviour changes. A configured replica that then fails to connect also
 * falls back to the primary rather than taking reads down with it (see
 * Database::open_connection()).
 *
 * The credentials here are an ASSISTANT's, not the application's: pair them with
 * migrations/v2.18_mcp_grants.sql's read user so a compromised endpoint cannot
 * write anything, whatever the code asks for.
 */
define('DB_REPLICA_HOST', wpms_db_env('WPMS_DB_REPLICA_HOST', ''));
define('DB_REPLICA_USER', wpms_db_env('WPMS_DB_REPLICA_USER', DB_USER));
define('DB_REPLICA_PASS', wpms_db_env('WPMS_DB_REPLICA_PASS', DB_PASS));
define('DB_REPLICA_NAME', wpms_db_env('WPMS_DB_REPLICA_NAME', DB_NAME));
