---
title: "Sampling & exporting"
description: "Gen::sample() and Gen::sampleShrinks() let you eyeball what a generator actually produces, and export a counterexample as reusable PHP code."
---

# Sampling & exporting

## Sampling a generator

`Gen::sample()` eagerly generates values from any arbitrary for a fixed seed — a
quick way to eyeball what a generator produces (it returns values, not an
arbitrary).

```php
Gen::sample(Gen::intBetween(1, 6), count: 5, seed: 42); // [3, 1, 6, 6, 2]
```

`Gen::sampleShrinks()` does the same for the shrink tree: it generates one
value and lists its first direct shrink candidates — the fastest way to check
that a custom arbitrary shrinks the way you intended.

```php
Gen::sampleShrinks(Gen::intBetween(0, 100), seed: 1);
// ['value' => 87, 'shrinks' => [0, 44, 66, 77, 82, 85, 86]]
```

## Exporting a counterexample

[`CounterExample::toArray()`](/api/classes/CounterExample) and `toJson()` expose
a normalized representation for reporters and CI artifacts, including nested
DTO state and recursion markers. To pin a shrunk scalar/array/enum case as a
regression example:

```php
$code = $violation->getCounterExample()->toExamplesCode('holdsExamples');
```

The generated method yields arguments in parameter order. Unsupported runtime
objects throw `LogicException` instead of producing code that cannot run.
