---
title: "Replaying a shrink path"
description: "Following the recorded steps of an earlier descent instead of searching for them again — one body execution per accepted step rather than one per candidate tried."
---

# Replaying a shrink path

A falsified property reports two things about its minimisation: how many steps
it accepted, and how many candidates it had to try to find them. The second
number is the larger one. Even the smallest integer property in the engine's own
suite accepts nine steps after trying thirty-nine candidates, and on a body that
takes a second per run that ratio is the difference between a coffee and a
commit.

The counterexample carries the descent itself:

```php
$counterExample->path;   // 'value:1/value:1/value:1/value:1/value:1/value:3/value:4/value:5/value:6'
```

Pass it back, with the seed it came from, and the run follows those steps
instead of searching for them:

```php
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;

new PropertyConfig(seed: 42, path: 'value:1/value:1/value:3');
```

Each step names a node — a parameter, or an in-body [draw](/guide/generators/dependent) under
its `draw#N` pseudo-name — and which candidate of that node's shrink
enumeration was accepted. The replay runs the body once per step. The nine-step
descent above costs nine executions instead of thirty-nine.

## What it does not save

The random phase. Reaching the run that failed means executing the runs before
it: a body can consume randomness through `Gen::draw()`, and whether a run is
discarded depends on the body itself, so those runs cannot be skipped and still
land on the same input. A path shortens the descent, not the search that
preceded it.

For the other half, pin the seed — or record the input in the
[regression corpus](/guide/regression-corpus), which replays it ahead of the
random phase.

A pinned path does not switch the corpus off, and the two meet in run order: a
recorded entry that still fails is reported before the random phase reaches the
path at all. Pass `replayRegressions: false`, or a phase set without
`Phase::Corpus`, to follow the path alone. In the other direction, a
counterexample reached through a path is stored like any other — the input is
minimal either way, and how it was reached is not part of what the corpus keeps.

## A path is a debugging aid, not a fixture

Steps are indices into each node's shrink candidates. Change a generator — even
reorder the candidates one of them yields — and the recorded indices point
somewhere else. That is expected, and it is why a path belongs in a terminal, a
ticket or a chat message, and not in `tests/fixtures/`, a committed
configuration or anything else that has to survive a refactor. The corpus is
what survives a refactor; a path reproduces *this* descent, today.

When a path stops applying, the run says so:

```
Shrink path "value:1/value:3" no longer applies: step 2 ("value:3") no longer
falsifies the property. Re-run without a path to search for the counterexample
again
```

Four things end a path: the node it names is gone, the enumeration is now
shorter than the index, the candidate no longer differs from the value it would
replace, or the step no longer falsifies the property. Every one of them is
reported as its own outcome (`PathFailed`) rather than absorbed. The
alternative — quietly searching instead — would return a counterexample that
looks exactly like a successful replay while reproducing a different descent,
which is the one failure mode a reproduction tool cannot afford.

## Configurations a path refuses

Each of these would leave the path a silent no-op, so it is rejected at
construction instead:

| Configuration | Why |
|---|---|
| No explicit `seed` | The steps only mean anything against the run that produced them. `derandomize` does not substitute: a path is always copied from a message that printed its seed beside it |
| `ShrinkMode::Off`, or `phases` without `Phase::Shrink` | There is no descent to follow |
| A `shrinkBudgetMs` | A path exists to be deterministic; a [wall-clock budget](/guide/controlling-runs/bounding-shrink) exists not to be |
| A `maxShrinks` below the path's own length | The replay could not finish the path |
| A malformed path | A typo in something pasted by hand, not a request to search |
