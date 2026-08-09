# Examples

Runnable scripts demonstrating `rasuvaeff/property-testing-core`.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | A property that holds, one that is falsified, and how the counterexample is shrunk by descending the `Shrinkable` tree (uses generators directly, no runner) | No |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, and dependent generation with `flatMap` (uses generators directly, no runner) | No |
| `standalone_runner.php` | Driving the framework-agnostic engine directly: a hand-built `PropertyDefinition`, `CallableTrialExecutor`, and structured `PropertyResult` inspection | No |
| `custom_listeners.php` | Custom observers over the engine's event model: a console reporter narrating the shrink descent and a telemetry collector aggregating run counts, timings and labels — pure `PropertyListener` implementations, no engine changes | No |

## Running

The examples are plain PHP scripts that load the package via Composer autoload.
Run them from the package root after `composer install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic.php
```
