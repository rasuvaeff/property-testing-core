---
title: Flaky detection
description: "After the shrink descent the runner re-executes the minimised counterexample; a replay that passes marks the failure as nondeterminism in the body or the code under test, not as a fact about the input."
---

# Flaky detection

A falsified property records its counterexample in the
[regression corpus](/guide/regression-corpus). If the body or the code under
test is nondeterministic — a clock, `mt_rand`, the iteration order of an
unordered map, a cache warmed by the previous run — the next replay of the
same input passes, the entry is pruned as healed, and the suite oscillates
red and green between CI runs. Diagnosing that by hand means re-running with
the seed and noticing that the same input fails only sometimes.

The runner does the noticing. After the descent it re-executes the minimised
input `flakyReplays` times — 2 by default, 0 disables — through the same
executor, with the same draw tape:

- every replay fails again → the counterexample stands, reported as before;
- a replay passes, or discards → the counterexample is **flaky**:
  [`CounterExample::isFlaky()`](/api/classes/CounterExample) is true,
  `$passedOnReplay` names the replay, `$replays` how many ran, and the
  failure message gains a line pointing away from the input.

```
Property falsified after 12 successful run(s); seed=7
  Original: n=734
  Shrunk:   n=10 (7 shrink step(s), 22 trial(s))
  Failure:  too big
  Flaky:    the minimised input passed on replay 1; suspect nondeterminism in the body or the code under test, not this input
```

The counterexample is still recorded — a flaky failure is a failure — and
`toArray()` carries `replays` and `passedOnReplay`, so a reporter can render
the distinction. Replays emit no events and are charged to wall clock only:
the cost is at most `flakyReplays` extra body executions **per
falsification**, never per run. A body that draws more on a replay than the
tape holds draws fresh values past its end, as a shrink trial would.

```php
new PropertyConfig(flakyReplays: 0);   // trust the first failure
```
