<?php
declare(strict_types=1);

/**
 * Password-reset tokens (tbl_password_resets) for BOTH audiences.
 *
 * login.php is one sign-in for everyone — it tries tblaccounts (couples)
 * first, then tblusers (planners / admins / vendors) — so the reset flow has
 * to cover both, or a planner who forgets their password is locked out of
 * their own back office for good. Every row records which table it belongs to
 * (user_table, migration v2.6): the two tables have independent id sequences,
 * so that column is what stops a couple's token from ever resolving to a
 * planner who happens to share the same number.
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
    public const MAX_PER_HOUR    = 5;     // per email — brute-force damper
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

        $owner = self::resolve_owner($email);
        if ($owner === null) {
            return null; // unknown address — caller stays vague
        }
        [$table, $userId] = $owner;

        // Rate limit: 5 REQUESTS per rolling hour per address — counted on ALL
        // rows in the window, not just live ones. (Counting only unused rows
        // never trips: issue() burns older live tokens right before inserting
        // the new one, so the counter would be reset to 1 on every call.)
        // Scoped by (user_table, user_id): user_id alone would conflate a
        // couple and a planner whose ids merely share a number.
        $recent = $db->prepare(
            'SELECT COUNT(*) FROM ' . self::$db_table
            . ' WHERE user_table = :tbl AND user_id = :uid AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $recent->execute([':tbl' => $table, ':uid' => $userId]);
        if ((int) $recent->fetchColumn() >= self::MAX_PER_HOUR) {
            return null;
        }

        // Burn any older live tokens for this account — only the newest link works.
        $db->prepare(
            'UPDATE ' . self::$db_table
            . ' SET used_at = NOW() WHERE user_table = :tbl AND user_id = :uid AND used_at IS NULL'
        )->execute([':tbl' => $table, ':uid' => $userId]);

        $plain = bin2hex(random_bytes(32));
        try {
            $ins = $db->prepare(
                'INSERT INTO ' . self::$db_table . ' (user_table, user_id, token_hash, expires_at)'
                . ' VALUES (:tbl, :uid, :tok, (NOW() + INTERVAL ' . self::TOKEN_TTL_MIN . ' MINUTE))'
            );
            $ins->execute([':tbl' => $table, ':uid' => $userId, ':tok' => hash('sha256', $plain)]);
        } catch (Throwable $e) {
            /*
             * A deployment that has not run migration v2.6 has no user_table
             * column. Failing here would 500 the public forgot-password page;
             * instead stay neutral (the caller always answers "check your
             * inbox") and leave the real reason in the log.
             */
            error_log('[WPMS][PasswordReset] could not issue token (is migration v2.6 applied?): ' . $e->getMessage());
            return null;
        }
        return $plain;
    }

    /**
     * Find the account login.php would sign in for this address.
     *
     * Order matters and mirrors login.php exactly: tblaccounts (couples)
     * first, then tblusers (staff). If the same address exists in both, login
     * picks the couple — so the reset must pick the couple too, otherwise one
     * could reset the other's password.
     *
     * @return array{0: string, 1: int}|null [table, id]
     */
    private static function resolve_owner(string $email): ?array
    {
        global $db;

        $stmt = $db->prepare('SELECT user_id FROM tblaccounts WHERE LOWER(user_email) = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $id = $stmt->fetchColumn();
        if ($id !== false && $id !== null) {
            return ['tblaccounts', (int) $id];
        }

        $stmt = $db->prepare('SELECT id FROM tblusers WHERE LOWER(email) = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $id = $stmt->fetchColumn();
        if ($id !== false && $id !== null) {
            return ['tblusers', (int) $id];
        }

        return null;
    }

    /**
     * Whitelist the owner table before it is ever used in SQL. The value comes
     * back out of the database, so treating it as trusted would be a real
     * injection path — anything unrecognised falls back to the couple table.
     */
    private static function normalise_table(string $table): string
    {
        return $table === 'tblusers' ? 'tblusers' : 'tblaccounts';
    }

    /** Current email for an account, or null when the row no longer exists. */
    private static function owner_email(string $table, int $id): ?string
    {
        global $db;
        [$idCol, $emailCol] = self::normalise_table($table) === 'tblusers'
            ? ['id', 'email']
            : ['user_id', 'user_email'];

        $stmt = $db->prepare(
            'SELECT `' . $emailCol . '` FROM `' . self::normalise_table($table) . "` WHERE `" . $idCol . '` = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $email = $stmt->fetchColumn();
        return ($email === false || $email === null || $email === '') ? null : (string) $email;
    }

    /**
     * Validate a plain token: must exist, be unused, unexpired.
     *
     * @return object|false row {id, user_id, user_table, user_email} or false
     */
    public static function select_valid(string $plain): object|false
    {
        global $db;
        if ($plain === '') {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT r.id, r.user_id, r.user_table FROM ' . self::$db_table . ' r '
            . 'WHERE r.token_hash = :tok AND r.used_at IS NULL AND r.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':tok' => hash('sha256', $plain)]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        if ($row === false) {
            return false;
        }

        $row->user_table = self::normalise_table((string) ($row->user_table ?? ''));
        $row->user_email = self::owner_email($row->user_table, (int) $row->user_id);
        if ($row->user_email === null) {
            return false; // the account was deleted — treat the token as dead
        }
        return $row;
    }

    /** Exchange a valid token for a password change. Returns true on success. */
    public static function reset_password(string $plain, string $newPassword): bool
    {
        global $db;
        $row = self::select_valid($plain);
        if ($row === false) {
            return false;
        }
        $table = self::normalise_table((string) $row->user_table);
        // Column names come off the whitelist above, never from the row itself.
        $idCol = $table === 'tblusers' ? 'id' : 'user_id';
        $pwCol = $table === 'tblusers' ? 'password' : 'user_password';

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd  = $db->prepare("UPDATE `{$table}` SET `{$pwCol}` = :p WHERE `{$idCol}` = :uid");
        $ok   = $upd->execute([':p' => $hash, ':uid' => (int) $row->user_id]);
        if (!$ok) {
            return false;
        }
        // Burn the token (single-use) and any other live tokens for safety.
        $db->prepare(
            'UPDATE ' . self::$db_table
            . ' SET used_at = NOW() WHERE user_table = :tbl AND user_id = :uid AND used_at IS NULL'
        )->execute([':tbl' => $table, ':uid' => (int) $row->user_id]);
        error_log(sprintf(
            '[WPMS][PasswordReset] password changed via reset token for %s id=%d',
            $table,
            (int) $row->user_id
        ));
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

        /*
         * The outbox row goes through Notification (v2.13) with
         * recipient_type = 'none': a reset mail is addressed to a bare address
         * before anyone is signed in, so there is no account behind the row and
         * no feed can match it. Writing it through the one door keeps the table
         * with a single writer, and keeps that fact reviewable in one place.
         */
        Notification::log_address_mail($toEmail, $subject, $body, $send[0], $send[1]);
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
