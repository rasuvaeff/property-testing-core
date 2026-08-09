---
title: "Bounding shrink work"
description: "Capping how long the shrinker searches for a minimal counterexample, so a pathological failing input can't turn a property run into a hang."
---

# Bounding shrink work

By default shrinking runs until no smaller candidate still fails, re-running the
property once per accepted step. On expensive properties or very large inputs you
can cap the number of accepted shrink steps with `maxShrinks`:

```php
#[Property(runs: 200, maxShrinks: 25)]
```

`maxShrinks: null` (the default) means no cap. `maxShrinks: 0` disables shrinking
entirely and reports the original counterexample unchanged. The cap counts
*accepted* shrink steps, not test executions.
