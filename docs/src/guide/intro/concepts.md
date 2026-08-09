---
title: Concepts
description: "A short glossary of property-testing vocabulary: property, generator/arbitrary, run, discard, seed, falsify, shrink, counterexample."
---

# Concepts

A short glossary of the vocabulary used across this site, in the order you
tend to meet the terms.

**Property**
&nbsp;&nbsp;A law about your code, expressed as a test method under
`#[Property]` (or the PHPUnit trait's `forAll()`): "for any input matching
this shape, this assertion holds." See [Getting started](/guide/intro/getting-started).

**Generator / arbitrary**
&nbsp;&nbsp;Something that knows how to produce random values of a given
shape and how to minimize them. `Gen::intBetween(0, 100)` is a generator; the
object it returns implements
[`ArbitraryInterface`](/api/classes/ArbitraryInterface).
See [Generators](/guide/generators/index).

**Run**
&nbsp;&nbsp;One execution of the property body with one set of generated
arguments. `#[Property(runs: 200)]` asks for 200 **successful** runs — see
"discard" below for what doesn't count.

**Discard**
&nbsp;&nbsp;A run abandoned via [`Assume::that(false)`](/guide/controlling-runs/assume-vs-filter)
because a precondition didn't hold. Discards don't consume `runs`; they're
retried until `maxDiscards` is exceeded.

**Seed**
&nbsp;&nbsp;The integer that deterministically drives a property's random
phase. Two runs with the same seed generate the identical sequence of
values — this is what makes a reported failure reproducible:
`#[Property(seed: 7382910)]`.

**Falsify**
&nbsp;&nbsp;What happens when a generated (or explicit) input makes the
property's assertion fail. The runner catches the failure and hands the
input to the shrinker.

**Shrink / shrinking**
&nbsp;&nbsp;Minimizing a falsifying input by descending through its value's
tree of smaller candidates, looking for the simplest one that still fails.
See [Shrinking](/guide/shrinking) for how the tree itself is built and why it's
guaranteed to terminate.

**Counterexample**
&nbsp;&nbsp;The shrunk (minimal) input that falsified the property, plus the
original input, the failure, and run/shrink statistics — carried by
[`CounterExample`](/api/classes/CounterExample) and reported inside
[`PropertyViolationException`](/api/classes/PropertyViolationException).

**Classify / coverage**
&nbsp;&nbsp;Recording which "kind" of input each run happened to be (`even`,
`empty`, `boundary`, …), to check that your generators actually reach the
interesting cases instead of passing vacuously. See
[Distribution](/guide/distribution).

**Regression corpus**
&nbsp;&nbsp;Optional on-disk memory (`PROPERTY_DB`) of past failures, replayed
before the random phase on every run, so a fixed bug can't silently come back
unnoticed. See [Regression corpus](/guide/regression-corpus).

**Command / state machine**
&nbsp;&nbsp;The vocabulary for testing a *sequence* of operations rather than
a single input — a generated list of `Command`s run against both a real
system and a simplified in-memory model. See
[State machine: concepts](/guide/state-machine/concepts).
