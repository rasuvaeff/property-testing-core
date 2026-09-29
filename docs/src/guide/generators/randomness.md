---
title: Shrinkable randomness
description: "Gen::randomEngine() and Gen::randomizer(): a Random\\Engine drawn from the property's tape, so code that takes a Randomizer has its random decisions recorded, replayed and shrunk."
---

# Shrinkable randomness

Code that takes a `Random\Randomizer` — a jittered backoff, a shuffle, a
weighted pick, a replica selector — can be property-tested by drawing a seed
and building `new Randomizer(new Mt19937($seed))`. That reproduces a failure;
it does not shrink it. The smallest failing seed is another opaque number,
and the sequence of random decisions behind the failure stays as long and as
noisy as it was.

`Gen::randomEngine()` yields a `Random\Engine` whose every `generate()` is one
[in-body draw](/guide/generators/dependent#in-body-draws-gen-draw) of eight
bytes. The code under test sees the native API and needs no seam; the engine
records each call on the replay tape, replays it by position on shrink
trials, and shrinks it through the bytes' own tree.

```php
use Rasuvaeff\PropertyTesting\Gen;

#[Property]
public function backoffStaysUnderCap(int $attempt, \Random\Randomizer $randomizer): void
{
    $delay = (new JitteredBackoff($randomizer))->delayMs($attempt);

    Assert::true($delay <= 60_000);
}
```

Under `auto`, a `Random\Engine` parameter is derived as `Gen::randomEngine()`
and a `Random\Randomizer` parameter as `Gen::randomizer()` — the native
`Randomizer` over the drawn engine; both are also there to pass explicitly.

## What "smaller" means

The bytes shrink toward `"\0"`, and the direction is worth knowing before
reading a counterexample: `Randomizer::getInt($min, $max)` maps all-zero
bytes to `$min`, `getFloat()` to the lower end, `shuffleArray()` to a
near-identity permutation. For most code that is the natural minimum — the
shortest jitter, the first replica, the unshuffled list — and the
counterexample says which random decisions were needed to fail, not just
that some were.

Each engine call is one tape position, reported as `draw#N`. The descent is
bounded like every in-body draw, so a body that consumes thousands of random
values per run shrinks only as far as that cap allows. The engine is valid
only inside a run — outside one, `generate()` throws the way `Gen::draw()`
does — and is not seedable: the tape is the seed. A counterexample that
carries draws is stored in the corpus as a seed entry, as always.
