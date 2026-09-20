---
title: Targeted search
description: "Target::maximize()/minimize() report a score from the property body; with searchRuns on, the engine climbs it after the random phase, mutating the best inputs one parameter at a time, and keeps the pool in a search document between runs."
---

# Targeted search

Some bugs live at an extreme — the longest delay a backoff can produce, the
deepest recursion a parser reaches, the fullest queue — and uniform sampling
reaches an extreme by luck. The property body knows a number that grows as
the input gets closer to where the bug lives. Targeted search lets it say so:

```php
use Rasuvaeff\PropertyTesting\Target;

#[Property(runs: 200, searchRuns: 100)]
public function backoffStaysUnderCap(int $base, int $attempt): void
{
    $delay = (new Backoff($base))->delayMs($attempt);
    Target::maximize('delay', $delay);

    Assert::true($delay <= 60_000);
}
```

`Target::maximize($label, $score)` and `minimize()` record a score for the
current run. Without a search phase they cost one array write per run and
change nothing. With `searchRuns > 0` on the
[configuration](/api/classes/Runner/PropertyConfig), a search phase follows the
random one:

1. every passing run of the random phase has already put its scores into a
   **pool** of the best inputs per label (eight per label, best first);
2. the search takes an input from the pool — better ones more often —
   regenerates **one** parameter, keeps the rest, and checks it like any
   other run: a discard spends the discard budget, a failure falsifies the
   property with the usual descent, a pass with a better score joins the
   pool;
3. labels take turns, `searchRuns` bodies in all; every new best is a
   [`TargetImproved`](/api/classes/Event/TargetImproved) event carrying the
   input that scored it.

The run's [`SearchReport`](/api/classes/Runner/SearchReport) —
`PropertyFinished::$search`, `RunStatistics::$search` — carries the number of
search evaluations and, per label, the direction, the best score, how many
times the best improved, and how many stored inputs the search started from.
A property that targets nothing reports no search and pays nothing.

## What the measurement said

The feature was gated on a go/no-go: parameter-level mutation had to show a
measurable gain over plain sampling on a real extremum bug, or not ship. The
bug: three parameters in `[0, 1000]` whose sum exceeds 2 900 — 0.17% of the
space — with `Target::maximize('sum', $a + $b + $c)`. Over 100 fixed seeds at
the same total budget of 300 body executions:

| Configuration | Seeds that found the corner |
|---|---|
| 300 random runs | 52 / 100 |
| 200 random + 100 search runs | 98 / 100 |
| 100 random + 200 search runs | 100 / 100 |

Two controls, so the numbers are read for what they are. A bug that needs
one parameter tuned to within a few units of another (`\|a - b\| < 3` over
`[0, 10⁵]`, boundary bias off) was found by neither configuration in 50 seeds:
regenerating a whole parameter is a global move, not a local one, and the
climb cannot close that gap — that is the *search tape* follow-up on the
[roadmap](/roadmap), not this phase. A score uncorrelated with the bug
(target `a - b`, bug on `a % 97 === 0 && b % 89 === 0`) found it 2 / 50 in
both configurations: the search neither helps nor hurts. The phase finds
extremes and corners; it does not find needles.

## Rules

- A label's direction is fixed for the whole property. Maximising a label one
  run and minimising it the next is a configuration error, thrown at the
  call; so is a score that is not finite.
- The search phase is checks like any other: the same discard budget, the
  same time budget, the same `timeoutMs` per run. A falsification found by
  the search is reported with `runsBeforeFailure` counting the random runs
  before it.
- `Target` is a process-local static like `Classify`, cleared before each run
  and drained when the property finishes. Properties run sequentially.

## The adaptive example database

Climbing from scratch every run wastes the climb. Pass a `Corpus` that also
implements [`SearchCorpus`](/api/classes/Runner/SearchCorpus) —
[`FilesystemCorpus`](/api/classes/Runner/FilesystemCorpus) and
[`RedisCorpus`](/api/classes/Runner/RedisCorpus) both do — and the pool is
stored after the search phase and recalled before the next one, so the
search resumes where it got to.

The store is a **separate document** per property — `<sha1(id)>.search.json`
beside the regression document, the `<prefix><sha1>:search` key beside the
regression key — deliberately: an older reader never mistakes a
best-scoring input for a regression and never prunes it as one, and the
regression document's own guards (parameter names, the sequence epoch,
values-versus-seed entries) are untouched. Arguments are encoded through the
same `ValueCodec` as a values entry; an input it cannot represent is left
out. On recall, an entry recorded under other parameter names, and a label
the body now pushes the other way, are ignored. A document that cannot be
read or written is a [`CorpusFailed`](/api/classes/Event/CorpusFailed) event
(`recallTargets` / `rememberTargets`), and the run goes on without it.
