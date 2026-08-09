---
title: Deterministic hash bucketing
description: "Would have caught: a rollout hash salted with the rollout percentage, so raising the percentage could remove an already-included subject."
---

# Deterministic hash bucketing

::: tip Would have caught, not "caught here"
See [Cookbook](/cookbook/) for what that distinction means and how it was
verified.
:::

## The bug

`yii3-ab-testing` assigns subjects to a percentage rollout by hashing the
subject id and comparing against the target percentage. An early
implementation folded the percentage itself into the hash input:

```php
function inRollout(string $subject, int $percent): bool
{
    return (crc32($subject . ':' . $percent) % 100) < $percent;
}
```

Salting the hash with `$percent` means the bucket a subject falls into
*changes* every time the rollout percentage changes — the whole point of
percentage-based rollout is the opposite: raising the percentage should only
ever **add** subjects to the included set, never remove one that was already
in. A subject included at 50% falling back out at 60% turns a monotone
ramp-up into a reshuffle, and re-exposes users who were supposed to stay in
a treatment group throughout the rollout.

## Why the unit test stayed green

```php
Assert::true(inRollout('user-42', 100));
Assert::false(inRollout('user-42', 0));
```

Both ends of the range are trivially correct for any hash function — the bug
only appears when comparing the same subject across two *different*
intermediate percentages, a pair nobody thinks to assert on by hand.

## The property

`p2` is built from `p1` plus a non-negative delta — clamped to 100 — rather
than generated independently and filtered down to `p2 >= p1`: constructing
the ordering directly instead of discarding draws that don't have it (root
`AGENTS.md`, "конструировать, не фильтровать"):

```php
$monotoneInPercent = static function (string $subject, int $p1, int $delta): void {
    $p2 = min(100, $p1 + $delta);

    if (inRollout($subject, $p1) && !inRollout($subject, $p2)) {
        throw new RuntimeException(sprintf(
            'subject %s is in the %d%% rollout but not the %d%% rollout',
            var_export($subject, true),
            $p1,
            $p2,
        ));
    }
};
```

Full runnable script:
[`examples/case-studies/hash-bucketing.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/case-studies/hash-bucketing.php).

## Runner output

```
Buggy rollout bucketing falsified:

Property falsified after 22 successful run(s); seed=5
  Original: subject="opcqcb45ni5", p1=16, delta=30
  Shrunk:   subject="opcqcbaani5", p1=16, delta=1 (8 shrink step(s), 129 trial(s))
  Changed:  subject="opcqcb45ni5" -> "opcqcbaani5", delta=30 -> 1
  Failure:  subject 'opcqcbaani5' is in the 16% rollout but not the 17% rollout
```

Twenty-two passing runs, then a subject/percentage pair that violates
monotonicity. The shrinker gets there fastest by collapsing `delta` down to
`1` — a one-percentage-point difference is already enough to flip this
subject's bucket, since the salted hash gives it an entirely unrelated value
at each percentage.

## The fix

```diff
  function inRollout(string $subject, int $percent): bool
  {
-     return (crc32($subject . ':' . $percent) % 100) < $percent;
+     return (crc32($subject) % 100) < $percent;
  }
```

Hash the subject alone. The bucket a subject falls into is then a fixed
number in `[0, 100)`, decided once — raising `$percent` only ever grows the
set of subjects whose bucket falls below it.
