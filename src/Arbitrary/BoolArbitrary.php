<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates booleans. false is the "smaller" boolean: true shrinks to false,
 * false is terminal.
 *
 * @implements Enumerable<bool>
 * @api
 */
final readonly class BoolArbitrary implements Enumerable
{
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->tree($random->int(0, 1) === 1);
    }

    #[\Override]
    public function domainSize(): ?int
    {
        return 2;
    }

    #[\Override]
    public function enumerate(): iterable
    {
        yield $this->tree(false);
        yield $this->tree(true);
    }

    /** @return Shrinkable<bool> */
    private function tree(bool $value): Shrinkable
    {
        /** @var list<Shrinkable<bool>> $shrinks */
        $shrinks = $value ? [Shrinkable::leaf(value: false)] : [];

        return Shrinkable::of($value, static fn(): array => $shrinks);
    }
}
