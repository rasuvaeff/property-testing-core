# Migrating from `rasuvaeff/property-testing` 2.x

`rasuvaeff/property-testing` 2.x was a single package: a property-based testing
engine welded to a Testo plugin. It is frozen at `2.8.1` and marked abandoned.
The same code now ships as three packages — this one plus one adapter per test
framework — so a project pulls only the framework it actually uses.

The split was designed as a **drop-in for the public API**: no public
fully-qualified class name changed, no method convention changed, no
environment variable changed, and the regression corpus on disk is read back
byte-for-byte. For a Testo project the whole migration is two Composer commands
and no PHP edits.

That guarantee covers what 2.x documented as public. Classes marked
`@internal` in 2.x are the one exception — some moved, and code that reached
for them has imports to update; see [Custom harness](#custom-harness) for the
exact mapping.

## Pick your path

| You were using | Install | PHP code changes |
|---|---|---|
| `#[Property]` under Testo | `rasuvaeff/property-testing-testo` | **none** |
| The engine directly (custom harness, CLI script, CI guard) | `rasuvaeff/property-testing-core` | none for public API; update imports of the `@internal` classes listed under [Custom harness](#custom-harness) |
| Nothing yet, and you test with PHPUnit | `rasuvaeff/property-testing-phpunit` | new integration, see [PHPUnit](#phpunit) |

## Testo

```bash
composer remove --dev rasuvaeff/property-testing
composer require --dev "rasuvaeff/property-testing-testo:^0.1" -W
```

That is the whole migration. Every import, attribute, generator method and
assertion stays as it is:

```php
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;

#[Property(runs: 300)]
public function roundTrip(string $value): void
{
    Assert::same(decode(encode($value)), $value);
}

/** @return array<string, ArbitraryInterface> */
public static function roundTripGenerators(): array
{
    return ['value' => Gen::string()];
}
```

Two things about that command are not optional:

- **`composer remove` must come first.** This package declares
  `conflict: {"rasuvaeff/property-testing": "*"}`, because both packages ship
  classes in the `Rasuvaeff\PropertyTesting` namespace. A mixed install is
  deliberately unsolvable rather than silently duplicated on the autoloader.
  Requiring the adapter while 2.x is still in `composer.json` fails with
  `Your requirements could not be resolved`.
- **`-W` (`--with-all-dependencies`).** The Testo adapter requires
  `testo/testo ^0.10.39 || ^1.0`. If your lock file pins an older `testo/testo`,
  Composer refuses the install without permission to raise it; `-W` lets it
  bump `testo/testo` and its satellites within their allowed ranges.

### What is guaranteed to keep working

| | |
|---|---|
| Class names | Every public FQCN — `Gen`, `ArbitraryInterface`, `Shrinkable`, `Assume`, `Classify`, `Property`, `CounterExample`, the `StateMachine` namespace, and every public exception |
| Conventions | `<method>Generators()` and `<method>Examples()`, resolved by reflection exactly as before |
| Environment | `PROPERTY_RUNS`, `PROPERTY_SEED`, `PROPERTY_DB`, `PROPERTY_VERBOSE` — same semantics, same precedence, same validation errors |
| Messages | The counterexample message format is unchanged and pinned by golden tests |
| Corpus | Same `FORMAT_VERSION`, same JSON, same file layout. A corpus written by 2.8 replays under the adapter — your CI regression corpora keep their value |
| Seeds | `SEQUENCE_EPOCH` was not bumped: a given seed still produces the same inputs |
| Coverage | Per-run Testo `TestResult` attributes, including codecov's `CoverageResult`, are still merged onto the aggregate result — property tests stay visible to per-test coverage and to Infection |

### CI

Nothing changes. The `PROPERTY_DB` regression-corpus cache recipe — restore
before the test step, `PROPERTY_DB` on the step itself, save after it with
`if: ${{ !cancelled() }}` — works identically, because the corpus format and
the variable are the same.

## Custom harness

If you were driving the engine yourself, install the engine alone:

```bash
composer require --dev rasuvaeff/property-testing-core
```

No test framework comes with it. Build a `PropertyDefinition`, hand it to
`PropertyRunner` through a `TrialExecutor`, and inspect the structured
`PropertyResult` — see [README](README.md) and
[`examples/standalone_runner.php`](examples/standalone_runner.php).

Under 2.x this was only half-possible: the runner did not exist as a separate
object until the split, so a custom harness had to reach into
`Rasuvaeff\PropertyTesting\Internal`. Those classes moved, and the ones a
harness actually needs became public in the move:

| 2.x (`@internal`) | Core |
|---|---|
| `Internal\CorpusStorage` | `Runner\FilesystemCorpus` (`@api`); `fromEnv()` remains as an opt-in helper — the runner itself never reads the environment |
| `Internal\CorpusEntry` | `Runner\CorpusEntry` (`@api`) |
| `Internal\Clock`, `Internal\MonotonicClock` | `Runner\Clock`, `Runner\MonotonicClock` (`@api`) — the clock is a constructor argument of `PropertyRunner`, so deadline and budget behaviour is testable deterministically |
| `Internal\ValueRenderer` | `ValueRenderer` at the package root (`@api`) — adapters need it for verbose output and messages |
| `Internal\PropertyInterceptor`, `Internal\TestoTrialExecutor`, `Internal\VerboseListener` | Not in core. They are Testo-specific and live in `rasuvaeff/property-testing-testo` |

`Internal\Boundary`, `Internal\DrawContext`, `Internal\RegexCompiler` and
`Internal\ValueCodec` keep both their names and their `@internal` status.

Reading environment variables is the adapter's job, not the engine's: core
never touches `getenv()`. If your harness wants the `PROPERTY_*` contract, read
the variables yourself and pass the resulting `PropertyConfig` and `Corpus` in
— `FilesystemCorpus::fromEnv()` covers the `PROPERTY_DB` half.

## PHPUnit

There was no PHPUnit integration in 2.x — installing it into a PHPUnit project
dragged in `testo/testo` transitively, which is exactly the coupling the split
removes.

```bash
composer require --dev rasuvaeff/property-testing-phpunit
```

The API is a trait with a fluent chain rather than an attribute, because
PHPUnit's public extension API observes test execution but offers no supported
way to intercept and re-run a test method:

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

Generators, shrinking, the corpus and the `PROPERTY_*` variables behave exactly
as under Testo — the two adapters share this engine and the parity is pinned by
tests. Failures arrive as a single `AssertionFailedError` carrying the engine's
message, with the engine exception as `previous`. See the
[adapter README](https://github.com/rasuvaeff/property-testing-phpunit) for the
full fluent chain.

## Both adapters in one project

Supported. They share this engine, declare no global registration, and keep
their framework-specific classes in separate sub-namespaces
(`Rasuvaeff\PropertyTesting\Testo`, `Rasuvaeff\PropertyTesting\PhpUnit`).

## Versioning

The family starts at `0.1`. Adapters pin the engine with `^0.1`; the three
version numbers are not kept in sync with each other. `1.0` follows once the
public boundary has been exercised by consumers outside the monorepo — until
then a minor may adjust it.

## Staying on 2.x

`rasuvaeff/property-testing` still installs and still works. It receives
security fixes only: no new features, no 3.0. Composer will report it as
abandoned and suggest `rasuvaeff/property-testing-testo`.
