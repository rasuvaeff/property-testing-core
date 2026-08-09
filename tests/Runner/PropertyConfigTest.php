<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PropertyConfig::class)]
final class PropertyConfigTest
{
    public function defaultsMatchTheAttributeDefaults(): void
    {
        $config = new PropertyConfig();

        Assert::same($config->runs, 100);
        Assert::null($config->seed);
        Assert::null($config->maxShrinks);
        Assert::null($config->maxDiscards);
        Assert::null($config->timeoutMs);
        Assert::null($config->budgetMs);
    }

    public function acceptsEveryBoundaryValue(): void
    {
        $config = new PropertyConfig(
            runs: 1,
            seed: PHP_INT_MIN,
            maxShrinks: 0,
            maxDiscards: 0,
            timeoutMs: 1,
            budgetMs: 1,
        );

        Assert::same($config->runs, 1);
        Assert::same($config->maxShrinks, 0);
        Assert::same($config->maxDiscards, 0);
        Assert::same($config->timeoutMs, 1);
        Assert::same($config->budgetMs, 1);
    }

    #[DataProvider('invalidProvider')]
    public function rejectsOutOfRangeValues(\Closure $construct, string $message): void
    {
        $thrown = null;

        try {
            $construct();
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        Assert::instanceOf($thrown, \InvalidArgumentException::class);
        Assert::same($thrown->getMessage(), $message);
    }

    public static function invalidProvider(): iterable
    {
        yield 'zero runs' => [
            static fn(): PropertyConfig => new PropertyConfig(runs: 0),
            'Runs must be greater than or equal to 1',
        ];

        yield 'negative max shrinks' => [
            static fn(): PropertyConfig => new PropertyConfig(maxShrinks: -1),
            'Max shrinks must be greater than or equal to 0',
        ];

        yield 'negative max discards' => [
            static fn(): PropertyConfig => new PropertyConfig(maxDiscards: -1),
            'Max discards must be greater than or equal to 0',
        ];

        yield 'zero timeout' => [
            static fn(): PropertyConfig => new PropertyConfig(timeoutMs: 0),
            'Timeout must be greater than or equal to 1 millisecond',
        ];

        yield 'zero budget' => [
            static fn(): PropertyConfig => new PropertyConfig(budgetMs: 0),
            'Budget must be greater than or equal to 1 millisecond',
        ];
    }
}
