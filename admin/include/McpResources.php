<?php
declare(strict_types=1);

/**
 * WPMS Kenya v2.16 — weddings as DOCUMENTS (McpResources)
 *
 * MCP gives an assistant two ways to read a server: call a tool, or read a
 * resource. Tools are for doing things and returning values; resources are for
 * THE DOCUMENTS a person would open — and clients differ in which they prefer, so
 * a server that offers only tools is invisible to the half that browses.
 *
 * This class is the resource half, and the important thing about it is what it
 * does NOT contain: not one query. Every URI resolves to a TOOL, and the tool's
 * own handler runs — with the same scope check, the same `booking_visible_to_
 * session()` predicate, the same models and the same audit row a tools/call
 * would produce. A second implementation of "the wedding overview" would be a
 * second answer to the question the moment either side changed, and the day they
 * disagree the AI is the door that is wrong.
 *
 * THE URIs
 *
 *   everafter://me                 who this token acts as, and what it may read
 *   everafter://weddings           the weddings this account may open
 *   wedding://{booking_id}/{view}  one wedding as a document
 *
 * `{view}` is one of the eight below, each mapped to the tool that already
 * answers it, and each needing that tool's scope:
 *
 *   overview  get_wedding          guests    list_guests       tasks      list_tasks
 *   budget    budget_position      vendors   list_vendor_deals timeline   list_timeline
 *   capacity  headcount_capacity   website   wedding_website
 *
 * WHY A MISSING WEDDING AND SOMEBODY ELSE'S WEDDING ANSWER THE SAME WAY. A
 * resource that said "you may not read wedding #201" while saying "no such
 * resource" for #99999 would be an oracle: an assistant could walk the id space
 * and learn how many weddings exist on the platform, and which ids are real. So
 * the ownership refusal is reported EXACTLY as a missing resource is (the
 * protocol's own not-found answer, -32602 in the current revision and -32002 in
 * the older one — see McpServer). A refusal that is about the TOKEN rather than
 * the resource — "this token does not carry the read:guests scope" — is a
 * different thing and is said plainly, because the caller can fix it and nothing
 * about the platform is learned by being told.
 *
 * The list is filtered to what the token may read, exactly like `tools/list`, so
 * browsing never doubles as a map of surfaces this account has no business with.
 */
final class McpResources
{
    /** The account's own document: identity, scopes, and what it can see. */
    public const ME_URI = 'everafter://me';

    /** The index of weddings this account may open. */
    public const WEDDINGS_URI = 'everafter://weddings';

    /**
     * The document views, keyed by the path segment a URI carries, mapped to the
     * tool whose handler produces them. One entry per document — this table is
     * the contract between a URI and an implementation that already exists.
     */
    public const VIEWS = [
        'overview' => 'get_wedding',
        'timeline' => 'list_timeline',
        'guests'   => 'list_guests',
        'tasks'    => 'list_tasks',
        'budget'   => 'budget_position',
        'capacity' => 'headcount_capacity',
        'vendors'  => 'list_vendor_deals',
        'website'  => 'wedding_website',
    ];

    /** Most wedding documents `resources/list` will enumerate in one answer. */
    public const LIST_LIMIT = 50;

    /**
     * The concrete resources on offer for this token: its own account, the
     * wedding index, and one overview per wedding it may open.
     *
     * Deliberately NOT all eight views per wedding: with fifty weddings that
     * would be four hundred entries in a list whose job is to be scannable. The
     * overview is what a client shows, and the other seven are discoverable one
     * level down through `resources/templates/list` — which is what templates are
     * for.
     *
     * @param array<string,mixed> $principal
     * @return array<int, array<string,mixed>>
     */
    public static function definitions(array $principal): array
    {
        $out = [[
            'uri'         => self::ME_URI,
            'name'        => 'Who this token acts as',
            'title'       => 'Who this token acts as',
            'description' => 'The account, role and scopes behind the token making this request, and how many weddings it can see.',
            'mimeType'    => 'application/json',
            'annotations' => ['audience' => ['assistant'], 'priority' => 0.9],
        ]];

        if (!McpTools::permitted('list_weddings', $principal)) {
            return $out; // no read:weddings → no wedding documents to name
        }

        $out[] = [
            'uri'         => self::WEDDINGS_URI,
            'name'        => 'Weddings this account may open',
            'title'       => 'Weddings this account may open',
            'description' => 'The index: every wedding visible to this token, with the date, days to go and RSVP progress.',
            'mimeType'    => 'application/json',
            'annotations' => ['audience' => ['assistant'], 'priority' => 0.8],
        ];

        // An overview is the only view named one level up; the other seven are
        // reached through resources/templates/list (see the docblock). Naming a
        // wedding the token could not then read would be a promise we cannot keep,
        // so the overview tool's own scope decides whether any are named at all.
        if (!McpTools::permitted('get_wedding', $principal)) {
            return $out;
        }

        foreach (array_slice(self::visible_weddings($principal), 0, self::LIST_LIMIT) as $wedding) {
            $id = (int) $wedding['booking_id'];
            /*
             * A booking can legitimately have no names on it yet (a partial
             * registration), and `trim()` of "bride & groom" is then just "&" — not
             * a wedding anybody can recognise in a list a client shows a person.
             * The id is.
             */
            $couple = trim((string) ($wedding['couple'] ?? ''), ' &');
            $name   = $couple !== '' ? $couple : 'Wedding #' . $id;
            $out[] = [
                'uri'         => 'wedding://' . $id . '/overview',
                'name'        => $name,
                'title'       => $name,
                'description' => $name . ' — wedding #' . $id . ' on ' . (string) ($wedding['wedding_date'] ?? '')
                    . ': couple, date, guest and RSVP totals, checklist progress, budget position and vendor deals.',
                'mimeType'    => 'application/json',
                'annotations' => [
                    'audience'     => ['assistant'],
                    'priority'     => $wedding['days_to_go'] !== null && (int) $wedding['days_to_go'] <= 30 ? 0.9 : 0.6,
                    'lastModified' => (string) ($wedding['wedding_date'] ?? ''),
                ],
            ];
        }
        return $out;
    }

    /**
     * The URI templates for this token: one per document view whose tool it may
     * call, using RFC 6570 syntax (`{booking_id}` is the variable).
     *
     * @param array<string,mixed> $principal
     * @return array<int, array<string,mixed>>
     */
    public static function templates(array $principal): array
    {
        $out = [];
        foreach (self::VIEWS as $view => $tool) {
            if (!McpTools::permitted($tool, $principal)) {
                continue;
            }
            $out[] = [
                'uriTemplate' => 'wedding://{booking_id}/' . $view,
                'name'        => 'Wedding ' . $view,
                'title'       => 'Wedding ' . $view,
                'description' => self::VIEW_DESCRIPTIONS[$view] ?? ('The ' . $view . ' document for one wedding.'),
                'mimeType'    => 'application/json',
            ];
        }
        return $out;
    }

    /**
     * The scope a URI's document needs — '' when the URI names nothing this class
     * serves (a typo, or somebody else's scheme).
     *
     * The audit row uses this so a resource read is recorded against the same scope
     * the equivalent tools/call would have used: an operator reading the trail sees
     * one vocabulary, whether the AI called `budget_position` or read
     * `wedding://200/budget`.
     */
    public static function scope_for(string $uri): string
    {
        $uri  = trim($uri);
        $tool = '';
        if ($uri === self::ME_URI) {
            $tool = 'whoami';
        } elseif ($uri === self::WEDDINGS_URI) {
            $tool = 'list_weddings';
        } elseif (preg_match('~^wedding://\d{1,12}/([a-z_]+)$~', $uri, $m) && isset(self::VIEWS[$m[1]])) {
            $tool = self::VIEWS[$m[1]];
        }
        return $tool !== '' && McpTools::known($tool) ? McpTools::scope_of($tool) : '';
    }

    /**
     * Read one resource. Returns the raw data a tool produced, which the caller
     * (McpServer) renders into `contents[]` exactly as a tool result is rendered.
     *
     * @param array<string,mixed> $principal
     * @return array{name:string, description:string, data:mixed, booking_id:?int}
     * @throws McpResourceError not found / not readable / scope the token lacks
     */
    public static function read(string $uri, array $principal): array
    {
        $uri = trim($uri);
        if ($uri === '') {
            throw new McpResourceError('a resource uri is required', true);
        }

        if ($uri === self::ME_URI) {
            $out = self::call_tool('whoami', [], $principal);
            return self::document('me', 'Who this token acts as', $out);
        }
        if ($uri === self::WEDDINGS_URI) {
            $out = self::call_tool('list_weddings', ['when' => 'all', 'limit' => self::LIST_LIMIT], $principal);
            return self::document('index', 'Weddings this account may open', $out);
        }

        // wedding://<booking_id>/<view>
        if (!preg_match('~^wedding://(\d{1,12})/([a-z_]+)$~', $uri, $m)) {
            throw new McpResourceError('unknown resource uri: ' . $uri, true);
        }
        $bookingId = (int) $m[1];
        $view      = $m[2];
        if ($bookingId <= 0 || !isset(self::VIEWS[$view])) {
            throw new McpResourceError('unknown resource uri: ' . $uri, true);
        }

        $tool = self::VIEWS[$view];
        if (!McpTools::permitted($tool, $principal)) {
            // About the TOKEN, not the resource: the caller can fix this, and the
            // refusal teaches nothing about what exists.
            throw new McpResourceError(
                'this token does not carry the ' . McpTools::scope_of($tool) . ' scope, which the ' . $view . ' document needs',
                false
            );
        }

        try {
            $out = self::call_tool($tool, ['booking_id' => $bookingId], $principal);
        } catch (McpToolError $e) {
            /*
             * The tool refused. Two of those are "it is not yours" — an unopenable
             * booking or a token narrowed elsewhere — and those are reported as a
             * MISSING resource (see the class docblock: anything else is an oracle
             * for walking the id space). A refusal that is about the arguments is
             * passed through, because it says nothing about what exists.
             */
            $message = $e->getMessage();
            $notYours = str_contains($message, 'cannot open wedding')
                || str_contains($message, 'narrowed to wedding')
                || str_contains($message, 'booking_id is required');
            throw new McpResourceError($notYours ? 'no such resource: ' . $uri : $message, $notYours);
        }

        return self::document($view, 'Wedding #' . $bookingId . ' ' . $view, $out);
    }

    /**
     * The weddings this token may open, as the index tool reports them. An empty
     * list is a legitimate answer (a token that may read weddings but sees none);
     * a tool refusal is not, and is swallowed into "nothing to list" because
     * resources/list must still answer — the resources it names are only hints,
     * and reading one goes through the full check again.
     *
     * @param array<string,mixed> $principal
     * @return array<int, array<string,mixed>>
     */
    private static function visible_weddings(array $principal): array
    {
        try {
            $out = McpTools::call('list_weddings', ['when' => 'all', 'limit' => self::LIST_LIMIT], $principal);
        } catch (Throwable $e) {
            return [];
        }
        $rows = $out['data']['weddings'] ?? [];
        return is_array($rows) ? $rows : [];
    }

    /**
     * Run one tool on behalf of a resource read, normalising its answer.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $principal
     * @return array{data:mixed, booking_id:?int}
     */
    private static function call_tool(string $tool, array $args, array $principal): array
    {
        return McpTools::call($tool, $args, $principal);
    }

    /**
     * @param array{data:mixed, booking_id:?int} $out
     * @return array{name:string, description:string, data:mixed, booking_id:?int}
     */
    private static function document(string $key, string $name, array $out): array
    {
        return [
            'name'        => $name,
            'description' => self::VIEW_DESCRIPTIONS[$key] ?? '',
            'data'        => $out['data'],
            'booking_id'  => $out['booking_id'] ?? null,
        ];
    }

    /**
     * What each document holds, in the operator's own words — the description a
     * client shows before a model decides whether the document is worth reading.
     */
    private const VIEW_DESCRIPTIONS = [
        'overview' => 'One wedding in one document: couple and contact, date and days to go, package and price, guest and '
            . 'RSVP totals, checklist progress, payments against the balance, and how many vendor deals are booked or pending.',
        'timeline' => 'The running order of the wedding day: every function in time order with its venue, its planned '
            . 'headcount and the vendors covering it — including the functions nobody has planned for yet.',
        'guests'   => 'The guest list with RSVP answers, priority bands, travel and dietary needs.',
        'tasks'    => 'The planning checklist: what is done, what is due, and what is already overdue.',
        'budget'   => 'The package price, every payment received by method, the balance due and the installment plan.',
        'capacity' => 'Whether the crowd planned for each function still fits the guest capacity of the packages booked on '
            . 'it, with the position on anything already over a limit and the platform\'s own way-out advice.',
        'vendors'  => 'The vendors engaged for this wedding: business, category, package, amount and deal status.',
        'website'  => 'The couple\'s shareable wedding page: public URL, published state, views, venue and story.',
        'me'       => 'The account, role and scopes behind the token making this request.',
        'index'    => 'Every wedding this token may open, with date, days to go and RSVP progress.',
    ];
}

/**
 * A resource problem, in the shape the protocol needs.
 *
 * `notFound` is the interesting half: TRUE when the caller must not be able to
 * tell "somebody else's wedding" from "no such wedding" (reported as the
 * protocol's resource-not-found), and FALSE when the message is about the CALLER
 * — a scope the token lacks — and can be said plainly.
 */
final class McpResourceError extends RuntimeException
{
    public function __construct(string $message, public readonly bool $notFound = false)
    {
        parent::__construct($message);
    }
}
