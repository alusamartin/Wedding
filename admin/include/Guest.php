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

    /** Ensure every guest of a booking has an RSVP token; returns tokens created. */
    public static function ensure_tokens(mixed $booking_id): int
    {
        global $db;
        $stmt = $db->prepare(
            'SELECT id FROM tblguest WHERE booking_id = :bid AND (rsvp_token IS NULL OR rsvp_token = "")'
        );
        $stmt->execute([':bid' => (int) $booking_id]);
        $missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $upd = $db->prepare('UPDATE tblguest SET rsvp_token = :t WHERE id = :id');
        $n = 0;
        foreach ($missing as $id) {
            $upd->execute([':t' => bin2hex(random_bytes(16)), ':id' => (int) $id]);
            $n++;
        }
        return $n;
    }

    /**
     * Pending guests whose reminder window has arrived.
     *
     * A reminder is due when the guest:
     *  - belongs to a CONFIRMED booking whose wedding is within the window
     *    (today..$min_days_before days out — never for pending/cancelled
     *    bookings or past weddings),
     *  - has not replied (rsvp_status still 'pending'), and
     *  - was never reminded, or was last reminded $resend_days+ days ago,
     *    with a lifetime cap of $max_reminders per guest.
     *
     * @return array<int, object> rows with booking + couple context attached
     */
    public static function find_reminder_due(
        int $min_days_before = 30,
        int $resend_days = 7,
        int $max_reminders = 3
    ): array {
        global $db;
        $sql = "SELECT g.id, g.booking_id, g.fullname, g.phone, g.rsvp_token,
                       g.rsvp_status, g.rsvp_reminded_at, g.rsvp_reminders,
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
                  AND (g.rsvp_reminded_at IS NULL
                       OR g.rsvp_reminded_at <= DATE_SUB(NOW(), INTERVAL :resend DAY))
                  AND b.wedding_date >= CURDATE()
                  AND b.wedding_date <= DATE_ADD(CURDATE(), INTERVAL :mindays DAY)
                ORDER BY b.wedding_date ASC, g.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':maxr'    => $max_reminders,
            ':resend'  => $resend_days,
            ':mindays' => $min_days_before,
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
