---
title: Cookbook
description: "Real monorepo incidents, reconstructed as property tests — every page is labeled honestly as \"would have caught\", not \"caught here\"."
---

# Cookbook

Four real bugs from this monorepo's history, each rebuilt here as a small,
self-contained property. Every page follows the same shape:

1. the bug, in its original package;
2. the unit test that stayed green next to it, and why;
3. the property that falsifies it, in 8–12 lines;
4. real runner output — pasted from an actual execution of
   [`examples/case-studies/`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/case-studies/),
   not invented;
5. the one-line fix.

## Why "would have caught", not "caught here"

Every page here is honest about one thing: **none of these bugs was actually
caught by a property test at the time.** A search across this monorepo's
history — every package that has `property-testing` in `require-dev`, every
commit touching `src/` alongside a new `#[Property]`, every commit message
mentioning "counterexample", "shrink" or "falsif", every committed regression
corpus (there are none) — turned up no case of a property test finding a bug
before it shipped.

That search did surface one look-alike:
[`domain-monitor@546c1c9`](https://github.com/rasuvaeff/domain-monitor/commit/546c1c9)
fixes a PSR-7 bug (`withHeader(name: ...)` didn't match the real parameter
name `$header`) in the same commit that adds property tests — but the bug was
found while making the package's examples executable, and the property tests
in that commit check unrelated invariants. It is not a counterexample.

So each case study here is a **reconstruction**: the buggy code is
re-created from the real incident, the property is what *should* have run
against it, and the runner output below is real — captured by actually
running the script, with the seed pinned so the same counterexample keeps
reproducing. What's reconstructed is the timing, not the bug or the output.

## The four cases

| Case | Real incident | Property |
|---|---|---|
| [Regex accept/reject anchoring](/cookbook/regex-anchor) | ER-001 — an identifier validator anchored with `$` accepted a trailing newline | `$`-anchored and `\z`-anchored regexes must agree on every input |
| [Saturating subtraction](/cookbook/saturating-minus) | `duration`'s subtraction could produce a negative microsecond count | `a.minus(b)` is never negative |
| [Backoff delay stays within its cap](/cookbook/backoff-cap) | `retry`'s jitter was added after the cap was applied, so it could push the delay past it | `delay <= cap`, always |
| [Deterministic hash bucketing](/cookbook/hash-bucketing) | `yii3-ab-testing`'s rollout hash was salted with the rollout percentage itself | raising the rollout percentage never removes an already-included subject |
