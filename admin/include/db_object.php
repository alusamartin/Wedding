<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.1 — Base Active Record (PDO edition)
 * - All queries via PDO prepared statements
 * - No string-interpolated SQL with user input
 * - Backward compatible API (same static find_* / save / update / delete signatures)
 * - Typed where feasible, PHP 8.2+
 */

class DB_Object
{
    // -----------------------------------------------------------------
    // FINDERS (all now parameterized — IDs cast to int)
    // -----------------------------------------------------------------

    public static function find_all(): array
    {
        return static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' ORDER BY id DESC'
        );
    }

    public static function find_booking_all(): array
    {
        return static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' ORDER BY booking_id DESC'
        );
    }

    public static function find_by_event_all(mixed $id): array
    {
        return static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE booking_id = :id ORDER BY id DESC',
            [':id' => (int) $id]
        );
    }

    public static function find_by_id(mixed $id): object|false
    {
        $rows = static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE id = :id ORDER BY id DESC LIMIT 1',
            [':id' => (int) $id]
        );
        return !empty($rows) ? array_shift($rows) : false;
    }

    public static function find_by_feature_all(mixed $id): array
    {
        return static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE category_id = :id ORDER BY category_id DESC LIMIT 10',
            [':id' => (int) $id]
        );
    }

    public static function find_by_feature_all_a(mixed $id): array
    {
        return static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE category_id = :id ORDER BY category_id DESC',
            [':id' => (int) $id]
        );
    }

    public static function find_by_feature_no_limit(mixed $id): array
    {
        return static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE category_id = :id ORDER BY category_id DESC',
            [':id' => (int) $id]
        );
    }

    public static function find_by_feature_id(mixed $id): object|false
    {
        $rows = static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE feature_id = :id',
            [':id' => (int) $id]
        );
        return !empty($rows) ? array_shift($rows) : false;
    }

    public static function find_by_user_id(mixed $id): object|false
    {
        $rows = static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE user_id = :id ORDER BY user_id DESC LIMIT 1',
            [':id' => (int) $id]
        );
        return !empty($rows) ? array_shift($rows) : false;
    }

    public static function find_by_booking_id(mixed $id): object|false
    {
        $rows = static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE booking_id = :id ORDER BY booking_id DESC LIMIT 1',
            [':id' => (int) $id]
        );
        return !empty($rows) ? array_shift($rows) : false;
    }

    public static function find_by_customer_id(mixed $id): object|false
    {
        $rows = static::find_by_query(
            'SELECT * FROM ' . static::$db_table . ' WHERE customer_id = :id LIMIT 1',
            [':id' => (int) $id]
        );
        return !empty($rows) ? array_shift($rows) : false;
    }

    /**
     * Core query runner — always via PDO.
     * If $params empty and $sql has no placeholders, direct query (read-only internal calls).
     * New callers should always pass $params.
     *
     * @param string $sql
     * @param array  $params Named or positional bound values
     * @return array<object>
     */
    public static function find_by_query(string $sql, array $params = []): array
    {
        global $db;

        try {
            $stmt = $params === [] ? $db->query($sql) : $db->execute($sql, $params);
        } catch (PDOException $e) {
            error_log('[WPMS][DB_Object::find_by_query] ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }

        $object_array = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $object_array[] = static::instantiation($row);
        }
        return $object_array;
    }

    public static function instantiation(array|object $record): static
    {
        $class  = get_called_class();
        $object = new $class();
        $record = (array) $record;
        foreach ($record as $attr => $value) {
            if ($object->has_the_attribute($attr)) {
                $object->$attr = $value;
            }
        }
        return $object;
    }

    private function has_the_attribute(string $attr): bool
    {
        // Check declared properties (including dynamic via get_object_vars on $this)
        return array_key_exists($attr, get_object_vars($this));
    }

    // -----------------------------------------------------------------
    // SAVE / CREATE / UPDATE / DELETE — all prepared
    // -----------------------------------------------------------------

    public function save(): bool
    {
        // `id` is the PK for most tables
        return isset($this->id) && $this->id !== '' && $this->id !== null
            ? $this->update()
            : $this->create();
    }

    public function save_booking(): bool
    {
        return isset($this->booking_id) && $this->booking_id !== '' && $this->booking_id !== null
            ? $this->update_booking()
            : $this->create();
    }

    public function save_user(): bool
    {
        return isset($this->user_id) && $this->user_id !== '' && $this->user_id !== null
            ? $this->update_user()
            : $this->create();
    }

    public function save_account(): bool
    {
        return isset($this->user_id) && $this->user_id !== '' && $this->user_id !== null
            ? $this->update_user_account()
            : $this->create();
    }

    protected function properties(): array
    {
        $props = [];
        foreach (static::$db_table_fields as $field) {
            if (property_exists($this, $field)) {
                $props[$field] = $this->$field;
            }
        }
        return $props;
    }

    /**
     * @deprecated No longer escapes — returns raw properties for binding.
     * Kept for backward compat if any external code calls it.
     */
    protected function clean_properties(): array
    {
        return $this->properties();
    }

    /**
     * INSERT using named placeholders — PDO prepared.
     */
    public function create(): bool
    {
        global $db;

        $props = $this->properties();
        // Remove null PK auto-increment if present so DB can assign
        // Keep it if caller explicitly set a PK (e.g. booking_id)
        $isAutoId = in_array('id', static::$db_table_fields, true);
        if ($isAutoId && array_key_exists('id', $props) && ($props['id'] === null || $props['id'] === '')) {
            unset($props['id']);
        }

        $columns      = array_keys($props);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . static::$db_table
             . ' (' . implode(', ', $columns) . ')'
             . ' VALUES (' . implode(', ', $placeholders) . ')';

        $params = [];
        foreach ($props as $k => $v) {
            $params[':' . $k] = $v;
        }

        $stmt = $db->prepare($sql);
        $ok   = $stmt->execute($params);

        if ($ok && $isAutoId) {
            $this->id = (int) $db->the_insert_id();
        }
        return $ok;
    }

    // --- generic UPDATE helpers -------------------------------------

    private function updateBy(string $whereColumn, mixed $whereValue): bool
    {
        global $db;

        $props = $this->properties();
        $pairs = [];
        $params = [];

        foreach ($props as $k => $v) {
            // Don't try to update the WHERE column to itself via duplicate binding
            $pairs[] = "`{$k}` = :{$k}";
            $params[":{$k}"] = $v;
        }
        $params[':where_val'] = $whereValue;

        $sql  = 'UPDATE ' . static::$db_table . ' SET ' . implode(', ', $pairs)
              . " WHERE `{$whereColumn}` = :where_val";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function update(): bool
    {
        return $this->updateBy('id', $this->id);
    }

    public function update_user(): bool
    {
        return $this->updateBy('user_id', $this->user_id);
    }

    public function update_user_account(): bool
    {
        return $this->updateBy('user_id', $this->user_id);
    }

    public function update_booking(): bool
    {
        return $this->updateBy('booking_id', $this->booking_id);
    }

    public function update_feature(mixed $id): bool
    {
        // $id overrides instance feature_id — bind explicitly
        global $db;
        $props  = $this->properties();
        $pairs  = [];
        $params = [];
        foreach ($props as $k => $v) {
            $pairs[] = "`{$k}` = :{$k}";
            $params[":{$k}"] = $v;
        }
        $params[':where_val'] = (int) $id;
        $sql = 'UPDATE ' . static::$db_table . ' SET ' . implode(', ', $pairs) . ' WHERE feature_id = :where_val';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function delete(): bool
    {
        global $db;
        $sql  = 'DELETE FROM ' . static::$db_table . ' WHERE id = :id LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $this->id]);
        return $stmt->rowCount() === 1;
    }

    public function delete_feature(mixed $id): bool
    {
        global $db;
        $sql  = 'DELETE FROM ' . static::$db_table . ' WHERE feature_id = :id LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return $stmt->rowCount() === 1;
    }

    public static function count_all(): int
    {
        global $db;
        $sql = 'SELECT COUNT(*) FROM ' . static::$db_table;
        return (int) $db->fetchValue($sql);
    }
}
