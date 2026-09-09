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
     *
     * Rows logged while the transport is off stay 'pending'; the cron-safe
     * scripts/mail_retry.php (via retry_pending()) re-attempts them later.
     */
    private static function email(int $notificationId, int $userId, int $bookingId, string $message, string $link = '', ?string $subject = null): void
    {
        global $db;

        // Recipient: staff live in tblusers; COUPLES live in tblaccounts
        // (their bell alerts — deposit receipts, confirmations — must reach
        // their email too, not just the in-app row).
        $stmt = $db->prepare('SELECT email, firstname, lastname FROM tblusers WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $recipient = $stmt->fetch(PDO::FETCH_OBJ);
        $to = is_object($recipient) ? trim((string) ($recipient->email ?? '')) : '';
        $name = is_object($recipient) ? trim((string) ($recipient->firstname ?? '') . ' ' . (string) ($recipient->lastname ?? '')) : '';
        if ($to === '') {
            $stmt = $db->prepare('SELECT user_email FROM tblaccounts WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $userId]);
            $to = trim((string) ($stmt->fetchColumn() ?: ''));
        }
        if ($to === '') {
            return; // no address on file — the in-app alert is the only channel
        }

        $subject ??= 'Everafter Planner notification — booking #' . $bookingId;
        $body = 'Hi ' . ($name !== '' ? $name : 'there') . ",\n\n" . $message . "\n\n";

        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? trim($base) : '';
        if ($link !== '') {
            $href = $base !== '' ? rtrim($base, '/') . '/admin/' . ltrim($link, '/') : $link;
            $body .= 'Open booking #' . $bookingId . ': ' . $href . "\n";
        }
        $body .= "\n— Everafter Planner";

        [$status, $note] = self::attempt_send($to, $subject, $body);

        $ins = $db->prepare(
            'INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, to_email, subject, body, status, note, attempts, last_attempt_at) '
            . 'VALUES (:nid, :uid, :bid, :to, :subj, :body, :status, :note, :attempts, :last_attempt)'
        );
        $ins->execute([
            ':nid'          => $notificationId,
            ':uid'          => $userId,
            ':bid'          => $bookingId,
            ':to'           => $to,
            ':subj'         => mb_substr($subject, 0, 255),
            ':body'         => $body,
            ':status'       => $status,
            ':note'         => $note,
            ':attempts'     => $status === 'pending' ? 0 : 1,
            ':last_attempt' => $status === 'pending' ? null : date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * One delivery attempt through the opt-in mail() transport.
     *
     * @return array{0: string, 1: ?string} [status, note] — status is
     *         'sent', 'failed', or 'pending' (transport disabled; the row
     *         waits for scripts/mail_retry.php to pick it up later).
     */
    private static function attempt_send(string $to, string $subject, string $body): array
    {
        if (getenv('WPMS_MAIL_ENABLED') !== '1') {
            return ['pending', 'Transport disabled (set WPMS_MAIL_ENABLED=1 to send via mail()). Logged, not delivered.'];
        }
        if (!function_exists('mail')) {
            return ['failed', 'mail() is disabled on this server.'];
        }
        $from    = getenv('WPMS_MAIL_FROM');
        $from    = is_string($from) && trim($from) !== '' ? trim($from) : 'no-reply@everafterplanner.co.ke';
        $headers = 'From: ' . $from . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8";
        $ok      = @mail($to, $subject, $body, $headers);
        return $ok
            ? ['sent', null]
            : ['failed', 'mail() returned false — is an MTA configured on the host?'];
    }

    /**
     * Re-attempt delivery of pending outbox rows (scripts/mail_retry.php).
     * Only rows below the attempt cap are tried; a row that fails on its
     * final attempt is marked 'abandoned' so it cannot loop forever.
     * No-op (empty result) unless WPMS_MAIL_ENABLED=1, so accidental cron
     * runs with the transport off change nothing.
     *
     * @return array{attempted:int, sent:int, failed:int, abandoned:int}
     */
    public static function retry_pending(int $limit = 50, int $maxAttempts = 5): array
    {
        global $db;
        $result = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'abandoned' => 0];
        if (getenv('WPMS_MAIL_ENABLED') !== '1') {
            return $result; // transport off — leave every row untouched
        }

        $stmt = $db->prepare(
            'SELECT id, to_email, subject, body, attempts FROM tbl_mail_logs '
            . "WHERE status = 'pending' AND attempts < :cap ORDER BY id ASC LIMIT :lim"
        );
        $stmt->bindValue(':cap', max(1, $maxAttempts), PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        $upd = $db->prepare(
            'UPDATE tbl_mail_logs SET status = :status, note = :note, attempts = attempts + 1, last_attempt_at = NOW() WHERE id = :id'
        );
        foreach ($rows as $row) {
            $result['attempted']++;
            [$status, $note] = self::attempt_send((string) $row->to_email, (string) $row->subject, (string) $row->body);
            $attemptsUsed = (int) $row->attempts + 1;
            if ($status === 'failed' && $attemptsUsed >= max(1, $maxAttempts)) {
                $status = 'abandoned';
                $note   = trim((string) $note . ' — abandoned after ' . $attemptsUsed . ' attempts.');
                $result['abandoned']++;
            } elseif ($status === 'sent') {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
            $upd->execute([':status' => $status, ':note' => $note, ':id' => (int) $row->id]);
        }
        return $result;
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
     * Total notifications for a staff user (pagination). Optional filters:
     * unread-only and/or a substring match on the message.
     */
    public static function count_for(int $userId, bool $unreadOnly = false, string $search = ''): int
    {
        global $db;
        [$where, $params] = self::filter_clause($unreadOnly, $search);
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ' . self::$db_table . ' WHERE user_id = :uid' . $where
        );
        $stmt->execute([':uid' => $userId] + $params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of a staff user's notifications, newest first (full archive).
     * Honors the same unread-only / message-search filters as count_for().
     *
     * @return object[]
     */
    public static function page(int $userId, int $offset, int $limit, bool $unreadOnly = false, string $search = ''): array
    {
        global $db;
        [$where, $params] = self::filter_clause($unreadOnly, $search);
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::$db_table . ' WHERE user_id = :uid' . $where
            . ' ORDER BY id DESC LIMIT :offset, :limit'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Shared WHERE fragment + bind params for the archive filters.
     * The search is a literal substring match (LIKE metacharacters in user
     * input are escaped, so '%', '_' and '\\' find themselves).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function filter_clause(bool $unreadOnly, string $search): array
    {
        $where  = '';
        $params = [];
        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }
        if ($search !== '') {
            $where .= ' AND message LIKE :search ESCAPE "\\\\"';
            $params[':search'] = '%' . addcslashes($search, "\\%_") . '%';
        }
        return [$where, $params];
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

    /**
     * Housekeeping: drop alerts older than the retention window. Read alerts
     * are pruned after 60 days, unread after 180 (the owner still has not seen
     * them, so they keep their chance a bit longer). Mail-log rows travel with
     * their notification. Returns the number of notifications removed.
     */
    public static function prune_old(int $readDays = 60, int $unreadDays = 180): int
    {
        global $db;
        $db->execute(
            'DELETE FROM tbl_mail_logs
              WHERE notification_id IN (
                  SELECT id FROM (
                      SELECT id FROM tblnotifications
                       WHERE (is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL :read_days DAY))
                          OR (is_read = 0 AND created_at < DATE_SUB(NOW(), INTERVAL :unread_days DAY))
                  ) x
              )',
            [':read_days' => $readDays, ':unread_days' => $unreadDays]
        );
        $stmt = $db->prepare(
            'DELETE FROM tblnotifications
              WHERE (is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL :read_days DAY))
                 OR (is_read = 0 AND created_at < DATE_SUB(NOW(), INTERVAL :unread_days DAY))'
        );
        $stmt->execute([':read_days' => $readDays, ':unread_days' => $unreadDays]);
        return $stmt->rowCount();
    }
}