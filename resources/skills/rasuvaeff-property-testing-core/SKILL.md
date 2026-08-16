---
name: rasuvaeff-property-testing-core
description: >-
  Write property-based tests in PHP with rasuvaeff/property-testing-core —
  Gen generators with integrated shrinking, Classify coverage gates,
  Assume discards, Gen::commands + StateMachine model-based testing,
  regression corpus, explicit Examples, deadline/budget guards. Use when
  writing or reviewing tests for code that has algebraic laws, round-trip
  invariants, state machines, or format validators, and when an AI coding
  assistant in a rasuvaeff/* package needs to know WHICH generator and
  WHICH phase mechanism to reach for (Examples vs corpus vs Assume vs
  Classify::cover vs StateMachine).
---

# rasuvaeff/property-testing-core

Framework-agnostic property-based testing engine for PHP 8.3+. Namespace
`Rasuvaeff\PropertyTesting\`. Installed via the `rasuvaeff/property-testing-testo`
adapter in Testo projects (drops a `#[Property]` attribute); the core is the
engine under that attribute.

The full syntax reference is `vendor/rasuvaeff/property-testing-core/llms.txt`
(resolved from the consumer project root after `llm/skills` syncs this file
into `.agents/skills/`). This skill is **decision-oriented**: which knob to
reach for, and the safety rules that break tests silently when ignored.

## Safety rules — verify on every change

1. **The engine is env-free.** `PropertyRunner` reads no env; adapters resolve
   `PROPERTY_RUNS` / `PROPERTY_SEED` / `PROPERTY_VERBOSE` / `PROPERTY_DB` into
   a `PropertyConfig` + `Corpus`. The ONE env helper in core is
   `FilesystemCorpus::fromEnv()` — call it only when YOU want the corpus on.
2. **Construct, don't filter.** Build dependent values with `Gen::flatMap()` /
   `Gen::draw()` or by composing ($max = $n + $slack), not by
   `Gen::filter($arb, fn => rare condition)`. `filter()` retries 100 times
   then throws `GenerationExhausted`; >90% discard also warns.
3. **`Assume::that(false)` discards, does not pass.** Discards are capped by
   `maxDiscards` (default `runs * 10`); exhausting it ends with `GaveUp`, not
   `Passed`. Use `Assume` only when construction is impossible — which is rare
   (see rule 2).
4. **Generators and Examples methods are `public static`.** Their only call
   site is reflection; Rector's `RemoveUnusedPrivateMethodRector` deletes
   `private` ones. `public` without `static` is allowed only when the body
   needs `$this`.
5. **`#[Property]` is on the method, `#[Test]` is on the class.** Generators
   method name: `<testMethod>Generators()`. Examples method name:
   `<testMethod>Examples()`. Both are discovered by name; no attribute needed.
6. **`#[Covers]` is required on every Testo test class.** `#[CoversNothing]`
   on integration tests that drive multiple classes (corpus, model-based).
7. **A listener exception aborts the run.** It is an infrastructure failure,
   not a property outcome. A listener can NEVER change a property's verdict.
8. **Corpus files are sensitive.** Values entries store the failing input
   verbatim as JSON. For personal/credential-shaped data, synthesise inside
   the body so only a seed is recorded; never put `PROPERTY_DB` in a
   world-readable path or publish it as a build artifact.

## When to write a property (and when not to)

| Code shape | Property? | Why |
|---|---|---|
| Algebraic law (commutativity, associativity, de Morgan, monad laws) | **Yes** | Equality of two expressions, large input space |
| Round-trip: `decode(encode(x)) == x`, `fromArray(toArray(x))` | **Yes** | Counterexamples shrink to the smallest breaking input |
| Idempotence: `normalize(normalize(x)) == normalize(x)` | **Yes** | Same |
| Regex accept/reject for a static format | **Yes** | Use `Gen::regex()` / `stringMatching()` (PCRE subset); generator builds valid AND invalid strings from the same alphabet |
| Serialization consistency (no `fromArray` exists) | **Yes** | `json_decode(toJson(x), true) ≡ toArray(x)`, deterministic, required fields present |
| Stateful / model-based (lifecycle, retries, state machine) | **Yes** | `Gen::commands()` + `StateMachine::check()` |
| Adapter over a third-party library | **Yes, but only "adapter preserves result"** | Don't re-test the library's laws; test that your wrapper doesn't lose or invent output |
| Glue / DI / config wiring | **No** | No meaningful input space; use `ConfigWiringTest` |
| Pure pass-through mapper | **No** | Trivial; one example test is enough |
| Random/UUID generation (no `fromString`) | **No round-trip** | Use determinism + format checks instead |

If you cannot state the property as an invariant that holds "for any X", do
not write a property — write a unit test.

## Canonical Testo usage (the `#[Property]` attribute)

```php
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MyClass::class)]
final class MyClassTest
{
    #[Property(runs: 100)]
    public function normalizeIsIdempotent(string $input): void
    {
        $once = MyClass::normalize($input);
        $twice = MyClass::normalize($once);

        Assert::same($twice, $once);
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function normalizeIsIdempotentGenerators(): array
    {
        return ['input' => Gen::stringAscii()];
    }
}
```

The Testo attribute takes: `runs` (≥1, default 100), `seed` (reproducible),
`maxShrinks` (0 = off), `maxDiscards`, `timeoutMs` (per-run deadline),
`budgetMs` (whole random phase), `generators` / `examples` (override the
default method names), `auto` (derive from the signature, below),
`edgeCases` (boundary bias, below).

**`auto: true` (-testo ≥0.6): the generators method can be omitted** when the
parameters are fully described by `@param` psalm types and native types — a
generator is derived per parameter from the property's own signature
(`Gen::forParameters` rules). A provider (explicit or conventional) becomes
PARTIAL overrides: name only what the type cannot express (a float range, a
`flatMap` pair), the rest derives. With auto, a provider key that is not a
parameter is an error. Strictly opt-in — bare `int`/`float` derive their full
native domain. PHPUnit adapter parity (≥0.5):
`$this->forAll()->auto()->check(/** @param int<0, 9> $n */ function (int $n): void {…})`.

`PropertyConfig` (engine level, for callers that build a `PropertyDefinition`
themselves) adds: `shrink` (`ShrinkMode::Off` reports the counterexample as
generated), `shrinkBudgetMs` (wall-clock budget of the descent, implies
`ShrinkMode::Bounded`, and trades determinism for a bounded descent), and
`phases` (`Phase::Examples`/`Corpus`/`Random`/`Shrink`; `[]` throws, a set
without `Shrink` IS `ShrinkMode::Off`, a set without `Random` reports zero
statistics and passes unless an enabled example or corpus replay failed first,
and `Phase::Corpus` gates replay only). Every element must be a `Phase`: a
stray value is rejected, not silently skipped. It also adds `derandomize`: an
unset seed becomes a pure function of the property id, so a locally found bug
reproduces in CI before any corpus entry exists, and a passing property keeps a
stable input distribution. An explicit seed still wins.

The distribution is also available as data, not only as the line an adapter
prints: `PropertyFinished::$distribution` carries a `DistributionReport` with a
`LabelShare` per label (count, share, and the `cover()` threshold it was
registered with), `discardPercent()`, `unmetRequirements()` and `toArray()`.
Label shares are over the successful checks and the discard share is over the
attempts — two denominators, named apart. `coverageAssessed` is false when the
run ended before the check loop completed, and a falsified run carries no
report at all.

Finally `path`: the accepted steps of an earlier descent, reported on
`CounterExample::$path` as `value:1/draw#1:0/value:3` and passed back with the
seed to follow them instead of searching for them again — one body execution per
step instead of one per candidate tried. It does not skip the random phase. A
path indexes into shrink candidates, so a generator edit orphans it: that is a
debugging aid, not a fixture, and the corpus is what survives a refactor. A
stale path is its own outcome (`PathFailed`) naming the step that broke, never a
silent fresh search, and any configuration that would make the path a no-op is
rejected at construction.

## Choosing a generator

| You want | Use | Notes |
|---|---|---|
| Any int | `Gen::int()` | Shrinks to 0 |
| Bounded int | `Gen::intBetween($min, $max)` | |
| Positive int (IDs, counts) | `Gen::intPositive()` | 1..PHP_INT_MAX |
| Float with edges (NaN, INF) | `Gen::float()` + combine with `Gen::floatSpecial()` | |
| ASCII string | `Gen::stringAscii()` | 0..100 chars |
| Unicode string | `Gen::string()` | |
| Bounded length | `Gen::stringOf($min, $max)` | |
| From alphabet | `Gen::stringFrom($alphabet, $min, $max)` | **Preferred for parser inputs** — the alphabet is the caller's responsibility: pick one that excludes the parser's delimiter chars (`,`, `=`, `&`, ...) so the value cannot break out of its field, but DOES include the chars you want the parser to handle |
| Bytes (HMAC, raw) | `Gen::bytes($min, $max)` | |
| Array | `Gen::arrayOf($element, $min, $max)` | |
| Unique array (ids, keys) | `Gen::uniqueArrayOf($element, $min, $max)` | |
| Map / dictionary | `Gen::dictOf($keyArb, $valueArb, $min, $max)` | |
| Fixed-shape object/VO | `Gen::record(['id' => Gen::uuid(), 'age' => Gen::intBetween(0, 120)])` | |
| One of N | `Gen::oneOf($a, $b, $c)` or `Gen::elements([$a, $b, $c])` | |
| Enum cases | `Gen::enum(MyEnum::class)` | |
| Weighted choice | `Gen::frequency([[7, $common], [3, $rare]])` | |
| Case that must LACK an operation | `Gen::swarm(Gen::oneOf(...))` / `Gen::swarm(Gen::commands(...))` | Swarm testing: each case uses a random non-empty subset of the variants; shrinking stays inside that subset |
| UUID v4 | `Gen::uuid()` | |
| Date/time | `Gen::datetime($min, $max)` | UTC `DateTimeImmutable` |
| URL / email / IP | `Gen::url()`, `Gen::email()`, `Gen::ipv4()`, `Gen::ipv6()` | Domain-shaped, shrink meaningfully; `ipv6()` is canonical RFC 5952 text and shrinks to `::` |
| JSON value | `Gen::json($maxDepth)` / `Gen::jsonString($maxDepth)` | |
| String matching a regex | `Gen::regex($pattern)` / `Gen::stringMatching($pattern)` | PCRE subset: `a-z . * + ? \| ()` |
| Recursive structure (tree) | `Gen::recursive($leaf, $wrap, $maxDepth)` | |
| Nullable | `Gen::nullable($inner)` | |
| Dependent on previous value | `Gen::flatMap($inner, fn($x) => $dependent)` | Integrated shrinking preserved |
| In-body draw (rare, multiple deps) | `Gen::draw($arb)` | ONLY inside a running property body; replay tape recorded |
| Constant (for commands) | `Gen::constant($value)` | |
| VO/config from its constructor | `Gen::forClass(Money::class, $overrides)` | Per parameter: override → `@param` psalm type (`int<0, 100>` beats `int`) → native type; unreadable types THROW naming the parameter, never a widened guess; `skipInvalid: true` discards constructor-rejected values (core ≥0.3) |
| Generators from any signature | `Gen::forParameters($reflectionFn, $overrides)` | The forClass rules for a method/closure's parameters, returned as `array<string, ArbitraryInterface>` in signature order; overrides may be PARTIAL — named params taken as given, rest derived (core ≥0.4) |

Numeric generators are **boundary-biased**: ~1 in 5 draws returns an in-range
edge value. Sized generators never go below their minimum. If the body
discards edges (`0`, range ends) through `Assume::that()`, that's one run in
five producing a value the property throws away — the discard budget pays for
it. Turn the bias off per property: `#[Property(edgeCases: EdgeCases::None)]`
(-testo; default `EdgeCases::Mixin`) / `->edgeCases(EdgeCases::None)`
(-phpunit). The edge roll still happens under `None`, so the sequence stays
aligned on the same seed — only which values appear changes, not everything
after the first draw.

## Choosing the phase mechanism

| Symptom | Mechanism |
|---|---|
| Known regression must fail deterministically, before random phase | `<testMethod>Examples(): iterable` — yields positional arg tuples; auto-discovered by name |
| Failing input found in CI must persist across runs | `PROPERTY_DB` env on the runner + `FilesystemCorpus::fromEnv()` — adapter wires this from env |
| Body inputs are sometimes invalid | `Gen::flatMap` / `Gen::draw` to **construct** valid ones; `Assume::that($valid)` only when construction is impossible |
| Need "every branch hit at least N%" | `Classify::cover($condition, $label, $minPercent)` — fails with `CoverageFailed` even if every run passed |
| Just want distribution in the report | `Classify::when($condition, $label)` / `Classify::label($label)` — tags only, no gate |
| Body has wall-clock risk (catastrophic regex, deep recursion) | `#[Property(timeoutMs: 1000)]` — single run over deadline = `DeadlineExceeded` |
| Whole random phase has SLA | `#[Property(budgetMs: 5000)]` — `TimeBudgetExceeded` |

### `<method>Examples()` — the underused one

Examples are **fixed argument tuples run before the random phase**, no
shrinking. Auto-discovered by method name. Pin the shapes mutation proofs
already found — random search hits them stochastically at best.

```php
#[Property(runs: 150)]
public function andIsCommutative(Specification $a, Specification $b): void
{
    // body unchanged
}

/** @return iterable<array{0: Specification, 1: Specification}> */
public static function andIsCommutativeExamples(): iterable
{
    // Examples methods are `public static` (their only call site is reflection),
    // so fixtures must be built statically too — `$this->active` would not compile.
    // The pattern is a private static catalog (see specification/tests/Integration/
    // CompositionLawsPropertyTest.php for the live version).
    $active = ComparisonSpecification::equal(column: 'status', value: 'active');
    $inactive = ComparisonSpecification::equal(column: 'status', value: 'inactive');
    $notActive = NotSpecification::create(specification: $active);

    yield 'identity (same leaf twice)' => [$active, $active];
    yield 'disjoint (empty intersection)' => [$active, $inactive];
    yield 'NOT-wrapped left' => [$notActive, $inactive];
}
```

Coverage gained per example = one assertion. Do NOT put `path` (when it ships
in 0.2) in `tests/fixtures/` — that's debug-only; the corpus is the stable
regression mechanism.

## Stateful / model-based testing

For state machines, retries, lifecycles. Four files in `tests/Support/`:

1. **Model** — immutable value threaded through the sequence.
   ```php
   final readonly class DeliveryState {
       public function __construct(
           public Status $status,
           public int $attempts,
           public ?string $lastError,
       ) {}
   }
   ```
2. **Command** — implements `Rasuvaeff\PropertyTesting\StateMachine\Command`:
   `preCondition($model)`, `nextState($model)` (pure, returns NEW model),
   `run($model, $system)` (mutates SUT), `postCondition($model, $result)`.
3. **Harness** (optional) — holds the SUT instance; reassigned by each `run()`.
4. **Test** — drives it via `Gen::commands()` + `StateMachine::check()`:
   ```php
   #[Property(runs: 100)]
   public function lifecycleTracksModel(CommandSequence $sequence): void
   {
       $harness = null;
       StateMachine::check($sequence, static function () use (&$harness) {
           // The factory receives a fresh SUT per sequence replay; the harness
           // stores it so Command::run() can reassign it as the SUT evolves.
           return $harness = new Harness(MyClass::create());
       });
       \assert($harness instanceof Harness);

       // Coverage gate: every terminal state reached across the batch
       Classify::cover($harness->status === Status::Done, 'done', 5.0);
       Classify::cover($harness->status === Status::Failed, 'failed', 5.0);
   }

   public static function lifecycleTracksModelGenerators(): array
   {
       return [
           'sequence' => Gen::commands(
               new Model(initialState),
               [Gen::constant(new MyCommand('a')), Gen::constant(new MyCommand('b'))],
               minLength: 0, maxLength: 30,
           ),
       ];
   }
   ```

`StateMachine::check()` throws `PostconditionViolation` naming the failing
step. Shrinking drops command blocks then simplifies each command's params;
replay skips commands whose precondition a dropped step invalidated.

**Test your own state machine, not the library's.** If the SUT is a wrapper
around `symfony/workflow` or similar, the property is "the wrapper's marking
tracks the model" — not "symfony/workflow obeys Petri-net rules".

## Deterministic time

`PropertyRunner::__construct(?Clock $clock = null)`. The default is
`MonotonicClock`; inject a fake `Clock` for deterministic deadline/budget
tests. **Adapters** resolve the clock from `ClockInterface` (PSR-20) when
needed — the engine itself never reads `time()`.

`#[Property(timeoutMs: 1000)]` and `budgetMs` use this clock. A fake clock in
tests makes a wall-clock deadline deterministic; the production clock makes it
a true SLA.

## Regression corpus (`PROPERTY_DB`)

Enabled by adapter env: `PROPERTY_DB=/path/to/dir`. Behaviour:

- On **falsification**: counterexample is minimised and stored.
- On **next run**: stored entries replay BEFORE the random phase. A values
  entry (data-representable) runs once; a seed entry (objects/closures/draws)
  re-runs the whole random phase.
- On **green replay**: entry is auto-pruned — the regression is fixed.
- An **attribute `seed`** disables replay for that property; an **env
  `PROPERTY_SEED`** does not. Asymmetry is pinned by adapter tests.

CI pipeline for property packages (the recipe consumer packages follow, not
the core engine — core has no `#[Property]` tests of its own):

1. `actions/cache/restore` for `build/property-db` with a unique save key
   `property-db-${{ github.run_id }}-${{ github.run_attempt }}` AND a stable
   prefix `restore-keys: property-db-` — without the prefix, every run misses
   the corpus from earlier runs (the unique key alone never collides).
2. `env: PROPERTY_DB: ${{ github.workspace }}/build/property-db` on the
   coverage step (`composer test:coverage:ci`).
3. `actions/cache/save` with `if: ${{ !cancelled() }}` and the same unique
   key. Separate restore/save — the combined `actions/cache` declares
   `post-if: success()` and would skip the save on red runs, where new
   counterexamples appear.

The exact YAML lives in the consumer package's `.github/workflows/build.yml`
— see root `AGENTS.md` (monorepo) for the canonical block.

**Sensitivity of the cache.** A values entry stores the failing input as plain
JSON. The CI cache therefore inherits whatever the generators produced. This
is fine for synthetic test inputs (the rasuvaeff/* default — properties run
on generated ASCII, not on production-shaped data); for suites that feed the
property real or credential-shaped inputs, either synthesise inside the body
so only a seed is recorded, or skip step 3 (no cache save) and accept that
regressions live in one CI run only.

## Adapters

- **`rasuvaeff/property-testing-testo`** — `#[Property]` attribute on a Testo
  test method. Drop-in replacement for the frozen `rasuvaeff/property-testing`
  2.x (same FQCN). This is what rasuvaeff/* packages use.
- **`rasuvaeff/property-testing-phpunit`** — fluent trait for PHPUnit.
  `$this->forAll([...])->runs(100)->check(fn(...))`. Same engine under.

Both adapters resolve env (`PROPERTY_RUNS`, `PROPERTY_SEED`,
`PROPERTY_VERBOSE`, `PROPERTY_DB`) into a `PropertyConfig` + `Corpus` and feed
the engine. The engine's contract (returns `PropertyResult`, never throws for
an outcome) is the same regardless of adapter.

## Domain generators

- **`rasuvaeff/property-testing-names`** — person names for `en` and `ru`:
  `Names::first()`, `last()`, `middle()` (patronymic), `full()` and
  `person()` (a `PersonName` value object with `full()`/`initialLast()`/
  `lastInitials()`, gender-consistent across the parts). Plain
  `ArbitraryInterface` values — usable from any adapter or a custom harness.
  Reach for it instead of `Gen::string()` when the property is about people:
  forms, profiles, auth, validators, reports. Data sets are versioned by
  release policy — changing an entry is a release, not a silent edit.

## What to read next

- `vendor/rasuvaeff/property-testing-core/llms.txt` — full syntax reference
  (every generator, every result class, every event).
- `vendor/rasuvaeff/property-testing-core/README.md` — narrative documentation
  with rendered examples.
- `vendor/rasuvaeff/property-testing-core/docs/src/cookbook/` — real bugs
  reconstructed and pinned to seeds; `make docs-cookbook` (in the package
  checkout, not the consumer) re-runs them and diffs stdout against the page.
