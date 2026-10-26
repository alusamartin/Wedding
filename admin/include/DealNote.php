<?php
declare(strict_types=1);

/**
 * The words that come with a "no" on a deal (v3.3).
 *
 * WHY THIS EXISTS
 *   Accept and Decline used to be equally silent: both moved a status, and only
 *   one of them needed explaining. A grey "Cancelled" badge leaves the couple
 *   unable to tell "we were too expensive for them" from "they are already
 *   booked that Saturday" — and the second is still a booking, one phone call
 *   away, while the first is a dead end. Their planner reads the same badge and
 *   has to ring the vendor to find out which one it was.
 *
 *   So both people who can drop a deal get one optional line when they do — the
 *   vendor declining an enquiry, and the planner cancelling one — and every
 *   surface that shows a cancelled deal shows that line, escaped.
 *
 * THE RULE
 *   `tblvendor_bookings.status_note` holds the words on the answer that is
 *   CURRENT: written when either side cancels or declines, cleared by any other
 *   answer so a stale "already booked that day" can never sit beside a
 *   confirmed booking. `status_note_by` says which side said it, because the
 *   couple reading "Vendor said" about something their planner decided would be
 *   a lie — and it is a different phone call to a different person.
 *   `notes` keeps its job as the append-only audit trail — the dated line
 *   written at decline time includes the reason, so clearing the column never
 *   erases what was said.
 *
 * The columns are OPTIONAL: an install that has not run v3.3 keeps working, and
 * every reader and writer asks installed() / author_installed() first rather
 * than naming a column the database does not have. A missing `status_note_by`
 * reads as 'vendor', which is what every pre-v3.3b reason was.
 */
final class DealNote
{
    /** What the input accepts (and the column is comfortably sized for). */
    public const MAX = 200;

    /** The two people who can drop a deal, as stored in status_note_by. */
    public const VENDOR  = 'vendor';
    public const PLANNER = 'planner';

    /**
     * Is the v3.3 column there? One cached probe per request, the same shape
     * VendorThread::installed() and VendorService::installed() use.
     */
    public static function installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }
        global $db;
        try {
            $db->fetchValue('SELECT status_note FROM tblvendor_bookings LIMIT 1');
            $installed = true;
        } catch (Throwable $e) {
            $installed = false;
        }
        return $installed;
    }

    /**
     * Turn a posted reason into the line the database stores.
     *
     * Tags are stripped rather than escaped here because this text travels to
     * places that are not HTML (the SMS, the notification feed, the notes
     * trail); every HTML surface escapes again at print time. Newlines and runs
     * of spaces collapse to one space — it is a sentence, not a paragraph — and
     * an over-long reason is cut at MAX, because the input is a courtesy, not a
     * place to paste an email.
     */
    public static function clean(string $raw): string
    {
        /*
         * Repair the encoding BEFORE the whitespace pass. `preg_replace('/\s+/u')`
         * returns NULL on invalid UTF-8 — one cp1252 byte pasted from a terminal
         * or posted by a client that guessed the charset was enough to turn the
         * whole reason into '' and store nothing, silently. Converting UTF-8 to
         * UTF-8 replaces the bad bytes and leaves the rest of the sentence alone,
         * which is what a person typing around one odd character expects.
         */
        $note = mb_convert_encoding(strip_tags($raw), 'UTF-8', 'UTF-8');
        $note = trim((string) preg_replace('/\s+/u', ' ', $note) ?? '');
        if ($note === '') {
            return '';
        }
        if (mb_strlen($note) > self::MAX) {
            $note = rtrim(mb_substr($note, 0, self::MAX - 1)) . '…';
        }
        return $note;
    }

    /**
     * Is the v3.3b attribution column there? Separate probe from installed()
     * because the two columns arrived in one file but a database can sit between
     * them: a reason with no attribution must read as the vendor's, not vanish.
     */
    public static function author_installed(): bool
    {
        static $installed = null;
        if ($installed !== null) {
            return $installed;
        }
        global $db;
        try {
            $db->fetchValue('SELECT status_note_by FROM tblvendor_bookings LIMIT 1');
            $installed = true;
        } catch (Throwable $e) {
            $installed = false;
        }
        return $installed;
    }

    /**
     * The reason cut to fit somewhere smaller than the column — a
     * `tblnotifications.message` (varchar 255, with a sentence wrapped round it)
     * or an SMS segment. Cut on the character, never mid-word soup: the tail gets
     * an ellipsis so a clipped reason reads as clipped rather than as a typo.
     *
     * Needed because `MAX` is 200 and 200 characters plus "your booking was
     * cancelled by their planner, they said" is more than the column holds —
     * without this, a long decline reason would fail the INSERT on a strict
     * server and lose the alert entirely.
     */
    public static function short(string $reason, int $max = 120): string
    {
        $reason = trim($reason);
        if ($max <= 0 || mb_strlen($reason) <= $max) {
            return $reason;
        }
        return rtrim(mb_substr($reason, 0, $max - 1)) . '…';
    }

    /**
     * The stored reason on a deal row, or '' when there is none. Rows read
     * through `vb.*` carry the column only when the migration is installed, so
     * a missing property reads as "no note" rather than a warning.
     *
     * @param object|null $row a tblvendor_bookings row
     */
    public static function text(?object $row): string
    {
        return trim((string) ($row->status_note ?? ''));
    }

    /**
     * Who said it: 'vendor' or 'planner'. Anything unrecognised — a NULL from a
     * pre-v3.3b row, a column that is not installed, an older row nobody
     * attributed — reads as the vendor, because every reason written before this
     * existed was theirs.
     *
     * @param object|null $row a tblvendor_bookings row
     */
    public static function author(?object $row): string
    {
        return (string) ($row->status_note_by ?? '') === self::PLANNER
            ? self::PLANNER
            : self::VENDOR;
    }

    /**
     * "Vendor said" / "Your planner said", ready to print before the reason.
     * A page whose reader is one of the two should say "You said" for their own
     * words instead — the caller knows who is reading; this only knows who wrote.
     *
     * @param object|null $row a tblvendor_bookings row
     */
    public static function attribution(?object $row): string
    {
        return self::author($row) === self::PLANNER ? 'Your planner said' : 'Vendor said';
    }
}
