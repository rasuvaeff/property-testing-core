<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Random\Engine\Mt19937;
use Random\Randomizer;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

// Case study for docs/src/cookbook/faker-vs-property.md — the same bug found
// twice, once with realistic data and once with shrinkable data. Both find it;
// only one of them can say what the smallest failing input is.
//
// Unlike its neighbours in this directory, the bug below is not from this
// monorepo's history. It is the plainest instance of the difference the page
// is about: byte-wise truncation of a UTF-8 string.

/**
 * Cuts a display name to $limit BYTES — the bug. A multibyte character
 * straddling the limit is left half-written, and the result is no longer
 * valid UTF-8.
 */
function truncateDisplayName(string $name, int $limit): string
{
    return substr($name, 0, $limit);
}

/**
 * A stand-in with Faker's shape: a seed goes in, a realistic string comes out.
 * Nothing here depends on Faker's implementation — what matters is the shape,
 * because that shape is what has no ordering. There is no sense in which one
 * of these names is "simpler" than another, so nothing can minimise a failing
 * one.
 */
final class RealisticNames
{
    private const array FIRST = ['Émilie', 'Zoë', 'Andrés', 'Björn', 'Agnès', 'Ruslan'];
    private const array LAST = ['Dubois-Lévesque', 'Ångström', 'Müller', 'Sørensen'];
    private const array SUFFIX = ['', ' Jr.', ' III', ' PhD'];

    public static function name(int $seed): string
    {
        $random = new Randomizer(new Mt19937($seed));

        return self::FIRST[$random->getInt(0, count(self::FIRST) - 1)]
            . ' ' . self::LAST[$random->getInt(0, count(self::LAST) - 1)]
            . self::SUFFIX[$random->getInt(0, count(self::SUFFIX) - 1)];
    }
}

$staysValidUtf8 = static function (string $name, int $limit): void {
    $truncated = truncateDisplayName($name, $limit);

    if (!mb_check_encoding($truncated, 'UTF-8')) {
        throw new RuntimeException(sprintf(
            'truncateDisplayName("%s", %d) returned bytes that are not valid UTF-8',
            $name,
            $limit,
        ));
    }
};

/**
 * @param array<string, ArbitraryInterface> $generators
 */
function falsify(string $id, array $generators, \Closure $property, int $seed): void
{
    $result = (new PropertyRunner())->run(
        new PropertyDefinition(
            id: $id,
            name: 'truncationKeepsValidUtf8',
            generators: $generators,
            parameterNames: array_keys($generators),
            config: new PropertyConfig(runs: 200, seed: $seed),
        ),
        new CallableTrialExecutor($property),
    );

    if (!$result instanceof Falsified) {
        echo "Not falsified — the truncation bug should reproduce here.\n";

        exit(1);
    }

    echo $result->failure()->getMessage() . "\n";
}

// 1. Realistic data. The generator draws a seed and maps it to a name, which
//    is exactly how a Faker-backed arbitrary has to be built: the library
//    offers no smaller name, only another one.
echo "Realistic data (a seed mapped to a name):\n\n";
falsify(
    'case-study::fakerShaped',
    [
        'name' => Gen::map(Gen::intBetween(0, 1_000_000), static fn(int $seed): string => RealisticNames::name($seed)),
        'limit' => Gen::intBetween(1, 12),
    ],
    $staysValidUtf8,
    seed: 3,
);

// 2. Shrinkable data. The same bug, generated from an alphabet the engine can
//    take apart: shorter strings, earlier characters, smaller limits.
echo "\nShrinkable data (a string over an alphabet):\n\n";
falsify(
    'case-study::shrinkable',
    [
        'name' => Gen::stringFrom('aé', 1, 8),
        'limit' => Gen::intBetween(1, 12),
    ],
    $staysValidUtf8,
    seed: 3,
);
