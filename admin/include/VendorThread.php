<?php
declare(strict_types=1);

/**
 * VendorThread — the conversation on ONE vendor deal (v2.25).
 *
 * A couple enquires, the vendor replies with availability and a price, the
 * planner keeps both moving. Until v2.25 all three wrote into
 * `tblvendor_bookings.notes` — one text column, no author, no timestamp, no
 * way to answer — so the couple had to phone the planner to ask "is the 14th
 * still free?" and nobody could tell whose turn it was.
 *
 * This class owns the three things that make the thread feel like chat:
 *
 *   1. WRITES (post) — validated, attributed, and it notifies the other two
 *      parties in-app. The message and its notifications are one act; callers
 *      never send a message without the bell.
 *   2. READ STATE (for_deals + unread_counts + mark_read) — three stamps on
 *      the message row, so "new" is per role and no per-viewer table exists.
 *   3. THE NEXT STEP (stage) — derived from the deal's status and who spoke
 *      last, in the viewer's own words: the couple reads "the vendor replied —
 *      your turn", the vendor reads "reply needed". Derived, never stored, so a
 *      confirmed deal cannot keep advertising a pending step.
 *
 * `tblvendor_bookings.notes` is NOT touched: it stays the deal's audit trail
 * (confirmations, availability flags, staff edits), which is why an existing
 * deal still reads exactly as it did before this table existed.
 *
 * Table: migrations/v2.25_vendor_threads.sql (tblvendor_messages).
 * Guards live in admin/vendor_thread_post.php; this class assumes the caller
 * has already proved that the session may act as `$role` on this deal.
 */
class VendorThread
{
    public const COUPLE  = 'couple';
    public const VENDOR  = 'vendor';
    public const PLANNER = 'planner';

    /** The author roles a message may carry, in display order. */
    public const ROLES = [self::COUPLE, self::VENDOR, self::PLANNER];

    /** One message's ceiling — a text, not a document. */
    public const MAX_BODY = 2000;

    /**
     * Is migrations/v2.25_vendor_threads.sql installed? The panels check this
     * once per request so a database that predates v2.25 shows a plain "not
     * enabled yet" line instead of a PDO error on three different pages.
     */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }
        global $db;
        try {
            $db->execute('SELECT 1 FROM tblvendor_messages LIMIT 1');
            $installed = true;
        } catch (Throwable $e) {
            $installed = false;
        }
        return $installed;
    }

    /** What this role is called on a message bubble. */
    public static function role_label(string $role): string
    {
        return match ($role) {
            self::COUPLE  => 'Couple',
            self::VENDOR  => 'Vendor',
            self::PLANNER => 'Planner',
            default       => 'Someone',
        };
    }

    /** A Material Design Icon name for the role (2.x set: admin pages vary). */
    public static function role_icon(string $role): string
    {
        return match ($role) {
            self::COUPLE  => 'heart',
            self::VENDOR  => 'store-outline',
            self::PLANNER => 'briefcase-outline',
            default       => 'account',
        };
    }

    // =====================================================================
    // READS
    // =====================================================================

    /**
     * Every message on one deal, oldest first.
     *
     * @return object[] rows with id, author_role, author_name, body, created_at
     */
    public static function for_deal(int $dealId): array
    {
        global $db;
        if ($dealId <= 0 || !self::installed()) {
            return [];
        }
        $stmt = $db->prepare(
            'SELECT * FROM tblvendor_messages WHERE deal_id = :id ORDER BY id ASC'
        );
        $stmt->execute([':id' => $dealId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];
    }

    /**
     * Threads for a whole ledger in ONE query, keyed by deal id.
     *
     * The planner's ledger and a couple's enquiry list both render a dozen
     * deals at a time; asking per row would be a dozen round trips to say "no
     * messages yet".
     *
     * @param int[] $dealIds
     * @return array<int, object[]> deal id => messages, oldest first (missing = none)
     */
    public static function for_deals(array $dealIds): array
    {
        global $db;
        $ids = array_values(array_unique(array_filter(array_map('intval', $dealIds), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || !self::installed()) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT * FROM tblvendor_messages WHERE deal_id IN ($in) ORDER BY deal_id ASC, id ASC"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) ?: [] as $row) {
            $out[(int) $row->deal_id][] = $row;
        }
        return $out;
    }

    /**
     * The message each deal has been waiting on longest: the oldest unread
     * message from the other side, as [created_at, id].
     *
     * That pair is exactly what oldest_unread() walks, so the deal on top of a
     * queue, the deal the sidebar badge opens and the "waiting 3 h" label on a
     * row all describe the same message instead of three near-misses.
     *
     * @param int[]                $dealIds       the rows on the page
     * @param array<int, object[]> $threadsByDeal from for_deals()
     * @return array<int, array{0: string, 1: int}> deal id => wait key (missing = nothing waiting)
     */
    public static function waiting_since(array $dealIds, array $threadsByDeal, string $role): array
    {
        $column = self::read_column($role);
        if ($column === null) {
            return [];
        }

        $waiting = [];
        foreach ($dealIds as $dealId) {
            $dealId = (int) $dealId;
            foreach ($threadsByDeal[$dealId] ?? [] as $message) {
                if ((string) $message->author_role === $role || $message->{$column} !== null) {
                    continue; // its own words, or already read by this role
                }
                $key = [(string) $message->created_at, (int) $message->id];
                if (!isset($waiting[$dealId]) || $key < $waiting[$dealId]) {
                    $waiting[$dealId] = $key;
                }
            }
        }

        return $waiting;
    }

    /**
     * A ledger in queue order: the deal whose longest-waiting unread message is
     * oldest comes first — the SAME deal the sidebar badge opens, because both
     * walk (created_at, id) over the messages nobody has answered yet. A reader
     * working the unread filter down the page answers conversations in the order
     * they have been ignored.
     *
     * Deals with nothing waiting sink to the bottom: the row an opener just
     * marked read is kept on screen by the filter so its panel still renders,
     * but it is not waiting on anybody. Ties fall back to the newest deal, so
     * a whole ledger of silence still renders in a stable order.
     *
     * @param object[]                              $deals   the page's rows, in ledger order
     * @param array<int, array{0: string, 1: int}>  $waiting from waiting_since()
     * @return object[] the same rows, longest waiting first
     */
    public static function waiting_first(array $deals, array $waiting): array
    {
        usort($deals, static function (object $a, object $b) use ($waiting): int {
            $left  = $waiting[(int) $a->id] ?? null;
            $right = $waiting[(int) $b->id] ?? null;
            if ($left === null || $right === null) {
                // Waiting deals first; among equals (a clear queue) newest wins.
                return $left === null
                    ? ($right === null ? (int) $b->id <=> (int) $a->id : 1)
                    : -1;
            }
            return $left <=> $right;
        });

        return array_values($deals);
    }

    /**
     * How long a message has been waiting, in the few characters a ledger row
     * can spare: "less than a minute", "8 min", "3 h", "2 days", "3 wk".
     * The caller prefixes it ("waiting 3 h") and keeps the exact time in the
     * tooltip; $now is injectable so the wording can be tested without waiting.
     */
    public static function waiting_for(string $since, ?int $now = null): string
    {
        $then = strtotime($since);
        if ($then === false) {
            return '';
        }
        $minutes = max(0, intdiv(($now ?? time()) - $then, 60));
        if ($minutes < 60) {
            return $minutes < 1 ? 'less than a minute' : $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . ' h';
        }
        $days = intdiv($hours, 24);
        if ($days < 14) {
            return $days . ' day' . ($days === 1 ? '' : 's');
        }
        return intdiv($days, 7) . ' wk';
    }

    /**
     * How many messages this role has not read, per deal. Messages written by
     * the role itself never count as unread for it.
     *
     * @param int[] $dealIds
     * @return array<int, int> deal id => count (missing = 0)
     */
    public static function unread_counts(array $dealIds, string $role): array
    {
        global $db;
        $column = self::read_column($role);
        $ids = array_values(array_unique(array_filter(array_map('intval', $dealIds), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || $column === null || !self::installed()) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT deal_id, COUNT(*) AS c FROM tblvendor_messages
              WHERE deal_id IN ($in) AND author_role <> ? AND $column IS NULL
              GROUP BY deal_id"
        );
        $stmt->execute(array_merge($ids, [$role]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) ?: [] as $row) {
            $out[(int) $row->deal_id] = (int) $row->c;
        }
        return $out;
    }

    /** One deal's unread count for a role. */
    public static function unread_count(int $dealId, string $role): int
    {
        return self::unread_counts([$dealId], $role)[$dealId] ?? 0;
    }

    /**
     * How many messages this role has not read across every deal it can open —
     * the sidebar badge.
     *
     * One query, scoped by the SAME ownership rules the pages and the doors
     * use, so the badge can never promise a message on a deal the reader would
     * be refused when they click it:
     *
     *   - couple  : deals on the booking(s) their own account owns; pass a
     *               $bookingId to count just the wedding the badge links to.
     *   - vendor  : deals with any listing the account runs (not one listing).
     *   - planner : deals whose wedding this planner may open, via the shared
     *               planner scope (super admins and unscoped installs count all).
     *
     * Returns 0 rather than throwing when v2.25 is not installed: the sidebar
     * renders on every page and must not turn a missing migration into a 500.
     */
    public static function unread_total(?string $role = null, int $sessionId = 0, int $bookingId = 0): int
    {
        global $db;
        $role = $role ?? self::session_role();
        if ($role === null || !self::installed()) {
            return 0;
        }
        $scope = self::unread_scope($role, $sessionId, $bookingId);
        if ($scope === null) {
            return 0;
        }
        try {
            return (int) $db->fetchValue(
                'SELECT COUNT(*) FROM tblvendor_messages m ' . $scope['joins']
                . ' WHERE ' . implode(' AND ', $scope['where']),
                $scope['params']
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The deal holding this role's OLDEST unread message, or 0 when the ledger
     * is clear — what the sidebar badge opens.
     *
     * It is deliberately the oldest, not the newest: a badge is a queue, and a
     * reader who clicks it should start at the message that has been waiting
     * longest instead of the one that just arrived. The scope is
     * unread_scope(), the same definition the count uses, so "3 new" can never
     * open a deal that has none.
     */
    public static function oldest_unread(?string $role = null, int $sessionId = 0, int $bookingId = 0): int
    {
        global $db;
        $role = $role ?? self::session_role();
        if ($role === null || !self::installed()) {
            return 0;
        }
        $scope = self::unread_scope($role, $sessionId, $bookingId);
        if ($scope === null) {
            return 0;
        }
        try {
            $stmt = $db->prepare(
                'SELECT m.deal_id FROM tblvendor_messages m ' . $scope['joins']
                . ' WHERE ' . implode(' AND ', $scope['where'])
                . ' ORDER BY m.created_at ASC, m.id ASC LIMIT 1'
            );
            $stmt->execute($scope['params']);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The one definition of "unread for this role": the joins, predicates and
     * bound values shared by the badge count and the badge's destination. The
     * ownership rules are the pages' own (a couple's booking, a vendor's
     * listings, a planner's scope), which is why a badge can never point at a
     * deal its reader would be refused when they click it.
     *
     * @return array{joins:string,where:string[],params:array<string,mixed>}|null
     *         null when there is no role/table to ask about
     */
    private static function unread_scope(string $role, int $sessionId, int $bookingId): ?array
    {
        $sessionId = $sessionId > 0 ? $sessionId : (int) ($_SESSION['id'] ?? 0);
        $column    = self::read_column($role);
        if ($sessionId <= 0 || $column === null) {
            return null;
        }

        $joins  = "INNER JOIN tblvendor_bookings vb ON vb.id = m.deal_id
                   INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id";
        $params = [':role' => $role];
        $where  = ["m.author_role <> :role", "m.$column IS NULL"];

        if ($role === self::VENDOR) {
            $joins .= "
                   INNER JOIN tblvendors v ON v.id = vb.vendor_id";
            $where[]       = 'v.user_id = :me';
            $params[':me'] = $sessionId;
        } elseif ($role === self::COUPLE) {
            $where[]       = 'b.user_id = :me';
            $params[':me'] = $sessionId;
            if ($bookingId > 0) {
                $where[]        = 'vb.booking_id = :bid';
                $params[':bid'] = $bookingId;
            }
        } elseif (function_exists('planner_scope_sql')) {
            $plannerScope = planner_scope_sql('b.organizer_id');
            if ($plannerScope['sql'] !== '') {
                $where[] = $plannerScope['sql'];
                $params += $plannerScope['params'];
            }
        }

        return ['joins' => $joins, 'where' => $where, 'params' => $params];
    }

    /**
     * Mark everything the OTHER roles wrote on this deal as read for $role.
     * Returns the number of messages newly stamped (what the badge just lost).
     */
    public static function mark_read(int $dealId, string $role): int
    {
        global $db;
        $column = self::read_column($role);
        if ($dealId <= 0 || $column === null || !self::installed()) {
            return 0;
        }
        $stmt = $db->prepare(
            "UPDATE tblvendor_messages SET $column = NOW()
              WHERE deal_id = :id AND author_role <> :role AND $column IS NULL"
        );
        $stmt->execute([':id' => $dealId, ':role' => $role]);
        return (int) $stmt->rowCount();
    }

    // =====================================================================
    // ESCALATION — the thread that went quiet
    // =====================================================================

    /**
     * How long a vendor may leave the newest message unanswered before the
     * couple's planner is told. A day: long enough that a Saturday enquiry is
     * not an incident on Sunday, short enough that the couple's week is not
     * spent waiting for a reply they were promised "shortly".
     */
    public const REPLY_GRACE_HOURS = 24;

    /**
     * Is the v2.26 escalation stamp installed? Same contract as installed():
     * asked once per request, answered false rather than throwing on a database
     * that predates the migration. Without it the sweep reports NOOP and the
     * ledger still shows its "waiting 3 h" labels — the column only records who
     * has already been told, so nothing a couple sees depends on it.
     */
    public static function escalation_installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }
        global $db;
        try {
            $db->execute('SELECT escalated_at FROM tblvendor_messages LIMIT 1');
            $installed = true;
        } catch (Throwable $e) {
            $installed = false;
        }
        return $installed;
    }

    /**
     * Deals whose vendor has left the conversation unanswered for too long —
     * the queue scripts/thread_escalation.php works through.
     *
     * The trigger is the NEWEST message on the thread, not the unread flag: if
     * the vendor has not written since, nobody has answered, whether or not they
     * opened the page. That is deliberate — reading a couple's question and not
     * replying is the case most worth telling their planner about.
     *
     * A deal is offered once per unanswered message (`escalated_at`): the sweep
     * claims the row before it rings, so a re-run or a second pinger is silent,
     * and a later message is a new row that can be escalated again when IT has
     * waited a day. Deals that no longer need an answer — completed, cancelled,
     * a wedding already held — are never escalated: chasing a reply for a day
     * that has passed is noise, not service.
     *
     * @return object[] one row per deal: deal/booking/vendor ids, organizer_id,
     *                  business_name, bride, groom, wedding_date, message_id,
     *                  author_role, body, waiting_since — oldest wait first
     */
    public static function stale_deals(int $hours = self::REPLY_GRACE_HOURS, int $limit = 100): array
    {
        global $db;
        if (!self::installed() || !self::escalation_installed()) {
            return [];
        }
        try {
            return $db->fetchAll(
                "SELECT vb.id AS deal_id, vb.booking_id, vb.vendor_id,
                        b.organizer_id, b.bride, b.groom, b.wedding_date,
                        v.business_name,
                        m.id AS message_id, m.author_role, m.body, m.created_at AS waiting_since
                   FROM tblvendor_messages m
             INNER JOIN (SELECT deal_id, MAX(id) AS newest
                           FROM tblvendor_messages GROUP BY deal_id) n
                     ON n.deal_id = m.deal_id AND n.newest = m.id
             INNER JOIN tblvendor_bookings vb ON vb.id = m.deal_id
             INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id
             INNER JOIN tblvendors v ON v.id = vb.vendor_id
                  WHERE m.author_role <> 'vendor'
                    AND m.escalated_at IS NULL
                    AND TIMESTAMPDIFF(HOUR, m.created_at, NOW()) >= :hours
                    AND vb.status IN ('pending', 'confirmed')
                    AND b.wedding_date >= CURDATE()
                    AND b.organizer_id > 0
               ORDER BY m.created_at ASC, m.id ASC
                  LIMIT :lim",
                [':hours' => max(1, $hours), ':lim' => max(1, $limit)]
            );
        } catch (Throwable $e) {
            error_log('[WPMS][VendorThread] escalation queue unreadable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Claim one message for escalation. TRUE means this run is the one that
     * should ring the planner: the row was still unstamped, so no other run —
     * a second pinger, an overlapping cron — has told them. Claim first, ring
     * second; the reverse would send the same bell twice on a race.
     */
    public static function mark_escalated(int $messageId): bool
    {
        global $db;
        if ($messageId <= 0 || !self::escalation_installed()) {
            return false;
        }
        $stmt = $db->prepare(
            'UPDATE tblvendor_messages SET escalated_at = NOW()
              WHERE id = :id AND escalated_at IS NULL'
        );
        $stmt->execute([':id' => $messageId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * What the planner is told, in one sentence a phone can show: who has
     * waited, how long, on whom — and what to do next. $now is injectable so
     * the wording is testable without waiting a day.
     */
    public static function escalation_message(object $row, ?int $now = null): string
    {
        $couple = trim((string) ($row->bride ?? '') . ' & ' . (string) ($row->groom ?? ''), ' &');
        $couple = $couple !== '' ? $couple : 'A couple';
        $vendor = trim((string) ($row->business_name ?? '')) ?: 'their vendor';
        $waited = self::waiting_for((string) ($row->waiting_since ?? ''), $now);
        $waited = $waited !== '' ? $waited : 'over a day';

        return $couple . ' have been waiting ' . $waited . ' for ' . $vendor
            . ' to reply. Open the thread and give them a nudge.';
    }

    // =====================================================================
    // WRITE
    // =====================================================================

    /**
     * Add $role's message to a deal's thread and notify the other two parties.
     *
     * The deal is re-read here (never trusted from the caller) so the author's
     * display name, the couple's names and the notification recipients all come
     * from the database in one place. Returns the new message id, or 0 when the
     * body is empty / too long / the deal does not exist.
     *
     * @param int    $authorId tblaccounts.user_id for a couple, tblusers.id otherwise
     */
    public static function post(int $dealId, string $role, int $authorId, string $body): int
    {
        global $db;
        if ($dealId <= 0 || !in_array($role, self::ROLES, true) || !self::installed()) {
            return 0;
        }
        $body = self::clean_body($body);
        if ($body === '') {
            return 0;
        }
        $deal = self::find($dealId);
        if ($deal === null) {
            return 0;
        }

        $name = self::author_name($deal, $role, $authorId);
        $stmt = $db->prepare(
            'INSERT INTO tblvendor_messages
                (deal_id, author_role, author_id, author_name, body, read_couple_at, read_vendor_at, read_planner_at)
             VALUES (:deal, :role, :aid, :name, :body, ' . self::own_stamp_sql($role) . ')'
        );
        $stmt->execute([
            ':deal' => $dealId,
            ':role' => $role,
            ':aid'  => $authorId > 0 ? $authorId : 0,
            ':name' => $name,
            ':body' => $body,
        ]);
        $messageId = (int) $db->the_insert_id();
        if ($messageId <= 0) {
            return 0;
        }

        self::notify($deal, $role, $body);
        return $messageId;
    }

    /**
     * Trim a message and cap it at MAX_BODY characters. Control characters are
     * stripped (a pasted spreadsheet column should not arrive as tabs and
     * never-ending whitespace); newlines are kept, because people write lists.
     */
    public static function clean_body(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $body) ?? $body;
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        return mb_substr($body, 0, self::MAX_BODY);
    }

    // =====================================================================
    // THE NEXT STEP
    // =====================================================================

    /**
     * Whose move is it, said in the viewer's own terms.
     *
     * Derived from the deal's status and the last message's author — never
     * stored, so it cannot go stale. A confirmed deal is "booked" for
     * everybody even if the vendor spoke last; a pending deal whose last word
     * came from the couple is "waiting on the vendor" for them and "reply
     * needed" for the vendor, which is the entire convenience being sold.
     *
     * @param object   $deal     needs status, business_name, bride, groom
     * @param object[] $messages the thread, oldest first (for_deal)
     * @return array{key:string, owner:?string, label:string, detail:string, icon:string, tone:string}
     */
    public static function stage(object $deal, array $messages, string $role): array
    {
        $vendor = trim((string) ($deal->business_name ?? '')) ?: 'the vendor';
        $couple = trim((string) ($deal->bride ?? '') . ' & ' . (string) ($deal->groom ?? ''), ' &');
        $couple = $couple !== '' ? $couple : 'the couple';
        $status = (string) ($deal->status ?? 'pending');
        $last   = $messages !== [] ? (string) ($messages[count($messages) - 1]->author_role ?? '') : '';

        $build = static function (string $key, ?string $owner, string $label, string $detail, string $icon, string $tone): array {
            return ['key' => $key, 'owner' => $owner, 'label' => $label, 'detail' => $detail, 'icon' => $icon, 'tone' => $tone];
        };

        if ($status === 'confirmed') {
            $amount = (float) ($deal->amount ?? 0);
            $money  = $amount > 0
                ? (function_exists('format_ksh') ? format_ksh($amount) : 'KSh ' . number_format($amount, 2))
                : 'the agreed amount';
            return $build(
                'booked',
                null,
                'Booked',
                $role === self::COUPLE
                    ? $vendor . ' is confirmed for your day. Anything you need to agree — timings, numbers, access — say it here and your planner sees it too.'
                    : ($role === self::VENDOR
                        ? 'You are booked with ' . $couple . '. Keep the planner in the loop here rather than over the phone.'
                        : 'Deal confirmed at ' . $money . '. ' . $couple . ' and ' . $vendor . ' both see this thread.'),
                'check-decagram',
                'ok'
            );
        }
        if ($status === 'completed') {
            return $build(
                'wrapped',
                null,
                'Wrapped up',
                'The wedding is done and this deal is closed. The conversation stays here for your records.',
                'certificate',
                'ok'
            );
        }
        if ($status === 'cancelled') {
            return $build(
                'closed',
                null,
                'Closed',
                $role === self::COUPLE
                    ? 'This enquiry was closed. Your planner can help you find another ' . strtolower((string) (($deal->category ?? '') !== '' ? $deal->category : 'vendor')) . '.'
                    : 'This deal was closed. Reopen it on the ledger if things change.',
                'close-circle',
                'muted'
            );
        }

        // Pending — the status where whose-turn actually matters.
        return match ($role) {
            self::COUPLE => match ($last) {
                self::VENDOR  => $build('reply', self::COUPLE, 'Your turn', $vendor . ' replied. Say yes, ask a question, or leave it to your planner — they can see everything here.', 'message-reply-text', 'action'),
                self::PLANNER => $build('reply', self::COUPLE, 'Your planner replied', 'Your planner answered on this enquiry. Add anything you want ' . $vendor . ' to know.', 'account-voice', 'action'),
                self::COUPLE  => $build('waiting', self::VENDOR, 'Waiting on ' . $vendor, 'Your message is with ' . $vendor . ', and your planner is copied on it. Nothing is needed from you right now.', 'clock-outline', 'wait'),
                default       => $build('waiting', self::VENDOR, 'Sent — waiting on the vendor', 'Your planner has been told and is following up with ' . $vendor . '. You can add anything else here in the meantime.', 'clock-outline', 'wait'),
            },
            self::VENDOR => $last === self::VENDOR
                ? $build('waiting', self::COUPLE, 'Waiting on ' . $couple, 'Your reply is with ' . $couple . ' and their planner. You will be told the moment they answer.', 'clock-outline', 'wait')
                : $build('reply', self::VENDOR, 'Reply needed', $last === ''
                    ? $couple . ' asked about your services. Confirm availability and a price here — their planner is copied automatically.'
                    : $couple . ' is waiting on you. Answer here and both the couple and their planner see it at once.', 'message-reply-text', 'action'),
            self::PLANNER => match ($last) {
                self::COUPLE  => $build('reply', self::PLANNER, 'Follow up with ' . $vendor, $couple . ' wrote to ' . $vendor . '. Nudge the vendor here so the couple sees progress without ringing you.', 'message-reply-text', 'action'),
                self::VENDOR  => $build('reply', self::PLANNER, 'Loop the couple in', $vendor . ' replied. Answer here and ' . $couple . ' reads it immediately — no relay call needed.', 'message-reply-text', 'action'),
                self::PLANNER => $build('waiting', self::VENDOR, 'Ball is with them', 'Your note is with ' . $couple . ' and ' . $vendor . '. You will see their reply here.', 'clock-outline', 'wait'),
                default       => $build('reply', self::PLANNER, 'Introduce yourself', $couple . ' enquired with ' . $vendor . '. Send one line so the vendor knows who is handling the booking.', 'account-voice', 'action'),
            },
            default => $build('waiting', null, 'Open', 'This enquiry is open.', 'clock-outline', 'wait'),
        };
    }

    /**
     * One-tap replies, per role. Deliberately short and in the register each
     * side actually writes in — the point is that a busy vendor answers in one
     * click from a phone, and a couple never has to compose a "how do I ask
     * this" message.
     *
     * @return array<string, string[]> label => text (the label is the text)
     */
    public static function quick_replies(string $role): array
    {
        return match ($role) {
            self::COUPLE => [
                'Is our date still available?',
                'Could you send a written quote?',
                'Can we visit or call this week?',
                'That works for us, thank you.',
            ],
            self::VENDOR => [
                'Yes, your date is free.',
                'Our price depends on your final numbers — shall we talk?',
                'Could we have a quick call to confirm the details?',
                'Sorry, we are already booked that day.',
            ],
            self::PLANNER => [
                'Hello from the planner — I am coordinating this booking.',
                'Please send your written quote so we can compare.',
                'Can you hold the date for 48 hours?',
                'The quote is approved — please confirm the booking.',
            ],
            default => [],
        };
    }

    /**
     * The couple's original enquiry, recovered from the deal's notes for the
     * thread's opening bubble.
     *
     * `notes` is a trail: the couple's message first, then bracketed system
     * lines prepended by confirmations and availability flags. Those bracketed
     * lines are the ledger's audit trail and stay there — this only returns the
     * human part, so a deal that predates v2.25 still opens with the couple's
     * own words instead of an empty box.
     */
    public static function original_brief(?string $notes, int $max = 600): string
    {
        $lines = preg_split('/\R/', (string) $notes) ?: [];
        $kept  = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[/', $line)) {
                continue; // a system/ledger line, not something a person wrote
            }
            $kept[] = rtrim($line);
        }
        $text = trim(implode("\n", $kept));
        if ($text === '') {
            return '';
        }
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    // =====================================================================
    // WHO MAY ACT
    // =====================================================================

    /**
     * Which side of a deal this session is, or null for a session that is
     * none of them. The three predicates answer in the order that matters: a
     * planner is staff even though their id space matches a vendor's, and a
     * vendor is a tblusers row that must never be mistaken for a couple.
     */
    public static function session_role(): ?string
    {
        if ((int) ($_SESSION['id'] ?? 0) <= 0) {
            return null;
        }
        if (function_exists('is_staff') && is_staff()) {
            return self::PLANNER;
        }
        if (function_exists('is_vendor') && is_vendor()) {
            return self::VENDOR;
        }
        if (function_exists('is_client_account') && is_client_account()) {
            return self::COUPLE;
        }
        return null;
    }

    /**
     * Resolve a deal this session may act on AS $role, or null.
     *
     * The one gate for both doors into a thread (writing a message and marking
     * it read), so the two can never disagree about who owns a deal:
     *   - a couple must be the account the booking belongs to;
     *   - a vendor must own the listing the deal was made with (any of them —
     *     an account can run more than one business);
     *   - a planner must be able to open the booking at all, which is the same
     *     `booking_visible_to_session()` rule every page and endpoint uses, so
     *     per-planner scoping and the super-admin bypass are not re-derived.
     *
     * $role is re-checked against the session, never trusted from the request.
     */
    public static function authorize(int $dealId, string $role, int $sessionId): ?object
    {
        if ($dealId <= 0 || $sessionId <= 0 || self::session_role() !== $role) {
            return null;
        }
        $deal = self::find($dealId);
        if ($deal === null) {
            return null;
        }
        if ($role === self::COUPLE) {
            return (int) $deal->couple_user_id === $sessionId ? $deal : null;
        }
        if ($role === self::VENDOR) {
            foreach (Vendor::all_for_user($sessionId) as $listing) {
                if ((int) $listing->id === (int) $deal->vendor_id) {
                    return $deal;
                }
            }
            return null;
        }
        if ($role === self::PLANNER) {
            $booking = Booking::find_by_booking_id((int) $deal->booking_id);
            return booking_visible_to_session($booking) ? $deal : null;
        }
        return null;
    }

    /**
     * Where this role reads this deal, with the row's anchor so the browser
     * lands on it. Extra query parameters are merged AFTER the identity ones,
     * so a caller can add a filter or open the thread but can never re-point
     * the link at another booking, couple or vendor.
     */
    public static function landing(object $deal, string $role, array $extra = []): string
    {
        $dealId = (int) ($deal->id ?? 0);
        [$base, $query] = match ($role) {
            self::COUPLE  => ['client_vendors.php', ['booking_id' => (int) ($deal->booking_id ?? 0), 'user_id' => (int) ($deal->couple_user_id ?? 0)]],
            self::VENDOR  => ['vendor_home.php', ['vendor_id' => (int) ($deal->vendor_id ?? 0)]],
            self::PLANNER => ['vendor_enquiries.php', []],
            default       => ['', []],
        };
        if ($base === '') {
            return '';
        }
        $query += $extra;
        $url = $base . ($query !== [] ? '?' . http_build_query($query) : '');
        return $dealId > 0 ? $url . '#deal-' . $dealId : $url;
    }

    /**
     * The link that opens + marks read one thread (see vendor_thread_open.php).
     *
     * $extra carries the VIEW the reader is working in — the planner's queue
     * filter, for instance — so the opener can return them to it after the
     * thread is read instead of dumping them on the unfiltered ledger. The
     * opener whitelists what it accepts before building the redirect.
     */
    public static function opener_url(int $dealId, array $extra = []): string
    {
        return 'vendor_thread_open.php?deal_id=' . $dealId
            . ($extra !== [] ? '&' . http_build_query($extra) : '');
    }

    // =====================================================================
    // INTERNALS
    // =====================================================================

    /** The read-stamp column for a role, or null for an unknown role. */
    private static function read_column(string $role): ?string
    {
        return match ($role) {
            self::COUPLE  => 'read_couple_at',
            self::VENDOR  => 'read_vendor_at',
            self::PLANNER => 'read_planner_at',
            default       => null,
        };
    }

    /** The author's own read stamp is set at insert: you have read what you wrote. */
    private static function own_stamp_sql(string $role): string
    {
        return match ($role) {
            self::COUPLE  => 'NOW(), NULL, NULL',
            self::VENDOR  => 'NULL, NOW(), NULL',
            self::PLANNER => 'NULL, NULL, NOW()',
            default       => 'NULL, NULL, NULL',
        };
    }

    /**
     * The deal plus the names every caller needs, read fresh. One query: the
     * notification and the bubble text must agree with what the ledger shows.
     *
     * Public because the POST endpoint and the opener authorize against exactly
     * this row rather than against a caller-supplied one.
     */
    public static function find(int $dealId): ?object
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT vb.*, v.business_name, v.category, v.user_id AS vendor_user_id,
                    b.bride, b.groom, b.user_id AS couple_user_id, b.organizer_id
               FROM tblvendor_bookings vb
               INNER JOIN tblvendors v ON v.id = vb.vendor_id
               INNER JOIN tblweddingbook b ON b.booking_id = vb.booking_id
              WHERE vb.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $dealId]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /** What goes on the bubble: the couple by their names, the vendor by their business. */
    private static function author_name(object $deal, string $role, int $authorId): string
    {
        global $db;
        if ($role === self::COUPLE) {
            $name = trim((string) ($deal->bride ?? '') . ' & ' . (string) ($deal->groom ?? ''), ' &');
            return mb_substr($name !== '' ? $name : 'The couple', 0, 120);
        }
        if ($role === self::VENDOR) {
            return mb_substr(trim((string) ($deal->business_name ?? '')) ?: 'The vendor', 0, 120);
        }
        try {
            $stmt = $db->prepare('SELECT firstname, lastname FROM tblusers WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $authorId]);
            $row = $stmt->fetch(PDO::FETCH_OBJ);
            $name = $row !== false ? trim((string) $row->firstname . ' ' . (string) $row->lastname) : '';
        } catch (Throwable $e) {
            $name = '';
        }
        return mb_substr($name !== '' ? $name : 'Your planner', 0, 120);
    }

    /**
     * In-app bells for the two parties who did not write the message.
     *
     * Best-effort by design: a message that was stored must never be lost
     * because a notification insert failed, so the loop catches per recipient.
     * Each recipient gets a link that opens the page holding their panel, with
     * the deal's anchor so the browser lands on the right row.
     */
    private static function notify(object $deal, string $authorRole, string $body): void
    {
        $dealId   = (int) $deal->id;
        $booking  = (int) $deal->booking_id;
        $vendor   = (string) ($deal->business_name ?? 'the vendor');
        $couple   = trim((string) ($deal->bride ?? '') . ' & ' . (string) ($deal->groom ?? ''), ' &');
        $couple   = $couple !== '' ? $couple : 'A couple';
        $excerpt  = mb_strlen($body) > 140 ? mb_substr($body, 0, 139) . '…' : $body;
        /*
         * The bell goes through the opener, not straight at the ledger: one
         * click clears that role's badge AND expands the thread, which is what
         * "new message" promises. The opener authorises again on the way.
         */
        $link     = static fn(string $role): string => self::opener_url($dealId);

        $deliveries = [];
        if ($authorRole !== self::COUPLE && (int) $deal->couple_user_id > 0) {
            $deliveries[] = [self::COUPLE, (int) $deal->couple_user_id];
        }
        if ($authorRole !== self::VENDOR && (int) $deal->vendor_user_id > 0) {
            $deliveries[] = [self::VENDOR, (int) $deal->vendor_user_id];
        }
        if ($authorRole !== self::PLANNER && (int) $deal->organizer_id > 0) {
            $deliveries[] = [self::PLANNER, (int) $deal->organizer_id];
        }

        foreach ($deliveries as [$role, $recipientId]) {
            /*
             * The line has to read well when the vendor is the author too, so
             * the enquiry being written on is named by WHO ELSE is on it: a
             * vendor's message is "on the couple's enquiry", not "on the
             * {vendor} enquiry" with their own business named twice.
             */
            $writer = match ($authorRole) {
                self::COUPLE  => $couple . ' (couple)',
                self::VENDOR  => $vendor . ' (vendor)',
                self::PLANNER => 'Your planner',
                default       => 'Someone',
            };
            $about = match ($authorRole) {
                self::COUPLE  => $vendor,
                self::VENDOR  => $couple,
                default       => $couple . ' / ' . $vendor,
            };
            $text = sprintf('%s wrote on the %s enquiry: “%s”', $writer, $about, $excerpt);
            try {
                if ($role === self::COUPLE) {
                    Notification::create_for_couple($recipientId, $booking, $text, $link($role), 'New message — ' . $vendor);
                } elseif ($role === self::VENDOR) {
                    Notification::create_for_vendor($recipientId, $booking, $text, $link($role), 'New message — ' . $couple);
                } else {
                    Notification::create_for_staff($recipientId, $booking, $text, $link($role), 'New message — ' . $couple . ' / ' . $vendor);
                }
            } catch (Throwable $e) {
                // The message is already stored; a bell that cannot be written
                // must not turn a successful reply into an error page.
            }
        }
    }
}
