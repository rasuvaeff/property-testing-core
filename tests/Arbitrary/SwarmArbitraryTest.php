<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use ArrayObject;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\SwarmArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\SwarmSpy;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The point of a swarm is what a case does NOT contain, so most of these tests
 * assert about the subset rather than the value: it is non-empty, it is drawn
 * once per case, it is sometimes proper, and a shrink descent never escapes it.
 */
#[Test]
#[Covers(SwarmArbitrary::class)]
final class SwarmArbitraryTest
{
    private const array ALPHABET = ['a', 'b', 'c', 'd'];

    public function everyGeneratedValueComesFromTheSourceAlphabet(): void
    {
        $swarm = new SwarmArbitrary(new OneOfArbitrary(...self::ALPHABET));
        $random = new Random(1);

        for ($case = 0; $case < 200; ++$case) {
            Assert::true(in_array($swarm->generate($random)->value, self::ALPHABET, strict: true));
        }
    }

    public function theSubsetIsDrawnOncePerCase(): void
    {
        // One restriction per generated value: the swarm must not re-draw the
        // alphabet while producing a single case, or "this case never used ack"
        // would stop being true of the case as a whole.
        $log = new ArrayObject();
        $swarm = new SwarmArbitrary($this->spy($log));
        $random = new Random(7);

        for ($case = 0; $case < 50; ++$case) {
            $swarm->generate($random);
        }

        Assert::same($log->count(), 50);
    }

    public function everySubsetIsNonEmptyAndWithinTheSource(): void
    {
        // The invariant the whole decorator rests on: a case with no variants
        // left could not produce a value at all.
        $log = new ArrayObject();
        $swarm = new SwarmArbitrary($this->spy($log));
        $random = new Random(3);

        for ($case = 0; $case < 200; ++$case) {
            $swarm->generate($random);
        }

        foreach ($log as $kept) {
            Assert::true($kept !== []);
            Assert::same(array_values(array_unique($kept)), $kept);

            foreach ($kept as $index) {
                Assert::true($index >= 0 && $index < count(self::ALPHABET));
            }
        }
    }

    public function someCasesLoseVariantsAndEveryVariantIsReachedAcrossThem(): void
    {
        // Both halves matter. Without proper subsets a swarm is an expensive
        // no-op; without every variant appearing somewhere it would silently
        // narrow the property's input space instead of varying it.
        $log = new ArrayObject();
        $swarm = new SwarmArbitrary($this->spy($log));
        $random = new Random(11);

        for ($case = 0; $case < 200; ++$case) {
            $swarm->generate($random);
        }

        $proper = 0;
        $seen = [];

        foreach ($log as $kept) {
            if (count($kept) < count(self::ALPHABET)) {
                ++$proper;
            }

            foreach ($kept as $index) {
                $seen[$index] = true;
            }
        }

        Assert::true($proper > 0);
        Assert::same(count($seen), count(self::ALPHABET));
    }

    public function theSameSeedGivesTheSameCase(): void
    {
        $swarm = new SwarmArbitrary(new OneOfArbitrary(...self::ALPHABET));

        $first = [];
        $second = [];

        for ($case = 0; $case < 20; ++$case) {
            $first[] = $swarm->generate(new Random($case))->value;
            $second[] = $swarm->generate(new Random($case))->value;
        }

        Assert::same($first, $second);
    }

    public function shrinkingNeverLeavesTheSubsetTheCaseCameFrom(): void
    {
        // The reason this decorator delegates to the restricted generator
        // instead of shrinking on its own: a counterexample found without 'a'
        // must not shrink into one containing 'a', or the finding "it breaks
        // when 'a' never occurs" stops reproducing.
        $withoutFirstVariant = 0;

        for ($seed = 0; $seed < 200; ++$seed) {
            $log = new ArrayObject();
            $node = (new SwarmArbitrary($this->spy($log)))->generate(new Random($seed));

            /** @var list<int> $kept */
            $kept = $log[0];
            $available = array_map(static fn(int $index): string => self::ALPHABET[$index], $kept);

            foreach (Trees::valuesToDepth($node, 4) as $candidate) {
                Assert::true(in_array($candidate, $available, strict: true));
            }

            if (!in_array(0, $kept, strict: true)) {
                ++$withoutFirstVariant;
            }
        }

        // 'a' is what every OneOf descent gravitates to, so cases that never
        // had it are the ones the assertion above is really about.
        Assert::true($withoutFirstVariant > 0);
    }

    public function aSourceWithOneVariantIsANoOp(): void
    {
        $swarm = new SwarmArbitrary(new OneOfArbitrary('only'));
        $random = new Random(5);

        for ($case = 0; $case < 20; ++$case) {
            Assert::same($swarm->generate($random)->value, 'only');
        }
    }

    public function rejectsAGeneratorThatDoesNotChooseAmongVariants(): void
    {
        // The message names the generators that do work: "not swarmable" is
        // useless to someone who reached for it on Gen::int().
        try {
            new SwarmArbitrary(new IntArbitrary(0, 10));

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same(
                $e->getMessage(),
                'Swarm requires a choice generator (oneOf, elements, frequency, commands), got '
                . IntArbitrary::class,
            );
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsASourceThatReportsNoVariants(): void
    {
        // Reachable only through a hand-written Swarmable: the shipped ones
        // cannot be constructed empty. Without the guard the index range would
        // be range(0, -1), which counts downwards.
        new SwarmArbitrary(new SwarmSpy(new OneOfArbitrary('a'), new ArrayObject(), variants: 0));
    }

    /**
     * @param ArrayObject<int, list<int>> $log
     *
     * @return SwarmSpy<string>
     */
    private function spy(ArrayObject $log): SwarmSpy
    {
        return new SwarmSpy(new OneOfArbitrary(...self::ALPHABET), $log, count(self::ALPHABET));
    }
}
