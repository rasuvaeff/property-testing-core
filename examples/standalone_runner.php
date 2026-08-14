<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PathFailed;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;

/**
 * This example drives the engine directly — no Testo, no PHPUnit, no test
 * framework at all. A custom harness (CLI checker, CI guard, application-side
 * validation) builds a PropertyDefinition by hand, executes the body through
 * CallableTrialExecutor, and inspects the structured PropertyResult. The
 * runner never prints, never exits and never throws for a property outcome:
 * how a failure surfaces is entirely up to this script.
 */

$runner = new PropertyRunner();

// A property that holds: string concatenation length is additive.
$holds = new PropertyDefinition(
    id: 'standalone::concatLengthIsAdditive',
    name: 'concatLengthIsAdditive',
    generators: [
        'left' => Gen::stringAscii(),
        'right' => Gen::stringAscii(),
    ],
    parameterNames: ['left', 'right'],
    config: new PropertyConfig(runs: 200, seed: 42),
);

$result = $runner->run($holds, new CallableTrialExecutor(
    static function (string $left, string $right): void {
        if (strlen($left . $right) !== strlen($left) + strlen($right)) {
            throw new RuntimeException('Concatenation lost characters');
        }
    },
));

echo $result instanceof Passed
    ? "concatLengthIsAdditive: held for 200 runs\n"
    : "concatLengthIsAdditive: FAILED unexpectedly\n";

// derandomize: with no explicit seed the runner would draw a random one, and a
// property that fails for one input in fifty would fail CI only some of the
// time. Derived from the property id instead, the same run always selects the
// same inputs — printed here twice to show it.
foreach ([1, 2] as $attempt) {
    $seen = [];
    $runner->run(
        new PropertyDefinition(
            id: 'standalone::derandomised',
            name: 'derandomised',
            generators: ['value' => Gen::intBetween(0, 1_000_000)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 3, derandomize: true),
        ),
        new CallableTrialExecutor(static function (int $value) use (&$seen): void {
            $seen[] = $value;
        }),
    );

    echo sprintf("derandomised run %d: %s\n", $attempt, implode(', ', $seen));
}

// A property that is falsified: "every integer stays below 100". The runner
// shrinks the failing input to the minimal one and reports it as a structured
// Falsified result carrying the usual CounterExample.
$falsified = new PropertyDefinition(
    id: 'standalone::everyIntStaysBelowHundred',
    name: 'everyIntStaysBelowHundred',
    generators: ['value' => Gen::intBetween(0, 10_000)],
    parameterNames: ['value'],
    config: new PropertyConfig(runs: 200, seed: 42),
);

$result = $runner->run($falsified, new CallableTrialExecutor(
    static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    },
));

if ($result instanceof Falsified) {
    $example = $result->counterExample();

    echo "everyIntStaysBelowHundred: falsified as expected\n";
    echo sprintf("  seed:     %d\n", $example->seed);
    echo sprintf("  original: %s\n", var_export($example->originalArguments['value'], true));
    echo sprintf("  shrunk:   %s (in %d step(s))\n", var_export($example->shrunkArguments['value'], true), $example->shrinkSteps);
} else {
    echo "everyIntStaysBelowHundred: held unexpectedly\n";
}

// The same property with the descent switched off: the counterexample is
// reported exactly as generated, with zero shrink steps and zero trials. A
// wall-clock alternative is `shrinkBudgetMs`, which keeps the best candidate
// the descent reached before the budget ran out.
$unshrunk = $runner->run(
    new PropertyDefinition(
        id: 'standalone::everyIntStaysBelowHundred',
        name: 'everyIntStaysBelowHundred',
        generators: ['value' => Gen::intBetween(0, 10_000)],
        parameterNames: ['value'],
        config: new PropertyConfig(runs: 200, seed: 42, shrink: ShrinkMode::Off),
    ),
    new CallableTrialExecutor(static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    }),
);

if ($unshrunk instanceof Falsified) {
    $example = $unshrunk->counterExample();

    echo sprintf(
        "  with ShrinkMode::Off: %s in %d step(s), %d trial(s)\n",
        var_export($example->shrunkArguments['value'], true),
        $example->shrinkSteps,
        $example->shrinkTrials,
    );
}

// The counterexample carries the descent that produced it. Passed back with the
// seed, the runner follows those steps instead of searching for them again: one
// body execution per accepted step instead of one per candidate tried. It does
// not skip the random phase — reaching the failing run still means running the
// runs before it.
if ($result instanceof Falsified) {
    $replayed = $runner->run(
        new PropertyDefinition(
            id: 'standalone::everyIntStaysBelowHundred',
            name: 'everyIntStaysBelowHundred',
            generators: ['value' => Gen::intBetween(0, 10_000)],
            parameterNames: ['value'],
            config: new PropertyConfig(runs: 200, seed: 42, path: $result->counterExample()->path),
        ),
        new CallableTrialExecutor(static function (int $value): void {
            if ($value >= 100) {
                throw new RuntimeException(sprintf('%d is not below 100', $value));
            }
        }),
    );

    if ($replayed instanceof Falsified) {
        echo sprintf(
            "  path %s: same shrunk value %s in %d trial(s) instead of %d\n",
            $result->counterExample()->path,
            var_export($replayed->counterExample()->shrunkArguments['value'], true),
            $replayed->counterExample()->shrinkTrials,
            $result->counterExample()->shrinkTrials,
        );
    }
}

// A path that no longer applies is its own outcome, never a silent fresh
// search: this one names a candidate the enumeration does not have.
$stale = $runner->run(
    new PropertyDefinition(
        id: 'standalone::everyIntStaysBelowHundred',
        name: 'everyIntStaysBelowHundred',
        generators: ['value' => Gen::intBetween(0, 10_000)],
        parameterNames: ['value'],
        config: new PropertyConfig(runs: 200, seed: 42, path: 'value:99'),
    ),
    new CallableTrialExecutor(static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    }),
);

if ($stale instanceof PathFailed) {
    echo sprintf("  stale path: %s\n", $stale->exception->getMessage());
}

// Phases are a set: this run performs the pinned examples and nothing else, so
// it completes in one body execution instead of 200. With no random phase the
// statistics report honest zeros rather than the configured run count.
$gate = $runner->run(
    new PropertyDefinition(
        id: 'standalone::fastGate',
        name: 'fastGate',
        generators: ['value' => Gen::intBetween(0, 10_000)],
        parameterNames: ['value'],
        config: new PropertyConfig(runs: 200, seed: 42, phases: [Phase::Examples, Phase::Corpus]),
        examples: [[7]],
    ),
    new CallableTrialExecutor(static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    }),
);

if ($gate instanceof Passed) {
    echo sprintf(
        "fastGate: passed with attempts=%d, checks=%d (the random phase never ran)\n",
        $gate->statistics->attempts,
        $gate->statistics->checks,
    );
}
