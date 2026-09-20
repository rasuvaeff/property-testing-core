<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\CompositeArbitrary;
use Rasuvaeff\PropertyTesting\Draw;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CompositeArbitrary::class)]
#[Covers(Draw::class)]
final class CompositeArbitraryTest
{
    /** @return CompositeArbitrary<array{0: int, 1: int}> */
    private function orderedPair(): CompositeArbitrary
    {
        return new CompositeArbitrary(static function (Draw $d): array {
            $min = $d->draw(Gen::intBetween(0, 100));
            $max = $d->draw(Gen::intBetween($min, 100));

            return [$min, $max];
        });
    }

    public function laterDrawsSeeEarlierOnes(): void
    {
        $random = new Random(1);

        for ($i = 0; $i < 200; ++$i) {
            [$min, $max] = $this->orderedPair()->generate($random)->value;

            Assert::true($min <= $max);
        }
    }

    public function isDeterministicForASeed(): void
    {
        $first = $this->orderedPair()->generate(new Random(9))->value;
        $second = $this->orderedPair()->generate(new Random(9))->value;

        Assert::same($first, $second);
    }

    public function consumesOneSeedFromTheOuterStreamRegardlessOfHowMuchTheBodyDraws(): void
    {
        $lean = new CompositeArbitrary(static fn(Draw $d): int => $d->draw(Gen::intBetween(0, 10)));
        $greedy = new CompositeArbitrary(static function (Draw $d): int {
            for ($i = 0; $i < 50; ++$i) {
                $d->draw(Gen::intBetween(0, 10));
            }

            return 0;
        });

        $afterLean = new Random(4);
        $lean->generate($afterLean);
        $afterGreedy = new Random(4);
        $greedy->generate($afterGreedy);

        Assert::same($afterLean->int(0, PHP_INT_MAX), $afterGreedy->int(0, PHP_INT_MAX));
    }

    public function everyShrinkCandidateIsAValueTheBodyWouldBuild(): void
    {
        $node = Trees::generateWhere(
            $this->orderedPair(),
            static fn(mixed $v): bool => $v[1] - $v[0] >= 20,
        );

        foreach (Trees::valuesToDepth($node, 3) as [$min, $max]) {
            Assert::true($min <= $max);
        }
    }

    public function shrinksTheEarliestDrawFirst(): void
    {
        $node = Trees::generateWhere(
            $this->orderedPair(),
            static fn(mixed $v): bool => $v[0] >= 10 && $v[1] - $v[0] >= 10,
        );
        [$min, $max] = $node->value;

        // The first candidate replaces the first draw with its first
        // candidate (0) and re-derives the second over the wider range.
        $first = Trees::childValues($node)[0];
        Assert::same($first[0], 0);
        Assert::true($first[1] <= 100);
    }

    public function descendsToAMinimalDependentValue(): void
    {
        $node = Trees::generateWhere(
            $this->orderedPair(),
            static fn(mixed $v): bool => $v[1] - $v[0] >= 5,
        );

        $minimal = Trees::descendWhile($node, static fn(mixed $v): bool => $v[1] - $v[0] >= 5);

        Assert::same($minimal->value, [0, 5]);
    }

    public function aSmallerPrefixRegrowsTheTapeDeterministically(): void
    {
        // n, then n values: shrinking n truncates the tape; growing it back
        // (a smaller-prefix candidate that makes the body draw more) regenerates
        // from the captured seed, so the same candidate is built every time.
        $arbitrary = new CompositeArbitrary(static function (Draw $d): array {
            $n = $d->draw(Gen::intBetween(0, 5));
            $values = [];

            for ($i = 0; $i < $n; ++$i) {
                $values[] = $d->draw(Gen::intBetween(0, 100));
            }

            return $values;
        });
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => count($v) === 5);

        $once = Trees::childValues($node);
        $twice = Trees::childValues($node);

        Assert::same($once, $twice);
        Assert::same($once[0], []);
        foreach ($once as $candidate) {
            Assert::true(count($candidate) <= 5);
        }
    }

    public function aBodyThatRefusesASmallerDrawSkipsThatCandidate(): void
    {
        $arbitrary = new CompositeArbitrary(static function (Draw $d): int {
            $n = $d->draw(Gen::intBetween(0, 100));

            if ($n > 0 && $n < 3) {
                throw new \InvalidArgumentException('too small');
            }

            return $n;
        });

        // The body refuses at generation time too; find a seed it accepts.
        $node = null;
        for ($seed = 0; !$node instanceof \Rasuvaeff\PropertyTesting\Shrinkable; ++$seed) {
            try {
                $candidate = $arbitrary->generate(new Random($seed));
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($candidate->value >= 50) {
                $node = $candidate;
            }
        }

        $candidates = Trees::valuesToDepth($node, 3);
        Assert::true(in_array(0, $candidates, strict: true));
        foreach ($candidates as $candidate) {
            Assert::true($candidate === 0 || $candidate >= 3);
        }
    }

    public function aRefusalAtGenerationTimePropagates(): void
    {
        $arbitrary = new CompositeArbitrary(static function (Draw $d): int {
            $d->draw(Gen::intBetween(0, 100));

            throw new \InvalidArgumentException('never');
        });

        try {
            $arbitrary->generate(new Random(1));

            Assert::fail('expected the InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'never');
        }
    }

    public function anErrorInTheBodyPropagates(): void
    {
        $arbitrary = new CompositeArbitrary(static function (Draw $d): int {
            $n = $d->draw(Gen::intBetween(0, 100));

            if ($n === 0) {
                throw new \TypeError('broken body');
            }

            return $n;
        });
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => $v >= 50);

        try {
            iterator_to_array($node->shrinks(), preserve_keys: false);

            Assert::fail('expected the TypeError');
        } catch (\TypeError $e) {
            Assert::same($e->getMessage(), 'broken body');
        }
    }

    public function aCandidateThatRebuildsTheSameValueIsSkipped(): void
    {
        // The body collapses every draw to one of two values, so most
        // shrunk tapes rebuild the parent's value and must not be yielded.
        $arbitrary = new CompositeArbitrary(static fn(Draw $d): int => $d->draw(Gen::intBetween(0, 100)) >= 50 ? 1 : 0);
        $node = Trees::generateWhere($arbitrary, static fn(mixed $v): bool => $v === 1);

        foreach (Trees::valuesToDepth($node, 2) as $candidate) {
            Assert::same($candidate, 0);
        }

        // Skipped, not stopped at: a later candidate that does change the
        // value is still reached. Parity of 64: every halving candidate
        // (0, 32, 48, ...) is even like the parent until the last one, 63.
        $parity = new CompositeArbitrary(static fn(Draw $d): int => $d->draw(Gen::intBetween(0, 100)) % 2);
        $even = null;
        for ($seed = 0; $even === null; ++$seed) {
            $candidate = $parity->generate(new Random($seed));

            if ($candidate->value === 0 && Trees::childValues($candidate) !== []) {
                $even = $candidate;
            }
        }

        // The first candidate, 0, is even like the parent; the odd ones after it survive.
        Assert::same(array_values(array_unique(Trees::childValues($even))), [1]);
    }

    public function positionsAreKeyedApartFromTheSeedItself(): void
    {
        // Seed 1 at position 12 and seed 11 at position 2 would share a stream
        // if the seed and the position were merely concatenated.
        $first = new Draw(1, EdgeCases::Mixin);
        $second = new Draw(11, EdgeCases::Mixin);
        $twelfth = null;
        for ($i = 0; $i < 13; ++$i) {
            $twelfth = $first->draw(Gen::intBetween(0, 1_000_000));
        }
        $second->draw(Gen::intBetween(0, 1_000_000));
        $second->draw(Gen::intBetween(0, 1_000_000));
        $third = $second->draw(Gen::intBetween(0, 1_000_000));

        Assert::true($twelfth !== $third);
    }

    public function descentDepthIsCapped(): void
    {
        $pair = static fn(Draw $d): array => [$d->draw(Gen::intBetween(1, 1000)), $d->draw(Gen::intBetween(1, 1000))];
        $bothAboveOne = static fn(mixed $v): bool => $v[0] > 1 && $v[1] > 1;
        $always = static fn(mixed $v): bool => true;

        // Accepting every first candidate: step 1 takes the first draw to 1
        // (the second comes back as it was), step 2 takes the second to 1.
        $deep = Trees::descendWhile(Trees::generateWhere(new CompositeArbitrary($pair), $bothAboveOne), $always);
        Assert::same($deep->value, [1, 1]);

        $capped = Trees::generateWhere(new CompositeArbitrary($pair, maxDepth: 1), $bothAboveOne);
        $shallow = Trees::descendWhile($capped, $always);
        Assert::same($shallow->value, [1, $capped->value[1]]);
    }

    public function eachAcceptedStepCostsExactlyOneLevelOfDepth(): void
    {
        $triple = static fn(Draw $d): array => [$d->draw(Gen::intBetween(1, 1000)), $d->draw(Gen::intBetween(1, 1000)), $d->draw(Gen::intBetween(1, 1000))];
        $allAboveOne = static fn(mixed $v): bool => $v[0] > 1 && $v[1] > 1 && $v[2] > 1;
        $always = static fn(mixed $v): bool => true;

        $capped = Trees::generateWhere(new CompositeArbitrary($triple, maxDepth: 2), $allAboveOne);
        $shallow = Trees::descendWhile($capped, $always);

        Assert::same($shallow->value, [1, 1, $capped->value[2]]);
    }

    public function aRefusedCandidateDoesNotEndTheEnumeration(): void
    {
        $arbitrary = new CompositeArbitrary(static function (Draw $d): int {
            $n = $d->draw(Gen::intBetween(0, 100));

            if ($n === 0) {
                throw new \InvalidArgumentException('zero refused');
            }

            return $n;
        });
        $node = null;
        for ($seed = 0; $node === null; ++$seed) {
            try {
                $candidate = $arbitrary->generate(new Random($seed));
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($candidate->value >= 64) {
                $node = $candidate;
            }
        }

        // The first candidate (0) is refused; the halving ladder after it survives.
        $children = Trees::childValues($node);
        Assert::true(count($children) >= 2);
        Assert::false(in_array(0, $children, strict: true));
    }

    public function twoDrawsAtDifferentPositionsUseDifferentStreams(): void
    {
        $arbitrary = new CompositeArbitrary(static fn(Draw $d): array => [$d->draw(Gen::intBetween(0, 1_000_000)), $d->draw(Gen::intBetween(0, 1_000_000))]);
        $differ = 0;

        for ($seed = 0; $seed < 20; ++$seed) {
            [$a, $b] = $arbitrary->generate(new Random($seed))->value;
            $differ += $a === $b ? 0 : 1;
        }

        Assert::true($differ >= 19);
    }

    public function honoursTheEdgeCaseModeOfTheOuterStream(): void
    {
        $arbitrary = new CompositeArbitrary(static fn(Draw $d): int => $d->draw(Gen::intBetween(1, 1_000_000)));
        $boundaries = 0;

        $random = new Random(2, EdgeCases::None);
        for ($i = 0; $i < 300; ++$i) {
            $value = $arbitrary->generate($random)->value;
            if ($value === 1 || $value === 1_000_000) {
                ++$boundaries;
            }
        }
        Assert::same($boundaries, 0);

        $random = new Random(2);
        for ($i = 0; $i < 300; ++$i) {
            $value = $arbitrary->generate($random)->value;
            if ($value === 1 || $value === 1_000_000) {
                ++$boundaries;
            }
        }
        Assert::true($boundaries > 0);
    }

    public function drawReplaysATapeByPositionAndGeneratesPastIt(): void
    {
        $recorded = new Draw(1, EdgeCases::Mixin);
        $recorded->draw(Gen::intBetween(0, 10));
        $second = $recorded->draw(Gen::intBetween(0, 1_000_000));
        $tape = $recorded->recorded();

        $replay = new Draw(1, EdgeCases::Mixin, [$tape[0]]);
        Assert::same($replay->draw(Gen::intBetween(0, 10)), $tape[0]->value);
        // Position 1 draws from its own stream: the same choice as before.
        Assert::same($replay->draw(Gen::intBetween(0, 1_000_000)), $second);

        Assert::same(count($replay->recorded()), 2);
        Assert::same($replay->recorded()[0], $tape[0]);
    }

    public function aDrawAfterAShrunkOneIsRedrawnThroughTheNewRange(): void
    {
        $node = Trees::generateWhere(
            $this->orderedPair(),
            static fn(mixed $v): bool => $v[0] >= 10 && $v[1] - $v[0] >= 10 && $v[1] < 100,
        );

        // Shrinking min to 0 re-draws max over [0, 100] from position 1's own
        // stream: within the new range, and the same on every enumeration.
        $first = Trees::childValues($node)[0];
        Assert::same($first[0], 0);
        Assert::true($first[1] >= 0 && $first[1] <= 100);
        Assert::same(Trees::childValues($node)[0], $first);
    }
}
