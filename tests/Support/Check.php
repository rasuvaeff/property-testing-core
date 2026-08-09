<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

/**
 * Dogfooding harness for this package's own property-style tests: drives the
 * engine directly through {@see PropertyRunner}, without any adapter (the
 * `#[Property]` attribute lives in property-testing-testo, which depends on
 * this package — the engine's own suite cannot use it). A failing outcome
 * rethrows the engine failure, so a falsified test surfaces the usual
 * counterexample message.
 */
final readonly class Check
{
    /**
     * @param \Closure(mixed...): void $body The property body; its parameter
     *   names select the generators.
     * @param array<string, ArbitraryInterface> $generators
     */
    public static function property(\Closure $body, array $generators, int $runs, int $seed): void
    {
        $parameterNames = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionFunction($body))->getParameters(),
        );

        $definition = new PropertyDefinition(
            id: 'core-tests::' . implode(',', $parameterNames) . '@' . $seed,
            name: 'property',
            generators: $generators,
            parameterNames: $parameterNames,
            config: new PropertyConfig(runs: $runs, seed: $seed),
        );

        $result = (new PropertyRunner())->run($definition, new CallableTrialExecutor($body));

        $failure = $result->failure();

        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }
}
