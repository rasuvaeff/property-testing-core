<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Target;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Target::class)]
#[Covers(TargetDirection::class)]
final class TargetTest
{
    #[BeforeTest]
    public function reset(): void
    {
        Target::beginRun();
        Target::flushDirections();
    }

    public function recordsTheLatestScorePerLabelForTheRun(): void
    {
        Target::maximize('delay', 10);
        Target::maximize('delay', 12.5);
        Target::minimize('depth', 3);

        Assert::same(Target::flushRun(), ['delay' => 12.5, 'depth' => 3.0]);
        Assert::same(Target::flushRun(), []);
        Assert::same(Target::directions(), ['delay' => TargetDirection::Maximize, 'depth' => TargetDirection::Minimize]);
    }

    public function beginRunClearsScoresButKeepsDirections(): void
    {
        Target::maximize('delay', 1);
        Target::beginRun();

        Assert::same(Target::flushRun(), []);
        Assert::same(Target::directions(), ['delay' => TargetDirection::Maximize]);
    }

    public function flushDirectionsClearsTheRegistry(): void
    {
        Target::minimize('depth', 1);

        Assert::same(Target::flushDirections(), ['depth' => TargetDirection::Minimize]);
        Assert::same(Target::directions(), []);
    }

    #[DataProvider('nonFiniteProvider')]
    public function refusesANonFiniteScore(float $score, string $rendered): void
    {
        try {
            Target::maximize('x', $score);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Target score for "x" must be finite, got ' . $rendered);
        }

        Assert::same(Target::flushRun(), []);
    }

    public static function nonFiniteProvider(): iterable
    {
        yield 'NAN' => [NAN, 'NAN'];
        yield 'INF' => [INF, 'INF'];
        yield '-INF' => [-INF, '-INF'];
    }

    public function refusesToChangeALabelsDirection(): void
    {
        Target::maximize('x', 1);

        try {
            Target::minimize('x', 2);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Target "x" was maximized before and cannot be minimized now; a label has one direction for the whole property');
        }

        Assert::same(Target::flushRun(), ['x' => 1.0]);
    }

    public function directionsKnowWhatAnImprovementIs(): void
    {
        Assert::true(TargetDirection::Maximize->improves(2.0, 1.0));
        Assert::false(TargetDirection::Maximize->improves(1.0, 1.0));
        Assert::false(TargetDirection::Maximize->improves(0.5, 1.0));
        Assert::true(TargetDirection::Minimize->improves(0.5, 1.0));
        Assert::false(TargetDirection::Minimize->improves(1.0, 1.0));
        Assert::false(TargetDirection::Minimize->improves(2.0, 1.0));
    }
}
