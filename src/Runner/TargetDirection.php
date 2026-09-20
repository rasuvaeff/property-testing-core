<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Which way a {@see \Rasuvaeff\PropertyTesting\Target} label is pushed.
 *
 * @api
 */
enum TargetDirection: string
{
    case Maximize = 'maximize';
    case Minimize = 'minimize';

    /**
     * Whether $candidate is an improvement on $best in this direction.
     *
     * @param float $candidate The score under consideration.
     * @param float $best The best so far.
     */
    public function improves(float $candidate, float $best): bool
    {
        return $this === self::Maximize ? $candidate > $best : $candidate < $best;
    }
}
