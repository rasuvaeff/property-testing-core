---
title: Rule-based machines
description: "One class instead of a class per command: #[Rule] methods are the steps, #[Precondition] names the guard, #[Invariant] methods hold after every step, and Gen::rules() generates and shrinks sequences over it."
---

# Rule-based machines

The [`Command`](/api/classes/StateMachine/Command) interface is the primitive:
a model threaded as an immutable value, one class per command, a harness, a
test. That is the right shape when the model *is* a separate value. For the
common case — the model is a few fields, the commands are a few methods — the
entry cost keeps stateful testing unused. `Gen::rules()` is a second façade
over the same engine, in the shape Hypothesis's rule-based state machines use:

```php
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;

final class QueueMachine
{
    private array $model = [];

    public function __construct(private readonly Queue $sut) {}

    #[Rule]
    public function enqueue(int $value): void          // parameters drawn like a property's
    {
        $this->sut->push($value);
        $this->model[] = $value;
    }

    #[Rule]
    #[Precondition('notEmpty')]
    public function dequeue(): void
    {
        Assert::same($this->sut->pop(), array_shift($this->model));
    }

    public function notEmpty(): bool { return $this->model !== []; }

    #[Invariant]
    public function sizeMatches(): void
    {
        Assert::same($this->sut->size(), count($this->model));
    }
}
```

```php
#[Property(runs: 200)]
public function queueBehavesLikeAList(RuleSequence $sequence): void
{
    $sequence->run(static fn (): QueueMachine => new QueueMachine(new Queue()));
}

public static function queueBehavesLikeAListGenerators(): array
{
    return ['sequence' => Gen::rules(QueueMachine::class, maxLength: 50)];
}
```

## What each attribute means

| Attribute | On | Meaning |
|---|---|---|
| `#[Rule]` | a public instance method | One step the sequence may take. Its parameters are drawn as [`Gen::forParameters()`](/guide/generators/from-a-class) draws them: an override, the docblock type, the native type. Overrides come from a `public static function <rule>Generators(): array` on the machine, or from the method `#[Rule(generators: 'name')]` points at. The body drives the system under test, updates the model, and asserts — an exception is the failed postcondition |
| `#[Precondition('method')]` | a rule | Names a public bool method of the machine. A step whose guard is false in the machine's current state is **skipped**, not failed — the same skip-on-replay contract a dropped `Command` step has |
| `#[Invariant]` | a public instance method without parameters | Runs before the first step and after every executed one; an exception falsifies the sequence at that step |

`Gen::rules()` reads the class once and refuses, by name, a machine with no
rule, a rule or invariant that is not a public instance method, an invariant
with parameters, a guard that does not exist or is not public, and a
generators method that is missing, not `public static`, not an array, or not
keyed by parameter name.

## How it runs and shrinks

Each `#[Rule]` method becomes a [`RuleStep`](/api/classes/StateMachine/RuleStep)
— a `Command` under the hood whose model is the machine object itself, so
the sequence is generated and shrunk exactly as a
[`Gen::commands()`](/guide/state-machine/shrinking) sequence is: steps
dropped in blocks, each step's arguments simplified through its tree. At
generation time there is no machine yet and every step is applicable; at run
time the guard decides.

[`RuleSequence::run($factory)`](/api/classes/StateMachine/RuleSequence)
builds a fresh machine from the factory every time, so a shrink trial never
sees state a previous trial left behind, checks the invariants once, then
walks the steps. The factory is handed to `run()` rather than kept in the
value — the split `StateMachine::check()` makes — so the sequence stays a
plain value: it renders as the trace, serializes inside a result, and never
carries a closure into the corpus.

```
Property falsified after 3 successful run(s); seed=42
  Original: sequence=[push(value: 7), push(value: 2), pop(), push(value: 9), pop()]
  Shrunk:   sequence=[push(value: 0), push(value: 1), pop()] (11 shrink step(s))
  Failure:  popped 0, expected 1
```

Hypothesis's *bundles* — values produced by one rule and drawn by another,
an id returned by `create` consumed by `delete` — are not in this first cut;
a rule reads the model for them. `Command` stays the documented pattern for a
machine whose model is a separate value.
