<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

// Case study for docs/src/cookbook/saturating-minus.md — reconstructs the
// rasuvaeff/duration bug: subtracting a larger duration from a smaller one
// produced a Duration holding a negative microsecond count instead of
// saturating at zero.

final class BuggyDuration
{
    public function __construct(public readonly int $micros) {}

    public function minus(self $other): self
    {
        return new self($this->micros - $other->micros);
    }
}

final class FixedDuration
{
    public function __construct(public readonly int $micros) {}

    public function minus(self $other): self
    {
        return new self(max(0, $this->micros - $other->micros));
    }
}

$staysNonNegative = static function (int $a, int $b): void {
    $result = (new BuggyDuration($a))->minus(new BuggyDuration($b))->micros;

    if ($result < 0) {
        throw new RuntimeException(sprintf(
            'Duration(%d)->minus(Duration(%d)) produced %d micros, expected >= 0',
            $a,
            $b,
            $result,
        ));
    }
};

$definition = new PropertyDefinition(
    id: 'case-study::saturatingMinus',
    name: 'minusNeverGoesNegative',
    generators: [
        'a' => Gen::intBetween(0, 1_000_000_000),
        'b' => Gen::intBetween(0, 1_000_000_000),
    ],
    parameterNames: ['a', 'b'],
    config: new PropertyConfig(runs: 200, seed: 7),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor($staysNonNegative));

if ($result instanceof Falsified) {
    echo "Buggy Duration::minus() falsified:\n\n";
    echo $result->failure()->getMessage() . "\n";
} else {
    echo "No negative result found — unexpected, the saturating-minus bug should reproduce here.\n";
    exit(1);
}
