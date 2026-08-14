<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Generating a class from what its constructor already declares.
 *
 * The point of the example is the difference between the two value objects
 * below: they have the same native types, and only one of them says what its
 * values may be.
 */

enum Currency
{
    case Eur;
    case Usd;
}

/** Native types only: `int` is every integer there is. */
final readonly class LooseMoney
{
    public function __construct(
        public int $amount,
        public string $label,
    ) {}
}

/** The same shape, annotated — and the annotations are the value space. */
final readonly class Money
{
    /**
     * @param int<0, 1000> $amount
     * @param non-empty-string $label
     * @param list<int> $tags
     */
    public function __construct(
        public int $amount,
        public string $label,
        public array $tags,
        public Currency $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must be greater than or equal to 0');
        }
    }
}

$random = new Random(7);

echo "Native types — anything an int can be:\n";
foreach (Gen::sample(Gen::forClass(LooseMoney::class), 3, 7) as $money) {
    printf("  amount=%d\n", $money->amount);
}

echo "\nAnnotated — int<0, 1000>, non-empty-string, list<int>, an enum:\n";
foreach (Gen::sample(Gen::forClass(Money::class), 3, 7) as $money) {
    printf(
        "  amount=%d currency=%s tags=%d label-length=%d\n",
        $money->amount,
        $money->currency->name,
        count($money->tags),
        mb_strlen($money->label),
    );
}

// An override wins over every declared type — the way to narrow a value space
// the type cannot express.
echo "\nWith an override on one parameter:\n";
$fixed = Gen::forClass(Money::class, ['amount' => Gen::intBetween(500, 510)]);
foreach (Gen::sample($fixed, 3, 11) as $money) {
    printf("  amount=%d\n", $money->amount);
}

// A constructor that rejects a value says the generator does not match the
// domain. That is an error by default; discarding is opt-in.
echo "\nA rejected value, both ways:\n";

try {
    Gen::forClass(LooseMoney::class, ['amount' => Gen::intBetween(-10, -1)])->generate($random);
    echo "  (LooseMoney validates nothing, so nothing is rejected)\n";
} catch (InvalidArgumentException $e) {
    printf("  default:      %s\n", $e->getMessage());
}

try {
    Gen::forClass(Money::class, ['amount' => Gen::intBetween(-10, -1)])->generate($random);
} catch (InvalidArgumentException $e) {
    printf("  default:      %s\n", $e->getMessage());
}

$skipping = Gen::forClass(Money::class, ['amount' => Gen::intBetween(-10, 10)], skipInvalid: true);
$amounts = array_map(static fn(Money $money): int => $money->amount, Gen::sample($skipping, 5, 3));
printf("  skipInvalid:  every amount survived validation: %s\n", implode(', ', $amounts));
