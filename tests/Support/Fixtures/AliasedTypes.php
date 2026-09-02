<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support\Aliased;

use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\Currency as Money;
use Rasuvaeff\PropertyTesting\Tests\Support\Fixtures\{NativeTypes, Nested as Wrapped};

/**
 * A class whose docblock names its types through `use` aliases and a group
 * import, in another namespace than the classes it names: what
 * {@see \Rasuvaeff\PropertyTesting\Internal\DocblockTypes::imports()} has
 * to read for the docblock to mean what the code beneath it means.
 */
final readonly class AliasedTypes
{
    /**
     * @param list<NativeTypes> $items
     * @param non-empty-list<Money> $moneys
     * @param list<Wrapped> $wrapped
     */
    public function __construct(
        public array $items,
        public array $moneys,
        public array $wrapped,
    ) {}
}
