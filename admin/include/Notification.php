<?php
declare(strict_types=1);

/**
 * In-app notification feed for staff planners (PDO edition).
 *
 * Fuelled by the couple portal: when a couple records a self-serve M-Pesa
 * deposit, the assigned planner (tblweddingbook.organizer_id) receives an
 * in-app alert with the amount, reference and a deep link to the booking's
 * budget page. The staff sidebar renders the unread badge + dropdown.
 *
 * Uses tblnotifications (created via kenyan_schema.sql / the migration run —
 * never auto-DDL at request time).
 */
class Notification
{
    protected static $db_table = 'tblnotifications';

    /**
     * Create a notification for a staff planner.
     *
     * @return int the new notification id
     */
    public static function create(int $userId, int $bookingId, string $message, string $link = ''): int
    {
        global $db;
        $stmt = $db->prepare(
            'INSERT INTO ' . self::$db_table . ' (user_id, booking_id, message, link) VALUES (:uid, :bid, :msg, :link)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':bid'  => $bookingId,
            ':msg'  => $message,
            ':link' => $link,
        ]);
        return (int) $db->the_insert_id();
    }

    public static function unread_count(int $userId): int
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ' . self::$db_table . ' WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function latest(int $userId, int $limit = 8): array
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::$db_table . ' WHERE user_id = :uid ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function mark_read(int $id, int $userId): bool
    {
        global $db;
        $stmt = $db->prepare(
            'UPDATE ' . self::$db_table . ' SET is_read = 1 WHERE id = :id AND user_id = :uid'
        );
        return $stmt->execute([':id' => $id, ':uid' => $userId]);
    }

    public static function mark_all_read(int $userId): bool
    {
        global $db;
        $stmt = $db->prepare(
            'UPDATE ' . self::$db_table . ' SET is_read = 1 WHERE user_id = :uid AND is_read = 0'
        );
        return $stmt->execute([':uid' => $userId]);
    }
}