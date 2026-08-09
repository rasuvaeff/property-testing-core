<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

// Case study for docs/src/cookbook/regex-anchor.md — reconstructs ER-001
// (docs/evolved-rules.md): an identifier validator anchored with `$`
// accepts a trailing "\n" because `$` matches before a trailing newline,
// not only at the true end of the string. `\z` does not.

function acceptsBuggy(string $identifier): bool
{
    return (bool) preg_match('/^[a-z_][a-z0-9_]*$/', $identifier);
}

function acceptsFixed(string $identifier): bool
{
    return (bool) preg_match('/^[a-z_][a-z0-9_]*\z/', $identifier);
}

// Alphabet includes "\n" deliberately — a Gen::string() draw would only
// stumble onto it by chance. Constructing the alphabet, not filtering for
// it, is what makes the newline case reachable at all (root AGENTS.md,
// "Property-based тесты" > "Конструировать, не фильтровать").
$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789_' . "\n";

$agreement = static function (string $identifier): void {
    if (acceptsBuggy($identifier) !== acceptsFixed($identifier)) {
        throw new RuntimeException(sprintf(
            '$-anchor and \z-anchor disagree on %s: $ says %s, \z says %s',
            var_export($identifier, true),
            acceptsBuggy($identifier) ? 'accept' : 'reject',
            acceptsFixed($identifier) ? 'accept' : 'reject',
        ));
    }
};

$definition = new PropertyDefinition(
    id: 'case-study::regexAnchor',
    name: 'dollarAnchorAgreesWithZAnchor',
    generators: ['identifier' => Gen::stringFrom($alphabet, 1, 8)],
    parameterNames: ['identifier'],
    config: new PropertyConfig(runs: 200, seed: 42),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor($agreement));

if ($result instanceof Falsified) {
    echo "Buggy \$-anchored validator falsified:\n\n";
    echo $result->failure()->getMessage() . "\n";
} else {
    echo "No disagreement found — unexpected, the ER-001 bug should reproduce here.\n";
    exit(1);
}
