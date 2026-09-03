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

function wpms_db_env(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

define('DB_HOST', wpms_db_env('WPMS_DB_HOST', 'localhost'));
define('DB_USER', wpms_db_env('WPMS_DB_USER', 'root'));
define('DB_PASS', wpms_db_env('WPMS_DB_PASS', ''));
define('DB_NAME', wpms_db_env('WPMS_DB_NAME', 'dbwedding'));
