<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.16 — reusable instructions, written by the people who know (McpPrompts)
 *
 * A prompt is the third thing MCP offers: not data (a tool) and not a document (a
 * resource), but a piece of EXPERTISE the server hands over ready-made — the
 * sections a good weekly brief has, the questions a collections call asks, the
 * things a planner must never say to a bride. A client shows them as slash
 * commands: `/weekly_wedding_brief 200`.
 *
 * WHY THIS IS WORTH HAVING. Everything else on this platform tells a model WHAT
 * IT CAN SEE. Nothing tells it what to DO with it, so every user retypes the
 * same paragraph of context ("you are helping a Kenyan wedding planner, money is
 * KSh, don't make numbers up") and gets a different answer each time. A prompt is
 * that paragraph, kept in the repository, reviewable, and identical for every
 * client and every user.
 *
 * IT GATHERS ITS OWN CONTEXT, AND THE REASON IS COST. A prompt could just say
 * "call rsvp_status, then list_tasks…" and let the model spend five round-trips
 * discovering what it needs. Instead the prompts below run the same READ
 * tools the catalogue offers — through McpTools::call(), so the scope ceiling,
 * the `booking_visible_to_session()` row rule and the model code are the ones
 * already tested — and hand the results over as JSON in the message. One
 * request, one turn, real numbers.
 *
 * Two rules keep that honest:
 *
 *   1. A prompt only calls tools the TOKEN may call. If the token lacks a scope,
 *      the prompt is not advertised at all (definitions() filters it), so a
 *      client can never pick a command that exists only to refuse.
 *   2. A refusal from those reads is REPORTED, never worked around. Asking for a
 *      wedding this account cannot open fails the request with the platform's own
 *      sentence (the same one a tool call gives), because silently emitting a
 *      brief built from nothing is worse than saying no.
 *
 * Prompts change no data, carry no scope of their own beyond the read scope of
 * the tools they use, and are audited by name in tblapi_audit like any other
 * request.
 */
final class McpPrompts
{
    /**
     * The catalogue.
     *
     * `scope` is the read scope the prompt needs (and the one its context tools
     * take). `arguments` is the protocol's own list; `build` names the private
     * method that assembles the message.
     *
     * @var array<string, array{title:string, scope:string, description:string,
     *                          arguments:array<int, array{name:string, description:string, required:bool}>,
     *                          build:string}>
     */
    private const SPECS = [
        'weekly_wedding_brief' => [
            'title'       => 'Weekly wedding brief',
            'scope'       => 'read:weddings',
            'description' => 'The Monday-morning brief for one wedding: where it stands, what is due this week, who has '
                . 'not answered, what is owed, and the three things to do next. Pass a booking_id, or run it without one '
                . 'to see which weddings are coming up.',
            'arguments'   => [
                ['name' => 'booking_id', 'description' => 'From list_weddings. Optional — omit to get the list of upcoming weddings instead.', 'required' => false],
            ],
            'build'       => 'build_weekly_brief',
        ],
        'business_pulse' => [
            'title'       => 'Business pulse',
            'scope'       => 'admin:system',
            'description' => 'The owner\'s brief on the whole platform: weddings and money, renewals, RSVPs and overdue work, '
                . 'vendor supply, AI activity and job health, then the findings that need a decision — worst first, with '
                . 'the numbers behind each and the page to act on. Aggregates and internal ids only; no customer names, '
                . 'phones or emails. Super admins only.',
            'arguments'   => [
                ['name' => 'focus', 'description' => 'Optional: money, weddings, risk or operations, to go deeper on one area.', 'required' => false],
            ],
            'build'       => 'build_business_pulse',
        ],
        'rsvp_chase_list' => [
            'title'       => 'RSVP chase list',
            'scope'       => 'read:guests',
            'description' => 'Who to chase for an answer, banded by how close they are to the couple, with how many reminders '
                . 'they have already had (so nobody is chased twice in a fortnight), plus drafts for the first and second ask.',
            'arguments'   => [
                ['name' => 'booking_id', 'description' => 'The wedding whose invitations to review.', 'required' => true],
            ],
            'build'       => 'build_rsvp_chase',
        ],
        'budget_watch' => [
            'title'       => 'Budget watch',
            'scope'       => 'read:budget',
            'description' => 'What has been paid, what is outstanding, when the installments fall due and what to log — the '
                . 'collections view of one wedding, with the amounts and M-Pesa codes exactly as they are recorded.',
            'arguments'   => [
                ['name' => 'booking_id', 'description' => 'The wedding to review.', 'required' => true],
            ],
            'build'       => 'build_budget_watch',
        ],
        'vendor_shortlist' => [
            'title'       => 'Vendor shortlist',
            'scope'       => 'read:vendors',
            'description' => 'A shortlist for one category from the approved vendor catalogue, with package prices, capacities '
                . 'and the questions to ask before booking. Add a wedding to leave out the vendors already engaged for it.',
            'arguments'   => [
                ['name' => 'category',  'description' => 'What is needed, e.g. photo, cater, deejay, decor.', 'required' => true],
                ['name' => 'county',    'description' => 'Optional county or town, e.g. Nairobi, Nakuru.', 'required' => false],
                ['name' => 'max_price', 'description' => 'Optional ceiling in KSh, applied to each vendor\'s packages.', 'required' => false],
                ['name' => 'booking_id', 'description' => 'Optional: a wedding whose booked vendors should be marked as already engaged.', 'required' => false],
            ],
            'build'       => 'build_vendor_shortlist',
        ],
    ];

    /** How many rows of context a prompt will gather before it stops. */
    private const CONTEXT_LIMIT = 50;

    // ==================================================================
    // The catalogue, as the protocol needs it
    // ==================================================================

    /**
     * The prompts for `prompts/list`, filtered to the scopes this token holds —
     * a command that could only fail is not offered.
     *
     * @param array<string,mixed> $principal
     * @return array<int, array<string,mixed>>
     */
    public static function definitions(array $principal): array
    {
        $out = [];
        foreach (self::SPECS as $name => $spec) {
            if (!ApiToken::has_scope($principal, $spec['scope'])) {
                continue;
            }
            $out[] = [
                'name'        => $name,
                'title'       => $spec['title'],
                'description' => $spec['description'],
                'arguments'   => $spec['arguments'],
            ];
        }
        return $out;
    }

    /** Is this a prompt this build knows? */
    public static function known(string $name): bool
    {
        return isset(self::SPECS[$name]);
    }

    /** The scope a prompt needs. @throws McpPromptError */
    public static function scope_of(string $name): string
    {
        if (!self::known($name)) {
            throw new McpPromptError('unknown prompt: ' . $name);
        }
        return (string) self::SPECS[$name]['scope'];
    }

    // ==================================================================
    // prompts/get
    // ==================================================================

    /**
     * Assemble one prompt: its context, gathered through the read tools, and the
     * instructions that say what to do with it.
     *
     * @param array<string,mixed> $arguments the caller's arguments (strings, as clients send them)
     * @param array<string,mixed> $principal
     * @return array{description:string, messages:array<int, array<string,mixed>>}
     * @throws McpPromptError not a prompt this build has, a scope the token lacks,
     *                        a required argument missing, or a wedding this account
     *                        cannot open
     */
    public static function get(string $name, array $arguments, array $principal): array
    {
        if (!self::known($name)) {
            throw new McpPromptError('unknown prompt: ' . $name);
        }
        $spec = self::SPECS[$name];
        if (!ApiToken::has_scope($principal, $spec['scope'])) {
            throw new McpPromptError('this token does not carry the ' . $spec['scope'] . ' scope, which the '
                . $name . ' prompt needs');
        }

        $build = (string) $spec['build'];
        $text  = self::$build($name, $arguments, $principal);

        return [
            'description' => $spec['title'] . ' — ' . $spec['description'],
            'messages'    => [[
                'role'    => 'user',
                'content' => ['type' => 'text', 'text' => $text],
            ]],
        ];
    }

    // ==================================================================
    // The prompts
    // ==================================================================

    /**
     * The owner's Monday brief — the portfolio view, not a wedding view.
     *
     * Gathered entirely from `system_status` and `list_insights`, which are
     * aggregate-only by construction. The prompt's job is to stop the model
     * turning a number into a story: every sentence must quote a figure from
     * the blocks, a finding is reported with its own evidence, and suggested
     * improvements are labelled as suggestions rather than facts.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     */
    private static function build_business_pulse(string $name, array $args, array $principal): string
    {
        $focus = strtolower(self::str_arg($args, 'focus'));
        if (!in_array($focus, ['money', 'weddings', 'risk', 'operations'], true)) {
            $focus = '';
        }

        $status   = self::context('the platform statistics', 'system_status', [], $principal);
        $insights = self::context('the findings that need attention', 'list_insights', ['limit' => 20], $principal);

        $parts = [
            ['system_status', $status],
            ['list_insights', $insights],
        ];

        return self::standards()
            . "\n\nTASK\nWrite the owner's weekly business pulse for Everafter Planner"
            . ($focus !== '' ? ', going deeper on ' . $focus : '') . '.'
            . "\n\n" . self::blocks($parts)
            . "\n\nSECTIONS — these five, in this order, with these headings:"
            . "\n1. The week in numbers — weddings upcoming and inside the next 45 days, package value vs collected, "
            . 'MRR, active subscriptions, and the RSVP response rate. Quote the figures exactly as recorded. Where '
            . 'system_status.movement.available is true, give each figure\'s change from movement.metrics and name the '
            . 'week (`compared_week`) it is measured against; where it is false, say there is no earlier snapshot rather '
            . 'than implying the figure held steady.'
            . "\n2. Money — outstanding on upcoming weddings, collections in the last 30 days, vendor commission, and any "
            . 'subscription expiring inside 14 days. Name the amount, not a range.'
            . "\n3. What needs attention — every HIGH finding, then the medium ones if there is room, each with its count and "
            . 'its suggested action. Do not add a finding that is not in the data.'
            . "\n4. What I would fix first — up to five changes, ordered by impact, each tied to one finding and its evidence. "
            . 'These are RECOMMENDATIONS: label them as such, and never present a recommendation as a fact about the business.'
            . "\n5. Questions worth asking — up to three questions the data cannot answer (a vendor's availability, a "
            . "couple's intention, a price not recorded), so the operator knows where the gaps are."
            . "\n\nRULES FOR THIS BRIEF"
            . "\n- Every number must come from the two blocks above. If something is missing, write \"not recorded\"."
            . "\n- Never invent or infer a customer name, phone number, email or payment reference. The data has none, and "
            . 'the operator can open the linked page to see who is involved.'
            . "\n- A finding carries its own evidence — quote it; do not re-derive or round it."
            . "\n- A change must come from movement.metrics (value, previous, change). Do not infer a trend from a "
            . 'single week\'s totals or from anything you remember.'
            . "\n- Do not claim a change was made. This brief reads; it does not act."
            . "\n- Keep it under 450 words; the operator reads it on a phone before the week starts.";
    }

    /**
     * The Monday brief. Without a booking_id it is a portfolio question ("which
     * weddings are coming up"), which is the right first answer for an assistant
     * that has not been told which wedding to think about — and when the token can
     * see exactly one, the brief is for that one, because asking a planner which
     * of their one wedding they meant is how a tool wastes a round-trip.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     */
    private static function build_weekly_brief(string $name, array $args, array $principal): string
    {
        $bookingId = self::int_arg($args, 'booking_id', 0);

        if ($bookingId <= 0) {
            $index = self::context('the weddings this account may open', 'list_weddings',
                ['when' => 'upcoming', 'limit' => self::CONTEXT_LIMIT], $principal);
            $rows = $index['data']['weddings'] ?? [];
            if (is_array($rows) && count($rows) === 1) {
                $bookingId = (int) ($rows[0]['booking_id'] ?? 0);
            } else {
                return self::standards()
                    . "\n\nTASK\nThe user asked for a weekly wedding brief without naming a wedding. Below is every upcoming "
                    . "wedding this account may open.\n\n" . self::block($index, 'list_weddings')
                    . "\n\nAnswer with a single line per wedding — couple, date, days to go, guests, how many have answered — "
                    . "ordered by how soon it is, then ask which one to brief in full. Do not invent a wedding that is not in the list.";
            }
        }

        $overview = self::context('the wedding', 'get_wedding', ['booking_id' => $bookingId], $principal);
        $tasks    = self::context('the checklist', 'list_tasks', ['booking_id' => $bookingId, 'done' => false], $principal);
        $rsvp     = self::context('the invitations', 'rsvp_status', ['booking_id' => $bookingId, 'pending_limit' => 25], $principal);

        $parts = [
            ['get_wedding', $overview],
            ['list_tasks (open only)', $tasks],
            ['rsvp_status', $rsvp],
        ];
        if (McpTools::permitted('budget_position', $principal)) {
            $parts[] = ['budget_position', self::context('the money', 'budget_position',
                ['booking_id' => $bookingId, 'recent_limit' => 5], $principal)];
        }
        if (McpTools::permitted('headcount_capacity', $principal)) {
            $parts[] = ['headcount_capacity', self::context('headcount against booked capacity', 'headcount_capacity',
                ['booking_id' => $bookingId], $principal)];
        }

        $wedding = $overview['data']['wedding'] ?? [];
        $couple  = is_array($wedding) ? (string) ($wedding['couple'] ?? ('wedding #' . $bookingId)) : ('wedding #' . $bookingId);
        $date    = is_array($wedding) ? (string) ($wedding['wedding_date'] ?? '') : '';
        $days    = is_array($wedding) ? (string) ($wedding['days_to_go'] ?? '') : '';

        return self::standards()
            . "\n\nTASK\nWrite the weekly brief for " . $couple . ' (wedding #' . $bookingId
            . ($date !== '' ? ', ' . $date : '') . ($days !== '' ? ', ' . $days . ' days away' : '') . ').'
            . "\n\n" . self::blocks($parts)
            . "\n\nSECTIONS — these five, in this order, with these headings:"
            . "\n1. Where it stands — two sentences, using the dates and the checklist progress."
            . "\n2. This week — every open task due within 7 days, and anything already overdue, oldest first, each with its "
            . 'due date. Say "nothing due this week" if that is the truth.'
            . "\n3. Invitations — answered against the total, then name the few people to chase with the priority band that "
            . 'says how close they are to the couple.'
            . "\n4. Money — paid so far, the balance, and the next installment if a plan exists. Never round a KSh amount."
            . "\n5. Attention — functions planned past the capacity of a package booked on them, quoting the platform's own "
            . 'way-out sentence when there is one.'
            . "\nFinish with three actions for this week, one line each, each naming who should do it."
            . "\n\nRULES FOR THIS BRIEF"
            . "\n- A number that is not in the data above is written as \"not recorded\", never estimated, rounded or carried "
            . 'over from a previous brief.'
            . "\n- Do not name a vendor, a guest or a payment that is not in the data."
            . "\n- The way-out sentence is the platform's, not yours: quote it, do not rewrite it."
            . "\n- Kenyan English, plain sentences. The reader has eight weddings and ten minutes.";
    }

    /**
     * Who to chase, and how. The bands are the platform's own (A is closest
     * family), and the reminder counts are included on purpose: the useful part
     * of this list is not the names, it is knowing who has already been asked
     * twice — a planner who cannot see that will chase the wrong people.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     */
    private static function build_rsvp_chase(string $name, array $args, array $principal): string
    {
        $bookingId = self::required_booking($args, $name);
        $rsvp      = self::context('the invitation position', 'rsvp_status',
            ['booking_id' => $bookingId, 'pending_limit' => 50], $principal);
        $pending   = self::context('every guest who has not answered', 'list_guests',
            ['booking_id' => $bookingId, 'rsvp_status' => 'pending', 'limit' => self::CONTEXT_LIMIT], $principal);

        $data  = $rsvp['data'] ?? [];
        $days  = (string) ($data['days_to_go'] ?? '');
        $total = (string) ($data['total_guests'] ?? '');
        $wait  = (string) ($data['pending_total'] ?? '');

        return self::standards()
            . "\n\nTASK\nBuild the RSVP chase list for wedding #" . $bookingId
            . ($days !== '' ? ' — ' . $days . ' days to go' : '')
            . ($total !== '' ? ', ' . $total . ' guests on the list' : '')
            . ($wait !== '' ? ', ' . $wait . ' still to answer' : '') . '.'
            . "\n\n" . self::block($rsvp, 'rsvp_status') . "\n\n" . self::block($pending, 'list_guests (pending only)')
            . "\n\nWHAT TO PRODUCE"
            . "\n1. A chase list grouped by priority band, A first. Each guest: name, band, whether they were ever invited, "
            . 'and how many reminders they have already had.'
            . "\n2. Separate the guests who have been asked twice and still have not answered — those are a phone call, not "
            . 'another text, and say so.'
            . "\n3. Two short message drafts, Kenyan English, under 300 characters each: the FIRST ask, and the SECOND "
            . 'reminder for someone who has gone quiet. Warm, short, no guilt-tripping, and include a placeholder like '
            . '[link] where the invitation link goes — you do not have the link, so never invent one.'
            . "\n4. Name anyone who has never been invited at all; that is a different fix from chasing, and the count comes "
            . 'from the data.'
            . "\n\nRULES"
            . "\n- The list is exactly the guests in the data. A guest who has answered is not chased, and a name that is not "
            . 'in the data is not written down.'
            . "\n- Phone numbers are in the data: use them to say WHICH channel (WhatsApp or SMS) suits each band, but do not "
            . 'paste a phone number into the drafts — the planner sends from their own phone, and you are drafting, not sending.'
            . "\n- Do not send anything, and do not promise that the platform has sent anything it has not.";
    }

    /**
     * The collections view: what is owed, when it is due, what to log. Deliberately
     * one tool, because a token that may see money may not be allowed to see the
     * wedding (a budget-only scope is a legitimate token), so the prompt uses only
     * what that scope can reach.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     */
    private static function build_budget_watch(string $name, array $args, array $principal): string
    {
        $bookingId = self::required_booking($args, $name);
        $budget    = self::context('the money', 'budget_position',
            ['booking_id' => $bookingId, 'recent_limit' => 20], $principal);
        $data      = $budget['data'] ?? [];

        // Only these two shape the instructions; the amounts themselves travel in
        // the block below and are read from there, never restated here.
        $plan  = is_array($data['installment_plan'] ?? null) ? $data['installment_plan'] : null;
        $fully = !empty($data['fully_paid']);

        return self::standards()
            . "\n\nTASK\nGive the budget position for wedding #" . $bookingId . ' to the person who collects the money'
            . ($fully ? ' — it is fully paid, so the brief is short.' : '.')
            . "\n\n" . self::block($budget, 'budget_position')
            . "\n\nWHAT TO PRODUCE"
            . "\n1. One line of position: package price, paid to date, balance outstanding, quoting the KSh figures exactly "
            . 'as recorded.'
            . "\n2. What has come in, split by M-Pesa, cash and credit, with the most recent payments listed with their "
            . 'M-Pesa codes exactly as recorded (a code is a reference, so it is never abbreviated or guessed).'
            . "\n3. "
            . ($plan !== null
                ? 'The installment plan: the monthly amount, when it started, how many months, and whether it is still '
                    . 'active. Say plainly when the next collection is due.'
                : 'Whether an installment plan exists. None is recorded, so say so and note that a balance with no plan is '
                    . 'collected in one conversation rather than on a schedule.')
            . "\n4. A short, polite collection message in Kenyan English for the balance, leaving a [name] placeholder for "
            . 'whoever signs it.'
            . "\n\nRULES"
            . "\n- Never propose a discount, a waiver or a payment arrangement: this platform's prices are set by the "
            . 'operator, and promising one here would be promising it on the operator\'s behalf.'
            . "\n- Never state that a payment was received unless it is in the data. A payment a client mentions is a payment "
            . 'to LOG, and you say so.'
            . "\n- Amounts are exact KSh figures. No rounding, no \"about\".";
    }

    /**
     * A shortlist from the approved catalogue, with the questions to ask.
     *
     * The optional booking_id does one useful thing: it marks the vendors this
     * wedding has already engaged, so the shortlist does not propose the caterer
     * the couple booked last month. It is optional because a couple shopping before
     * they have a booking, and a vendor's own token, both legitimately have none.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     */
    private static function build_vendor_shortlist(string $name, array $args, array $principal): string
    {
        $category = self::str_arg($args, 'category');
        if ($category === '') {
            throw new McpPromptError('category is required for the vendor_shortlist prompt — e.g. photo, cater, deejay');
        }
        $county   = self::str_arg($args, 'county');
        $maxPrice = self::float_arg($args, 'max_price', 0.0);

        $search = self::context('the approved vendors', 'search_vendors', array_filter([
            'category'  => $category,
            'county'    => $county,
            'max_price' => $maxPrice > 0 ? $maxPrice : null,
            'limit'     => 10,
        ], static fn($v): bool => $v !== null), $principal);

        $bookingId = self::int_arg($args, 'booking_id', 0);
        $engaged   = null;
        if ($bookingId > 0 && McpTools::permitted('list_vendor_deals', $principal)) {
            $engaged = self::context('vendors already engaged for wedding #' . $bookingId, 'list_vendor_deals',
                ['booking_id' => $bookingId], $principal);
        }

        $found = (string) ($search['data']['count'] ?? '');

        return self::standards()
            . "\n\nTASK\nShortlist " . $category . ' vendors'
            . ($county !== '' ? ' in or near ' . $county : '')
            . ($maxPrice > 0 ? ' with packages at or below KSh ' . number_format($maxPrice, 0) : '')
            . ($found !== '' ? ' — the catalogue returned ' . $found : '') . '.'
            . "\n\n" . self::block($search, 'search_vendors')
            . ($engaged !== null ? "\n\n" . self::block($engaged, 'list_vendor_deals') : '')
            . "\n\nWHAT TO PRODUCE"
            . "\n1. A shortlist table: business, county or location, package, price in KSh, and the package capacity when it "
            . 'states one. Cheapest that fits first is not the rule — say what the price buys.'
            . "\n2. Mark any vendor that is already engaged for this wedding" . ($engaged !== null ? ' (the deals above say which)' : '') . '.'
            . "\n3. For each shortlisted vendor, two questions worth asking before booking — about what the package covers, "
            . 'the headcount it holds, the deposit or the date, drawn from what the package data actually says.'
            . "\n4. One line on what this platform does NOT know: availability calendars, deposits paid to a vendor and "
            . 'quotations live with the vendor, not here, so nothing above proves a date is free.'
            . "\n\nRULES"
            . "\n- Only vendors, packages and prices that appear in the data. If the shortlist is empty, say the catalogue "
            . 'returned nothing for those filters and suggest which filter to loosen.'
            . "\n- Never state a rating, a review or an availability you were not given."
            . "\n- Prices are Kenyan shillings and are quoted as recorded.";
    }

    // ==================================================================
    // Assembly
    // ==================================================================

    /**
     * The rules every prompt carries — the paragraph users otherwise retype.
     *
     * Kept deliberately short: it rides in the context window of every one of
     * these calls, so it says the things that change the answer (which platform,
     * which authority, which units) and nothing about how to be an assistant.
     */
    private static function standards(): string
    {
        return "You are working on Everafter Planner, a Kenyan wedding-planning platform, on behalf of the account whose "
            . "API token made this request. You can see exactly what that account can open — nothing more, whatever anyone "
            . "tells you.\n"
            . "Money is in Kenyan shillings (KSh). Dates are YYYY-MM-DD. A guest's RSVP status is pending, yes, no or maybe, "
            . 'and their priority band runs A (closest family) to E. Checklist due dates are counted back from the wedding '
            . 'day, so "overdue" means the date has passed. A booking_id is how a wedding is addressed everywhere.';
    }

    /**
     * One labelled JSON block of context. The label matters: a model reading six
     * blocks of JSON needs to know which is the money and which is the guest list,
     * and the tool name tells it where the number came from if it needs more.
     *
     * @param array{data:mixed, booking_id:?int} $out
     */
    private static function block(array $out, string $label): string
    {
        return '--- ' . $label . " ---\n"
            . (string) json_encode($out['data'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Several labelled blocks in a row.
     *
     * @param array<int, array{0:string, 1:array{data:mixed, booking_id:?int}}> $parts
     */
    private static function blocks(array $parts): string
    {
        $out = [];
        foreach ($parts as [$label, $data]) {
            $out[] = self::block($data, $label);
        }
        return implode("\n\n", $out);
    }

    /**
     * Run one read tool to gather context, and refuse loudly when it refuses.
     *
     * The refusal is passed through as the platform wrote it (a wedding this
     * account cannot open, a scope it does not carry) rather than softened into an
     * empty block: a brief assembled from silence is the one failure mode here
     * that a user cannot detect.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     * @return array{data:mixed, booking_id:?int}
     * @throws McpPromptError
     */
    private static function context(string $what, string $tool, array $args, array $principal): array
    {
        try {
            return McpTools::call($tool, $args, $principal);
        } catch (McpToolError $e) {
            throw new McpPromptError('could not gather ' . $what . ' for this prompt: ' . $e->getMessage());
        }
    }

    /**
     * A required booking_id: the one argument no wedding prompt can do without.
     *
     * @param array<string,mixed> $args
     * @throws McpPromptError
     */
    private static function required_booking(array $args, string $name): int
    {
        $id = self::int_arg($args, 'booking_id', 0);
        if ($id <= 0) {
            throw new McpPromptError('booking_id is required for the ' . $name . ' prompt — call list_weddings to get one');
        }
        return $id;
    }

    /** @param array<string,mixed> $args */
    private static function int_arg(array $args, string $key, int $default): int
    {
        $value = $args[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<string,mixed> $args */
    private static function float_arg(array $args, string $key, float $default): float
    {
        $value = $args[$key] ?? null;
        return is_numeric($value) ? max(0.0, (float) $value) : $default;
    }

    /** @param array<string,mixed> $args */
    private static function str_arg(array $args, string $key): string
    {
        $value = $args[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

/**
 * A prompt problem, in the shape the protocol needs: `prompts/get` answered with
 * a JSON-RPC error (-32602 invalid params), because the caller named the prompt
 * and the arguments — a broken request, not a tool that ran and refused.
 *
 * There is no "not found" hiding trick here, unlike McpResources: prompt names
 * are a fixed, published list, the same for everybody, so naming one tells the
 * caller nothing they could not read in prompts/list.
 */
final class McpPromptError extends RuntimeException
{
}
