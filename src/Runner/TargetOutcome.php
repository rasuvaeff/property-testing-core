<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * What the search did for one {@see \Rasuvaeff\PropertyTesting\Target} label.
 *
 * @api
 */
final readonly class TargetOutcome
{
    /**
     * @param string $label The label as reported to `Target::maximize()`/`minimize()`.
     * @param TargetDirection $direction Which way the label was pushed.
     * @param ?float $best The best score any passing run reached, in that direction; null when no
     *        run reported the label.
     * @param int $improvements How many times the best improved, across the random and the
     *        search phase — each one was a {@see \Rasuvaeff\PropertyTesting\Event\TargetImproved}.
     * @param int $recalled How many stored best inputs the search started from
     *        ({@see SearchCorpus}).
     */
    public function __construct(
        public string $label,
        public TargetDirection $direction,
        public ?float $best,
        public int $improvements,
        public int $recalled = 0,
    ) {}
}
