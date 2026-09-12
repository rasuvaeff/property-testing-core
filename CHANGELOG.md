# Changelog

## 0.10.0 — 2026-09-12

The contract freeze ahead of 1.0. Everything here is something that could not
be changed after `1.0.0` without a major, so it is changed now. The new
[Compatibility policy](README.md#compatibility-policy) says what the numbers
will promise from then on.

- **Breaking:** `CounterExample::$skips` is now `$discards`, and `$skips` is a
  real, separate counter — the runs the environment refused. The two were one
  word for two quantities: the field held `Assume::that()` discards, while
  `RunStatistics::$skips` (0.9.0) held environmental skips, in the same `@api`.
  `toArray()`/`toJson()` gain a `discards` key beside `skips`. The corpus is
  untouched — `CorpusDocument` never wrote the full `toArray()`.
- **Breaking:** `FilesystemCorpus::fromEnv()` is removed. It read `PROPERTY_DB`
  and built `new self($directory)` unconditionally, so `PROPERTY_DB=redis://…`
  created a directory literally named `redis:/host:port/…` and emitted four
  `E_WARNING`s per call from the stream-wrapper lookup — a silent fall back to
  the filesystem, which is exactly what the family forbids. Use
  `CorpusFactory::fromDsn(EnvironmentOverrides::string(getenv('PROPERTY_DB')) ?? …)`,
  which cannot return a backend the value did not name. Nothing in `src/` calls
  `getenv()` any more.
- **Breaking:** `GenerationExhausted` is `GenerationExhaustedException` and
  `StateMachine\PostconditionViolation` is
  `StateMachine\PostconditionViolationException`, matching the seven public
  exceptions that already carried the suffix. No aliases: 0.x is where a rename
  costs a line in an upgrade note rather than a major.
- **Breaking:** every `Gen::*` factory returns `ArbitraryInterface<T>` instead
  of a concrete arbitrary. The concrete classes stay `@api` and unchanged —
  they are simply no longer part of the facade's signature, so an
  implementation can be replaced without a major. `Gen::enum()` bounds its
  template at `\UnitEnum` and `Gen::intRange()` declares the honest
  `array{int, int}` it produces.
- **Breaking:** a flag environment variable is off for `0`, `false`, `off` and
  `no` (case-insensitive, trimmed), not only `0` and `''`.
  `PROPERTY_VERBOSE=false` turning verbose output *on* was 2.8-compatible and
  indefensible.
- **Breaking:** left implicit, the skip budget is `runs` rather than the
  discard budget's `runs * 10`. A property skipped on every run gives up after
  `runs + 1` attempts instead of `10 * runs + 1` — under Testo that is a
  `#[BeforeTest]` hook executed 101 times rather than 1001 for a `runs: 100`
  property. An explicit `maxDiscards` still governs both budgets; transient
  skips still do not end a property early.
- `RunDiscarded` carries `bool $skipped`, so a listener can tell an
  environmental skip from an `Assume::that()` discard. Both adapters'
  `VerboseListener` counted them as one.
- `DistributionReport` carries `int $skips` and reports it in `toArray()`.
  `discardPercent()` keeps dividing by `attempts` — that denominator is the
  point of the name.
- `GaveUpException` carries `?int $maxSkips`, the cap the skip budget was
  measured against; the skip message names it. Null means the caller did not
  tell the two budgets apart, and the message falls back to `$maxDiscards`.
- Redis `AUTH` is reachable: `RedisDsn` and `CorpusFactory::fromDsn()` take an
  optional password, `LazyPhpRedisCorpusClient` authenticates before `SELECT`,
  and `toPredisParameters()` carries it. Adapters read `PROPERTY_DB_PASSWORD`.
  The DSN's userinfo is still refused — `PROPERTY_DB` is echoed in diagnostics
  and lands in CI logs.
- `RedisCorpus` refuses an empty prefix and a negative `maxValues`/`maxSeeds`.
  A negative cap made `CorpusDocument::cap()` drop every entry in silence.
- `composer rector` is green: `PropertyRunner` and `RegexCompiler` name two
  literal arguments. It had been red since 0.9.0, which is `composer
  release-check` red — `composer build` does not run rector.
- Documentation is part of the contract, and this release closes 37 places
  where it disagreed with the code: the split Configuration table in both
  READMEs, `forAll($generators, $id)` (the id comes from `->id()`),
  `floatBetween`'s interval (half-open, and it had no description at all), the
  four-of-eleven examples tables, the four-of-nine environment variables page,
  the eight-of-fifteen attribute table, `Command::shrinks()` and `minLen`,
  PSR-20 as the adapters' clock, `path` "when it ships in 0.2", `CorpusFromEnv`,
  `ext-tokenizer` missing from the requirements, and Redis absent from the
  regression-corpus page entirely. The site gained a **Compatibility policy**
  page, `roadmap.md` no longer promises a seed sequence that 0.6.0 changed, and
  the API reference reflects traits — so the PHPUnit adapter's single entry
  point, the `PropertyTesting` trait, has a page for the first time.
- `docs/.api-workspace` follows the adapters again (`^0.9`/`^0.7`; it sat at
  `^0.6`/`^0.5`, and a caret on `0.x` pins a minor, so the weekly docs rebuild
  had been re-reflecting the same stale release). Keeping it in step is now in
  the release checklist beside `build.yml`'s pin. At release time `build.yml`
  moves to `0.10.0` and the workspace to `^0.9`/`^0.8`, but the workspace's
  path version stays `0.9.0` for now: the published Testo adapter still
  constrains core to `^0.9` (its `^0.9 || ^0.10` bridge is merged, not
  tagged), and a `0.10.0` path version would make the workspace uninstallable.
- The Compatibility policy gains the point the 1.0 review asked for and the
  freeze first left out: **how data is reached is frozen as it stands**. The
  accessor style is not uniform — results, events and most exceptions expose
  public `readonly` properties, while `PropertyViolationException`,
  `ExampleViolationException`, `PathViolationException` and
  `RegressionViolationException` expose getters — and it stays that way. Unifying it would break every consumer for a cosmetic
  gain, and the split is at least stable: a type either has the field or the
  getter, and one never becomes the other. Written down rather than left to be
  rediscovered.
- `ValueCodec` refuses an int-flagged array key whose text is not a decimal
  integer the platform holds: a bare `(int)` cast saturated `9223372036854775808`
  to `PHP_INT_MAX` and collapsed `abc` to `0`, replaying a key the property was
  never called with. The lenient `007` → `7` reading stays (#119).
- `ValueCodec` reads a finite float back only in the shape `var_export()` writes
  it: `1e999` (cast to `INF`), `1.0E-400` (cast to `0.0`), `01.0`, `.5` and
  whitespace-padded texts were accepted by `is_numeric()` and decoded to a
  value the encoder could never have stored (#120).
- `FrequencyArbitrary` reports weights whose sum passes `PHP_INT_MAX` as an
  `InvalidArgumentException`; the float sum used to reach the typed property
  and fail with a `TypeError` (#121).
- `PropertyDefinition` rejects a repeated parameter name — the runner combined
  names and draws by name, so one draw was dropped without a word and the
  body received fewer arguments than declared (#122).
- `DateTimeArbitrary` refuses a bound outside the microsecond range of a
  64-bit integer (about 292 000 years either side of the epoch) with an
  `InvalidArgumentException`; the overflowed product used to fail the `int`
  return type with a `TypeError` (#123).

## 0.9.0 — 2026-09-05

- **Breaking:** `Gen::oneOf()` and `Gen::elements()` reject an
  `ArbitraryInterface` among their values. `oneOf(generator, generator)` is how
  fast-check, jqwik and Hypothesis spell "pick one of these generators", and
  here it made the generator objects themselves the data: the body received
  `IntArbitrary` and `StringArbitrary` instances, never a generated value, and
  the property reported green having checked nothing. Pick between generators
  with `Gen::frequency()`; `oneOf`/`elements` take values.
- **Breaking:** `Gen::regex()` / `Gen::stringMatching()` reject a delimited
  pattern. The compiler always wanted `[a-z]{3,6}`, not `/[a-z]{3,6}/`, and the
  word "delimiter" appeared nowhere in the code or the documentation. A PHP
  developer arrives with a pattern from `preg_match()`, which is always
  delimited, and got one of two things: an anchored pattern was blamed on its
  anchor (the `^` is not leading only because a `/` precedes it), and an
  unanchored one compiled with the delimiters as literals — values that still
  passed an unanchored `preg_match()`, so the property stayed green while every
  generated string carried two junk characters. The rejection names the
  undelimited pattern to paste back, and escaping the character still matches
  it literally.
- Environmental skips no longer spend the discard budget, and
  `RunStatistics::$skips` counts them apart from `$discards`. A skip says
  nothing about the input, so charging it to the discard budget made a machine
  missing a dependency give up — and be advised to narrow generators that were
  never at fault. `GaveUpException` gained `$skippedRuns` and
  `$exhaustedBySkips`, and reports the environment rather than the generators
  when it is the skip budget that ran out. Both budgets share the
  `maxDiscards` cap.

## 0.8.0 — 2026-09-04

- `TrialOutcome::skipped()` joins `discarded()` as a third "this run checked
  nothing" outcome, and adapters should report an environmental skip
  (`markTestSkipped()`, a framework skip from a lifecycle hook) as such. It
  counts as a discard everywhere but one: a recorded regression whose replay
  only skipped is kept instead of pruned. Before, one machine without the
  dependency the body guards against deleted the counterexample for every
  machine that has it.
- A shrink-path replay no longer accepts `GenerationExhausted` as the step's
  falsification. A draw past the end of the recorded tape regenerates through
  the live generator, and an exhaustible one (`filter()`, `uniqueArrayOf()`
  with a minimum, `commands()` with a minimum length) can fail to produce a
  value: the recorded bug was reported as a generator exhaustion. The step is
  now reported as a stale path.
- `prune()` says so — as a `CorpusFailed` event — when the entry cannot be
  re-encoded to the key that identifies it, on both the filesystem and the
  Redis backend. It used to compute a null key, match nothing and silently
  replay the entry forever.
- `Gen::forParameters()` / `Gen::forClass()` refuse a `DateTimeInterface` type
  that is not `DateTimeImmutable` itself — a subclass, or `DateTime` — by name.
  Reflection over the inherited constructor asked for a `string` and blew up
  with a date-parse error deep inside the recursion instead.
- A Redis DSN with an array-valued `prefix` (`?prefix[]=x`) is refused rather
  than silently falling back to the default prefix, and a database index with a
  leading zero (`/01`) is reported as the spelling mistake it is instead of as
  a number outside the integer range.
- The corpus file is read through a suppressed `file_get_contents()`, like
  every other filesystem call in that class: a file removed between the
  `is_file()` check and the read no longer prints a raw warning into the
  suite's output.

## 0.7.0 — 2026-09-04

- `PropertyConfig` rejects a `timeoutMs` or `budgetMs` past the value where the
  millisecond-to-nanosecond conversion leaves the integer range, the way it
  already rejected such a `shrinkBudgetMs`. Above that bound the deadline
  silently stopped being one.
- A corpus document whose keys collide once PHP normalises them — `0` and `"0"`
  in one encoded array, which no real PHP array can hold — is refused instead of
  losing a pair to `array_combine()`.
- `Gen::regex()` strips a trailing `$` anchor by the parity of the backslash run
  before it, so `foo\\$` compiles (literal backslash, real anchor) instead of
  failing on the anchor it mistook for an escaped `$`.
- A quoted literal in a psalm docblock type may contain the separator of the
  type it sits in: `'a|b'|'c'` and `array{k: 'x,y'}` are read as written, and
  `\'` inside a literal is unescaped.
- `Gen::oneOf()` enumerates shrink candidates for objects by identity. The
  previous `var_export()` key threw on a cyclic object graph and treated two
  distinct objects of equal state as one candidate.
- `Gen::forClass()` builds its `ReflectionClass` once instead of once per
  generated value.

## 0.6.1 — 2026-09-03

- Redis corpus DSNs accept a finite connection `timeout` (default `5.0`),
  passed to both Predis and ext-redis clients.
- Redis DSN ports and database indexes are validated strictly; invalid and
  overflowing values are rejected instead of being silently changed.

## 0.6.0 — 2026-09-03

- `Gen::string()` / `stringOf()` / `char()` draw characters with a distribution
  that keeps strings readable and adversarial at once — half ASCII printable,
  a tenth from a list of troublemakers (quotes, backslash, `<>&`, tab and
  newlines, no-break space, soft hyphen, combining marks, zero-width space
  and joiner, right-to-left mark and override, line separator, byte order
  mark, replacement character, astral emoji, tag characters), a tenth
  Latin-1/Latin Extended, a tenth the rest of the Basic Multilingual Plane,
  a fifth uniform over U+0001..U+10FFFF — instead of uniformly over 1.1
  million codepoints, which met a quote once in a million characters and
  produced strings that were never ASCII. This changes the sequence a seed
  produces: `FilesystemCorpus::SEQUENCE_EPOCH` is now 2, and seed entries
  recorded by 0.5 and earlier are dropped from the corpus (values entries
  are unaffected).
- `Gen::recursive()` — and `Gen::json()` on top of it — shrink every level to
  its leaf first, so a nested value minimises to the plain value it wraps:
  `[[[1]]]` reaches `1`, not merely `[]`. Each level draws the leaf's seed at
  generation time (the sequence shifts, covered by the epoch above).
- `Gen::datetime()` generates with microsecond precision and shrinks toward
  the epoch through an integer ladder. Bounds keep their fraction
  (`min = 12:00:00.5` no longer generates `12:00:00.0`), and "any date after
  2000 fails" now minimises to the boundary instead of stopping at the
  original because the single epoch candidate passed.
- `Gen::dictOf()` with a string key generator redraws a canonical integer
  string (`"12"`, `"-3"`, `"0"`), which PHP would store under the integer
  key — the map stays the `array<string, T>` it declares.
- A corpus that throws (a Redis server refusing the connection, a client
  error) no longer escapes `PropertyRunner::run()`: the failure is a new
  `CorpusFailed` event (property id, operation, throwable) and the corpus is
  dropped for the rest of that run. The engine's promise that it never
  throws for a property outcome now covers the corpus.
- `FilesystemCorpus` takes one lock for the directory (`.corpus.lock`)
  instead of one `.lock` per property: a per-property lock file could never
  be removed safely and accumulated one file per property for good. A
  symlink at the lock path is refused, like the temp path.
- Both backends leave a document written by another format version as it
  is: it read as empty, and a write replaced it — the other version's memory
  lost without a trace. `CorpusDocument::isForeignFormat()` tells it apart
  from corrupt content, which is still replaced.
- The Redis clients address the compare-and-set script by `EVALSHA`
  (`CorpusScript::SHA`) and fall back to `EVAL` on `NOSCRIPT`: the script
  text travels once per server, not once per write.

## 0.5.0 — 2026-09-02

- The code both adapters carried byte for byte now lives here, so a DSN
  and a `PROPERTY_*` value mean the same thing under Testo and PHPUnit:
  `Runner\CorpusFactory::fromDsn()` (directory path or Redis DSN, memoized
  per value, `ext-redis` preferred, predis otherwise, any other scheme
  refused), `Runner\Redis\RedisDsn` and `Runner\Redis\LazyPhpRedisCorpusClient`,
  and `Runner\EnvironmentOverrides` with the parsers for `PROPERTY_RUNS`,
  `PROPERTY_SEED`, `PROPERTY_PHASES`, `PROPERTY_EDGE_CASES` and the flag and
  string variables. The engine still never reads the environment: the
  adapter reads a variable and hands the value over. `PROPERTY_RUNS` and
  `PROPERTY_SEED` past the integer range are refused instead of saturating
  to `PHP_INT_MAX` under a cast.
- The Redis DSN has the shape everything else gives it (the IANA
  registration, predis, Symfony): `redis://host[:port][/db][?prefix=key-prefix]`,
  `rediss://` for TLS, an IPv6 literal in brackets. The path is the database
  index; the key prefix moved to the `prefix` query parameter. The pre-0.5
  form `redis://host/suite-a:` — the path as the prefix — is refused with the
  new spelling in the message rather than silently selecting a database. A
  refused connection or database is an error, no longer a corpus that is
  quietly empty.
- A seed entry in the regression corpus now records the `EdgeCases` mode
  the failure was found under (`edgeCases: mixin|none`, read as `mixin` by
  documents that predate the field — the only mode there was), and the
  replay runs under that mode rather than the current configuration's. The
  modes share the roll but not the values it selects, so a suite that
  switched modes replayed other values, passed, and pruned a live
  regression. `CounterExample` carries the mode as `$edgeCases`, in
  `toArray()` too; `Random` exposes it as `$edgeCases`. The document format
  policy is now written down: within a format version the document grows
  only by optional fields, which older readers ignore and newer readers
  default; the version changes only when an existing field changes.
- Shrinking accepts a candidate only when it fails with the same exception
  class as the original run. A smaller input that trips a different error
  (a `TypeError` in the body's setup below the assertion's boundary) used to
  be accepted, and the reported minimal counterexample minimised that other
  bug — the descent now stops at the boundary of the failure that was found.
- `Corpus::prune()` finds a values entry recalled under a reordered
  signature: `hydrate()` accepts the reorder and hands the arguments back in
  the current order, so the re-encoded entry no longer matched the stored
  bytes and the fixed regression replayed on every run. Entry identity keys
  the arguments by name.
- `FilesystemCorpus` reclaims an orphaned temp file: a writer killed between
  create and rename, under a pid that came around again (pid 1 in a
  container, a recycled range under paratest), left a regular file that made
  the exclusive create refuse every later write of that property for good.
  The write runs under the property's lock, so a regular file at the temp
  path can only be such an orphan; a symlink or a directory keeps the
  refusal.
- Documentation: `timeoutMs` is measured when the run returns — it reports a
  run that overran, it cannot interrupt a body that hangs — and shrink
  trials are not timed; the README, `llms.txt` and the skill said or implied
  otherwise.
- A shrink candidate that cannot be built no longer escapes the descent and
  discards the counterexample the random phase found. `Shrinkable::map()`
  skips a candidate the transformation refuses with an exception (a
  validating constructor under `Gen::forClass()` or `Gen::map()`), and
  `Gen::flatMap()` skips a source candidate under which the dependent side
  has nothing to generate — each together with its subtree, siblings still
  offered. As a safety net, `PropertyRunner` treats a candidate enumeration
  that throws (an `Error` in a transformation, a broken hand-written
  `Shrinkable` tree) as exhausted at that point: the candidates yielded before
  it count, the descent continues with the next node, and a replayed path
  indexing past the break reports the candidate as missing.
- `Gen::forClass()` / `Gen::forParameters()` no longer fall back to the native
  type when a docblock type is outside the readable subset. `float<0.0, 1.0>`
  generated from `float` — the whole line for a parameter documented as the
  unit interval — is the widened guess the reader promises never to make; it
  is now an error naming the parameter and the documented type. The one
  fallback kept is a native class type, whose docblock can only narrow
  generics (`Collection<Item>`), never the values.
- Docblock types now resolve class names: `list<LineItem>`, `Status|null`,
  `'draft'|'published'|null`, `\DateTimeImmutable` and a namespace-relative
  `Order\LineItem` are read the way the code beneath the docblock reads
  them — through the declaring file's namespace and `use` imports (aliases
  and group imports included), which reflection does not expose, so the
  file is tokenised once. `ext-tokenizer` is now required. Literal unions
  accept `null`, `true` and `false` beside quoted strings and integers.
- `Gen::forClass()` / `Gen::forParameters()` reject an override whose key
  names no parameter (`['amout' => …]`) instead of silently generating the
  parameter from its declared type.
- `Gen::regex()` / `stringMatching()` bound the longest string a whole
  pattern can generate (10,000 characters) at compile time, not only each
  quantifier on its own: `(a{10000}){10000}` and a deeply nested `(a*)*`
  chain passed the per-quantifier guard and exhausted memory on the first
  draw. `maxRepeat` above the same bound is rejected for the same reason.
- `FloatArbitrary` (`Gen::floatBetween()`) no longer emits the exclusive
  upper bound when the span is within a few ulps of `min` (`1e16 .. 1e16 + 2`
  rounded up to `max`), and rejects a `NAN`/`INF` bound instead of generating
  `NAN` forever.
- Lists, unique lists, maps, strings, charset strings and byte strings now
  shrink by removing contiguous blocks of elements from every position —
  the whole sequence, then aligned halves, quarters, …, single elements —
  before shrinking elements in place, the way QuickCheck's `shrinkList` and
  Hedgehog do. The previous length phase only halved prefixes, so a failing
  element in the middle or at the end could never be isolated: `[7, 3, 42]`
  under "no 42 allowed" stopped at `[0, 0, 42]`, and now reaches `[42]`.
  Generation is unchanged (same seed, same values); a shrink path recorded
  against a sequence generator before this release indexes different
  candidates and is reported as stale by path replay.
- `Gen::oneOf()` / `OneOfArbitrary` accept a string-keyed variadic (named
  arguments, a spread map) — the values are picked by position instead of
  reading a missing index 0.

## 0.4.2 — 2026-08-20

- `FilesystemCorpus` now creates its temp file with `O_EXCL` (`fopen(…, 'x')`)
  instead of a plain write. The temp path is derived from the property id and
  pid, so on a shared corpus directory it is predictable; a pre-planted symlink
  would otherwise be followed and let a corpus write overwrite an arbitrary
  file. The exclusive create refuses the existing path, turning that into a
  skipped write (the corpus is best-effort memory, not a ledger).
- `Gen::regex()` / `stringMatching()` now reject an explicit `{n}`/`{n,m}` bound
  above 10,000 with a named error, instead of building a fixed-size array that
  exhausts memory during generation. Unbounded `*`/`+`/`{n,}` were already
  capped by `maxRepeat`; only the explicit bounds were unguarded.
- Docs: documented that seed replay may run more attempts than the configured
  `runs` (it extends up to the recorded failing attempt so it can reproduce the
  regression), and that `ValueCodec::decodeEnum()` triggers the autoloader on a
  class name from the corpus document.
- Docs: SKILL.md now covers `EdgeCases::None` — the attribute parameter list
  and the boundary-bias rule (per-property opt-out for bodies that discard
  edge values, with the PHPUnit `edgeCases()` parity and the seed-alignment
  guarantee under `None`).

## 0.4.1 — 2026-08-16

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
