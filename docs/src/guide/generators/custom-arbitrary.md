---
title: "Writing your own arbitrary"
description: "Implementing ArbitraryInterface directly for a domain-specific generator, with a shrink tree that terminates and never repeats its parent's value."
---

# Writing your own arbitrary

`Gen` covers common cases, but any value space is reachable by implementing
[`ArbitraryInterface`](/api/classes/ArbitraryInterface) directly:
`generate(Random)` returns a [`Shrinkable`](/api/classes/Shrinkable) — the
drawn value plus a lazy tree of smaller candidates, most aggressive first,
each carrying its own subtree. Draw randomness only through the injected
`Random` (`int()`, `float()`, `bytes()`) so seeded runs stay reproducible.

```php
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Even integers in [0, $max], shrinking toward 0 in even steps.
 */
final readonly class EvenArbitrary implements ArbitraryInterface
{
    public function __construct(private int $max = 1000) {}

    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->tree($random->int(0, intdiv($this->max, 2)) * 2);
    }

    private function tree(int $value): Shrinkable
    {
        return Shrinkable::of($value, function () use ($value): \Generator {
            if ($value === 0) {
                return;
            }

            yield $this->tree(0);

            $half = intdiv($value, 4) * 2; // stay even

            if ($half !== 0 && $half !== $value) {
                yield $this->tree($half);
            }
        });
    }
}
```

A custom arbitrary is used like any built-in: return it from the generators
method keyed by parameter name. `Shrinkable::leaf($value)` builds a terminal
node (no candidates); `Shrinkable::of($value, $closure)` attaches lazily
computed candidates; `Shrinkable::map($fn)` transforms a whole tree. Keep every
branch of the tree finite and never yield a candidate equal to its parent —
that is what guarantees shrinking terminates. See [Shrinking](/guide/shrinking)
for the full termination argument.
