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
     * @var array<string, true>
     */
    private static array $current = [];

    /**
     * Minimum coverage requirements registered during the current property
     * (label => required percentage of passing runs).
     *
     * @var array<string, float>
     */
    private static array $requirements = [];

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
    public static function beginRun(): void
    {
        self::$current = [];
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
        $labels = array_keys(self::$current);
        self::$current = [];

        return $labels;
    }

    /**
     * Return the coverage requirements registered during the property and clear
     * them. The runner drains this once after the run loop (and defensively
     * before it, in case a previous property aborted mid-flight).
     *
     * @internal Driven by the property runner.
     *
     * @return array<string, float>
     */
    public static function flushRequirements(): array
    {
        $requirements = self::$requirements;
        self::$requirements = [];

        return $requirements;
    }
}
