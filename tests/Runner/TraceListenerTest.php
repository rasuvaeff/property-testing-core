<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Runner\TraceListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The `PROPERTY_VERBOSE` lines both adapters print, byte for byte.
 */
#[Test]
#[Covers(TraceListener::class)]
final class TraceListenerTest
{
    /** @var list<string> */
    private array $lines = [];

    public function everyTracedEventInItsFormat(): void
    {
        $trace = $this->trace();
        $failure = new \RuntimeException('boom');

        $trace->onEvent(new PropertyStarted('Suite::prop', 1, 10));
        $trace->onEvent(new RunStarted('Suite::prop', 1, ['x' => 7, 's' => 'a']));
        $trace->onEvent(new RunPassed('Suite::prop', 1, ['x' => 7], [], [], 10));
        $trace->onEvent(new RunPassed('Suite::prop', 2, ['x' => 7], ['draw#1' => 3], [], 10));
        $trace->onEvent(new RunFailed('Suite::prop', 3, ['x' => 7], ['draw#1' => 4], $failure, 10));
        $trace->onEvent(new RunDiscarded('Suite::prop', 4, ['x' => 7], ['draw#1' => 5], skipped: false));
        $trace->onEvent(new TargetImproved('Suite::prop', 'sum', TargetDirection::Maximize, 2.5, null, ['x' => 7]));
        $trace->onEvent(new TargetImproved('Suite::prop', 'len', TargetDirection::Minimize, 1.0, 2.0, ['x' => 1]));
        $trace->onEvent(new ShrinkAccepted('Suite::prop', 1, 'x', 7, 0));

        Assert::same($this->lines, [
            'Property "prop" attempt 1: x=7, s="a"',
            'Property "prop" attempt 2 draws: draw#1=3',
            'Property "prop" attempt 3 draws: draw#1=4',
            'Property "prop" attempt 4 draws: draw#1=5',
            'Property "prop" target sum max: - -> 2.5 (x=7)',
            'Property "prop" target len min: 2 -> 1 (x=1)',
            'Property "prop" shrink step 1: x=7 -> 0',
        ]);
    }

    public function anIdWithoutAClassIsItsOwnName(): void
    {
        $this->trace()->onEvent(new RunStarted('bare-id', 1, []));

        Assert::same($this->lines, ['Property "bare-id" attempt 1: ']);
    }

    /**
     * A listener exception would abort the run; the built-in trace swallows
     * its own, the sink's included.
     */
    public function aFailingSinkDoesNotEscape(): void
    {
        $calls = 0;
        $trace = new TraceListener(static function (string $line) use (&$calls): void {
            ++$calls;

            throw new \RuntimeException('sink broke');
        });

        $trace->onEvent(new RunStarted('Suite::prop', 1, ['x' => 1]));

        Assert::same($calls, 1);
    }

    private function trace(): TraceListener
    {
        $this->lines = [];

        return new TraceListener(function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
