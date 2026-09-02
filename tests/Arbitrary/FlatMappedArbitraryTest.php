<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FlatMappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\TupleArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhausted;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FlatMappedArbitrary::class)]
final class FlatMappedArbitraryTest
{
    /**
     * A dependent pair [n, i] with i strictly below n — the canonical flatMap
     * use case (a collection plus a valid index into it).
     */
    private function dependentPair(): FlatMappedArbitrary
    {
        return new FlatMappedArbitrary(
            new IntArbitrary(1, 20),
            static fn(int $n): TupleArbitrary => new TupleArbitrary(
                new ConstantArbitrary($n),
                new IntArbitrary(0, $n - 1),
            ),
        );
    }

    public function generateFeedsTheSourceValueIntoTheDependentArbitrary(): void
    {
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            [$n, $index] = $this->dependentPair()->generate($random)->value;

            Assert::true($n >= 1 && $n <= 20);
            Assert::true($index >= 0 && $index < $n);
        }
    }

    public function everyShrinkCandidateKeepsTheDependencyInvariant(): void
    {
        // Both shrink directions — smaller source (regenerated dependent value)
        // and smaller dependent value (source fixed) — must stay in the domain.
        $node = Trees::generateWhere(
            $this->dependentPair(),
            static fn(mixed $v): bool => is_array($v) && $v[0] >= 10 && $v[1] >= 5,
        );

        foreach (Trees::valuesToDepth($node, 3) as [$n, $index]) {
            Assert::true($n >= 1 && $n <= 20);
            Assert::true($index >= 0 && $index < $n);
        }
    }

    public function shrinkOffersSourceCandidatesBeforeDependentOnes(): void
    {
        // With a constant dependent arbitrary the value mirrors the source, so
        // the first candidate must come from the source ladder (target 1).
        $arbitrary = new FlatMappedArbitrary(
            new IntArbitrary(1, 20),
            static fn(int $n): ConstantArbitrary => new ConstantArbitrary($n * 10),
        );
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => is_int($v) && $v > 10);

        Assert::same(Trees::childValues($node)[0], 10);
    }

    public function sourceShrinkRegeneratesTheDependentValueDeterministically(): void
    {
        // The captured seed makes the whole tree a pure value: walking it twice
        // yields identical candidates.
        $node = Trees::generateWhere(
            $this->dependentPair(),
            static fn(mixed $v): bool => is_array($v) && $v[0] >= 10 && $v[1] >= 5,
        );

        Assert::same(Trees::valuesToDepth($node, 2), Trees::valuesToDepth($node, 2));
    }

    public function generateIsReproducibleForAGivenSeed(): void
    {
        Assert::same(
            $this->dependentPair()->generate(new Random(42))->value,
            $this->dependentPair()->generate(new Random(42))->value,
        );
    }

    public function greedyDescentMinimisesTheDependentValue(): void
    {
        // Dependent value m in [0, n]; the monotone predicate "m > 3" must
        // minimise to exactly 4 via the dependent ladder (source held fixed or
        // shrunk when the regenerated value still fails).
        $arbitrary = new FlatMappedArbitrary(
            new IntArbitrary(1, 10),
            static fn(int $n): IntArbitrary => new IntArbitrary(0, $n),
        );
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => is_int($v) && $v > 3);
        $minimal = Trees::descendWhile($node, static fn(mixed $v): bool => is_int($v) && $v > 3);

        Assert::same($minimal->value, 4);
    }

    public function aSourceCandidateTheDependentSideCannotSatisfyIsSkipped(): void
    {
        // m < n: shrinking the source n to 0 leaves the filter unsatisfiable,
        // which exhausts generation. That candidate is no value at all and is
        // skipped; the remaining source candidates and the dependent ladder
        // are still offered, and none of them breaks the invariant.
        $arbitrary = new FlatMappedArbitrary(
            new IntArbitrary(0, 10),
            static fn(int $n): ArbitraryInterface => Gen::filter(new IntArbitrary(0, 10), static fn(int $m): bool => $m < $n),
        );

        $node = null;

        for ($seed = 0; $seed < 1_000 && !$node instanceof Shrinkable; ++$seed) {
            try {
                $generated = $arbitrary->generate(new Random($seed));
            } catch (GenerationExhausted) {
                continue;
            }

            if (is_int($generated->value) && $generated->value >= 2) {
                $node = $generated;
            }
        }

        Assert::instanceOf($node, Shrinkable::class);

        $children = Trees::childValues($node);

        Assert::true($children !== []);

        foreach ($children as $child) {
            Assert::true(is_int($child) && $child < 10);
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAClosureNotReturningAnArbitrary(): void
    {
        (new FlatMappedArbitrary(
            new IntArbitrary(1, 5),
            static fn(int $n): int => $n,
        ))->generate(new Random(1));
    }
}
