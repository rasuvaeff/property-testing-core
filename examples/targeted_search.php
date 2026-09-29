<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Target;

/**
 * Targeted search: a bug that lives in a corner of the input space — three
 * parameters whose sum exceeds a threshold that only 0.17% of the space
 * reaches. The body reports the sum with Target::maximize(); with searchRuns
 * on, the engine climbs it after the random phase by mutating the
 * best-scoring inputs one parameter at a time. The same seed is run both
 * ways so the two outcomes can be compared directly.
 */

$body = static function (int $a, int $b, int $c): void {
    Target::maximize('sum', $a + $b + $c);

    if ($a + $b + $c > 2_900) {
        throw new RuntimeException('the corner is reachable');
    }
};

$definition = static fn(int $runs, int $searchRuns): PropertyDefinition => new PropertyDefinition(
    id: 'examples::cornerIsUnreachable',
    name: 'cornerIsUnreachable',
    generators: [
        'a' => Gen::intBetween(0, 1_000),
        'b' => Gen::intBetween(0, 1_000),
        'c' => Gen::intBetween(0, 1_000),
    ],
    parameterNames: ['a', 'b', 'c'],
    config: new PropertyConfig(runs: $runs, seed: 1, searchRuns: $searchRuns),
);

$runner = new PropertyRunner();

echo "== 300 random runs, no search ==\n";
$sampled = $runner->run($definition(300, 0), new CallableTrialExecutor($body));
echo $sampled instanceof Passed
    ? sprintf("passed after %d checks — the corner was never sampled\n", $sampled->statistics->checks)
    : "falsified\n";

echo "\n== 200 random runs + 100 search runs ==\n";

$improvements = new class implements PropertyListener {
    public function onEvent(PropertyEvent $event): void
    {
        if ($event instanceof TargetImproved) {
            printf(
                "  %-4s %s: %s -> %s  (a=%d, b=%d, c=%d)\n",
                $event->direction->value === 'maximize' ? 'max' : 'min',
                $event->label,
                $event->previous === null ? '-' : (string) $event->previous,
                $event->score,
                $event->arguments['a'],
                $event->arguments['b'],
                $event->arguments['c'],
            );
        }
    }
};

$searched = $runner->run($definition(200, 100), new CallableTrialExecutor($body), [$improvements]);

if ($searched instanceof Falsified) {
    $example = $searched->counterExample();
    printf(
        "falsified after %d checks; shrunk to a=%d, b=%d, c=%d\n",
        $example->runsBeforeFailure,
        $example->shrunkArguments['a'],
        $example->shrunkArguments['b'],
        $example->shrunkArguments['c'],
    );
} elseif ($searched instanceof Passed && $searched->statistics->search !== null) {
    printf("passed; best sum reached %s\n", $searched->statistics->search->targets['sum']->best);
}
