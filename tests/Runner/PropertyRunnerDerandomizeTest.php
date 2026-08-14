<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Tests\Support\CollectingListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Pins the derandomised seed. The seeds below are golden values: the
 * derivation is part of the observable contract, because a run that picks a
 * different seed after an upgrade reproduces a different bug than the one the
 * developer was chasing.
 */
#[Test]
#[Covers(PropertyRunner::class)]
final class PropertyRunnerDerandomizeTest
{
    #[DataProvider('derivedSeedProvider')]
    public function theSeedIsAPureFunctionOfThePropertyId(string $id, int $expected): void
    {
        Assert::same($this->seedOf($id, new PropertyConfig(runs: 1, derandomize: true)), $expected);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function derivedSeedProvider(): iterable
    {
        // Two ids, so the digest offset and length are pinned as well as the
        // fact that the derivation is deterministic at all.
        yield 'one id' => ['derandomize::property', 450_762_953_188_432_453];
        yield 'another id' => ['derandomize::other', 1_057_752_850_823_600_212];
    }

    public function twoRunsOfTheSameIdGenerateTheSameArguments(): void
    {
        Assert::same(
            $this->argumentsOf('derandomize::property'),
            $this->argumentsOf('derandomize::property'),
        );
    }

    public function differentIdsGetDifferentSeeds(): void
    {
        $config = new PropertyConfig(runs: 1, derandomize: true);

        Assert::true($this->seedOf('derandomize::property', $config) !== $this->seedOf('derandomize::other', $config));
    }

    public function anExplicitSeedBeatsTheFlag(): void
    {
        Assert::same($this->seedOf('derandomize::property', new PropertyConfig(runs: 1, seed: 7, derandomize: true)), 7);
    }

    public function withoutTheFlagTheSeedIsNotTheDerivedOne(): void
    {
        // Asserted against the derived value rather than against a second run:
        // two random draws differing is not evidence of anything, while a draw
        // landing exactly on the derived seed would be.
        Assert::true($this->seedOf('derandomize::property', new PropertyConfig(runs: 1)) !== 450_762_953_188_432_453);
    }

    /**
     * The seed the runner announced for a property, read off the event that
     * carries it rather than inferred from the values it produced.
     */
    private function seedOf(string $id, PropertyConfig $config): int
    {
        $listener = new CollectingListener();
        $this->run($id, $config, $listener);

        $started = $listener->ofType(PropertyStarted::class);
        Assert::same(count($started), 1);

        return $started[0]->seed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function argumentsOf(string $id): array
    {
        $listener = new CollectingListener();
        $this->run($id, new PropertyConfig(runs: 10, derandomize: true), $listener);

        return array_map(
            static fn(RunStarted $event): array => $event->arguments,
            $listener->ofType(RunStarted::class),
        );
    }

    private function run(string $id, PropertyConfig $config, CollectingListener $listener): void
    {
        $result = (new PropertyRunner())->run(
            new PropertyDefinition(
                id: $id,
                name: 'property',
                generators: ['value' => Gen::intBetween(0, 1_000_000)],
                parameterNames: ['value'],
                config: $config,
            ),
            new CallableTrialExecutor(static function (int $value): void {
                Assert::true($value >= 0);
            }),
            [$listener],
        );

        Assert::instanceOf($result, Passed::class);
    }
}
