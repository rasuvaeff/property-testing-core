---
title: "Generating from a class"
description: "Gen::forClass() builds a generator from what a constructor already declares — and Gen::forParameters() applies the same rules to any signature, which is what the adapters' auto mode is made of."
---

# Generating from a class

jqwik has type-driven `@ForAll`, Rust has `derive(Arbitrary)`. Both read the
types the language already carries. PHP has no macros — but it does have
promoted constructor properties and psalm annotations, and in a codebase that
writes them the information a derive macro would need is already there, going
to waste.

```php
Gen::forClass(Money::class);
Gen::forClass(Money::class, ['amount' => Gen::intPositive()]);
```

## The annotations are the point

These two classes have the same native types:

```php
final readonly class LooseMoney
{
    public function __construct(public int $amount, public string $label) {}
}

final readonly class Money
{
    /**
     * @param int<0, 1000> $amount
     * @param non-empty-string $label
     * @param list<int> $tags
     */
    public function __construct(
        public int $amount,
        public string $label,
        public array $tags,
        public Currency $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must be greater than or equal to 0');
        }
    }
}
```

And a very different value space, which is what the generator produces
([`examples/for_class.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/for_class.php)):

```text
Native types — anything an int can be:
  amount=-1
  amount=-1974865818361177172
  amount=-9223372036854775808

Annotated — int<0, 1000>, non-empty-string, list<int>, an enum:
  amount=1 currency=Eur tags=6 label-length=22
  amount=117 currency=Usd tags=9 label-length=55
  amount=0 currency=Usd tags=3 label-length=7
```

Guessing `int` for the second class would have every fifth value rejected by
its own constructor. Reading `int<0, 1000>` is the difference between a
generator that matches the domain and a demo.

## What it reads, in order

Per parameter: an **override**, then the **`@param` docblock**, then the
**native type**. The docblock wins over the native type because it says more.

| Written | Generated |
|---|---|
| `int`, `float`, `string`, `bool` | the matching generator |
| `int<0, 100>`, `int<min, 10>`, `int<0, max>` | `Gen::intBetween()` with those bounds |
| `positive-int`, `negative-int`, `non-negative-int`, `non-positive-int` | the matching range |
| `non-empty-string` | `Gen::stringOf(1, 100)` |
| `list<T>`, `non-empty-list<T>`, `T[]` | `Gen::arrayOf()` |
| `array<K, V>`, `non-empty-array<K, V>` | `Gen::dictOf()` |
| `'draft'\|'published'`, `1\|2\|3` | `Gen::elements()` — a domain spelled out in the type |
| `A\|B` | `Gen::frequency()` over both |
| `?T` | `Gen::nullable()` |
| an enum | `Gen::enum()` |
| `DateTimeImmutable` | `Gen::datetime()` |
| another class | followed recursively, to `maxDepth` |

Anything else — a bare `array`, `mixed`, `callable`, an intersection, a
variadic constructor — is an **exception naming the parameter**, never a
widened guess:

```
Cannot generate …\Unreadable: parameter $anything is typed array, which this
cannot read; pass an override
```

A class it cannot instantiate — a value object with a private constructor and
named factories, say — names the chain that reached it, because such a class is
usually three levels down from the one you asked for:

```
Cannot generate …\Duration: it is not instantiable (reached through
…\BreakerConfig -> …\Ratio -> …\Duration); pass an override
```

That refusal is the design. A guessed generator does not fail here; it fails
later, in somebody's test, as a value their code was never meant to see.

## A validating constructor

Constructors in this family reject bad input, and by default a rejection
propagates. That is information: the generator does not match the domain, and
an override or a narrower annotation is the fix.

`skipInvalid: true` is the deliberate opposite — the value is discarded and
redrawn by the same [`Gen::filter()`](/guide/controlling-runs/assume-vs-filter)
every other filtered generator uses, with the same hundred attempts and the
same `GenerationExhausted` at the end. Only exceptions are discarded, never
`Error`s: a `TypeError` means the generator produced the wrong type, which is a
bug rather than a rejected value.

## Cycles and depth

A class reachable from itself is refused with the chain named, rather than
followed until the stack ends; so is a chain deeper than `maxDepth`
(3 by default). An override on the offending parameter breaks either.

## Shrinking is unaffected

The instance is built by mapping over the generated arguments, so
[integrated shrinking](/guide/shrinking) works through it: the counterexample
shrinks by shrinking the arguments and rebuilding the object, and with
`skipInvalid` the candidates the constructor would reject are pruned from the
descent rather than thrown from it.

## The same rules for a whole property

`Gen::forParameters()` (core 0.4) applies the same resolution — an override,
the `@param` psalm type, then the native type, with the same supported subset
and the same refusals — to the parameters of any function, method, or closure,
and returns the map a property needs: `array<string, ArbitraryInterface>` by
parameter name, in signature order.

Both adapters expose it as an opt-in `auto` mode, so a fully typed property
needs no provider at all. Under Testo (0.6):

```php
/**
 * @param int<1, 300> $base
 * @param int<1, 86400> $cap
 */
#[Property(auto: true)]
public function delayStaysWithinCap(int $base, int $cap): void { /* … */ }
```

Under PHPUnit (0.5), where the docblock sits on the closure itself:

```php
$this->forAll()
    ->auto()
    ->check(
        /**
         * @param int<1, 300> $base
         * @param int<1, 86400> $cap
         */
        function (int $base, int $cap): void { /* … */ },
    );
```

The provider — the `<method>Generators` convention, an explicit `generators`
argument, the `forAll()` map — becomes the **overrides** and may be partial:
the parameters it names are taken as given, the rest are derived. That is the
escape hatch for a domain no psalm type can express — a float range, a
dependent pair built with `flatMap()`:

```php
/** @param int<1, 40> $attempt */
#[Property(generators: 'provide', auto: true)]
public function delayIsMonotonic(float $multiplier, int $attempt): void { /* … */ }

/** @return array<string, ArbitraryInterface> */
public static function provide(): array
{
    return ['multiplier' => Gen::floatBetween(1.0, 4.0)];   // the rest is derived
}
```

Two deliberate edges. Auto is opt-in and stays opt-in: a bare `int` or `float`
derives its full native domain, and only the property's author knows whether
that is the one they meant. And under auto, a provider key that is not a
parameter of the property is an error — merge semantics would otherwise
silently replace a mistyped entry with a signature-derived generator.

There is no `skipInvalid` here: nothing is executed at derivation time, and a
property body filters untrusted input through
[`Assume`](/guide/controlling-runs/assume-vs-filter), as always.
