<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Internal\Domain;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Fixed-shape associative array: produces a map with one value per named field,
 * each drawn from that field's arbitrary. Useful for generating DTO-shaped
 * payloads where every key is known up front (the property receives the record
 * as a single string-keyed array argument).
 *
 * Shrinking keeps the key set fixed and shrinks one field at a time through
 * that field's own shrink tree, so each value shrinks within its domain.
 *
 * @implements Enumerable<array<string, mixed>>
 * @api
 */
final readonly class RecordArbitrary implements Enumerable
{
    /** @var non-empty-array<string, ArbitraryInterface> */
    private array $shape;

    /**
     * @param array<string, ArbitraryInterface> $shape Field name => arbitrary for that field.
     */
    public function __construct(array $shape)
    {
        if ($shape === []) {
            throw new \InvalidArgumentException('Record requires at least one field');
        }

        $this->shape = $shape;
    }

    /**
     * @return Shrinkable<array<string, mixed>>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        // array_map over the shape preserves the field keys, so the record is
        // built without a per-key mixed assignment.
        return $this->tree(array_map(
            static fn(ArbitraryInterface $arbitrary): Shrinkable => $arbitrary->generate($random),
            $this->shape,
        ));
    }

    /**
     * The product of the fields' domains, when every field has one.
     */
    #[\Override]
    public function domainSize(): ?int
    {
        return Domain::product($this->shape);
    }

    /**
     * First field varying slowest.
     */
    #[\Override]
    public function enumerate(): iterable
    {
        $shape = [];

        foreach ($this->shape as $key => $field) {
            if (!$field instanceof Enumerable) {
                throw new \LogicException(sprintf('Gen::record(): field "%s" has no finite domain to enumerate', $key));
            }

            $shape[$key] = $field;
        }

        foreach (Domain::cartesian($shape) as $fields) {
            yield $this->tree($fields);
        }
    }

    /**
     * @param array<string, Shrinkable<mixed>> $fields
     *
     * @return Shrinkable<array<string, mixed>>
     */
    private function tree(array $fields): Shrinkable
    {
        $value = array_map(static fn(Shrinkable $field): mixed => $field->value, $fields);

        return Shrinkable::of($value, function () use ($fields): \Generator {
            // The key set is fixed: there is no length phase. Shrink one field at a
            // time through its own tree, keeping the others as-is.
            foreach ($fields as $key => $field) {
                foreach ($field->shrinks() as $smaller) {
                    yield $this->tree(array_replace($fields, [$key => $smaller]));
                }
            }
        });
    }
}
