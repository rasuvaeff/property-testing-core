<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Rasuvaeff\PropertyTesting\Swarmable;

/**
 * Picks a value uniformly at random from a fixed set.
 *
 * Values are used verbatim: an {@see ArbitraryInterface} among them is
 * rejected rather than handed to the body as data. Earlier values are
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
     *
     * @throws \InvalidArgumentException When no value is given, or when one of
     *         them is an {@see ArbitraryInterface} rather than a value.
     */
    public function __construct(
        mixed ...$values,
    ) {
        if ($values === []) {
            throw new \InvalidArgumentException('OneOf requires at least one value');
        }

        // A generator among the values is the spelling every other library
        // uses for "pick one of these generators", and here it would become
        // the data: the body would receive arbitrary objects and pass without
        // exercising a single branch it was written for.
        foreach ($values as $value) {
            if ($value instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'OneOf takes values, not generators, and was given %s. '
                    . 'Use Gen::frequency() to pick between generators, or pass the values themselves',
                    $value::class,
                ));
            }
        }

        // Named arguments (`new OneOfArbitrary(...['ok' => 1])`) arrive as a
        // string-keyed variadic; the enumeration indexes by position.
        /** @var non-empty-list<TValue> $values */
        $values = array_values($values);
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
            /** @var list<TValue> $seen */
            $seen = [];

            for ($candidate = 0; $candidate < $index; ++$candidate) {
                if ($this->values[$candidate] === $this->values[$index]) {
                    continue;
                }

                // Deduplicated by the same identity the line above uses. A
                // string key from var_export() would be cheaper, but it throws
                // on a cyclic object graph and calls two distinct objects of
                // equal state the same candidate; the list is bounded by the
                // number of values, which is what OneOf is for.
                if (in_array($this->values[$candidate], $seen, strict: true)) {
                    continue;
                }
                $seen[] = $this->values[$candidate];

                yield $this->tree($candidate);
            }
        });
    }
}
