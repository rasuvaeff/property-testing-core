<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Event;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Event\CorpusFailed;
use Rasuvaeff\PropertyTesting\Event\CorpusPruned;
use Rasuvaeff\PropertyTesting\Event\CorpusReplayed;
use Rasuvaeff\PropertyTesting\Event\CorpusStored;
use Rasuvaeff\PropertyTesting\Event\ExampleFinished;
use Rasuvaeff\PropertyTesting\Event\ExampleStarted;
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\Runner\DistributionReport;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The events are data: every field is what the constructor was handed, in the
 * order the compatibility policy freezes (new fields go at the end, with a
 * default). Pinned here per event so that a renamed or reordered field is a
 * red test in this package, not only in an adapter's characterization suite.
 */
#[Test]
#[Covers(PropertyStarted::class)]
#[Covers(PropertyFinished::class)]
#[Covers(ExampleStarted::class)]
#[Covers(ExampleFinished::class)]
#[Covers(RunStarted::class)]
#[Covers(RunPassed::class)]
#[Covers(RunDiscarded::class)]
#[Covers(RunFailed::class)]
#[Covers(ShrinkTried::class)]
#[Covers(ShrinkAccepted::class)]
#[Covers(CorpusReplayed::class)]
#[Covers(CorpusPruned::class)]
#[Covers(CorpusStored::class)]
#[Covers(CorpusFailed::class)]
final class EventFieldsTest
{
    public function propertyStartedAndFinished(): void
    {
        $started = new PropertyStarted('suite::p', 42, 100);
        Assert::same([$started->propertyId, $started->seed, $started->runs], ['suite::p', 42, 100]);

        $failure = new \RuntimeException('boom');
        $report = DistributionReport::of(new RunStatistics(attempts: 1, discards: 0, checks: 1, classifications: []), coverageAssessed: true);
        $finished = new PropertyFinished('suite::p', $failure, $report);
        Assert::same([$finished->propertyId, $finished->failure, $finished->distribution], ['suite::p', $failure, $report]);
        Assert::null((new PropertyFinished('suite::p', null))->distribution);
    }

    public function exampleStartedAndFinished(): void
    {
        $started = new ExampleStarted('suite::p', 2, ['x' => 1]);
        Assert::same([$started->propertyId, $started->index, $started->arguments], ['suite::p', 2, ['x' => 1]]);

        $failure = new \RuntimeException('boom');
        $finished = new ExampleFinished('suite::p', 2, ['x' => 1], $failure);
        Assert::same([$finished->propertyId, $finished->index, $finished->arguments, $finished->failure], ['suite::p', 2, ['x' => 1], $failure]);
        Assert::null((new ExampleFinished('suite::p', 2, [], null))->failure);
    }

    public function runEvents(): void
    {
        $started = new RunStarted('suite::p', 3, ['x' => 1]);
        Assert::same([$started->propertyId, $started->attempt, $started->arguments], ['suite::p', 3, ['x' => 1]]);

        $passed = new RunPassed('suite::p', 3, ['x' => 1], ['draw#1' => 2], ['even'], 500);
        Assert::same(
            [$passed->propertyId, $passed->attempt, $passed->arguments, $passed->draws, $passed->labels, $passed->elapsedNs],
            ['suite::p', 3, ['x' => 1], ['draw#1' => 2], ['even'], 500],
        );

        $discarded = new RunDiscarded('suite::p', 3, ['x' => 1], ['draw#1' => 2]);
        Assert::same(
            [$discarded->propertyId, $discarded->attempt, $discarded->arguments, $discarded->draws, $discarded->skipped],
            ['suite::p', 3, ['x' => 1], ['draw#1' => 2], false],
        );
        Assert::true((new RunDiscarded('suite::p', 3, [], [], skipped: true))->skipped);

        $failure = new \RuntimeException('boom');
        $failed = new RunFailed('suite::p', 3, ['x' => 1], ['draw#1' => 2], $failure, 700);
        Assert::same(
            [$failed->propertyId, $failed->attempt, $failed->arguments, $failed->draws, $failed->failure, $failed->elapsedNs],
            ['suite::p', 3, ['x' => 1], ['draw#1' => 2], $failure, 700],
        );
    }

    public function shrinkEvents(): void
    {
        $tried = new ShrinkTried('suite::p', 'x', 5, accepted: true);
        Assert::same([$tried->propertyId, $tried->parameter, $tried->candidate, $tried->accepted], ['suite::p', 'x', 5, true]);

        $accepted = new ShrinkAccepted('suite::p', 1, 'x', 10, 5);
        Assert::same(
            [$accepted->propertyId, $accepted->step, $accepted->parameter, $accepted->before, $accepted->after],
            ['suite::p', 1, 'x', 10, 5],
        );
    }

    public function corpusEvents(): void
    {
        $replayed = new CorpusReplayed('suite::p', isValues: true, arguments: ['x' => 1], seed: 42);
        Assert::same([$replayed->propertyId, $replayed->isValues, $replayed->arguments, $replayed->seed], ['suite::p', true, ['x' => 1], 42]);

        $pruned = new CorpusPruned('suite::p', isValues: false, seed: 42);
        Assert::same([$pruned->propertyId, $pruned->isValues, $pruned->seed], ['suite::p', false, 42]);

        $counterExample = new CounterExample(42, 0, ['x' => 1], ['x' => 1]);
        $stored = new CorpusStored('suite::p', $counterExample);
        Assert::same([$stored->propertyId, $stored->counterExample], ['suite::p', $counterExample]);

        $failure = new \RuntimeException('down');
        $failed = new CorpusFailed('suite::p', 'remember', $failure);
        Assert::same([$failed->propertyId, $failed->operation, $failed->failure], ['suite::p', 'remember', $failure]);
    }

    public function everyEventIsAPropertyEvent(): void
    {
        foreach ([
            PropertyStarted::class, PropertyFinished::class, ExampleStarted::class, ExampleFinished::class,
            RunStarted::class, RunPassed::class, RunDiscarded::class, RunFailed::class,
            ShrinkTried::class, ShrinkAccepted::class,
            CorpusReplayed::class, CorpusPruned::class, CorpusStored::class, CorpusFailed::class,
        ] as $event) {
            Assert::true(is_subclass_of($event, PropertyEvent::class), $event);
        }
    }
}
