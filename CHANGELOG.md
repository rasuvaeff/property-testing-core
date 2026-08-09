# Changelog

## Unreleased

- Added `MIGRATION.md`: the guide from the frozen `rasuvaeff/property-testing`
  2.x to this family — one Composer command for Testo projects, plus the
  custom-harness path (including where the `@internal` classes a harness used
  to reach for now live) and the PHPUnit path.
- CI: added the `Adapter contract suite` job — both adapter packages are
  checked out at their default branch, pointed at the core under review
  through a path repository, and their test suites run. Documentation and CI
  only; no library changes.

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
