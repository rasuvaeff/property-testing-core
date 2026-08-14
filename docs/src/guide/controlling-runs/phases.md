---
title: "Run phases"
description: "Selecting which stages of a property run actually execute, so a pull request can replay only the corpus and the pinned examples instead of the full random phase."
---

# Run phases

A run has four stages, in this order: the pinned examples, the regression
corpus replay, the random phase, and the shrink descent. They are a set, not a
fixed sequence — `PropertyConfig` takes the ones to perform:

```php
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;

new PropertyConfig(phases: [Phase::Examples, Phase::Corpus]);  // examples and corpus only
new PropertyConfig();                                          // every phase — the default
```

The first of those runs in seconds where the full set runs in minutes, and it
serves two purposes. One is a pull-request gate that replays only what is
already known to have failed, leaving the full random phase to a nightly job.
The other is an honest measurement of a property with corpus replay off — which
until now meant deleting the corpus directory, and so losing the corpus.

## Rules

| Rule | Behaviour |
|---|---|
| An empty phase set | `InvalidArgumentException`: a run with no stages has nothing to report |
| A set without `Phase::Shrink` | Exactly `ShrinkMode::Off` — one behaviour, one implementation, and the stricter of the two knobs always wins |
| `Phase::Corpus` | Gates corpus **replay** only. Both it and `PropertyDefinition::$replayRegressions` must allow the replay, and neither stops a fresh falsification from being recorded: storing is not a stage |
| A set without `Phase::Random` | Nothing is generated: `attempts: 0`, `checks: 0`, and `Passed` once the enabled earlier phases pass |
| A phase set holding anything but a `Phase` | `InvalidArgumentException`: an unrecognised stage would simply not run, and the property would report green having checked nothing |

That second-to-last row is the one to read twice. With no random phase the
statistics report zeros rather than the configured run count, and coverage
requirements are dropped instead of being assessed against an empty denominator
— a `Classify::cover()` gate cannot be satisfied by runs that never happened,
and failing on that would make the fast gate unusable on any property that has
one. `Passed` is the result of the phases that did run, not a shortcut around
them: a pinned example or a corpus entry that fails still reports
`ExampleFailed` or `RegressionFailed`, exactly as it would in a full run.

## Where to set it

These are engine-level knobs on `PropertyConfig`, so they are available to any
caller that builds a `PropertyDefinition` itself — see
[the standalone runner](/guide/sampling-and-export). Exposure through the Testo
attribute and the PHPUnit fluent API arrives with the adapter releases that
follow this one.
