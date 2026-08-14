---
title: Swarm testing
description: "Why a uniform alphabet hides the bugs that need an operation to be absent, and how Gen::swarm() restricts a choice generator to a non-empty subset of its variants per generated case."
---

# Swarm testing

A property that draws each event uniformly from `oneOf('push', 'pop', 'flush')`
almost never produces a case without `flush`: avoiding one variant for thirty
draws is a coin flipped thirty times. So the bugs that need an operation to be
**absent** — a queue that never once received a flush, a cache that is never
invalidated, a parser that never sees a closing tag — are effectively out of
reach, no matter how many cases you run.

Swarm testing (Groce et al., *Swarm Testing*, ISSTA 2012) changes the
distribution rather than the count: each generated case may use only part of
the alphabet.

```php
Gen::swarm(Gen::oneOf('push', 'pop', 'flush'));   // one case sees, say, only 'pop' and 'flush'
Gen::swarm(Gen::commands($model, $commands));   // one sequence uses a subset of the commands
```

The difference is not subtle. From
[`examples/swarm.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/swarm.php),
over 200 generated cases of 8 commands each:

```
8-command sequences that never used 'flush', out of 200:
  plain commands: 4
  swarmed:        77
```

## What it accepts

Any *choice* generator: `Gen::oneOf()`, `Gen::elements()`, `Gen::frequency()`,
`Gen::commands()`, and any [`Swarmable`](/api/classes/Swarmable) of your own.
Anything else throws — a swarm has no variants to remove from `Gen::int()`.

The subset is never empty, and surviving `frequency` branches keep their
weights: a branch that was twice as likely as its neighbour still is, because
the dropped branch's weight leaves with it instead of being redistributed.

## Shrinking stays inside the subset

A counterexample generated without `flush` shrinks only into values the case
could have produced — it never widens back to the full alphabet. That is the
point rather than an implementation detail: a finding that says "it breaks
when no flush ever arrives" would stop reproducing the moment shrinking put an
flush back in.

It falls out of the structure. `SwarmArbitrary` draws the subset, asks the
generator for a copy restricted to it, and returns *that* generator's shrink
tree. There is no separate rule to enforce, and nothing in the descent knows
about the variants that were removed. The alphabet widens again for the next
case, never during a descent.

## Two things to know

**The subset is drawn once per generated value.** So wrap the generator whose
scope you mean:

```php
Gen::swarm(Gen::commands($model, $commands));      // one subset per sequence — what you want
Gen::arrayOf(Gen::swarm(Gen::oneOf(...$events)));  // a fresh subset per element — noise
```

For a container over a choice generator, spell the same idea out with the pair
a swarm is made of — a non-empty subset plus a generator built over it:

```php
Gen::flatMap(
    Gen::subset(['push', 'pop', 'flush'], minSize: 1),
    static fn(array $available): ArbitraryInterface => Gen::arrayOf(Gen::oneOf(...$available), 30, 30),
);
```

**The counterexample reports the value, not the subset.** Replaying the seed
reproduces both, but a counterexample read on its own does not say which
variants were available when it was drawn. Deliberate: the report describes the
input, and the subset is a property of how that input was drawn.

## Swarming a command sequence

`Gen::commands()` guarantees its `minLength` or throws
[`GenerationExhausted`](/api/classes/GenerationExhausted). Restricting the
commands makes it likelier that no applicable command reaches that minimum —
`Gen::swarm(Gen::commands($model, $commands, minLength: 5))` can therefore
fail generation where the unrestricted generator would not. That is the
existing contract, not a swarm-specific surprise: a sequence shorter than its
declared minimum has never been a valid result. Keep `minLength` at zero when
swarming, or size it against the smallest subset that can still reach it.
