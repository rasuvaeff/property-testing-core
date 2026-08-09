---
title: "Assume::that() vs Gen::filter()"
description: "When to discard a generated run with Assume::that() versus constructing valid input directly or filtering it with Gen::filter()."
---

# Assume::that() vs Gen::filter()

## `Assume::that()`

Discards the current attempt when a precondition does not hold. `runs` is the
number of successful checks, so discarded attempts are replaced. Prefer
`Assume::that()` over `Gen::filter()` when the rejection rate is low; when more
than 90% of attempts are discarded the runner warns that the generators are
likely misconfigured.

```php
Assume::that($cap >= $baseSeconds);
```

Retries are bounded by `maxDiscards` (default: `runs * 10`). Exceeding the budget
fails with `GaveUpException`, whose public fields report required and successful
runs, discarded attempts, total attempts and the budget. Override it when a
legitimate domain is sparse:

```php
#[Property(runs: 200, maxDiscards: 5_000)]
```

Construct valid inputs (`Gen::flatMap()` / `Gen::draw()`) instead of raising the
budget when the relationship can be encoded directly.
