<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * A command that only records that it happened — enough to show which
 * operations a generated sequence contains, without a system under test.
 */
final readonly class NamedCommand implements Command
{
    public function __construct(private string $name) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        return null;
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

/**
 * Swarm testing: each generated case may use only part of the alphabet.
 *
 * Uniform draws make every case look alike, and the bugs that need an
 * operation to be absent stay out of reach. The counts below are the whole
 * argument — same generator, same number of cases, two distributions.
 */

$alphabet = ['push', 'pop', 'flush'];
const CASES = 200;
const EVENTS_PER_CASE = 30;

// 1. Uniform: every case draws each event from the full alphabet.
$uniform = Gen::arrayOf(Gen::oneOf(...$alphabet), EVENTS_PER_CASE, EVENTS_PER_CASE);

// 2. Swarm: the alphabet is drawn once per case (never empty), then the events
//    come from it. A swarm is exactly this pair — a non-empty subset plus a
//    generator built over it — which is why the recipe is spelled out here for
//    a container and packaged as Gen::swarm() for the choice generator itself.
$swarmed = Gen::flatMap(
    Gen::subset($alphabet, minSize: 1),
    static fn(array $available): ArbitraryInterface => Gen::arrayOf(
        Gen::oneOf(...$available),
        EVENTS_PER_CASE,
        EVENTS_PER_CASE,
    ),
);

$withoutFlush = static function (ArbitraryInterface $arbitrary, int $seed): int {
    $random = new Random($seed);
    $found = 0;

    for ($case = 0; $case < CASES; ++$case) {
        /** @var list<string> $events */
        $events = $arbitrary->generate($random)->value;

        if (!in_array('flush', $events, true)) {
            ++$found;
        }
    }

    return $found;
};

printf(
    "cases of %d events that never used 'flush', out of %d:\n  uniform: %d\n  swarm:   %d\n",
    EVENTS_PER_CASE,
    CASES,
    $withoutFlush($uniform, 1),
    $withoutFlush($swarmed, 1),
);

// 3. Gen::swarm() over a choice generator: one case sees a subset of the
//    variants. Over enough cases every variant still appears somewhere.
$swarm = Gen::swarm(Gen::oneOf(...$alphabet));
$random = new Random(2);
$seen = [];

for ($case = 0; $case < 200; ++$case) {
    $seen[$swarm->generate($random)->value] = true;
}

echo 'variants reached across 200 swarmed cases: ' . implode(', ', array_keys($seen)) . "\n";

// 4. Shrinking stays inside the subset the case came from. 'push' is the
//    first-listed value, so an unrestricted OneOf descent always ends there;
//    a case drawn without 'push' must not shrink into it.
$descend = static function (Shrinkable $node) use (&$descend): array {
    $path = [$node->value];

    foreach ($node->shrinks() as $child) {
        /** @var callable(Shrinkable): list<mixed> $descend */
        foreach ($descend($child) as $value) {
            $path[] = $value;
        }

        break;
    }

    return $path;
};

for ($seed = 0; $seed < 100; ++$seed) {
    $node = $swarm->generate(new Random($seed));
    $path = $descend($node);

    if (!in_array('push', $path, true) && count($path) > 1) {
        echo 'shrink descent of a case drawn without push: ' . implode(' -> ', $path) . "\n";

        break;
    }
}

// 5. A stateful sequence: swarm restricts which commands the WHOLE sequence
//    may use, which is where "a queue that never once received a flush" comes
//    from. Gen::commands() is swarmable, so no recipe is needed here.
$commands = Gen::commands(
    [],
    [
        Gen::constant(new NamedCommand('push')),
        Gen::constant(new NamedCommand('pop')),
        Gen::constant(new NamedCommand('flush')),
    ],
    minLength: 8,
    maxLength: 8,
);

$sequencesWithoutFlush = static function (ArbitraryInterface $arbitrary, int $seed): int {
    $random = new Random($seed);
    $found = 0;

    for ($case = 0; $case < CASES; ++$case) {
        $sequence = $arbitrary->generate($random)->value;
        $names = array_map(static fn(object $command): string => (string) $command, $sequence->commands);

        if (!in_array('flush', $names, true)) {
            ++$found;
        }
    }

    return $found;
};

printf(
    "8-command sequences that never used 'flush', out of %d:\n  plain commands: %d\n  swarmed:        %d\n",
    CASES,
    $sequencesWithoutFlush($commands, 3),
    $sequencesWithoutFlush(Gen::swarm($commands), 3),
);
