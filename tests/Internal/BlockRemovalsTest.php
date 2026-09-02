<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\BlockRemovals;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(BlockRemovals::class)]
final class BlockRemovalsTest
{
    #[DataProvider('removalsProvider')]
    public function removalsAreLongestFirstAlignedAndBounded(int $count, int $minSize, array $expected): void
    {
        Assert::same(iterator_to_array(BlockRemovals::of($count, $minSize), preserve_keys: false), $expected);
    }

    /**
     * @return iterable<string, array{int, int, list<array{int, int}>}>
     */
    public static function removalsProvider(): iterable
    {
        yield 'four, unbounded' => [4, 0, [[0, 4], [0, 2], [2, 2], [0, 1], [1, 1], [2, 1], [3, 1]]];
        yield 'five, unbounded: the odd tail is only removed alone' => [5, 0, [[0, 5], [0, 2], [2, 2], [0, 1], [1, 1], [2, 1], [3, 1], [4, 1]]];
        yield 'four, at least one' => [4, 1, [[0, 2], [2, 2], [0, 1], [1, 1], [2, 1], [3, 1]]];
        yield 'four, at least three' => [4, 3, [[0, 1], [1, 1], [2, 1], [3, 1]]];
        yield 'four, at least four' => [4, 4, []];
        yield 'one, unbounded' => [1, 0, [[0, 1]]];
        yield 'empty' => [0, 0, []];
    }

    public function everyRemovalStrictlyShortensAndRespectsTheFloor(): void
    {
        for ($count = 0; $count <= 40; ++$count) {
            for ($minSize = 0; $minSize <= $count; ++$minSize) {
                foreach (BlockRemovals::of($count, $minSize) as [$offset, $length]) {
                    Assert::true($length >= 1);
                    Assert::true($offset >= 0 && $offset + $length <= $count);
                    Assert::true($count - $length >= $minSize);
                }
            }
        }
    }
}
