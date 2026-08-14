<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Testo\Bench;

/**
 * Whole-property runs, to keep the distribution report where it belongs: off
 * the hot path. `Classify::label()` executes inside the body on every run; the
 * report is one projection of the counters those calls accumulated, built after
 * the last of them. The pair below is the measurement — a classifying property
 * against the same property without labels — and the difference between them
 * must stay the cost of the `Classify` calls themselves.
 */
final class PropertyRunBench
{
    #[Bench(['no labels' => [self::class, 'plainProperty']], calls: 20, iterations: 5)]
    public static function classifyingProperty(): PropertyResult
    {
        return self::run(static function (int $value): void {
            Classify::label($value % 2 === 0 ? 'even' : 'odd');
            Classify::when($value === 0, 'zero');
            Classify::cover($value > 5_000, 'high', 10.0);
        });
    }

    public static function plainProperty(): PropertyResult
    {
        return self::run(static function (int $value): void {});
    }

    /**
     * @param \Closure(int): void $body
     */
    private static function run(\Closure $body): PropertyResult
    {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'bench::property',
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 10_000)],
                parameterNames: ['value'],
                config: new PropertyConfig(runs: 100, seed: 42),
            ),
            new CallableTrialExecutor($body),
        );
    }
}
