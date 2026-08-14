<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\ShrinkPath;
use Rasuvaeff\PropertyTesting\Tests\Support\Check;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ShrinkPath::class)]
final class ShrinkPathTest
{
    /**
     * @param list<array{name: string, index: int}> $steps
     */
    #[DataProvider('pathProvider')]
    public function formatAndParseAreTwoViewsOfTheSameSteps(string $path, array $steps): void
    {
        Assert::same(ShrinkPath::format($steps), $path);
        Assert::same(ShrinkPath::parse($path), $steps);
    }

    /**
     * @return iterable<string, array{string, list<array{name: string, index: int}>}>
     */
    public static function pathProvider(): iterable
    {
        yield 'one parameter step' => ['value:0', [['name' => 'value', 'index' => 0]]];

        yield 'several steps of one parameter' => [
            'value:1/value:3',
            [['name' => 'value', 'index' => 1], ['name' => 'value', 'index' => 3]],
        ];

        yield 'parameters and in-body draws' => [
            'a:2/draw#1:0/b:11/draw#12:7',
            [
                ['name' => 'a', 'index' => 2],
                ['name' => 'draw#1', 'index' => 0],
                ['name' => 'b', 'index' => 11],
                ['name' => 'draw#12', 'index' => 7],
            ],
        ];

        yield 'an underscored parameter' => ['_private_1:0', [['name' => '_private_1', 'index' => 0]]];

        // PHP accepts `$é` as a parameter name, so the recorder can write one;
        // a grammar that rejected it would reject a path this engine produced.
        yield 'a non-ASCII parameter' => ['éclair:3', [['name' => 'éclair', 'index' => 3]]];
    }

    /**
     * Any sequence of steps renders to a path this parser reads back — the
     * property the whole feature rests on, since one side records and the other
     * replays.
     */
    public function everyRecordedDescentParsesBackToItself(): void
    {
        Check::property(
            static function (array $steps): void {
                $hasDraw = array_filter($steps, static fn(array $step): bool => str_starts_with((string) $step['name'], 'draw#')) !== [];
                Classify::cover($hasDraw, 'has an in-body draw step', 25.0);
                Classify::cover(!$hasDraw, 'parameters only', 5.0);

                /** @var list<array{name: string, index: int<0, max>}> $steps */
                Assert::same(ShrinkPath::parse(ShrinkPath::format($steps)), $steps);
            },
            ['steps' => Gen::nonEmptyArrayOf(self::stepGenerator(), maxSize: 6)],
            runs: 200,
            seed: 20_260_814,
        );
    }

    public function theEmptyPathIsWhatNoStepsRenderTo(): void
    {
        // Recorded by a descent that never accepted anything; rejected on the
        // way back in, because a run pinned to nothing is a run that searches.
        Assert::same(ShrinkPath::format([]), '');
    }

    #[DataProvider('malformedProvider')]
    public function malformedPathsAreRejected(string $path): void
    {
        $thrown = null;

        try {
            ShrinkPath::parse($path);
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        Assert::instanceOf($thrown, \InvalidArgumentException::class);
        Assert::same($thrown->getMessage(), sprintf('Invalid shrink path "%s"', $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no index' => ['value'];
        yield 'empty index' => ['value:'];
        yield 'no name' => [':1'];
        yield 'trailing separator' => ['value:1/'];
        yield 'doubled separator' => ['value:1//value:2'];
        yield 'a name starting with a digit' => ['1value:0'];
        yield 'a name with a space' => ['my value:0'];
        // `draw#0` would address the position before the tape starts.
        yield 'a zero draw position' => ['draw#0:1'];
        yield 'a draw without a position' => ['draw#:1'];
        yield 'an index past nine digits' => ['value:1234567890'];
        yield 'a negative index' => ['value:-1'];
        // The anchor is \z, not $: a trailing newline is not a path.
        yield 'a trailing newline' => ["value:1\n"];
    }

    #[DataProvider('drawNumberProvider')]
    public function drawStepsResolveToTheDrawTheyName(string $name, ?int $expected): void
    {
        Assert::same(ShrinkPath::drawNumber($name), $expected);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function drawNumberProvider(): iterable
    {
        yield 'the first draw' => ['draw#1', 1];
        yield 'a later draw' => ['draw#12', 12];
        yield 'a parameter' => ['value', null];
        // Reported under `draw#N` precisely because `#` cannot occur in a PHP
        // parameter name; a name that merely mentions draw is a parameter.
        yield 'a parameter called draw' => ['draw', null];
    }

    /**
     * @return ArbitraryInterface<array<string, mixed>>
     */
    private static function stepGenerator(): ArbitraryInterface
    {
        return Gen::record([
            'name' => Gen::frequency([
                [3, Gen::regex('[A-Za-z_][A-Za-z0-9_]{0,7}')],
                [1, Gen::map(Gen::intBetween(1, 999), static fn(int $position): string => 'draw#' . $position)],
            ]),
            'index' => Gen::intBetween(0, 999_999_999),
        ]);
    }
}
