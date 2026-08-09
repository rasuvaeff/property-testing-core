---
title: "Explicit examples"
description: "Pinning known regression inputs with #[Property(examples: ...)] — they run before the random phase and never shrink."
---

# Explicit examples

Fixed inputs pin a found bug as a permanent case that runs on every invocation,
alongside the random ones. Declare a `<testMethod>Examples` method (or name one
via `#[Property(examples: 'method')]`) returning positional argument tuples; each
runs **before** the random inputs and is reported verbatim (not shrunk — it is
already the minimal case you pinned) via `ExampleViolationException`.

```php
#[Test]
#[Property(generators: 'ints')]
public function additionCommutes(int $a, int $b): void
{
    Assert::same($a + $b, $b + $a);
}

/** @return list<array{int, int}> */
public static function additionCommutesExamples(): array
{
    return [[0, 0], [PHP_INT_MAX, 1]]; // regressions that must always run
}
```
