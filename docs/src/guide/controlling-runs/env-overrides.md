---
title: "Environment overrides"
description: "Environment variables that override a property's runs, seed, phases, boundary mode, shrink path and regression corpus at the command line, without editing the test."
---

# Environment overrides

Nine environment variables tune runs without touching the attributes — useful
in CI. They are resolved by the adapter (Testo's interceptor, PHPUnit's
trait), not the engine itself: `PropertyRunner` never reads the process
environment, so a custom harness driving it directly opts into the same table
only if it chooses to. Core supplies the parsing —
[`EnvironmentOverrides`](/api/classes/Runner/EnvironmentOverrides) and
[`CorpusFactory`](/api/classes/Runner/CorpusFactory) — so both adapters mean
the same thing by the same value.

| Variable | Effect |
|---|---|
| `PROPERTY_RUNS` | Positive integer that overrides every property's run count (dial runs up in CI). |
| `PROPERTY_SEED` | Integer seed used for any property whose attribute omits `seed` (replay a whole suite). An explicit attribute `seed` still wins. |
| `PROPERTY_VERBOSE` | Logs every run's generated arguments and, on failure, every accepted shrink step (`shrink step 3: x=63 -> 51`) — see exactly what a replayed seed feeds the property and how the shrinker descends. |
| `PROPERTY_DERANDOMIZE` | Derives an unset seed from the property id instead of drawing one, so a locally found bug reproduces in CI before any corpus entry exists. See [Derandomized runs](/guide/controlling-runs/derandomize). |
| `PROPERTY_PHASES` | Comma-separated subset of `examples`, `corpus`, `random`, `shrink` — the same set as `PropertyConfig::$phases`. An unknown name is an error, not a silently skipped phase. See [Run phases](/guide/controlling-runs/phases). |
| `PROPERTY_EDGE_CASES` | `mixin` (default) or `none`; `none` turns off the numeric boundary bias without shifting the sequence a seed produces. See [Boundary bias](/guide/generators/boundary-bias). |
| `PROPERTY_PATH` | Replays a recorded shrink descent instead of searching for it. Requires `PROPERTY_SEED` (or an attribute `seed`) — a path without the seed it was recorded on is refused rather than applied to a different run. See [Replaying a shrink path](/guide/controlling-runs/replaying-a-path). |
| `PROPERTY_DB` | Enables the regression corpus: a directory path, or `redis://host[:port][/db][?prefix=key-prefix&timeout=seconds]` (`rediss://` for TLS) for a store shared between CI and developers. Any other scheme is an error. Unset means the feature is off and nothing is written. See [Regression corpus](/guide/regression-corpus). |
| `PROPERTY_DB_PASSWORD` | The `AUTH` password for a Redis `PROPERTY_DB`. Kept out of the DSN on purpose: `PROPERTY_DB` is echoed in the diagnostics and lands in CI logs, and credentials in the userinfo of a DSN are refused outright. |

## Unset, empty, and "off"

Unset and empty both mean "not given": the attribute's own value stands. For
the two flags — `PROPERTY_VERBOSE` and `PROPERTY_DERANDOMIZE` — `0`, `false`,
`off` and `no` (case-insensitively, surrounding whitespace ignored) turn the
feature off, and any other value turns it on. A shell exports words as readily
as digits, and `PROPERTY_VERBOSE=false` enabling verbose output is a surprise
nobody needs.

A malformed value is an error naming the variable, never a fallback to the
default: `PROPERTY_RUNS=abc` stops the suite instead of quietly running 100
times.

```bash
PROPERTY_RUNS=10000 vendor/bin/testo
PROPERTY_SEED=1712345678 PROPERTY_VERBOSE=1 vendor/bin/testo
PROPERTY_PHASES=examples,corpus vendor/bin/phpunit
PROPERTY_DB=build/property-db vendor/bin/testo
PROPERTY_DB=redis://redis:6379/2?prefix=suite-a: PROPERTY_DB_PASSWORD=… vendor/bin/testo
```
