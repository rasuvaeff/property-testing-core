---
title: "Getting started"
description: "Install a property-testing adapter and write your first #[Property] test — requirements, installation, and the generator-method convention."
---

# Getting started

## Which package to install

Property-testing is three packages, not one. Install the engine plus exactly
the adapter that matches your test suite:

| You test with | Install | Attribute / API |
|---|---|---|
| [Testo](https://php-testo.github.io/) | `composer require --dev rasuvaeff/property-testing-testo` | `#[Property]` — this page's examples |
| PHPUnit | `composer require --dev rasuvaeff/property-testing-phpunit` | a fluent `forAll()->check()` trait — see [PHPUnit adapter](/adapters/phpunit) |
| Neither / a custom harness | `composer require --dev rasuvaeff/property-testing-core` | drive `PropertyRunner` directly — see [Testo adapter → driving the engine directly](/adapters/testo) |

Each adapter pulls in `rasuvaeff/property-testing-core` itself, so you never
install it by hand alongside an adapter. `composer why testo/testo` on a
core-only install reports nothing — the engine has no framework dependency.

## Requirements

- PHP 8.3+
- `ext-mbstring`
- `ext-random`

## Installation

```bash
composer require --dev rasuvaeff/property-testing-testo
```

No plugin registration is needed: the `#[Property]` attribute self-registers
with Testo through the framework's interceptor discovery.

## Usage

Mark a test method with `#[Property]` and point it at a generators method that
maps each parameter name to a `Gen` factory.
The runner generates random arguments, runs the property `runs` times, and on
the first failure shrinks the counterexample to a minimal one.

```php
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

#[Test]
final class RetryPolicyPropertyTest
{
    #[Property(runs: 500, generators: 'delayGenerators')]
    public function delayNeverExceedsCap(int $maxAttempts, int $baseSeconds, int $cap, int $attempts): void
    {
        Assume::that($cap >= $baseSeconds);

        $policy = WebhookRetryPolicy::exponential($maxAttempts, $baseSeconds, $cap);

        Assert::true($policy->nextDelaySeconds($attempts) <= $cap);
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function delayGenerators(): array
    {
        return [
            'maxAttempts' => Gen::intBetween(1, 50),
            'baseSeconds' => Gen::intBetween(1, 300),
            'cap' => Gen::intBetween(1, 86400),
            'attempts' => Gen::intBetween(1, 100),
        ];
    }
}
```

On failure, the counterexample is rendered into the test output:

```
Property falsified after 246 successful run(s); seed=7382910
  Original: maxAttempts=17, baseSeconds=91, cap=847, attempts=23
  Shrunk:   maxAttempts=1, baseSeconds=848, cap=847, attempts=1 (12 shrink step(s), 41 trial(s))
  Changed:  maxAttempts=17 -> 1, baseSeconds=91 -> 848, attempts=23 -> 1
```

The `Changed:` line diffs the original against the shrunk counterexample —
arguments the shrinker left untouched (here `cap`) are omitted, so the inputs
that actually drive the failure stand out. `trial(s)` counts every candidate
the shrinker ran (accepted and rejected); `shrink step(s)` counts only the
accepted ones.

Reproduce the exact run by passing the reported seed back to the attribute:

```php
#[Property(runs: 500, seed: 7382910, generators: 'delayGenerators')]
```

Everything past this point on the site — generators, shrinking, the
regression corpus, distribution checks, state machines — is engine behavior
shared identically by every adapter. Code samples keep using `#[Property]`
for readability; swap it for the [PHPUnit trait](/adapters/phpunit) and the
same generators, the same shrink trees, and the same counterexample format
apply unchanged.
