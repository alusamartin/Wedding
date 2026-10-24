<?php
declare(strict_types=1);

/**
 * In-app notification feed + email outbox (PDO edition).
 *
 * THE RULE: a recipient id belongs to exactly one of two account tables, and
 * every row says which.
 *
 *   tblusers.id           staff — planners, admins and vendor leads (access_level 2/1/0)
 *   tblaccounts.user_id   couples
 *
 * Those are two separate auto-increment sequences, so 203 in tblusers and 203
 * in tblaccounts are different people who merely share a number. Neither table
 * is "the user table": `tblaccounts` keys its couple on `user_id` while
 * `tblusers` keys a planner on `id`, and an id carries no evidence of which one
 * it came from. A read scoped by the bare number therefore answered for BOTH —
 * an account in one table whose id collided with an account in the other could
 * read (and mark read) its feed, and, because the email leg resolved an address
 * the same way, could be sent its messages. Both ownership audits recorded this
 * as their remaining open finding (docs/COUPLE_PAGE_OWNERSHIP.md §5,
 * docs/VENDOR_PAGE_OWNERSHIP.md §5); `recipient_type`
 * (migrations/v2.13_notification_recipients.sql) is the fix, and this class is
 * the only door to the two tables.
 *
 * Consequences, all deliberate:
 *   - Every read names the type (FOR_USER / FOR_ACCOUNT) and the id. The old
 *     one-argument shape no longer exists, so a call site that was missed is a
 *     fatal error rather than a cross-table read.
 *   - Every write goes through create_for_staff() / create_for_vendor() /
 *     create_for_couple(), which state who the recipient is in the code that
 *     chose them, rather than inferring it from a number.
 *   - The email leg looks the address up in the ONE table the type names; it
 *     does not fall back to the other. That fallback was the second half of the
 *     same defect: with a collision, a couple's message went to the staff
 *     address.
 *   - A recipient id of 0 — a booking with no organizer, a mailbox with no
 *     account yet — is stored as FOR_NONE. The row is kept (the outbox records
 *     the attempt) and is unreachable by any session, because no account ever
 *     holds id 0. The old code wrote a row for user 0 that nobody could read;
 *     now it says so.
 *
 * create_for_*() also pushes a copy to the recipient's email through the
 * tbl_mail_logs outbox (opt-in mail() transport) so they are alerted even when
 * logged out — see email().
 */
class Notification
{
    protected static $db_table = 'tblnotifications';

    /** Recipient id indexes `tblusers.id` — planners, admins, vendor leads. */
    public const FOR_USER = 'user';
    /** Recipient id indexes `tblaccounts.user_id` — couples. */
    public const FOR_ACCOUNT = 'account';
    /** No account behind the row: the id is 0 and no session can reach it. */
    public const FOR_NONE = 'none';

    /** Every value the column accepts, in one place for the guard below. */
    private const TYPES = [self::FOR_USER, self::FOR_ACCOUNT, self::FOR_NONE];

    // =====================================================================
    // WRITES
    // =====================================================================

    /**
     * Record one alert for a TYPED recipient. This is the single writer; callers
     * use the three named wrappers below rather than passing the type, so "who
     * is this for" is answered where the recipient is chosen.
     *
     * @param string $recipientType self::FOR_USER | FOR_ACCOUNT | FOR_NONE
     * @return int the new notification id
     * @throws InvalidArgumentException on an unknown type, or on a type/id pair
     *         that cannot both be true (see normalise_recipient())
     */
    public static function create(
        string $recipientType,
        int $recipientId,
        int $bookingId,
        string $message,
        string $link = '',
        ?string $subject = null
    ): int {
        global $db;
        [$recipientType, $recipientId] = self::normalise_recipient($recipientType, $recipientId, 'create');

        $stmt = $db->prepare(
            'INSERT INTO ' . self::$db_table
            . ' (user_id, booking_id, recipient_type, message, link)'
            . ' VALUES (:uid, :bid, :rtype, :msg, :link)'
        );
        $stmt->execute([
            ':uid'   => $recipientId,
            ':bid'   => $bookingId,
            ':rtype' => $recipientType,
            ':msg'   => $message,
            ':link'  => $link,
        ]);
        $id = (int) $db->the_insert_id();

        self::email($recipientType, $id, $recipientId, $bookingId, $message, $link, $subject);
        return $id;
    }

    /**
     * An alert for a STAFF account (tblusers): a planner or an admin. An id of 0
     * — a booking with no organizer — records a FOR_NONE row instead of a row
     * for a user who does not exist, so the calling page is never made to fail.
     */
    public static function create_for_staff(int $staffId, int $bookingId, string $message, string $link = '', ?string $subject = null): int
    {
        return self::create(self::FOR_USER, $staffId, $bookingId, $message, $link, $subject);
    }

    /**
     * An alert for a COUPLE — `$accountId` is `tblaccounts.user_id`, the id the
     * booking's `user_id` and the couple's session both carry.
     */
    public static function create_for_couple(int $accountId, int $bookingId, string $message, string $link = '', ?string $subject = null): int
    {
        return self::create(self::FOR_ACCOUNT, $accountId, $bookingId, $message, $link, $subject);
    }

    /**
     * An alert for a VENDOR lead — a tblusers row exactly like a planner's
     * (access_level 0 with account_type staff), so it shares their id space.
     * Named separately because the pages that alert a vendor say "vendor", and
     * the pair (recipient kind, id space) is the whole point of this class.
     */
    public static function create_for_vendor(int $vendorUserId, int $bookingId, string $message, string $link = '', ?string $subject = null): int
    {
        return self::create(self::FOR_USER, $vendorUserId, $bookingId, $message, $link, $subject);
    }

    /**
     * Settle a (type, id) pair into the one state the column may hold.
     *
     *   - an unknown type is a programming error and throws (there is no
     *     sensible third answer to "which table does this id come from?");
     *   - a non-positive id becomes FOR_NONE, because no account holds id 0 and
     *     the caller still deserves its row rather than an exception on a live
     *     page (a booking with no organizer is normal, not a bug);
     *   - FOR_NONE with a real id is refused — it would be a row that claims to
     *     have no recipient while naming one.
     *
     * @return array{0: string, 1: int} [type, id]
     */
    private static function normalise_recipient(string $recipientType, int $recipientId, string $where): array
    {
        if (!in_array($recipientType, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Notification::{$where}() needs self::FOR_USER, FOR_ACCOUNT or FOR_NONE — '" . $recipientType . "' names no account table"
            );
        }
        if ($recipientId <= 0) {
            return [self::FOR_NONE, 0];
        }
        if ($recipientType === self::FOR_NONE) {
            throw new InvalidArgumentException(
                "Notification::{$where}() cannot store FOR_NONE with id {$recipientId}: 'none' means there is no account, and an id says there is one"
            );
        }
        return [$recipientType, $recipientId];
    }

    // =====================================================================
    // READS — every one names the account table
    // =====================================================================

    /**
     * The type a FEED read may use: a feed belongs to an account, so 'none' is
     * not a feed and is refused here (it can only ever match nothing).
     *
     * @throws InvalidArgumentException
     */
    private static function feed_type(string $recipientType, string $where): string
    {
        if (!in_array($recipientType, [self::FOR_USER, self::FOR_ACCOUNT], true)) {
            throw new InvalidArgumentException(
                "Notification::{$where}() reads a feed for one account table: pass self::FOR_USER or self::FOR_ACCOUNT, not '" . $recipientType . "'"
            );
        }
        return $recipientType;
    }

    public static function unread_count(string $recipientType, int $recipientId): int
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'unread_count');
        if ($recipientId <= 0) {
            return 0;
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ' . self::$db_table
            . ' WHERE recipient_type = :rtype AND user_id = :uid AND is_read = 0'
        );
        $stmt->execute([':rtype' => $recipientType, ':uid' => $recipientId]);
        return (int) $stmt->fetchColumn();
    }

    public static function latest(string $recipientType, int $recipientId, int $limit = 8): array
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'latest');
        if ($recipientId <= 0) {
            return [];
        }
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::$db_table
            . ' WHERE recipient_type = :rtype AND user_id = :uid ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':rtype', $recipientType, PDO::PARAM_STR);
        $stmt->bindValue(':uid', $recipientId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Total notifications for one account (pagination). Optional filters:
     * unread-only and/or a substring match on the message.
     */
    public static function count_for(string $recipientType, int $recipientId, bool $unreadOnly = false, string $search = ''): int
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'count_for');
        if ($recipientId <= 0) {
            return 0;
        }
        [$where, $params] = self::filter_clause($unreadOnly, $search);
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ' . self::$db_table
            . ' WHERE recipient_type = :rtype AND user_id = :uid' . $where
        );
        $stmt->execute([':rtype' => $recipientType, ':uid' => $recipientId] + $params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of an account's notifications, newest first (full archive).
     * Honors the same unread-only / message-search filters as count_for().
     *
     * @return object[]
     */
    public static function page(string $recipientType, int $recipientId, int $offset, int $limit, bool $unreadOnly = false, string $search = ''): array
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'page');
        if ($recipientId <= 0) {
            return [];
        }
        [$where, $params] = self::filter_clause($unreadOnly, $search);
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::$db_table
            . ' WHERE recipient_type = :rtype AND user_id = :uid' . $where
            . ' ORDER BY id DESC LIMIT :offset, :limit'
        );
        $stmt->bindValue(':rtype', $recipientType, PDO::PARAM_STR);
        $stmt->bindValue(':uid', $recipientId, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * The deep link of one alert, IF it belongs to this account — the archive's
     * ?action=read click-through. Replaces the page's own inline SELECT, so a
     * request parameter can never hand back another account's link.
     */
    public static function link_for(string $recipientType, int $notificationId, int $recipientId): string
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'link_for');
        if ($notificationId <= 0 || $recipientId <= 0) {
            return '';
        }
        $stmt = $db->prepare(
            'SELECT link FROM ' . self::$db_table
            . ' WHERE id = :id AND recipient_type = :rtype AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $notificationId, ':rtype' => $recipientType, ':uid' => $recipientId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Mark ONE alert read for one account. Also the ownership re-check: the
     * statement carries the type in its WHERE clause, so it returns true only
     * when a row this account owns was actually flipped (false for somebody
     * else's row, and for one that was already read).
     */
    public static function mark_read(string $recipientType, int $notificationId, int $recipientId): bool
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'mark_read');
        if ($notificationId <= 0 || $recipientId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            'UPDATE ' . self::$db_table . ' SET is_read = 1'
            . ' WHERE id = :id AND recipient_type = :rtype AND user_id = :uid'
        );
        $stmt->execute([':id' => $notificationId, ':rtype' => $recipientType, ':uid' => $recipientId]);
        return $stmt->rowCount() > 0;
    }

    public static function mark_all_read(string $recipientType, int $recipientId): bool
    {
        global $db;
        $recipientType = self::feed_type($recipientType, 'mark_all_read');
        if ($recipientId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            'UPDATE ' . self::$db_table . ' SET is_read = 1'
            . ' WHERE recipient_type = :rtype AND user_id = :uid AND is_read = 0'
        );
        return $stmt->execute([':rtype' => $recipientType, ':uid' => $recipientId]);
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

    // =====================================================================
    // EMAIL OUTBOX (tbl_mail_logs)
    // =====================================================================

    /**
     * Best-effort email push of one alert (the recipient is alerted even when
     * not logged in). Never throws and never blocks the request: the in-app row
     * is already committed by create(); this leg only records and optionally
     * delivers.
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
     *
     * The address comes from the table the recipient type names — staff from
     * `tblusers` (its `email`), couples from `tblaccounts` (its `user_email`) —
     * and from no other. With a colliding id there is no second table to try, so
     * the message cannot reach the wrong account. A recipient with no address on
     * file (that includes FOR_NONE: no account behind the row) gets no outbox
     * row at all — the in-app alert is the only channel, which is what this leg
     * did before too.
     */
    private static function email(
        string $recipientType,
        int $notificationId,
        int $recipientId,
        int $bookingId,
        string $message,
        string $link = '',
        ?string $subject = null
    ): void {
        global $db;

        $to   = '';
        $name = '';
        if ($recipientType === self::FOR_NONE) {
            // No account behind the row (a booking with no organizer, a signup
            // whose account does not exist yet). Nothing to look up.
            $to = '';
        } elseif ($recipientType === self::FOR_USER) {
            $stmt = $db->prepare('SELECT email, firstname, lastname FROM tblusers WHERE id = :uid LIMIT 1');
            $stmt->execute([':uid' => $recipientId]);
            $row = $stmt->fetch(PDO::FETCH_OBJ);
            if (is_object($row)) {
                $to   = trim((string) ($row->email ?? ''));
                $name = trim((string) ($row->firstname ?? '') . ' ' . (string) ($row->lastname ?? ''));
            }
        } elseif ($recipientType === self::FOR_ACCOUNT) {
            $stmt = $db->prepare('SELECT user_email FROM tblaccounts WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $recipientId]);
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

        [$status, $note] = self::attempt_send($to, (string) $subject, $body);

        $ins = $db->prepare(
            'INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, recipient_type, to_email, subject, body, status, note, attempts, last_attempt_at) '
            . 'VALUES (:nid, :uid, :bid, :rtype, :to, :subj, :body, :status, :note, :attempts, :last_attempt)'
        );
        $ins->execute([
            ':nid'          => $notificationId,
            ':uid'          => $recipientId,
            ':bid'          => $bookingId,
            ':rtype'        => $recipientType,
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
     * Record one outbox row for a message addressed to a BARE ADDRESS — the
     * password-reset and planner-verification mails, which are sent before
     * anybody is signed in and therefore have no account behind them.
     *
     * This exists so those two flows do not become a second door to the table:
     * the row is written FOR_NONE here, in the same place every other recipient
     * type is written, and FOR_NONE is exactly the statement that there is no
     * account to reach — no feed can match it, and the recipient id stays 0.
     *
     * Never throws: an outbox row is a record, not a gate (the caller has
     * already attempted and reported the send).
     */
    /**
     * Attempt one bare-address email and record it in the outbox — the pair
     * every scheduled mail job needs, in one call.
     *
     * WHY IT EXISTS. `attempt_send()` is private and `log_address_mail()` only
     * records what somebody else already attempted. The weekly business pulse
     * (scripts/business_pulse_email.php) is a cron job with no HTTP request
     * behind it and no Notification row to hang the email on, so without this
     * it would either duplicate the transport (a second set of headers to keep
     * in step with this one) or silently write 'pending' rows that were never
     * tried. One door: attempt through the same opt-in transport, then record
     * the outcome exactly as every other bare-address mail does.
     *
     * Returns the transport's verdict so the caller can heartbeat honestly:
     * 'sent' means delivered, 'pending' means the transport is off and
     * mail_retry will pick the row up, 'failed' means the MTA refused and the
     * caller should NOT mark its own "already sent this week" flag.
     *
     * @return array{status:string, note:?string}
     */
    public static function send_address_mail(string $toEmail, string $subject, string $body): array
    {
        [$status, $note] = self::attempt_send($toEmail, $subject, $body);
        self::log_address_mail($toEmail, $subject, $body, $status, $note);
        return ['status' => $status, 'note' => $note];
    }

    public static function log_address_mail(string $toEmail, string $subject, string $body, string $status, ?string $note): void
    {
        global $db;
        try {
            $ins = $db->prepare(
                'INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, recipient_type, to_email, subject, body, status, note, attempts, last_attempt_at) '
                . 'VALUES (NULL, 0, 0, :rtype, :to, :subj, :body, :status, :note, :attempts, :last_attempt)'
            );
            $ins->execute([
                ':rtype'        => self::FOR_NONE,
                ':to'           => $toEmail,
                ':subj'         => mb_substr($subject, 0, 255),
                ':body'         => $body,
                ':status'       => $status,
                ':note'         => $note,
                ':attempts'     => $status === 'pending' ? 0 : 1,
                ':last_attempt' => $status === 'pending' ? null : date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[WPMS][Notification] address-mail outbox write failed: ' . $e->getMessage());
        }
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

    /**
     * Rows the retry sweeper would pick up right now — the queue's depth without
     * touching it. The predicate is retry_pending()'s own selection (pending,
     * below the attempt cap), so a queue that is empty for the sweeper is empty
     * here too, and a queue of exhausted rows is never called waiting.
     *
     * Read by the watchdog (include/JobWatch.php) to tell a sweep that had
     * nothing to do from a sweep that did nothing while work waited — and it
     * lives here, next to the selection, so the two cannot drift apart. The
     * feed tables are never named outside this class (see
     * tests/NotificationRecipientScopeTest.php).
     */
    public static function pending_mail_count(int $maxAttempts = 5): int
    {
        global $db;
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tbl_mail_logs WHERE status = 'pending' AND attempts < :cap",
            [':cap' => max(1, $maxAttempts)]
        );
    }

    /**
     * Housekeeping: drop alerts older than the retention window. Read alerts
     * are pruned after 60 days, unread after 180 (the owner still has not seen
     * them, so they keep their chance a bit longer). Mail-log rows travel with
     * their notification. Returns the number of notifications removed.
     *
     * Deliberately table-wide: a retention sweep has no session and no
     * recipient, which is why it carries no type — it only ever deletes by age.
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

    /**
     * Alerts sitting past their retention window right now — how much work
     * prune_old() would find if it ran this second, asked with the same two
     * thresholds (read 60 days, unread 180). The watchdog uses it for the same
     * reason as pending_mail_count() above: a pruner that reports success while
     * this is non-zero has stopped pruning.
     */
    public static function prunable_count(int $readDays = 60, int $unreadDays = 180): int
    {
        global $db;
        return (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblnotifications
              WHERE (is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL :read_days DAY))
                 OR (is_read = 0 AND created_at < DATE_SUB(NOW(), INTERVAL :unread_days DAY))',
            [':read_days' => max(1, $readDays), ':unread_days' => max(1, $unreadDays)]
        );
    }
}
