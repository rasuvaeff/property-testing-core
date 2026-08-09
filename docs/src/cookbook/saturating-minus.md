---
title: Saturating subtraction
description: "Would have caught: Duration subtraction going negative in microseconds."
---

# Saturating subtraction

::: tip Would have caught, not "caught here"
See [Cookbook](/cookbook/) for what that distinction means and how it was
verified.
:::

## The bug

[`rasuvaeff/duration`](https://github.com/rasuvaeff/duration) models a
duration as an immutable microsecond count. An early `minus()` implementation
subtracted directly:

```php
public function minus(self $other): self
{
    return new self($this->micros - $other->micros);
}
```

Subtracting a larger duration from a smaller one produces a `Duration`
holding a **negative** microsecond count — a value with no sensible meaning
for something that is supposed to represent an elapsed amount of time.
Anything downstream that assumes `Duration::micros >= 0` (formatting,
comparisons, further arithmetic) silently inherits the bad value.

## Why the unit test stayed green

```php
Assert::same(500, (new Duration(1_500))->minus(new Duration(1_000))->micros);
```

The one example anyone hand-writes for a `minus()` test is the case where
the result is obviously positive — nobody sits down and asks "what if the
right-hand side is bigger?" until it happens in production.

## The property

```php
$staysNonNegative = static function (int $a, int $b): void {
    $result = (new BuggyDuration($a))->minus(new BuggyDuration($b))->micros;

    if ($result < 0) {
        throw new RuntimeException(sprintf(
            'Duration(%d)->minus(Duration(%d)) produced %d micros, expected >= 0',
            $a,
            $b,
            $result,
        ));
    }
};
```

`$a` and `$b` are independent generated microsecond counts — no need to
construct or discard anything, since a negative result is exactly what makes
`$b > $a` interesting.

Full runnable script:
[`examples/case-studies/saturating-minus.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/case-studies/saturating-minus.php).

## Runner output

```
Buggy Duration::minus() falsified:

Property falsified after 0 successful run(s); seed=7
  Original: a=1, b=369975285
  Shrunk:   a=0, b=1 (30 shrink step(s), 60 trial(s))
  Changed:  a=1 -> 0, b=369975285 -> 1
  Failure:  Duration(0)->minus(Duration(1)) produced -1 micros, expected >= 0
```

Zero successful runs — the very first draw already falsifies it, because
`b > a` is roughly half the input space. Thirty shrink steps collapse the
huge original pair down to the smallest possible violation: `0 - 1`.

## The fix

```diff
  public function minus(self $other): self
  {
-     return new self($this->micros - $other->micros);
+     return new self(max(0, $this->micros - $other->micros));
  }
```

Shipped in `rasuvaeff/duration` as the "saturating minus" behavior:
subtraction clamps at zero instead of going negative.
