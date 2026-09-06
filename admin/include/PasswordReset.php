<?php
declare(strict_types=1);

/**
 * Client password-reset tokens (tbl_password_resets).
 *
 * Flow: forgot_password.php issues a 64-hex token (stored hashed — a DB leak
 * must not leak usable reset links), emails the link, reset_password.php
 * exchanges it for a one-time password change within 60 minutes. Single-use:
 * mark_used() burns the token; selection only ever returns pending,
 * unexpired rows.
 *
 * The email leg uses tbl_mail_logs with the SAME opt-in transport as staff
 * alerts (Notification's attempt path): when WPMS_MAIL_ENABLED is off the
 * row is logged pending and scripts/mail_retry.php delivers it later — so a
 * reset requested at a demo still works once the host has an MTA.
 */
class PasswordReset
{
    protected static $db_table   = 'tbl_password_resets';
    public const TOKEN_TTL_MIN   = 60;
    private const MAX_PER_HOUR   = 5;     // per email — brute-force damper
    private const LINK_BASE_PATH = 'reset_password.php';

    /**
     * Issue a reset token for $email. Always rate-limit-shaped: the caller
     * shows the same "check your inbox" message whether or not the address
     * exists (no account enumeration).
     *
     * @return string|null the PLAIN token (for the email link) or null when
     *         the address is unknown or rate-limited (caller decides what to say)
     */
    public static function issue(string $email): ?string
    {
        global $db;
        $email = strtolower(trim($email));

        $stmt = $db->prepare('SELECT user_id FROM tblaccounts WHERE LOWER(user_email) = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $userId = $stmt->fetchColumn();
        if ($userId === false || $userId === null) {
            return null; // unknown address — caller stays vague
        }
        $userId = (int) $userId;

        // Rate limit: 5 live requests per rolling hour per address.
        $recent = $db->prepare(
            'SELECT COUNT(*) FROM ' . self::$db_table
            . " WHERE user_id = :uid AND used_at IS NULL AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $recent->execute([':uid' => $userId]);
        if ((int) $recent->fetchColumn() >= self::MAX_PER_HOUR) {
            return null;
        }

        // Burn any older live tokens for this account — only the newest link works.
        $db->prepare('UPDATE ' . self::$db_table . ' SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
           ->execute([':uid' => $userId]);

        $plain = bin2hex(random_bytes(32));
        $ins = $db->prepare(
            'INSERT INTO ' . self::$db_table . ' (user_id, token_hash, expires_at) VALUES (:uid, :tok, (NOW() + INTERVAL ' . self::TOKEN_TTL_MIN . ' MINUTE))'
        );
        $ins->execute([':uid' => $userId, ':tok' => hash('sha256', $plain)]);
        return $plain;
    }

    /**
     * Validate a plain token: must exist, be unused, unexpired.
     *
     * @return object|false row {id, user_id, user_email} or false
     */
    public static function select_valid(string $plain): object|false
    {
        global $db;
        if ($plain === '') {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT r.id, r.user_id, a.user_email FROM ' . self::$db_table . ' r '
            . 'JOIN tblaccounts a ON a.user_id = r.user_id '
            . 'WHERE r.token_hash = :tok AND r.used_at IS NULL AND r.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':tok' => hash('sha256', $plain)]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row === false ? false : $row;
    }

    /** Exchange a valid token for a password change. Returns true on success. */
    public static function reset_password(string $plain, string $newPassword): bool
    {
        global $db;
        $row = self::select_valid($plain);
        if ($row === false) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd  = $db->prepare('UPDATE tblaccounts SET user_password = :p WHERE user_id = :uid');
        $ok   = $upd->execute([':p' => $hash, ':uid' => (int) $row->user_id]);
        if (!$ok) {
            return false;
        }
        // Burn the token (single-use) and any other live tokens for safety.
        $db->prepare('UPDATE ' . self::$db_table . ' SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
           ->execute([':uid' => (int) $row->user_id]);
        return true;
    }

    /**
     * Deliver the reset email. Records to tbl_mail_logs (status 'pending'
     * when the transport is off) and attempts immediate send via the shared
     * opt-in mail() path. Never throws.
     */
    public static function deliver(string $toEmail, string $firstname, string $plainToken): void
    {
        global $db;
        $link = (self::LINK_BASE_PATH) . '?token=' . urlencode($plainToken);

        $base = getenv('WPMS_SITE_URL');
        $base = is_string($base) ? rtrim(trim($base), '/') : '';
        $absolute = $base !== '' ? $base . '/' . ltrim($link, '/') : $link;

        $name = trim($firstname) !== '' ? $firstname : 'there';
        $subject = 'Reset your Everafter Planner password';
        $body = "Hi {$name},\n\n"
            . "We received a request to reset your Everafter Planner password.\n"
            . "Open this link within " . self::TOKEN_TTL_MIN . " minutes to choose a new one:\n\n"
            . $absolute . "\n\n"
            . "If you didn't ask for this, ignore this email — your password stays as it was.\n\n"
            . "— Everafter Planner";

        // Shared transport: 'pending' when WPMS_MAIL_ENABLED is off.
        $send = self::attempt_send($toEmail, $subject, $body);

        $ins = $db->prepare(
            'INSERT INTO tbl_mail_logs (notification_id, user_id, booking_id, to_email, subject, body, status, note, attempts, last_attempt_at) '
            . "VALUES (NULL, 0, 0, :to, :subj, :body, :status, :note, :attempts, :last)"
        );
        $ins->execute([
            ':to'       => $toEmail,
            ':subj'     => $subject,
            ':body'     => $body,
            ':status'   => $send[0],
            ':note'     => $send[1],
            ':attempts' => $send[0] === 'pending' ? 0 : 1,
            ':last'     => $send[0] === 'pending' ? null : date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The opt-in mail() path — mirrors Notification::attempt_send(). Kept as
     * a copy because Notification is staff-scoped by contract; a shared trait
     * would couple the couple-facing flow to the staff model.
     *
     * @return array{0: string, 1: ?string} [status, note]
     */
    private static function attempt_send(string $to, string $subject, string $body): array
    {
        if (getenv('WPMS_MAIL_ENABLED') !== '1') {
            return ['pending', 'Transport disabled (set WPMS_MAIL_ENABLED=1). Logged for scripts/mail_retry.php.'];
        }
        if (!function_exists('mail')) {
            return ['failed', 'mail() is disabled on this server.'];
        }
        $from    = getenv('WPMS_MAIL_FROM');
        $from    = is_string($from) && trim($from) !== '' ? trim($from) : 'no-reply@everafterplanner.co.ke';
        $headers = 'From: ' . $from . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8";
        $ok = @mail($to, $subject, $body, $headers);
        return $ok ? ['sent', null] : ['failed', 'mail() returned false — is an MTA configured on the host?'];
    }
}
