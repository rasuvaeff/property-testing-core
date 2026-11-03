<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Gen::class)]
final class GenJsonTest
{
    public function jsonStringLeavesSpanLengthZeroToTwelve(): void
    {
        $lengths = array_map(strlen(...), $this->stringLeaves());

        Assert::true(in_array(0, $lengths, strict: true));
        Assert::true(in_array(12, $lengths, strict: true));
        Assert::same(max($lengths), 12);
    }

    public function jsonStringLeavesUseAlphanumericsAndSpace(): void
    {
        $chars = array_unique(str_split(implode('', $this->stringLeaves())));
        sort($chars);

        Assert::same(implode('', $chars), ' 0123456789abcdefghijklmnopqrstuvwxyz');
    }

    public function jsonStringLeafShrinksTowardTheFirstAlphanumeric(): void
    {
        $node = Trees::generateWhere(
            Gen::json(1),
            static fn(mixed $v): bool => is_string($v) && strlen($v) >= 3 && !str_contains($v, 'a'),
        );

        $minimal = Trees::descendWhile($node, static fn(mixed $v): bool => is_string($v) && $v !== '');

        Assert::same($minimal->value, 'a');
    }

    public function jsonListsSpanSizeZeroToFour(): void
    {
        $sizes = [];

        foreach (Gen::sample(Gen::json(), 1500, 7) as $value) {
            $this->collect($value, $strings, $sizes);
        }

        Assert::true(in_array(0, $sizes, strict: true));
        Assert::true(in_array(4, $sizes, strict: true));
        Assert::same(max($sizes), 4);
    }

    public function withEdgeCasesAcceptsNamedEdgeValues(): void
    {
        $values = Gen::sample(Gen::withEdgeCases(new ConstantArbitrary(0), ...['edge' => 7]), 300, 5);

        Assert::true(in_array(7, $values, strict: true));
        Assert::same(array_values(array_unique(array_merge([0, 7], $values))), [0, 7]);
    }

    public function noteAttachesToTheCurrentRun(): void
    {
        DrawContext::arm(new Random(1));

        try {
            Gen::note('size', 3);
            Gen::note('size', 4);

            Assert::same(DrawContext::notes(), ['size' => 4]);
        } finally {
            DrawContext::disarm();
        }
    }

    /**
     * @return list<string>
     */
    private function stringLeaves(): array
    {
        $strings = [];

        foreach (Gen::sample(Gen::json(), 1500, 7) as $value) {
            $this->collect($value, $strings, $sizes);
        }

        return $strings;
    }

    /**
     * @param list<string>|null $strings
     * @param list<int>|null $sizes
     */
    private function collect(mixed $value, ?array &$strings, ?array &$sizes): void
    {
        $strings ??= [];
        $sizes ??= [];

        if (is_string($value)) {
            $strings[] = $value;

            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (array_is_list($value)) {
            $sizes[] = count($value);
        }

        foreach ($value as $item) {
            $this->collect($item, $strings, $sizes);
        }
    }
}
