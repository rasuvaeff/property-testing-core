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
                'hex:f2a0ae9df385b4b7f0a1a79af4828c8af3bcb9b2f1af8487f1bdb1a7f29f8f8af290aebcf3aab29bf2a78593f387a385f199b496f2b394b6f2acb095f3a0a3a9f188b297f0abbbbdf383a49bf0aa9089e9bda9f2888397f0ae8098f1b9b680f395bcb4f3a1b8afe6918df19e84b7f38cb39ff0bc83a2f3a688a7f18681aef28c9ea4f3bf9aaaf2929a9af39e9885f0a09e89f2a09891f0bbae85f3ba8ab2f1b6beb5f2b28ca1f0b9a5a2',
                'hex:e8bf9bf0beaabef1a0a7b4f184bf96f1b0a9b1f3a58ab7f3879991f48ea08ef0a7acb0f28791bbf39b9e93f094bebff18e93b8f184bb98f0b79894f39c99b5f386a2adf09f9186f18eb68af1be879ff39eab8ef2828186e6bdb4f3ac9da1f295baa7f2ae90b1f48ea2a8f2b0ba9cf2a49ebdf3a39e82f3a087b6f3888996f2aab482f3a586bef3ac94aaf3aa9f81f48c8bbdf2808398f28684b6f1bc8280f28a81aaf1928eb4f3b1b0a6f38cab81f3bba9aef29da68df28698a4f18896b4',
                'hex:f2bda79af292b5bdf1858198f1b881bdf0b38f95f4879c83f191b6bdf19ea6a6f2b9a89ef2ae879af28b91b1f3ad95a0f29297af',
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
                'datetime:1985-02-25T06:50:42+00:00',
                'datetime:2077-09-07T21:25:41+00:00',
                'datetime:1998-09-08T07:30:16+00:00',
                'datetime:2001-09-15T15:04:20+00:00',
                'datetime:2079-12-13T01:56:17+00:00',
            ],
        ];

        yield 'ipv4' => [
            Gen::ipv4(...),
            122,
            ['154.182.62.108', '187.207.182.21', '169.78.228.51', '141.173.1.188', '255.42.48.255'],
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
            [null, true, 'uj9 7738', [745, -600.0489853838493], 1000],
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
