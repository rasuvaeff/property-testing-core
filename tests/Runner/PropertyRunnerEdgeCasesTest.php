<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The edge-case choice has to reach the generators, which is the only part of
 * it the runner owns: the bias itself lives in the arbitraries and the switch
 * travels on {@see \Rasuvaeff\PropertyTesting\Random}.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerEdgeCasesTest
{
    private const array EDGES = [0, 1, -1, -1_000_000, 1_000_000];

    public function byDefaultTheRunnerGeneratesEdgeValues(): void
    {
        Assert::true($this->edgesSeen(new PropertyConfig(runs: 300, seed: 5)) > 20);
    }

    public function edgeCasesOffKeepsThemOutOfTheRun(): void
    {
        // What the knob is for: a property that discards the edges would spend
        // one run in five producing a value it throws away.
        Assert::same($this->edgesSeen(new PropertyConfig(runs: 300, seed: 5, edgeCases: EdgeCases::None)), 0);
    }

    private function edgesSeen(PropertyConfig $config): int
    {
        $listener = new CollectingListener();

        (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'edge::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(-1_000_000, 1_000_000)],
                parameterNames: ['value'],
                config: $config,
            ),
            new CallableTrialExecutor(static function (int $value): void {}),
            [$listener],
        );

        $edges = 0;

        foreach ($listener->ofType(RunStarted::class) as $event) {
            if (in_array($event->arguments['value'] ?? null, self::EDGES, strict: true)) {
                ++$edges;
            }
        }

        return $edges;
    }
}
