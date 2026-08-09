---
title: Boundary bias
description: "Why the numeric generators skew a 1-in-5 draw toward 0, ±1 and the range's own bounds instead of sampling uniformly — bugs cluster at the edges."
---

# Boundary bias

Uniform random sampling wastes most of its draws on the uninteresting middle
of a range. Bugs cluster at the edges — `0`, `±1`, the exact bounds — so the
numeric generators deliberately skew toward them instead of sampling
uniformly.

## How it works

`Gen::int()`, `Gen::intBetween()`, `Gen::float()` and `Gen::floatBetween()`
roll a 1-in-5 chance on every draw (`IntArbitrary`/`FloatArbitrary`'s
`BIAS_DENOMINATOR = 5`). On that roll, instead of a uniform value they return
one of the in-range boundary candidates computed by the internal `Boundary`
helper:

- **Integers**: the distinct values among `0, 1, -1, $min, $max` that fall
  inside `[$min, $max]`. `$min` and `$max` are always in range by
  construction, so the candidate list is never empty — a range like
  `intBetween(10, 20)` still biases toward its own `10` and `20` even though
  `0`/`1`/`-1` are all out of range.
- **Floats**: the distinct values among `0.0, $min` that fall inside
  `[$min, $max)`. `FloatArbitrary` generates a **half-open** range, so `$max`
  itself is deliberately excluded from both the boundary list and ordinary
  generation — it can never be emitted as a value. A degenerate range where
  neither `0.0` nor `$min` qualifies (empty boundary list) just falls back to
  uniform sampling for that draw.

The other 4 draws in 5 sample uniformly across the full range, so the
generator still explores the interior — bias shifts the odds, it doesn't
replace coverage.

## Shrinking is unaffected

Boundary bias only changes what generation *returns*; it says nothing about
how a value *shrinks*. Both arbitraries shrink toward the same target
regardless of how a value was produced — `IntArbitrary` by halving the
distance to `max($min, min($max, 0))`, `FloatArbitrary` with the single
candidate `max($min, min($max, 0.0))`. A boundary-biased draw and a
uniformly sampled draw with the same value produce identical shrink trees.

## Why this matters for property design

- Don't write `Gen::filter($ints, fn($n) => $n !== 0)` expecting the filter
  to rarely trigger — with a 1-in-5 boundary roll landing on `0` whenever it's
  in range, plus `0` being a frequent target of ordinary shrinking, that
  filter discards far more often than a uniform-only model would suggest.
  Prefer `Gen::intBetween(1, $max)` (construct the domain) over filtering
  zero out of it.
- A property that "usually passes but sometimes fails at the edges" is
  exactly what this bias is built to surface fast — you don't need
  `runs: 10000` to hit `min`/`max`/`0`; a few hundred runs already samples
  them repeatedly.
- `Gen::floatSpecial()` is the separate, opt-in mechanism for `NAN`/`±INF`/
  `-0.0` — the default `float()`/`floatBetween()` bias stays entirely
  in-range and finite on purpose. See the [generators overview](/guide/generators/index)
  for the full catalog.
