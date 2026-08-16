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
different input under the guise of a regression). A seed entry also records how
many runs the recorded failure survived, and its replay extends a lowered run
count up to that attempt — otherwise the replay would pass early and prune a
regression that is still alive. A values entry is also dropped
when the property's signature no longer matches the recorded argument names — a
renamed or added parameter makes the stored input a different input.

Storage is one small JSON file per property (`<sha1(id)>.json`, at most 8 values
entries and 2 seed entries, oldest evicted first); add the directory to
`.gitignore`.

## The corpus as a CI artifact

A corpus is the only memory a property has between runs, and CI is where most
falsifications happen — on a machine that is destroyed when the job ends. Left
alone, the counterexample CI just found dies with the runner, and the property
starts from nothing on the next push.

On GitHub Actions, three steps fix that. They look redundant and are not: each
one exists because of a specific way this silently fails.

```yaml
- name: Restore property regression corpus
  uses: actions/cache/restore@<sha> # vN
  with:
    path: build/property-db
    key: property-db-${{ github.run_id }}-${{ github.run_attempt }}
    restore-keys: property-db-

- name: Test
  env:
    PROPERTY_DB: ${{ github.workspace }}/build/property-db
  run: composer test

- name: Save property regression corpus
  if: ${{ !cancelled() }}
  uses: actions/cache/save@<sha> # vN
  with:
    path: build/property-db
    key: property-db-${{ github.run_id }}-${{ github.run_attempt }}
```

**`restore` and `save` are split, not combined.** The combined `actions/cache`
action declares `post-if: success()`, so its save step does not run on a red
job — and a red job is exactly when the corpus was written. The failing run
records the counterexample, the job fails, the save never happens, and the one
scenario the corpus exists for is the one it misses. (`save-always: true` was
the upstream workaround and is deprecated, with splitting the actions as the
recommended replacement.)

**`run_attempt` belongs in the key.** On a re-run, `github.run_id` is
unchanged; without the attempt the key already exists, `save` reports
`Cache already exists` and writes nothing.

**`restore-keys` is what carries the corpus forward.** The exact key is unique
per attempt, so it never hits; the prefix falls back to the most recent
previous cache. Without the restore step the corpus only survives *within* one
job, not across pushes.

## Sharing a corpus between CI and a developer

A cache is per-repository and one-directional in practice: CI writes it, and
nobody's laptop reads it. Two patterns go further, and they answer different
questions.

| Pattern | Who reads it | What it is good for |
|---|---|---|
| Cache (above) | the next CI run | a falsification found in CI keeps being replayed there |
| Committed fixture | everyone, forever | a specific counterexample worth keeping under review, copied into `tests/fixtures/` |
| Shared store | CI and developers | one corpus for a team, so a failure found anywhere is replayed everywhere |

The committed fixture is the honest way to keep one important counterexample:
copy the JSON document out of the corpus directory into the repository, and it
becomes a reviewable file that a code review can see. It is also the only one
of the three that survives a cache eviction.

::: warning What not to commit
The same rule as above, sharpened by review: a values entry *is* generator
output. A corpus from a property whose generators touch a real value space
does not belong in a repository, a cache that other jobs can read, or a store
shared beyond the people who may see that data.
:::

::: warning Corpus inherits your inputs' sensitivity
`PROPERTY_DB` writes falsifying arguments to disk verbatim — a values entry
*is* generator output, not synthetic noise. If a property's generators can
produce credential-shaped or personal data (fed real fixtures, wrapping a
production value space), the corpus directory can too. Don't make it
world-readable, and don't commit it alongside a property like that. See
[Security](/guide/security).
:::
