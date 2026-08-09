---
title: PHPUnit adapter
description: "The fluent trait, how results map onto PHPUnit assertions, and the supported PHPUnit version matrix."
---

# PHPUnit adapter

<img src="/adapters/phpunit/logo-mark.svg" width="56" height="56" alt="" style="float: right; margin: 0 0 1rem 1rem;" />

[`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit)
wires the engine into PHPUnit: a `PropertyTesting` trait with a fluent
`forAll()->check()` API over the framework-agnostic runner.

```bash
composer require --dev rasuvaeff/property-testing-phpunit
```

Requires `phpunit/phpunit` `^11.5 || ^12.0`. No configuration is needed: mix
the trait into a `TestCase` and call `forAll()` from a test method.

## Usage

Map each property-body parameter to a generator, configure the run with the
fluent chain, and hand the property to `check()`:

```php
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;

final class SortPropertyTest extends TestCase
{
    use PropertyTesting;

    public function testSortIsIdempotent(): void
    {
        $this->forAll(['values' => Gen::arrayOf(Gen::int())])
            ->runs(300)
            ->check(static function (array $values): void {
                sort($values);
                $once = $values;
                sort($values);

                self::assertSame($once, $values);
            });
    }
}
```

The closure's **parameter names select the generators**, exactly like a
`#[Property]` method signature does under the [Testo adapter](/adapters/testo).
On failure the test fails with the engine's message:

```
Property falsified after 12 successful run(s); seed=7382910
  Original: values=[20, 82, 44, 43, 29, 47, 29, 0, … +4 more]
  Shrunk:   values=[0, 0, 0, 0, 0, 0] (7 shrink step(s), 29 trial(s))
  Changed:  values=[20, 82, 44, …] -> [0, 0, 0, 0, 0, 0]
```

Reproduce the exact run by pinning the reported seed: `->seed(7382910)`.

## The fluent chain

`forAll()` returns a `PropertyCheck`; every setter returns it for chaining,
and `check()` runs the property.

| Method | Meaning |
|---|---|
| `runs(int)` | Successful checks to complete (default 100). Discarded runs do not count |
| `seed(int)` | Pins the random phase for reproduction. Also disables corpus replay — the pinned run wins |
| `maxShrinks(int)` | Cap on accepted shrink steps; `0` disables shrinking |
| `maxDiscards(int)` | Discard budget before the property fails with `GaveUpException`; default `runs * 10` |
| `timeoutMs(int)` | Wall-clock deadline for a single run — exceeding it fails with `DeadlineExceededException` |
| `budgetMs(int)` | Wall-clock budget for the whole random phase — running out fails with `TimeBudgetExceededException` |
| `examples(array)` | Fixed positional argument tuples run **before** the random phase; a failing example short-circuits, unshrunk |
| `listeners(...)` | `PropertyListener` observers of the engine's lifecycle events |
| `output($stdout, $stderr)` | Redirects the distribution report, discard warning and verbose trace (used by this package's own tests) |

## How results map onto PHPUnit

- A **pass** counts one assertion — the test is never marked risky.
- Every **failing outcome** (falsified, gave up, unmet coverage, deadline,
  budget, generation failure, failing example, replayed regression) surfaces
  as **one `AssertionFailedError`** whose message is the engine's own — seed,
  original and shrunk arguments, shrink statistics — and whose `previous` is
  the engine exception (`PropertyViolationException`, `GaveUpException`,
  `RegressionViolationException`, …).
- `Assume::that()` is a **discarded run inside the property**, retried by the
  engine — never a skipped PHPUnit test.

## Environment overrides

Byte-for-byte parity with the Testo adapter — see
[Environment overrides](/guide/controlling-runs/env-overrides). The
[regression corpus](/guide/regression-corpus) format is exactly the one
`rasuvaeff/property-testing` 2.8 wrote — a corpus recorded under Testo (or
under 2.x) replays here and vice versa.

## Distribution and discards

`Classify::label()`/`when()`/`cover()` work inside the property body exactly
as described in [Distribution](/guide/distribution). When a classified
property passes, the adapter prints the label distribution:

```
Property "testSortKeepsEveryElement" distribution: long 39% (77/200), short 61% (123/200)
```

## Why no `#[Property]` attribute?

PHPUnit's public extension/event API observes test execution but offers no
stable contract for intercepting and re-invoking a test method many times —
which is exactly what a property attribute must do. This adapter deliberately
does not depend on PHPUnit internals; the fluent API needs only the
documented surface. An attribute may appear later, only if it can be built on
the documented extension API of the supported majors.

## Generators

The full generator catalog belongs to the engine and is identical from every
adapter — see [Generators](/guide/generators/index). Everything there is
usable from a `check()` closure as-is, including [state machine
testing](/guide/state-machine/concepts).

## Public API of this package

| Type | Role |
|---|---|
| `Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting` | The trait a `TestCase` mixes in; `forAll()` is its single entry point |
| `Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck` | The fluent builder: resolves the chain and the environment into a core `PropertyDefinition`, runs the engine, maps the structured result onto PHPUnit |
| `Rasuvaeff\PropertyTesting\PhpUnit\VerboseListener` | `PROPERTY_VERBOSE` output as an exception-hardened engine listener (internal) |

## Security

Generated values are pseudo-random (seeded MT19937), not cryptographic —
seeds are printed in failure output by design, not secrets. See
[Security](/guide/security) for what stays the test author's responsibility,
including `PROPERTY_DB` corpus files.

## Examples

See [Examples](/guide/examples) for the full per-package table.

## Development

```bash
make install     # composer install (Docker)
make build       # validate + normalize + require-checker + cs + psalm + tests
make cs-fix      # apply code style
make mutation    # infection mutation testing
```

Tests run through PHPUnit (`composer test` is `phpunit`), not Testo.
