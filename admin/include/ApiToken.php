<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.16 — AI access on this platform's own terms (ApiToken)
 *
 * An MCP endpoint (mcp.php) lets an AI client query, and later act on, real
 * wedding data. The AI is the CLIENT. This class is what keeps the platform the
 * authority, and it does that with one idea:
 *
 *   A TOKEN IS A PERSON. Everything an MCP call may see or do is decided by the
 *   account the token belongs to, using the same functions the pages already
 *   use — not by anything the AI sends.
 *
 * So a token resolves to a PRINCIPAL (`tblusers.id` for staff, super admins and
 * vendor leads; `tblaccounts.user_id` for couples — the two id spaces of v2.13,
 * kept apart exactly as the bell feed keeps them apart), and the call is then
 * run with the session that principal would have had:
 *
 *   ApiToken::authenticate($secret)   → who is asking (never from arguments)
 *   ApiToken::bind_principal($p)      → $_SESSION becomes that account
 *   …the tool calls booking_visible_to_session(), may_open_couple_page(),
 *   is_staff(), is_super_admin(), the models themselves…
 *
 * THAT BRIDGE IS THE WHOLE SECURITY ARGUMENT. The alternative — teaching every
 * tool its own copy of the row rules — is how a codebase ends up with two
 * answers to "may this account open this booking", and the day they disagree the
 * AI is the door that is wrong. Here there is one implementation, and the AI
 * cannot reach a booking the account could not open in a browser.
 *
 * WHY A TOKEN AND NOT A SESSION. An AI client is a machine: it has no cookies,
 * no CSRF token and no browser. A session cookie handed to an agent would also
 * be a session the user cannot see or revoke — so tokens are separate rows that
 * are hashed at rest, carry an expiry, carry SCOPES (a ceiling below the
 * account's own powers), can be narrowed to one wedding, and can be switched off
 * individually without touching the person's password.
 *
 * WHAT THIS CLASS WILL NOT DO:
 *   - store a secret (only sha256 of it — the plaintext is shown once, at mint);
 *   - treat a missing or unreadable token table as "no tokens, everyone
 *     welcome": authenticate() refuses, and the endpoint answers 503, because an
 *     auth stack that fails open is not an auth stack;
 *   - grant `admin:*` to a token whose principal is not a super admin, whatever
 *     was asked for at mint time;
 *   - write an argument VALUE into the audit trail (see audit()).
 *
 * Storage: migrations/v2.16_api_tokens.sql. All time arithmetic happens in SQL
 * against the DB session timezone (Africa/Nairobi) — the same discipline as
 * LoginThrottle, PublicGuard and JobWatch.
 */
final class ApiToken
{
    public const TABLE       = 'tblapi_tokens';
    public const AUDIT_TABLE = 'tblapi_audit';

    /** The principal's id indexes `tblusers.id` — staff, super admins, vendors. */
    public const KIND_USER = 'user';
    /** The principal's id indexes `tblaccounts.user_id` — couples. */
    public const KIND_ACCOUNT = 'account';
    /** No principal resolved (a call refused before authentication). */
    public const KIND_NONE = 'none';

    /** Prefix on every secret, so a leaked string is recognisable. */
    public const SECRET_PREFIX = 'wpms_';

    /** 32 random bytes → 64 hex characters of entropy behind the prefix. */
    public const SECRET_BYTES = 32;

    /** Tokens minted without an explicit lifetime expire after this. */
    public const DEFAULT_TTL_DAYS = 90;

    /** Audit rows older than this are pruned (by scripts/notify_prune.php). */
    public const AUDIT_KEEP_DAYS = 90;

    /** Longest `created_at`-style stamp this class writes. */
    public const MAX_NAME = 96;

    /**
     * The identity a KEY's failures are counted under in `tbllogin_throttle`:
     * `mcp:<token id>` — see throttle_key(). Namespaced so it can never collide
     * with an email, and so LoginThrottle's per-IP sign-in cap can leave these
     * rows out of the human path on purpose (LoginThrottle::secondsRemaining()).
     */
    public const THROTTLE_PREFIX = 'mcp:';

    /**
     * The scope catalogue — the contract between a token and the tools.
     *
     * Read scopes are what Phase 1's tools need. Write scopes are the ceiling for
     * Phase 3's tools and are already grantable: a write scope is inert until
     * WPMS_MCP_WRITES=1 (McpServer checks it), so a token carrying `write:guests`
     * on a writing-disabled install can still only read.
     *
     * `admin:system` is the operator's own surface (job health, platform pulse).
     * It is refused at mint time for any principal that is not a super admin, and
     * the tool that needs it re-checks — a token must never be a way to launder
     * privileges.
     */
    public const SCOPES = [
        'read:weddings' => 'see the weddings this account may open, and their overview',
        'read:guests'   => 'see guest lists, RSVP answers, dietary and transport needs',
        'read:tasks'    => 'see the planning checklist and what is done or overdue',
        'read:budget'   => 'see the package price, payments received and the balance',
        'read:vendors'  => 'see booked vendors, their packages and the vendor catalogue',
        'read:subscription' => 'see this account\'s own plan, status and renewal date, and the plan catalogue',
        'read:sms'      => 'see SMS credits, and what a message to an audience would cost',
        'write:guests'  => 'add or change guests, invite them, answer RSVPs   (Tier 2; needs WPMS_MCP_WRITES=1)',
        'write:tasks'   => 'add or tick checklist items                       (Tier 2; needs WPMS_MCP_WRITES=1)',
        'write:budget'  => 'record payments and set the installment plan      (Tier 3; needs a human approval)',
        'write:vendors' => 'send vendor enquiries on this account\'s behalf    (needs WPMS_MCP_WRITES=1)',
        'write:sms'     => 'SMS a wedding\'s guest list                       (Tier 3; needs a human approval)',
        'write:planners' => 'set which planner owns a wedding — organizer_id, which decides who else can see it (Tier 3; needs a human approval)',
        'admin:system'  => 'super admins only: the platform\'s own health, statistics and the findings that need attention',
    ];

    /** The scopes a tool call needs before anything else happens. */
    public const READ_SCOPES = [
        'read:weddings', 'read:guests', 'read:tasks', 'read:budget', 'read:vendors',
        'read:subscription', 'read:sms',
    ];

    /** The staff-only scopes: a couple's or vendor's token never carries these. */
    public const STAFF_SCOPES = ['read:subscription', 'read:sms'];

    /** Scopes that change data — inert until WPMS_MCP_WRITES=1. */
    public const WRITE_SCOPES = ['write:guests', 'write:tasks', 'write:budget', 'write:vendors', 'write:sms', 'write:planners'];

    /** Scopes only a super admin may hold. */
    public const ADMIN_SCOPES = ['admin:system'];

    /** Cached "are the tables installed?" answer for this request. */
    private static ?bool $installed = null;

    // ==================================================================
    // SCOPES
    // ==================================================================

    /** Every scope key, in catalogue order. @return string[] */
    public static function scope_keys(): array
    {
        return array_keys(self::SCOPES);
    }

    /** The scopes that only a super admin may hold. @return string[] */
    public static function admin_scope_keys(): array
    {
        return self::ADMIN_SCOPES;
    }

    /** Is this a scope this build knows? */
    public static function known_scope(string $scope): bool
    {
        return isset(self::SCOPES[$scope]);
    }

    /** Is this scope one that changes data (and so needs the write switch)? */
    public static function is_write_scope(string $scope): bool
    {
        return in_array($scope, self::WRITE_SCOPES, true);
    }

    /** Does the principal's token carry this scope? */
    public static function has_scope(array $principal, string $scope): bool
    {
        return in_array($scope, $principal['scopes'] ?? [], true);
    }

    /**
     * Is this principal a member of staff (planner or super admin)?
     *
     * The SAME two role names principal() stamps from `tblusers.access_level`,
     * so a tool that is staff-only is judged here exactly as is_staff() judges
     * the person in a browser — one vocabulary, not two.
     */
    public static function principal_is_staff(array $principal): bool
    {
        return in_array((string) ($principal['role'] ?? ''), ['staff', 'super_admin'], true);
    }

    /** Is this scope one only staff may hold? (offered and refused at mint) */
    public static function is_staff_scope(string $scope): bool
    {
        return in_array($scope, self::STAFF_SCOPES, true);
    }

    /**
     * The scopes an account is offered by default: everything it could usefully
     * read, and nothing that writes. A super admin additionally gets the operator
     * surface — nobody else can hold it (mint() enforces it).
     *
     * @return string[]
     */
    public static function default_scopes(array $principal): array
    {
        $scopes = self::READ_SCOPES;
        // A couple or a vendor is not offered the staff scopes, and could not
        // hold them if it were (mint() refuses them).
        if (!self::principal_is_staff($principal)) {
            $scopes = array_values(array_diff($scopes, self::STAFF_SCOPES));
        }
        if (($principal['role'] ?? '') === 'super_admin') {
            $scopes[] = 'admin:system';
        }
        return $scopes;
    }

    /** Keep only scopes this build knows, de-duplicated, in catalogue order. @return string[] */
    public static function normalise_scopes(array $scopes): array
    {
        $seen = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && self::known_scope($scope)) {
                $seen[$scope] = true;
            }
        }
        return array_values(array_filter(self::scope_keys(), static fn(string $s): bool => isset($seen[$s])));
    }

    /** The csv form stored in the column. */
    private static function pack_scopes(array $scopes): string
    {
        return implode(',', self::normalise_scopes($scopes));
    }

    /** The csv column back into a list. @return string[] */
    private static function unpack_scopes(?string $csv): array
    {
        $out = [];
        foreach (explode(',', (string) $csv) as $scope) {
            $scope = trim($scope);
            if ($scope !== '' && self::known_scope($scope)) {
                $out[] = $scope;
            }
        }
        return $out;
    }

    // ==================================================================
    // MINTING AND REVOKING
    // ==================================================================

    /**
     * Create a token for one principal. Returns the SECRET ONCE — it is not
     * recoverable afterwards, by design.
     *
     * @param string   $kind      self::KIND_USER | self::KIND_ACCOUNT
     * @param int      $userId    the principal's id in the table $kind names
     * @param string[] $scopes    requested scopes; unknown ones are dropped
     * @param int|null $ttlDays   lifetime; null = DEFAULT_TTL_DAYS, 0 = never expires
     * @return array{id:int, secret:string, prefix:string, scopes:string[], expires_at:?string}
     * @throws InvalidArgumentException on an unknown kind, an unknown principal, or a scope the principal may not hold
     */
    public static function mint(
        string $kind,
        int $userId,
        string $name,
        array $scopes,
        ?int $bookingId = null,
        ?int $ttlDays = null,
        ?int $createdBy = null
    ): array {
        self::assert_kind($kind);
        $principal = self::principal($kind, $userId);
        if ($principal === null) {
            throw new InvalidArgumentException('ApiToken: no account ' . $userId . ' in the table ' . $kind . ' names');
        }

        $scopes = self::normalise_scopes($scopes);

        /*
         * Privilege laundering, refused at the mint as well as at the call: a
         * planner's token can never carry the operator's scope, so a stolen or
         * mistaken token cannot be the thing that grants a back-office view.
         */
        foreach (self::ADMIN_SCOPES as $adminScope) {
            if (in_array($adminScope, $scopes, true) && $principal['role'] !== 'super_admin') {
                throw new InvalidArgumentException(
                    'ApiToken: only a super admin may hold the ' . $adminScope . ' scope'
                );
            }
        }

        /*
         * Staff scopes are NARROWED rather than refused, and the difference is
         * deliberate: an admin scope grants a back office, so asking for one is a
         * privilege error worth failing loudly. A staff read scope is inert for
         * anyone else — the plan, the renewal date and the SMS credits are read by
         * tools that are role-gated to staff as well — so a couple's token minted
         * from a list that happens to include them simply does not carry them.
         * The invariant is the same either way: a non-staff token never HOLDS
         * read:subscription or read:sms, so it can never spend them.
         */
        $scopes = array_values(array_diff($scopes, self::STAFF_SCOPES));

        $secret = self::new_secret();
        $days   = $ttlDays === null ? self::DEFAULT_TTL_DAYS : max(0, $ttlDays);
        $expiry = $days === 0 ? null : date('Y-m-d H:i:s', time() + ($days * 86400));

        try {
            global $db;
            $stmt = $db->prepare(
                'INSERT INTO ' . self::TABLE
                . ' (recipient_type, user_id, name, token_hash, token_prefix, scopes, booking_id, expires_at, created_by)'
                . ' VALUES (:kind, :uid, :name, :hash, :prefix, :scopes, :bid, :expires, :by)'
            );
            $stmt->execute([
                ':kind'    => $kind,
                ':uid'     => $userId,
                ':name'    => mb_substr(trim($name), 0, self::MAX_NAME),
                ':hash'    => self::hash($secret),
                ':prefix'  => self::prefix_of($secret),
                ':scopes'  => self::pack_scopes($scopes),
                ':bid'     => $bookingId !== null && $bookingId > 0 ? $bookingId : null,
                ':expires' => $expiry,
                ':by'      => $createdBy !== null && $createdBy > 0 ? $createdBy : null,
            ]);
            $id = (int) $db->the_insert_id();
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] token not minted: ' . $e->getMessage());
            throw new RuntimeException('the token could not be stored — is migrations/v2.16_api_tokens.sql applied?');
        }

        return ['id' => $id, 'secret' => $secret, 'prefix' => self::prefix_of($secret), 'scopes' => $scopes, 'expires_at' => $expiry];
    }

    /**
     * Switch a token off. Owner-scoped: a token may only be revoked by the
     * principal that owns it (an operator revokes their own, not other people's).
     */
    public static function revoke(int $id, string $kind, int $userId): bool
    {
        self::assert_kind($kind);
        try {
            global $db;
            $stmt = $db->prepare(
                'UPDATE ' . self::TABLE . ' SET revoked_at = NOW()
                  WHERE id = :id AND recipient_type = :kind AND user_id = :uid AND revoked_at IS NULL'
            );
            $stmt->execute([':id' => $id, ':kind' => $kind, ':uid' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] token not revoked: ' . $e->getMessage());
            return false;
        }
    }

    /** One token row (no secret — there is none to return). */
    public static function find(int $id): ?object
    {
        if (!self::available()) {
            return null;
        }
        try {
            global $db;
            return $db->fetchOne('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1', [':id' => $id]);
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] token not readable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The tokens one account owns, newest first — the "my tokens" page. Revoked
     * rows are included (an owner should see what they switched off) but the
     * caller can filter them out.
     *
     * @return object[]
     */
    public static function for_owner(string $kind, int $userId): array
    {
        self::assert_kind($kind);
        if (!self::available()) {
            return [];
        }
        try {
            global $db;
            return $db->fetchAll(
                'SELECT id, recipient_type, user_id, name, token_prefix, scopes, booking_id,
                        expires_at, last_used_at, last_used_ip, created_at, created_by, revoked_at,
                        (expires_at IS NOT NULL AND expires_at <= NOW()) AS expired
                   FROM ' . self::TABLE . '
                  WHERE recipient_type = :kind AND user_id = :uid
               ORDER BY id DESC',
                [':kind' => $kind, ':uid' => $userId]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] tokens not readable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * How many live (unrevoked, unexpired) tokens this account has — the number
     * the token page and the settings summary show.
     */
    public static function live_count(string $kind, int $userId): int
    {
        self::assert_kind($kind);
        if (!self::available()) {
            return 0;
        }
        try {
            global $db;
            return (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                  WHERE recipient_type = :kind AND user_id = :uid AND revoked_at IS NULL
                    AND (expires_at IS NULL OR expires_at > NOW())',
                [':kind' => $kind, ':uid' => $userId]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * What has this account's AI been doing? The last few audit rows for a set of
     * token ids, newest first, each carrying the tool, whether it worked, why it
     * did not, and how long it took.
     *
     * The question the token page answers is "what has the AI been doing with my
     * data" — so this reads by the OWNER's tokens (ids resolved from for_owner(),
     * i.e. scoped to the signed-in account) and never by token id supplied from
     * outside. A row whose token has since been revoked still shows: switching a
     * token off must not erase what it already saw.
     *
     * @param int[] $tokenIds
     * @return object[]
     */
    public static function recent_audit(array $tokenIds, int $limit = 25): array
    {
        $ids = self::clean_ids($tokenIds);
        if ($ids === [] || !self::available()) {
            return [];
        }
        try {
            global $db;
            $in   = bound_id_list($ids);
            /*
             * LIMIT is bound as an INT on purpose: through execute() a named
             * parameter arrives as a string, MySQL refuses a quoted LIMIT, and
             * the page would come back empty with a warning in the log instead of
             * an error on screen. Same reason Notification::latest() binds it.
             */
            $stmt = $db->prepare(
                'SELECT a.id, a.token_id, a.tool, a.scope, a.ok, a.error, a.args_digest,
                        a.booking_id, a.ip, a.ms, a.created_at, t.name AS token_name
                   FROM ' . self::AUDIT_TABLE . ' a
              LEFT JOIN ' . self::TABLE . ' t ON t.id = a.token_id
                  WHERE a.token_id IN (' . $in['sql'] . ')
               ORDER BY a.id DESC
                  LIMIT :lim'
            );
            foreach ($in['params'] as $key => $id) {
                $stmt->bindValue($key, $id, PDO::PARAM_INT);
            }
            $stmt->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] audit not readable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Call and refusal counts for a set of tokens over the last few days — the
     * one-line header above the audit trail ("412 calls, 3 refused").
     *
     * @param int[] $tokenIds
     * @return array{calls:int, refusals:int}
     */
    public static function audit_totals(array $tokenIds, int $days = 7): array
    {
        $ids = self::clean_ids($tokenIds);
        if ($ids === [] || !self::available()) {
            return ['calls' => 0, 'refusals' => 0];
        }
        try {
            global $db;
            $sql = 'SELECT COUNT(*) AS calls, SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS refusals
                      FROM ' . self::AUDIT_TABLE . '
                     WHERE token_id IN (' . bound_id_list($ids)['sql'] . ')
                       AND created_at > NOW() - INTERVAL :days DAY';
            $row = $db->fetchOne($sql, bound_id_list($ids)['params'] + [':days' => max(1, min(365, $days))]);
            return [
                'calls'    => (int) ($row->calls ?? 0),
                'refusals' => (int) ($row->refusals ?? 0),
            ];
        } catch (Throwable $e) {
            return ['calls' => 0, 'refusals' => 0];
        }
    }

    /**
     * Positive integer ids only, de-duplicated — the argument every audit read
     * above will accept and no other.
     *
     * @param int[] $ids @return int[]
     */
    private static function clean_ids(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        return array_map('intval', array_keys($clean));
    }

    // ==================================================================
    // AUTHENTICATION — the only way a principal is established
    // ==================================================================

    /**
     * Resolve a presented secret into a principal, or null.
     *
     * The lookup is by HASH (so the database never holds a usable credential),
     * and the answer is a complete principal: kind, id, display name, role,
     * scopes, expiry and any booking narrowing. Everything a tool is allowed to
     * know about the caller comes from here.
     *
     * @return array{
     *   kind:string, id:int, name:string, email:string, role:string,
     *   account_type:string, privilege_level:string, scopes:string[],
     *   token_id:int, token_name:string, booking_id:?int, expires_at:?string
     * }|null
     */
    public static function authenticate(string $secret): ?array
    {
        $secret = trim($secret);
        if ($secret === '' || strlen($secret) > 128 || !self::available()) {
            return null;
        }
        try {
            global $db;
            $row = $db->fetchOne(
                'SELECT * FROM ' . self::TABLE . '
                  WHERE token_hash = :h AND revoked_at IS NULL
                    AND (expires_at IS NULL OR expires_at > NOW())
                  LIMIT 1',
                [':h' => self::hash($secret)]
            );
            if ($row === null) {
                self::throttle_failure($secret);
                return null;
            }
            /*
             * A token whose OWN identity is locked stops here. The pair rule is
             * the whole verdict (LoginThrottle::pairBlocked()): a revoked or
             * leaked key ground from one address earns 15 minutes, then an hour,
             * then six, without a burst of failed browser logins from the same IP
             * ever refusing a valid key — and without this refusal ever locking a
             * human out of their own login (LoginThrottle excludes `mcp:` rows
             * from the per-IP sign-in cap).
             *
             * It answers the SAME null as a bad secret, deliberately: telling a
             * caller "your key is correct but throttled" would confirm a key. The
             * audit row the server writes still says which it was.
             */
            if (LoginThrottle::pairBlocked(self::throttle_key((int) $row->id))) {
                return null;
            }
            $principal = self::principal((string) $row->recipient_type, (int) $row->user_id);
            if ($principal === null) {
                self::throttle_failure($secret, (int) $row->id);
                return null; // the account is gone; the token is useless, not a guest pass
            }
            LoginThrottle::clear(self::throttle_key((int) $row->id));
            $principal['scopes']      = self::unpack_scopes((string) $row->scopes);
            $principal['token_id']    = (int) $row->id;
            $principal['token_name']  = (string) $row->name;
            $principal['booking_id']  = $row->booking_id === null ? null : (int) $row->booking_id;
            $principal['expires_at']  = $row->expires_at === null ? null : (string) $row->expires_at;
            return $principal;
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] token lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The identity ONE KEY's authentication failures are counted under.
     *
     * `mcp:<token id>` — a revoked or expired token still has a row, so the id is
     * knowable even when the key may no longer authenticate; a secret matching no
     * row at all counts under `mcp:unknown`, which is the spray case. Never an
     * email: LoginThrottle's per-IP sign-in cap skips `mcp:%` rows so an API key
     * and a password can never spend each other's budget.
     */
    public static function throttle_key(int $tokenId): string
    {
        return self::THROTTLE_PREFIX . ($tokenId > 0 ? (string) $tokenId : 'unknown');
    }

    /**
     * Count one failed authentication (best-effort: a throttle that fataled would
     * be worse than a throttle that missed).
     */
    private static function throttle_failure(string $secret, int $tokenId = 0): void
    {
        try {
            if ($tokenId <= 0) {
                $tokenId = self::token_id_for($secret);
            }
            LoginThrottle::recordFailure(self::throttle_key($tokenId));
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] throttle not recorded: ' . $e->getMessage());
        }
    }

    /**
     * Which token does this secret belong to — revoked and expired included?
     * Only the throttle needs this answer, and only to file a failure under the
     * key's own id rather than under `unknown`.
     */
    private static function token_id_for(string $secret): int
    {
        try {
            global $db;
            return (int) $db->fetchValue(
                'SELECT id FROM ' . self::TABLE . ' WHERE token_hash = :h LIMIT 1',
                [':h' => self::hash($secret)]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Who is this account, and what may it do? Role and display name are read
     * from the account's own row here and nowhere else — the token row names a
     * principal; this method says what that principal IS.
     *
     * @return array{kind:string,id:int,name:string,email:string,role:string,account_type:string,privilege_level:string,scopes:string[],token_id:int,token_name:string,booking_id:?int,expires_at:?string}|null
     */
    public static function principal(string $kind, int $userId): ?array
    {
        self::assert_kind($kind);
        if ($userId <= 0) {
            return null;
        }
        try {
            global $db;
            if ($kind === self::KIND_USER) {
                $row = $db->fetchOne(
                    'SELECT id, firstname, lastname, email, access_level FROM tblusers WHERE id = :id LIMIT 1',
                    [':id' => $userId]
                );
                if ($row === null) {
                    return null;
                }
                $level = (string) ($row->access_level ?? '');
                $role  = self::role_for_level($level);
                return [
                    'kind'             => self::KIND_USER,
                    'id'               => (int) $row->id,
                    'name'             => trim((string) $row->firstname . ' ' . (string) $row->lastname),
                    'email'            => (string) $row->email,
                    'role'             => $role,
                    // The two session keys login.php stamps, so is_staff() /
                    // is_vendor() / is_super_admin() answer here exactly as they
                    // do for this person in a browser.
                    'account_type'     => 'staff',
                    'privilege_level'  => $level === '' ? 'user' : $level,
                    'scopes'           => [],
                    'token_id'         => 0,
                    'token_name'       => '',
                    'booking_id'       => null,
                    'expires_at'       => null,
                ];
            }

            $account = $db->fetchOne(
                'SELECT user_id, user_email, access_level FROM tblaccounts WHERE user_id = :id LIMIT 1',
                [':id' => $userId]
            );
            if ($account === null) {
                return null;
            }
            $detail = $db->fetchOne(
                'SELECT firstname, lastname FROM tblaccounts_detail WHERE user_id = :id LIMIT 1',
                [':id' => $userId]
            );
            $name = $detail !== null ? trim((string) $detail->firstname . ' ' . (string) $detail->lastname) : '';
            return [
                'kind'             => self::KIND_ACCOUNT,
                'id'               => (int) $account->user_id,
                'name'             => $name !== '' ? $name : (string) $account->user_email,
                'email'            => (string) $account->user_email,
                'role'             => 'couple',
                // A couple is a 'client' session with privilege '0' — see
                // is_client_account(): a vendor row is ALSO level 0, and only
                // account_type tells the two apart.
                'account_type'     => 'client',
                'privilege_level'  => (string) ($account->access_level ?? '0'),
                'scopes'           => [],
                'token_id'         => 0,
                'token_name'       => '',
                'booking_id'       => null,
                'expires_at'       => null,
            ];
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] principal not resolvable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Become this principal for the rest of the request.
     *
     * This is the seam the whole design rests on: after this call, every
     * ownership predicate and every model behaves exactly as it would for that
     * person signed in to the browser — `booking_visible_to_session()`,
     * `may_open_couple_page()`, `vendor_listing_visible_to_session()`,
     * `is_staff()`, `is_super_admin()`, `notification_recipient_for_session()`.
     *
     * The keys are EXACTLY the ones login.php and Session::login() stamp
     * (privilege_level from the account's access_level, account_type 'staff' vs
     * 'client'), because the predicates read those keys and nothing else. No
     * session is started and no cookie is written: $_SESSION here is an array in
     * this one request, which is all any of those functions look at.
     */
    public static function bind_principal(array $principal): void
    {
        $_SESSION = [
            'id'              => (int) $principal['id'],
            'user_email'      => (string) $principal['email'],
            'privilege_level' => (string) $principal['privilege_level'],
            'account_type'    => (string) $principal['account_type'],
        ];
    }

    /**
     * The role behind a tblusers access_level. Mirrors is_staff()/is_vendor()/
     * is_super_admin(), including the legacy non-numeric designations, so the
     * role a token reports and the role the predicates compute cannot disagree.
     */
    private static function role_for_level(string $level): string
    {
        if (is_numeric($level)) {
            return match ((int) $level) {
                2       => 'super_admin',
                1       => 'staff',
                default => 'vendor', // 0 = vendor, exactly as is_vendor() reads it
            };
        }
        $level = strtolower(trim($level));
        return in_array($level, ['super admin', 'admin'], true) ? 'super_admin' : 'staff';
    }

    // ==================================================================
    // USE, AUDIT, PRUNE
    // ==================================================================

    /** Remember when this token was last used, and from where. */
    public static function touch(int $tokenId, string $ip): void
    {
        if ($tokenId <= 0) {
            return;
        }
        try {
            global $db;
            $db->execute(
                'UPDATE ' . self::TABLE . ' SET last_used_at = NOW(), last_used_ip = :ip WHERE id = :id',
                [':ip' => mb_substr($ip, 0, 45), ':id' => $tokenId]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] token touch failed: ' . $e->getMessage());
        }
    }

    /**
     * One audit row per call. Never throws: an audit that can break the request
     * it describes would be worse than a gap in the log.
     *
     * `$extra` carries what v2.17 added for writes — the tier (1 read, 2 confirm,
     * 3 human-approved), the caller's `request_id` idempotency key, the approval
     * that authorised the call and the hash of the payload it was bound to, and a
     * short server-authored `outcome_note` saying what actually changed. It is an
     * array rather than five more positional arguments because the first ten are
     * already too many to read at a call site.
     *
     * A `request_id` is written ONLY for a call that actually changed data, and
     * (token_id, request_id) is unique: that is how a retry finds the first
     * outcome instead of repeating the write, and how a read or a preview stays
     * exempt (many NULLs are allowed in the key).
     *
     * @param array<string,mixed> $args  the tool arguments — only their SHAPE is kept
     * @param array{tier?:int,request_id?:?string,approval_id?:?int,payload_hash?:string,outcome_note?:string} $extra
     */
    public static function audit(
        ?int $tokenId,
        string $recipientType,
        int $userId,
        string $tool,
        string $scope,
        bool $ok,
        string $error = '',
        array $args = [],
        ?int $bookingId = null,
        int $ms = 0,
        array $extra = []
    ): void {
        if (!self::available()) {
            return;
        }
        try {
            global $db;
            if (!in_array($recipientType, [self::KIND_USER, self::KIND_ACCOUNT, self::KIND_NONE], true)) {
                $recipientType = self::KIND_NONE;
            }
            $requestId = trim((string) ($extra['request_id'] ?? ''));
            $payloadHash = trim((string) ($extra['payload_hash'] ?? ''));
            /*
             * The table is spelled out here rather than concatenated from
             * self::AUDIT_TABLE, and that is deliberate: scripts/audit_promises.php
             * (sweep D) indexes row creators from literal INSERT statements, so a
             * table assembled from a constant reads as a table nothing can write
             * — the exact blind spot the sweep exists to catch. Every other query
             * in this class still uses the constant; only the creator is literal.
             */
            $db->execute(
                'INSERT INTO tblapi_audit'
                . ' (token_id, recipient_type, user_id, tool, scope, ok, error, args_digest, booking_id, ip, ms,'
                . '  tier, request_id, approval_id, payload_hash, outcome_note)'
                . ' VALUES (:token, :kind, :uid, :tool, :scope, :ok, :err, :digest, :bid, :ip, :ms,'
                . '         :tier, :req, :apr, :phash, :outcome)',
                [
                    ':token'  => $tokenId !== null && $tokenId > 0 ? $tokenId : null,
                    ':kind'   => $recipientType,
                    ':uid'    => max(0, $userId),
                    ':tool'   => mb_substr($tool, 0, 64),
                    ':scope'  => mb_substr($scope, 0, 48),
                    ':ok'     => $ok ? 1 : 0,
                    ':err'    => mb_substr($error, 0, 255),
                    ':digest' => mb_substr(self::digest($args), 0, 255),
                    ':bid'    => $bookingId !== null && $bookingId > 0 ? $bookingId : null,
                    ':ip'     => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ':ms'     => max(0, $ms),
                    ':tier'   => max(1, min(3, (int) ($extra['tier'] ?? 1))),
                    ':req'    => $requestId === '' ? null : mb_substr($requestId, 0, 64),
                    ':apr'    => isset($extra['approval_id']) && (int) $extra['approval_id'] > 0 ? (int) $extra['approval_id'] : null,
                    ':phash'  => mb_substr($payloadHash, 0, 64),
                    ':outcome' => mb_substr((string) ($extra['outcome_note'] ?? ''), 0, 255),
                ]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] audit row not written: ' . $e->getMessage());
        }
    }

    /**
     * Has this token already had a call with this idempotency key, and what did
     * it do? Returns the recorded outcome line, or null when the key is new.
     *
     * This is what makes a retry safe: the assistant that re-sends a write after
     * a timeout gets the FIRST outcome back (a sentence, not a second guest), and
     * the unique key on (token_id, request_id) means two concurrent retries cannot
     * both claim it.
     */
    public static function idempotent_outcome(int $tokenId, string $requestId): ?string
    {
        $requestId = trim($requestId);
        if ($tokenId <= 0 || $requestId === '' || !self::available()) {
            return null;
        }
        try {
            global $db;
            $row = $db->fetchOne(
                'SELECT outcome_note FROM ' . self::AUDIT_TABLE
                . ' WHERE token_id = :token AND request_id = :req AND ok = 1 ORDER BY id DESC LIMIT 1',
                [':token' => $tokenId, ':req' => mb_substr($requestId, 0, 64)]
            );
            return $row === null ? null : (string) $row->outcome_note;
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] idempotency lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The SHAPE of a call's arguments: which keys, how long each value was.
     *
     * Values are deliberately absent. An audit table that outlives a request must
     * not accumulate guest phone numbers, email addresses or notes — the point of
     * the row is "what did the AI ask for", and a planner reading it later needs
     * the tool and the arguments' names, not a second copy of their data.
     */
    private static function digest(array $args): string
    {
        if ($args === []) {
            return '';
        }
        $parts = [];
        foreach ($args as $key => $value) {
            $length = is_array($value) ? count($value) : mb_strlen(is_scalar($value) ? (string) $value : '');
            $parts[] = mb_substr((string) $key, 0, 24) . ':' . $length;
        }
        return implode(' ', $parts);
    }

    /**
     * Delete audit rows past retention. Called by scripts/notify_prune.php — the
     * job that already owns retention — so this table cannot grow forever and its
     * pruning is covered by the same scheduled job the watchdog monitors.
     */
    public static function prune_audit(int $days = self::AUDIT_KEEP_DAYS): int
    {
        if (!self::available()) {
            return 0;
        }
        try {
            global $db;
            $stmt = $db->execute(
                'DELETE FROM ' . self::AUDIT_TABLE . ' WHERE created_at < NOW() - INTERVAL :days DAY',
                [':days' => max(1, $days)]
            );
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[WPMS][Mcp] audit prune failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * A short, human line about one token: for the page and for the audit view.
     * Never renders a secret (there is none to render).
     */
    public static function describe(object $token): string
    {
        $parts = ['prefix ' . (string) $token->token_prefix . '…'];
        if (!empty($token->booking_id)) {
            $parts[] = 'wedding #' . (int) $token->booking_id;
        }
        if (!empty($token->expires_at)) {
            $parts[] = 'expires ' . (string) $token->expires_at;
        } else {
            $parts[] = 'never expires';
        }
        if (!empty($token->revoked_at)) {
            $parts[] = 'REVOKED ' . (string) $token->revoked_at;
        }
        return implode(' · ', $parts);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** Are the v2.16 tables installed? Cached per request. */
    public static function available(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }
        try {
            global $db;
            self::$installed = (bool) $db->fetchValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . self::TABLE . "', '" . self::AUDIT_TABLE . "')"
            ) >= 2;
        } catch (Throwable $e) {
            self::$installed = false;
        }
        return self::$installed;
    }

    /** A fresh secret: prefix + 64 hex characters (32 bytes of entropy). */
    public static function new_secret(): string
    {
        return self::SECRET_PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));
    }

    /**
     * How a secret is stored and looked up. High-entropy random tokens do not
     * need a slow KDF — there is nothing to brute-force — and a fast hash is what
     * makes an indexed lookup possible, so authentication stays one query.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** The first characters of a secret, for display only. */
    public static function prefix_of(string $secret): string
    {
        return mb_substr($secret, 0, 12);
    }

    /** @throws InvalidArgumentException */
    private static function assert_kind(string $kind): void
    {
        if (!in_array($kind, [self::KIND_USER, self::KIND_ACCOUNT], true)) {
            throw new InvalidArgumentException(
                "ApiToken: '" . $kind . "' is not a principal kind — pass self::KIND_USER or self::KIND_ACCOUNT"
            );
        }
    }

    /** The kind a signed-in session belongs to — the UI's own convenience. */
    public static function kind_for_session(): string
    {
        return is_client_account() ? self::KIND_ACCOUNT : self::KIND_USER;
    }
}
