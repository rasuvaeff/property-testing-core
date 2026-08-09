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
