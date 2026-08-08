<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Picks a value uniformly at random from a fixed set.
 *
 * Values are used verbatim (they are not arbitraries). Earlier values are
 * considered "smaller": a failing value shrinks through the distinct values
 * listed before it, so put simpler values first. Because the index strictly
 * decreases on every step, shrinking terminates even when several values keep
 * failing. Use this for enumerations and small tagged unions.
 *
 * @template TValue
 * @implements ArbitraryInterface<TValue>
 * @api
 */
final readonly class OneOfArbitrary implements ArbitraryInterface
{
    /** @var list<TValue> */
    private array $values;

    /**
     * @param TValue ...$values
     */
    public function __construct(
        mixed ...$values,
    ) {
        if ($values === []) {
            throw new \InvalidArgumentException('OneOf requires at least one value');
        }

        /** @var list<TValue> $values */
        $this->values = $values;
    }

    /**
     * @return Shrinkable<TValue>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->tree($random->int(0, count($this->values) - 1));
    }

    /** @return Shrinkable<TValue> */
    private function tree(int $index): Shrinkable
    {
        return Shrinkable::of($this->values[$index], function () use ($index): \Generator {
            // Candidates are the values listed before the current one, most
            // aggressive (first-listed) first, deduplicated and skipping any
            // that equal the current value.
            /** @var array<string, true> $seen */
            $seen = [];

            for ($candidate = 0; $candidate < $index; ++$candidate) {
                if ($this->values[$candidate] === $this->values[$index]) {
                    continue;
                }

                // Deduplicate identical candidates.
                $key = var_export($this->values[$candidate], return: true);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                yield $this->tree($candidate);
            }
        });
    }
}
