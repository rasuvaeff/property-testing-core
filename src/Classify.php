<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

/**
 * Records distribution labels for the current property run so the runner can
 * report how often each case occurred. Use it to confirm a property is not
 * passing vacuously — that the generators actually exercise the interesting
 * inputs.
 *
 * ```php
 * #[Property(runs: 500)]
 * public function holds(int $n): void
 * {
 *     Classify::when($n === 0, 'zero');
 *     Classify::when($n < 0, 'negative');
 *     Classify::label($n % 2 === 0 ? 'even' : 'odd');
 *     // ... assertions ...
 * }
 * ```
 *
 * After a fully passing property the runner prints the share of runs that hit
 * each label. A label recorded several times within one run still counts once
 * for that run.
 *
 * State is per-run and process-local: the runner clears it before each run via
 * {@see beginRun()} and drains it via {@see flushRun()}. Property runs are
 * sequential, so the static buffer is never shared concurrently.
 *
 * @api
 */
final class Classify
{
    /**
     * Labels recorded during the current run (used as a set).
     *
     * Keyed by label, and typed `array-key` rather than `string` because PHP
     * stores a numeric label such as `'42'` under an integer key. The type
     * says what the array can hold; {@see flushRun()} is where the label is
     * handed back as the string the body recorded.
     *
     * @var array<array-key, true>
     */
    private static array $current = [];

    /**
     * Minimum coverage requirements registered during the current property
     * (label => required percentage of passing runs). Keyed as `array-key`
     * for the same reason as {@see $current}.
     *
     * @var array<array-key, float>
     */
    private static array $requirements = [];

    /**
     * Tags recorded during the current run through {@see tabulate()}, by
     * table (each table used as a set). `array-key` for the same reason
     * {@see $current} is.
     *
     * @var array<string, array<array-key, true>>
     */
    private static array $currentTables = [];

    /**
     * Record $label for the current run.
     */
    public static function label(string $label): void
    {
        self::$current[$label] = true;
    }

    /**
     * Record $label for the current run only when $condition holds.
     */
    public static function when(bool $condition, string $label): void
    {
        if ($condition) {
            self::$current[$label] = true;
        }
    }

    /**
     * Like {@see when()}, but additionally REQUIRES the label to occur in at
     * least $minPercent of the property's passing runs. When the requirement is
     * not met the property fails with a {@see CoverageViolationException} even
     * though every run passed — turning "the distribution looks wrong" from a
     * printed hint into a CI failure.
     *
     * ```php
     * Classify::cover($n % 2 === 0, 'even', 30.0);
     * ```
     *
     * The threshold belongs to the label, not to the call: covering the same
     * label twice in one run with different percentages leaves the last one
     * standing.
     */
    public static function cover(bool $condition, string $label, float $minPercent): void
    {
        if ($minPercent < 0.0 || $minPercent > 100.0) {
            throw new \InvalidArgumentException('Minimum coverage percentage must be between 0 and 100');
        }

        self::$requirements[$label] = $minPercent;

        if ($condition) {
            self::$current[$label] = true;
        }
    }

    /**
     * Clear the labels buffered for the current run.
     *
     * @internal Driven by the property runner.
     */
    /**
     * Record one or more tags of $table for the current run: the categories
     * a run belongs to at once, aggregated into a per-table tally with the
     * pairwise intersections of tags that were hit together. Observability
     * only — no minimum share, no verdict; {@see cover()} stays the one
     * enforcement tool:
     *
     *     Classify::tabulate('payload', $size < 1024 ? 'small' : 'large');
     *     Classify::tabulate('features', array_keys(array_filter([
     *         'compressed' => $compressed, 'retried' => $attempt > 1,
     *     ])));
     *
     * A tag recorded several times within one run counts once for that run,
     * like a label. An empty tag list records nothing.
     *
     * @param string|list<string> $tags
     */
    public static function tabulate(string $table, string|array $tags): void
    {
        foreach (is_array($tags) ? $tags : [$tags] as $tag) {
            self::$currentTables[$table][$tag] = true;
        }
    }

    public static function beginRun(): void
    {
        self::$current = [];
        self::$currentTables = [];
    }

    /**
     * Return the labels recorded during the current run and clear the buffer.
     *
     * @internal Driven by the property runner.
     *
     * @return list<string>
     */
    public static function flushRun(): array
    {
        // Cast back what the array key coerced away: a label like '42' went in
        // as a string and comes out of array_keys() as an int. Listeners are
        // promised list<string>, and a property that labels by a numeric id is
        // not doing anything exotic.
        $labels = array_map(
            static fn(int|string $label): string => (string) $label,
            array_keys(self::$current),
        );
        self::$current = [];

        return $labels;
    }

    /**
     * Return the tables recorded during the current run — table name to the
     * tags hit, as the strings the body recorded — and clear them.
     *
     * @internal Driven by the property runner.
     *
     * @return array<string, list<string>>
     */
    public static function flushTables(): array
    {
        $tables = array_map(
            static fn(array $tags): array => array_map(
                static fn(int|string $tag): string => (string) $tag,
                array_keys($tags),
            ),
            self::$currentTables,
        );
        self::$currentTables = [];

        return $tables;
    }

    /**
     * Return the coverage requirements registered during the property and clear
     * them. The runner drains this once after the run loop (and defensively
     * before it, in case a previous property aborted mid-flight).
     *
     * @internal Driven by the property runner.
     *
     * @return array<array-key, float>
     */
    public static function flushRequirements(): array
    {
        $requirements = self::$requirements;
        self::$requirements = [];

        return $requirements;
    }
}
