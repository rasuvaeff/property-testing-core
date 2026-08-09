<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

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
