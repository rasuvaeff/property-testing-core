<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Arbitrary;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;

/**
 * Generates UTC {@see DateTimeImmutable} values with microsecond precision,
 * drawn uniformly from an inclusive range, and shrinks toward the Unix epoch
 * (1970-01-01T00:00:00Z) through an integer ladder — clamped to the
 * configured range, the way {@see IntArbitrary} shrinks toward zero.
 *
 * The bounds keep their fraction: `min = 12:00:00.5` never generates
 * `12:00:00.0`, and a value at `.999999` is as likely as one on the second.
 *
 * @implements ArbitraryInterface<DateTimeImmutable>
 * @api
 */
final readonly class DateTimeArbitrary implements ArbitraryInterface
{
    private const int DEFAULT_MAX_TIMESTAMP = 4_102_444_800; // 2100-01-01T00:00:00Z

    private const int MICROSECONDS = 1_000_000;

    private IntArbitrary $microseconds;

    /**
     * @param ?DateTimeImmutable $min The earliest moment, fraction included; the Unix epoch when null.
     * @param ?DateTimeImmutable $max The latest moment, fraction included; 2100-01-01T00:00:00Z when null.
     *
     * @throws \InvalidArgumentException When $min is after $max.
     */
    public function __construct(?DateTimeImmutable $min = null, ?DateTimeImmutable $max = null)
    {
        $minMicro = $min instanceof DateTimeImmutable ? self::toMicroseconds($min) : 0;
        $maxMicro = $max instanceof DateTimeImmutable ? self::toMicroseconds($max) : self::DEFAULT_MAX_TIMESTAMP * self::MICROSECONDS;

        if ($minMicro > $maxMicro) {
            throw new \InvalidArgumentException('Min must be less than or equal to max');
        }

        $this->microseconds = new IntArbitrary($minMicro, $maxMicro);
    }

    /**
     * @throws \LogicException When PHP cannot build a moment from the drawn microseconds — a
     *         range within the integer bounds never triggers it.
     */
    #[\Override]
    public function generate(Random $random): Shrinkable
    {
        return $this->microseconds->generate($random)->map(self::fromMicroseconds(...));
    }

    private static function toMicroseconds(DateTimeImmutable $moment): int
    {
        return $moment->getTimestamp() * self::MICROSECONDS + (int) $moment->format('u');
    }

    private static function fromMicroseconds(int $microseconds): DateTimeImmutable
    {
        $seconds = intdiv($microseconds, self::MICROSECONDS);
        $fraction = $microseconds - $seconds * self::MICROSECONDS;

        // intdiv truncates toward zero; a negative moment with a fraction sits
        // in the second before its truncated quotient.
        if ($fraction < 0) {
            --$seconds;
            $fraction += self::MICROSECONDS;
        }

        // A `U` format yields the +00:00 offset zone; the named UTC zone is
        // what the pre-microsecond arbitrary produced and what callers compare.
        $moment = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $fraction));

        if (!$moment instanceof DateTimeImmutable) {
            throw new \LogicException(sprintf('Could not build a DateTimeImmutable from %d microseconds', $microseconds));
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
