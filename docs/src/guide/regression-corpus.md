---
title: "Regression corpus"
description: "PROPERTY_DB replays every past falsifying input before the random phase, so a fixed bug can never silently come back."
---

# Regression corpus

Set `PROPERTY_DB` to a directory and every falsified property records its failure
there. On the next run the recorded failures are replayed **first** (unless the
attribute pins its own `seed`): one that still fails is reported immediately for
fast feedback, one that no longer fails — or that the property now discards via
`Assume::that()` — is pruned. A property accumulates several past failures, so
fixing the newest one does not lose the older ones.

A failure is recorded in one of two ways:

| Entry | When | Replay | Reported as |
|---|---|---|---|
| Values | Every minimised argument is representable as data: `null`, scalars, arrays, enum cases, byte strings | One run with the exact recorded input | `RegressionViolationException` |
| Seed | Anything else — objects, closures, or in-body `Gen::draw()` values in the counterexample | The whole random phase, re-run with that seed | `PropertyViolationException` |

Values entries are preferred: they cost a single run, and they keep working when
the generation sequence shifts, because they carry the input rather than a
recipe for regenerating it. Seed entries are the fallback and are dropped when
the package's generation sequence changes (they would otherwise replay a
different input under the guise of a regression). A values entry is also dropped
when the property's signature no longer matches the recorded argument names — a
renamed or added parameter makes the stored input a different input.

Storage is one small JSON file per property (`<sha1(id)>.json`, at most 8 values
entries and 2 seed entries, oldest evicted first); add the directory to
`.gitignore`.

::: warning Corpus inherits your inputs' sensitivity
`PROPERTY_DB` writes falsifying arguments to disk verbatim — a values entry
*is* generator output, not synthetic noise. If a property's generators can
produce credential-shaped or personal data (fed real fixtures, wrapping a
production value space), the corpus directory can too. Don't make it
world-readable, and don't commit it alongside a property like that. See
[Security](/guide/security).
:::
