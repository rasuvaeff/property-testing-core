<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\ValueRenderer;

/**
 * The lines an adapter prints after a property run, worded once for every
 * adapter: the excessive-discard warning, the discarded-examples warning, the
 * {@see \Rasuvaeff\PropertyTesting\Classify} distribution, one line per
 * `Classify::tabulate()` table, what exhaustive mode did, and the targeted
 * search report — in that order, so two adapters writing into one log print
 * the same lines in the same order. The engine still never prints: the
 * adapter decides where each {@see DiagnosticLevel} goes.
 *
 * ```php
 * $statistics = RunStatistics::of($result);
 *
 * if ($statistics !== null) {
 *     foreach (DiagnosticLines::of($name, $statistics) as $line) {
 *         fwrite($line->level === DiagnosticLevel::Warning ? STDERR : STDOUT, $line->text . "\n");
 *     }
 * }
 * ```
 *
 * @api
 */
final class DiagnosticLines
{
    /**
     * More than this share of discarded attempts is worth a warning.
     */
    public const SKIP_RATE_WARNING_THRESHOLD = 0.9;

    private function __construct()
    {
        // Static helper; not instantiable.
    }

    /**
     * The lines for one run, in print order; empty when there is nothing to say.
     *
     * @param string $name The property's display name, as the messages quote it.
     * @param RunStatistics $statistics What the run counted ({@see RunStatistics::of()}).
     *
     * @return list<DiagnosticLine>
     */
    public static function of(string $name, RunStatistics $statistics): array
    {
        return array_values(array_filter([
            self::excessiveDiscards($name, $statistics),
            self::discardedExamples($name, $statistics),
            self::distribution($name, $statistics),
            ...self::tables($name, $statistics),
            self::exhaustive($name, $statistics),
            self::search($name, $statistics),
        ]));
    }

    private static function excessiveDiscards(string $name, RunStatistics $statistics): ?DiagnosticLine
    {
        $discards = $statistics->discards;
        $attempts = $statistics->attempts;

        if ($attempts <= 0 || ($discards / $attempts) <= self::SKIP_RATE_WARNING_THRESHOLD) {
            return null;
        }

        return new DiagnosticLine(DiagnosticLevel::Warning, sprintf(
            'Property "%s" discarded %d of %d attempt(s) (%d%%); consider narrowing the generators',
            $name,
            $discards,
            $attempts,
            self::percent($discards, $attempts),
        ));
    }

    private static function discardedExamples(string $name, RunStatistics $statistics): ?DiagnosticLine
    {
        if ($statistics->discardedExamples <= 0) {
            return null;
        }

        return new DiagnosticLine(DiagnosticLevel::Warning, sprintf(
            'Property "%s" discarded %d explicit example(s) with Assume::that(); a discarded example checks nothing',
            $name,
            $statistics->discardedExamples,
        ));
    }

    private static function distribution(string $name, RunStatistics $statistics): ?DiagnosticLine
    {
        $classifications = $statistics->classifications;
        $checks = $statistics->checks;

        if ($classifications === [] || $checks <= 0) {
            return null;
        }

        arsort($classifications);

        $parts = [];

        foreach ($classifications as $label => $count) {
            $parts[] = sprintf('%s %d%% (%d/%d)', $label, self::percent($count, $checks), $count, $checks);
        }

        return new DiagnosticLine(DiagnosticLevel::Info, sprintf('Property "%s" distribution: %s', $name, implode(', ', $parts)));
    }

    /**
     * @return list<DiagnosticLine>
     */
    private static function tables(string $name, RunStatistics $statistics): array
    {
        if ($statistics->checks <= 0) {
            return [];
        }

        $report = DistributionReport::of($statistics, coverageAssessed: true);
        $render = static fn(LabelShare $share): string => sprintf('%s %d%% (%d/%d)', $share->label, (int) round($share->percent), $share->count, $report->checks);
        $lines = [];

        foreach ($report->tables as $table => $shares) {
            $pairs = array_map($render, $report->intersections[$table] ?? []);

            $lines[] = new DiagnosticLine(
                DiagnosticLevel::Info,
                sprintf('Property "%s" table %s: %s', $name, $table, implode(', ', array_map($render, $shares)))
                    . ($pairs === [] ? '' : sprintf('; together: %s', implode(', ', $pairs))),
            );
        }

        return $lines;
    }

    private static function exhaustive(string $name, RunStatistics $statistics): ?DiagnosticLine
    {
        if ($statistics->domainSize !== null) {
            return new DiagnosticLine(DiagnosticLevel::Info, sprintf('Property "%s" enumerated its whole domain of %d input(s)', $name, $statistics->domainSize));
        }

        if ($statistics->exhaustiveDeclined !== null) {
            return new DiagnosticLine(DiagnosticLevel::Warning, sprintf('Property "%s" could not enumerate its domain and sampled instead: %s', $name, $statistics->exhaustiveDeclined));
        }

        return null;
    }

    private static function search(string $name, RunStatistics $statistics): ?DiagnosticLine
    {
        $search = $statistics->search;

        if (!$search instanceof SearchReport) {
            return null;
        }

        $parts = array_map(
            static fn(TargetOutcome $target): string => sprintf(
                '%s %s %s (%d improvement(s)%s)',
                $target->label,
                $target->direction === TargetDirection::Maximize ? 'max' : 'min',
                $target->best === null ? '-' : ValueRenderer::render($target->best),
                $target->improvements,
                $target->recalled > 0 ? sprintf(', %d recalled', $target->recalled) : '',
            ),
            $search->targets,
        );

        return new DiagnosticLine(DiagnosticLevel::Info, sprintf('Property "%s" search: %d evaluation(s); %s', $name, $search->evaluations, implode(', ', $parts)));
    }

    /**
     * A share as a whole percentage, rounded half up on the exact ratio: the
     * product is formed in integers before the one division, so `23/40`
     * reads 58%, not the 57% a ratio scaled by 100.0 rounds to under PHP 8.4.
     */
    private static function percent(int $count, int $total): int
    {
        return (int) round($count * 100 / $total);
    }
}
