<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * Resolved knobs of one property run. The engine never reads the process
 * environment: an adapter resolves its attribute/env/defaults into this value
 * and hands it over, so a direct {@see PropertyRunner} call has no hidden
 * process state.
 *
 * @api
 */
final readonly class PropertyConfig
{
    /**
     * @param int $runs Number of successful checks to complete. Discarded runs do not count.
     * @param ?int $seed Seed of the random phase. Null lets the runner draw a random one.
     * @param ?int $maxShrinks Cap on accepted shrink steps. Null means no cap, 0 disables shrinking.
     * @param ?int $maxDiscards Maximum discarded runs before giving up. Null uses ten times $runs
     *        (saturating to PHP_INT_MAX).
     * @param ?int $timeoutMs Wall-clock deadline for a single run in milliseconds; null disables it.
     * @param ?int $budgetMs Wall-clock budget for the whole random phase in milliseconds; null disables it.
     */
    public function __construct(
        public int $runs = 100,
        public ?int $seed = null,
        public ?int $maxShrinks = null,
        public ?int $maxDiscards = null,
        public ?int $timeoutMs = null,
        public ?int $budgetMs = null,
    ) {
        if ($runs < 1) {
            throw new \InvalidArgumentException('Runs must be greater than or equal to 1');
        }
        if ($maxShrinks !== null && $maxShrinks < 0) {
            throw new \InvalidArgumentException('Max shrinks must be greater than or equal to 0');
        }
        if ($maxDiscards !== null && $maxDiscards < 0) {
            throw new \InvalidArgumentException('Max discards must be greater than or equal to 0');
        }
        if ($timeoutMs !== null && $timeoutMs < 1) {
            throw new \InvalidArgumentException('Timeout must be greater than or equal to 1 millisecond');
        }
        if ($budgetMs !== null && $budgetMs < 1) {
            throw new \InvalidArgumentException('Budget must be greater than or equal to 1 millisecond');
        }
    }
}
