<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Tours the generators without the Testo runner: sampling, boundary-biased
 * numbers, the value/structure generators, and dependent generation with
 * flatMap. generate() returns a Shrinkable; the plain value is at ->value.
 */

// Gen::sample — eyeball what a generator produces for a fixed seed.
echo 'intBetween(1, 6) sample: ' . implode(', ', Gen::sample(Gen::intBetween(1, 6), 8, 42)) . "\n";

// Numeric generators are boundary-biased: edge values appear far more often than
// uniform sampling would produce them.
$random = new Random(7);
$int = Gen::intBetween(0, 1000);
$edges = 0;
for ($i = 0; $i < 100; ++$i) {
    if (in_array($int->generate($random)->value, [0, 1, 1000], true)) {
        ++$edges;
    }
}
echo "boundary hits in 100 draws: {$edges} (uniform would be ~0)\n";

// Value generators.
$random = new Random(1);
echo 'uuid: ' . Gen::uuid()->generate($random)->value . "\n";
echo 'datetime: ' . Gen::datetime()->generate($random)->value->format(DATE_ATOM) . "\n";

// dictOf — string-keyed map; record — fixed shape keyed by field name.
$dictionary = Gen::dictOf(Gen::stringOf(3, 5), Gen::intBetween(1, 9))->generate($random)->value;
echo 'dictOf entries: ' . count($dictionary) . "\n";

$user = Gen::record([
    'age' => Gen::intBetween(0, 120),
    'active' => Gen::bool(),
])->generate($random)->value;
echo "record: age={$user['age']}, active=" . ($user['active'] ? 'true' : 'false') . "\n";

// flatMap — dependent generators: an array plus an always-valid index into it.
// No Assume::that() discards, and both levels shrink.
$pair = Gen::flatMap(
    Gen::nonEmptyArrayOf(Gen::intBetween(1, 9)),
    static fn(array $items): ArbitraryInterface => Gen::tuple(
        Gen::constant($items),
        Gen::intBetween(0, count($items) - 1),
    ),
)->generate($random)->value;
[$items, $index] = $pair;
echo 'flatMap: ' . count($items) . " items, valid index {$index}, item={$items[$index]}\n";

// 2.1.0 generators: alphabet-bound strings, raw bytes, ordered ranges.
echo 'stringFrom(hex): ' . Gen::stringFrom('0123456789abcdef', 8, 8)->generate($random)->value . "\n";
echo 'bytes: ' . bin2hex(Gen::bytes(4, 4)->generate($random)->value) . "\n";
[$lo, $hi] = Gen::intRange(0, 100)->generate($random)->value;
echo "intRange: [{$lo}, {$hi}]\n";

// sampleShrinks — eyeball how a value would shrink (debug custom arbitraries).
$sampled = Gen::sampleShrinks(Gen::intBetween(0, 100), seed: 5, limit: 5);
echo "sampleShrinks: value={$sampled['value']}, candidates=" . implode(', ', $sampled['shrinks']) . "\n";
