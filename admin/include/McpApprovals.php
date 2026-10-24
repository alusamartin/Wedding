<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.17 — the human in the loop for Tier-3 MCP writes (McpApprovals)
 *
 * An AI client that can read a wedding is useful. An AI client that can SEND 400
 * GUESTS AN SMS, or record a payment, or start an M-Pesa prompt against a
 * couple's phone, is a different proposition — those are money and messages, and
 * they are not reversible by editing a field back. This class is the gate in
 * front of them, and it is deliberately the smallest possible gate:
 *
 *   A HUMAN SIGNS IN, LOOKS AT ONE SENTENCE, AND APPROVES ONE EXACT PAYLOAD.
 *
 * The flow, end to end:
 *
 *   1. the assistant calls a Tier-3 tool with no `approval`;
 *   2. the tool resolves the real data, writes a ROW here, and answers
 *      `approval_required` with the reference and the sentence the planner will
 *      read — nothing was sent, nothing was written to the ledger;
 *   3. the planner opens admin/api_tokens.php… no: admin/mcp_approvals.php, with
 *      their own session and a CSRF token, and presses Approve — or Deny, with a
 *      reason;
 *   4. Approve mints `mcpap_…`: HMAC-signed over the row, short-lived,
 *      single-use, and bound to the tool AND to `payload_hash` — a hash of the
 *      exact committed payload (that SMS body, that audience, that booking; that
 *      amount, that method, that M-Pesa code). Change one byte and it no longer
 *      fits;
 *   5. the assistant retries the identical call WITH the approval; this class
 *      verifies it, CONSUMES it with `UPDATE … WHERE consumed_at IS NULL` (the
 *      row count is the exactly-once guarantee), and the tool runs once.
 *
 * WHY THE PAYLOAD IS HASHED AND NOT JUST THE TOOL NAME. "Approve sending an SMS"
 * is not a decision a human can make; "approve these 312 characters to the 47
 * upcountry guests of wedding #200 for KSh 47" is. An approval bound to a tool
 * alone would let the assistant swap the body after it was read, which is how a
 * confirmation step becomes decoration.
 *
 * WHY THE SECRET IS NEVER STORED. Same reason as `tblapi_tokens`: only
 * sha256(secret) is kept. A dump of this table hands out no usable approval, and
 * the planner sees the string exactly once, on the page that minted it.
 *
 * WHY IT FAILS CLOSED. No table → no approvals → Tier-3 tools refuse. A missing
 * migration must not become a bypass, exactly as with v2.16.
 *
 * Storage: migrations/v2.17_mcp_approvals.sql (tblapi_approvals). All time
 * arithmetic is SQL against the DB session timezone (Africa/Nairobi), the same
 * discipline as LoginThrottle, PublicGuard, JobWatch and ApiToken.
 */
final class McpApprovals
{
    public const TABLE = 'tblapi_approvals';

    /** The write tier this class gates: money, messages or status (McpWrites::TIER_APPROVAL). */
    public const TIER = 3;

    /** Prefix on every minted approval, so a leaked string is recognisable. */
    public const SECRET_PREFIX = 'mcpap_';

    /** 32 random bytes → 64 hex characters of entropy behind the prefix. */
    public const SECRET_BYTES = 32;

    /** How long an UNANSWERED request waits before it is dead. */
    public const REQUEST_TTL_MINUTES = 30;

    /**
     * How long a REFUSAL stands for the same tool and payload. A denial is an
     * answer to the assistant, not just a closed row: while it stands, the same
     * request is reported as refused instead of minting a fresh request the
     * planner has to refuse again. Changing the payload changes the hash, which
     * makes it a new question — the intended behaviour.
     */
    public const DENIAL_COOLDOWN_MINUTES = 1440;

    /** How long a MINTED approval is usable. Short on purpose: it is a decision, not a key. */
    public const APPROVAL_TTL_MINUTES = 15;

    /**
     * How far back a RETRY looks for the identical request that expired.
     *
     * An expiry the assistant only hears about while it is still working on the
     * task is actionable; one it hears about on tomorrow's unrelated call is
     * noise. A day covers a normal conversation and a normal night's sleep.
     */
    public const EXPIRY_NOTICE_MINUTES = 1440;

    /** Most rows one sweep closes — a bound, not a target (the job re-runs). */
    public const SWEEP_LIMIT = 200;

    /** Most references a digest bell names before it says "and N more". */
    public const SWEEP_REFS_IN_ALERT = 5;

    /** Longest notification body (tblnotifications.message is varchar(255)). */
    public const ALERT_MAX = 250;

    /** Longest sentence a payload summary may be (the column is 512). */
    public const MAX_NOTE = 512;

    /** Environment variable holding the HMAC key, when the operator sets one. */
    public const KEY_ENV = 'WPMS_MCP_APPROVAL_SECRET';

    /** Cached installed/uninstalled answer for this request. */
    private static ?bool $installed = null;

    /** Cached has-v2.19-expired_at answer for this request. */
    private static ?bool $expiryMarker = null;

    /** Cached has-v2.20-re_request-of answer for this request. */
    private static ?bool $rerequestMarker = null;

    // ==================================================================
    // HASHING AND SIGNING
    // ==================================================================

    /**
     * The HMAC key. `WPMS_MCP_APPROVAL_SECRET` when the operator set one;
     * otherwise a value DERIVED from this install's own configuration (the DB
     * credentials and the install path) — stable across requests, identical on
     * every front-end node of the same install, and useless to anyone who cannot
     * already read the config file. Deriving is a fallback, not the intent:
     * docs/MCP_PLAN.md and .env.example both say to set the real secret, and
     * rotating it simply invalidates outstanding approvals.
     */
    private static function key(): string
    {
        $configured = trim((string) getenv(self::KEY_ENV));
        if ($configured !== '') {
            return $configured;
        }
        /*
         * The install path here must be the SAME string in both processes that
         * use this class: the staff page (which mints) and mcp.php (which
         * verifies). It used to be `defined('SITE_ROOT') ? SITE_ROOT : __DIR__`,
         * and that was wrong in a way nobody would see until an approval was
         * refused: admin/include/init.php defines SITE_ROOT as `…/admin`, while
         * mcp.php never defines it at all, so the endpoint derived its key from
         * `…/admin/include` — a different seed, a different HMAC key, and every
         * approval minted on the page failed with "does not verify". `dirname(__DIR__)`
         * is `…/admin` in both processes because this file's own location does not
         * depend on which constants the entry point happened to define.
         */
        $seed = (defined('DB_USER') ? DB_USER : '') . '|'
              . (defined('DB_PASS') ? DB_PASS : '') . '|'
              . (defined('DB_NAME') ? DB_NAME : '') . '|'
              . dirname(__DIR__);
        return hash('sha256', 'wpms-mcp-approval|' . $seed);
    }

    /**
     * The hash an approval is bound to: the tool plus its COMMITTED payload.
     *
     * The payload arrives already reduced to the fields that decide the action
     * (McpWrites::committed()) so that `confirm`, `approval` and `request_id` —
     * which are transport, not intent — cannot break the binding, while the
     * booking, the audience, the message text, the amount, the method and the
     * M-Pesa code all can.
     *
     * @param array<string,mixed> $payload
     */
    public static function payload_hash(string $tool, array $payload): string
    {
        ksort($payload);
        return hash('sha256', 'v1|' . $tool . '|' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** How an approval string is stored and looked up (never the string itself). */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    // ==================================================================
    // THE REQUEST (step 2)
    // ==================================================================

    /**
     * Record a Tier-3 request the assistant made without an approval.
     *
     * Reuses an outstanding identical request rather than piling up rows: an
     * assistant that calls the tool three times while the planner is reading the
     * page gets ONE reference, not three approvals for the same SMS.
     *
     * @param array<string,mixed> $principal from ApiToken::authenticate()
     * @param array<string,mixed> $payload   the committed payload (hashed, never stored in the clear)
     * @return array{id:int, reference:string, expires_at:string, reused:bool, denied?:bool, reason?:string, denied_at?:string}
     */
    public static function request(
        array $principal,
        string $tool,
        int $tier,
        ?int $bookingId,
        array $payload,
        string $note
    ): array {
        if (!self::available()) {
            throw new McpApprovalError('approvals are not available on this install — apply migrations/v2.17_mcp_approvals.sql');
        }

        global $db;
        $hash = self::payload_hash($tool, $payload);
        $note = mb_substr(trim($note), 0, self::MAX_NOTE);

        $existing = $db->fetchOne(
            'SELECT id, expires_at FROM ' . self::TABLE . '
              WHERE token_id = :token AND tool = :tool AND payload_hash = :hash
                AND approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL
                AND expires_at > NOW()
           ORDER BY id DESC LIMIT 1',
            [
                ':token' => (int) ($principal['token_id'] ?? 0),
                ':tool'  => $tool,
                ':hash'  => $hash,
            ]
        );
        if ($existing !== null) {
            return [
                'id'         => (int) $existing->id,
                'reference'  => self::reference((int) $existing->id),
                'expires_at' => (string) $existing->expires_at,
                'reused'     => true,
            ];
        }

        /*
         * A planner who REFUSED this exact payload has already answered. Without
         * this lookup the same call would mint a fresh request every time — deny,
         * re-ask, deny again — and the sentence on the Approvals page ("the
         * assistant is told it was refused") would be untrue, because nothing in
         * the MCP layer ever read deny_reason. The refusal stands while the
         * payload is unchanged; a different message, amount or booking has a
         * different hash and is a genuinely new request.
         *
         * A refusal is checked BEFORE the expired-request notice below: when a
         * payload was both refused and (earlier) expired, the refusal is the
         * live answer and the expiry is history.
         */
        $denied = $db->fetchOne(
            'SELECT id, deny_reason, denied_at FROM ' . self::TABLE . '
              WHERE token_id = :token AND tool = :tool AND payload_hash = :hash
                AND denied_at IS NOT NULL
                AND denied_at > NOW() - INTERVAL :mins MINUTE
           ORDER BY id DESC LIMIT 1',
            [
                ':token' => (int) ($principal['token_id'] ?? 0),
                ':tool'  => $tool,
                ':hash'  => $hash,
                ':mins'  => self::DENIAL_COOLDOWN_MINUTES,
            ]
        );
        if ($denied !== null) {
            return [
                'id'         => (int) $denied->id,
                'reference'  => self::reference((int) $denied->id),
                'expires_at' => '',
                'reused'     => false,
                'denied'     => true,
                'reason'     => (string) $denied->deny_reason,
                'denied_at'  => (string) $denied->denied_at,
            ];
        }

        $db->execute(
            'INSERT INTO ' . self::TABLE . '
                (token_id, recipient_type, user_id, tool, tier, booking_id, payload_hash, payload_note, expires_at)
             VALUES (:token, :kind, :uid, :tool, :tier, :bid, :hash, :note, NOW() + INTERVAL :mins MINUTE)',
            [
                ':token' => self::tokenId($principal),
                ':kind'  => self::kind($principal),
                ':uid'   => (int) ($principal['id'] ?? 0),
                ':tool'  => mb_substr($tool, 0, 64),
                ':tier'  => max(3, $tier),
                ':bid'   => $bookingId !== null && $bookingId > 0 ? $bookingId : null,
                ':hash'  => $hash,
                ':note'  => $note,
                ':mins'  => self::REQUEST_TTL_MINUTES,
            ]
        );

        $id = (int) $db->the_insert_id();
        $row = self::find($id);
        return [
            'id'         => $id,
            'reference'  => self::reference($id),
            'expires_at' => (string) ($row->expires_at ?? ''),
            'reused'     => false,
            // The identical request, expired recently: said out loud so the
            // assistant stops waiting on a reference that is already dead.
            'previous'   => self::previous_expiry(self::tokenId($principal) ?? 0, $tool, $hash),
        ];
    }

    /**
     * The most recent IDENTICAL request that expired inside the notice window,
     * or null.
     *
     * Read-only and time-based, deliberately independent of the sweep: the
     * assistant is told its old request died even on an install where the cron
     * job was never wired up, because silence would let it wait forever for an
     * approval that cannot arrive. The sweep's marker adds the planner's half
     * (one bell, one audit line) — it is not a prerequisite for this one.
     *
     * @return array{reference:string, outcome:string, expired_at:string, swept:bool}|null
     */
    private static function previous_expiry(int $tokenId, string $tool, string $hash): ?array
    {
        try {
            global $db;
            $row = $db->fetchOne(
                // `<=>` (NULL-safe equality) so a request recorded without a
                // resolvable token still recognises its own earlier expiry:
                // `token_id = NULL` is never true in SQL, which would make the
                // note silently absent for exactly those principals.
                'SELECT id, approved_at, consumed_at, expires_at, expired_at FROM ' . self::TABLE . '
                  WHERE token_id <=> :token AND tool = :tool AND payload_hash = :hash
                    AND denied_at IS NULL AND consumed_at IS NULL
                    AND expires_at <= NOW()
                    AND created_at > NOW() - INTERVAL :mins MINUTE
               ORDER BY id DESC LIMIT 1',
                [
                    ':token' => $tokenId > 0 ? $tokenId : null,
                    ':tool'  => $tool,
                    ':hash'  => $hash,
                    ':mins'  => self::EXPIRY_NOTICE_MINUTES,
                ]
            );
        } catch (Throwable $e) {
            return null; // a courtesy note must never break the request itself
        }
        if ($row === null) {
            return null;
        }
        return [
            'reference'  => self::reference((int) $row->id),
            'outcome'    => empty($row->approved_at) ? 'expired unanswered' : 'approved but not used in time',
            'expired_at' => (string) $row->expires_at,
            'swept'      => !empty($row->expired_at),
        ];
    }

    /** The reference a human and an assistant can both say out loud. */
    public static function reference(int $id): string
    {
        return 'apr-' . $id;
    }

    /**
     * Which request a presented string names, or null when it is not shaped like
     * one. Used by the audit writer to point at the human decision behind a call —
     * never as a permission check (that is consume(), and it verifies the HMAC).
     */
    public static function id_from(string $secret): ?int
    {
        $parsed = self::parse(trim($secret));
        return $parsed === null ? null : $parsed[0];
    }

    // ==================================================================
    // THE DECISION (steps 3-4) — called only from the staff page
    // ==================================================================

    /**
     * Approve a pending request and mint the string.
     *
     * ONLY admin/mcp_approvals.php calls this: it runs on a signed-in session,
     * behind require_staff_subscribed() and a CSRF check, and it passes the
     * approving `tblusers.id`. Nothing in the MCP layer can reach it, which is
     * the point — an assistant cannot approve its own request.
     *
     * @return array{token:string, prefix:string, expires_at:string}
     * @throws McpApprovalError when the request is not approvable any more
     */
    public static function mint(int $id, int $approverUserId): array
    {
        if (!self::available()) {
            throw new McpApprovalError('approvals are not available on this install');
        }
        $row = self::find($id);
        if ($row === null) {
            throw new McpApprovalError('that approval request does not exist');
        }
        if (self::state_of($row) !== 'pending') {
            throw new McpApprovalError('that request is ' . self::state_of($row) . ' and can no longer be approved');
        }

        global $db;

        /*
         * The clock is the DATABASE's, not PHP's. Every other class here keeps
         * time arithmetic in SQL for exactly this reason, and the approval window
         * is a security boundary: a PHP box whose timezone or clock differs from
         * MySQL's must not be able to mint a string that the row then considers
         * expired (or worse, one that outlives its window). So the expiry is
         * computed by MySQL, and the epoch the HMAC signs is that same instant.
         */
        $expires  = (string) $db->fetchValue(
            'SELECT DATE_FORMAT(NOW() + INTERVAL :mins MINUTE, "%Y-%m-%d %H:%i:%s") AS t',
            [':mins' => self::APPROVAL_TTL_MINUTES]
        );
        $expUnix  = (int) $db->fetchValue('SELECT UNIX_TIMESTAMP(:t) AS e', [':t' => $expires]);
        $secret   = self::mint_string($row, (string) $row->payload_hash, $expUnix);

        $stmt = $db->execute(
            'UPDATE ' . self::TABLE . '
                SET approved_at = NOW(), approved_by = :by, approval_hash = :hash,
                    approval_prefix = :prefix, expires_at = :exp, payload_note = :note
              WHERE id = :id AND approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL
                AND expires_at > NOW()',
            [
                ':by'     => max(1, $approverUserId),
                ':hash'   => self::hash($secret),
                ':prefix' => mb_substr($secret, 0, 12),
                ':exp'    => $expires,
                ':note'   => mb_substr((string) $row->payload_note, 0, self::MAX_NOTE),
                ':id'     => $id,
            ]
        );
        if ($stmt->rowCount() !== 1) {
            // Another planner approved or denied it between the read and the
            // write; the loser of that race must not mint a second string.
            throw new McpApprovalError('that request was already decided');
        }

        return ['token' => $secret, 'prefix' => mb_substr($secret, 0, 12), 'expires_at' => $expires];
    }

    /**
     * An approval string: `mcpap_<id>-<expiry>-<hmac>`.
     *
     * The id and the expiry are readable (so a planner can see what it is and
     * when it dies); everything that DECIDES the action — the tool, its tier,
     * the booking, the payload hash, the asking token and the account — is inside
     * the signature, together with the expiry. That is what makes an approval
     * unmovable: it cannot be re-aimed at another booking, another account, a
     * changed SMS body, or a longer life, because any of those changes the
     * material and the HMAC stops matching.
     */
    private static function mint_string(object $row, string $payloadHash, int $expUnix): string
    {
        return self::SECRET_PREFIX . (int) $row->id . '-' . $expUnix
            . '-' . self::signature($row, $payloadHash, (int) ($row->token_id ?? 0), (int) $row->user_id, $expUnix);
    }

    /** The HMAC over one approval's deciding fields, with this install's key. */
    private static function signature(object $row, string $payloadHash, int $tokenId, int $userId, int $expUnix): string
    {
        $material = implode('|', [
            'v1',
            (int) $row->id,
            (string) $row->tool,
            (int) $row->tier,
            (int) ($row->booking_id ?? 0),
            $payloadHash,
            $tokenId,
            $userId,
            $expUnix,
        ]);
        return hash_hmac('sha256', $material, self::key());
    }

    /**
     * Pull `[id, expiry, hmac]` out of a presented string, or null when it is not
     * shaped like an approval at all.
     *
     * @return array{0:int, 1:int, 2:string}|null
     */
    private static function parse(string $secret): ?array
    {
        if (!str_starts_with($secret, self::SECRET_PREFIX)) {
            return null;
        }
        $parts = explode('-', substr($secret, strlen(self::SECRET_PREFIX)));
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $parts[2])) {
            return null;
        }
        return [(int) $parts[0], (int) $parts[1], $parts[2]];
    }

    // ==================================================================
    // THE USE (step 5) — called by the tool, once
    // ==================================================================

    /**
     * Verify an approval string for THIS tool and THIS payload, and consume it.
     *
     * Every check is a refusal with a readable sentence, because the assistant
     * has to be able to tell the planner what went wrong. Order matters: the
     * cheap format checks first, then the row, then the binding, then the
     * single-use UPDATE that actually consumes it.
     *
     * @param array<string,mixed> $principal the asking token's principal
     * @param array<string,mixed> $payload   the committed payload of THIS call
     * @return object the consumed row
     * @throws McpApprovalError on any mismatch
     */
    public static function consume(string $secret, array $principal, string $tool, array $payload): object
    {
        if (!self::available()) {
            throw new McpApprovalError('approvals are not available on this install');
        }
        $secret = trim($secret);
        $parsed = self::parse($secret);
        if ($parsed === null) {
            throw new McpApprovalError('that is not an approval string — mint one on the Approvals page');
        }
        [$approvalId, $expUnix, $presented] = $parsed;

        global $db;
        $row = self::find($approvalId);
        if ($row === null) {
            throw new McpApprovalError('that approval is not recognised');
        }

        /*
         * The signature first, then the state. Verification is a recomputation
         * over the row's own deciding fields plus the expiry the string carries,
         * so a tampered id, expiry, tool, booking or account fails HERE — before
         * a consumed_at race is even attempted.
         */
        $expected = self::signature($row, (string) $row->payload_hash,
            (int) ($row->token_id ?? 0), (int) $row->user_id, $expUnix);
        if (!hash_equals($expected, $presented)) {
            throw new McpApprovalError('that approval does not verify — ask for a fresh one');
        }
        if ($expUnix <= time()) {
            throw new McpApprovalError('that approval has expired — ask the planner to approve it again');
        }
        $state = self::state_of($row);
        if ($state !== 'approved') {
            // A string that was already spent gets the sentence an assistant can
            // act on (ask for a new one), not the bare state name.
            throw new McpApprovalError($state === 'used'
                ? 'that approval has already been used — request a new one'
                : 'that approval is ' . $state);
        }
        if (!hash_equals((string) $row->approval_hash, self::hash($secret))) {
            throw new McpApprovalError('that approval is not the one that was minted for this request');
        }
        if (!hash_equals((string) $row->tool, $tool)) {
            throw new McpApprovalError('that approval was minted for ' . (string) $row->tool . ', not ' . $tool);
        }
        if (!hash_equals((string) $row->payload_hash, self::payload_hash($tool, $payload))) {
            throw new McpApprovalError('that approval was minted for a different payload — the message, audience or amount must be identical');
        }
        if ((int) ($row->token_id ?? 0) !== (int) ($principal['token_id'] ?? 0)
            || (int) $row->user_id !== (int) ($principal['id'] ?? 0)) {
            throw new McpApprovalError('that approval was minted for another account');
        }

        // The exactly-once step. Losing this race means someone else already
        // performed the action with this approval, which is the whole point.
        $stmt = $db->execute(
            'UPDATE ' . self::TABLE . '
                SET consumed_at = NOW()
              WHERE id = :id AND consumed_at IS NULL AND denied_at IS NULL AND approved_at IS NOT NULL
                AND expires_at > NOW()',
            [':id' => (int) $row->id]
        );
        if ($stmt->rowCount() !== 1) {
            throw new McpApprovalError('that approval has already been used or has expired — request a new one');
        }

        $row->consumed_at = date('Y-m-d H:i:s');
        return $row;
    }

    /**
     * Refuse a pending request, with the planner's reason.
     *
     * @throws McpApprovalError
     */
    public static function deny(int $id, int $userId, string $reason): void
    {
        if (!self::available()) {
            throw new McpApprovalError('approvals are not available on this install');
        }
        global $db;
        $stmt = $db->execute(
            'UPDATE ' . self::TABLE . '
                SET denied_at = NOW(), denied_by = :by, deny_reason = :why
              WHERE id = :id AND approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL',
            [':by' => max(1, $userId), ':why' => mb_substr(trim($reason), 0, 255), ':id' => $id]
        );
        if ($stmt->rowCount() !== 1) {
            throw new McpApprovalError('that request was already decided, or does not exist');
        }
    }

    // ==================================================================
    // THE RE-REQUEST — a dead reference the planner can revive (v2.20)
    // ==================================================================

    /**
     * Revive an EXPIRED request as a NEW pending request for the same payload.
     *
     * This is the planner's half of the expiry bell: the sweep told them the
     * window closed, but until now the only way to act on that was to go back to
     * the assistant and ask for the same thing again. A planner who has read the
     * payload and still wants it can press ONE button instead.
     *
     * WHY A NEW ROW, NOT A REOPENED ONE. The old row's history is true as it
     * stands — it expired, nothing ran, the assistant was told — and reopening it
     * would rewrite that record. A new row keeps the chain honest, gets its own
     * reference and its own 30-minute window, and is bound to the SAME
     * `payload_hash`: the assistant's identical retry still matches, and a changed
     * message, amount, audience or booking still does not.
     *
     * WHY THE TOKEN MUST STILL BE ALIVE. The revived request belongs to the token
     * that asked, because the approval string may only be spent by that token. A
     * revoked, expired or deleted token cannot use it, so reviving would create a
     * decision nobody can carry out; the planner is told that instead.
     *
     * NOTHING IS MINTED HERE. The new row waits in the same inbox as any other
     * request, and approving it is the same explicit, CSRF-protected decision. The
     * button says "ask again", not "approve".
     *
     * ONLY admin/mcp_approvals.php calls this. The page has already checked that
     * the planner may open the wedding, exactly as it does for mint() and deny();
     * this class does not own session policy.
     *
     * @return array{id:int, reference:string, source_id:int, source_reference:string, expires_at:string, reused:bool}
     * @throws McpApprovalError when the request is not expired, its token is gone, or the tables are missing
     */
    public static function revive(int $id, int $userId): array
    {
        if (!self::available()) {
            throw new McpApprovalError('approvals are not available on this install');
        }

        global $db;
        $db->beginTransaction();
        try {
            /*
             * FOR UPDATE makes a double-click one request: the second click waits,
             * then sees the first click's pending row below and returns it rather
             * than creating a second identical question.
             */
            $row = $db->fetchOne(
                'SELECT a.*, (a.expires_at > NOW()) AS live_now
                   FROM ' . self::TABLE . ' a WHERE a.id = :id LIMIT 1 FOR UPDATE',
                [':id' => $id]
            );
            if ($row === null) {
                throw new McpApprovalError('that approval request does not exist');
            }
            $state = self::state_of($row);
            if ($state !== 'expired') {
                throw new McpApprovalError('only an expired request can be re-requested — that one is ' . $state);
            }

            $tokenId = (int) ($row->token_id ?? 0);
            $liveToken = $tokenId > 0 && (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . ApiToken::TABLE . '
                  WHERE id = :id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())',
                [':id' => $tokenId]
            ) > 0;
            $principal = $liveToken
                ? ApiToken::principal((string) ($row->recipient_type ?? ApiToken::KIND_NONE), (int) ($row->user_id ?? 0))
                : null;
            if (!$liveToken || $principal === null) {
                throw new McpApprovalError(
                    'the token that asked for this is no longer active, so a fresh approval could not be used — '
                    . 'ask the assistant to make the request with a current token'
                );
            }

            // The same tool and payload already waiting? Then the "revival" is
            // already on the page (a second click, or the assistant re-asked in
            // the meantime). Return that reference instead of a duplicate row.
            $existing = $db->fetchOne(
                'SELECT id, expires_at FROM ' . self::TABLE . '
                  WHERE token_id = :token AND tool = :tool AND payload_hash = :hash
                    AND approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL
                    AND expires_at > NOW()
               ORDER BY id DESC LIMIT 1',
                [':token' => $tokenId, ':tool' => (string) $row->tool, ':hash' => (string) $row->payload_hash]
            );
            if ($existing !== null) {
                $db->commit();
                return [
                    'id'               => (int) $existing->id,
                    'reference'        => self::reference((int) $existing->id),
                    'source_id'        => $id,
                    'source_reference' => self::reference($id),
                    'expires_at'       => (string) $existing->expires_at,
                    'reused'           => true,
                ];
            }

            $params = [
                ':token' => $tokenId,
                ':kind'  => (string) ($row->recipient_type ?? ApiToken::KIND_NONE),
                ':uid'   => (int) ($row->user_id ?? 0),
                ':tool'  => (string) $row->tool,
                ':tier'  => max(self::TIER, (int) ($row->tier ?? self::TIER)),
                ':bid'   => (int) ($row->booking_id ?? 0) > 0 ? (int) $row->booking_id : null,
                ':hash'  => (string) $row->payload_hash,
                ':note'  => mb_substr((string) $row->payload_note, 0, self::MAX_NOTE),
                ':mins'  => self::REQUEST_TTL_MINUTES,
            ];

            /*
             * Two literal INSERTs, not one assembled from strings: the project's
             * promise sweep reads the SQL text to learn which columns are written,
             * and a column that only appears in a runtime-built fragment looks
             * read-but-never-written. The branch is about the migration, not the
             * data, so both statements are visible here.
             */
            if (self::rerequest_marker_installed()) {
                $params[':from'] = $id;
                $params[':by']   = max(1, $userId);
                $db->execute(
                    'INSERT INTO ' . self::TABLE . '
                        (token_id, recipient_type, user_id, tool, tier, booking_id, payload_hash, payload_note, expires_at, re_request_of, re_requested_by)
                     VALUES (:token, :kind, :uid, :tool, :tier, :bid, :hash, :note, NOW() + INTERVAL :mins MINUTE, :from, :by)',
                    $params
                );
            } else {
                $db->execute(
                    'INSERT INTO ' . self::TABLE . '
                        (token_id, recipient_type, user_id, tool, tier, booking_id, payload_hash, payload_note, expires_at)
                     VALUES (:token, :kind, :uid, :tool, :tier, :bid, :hash, :note, NOW() + INTERVAL :mins MINUTE)',
                    $params
                );
            }
            $newId = (int) $db->the_insert_id();
            $fresh = self::find($newId);
            $db->commit();

            return [
                'id'               => $newId,
                'reference'        => self::reference($newId),
                'source_id'        => $id,
                'source_reference' => self::reference($id),
                'expires_at'       => (string) ($fresh->expires_at ?? ''),
                'reused'           => false,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Is the v2.20 `re_request_of` lineage installed? Like the v2.19 marker, a
     * live site mid-upgrade still works: the revival falls back to a request with
     * no recorded source rather than failing the click.
     */
    public static function rerequest_marker_installed(): bool
    {
        if (self::$rerequestMarker !== null) {
            return self::$rerequestMarker;
        }
        try {
            global $db;
            self::$rerequestMarker = (bool) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'
                    AND COLUMN_NAME IN ('re_request_of', 're_requested_by')"
            ) >= 2;
        } catch (Throwable $e) {
            self::$rerequestMarker = false;
        }
        return self::$rerequestMarker;
    }

    // ==================================================================
    // THE SWEEP — the window that closed in silence (v2.19)
    // ==================================================================

    /**
     * Close every request whose 30-minute window passed with no decision: mark
     * it once, write the assistant's record, and ring one bell per planner.
     *
     * Called by scripts/mcp_approval_sweep.php (cron). WHY A SWEEP AND NOT JUST
     * `expires_at < NOW()` ON THE PAGE: an expiry that nobody is told about is
     * indistinguishable, from both ends, from a request still waiting. The
     * planner never learns the SMS did not go out; the assistant's retry mints a
     * new reference as if the first had not existed; and the call log has no line
     * saying what happened. So the closure is made audible on both sides and
     * written down:
     *
     *   - ROW: `expired_at` set ONCE. The UPDATE races on `expired_at IS NULL`
     *     (the same discipline consume() uses on `consumed_at`), so repeated cron
     *     runs are silent and a planner deciding at that instant wins.
     *   - ASSISTANT: one tblapi_audit row per expiry, pointing at the approval,
     *     its payload hash and the reference — `ok` is false because nothing ran.
     *   - PLANNER: ONE digest notification per planner per run (+ email through
     *     Notification), never one per row.
     *
     * TWO KINDS DIE THE SAME WAY, and both are swept:
     *
     *   unanswered   the planner never decided. The assistant waits forever if
     *                nobody says so.
     *   unused       the planner DID approve, and the assistant never used the
     *                string inside its 15 minutes (mint() moves expires_at to
     *                NOW()+15, so that window is the string's own). The planner
     *                believes the SMS went out; it did not.
     *
     * A DENIED row is never swept: a refusal is an answer, and it stays on the
     * record as one. A CONSUMED row is never swept either — the write happened.
     *
     * Never throws: this is a housekeeping job, and a sweep that could break its
     * own cron run is worse than the silence it exists to report. Every failure
     * is logged and counted, the rest of the batch still runs.
     *
     * @param bool $dryRun count and group, write nothing (operator check)
     * @param int  $limit  most rows this run closes; the job re-runs anyway
     * @return array{installed:bool, dry_run:bool, open:int, expired:int, unanswered:int, unused:int, audited:int, notified:int, limited:bool, planners:array<int,string[]>}
     */
    public static function sweep_expired(bool $dryRun = false, int $limit = self::SWEEP_LIMIT): array
    {
        $report = [
            'installed' => self::expiry_marker_installed(),
            'dry_run'   => $dryRun,
            'open'      => 0,
            'expired'   => 0,
            'unanswered' => 0,
            'unused'    => 0,
            'audited'   => 0,
            'notified'  => 0,
            'limited'   => false,
            'planners'  => [],
        ];
        if (!self::available() || !$report['installed']) {
            return $report;
        }

        global $db;
        $limit = max(1, min(500, $limit));
        /*
         * `expires_at <= NOW()` is the whole test: state_of() reads live_now
         * BEFORE approved_at, so a row whose window closed can no longer be
         * consumed whatever was decided about it. Denied rows are excluded — a
         * refusal is an answer and is already reported to the assistant.
         */
        $where = 'denied_at IS NULL AND consumed_at IS NULL'
            . ' AND expired_at IS NULL AND expires_at <= NOW()';

        try {
            $report['open'] = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where);
            $rows = $db->fetchAll(
                'SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY expires_at ASC, id ASC LIMIT :lim',
                [':lim' => $limit]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] approval sweep could not read the queue: ' . $e->getMessage());
            return $report;
        }
        $report['limited'] = $report['open'] > count($rows);

        $byPlanner = [];
        foreach ($rows as $row) {
            $id  = (int) $row->id;
            $ref = self::reference($id);

            if (!$dryRun) {
                try {
                    $stmt = $db->execute(
                        'UPDATE ' . self::TABLE . ' SET expired_at = NOW()
                          WHERE id = :id AND denied_at IS NULL AND consumed_at IS NULL
                            AND expired_at IS NULL AND expires_at <= NOW()',
                        [':id' => $id]
                    );
                } catch (Throwable $e) {
                    error_log('[WPMS][Mcp] approval ' . $ref . ' could not be closed: ' . $e->getMessage());
                    continue;
                }
                if ($stmt->rowCount() !== 1) {
                    continue; // decided, consumed or swept by another run — theirs to report
                }
                $report['expired']++;
                $kind = empty($row->approved_at) ? 'unanswered' : 'unused';
                if (self::record_expiry($row, $ref, (string) $row->expires_at, $kind)) {
                    $report['audited']++;
                }
            } else {
                $report['expired']++;
                $kind = empty($row->approved_at) ? 'unanswered' : 'unused';
            }
            $report[$kind]++;

            $key = (string) ($row->recipient_type ?? '') === ApiToken::KIND_USER && (int) $row->user_id > 0
                ? (int) $row->user_id
                : 0;
            $byPlanner[$key][] = $ref . '|' . (string) $row->tool . '|' . (int) ($row->booking_id ?? 0) . '|' . $kind;
        }

        if (!$dryRun) {
            foreach ($byPlanner as $staffId => $entries) {
                if (self::alert_planner($staffId, $entries)) {
                    $report['notified']++;
                }
            }
        }
        foreach ($byPlanner as $staffId => $entries) {
            $report['planners'][$staffId] = array_map(
                static fn(string $entry): string => explode('|', $entry)[0],
                $entries
            );
        }
        return $report;
    }

    /**
     * The assistant's record of a request that died: an audit row naming the
     * approval, the tool, the payload hash and the reference, with `ok` false
     * because NOTHING RAN.
     *
     * The note is the sentence the next call is answered with, so a model reading
     * its own history sees what happened without a second query: the window
     * closed, the request is dead, nothing was sent or recorded.
     */
    private static function record_expiry(object $row, string $ref, string $expiresAt, string $kind): bool
    {
        $error = $kind === 'unused'
            ? 'approval was approved but never used before it expired — nothing was sent or recorded'
            : 'approval request expired unanswered — nothing was sent or recorded';
        $note = $kind === 'unused'
            ? $ref . ' was approved but never used; its window closed at ' . $expiresAt . ' — nothing was sent or recorded'
            : $ref . ' expired unanswered at ' . $expiresAt . ' — nothing was sent or recorded';
        try {
            ApiToken::audit(
                isset($row->token_id) && (int) $row->token_id > 0 ? (int) $row->token_id : null,
                (string) ($row->recipient_type ?? ApiToken::KIND_NONE),
                (int) ($row->user_id ?? 0),
                (string) ($row->tool ?? ''),
                'approval',
                false,
                $error,
                [],
                (int) ($row->booking_id ?? 0) > 0 ? (int) $row->booking_id : null,
                0,
                [
                    'tier'         => self::TIER,
                    'approval_id'  => (int) $row->id,
                    'payload_hash' => (string) ($row->payload_hash ?? ''),
                    'outcome_note' => mb_substr($note, 0, 255),
                ]
            );
            return ApiToken::available();
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] expiry record for ' . $ref . ' not written: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * One bell (+ email via Notification) for one planner's expired references.
     *
     * A staff id of 0 means the asking principal is gone or was never a staff
     * account, so the install's operators are told instead — the same fallback
     * LoginThrottle and JobWatch use when there is no owner to warn. A deleted
     * planner gets no row it cannot read; the operators get the finding.
     *
     * @param string[] $entries ref|tool|booking_id|kind
     */
    private static function alert_planner(int $staffId, array $entries): bool
    {
        if (!class_exists('Notification', false)) {
            return false;
        }

        $parts  = [];
        $books  = [];
        foreach ($entries as $entry) {
            [$ref, $tool, $bookingId, $kind] = array_pad(explode('|', $entry), 4, '');
            $parts[] = $kind === 'unused'
                ? $ref . ' (' . $tool . ') was approved but never used'
                : $ref . ' (' . $tool . ') expired unanswered';
            $books[(int) $bookingId] = true;
        }

        $n = count($parts);
        if ($n === 1) {
            $message = $parts[0] . ' — nothing was sent or recorded. Open AI Approvals to see it; '
                . 'if you still want it, Re-request it there.';
        } else {
            $named   = array_slice($parts, 0, self::SWEEP_REFS_IN_ALERT);
            $message = $n . ' AI requests died without acting: ' . implode('; ', $named)
                . ($n > count($named) ? '; and ' . ($n - count($named)) . ' more' : '')
                . ' — nothing was sent or recorded. Open AI Approvals to Re-request it.';
        }

        $targets = $staffId > 0 && self::staff_exists($staffId)
            ? [$staffId]
            : (class_exists('JobWatch', false) ? JobWatch::operator_ids() : []);
        $booking = count($books) === 1 ? (int) array_key_first($books) : 0;

        $sent = false;
        foreach ($targets as $id) {
            try {
                Notification::create_for_staff(
                    (int) $id,
                    $booking,
                    mb_substr($message, 0, self::ALERT_MAX),
                    'mcp_approvals.php',
                    'AI approval request expired unanswered'
                );
                $sent = true;
            } catch (Throwable $e) {
                error_log('[WPMS][Mcp] expiry alert not delivered to user ' . $id . ': ' . $e->getMessage());
            }
        }
        return $sent;
    }

    /** Does the account a request was made for still exist in tblusers? */
    private static function staff_exists(int $staffId): bool
    {
        try {
            global $db;
            return (bool) $db->fetchValue('SELECT COUNT(*) FROM tblusers WHERE id = :id', [':id' => $staffId]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Is the v2.19 `expired_at` marker installed? A live site mid-upgrade keeps
     * serving and keeps sweeping nothing — the job reports "migration missing"
     * rather than failing every ten minutes on an unknown column.
     */
    public static function expiry_marker_installed(): bool
    {
        if (self::$expiryMarker !== null) {
            return self::$expiryMarker;
        }
        try {
            global $db;
            self::$expiryMarker = (bool) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'
                    AND COLUMN_NAME = 'expired_at'"
            );
        } catch (Throwable $e) {
            self::$expiryMarker = false;
        }
        return self::$expiryMarker;
    }

    // ==================================================================
    // READING THE RECORD (the page, and the tests)
    // ==================================================================

    /**
     * One request by id.
     *
     * The row carries `live_now` — the DATABASE's own verdict on whether it is
     * still inside its window — because PHP comparing that timestamp to its own
     * clock is how a valid approval gets reported as expired on a host whose
     * timezone differs from MySQL's.
     */
    public static function find(int $id): ?object
    {
        if (!self::available()) {
            return null;
        }
        global $db;
        return $db->fetchOne(
            'SELECT a.*, (a.expires_at > NOW()) AS live_now FROM ' . self::TABLE . ' a WHERE a.id = :id LIMIT 1',
            [':id' => $id]
        ) ?: null;
    }

    /**
     * Awaiting a human: undecided, unexpired, oldest first — the order a
     * planner should work through them.
     *
     * @return array<int,object>
     */
    public static function pending(int $limit = 50): array
    {
        if (!self::available()) {
            return [];
        }
        global $db;
        return $db->fetchAll(
            'SELECT a.*, (a.expires_at > NOW()) AS live_now, t.name AS token_name, t.token_prefix
               FROM ' . self::TABLE . ' a
               LEFT JOIN ' . ApiToken::TABLE . ' t ON t.id = a.token_id
              WHERE a.approved_at IS NULL AND a.denied_at IS NULL AND a.consumed_at IS NULL
                AND a.expires_at > NOW()
           ORDER BY a.created_at ASC, a.id ASC
              LIMIT :lim',
            [':lim' => max(1, min(200, $limit))]
        );
    }

    /**
     * Decided requests, newest first: what a planner already approved, what an
     * assistant then did with it, what was refused — and, since v2.19, what
     * expired with no decision at all.
     *
     * The expired-unanswered rows are INCLUDED deliberately. They used to vanish
     * from both tables on this page, so a reference the assistant quoted was
     * nowhere a planner could look it up, and the request read as if it had never
     * existed. History is the one place that can answer "what happened to apr-57?".
     *
     * @return array<int,object>
     */
    public static function decided(int $limit = 25): array
    {
        if (!self::available()) {
            return [];
        }
        global $db;
        /*
         * "Expired" is a fact about the clock, not about the sweep having run.
         * The page must offer Re-request the moment the window closes, even on an
         * install whose cron is a few minutes behind; `expired_at` only adds the
         * rows the sweep has already reported, for the install that upgrades
         * mid-cycle.
         */
        $expired = ' OR a.expires_at <= NOW()'
            . (self::expiry_marker_installed() ? ' OR a.expired_at IS NOT NULL' : '');
        $revived = self::rerequest_marker_installed()
            ? ', (SELECT MAX(r.id) FROM ' . self::TABLE . ' r WHERE r.re_request_of = a.id) AS revived_as_id'
            : '';
        return $db->fetchAll(
            'SELECT a.*, (a.expires_at > NOW()) AS live_now' . $revived . ', t.name AS token_name, t.token_prefix, u.username AS approved_by_name
               FROM ' . self::TABLE . ' a
               LEFT JOIN ' . ApiToken::TABLE . ' t ON t.id = a.token_id
               LEFT JOIN tblusers u ON u.id = a.approved_by
              WHERE a.approved_at IS NOT NULL OR a.denied_at IS NOT NULL' . $expired . '
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT :lim',
            [':lim' => max(1, min(200, $limit))]
        );
    }

    /**
     * Where a request stands, in one word — derived from the timestamps so
     * there is no status column to fall out of step with them.
     */
    public static function state_of(object $row): string
    {
        if (!empty($row->denied_at)) {
            return 'denied';
        }
        if (!empty($row->consumed_at)) {
            return 'used';
        }
        // The database's verdict when the row was loaded (see find()); PHP's own
        // clock only as a fallback for a row handed in from somewhere else.
        $live = property_exists($row, 'live_now')
            ? (int) $row->live_now === 1
            : strtotime((string) $row->expires_at) > time();
        if (!$live) {
            return 'expired';
        }
        if (!empty($row->approved_at)) {
            return 'approved';
        }
        return 'pending';
    }

    /** Count awaiting a human — the sidebar badge, and the page's own heading. */
    public static function pending_count(): int
    {
        if (!self::available()) {
            return 0;
        }
        global $db;
        try {
            return (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                  WHERE approved_at IS NULL AND denied_at IS NULL AND consumed_at IS NULL AND expires_at > NOW()'
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Are the v2.17 objects installed? Cached per request. */
    public static function available(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }
        try {
            global $db;
            self::$installed = (bool) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
            ) && (bool) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . ApiToken::AUDIT_TABLE . "'
                    AND COLUMN_NAME IN ('tier', 'request_id', 'approval_id', 'payload_hash', 'outcome_note')"
            ) >= 5;
        } catch (Throwable $e) {
            self::$installed = false;
        }
        return self::$installed;
    }

    /** Requests and decisions past retention — called by scripts/notify_prune.php. */
    public static function prune(int $days = ApiToken::AUDIT_KEEP_DAYS): int
    {
        if (!self::available()) {
            return 0;
        }
        try {
            global $db;
            $stmt = $db->execute(
                'DELETE FROM ' . self::TABLE . ' WHERE created_at < NOW() - INTERVAL :days DAY',
                [':days' => max(1, $days)]
            );
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] approval prune failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private static function tokenId(array $principal): ?int
    {
        $id = (int) ($principal['token_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function kind(array $principal): string
    {
        $kind = (string) ($principal['kind'] ?? ApiToken::KIND_NONE);
        return in_array($kind, [ApiToken::KIND_USER, ApiToken::KIND_ACCOUNT], true) ? $kind : ApiToken::KIND_NONE;
    }
}

/** An approval refusal the assistant (and the planner) can read. */
final class McpApprovalError extends RuntimeException
{
}
