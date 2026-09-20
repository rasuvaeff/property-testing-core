---
title: Roadmap
description: "What the 0.x releases delivered, what 1.0 freezes, and the direction of the property-testing package family: targeted property testing and coverage-guided search."
---

# Roadmap

This roadmap describes direction, not commitment. Scope and ordering may
change as each item goes through design review and implementation, and an
item is dropped rather than shipped if its prototype does not show a
measurable gain.

Everything this page previously planned for 0.2 has shipped, and so has
everything through 0.10. The details are in the changelog of each package;
what follows is the shape of it.

## Shipped in 0.2 and 0.3

- **Replay by seed and path** — a falsified property reports the shrink
  descent on `CounterExample::$path`, the failure message ends with a
  `Path:` line (0.2.1), and `PropertyConfig::$path` replays the descent with
  one body execution per accepted step.
- **Shrinking controls** — `ShrinkMode::Off` and a wall-clock
  `shrinkBudgetMs` that returns the best counterexample found so far.
- **Deterministic unseeded runs** — `derandomize` derives the seed from the
  property id; explicit seeds keep the highest priority.
- **Selectable run phases** — examples, corpus replay, random generation, and
  shrinking are individually selectable through `PropertyConfig::$phases`.
- **Machine-readable distributions** — `PropertyFinished` carries a
  `DistributionReport`: label shares, `cover()` requirements, and the discard
  tally, as data rather than console text.
- **Swarm generation** — `Gen::swarm()` runs a choice generator with a
  random, non-empty subset of its variants per generated case; shrinking
  stays inside the drawn subset. Custom choice generators join through the
  `Swarmable` interface.
- **IPv6 generation** — `Gen::ipv6()` generates canonical RFC 5952 addresses
  and shrinks toward `::` and `::1`.
- **Edge-case modes** (0.3) — `EdgeCases::None` turns the numeric boundary
  bias off without shifting the sequence a seed produces.
- **Generators from constructor types** (0.3) — `Gen::forClass()` builds a
  generator from what a constructor declares: an override, the `@param`
  psalm type, then the native type, with a bounded and documented supported
  subset and refusal — naming the parameter and the chain that reached it —
  for anything outside it.
- **A Redis corpus backend** (0.3) — `RedisCorpus` stores a document
  byte-identical to the filesystem backend's, so moving a corpus between the
  two is a copy. The client seam ships implementations over `ext-redis` and
  predis.
- **Stable property ids for PHPUnit** — the PHPUnit adapter's explicit
  `id()` provides stable event and corpus keys for properties invoked from
  closures, including Pest tests.
- **Adapter delivery** — the Testo and PHPUnit adapters resolve
  `PROPERTY_PATH`, `PROPERTY_DERANDOMIZE`, `PROPERTY_PHASES`,
  `PROPERTY_EDGE_CASES`, and `PROPERTY_DB=redis://…`; the PHPUnit fluent API
  gained the corresponding methods.

## Shipped in 0.4 and the matching adapter minors

- **Generators from any signature** (core 0.4.0) — `Gen::forParameters()`
  applies the `forClass()` resolution rules to the parameters of any
  function, method, or closure: an override, the `@param` psalm type, then
  the native type, with refusals that name the function and the parameter.
  Overrides may be partial — the parameters they name are taken as given,
  the rest are derived.
- **Auto-derived property arguments** — the Testo adapter's
  `#[Property(auto: true)]` (0.6.0) and the PHPUnit adapter's fluent
  `auto()` with an optional `forAll()` map (0.5.0) derive a generator for
  every parameter the provider does not cover, so a fully-typed property
  needs no provider at all. Strictly opt-in, and a provider key that is not
  a parameter of the property is an error rather than a silent replacement.
- **Generators in attribute arguments** (Testo adapter 0.5.0) — the
  `#[Property]` attribute accepts callable providers: a
  `[SharedGens::class, 'delay']` method reference on every supported PHP
  version, an invokable provider object, and — on PHP 8.5 only — an inline
  closure or first-class callable. The `<method>Generators()` convention
  stays; the new forms are an addition.

## Shipped in 0.5 through 0.10

- **A corpus from a DSN** (0.5) — `CorpusFactory::fromDsn()` turns a
  `PROPERTY_DB` value into the backend it names: a directory path into a
  `FilesystemCorpus`, `redis://` / `rediss://` into a `RedisCorpus`, any other
  scheme into an error rather than a directory called after the scheme.
- **Regex generators** (0.5) — `Gen::regex()` / `Gen::stringMatching()` compile
  a documented subset of PCRE into ordinary combinators, so a matching string
  shrinks like any other value. Delimiters are refused (0.9) instead of being
  compiled as literals.
- **A single directory lock** (0.6) — `FilesystemCorpus` serialises
  read-modify-write behind one `.corpus.lock` per directory rather than a lock
  file per property.
- **A better `Gen::string()` distribution** (0.6) — and, with it,
  `SEQUENCE_EPOCH` at 2. See [Compatibility policy](/guide/compatibility) §2
  for why that is a minor.
- **Skips counted apart from discards** (0.9) — a run the environment refused
  is no longer charged to the discard budget, and `GaveUpException` names the
  environment rather than advising narrower generators.
- **The contract freeze for 1.0** (0.10) — `CounterExample::$skips` became
  `$discards` beside a real `$skips`; `RunDiscarded` and `DistributionReport`
  learned the same distinction; `GenerationExhausted` and
  `PostconditionViolation` gained the `Exception` suffix the other seven
  public exceptions carried; `Gen::*` returns `ArbitraryInterface<T>` rather
  than concrete classes; `FilesystemCorpus::fromEnv()` — the one helper in the
  engine that read the environment, and the one that turned a Redis DSN into a
  directory — is gone; the implicit skip budget is `runs` rather than
  `10 * runs`; `PROPERTY_DB_PASSWORD` reaches an authenticated Redis; and
  `false`/`off`/`no` turn a `PROPERTY_*` flag off.

## Shipped in 0.12

The ten items of the 2026-09-19 issue wave, before the 1.0 freeze so that
none of them has to wait for a major:

- **Targeted property testing** — `Target::maximize()` / `minimize()`, an
  opt-in search phase (`searchRuns`) that climbs the score over a pool of
  the best inputs by regenerating one parameter at a time, a
  `TargetImproved` event, a `SearchReport` on `PropertyFinished`, and the
  `SearchCorpus` seam with a separate search document in both backends. It
  passed its go/no-go on a corner bug — 52 to 98 of 100 seeds at equal
  budget — and the [measurement](/guide/targeted-search#what-the-measurement-said)
  also records what parameter-level mutation cannot do: close a gap between
  two parameters. That is what the search tape below is for.
- **Exhaustive mode** — `PropertyConfig::$exhaustive` walks the parameter
  product through the `Enumerable` seam when it fits the budget.
- **Flaky detection** — the minimised counterexample is replayed
  (`flakyReplays`), and a replay that passes says so on the counterexample.
- **A rule-based stateful façade** — `#[Rule]`, `#[Precondition]`,
  `#[Invariant]` on one class, `Gen::rules()` over the existing sequence
  generation and shrinking.
- **Generators** — `Gen::composite()` with its `Draw` seam,
  `Gen::withEdgeCases()`, `Gen::randomEngine()` / `randomizer()`,
  `Gen::uniqueArrayOf(by:)`.
- **Reporting** — `Gen::note()` on the counterexample,
  `Classify::tabulate()` with pairwise intersections on the distribution
  report.

## Compatibility commitments

Written out in full on [Compatibility policy](/guide/compatibility): what
`@api` covers, why seed→values is deliberately outside SemVer, what the corpus
format and the machine-readable result shapes promise, and how the family's
releases are ordered.

Two things that page replaces, and that this page used to say wrongly: a minor
does **not** promise the same generated sequence for a given seed (0.6.0 shifted
it, correctly, and bumped the epoch), and message texts are prose that may be
reworded in a minor — the machine-readable form is what is frozen.

## 1.0

1.0 is the version at which the above stops being a description and becomes a
commitment. It carries no new capability of its own: the work is the freeze —
names, signatures, field semantics, and the documentation that is part of the
contract. The engine ships first, then the two adapters, then `-names`, each
requiring `^1.0`.

## Next: a search tape

Targeted search mutates at parameter granularity because ordinary generator
decisions are not recorded on any replay tape: regenerating one parameter is
a global move. A bug that needs one parameter tuned to within a few units of
another needs a local move — mutate one generator decision, keep the rest —
and that needs the decisions recorded. A search tape (replay and mutation of
generator decisions, the way in-body draws already replay) is the
prerequisite for the next step of targeted search, and the item this
roadmap commits to designing before any coverage-guided work.

## Later: coverage-guided search, as a separate optional package

Only after targeted search, which provides the pool and the search loop it
needs. The split is fixed in advance:

- core gains only the seam: a `Feedback::feature()` facade for semantic
  novelty reported by the property body, and a `FeedbackProvider` interface
  for instrumented novelty. Inputs that reach something new join the same
  search pool as `novelty` entries;
- instrumented coverage ships as an optional package with a PCOV-backed,
  line-granularity provider. Core will not depend on PCOV or Xdebug, not
  even as a suggestion, and the package is inert without the extension
  rather than silently fuzzing blind;
- fuzzing runs as a nightly job with a time budget, never as a default mode
  or a pull-request gate. Its findings are recorded in the ordinary
  regression corpus, so a nightly discovery reproduces in the next normal
  test run;
- it is not an AFL reimplementation: no bytecode instrumentation, no
  symbolic execution, and no replacement of the integrated rose-tree
  shrinking model.

## Deferred

A dedicated Pest package remains deferred. Pest can already use the PHPUnit
adapter's trait with an explicit property id; a separate integration will be
considered only if it can be built without relying on Pest internals.
