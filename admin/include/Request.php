<?php
/**
 * Request — null-safe accessors for PHP's request superglobals.
 *
 * WHY THIS EXISTS. `$_POST['input_email']` on a request that did not send that
 * field is an "Undefined array key" warning on PHP 8.x. With display_errors off
 * the visitor sees nothing while the warning still lands in the error log, so
 * the class only ever surfaces in production logs or when a developer turns
 * debug on — which is exactly the kind of defect that sits unnoticed for
 * months. `clean()` guards null (see Helper.php), but the array access itself
 * is what warns, so every bare `$_POST['x']` is a landmine.
 *
 * These accessors name the default ONCE, at the read, instead of requiring a
 * `?? ''` at every call site, and they collapse the common shapes:
 *
 *   $email  = Request::post('email');          // string, '' when absent
 *   $plan   = Request::postInt('plan_id');     // int, 0 when absent
 *   $amount = Request::postFloat('amount');    // float, 0.0 when absent
 *   $page   = Request::get('page', 1);         // any scalar default
 *   $q      = Request::input('q');             // POST first, then GET
 *   if (Request::hasPost('submit')) { ... }    // presence test, no warning
 *
 * A value that arrives as an ARRAY (a crafted `email[]=x`) is never returned as
 * a string: the string/int/float helpers fall back to the default, so a caller
 * can never be handed an array where it expects a scalar. That closes the other
 * half of the same class — `trim($_POST['x'])` fataling on an array.
 *
 * No side effects, no output, no session: safe to require in the headless MCP
 * entry point alongside every other model (see models.php).
 */
final class Request
{
    /** Raw value of a POST field, or $default when absent. */
    public static function post(string $key, mixed $default = ''): mixed
    {
        return array_key_exists($key, $_POST) ? $_POST[$key] : $default;
    }

    /** Raw value of a GET field, or $default when absent. */
    public static function get(string $key, mixed $default = ''): mixed
    {
        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    /** POST field if present, else GET field, else $default. */
    public static function input(string $key, mixed $default = ''): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        return $default;
    }

    /** A POST field coerced to string; arrays and objects fall back to $default. */
    public static function postString(string $key, string $default = ''): string
    {
        return self::asString(self::post($key, $default), $default);
    }

    /** A GET field coerced to string; arrays and objects fall back to $default. */
    public static function getString(string $key, string $default = ''): string
    {
        return self::asString(self::get($key, $default), $default);
    }

    /** An input (POST-then-GET) field coerced to string. */
    public static function string(string $key, string $default = ''): string
    {
        return self::asString(self::input($key, $default), $default);
    }

    public static function postInt(string $key, int $default = 0): int
    {
        return self::asInt(self::post($key, $default), $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return self::asInt(self::get($key, $default), $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return self::asInt(self::input($key, $default), $default);
    }

    public static function postFloat(string $key, float $default = 0.0): float
    {
        return self::asFloat(self::post($key, $default), $default);
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return self::asFloat(self::get($key, $default), $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return self::asFloat(self::input($key, $default), $default);
    }

    /** Was this POST field sent at all? (No warning, unlike isset($_POST[$k]).) */
    public static function hasPost(string $key): bool
    {
        return array_key_exists($key, $_POST);
    }

    /** Was this GET field sent at all? */
    public static function hasGet(string $key): bool
    {
        return array_key_exists($key, $_GET);
    }

    // ------------------------------------------------------------- internals

    private static function asString(mixed $value, string $default): string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        // null, arrays, objects — anything a caller cannot treat as a string.
        return $default;
    }

    private static function asInt(mixed $value, int $default): int
    {
        if (is_array($value) || is_object($value) || is_null($value)) {
            return $default;
        }
        if (is_string($value) && !is_numeric(trim($value))) {
            return $default;
        }
        return (int) $value;
    }

    private static function asFloat(mixed $value, float $default): float
    {
        if (is_array($value) || is_object($value) || is_null($value)) {
            return $default;
        }
        if (is_string($value) && !is_numeric(trim($value))) {
            return $default;
        }
        return (float) $value;
    }
}
