<?php
declare(strict_types=1);

/**
 * Fixture page for tests/EnumValueSweepTest — the guard rule.
 *
 * The answer arrives as a string from the guest's own page, and is refused
 * unless it is one of the two values below. Past that guard the only values that
 * can reach tblguest.rsvp_status are 'yes' and 'no' — so a branch elsewhere that
 * looks for 'maybe' is unreachable, and the sweep must be able to see the two
 * written values without giving up on the column.
 */
function fixture_record_answer(string $status): bool
{
    if (!in_array($status, ['yes', 'no'], true)) {
        return false;
    }
    $guest = new Guest();
    $guest->rsvp_status = $status;
    return $guest->update();
}

$guest = new Guest();
$guest->rsvp_status = 'pending';
