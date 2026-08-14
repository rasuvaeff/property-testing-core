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

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model. If the project uses [`llm/skills`](https://github.com/roxblnfk/skills), the [`rasuvaeff-property-testing-core` skill](resources/skills/rasuvaeff-property-testing-core/SKILL.md) is auto-synced to `.agents/skills/` on `composer require` — it is decision-oriented (which generator, which phase mechanism) and points back here for the full syntax. To mirror the skill into `.claude/skills/` or `.cursor/skills/` (a single set of files, OS-level junctions/symlinks), add a `skills.json` at the project root: `{"target": ".agents/skills", "aliases": [".claude/skills", ".cursor/skills"]}` — or run `composer skills:init` for the interactive wizard.

## Part of the property-testing family

| Package | Use it when |
|---|---|
| **`rasuvaeff/property-testing-core`** (this package) | You drive the engine yourself: a custom harness, CI guard, CLI checker, or another framework adapter |
| [`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo) | You test with [Testo](https://github.com/php-testo/testo) — drop-in replacement for the frozen `rasuvaeff/property-testing` with the same `#[Property]` attribute |
| [`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit) | You test with PHPUnit — a `PropertyTesting` trait with a fluent `forAll()->check()` API |
| [`rasuvaeff/property-testing-names`](https://github.com/rasuvaeff/property-testing-names) | Your inputs are people: forms, profiles, auth, validators, reports — `Names::first()`/`last()`/`middle()` draw single parts independently, `full()`/`person()` keep every part consistent with one gender; bundled `en` and `ru` datasets, shrinking to the shortest entry |

> **Note:** this package `conflict`s with the frozen `rasuvaeff/property-testing`
> (2.x) — both ship classes in the `Rasuvaeff\PropertyTesting` namespace, so
> Composer refuses to install them together. Migrating from 2.x? Swap the dev
> dependency for the adapter matching your framework; your imports stay as they
> are. [MIGRATION.md](MIGRATION.md) is the full guide: two Composer commands
> and no PHP edits for Testo projects, plus the custom-harness and PHPUnit
> paths.

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
| `PathFailed` | The run falsified the property but could not follow the pinned shrink `path` | `PathViolationException` |

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
| `Gen::ipv6()` | IPv6 addresses in the canonical RFC 5952 text form (lowercase, no leading zeros, longest zero run compressed to `::`) | each group toward `0`, ending at `::` |
| `Gen::email()` | `local@label.tld` addresses | toward the shortest local/label and first TLD |
| `Gen::url()` | `http(s)://host.tld[/path]` URLs | toward `http://a.com` |
| `Gen::json($maxDepth)` | a JSON-encodable value (null/bool/int/float/string/list/object) | within the generated structure |
| `Gen::jsonString($maxDepth)` | the `json_encode` text of `Gen::json()` | through the value's tree |
| `Gen::regex($pattern)` / `Gen::stringMatching($pattern)` | strings matching a regex subset (compiled to combinators) | shorter/simpler matches (via the compiled trees) |
| `Gen::commands($initialModel, $commandGenerators, $min, $max)` | `CommandSequenceArbitrary`, valid command sequences for stateful testing | drops command blocks, then simplifies each command |
| `Gen::swarm($choiceGenerator)` | `SwarmArbitrary`, swarm testing: each case may use only a non-empty subset of the wrapped choice generator's variants (`oneOf`, `elements`, `frequency`, `commands`) | inside the subset the case came from — never widening back to the full alphabet |

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

### Swarm testing (`Gen::swarm`)

Uniform draws from the full alphabet make every case look like every other one:
a hundred draws from `oneOf('push', 'pop', 'flush')` almost surely contain all
three, so the bugs that need an operation to be *absent* are practically
unreachable. `Gen::swarm()` restricts a choice generator to a random, non-empty
subset of its variants for each generated case — Groce et al., *Swarm Testing*
(ISSTA 2012):

```php
Gen::swarm(Gen::oneOf('push', 'pop', 'flush'));   // one case sees, say, only 'pop' and 'flush'
Gen::swarm(Gen::commands($model, $commands));   // one sequence uses a subset of the commands
```

It accepts the choice generators — `Gen::oneOf()`, `Gen::elements()`,
`Gen::frequency()`, `Gen::commands()` — and any `Swarmable` of your own;
anything else throws. Surviving `frequency` branches keep their weights, so a
branch that was twice as likely as its neighbour still is.

Shrinking stays inside the subset the case came from: a counterexample found
without `flush` never shrinks into one containing it, which is what keeps such a
finding reproducible at all. Two consequences worth knowing:

- the subset is drawn once per generated value, so wrap the generator whose
  scope you mean. `swarm(commands(...))` restricts a whole sequence;
  `arrayOf(swarm(oneOf(...)))` redraws per element, which is noise rather than
  swarm testing;
- the counterexample reports the value, not the subset it was drawn from. Seed
  replay reproduces both.

A swarm over `Gen::commands()` with a non-zero `$minLength` can leave a case
with no applicable command; that throws `GenerationExhausted`, exactly as an
unrestricted generator starved by its model does.

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

The same contents are available as data, without parsing the printed line:
`PropertyFinished::$distribution` carries a `DistributionReport` — every label
as a `LabelShare` (count, share, and the `cover()` threshold it was registered
with), `discardPercent()`, `unmetRequirements()` and `toArray()` for telemetry.
Two denominators, named apart: label shares are over the successful checks (a
discard never dilutes them), the discard share is over the attempts. A label
that was required and never occurred is reported with a count of zero rather
than omitted, and `coverageAssessed` is false when the run ended before the
check loop completed, so a report never implies a coverage verdict the run
never reached. A
falsified run carries no distribution — it stops at the counterexample.

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
| `shrink` | `null` | `ShrinkMode::Off` reports the counterexample as generated; null resolves to `Full` |
| `shrinkBudgetMs` | `null` | Wall-clock budget for the shrink descent; implies `ShrinkMode::Bounded` |
| `phases` | `null` | Stages to perform (`Phase::Examples`/`Corpus`/`Random`/`Shrink`); null runs all of them |
| `derandomize` | `false` | Derive an unset seed from the property id instead of drawing one |
| `path` | `null` | Replay a recorded shrink descent instead of searching for it; needs an explicit `seed` |

### Derandomized runs

An unset seed is drawn at random, so a property that fails for one input in
fifty fails in CI only sometimes. The corpus fixes that — but only *after* the
first failure is recorded. `derandomize: true` covers the other side of that
moment:

```php
new PropertyConfig(derandomize: true);   // the same id always selects the same inputs
```

The seed becomes a pure function of the property's id, so a bug found locally
reproduces in CI without waiting for a corpus entry to exist, and a passing
property keeps a stable input distribution — which is what makes distribution
numbers comparable between commits. An explicit `seed` always wins over the
flag. The seed→values mapping is untouched: this changes which seed a run
picks, never what that seed produces.

### Replaying a shrink path

A descent spends most of its work on candidates it rejects: the engine's own
smallest integer property accepts nine steps after trying thirty-nine. The
counterexample carries the accepted steps, so a rerun can follow them instead of
searching for them again:

```php
$counterExample->path;                                  // 'value:1/value:1/value:3'

new PropertyConfig(seed: 42, path: 'value:1/value:1/value:3');
```

Each step names a node — a parameter, or an in-body draw under its `draw#N`
pseudo-name — and which candidate of that node's shrink enumeration was
accepted. The replay runs the body once per step rather than once per candidate.
It does not skip the random phase: reaching the failing run means executing the
runs before it, because a body can consume randomness through `Gen::draw()` and
discards depend on the body.

A path is a debugging aid, not a fixture. Its steps are indices into shrink
candidates, so editing a generator orphans it — that is what the regression
corpus is for. A path that no longer applies is reported as its own outcome
(`PathFailed`) naming the step that broke, never absorbed into a fresh search:
searching quietly would return a counterexample that looks exactly like a
successful replay. Configurations that would leave the path a no-op — no
explicit seed, a phase set without `Random` or `Shrink`, shrinking switched off, a
wall-clock shrink budget, a `maxShrinks` below the path's length, a malformed
path — are rejected at construction.

### Shrink modes and phases

`maxShrinks` caps the *accepted* steps, but the cost of a descent is in the
candidates it *tries* — on large collections that is easily more expensive than
the random phase that found the failure. Two knobs bound it from the other side:

```php
new PropertyConfig(shrink: ShrinkMode::Off);   // report the counterexample as generated
new PropertyConfig(shrinkBudgetMs: 500);       // descend for at most 500 ms, keep the best so far
```

A shrink budget is the one knob in this package that costs determinism: how far
the descent gets depends on how long the body takes, so the same seed can
minimise differently on a fast and a slow machine. It answers "the descent
hung", not "reproduce this exactly" — for the latter, pin the seed or rely on
the corpus.

The stages of a run are a set, not a fixed sequence:

```php
new PropertyConfig(phases: [Phase::Examples, Phase::Corpus]);  // fast pull-request gate
new PropertyConfig();                                          // every phase (the default)
```

| Rule | Behaviour |
|---|---|
| Empty phase set | `InvalidArgumentException` — a run with no phases has nothing to report |
| Phase set holding anything but a `Phase` | `InvalidArgumentException` — an unrecognised stage would simply not run, and the property would report green having checked nothing |
| Phase set without `Shrink` | Exactly `ShrinkMode::Off`; the stricter of the two knobs always wins |
| `Phase::Corpus` | Gates corpus **replay** only, and composes with `replayRegressions` as an AND; a fresh falsification is still recorded |
| Phase set without `Random` | Nothing is generated: honest zero statistics (`attempts: 0`, `checks: 0`) and coverage requirements dropped rather than assessed against an empty denominator. The result is `Passed` once the enabled earlier phases pass — a pinned example or a corpus entry that fails still reports its own failure |

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

A values entry stores the failing input **verbatim**, so a corpus directory is
as sensitive as the data your generators produce. That is normally
uninteresting — random ints and strings — but a generator seeded from a
production fixture, or one that composes realistic personal or
credential-shaped data, writes exactly that to disk in plain JSON. Keep the
directory out of world-readable locations and out of published build
artifacts, and prefer synthesising such values inside the property body over
generating them, so the counterexample records the seed rather than the data.

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

When you do enable the corpus, remember that it persists failing inputs as
plain JSON: see [Regression corpus](#regression-corpus) for what that means
for generators that can produce sensitive-looking data.

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
