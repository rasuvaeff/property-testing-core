# AGENTS.md — property-testing-core

Guidance for AI agents working on this package. Read before changing code.

## What this is

The framework-agnostic property-based testing **engine**, extracted from
`rasuvaeff/property-testing` 2.8 with FQCNs preserved (namespace
`Rasuvaeff\PropertyTesting`). It owns: the `Gen` facade and every `Arbitrary`
(`ArbitraryInterface`, `Shrinkable` integrated-shrinking trees, `Random`),
`Assume`/`Classify`/in-body `Gen::draw()`, the `Runner` namespace
(`PropertyRunner`, `PropertyDefinition`/`PropertyConfig`, the
`TrialExecutor`/`TrialOutcome` seam with `CallableTrialExecutor`, the closed
`PropertyResult` hierarchy, `Corpus`/`CorpusEntry`/`FilesystemCorpus`,
`Clock`/`MonotonicClock`), the event model (`Event\*`, `PropertyListener`),
the public exceptions, and `StateMachine` (model-based testing).

It knows nothing about test frameworks. Adapters live in separate packages:
`rasuvaeff/property-testing-testo` (the old `#[Property]` attribute and Testo
interceptor) and `rasuvaeff/property-testing-phpunit` (fluent trait). This
package declares `conflict` with the frozen `rasuvaeff/property-testing` —
both ship the same namespace.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Preserve seed determinism.** `tests/SeedDeterminismVectorsTest.php` is the
   observable definition of `FilesystemCorpus::SEQUENCE_EPOCH` — a diff there
   means the generated sequence for a given seed shifted: revert the change or
   bump the epoch in the same commit. Never repin silently.
4. **Preserve shrinking termination.** Every branch of a `Shrinkable` tree
   must be finite and no candidate may equal its parent value. The runner
   additionally skips candidates whose value equals the current one.
5. **Preserve the public contract.** The engine never reads env, never prints,
   never exits, and never throws for a property outcome — it returns a
   `PropertyResult` whose failing members carry the established exception
   types and message formats. Update README (both languages) + tests with any
   API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.
`composer.lock` is gitignored (library).

## Invariants & gotchas

- **The engine is env-free.** `PropertyRunner` reads no environment; adapters
  resolve `PROPERTY_RUNS`/`PROPERTY_SEED`/`PROPERTY_VERBOSE`/`PROPERTY_DB`
  into a `PropertyConfig` and a `Corpus`. The one opt-in helper here is
  `FilesystemCorpus::fromEnv()` — it reads `PROPERTY_DB` only when called.
- **This package's own property-style tests cannot use `#[Property]`** — the
  attribute lives in the `-testo` adapter, which depends on this package
  (a circular dev dependency). They drive the engine directly through
  `tests/Support/Check::property()`, which rethrows a failing outcome so the
  usual counterexample message surfaces.
- **Sequential-only.** `Classify` (labels + coverage requirements) and
  `DrawContext` (the `Gen::draw()` replay tape) are process-local statics,
  armed and drained around every body execution, with a defensive flush at
  the start of `run()`. The runner drains coverage requirements on every exit
  path of the random phase. Do not add concurrency without redesigning them.
- **Event model.** Events carry engine data only — property id, seed,
  attempts, arguments, labels, elapsed time, failures, counterexamples;
  framework types never appear in an event. A listener exception aborts the
  run (deliberately not caught in `emit()`); a listener can never change the
  outcome. The exact per-outcome event sequences are pinned by the adapter
  packages' characterization suites — an extra, missing or reordered event is
  an observable engine change.
- `Random` uses an object-scoped `\Random\Randomizer` (MT19937), NOT the
  global `mt_srand`/`mt_rand` — same seed, same sequence, regardless of
  intervening random calls.
- In-body draw shrinking is replay-tape-based and intentionally does NOT
  re-validate replayed nodes (fast-check `gen()` model). A regrown tape breaks
  the finite-tree termination argument, so accepted steps are capped by
  `PropertyRunner::MAX_DRAW_SHRINK_STEPS` whenever the tape is non-empty. Do
  not remove the cap or add re-validation.
- **The regression corpus must never replay a different input than it
  recorded.** Three guards enforce that: (1) a counterexample carrying
  `draw#N` pseudo-arguments is stored as a SEED, never as values; (2)
  `FilesystemCorpus::recall()` drops a values entry whose argument names are
  not exactly the property's current parameters and orders values by the
  parameter list; (3) `FilesystemCorpus::SEQUENCE_EPOCH` fences seed entries
  off — bump it in any release that shifts the seed→values mapping (see
  golden rule 3). Values entries are exempt by design.
- **`FilesystemCorpus` writes are atomic and serialised by a cross-process
  flock.** `remember()`/`prune()` do read-modify-write behind a `.json.lock`
  file; `write()` goes through a temp file + `rename()`. Do not remove the
  lock or switch back to a bare `file_put_contents()`. The on-disk format is
  byte-compatible with `rasuvaeff/property-testing` 2.8 — `FORMAT_VERSION`
  does not change just because classes moved.
- `ValueCodec` sends EVERY float through a tagged envelope, as text —
  `json_encode()` renders an integral float as an integer literal, so an
  unenveloped float decodes back as an int. Do not "optimise" finite floats
  back to raw JSON numbers.
- Psalm 6.16 crashes on the `NAN` constant in `src/` —
  `ValueCodec::decodeFloat()` computes it with `fdiv(0.0, 0.0)` for that
  reason.
- `Gen::filter()` retries up to 100 times then throws `GenerationExhausted`;
  the runner catches it at the generation step and reports `GenerationFailed`.
  Sized collections (`uniqueArrayOf`, `dictOf`, `commands`) guarantee their
  minimum or throw — never return a too-small value.
- `yield from` inside a generator that already `yield`ed causes integer-key
  collisions. Spread inner shrink candidates with an explicit `foreach` +
  `yield`.
- Shrink trees are built at generation time; `FlatMappedArbitrary` captures
  one extra seed at `generate()` to regenerate the dependent value
  deterministically — do not replace it with ambient randomness.
  `Shrinkable::shrinks()` re-invokes its closure on every call; children must
  be re-derivable (pure closures over immutable state).
- Stateful validity is enforced at RUN time, not shrink time:
  `CommandSequenceArbitrary` shrinks by pure list drop/element-shrink WITHOUT
  re-validating; `StateMachine::check()` skips commands whose precondition a
  dropped step invalidated. The skip-on-replay is the contract.
- Serialization contract (pinned by
  `tests/Runner/PropertyResultSerializationTest.php`): the engine adds no
  unserializable state of its own — every result survives `serialize()` under
  `zend.exception_ignore_args=1`; the portable format is
  `CounterExample::toArray()`.
- Tests obtain shrink trees only via generation: `tests/Support/Trees.php`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
