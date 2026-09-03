<?php
declare(strict_types=1);

class Accounts extends DB_Object
{
    protected static $db_table = "tblaccounts";
    protected static $db_table_fields = array(
        'user_id',
        'user_email',
        'user_password',
        'access_level'
    );

    public $user_id;
    public $user_email;
    public $user_password;
    public $access_level;

    /**
     * PDO prepared login — no escape_string.
     */
    public static function login_user(string $email, string $password): object|false
    {
        global $db;
        $sql  = "SELECT * FROM " . self::$db_table . " WHERE user_email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);

        if ($user && password_verify($password, $user->user_password)) {
            return $user;
        }
        return false;
    }

    public static function hash_password(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * PDO prepared — fixes SQL injection from interpolated '$email'.
     */
    public static function email_exists(string $email): bool
    {
        global $db;
        $sql  = "SELECT user_id FROM " . self::$db_table . " WHERE user_email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() !== false;
    }
}
