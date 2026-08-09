---
title: "State machine: concepts"
description: "Generating whole Command sequences with Gen::commands(), running them against a model, and shrinking to the shortest failing sequence."
---

# State machine: concepts

## Stateful / model-based testing

Some bugs only surface across a *sequence* of operations — a counter that
overflows after N increments, a cache that returns stale data, a stack that
loses ordering. Model-based testing generates random sequences of commands,
runs each against the real system while mirroring it in a simplified model, and
on failure **shrinks the sequence** to the shortest one that still breaks.

Implement [`Command`](/api/classes/StateMachine/Command) — four pure-ish
responsibilities plus a label:

| Method | Purpose |
|---|---|
| `preCondition(mixed $model): bool` | May this command run in the current model state? Gates generation and, on replay, whether the command runs or is skipped. |
| `nextState(mixed $model): mixed` | The model's expected next state (pure; returns a new model, never mutates). |
| `run(mixed $model, mixed $system): mixed` | Execute against the system under test; return the observed result. |
| `postCondition(mixed $model, mixed $result): bool` | Check the result against the pre-state model. Return `false` (or throw) to falsify. |
| `__toString(): string` | Label used in the counterexample trace. |

`Gen::commands($initialModel, $commandGenerators)` builds valid sequences (each
step appends a command whose precondition holds, then advances the model), and
`StateMachine::check()` drives the generated sequence against a fresh system:

```php
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Testo\Test;

#[Test]
final class StackModelTest
{
    #[Property(runs: 200)]
    public function stackBehavesLikeItsModel(CommandSequence $sequence): void
    {
        StateMachine::check($sequence, static fn(): ExampleStack => new ExampleStack());
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function stackBehavesLikeItsModelGenerators(): array
    {
        return ['sequence' => Gen::commands([], [
            Gen::map(Gen::intBetween(0, 99), static fn(int $v): Command => new Push($v)),
            Gen::constant(new Pop()),
        ])];
    }
}
```

Shrinking removes whole blocks of commands (down to a single one, so a failing
step in the middle is isolated) and then simplifies each command's parameters
through its own tree. Because the runner re-checks each precondition and skips
any a dropped step invalidated, every shrunk sequence stays sound. The
counterexample renders as a readable trace, and a failed postcondition throws a
[`PostconditionViolation`](/api/classes/StateMachine/PostconditionViolation)
naming the step:

```
Property falsified after 7 successful run(s); seed=42
  Original: sequence=[Push(3), Pop(), Push(5), Push(1), Pop(), Pop()]
  Shrunk:   sequence=[Push(0), Push(1), Pop()] (9 shrink step(s))
  Failure:  Postcondition failed at step 3 for command Pop(); sequence: [Push(0), Push(1), Pop()]
```

See [`examples/state_machine.php`](https://github.com/rasuvaeff/property-testing-testo/blob/master/examples/state_machine.php)
(in `property-testing-testo` — a full worked example against `ExampleStack`,
`Push` and `Pop`) for the runnable version. State machine testing is engine
behavior (`Gen::commands()`, `StateMachine::check()`, `Command` all live in
`rasuvaeff/property-testing-core`); the example uses Testo only to run it.
