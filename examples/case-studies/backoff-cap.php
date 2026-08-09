<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

// Case study for docs/src/cookbook/backoff-cap.md — reconstructs the
// rasuvaeff/retry bug: the cap was applied to the base delay BEFORE jitter
// was added, so jitter could still push the final delay past the cap.
//
// The jitter factor is a generated parameter, not an ambient random draw —
// every source of randomness has to flow through the engine's seeded
// generators, or a pinned seed stops reproducing the same run.

function buggyDelayMs(int $baseMs, int $capMs, float $jitterFactor): int
{
    $capped = min($baseMs, $capMs);
    $jitter = (int) round($capped * $jitterFactor);

    return $capped + $jitter;
}

function fixedDelayMs(int $baseMs, int $capMs, float $jitterFactor): int
{
    $jittered = $baseMs + (int) round($baseMs * $jitterFactor);

    return min($jittered, $capMs);
}

$staysWithinCap = static function (int $baseMs, int $capMs, float $jitterFactor): void {
    $delay = buggyDelayMs($baseMs, $capMs, $jitterFactor);

    if ($delay > $capMs) {
        throw new RuntimeException(sprintf(
            'buggyDelayMs(base=%d, cap=%d, jitter=%.4f) returned %d, expected <= cap %d',
            $baseMs,
            $capMs,
            $jitterFactor,
            $delay,
            $capMs,
        ));
    }
};

$definition = new PropertyDefinition(
    id: 'case-study::backoffCap',
    name: 'delayStaysWithinCap',
    generators: [
        'baseMs' => Gen::intBetween(0, 10_000),
        'capMs' => Gen::intBetween(0, 60_000),
        'jitterFactor' => Gen::floatBetween(0.0, 1.0),
    ],
    parameterNames: ['baseMs', 'capMs', 'jitterFactor'],
    config: new PropertyConfig(runs: 300, seed: 17),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor($staysWithinCap));

if ($result instanceof Falsified) {
    echo "Buggy backoff falsified:\n\n";
    echo $result->failure()->getMessage() . "\n";
} else {
    echo "No cap violation found — unexpected, the backoff-cap bug should reproduce here.\n";
    exit(1);
}
