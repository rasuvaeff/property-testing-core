<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Support\Fixtures;

/**
 * Shapes {@see \Rasuvaeff\PropertyTesting\Arbitrary\ClassArbitrary} has to read,
 * written the way this family writes them: promoted readonly properties,
 * psalm-annotated parameters, validation in the constructor.
 */
enum Currency
{
    case Eur;
    case Usd;
}

final readonly class NativeTypes
{
    public function __construct(
        public int $count,
        public float $ratio,
        public string $label,
        public bool $active,
    ) {}
}

final readonly class AnnotatedTypes
{
    /**
     * @param int<0, 100> $percent
     * @param positive-int $quantity
     * @param non-empty-string $name
     * @param list<int> $ids
     * @param array<non-empty-string, int> $counters
     * @param 'draft'|'published' $status
     * @param ?non-empty-string $note
     */
    public function __construct(
        public int $percent,
        public int $quantity,
        public string $name,
        public array $ids,
        public array $counters,
        public string $status,
        public ?string $note,
    ) {}
}

final readonly class WithEnumAndDate
{
    public function __construct(
        public Currency $currency,
        public \DateTimeImmutable $at,
    ) {}
}

final readonly class Nested
{
    public function __construct(
        public NativeTypes $inner,
        public bool $flag,
    ) {}
}

final readonly class Validating
{
    public function __construct(
        public int $amount,
    ) {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount must be greater than or equal to 0');
        }
    }
}

final readonly class Cyclic
{
    public function __construct(
        public Cyclic $other,
    ) {}
}

final readonly class Unreadable
{
    public function __construct(
        public array $anything,
    ) {}
}

final readonly class Variadic
{
    /** @param list<int> $numbers */
    public array $numbers;

    public function __construct(int ...$numbers)
    {
        $this->numbers = array_values($numbers);
    }
}

final readonly class NoConstructor
{
    public int $value;
}

abstract class NotInstantiable
{
    public function __construct(public int $x) {}
}

final readonly class WrapsNotInstantiable
{
    public function __construct(
        public NotInstantiable $inner,
    ) {}
}
