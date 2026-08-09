<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Arbitrary;

use Rasuvaeff\PropertyTesting\Arbitrary\UuidArbitrary;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UuidArbitrary::class)]
final class UuidArbitraryTest
{
    public function generateProducesCanonicalVersion4Format(): void
    {
        $uuid = (new UuidArbitrary())->generate(new Random(1))->value;

        Assert::same(
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $uuid),
            1,
        );
    }

    public function generateSetsTheVersionNibbleToFour(): void
    {
        $uuid = (new UuidArbitrary())->generate(new Random(7))->value;

        // Position 14 is the version nibble in the canonical form.
        Assert::same($uuid[14], '4');
    }

    public function generateSetsTheVariantNibble(): void
    {
        $uuid = (new UuidArbitrary())->generate(new Random(7))->value;

        // Position 19 is the variant nibble; RFC 4122 requires 8, 9, a or b.
        Assert::true(in_array($uuid[19], ['8', '9', 'a', 'b'], strict: true));
    }

    public function generateIsReproducibleForAGivenSeed(): void
    {
        Assert::same(
            (new UuidArbitrary())->generate(new Random(123))->value,
            (new UuidArbitrary())->generate(new Random(123))->value,
        );
    }

    public function generateAlwaysProducesValidVersion4UuidsAcrossManyDraws(): void
    {
        $arbitrary = new UuidArbitrary();
        $random = new Random(1);

        for ($i = 0; $i < 300; ++$i) {
            Assert::same(
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $arbitrary->generate($random)->value),
                1,
            );
        }
    }

    public function generateIsByteExactForAGivenSeed(): void
    {
        // Pins the exact byte layout so any change to the bit-masking or the
        // substring offsets is caught.
        Assert::same(
            (new UuidArbitrary())->generate(new Random(1))->value,
            '25f4c16a-eb80-47ff-8c2f-67b84814bcee',
        );
    }

    public function versionByteLowNibbleStaysRandom(): void
    {
        // Only the HIGH nibble of byte 6 is forced (to 4); the low nibble
        // (position 15) keeps its random bits, so even digits must appear —
        // a mask that also sets bit 0 would make it permanently odd.
        $arbitrary = new UuidArbitrary();
        $random = new Random(1);
        $sawEven = false;

        for ($i = 0; $i < 300 && !$sawEven; ++$i) {
            $uuid = $arbitrary->generate($random)->value;
            $sawEven = in_array($uuid[15], ['0', '2', '4', '6', '8', 'a', 'c', 'e'], strict: true);
        }

        Assert::true($sawEven);
    }

    public function variantByteKeepsItsLowRandomBits(): void
    {
        // The variant mask (& 0x3F | 0x80) must preserve bit 0 of byte 8, whose
        // low nibble renders at position 20: odd digits must appear — a mask
        // clearing bit 0 would make it permanently even.
        $arbitrary = new UuidArbitrary();
        $random = new Random(1);
        $sawOdd = false;

        for ($i = 0; $i < 300 && !$sawOdd; ++$i) {
            $uuid = $arbitrary->generate($random)->value;
            $sawOdd = in_array($uuid[20], ['1', '3', '5', '7', '9', 'b', 'd', 'f'], strict: true);
        }

        Assert::true($sawOdd);
    }

    public function generateConsumesExactlySixteenBytesPerUuid(): void
    {
        // Two draws from ONE stream: the first uuid is built from exactly 16
        // bytes, so the second is pinned too — drawing even one extra byte
        // would advance the engine differently and shift the second uuid.
        $arbitrary = new UuidArbitrary();
        $random = new Random(1);

        Assert::same($arbitrary->generate($random)->value, '25f4c16a-eb80-47ff-8c2f-67b84814bcee');
        Assert::same($arbitrary->generate($random)->value, 'ff7e0700-89c3-4c20-8baa-654d851ec1ff');
    }

    public function shrinkYieldsNothing(): void
    {
        Assert::same(Trees::childValues((new UuidArbitrary())->generate(new Random(1))), []);
    }
}
