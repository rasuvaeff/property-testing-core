<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;

/**
 * One property, fully resolved for the engine: no attribute, no reflection,
 * no environment. An adapter builds it from its own conventions (Testo's
 * `#[Property]` + `<method>Generators()`, a PHPUnit fluent call, a bare CLI
 * script) and hands it to {@see PropertyRunner}.
 *
 * @api
 */
final readonly class PropertyDefinition
{
    /**
     * @param string $id Stable identity of the property (`Class::method` under Testo) —
     *        keys the events and the regression corpus.
     * @param string $name Display name used in failure messages (the bare method name under Testo).
     * @param array<string, ArbitraryInterface> $generators One generator per parameter, keyed by name.
     * @param list<string> $parameterNames The property body's parameters, in declaration order.
     * @param list<list<mixed>> $examples Fixed positional argument tuples run before the random phase.
     * @param bool $replayRegressions Whether recorded corpus entries replay before the random phase.
     *        An adapter turns this off when the property pins its own seed, so the pinned
     *        reproducibility wins over the corpus.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $generators,
        public array $parameterNames,
        public PropertyConfig $config = new PropertyConfig(),
        public array $examples = [],
        public bool $replayRegressions = true,
    ) {
        foreach ($parameterNames as $parameter) {
            if (!array_key_exists($parameter, $generators)) {
                throw new \InvalidArgumentException(sprintf(
                    'No generator for parameter "%s"',
                    $parameter,
                ));
            }
        }

        foreach ($examples as $index => $example) {
            if (count($example) !== count($parameterNames)) {
                throw new \InvalidArgumentException(sprintf(
                    'Example #%d must contain exactly %d value(s), got %d',
                    $index,
                    count($parameterNames),
                    count($example),
                ));
            }
        }
    }
}
