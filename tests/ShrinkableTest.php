<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Shrinkable::class)]
final class ShrinkableTest
{
    public function leafCarriesTheValueAndHasNoShrinks(): void
    {
        $leaf = Shrinkable::leaf(42);

        Assert::same($leaf->value, 42);
        Assert::same(Trees::childValues($leaf), []);
    }

    public function ofExposesTheChildrenFromTheClosure(): void
    {
        $node = Shrinkable::of(10, static fn(): array => [Shrinkable::leaf(0), Shrinkable::leaf(5)]);

        Assert::same($node->value, 10);
        Assert::same(Trees::childValues($node), [0, 5]);
    }

    public function shrinksAreLazyUntilAsked(): void
    {
        $invocations = new \stdClass();
        $invocations->count = 0;

        $node = Shrinkable::of(1, static function () use ($invocations): array {
            ++$invocations->count;

            return [Shrinkable::leaf(0)];
        });

        Assert::same($invocations->count, 0);

        Trees::childValues($node);

        Assert::same($invocations->count, 1);
    }

    public function shrinksCanBeIteratedRepeatedly(): void
    {
        // The closure is re-invoked per shrinks() call, so descending into a
        // sibling later does not exhaust the candidates.
        $node = Shrinkable::of(2, static fn(): array => [Shrinkable::leaf(0), Shrinkable::leaf(1)]);

        Assert::same(Trees::childValues($node), [0, 1]);
        Assert::same(Trees::childValues($node), [0, 1]);
    }

    public function mapTransformsTheValue(): void
    {
        $node = Shrinkable::leaf(21)->map(static fn(int $x): int => $x * 2);

        Assert::same($node->value, 42);
    }

    public function mapTransformsEveryShrinkCandidateRecursively(): void
    {
        $node = Shrinkable::of(4, static fn(): array => [
            Shrinkable::of(2, static fn(): array => [Shrinkable::leaf(1)]),
            Shrinkable::leaf(3),
        ]);

        $mapped = $node->map(static fn(int $x): int => $x * 10);

        Assert::same($mapped->value, 40);
        Assert::same(Trees::childValues($mapped), [20, 30]);
        Assert::same(Trees::valuesToDepth($mapped, 2), [20, 10, 30]);
    }

    public function mapIsLazyOnChildren(): void
    {
        $applications = new \stdClass();
        $applications->count = 0;

        $node = Shrinkable::of(1, static fn(): array => [Shrinkable::leaf(0)])
            ->map(static function (int $x) use ($applications): int {
                ++$applications->count;

                return $x;
            });

        // Only the root value is mapped eagerly; children wait for shrinks().
        Assert::same($applications->count, 1);
        Assert::same($node->value, 1);

        Trees::childValues($node);

        Assert::same($applications->count, 2);
    }
}
