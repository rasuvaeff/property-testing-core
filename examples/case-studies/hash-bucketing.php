<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

// Case study for docs/src/cookbook/hash-bucketing.md — reconstructs a
// yii3-ab-testing rollout-bucketing bug: the assignment hash was salted
// with the rollout percentage itself, so a subject included at 50% could
// fall back OUT of the rollout at 60% — rollout percentage is supposed to
// only ever grow the included set, never reshuffle it.
//
// p2 is built from p1 plus a non-negative delta, not filtered down to
// "p2 >= p1" after the fact — constructing valid inputs instead of
// discarding invalid ones (root AGENTS.md, "Конструировать, не фильтровать").

function buggyInRollout(string $subject, int $percent): bool
{
    return (crc32($subject . ':' . $percent) % 100) < $percent;
}

function fixedInRollout(string $subject, int $percent): bool
{
    return (crc32($subject) % 100) < $percent;
}

$monotoneInPercent = static function (string $subject, int $p1, int $delta): void {
    $p2 = min(100, $p1 + $delta);

    if (buggyInRollout($subject, $p1) && !buggyInRollout($subject, $p2)) {
        throw new RuntimeException(sprintf(
            'subject %s is in the %d%% rollout but not the %d%% rollout',
            var_export($subject, true),
            $p1,
            $p2,
        ));
    }
};

$definition = new PropertyDefinition(
    id: 'case-study::hashBucketing',
    name: 'rolloutIsMonotoneInPercent',
    generators: [
        'subject' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789', 1, 12),
        'p1' => Gen::intBetween(0, 100),
        'delta' => Gen::intBetween(0, 100),
    ],
    parameterNames: ['subject', 'p1', 'delta'],
    config: new PropertyConfig(runs: 300, seed: 5),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor($monotoneInPercent));

if ($result instanceof Falsified) {
    echo "Buggy rollout bucketing falsified:\n\n";
    echo $result->failure()->getMessage() . "\n";
} else {
    echo "No monotonicity violation found — unexpected, the hash-bucketing bug should reproduce here.\n";
    exit(1);
}
