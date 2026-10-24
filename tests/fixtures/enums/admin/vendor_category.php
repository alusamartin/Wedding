<?php
declare(strict_types=1);

/**
 * Fixture page for tests/EnumValueSweepTest — every value, not just the first.
 *
 * category is a ten-value ENUM. This tree only ever stores 'catering', so all
 * four values the code reacts to below are unreachable, and the sweep must
 * report every one of them rather than stopping at the first it finds. The
 * reaction kinds are mixed on purpose: a comparison, a switch case and a label
 * map all count as "code reacts to this value".
 */
$vendor = new Vendor();
$vendor->category = 'catering';

if ($vendor->category === 'decor') {
    echo 'Drapes and lighting';
}

switch ($vendor->category) {
    case 'venue':
        echo 'Somewhere to hold it';
        break;
}

echo ['catering' => 'Food and drink', 'flowers' => 'Bouquets', 'other' => 'Anything else'][$vendor->category] ?? '';
