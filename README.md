# rasuvaeff/property-testing-core

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-core/v)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-core/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![Build](https://github.com/rasuvaeff/property-testing-core/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-core/php)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[Русская версия](README.ru.md)

Framework-agnostic property-based testing **engine** for PHP 8.3+: generators
with integrated shrinking, a structured property runner, a regression corpus,
lifecycle events, and stateful/model-based testing — with no dependency on any
test framework. Generate hundreds of random inputs, find the failing one, and
shrink it to a minimal counterexample you can actually read.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Part of the property-testing family

| Package | Use it when |
|---|---|
| **`rasuvaeff/property-testing-core`** (this package) | You drive the engine yourself: a custom harness, CI guard, CLI checker, or another framework adapter |
| [`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo) | You test with [Testo](https://github.com/php-testo/testo) — drop-in replacement for the frozen `rasuvaeff/property-testing` with the same `#[Property]` attribute |
| [`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit) | You test with PHPUnit — a `PropertyTesting` trait with a fluent `forAll()->check()` API |

> **Note:** this package `conflict`s with the frozen `rasuvaeff/property-testing`
> (2.x) — both ship classes in the `Rasuvaeff\PropertyTesting` namespace, so
> Composer refuses to install them together. Migrating from 2.x? Swap the dev
> dependency for the adapter matching your framework; your imports stay as they
> are. [MIGRATION.md](MIGRATION.md) is the full guide: one command for Testo
> projects, plus the custom-harness and PHPUnit paths.

## Requirements

- PHP 8.3+
- `ext-mbstring`
- `ext-random`

## Installation

```bash
composer require --dev rasuvaeff/property-testing-core
```

The engine has no test-framework dependency: you hand it a property definition
and an executor, it hands you back a structured result. It never reads
environment variables, never prints, never exits, and never throws to report a
property outcome.

## Usage

Build a `PropertyDefinition` (generators keyed by parameter name plus a
`PropertyConfig`), execute the body through a `TrialExecutor`
(`CallableTrialExecutor` adapts a plain closure), and inspect the
`PropertyResult` the `PropertyRunner` returns:

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

The failure message renders the counterexample:

```
Property falsified after 0 successful run(s); seed=42
  Original: value=54
  Shrunk:   value=100 (3 shrink step(s), 11 trial(s))
  Changed:  value=54 -> 100
  Failure:  100 is not below 100
```

The `Changed:` line diffs the original against the shrunk counterexample —
arguments the shrinker left untouched are omitted. `trial(s)` counts every
candidate the shrinker ran (accepted and rejected); `shrink step(s)` counts
only the accepted ones. Reproduce the exact run by pinning the reported seed in
`PropertyConfig`.

See [`examples/standalone_runner.php`](examples/standalone_runner.php) for the
full runnable script.

### The executor seam

`TrialExecutor` is the boundary between the engine and whatever executes the
property body. Each `execute($arguments)` call returns a `TrialOutcome` —
`passed()`, `failed($throwable)`, or `discarded()`:

- `CallableTrialExecutor` — the standalone executor: a normal return passes,
  `Assume::that()` discards, any other throwable fails the trial.
- Framework adapters implement their own (Testo maps a `TestResult`, PHPUnit
  maps assertion exceptions) — the run/shrink loop never learns about
  framework types.

### Structured results

`PropertyRunner::run()` returns one of a closed `PropertyResult` hierarchy —
impossible data combinations are not representable, and every failing outcome
carries the engine's own exception type with the established message format:

| Result | Meaning | Carries |
|---|---|---|
| `Passed` | Every check completed, every coverage requirement held | `RunStatistics` |
| `Falsified` | A random run failed; the counterexample is shrunk | `PropertyViolationException` → `CounterExample` |
| `GaveUp` | Discard budget exhausted before `runs` checks | `GaveUpException`, `RunStatistics` |
| `CoverageFailed` | Every run passed but a `Classify::cover()` requirement was missed | `CoverageViolationException`, `RunStatistics` |
| `DeadlineExceeded` | A single run overran `timeoutMs` | `DeadlineExceededException` |
| `TimeBudgetExceeded` | The random phase overran `budgetMs` | `TimeBudgetExceededException`, `RunStatistics` |
| `GenerationFailed` | A generator could not produce a valid value | `GenerationExhausted` |
| `ExampleFailed` | An explicit example failed (examples run first, unshrunk) | `ExampleViolationException` |
| `RegressionFailed` | A recorded corpus entry still fails | `RegressionViolationException` |

Configuration errors (`runs < 1`, a missing generator, mismatched parameter
names) remain exceptions — they are programmer errors, not verdicts about the
property.

`RunStatistics` exposes the raw phase counters (attempts, discards, checks,
per-label classification counts) so a reporter can print a distribution table
or a discard warning — the engine itself never formats framework output.

Serialization: every result survives native `serialize()` when captured stack
traces carry no argument values (`zend.exception_ignore_args=1`); the portable
machine format is `CounterExample::toArray()` / `toJson()`.

### Generators

All factories live on the `Gen` facade; each returns an implementation of
`ArbitraryInterface` whose `generate(Random)` yields a `Shrinkable` — the value
plus a lazy tree of smaller candidates, so transformed generators shrink
through their source domain.

| Factory | Produces | Shrinks |
|---|---|---|
| `Gen::int()` | `IntArbitrary`, `PHP_INT_MIN..PHP_INT_MAX` | toward `0` |
| `Gen::intBetween($min, $max)` | `IntArbitrary`, `[$min, $max]` | toward `0`, clamped to range |
| `Gen::intPositive()` | `IntArbitrary`, `1..PHP_INT_MAX` | toward `1` |
| `Gen::float()` | `FloatArbitrary`, `[0.0, 1.0)` | toward `0.0` |
| `Gen::floatBetween($min, $max)` | `FloatArbitrary`, `[$min, $max]` | toward `0.0`, clamped to range |
| `Gen::bool()` | `BoolArbitrary`, `true` / `false` | `true` -> `false` |
| `Gen::string()` | `StringArbitrary`, Unicode, length 0..100 | toward `''`, then by length, then each character toward `a` |
| `Gen::stringAscii()` | `StringArbitrary`, printable ASCII, length 0..100 | toward `''`, then by length, then each character toward `a` |
| `Gen::stringOf($min, $max)` | `StringArbitrary`, Unicode, bounded length | toward `''`, then by length, then each character toward `a` |
| `Gen::stringFrom($alphabet, $min, $max)` | `CharsetStringArbitrary`, characters from a fixed alphabet (multibyte OK) | toward `''`, then by length, then each character toward the first alphabet character |
| `Gen::bytes($min, $max)` | `BytesArbitrary`, raw byte strings (bytes 0..255) | toward `''`, then by length, then each byte toward `"\x00"` |
| `Gen::arrayOf($element, $min, $max)` | `ArrayArbitrary`, lists of `$element`, size 0..100 by default | toward `[]`, then by length, then each element |
| `Gen::nonEmptyArrayOf($element, $max)` | `ArrayArbitrary`, non-empty lists | by length (never below 1), then each element |
| `Gen::uniqueArrayOf($element, $min, $max)` | `UniqueArrayArbitrary`, lists of pairwise-distinct elements | like `arrayOf`, but element candidates colliding with another element are skipped |
| `Gen::subset($values, $min, $max)` | `SubsetArbitrary`, subsets of a fixed ordered set — distinct members of `$values` in source order; duplicates in the source are rejected | size first (toward the empty set), then each kept element toward earlier source positions — the minimal subset is a short prefix |
| `Gen::dictOf($key, $value, $min, $max)` | `DictionaryArbitrary`, maps with distinct keys from `$key` (int/string) and values from `$value`, size 0..100 by default | toward `[]`, then by size, then each value (keys fixed) |
| `Gen::record($shape)` | `RecordArbitrary`, fixed-shape map `['field' => $arb, ...]` | each field via its arbitrary, key set fixed |
| `Gen::elements($array)` | `OneOfArbitrary`, one value from an array (array form of `oneOf`) | toward earlier-listed distinct values |
| `Gen::enum(SomeEnum::class)` | `OneOfArbitrary` over the enum's cases | toward earlier-declared cases (declare simpler cases first) |
| `Gen::constant($value)` | `ConstantArbitrary`, always `$value` | does not shrink |
| `Gen::char()` | `StringArbitrary`, a single printable ASCII character | toward `a` |
| `Gen::uuid()` | `UuidArbitrary`, RFC 4122 v4 UUID strings | does not shrink |
| `Gen::datetime($min, $max)` | `DateTimeArbitrary`, UTC `DateTimeImmutable`, timestamp in `[$min, $max]` | toward the Unix epoch, clamped |
| `Gen::floatSpecial()` | `OneOfArbitrary` over `NAN`, `±INF`, `-0.0` and the float representation edges | toward earlier-listed specials |
| `Gen::intRange($min, $max)` | `FlatMappedArbitrary`, ordered pairs `[lo, hi]` with `lo <= hi` | both bounds shrink, order always holds |
| `Gen::recursive($leaf, $wrap, $maxDepth)` | bounded recursive structures: `$wrap` lifts the previous level's arbitrary | within the branch that generated the value |
| `Gen::oneOf(...$values)` | `OneOfArbitrary`, one of the given values | toward earlier-listed distinct values (put simpler values first) |
| `Gen::nullable($inner)` | `NullableArbitrary`, `null` or an `$inner` value | prefers `null`, then the inner tree |
| `Gen::map($inner, $fn)` | `MappedArbitrary`, `$inner` transformed by `$fn` | through the inner tree, re-applying `$fn` |
| `Gen::flatMap($inner, $fn)` | `FlatMappedArbitrary`, dependent generator returned by `$fn($innerValue)` | source value first (dependent value regenerated), then the dependent tree |
| `Gen::filter($inner, $predicate)` | `FilteredArbitrary`, `$inner` values satisfying `$predicate` (throws `GenerationExhausted` after 100 rejected draws — never yields an out-of-domain value) | inner tree, pruning candidates that fail the predicate |
| `Gen::tuple(...$elements)` | `TupleArbitrary`, fixed-arity tuple, one value per element | each position via its element, arity fixed |
| `Gen::frequency($pairs)` | `FrequencyArbitrary`, weighted choice over `[weight, arbitrary]` pairs | within the branch that generated the value |
| `Gen::ipv4()` | IPv4 dotted-quad strings | each octet toward `0` |
| `Gen::email()` | `local@label.tld` addresses | toward the shortest local/label and first TLD |
| `Gen::url()` | `http(s)://host.tld[/path]` URLs | toward `http://a.com` |
| `Gen::json($maxDepth)` | a JSON-encodable value (null/bool/int/float/string/list/object) | within the generated structure |
| `Gen::jsonString($maxDepth)` | the `json_encode` text of `Gen::json()` | through the value's tree |
| `Gen::regex($pattern)` / `Gen::stringMatching($pattern)` | strings matching a regex subset (compiled to combinators) | shorter/simpler matches (via the compiled trees) |
| `Gen::commands($initialModel, $commandGenerators, $min, $max)` | `CommandSequenceArbitrary`, valid command sequences for stateful testing | drops command blocks, then simplifies each command |

Numeric generators (`int*`, `float*`) are **boundary-biased**: roughly one draw in
five returns an in-range edge value (`0`, `±1`, `min`, `max` for ints; `0.0` or
`min` for floats), where bugs cluster, instead of a uniform one. Shrinking is
unaffected.

Sized generators guarantee their **minimum**: `uniqueArrayOf`/`dictOf` (distinct
elements/keys) and `commands` (applicable steps) may fall short of the *drawn*
size when the value space runs out, but never fall below `$min` — an unreachable
minimum throws `GenerationExhausted` rather than hand the property a too-small
value.

`Random` wraps an object-scoped MT19937 engine: two instances with the same
seed produce identical sequences regardless of other random calls in the
process — that is what makes reported seeds reproducible. Do not use generated
values for cryptography.

### Dependent generators (`flatMap`)

When one input's domain depends on another — a list plus a valid index into it,
a size plus a payload of that size — `Gen::flatMap()` feeds each generated value
into a closure that returns the arbitrary for the final value. Unlike an
`Assume::that()` guard, no runs are discarded, and both levels shrink:

```php
Gen::flatMap(
    Gen::nonEmptyArrayOf(Gen::int()),
    static fn(array $items): ArbitraryInterface => Gen::tuple(
        Gen::constant($items),
        Gen::intBetween(0, count($items) - 1), // always a valid index
    ),
);
```

### In-body draws (`Gen::draw`)

When several dependent values make nested `flatMap` awkward, draw them inside
the property body with `Gen::draw()` — valid only while the runner executes a
body (anywhere else it throws). Drawn values are recorded on a replay tape,
shrunk like extra parameters, and reported as `draw#1`, `draw#2`, ... in the
counterexample. With draws present, accepted shrink steps are capped (1000 by
default; an explicit `maxShrinks` wins) to guarantee termination.

### Discarding runs (`Assume`)

`Assume::that($condition)` discards the current attempt when a precondition
does not hold — the attempt is neither a failure nor a successful check, and
`runs` still means successful checks. Retries stop at `maxDiscards` (default
`runs * 10`), failing with a structured `GaveUpException`. Construct valid
inputs (`flatMap`/`draw`) instead of discarding broadly.

### Distribution (`Classify`)

`Classify::label()` / `Classify::when()` tally labels per run;
`Classify::cover($condition, $label, $minPercent)` turns the tally into a hard
requirement — a passing property whose label coverage falls short fails with
`CoverageFailed`. The counts come back on `RunStatistics::$classifications`;
printing a distribution report is the adapter's job.

### Configuration

`PropertyConfig` carries every knob the engine understands — the runner reads
no environment:

| Field | Default | Meaning |
|---|---|---|
| `runs` | 100 | Successful checks to complete (discards do not count) |
| `seed` | `null` | Random-phase seed; null draws one (reported in failures) |
| `maxShrinks` | `null` | Cap on accepted shrink steps; 0 disables shrinking |
| `maxDiscards` | `null` | Discard budget; null resolves to `runs * 10` |
| `timeoutMs` | `null` | Wall-clock deadline per single run → `DeadlineExceeded` |
| `budgetMs` | `null` | Wall-clock budget for the whole random phase → `TimeBudgetExceeded` |

`PropertyDefinition` adds the identity (`id` keys events and the corpus),
display `name`, `generators`, `parameterNames`, fixed `examples` (positional
tuples run before the random phase, never shrunk), and `replayRegressions`
(adapters turn it off when the property pins its own seed).

The `PROPERTY_RUNS` / `PROPERTY_SEED` / `PROPERTY_VERBOSE` / `PROPERTY_DB`
environment variables are **adapter** conventions: the adapters resolve them
into a `PropertyConfig` and a `Corpus`. The one helper the engine ships is
`FilesystemCorpus::fromEnv()`, which reads `PROPERTY_DB` when *you* call it.

### Regression corpus

Pass a `Corpus` to `PropertyRunner::run()` and every falsification is recorded;
recorded failures replay **before** the random phase — one that still fails is
reported immediately (`RegressionFailed` for a values entry), one that no
longer fails is pruned. No corpus argument means no replay and no filesystem
access at all.

`FilesystemCorpus` is the built-in implementation: one small JSON file per
property (`<sha1(id)>.json`, at most 8 values entries and 2 seed entries,
oldest evicted; atomic, lock-serialised writes). The format is byte-compatible
with the corpus written by `rasuvaeff/property-testing` 2.8 — existing corpora
keep working after the migration.

| Entry (`CorpusEntry`) | When | Replay |
|---|---|---|
| Values | Every minimised argument is representable as data (null/scalars/arrays/enum cases/byte strings) | One run with the exact recorded input |
| Seed | Objects, closures, or in-body `Gen::draw()` values in the counterexample | The whole random phase, re-run with that seed; fenced off by the sequence epoch |

### Events and listeners

Pass `PropertyListener` implementations to `PropertyRunner::run()` and observe
the whole lifecycle — this is how a console reporter, telemetry exporter, or
IDE integration attaches without any engine change:

| Event | Fired |
|---|---|
| `PropertyStarted` / `PropertyFinished` | Around the whole property (id, seed, runs / final failure or null) |
| `ExampleStarted` / `ExampleFinished` | Around each explicit example |
| `RunStarted` / `RunPassed` / `RunDiscarded` / `RunFailed` | Around each random run (arguments, draws, labels, elapsed time) |
| `ShrinkTried` / `ShrinkAccepted` | Per shrink candidate / per accepted step |
| `CorpusReplayed` / `CorpusPruned` / `CorpusStored` | Corpus activity |

Events carry engine data only — never framework types. A listener exception
aborts the run (an observer's failure is an infrastructure failure, not
something to hide), and a listener can never change a property's outcome. See
[`examples/custom_listeners.php`](examples/custom_listeners.php) for a console
reporter and a telemetry collector built purely on events.

### Deterministic time (`Clock`)

The runner measures deadlines and budgets through the `Clock` abstraction —
`MonotonicClock` (the default, `hrtime`-based) in production, a fake clock in
tests, injected via the `PropertyRunner` constructor. That is what makes
`timeoutMs`/`budgetMs` behaviour exactly testable.

### Writing your own arbitrary

Any value space is reachable by implementing `ArbitraryInterface` directly:
`generate(Random)` returns a `Shrinkable` — the drawn value plus a lazy tree of
smaller candidates, most aggressive first, each carrying its own subtree. Draw
randomness only through the injected `Random` (`int()`, `float()`, `bytes()`)
so seeded runs stay reproducible. `Shrinkable::leaf($value)` builds a terminal
node; `Shrinkable::of($value, $closure)` attaches lazily computed candidates;
`Shrinkable::map($fn)` transforms a whole tree. Keep every branch finite and
never yield a candidate equal to its parent — that is what guarantees shrinking
terminates.

### Stateful / model-based testing

Some bugs only surface across a *sequence* of operations. Implement `Command`
(`preCondition` / `nextState` / `run` / `postCondition` plus a `__toString`
label), generate valid sequences with `Gen::commands()`, and drive them with
`StateMachine::check()` inside the property body — a failed postcondition
throws `PostconditionViolation` naming the step, and the failing
`CommandSequence` shrinks to the shortest sequence that still breaks:

```php
$definition = new PropertyDefinition(
    id: 'demo::stackBehavesLikeItsModel',
    name: 'stackBehavesLikeItsModel',
    generators: ['sequence' => Gen::commands([], [
        Gen::map(Gen::intBetween(0, 99), static fn(int $v): Command => new Push($v)),
        Gen::constant(new Pop()),
    ])],
    parameterNames: ['sequence'],
    config: new PropertyConfig(runs: 200),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(
    static function (CommandSequence $sequence): void {
        StateMachine::check($sequence, static fn(): Stack => new Stack());
    },
));
```

### Exporting a counterexample

`CounterExample` exposes `seed`, `runsBeforeFailure`, `originalArguments`,
`shrunkArguments`, `shrinkSteps`, `shrinkTrials`, `skips` and the underlying
`failure`; `toArray()`/`toJson()` return a normalized machine-readable form,
and `toExamplesCode()` emits runnable PHP pinning the shrunk case as a
permanent example.

`ValueRenderer::render($value)` produces the single-line human form used inside
counterexample messages (strings quoted and escaped, arrays and objects
summarised, recursion and depth bounded). Adapters reuse it so their verbose
output reads exactly like the failure message.

### Debugging generators

`Gen::sample($arb, $count, $seed)` eagerly generates values;
`Gen::sampleShrinks($arb, $seed)` shows one value plus its first shrink
candidates — the fastest way to check a custom arbitrary shrinks as intended.

## Security

The engine performs no I/O, SQL, shell, or network operations itself; the only
filesystem access is the opt-in `FilesystemCorpus`, and only when you pass it
to the runner. Random values come from PHP's MT19937 engine seeded by the
reported seed — a PRNG, not a CSPRNG; never use generated values for
cryptographic purposes, and treat seeds as reproducibility handles, not
secrets.

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | a property that holds, one that is falsified, and tree-based shrinking | No |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, `flatMap` | No |
| `standalone_runner.php` | driving the engine directly: `PropertyDefinition`, `CallableTrialExecutor`, structured `PropertyResult` | No |
| `custom_listeners.php` | a console reporter and a telemetry collector as pure `PropertyListener`s | No |

## Development

No PHP/Composer on the host. Run commands in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
