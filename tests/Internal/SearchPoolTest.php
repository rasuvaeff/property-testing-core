<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\SearchPool;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SearchPool::class)]
final class SearchPoolTest
{
    /** @return array<string, Shrinkable> */
    private function trees(int $n): array
    {
        return ['n' => Shrinkable::leaf($n)];
    }

    public function keepsTheBestFirstAndReportsImprovements(): void
    {
        $pool = new SearchPool();

        Assert::true($pool->offer('up', TargetDirection::Maximize, 5.0, $this->trees(5)));
        Assert::false($pool->offer('up', TargetDirection::Maximize, 3.0, $this->trees(3)));
        Assert::true($pool->offer('up', TargetDirection::Maximize, 9.0, $this->trees(9)));
        Assert::false($pool->offer('up', TargetDirection::Maximize, 9.0, $this->trees(9)));

        Assert::same($pool->best('up'), 9.0);
        Assert::same($pool->improvements('up'), 2);
        Assert::same($pool->direction('up'), TargetDirection::Maximize);
        Assert::same(array_map(static fn(array $e): float => $e['score'], $pool->export()['up']['entries']), [9.0, 9.0, 5.0, 3.0]);
    }

    public function countsOnlyImprovementsNotEveryOffer(): void
    {
        $pool = new SearchPool();
        $pool->offer('up', TargetDirection::Maximize, 1.0, $this->trees(1));
        $pool->offer('up', TargetDirection::Maximize, 2.0, $this->trees(2));
        $pool->offer('up', TargetDirection::Maximize, 3.0, $this->trees(3));
        $pool->offer('up', TargetDirection::Maximize, 0.0, $this->trees(0));

        Assert::same($pool->improvements('up'), 3);
        Assert::same($pool->export()['up']['direction'], TargetDirection::Maximize);
    }

    public function recallsAccumulateAcrossCallsAndExportKeepsEveryLabel(): void
    {
        $pool = new SearchPool();
        $pool->recall('up', TargetDirection::Maximize, [['score' => 1.0, 'arguments' => ['n' => 1]], ['score' => 2.0, 'arguments' => ['n' => 2]]]);
        $pool->recall('up', TargetDirection::Maximize, [['score' => 3.0, 'arguments' => ['n' => 3]]]);
        $pool->offer('down', TargetDirection::Minimize, 5.0, $this->trees(5));

        Assert::same($pool->recalledCount('up'), 3);
        Assert::same(array_keys($pool->export()), ['up', 'down']);
        Assert::same($pool->export()['down']['direction'], TargetDirection::Minimize);
    }

    public function picksTheOnlyEntryOfASingletonPool(): void
    {
        $pool = new SearchPool();
        $pool->offer('up', TargetDirection::Maximize, 1.0, $this->trees(1));
        $random = new Random(3);

        for ($i = 0; $i < 20; ++$i) {
            Assert::same($pool->pick('up', $random)['n']->value, 1);
        }
    }

    public function pickReachesTheLastEntryToo(): void
    {
        $pool = new SearchPool();
        $pool->offer('up', TargetDirection::Maximize, 2.0, $this->trees(2));
        $pool->offer('up', TargetDirection::Maximize, 1.0, $this->trees(1));
        $random = new Random(3);
        $seen = [];

        for ($i = 0; $i < 100; ++$i) {
            $seen[$pool->pick('up', $random)['n']->value] = true;
        }

        $values = array_keys($seen);
        sort($values);
        Assert::same($values, [1, 2]);
    }

    public function minimisationSortsTheOtherWay(): void
    {
        $pool = new SearchPool();
        $pool->offer('down', TargetDirection::Minimize, 5.0, $this->trees(5));
        $pool->offer('down', TargetDirection::Minimize, 8.0, $this->trees(8));
        Assert::true($pool->offer('down', TargetDirection::Minimize, 1.0, $this->trees(1)));

        Assert::same($pool->best('down'), 1.0);
        Assert::same(array_map(static fn(array $e): float => $e['score'], $pool->export()['down']['entries']), [1.0, 5.0, 8.0]);
    }

    public function isCappedAtItsCapacity(): void
    {
        $pool = new SearchPool();

        for ($i = 0; $i < 20; ++$i) {
            $pool->offer('up', TargetDirection::Maximize, (float) $i, $this->trees($i));
        }

        $scores = array_map(static fn(array $e): float => $e['score'], $pool->export()['up']['entries']);
        Assert::same(count($scores), SearchPool::CAPACITY);
        Assert::same($scores[0], 19.0);
        Assert::same($scores[SearchPool::CAPACITY - 1], 12.0);
    }

    public function recalledEntriesSeedThePoolWithoutCountingAsImprovements(): void
    {
        $pool = new SearchPool();
        $pool->recall('up', TargetDirection::Maximize, [
            ['score' => 4.0, 'arguments' => ['n' => 4]],
            ['score' => 7.0, 'arguments' => ['n' => 7]],
        ]);

        Assert::same($pool->best('up'), 7.0);
        Assert::same($pool->improvements('up'), 0);
        Assert::same($pool->recalledCount('up'), 2);
        Assert::same($pool->labels(), ['up']);
        $picked = $pool->pick('up', new Random(1))['n'];
        Assert::true(in_array($picked->value, [4, 7], strict: true));
        // A recalled input is a leaf: nothing to shrink through but what the search regenerates.
        Assert::same(iterator_to_array($picked->shrinks(), preserve_keys: false), []);
        Assert::same($pool->export()['up']['entries'][0]['arguments'], ['n' => 7]);
    }

    public function pickPrefersBetterEntries(): void
    {
        $pool = new SearchPool();

        for ($i = 0; $i < SearchPool::CAPACITY; ++$i) {
            $pool->offer('up', TargetDirection::Maximize, (float) $i, $this->trees($i));
        }

        $random = new Random(5);
        $picks = [];

        for ($i = 0; $i < 400; ++$i) {
            $picks[] = $pool->pick('up', $random)['n']->value;
        }

        $topHalf = count(array_filter($picks, static fn(int $n): bool => $n >= 4));
        Assert::true($topHalf > 250);
    }

    public function anUnknownLabelHasNoBest(): void
    {
        $pool = new SearchPool();

        Assert::null($pool->best('none'));
        Assert::same($pool->improvements('none'), 0);
        Assert::same($pool->recalledCount('none'), 0);
        Assert::null($pool->direction('none'));
        Assert::same($pool->export(), []);
    }
}
