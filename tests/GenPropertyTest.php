<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Tests\Support\Check;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Property-style tests of the generator combinators, driven straight through
 * the engine via {@see Check::property()} — the attribute adapter lives in
 * property-testing-testo and cannot be used here.
 */
#[Test]
#[Covers(Gen::class)]
final class GenPropertyTest
{
    public function intBetweenGeneratesValuesInsideTheConfiguredRange(): void
    {
        Check::property(
            static function (int $value): void {
                Assert::true($value >= -100 && $value <= 100);
            },
            ['value' => Gen::intBetween(-100, 100)],
            runs: 50,
            seed: 123,
        );
    }

    public function stringOfGeneratesStringsInsideTheConfiguredLengthRange(): void
    {
        Check::property(
            static function (string $value): void {
                $length = mb_strlen($value, 'UTF-8');

                Assert::true($length >= 2 && $length <= 8);
            },
            ['value' => Gen::stringOf(2, 8)],
            runs: 50,
            seed: 456,
        );
    }

    public function arrayOfGeneratesListsWhoseElementsComeFromTheInnerGenerator(): void
    {
        Check::property(
            static function (array $values): void {
                foreach ($values as $value) {
                    Assert::true(is_int($value));
                    Assert::true($value >= 1 && $value <= 3);
                }
            },
            ['values' => Gen::arrayOf(Gen::intBetween(1, 3))],
            runs: 50,
            seed: 789,
        );
    }

    public function dictOfGeneratesMapsWithKeysAndValuesFromTheirGenerators(): void
    {
        Check::property(
            static function (array $map): void {
                foreach ($map as $key => $value) {
                    Assert::true(is_string($key));
                    Assert::true(is_int($value) && $value >= 1 && $value <= 3);
                }
            },
            ['map' => Gen::dictOf(Gen::stringOf(1, 5), Gen::intBetween(1, 3))],
            runs: 50,
            seed: 321,
        );
    }

    public function recordGeneratesEveryFieldWithinItsDomain(): void
    {
        Check::property(
            static function (array $record): void {
                Assert::true(is_int($record['age']) && $record['age'] >= 0 && $record['age'] <= 120);
                Assert::true(is_bool($record['active']));
                Assert::same(array_keys($record), ['age', 'active']);
            },
            ['record' => Gen::record([
                'age' => Gen::intBetween(0, 120),
                'active' => Gen::bool(),
            ])],
            runs: 50,
            seed: 654,
        );
    }

    public function flatMapKeepsTheDependentValueInsideTheSourceDomain(): void
    {
        Check::property(
            static function (array $pair): void {
                [$size, $index] = $pair;

                Assert::true($size >= 1 && $size <= 20);
                Assert::true($index >= 0 && $index < $size);
            },
            ['pair' => Gen::flatMap(
                Gen::intBetween(1, 20),
                static fn(int $size): ArbitraryInterface => Gen::tuple(
                    Gen::constant($size),
                    Gen::intBetween(0, $size - 1),
                ),
            )],
            runs: 100,
            seed: 987,
        );
    }

    public function mapTransformsEveryGeneratedValue(): void
    {
        Check::property(
            static function (int $even): void {
                Assert::same($even % 2, 0);
            },
            ['even' => Gen::map(Gen::intBetween(0, 100), static fn(int $x): int => $x * 2)],
            runs: 100,
            seed: 555,
        );
    }

    public function stringFromStaysInsideItsAlphabet(): void
    {
        Check::property(
            static function (string $identifier): void {
                Assert::same(preg_match('/^[a-z_]{1,16}$/', $identifier), 1);
            },
            ['identifier' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyz_', 1, 16)],
            runs: 100,
            seed: 111,
        );
    }

    public function coverEnforcesTheDistributionOfAPassingProperty(): void
    {
        Check::property(
            static function (int $n): void {
                // The generator is symmetric, so both parities comfortably clear 20%;
                // this dogfoods the coverage gate through the real runner.
                Classify::cover($n % 2 === 0, 'even', 20.0);
                Classify::cover($n % 2 !== 0, 'odd', 20.0);

                Assert::true($n >= 0 && $n <= 1000);
            },
            ['n' => Gen::intBetween(0, 1000)],
            runs: 200,
            seed: 222,
        );
    }

    public function ipv6GeneratesAddressesInTheCanonicalTextForm(): void
    {
        Check::property(
            static function (string $address): void {
                $packed = inet_pton($address);

                Assert::true($packed !== false);
                \assert(is_string($packed));

                // Canonical means the parser's own rendering is the same text:
                // lowercase, no leading zeros, longest zero run compressed.
                Assert::same(inet_ntop($packed), $address);

                // The shortened form is the branch address parsers get wrong;
                // the boundary bias reaches it in a small share of runs, and
                // the gate keeps that share from silently dropping to zero.
                Classify::cover(str_contains($address, '::'), 'compressed', 1.0);
                Classify::when(!str_contains($address, '::'), 'full form');
            },
            ['address' => Gen::ipv6()],
            runs: 300,
            seed: 555,
        );
    }
}
