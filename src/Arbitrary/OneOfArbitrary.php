<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Swarmable;

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
 * @implements Swarmable<TValue>
 * @api
 */
final readonly class OneOfArbitrary implements Swarmable
{
    /** @var non-empty-list<TValue> */
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

        /** @var non-empty-list<TValue> $values */
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

    #[\Override]
    public function variantCount(): int
    {
        return count($this->values);
    }

    /**
     * @param list<int> $indices Variant positions to keep, each in `[0, variantCount() - 1]`.
     *
     * @throws \InvalidArgumentException When an index falls outside the values.
     *
     * @return self<TValue>
     */
    #[\Override]
    public function withVariants(array $indices): self
    {
        return new self(...array_map(
            fn(int $index): mixed => array_key_exists($index, $this->values)
                ? $this->values[$index]
                : throw new \InvalidArgumentException(sprintf(
                    'Variant %d is outside the %d values of this generator',
                    $index,
                    count($this->values),
                )),
            $indices,
        ));
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
