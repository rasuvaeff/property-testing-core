---
title: "Dependent generators: flatMap vs Gen::draw()"
description: "Building one generated value from another with Gen::flatMap(), and in-body dependent draws with Gen::draw() for several dependent values at once."
---

# Dependent generators: flatMap vs Gen::draw()

## Dependent generators (`flatMap`)

When one input's domain depends on another — a list plus a valid index into it,
a size plus a payload of that size — `Gen::flatMap()` feeds each generated value
into a closure that returns the arbitrary for the final value. Unlike an
`Assume::that()` guard, no runs are discarded, and both levels shrink: the
source value shrinks (the dependent value is regenerated deterministically from
the run's seed), then the dependent value shrinks with the source held fixed.

```php
/** @return array<string, ArbitraryInterface> */
public static function sliceGenerators(): array
{
    return ['pair' => Gen::flatMap(
        Gen::nonEmptyArrayOf(Gen::int()),
        static fn(array $items): ArbitraryInterface => Gen::tuple(
            Gen::constant($items),
            Gen::intBetween(0, count($items) - 1), // always a valid index
        ),
    )];
}
```

## In-body draws (`Gen::draw`)

When several dependent values make nested `flatMap` awkward, draw them inside
the property body with `Gen::draw()`. The domain may depend on anything already
in scope — parameters, previous draws, intermediate results:

```php
#[Property(runs: 200)]
public function sliceIsContainedInTheList(array $xs): void
{
    $from = Gen::draw(Gen::intBetween(0, count($xs)));
    $to = Gen::draw(Gen::intBetween($from, count($xs))); // depends on $from

    foreach (array_slice($xs, $from, $to - $from) as $item) {
        Assert::true(in_array($item, $xs, true));
    }
}
```

Drawn values shrink together with the parameters. The runner records every
draw on a replay tape; when the property fails, it shrinks each recorded draw
through its own tree and re-runs the body with the tape replayed by position.
A shrunk parameter can change the body's control flow: draws past the tape's
end are generated anew, and draws the smaller run no longer reaches are
dropped. Counterexamples report each draw as `draw#1`, `draw#2`, `draw#3` next to
the named parameters (and `PROPERTY_VERBOSE` logs them per run).

Two things to know:

- A replayed draw is served by position and is **not** re-validated against
  the (possibly narrower) arbitrary of the new control flow — the same model
  as fast-check's `gen()`. Assert what the body actually requires rather than
  relying on the draw's range after shrinking.
- Because the tape can regrow during shrinking, the finite-tree termination
  argument no longer applies on its own; with draws present, accepted shrink
  steps are capped (1000 by default, `maxShrinks` still wins when set).

`Gen::draw()` is only valid while the runner executes a property body;
anywhere else it throws. Prefer `flatMap` for a single dependent value — it
keeps the whole domain visible in the generators method.
