<?php
declare(strict_types=1);

/**
 * Fixture page for tests/EnumValueSweepTest — the value nobody writes.
 *
 * The label map and the branch below are prepared for 'maybe', but the only
 * writers in this tree are the guard in guarded_rsvp.php ('yes', 'no') and the
 * pending default. So 'maybe' is a value the code reacts to and no code path can
 * produce — the finding this sweep exists to print. It is also the value the
 * repository's own ACCEPTED_VALUES entry covers, so the fixture asserts the
 * accepted line and its grouped reason too.
 */
$guest = new Guest();

$label = ['yes' => 'Attending', 'no' => 'Not attending', 'maybe' => 'Still deciding'][$guest->rsvp_status ?? 'pending'];

if ($guest->rsvp_status === 'maybe') {
    echo 'The couple would love a final answer.';
}
