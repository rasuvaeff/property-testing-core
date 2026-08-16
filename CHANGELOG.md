# Changelog

## Unreleased

- Fixed `CounterExample::toExamplesCode()` emitting non-runnable example code
  for a counterexample with in-body `Gen::draw()` values: `draw#N`
  pseudo-arguments are not parameters, so rendered positionally they produced
  an example of the wrong arity. It now throws a `LogicException` naming the
  draw and pointing at seed replay, following the non-exportable-object
  precedent (#55).
- Fixed `Gen::regex()` silently compiling escapes outside the supported subset
  (`\h`, `\v`, `\R`, `\Q…\E`, `\0`, `\x`, ...) to literal characters,
  generating strings that do not match the pattern. Unknown alphanumeric
  escapes now throw naming the escape (escaped punctuation stays literal, as
  before); `[\b]` inside a character class generates a backspace; lazy and
  possessive quantifiers are rejected with an honest message instead of
  `"?" has nothing to repeat` (#56).
- Fixed corpus seed-entry replay pruning a live regression when the configured
  runs count was lowered below the recorded failing attempt: seed entries now
  store `runsBeforeFailure` and the replay extends its run count up to the
  recorded attempt. Documents written before the field keep the previous
  behaviour; the on-disk format version is unchanged (#57).

## 0.4.0 — 2026-08-16

- Added `Gen::forParameters(\ReflectionFunctionAbstract $function, array
  $overrides = [], int $maxDepth = 3)`: generators for a function's parameters,
  by name in signature order — the `forClass()` resolution rules (override →
  `@param` psalm type → native type, same supported subset, refusals that name
  the function and the parameter) applied to any method or closure instead of a
  constructor. Overrides may be partial: the parameters they name are taken as
  given, the rest are derived from the signature. This is the engine half of
  the adapters' upcoming `auto` mode, where a fully-typed property needs no
  provider method at all.

## 0.3.1 — 2026-08-15

- `Gen::forClass()` names the chain that reached a class it cannot instantiate:
  `Cannot generate …\Duration: it is not instantiable (reached through
  …\BreakerConfig -> …\Ratio -> …\Duration)`. A value object with a private
  constructor and named factories is usually several levels below the class you
  asked for, and naming only that class sent the reader hunting for which
  parameter pulled it in — the cycle and depth refusals already named their
  chains. Found by using `forClass()` on a real package rather than on a
  fixture.

## 0.3.0 — 2026-08-15

- Added `EdgeCases`, the explicit switch for the numeric boundary bias:
  `PropertyConfig(edgeCases: EdgeCases::None)` generates uniformly instead of
  returning an in-range edge value roughly one draw in five. The bias is right
  until the edges are what a property cannot use — a body discarding `0`, a
  range end that violates a precondition — where it costs one run in five and
  the discard budget pays. The roll that chooses an edge is still consumed
  under `None`, so the two modes stay aligned on the same seed: switching
  changes which values are edges rather than every draw after the first, which
  is what keeps a suite comparable to itself. jqwik's `FIRST` is deliberately
  absent — explicit examples and the corpus already run before the random
  phase, with values chosen rather than guessed.
- Added `Gen::forClass()`: a generator built from what a constructor already
  declares. Per parameter, in order — an override, the `@param` psalm type, the
  native type. The docblock wins because it says more: `int` and `int<0, 100>`
  are the same native type and a very different value space, and reading the
  narrower one is what keeps a validating constructor from rejecting four
  generated values in five. The supported subset is bounded and documented
  (`int<a, b>`, `positive-int` and friends, `non-empty-string`, `list<T>`,
  `array<K, V>`, literal unions such as `'draft'|'published'`, `?T`, unions,
  enums, `DateTimeImmutable`, and other classes followed to `maxDepth` with
  cycles refused by name); anything outside it throws naming the parameter
  rather than widening to a guess, because a guessed generator fails later, in
  somebody else's test. A constructor that rejects a value throws by default —
  that is information — and `skipInvalid: true` discards and redraws through
  the same `Gen::filter()` machinery, discarding exceptions only, never
  `Error`s.
- Added `RedisCorpus`: the regression corpus in Redis instead of a directory, so
  a falsification found on a laptop replays in CI and one found in CI replays on
  the next laptop. The stored document is byte-identical to the filesystem
  backend's, so moving a corpus between the two is a copy rather than a
  migration — asserted, not assumed. It takes a two-method client seam
  (`Runner\Redis\CorpusClient`), shipped over `ext-redis`
  (`PhpRedisCorpusClient`) and predis (`PredisCorpusClient`); a consumer with a
  pool or a namespaced wrapper can supply its own. Writes are optimistic — read,
  compare-and-set through one Lua script, retry — and give up quietly after
  `RedisCorpus::MAX_ATTEMPTS`, because a corpus is memory rather than a ledger
  and failing a passing run to record a counterexample is the wrong trade.
- Documented the corpus as a CI artifact: the three GitHub Actions steps that
  carry a corpus across runs, and why each exists. The combined cache action
  declares `post-if: success()`, so it never saves on the red job that just
  recorded the counterexample; `run_attempt` has to be in the key or a re-run
  writes nothing; `restore-keys` is what actually carries the corpus forward.
  Plus the two patterns beyond a cache — a committed fixture, and a store
  shared between CI and developers — and what must not be committed or shared.

## 0.2.1 — 2026-08-14

- The falsification message now ends with the shrink path
  (`Path:     value:1/value:3`), the same value `CounterExample::$path` has
  carried since 0.2.0. Replaying a descent is now a copy of one line plus the
  seed printed above it, instead of reading the path out of the counterexample
  programmatically. A run that shrank nothing has no path and the line is
  omitted rather than printed empty. Adapters that pin the message verbatim
  (the Testo adapter's golden) see one added line.

## 0.2.0 — 2026-08-14

- Added `Gen::swarm()` — swarm testing over a choice generator. Each generated
  case may use only a random, non-empty subset of the wrapped generator's
  variants, so the cases that never perform an operation at all stop being
  astronomically rare: over 200 eight-command sequences, 4 avoided one command
  by chance against 77 when swarmed. It accepts `Gen::oneOf()`,
  `Gen::elements()`, `Gen::frequency()` and `Gen::commands()` through the new
  `Swarmable` interface, which a custom choice generator can implement too;
  surviving `frequency` branches keep their weights. Shrinking stays inside the
  subset a case was drawn from and never widens back to the full alphabet —
  without that, a counterexample found without some operation would shrink into
  one containing it, and the finding would stop reproducing. The subset is
  drawn once per generated value; swarming `Gen::commands()` with a non-zero
  `minLength` can starve the sequence and throw `GenerationExhausted`, exactly
  as an unrestricted generator starved by its model does.

- Added the machine-readable distribution report. `PropertyFinished` now carries
  a `DistributionReport`: every `Classify` label as a `LabelShare` (count, share
  and the `cover()` threshold it was registered with), the discard tally, and
  `toArray()` for telemetry — the contents of the line an adapter prints, before
  it becomes a line, so a CI job or a test can read it without parsing text.
  Label shares are over the successful checks and the discard share is over the
  attempts, named apart so the two cannot be confused; a label that was required
  and never occurred is reported with a count of zero rather than omitted; and
  `coverageAssessed` says whether the run reached the coverage assessment at
  all, so a report never implies a verdict that a run which gave up or exhausted
  its budget never reached. `RunStatistics` carries the `cover()` requirements
  alongside the counts they are compared against, at every exit that builds one.
  A falsified run carries no report — it stops at the counterexample. The report
  is a projection of counters the phase already accumulated, computed once when
  the run finishes; printing stays with the adapters.
- Added shrink-path replay. A falsified property now reports the descent that
  produced its counterexample on `CounterExample::$path` (and in `toArray()` /
  `toJson()`) as `name:index` steps, where a step names a parameter — or an
  in-body draw under its `draw#N` pseudo-name — and the shrink candidate that
  was accepted. Passing it back through `PropertyConfig::$path`, together with
  the seed it came from, follows those steps instead of searching for them
  again: one body execution per accepted step instead of one per candidate
  tried. It does not skip the random phase; reaching the failing run still
  means executing the runs before it. A path is a debugging aid rather than a
  fixture — its steps index into shrink candidates, so editing a generator
  orphans it, which is what the regression corpus is for. A path that no longer
  applies is reported as the new `PathFailed` result carrying the new
  `PathViolationException`, naming the step that broke, and is never absorbed
  into a fresh search. Configurations that would leave the path a silent no-op
  (no explicit seed, a phase set without `Random` or `Shrink`, shrinking
  switched off, a wall-clock shrink budget, a `maxShrinks` below the path's own
  length, a malformed path) are rejected at construction. The failure message is unchanged: the path travels on the
  counterexample, and printing it is the adapters' half of the 0.2 line.
- Added `PropertyConfig::$derandomize`: with it set, a run without an explicit
  seed derives one from the property's id instead of drawing it at random, so
  the same property on the same code always selects the same inputs. The
  regression corpus only helps once a failure has been recorded; this covers
  the other side of that moment — a bug found locally reproduces in CI before
  any corpus entry exists, and a property that passes (and therefore records
  nothing) keeps a stable input distribution, which is what makes distribution
  numbers comparable between commits. An explicit seed still wins, and the
  mapping from a seed to the values it produces is untouched.
- Added shrink modes and switchable run phases to `PropertyConfig`.
  `ShrinkMode::Off` reports a counterexample exactly as generated (no trial, no
  shrink event); `shrinkBudgetMs` bounds the descent by wall clock and keeps
  the best candidate it reached, which `maxShrinks` could not do because it
  counts accepted steps rather than the tried candidates a descent actually
  spends its time on. A shrink budget deliberately trades determinism for a
  bounded descent — the corpus and an explicit seed remain the reproducible
  paths. A budget too large to convert into its own nanosecond deadline is
  rejected: an overflowed deadline is not a deadline.
- Added `Phase` and `PropertyConfig::$phases`: the stages of a run (examples,
  corpus replay, random, shrink) are now a set instead of a fixed sequence, so
  a pull request can replay only the corpus and the pinned examples, and a
  property can be measured with corpus replay off without deleting the corpus
  to do it. An empty set throws; a set without `Phase::Shrink` is exactly
  `ShrinkMode::Off`; `Phase::Corpus` gates replay only, and a fresh
  falsification is still recorded; without `Phase::Random` the result carries
  honest zero statistics and no coverage assessment, and passes only once the
  enabled earlier phases have. A phase set is validated element by element: a
  value that is not a `Phase` is rejected rather than silently skipped, since
  an unrecognised stage would make a property report green having checked
  nothing.
- Added `Gen::ipv6()`: IPv6 address strings in the canonical text form of
  RFC 5952 — lowercase hex, leading zeros stripped, the longest run of zero
  groups compressed to `::` (leftmost on a tie, never a single group). Each of
  the eight 16-bit groups shrinks toward zero, so the descent walks the
  shortened forms address parsers get wrong and ends at `::`. IPv4-mapped
  addresses, zone ids and the bracketed URL form are out of scope; `Gen::url()`
  still emits no IPv6 host.
- Documented `rasuvaeff/property-testing-names` — the person-name domain
  generator built on this engine — in both READMEs, `llms.txt` and the
  bundled skill.
- Documented two things the site never covered: a cookbook comparison
  [Faker vs property](https://rasuvaeff.github.io/property-testing-core/cookbook/faker-vs-property),
  which runs one UTF-8 truncation bug past a realistic-data generator and a
  shrinkable one and quotes what each reports (both falsify; one shrinks 30
  steps to another arbitrary name, the other one step to the boundary), and a
  Pest section on the PHPUnit adapter page — the scenario that already works
  (`uses()` plus the chain inside `it()`), why `id()` is not optional there,
  and why no `it(...)->forAll(...)` chain exists or is planned.
- Added `PropertyId::unstableWarning()`: the warning text for a property id
  derived from a closure (`Suite::{closure}` on PHP 8.3,
  `Suite::{closure:file:line}` from 8.4), or null when the id is stable. Such an
  id breaks the regression corpus without breaking anything visible — on 8.3
  every closure of a class shares one key, so two properties in a file overwrite
  each other's counterexample; from 8.4 the key carries a line number that any
  edit above shifts, orphaning yesterday's entry. The engine returns the
  sentence and prints nothing; an adapter shows it through the channel it
  already warns on.
- Fixed a numeric classification label reaching listeners as an int. PHP stores
  a numeric string such as `'42'` under an integer array key, so a label
  recorded with `Classify::label('42')` came back from the per-run buffer as
  `42` and travelled on to `RunPassed::$labels` — declared `list<string>`. The
  label is now handed back as the string the body recorded, and the internal
  counters declare the `array-key` they can actually hold instead of a `string`
  they cannot.

## 0.1.1 — 2026-08-10

- Added `MIGRATION.md`: the guide from the frozen `rasuvaeff/property-testing`
  2.x to this family — one Composer command for Testo projects, plus the
  custom-harness path (including where the `@internal` classes a harness used
  to reach for now live) and the PHPUnit path.
- Documented that a corpus values entry persists the failing input verbatim as
  plain JSON, and what that implies for generators that can produce
  sensitive-looking data (both READMEs, `llms.txt`).
- CI: added the `Adapter contract suite` job — both adapter packages are
  checked out at their default branch, pointed at the core under review
  through a path repository, and their test suites run. Documentation and CI
  only; no library changes.
- Added `resources/skills/rasuvaeff-property-testing-core/SKILL.md` and wired
  `extra.skills.source` in `composer.json`. The skill is decision-oriented
  (which generator, which phase mechanism) and auto-syncs into a consumer
  project's `.agents/skills/` via [`llm/skills`](https://github.com/roxblnfk/skills).
  Both READMEs document the consumer-side `skills.json` snippet for mirroring
  into `.claude/skills` / `.cursor/skills` via OS-level junctions/symlinks.
  Documentation and distribution metadata only; no library changes.

## 0.1.0 — 2026-08-09

- Extracted the framework-agnostic engine from `rasuvaeff/property-testing`
  2.8.1 with FQCNs preserved (namespace `Rasuvaeff\PropertyTesting`) — a
  drop-in split: generators, integrated shrinking, the property runner,
  the regression corpus, lifecycle events, and stateful/model-based testing.
- Promoted the `Runner` namespace, the event model (`Event\*`,
  `PropertyListener`) and the corpus surface to `@api`.
- Moved `Internal\CorpusStorage` to `Runner\FilesystemCorpus`, and
  `CorpusEntry`, `Clock`, `MonotonicClock` into the `Runner` namespace.
- Added `Gen::subset($values, $minSize, $maxSize)` (`SubsetArbitrary`):
  subsets of a fixed ordered set — duplicates rejected, source order
  preserved, size drawn uniformly, shrinking reduces size first and then
  moves kept elements toward earlier source positions; no discards.
- Declared `conflict` with `rasuvaeff/property-testing` (both packages ship
  the `Rasuvaeff\PropertyTesting` namespace; the frozen 2.x line is
  superseded by this family).
