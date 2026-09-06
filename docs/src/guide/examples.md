---
title: "Examples"
description: "Runnable example scripts covering generators, shrinking, state machines and regression corpus replay, one topic each."
---

# Examples

Every package ships its own `examples/`, runnable after `composer install`
via the `composer:2` Docker image (no PHP/Composer needed on the host).

### [`property-testing-core`](https://github.com/rasuvaeff/property-testing-core/tree/master/examples)

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | A property that holds, one that is falsified, and how the counterexample is shrunk by descending the `Shrinkable` tree (uses generators directly, no runner) | No |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, and dependent generation with `flatMap` (uses generators directly, no runner) | No |
| `standalone_runner.php` | Driving the framework-agnostic engine directly: a hand-built `PropertyDefinition`, `CallableTrialExecutor`, structured `PropertyResult` inspection, and the run knobs — `ShrinkMode::Off`, `path` replay, the `DistributionReport` a listener reads off `PropertyFinished`, and `phases: [Phase::Examples, Phase::Corpus]` as a fast gate | No |
| `for_class.php` | `Gen::forClass()`: the same shape with and without psalm annotations, an override narrowing one parameter, and a validating constructor both ways (throwing by default, `skipInvalid: true` discarding) | No |
| `swarm.php` | Swarm testing: how often a case avoids an operation entirely with a uniform alphabet versus a per-case subset, `Gen::swarm()` over a choice generator and over `Gen::commands()`, and a shrink descent that stays inside its subset | No |
| `custom_listeners.php` | Custom observers over the engine's event model: a console reporter narrating the shrink descent and a telemetry collector aggregating run counts, timings and labels — pure `PropertyListener` implementations, no engine changes | No |
| `case-studies/regex-anchor.php` | [Cookbook](/cookbook/regex-anchor) case study: a `$`-anchored identifier validator accepting a trailing newline | No |
| `case-studies/saturating-minus.php` | [Cookbook](/cookbook/saturating-minus) case study: subtraction producing a negative duration instead of saturating at zero | No |
| `case-studies/backoff-cap.php` | [Cookbook](/cookbook/backoff-cap) case study: jitter added after the cap was applied, pushing the delay past it | No |
| `case-studies/hash-bucketing.php` | [Cookbook](/cookbook/hash-bucketing) case study: a rollout hash salted with the percentage, breaking monotonicity | No |
| `case-studies/faker-vs-property.php` | [Cookbook](/cookbook/faker-vs-property) comparison: the same UTF-8 truncation bug found with realistic (Faker-shaped) data and with shrinkable data — both falsify, only one minimises | No |

### [`property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo/tree/master/examples)

| Script | Shows | Needs server? |
|---|---|---|
| `property_test.php` | Canonical `#[Property]` usage as a real Testo test case, including an in-body dependent draw with `Gen::draw()` and `auto: true` deriving generators from the signature | No |
| `state_machine.php` | Stateful / model-based testing: a `Command` interface, `Gen::commands()`, and `StateMachine::check()` driving command sequences against a stack | No |

### [`property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit/tree/master/examples)

| Example | Shows | Needs server? |
|---|---|---|
| `SortPropertyTest.php` | A complete property-based PHPUnit `TestCase`: the `PropertyTesting` trait, the fluent `forAll()->runs()->check()` chain, `Classify::when()` distribution labels, an `Assume::that()` discard, and `auto()` deriving generators from the closure — four properties over a plain sort | No |
