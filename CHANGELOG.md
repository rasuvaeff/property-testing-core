# Changelog

## Unreleased

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
