<?php
declare(strict_types=1);

class Guest extends DB_Object
{
    protected static $db_table = "tblguest";
    protected static $db_table_fields = array(
        'id',
        'booking_id',
        'fullname',
        'guestname',
        'address',
        'state',
        'zipcode',
        'priority',
        'out_of_town',
        'relationship',
        'tracks_and_gifts',
        'city',
        'upcountry_accommodation',
        'harambee_contribution',
        'dietary_requirements',
        'transport_needed',
        'phone',
        'rsvp_token',
        'rsvp_status',
        'rsvp_at',
        'rsvp_note',
        'rsvp_invited_at',
        'rsvp_reminded_at',
        'rsvp_reminders'
    );

    public $id;
    public $booking_id;
    public $fullname;
    public $guestname;
    public $address;
    public $state;
    public $zipcode;
    public $priority;
    public $out_of_town;
    public $relationship;
    public $tracks_and_gifts;
    public $city;
    public $upcountry_accommodation;
    public $harambee_contribution;
    public $dietary_requirements;
    public $transport_needed;
    public $phone;
    public $rsvp_token;
    public $rsvp_status;
    public $rsvp_at;
    public $rsvp_note;
    /** When this guest was invited — reminder windows count from here. */
    public $rsvp_invited_at;
    /** Defaults matter: the ORM binds every declared property on create(). */
    public $rsvp_reminded_at = null;
    public $rsvp_reminders = 0;

    public static function getGuest(mixed $booking_id): array
    {
        global $db;
        $sql  = "SELECT * FROM tblguest WHERE booking_id = :bid ORDER BY priority ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function count_guest(mixed $id): int
    {
        global $db;
        $sql  = "SELECT count(booking_id) FROM " . static::$db_table . " WHERE booking_id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => (int) $id]);
        return (int) $stmt->fetchColumn();
    }

    public static function get_upcountry_guests(mixed $booking_id): array
    {
        global $db;
        $sql  = "SELECT * FROM tblguest WHERE booking_id = :bid AND (out_of_town = 'y' OR transport_needed = 1)";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        $arr = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = static::instantiation($row);
        }
        return $arr;
    }

    public static function total_harambee(mixed $booking_id): float
    {
        global $db;
        $sql  = "SELECT SUM(harambee_contribution) FROM " . static::$db_table . " WHERE booking_id = :bid";
        $stmt = $db->prepare($sql);
        $stmt->execute([':bid' => (int) $booking_id]);
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /** RSVP tallies for one booking: counts per status (pending included). */
    public static function rsvp_tally(mixed $booking_id): array
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT rsvp_status, COUNT(*) AS n FROM tblguest WHERE booking_id = :bid GROUP BY rsvp_status'
        );
        $stmt->execute([':bid' => (int) $booking_id]);
        $tally = ['pending' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = $row['rsvp_status'] ?? 'pending';
            $tally[$status] = (int) $row['n'];
        }
        return $tally;
    }

    /** The guest owning an RSVP token (public RSVP page lookup), or null. */
    public static function find_by_rsvp_token(string $token): ?Guest
    {
        global $db;
        $token = preg_replace('/[^a-f0-9]/', '', $token) ?? '';
        if (strlen($token) !== 32) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM tblguest WHERE rsvp_token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? static::instantiation($row) : null;
    }

    /**
     * Record an RSVP answer through the public token flow. Status is
     * validated against the enum; note capped to the column size. Returns
     * true when a row was updated.
     */
    public static function record_rsvp(string $token, string $status, string $note = ''): bool
    {
        global $db;
        if (!in_array($status, ['yes', 'no', 'maybe'], true)) {
            return false;
        }
        $token = preg_replace('/[^a-f0-9]/', '', $token) ?? '';
        if (strlen($token) !== 32) {
            return false;
        }
        $stmt = $db->prepare(
            'UPDATE tblguest SET rsvp_status = :s, rsvp_at = NOW(), rsvp_note = :note '
            . 'WHERE rsvp_token = :t'
        );
        $stmt->execute([
            ':s'    => $status,
            ':note' => mb_substr(trim($note), 0, 255),
            ':t'    => $token,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Ensure every guest of a booking has an RSVP token; returns tokens created.
     *  Token minting IS the invite: the same moment stamps rsvp_invited_at,
     *  which reminder windows count from (not the wedding date). */
    public static function ensure_tokens(mixed $booking_id): int
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT id FROM tblguest WHERE booking_id = :bid AND (rsvp_token IS NULL OR rsvp_token = "")'
        );
        $stmt->execute([':bid' => (int) $booking_id]);
        $missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $upd = $db->prepare(
            'UPDATE tblguest SET rsvp_token = :t, rsvp_invited_at = COALESCE(rsvp_invited_at, NOW()) WHERE id = :id'
        );
        $n = 0;
        foreach ($missing as $id) {
            $upd->execute([':t' => bin2hex(random_bytes(16)), ':id' => (int) $id]);
            $n++;
        }
        return $n;
    }

    /**
     * Mark guests as invited at an explicit moment (planner sent invites,
     * guest list imported, etc.). Only fills gaps — never overwrites an
     * existing invite timestamp — and always mints tokens, since a guest
     * cannot be invited without one.
     *
     * @param array<int, int|string> $guest_ids
     */
    public static function mark_invited(array $guest_ids, ?int $booking_id = null): int
    {
        global $db;
        $guest_ids = array_values(array_filter(array_map('intval', $guest_ids)));
        if ($guest_ids === []) {
            return 0;
        }
        // NB: PDO forbids mixing named (:x) and positional (?) parameters in
        // one statement (HY093) — build named placeholders for the id list.
        $idMarks = [];
        $params = [
            ':t'   => bin2hex(random_bytes(16)),
            ':now' => date('Y-m-d H:i:s'),
        ];
        foreach ($guest_ids as $i => $gid) {
            $idMarks[]      = ':g' . $i;
            $params[':g' . $i] = $gid;
        }
        $sql = "UPDATE tblguest
                SET rsvp_token = COALESCE(NULLIF(rsvp_token, ''), :t),
                    rsvp_invited_at = COALESCE(rsvp_invited_at, :now)
                WHERE id IN (" . implode(',', $idMarks) . ')'
            . ($booking_id !== null ? ' AND booking_id = :bid' : '');
        if ($booking_id !== null) {
            $params[':bid'] = $booking_id;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Pending guests whose reminder window has arrived.
     *
     * Timing is INVITE-RELATIVE: the clock starts at the guest's invite
     * (rsvp_invited_at — stamped when their token was minted, or explicitly
     * via mark_invited()), NOT at the wedding date. A reminder is due when
     * the guest:
     *  - was invited at least $remind_after_days ago,
     *  - belongs to a CONFIRMED booking whose wedding has not passed
     *    ($grace_days of post-wedding grace covers events still settling),
     *  - has not replied (rsvp_status still 'pending'), and
     *  - was never reminded, or was last reminded $resend_days+ days ago,
     *    with a lifetime cap of $max_reminders per guest.
     *
     * Guests without an invite timestamp never qualify (no invite, no nag).
     *
     * @return array<int, object> rows with booking + couple context attached
     */
    public static function find_reminder_due(
        int $remind_after_days = 7,
        int $resend_days = 7,
        int $max_reminders = 3,
        int $grace_days = 7
    ): array {
        global $db;
        $sql = "SELECT g.id, g.booking_id, g.fullname, g.phone, g.rsvp_token,
                       g.rsvp_status, g.rsvp_invited_at, g.rsvp_reminded_at, g.rsvp_reminders,
                       b.wedding_date, b.bride, b.groom, b.organizer_id,
                       d.firstname, d.lastname, d.user_id AS couple_user_id
                FROM tblguest g
                INNER JOIN tblweddingbook b ON b.booking_id = g.booking_id
                INNER JOIN tblaccounts_detail d ON d.user_id = b.user_id
                INNER JOIN tblaccounts a ON a.user_id = b.user_id
                WHERE g.rsvp_status = 'pending'
                  AND d.status = 'confirm'
                  AND g.rsvp_reminders < :maxr
                  AND g.rsvp_token IS NOT NULL
                  AND g.rsvp_invited_at IS NOT NULL
                  AND g.rsvp_invited_at <= DATE_SUB(NOW(), INTERVAL :afterdays DAY)
                  AND (g.rsvp_reminded_at IS NULL
                       OR g.rsvp_reminded_at <= DATE_SUB(NOW(), INTERVAL :resend DAY))
                  AND b.wedding_date >= DATE_SUB(CURDATE(), INTERVAL :grace DAY)
                ORDER BY b.wedding_date ASC, g.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':maxr'      => $max_reminders,
            ':resend'    => $resend_days,
            ':afterdays' => $remind_after_days,
            ':grace'     => $grace_days,
        ]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** Record that a reminder went out (timestamp + count bump). */
    public static function mark_reminded(int $guest_id): bool
    {
        global $db;
        $stmt = $db->prepare(
            'UPDATE tblguest SET rsvp_reminded_at = NOW(), rsvp_reminders = rsvp_reminders + 1 WHERE id = :id'
        );
        return $stmt->execute([':id' => $guest_id]);
    }
}
