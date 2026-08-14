<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * Renders eight 16-bit groups as the canonical IPv6 text form of RFC 5952:
 * lowercase hex, leading zeros stripped, and the longest run of zero groups
 * replaced by `::` — leftmost run on a tie, and never a run of a single group.
 *
 * The canonical form is what {@see \inet_ntop()} produces, which is why tests
 * can use it as an independent oracle; the implementation here is deliberately
 * hand-written so that oracle stays independent.
 *
 * @internal
 */
final class Ipv6Formatter
{
    public const int GROUPS = 8;

    private const int MIN_COMPRESSED_RUN = 2;

    /**
     * @param non-empty-list<int> $groups exactly {@see GROUPS} values in `[0, 65535]`
     *
     * @return non-empty-string
     */
    public static function format(array $groups): string
    {
        \assert(count($groups) === self::GROUPS);

        $hex = array_map(static fn(int $group): string => dechex($group), $groups);
        $run = self::compressibleRun($groups);

        if ($run === null) {
            return implode(':', $hex);
        }

        [$start, $length] = $run;

        return implode(':', array_slice($hex, 0, $start)) . '::' . implode(':', array_slice($hex, $start + $length));
    }

    /**
     * Start index and length of the longest run of zero groups worth
     * compressing, or null when there is none: no zero group at all, or only
     * runs shorter than {@see MIN_COMPRESSED_RUN}, which RFC 5952 keeps
     * spelled out. The leftmost run wins when several share the greatest
     * length.
     *
     * @param non-empty-list<int> $groups
     *
     * @return array{int, int}|null
     */
    private static function compressibleRun(array $groups): ?array
    {
        /** @var array{int, int}|null $best */
        $best = null;
        /** @var array{int, int}|null $current */
        $current = null;

        foreach ($groups as $index => $group) {
            if ($group !== 0) {
                $current = null;

                continue;
            }

            $current = $current === null ? [$index, 1] : [$current[0], $current[1] + 1];

            // Strictly greater keeps the leftmost run when lengths tie.
            if ($best === null || $current[1] > $best[1]) {
                $best = $current;
            }
        }

        return $best !== null && $best[1] >= self::MIN_COMPRESSED_RUN ? $best : null;
    }
}
