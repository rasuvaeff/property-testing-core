---
title: "Examples"
description: "Runnable example scripts covering generators, shrinking, state machines and regression corpus replay, one topic each."
---

# Examples

Every package ships its own `examples/`, runnable after `composer install`
via the `composer:2` Docker image (no PHP/Composer needed on the host).

### [`property-testing-core`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/)

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | A property that holds, one that is falsified, and how the counterexample is shrunk by descending the `Shrinkable` tree (uses generators directly, no runner) | No |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, and dependent generation with `flatMap` (uses generators directly, no runner) | No |
| `standalone_runner.php` | Driving the framework-agnostic engine directly: a hand-built `PropertyDefinition`, `CallableTrialExecutor`, and structured `PropertyResult` inspection | No |
| `custom_listeners.php` | Custom observers over the engine's event model: a console reporter narrating the shrink descent and a telemetry collector aggregating run counts, timings and labels — pure `PropertyListener` implementations, no engine changes | No |

### [`property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo/blob/master/examples/)

| Script | Shows | Needs server? |
|---|---|---|
| `property_test.php` | Canonical `#[Property]` usage as a real Testo test case, including an in-body dependent draw with `Gen::draw()` | No |
| `state_machine.php` | Stateful / model-based testing: a `Command` interface, `Gen::commands()`, and `StateMachine::check()` driving command sequences against a stack | No |

### [`property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit/blob/master/examples/)

| Example | Shows | Needs server? |
|---|---|---|
| `SortPropertyTest.php` | A complete property-based PHPUnit `TestCase`: the `PropertyTesting` trait, the fluent `forAll()->runs()->check()` chain, `Classify::when()` distribution labels, and an `Assume::that()` discard — three properties over a plain sort | No |
