<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Closure;
use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Seed determinism vectors (evolution plan, stage E): the exact values every
 * representative generator produces for a pinned seed. These vectors are the
 * observable definition of `FilesystemCorpus::SEQUENCE_EPOCH` — they move to
 * property-testing-core verbatim, and the core extraction must reproduce them
 * bit-for-bit before seed-entry corpora recorded by 2.8 may replay there.
 *
 * A diff in this file means the generated sequence for a given seed shifted:
 * either revert the change or bump `SEQUENCE_EPOCH` in the same commit —
 * never repin silently.
 */
#[Test]
#[Covers(Gen::class)]
final class SeedDeterminismVectorsTest
{
    /**
     * @param Closure(): ArbitraryInterface $factory
     * @param list<mixed> $expected
     */
    #[DataProvider('vectorProvider')]
    public function generatorReproducesPinnedSequence(Closure $factory, int $seed, array $expected): void
    {
        $arbitrary = $factory();
        $random = new Random($seed);

        $actual = [];
        for ($i = 0, $count = count($expected); $i < $count; ++$i) {
            $actual[] = self::normalize($arbitrary->generate($random)->value);
        }

        Assert::same($actual, $expected);
    }

    /**
     * @return iterable<string, array{Closure(): ArbitraryInterface, int, list<mixed>}>
     */
    public static function vectorProvider(): iterable
    {
        yield 'int' => [
            Gen::int(...),
            101,
            [1303586833714857483, 8936481239650145855, 3417757055142277195, -1, 1423289486755059332],
        ];

        yield 'intBetween' => [
            static fn(): ArbitraryInterface => Gen::intBetween(-1_000, 1_000),
            102,
            [-142, -730, 449, 606, -929],
        ];

        yield 'intPositive' => [
            Gen::intPositive(...),
            103,
            [9223372036854775807, 3153354516774608220, 7289708339898856691, 1, 9223372036854775807],
        ];

        yield 'float' => [
            Gen::float(...),
            104,
            [0.0, 0.25359402529315433, 0.5431097514750576, 0.0, 0.6598553213486613],
        ];

        yield 'floatBetween' => [
            static fn(): ArbitraryInterface => Gen::floatBetween(-1.5, 1.5),
            105,
            [1.4373459888477278, 0.6931902990020635, -1.4724210491719543, 1.1572250408798643, -1.5],
        ];

        yield 'bool' => [
            Gen::bool(...),
            106,
            [false, false, true, true, true],
        ];

        yield 'string (unicode, hex-normalised)' => [
            Gen::string(...),
            107,
            [
                'hex:7dc7be76ecb5b63bc4b3f2b394b64271c392f288839726674d27f18681aef3bf9aaae0bb8621f09f91a82a4ff0beaabef184bf9658f48ea08ef28791bbf094bebfc68430e6a5a8f1be879f52373b7a70c2adf09f91a85637f1bc82802a',
                'hex:c2b5e7929df3a584a5265c36c389f2ae879a40213020f0b9a5916ec881e5a288eb9b92f3849d8a65c381f181a795eb80a5c384ee91a3c98b7279eca6b644f1a39e8af2b58682674d22243ac583e2808fe3ba99f092ba8bc3ade9a9b52846ea8680c4b3f2b2b889e88a8cf29c978947f289829c33ecb5a6f3b7b08976f3a081817924efbfbdefbfbdf1a3988a387bcc81726a356b40e5a387c580f486b395f39d838f7ce6b3a3f380ab96f3818f82f19081bb5ec3a3f2b98f84567c3fe583a9f3a985a16fe9bdb4367325f1b6b8ac',
                '',
            ],
        ];

        yield 'stringAscii' => [
            Gen::stringAscii(...),
            108,
            [
                'v_vTbR4?.atzK3n1E[&X;..Sz#$!T 8]l R q}qP7b+0FWm>4[y6eX+Z|~4h~:\']YN1r.Aa^abi5\'Q<2:PSh',
                '$=OE7Lb:r.<A@PAoLuy}Nlky4|eX*$s.\\Uz_IXj)AYm#q-d~i<R\'^GBhDx@zICaEK`[+5~fq#m.4WEX*(',
                'YkZMyw1\'Sc+=L5x"pBO?GR)B}fA(?N*&^oEM}3/jY~&d}1FDK,7@Ln3PKMk0>cv)P.geTT\'yY7?81Xi-R',
                '+(Sd(K/YR\\GnH%DAY)T#EfQpC',
                'N)1<Pz`q5,ad,k@j<CeS)D',
            ],
        ];

        yield 'stringFrom' => [
            static fn(): ArbitraryInterface => Gen::stringFrom('abc', 1, 5),
            109,
            ['aa', 'ab', 'b', 'aaabc', 'b'],
        ];

        yield 'bytes (hex-normalised)' => [
            static fn(): ArbitraryInterface => Gen::bytes(0, 8),
            110,
            ['hex:e32617d03d', 'hex:7d9926605937a2f4', 'hex:808b', 'hex:d018212846', ''],
        ];

        yield 'arrayOf' => [
            static fn(): ArbitraryInterface => Gen::arrayOf(Gen::intBetween(0, 9), 0, 5),
            111,
            [[6, 2], [6], [], [9, 3], [9, 6]],
        ];

        yield 'uniqueArrayOf' => [
            static fn(): ArbitraryInterface => Gen::uniqueArrayOf(Gen::intBetween(0, 20), 0, 5),
            112,
            [[18, 1], [7, 0, 1, 5], [8, 18, 12], [], [8, 15, 6]],
        ];

        yield 'dictOf' => [
            static fn(): ArbitraryInterface => Gen::dictOf(Gen::stringFrom('xyz', 1, 3), Gen::intBetween(0, 9), 0, 3),
            113,
            [
                ['z' => 6],
                ['zzz' => 0, 'zyx' => 9],
                ['z' => 9, 'yx' => 1, 'zx' => 4],
                ['x' => 7],
                ['y' => 3, 'yz' => 1],
            ],
        ];

        yield 'oneOf' => [
            static fn(): ArbitraryInterface => Gen::oneOf('a', 'b', 'c'),
            114,
            ['a', 'b', 'b', 'a', 'c'],
        ];

        yield 'nullable' => [
            static fn(): ArbitraryInterface => Gen::nullable(Gen::intBetween(0, 9)),
            115,
            [1, 4, null, 9, null],
        ];

        yield 'map' => [
            static fn(): ArbitraryInterface => Gen::map(Gen::intBetween(0, 9), static fn(int $value): int => $value * 2),
            116,
            [14, 16, 12, 0, 2],
        ];

        yield 'flatMap' => [
            static fn(): ArbitraryInterface => Gen::flatMap(
                Gen::intBetween(1, 3),
                static fn(int $length): ArbitraryInterface => Gen::stringFrom('z', $length, $length),
            ),
            117,
            ['zz', 'zzz', 'zzz', 'zzz', 'zzz'],
        ];

        yield 'tuple' => [
            static fn(): ArbitraryInterface => Gen::tuple(Gen::intBetween(0, 9), Gen::bool()),
            118,
            [[1, true], [7, true], [9, true], [0, false], [0, true]],
        ];

        yield 'frequency' => [
            static fn(): ArbitraryInterface => Gen::frequency([[1, Gen::constant('a')], [9, Gen::constant('b')]]),
            119,
            ['b', 'b', 'b', 'b', 'b'],
        ];

        yield 'uuid' => [
            Gen::uuid(...),
            120,
            [
                'a77e8ead-00a7-445d-9e44-5183df8233ff',
                '7e2dab9f-0a40-4690-af02-267a38cd2197',
                '5496d375-b1c3-4e88-ace1-6bf3979694f5',
                '7f250dfc-219e-46f3-936f-bbda58a097dc',
                '69b25197-543b-4928-a9ad-04346929e47e',
            ],
        ];

        yield 'datetime' => [
            static fn(): ArbitraryInterface => Gen::datetime(),
            121,
            [
                'datetime:2063-10-21T08:55:55+00:00',
                'datetime:2033-01-09T12:18:34+00:00',
                'datetime:2089-02-20T05:45:59+00:00',
                'datetime:2073-07-15T23:24:07+00:00',
                'datetime:2004-09-05T21:42:37+00:00',
            ],
        ];

        yield 'ipv4' => [
            Gen::ipv4(...),
            122,
            ['154.182.62.108', '187.207.182.21', '169.78.228.51', '141.173.1.188', '255.42.48.255'],
        ];

        yield 'ipv6' => [
            Gen::ipv6(...),
            127,
            [
                'ffff:0:fd43:2d9b:ffff:1b17:63d9:e713',
                'a38:1:9632:0:2544:c26d:cf33:608c',
                '6cdb:3db1:8c22:81c4:c70d:2990:a2c:dca1',
                'b0d3:ffff:3343:0:ebff:8bb5:148c:679b',
                '4727::727d:5d17:1ff1:848d:2c6b',
            ],
        ];

        yield 'email' => [
            Gen::email(...),
            123,
            [
                'b4s2os3x89z4z2v@pb282hjhsva6aab.io',
                '42xs@x6jqh.io',
                '2uas@3g.org',
                'w32mt8fmh44s@3wml5tb.org',
                'akn14a0o12h5gd3@0nxfgairiojv.dev',
            ],
        ];

        yield 'url' => [
            Gen::url(...),
            124,
            [
                'http://b9cnimmy0zjwa.org/kw22nj5',
                'http://4j3.io/7rkq',
                'http://5f.org',
                'https://xd3w3meyrel34817.dev/8/9/vcbcs',
                'http://024.dev/oexe',
            ],
        ];

        yield 'regex' => [
            static fn(): ArbitraryInterface => Gen::regex('[a-c]{1,3}-[0-9]'),
            125,
            ['c-0', 'bc-2', 'baa-5', 'cb-6', 'a-0'],
        ];

        yield 'json' => [
            static fn(): ArbitraryInterface => Gen::json(2),
            126,
            [true, [], -527, false, -600.0489853838493],
        ];
    }

    /**
     * Renders a generated value in a form that pins it exactly and stays
     * printable in this file: DateTimeImmutable as a formatted tag, binary or
     * control-character strings as a hex tag, everything else as-is.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeImmutable) {
            return 'datetime:' . $value->format('Y-m-d\TH:i:sP');
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        if (is_string($value) && (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{C}]/u', $value) === 1)) {
            return 'hex:' . bin2hex($value);
        }

        return $value;
    }
}
