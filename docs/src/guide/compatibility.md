---
title: Compatibility policy
description: "What a version number promises in the property-testing family: the public API surface, seed stability, the corpus format, message texts, events, constructors, and the family's release order."
---

# Compatibility policy

What a version number promises, so a minor upgrade is a decision you can make
without reading a diff.

## 1. Scope

The public API is every type marked `@api`. Types marked `@internal` are
implementation and may change in any release, including a patch — the
`Rasuvaeff\PropertyTesting\Internal` namespace above all.

## 2. Seed → values is not under SemVer

A minor release may shift what a given seed generates. When it does:

- [`FilesystemCorpus::SEQUENCE_EPOCH`](/api/classes/Runner/FilesystemCorpus) is
  bumped in the same release, so seed entries recorded under an older epoch are
  dropped rather than replayed as a different input;
- the change is named in the changelog.

Values entries — the minimised input stored as data — survive every release,
which is what makes the corpus the durable half of a regression.

[`CounterExample::$path`](/api/classes/CounterExample) is a debugging aid, not
a durable identifier: it indexes into each node's shrink candidates, so editing
a generator orphans it.

This is the clause that has already been exercised. Core 0.6.0 changed the
distribution of `Gen::string()` and bumped the epoch to 2; 0.5.0 changed the
order of list shrink candidates. Both were minors, and both were correct — an
engine that could never improve a generator's distribution would be frozen at
its first mistake.

## 3. Corpus format

`FilesystemCorpus::FORMAT_VERSION` does not change within 1.x. The document
grows only by optional fields, and a document written by any 0.x release — or
by the frozen `rasuvaeff/property-testing` 2.8 — stays readable.
[`RedisCorpus`](/api/classes/Runner/RedisCorpus) writes the byte-identical
document, so moving a corpus between the two backends is a copy.

## 4. Message texts may be reworded; the machine-readable form may not

Human-readable exception and warning texts are prose for a developer reading a
red run, not a parsing surface. They may be reworded in a minor, with the
change named in the changelog. The adapters' golden-message suites pin them as
characterizations, which is what makes such a rewording visible rather than
silent.

Frozen instead:

- the keys of [`CounterExample::toArray()`](/api/classes/CounterExample) and
  `toJson()`;
- the keys of
  [`DistributionReport::toArray()`](/api/classes/Runner/DistributionReport);
- the fields of every `@api` result and event.

## 5. Events

| Change | Release |
|---|---|
| A new event type | minor |
| A new field at the end of an existing event | minor |
| A new `PropertyResult` implementation | minor — consumers must carry a default branch |
| Removing an event or a field | major |
| Reordering the sequence emitted for an existing outcome | major |

## 6. Constructors are append-only

Constructors of `@api` `final readonly` classes take new parameters with
defaults, at the end. Positional construction of
[`CounterExample`](/api/classes/CounterExample),
[`PropertyConfig`](/api/classes/Runner/PropertyConfig),
[`RunStatistics`](/api/classes/Runner/RunStatistics) and the events keeps
working across a minor.

## 7. The family moves together

The adapters (`rasuvaeff/property-testing-testo`,
`rasuvaeff/property-testing-phpunit`) and `rasuvaeff/property-testing-names`
require the engine with a caret range on its current major. A major here is a
major there, and the release order is fixed: engine first, then the adapters,
then `-names`.

## 8. PHP

`8.3 - 8.5`. Support for a newer PHP minor ships as a patch that widens the
constraint. Dropping a PHP version is a major.
