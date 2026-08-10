---
title: Roadmap
description: "Planned work for property-testing-core 0.2 and the longer-term direction of the property-testing package family."
---

# Roadmap

This roadmap describes planned work, not released functionality. Scope and
ordering may change as each item goes through design review and implementation.
The current target is `property-testing-core` 0.2, an additive minor release
that preserves every existing call and its meaning.

## Compatibility commitments

Version 0.2 must preserve the observable contracts established by 0.1:

- the same generated sequence for an existing generator and seed;
- compatibility with regression corpora written by 2.8 and 0.1;
- the existing order and content of events for current outcomes;
- the package conflict with the frozen `rasuvaeff/property-testing` 2.x line.

New configuration, result fields, generators, and events may be added. Existing
behaviour will not be silently reinterpreted.

## Planned for 0.2

### Direct replay by seed and path

Failure output will include a shrink path alongside the seed. Supplying both
will reproduce the minimal counterexample directly, without repeating the
whole random run and shrink search. A path that no longer applies will produce
an explicit replay failure instead of falling back to an unrelated run.

### Shrinking controls

The runner will support disabling shrinking and bounding it by wall-clock time.
When a time budget expires, it will return the best counterexample found so
far. This complements the existing accepted-step limit, which does not bound
the cost of rejected shrink candidates.

### Deterministic unseeded runs

An opt-in `derandomize` mode will derive the seed from the property id. This
makes unseeded CI runs repeatable while preserving explicit seeds as the
highest-priority source of reproducibility.

### Selectable run phases

Examples, corpus replay, random generation, and shrinking will become
individually selectable phases. This enables fast corpus-and-example checks on
pull requests without deleting the corpus or changing the default full run.

### Machine-readable distributions

Classification counts, coverage requirements, and discard statistics will be
available as structured data. Adapters will keep their human-readable output,
while listeners and CI integrations can consume the report without parsing
console text.

### Swarm generation

`Gen::swarm()` will run a choice-based generator with a non-empty subset of its
available variants. It is aimed particularly at state-machine tests where bugs
often require an operation to be absent from a generated command sequence.

### IPv6 generation

`Gen::ipv6()` will generate canonical RFC 5952 addresses and shrink toward
simple compressed forms such as `::` and `::1`. IPv4-mapped addresses, zone
identifiers, and bracketed URL forms are outside the first version.

### Stable property ids for PHPUnit

The PHPUnit adapter will accept an explicit property id. This provides stable
event and corpus keys for properties invoked from closures, including Pest
tests, where a backtrace-derived id may collide or depend on a source line.

## Adapter delivery

Core configuration added in 0.2 will be exposed consistently by the Testo and
PHPUnit adapters. The planned environment contract adds `PROPERTY_PATH`,
`PROPERTY_DERANDOMIZE`, and `PROPERTY_PHASES`; the PHPUnit fluent API will gain
the corresponding methods. Adapter releases follow the core release and run
against it in the shared adapter contract suite.

The PHPUnit adapter's explicit-id work does not depend on core 0.2 and may ship
earlier as its own additive release.

## Delivery sequence

Each item is intended to land as a separate pull request with a green build:

1. shrinking modes and a shrinking time budget;
2. deterministic unseeded runs;
3. selectable phases;
4. seed-and-path replay;
5. machine-readable distribution reports;
6. swarm generation;
7. IPv6 generation;
8. release documentation and executable examples;
9. matching Testo and PHPUnit adapter releases.

The independent generator work may proceed in parallel. Ordering is otherwise
chosen so that no pull request depends on a later one.

## Release criteria

The 0.2 release is ready when:

- the full build and mutation gates pass without ignored mutants;
- seed determinism vectors remain unchanged;
- a corpus written by 0.1 is read by 0.2 in a golden compatibility test;
- every feature has an executable example;
- package audit and workflow security checks are clean;
- English and Russian READMEs and `llms.txt` describe every new control;
- the documentation build and executable cookbook checks pass;
- published-package smoke tests pass for core and both adapters.

## Beyond 0.2

Targeted property testing and coverage-guided search are candidates for 0.3 or
later. They require an adaptive example database for valuable passing inputs,
scoring and eviction policies, and a separate search phase. They are not
treated as another backend for the current falsification-only corpus.

Potential coverage feedback will remain adapter-owned so that core does not
depend on PCOV or Xdebug. A future search tape may support replay and mutation
of generator decisions, but it will not replace the integrated rose-tree
shrinking model.

A dedicated Pest package is also deferred. Pest can already use the PHPUnit
adapter's trait; a separate integration will be considered only after stable
explicit property ids are available and without relying on Pest internals.
