---
title: Composite generators
description: "Gen::composite(): several dependent draws in one reusable generator, shrinking the draws rather than the result, with later draws re-drawn through the range a smaller prefix gives them."
---

# Composite generators

`Gen::flatMap()` builds one dependent value; every further dependency nests
one closure deeper to the right. In-body `Gen::draw()` reads more naturally
but exists only inside a property body, so the shape it builds cannot be
handed to `arrayOf()`, a provider, or another test class. `Gen::composite()`
is the third form: a body that draws everything it needs through a `Draw`
and returns what it built, packaged as an ordinary
[`ArbitraryInterface`](/api/classes/ArbitraryInterface).

```php
use Rasuvaeff\PropertyTesting\Draw;
use Rasuvaeff\PropertyTesting\Gen;

$interval = Gen::composite(static fn (Draw $d): Interval => new Interval(
    $min = $d->draw(Gen::datetime()),
    $d->draw(Gen::datetime(min: $min)),   // sees the prior draw
));

Gen::arrayOf($interval, 1, 20);           // composes like any generator
```

## How it shrinks

The composite does not know how to make an `Interval` smaller; it knows how
to make the *draws* smaller. A candidate re-executes the body with one
recorded draw replaced by a candidate of that draw's own tree, earliest draw
first. What happens to the draws after it is the part worth knowing:

- a draw whose generator did not change comes back **as it was** — every
  position draws from a stream of its own, derived from one seed captured at
  generation time, so replaying the prefix and re-drawing the rest is
  deterministic;
- a draw whose generator *did* change — the `max` above a shrunk `min` — is
  **re-drawn** through the new range from that same stream, rather than
  replayed as a node built for the old range. That is what keeps every
  candidate a value the body would have built.

A body that throws an `Exception` for a smaller draw — a validating
constructor refusing the pair — refuses that candidate: it is skipped with
its subtree, exactly as [`Gen::map()`](/guide/shrinking#integrated-shrinking)
skips a refused candidate. An `Error` propagates; a broken body is not a
smaller value.

## Bounds

The body sees no randomness other than what it draws, so a candidate is a
pure function of the tape it is given. A re-executed body can draw *more*
than the original did, so the tree has no finite bound of its own; the
descent is capped at the same depth the runner applies to in-body draws
(1000 accepted steps). A refusal at generation time — the body throwing for
the values it was first handed — propagates from `generate()`, like a
mapped root.

## Which form to reach for

| Shape | Form |
|---|---|
| One dependent value | `Gen::flatMap()` |
| Several dependent values, used in one property body | in-body `Gen::draw()` |
| Several dependent values, reused as a value (`arrayOf`, a provider, another class) | `Gen::composite()` |
