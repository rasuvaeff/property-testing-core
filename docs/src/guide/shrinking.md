---
title: Shrinking
description: "Integrated shrinking: every generate() call returns a value plus its lazy shrink tree together, so transformed generators (map, flatMap) shrink correctly for free."
---

# Shrinking

A random 1000-element array that fails an assertion tells you *that*
something's wrong, but reading it tells you almost nothing about *why*.
Shrinking turns that array into something like `[0, 1]` — the smallest input
the runner could find that still breaks the property — by searching, not by
inspection.

## Integrated shrinking

Shrinking is **integrated into generation**, not a separate step. Every
`ArbitraryInterface::generate(Random)` call returns a
[`Shrinkable`](/api/classes/Shrinkable): the drawn value, plus a lazy tree of
smaller candidates attached right there at generation time. There is no
standalone `shrink(mixed $value)` method that has to reverse-engineer a
smaller value from a bare scalar — the tree already exists, built by the same
code that produced the value.

This is what makes *transformed* generators shrink correctly. `Gen::map()`
and `Gen::flatMap()` don't need their own shrinking logic: they carry their
source value's `Shrinkable` and re-apply the transformation to each
candidate as the source shrinks. A `Gen::map($int, fn($n) => "user-$n")`
generator shrinks by shrinking the underlying int and re-formatting the
string — never by guessing how to make an arbitrary string smaller.

## The shrink loop

On failure, the runner performs a **greedy descent**: try the first child
candidate in the current node's tree; if it still fails, move to it and
repeat from its children; if none of a node's children still fail, that node
is the answer. This is a heuristic, not an exhaustive search — the result is
best-effort minimal, not *provably* minimal. (One exception: for monotone
predicates, the integer generators' shrink ladder is an exact binary search,
so the result is actually minimal there.)

```
Property falsified after 246 successful run(s); seed=7382910
  Original: maxAttempts=17, baseSeconds=91, cap=847, attempts=23
  Shrunk:   maxAttempts=1, baseSeconds=848, cap=847, attempts=1 (12 shrink step(s), 41 trial(s))
```

`trial(s)` counts every candidate tried, accepted and rejected; `shrink
step(s)` counts only the accepted descents. You can cap the descent with
`#[Property(maxShrinks: 25)]`, or disable it entirely with `maxShrinks: 0` to
get the original counterexample unchanged — see
[Bounding shrink work](/guide/controlling-runs/bounding-shrink).

## What counts as still failing

"Still fails" is stricter than *fails*. A candidate is accepted only when it
fails **the same way** the original run did: the same exception class, raised at
the same place in the property's own file. A body can fail in more than one place
— two assertions, or an assertion beside a call that throws the same type — and
without that the descent would leave the failure it was given and minimise
another one, reporting a minimal counterexample for a bug the run never found.

The place is the line of the property's own file in the failure's stack, which is
not the same as where the exception was raised: an assertion library raises every
assertion of a body from one line of its own (PHPUnit from `Constraint::fail()`,
Testo from `Assert`), so that line tells two assertions apart from nothing, while
the line that called in is exactly the assertion the body wrote.

Where the place cannot be read, the exception class is the whole criterion — the
engine never guesses one, because a criterion that refused candidates failing
exactly as the original did would stall the descent on the input it started from.
That is the case when the property id names no loaded class (an adapter-supplied
id string), when the body is written in another file (a trait, an included
fixture), and when the failure was raised by the engine rather than by the body.

A stateful property is worth a note here: with the rule-based façade the
`#[Rule]` methods live in the test file, so a command sequence whose failure
moves from one rule to another is now a different failure, and the descent stops
before it.

## The termination invariant

A shrink tree is only useful if walking it is guaranteed to stop. Two rules,
enforced by every built-in generator (and required of any
[custom `ArbitraryInterface`](/guide/generators/custom-arbitrary) you write),
make that guarantee:

1. **Every branch is finite.** No generator hands back an infinite stream of
   ever-smaller candidates.
2. **No candidate equals its parent.** Each builder decreases some concrete
   measure — distance to a target value, string length, list size, index
   into an ordered domain — strictly on every step. The runner adds a
   defensive check on top: a candidate whose *value* equals the current one
   (possible when a `map()` function isn't injective) is skipped, even if the
   tree technically offered it.

Together these mean the descent always reaches a fixed point in finitely
many steps, regardless of how deep or wide the tree is.

## Writing your own tree

`Shrinkable` gives you three constructors: `leaf($value)` for a value with no
smaller candidates, `of($value, $closure)` for a value with a lazily computed
list of candidates, and `map($fn)` to transform an entire existing tree.
Building one from scratch — including the exact discipline the termination
invariant requires — is covered with a worked example in
[Custom arbitrary](/guide/generators/custom-arbitrary).

## Notes on the counterexample

A shrunk counterexample renders its arguments and its `draw#N` values. An
intermediate value the body computed — the parsed form of a string, the delay
a backoff chose, the index a search landed on — is invisible unless the
assertion message carries it, and debugging then means re-running with a
`var_dump` in the body. `Gen::note('encoded', $encoded)` attaches such a
value to the current run; the counterexample keeps the notes of the original
failing run and of the minimised one
([`CounterExample::$originalNotes`](/api/classes/CounterExample) /
`$shrunkNotes`), and the message renders the minimised run's after the
arguments:

```
  Shrunk:   s="a" (2 shrink step(s), 5 trial(s))
  Notes:    encoded="YQ=="
  Failure:  mismatch
```

Notes are kept only for the run being reported — a passing run's are
dropped, a rejected shrink candidate's are not adopted — so the cost is one
array per run. They are not stored in the corpus: a replay recomputes them.
And they are not labels: `Classify` aggregates over the whole run set, a note
belongs to one run. Outside a run, `Gen::note()` throws like `Gen::draw()`.

