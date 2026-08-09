<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

/**
 * Two custom observers built purely on the engine's event model — a console
 * reporter and a telemetry collector. Neither needs Testo, PHPUnit, or any
 * engine change: everything they report comes from PropertyListener events.
 * This is the shape a CI reporter, a metrics exporter, or an IDE integration
 * would take against property-testing-core.
 */
final class ConsoleReporter implements PropertyListener
{
    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        match (true) {
            $event instanceof PropertyStarted => printf(
                "▶ %s (seed %d, %d runs)\n",
                $event->propertyId,
                $event->seed,
                $event->runs,
            ),
            $event instanceof RunFailed => printf(
                "  ✗ falsified on attempt %d: %s\n",
                $event->attempt,
                $event->failure?->getMessage() ?? 'unknown failure',
            ),
            $event instanceof ShrinkAccepted => printf(
                "  ↓ shrink step %d: %s %s → %s\n",
                $event->step,
                $event->parameter,
                json_encode($event->before, JSON_THROW_ON_ERROR),
                json_encode($event->after, JSON_THROW_ON_ERROR),
            ),
            $event instanceof PropertyFinished => printf(
                "%s %s\n",
                $event->failure === null ? '✓' : '✗',
                $event->failure === null ? 'passed' : $event->failure->getMessage(),
            ),
            default => null,
        };
    }
}

final class TelemetryCollector implements PropertyListener
{
    private int $passed = 0;
    private int $discarded = 0;
    private int $failed = 0;
    private int $shrinkTrials = 0;
    private int $shrinkAccepted = 0;
    private int $elapsedNs = 0;

    /** @var array<string, int> */
    private array $labels = [];

    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        if ($event instanceof RunPassed) {
            ++$this->passed;
            $this->elapsedNs += $event->elapsedNs;
            foreach ($event->labels as $label) {
                $this->labels[$label] = ($this->labels[$label] ?? 0) + 1;
            }
        } elseif ($event instanceof RunDiscarded) {
            ++$this->discarded;
        } elseif ($event instanceof RunFailed) {
            ++$this->failed;
            $this->elapsedNs += $event->elapsedNs;
        } elseif ($event instanceof ShrinkTried) {
            ++$this->shrinkTrials;
        } elseif ($event instanceof ShrinkAccepted) {
            ++$this->shrinkAccepted;
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'runs_passed' => $this->passed,
            'runs_discarded' => $this->discarded,
            'runs_failed' => $this->failed,
            'shrink_trials' => $this->shrinkTrials,
            'shrink_accepted' => $this->shrinkAccepted,
            'body_time_ms' => round((float) $this->elapsedNs / 1e6, 3),
            'labels' => $this->labels,
        ];
    }
}

$runner = new PropertyRunner();
$telemetry = new TelemetryCollector();

// A passing property with classification labels: telemetry sees every run.
$holds = new PropertyDefinition(
    id: 'listeners::absoluteValueIsNonNegative',
    name: 'absoluteValueIsNonNegative',
    generators: ['value' => Gen::intBetween(-1_000, 1_000)],
    parameterNames: ['value'],
    config: new PropertyConfig(runs: 100, seed: 7),
);

$runner->run(
    $holds,
    new CallableTrialExecutor(static function (int $value): void {
        Classify::when($value < 0, 'negative');
        Classify::when($value >= 0, 'non-negative');

        if (abs($value) < 0) {
            throw new RuntimeException('abs() went negative');
        }
    }),
    [new ConsoleReporter(), $telemetry],
);

// A falsified property: the reporter narrates the shrink descent live.
$falsified = new PropertyDefinition(
    id: 'listeners::everyListStaysShort',
    name: 'everyListStaysShort',
    generators: ['values' => Gen::arrayOf(Gen::intBetween(0, 100), maxSize: 20)],
    parameterNames: ['values'],
    config: new PropertyConfig(runs: 100, seed: 7),
);

$runner->run(
    $falsified,
    new CallableTrialExecutor(static function (array $values): void {
        if (count($values) > 5) {
            throw new RuntimeException(sprintf('list of %d exceeds 5 elements', count($values)));
        }
    }),
    [new ConsoleReporter(), $telemetry],
);

echo "\ntelemetry snapshot:\n";
echo json_encode($telemetry->snapshot(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
