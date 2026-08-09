<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;

/**
 * This example shows the three pieces of property-based testing in isolation,
 * without the Testo runner: a generator, a property that holds, and a property
 * that is falsified and then shrunk to a minimal counterexample by descending
 * through the generated value's shrink tree.
 */

$random = new Random(42);

// A property that holds: the sum of two non-negative integers is non-negative.
$left = Gen::intBetween(0, 1000);
$right = Gen::intBetween(0, 1000);

$violated = false;

for ($run = 0; $run < 100; ++$run) {
    $a = $left->generate($random)->value;
    $b = $right->generate($random)->value;

    if ($a + $b < 0) {
        $violated = true;
    }
}

echo $violated
    ? "sum-is-nonnegative: FAILED unexpectedly\n"
    : "sum-is-nonnegative: held for 100 runs\n";

// A property that is falsified: "every integer is even". Since 2.0 generate()
// returns a Shrinkable — the value plus a lazy tree of smaller candidates —
// so shrinking is a greedy descent: move to the first candidate that still
// fails, repeat until no candidate does.
$ints = Gen::intBetween(0, 1000);

$failing = null;
for ($run = 0; $run < 100; ++$run) {
    $shrinkable = $ints->generate($random);

    if ($shrinkable->value % 2 !== 0) {
        $failing = $shrinkable;

        break;
    }
}

if ($failing === null) {
    echo "all-even: never falsified\n";
} else {
    echo "all-even: falsified with original value {$failing->value}\n";

    do {
        $descended = false;

        foreach ($failing->shrinks() as $candidate) {
            if ($candidate->value % 2 !== 0) {
                $failing = $candidate;
                $descended = true;

                break;
            }
        }
    } while ($descended);

    echo "all-even: shrunk to minimal odd value {$failing->value}\n";
}
