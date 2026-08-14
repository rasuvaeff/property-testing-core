---
title: "Distribution"
description: "classify() and collect() report which kind of generated input each run actually produced, so you can see whether your generator's coverage is honest."
---

# Distribution

## Checking the distribution

A property can pass vacuously if its generators never reach the interesting
inputs. `Classify` records labels per run; after a fully passing property the
runner prints the share of runs that hit each label.

```php
#[Property(runs: 500)]
public function holds(int $n): void
{
    Classify::when($n === 0, 'zero');
    Classify::label($n % 2 === 0 ? 'even' : 'odd');
    // ... assertions ...
}
// Property "holds" distribution: odd 51% (255/500), even 49% (245/500), zero 1% (3/500)
```

A label recorded several times within one run still counts once for that run.

## Enforcing the distribution

`Classify::cover()` upgrades the printed hint to a hard requirement: the label
must occur in at least the given percentage of passing runs, or the property
**fails** with a `CoverageViolationException` — even though every run passed.
Use it to make vacuous passes impossible in CI.

```php
#[Property(runs: 500)]
public function holds(int $n): void
{
    Classify::cover($n % 2 === 0, 'even', 30.0); // fail if < 30% of runs are even
    // ... assertions ...
}
```

Discarded attempts (`Assume::that()`) are excluded from the denominator and
replaced until all requested successful runs complete. Exceeding `maxDiscards`
fails with `GaveUpException` (see [Assume::that() vs Gen::filter()](/guide/controlling-runs/assume-vs-filter)).

## Reading the distribution as data

The printed line is for people. Everything else — a CI job collecting
distributions, a test asserting that a property really reaches a branch — gets
the same contents as a `DistributionReport`, on the `PropertyFinished` event:

```php
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\PropertyListener;

final class DistributionCollector implements PropertyListener
{
    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        if (!$event instanceof PropertyFinished || $event->distribution === null) {
            return;
        }

        foreach ($event->distribution->labels as $share) {
            // 'even', 245, 49.0, null
            [$share->label, $share->count, $share->percent, $share->required];
        }

        $event->distribution->toArray();      // ready for a telemetry payload
        $event->distribution->unmetRequirements();
    }
}
```

Each label carries the `cover()` threshold it was registered with, if any, so
"is this branch reached often enough" is a comparison instead of a parse.
`Classify::cover()` still fails the property; the report is what lets you *see*
the share, including the label that was required and never occurred — it
appears with a count of zero rather than going missing.

### Two denominators

| Number | Out of | Why |
|---|---|---|
| `LabelShare::$percent` | the successful checks | A discarded run produced no input the label could describe; counting it would shrink the share of the same generator merely because `Assume::that()` rejected more inputs |
| `DistributionReport::discardPercent()` | the attempts | A discard *is* an attempt that never became a check |

A phase that executed nothing (a run without `Phase::Random`) reports zeros,
not a division by zero.

### What a report does not say

`coverageAssessed` is false when the run ended before the check loop completed —
it gave up on discards, or ran out of its time budget. The shares are still what
happened, but nothing enforced the `cover()` thresholds beside them.

A falsified run reports no distribution at all: it stops at the counterexample,
and the counters it did accumulate are not part of that result.
