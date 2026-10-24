<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.17 — the tools an AI assistant may USE (McpWrites)
 *
 * McpTools is what the assistant can ASK; this is what it can DO. The two files
 * are separate on purpose: reads are the surface almost every question needs and
 * writes are the surface that has to be argued about, so the write catalogue, its
 * tiers and every handler that changes a row live here, in one reviewable place,
 * and McpTools just merges the list in.
 *
 * TWO TIERS, BECAUSE NOT ALL WRITES ARE THE SAME SIZE
 *
 *   TIER 2 — REVERSIBLE. A guest, a checklist tick, an invitation timestamp.
 *   Wrong in a way a human can see and undo on the guest list page. These run
 *   when the call carries `confirm: true`, and WITHOUT it they return a PREVIEW
 *   of exactly what would change and write nothing. A model that is about to add
 *   a guest says what it is about to add, in the same result shape it always
 *   gets, and can be told no.
 *
 *   TIER 3 — MONEY, MESSAGES, STATUS. An SMS to a guest list, a payment row, an
 *   M-Pesa deposit prompt, a planner assignment. Sending 400 people a text is not
 *   reversible, and a payment is somebody's money — so these REFUSE on their own
 *   authority: they resolve the real data, write a request to `tblapi_approvals`,
 *   and answer `approval_required` with the reference and the sentence a planner
 *   will read. A signed-in human approves that exact payload on
 *   admin/mcp_approvals.php, which mints a short-lived, single-use, HMAC-signed
 *   string bound to the tool AND the payload hash (McpApprovals). Only then does
 *   the retried call act, exactly once.
 *
 * WHAT EVERY HANDLER HERE OBEYS
 *
 *   1. THE SAME GATES AS THE PAGES. A booking id is a request, not permission:
 *      every handler resolves it through `McpTools::require_booking()`, which
 *      applies the token's narrowing and then `booking_visible_to_session()` —
 *      the predicate admin/ pages use. A couple's token can only reach its own
 *      wedding; a vendor's token reaches no couple data.
 *
 *   2. MODELS DO THE WRITING. Not one statement here is raw SQL, and no tool
 *      takes a table or column name. A guest goes through the `Guest` row, a
 *      checklist tick through `Checklist::toggle()`, an SMS through `SMS`, a
 *      payment through a `Liquidation` row, a deposit through `MpesaStk::initiate()`
 *      — the same classes the pages drive.
 *
 *   3. NOTHING IS REPORTED THAT DID NOT HAPPEN. A refusal throws McpToolError and
 *      becomes a readable `isError` result. A Tier-3 tool with no approval has
 *      SENT NOTHING, and says so. An STK push is a PROMISE and is described as
 *      one: the tool returns `pending` and names the poll that reports the
 *      outcome, and never calls a push a payment.
 *
 *   4. WHAT WAS DONE IS WRITTEN DOWN. Every handler returns a short
 *      server-authored `note`, which lands in `tblapi_audit.outcome_note` next to
 *      the tier, the idempotency key and the approval that authorised it. The note
 *      never contains a message body or a guest's phone number.
 *
 * NOT HERE, DELIBERATELY: `add_task` (needs a model method the platform does not
 * have yet), `set_installment_plan` (payment terms, and the plan is composed on a
 * couple's own page), `record_rsvp_answer` (an RSVP is the guest's answer, not an
 * assistant's), and `system_status` (the operator's surface — Phase 4, in
 * docs/MCP_PLAN.md). A visible gap is better than a tool that guesses.
 */
final class McpWrites
{
    /** Reversible write: `confirm: true`, or it previews and writes nothing. */
    public const TIER_CONFIRM = 2;

    /** Money, messages or status: a signed-in human approves this exact payload. */
    public const TIER_APPROVAL = 3;

    /**
     * The guest relationship vocabulary — the `tblguest.relationship` enum, in
     * the order the couple's own form offers it. The priority band below is
     * derived from it exactly as admin/client_guest_save.php derives it, so an
     * assistant adding a guest produces the same row the couple's form would.
     */
    public const RELATIONSHIPS = [
        'family_elder', 'bridal_train', 'upcountry_guest', 'family_friend', 'colleague',
        'pastor_clergy', 'vc_member', 'harambee_sponsor', 'close_family', 'regular', 'other',
    ];

    /** relationship → priority band (A = closest family, E = furthest). */
    public const PRIORITY_BY_RELATIONSHIP = [
        'family_elder'     => 'A',
        'pastor_clergy'    => 'A',
        'bridal_train'     => 'B',
        'vc_member'        => 'B',
        'close_family'     => 'B',
        'harambee_sponsor' => 'B',
        'upcountry_guest'  => 'C',
        'family_friend'    => 'C',
        'colleague'        => 'D',
        'regular'          => 'D',
        'other'            => 'E',
    ];

    /** The SMS audiences the platform already names on the SMS centre page. */
    public const AUDIENCES = ['all', 'upcountry', 'vip'];

    /** Payment methods admin/budget_add.php accepts. */
    public const PAYMENT_METHODS = ['cash', 'mpesa', 'bank'];

    /** Most guest ids one mark_invited call may carry. */
    public const MAX_GUEST_IDS = 200;

    /** Longest SMS body accepted (three billed segments' worth of text). */
    public const MAX_SMS_CHARS = 480;

    /**
     * The write catalogue.
     *
     * `tier` is the gate: 2 needs `confirm: true`, 3 needs a human approval.
     * `handler` is the method that answers it.
     *
     * @var array<string, array{title:string, scope:string, tier:int, description:string, schema:array<string,mixed>, handler:string}>
     */
    private const SPECS = [
        // ==============================================================
        // Tier 2 — reversible, previewed, confirmable
        // ==============================================================
        'add_guest' => [
            'title'       => 'Add a guest (Tier 2 — needs confirm)',
            'scope'       => 'write:guests',
            'tier'        => self::TIER_CONFIRM,
            'description' => 'Add one guest to a wedding. Tier 2: without `confirm: true` this changes nothing and returns a preview '
                . 'of the row it would create. Nothing is ever added twice for one `request_id`.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id'            => ['type' => 'integer'],
                    'fullname'              => ['type' => 'string', 'description' => 'The guest\'s full name, as it should read on the list.'],
                    'guestname'             => ['type' => 'string', 'description' => 'The short name used on the invitation, e.g. "Mama Grace".'],
                    'phone'                 => ['type' => 'string', 'description' => 'Kenyan number; normalised to 2547XXXXXXXX when an SMS is sent.'],
                    'city'                  => ['type' => 'string'],
                    'relationship'          => ['type' => 'string', 'enum' => self::RELATIONSHIPS],
                    'priority'              => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D', 'E'],
                                                'description' => 'A = closest family and elders. Omitted = derived from the relationship.'],
                    'out_of_town'           => ['type' => 'boolean', 'description' => 'Travelling in — counts in the upcountry SMS audience.'],
                    'transport_needed'      => ['type' => 'boolean'],
                    'dietary_requirements'  => ['type' => 'string'],
                    'confirm'               => ['type' => 'boolean', 'description' => 'Must be true to write. Otherwise a preview is returned.'],
                    'request_id'            => ['type' => 'string', 'description' => 'Your own idempotency key: retrying with it cannot add the guest twice.'],
                ],
                'required'   => ['booking_id', 'fullname', 'guestname'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_add_guest',
        ],
        'update_guest' => [
            'title'       => 'Change a guest (Tier 2 — needs confirm)',
            'scope'       => 'write:guests',
            'tier'        => self::TIER_CONFIRM,
            'description' => 'Change named fields on one existing guest of a wedding. Only the fields you send are changed; the rest '
                . 'are left alone. Tier 2: needs `confirm: true`, otherwise a before/after preview is returned.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id'           => ['type' => 'integer'],
                    'guest_id'             => ['type' => 'integer', 'description' => 'From list_guests.'],
                    'fullname'             => ['type' => 'string'],
                    'guestname'            => ['type' => 'string'],
                    'phone'                => ['type' => 'string'],
                    'city'                 => ['type' => 'string'],
                    'relationship'         => ['type' => 'string', 'enum' => self::RELATIONSHIPS],
                    'priority'             => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D', 'E']],
                    'out_of_town'          => ['type' => 'boolean'],
                    'transport_needed'     => ['type' => 'boolean'],
                    'dietary_requirements' => ['type' => 'string'],
                    'confirm'              => ['type' => 'boolean'],
                    'request_id'           => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'guest_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_update_guest',
        ],
        'mark_invited' => [
            'title'       => 'Mark guests invited (Tier 2 — needs confirm)',
            'scope'       => 'write:guests',
            'tier'        => self::TIER_CONFIRM,
            'description' => 'Record that named guests have been invited. This is what starts their RSVP reminder clock, and any guest '
                . 'without an RSVP link gets one. Guests already invited keep their original timestamp — a second call cannot '
                . 'restart the clock, which is why this is safe to repeat.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'guest_ids'  => ['type' => 'array', 'items' => ['type' => 'integer'],
                                      'description' => 'Up to ' . self::MAX_GUEST_IDS . ' guest ids from list_guests.'],
                    'confirm'    => ['type' => 'boolean'],
                    'request_id' => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'guest_ids'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_mark_invited',
        ],
        'toggle_task' => [
            'title'       => 'Tick or untick a checklist item (Tier 2 — needs confirm)',
            'scope'       => 'write:tasks',
            'tier'        => self::TIER_CONFIRM,
            'description' => 'Toggle one checklist milestone for a wedding between done and not done. Tier 2: needs `confirm: true`, '
                . 'otherwise the preview says what the item is and which way it would flip.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'item_id'    => ['type' => 'integer', 'description' => 'From list_tasks.'],
                    'confirm'    => ['type' => 'boolean'],
                    'request_id' => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'item_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_toggle_task',
        ],

        // ==============================================================
        // Tier 3 — money, messages, status: a human approves first
        // ==============================================================
        'send_bulk_sms' => [
            'title'       => 'SMS a wedding\'s guests (Tier 3 — needs a human approval)',
            'scope'       => 'write:sms',
            'tier'        => self::TIER_APPROVAL,
            // Staff only: the credits are the planner's, and the approval is a
            // planner's. A couple's token is never offered this tool at all.
            'roles'       => ['staff'],
            'description' => 'Send one SMS to an audience of a wedding\'s guests: everyone with a number, the out-of-town guests, or '
                . 'the VIP band. Tier 3: this MESSAGES REAL PEOPLE and costs credits, so it sends NOTHING until a signed-in '
                . 'planner approves this exact message, audience and wedding on the Approvals page; the first call answers with '
                . 'the reference and the recipient count.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'audience'   => ['type' => 'string', 'enum' => self::AUDIENCES,
                                      'description' => 'all = every guest with a number, upcountry = out-of-town guests, vip = priority A.'],
                    'message'    => ['type' => 'string', 'maxLength' => self::MAX_SMS_CHARS,
                                      'description' => 'The exact text that will be sent. KSh 1 per 160 characters, per recipient.'],
                    'approval'   => ['type' => 'string', 'description' => 'The approval string a planner minted for THIS message.'],
                    'request_id' => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'audience', 'message'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_send_bulk_sms',
        ],
        'record_payment' => [
            'title'       => 'Record a payment (Tier 3 — needs a human approval)',
            'scope'       => 'write:budget',
            'tier'        => self::TIER_APPROVAL,
            // Staff only: recording money received is the back-office action
            // budget_add.php performs, and the couple's own payments reach the
            // ledger through the M-Pesa callbacks, not through a token.
            'roles'       => ['staff'],
            'description' => 'Record money received for a wedding against one of its functions: cash, M-Pesa (with the 10-character '
                . 'transaction code) or bank transfer. Tier 3: it changes what a couple owes, so a signed-in planner approves the '
                . 'exact amount, method, event and code before anything is written.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'event_id'   => ['type' => 'integer', 'description' => 'The function the payment belongs to (from list_timeline or the budget).'],
                    'amount'     => ['type' => 'number', 'description' => 'KSh, greater than zero.'],
                    'method'     => ['type' => 'string', 'enum' => self::PAYMENT_METHODS],
                    'mpesa_code' => ['type' => 'string', 'description' => 'Required for method=mpesa: the 10-character M-Pesa confirmation code.'],
                    'approval'   => ['type' => 'string'],
                    'request_id' => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'event_id', 'amount', 'method'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_record_payment',
        ],
        'initiate_stk_deposit' => [
            'title'       => 'Ask a payer for a deposit by M-Pesa (Tier 3 — needs a human approval)',
            'scope'       => 'write:budget',
            'tier'        => self::TIER_APPROVAL,
            // Staff only. A couple still gets their own prompt, on their own
            // deposit page with their own session — that is the route that does
            // not need a planner to approve a stranger's phone.
            'roles'       => ['staff'],
            'description' => 'Ask Safaricom to prompt a phone for a deposit towards a wedding\'s balance. A PUSH IS A PROMISE, NOT A '
                . 'PAYMENT: this tool returns `pending_promise` and nothing is recorded as received until the customer completes '
                . 'it. It cannot exceed the balance due, and it needs a planner\'s approval first.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'phone'      => ['type' => 'string', 'description' => 'The payer\'s Kenyan M-Pesa number.'],
                    'amount'     => ['type' => 'number', 'description' => 'KSh, at least 50 and no more than the balance due.'],
                    'event_id'   => ['type' => 'integer', 'description' => 'The function the deposit is for, when known.'],
                    'approval'   => ['type' => 'string'],
                    'request_id' => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'phone', 'amount'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_initiate_stk_deposit',
        ],
        'assign_planner' => [
            'title'       => 'Set the planner on a wedding (Tier 3 — needs a human approval)',
            'scope'       => 'write:planners',
            'tier'        => self::TIER_APPROVAL,
            // Staff only, and not because of the money: who owns a wedding is a
            // staffing decision. A couple choosing their planner is a
            // conversation, not a tool call.
            'roles'       => ['staff'],
            'description' => 'Record which planner owns a wedding (`tblweddingbook.organizer_id`, the planner the couple is told about, '
                . 'whose SMS credits the courtesy messages are charged to, and who can SEE the wedding). Tier 3, and stated plainly: '
                . 'with per-planner scoping on, this is the field that decides who else can open it — the organizer sees it, other '
                . 'planners do not, a super admin sees every wedding, and an unassigned wedding stays visible to every planner until '
                . 'somebody takes it.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id'   => ['type' => 'integer'],
                    'organizer_id' => ['type' => 'integer', 'description' => 'A staff account (tblusers.id) with planner or super-admin access.'],
                    'approval'     => ['type' => 'string'],
                    'request_id'   => ['type' => 'string'],
                ],
                'required'   => ['booking_id', 'organizer_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_assign_planner',
        ],
    ];

    // ==================================================================
    // The catalogue, as the protocol needs it
    // ==================================================================

    /** Is this a write tool this build knows? */
    public static function known(string $name): bool
    {
        return isset(self::SPECS[$name]);
    }

    /** The scope a write tool requires. @throws McpToolError */
    public static function scope_of(string $name): string
    {
        if (!self::known($name)) {
            throw new McpToolError('unknown tool: ' . $name);
        }
        return (string) self::SPECS[$name]['scope'];
    }

    /** 2 = confirm, 3 = human approval. Reads are 1 and live in McpTools. */
    public static function tier_of(string $name): int
    {
        return self::known($name) ? (int) self::SPECS[$name]['tier'] : 1;
    }

    /** Every write tool name this build knows. @return string[] */
    public static function names(): array
    {
        return array_keys(self::SPECS);
    }

    /** The roles a write tool is offered to, or null when it is offered to every role. */
    public static function roles_of(string $name): ?array
    {
        return self::known($name) ? (self::SPECS[$name]['roles'] ?? null) : null;
    }

    /**
     * The definitions for `tools/list`, filtered to the scopes this token holds
     * — a token without `write:guests` is never told `add_guest` exists, and a
     * write tool is not advertised at all when the install has writes switched
     * off, so an assistant cannot spend a turn discovering the refusal.
     *
     * @param array<string,mixed> $principal
     * @return array<int, array<string,mixed>>
     */
    public static function definitions(array $principal): array
    {
        $out = [];
        foreach (self::SPECS as $name => $spec) {
            if (!self::permitted($name, $principal)) {
                continue;
            }
            $tier = (int) $spec['tier'];
            $out[] = [
                'name'        => $name,
                'title'       => $spec['title'],
                'description' => $spec['description'],
                'inputSchema' => $spec['schema'],
                'annotations' => [
                    'title'           => $spec['title'],
                    'readOnlyHint'    => false,
                    // Tier 3 is the one that can cost money or reach people.
                    'destructiveHint' => $tier === self::TIER_APPROVAL,
                    // Tier 2 repeats are harmless (mark_invited fills gaps only),
                    // Tier 3 is guarded by a single-use approval plus request_id.
                    'idempotentHint'  => $tier === self::TIER_CONFIRM,
                    'openWorldHint'   => $tier === self::TIER_APPROVAL,
                ],
            ];
        }
        return $out;
    }

    /**
     * May this principal call this write tool at all?
     *
     * Role (when the spec names roles), then scope, then the install switch. The
     * role gate is the same ceiling McpTools applies to a read — a vendor's or a
     * couple's token is never told the money and message tools exist, and asking
     * for one anyway is refused for the same reason (call()).
     */
    public static function permitted(string $name, array $principal): bool
    {
        if (!self::known($name)) {
            return false;
        }
        $roles = self::SPECS[$name]['roles'] ?? null;
        if ($roles !== null && !McpTools::role_allowed($principal, $roles)) {
            return false;
        }
        return ApiToken::has_scope($principal, self::scope_of($name)) && McpServer::writes_enabled();
    }

    /**
     * Run one write tool. The handler returns the same shape every other tool
     * returns, plus `applied` (did this call change anything?) and `note` (one
     * server-authored line for the audit trail).
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     * @return array{data:mixed, booking_id:?int, applied:bool, note:string}
     * @throws McpToolError on a refusal the assistant should read
     */
    public static function call(string $name, array $args, array $principal): array
    {
        if (!self::known($name)) {
            throw new McpToolError('unknown tool: ' . $name);
        }
        $roles = self::SPECS[$name]['roles'] ?? null;
        if ($roles !== null && !McpTools::role_allowed($principal, $roles)) {
            throw new McpToolError(McpTools::role_refusal($principal));
        }
        $scope = self::scope_of($name);
        if (!ApiToken::has_scope($principal, $scope)) {
            throw new McpToolError('this token does not carry the ' . $scope . ' scope');
        }
        if (!McpServer::writes_enabled()) {
            throw new McpToolError('changing data over MCP is switched off on this install (' . McpServer::WRITE_SWITCH . ')');
        }
        $handler = (string) self::SPECS[$name]['handler'];
        $out = self::$handler($args, $principal);
        return [
            'data'       => $out['data'],
            'booking_id' => $out['booking_id'] ?? null,
            'applied'    => (bool) ($out['applied'] ?? false),
            'note'       => (string) ($out['note'] ?? ''),
        ];
    }

    /**
     * The COMMITTED payload of a Tier-3 call: the fields that decide what will
     * happen, and nothing else.
     *
     * This is what the approval's HMAC is bound to, so it has to be exactly the
     * set a human is approving — the booking, the audience, the message text, the
     * amount, the method, the M-Pesa code, the phone — and NOT the transport
     * fields (`confirm`, `approval`, `request_id`), which change between the
     * request and the retry and would otherwise make an approval unusable.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function committed(string $tool, array $args): array
    {
        $fields = match ($tool) {
            'send_bulk_sms'        => ['booking_id', 'audience', 'message'],
            'record_payment'       => ['booking_id', 'event_id', 'amount', 'method', 'mpesa_code'],
            'initiate_stk_deposit' => ['booking_id', 'event_id', 'phone', 'amount'],
            'assign_planner'       => ['booking_id', 'organizer_id'],
            default                => [],
        };
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $args[$field] ?? null;
        }
        // Booking ids are ints wherever they came from, so a JSON string and a
        // JSON number are the same payload rather than two different approvals.
        if (isset($out['booking_id'])) {
            $out['booking_id'] = (int) $out['booking_id'];
        }
        if (isset($out['event_id'])) {
            $out['event_id'] = (int) $out['event_id'];
        }
        if (isset($out['organizer_id'])) {
            $out['organizer_id'] = (int) $out['organizer_id'];
        }
        if (isset($out['amount'])) {
            $out['amount'] = round((float) $out['amount'], 2);
        }
        if (isset($out['message']) && is_string($out['message'])) {
            $out['message'] = trim($out['message']);
        }
        return $out;
    }

    // ==================================================================
    // Tier 2 handlers
    // ==================================================================

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_add_guest(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;

        $fullname  = McpTools::str_arg($args, 'fullname');
        $guestname = McpTools::str_arg($args, 'guestname');
        if ($fullname === '' || $guestname === '') {
            throw new McpToolError('fullname and guestname are both required — the invitation needs a name to print and a name to say');
        }
        $relationship = McpTools::enum_arg($args, 'relationship', self::RELATIONSHIPS, 'regular');
        $priority     = McpTools::enum_arg($args, 'priority', ['a', 'b', 'c', 'd', 'e'], '');
        $priority     = $priority === '' ? (self::PRIORITY_BY_RELATIONSHIP[$relationship] ?? 'D') : strtoupper($priority);

        $row = [
            'fullname'             => mb_substr($fullname, 0, 128),
            'guestname'            => mb_substr($guestname, 0, 64),
            'phone'                => mb_substr(McpTools::str_arg($args, 'phone'), 0, 32),
            'city'                 => mb_substr(McpTools::str_arg($args, 'city'), 0, 96),
            'relationship'         => $relationship,
            'priority'             => $priority,
            'out_of_town'          => McpTools::bool_arg($args, 'out_of_town') ? 'y' : 'n',
            'transport_needed'     => McpTools::bool_arg($args, 'transport_needed') ? 1 : 0,
            'dietary_requirements' => mb_substr(McpTools::str_arg($args, 'dietary_requirements'), 0, 255),
        ];
        $note  = 'add guest "' . $row['fullname'] . '" (' . $row['guestname'] . '), '
            . $relationship . ', priority ' . $priority
            . ($row['out_of_town'] === 'y' ? ', out of town' : '')
            . ', to wedding #' . $bookingId;
        // The log says WHAT was added, not WHO: a guest's name must not be copied
        // into a table that outlives the request (the same rule as args_digest).
        $audit = 'add ONE guest to wedding #' . $bookingId . ' (' . $relationship . ', priority ' . $priority
            . ($row['out_of_town'] === 'y' ? ', out of town' : '') . ')';

        if (!McpTools::bool_arg($args, 'confirm')) {
            return self::preview('add_guest', $note, $row, $bookingId, $audit);
        }

        $guest = new Guest();
        $guest->booking_id = $bookingId;
        $guest->fullname   = $row['fullname'];
        $guest->guestname  = $row['guestname'];
        $guest->phone      = $row['phone'];
        $guest->city       = $row['city'];
        // Legacy NOT NULL columns the couple's form derives rather than collects.
        $guest->address          = $row['city'];
        $guest->state            = '';
        $guest->zipcode          = '';
        $guest->tracks_and_gifts = '';
        $guest->relationship     = $row['relationship'];
        $guest->priority         = $row['priority'];
        $guest->out_of_town      = $row['out_of_town'];
        $guest->upcountry_accommodation = $row['out_of_town'] === 'y' ? 1 : 0;
        $guest->transport_needed        = $row['transport_needed'];
        $guest->dietary_requirements    = $row['dietary_requirements'];
        $guest->rsvp_status = 'pending';
        // The RSVP link is minted with the guest, exactly as the couple's own
        // form does it, so an assistant-added guest is invitable immediately.
        $guest->rsvp_token = bin2hex(random_bytes(16));
        if (!$guest->create()) {
            throw new McpToolError('the guest could not be saved — nothing was added');
        }

        return self::applied('add_guest', $audit . ' — added as guest #' . (int) $guest->id, $bookingId, [
            'guest_id' => (int) $guest->id,
        ]);
    }

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_update_guest(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;
        $guestId   = McpTools::int_arg($args, 'guest_id', 0, 0, PHP_INT_MAX);
        if ($guestId <= 0) {
            throw new McpToolError('guest_id is required — call list_guests to see which ones this wedding has');
        }
        $guest = Guest::find_by_id($guestId);
        // The row is re-checked against the booking, exactly as
        // admin/client_guest_save.php re-checks it: an id from another wedding
        // must not be editable through this one.
        if (!$guest || (int) $guest->booking_id !== $bookingId) {
            throw new McpToolError('guest #' . $guestId . ' is not on wedding #' . $bookingId . '\'s list');
        }

        $changes = [];
        foreach (['fullname', 'guestname', 'phone', 'city', 'dietary_requirements'] as $field) {
            if (array_key_exists($field, $args)) {
                $changes[$field] = mb_substr(McpTools::str_arg($args, $field), 0, 255);
            }
        }
        if (array_key_exists('relationship', $args)) {
            $changes['relationship'] = McpTools::enum_arg($args, 'relationship', self::RELATIONSHIPS, (string) $guest->relationship);
        }
        if (array_key_exists('priority', $args)) {
            $changes['priority'] = strtoupper(McpTools::enum_arg($args, 'priority', ['a', 'b', 'c', 'd', 'e'], ''));
            if ($changes['priority'] === '') {
                throw new McpToolError('priority must be one of A, B, C, D, E');
            }
        }
        if (array_key_exists('out_of_town', $args)) {
            $changes['out_of_town'] = McpTools::bool_arg($args, 'out_of_town') ? 'y' : 'n';
        }
        if (array_key_exists('transport_needed', $args)) {
            $changes['transport_needed'] = McpTools::bool_arg($args, 'transport_needed') ? 1 : 0;
        }
        if ($changes === []) {
            throw new McpToolError('nothing to change — send at least one field besides booking_id and guest_id');
        }
        if (array_key_exists('fullname', $changes) && $changes['fullname'] === '') {
            throw new McpToolError('fullname cannot be emptied');
        }

        $before = [];
        $realChange = false;
        foreach (array_keys($changes) as $field) {
            $before[$field] = $guest->$field;
            if ((string) $before[$field] !== (string) $changes[$field]) {
                $realChange = true;
            }
        }
        $note  = 'change guest #' . $guestId . ' (' . $guest->fullname . ') on wedding #' . $bookingId . ': '
            . self::describe_changes($before, $changes);
        // Field NAMES, never their values: the before/after text can contain a
        // phone number or a dietary note.
        $audit = 'change guest #' . $guestId . ' on wedding #' . $bookingId
            . ' (fields: ' . implode(', ', array_keys($changes)) . ')';

        if (!McpTools::bool_arg($args, 'confirm')) {
            return self::preview('update_guest', $note, ['guest_id' => $guestId, 'from' => $before, 'to' => $changes], $bookingId, $audit);
        }
        if (!$realChange) {
            return self::unchanged('update_guest', $audit, $bookingId, ['guest_id' => $guestId]);
        }

        foreach ($changes as $field => $value) {
            $guest->$field = $value;
        }
        $guest->update();

        /*
         * Read the row back rather than trusting the return value: `update()`
         * answers "did MySQL report a changed row", and a statement that sets a
         * column to the value it already had reports zero — which is not a
         * failure, and must not be reported to a planner as one.
         */
        $after = Guest::find_by_id($guestId);
        foreach ($changes as $field => $value) {
            if (!$after || (string) $after->$field !== (string) $value) {
                throw new McpToolError('the change could not be saved — ' . $field . ' is still what it was');
            }
        }

        return self::applied('update_guest', $audit, $bookingId, [
            'guest_id' => $guestId,
            'changed'  => array_keys($changes),
        ]);
    }

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_mark_invited(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;

        $raw = $args['guest_ids'] ?? [];
        if (!is_array($raw) || $raw === []) {
            throw new McpToolError('guest_ids is required — call list_guests (rsvp_status: pending) to choose them');
        }
        if (count($raw) > self::MAX_GUEST_IDS) {
            throw new McpToolError('at most ' . self::MAX_GUEST_IDS . ' guests per call — send them in batches');
        }

        // Which of the ids are really on THIS wedding's list, and which are
        // already invited (their clock must not restart).
        $onList = [];
        $foreign = [];
        $already = 0;
        foreach ($raw as $candidate) {
            $id = (int) $candidate;
            if ($id <= 0) {
                continue;
            }
            $guest = Guest::find_by_id($id);
            if (!$guest || (int) $guest->booking_id !== $bookingId) {
                $foreign[] = $id;
                continue;
            }
            $onList[$id] = true;
            if (!empty($guest->rsvp_invited_at)) {
                $already++;
            }
        }
        if ($onList === []) {
            throw new McpToolError('none of those guests are on wedding #' . $bookingId . '\'s list — nothing was marked');
        }

        $note = 'mark ' . count($onList) . ' guest(s) of wedding #' . $bookingId
            . ' as invited (their RSVP reminder clock starts)'
            . ($already > 0 ? '; ' . $already . ' already had an invite and keep their original timestamp' : '')
            . ($foreign !== [] ? '; ' . count($foreign) . ' id(s) are not on this wedding and were ignored' : '');

        if (!McpTools::bool_arg($args, 'confirm')) {
            return self::preview('mark_invited', $note, ['guest_ids' => array_keys($onList), 'ignored_ids' => $foreign], $bookingId);
        }

        $marked = Guest::mark_invited(array_keys($onList), $bookingId);

        return self::applied('mark_invited', $note . ' — ' . $marked . ' row(s) changed', $bookingId, [
            'marked'      => $marked,
            'ignored_ids' => $foreign,
        ]);
    }

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_toggle_task(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;
        $itemId    = McpTools::int_arg($args, 'item_id', 0, 0, PHP_INT_MAX);
        if ($itemId <= 0) {
            throw new McpToolError('item_id is required — call list_tasks to see the checklist');
        }

        // Find the item on THIS booking before touching anything: Checklist::toggle
        // scopes its own UPDATE by booking, and this makes the refusal readable.
        $item = null;
        foreach (Checklist::find_for_booking($bookingId) as $row) {
            if ((int) $row->id === $itemId) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            throw new McpToolError('item #' . $itemId . ' is not on wedding #' . $bookingId . '\'s checklist');
        }
        $flipping = (int) $item->is_done === 1 ? 'untick (mark as not done)' : 'tick (mark as done)';
        $note = $flipping . ' checklist item #' . $itemId . ' "' . (string) $item->title . '" on wedding #' . $bookingId;

        if (!McpTools::bool_arg($args, 'confirm')) {
            return self::preview('toggle_task', $note, [
                'item_id'  => $itemId,
                'title'    => (string) $item->title,
                'is_done'  => (int) $item->is_done,
                'would_be' => (int) $item->is_done === 1 ? 0 : 1,
            ], $bookingId);
        }

        if (!Checklist::toggle($itemId, $bookingId)) {
            throw new McpToolError('the checklist item could not be changed');
        }

        return self::applied('toggle_task', $note, $bookingId, ['item_id' => $itemId]);
    }

    // ==================================================================
    // Tier 3 handlers — every one of them: resolve, ask, then act
    // ==================================================================

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_send_bulk_sms(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;
        $sender    = self::staff_user_id($principal, 'send SMS to a guest list');

        $audience = McpTools::enum_arg($args, 'audience', self::AUDIENCES, 'all');
        $message  = trim(McpTools::str_arg($args, 'message'));
        if ($message === '') {
            throw new McpToolError('message is required — an empty SMS is not a message');
        }
        if (mb_strlen($message) > self::MAX_SMS_CHARS) {
            throw new McpToolError('the message is longer than ' . self::MAX_SMS_CHARS . ' characters — shorten it');
        }

        $phones = SMS::audience_phones($bookingId, $audience);
        if ($phones === []) {
            throw new McpToolError('no guest of wedding #' . $bookingId . ' is in the "' . $audience . '" audience with a phone number');
        }
        $costEach = SMS::calculate_cost($message);
        $cost     = $costEach * count($phones);
        $credits  = SMS::get_credits($sender);

        // What the PLANNER reads — the exact words, the audience and the cost,
        // because that is what they are being asked to authorise.
        $note = 'send one SMS to the "' . $audience . '" audience of wedding #' . $bookingId
            . ': ' . count($phones) . ' recipient(s), ' . mb_strlen($message) . ' characters, KSh '
            . number_format($cost, 2) . ' total (KSh ' . number_format($costEach, 2) . ' each); sender has '
            . number_format($credits) . ' credit(s). Message: «' . $message . '»';
        // What the LOG keeps: the same send, without the message body (an SMS can
        // name people, and the approval row is where that text belongs).
        $audit = 'send one SMS to the "' . $audience . '" audience of wedding #' . $bookingId
            . ': ' . count($phones) . ' recipient(s), ' . mb_strlen($message) . ' characters, KSh '
            . number_format($cost, 2) . ' total';

        $approval = trim((string) ($args['approval'] ?? ''));
        $payload  = self::committed('send_bulk_sms', $args);

        if ($approval === '') {
            // A push-free dry run as well: an operator who has not bought credits
            // should learn that from the request, not after approving it.
            if ($credits < $cost) {
                throw new McpToolError('this send costs ' . $costEach * 1 . ' credit(s) per recipient ('
                    . number_format($cost, 2) . ' total) and this account has ' . number_format($credits)
                    . ' — buy credits in the SMS centre first');
            }
            return self::approval_required('send_bulk_sms', $principal, $bookingId, $payload, $note, [
                'recipients'    => count($phones),
                'credits_cost'  => $cost,
                'characters'    => mb_strlen($message),
            ], $audit);
        }

        // Verify the human's approval against THIS payload before anything leaves
        // the building; McpApprovals consumes it, so this can happen once.
        McpApprovals::consume($approval, $principal, 'send_bulk_sms', $payload);

        $result = SMS::send_bulk_audience($bookingId, $audience, $message, $sender);

        return self::applied('send_bulk_sms', $audit . ' — sent ' . (int) $result['sent'] . ', failed ' . (int) $result['failed'], $bookingId, [
            'sent'    => (int) $result['sent'],
            'failed'  => (int) $result['failed'],
            'note'    => $result['failed'] > 0
                ? 'Some recipients were not sent: check the SMS centre log (credits, or a number the gateway refused).'
                : 'Every recipient was accepted by the gateway.',
        ]);
    }

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_record_payment(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;

        $eventId = McpTools::int_arg($args, 'event_id', 0, 0, PHP_INT_MAX);
        $amount  = McpTools::float_arg($args, 'amount', 0.0);
        $method  = McpTools::enum_arg($args, 'method', self::PAYMENT_METHODS, '');
        $code    = strtoupper(McpTools::str_arg($args, 'mpesa_code'));

        if ($eventId <= 0) {
            throw new McpToolError('event_id is required — a payment belongs to one of the wedding\'s functions');
        }
        if ($amount <= 0) {
            throw new McpToolError('amount must be greater than zero');
        }
        if ($method === '') {
            throw new McpToolError('method must be one of: ' . implode(', ', self::PAYMENT_METHODS));
        }

        // The function must belong to this booking — the same check
        // admin/budget_add.php makes, through the model rather than a query the
        // MCP layer wrote.
        $event = Events::find_by_id($eventId);
        if (!$event || (int) $event->booking_id !== $bookingId) {
            throw new McpToolError('event #' . $eventId . ' is not a function of wedding #' . $bookingId);
        }

        $cash = 0.0;
        $credit = 0.0;
        $callbackId = 0;
        if ($method === 'cash') {
            $cash = $amount;
        } elseif ($method === 'bank') {
            $credit = $amount;
        } else {
            if (!preg_match('/^[A-Z0-9]{10}$/', $code)) {
                throw new McpToolError('method=mpesa needs the 10-character M-Pesa transaction code (e.g. QKJ7GH9P2W)');
            }
            if (MpesaC2B::verification_enabled()) {
                $check = MpesaC2B::verify($code, $amount, (string) $bookingId);
                if (empty($check['verified'])) {
                    MpesaC2B::record_reject($code, 'MCP record_payment for booking #' . $bookingId . ': ' . ($check['reason'] ?? 'not verified'), $amount);
                    throw new McpToolError('that M-Pesa code could not be verified for this amount: '
                        . (string) ($check['reason'] ?? 'no matching payment was reported')
                        . ' — an administrator reviews rejected codes on the M-Pesa ledger');
                }
                $callbackId = (int) ($check['callback_id'] ?? 0);
            }
        }

        $note = 'record a ' . $method . ' payment of KSh ' . number_format($amount, 2) . ' for wedding #' . $bookingId
            . ' against function "' . (string) $event->title . '" (#' . $eventId . ')'
            . ($method === 'mpesa' ? ', M-Pesa code ' . $code : '')
            . ' — it appears immediately in the couple\'s budget and the staff ledger';
        // The log keeps the amount and the method (an operator's business fact)
        // but never the transaction code — a code is a reference that must not be
        // duplicated into an append-only table.
        $audit = 'record a ' . $method . ' payment of KSh ' . number_format($amount, 2) . ' for wedding #' . $bookingId
            . ' against function #' . $eventId;

        $approval = trim((string) ($args['approval'] ?? ''));
        $payload  = self::committed('record_payment', $args);
        if ($approval === '') {
            return self::approval_required('record_payment', $principal, $bookingId, $payload, $note, [], $audit);
        }
        McpApprovals::consume($approval, $principal, 'record_payment', $payload);

        $liquidate = new Liquidation();
        $liquidate->event_id  = $eventId;
        $liquidate->payment   = $amount;
        $liquidate->cash      = $cash;
        $liquidate->credit    = $credit;
        $liquidate->booking_id = $bookingId;
        // The ledger row belongs to the couple's account, exactly as the staff
        // form records it — not to whoever approved it.
        $liquidate->user_id   = (int) $booking->user_id;
        $liquidate->mpesa_code = $method === 'mpesa' ? $code : null;
        $now = date('Y-m-d H:i:s');
        $liquidate->date_issue    = $now;
        $liquidate->date_modified = $now;

        try {
            if (!$liquidate->save()) {
                throw new McpToolError('the payment could not be saved — nothing was recorded');
            }
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                throw new McpToolError('that M-Pesa code has already been recorded on this platform — it is single-use');
            }
            throw $e;
        }
        $rowId = (int) ($liquidate->id ?: 0);

        // Bind the verified callback to the saved row so the same code cannot be
        // reused for a second payment (mark_consumed is race-safe).
        if ($callbackId > 0 && $rowId > 0) {
            MpesaC2B::mark_consumed($callbackId, 'tbl_liquidation', $rowId);
        }

        return self::applied('record_payment', $audit . ' — ledger row #' . $rowId, $bookingId, [
            'liquidation_id' => $rowId,
            'amount'         => $amount,
            'method'         => $method,
            'mpesa_code'     => $method === 'mpesa' ? $code : null,
        ]);
    }

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_initiate_stk_deposit(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;
        $payerId   = (int) $booking->user_id;

        if (!MpesaStk::enabled()) {
            throw new McpToolError('M-Pesa STK push is not enabled on this installation (WPMS_MPESA_STK)');
        }
        $phone  = SMS::format_phone(McpTools::str_arg($args, 'phone'));
        $amount = McpTools::float_arg($args, 'amount', 0.0);
        $eventId = McpTools::int_arg($args, 'event_id', 0, 0, PHP_INT_MAX);

        if (!preg_match('/^254[17]\d{8}$/', $phone)) {
            throw new McpToolError('phone must be a Kenyan M-Pesa number (e.g. 0712 345 678)');
        }
        if ($amount < 50) {
            throw new McpToolError('amount must be at least KSh 50');
        }

        $note = 'ask M-Pesa to prompt ' . $phone . ' for a KSh ' . number_format($amount, 2) . ' deposit on wedding #' . $bookingId
            . ($eventId > 0 ? ' (function #' . $eventId . ')' : '')
            . '. The customer must complete the prompt on their handset: a push is a PROMISE, not a payment, and nothing '
            . 'is recorded as received until M-Pesa confirms it. The push is refused if it would exceed the balance due.';
        // The log does not keep the payer's phone number.
        $audit = 'ask M-Pesa to prompt the payer for a KSh ' . number_format($amount, 2) . ' deposit on wedding #' . $bookingId
            . ($eventId > 0 ? ' (function #' . $eventId . ')' : '');

        $approval = trim((string) ($args['approval'] ?? ''));
        $payload  = self::committed('initiate_stk_deposit', $args);
        if ($approval === '') {
            return self::approval_required('initiate_stk_deposit', $principal, $bookingId, $payload, $note, [
                'phone'  => $phone,
                'amount' => $amount,
            ], $audit);
        }
        McpApprovals::consume($approval, $principal, 'initiate_stk_deposit', $payload);

        // The model owns every rule here: enabled switch, phone shape, the
        // balance guard (a deposit can never exceed what is owed) and the row.
        $result = MpesaStk::initiate('deposit', $payerId, $phone, $amount, [
            'booking_id' => $bookingId,
            'event_id'   => $eventId,
        ]);
        if (empty($result['ok'])) {
            throw new McpToolError((string) ($result['error'] ?? 'the M-Pesa prompt could not be started'));
        }
        $pushId = (int) ($result['push']->id ?? $result['id'] ?? 0);

        return self::applied('initiate_stk_deposit', $audit . ' — push #' . $pushId . ' is pending', $bookingId, [
            'status'     => 'pending_promise',
            'push_id'    => $pushId,
            'phone'      => $phone,
            'amount'     => $amount,
            'what_next'  => 'This is NOT a payment yet. Poll M-Pesa: the push is fulfilled only when the customer enters their '
                . 'PIN and M-Pesa reports success (ResultCode 0), exactly once. Until then the balance is unchanged.',
        ]);
    }

    /** @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string} */
    private static function tool_assign_planner(array $args, array $principal): array
    {
        $booking   = McpTools::require_booking($args, $principal);
        $bookingId = (int) $booking->booking_id;

        $organizerId = McpTools::int_arg($args, 'organizer_id', 0, 0, PHP_INT_MAX);
        if ($organizerId <= 0) {
            throw new McpToolError('organizer_id is required — it is a staff account id, not a name');
        }
        $planner = Users::find_by_id($organizerId);
        if (!$planner) {
            throw new McpToolError('there is no staff account #' . $organizerId);
        }
        // access_level is an enum: '1' and '2' are staff, '0' is a vendor. The
        // comparison is against STRINGS for the reason ApiToken and JobWatch
        // both document — MySQL compares an enum to an integer by ordinal.
        if (!in_array((string) $planner->access_level, ['1', '2'], true)) {
            throw new McpToolError('account #' . $organizerId . ' is not a planner or admin account (access_level '
                . (string) $planner->access_level . ')');
        }

        $current = (int) ($booking->organizer_id ?? 0);
        $name = trim((string) ($planner->username ?? ''));
        $note = 'set the planner of wedding #' . $bookingId . ' to ' . ($name !== '' ? '"' . $name . '" ' : '')
            . '(staff account #' . $organizerId . '), replacing '
            . ($current > 0 ? 'account #' . $current : 'nobody')
            . '. This writes organizer_id — the planner the couple is told about, whose SMS credits courtesy messages use, AND who '
            . 'can open this wedding: per-planner scoping gives it to the organizer from now on, takes it away from every other '
            . 'planner (a super admin still sees all of them), and leaves the unassigned ones visible to everybody.';
        // One sentence, used for the approval, the result and the log: this write
        // names staff accounts, not wedding guests, so there is nothing here to
        // keep out of the audit trail.
        $audit = $note;

        $approval = trim((string) ($args['approval'] ?? ''));
        $payload  = self::committed('assign_planner', $args);
        if ($approval === '') {
            return self::approval_required('assign_planner', $principal, $bookingId, $payload, $note, ['current' => $current]);
        }
        McpApprovals::consume($approval, $principal, 'assign_planner', $payload);

        if ($current === $organizerId) {
            // Nothing to do, and that is not a failure: the wedding already has
            // this planner. Reported honestly so a planner is not told a save
            // failed when the state was already what was asked for.
            return self::unchanged('assign_planner', $audit . ' — it was already this planner; nothing changed',
                $bookingId, ['organizer_id' => $organizerId]);
        }

        $booking->organizer_id = $organizerId;
        $booking->save_booking();
        // Read back, for the same reason as update_guest: `update_booking()`
        // reports changed rows, not success.
        $after = Booking::find_by_booking_id($bookingId);
        if (!$after || (int) $after->organizer_id !== $organizerId) {
            throw new McpToolError('the assignment could not be saved — the wedding still has ' . $current . ' as its planner');
        }

        return self::applied('assign_planner', $audit, $bookingId, [
            'organizer_id' => $organizerId,
            'previous'     => $current,
            'scoping'      => 'wedding #' . $bookingId . ' is now visible to staff account #' . $organizerId
                . ' (and to super admins); other planners can no longer open it',
        ]);
    }

    // ==================================================================
    // Result shaping
    // ==================================================================

    /**
     * A Tier-2 call without `confirm`: what WOULD happen, and a promise that
     * nothing did. Returned as a normal result (not an error) because the model
     * did nothing wrong — it is being asked to say the change out loud first.
     *
     * @param array<string,mixed> $would
     * @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string}
     */
    private static function preview(string $action, string $note, array $would, int $bookingId, string $auditNote = ''): array
    {
        return [
            'data' => [
                'status'       => 'preview',
                'action'       => $action,
                'would_change' => $would,
                'note'         => $note,
                'requires'     => 'confirm: true — nothing has been written',
            ],
            'booking_id' => $bookingId,
            'applied'    => false,
            // The audit line is the same sentence EXCEPT where that sentence
            // quotes a name, a number or a message body — see audit_note().
            'note'       => 'preview only (no write): ' . ($auditNote !== '' ? $auditNote : $note),
        ];
    }

    /**
     * A Tier-3 call without an approval: the request is on record, the planner
     * has something to read, and NOTHING has happened yet.
     *
     * @param array<string,mixed> $principal
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $extra
     * @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string}
     */
    private static function approval_required(
        string $tool,
        array $principal,
        int $bookingId,
        array $payload,
        string $note,
        array $extra = [],
        string $auditNote = ''
    ): array {
        // The planner reads the full sentence (that is what they are approving);
        // the audit trail keeps the version without the message body, the phone
        // number or the transaction code.
        $request = McpApprovals::request($principal, $tool, self::TIER_APPROVAL, $bookingId, $payload, $note);

        /*
         * A planner already refused this exact payload. Say so, with their
         * reason, instead of asking them again: an assistant that cannot tell a
         * refusal from a queue keeps proposing the same thing, and a planner
         * keeps refusing it. Nothing was done, so this is not `applied`.
         */
        if (!empty($request['denied'])) {
            $reason = (string) ($request['reason'] ?? '');
            return [
                'data' => [
                    'status'    => 'refused',
                    'reference' => $request['reference'],
                    'reason'    => $reason !== '' ? $reason : 'no reason was given',
                    'what_now'  => 'A signed-in planner refused this exact request, so nothing was sent or recorded. '
                        . 'Do not repeat it unchanged: propose a different message, amount or booking, or ask the planner '
                        . 'directly. A changed payload is a new request.',
                ] + $extra,
                'booking_id' => $bookingId,
                'applied'    => false,
                'note'       => mb_substr('refused by a planner (' . $request['reference'] . '): ' . $reason, 0, 250),
            ];
        }

        $data = [
            'status'      => 'approval_required',
            'reference'   => $request['reference'],
            'expires_at'  => $request['expires_at'],
            'will_happen' => $note,
            'nothing_happened_yet' => 'No message was sent, no money was recorded and no prompt was started. A signed-in '
                . 'planner must approve this exact payload on the Approvals page (AI Assistant → Approvals); the approval '
                . 'string it prints is then passed back as `approval` on this same call, unchanged.',
            'payload_hash' => McpApprovals::payload_hash($tool, $payload),
        ] + $extra;

        /*
         * The identical request was made before and DIED waiting (McpApprovals::
         * previous_expiry). Saying so is the difference between an assistant that
         * keeps retrying the reference it was given and one that understands the
         * reference is gone: the old one can never be approved or used now, and
         * this call has a NEW reference. The planner is told separately, by the
         * sweep's bell; this is the assistant's half of the same fact.
         */
        $previous = $request['previous'] ?? null;
        if (is_array($previous)) {
            $data['previous_request_expired'] = [
                'reference'        => $previous['reference'],
                'outcome'          => $previous['outcome'],
                'window_closed_at' => $previous['expired_at'],
                'what_now'         => 'That earlier request is dead: it cannot be approved or used any more, and nothing from it '
                    . 'carries over. This call is a NEW request (' . $request['reference'] . ') for the same payload, and it '
                    . 'needs its own decision. Quote the new reference, not the old one.',
            ];
        }

        return [
            'data'       => $data,
            'booking_id' => $bookingId,
            'applied'    => false,
            'note'       => mb_substr(
                'approval requested (' . $request['reference']
                . (is_array($previous) ? ', replaces expired ' . $previous['reference'] : '')
                . '): ' . ($auditNote !== '' ? $auditNote : $note),
                0,
                250
            ),
        ];
    }

    /**
     * A write that happened. `note` is what the audit trail keeps.
     *
     * @param array<string,mixed> $extra
     * @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string}
     */
    private static function applied(string $action, string $note, int $bookingId, array $extra = []): array
    {
        return [
            'data'       => ['status' => 'applied', 'action' => $action, 'note' => $note] + $extra,
            'booking_id' => $bookingId,
            'applied'    => true,
            'note'       => $note,
        ];
    }

    /**
     * The requested end state was ALREADY the state. Nothing was written, so the
     * idempotency key is not spent and the audit row says so plainly — the
     * alternative (reporting a failed save) sends a planner chasing a write that
     * never needed to happen.
     *
     * @param array<string,mixed> $extra
     * @return array{data:array<string,mixed>, booking_id:int, applied:bool, note:string}
     */
    private static function unchanged(string $action, string $note, int $bookingId, array $extra = []): array
    {
        return [
            'data'       => ['status' => 'unchanged', 'action' => $action, 'note' => $note] + $extra,
            'booking_id' => $bookingId,
            'applied'    => false,
            'note'       => $note,
        ];
    }

    /**
     * The staff account whose SMS credits a send is charged to — the token's own
     * principal, exactly as admin/sms_center.php charges $_SESSION['id'].
     *
     * A couple's or a vendor's token cannot reach this: a wedding's guest list is
     * not something a partner or a supplier may broadcast to, and the credits
     * being spent belong to a staff account either way.
     *
     * @param array<string,mixed> $principal
     * @throws McpToolError
     */
    private static function staff_user_id(array $principal, string $what): int
    {
        $role = (string) ($principal['role'] ?? '');
        if (!in_array($role, ['staff', 'super_admin'], true) || (string) ($principal['kind'] ?? '') !== ApiToken::KIND_USER) {
            throw new McpToolError('only a planner or admin account may ' . $what . ' — this token acts as a ' . ($role !== '' ? $role : 'unknown') . ' account');
        }
        return (int) $principal['id'];
    }

    /**
     * `field: before → after` for a set of changes, for the preview, the human
     * summary and the audit note. Long values are truncated: the note is a log
     * line, not a second copy of the record.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function describe_changes(array $before, array $after): string
    {
        $parts = [];
        foreach ($after as $field => $value) {
            $from = (string) ($before[$field] ?? '');
            $to   = is_scalar($value) ? (string) $value : json_encode($value);
            $parts[] = $field . ' "' . mb_substr($from, 0, 32) . '" → "' . mb_substr((string) $to, 0, 32) . '"';
        }
        return implode(', ', $parts);
    }
}
