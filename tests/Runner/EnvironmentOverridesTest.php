<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\EnvironmentOverrides;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(EnvironmentOverrides::class)]
final class EnvironmentOverridesTest
{
    #[DataProvider('absentValues')]
    public function anUnsetOrEmptyVariableIsNotGiven(string|false $value): void
    {
        Assert::null(EnvironmentOverrides::runs($value));
        Assert::null(EnvironmentOverrides::seed($value));
        Assert::null(EnvironmentOverrides::phases($value));
        Assert::null(EnvironmentOverrides::edgeCases($value));
        Assert::null(EnvironmentOverrides::flag($value));
        Assert::null(EnvironmentOverrides::string($value));
    }

    /**
     * @return iterable<string, array{string|false}>
     */
    public static function absentValues(): iterable
    {
        yield 'unset' => [false];
        yield 'empty' => [''];
    }

    public function runsIsAPositiveInteger(): void
    {
        Assert::same(EnvironmentOverrides::runs('1'), 1);
        Assert::same(EnvironmentOverrides::runs('500'), 500);
    }

    #[DataProvider('badRuns')]
    public function runsRefusesAnythingButAPositiveIntegerInRange(string $value): void
    {
        try {
            EnvironmentOverrides::runs($value);

            Assert::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), sprintf('PROPERTY_RUNS must be a positive integer, got "%s"', $value));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRuns(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'word' => ['abc'];
        yield 'float' => ['1.5'];
        yield 'past the integer range: a cast would saturate silently' => ['99999999999999999999'];
    }

    public function seedIsAnyInteger(): void
    {
        Assert::same(EnvironmentOverrides::seed('0'), 0);
        Assert::same(EnvironmentOverrides::seed('-42'), -42);
        Assert::same(EnvironmentOverrides::seed((string) PHP_INT_MAX), PHP_INT_MAX);
    }

    #[DataProvider('badSeeds')]
    public function seedRefusesAnythingButAnIntegerInRange(string $value): void
    {
        try {
            EnvironmentOverrides::seed($value);

            Assert::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), sprintf('PROPERTY_SEED must be an integer, got "%s"', $value));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSeeds(): iterable
    {
        yield 'word' => ['seed'];
        yield 'float' => ['4.2'];
        yield 'past the integer range' => ['9223372036854775808'];
    }

    public function phasesAreParsedCaseInsensitivelyAndTrimmed(): void
    {
        Assert::same(EnvironmentOverrides::phases('Examples, corpus'), [Phase::Examples, Phase::Corpus]);
        Assert::same(EnvironmentOverrides::phases('random,shrink'), [Phase::Random, Phase::Shrink]);
    }

    public function anUnknownPhaseIsAnErrorNotASkippedStage(): void
    {
        try {
            EnvironmentOverrides::phases('examples,replay');

            Assert::fail('expected the phase to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'PROPERTY_PHASES must be a comma-separated list of examples, corpus, random, shrink, got "replay"');
        }
    }

    public function edgeCasesAreParsedCaseInsensitivelyAndTrimmed(): void
    {
        Assert::same(EnvironmentOverrides::edgeCases('Mixin'), EdgeCases::Mixin);
        Assert::same(EnvironmentOverrides::edgeCases(' none '), EdgeCases::None);
    }

    public function anUnknownEdgeCaseModeIsAnError(): void
    {
        try {
            EnvironmentOverrides::edgeCases('some');

            Assert::fail('expected the mode to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'PROPERTY_EDGE_CASES must be one of mixin, none, got "some"');
        }
    }

    public function aFlagIsOffForZeroAndOnForAnythingElse(): void
    {
        Assert::false(EnvironmentOverrides::flag('0'));
        Assert::true(EnvironmentOverrides::flag('1'));
        Assert::true(EnvironmentOverrides::flag('false'));
    }

    public function aStringIsPassedThrough(): void
    {
        Assert::same(EnvironmentOverrides::string('value:3/draw#1:0'), 'value:3/draw#1:0');
    }
}
