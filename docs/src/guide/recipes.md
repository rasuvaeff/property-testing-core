---
title: "Recipes"
description: "Common property patterns: round-trip, idempotence, commutativity/associativity, and testing an adapter against the library it wraps."
---

# Recipes

Dependent values without discards — build, don't filter:

```php
// A size and a payload of exactly that size.
Gen::flatMap(Gen::intBetween(1, 32), static fn(int $size): ArbitraryInterface
    => Gen::tuple(Gen::constant($size), Gen::bytes($size, $size)));

// An ordered interval: Gen::intRange(0, 1440) yields [lo, hi] with lo <= hi.

// Domain strings from an alphabet instead of filtering Unicode.
Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789-', 1, 63); // hostname label
```

Bounded recursive data:

```php
use Rasuvaeff\PropertyTesting\Arbitrary\ArrayArbitrary;

// JSON-ish scalars nested in small arrays, at most 3 levels deep.
Gen::recursive(
    Gen::oneOf(null, true, false, 0, 1, 'a'),
    static fn(ArbitraryInterface $inner): ArbitraryInterface => new ArrayArbitrary($inner, 0, 3),
    maxDepth: 3,
);
```

Keep the branch fan-out small (bounded array sizes): breadth multiplies at
every level of nesting.
