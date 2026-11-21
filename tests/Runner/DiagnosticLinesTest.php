<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\DiagnosticLevel;
use Rasuvaeff\PropertyTesting\Runner\DiagnosticLine;
use Rasuvaeff\PropertyTesting\Runner\DiagnosticLines;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Runner\SearchReport;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Runner\TargetOutcome;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The texts both adapters print, byte for byte: their golden tests pin the
 * same strings through the adapters.
 */
#[Test]
#[Covers(DiagnosticLines::class)]
#[Covers(DiagnosticLine::class)]
final class DiagnosticLinesTest
{
    public function aQuietRunPrintsNothing(): void
    {
        Assert::same(DiagnosticLines::of('p', new RunStatistics(attempts: 10, discards: 9, checks: 1, classifications: [])), []);
        Assert::same(DiagnosticLines::of('p', new RunStatistics(attempts: 0, discards: 0, checks: 0, classifications: ['a' => 1], tables: ['t' => ['a' => 1]])), []);
    }

    public function everyLineInOrder(): void
    {
        $statistics = new RunStatistics(
            attempts: 100,
            discards: 91,
            checks: 9,
            classifications: ['small' => 3, 'large' => 6],
            tables: ['size' => ['big' => 5, 'tiny' => 1], 'kind' => ['odd' => 9]],
            intersections: ['size' => ['big & tiny' => 1]],
            exhaustiveDeclined: 'parameter "x" has no finite domain to enumerate',
            search: new SearchReport(12, [
                'sum' => new TargetOutcome('sum', TargetDirection::Maximize, 7.5, 3, 1),
                'len' => new TargetOutcome('len', TargetDirection::Minimize, null, 0),
            ]),
            discardedExamples: 2,
        );

        Assert::same($this->render(DiagnosticLines::of('prop', $statistics)), [
            'warning: Property "prop" discarded 91 of 100 attempt(s) (91%); consider narrowing the generators',
            'warning: Property "prop" discarded 2 explicit example(s) with Assume::that(); a discarded example checks nothing',
            'info: Property "prop" distribution: large 67% (6/9), small 33% (3/9)',
            'info: Property "prop" table size: big 56% (5/9), tiny 11% (1/9); together: big & tiny 11% (1/9)',
            'info: Property "prop" table kind: odd 100% (9/9)',
            'warning: Property "prop" could not enumerate its domain and sampled instead: parameter "x" has no finite domain to enumerate',
            'info: Property "prop" search: 12 evaluation(s); sum max 7.5 (3 improvement(s), 1 recalled), len min - (0 improvement(s))',
        ]);
    }

    public function anEnumeratedDomainAndATableWithoutPairs(): void
    {
        $statistics = new RunStatistics(
            attempts: 4,
            discards: 0,
            checks: 4,
            classifications: [],
            tables: ['kind' => ['a' => 4]],
            domainSize: 4,
        );

        Assert::same($this->render(DiagnosticLines::of('p', $statistics)), [
            'info: Property "p" table kind: a 100% (4/4)',
            'info: Property "p" enumerated its whole domain of 4 input(s)',
        ]);
    }

    /**
     * Exactly at the threshold is not excessive; just past it is.
     */
    #[DataProvider('discardRates')]
    public function theDiscardWarningStartsPastNinetyPercent(int $discards, int $attempts, bool $warned): void
    {
        $lines = DiagnosticLines::of('p', new RunStatistics(attempts: $attempts, discards: $discards, checks: 0, classifications: []));

        Assert::same($lines !== [], $warned);
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function discardRates(): iterable
    {
        yield 'exactly 90%' => [90, 100, false];
        yield 'just past' => [901, 1000, true];
    }

    /**
     * The share is rounded half up on the exact ratio. `23/40` is 57.5%:
     * scaled as a float ratio first it would read 57% under PHP 8.4.
     */
    #[DataProvider('halves')]
    public function aShareOnAHalfRoundsUp(int $count, int $checks, string $expected): void
    {
        $lines = DiagnosticLines::of('p', new RunStatistics(attempts: $checks, discards: 0, checks: $checks, classifications: ['x' => $count]));

        Assert::same($lines[0]->text, sprintf('Property "p" distribution: x %s (%d/%d)', $expected, $count, $checks));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function halves(): iterable
    {
        yield '23/40' => [23, 40, '58%'];
        yield '29/200' => [29, 200, '15%'];
        yield '1/8' => [1, 8, '13%'];
    }

    public function aDiscardShareOnAHalfRoundsUp(): void
    {
        $lines = DiagnosticLines::of('p', new RunStatistics(attempts: 200, discards: 189, checks: 11, classifications: []));

        Assert::same($lines[0]->text, 'Property "p" discarded 189 of 200 attempt(s) (95%); consider narrowing the generators');
    }

    /**
     * @param list<DiagnosticLine> $lines
     *
     * @return list<string>
     */
    private function render(array $lines): array
    {
        return array_map(
            static fn(DiagnosticLine $line): string => ($line->level === DiagnosticLevel::Warning ? 'warning: ' : 'info: ') . $line->text,
            $lines,
        );
    }
}
