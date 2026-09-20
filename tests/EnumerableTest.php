<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\EdgeCasedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FilteredArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\MappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\NullableArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\RecordArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\StringArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\TupleArbitrary;
use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Currency;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The finite-domain seam behind exhaustive mode: every built-in enumerable
 * counts what it walks, walks what it counts, and hands out the same shrink
 * trees generation would.
 */
#[Test]
#[Covers(ConstantArbitrary::class)]
#[Covers(BoolArbitrary::class)]
#[Covers(IntArbitrary::class)]
#[Covers(OneOfArbitrary::class)]
#[Covers(NullableArbitrary::class)]
#[Covers(TupleArbitrary::class)]
#[Covers(RecordArbitrary::class)]
#[Covers(MappedArbitrary::class)]
#[Covers(FilteredArbitrary::class)]
#[Covers(EdgeCasedArbitrary::class)]
final class EnumerableTest
{
    /**
     * @return list<mixed>
     */
    private function walk(Enumerable $enumerable): array
    {
        $values = [];

        foreach ($enumerable->enumerate() as $node) {
            Assert::instanceOf($node, Shrinkable::class);
            $values[] = $node->value;
        }

        return $values;
    }

    #[DataProvider('domainProvider')]
    public function countsWhatItWalks(Enumerable $enumerable, array $expected): void
    {
        Assert::same($enumerable->domainSize(), count($expected));
        Assert::same($this->walk($enumerable), $expected);
    }

    public static function domainProvider(): iterable
    {
        yield 'constant' => [new ConstantArbitrary('x'), ['x']];
        yield 'bool' => [new BoolArbitrary(), [false, true]];
        yield 'int' => [new IntArbitrary(-2, 2), [-2, -1, 0, 1, 2]];
        yield 'one int' => [new IntArbitrary(7, 7), [7]];
        yield 'oneOf' => [new OneOfArbitrary('b', 'a', 'b'), ['b', 'a', 'b']];
        yield 'enum' => [Gen::enum(Currency::class), Currency::cases()];
        yield 'nullable' => [new NullableArbitrary(new BoolArbitrary()), [null, false, true]];
        yield 'tuple' => [new TupleArbitrary(new BoolArbitrary(), new IntArbitrary(0, 1)), [[false, 0], [false, 1], [true, 0], [true, 1]]];
        yield 'record' => [new RecordArbitrary(['on' => new BoolArbitrary(), 'n' => new IntArbitrary(0, 1)]), [['on' => false, 'n' => 0], ['on' => false, 'n' => 1], ['on' => true, 'n' => 0], ['on' => true, 'n' => 1]]];
        yield 'map' => [new MappedArbitrary(new IntArbitrary(0, 2), static fn(int $n): int => $n * 10), [0, 10, 20]];
        yield 'edge cases' => [new EdgeCasedArbitrary(new IntArbitrary(0, 2), [2]), [0, 1, 2]];
    }

    public function aFilterWalksOnlyWhatItsPredicateAcceptsAndReportsAnUpperBound(): void
    {
        $even = new FilteredArbitrary(new IntArbitrary(0, 9), static fn(int $n): bool => $n % 2 === 0);

        Assert::same($even->domainSize(), 10);
        Assert::same($this->walk($even), [0, 2, 4, 6, 8]);
    }

    public function anUnboundedIntSaturatesAtTheIntegerMaximum(): void
    {
        Assert::same((new IntArbitrary())->domainSize(), PHP_INT_MAX);
        Assert::same((new IntArbitrary(PHP_INT_MIN, PHP_INT_MAX - 1))->domainSize(), PHP_INT_MAX);
        Assert::same((new IntArbitrary(0, PHP_INT_MAX - 1))->domainSize(), PHP_INT_MAX);
        Assert::same((new IntArbitrary(1, PHP_INT_MAX - 1))->domainSize(), PHP_INT_MAX - 1);
    }

    public function aWrapperOverAnUnboundedInnerDeclines(): void
    {
        Assert::null((new NullableArbitrary(new StringArbitrary()))->domainSize());
        Assert::null((new TupleArbitrary(new BoolArbitrary(), new StringArbitrary()))->domainSize());
        Assert::null((new RecordArbitrary(['s' => new StringArbitrary()]))->domainSize());
        Assert::null((new MappedArbitrary(new StringArbitrary(), static fn(string $s): int => strlen($s)))->domainSize());
        Assert::null((new FilteredArbitrary(new StringArbitrary(), static fn(string $s): bool => true))->domainSize());
        Assert::null((new EdgeCasedArbitrary(new StringArbitrary(), ['']))->domainSize());
    }

    #[DataProvider('unwalkableProvider')]
    public function walkingAWrapperOverAnUnboundedInnerIsRefused(Enumerable $enumerable, string $message): void
    {
        try {
            iterator_to_array($enumerable->enumerate(), preserve_keys: false);

            Assert::fail('expected a LogicException');
        } catch (\LogicException $e) {
            Assert::same($e->getMessage(), $message);
        }
    }

    public static function unwalkableProvider(): iterable
    {
        yield 'nullable' => [new NullableArbitrary(new StringArbitrary()), 'Gen::nullable(): the inner generator has no finite domain to enumerate'];
        yield 'tuple' => [new TupleArbitrary(new BoolArbitrary(), new StringArbitrary()), 'Gen::tuple(): an element generator has no finite domain to enumerate'];
        yield 'record' => [new RecordArbitrary(['ok' => new BoolArbitrary(), 's' => new StringArbitrary()]), 'Gen::record(): field "s" has no finite domain to enumerate'];
        yield 'map' => [new MappedArbitrary(new StringArbitrary(), static fn(string $s): int => strlen($s)), 'Gen::map(): the source generator has no finite domain to enumerate'];
        yield 'filter' => [new FilteredArbitrary(new StringArbitrary(), static fn(string $s): bool => true), 'Gen::filter(): the source generator has no finite domain to enumerate'];
        yield 'edge cases' => [new EdgeCasedArbitrary(new StringArbitrary(), ['']), 'Gen::withEdgeCases(): the inner generator has no finite domain to enumerate'];
    }

    public function enumeratedNodesCarryTheSameTreesGenerationGives(): void
    {
        $nodes = iterator_to_array((new IntArbitrary(0, 10))->enumerate(), preserve_keys: false);
        $generated = Trees::generateWhere(new IntArbitrary(0, 10), static fn(mixed $v): bool => $v === 7);

        Assert::same(Trees::childValues($nodes[7]), Trees::childValues($generated));

        $bools = iterator_to_array((new BoolArbitrary())->enumerate(), preserve_keys: false);
        Assert::same(Trees::childValues($bools[1]), [false]);
        Assert::same(Trees::childValues($bools[0]), []);

        $wrapped = iterator_to_array((new EdgeCasedArbitrary(new IntArbitrary(0, 3), [3]))->enumerate(), preserve_keys: false);
        Assert::same(Trees::childValues($wrapped[2])[0], 3);

        $nullable = iterator_to_array((new NullableArbitrary(new BoolArbitrary()))->enumerate(), preserve_keys: false);
        Assert::same(Trees::childValues($nullable[2]), [null, false]);
    }
}
