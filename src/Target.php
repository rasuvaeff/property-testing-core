<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Rasuvaeff\PropertyTesting\Runner\TargetDirection;

/**
 * Reports a score the engine should push toward an extreme: a delay, a
 * recursion depth, a queue length, a distance from a boundary — whatever
 * number gets larger (or smaller) as the input gets closer to where the bug
 * lives. With {@see Runner\PropertyConfig::$searchRuns} on, the run spends
 * a search phase after the random one climbing that score: the best-scoring
 * inputs are kept in a pool and mutated one parameter at a time, and every
 * improvement is reported as a {@see Event\TargetImproved} event.
 *
 * ```php
 * #[Property(runs: 200, searchRuns: 100)]
 * public function backoffStaysUnderCap(int $base, int $attempt): void
 * {
 *     $delay = (new Backoff($base))->delayMs($attempt);
 *     Target::maximize('delay', $delay);
 *
 *     Assert::true($delay <= 60_000);
 * }
 * ```
 *
 * A label's direction is fixed for the whole property: maximising and
 * minimising the same label is a configuration error, reported at the call.
 * So is a score that is not finite. Without a search phase the calls cost
 * one array write per run and change nothing.
 *
 * State is per-run and process-local, like {@see Classify}: the runner
 * clears it before each run and drains it after. Property runs are
 * sequential, so the static buffer is never shared concurrently.
 *
 * @api
 */
final class Target
{
    /**
     * Scores recorded during the current run, by label.
     *
     * @var array<string, float>
     */
    private static array $current = [];

    /**
     * The direction each label was first reported with, for the whole property.
     *
     * @var array<string, TargetDirection>
     */
    private static array $directions = [];

    private function __construct() {}

    /**
     * Push $label toward larger scores.
     */
    public static function maximize(string $label, int|float $score): void
    {
        self::record($label, $score, TargetDirection::Maximize);
    }

    /**
     * Push $label toward smaller scores.
     */
    public static function minimize(string $label, int|float $score): void
    {
        self::record($label, $score, TargetDirection::Minimize);
    }

    private static function record(string $label, int|float $score, TargetDirection $direction): void
    {
        $score = (float) $score;

        if (!is_finite($score)) {
            throw new \InvalidArgumentException(sprintf('Target score for "%s" must be finite, got %s', $label, ValueRenderer::render($score)));
        }

        $known = self::$directions[$label] ?? null;

        if ($known instanceof TargetDirection && $known !== $direction) {
            throw new \InvalidArgumentException(sprintf(
                'Target "%s" was %sd before and cannot be %sd now; a label has one direction for the whole property',
                $label,
                $known->value,
                $direction->value,
            ));
        }

        self::$directions[$label] = $direction;
        self::$current[$label] = $score;
    }

    /**
     * Clear the scores of the previous run.
     *
     * @internal Driven by the property runner.
     */
    public static function beginRun(): void
    {
        self::$current = [];
    }

    /**
     * Return the scores of the current run and clear them.
     *
     * @return array<string, float>
     *
     * @internal Driven by the property runner.
     */
    public static function flushRun(): array
    {
        $scores = self::$current;
        self::$current = [];

        return $scores;
    }

    /**
     * The directions registered so far, by label, in first-reported order.
     *
     * @return array<string, TargetDirection>
     *
     * @internal Driven by the property runner.
     */
    public static function directions(): array
    {
        return self::$directions;
    }

    /**
     * Return the directions registered during the property and clear them.
     *
     * @return array<string, TargetDirection>
     *
     * @internal Driven by the property runner.
     */
    public static function flushDirections(): array
    {
        $directions = self::$directions;
        self::$directions = [];

        return $directions;
    }
}
