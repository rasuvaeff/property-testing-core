# Examples

Runnable scripts demonstrating `rasuvaeff/property-testing-core`.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | A property that holds, one that is falsified, and how the counterexample is shrunk by descending the `Shrinkable` tree (uses generators directly, no runner) | No |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, and dependent generation with `flatMap` (uses generators directly, no runner) | No |
| `standalone_runner.php` | Driving the framework-agnostic engine directly: a hand-built `PropertyDefinition`, `CallableTrialExecutor`, structured `PropertyResult` inspection, and the run knobs — `ShrinkMode::Off` reporting a counterexample as generated, `path` replaying a recorded descent (and refusing a stale one), the `DistributionReport` a listener reads off `PropertyFinished`, and `phases: [Phase::Examples, Phase::Corpus]` as a fast gate that never reaches the random phase | No |
| `swarm.php` | Swarm testing: how often a case avoids an operation entirely with a uniform alphabet versus a per-case subset, `Gen::swarm()` over a choice generator and over `Gen::commands()`, and a shrink descent that stays inside the subset it came from | No |
| `custom_listeners.php` | Custom observers over the engine's event model: a console reporter narrating the shrink descent and a telemetry collector aggregating run counts, timings and labels — pure `PropertyListener` implementations, no engine changes | No |
| `case-studies/regex-anchor.php` | Docs cookbook case study: a `$`-anchored identifier validator accepting a trailing newline (ER-001) | No |
| `case-studies/saturating-minus.php` | Docs cookbook case study: subtraction producing a negative duration instead of saturating at zero | No |
| `case-studies/backoff-cap.php` | Docs cookbook case study: jitter added after the cap was applied, pushing the delay past it | No |
| `case-studies/hash-bucketing.php` | Docs cookbook case study: a rollout hash salted with the percentage, breaking monotonicity across percentage changes | No |

`case-studies/` backs the [documentation site's Cookbook](https://rasuvaeff.github.io/property-testing-core/cookbook/)
— each script's buggy code is reconstructed inline from a real incident
(never imported from the package it originally happened in) and pinned to a
fixed seed, so the counterexample quoted on the corresponding page stays
reproducible.

## Running

The examples are plain PHP scripts that load the package via Composer autoload.
Run them from the package root after `composer install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic.php
```
