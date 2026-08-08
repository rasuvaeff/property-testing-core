<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates RFC 4122 version 4 (random) UUID strings in the canonical
 * `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` form, where the version nibble is `4`
 * and the variant nibble is one of `8`, `9`, `a`, `b`.
 *
 * A UUID is an opaque identifier with no meaningful ordering, so it does not
 * shrink. Randomness comes from the seeded {@see Random}, so generated UUIDs are
 * reproducible but NOT suitable for security purposes.
 *
 * @implements ArbitraryInterface<non-empty-string>
 * @api
 */
final readonly class UuidArbitrary implements ArbitraryInterface
{
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        $bytes = $random->bytes(16);

        // Set the version (4) and variant (RFC 4122) bits.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return Shrinkable::leaf(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }
}
