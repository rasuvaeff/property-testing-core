<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Internal\Domain;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Fixed-arity tuple: produces a list with one value per element arbitrary, in
 * order. Useful for generating several correlated parameters as a single value
 * (the property receives the tuple as one array argument and destructures it).
 *
 * Shrinking keeps the arity fixed and shrinks one position at a time through
 * that position's own shrink tree, so each component shrinks within its domain.
 *
 * @implements Enumerable<list<mixed>>
 * @api
 */
final readonly class TupleArbitrary implements Enumerable
{
    /** @var non-empty-list<ArbitraryInterface<mixed>> */
    private array $elements;

    public function __construct(ArbitraryInterface ...$elements)
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('Tuple requires at least one element arbitrary');
        }

        // Named variadic arguments arrive string-keyed; re-index to a list so
        // generate() addresses each element by its positional index.
        $this->elements = array_values($elements);
    }

    /**
     * @return Shrinkable<list<mixed>>
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->tree(array_map(
            static fn(ArbitraryInterface $element): Shrinkable => $element->generate($random),
            $this->elements,
        ));
    }

    /**
     * The product of the elements' domains, when every element has one.
     */
    #[\Override]
    public function domainSize(): ?int
    {
        return Domain::product($this->elements);
    }

    /**
     * First element varying slowest.
     */
    #[\Override]
    public function enumerate(): iterable
    {
        foreach (Domain::cartesian($this->enumerableElements('Gen::tuple()', $this->elements)) as $components) {
            yield $this->tree(array_values($components));
        }
    }

    /**
     * @param array<array-key, ArbitraryInterface> $elements
     *
     * @return array<array-key, Enumerable>
     */
    private function enumerableElements(string $generator, array $elements): array
    {
        foreach ($elements as $element) {
            if (!$element instanceof Enumerable) {
                throw new \LogicException($generator . ': an element generator has no finite domain to enumerate');
            }
        }

        /** @var array<array-key, Enumerable> */
        return $elements;
    }

    /**
     * @param list<Shrinkable<mixed>> $components
     *
     * @return Shrinkable<list<mixed>>
     */
    private function tree(array $components): Shrinkable
    {
        $value = array_map(static fn(Shrinkable $component): mixed => $component->value, $components);

        return Shrinkable::of($value, function () use ($components): \Generator {
            // Arity is fixed: there is no length phase. Shrink one position at a
            // time through its own tree, keeping the others as-is.
            foreach ($components as $index => $component) {
                foreach ($component->shrinks() as $smaller) {
                    yield $this->tree(array_replace($components, [$index => $smaller]));
                }
            }
        });
    }
}
