---
title: "Environment overrides"
description: "Environment variables that override a property's runs/seed/timeout at the command line, without editing the test — useful for CI and local repro."
---

# Environment overrides

Four environment variables tune runs without touching the attributes — useful
in CI. They are resolved by the adapter (Testo's interceptor, PHPUnit's
trait), not the engine itself: `PropertyRunner` never reads the process
environment, so a custom harness driving it directly opts into the same table
only if it chooses to.

| Variable | Effect |
|---|---|
| `PROPERTY_RUNS` | Positive integer that overrides every property's run count (dial runs up in CI). |
| `PROPERTY_SEED` | Integer seed used for any property whose attribute omits `seed` (replay a whole suite). An explicit attribute `seed` still wins. |
| `PROPERTY_VERBOSE` | Any value except `''`/`0` logs every run's generated arguments and, on failure, every accepted shrink step (`shrink step 3: x=63 -> 51`) — see exactly what a replayed seed feeds the property and how the shrinker descends. |
| `PROPERTY_DB` | Directory path enabling the regression corpus (below). Unset means the feature is off and nothing is written. |
