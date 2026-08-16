---
title: Roadmap
description: "What the 0.2, 0.3 and 0.4 releases delivered, and the direction of the property-testing package family: targeted property testing and coverage-guided search."
---

# Roadmap

This roadmap describes direction, not commitment. Scope and ordering may
change as each item goes through design review and implementation, and an
item is dropped rather than shipped if its prototype does not show a
measurable gain.

Everything this page previously planned for 0.2 has shipped. Core 0.2.0 and
0.2.1 were released on 2026-08-14, 0.3.0 and 0.3.1 on 2026-08-15, and 0.4.0
on 2026-08-16, with matching adapter releases; the details are in the
changelog of each package.

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

## Compatibility commitments

Every minor release preserves the observable contracts of the previous one:

- the same generated sequence for an existing generator and seed;
- compatibility with regression corpora written by 2.8 and by every 0.x
  release so far;
- the existing order and content of events for current outcomes;
- the package conflict with the frozen `rasuvaeff/property-testing` 2.x line.

New configuration, result fields, generators, and events may be added.
Existing behaviour will not be silently reinterpreted.

## Next: targeted property testing and an adaptive example database

The next core minor is planned around search: the property body reports a
numeric score — a delay, a recursion depth, a distance from a boundary — and
the engine spends part of the run maximising or minimising it instead of
sampling blindly. The design under review:

- a `Target::maximize()` / `Target::minimize()` facade, process-local like
  `Classify`, with a fixed direction per label and an immediate configuration
  error for non-finite scores;
- an opt-in search phase after the random phase: hill climbing over a pool of
  the best-scoring inputs, mutating at parameter granularity — regenerate one
  parameter, keep the others — because ordinary generator decisions are not
  recorded on any replay tape;
- an adaptive example database: today's corpus stores falsifications only.
  Bounded top-K `target` entries per label will be kept in a **separate
  search document**, so an older reader never mistakes them for regressions
  and never prunes them;
- a `TargetImproved` event and a `SearchReport` on `PropertyFinished`, under
  the same guarantee as the distribution report: zero overhead when the
  feature is unused;
- the work starts with a go/no-go prototype against real extremum bugs. If
  parameter-level mutation shows no measurable gain over random search, the
  phase is not shipped, and a search tape — replay and mutation of generator
  decisions — becomes the prerequisite instead.

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
