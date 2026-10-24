<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.16 — the tool catalogue an AI client is offered (McpTools)
 *
 * One entry per thing the assistant may ask this platform. Each entry says what
 * the tool is for, which scope a token needs, the JSON Schema of its arguments,
 * and the handler that answers it. The catalogue is data first: `definitions()`
 * is what `tools/list` returns, `call()` is what `tools/call` dispatches to, and
 * both read the same table — so a tool can never be advertised without a handler
 * or handled without being advertised.
 *
 * THREE RULES EVERY HANDLER FOLLOWS
 *
 *   1. A booking id is a REQUEST, not a permission. Handlers that take
 *      `booking_id` resolve it and then ask `booking_visible_to_session()` — the
 *      same predicate admin/ pages use — so a couple's token reaches its own
 *      wedding and nothing else, and a vendor's token reaches no couple data at
 *      all. Nothing else in this class decides who may ask.
 *
 *   2. Tools return DATA, never SQL and never a table. The AI sees guests,
 *      payments and tasks the way a planner sees them on screen; the queries are
 *      bound, the limits are clamped, and paging is explicit so a 600-guest list
 *      is paged rather than dumped at a language model.
 *
 *   3. A refusal is a business result, not a crash. A tool that cannot answer
 *      (a booking the account may not open, an argument the columns cannot hold)
 *      throws McpToolError, which the server turns into an `isError` tool result
 *      the assistant can read and explain — never a 500 or a leaked stack.
 *
 * READS LIVE HERE; WRITES LIVE IN McpWrites. The split is deliberate: this file
 * is the surface almost every question needs, and the write catalogue — with its
 * two tiers, its previews and its human-approval boundary — gets a file of its
 * own to be read and reviewed in one sitting. The catalogue is still ONE list to
 * the protocol: `definitions()` merges the two, and `known()`, `scope_of()`,
 * `permitted()` and `call()` delegate by name, so a tool can never be advertised
 * without a handler or handled without being advertised.
 *
 * `whoami` reports whether changing data is enabled on this install, so an
 * assistant asked to change something can say why it cannot yet.
 */
final class McpTools
{
    /** Most rows any list tool will return in one call. */
    public const MAX_LIMIT = 200;

    /** Rows a list tool returns when the caller does not say. */
    public const DEFAULT_LIMIT = 50;

    /**
     * The catalogue.
     *
     * `scope` is the token scope the tool requires ('' = any valid token).
     * `schema` is the JSON Schema handed to the client — the contract the model
     * reads before it decides what to send.
     *
     * `roles` (optional) is the narrowest gate: the roles a tool is offered to
     * at all, before any scope is consulted. Omitted = every role. `['staff']`
     * means planners and super admins only — a couple's or a vendor's token is
     * never even TOLD the tool exists, because a wedding's plan, credits and
     * audience counts are not a couple's data. Row rules still run afterwards;
     * this is a ceiling, exactly like a scope, and never a substitute for one.
     *
     * @var array<string, array{title:string, scope:string, readonly:bool, description:string, schema:array<string,mixed>, handler:string, roles?:string[]}>
     */
    private const SPECS = [
        'whoami' => [
            'title'       => 'Who am I',
            'scope'       => '',
            'readonly'    => true,
            'description' => 'Who this token acts as, what it is allowed to do, and whether changing data is enabled on this '
                . 'install. Call this first when you need to know which wedding or role you are working with.',
            'schema'      => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'handler'     => 'tool_whoami',
        ],
        'system_status' => [
            'title'       => 'Platform pulse',
            'scope'       => 'admin:system',
            'roles'       => ['super_admin'],
            'readonly'    => true,
            'description' => 'The operator\'s own dashboard as one call: weddings upcoming and past, how many are unconfirmed or '
                . 'unassigned, package value and money collected, subscription MRR and renewals, guest RSVP response, checklist '
                . 'overdue, vendor supply and deals, AI-call activity and job health. Also returns `movement`: each headline '
                . 'figure against the last weekly business-pulse snapshot taken before this week, with value, previous value '
                . 'and the signed change — use it when asked whether the business is improving or falling. `movement.available` '
                . 'is false until a first weekly brief has been recorded; then there is no comparison, not a flat week. Every figure '
                . 'is an aggregate or an internal id — no names, phone numbers, emails, dietary notes or payment references. '
                . 'Use it when asked how the business is doing; use list_weddings then get_wedding for one wedding. Super admins only.',
            'schema'      => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'handler'     => 'tool_system_status',
        ],
        'list_insights' => [
            'title'       => 'What needs attention',
            'scope'       => 'admin:system',
            'roles'       => ['super_admin'],
            'readonly'    => true,
            'description' => 'Findings from fixed rules over the platform data: weddings close to the day that are unconfirmed, '
                . 'unassigned or short of RSVPs or money, overdue checklist work, stale vendor deals, capacity limits, planner '
                . 'overload, renewals due and failing jobs. Each finding has a severity (high/medium/low), the numbers that '
                . 'fired it (evidence) and a page to act on. Deterministic — it cannot invent a finding; open the linked page '
                . 'or the per-wedding tool to see who is involved. Super admins only.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low'],
                                   'description' => 'Only findings at this severity. Omit for all, worst first.'],
                    'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50,
                                   'description' => 'How many findings to return. Default 25.'],
                ],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_insights',
        ],
        'list_weddings' => [
            'title'       => 'List weddings',
            'scope'       => 'read:weddings',
            'readonly'    => true,
            'description' => 'The weddings this account may open, with the couple, the date, days until the wedding, guest and '
                . 'RSVP counts. Use the returned booking_id with every other tool. Staff see every wedding on the platform; a '
                . 'couple sees only their own.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'when'   => ['type' => 'string', 'enum' => ['upcoming', 'past', 'all'],
                                 'description' => 'Which weddings to return. Default: upcoming (soonest first).'],
                    'search' => ['type' => 'string', 'description' => 'Match the bride or groom name or the couple email.'],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                ],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_weddings',
        ],
        'get_wedding' => [
            'title'       => 'Wedding overview',
            'scope'       => 'read:weddings',
            'readonly'    => true,
            'description' => 'One wedding in one call: couple and contact, date and days to go, package and price, guest and RSVP '
                . 'totals, checklist progress with the next few tasks, payments received against the balance, and how many vendor '
                . 'deals are booked or pending. Start here when asked how a wedding is going.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer', 'description' => 'From list_weddings.'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_get_wedding',
        ],
        'list_guests' => [
            'title'       => 'List guests',
            'scope'       => 'read:guests',
            'readonly'    => true,
            'description' => 'A wedding\'s guest list, filtered and paged: RSVP state, priority band, out-of-town, transport, '
                . 'dietary needs, or a text search. Returns how many matched so you know whether to page.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id'   => ['type' => 'integer'],
                    'rsvp_status'  => ['type' => 'string', 'enum' => ['pending', 'yes', 'no', 'maybe']],
                    'priority'     => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D', 'E'],
                                       'description' => 'A = closest family, E = furthest.'],
                    'out_of_town'  => ['type' => 'boolean', 'description' => 'Only guests travelling in.'],
                    'transport'    => ['type' => 'boolean', 'description' => 'Only guests who need transport.'],
                    'dietary'      => ['type' => 'boolean', 'description' => 'Only guests with something recorded to cater for.'],
                    'search'       => ['type' => 'string', 'description' => 'Matches name, guest name, phone or city.'],
                    'limit'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                    'offset'       => ['type' => 'integer', 'minimum' => 0],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_guests',
        ],
        'rsvp_status' => [
            'title'       => 'RSVP status',
            'scope'       => 'read:guests',
            'readonly'    => true,
            'description' => 'How a wedding\'s invitations stand: the tally of answers, who has still not replied, how many were '
                . 'invited and never answered, and how many reminders have gone out. The right call for "who do we still need '
                . 'to chase?".',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'pending_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT,
                                        'description' => 'How many non-responders to list back. Default 25.'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_rsvp_status',
        ],
        'list_tasks' => [
            'title'       => 'List checklist tasks',
            'scope'       => 'read:tasks',
            'readonly'    => true,
            'description' => 'The planning checklist for a wedding: every milestone with its phase, whether it is done, the date it '
                . 'is due (counted back from the wedding day) and whether it is overdue. Can be filtered to one phase or to what '
                . 'is still open.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'phase'      => ['type' => 'string', 'description' => 'e.g. Foundation, Bookings, Legal & Church, Guests & Details, Final Month.'],
                    'done'       => ['type' => 'boolean', 'description' => 'true = only finished, false = only open. Omit for all.'],
                    'overdue_only' => ['type' => 'boolean', 'description' => 'Only open tasks whose due date has passed.'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_tasks',
        ],
        'budget_position' => [
            'title'       => 'Budget and payments',
            'scope'       => 'read:budget',
            'readonly'    => true,
            'description' => 'What a wedding costs and what has been paid: package price, payments received split by method '
                . '(M-Pesa, cash, credit), the balance still due, the installment plan when there is one, and the most recent '
                . 'payments with their M-Pesa codes.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id'   => ['type' => 'integer'],
                    'recent_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50,
                                       'description' => 'How many recent payments to list. Default 10.'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_budget_position',
        ],
        'list_vendor_deals' => [
            'title'       => 'Vendors booked for a wedding',
            'scope'       => 'read:vendors',
            'readonly'    => true,
            'description' => 'The vendors engaged for one wedding: business, category, package, event date, amount, commission '
                . 'and whether the deal is confirmed or still pending.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_vendor_deals',
        ],
        'search_vendors' => [
            'title'       => 'Search the vendor catalogue',
            'scope'       => 'read:vendors',
            'readonly'    => true,
            'description' => 'Approved vendors on the platform with their active packages and prices — for questions like '
                . '"find a photographer in Nairobi under 200,000". Filters are optional and combine.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'category'  => ['type' => 'string', 'description' => 'Partial match, e.g. photo, cater, deejay.'],
                    'county'    => ['type' => 'string', 'description' => 'Matches the county or the location text.'],
                    'max_price' => ['type' => 'number', 'description' => 'Only packages at or below this price in KSh.'],
                    'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30,
                                    'description' => 'How many vendors to return. Default 10.'],
                ],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_search_vendors',
        ],
        'list_timeline' => [
            'title'       => 'Wedding day timeline',
            'scope'       => 'read:weddings',
            'readonly'    => true,
            'description' => 'The running order of a wedding: every function of the day in time order (ruracio, church, '
                . 'reception, after-party) with its venue, its planned headcount and the vendors covering it. Functions '
                . 'nobody has planned for are listed with a headcount of zero and planned=false, so "what is still unplanned" '
                . 'is answerable. Use it to answer questions about the day itself; list_tasks covers the run-up.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer', 'description' => 'From list_weddings.'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_timeline',
        ],
        'headcount_capacity' => [
            'title'       => 'Headcount against booked capacity',
            'scope'       => 'read:guests',
            'readonly'    => true,
            'description' => 'Whether the crowd planned for each function still fits the guest capacity of the packages booked '
                . 'on it. Returns the per-function plan, the confirmed deals that state a limit, the functions already over '
                . 'a limit, and the positions close to one (within a share, 10% by default). Each finding carries the same '
                . 'way-out sentence the planner sees on the venue sheet, naming the vendor\'s next package up that covers the '
                . 'crowd.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                    'share'      => ['type' => 'number', 'enum' => [0.05, 0.10, 0.20],
                                     'description' => 'How close to a package limit counts as tight. Default 0.10 (10%).'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_headcount_capacity',
        ],
        'list_notifications' => [
            'title'       => 'Your own alerts',
            'scope'       => '',
            'readonly'    => true,
            'description' => 'The alerts on THIS token\'s own account — the same bell feed the person sees in the planner: '
                . 'booking confirmations, RSVP answers, vendor enquiries, capacity warnings. Read-only and never anyone '
                . 'else\'s feed. Useful for "what needs my attention today".',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'unread_only' => ['type' => 'boolean', 'description' => 'Only alerts that have not been read. Default false.'],
                    'search'      => ['type' => 'string', 'description' => 'Match the alert text.'],
                    'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                    'offset'      => ['type' => 'integer', 'minimum' => 0],
                ],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_list_notifications',
        ],
        'wedding_website' => [
            'title'       => 'The couple\'s wedding page',
            'scope'       => 'read:weddings',
            'readonly'    => true,
            'description' => 'The shareable wedding website built for this booking: its public URL, whether it is published, '
                . 'how many views it has had, the story and venue it states — so an assistant can tell a couple what their '
                . 'guests are being shown.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer'],
                ],
                'required'   => ['booking_id'],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_wedding_website',
        ],
        'my_subscription' => [
            'title'       => 'My subscription',
            'scope'       => 'read:subscription',
            'roles'       => ['staff'],
            'readonly'    => true,
            'description' => 'The subscription behind the account this token acts as: the plan, whether it is active, when it '
                . 'runs out, how many days are left, and whether it is still a trial. Read-only — a plan is bought or changed '
                . 'by the planner on admin/subscription.php, never over MCP.',
            'schema'      => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'handler'     => 'tool_my_subscription',
        ],
        'list_plans' => [
            'title'       => 'Plan catalogue',
            'scope'       => 'read:subscription',
            'roles'       => ['staff'],
            'readonly'    => true,
            'description' => 'The plans this platform sells: slug, name, monthly price in KSh and the wedding limit each one '
                . 'carries. Read-only — prices are the operator\'s surface (admin/subscription_plans.php) and are never '
                . 'changed over MCP.',
            'schema'      => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'handler'     => 'tool_list_plans',
        ],
        'sms_position' => [
            'title'       => 'SMS credits and audience cost',
            'scope'       => 'read:sms',
            'roles'       => ['staff'],
            'readonly'    => true,
            'description' => 'What a bulk SMS would cost before anyone approves one: the account\'s remaining credits, how many '
                . 'guests an audience covers (all / out-of-town / VIP), and the price at the platform\'s own rate of KSh 1 per '
                . '160 characters. Counts only — it never returns phone numbers. Use it to price a message, then ask the '
                . 'planner to approve send_bulk_sms.',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'booking_id' => ['type' => 'integer', 'description' => 'Price the audience for this wedding.'],
                    'audience'   => ['type' => 'string', 'enum' => ['all', 'upcountry', 'vip'],
                                     'description' => 'all = every guest with a phone; upcountry = out_of_town; vip = priority A.'],
                    'message'    => ['type' => 'string', 'maxLength' => 480,
                                     'description' => 'Draft text, to price it exactly. Never sent by this tool.'],
                ],
                'additionalProperties' => false,
            ],
            'handler'     => 'tool_sms_position',
        ],
    ];

    // ==================================================================
    // The catalogue, as the protocol needs it
    // ==================================================================

    /**
     * The tool definitions for `tools/list`, filtered to what this token may
     * actually call.
     *
     * A tool the token cannot use is not advertised: an assistant that never sees
     * `budget_position` will not spend a turn discovering it is refused, and the
     * list stops doubling as a map of surfaces this account has no business with.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function definitions(array $principal): array
    {
        $out = [];
        foreach (self::SPECS as $name => $spec) {
            if (!self::permitted($name, $principal)) {
                continue;
            }
            $out[] = [
                'name'        => $name,
                'title'       => $spec['title'],
                'description' => $spec['description'],
                'inputSchema' => $spec['schema'],
                'annotations' => [
                    'title'           => $spec['title'],
                    'readOnlyHint'    => (bool) $spec['readonly'],
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => false,
                ],
            ];
        }
        // The write catalogue is its own file (McpWrites); the protocol gets one
        // list, filtered by the same scopes and the same install switch.
        return array_merge($out, McpWrites::definitions($principal));
    }

    /** Is this a tool this build knows (read OR write)? */
    public static function known(string $name): bool
    {
        return isset(self::SPECS[$name]) || McpWrites::known($name);
    }

    /**
     * The scope a tool requires ('' = none). @throws McpToolError
     */
    public static function scope_of(string $name): string
    {
        if (!self::known($name)) {
            throw new McpToolError('unknown tool: ' . $name);
        }
        return McpWrites::known($name) ? McpWrites::scope_of($name) : (string) self::SPECS[$name]['scope'];
    }

    /**
     * The tier a tool belongs to: 1 read, 2 reversible write (confirm), 3 money /
     * messages / status (a signed-in human approves the exact payload).
     *
     * The server needs this to decide what a call must carry — and to record it
     * in the audit trail, so "the AI asked to send 200 text messages" and "the AI
     * read a guest list" are not the same row with different tool names.
     */
    public static function tier_of(string $name): int
    {
        return McpWrites::known($name) ? McpWrites::tier_of($name) : 1;
    }

    /**
     * Is this principal in one of the roles a tool is offered to?
     *
     * The role names are the ones ApiToken::principal() stamps — 'staff',
     * 'super_admin', 'vendor', 'couple' — so `['staff']` is judged by the SAME
     * predicate the browser uses (ApiToken::principal_is_staff()). Role gating is
     * a CEILING on top of scopes, never a replacement: a staff-only tool with no
     * scope of its own still runs the caller's row rules.
     *
     * @param string[] $roles
     */
    public static function role_allowed(array $principal, array $roles): bool
    {
        if (in_array('staff', $roles, true) && ApiToken::principal_is_staff($principal)) {
            return true;
        }
        return in_array((string) ($principal['role'] ?? ''), $roles, true);
    }

    /**
     * WHY this tool is refused for this principal, in a sentence worth reading.
     *
     * The dispatcher refuses on permitted() before any query runs, and the reason
     * it prints has to be the TRUE one: a tool withheld by ROLE (a couple asking
     * for send_bulk_sms) must not be explained as a missing scope, or an operator
     * would go and grant a scope that could never have helped.
     */
    public static function refusal_for(string $name, array $principal): string
    {
        $scope = self::scope_of($name);
        $roles = McpWrites::known($name) ? McpWrites::roles_of($name) : (self::SPECS[$name]['roles'] ?? null);
        if ($roles !== null && !self::role_allowed($principal, $roles)) {
            return self::role_refusal($principal);
        }
        if (ApiToken::is_write_scope($scope) && !McpServer::writes_enabled()) {
            return 'changing data over MCP is switched off on this install (' . McpServer::WRITE_SWITCH . ')';
        }
        return 'this token does not carry the ' . $scope . ' scope';
    }

    /** The reversible refusal for a tool this ROLE may not call. */
    public static function role_refusal(array $principal): string
    {
        return 'this token acts as ' . (string) ($principal['role'] ?? 'an unknown role')
            . '; that tool is offered to staff accounts only, because it reads the planner\'s own plan, credits or '
            . 'customer list rather than a wedding you can open';
    }

    /** May this principal call this tool at all (role + scope — the row rules run later)? */
    public static function permitted(string $name, array $principal): bool
    {
        if (!self::known($name)) {
            return false;
        }
        if (McpWrites::known($name)) {
            return McpWrites::permitted($name, $principal);
        }
        $roles = self::SPECS[$name]['roles'] ?? null;
        if ($roles !== null && !self::role_allowed($principal, $roles)) {
            return false;
        }
        $scope = (string) self::SPECS[$name]['scope'];
        if ($scope === '') {
            return true;
        }
        if (!ApiToken::has_scope($principal, $scope)) {
            return false;
        }
        // A write scope on a read tool is not a thing, but the environment's
        // switch is still checked for any scope that changes data.
        return !ApiToken::is_write_scope($scope) || McpServer::writes_enabled();
    }

    /**
     * Run one tool.
     *
     * A write is delegated to McpWrites, which returns two things a read cannot:
     * `applied` (did this call change anything? a preview and an
     * approval-required answer have NOT) and `note` (one server-authored line for
     * the audit trail).
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal from ApiToken::authenticate()
     * @return array{data:mixed, booking_id:?int, applied:bool, note:string}
     * @throws McpToolError on a refusal the assistant should read
     */
    public static function call(string $name, array $args, array $principal): array
    {
        if (!self::known($name)) {
            throw new McpToolError('unknown tool: ' . $name);
        }
        if (McpWrites::known($name)) {
            return McpWrites::call($name, $args, $principal);
        }
        // Role before scope, and the same gate permitted() applies to the
        // catalogue — a tool the list never showed is still refused if asked for.
        $roles = self::SPECS[$name]['roles'] ?? null;
        if ($roles !== null && !self::role_allowed($principal, $roles)) {
            throw new McpToolError(self::role_refusal($principal));
        }
        $scope = self::scope_of($name);
        if ($scope !== '' && !ApiToken::has_scope($principal, $scope)) {
            throw new McpToolError('this token does not carry the ' . $scope . ' scope');
        }
        if (ApiToken::is_write_scope($scope) && !McpServer::writes_enabled()) {
            throw new McpToolError('changing data over MCP is switched off on this install (WPMS_MCP_WRITES)');
        }
        $handler = (string) self::SPECS[$name]['handler'];
        /*
         * Every handler that reaches this point is a READ — McpWrites::call()
         * took the writes above — so it may run against the optional read
         * replica. The swap is scoped to this call and restored in a `finally`:
         * the audit row that records this answer, and any write the assistant
         * makes next, still go to the primary. With no replica configured the
         * read handle is $db and this is a plain call.
         */
        $result = Database::with_read_handle(
            static fn(): array => self::$handler($args, $principal)
        );
        return [
            'data'       => $result['data'],
            'booking_id' => $result['booking_id'] ?? null,
            'applied'    => false,
            'note'       => '',
        ];
    }

    // ==================================================================
    // Handlers
    // ==================================================================

    /**
     * The operator's aggregate dashboard.
     *
     * OpsPulse owns every query and the privacy contract; this handler only
     * shapes it for the protocol. No booking is involved, so `booking_id` is
     * null and the audit row records a platform-wide read.
     *
     * WEEK OVER WEEK is attached here rather than inside OpsPulse::snapshot():
     * the snapshot is the current figures the page and the email both render,
     * while movement is history kept by the Monday brief (BusinessPulse). The
     * tool reads that history; it never writes it, so a conversation cannot
     * move the baseline it reports against. With no earlier snapshot the block
     * says `available: false` and why, so the model cannot turn missing history
     * into "nothing changed".
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_system_status(array $args, array $principal): array
    {
        /*
         * The TOKEN OWNER'S thresholds, not the defaults: the windows are the
         * operator's judgement, and an assistant answering "how is the business
         * doing" must describe the same 45-day horizon the page shows them.
         * Only a staff account can reach this tool (roles: super_admin), so the
         * id is a tblusers id and never a couple's.
         */
        $userId   = $principal['kind'] === ApiToken::KIND_USER ? (int) $principal['id'] : 0;
        $snapshot = OpsPulse::snapshot($userId);

        if (!class_exists('BusinessPulse', false)) {
            require_once __DIR__ . '/BusinessPulse.php';
        }
        $snapshot['movement'] = BusinessPulse::movement($userId, $snapshot);

        return ['data' => $snapshot, 'booking_id' => null];
    }

    /**
     * The rule-based findings, filtered and capped.
     *
     * The severity is validated here (not interpolated into SQL — OpsPulse
     * never takes SQL from a caller) and an unknown value is a readable
     * refusal rather than a silently empty list.
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     * @throws McpToolError
     */
    private static function tool_list_insights(array $args, array $principal): array
    {
        $severity = strtolower(self::str_arg($args, 'severity'));
        if ($severity !== '' && !in_array($severity, OpsPulse::SEVERITIES, true)) {
            throw new McpToolError('severity must be one of: ' . implode(', ', OpsPulse::SEVERITIES));
        }
        $limit = self::limit_arg($args, 'limit', 25, 50);
        // Same account settings as the page: tuned windows, muted findings
        // excluded (and counted). The assistant sees what the operator sees,
        // which is the point of tuning them in one place.
        $userId = $principal['kind'] === ApiToken::KIND_USER ? (int) $principal['id'] : 0;
        return ['data' => OpsPulse::insights($severity, $limit, $userId), 'booking_id' => null];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_whoami(array $args, array $principal): array
    {
        $scopes = [];
        foreach ($principal['scopes'] as $scope) {
            $scopes[$scope] = ApiToken::SCOPES[$scope] ?? '';
        }
        $weddings = self::visible_booking_count($principal);
        return ['data' => [
            'acting_as'      => [
                'name'          => $principal['name'],
                'email'         => $principal['email'],
                'role'          => $principal['role'],
                'account_table' => $principal['kind'] === ApiToken::KIND_USER ? 'staff/vendor account' : 'couple account',
                'account_id'    => $principal['id'],
            ],
            'token'          => [
                'name'       => $principal['token_name'],
                'expires_at' => $principal['expires_at'],
                'narrowed_to_wedding' => $principal['booking_id'],
            ],
            'can_read'       => $scopes,
            'can_change_data' => McpServer::writes_enabled() && self::has_any_write_scope($principal),
            'weddings_visible' => $weddings,
            'note'           => 'A wedding id is only a request: every call is checked against what this account may open.',
        ], 'booking_id' => null];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_list_weddings(array $args, array $principal): array
    {
        $when   = self::enum_arg($args, 'when', ['upcoming', 'past', 'all'], 'upcoming');
        $search = self::str_arg($args, 'search');
        $limit  = self::limit_arg($args, 'limit', 25, self::MAX_LIMIT);
        $me     = $principal['kind'] === ApiToken::KIND_ACCOUNT ? (int) $principal['id'] : 0;

        $where  = [];
        $params = [];
        if ($me > 0) {
            $where[]        = 'b.user_id = :me';
            $params[':me']  = $me;
        }
        /*
         * Per-planner scoping, through the SAME rule the pages use. The
         * principal was bound to $_SESSION before this ran (ApiToken::bind_principal),
         * so a planner's token lists that planner's weddings and the unassigned
         * ones — the assistant behind the token cannot list what its human could
         * not open in the browser.
         */
        $scope = planner_scope_sql('b.organizer_id');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params += $scope['params'];
        }
        if (!empty($principal['booking_id'])) {
            $where[]             = 'b.booking_id = :tokenbid';
            $params[':tokenbid'] = (int) $principal['booking_id'];
        }
        if ($when === 'upcoming') {
            $where[] = 'b.wedding_date >= CURDATE()';
        } elseif ($when === 'past') {
            $where[] = 'b.wedding_date < CURDATE()';
        }
        if ($search !== '') {
            /*
             * One placeholder per use. PDO does not emulate prepares on this
             * connection, so a name used twice in one statement with a single bound
             * value is "Invalid parameter number" at execute() — the bug that made
             * a wedding SEARCH fail while a plain list worked.
             */
            $where[]       = '(b.bride LIKE :q1 OR b.groom LIKE :q2 OR a.user_email LIKE :q3)';
            $needle        = '%' . $search . '%';
            $params[':q1'] = $needle;
            $params[':q2'] = $needle;
            $params[':q3'] = $needle;
        }
        $order = $when === 'past' ? 'b.wedding_date DESC' : 'b.wedding_date ASC';

        $db = Database::read_handle(); // optional read replica; the primary when none is configured
        $sql = 'SELECT b.booking_id, b.bride, b.groom, b.wedding_date, b.user_id,
                       a.user_email AS couple_email, d.status AS couple_status, d.city,
                       DATEDIFF(b.wedding_date, CURDATE()) AS days_to_go,
                       (SELECT COUNT(*) FROM tblguest g WHERE g.booking_id = b.booking_id) AS guests,
                       (SELECT COUNT(*) FROM tblguest g WHERE g.booking_id = b.booking_id AND g.rsvp_status <> \'pending\') AS answered
                  FROM tblweddingbook b
                  LEFT JOIN tblaccounts a ON a.user_id = b.user_id
                  LEFT JOIN tblaccounts_detail d ON d.user_id = b.user_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY ' . $order . ' LIMIT ' . $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $weddings = [];
        foreach ($rows as $row) {
            $weddings[] = [
                'booking_id'   => (int) $row['booking_id'],
                'couple'       => trim((string) $row['bride'] . ' & ' . (string) $row['groom']),
                'wedding_date' => self::iso_when($row['wedding_date'] ?? ''),
                'days_to_go'   => (int) $row['days_to_go'],
                'couple_status' => (string) ($row['couple_status'] ?? ''),
                'city'         => (string) ($row['city'] ?? ''),
                'guests'       => (int) $row['guests'],
                'rsvp_answered' => (int) $row['answered'],
            ];
        }
        return ['data' => [
            'when'     => $when,
            'count'    => count($weddings),
            'totals'   => self::visible_booking_count($principal),
            'weddings' => $weddings,
        ], 'booking_id' => null];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_get_wedding(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;

        $detail  = Account_Details::find_by_user_id((int) $booking->user_id);
        $account = Accounts::find_by_user_id((int) $booking->user_id);
        $category = Category::find_by_id($booking->wedding_type ?? 0);
        $price    = (float) ($category->price ?? 0);

        $tally = Guest::rsvp_tally($id);
        $totals = Liquidation::getTotalsByMethod($id);
        $paid   = (float) ($totals['grand_total'] ?? 0);

        $tasks = Checklist::find_for_booking($id);
        $todo  = [];
        $done  = 0;
        foreach ($tasks as $task) {
            if ((int) $task->is_done === 1) {
                $done++;
                continue;
            }
            $todo[] = $task;
        }
        $weddingDate = (string) $booking->wedding_date;
        $dueOf = static fn(object $task): string => date(
            'Y-m-d',
            strtotime($weddingDate . ' -' . (int) $task->due_offset_days . ' days')
        );
        usort($todo, static fn(object $a, object $b): int => strcmp($dueOf($a), $dueOf($b)));
        $next = [];
        foreach (array_slice($todo, 0, 3) as $task) {
            $due = $dueOf($task);
            $next[] = [
                'title'      => (string) $task->title,
                'phase'      => (string) $task->phase,
                'due_on'     => $due,
                'days_left'  => (int) floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400),
            ];
        }
        $overdue = 0;
        foreach ($todo as $task) {
            if (strtotime($dueOf($task)) < strtotime(date('Y-m-d'))) {
                $overdue++;
            }
        }

        $deals = self::deals_for($id);

        return ['data' => [
            'wedding' => [
            'booking_id'   => $id,
            'couple'       => trim((string) $booking->bride . ' & ' . (string) $booking->groom),
            'wedding_date' => self::iso_when($weddingDate),
                'days_to_go'   => (int) floor((strtotime($weddingDate) - strtotime(date('Y-m-d'))) / 86400),
                'type'         => (string) ($booking->wedding_type ?? ''),
                'couple_status' => is_object($detail) ? (string) ($detail->status ?? '') : '',
                'couple_phone' => is_object($detail) ? (string) ($detail->phone ?? '') : '',
                'couple_city'  => is_object($detail) ? (string) ($detail->city ?? '') : '',
                'couple_email' => is_object($account) ? (string) ($account->user_email ?? '') : '',
                'package'      => is_object($category) ? (string) ($category->name ?? '') : '',
                'package_price' => $price,
            ],
            'guests' => [
                'total'      => Guest::count_guest($id),
                'tally'      => $tally,
                'pending'    => $tally['pending'] ?? 0,
                'out_of_town' => count(Guest::get_upcountry_guests($id)),
                'pledged_harambee_count' => Guest::count_pledged($id),
                'harambee_total' => Guest::total_harambee($id),
            ],
            'tasks' => [
                'total'   => count($tasks),
                'done'    => $done,
                'open'    => count($tasks) - $done,
                'overdue' => $overdue,
                'next'    => $next,
            ],
            'budget' => [
                'package_price' => $price,
                'paid_total'    => $paid,
                'paid_mpesa'    => (float) ($totals['mpesa_total'] ?? 0),
                'paid_cash'     => (float) ($totals['cash_total'] ?? 0),
                'paid_credit'   => (float) ($totals['credit_total'] ?? 0),
                'balance_due'   => max(0.0, $price - $paid),
                'installment_plan' => self::plan_summary($id),
            ],
            'vendors' => [
                'deals'   => count($deals),
                'booked'  => count(array_filter($deals, static fn(array $d): bool => $d['status'] === 'confirmed' || $d['status'] === 'completed')),
                'pending' => count(array_filter($deals, static fn(array $d): bool => $d['status'] === 'pending')),
                'committed_amount' => array_sum(array_column($deals, 'amount')),
            ],
        ], 'booking_id' => $id];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_list_guests(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;
        $limit   = self::limit_arg($args, 'limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset  = self::int_arg($args, 'offset', 0, 0, 100000);

        $filters = [
            'rsvp_status' => self::enum_arg($args, 'rsvp_status', ['pending', 'yes', 'no', 'maybe'], ''),
            'priority'    => self::enum_arg($args, 'priority', ['A', 'B', 'C', 'D', 'E'], ''),
            'out_of_town' => self::bool_arg($args, 'out_of_town'),
            'transport'   => self::bool_arg($args, 'transport'),
            'dietary'     => self::bool_arg($args, 'dietary'),
            'search'      => self::str_arg($args, 'search'),
        ];

        $rows  = Guest::find_filtered($id, $filters, $limit, $offset);
        $total = Guest::count_filtered($id, $filters);

        $guests = [];
        foreach ($rows as $row) {
            $guests[] = [
                'guest_id'    => (int) $row['id'],
                'name'        => (string) $row['fullname'],
                'guest_name'  => (string) ($row['guestname'] ?? ''),
                'phone'       => (string) ($row['phone'] ?? ''),
                'city'        => (string) ($row['city'] ?? ''),
                'relationship' => (string) ($row['relationship'] ?? ''),
                'priority'    => (string) ($row['priority'] ?? ''),
                'out_of_town' => ($row['out_of_town'] ?? 'n') === 'y',
                'needs_transport' => (int) ($row['transport_needed'] ?? 0) === 1,
                'dietary'     => (string) ($row['dietary_requirements'] ?? ''),
                'harambee_pledge' => (float) ($row['harambee_contribution'] ?? 0),
                'rsvp'        => (string) ($row['rsvp_status'] ?? 'pending'),
                'rsvp_at'     => $row['rsvp_at'] ?? null,
                'rsvp_note'   => (string) ($row['rsvp_note'] ?? ''),
                'invited'     => (int) ($row['invited'] ?? 0) === 1,
                'invited_at'  => $row['rsvp_invited_at'] ?? null,
                'reminders_sent' => (int) ($row['rsvp_reminders'] ?? 0),
            ];
        }
        return ['data' => [
            'booking_id' => $id,
            'filtered_total' => $total,
            'returned'   => count($guests),
            'offset'     => $offset,
            'limit'      => $limit,
            'all_guests' => Guest::count_guest($id),
            'guests'     => $guests,
        ], 'booking_id' => $id];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_rsvp_status(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;
        $limit   = self::limit_arg($args, 'pending_limit', 25, self::MAX_LIMIT);

        $tally   = Guest::rsvp_tally($id);
        $total   = Guest::count_guest($id);
        $pending = Guest::find_filtered($id, ['rsvp_status' => 'pending'], $limit, 0);
        $neverInvited = Guest::count_filtered($id, []) - self::invited_count($id);

        $waiting = [];
        foreach ($pending as $row) {
            $waiting[] = [
                'guest_id' => (int) $row['id'],
                'name'     => (string) $row['fullname'],
                'phone'    => (string) ($row['phone'] ?? ''),
                'priority' => (string) ($row['priority'] ?? ''),
                'invited_at'     => $row['rsvp_invited_at'] ?? null,
                'reminders_sent' => (int) ($row['rsvp_reminders'] ?? 0),
            ];
        }
        return ['data' => [
            'booking_id'   => $id,
            'wedding_date' => self::iso_when($booking->wedding_date),
            'days_to_go'   => (int) floor((strtotime((string) $booking->wedding_date) - strtotime(date('Y-m-d'))) / 86400),
            'total_guests' => $total,
            'answered'     => ($tally['yes'] ?? 0) + ($tally['no'] ?? 0) + ($tally['maybe'] ?? 0),
            'tally'        => $tally,
            'not_invited_yet' => max(0, $neverInvited),
            'pending_shown'   => count($waiting),
            'pending_total'   => $tally['pending'] ?? 0,
            'pending'         => $waiting,
        ], 'booking_id' => $id];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_list_tasks(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;
        $phase   = self::str_arg($args, 'phase');
        $doneArg = array_key_exists('done', $args) ? self::bool_arg($args, 'done') : null;
        $overdueOnly = self::bool_arg($args, 'overdue_only');

        $weddingDate = (string) $booking->wedding_date;
        $items = [];
        $counts = ['total' => 0, 'done' => 0, 'overdue' => 0];
        foreach (Checklist::find_for_booking($id) as $task) {
            $counts['total']++;
            $isDone = (int) $task->is_done === 1;
            if ($isDone) {
                $counts['done']++;
            }
            $due = date('Y-m-d', strtotime($weddingDate . ' -' . (int) $task->due_offset_days . ' days'));
            $overdue = !$isDone && strtotime($due) < strtotime(date('Y-m-d'));
            if ($overdue) {
                $counts['overdue']++;
            }
            if ($phase !== '' && stripos((string) $task->phase, $phase) === false) {
                continue;
            }
            if ($doneArg !== null && $isDone !== $doneArg) {
                continue;
            }
            if ($overdueOnly && !$overdue) {
                continue;
            }
            $items[] = [
                'task_id'    => (int) $task->id,
                'phase'      => (string) $task->phase,
                'title'      => (string) $task->title,
                'done'       => $isDone,
                'done_at'    => $task->done_at ?? null,
                'due_on'     => $due,
                'days_left'  => (int) floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400),
                'overdue'    => $overdue,
            ];
        }
        return ['data' => [
            'booking_id'   => $id,
            'wedding_date' => self::iso_when($weddingDate),
            'counts'       => $counts,
            'returned'     => count($items),
            'tasks'        => $items,
        ], 'booking_id' => $id];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_budget_position(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;
        $recent  = self::limit_arg($args, 'recent_limit', 10, 50);

        $category = Category::find_by_id($booking->wedding_type ?? 0);
        $price    = (float) ($category->price ?? 0);
        $totals   = Liquidation::getTotalsByMethod($id);
        $paid     = (float) ($totals['grand_total'] ?? 0);

        $payments = [];
        foreach (array_slice(Liquidation::find_by_liquadate_all($id), 0, $recent) as $row) {
            $payments[] = [
                'date'       => self::iso_when($row->date_issue ?? ''),
                'amount'     => (float) ($row->payment ?? 0),
                'cash'       => (float) ($row->cash ?? 0),
                'credit'     => (float) ($row->credit ?? 0),
                'mpesa_code' => (string) ($row->mpesa_code ?? ''),
            ];
        }
        return ['data' => [
            'booking_id'    => $id,
            'package'       => is_object($category) ? (string) ($category->name ?? '') : '',
            'package_price' => $price,
            'paid_total'    => $paid,
            'paid_mpesa'    => (float) ($totals['mpesa_total'] ?? 0),
            'paid_cash'     => (float) ($totals['cash_total'] ?? 0),
            'paid_credit'   => (float) ($totals['credit_total'] ?? 0),
            'balance_due'   => max(0.0, $price - $paid),
            'fully_paid'    => $price > 0 && $paid >= $price,
            'installment_plan' => self::plan_summary($id),
            'recent_payments'  => $payments,
        ], 'booking_id' => $id];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_list_vendor_deals(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;
        return ['data' => [
            'booking_id' => $id,
            'count'      => count(self::deals_for($id)),
            'deals'      => self::deals_for($id),
        ], 'booking_id' => $id];
    }

    /** @return array{data:array<string,mixed>, booking_id:?int} */
    private static function tool_search_vendors(array $args, array $principal): array
    {
        $category = self::str_arg($args, 'category');
        $county   = self::str_arg($args, 'county');
        $maxPrice = self::float_arg($args, 'max_price', 0.0);
        $limit    = self::limit_arg($args, 'limit', 10, 30);

        $where  = ['v.is_approved = 1'];
        $params = [];
        if ($category !== '') {
            $where[]        = 'v.category LIKE :cat';
            $params[':cat'] = '%' . $category . '%';
        }
        if ($county !== '') {
            // Two placeholders, one value: see the note in tool_list_weddings().
            $where[]           = '(v.county LIKE :county OR v.location LIKE :loc)';
            $whereValue        = '%' . $county . '%';
            $params[':county'] = $whereValue;
            $params[':loc']    = $whereValue;
        }
        if ($maxPrice > 0) {
            $where[]        = 'p.price IS NOT NULL AND p.price <= :maxp';
            $params[':maxp'] = $maxPrice;
        }

        $db = Database::read_handle(); // optional read replica; the primary when none is configured
        $sql = 'SELECT v.id, v.business_name, v.category, v.county, v.location, v.is_premium, v.rating,
                       v.total_bookings, p.id AS package_id, p.title AS package_title, p.price, p.capacity, p.duration_hours
                  FROM tblvendors v
                  JOIN tblvendor_packages p ON p.vendor_id = v.id AND p.is_active = 1'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY v.is_premium DESC, v.rating DESC, v.business_name ASC, p.price ASC
                 LIMIT ' . min(self::MAX_LIMIT, $limit * 12);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $vendors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (!isset($vendors[$id])) {
                if (count($vendors) >= $limit) {
                    continue; // keep the vendor count bounded; packages follow their vendor
                }
                $vendors[$id] = [
                    'vendor_id'     => $id,
                    'business_name' => (string) $row['business_name'],
                    'category'      => (string) $row['category'],
                    'county'        => (string) ($row['county'] ?? ''),
                    'location'      => (string) ($row['location'] ?? ''),
                    'premium'       => (int) $row['is_premium'] === 1,
                    'rating'        => (float) $row['rating'],
                    'deals_done'    => (int) $row['total_bookings'],
                    'packages'      => [],
                ];
            }
            $vendors[$id]['packages'][] = [
                'package_id' => (int) $row['package_id'],
                'title'      => (string) $row['package_title'],
                'price'      => (float) $row['price'],
                'capacity'   => $row['capacity'] === null ? null : (int) $row['capacity'],
                'duration_hours' => $row['duration_hours'] === null ? null : (int) $row['duration_hours'],
            ];
        }
        $list = array_values($vendors);
        return ['data' => [
            'filters' => ['category' => $category, 'county' => $county, 'max_price' => $maxPrice > 0 ? $maxPrice : null],
            'count'   => count($list),
            'vendors' => $list,
            'note'    => 'Prices are in Kenyan shillings. Only approved vendors with at least one active package are listed.',
        ], 'booking_id' => null];
    }

    /**
     * The day itself, in order.
     *
     * Built from BookingEvent::plan_for() — the venue sheet's own source — so the
     * running order an assistant reads and the one a planner prints are the same
     * rows by construction, and a function nobody has planned for still appears
     * (planned=false, headcount 0) instead of vanishing, because "what is still
     * unplanned" is one of the most useful answers this tool has.
     *
     * The vendors are attached per function from PackageCapacity::limits(), the
     * one place that knows which deals cover which function, so "who is feeding
     * the reception" is answered from the same rows the capacity check judges.
     *
     * DELIBERATELY NOT `calendar`: sweep D classes that table as legacy — no page
     * reads it, its only reference is the seed-date tooling — so a timeline built
     * on it would be a second, silent answer to a question `events` already
     * answers. See docs/MCP_PLAN.md §Phase 2.
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_list_timeline(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;

        $limits   = PackageCapacity::limits($id);
        $byFn     = [];
        $orphans  = []; // deals pinned to a function that is no longer on the calendar
        foreach ($limits as $deal) {
            $fid   = (int) $deal['features_id'];
            $key   = $fid > 0 ? (string) $fid : 'day';
            $entry = [
                'vendor'    => $deal['business_name'],
                'category'  => $deal['category'],
                'package'   => $deal['package_title'],
                'capacity'  => $deal['capacity'],
                // A deal booked for the day covers EVERY function, and saying so
                // is what stops a reader wondering whether the caterer is coming.
                'covers'    => $deal['scope'] === 'function'
                    ? $deal['function_title']
                    : ($deal['scope'] === 'wedding' ? 'the whole day' : 'a function no longer on the calendar'),
            ];
            if ($deal['scope'] === 'missing') {
                // It matches no function, so merging it nowhere would hide a
                // booked, paid-for vendor. It is reported instead of dropped.
                $orphans[] = $entry + ['function_id' => $fid];
                continue;
            }
            $byFn[$key][] = $entry;
        }

        $functions   = [];
        $unplanned   = 0;
        $plannedGuests = 0;
        foreach (BookingEvent::plan_for($id) as $fn) {
            $total = (int) $fn->family + (int) $fn->individual;
            if (!$fn->planned) {
                $unplanned++;
            }
            $plannedGuests += $total;
            $functions[] = [
                'features_id'   => (int) $fn->features_id,
                'function'      => (string) $fn->title,
                'venue'         => (string) $fn->location,
                'starts'        => self::iso_when($fn->start),
                'headcount'     => [
                    'family'     => (int) $fn->family,
                    'individual' => (int) $fn->individual,
                    'total'      => $total,
                ],
                'planned'       => (bool) $fn->planned,
                // A deal booked for the day covers EVERY function, so it appears
                // under each one — that is what `covers: the whole day` says, and
                // collapsing it into a separate list is how a reader ends up
                // wondering whether the caterer is coming.
                'vendors'       => array_merge($byFn[(string) $fn->features_id] ?? [], $byFn['day'] ?? []),
            ];
        }

        $weddingDate = (string) $booking->wedding_date;
        return ['data' => [
            'booking_id'     => $id,
            'couple'         => trim((string) $booking->bride . ' & ' . (string) $booking->groom),
            'wedding_date'   => self::iso_when($weddingDate),
            'days_to_go'     => (int) floor((strtotime($weddingDate) - strtotime(date('Y-m-d'))) / 86400),
            'functions'      => $functions,
            'unplanned_functions' => $unplanned,
            'planned_guests_total' => $plannedGuests,
            'deals_on_a_function_that_no_longer_exists' => $orphans,
            'note'           => trim(($unplanned > 0
                ? $unplanned . ' function(s) have no headcount planned yet, so nothing can say whether the packages booked '
                    . 'for them are big enough.'
                : 'Every function has a headcount plan.')
                . ($orphans !== []
                    ? ' ' . count($orphans) . ' booked deal(s) are pinned to a function that is no longer on the calendar, '
                        . 'so they are listed separately and cover nothing.'
                    : '')),
        ], 'booking_id' => $id];
    }

    /**
     * Does the crowd still fit what was sold?
     *
     * Three views of one question, all from PackageCapacity so a screen and an
     * assistant cannot disagree: the plan (per function), the limits (the deals
     * that state one), and the judgement — `breaches()` for what is already over, 
     * `headroom()` for what is closing on a limit. The way-out sentence is the
     * platform's own (`way_out()`), which names the SAME vendor's next package up
     * that covers the crowd: relaying advice nobody asked a language model to
     * invent is the point.
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_headcount_capacity(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;

        $share = PackageCapacity::sanitise_share($args['share'] ?? PackageCapacity::default_share());
        $plan  = PackageCapacity::plan_totals($id);
        $over  = PackageCapacity::breaches($id, $plan);
        $close = PackageCapacity::headroom($id, $share);

        $function = static fn(array $row): array => [
            'function'   => (string) $row['title'],
            'planned'    => (int) $row['total'],
            'over_by'    => (int) $row['total'] - (int) ($row['deals'][0]['capacity'] ?? 0),
            'package'    => (string) ($row['deals'][0]['package_title'] ?? ''),
            'vendor'     => (string) ($row['deals'][0]['business_name'] ?? ''),
            'capacity'   => (int) ($row['deals'][0]['capacity'] ?? 0),
        ];

        return ['data' => [
            'booking_id'      => $id,
            'share_watched'   => $share,
            'plan'            => $plan,
            'limits'          => array_map(static fn(array $d): array => [
                'vendor'    => $d['business_name'],
                'category'  => $d['category'],
                'package'   => $d['package_title'],
                'capacity'  => $d['capacity'],
                'covers'    => $d['scope'] === 'function' ? $d['function_title'] : ($d['scope'] === 'missing' ? 'a function no longer on the calendar' : 'the whole day'),
            ], PackageCapacity::limits($id)),
            'over_capacity'   => array_map($function, $over),
            'closing_on_limit' => array_map(static fn(array $p): array => [
                'function' => (string) $p['function_title'],
                'planned'  => (int) $p['total'],
                'capacity' => (int) $p['capacity'],
                'headroom' => (int) $p['margin'],
                'vendor'   => (string) $p['business_name'],
                'package'  => (string) $p['package_title'],
            ], $close),
            'advice'          => $over !== [] ? PackageCapacity::way_out($over) : '',
            'verdict'         => $over !== []
                ? count($over) . ' function(s) are planned past the capacity of a package booked on them.'
                : ($close !== []
                    ? 'Nothing is over a limit yet, but ' . count($close) . ' position(s) are close to one.'
                    : 'Every function fits the capacity of the packages booked on it.'),
        ], 'booking_id' => $id];
    }

    /**
     * The alerts on the TOKEN'S OWN account.
     *
     * The owner is resolved from the bound session, never from an argument: a
     * tool that took an account id would be a tool for reading somebody else's
     * notifications, and `notification_recipient_for_session()` is the one place
     * that answers which account table this session's id belongs to (staff live
     * in tblusers, couples in tblaccounts — two id spaces, v2.13).
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_list_notifications(array $args, array $principal): array
    {
        [$recipientType, $accountId] = notification_recipient_for_session();
        if ($accountId <= 0) {
            throw new McpToolError('this token is not bound to an account, so it has no alerts');
        }

        $unreadOnly = self::bool_arg($args, 'unread_only');
        $search     = self::str_arg($args, 'search');
        $limit      = self::limit_arg($args, 'limit', 20, self::MAX_LIMIT);
        $offset     = max(0, self::int_arg($args, 'offset', 0, 0, PHP_INT_MAX));

        $rows = Notification::page($recipientType, $accountId, $offset, $limit, $unreadOnly, $search);
        $base = rtrim(site_base_url(), '/');
        $out  = [];
        foreach ($rows as $row) {
            // Links are stored relative to admin/ (tblnotifications.link); an
            // assistant that quotes a link has to quote one a person can click,
            // so it is made absolute here and left alone if it already is.
            $link = trim((string) ($row->link ?? ''));
            if ($link !== '' && !preg_match('~^https?://~i', $link)) {
                $link = $base . '/admin/' . ltrim($link, '/');
            }
            $out[] = [
                'id'         => (int) $row->id,
                'when'       => self::iso_when($row->created_at ?? ''),
                'message'    => (string) ($row->message ?? ''),
                'booking_id' => isset($row->booking_id) && $row->booking_id !== null ? (int) $row->booking_id : null,
                'read'       => (int) ($row->is_read ?? 0) === 1,
                'link'       => $link,
            ];
        }

        return ['data' => [
            'account'      => $recipientType === Notification::FOR_ACCOUNT ? 'couple account' : 'staff/vendor account',
            'unread'       => Notification::unread_count($recipientType, $accountId),
            'matched'      => Notification::count_for($recipientType, $accountId, $unreadOnly, $search),
            'returned'     => count($out),
            'offset'       => $offset,
            'notifications' => $out,
            'note'         => 'These are this account\'s own alerts only — the same bell feed the person sees in the planner.',
        ], 'booking_id' => null];
    }

    /**
     * The couple's public wedding page.
     *
     * Read straight from tblwedding_websites by booking — this is the account's own
     * page, and the booking check above is the whole authorization (there is no
     * per-row rule on this table). The public URL is built from site_base_url() so
     * the assistant quotes the address that actually resolves.
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_wedding_website(array $args, array $principal): array
    {
        $booking = self::require_booking($args, $principal);
        $id      = (int) $booking->booking_id;

        /*
         * NOT cover_image. Sweep D is right about it: no statement anywhere on this
         * platform writes that column (site.php only falls back to a default image),
         * so reporting "your page has no cover image" would be telling a couple about
         * a state they have no screen to change — a promise this platform cannot
         * keep. The column is exposed the day a screen can set it.
         */
        $db = Database::read_handle(); // optional read replica; the primary when none is configured
        $stmt = $db->prepare(
            'SELECT slug, title, story, venue, venue_address, wedding_date, is_published, views, created_at
               FROM tblwedding_websites WHERE booking_id = :b ORDER BY id LIMIT 1'
        );
        $stmt->execute([':b' => $id]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($site === false || $site === null) {
            return ['data' => [
                'booking_id' => $id,
                'exists'     => false,
                'note'       => 'No wedding website has been built for this booking yet — the couple can create one on their '
                    . '"Our Wedding Page" screen.',
            ], 'booking_id' => $id];
        }

        $base = rtrim(site_base_url(), '/');
        return ['data' => [
            'booking_id' => $id,
            'exists'     => true,
            'title'      => (string) ($site['title'] ?? ''),
            'published'  => (int) ($site['is_published'] ?? 0) === 1,
            'url'        => $base . '/site.php?slug=' . rawurlencode((string) $site['slug']),
            'slug'       => (string) $site['slug'],
            'views'      => (int) ($site['views'] ?? 0),
            'venue'      => (string) ($site['venue'] ?? ''),
            'venue_address' => (string) ($site['venue_address'] ?? ''),
            'wedding_date'  => self::iso_when($site['wedding_date'] ?? ''),
            'has_story'     => trim((string) ($site['story'] ?? '')) !== '',
            'created_at'    => self::iso_when($site['created_at'] ?? ''),
            'note'          => (int) ($site['is_published'] ?? 0) === 1
                ? 'The page is live: guests with the link can see it and RSVP through their own invitation links.'
                : 'The page exists but is NOT published — visitors are sent home, so it has had no new guests.',
        ], 'booking_id' => $id];
    }

    /**
     * The plan behind the ACCOUNT THIS TOKEN ACTS AS — never another account's.
     *
     * The id comes from the principal, not from an argument, so there is no id to
     * tamper with: an assistant cannot ask about somebody else's subscription
     * because there is no parameter that would let it. Every figure is
     * Subscription::state()'s own verdict (the same call login_redirect_target()
     * uses to decide where a planner lands), so the assistant and the paywall
     * cannot disagree about whether this planner is locked out.
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_my_subscription(array $args, array $principal): array
    {
        $userId = (int) $principal['id'];
        $state  = Subscription::state($userId);
        $sub    = $state['sub'] ?? null;
        $active = Subscription::is_active($userId);

        return ['data' => [
            'plan'            => is_object($sub) ? (string) ($sub->plan ?? '') : null,
            'status'          => $active ? 'active' : 'inactive',
            'state'           => (string) ($state['state'] ?? 'unknown'),
            'expires'         => is_object($sub) ? (string) ($sub->expiry_date ?? '') : null,
            'days_left'       => (int) ($state['days_left'] ?? 0),
            'grace_days_left' => (int) ($state['grace_days_left'] ?? 0),
            'trial'           => (bool) ($state['trial'] ?? false),
            'note'            => 'Read-only. A plan is bought, renewed or changed by the planner on admin/subscription.php; '
                . 'no MCP tool can start a payment, and none can change a plan.',
        ], 'booking_id' => null];
    }

    /**
     * The plan catalogue: slug, name, monthly price and the wedding limit.
     *
     * Read-only for the same reason the plans page is operator-only — a price is
     * the platform's revenue, not a planner's setting — and because the MCP layer
     * must never be a second way to edit one (Subscription::create_plan(),
     * update_plan() and set_plan_active() are not exposed as tools at all).
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_list_plans(array $args, array $principal): array
    {
        $plans = [];
        foreach (Subscription::get_all_plans() as $plan) {
            $plans[] = [
                'slug'  => (string) ($plan->plan_slug ?? ''),
                'name'  => (string) ($plan->name ?? ''),
                'price' => (float) ($plan->price ?? 0),
                'limit' => $plan->wedding_limit ?? $plan->limit ?? null,
            ];
        }
        return ['data' => [
            'currency' => 'KSh',
            'count'    => count($plans),
            'plans'    => $plans,
            'note'     => 'Read-only. Prices change on admin/subscription_plans.php, which is the operator\'s page — '
                . 'on purpose there is no MCP tool that can edit a plan.',
        ], 'booking_id' => null];
    }

    /**
     * What a bulk SMS would cost, before anyone approves one.
     *
     * Counts, never phone numbers: the audience is resolved through
     * SMS::audience_phones() (the same method the send uses, so the count the
     * planner approves cannot differ from the count the send acts on), and the
     * price comes from SMS::calculate_cost() at the platform's own rate. Credits
     * are the ASKING account's (SMS::get_credits()), never another planner's.
     *
     * @return array{data:array<string,mixed>, booking_id:?int}
     */
    private static function tool_sms_position(array $args, array $principal): array
    {
        $audience = self::enum_arg($args, 'audience', McpWrites::AUDIENCES, 'all');
        $message  = mb_substr((string) ($args['message'] ?? ''), 0, McpWrites::MAX_SMS_CHARS);
        $userId   = (int) $principal['id'];

        $bookingId = (int) self::int_arg($args, 'booking_id', 0, 0, PHP_INT_MAX);
        $recipients = 0;
        $perMessage = 0.0;
        $total      = 0.0;
        $booking    = null;
        if ($bookingId > 0) {
            $booking    = self::require_booking($args, $principal);
            $bookingId  = (int) $booking->booking_id;
            $recipients = count(SMS::audience_phones($bookingId, $audience));
            if ($message !== '') {
                $perMessage = SMS::calculate_cost($message);
                $total      = $perMessage * $recipients;
            }
        }

        $credits = (int) SMS::get_credits($userId);
        return ['data' => [
            'currency'        => 'KSh',
            'rate'            => 'KSh 1 per 160 characters',
            'credits'         => $credits,
            'booking_id'      => $bookingId > 0 ? $bookingId : null,
            'audience'        => $audience,
            'recipients'      => $recipients,
            'cost_per_message' => $message === '' ? null : $perMessage,
            'estimated_cost'  => $message === '' ? null : $total,
            'affordable'      => $message === '' ? null : $total <= $credits,
            'note'            => 'Counting this change nothing and sends nothing. A send needs write:sms on the token, '
                . 'WPMS_MCP_WRITES=1 on this install, and a staff approval on admin/mcp_approvals.php.',
        ], 'booking_id' => $bookingId > 0 ? $bookingId : null];
    }

    // ==================================================================
    // Shared reads
    // ==================================================================

    /**
     * The vendor deals on one booking, in the shape the couple's own vendor page
     * uses (client_vendors.php) so the assistant and the screen cannot disagree.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function deals_for(int $booking_id): array
    {
        $db = Database::read_handle(); // optional read replica; the primary when none is configured
        $stmt = $db->prepare(
            'SELECT vb.id, vb.status, vb.event_date, vb.amount, vb.notes, vb.created_at,
                    v.business_name, v.category, p.title AS package_title
               FROM tblvendor_bookings vb
               JOIN tblvendors v ON v.id = vb.vendor_id
               LEFT JOIN tblvendor_packages p ON p.id = vb.package_id
              WHERE vb.booking_id = :b
           ORDER BY vb.status = \'pending\' DESC, vb.created_at DESC, vb.id DESC'
        );
        $stmt->execute([':b' => $booking_id]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'deal_id'       => (int) $row['id'],
                'vendor'        => (string) $row['business_name'],
                'category'      => (string) $row['category'],
                'package'       => (string) ($row['package_title'] ?? ''),
                'status'        => (string) $row['status'],
                'event_date'    => self::iso_when($row['event_date'] ?? ''),
                'amount'        => (float) $row['amount'],
                'notes'         => (string) ($row['notes'] ?? ''),
            ];
        }
        return $out;
    }

    /** The installment plan on a booking, or null when there is none. */
    private static function plan_summary(int $booking_id): ?array
    {
        $plan = InstallmentPlan::for_booking($booking_id);
        if ($plan === null) {
            return null;
        }
        return [
            'months'         => (int) ($plan->months ?? 0),
            'monthly_amount' => (float) ($plan->monthly_amount ?? 0),
            'starting_paid'  => (float) ($plan->starting_paid ?? 0),
            'started_at'     => self::iso_when($plan->started_at ?? ''),
            'active'         => (int) ($plan->is_active ?? 0) === 1,
        ];
    }

    /** How many guests of a booking already hold an invite (a minted token). */
    private static function invited_count(int $booking_id): int
    {
        $db = Database::read_handle(); // optional read replica; the primary when none is configured
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM tblguest WHERE booking_id = :b AND rsvp_token IS NOT NULL AND rsvp_token <> ''"
        );
        $stmt->execute([':b' => $booking_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * How many weddings this principal can see at all — the number `whoami` and
     * `list_weddings` report so the assistant knows whether it is looking at a
     * whole platform or one couple's wedding.
     *
     * @return array{upcoming:int, past:int, total:int}
     */
    private static function visible_booking_count(array $principal): array
    {
        $where  = [];
        $params = [];
        if ($principal['kind'] === ApiToken::KIND_ACCOUNT) {
            $where[]       = 'user_id = :me';
            $params[':me'] = (int) $principal['id'];
        }
        if (!empty($principal['booking_id'])) {
            $where[]             = 'booking_id = :tokenbid';
            $params[':tokenbid'] = (int) $principal['booking_id'];
        }
        // The same per-planner rule as the list, so the totals a token is shown
        // and the rows it can actually fetch cannot disagree.
        $scope = planner_scope_sql('organizer_id');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params += $scope['params'];
        }
        $clause = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $db = Database::read_handle(); // optional read replica; the primary when none is configured
        $stmt = $db->prepare(
            'SELECT
                SUM(CASE WHEN wedding_date >= CURDATE() THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN wedding_date < CURDATE() THEN 1 ELSE 0 END) AS past,
                COUNT(*) AS total
               FROM tblweddingbook' . $clause
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'past'     => (int) ($row['past'] ?? 0),
            'total'    => (int) ($row['total'] ?? 0),
        ];
    }

    // ==================================================================
    // Arguments — typed, clamped, and never trusted for identity
    // ==================================================================

    /**
     * Resolve the requested wedding and prove this account may open it.
     *
     * The order matters: the token's own narrowing first, then the row rule every
     * page uses. Nothing here consults anything the caller sent as evidence of
     * who it is — `booking_id` is a request, `$_SESSION` (bound from the token) is
     * the authority.
     *
     * PUBLIC because McpWrites resolves its bookings the same way: one
     * implementation of "may this account open this wedding" is the whole point,
     * and a second copy in the write layer is exactly how the two doors start
     * disagreeing.
     *
     * @throws McpToolError
     */
    public static function require_booking(array $args, array $principal): object
    {
        $id = self::int_arg($args, 'booking_id', 0, 0, PHP_INT_MAX);
        if ($id <= 0) {
            throw new McpToolError('booking_id is required — call list_weddings to see which ones this token may use');
        }
        if (!empty($principal['booking_id']) && (int) $principal['booking_id'] !== $id) {
            throw new McpToolError('this token is narrowed to wedding #' . (int) $principal['booking_id']
                . ' and cannot be used for wedding #' . $id);
        }
        $booking = Booking::find_by_booking_id($id);
        if (!booking_visible_to_session($booking)) {
            throw new McpToolError('this account cannot open wedding #' . $id
                . ' — a booking id is not permission');
        }
        return $booking;
    }

    /**
     * A date or timestamp from the database, in the shape the handshake promises
     * the model: `YYYY-MM-DD`, or `YYYY-MM-DD HH:MM` when the time of day is
     * meaningful.
     *
     * WHY THIS EXISTS: PDO stringifies a `DATETIME(6)` column as
     * `'2026-11-19 10:00:00.000000'`. That is six digits of noise for a model to
     * read out to a planner, and it contradicts the one line of the handshake
     * that says dates are `YYYY-MM-DD`. A value whose time is exactly midnight is
     * reported as a date, because at this scale that is a date-only column (the
     * wedding date, an event date) rather than a moment somebody chose.
     */
    private static function iso_when(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/', $raw, $m)) {
            return $raw; // not a date we recognise — hand back what the DB gave
        }
        $time = $m[2] ?? '';
        return ($time === '' || $time === '00:00') ? $m[1] : $m[1] . ' ' . $time;
    }

    public static function str_arg(array $args, string $key): string
    {
        $value = $args[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A string argument that must be one of a known set ('' is allowed when it is
     * in the list), so an enum column is never handed a value it cannot store.
     *
     * @param string[] $allowed
     * @throws McpToolError
     */
    public static function enum_arg(array $args, string $key, array $allowed, string $default): string
    {
        $value = strtolower(self::str_arg($args, $key));
        if ($value === '') {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw new McpToolError($key . ' must be one of: ' . implode(', ', $allowed));
        }
        return $value;
    }

    /**
     * @throws McpToolError
     */
    public static function int_arg(array $args, string $key, int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            return $default;
        }
        if (!is_numeric($args[$key])) {
            throw new McpToolError($key . ' must be a number');
        }
        return max($min, min($max, (int) $args[$key]));
    }

    /**
     * @throws McpToolError
     */
    public static function float_arg(array $args, string $key, float $default): float
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            return $default;
        }
        if (!is_numeric($args[$key])) {
            throw new McpToolError($key . ' must be a number');
        }
        return max(0.0, (float) $args[$key]);
    }

    public static function bool_arg(array $args, string $key): bool
    {
        if (!array_key_exists($key, $args)) {
            return false;
        }
        $value = $args[$key];
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** A page size, clamped to the tool's ceiling. */
    private static function limit_arg(array $args, string $key, int $default, int $max): int
    {
        return self::int_arg($args, $key, $default, 1, $max);
    }

    /** Does this token carry any scope that changes data? */
    private static function has_any_write_scope(array $principal): bool
    {
        foreach (ApiToken::WRITE_SCOPES as $scope) {
            if (ApiToken::has_scope($principal, $scope)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * A refusal the assistant should read: an argument the platform will not accept,
 * a wedding this account may not open, a scope this token does not carry.
 *
 * This is deliberately NOT a protocol error. MCP distinguishes a broken request
 * (a malformed tools/call — JSON-RPC error) from a tool that ran and could not do
 * what was asked (`isError: true` with an explanation). Every one of these is the
 * second kind: the model gets the reason in the same shape as any other result,
 * can tell the user why, and can try something else — which is exactly what a
 * booking id it guessed at should produce.
 */
class McpToolError extends RuntimeException
{
}
