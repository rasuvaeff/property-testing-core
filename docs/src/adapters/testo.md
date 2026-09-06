---
title: Testo adapter
description: "The #[Property] attribute, method conventions, environment overrides, and Testo coverage attributes."
---

# Testo adapter

<img src="/adapters/testo/logo-mark.svg" width="56" height="56" alt="" style="float: right; margin: 0 0 1rem 1rem;" />

[`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo)
wires the engine into [Testo](https://github.com/php-testo/testo) via the
`#[Property]` attribute — the same attribute `rasuvaeff/property-testing` 2.x
shipped, same FQCN, drop-in.

```bash
composer require --dev rasuvaeff/property-testing-testo
```

No plugin registration is needed: `#[Property]` self-registers with Testo
through the framework's interceptor discovery. Mark a test method with it and
point it at a generators method that maps each parameter name to a `Gen`
factory — the full walkthrough is [Getting started](/guide/intro/getting-started).

## Conventions

Attribute arguments must be constant expressions, so generators cannot be
passed inline. Name a method returning `array<string, ArbitraryInterface>`
keyed by parameter name; when the `generators` argument is omitted the
adapter falls back to `<testMethod>Generators`. The same pattern covers fixed
examples: `<testMethod>Examples` (or `#[Property(examples: 'method')]`)
returns positional argument tuples that run before the random inputs — see
[Explicit examples](/guide/explicit-examples).

Declare generators and examples methods **`public static`** (`public` if the
body needs `$this`): their only call site is this adapter's reflection, so
Rector's dead-code set would delete private ones.

## Attribute parameters

| Parameter | Meaning |
|---|---|
| `runs` | Successful checks to complete (default 100). Discarded runs do not count |
| `seed` | Pins the random phase for reproduction. Also disables corpus replay for this property — the pinned run wins |
| `generators` | The generators method, by name (default `<testMethod>Generators`) or as a callable: a `[Provider::class, 'method']` reference, a `'Provider::method'` string, or an invokable object — for a set shared between test classes |
| `examples` | The examples method, in the same forms; default `<testMethod>Examples` |
| `auto` | Derive a generator for every parameter the provider does not cover, from the parameter's `@param` psalm type and native type. Strictly opt-in; a provider key that is not a parameter is an error |
| `maxShrinks` | Cap on accepted shrink steps; `0` disables shrinking |
| `maxDiscards` | Cap for the discard budget **and** the skip budget. Left unset the two differ: `runs * 10` for discards, `runs` for environmental skips |
| `timeoutMs` | Wall-clock deadline for a single run — exceeding it fails the property with `DeadlineExceededException` |
| `budgetMs` | Wall-clock budget for the whole random phase — running out fails with `TimeBudgetExceededException` |
| `shrink` | `ShrinkMode::Off` reports the counterexample as generated; `Bounded` is implied by `shrinkBudgetMs` |
| `shrinkBudgetMs` | Wall-clock budget for the shrink descent, returning the best counterexample found so far |
| `phases` | The stages to perform (`Phase::Examples`/`Corpus`/`Random`/`Shrink`); see [Run phases](/guide/controlling-runs/phases) |
| `derandomize` | Derive an unset seed from the property id instead of drawing one |
| `path` | Replay a recorded shrink descent instead of searching for it; requires an explicit `seed` |
| `edgeCases` | `EdgeCases::None` turns off the numeric boundary bias |

## Environment overrides

The same variables as every adapter — see [Environment overrides](/guide/controlling-runs/env-overrides).
`PROPERTY_DB`'s [regression corpus](/guide/regression-corpus) is
byte-compatible with what `rasuvaeff/property-testing` 2.8 wrote: an existing
CI corpus keeps working after migrating.

## Coverage attributes

The adapter aggregates the per-run `TestResult` attributes of every executed
body — Testo codecov's `CoverageResult` among them — onto the single
`TestResult` a property test reports. Property tests therefore appear in
per-test coverage, and Infection runs them against mutants like any other
test.

## Stateful / model-based testing

The engine's state machine works unchanged under `#[Property]` — see
[State machine: concepts](/guide/state-machine/concepts) and
[`examples/state_machine.php`](https://github.com/rasuvaeff/property-testing-testo/blob/master/examples/state_machine.php)
for the full runnable stack example.

## Generators

The full generator catalog belongs to the engine and is identical from every
adapter — see [Generators](/guide/generators/index).

## Public API of this package

| Type | Role |
|---|---|
| `Rasuvaeff\PropertyTesting\Property` | The attribute — the same FQCN 2.x shipped |
| `Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor` | Testo interceptor: resolves reflection conventions and environment into a core `PropertyDefinition`, maps the structured result to one `TestResult` |

`TestoTrialExecutor` (which executes the body through Testo's pipeline) and
`VerboseListener` (which renders `PROPERTY_VERBOSE` output) are `@internal`:
the interceptor wires both, and neither is something a suite constructs.

## Drive the engine directly

Neither Testo nor PHPUnit is a requirement — `rasuvaeff/property-testing-core`
has no test-framework dependency. Build a `PropertyDefinition` (generators
keyed by parameter name plus a `PropertyConfig`), execute the body through a
`TrialExecutor` (`CallableTrialExecutor` adapts a plain closure), and inspect
the `PropertyResult` the `PropertyRunner` returns:

```php
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

$definition = new PropertyDefinition(
    id: 'demo::everyIntStaysBelowHundred',
    name: 'everyIntStaysBelowHundred',
    generators: ['value' => Gen::intBetween(0, 10_000)],
    parameterNames: ['value'],
    config: new PropertyConfig(runs: 200, seed: 42),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(
    static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    },
));

if ($result instanceof Falsified) {
    $example = $result->counterExample();
    // $example->seed, $example->originalArguments, $example->shrunkArguments, ...
    fwrite(STDERR, $result->failure()->getMessage());
}
```

The engine never reads environment variables, never prints, and never exits —
`PropertyRunner::run()` always returns a structured
[`PropertyResult`](/api/classes/Runner/PropertyResult); a custom harness
decides what to do with it. See
[`examples/standalone_runner.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/standalone_runner.php)
for the full runnable script and `composer require --dev
rasuvaeff/property-testing-core` (without either adapter) to install just the
engine.

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
