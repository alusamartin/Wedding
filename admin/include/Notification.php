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
 * create() also pushes a copy to the planner's email (tbl_mail_logs outbox,
 * opt-in mail() transport) so they are alerted even when logged out — see
 * email().
 *
 * Uses tblnotifications (created via kenyan_schema.sql / the migration run —
 * never auto-DDL at request time).
 */
class Notification
{
    protected static $db_table = 'tblnotifications';

    /**
     * Create a notification for a staff planner and push an email copy to
     * their address (best-effort; see email()).
     *
     * @return int the new notification id
     */
    public static function create(int $userId, int $bookingId, string $message, string $link = '', ?string $subject = null): int
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
        $id = (int) $db->the_insert_id();

        self::email($id, $userId, $bookingId, $message, $link, $subject);
        return $id;
    }

    /**
     * Best-effort email push of a staff notification (the planner is alerted
     * even when not logged in). Never throws and never blocks the request:
     * the in-app row is already committed by create(); this leg only records
     * and optionally delivers.
     *
     * The outbox row goes to tbl_mail_logs (mirrors tblsms_logs so no push is
     * silently lost). Real delivery uses PHP mail() and is opt-in via the
     * environment, so dev/demo never attempt a send and tests stay hermetic:
     *   WPMS_MAIL_ENABLED=1        actually call mail() (default: off)
     *   WPMS_MAIL_FROM=addr@x.com  From header (default no-reply@everafterplanner.co.ke)
     *   WPMS_SITE_URL=https://…    base for the absolute deep link in the body
     */
    private static function email(int $notificationId, int $userId, int $bookingId, string $message, string $link = '', ?string $subject = null): void
    {
        global $db;

        $stmt = $db->prepare('SELECT email, firstname, lastname FROM tblusers WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $planner = $stmt->fetch(PDO::FETCH_OBJ);
        $to = is_object($planner) ? trim((string) ($planner->email ?? '')) : '';
        if ($to === '') {
            return; // no address on file — the in-app alert is the only channel
        }

        $subject ??= 'Everafter Planner notification — booking #' . $bookingId;
        $name = trim((string) ($planner->firstname ?? '') . ' ' . (string) ($planner->lastname ?? ''));
        $body = 'Hi ' . ($name !== '' ? $name : 'Planner') . ",\n\n" . $message . "\n\n";

        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? trim($base) : '';
        if ($link !== '') {
            $href = $base !== '' ? rtrim($base, '/') . '/admin/' . ltrim($link, '/') : $link;
            $body .= 'Open booking #' . $bookingId . ': ' . $href . "\n";
        }
        $body .= "\n— Everafter Planner";

        $status = 'pending';
        $note   = 'Transport disabled (set WPMS_MAIL_ENABLED=1 to send via mail()). Logged, not delivered.';
        if (getenv('WPMS_MAIL_ENABLED') === '1') {
            if (!function_exists('mail')) {
                $status = 'failed';
                $note   = 'mail() is disabled on this server.';
            } else {
                $from    = getenv('WPMS_MAIL_FROM');
                $from    = is_string($from) && trim($from) !== '' ? trim($from) : 'no-reply@everafterplanner.co.ke';
                $headers = 'From: ' . $from . "\r\n"
                    . "MIME-Version: 1.0\r\n"
                    . "Content-Type: text/plain; charset=utf-8";
                $ok      = @mail($to, $subject, $body, $headers);
                $status  = $ok ? 'sent' : 'failed';
                $note    = $ok ? null : 'mail() returned false — is an MTA configured on the host?';
            }
        }

        $ins = $db->prepare(
            'INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, to_email, subject, body, status, note) '
            . 'VALUES (:nid, :uid, :bid, :to, :subj, :body, :status, :note)'
        );
        $ins->execute([
            ':nid'    => $notificationId,
            ':uid'    => $userId,
            ':bid'    => $bookingId,
            ':to'     => $to,
            ':subj'   => mb_substr($subject, 0, 255),
            ':body'   => $body,
            ':status' => $status,
            ':note'   => $note,
        ]);
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

    /**
     * Total notifications for a staff user (pagination).
     */
    public static function count_for(int $userId): int
    {
        global $db;
        $stmt = $db->prepare('SELECT COUNT(*) FROM ' . self::$db_table . ' WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of a staff user's notifications, newest first (full archive).
     *
     * @return object[]
     */
    public static function page(int $userId, int $offset, int $limit): array
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::$db_table . ' WHERE user_id = :uid ORDER BY id DESC LIMIT :offset, :limit'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
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