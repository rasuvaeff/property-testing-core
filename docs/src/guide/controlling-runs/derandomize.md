---
title: "Derandomized runs"
description: "Deriving an unset seed from the property id, so a property that fails one run in fifty stops failing CI only some of the time."
---

# Derandomized runs

Without an explicit seed, every run draws a new one. A property that fails for
one input in fifty therefore fails in CI only some of the time, and the run
that finally catches it is not the run anyone is looking at.

The [regression corpus](/guide/regression-corpus) fixes this — but only after
the first failure is recorded. `derandomize` covers the other side of that
moment:

```php
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;

new PropertyConfig(derandomize: true);
```

An unset seed now comes from the property's id, as a pure function of it. The
same property on the same code selects the same inputs on every machine and
every supported PHP version, so a bug found locally reproduces in CI without
waiting for a corpus entry to exist.

An explicit `seed` always wins over the flag.

## What it does not change

The mapping from a seed to the values it produces. Derandomizing changes
*which* seed a run picks, never what that seed generates — corpora recorded
before it can still be replayed, and the pinned sequences in the engine's own
determinism vectors do not move.

## Why it is not the corpus in another shape

They cover opposite sides of the first failure, and they compose:

| | Regression corpus | `derandomize` |
|---|---|---|
| Applies | After a falsification is recorded | Before any failure, and to properties that pass |
| Mechanism | Replays the stored input ahead of the random phase | Chooses the same seed every time |
| On a passing property | Records nothing | Keeps the input distribution stable |

That last cell is worth more than it looks: a passing property writes nothing
to the corpus, so without a derandomized seed its
[distribution numbers](/guide/distribution) are not comparable between two
commits — the inputs moved underneath the measurement.
