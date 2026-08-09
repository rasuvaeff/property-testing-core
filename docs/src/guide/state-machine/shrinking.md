---
title: "State machine: shrinking"
description: "How a failing Command sequence shrinks to the shortest reproduction: contiguous-block removal first, then per-command simplification."
---

# State machine: shrinking

A failing `CommandSequence` can be dozens of commands long. Shrinking finds
the shortest, simplest sequence that still reproduces the failure, using the
same two-phase approach `Command::shrinks()` composes into: drop commands
first, then simplify what's left.

## Phase 1: block removal

`CommandSequenceArbitrary` shrinks length before anything else, removing
**contiguous blocks** of commands rather than one at a time:

```
blockSize = count, count/2, count/4, ..., 1
for each blockSize:
  for each offset where offset + blockSize <= count:
    yield the sequence with commands[offset .. offset+blockSize) removed
```

The largest block (the whole sequence) is tried first, then halves, then
quarters, down to single-command removal. This is what lets the shrinker
**isolate a failing step in the middle** of a long sequence: prefix-only
halving (drop the first half, then the first half of what's left) can never
land on "keep everything except command #7" — sliding a single-command window
across every offset can. A block removal that would push the sequence below
`minLength` is skipped, so `Gen::commands(..., minLen: 3, ...)` never shrinks
past three steps.

## Phase 2: per-command simplification

Once block removal stops finding a smaller failing sequence, the shrinker
fixes the length and walks each remaining command's own shrink tree
(`$command->shrinks()`), substituting one simplified command at a time. A
`Push(87)` shrinks toward `Push(0)` through `IntArbitrary`'s tree exactly as
it would as a standalone parameter — commands shrink like any other
`Shrinkable`-backed value, because that's what they are.

## No revalidation on replay — by design

Neither phase re-checks `Command::preCondition()` while building candidates.
Dropping command #3 can invalidate command #7's precondition (it depended on
state #3 established), and the shrinker doesn't know that — it just proposes
the shorter sequence.

The soundness guarantee lives in the *runner*, not the *arbitrary*:
[`StateMachine::check()`](/api/classes/StateMachine/StateMachine) re-evaluates
`preCondition()` against the running model for every command in the replayed
sequence and **skips** any command whose precondition no longer holds,
instead of failing outright or throwing:

```php
foreach ($sequence->commands as $command) {
    if (!$command->preCondition($model)) {
        continue; // shrinking invalidated this step — skip, don't fail
    }
    // ... run, assert postCondition, advance model ...
}
```

This is a deliberate contract, not a gap to close: re-validating every
candidate inside the arbitrary would mean re-running commands against a real
model on every shrink *attempt* (not just accepted steps), which is exactly
the expensive, side-effecting work shrinking is supposed to avoid until a
candidate is confirmed to still fail. Skip-on-replay keeps every shrink
candidate cheap to propose and sound to run — the sequence you see in a
`Shrunk:` line is always one `StateMachine::check()` actually executed
command-by-command, never one that got silently skipped into passing.

## Reading a shrunk trace

```
Property falsified after 7 successful run(s); seed=42
  Original: sequence=[Push(3), Pop(), Push(5), Push(1), Pop(), Pop()]
  Shrunk:   sequence=[Push(0), Push(1), Pop()] (9 shrink step(s))
  Failure:  Postcondition failed at step 3 for command Pop(); sequence: [Push(0), Push(1), Pop()]
```

Six commands shrank to three: block removal cut it from six down to three
(dropping `Pop(); Push(5)`), then per-command simplification took `Push(3)`
to `Push(0)`. `step 3` in the failure line counts only commands that actually
ran — a step skipped by an invalidated precondition does not advance the step
counter, so it stays aligned with what you'd count reading the `sequence:`
list left to right.

See [State machine: concepts](/guide/state-machine/concepts) for `Command`,
`Gen::commands()`, and a full worked example.
