---
title: "Bounding shrink work"
description: "Capping how long the shrinker searches for a minimal counterexample, so a pathological failing input can't turn a property run into a hang."
---

# Bounding shrink work

By default shrinking runs until no smaller candidate still fails, re-running the
property once per accepted step. On expensive properties or very large inputs you
can cap the number of accepted shrink steps with `maxShrinks`:

```php
#[Property(runs: 200, maxShrinks: 25)]
```

`maxShrinks: null` (the default) means no cap. `maxShrinks: 0` disables shrinking
entirely and reports the original counterexample unchanged. The cap counts
*accepted* shrink steps, not test executions.

## Bounding the work, not the result

That last sentence is the limitation: a descent spends most of its time on
candidates it *rejects*, and `maxShrinks` never counts those. On a large
collection the rejected candidates alone can cost more than the random phase
that found the failure. The engine's `PropertyConfig` bounds the descent from
the other side:

```php
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;

new PropertyConfig(shrink: ShrinkMode::Off);   // report the counterexample as generated
new PropertyConfig(shrinkBudgetMs: 500);       // descend for at most 500 ms
```

`ShrinkMode::Off` skips the descent: no trial, no shrink event, zero steps and
zero trials on the counterexample. A `shrinkBudgetMs` resolves to
`ShrinkMode::Bounded`, which stops the descent on wall clock and reports the
best candidate it reached — not the original one.

The budget is a millisecond count of at least 1, and at most large enough to
still convert into the nanosecond deadline the runner compares the clock
against. Past that point the arithmetic leaves the integer range and the
deadline stops being one, so such a budget is rejected at construction rather
than silently disabled.

## What a budget costs

A wall-clock budget is the one knob here that gives up determinism. How far the
descent gets depends on how long the property body takes, so the same seed can
minimise to a different counterexample on a fast machine and a slow one. That
is a deliberate trade: the budget answers "the descent hung for a minute", not
"reproduce this exactly". For reproduction, pin the seed or let the
[regression corpus](/guide/regression-corpus) replay the recorded input — both
stay deterministic.
