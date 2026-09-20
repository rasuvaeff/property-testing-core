<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\MappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\UniqueArrayArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UniqueArrayArbitrary::class)]
final class UniqueArrayArbitraryTest
{
    /**
     * @param list<mixed> $values
     */
    private function isDistinct(array $values): bool
    {
        foreach ($values as $index => $value) {
            $others = $values;
            unset($others[$index]);

            if (in_array($value, $others, strict: true)) {
                return false;
            }
        }

        return true;
    }

    public function generateProducesPairwiseDistinctElements(): void
    {
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 0, 20);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            Assert::true($this->isDistinct($arbitrary->generate($random)->value));
        }
    }

    public function generateStaysWithinSizeRangeWhenTheElementSpaceIsLarge(): void
    {
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(), 2, 8);
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            $count = count($arbitrary->generate($random)->value);

            Assert::true($count >= 2 && $count <= 8);
        }
    }

    public function settlesForFewerElementsWhenTheSpaceRunsDry(): void
    {
        // Only 3 distinct values exist; a drawn size above 3 must settle for at
        // most 3 (minSize 0 keeps that legal) instead of looping forever.
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(1, 3), 0, 10);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $value = $arbitrary->generate($random)->value;

            Assert::true(count($value) <= 3);
            Assert::true($this->isDistinct($value));
        }
    }

    #[ExpectException(GenerationExhaustedException::class)]
    public function throwsWhenTheElementSpaceCannotReachTheMinimumSize(): void
    {
        // Two distinct values can never fill a minimum of 3 — generation is
        // exhausted and reported instead of silently under-filling.
        $arbitrary = new UniqueArrayArbitrary(new IntArbitrary(1, 2), 3, 5);
        $random = new Random(1);

        for ($i = 0; $i < 100; ++$i) {
            $arbitrary->generate($random);
        }
    }

    public function shrinkRemovesBlocksLongestFirstFromEveryOffset(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );
        [$a, $b, $c, $d] = $node->value;

        Assert::same(array_slice(Trees::childValues($node), 0, 7), [
            [],
            [$c, $d], [$a, $b],
            [$b, $c, $d], [$a, $c, $d], [$a, $b, $d], [$a, $b, $c],
        ]);
    }

    public function everyShrinkCandidateStaysPairwiseDistinct(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 50), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) >= 3,
        );

        foreach (Trees::valuesToDepth($node, 3) as $candidate) {
            Assert::true($this->isDistinct($candidate));
        }
    }

    public function elementShrinkCandidatesCollidingWithAnotherElementAreSkipped(): void
    {
        // Element 8 shrinks toward 0 first, but 0 is already in the list: the
        // colliding candidate must be dropped while the rest of the ladder
        // ([4, 0], [6, 0], [7, 0]) survives.
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 10), 2, 2),
            static fn(mixed $v): bool => $v === [8, 0],
        );
        $candidates = Trees::childValues($node);

        Assert::false(in_array([0, 0], $candidates, strict: true));
        Assert::true(in_array([4, 0], $candidates, strict: true));
    }

    public function keyClosureDeduplicatesByTheKeyItReturns(): void
    {
        // Elements are [id, payload] pairs; the payload varies freely, so
        // without the key two pairs sharing an id would count as distinct.
        $arbitrary = new UniqueArrayArbitrary(
            new MappedArbitrary(new IntArbitrary(0, 5), static fn(int $id): array => [$id, $id * 10]),
            0,
            20,
            static fn(array $pair): int => $pair[0],
        );
        $random = new Random(7);

        for ($i = 0; $i < 100; ++$i) {
            $ids = array_column($arbitrary->generate($random)->value, 0);

            Assert::same(array_values(array_unique($ids)), $ids);
        }
    }

    public function keyClosureMakesValuesDistinctThatIdentityWouldNot(): void
    {
        // Every generate() returns a fresh object, so by identity a list of
        // ten is reachable; by the `id` key only three keys exist.
        $objects = new MappedArbitrary(new IntArbitrary(0, 2), static fn(int $id): object => (object) ['id' => $id]);

        $byIdentity = (new UniqueArrayArbitrary($objects, 10, 10))->generate(new Random(3))->value;
        Assert::same(count($byIdentity), 10);

        $byKey = (new UniqueArrayArbitrary($objects, 0, 10, static fn(object $o): int => $o->id))->generate(new Random(3))->value;
        Assert::true(count($byKey) <= 3);
        Assert::same(array_values(array_unique(array_map(static fn(object $o): int => $o->id, $byKey))), array_map(static fn(object $o): int => $o->id, $byKey));
    }

    public function keyClosureShrinkCandidatesNeverShareAKey(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(
                new MappedArbitrary(new IntArbitrary(0, 20), static fn(int $n): array => [$n % 7, $n]),
                0,
                8,
                static fn(array $pair): int => $pair[0],
            ),
            static fn(mixed $v): bool => is_array($v) && count($v) >= 3,
        );

        foreach (Trees::valuesToDepth($node, 3) as $candidate) {
            $keys = array_column($candidate, 0);

            Assert::same(array_values(array_unique($keys)), $keys);
        }
    }

    public function keyClosureShrinkSkipsACandidateCollidingOnKey(): void
    {
        // [8, x] shrinks its id toward 0 first; [0, y] is already present, so
        // the colliding candidate is dropped while the rest of the ladder survives.
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(
                new MappedArbitrary(new IntArbitrary(0, 10), static fn(int $n): array => [$n, 'p']),
                2,
                2,
                static fn(array $pair): int => $pair[0],
            ),
            static fn(mixed $v): bool => $v === [[8, 'p'], [0, 'p']],
        );
        $candidates = Trees::childValues($node);

        Assert::false(in_array([[0, 'p'], [0, 'p']], $candidates, strict: true));
        Assert::true(in_array([[4, 'p'], [0, 'p']], $candidates, strict: true));
    }

    #[DataProvider('nonScalarKeyProvider')]
    public function keyClosureReturningANonScalarKeyIsRefused(\Closure $by, string $type): void
    {
        try {
            (new UniqueArrayArbitrary(new IntArbitrary(0, 10), 1, 3, $by))->generate(new Random(1));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Gen::uniqueArrayOf() key closure must return int|string, got ' . $type);
        }
    }

    public static function nonScalarKeyProvider(): iterable
    {
        yield 'float' => [static fn(int $n): float => $n / 2, 'float'];
        yield 'object' => [static fn(int $n): object => (object) ['n' => $n], 'stdClass'];
        yield 'null' => [static fn(int $n): mixed => null, 'null'];
    }

    public function acceptsMaximumSizeOfOne(): void
    {
        // maxSize === 1 is valid (the boundary of the "at least 1" rule).
        $value = (new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 1, 1))->generate(new Random(1))->value;

        Assert::same(count($value), 1);
    }

    public function exhaustsExactlyTheDrawBudgetWhenTheSpaceIsASingleton(): void
    {
        // One distinct value, fixed drawn size 5: the first draw is accepted,
        // every further draw collides, and the loop stops after exactly
        // size * 10 = 50 draws — pins the budget bounds. The under-filled
        // minimum then throws.
        $inner = new class implements ArbitraryInterface {
            public int $calls = 0;

            #[\Override]
            public function generate(Random $random): Shrinkable
            {
                ++$this->calls;

                return Shrinkable::leaf(1);
            }
        };

        try {
            (new UniqueArrayArbitrary($inner, 5, 5))->generate(new Random(1));

            Assert::fail('expected a GenerationExhaustedException');
        } catch (GenerationExhaustedException $e) {
            Assert::string($e->getMessage())->contains('distinct value');
        }

        Assert::same($inner->calls, 50);
    }

    public function shrinkYieldsTheEmptyArrayExactlyOnce(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 0, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        $empties = array_filter(Trees::childValues($node), static fn(mixed $candidate): bool => $candidate === []);
        Assert::same(count($empties), 1);
    }

    public function shrinkKeepsTheMinimumSizeCandidate(): void
    {
        // With minSize 1 the size floor (one element) is reachable by descent,
        // and the descent never goes below it.
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        $floor = Trees::descendWhile($node, static fn(mixed $v): bool => is_array($v) && count($v) >= 1)->value;

        Assert::true(is_array($floor) && count($floor) === 1);
    }

    public function shrinkNeverEscapesBelowMinimumSize(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(0, 1000), 1, 8),
            static fn(mixed $v): bool => is_array($v) && count($v) === 4,
        );

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::true(count($candidate) >= 1);
        }
    }

    public function shrinkOfEmptyArrayYieldsNothing(): void
    {
        $node = Trees::generateWhere(
            new UniqueArrayArbitrary(new IntArbitrary(), 0, 3),
            static fn(mixed $v): bool => $v === [],
        );

        Assert::same(Trees::childValues($node), []);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMinimumSize(): void
    {
        new UniqueArrayArbitrary(new IntArbitrary(), -1, 5);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsZeroMaximumSize(): void
    {
        new UniqueArrayArbitrary(new IntArbitrary(), 0, 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsInvertedSize(): void
    {
        new UniqueArrayArbitrary(new IntArbitrary(), 10, 2);
    }
}
