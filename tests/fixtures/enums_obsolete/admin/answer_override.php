<?php
declare(strict_types=1);

/**
 * Fixture page for tests/EnumValueSweepTest — the acceptance that outlived its
 * debt.
 *
 * tblguest.rsvp_status is accepted in the sweep's ACCEPTED_VALUES because the
 * answer is written through a request value the sweep cannot enumerate. An
 * operator override now writes the value directly, so the entry excuses nothing
 * any more and must fail the build instead of hiding the next regression.
 */
$guest = new Guest();
$guest->rsvp_status = 'maybe';
