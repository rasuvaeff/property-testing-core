<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * The best-scoring inputs of every target label, capped and sorted best
 * first — what the search phase mutates and what the search corpus stores.
 *
 * @internal Driven by the property runner.
 */
final class SearchPool
{
    public const int CAPACITY = 8;

    /**
     * @var array<string, TargetDirection>
     */
    private array $directions = [];

    /**
     * @var array<string, list<array{score: float, trees: array<string, Shrinkable>}>>
     */
    private array $entries = [];

    /**
     * @var array<string, int>
     */
    private array $improvements = [];

    /**
     * @var array<string, int>
     */
    private array $recalled = [];

    /**
     * Offer a passing input's score for $label; true when it is a new best.
     *
     * @param string $label The target label.
     * @param TargetDirection $direction Which way the label is pushed.
     * @param float $score The input's score.
     * @param array<string, Shrinkable> $trees The input, as the trees its shrink descent needs.
     */
    public function offer(string $label, TargetDirection $direction, float $score, array $trees): bool
    {
        $this->directions[$label] = $direction;
        $entries = $this->entries[$label] ?? [];
        $best = $entries[0]['score'] ?? null;
        $improved = $best === null || $direction->improves($score, $best);

        $entries[] = ['score' => $score, 'trees' => $trees];
        usort($entries, static fn(array $a, array $b): int => $direction === TargetDirection::Maximize
            ? $b['score'] <=> $a['score']
            : $a['score'] <=> $b['score']);
        $this->entries[$label] = array_slice($entries, 0, self::CAPACITY);

        if ($improved) {
            $this->improvements[$label] = ($this->improvements[$label] ?? 0) + 1;
        }

        return $improved;
    }

    /**
     * Seed the pool with stored inputs; they count as neither improvements
     * nor evaluations, and shrink only through what the search regenerates.
     *
     * @param string $label The target label.
     * @param TargetDirection $direction Which way the label is pushed.
     * @param list<array{score: float, arguments: array<string, mixed>}> $entries The stored inputs.
     */
    public function recall(string $label, TargetDirection $direction, array $entries): void
    {
        foreach ($entries as $entry) {
            $this->offer($label, $direction, $entry['score'], array_map(static fn(mixed $value): Shrinkable => Shrinkable::leaf($value), $entry['arguments']));
            $this->improvements[$label] = 0;
        }

        $this->recalled[$label] = ($this->recalled[$label] ?? 0) + count($entries);
    }

    /**
     * The labels with at least one input to start from.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_keys($this->entries);
    }

    /**
     * An input to mutate for $label, biased toward the better ones: the
     * smaller of two uniform picks.
     *
     * @param string $label A label with at least one entry.
     * @param Random $random The run's stream.
     *
     * @return array<string, Shrinkable>
     */
    public function pick(string $label, Random $random): array
    {
        $entries = $this->entries[$label];
        $last = count($entries) - 1;
        $index = min($random->int(0, $last), $random->int(0, $last));

        return $entries[$index]['trees'];
    }

    /**
     * The best score of $label, or null when none was offered.
     *
     * @param string $label The target label.
     */
    public function best(string $label): ?float
    {
        return $this->entries[$label][0]['score'] ?? null;
    }

    /**
     * How many times the best of $label improved.
     *
     * @param string $label The target label.
     */
    public function improvements(string $label): int
    {
        return $this->improvements[$label] ?? 0;
    }

    /**
     * How many stored inputs seeded $label.
     *
     * @param string $label The target label.
     */
    public function recalledCount(string $label): int
    {
        return $this->recalled[$label] ?? 0;
    }

    /**
     * The direction $label was offered with, or null when it never was.
     *
     * @param string $label The target label.
     */
    public function direction(string $label): ?TargetDirection
    {
        return $this->directions[$label] ?? null;
    }

    /**
     * The pool as the search corpus stores it.
     *
     * @return array<string, array{direction: TargetDirection, entries: list<array{score: float, arguments: array<string, mixed>}>}>
     */
    public function export(): array
    {
        $targets = [];

        foreach ($this->entries as $label => $entries) {
            $targets[$label] = [
                'direction' => $this->directions[$label],
                'entries' => array_map(static fn(array $entry): array => [
                    'score' => $entry['score'],
                    'arguments' => array_map(static fn(Shrinkable $tree): mixed => $tree->value, $entry['trees']),
                ], $entries),
            ];
        }

        return $targets;
    }
}
