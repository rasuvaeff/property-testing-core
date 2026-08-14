---
title: Backoff delay stays within its cap
description: "Would have caught: jitter pushing a retry delay past its configured cap."
---

# Backoff delay stays within its cap

::: tip Would have caught, not "caught here"
See [Cookbook](/cookbook/) for what that distinction means and how it was
verified.
:::

## The bug

[`rasuvaeff/retry`](https://github.com/rasuvaeff/retry)'s exponential
backoff caps the delay before a retry, so a misbehaving upstream can't push
a client into minutes-long waits. An early implementation applied the cap
**before** adding jitter:

```php
function delayMs(int $baseMs, int $capMs, float $jitterFactor): int
{
    $capped = min($baseMs, $capMs);
    $jitter = (int) round($capped * $jitterFactor);

    return $capped + $jitter;
}
```

`$capped` never exceeds `$capMs` on its own, but adding jitter on top of it
does — the operation the cap exists to bound happens *after* the cap is
applied, not before it.

## Why the unit test stayed green

```php
Assert::same(1_000, (new ExponentialBackoff(baseMs: 500, cap: 1_000))->delayMs(attempt: 10));
```

A fixed-seed test (or one with jitter mocked to zero) never exercises the
`+ jitter` line at all — the assertion only ever sees the capped base delay,
which is correctly bounded. The bug only shows up when jitter is nonzero,
which every hand-written example quietly avoids.

## The property

Every source of randomness — including the jitter factor — has to be a
generated parameter for the seed to reproduce a run; reaching for `mt_rand()`
inside the property body would defeat the pinned seed below (root
`AGENTS.md`'s "Property-based тесты" table lists exactly this shape under
"Монотонность / границы": "backoff-задержка ∈ [0, cap] и не убывает"):

```php
$staysWithinCap = static function (int $baseMs, int $capMs, float $jitterFactor): void {
    $delay = buggyDelayMs($baseMs, $capMs, $jitterFactor);

    if ($delay > $capMs) {
        throw new RuntimeException(sprintf(
            'delay %d exceeds cap %d (base=%d, jitter=%.4f)',
            $delay,
            $capMs,
            $baseMs,
            $jitterFactor,
        ));
    }
};
```

Full runnable script:
[`examples/case-studies/backoff-cap.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/case-studies/backoff-cap.php).

## Runner output

<!-- case-study-output: backoff-cap -->
```
Buggy backoff falsified:

Property falsified after 2 successful run(s); seed=17
  Original: baseMs=8397, capMs=11138, jitterFactor=0.47378904446907
  Shrunk:   baseMs=2, capMs=2, jitterFactor=0.47378904446907 (27 shrink step(s), 79 trial(s))
  Changed:  baseMs=8397 -> 2, capMs=11138 -> 2
  Failure:  buggyDelayMs(base=2, cap=2, jitter=0.4738) returned 3, expected <= cap 2
  Path:     baseMs:4/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:1/capMs:1/baseMs:2/capMs:1/baseMs:1/capMs:1/baseMs:1
```

Two passing runs — both happened to draw a near-zero jitter factor — then
the third falsifies it. The shrinker doesn't touch `jitterFactor` at all
(any nonzero jitter reproduces the bug); it collapses `baseMs`/`capMs` down
to the smallest pair where the cap is actually binding.

## The fix

```diff
  function delayMs(int $baseMs, int $capMs, float $jitterFactor): int
  {
-     $capped = min($baseMs, $capMs);
-     $jitter = (int) round($capped * $jitterFactor);
-
-     return $capped + $jitter;
+     $jittered = $baseMs + (int) round($baseMs * $jitterFactor);
+
+     return min($jittered, $capMs);
  }
```

Apply jitter first, cap the result second — the cap has to be the last
operation, not the first.
