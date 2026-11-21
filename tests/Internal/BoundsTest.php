<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\Bounds;
use Rasuvaeff\PropertyTesting\Tests\StateMachine\Support\PopCommand;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Bounds::class)]
final class BoundsTest
{
    /**
     * Inside a flatMap the caller cannot tell which nested generator refused:
     * every message names the factory and the values it got.
     *
     * @param \Closure(): mixed $build
     */
    #[DataProvider('refusals')]
    public function aRefusedRangeNamesTheGeneratorAndTheValues(\Closure $build, string $expected): void
    {
        try {
            $build();

            Assert::fail('expected the range to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), $expected);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function refusals(): iterable
    {
        yield 'intBetween' => [static fn(): mixed => Gen::intBetween(5, 1), 'Gen::intBetween(): min must be less than or equal to max, got 5 and 1'];
        yield 'floatBetween' => [static fn(): mixed => Gen::floatBetween(2.5, 1.5), 'Gen::floatBetween(): min must be less than or equal to max, got 2.5 and 1.5'];
        yield 'arrayOf: negative minimum' => [static fn(): mixed => Gen::arrayOf(Gen::int(), -1, 3), 'Gen::arrayOf(): minSize must be greater than or equal to 0, got -1'];
        yield 'arrayOf: inverted' => [static fn(): mixed => Gen::arrayOf(Gen::int(), 3, 0), 'Gen::arrayOf(): minSize must be less than or equal to maxSize, got 3 and 0'];
        yield 'nonEmptyArrayOf with no room' => [static fn(): mixed => Gen::nonEmptyArrayOf(Gen::int(), 0), 'Gen::arrayOf(): minSize must be less than or equal to maxSize, got 1 and 0'];
        yield 'uniqueArrayOf' => [static fn(): mixed => Gen::uniqueArrayOf(Gen::int(), 4, 2), 'Gen::uniqueArrayOf(): minSize must be less than or equal to maxSize, got 4 and 2'];
        yield 'dictOf' => [static fn(): mixed => Gen::dictOf(Gen::stringAscii(), Gen::int(), -2), 'Gen::dictOf(): minSize must be greater than or equal to 0, got -2'];
        yield 'stringOf' => [static fn(): mixed => Gen::stringOf(-1), 'Gen::stringOf(): minLength must be greater than or equal to 0, got -1'];
        yield 'stringFrom' => [static fn(): mixed => Gen::stringFrom('ab', 2, 1), 'Gen::stringFrom(): minLength must be less than or equal to maxLength, got 2 and 1'];
        yield 'stringFrom: empty alphabet' => [static fn(): mixed => Gen::stringFrom(''), 'Gen::stringFrom(): alphabet must not be empty'];
        yield 'bytes' => [static fn(): mixed => Gen::bytes(3, 2), 'Gen::bytes(): minLength must be less than or equal to maxLength, got 3 and 2'];
        yield 'commands' => [static fn(): mixed => Gen::commands([], [Gen::constant(new PopCommand())], -1), 'Gen::commands(): minLength must be greater than or equal to 0, got -1'];
        yield 'subset: negative minimum' => [static fn(): mixed => Gen::subset([1, 2], -1), 'Gen::subset(): minSize must be greater than or equal to 0, got -1'];
        yield 'subset: inverted' => [static fn(): mixed => Gen::subset([1, 2], 2, 1), 'Gen::subset(): minSize must be less than or equal to maxSize, got 2 and 1'];
        yield 'subset: above the source' => [static fn(): mixed => Gen::subset([1, 2], 0, 3), 'Gen::subset(): maxSize must be less than or equal to the source size, got 3 and 2'];
    }

    /**
     * A range whose ends meet is a range: equal bounds are accepted.
     */
    #[ExpectNoAssertions]
    public function equalEndsAreAccepted(): void
    {
        Bounds::size('Gen::arrayOf()', 'minSize', 'maxSize', 0, 0);
        Bounds::ordered('Gen::intBetween()', 'min', 'max', 3, 3);
    }
}
