---
title: "Deadlines and time budgets"
description: "timeoutMs and budgetMs turn a pathological generated input (catastrophic regex, deep recursion) into a reported property failure instead of a hang."
---

# Deadlines and time budgets

Pathological inputs (catastrophic regex, deep recursion, unbounded backoff)
show up as time, not as assertion failures. Two opt-in wall-clock caps turn
them into reported failures:

```php
#[Property(runs: 200, timeoutMs: 100, budgetMs: 5_000)]
```

- `timeoutMs` — deadline for a **single run** (random or explicit example). A
  body that takes longer fails the property with a `DeadlineExceededException`
  naming the offending input and the measured time. The input is reported
  as-is, not shrunk (shrink acceptance would have to re-measure wall time, and
  timing noise makes that descent non-deterministic). The run is measured
  after it returns — a body that never returns cannot be interrupted in
  synchronous PHP.
- `budgetMs` — budget for the **whole random phase**. When it runs out before
  `runs` successful checks complete, the property fails with a
  `TimeBudgetExceededException` exposing the completed/required counts, so a
  slow property cannot silently check less than it claims.

Both default to `null` (disabled). An assertion failure in a slow run wins
over the deadline — the falsified counterexample is the actionable signal.
