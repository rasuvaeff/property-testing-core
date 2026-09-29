---
title: Exhaustive mode
description: "PropertyConfig::$exhaustive walks the whole parameter domain instead of sampling it when every generator is Enumerable and the product fits the budget — a proof over the parameters, not a probability."
---

# Exhaustive mode

For a small domain a random sample is a probability statement where a
guarantee is available and cheap. Four statuses times six triggers is 24
inputs; 300 random runs almost certainly cover all of them, and prove
nothing. Explicit examples pin individual cases; they do not prove the
cartesian product either.

```php
new PropertyConfig(exhaustive: true);                        // engine
#[Property(exhaustive: true, exhaustiveBudget: 10_000)]     // Testo adapter
```

With the flag on, the random phase walks the whole parameter product — the
first parameter varying slowest, each generator in its own fixed order (ints
ascending, `oneOf` as listed, `null` first) — when every parameter's
generator implements [`Enumerable`](/api/classes/Enumerable) with a finite
domain and the product of the sizes is at most `exhaustiveBudget` (10 000
by default). The walk is seed-independent; `runs` is ignored in favour of
the domain size; a discarded input is skipped rather than resampled; a
falsified input shrinks through its tree as usual.

## What is enumerable

| Generator | Domain |
|---|---|
| `Gen::constant()` | 1 |
| `Gen::bool()` | 2: `false`, `true` |
| `Gen::intBetween($min, $max)` | `$max - $min + 1`, ascending; `Gen::int()` reports `PHP_INT_MAX`, which no budget accepts |
| `Gen::elements()` / `enum()` / `oneOf()` | the values, in the listed order — a value listed twice is walked twice |
| `Gen::nullable($inner)` | the inner domain plus one, `null` first |
| `Gen::tuple()` / `Gen::record()` | the product of the components, first one slowest, saturating |
| `Gen::map()` | the source's domain, mapped — an upper bound on distinct outputs |
| `Gen::filter()` | the source's domain, walked through the predicate — the size is an upper bound, the walk may be shorter |
| `Gen::withEdgeCases()` | the inner domain |

A wrapper over an unbounded source answers `domainSize(): null` and the mode
declines. Implement `Enumerable` — `domainSize()` and `enumerate()`, the
latter yielding the same shrink trees `generate()` would give each value —
on a custom arbitrary to join.

## What it is not

The mode enumerates the **parameters**. In-body `Gen::draw()` has no finite
domain: draws stay random inside each input, seeded as always, and a body
that draws is walked once per parameter tuple with whatever it drew. A
counterexample that carries draws is stored in the corpus as a seed entry,
and its replay walks the same product with the same seed, so it reproduces.

## When it declines

The decision is made once, before any phase, and reported rather than
silently downgraded:

| Reason | `DistributionReport::$exhaustiveDeclined` |
|---|---|
| A parameter's generator has no finite domain | `parameter "s" has no finite domain to enumerate` |
| The product exceeds the budget | `the domain has 10000 inputs, above the exhaustive budget of 9999` |

The phase then samples `runs` inputs as usual. When it walked,
`$domainSize` says how many inputs the domain had. `toArray()` carries an
`exhaustive` key — `{"domainSize": n}` or `{"declined": "…"}` — in either
case, and none when the flag was off.
