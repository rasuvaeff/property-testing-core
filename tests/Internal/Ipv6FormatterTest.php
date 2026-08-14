<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\Ipv6Formatter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Ipv6Formatter::class)]
final class Ipv6FormatterTest
{
    /**
     * @param list<int> $groups
     */
    #[DataProvider('canonicalFormProvider')]
    public function formatRendersTheCanonicalRfc5952Form(array $groups, string $expected): void
    {
        Assert::same(Ipv6Formatter::format($groups), $expected);
    }

    /**
     * @return iterable<string, array{list<int>, string}>
     */
    public static function canonicalFormProvider(): iterable
    {
        yield 'no zero group' => [
            [0x2001, 0x0DB8, 1, 2, 3, 4, 5, 6],
            '2001:db8:1:2:3:4:5:6',
        ];

        yield 'all groups zero' => [
            [0, 0, 0, 0, 0, 0, 0, 0],
            '::',
        ];

        yield 'leading run' => [
            [0, 0, 0, 0, 0, 0, 0, 1],
            '::1',
        ];

        yield 'trailing run' => [
            [0x2001, 0xDB8, 0, 0, 0, 0, 0, 0],
            '2001:db8::',
        ];

        yield 'run in the middle' => [
            [0xFE80, 0, 0, 0, 0, 0, 0, 1],
            'fe80::1',
        ];

        yield 'a single zero group is never compressed' => [
            [1, 0, 2, 3, 4, 5, 6, 7],
            '1:0:2:3:4:5:6:7',
        ];

        yield 'a single zero group in the first position' => [
            [0, 1, 2, 3, 4, 5, 6, 7],
            '0:1:2:3:4:5:6:7',
        ];

        yield 'a single zero group in the last position' => [
            [1, 2, 3, 4, 5, 6, 7, 0],
            '1:2:3:4:5:6:7:0',
        ];

        yield 'single zero groups on both sides of a longer run' => [
            [1, 0, 2, 0, 0, 3, 0, 4],
            '1:0:2::3:0:4',
        ];

        yield 'longest run wins over an earlier shorter one' => [
            [1, 0, 0, 2, 0, 0, 0, 3],
            '1:0:0:2::3',
        ];

        yield 'leftmost run wins on a tie' => [
            [1, 0, 0, 2, 0, 0, 3, 4],
            '1::2:0:0:3:4',
        ];

        yield 'leading zeros stripped, hex lowercased' => [
            [0x0001, 0x00AB, 0x0ABC, 0xABCD, 0x000F, 0x0010, 0x0100, 0x1000],
            '1:ab:abc:abcd:f:10:100:1000',
        ];

        yield 'maximum groups' => [
            [0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF, 0xFFFF],
            'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        ];

        yield 'run of exactly two at the end' => [
            [1, 2, 3, 4, 5, 6, 0, 0],
            '1:2:3:4:5:6::',
        ];
    }

    /**
     * @param list<int> $groups
     */
    #[DataProvider('canonicalFormProvider')]
    public function formatAgreesWithInetNtop(array $groups, string $expected): void
    {
        $oracle = inet_ntop(pack('n8', ...$groups));

        Assert::same($oracle, $expected);
        Assert::same(Ipv6Formatter::format($groups), $oracle);
    }
}
