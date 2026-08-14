<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * How often one {@see \Rasuvaeff\PropertyTesting\Classify} label occurred,
 * together with the {@see \Rasuvaeff\PropertyTesting\Classify::cover()}
 * threshold it was registered with, if any.
 *
 * The share is over the property's successful checks, never its attempts: a
 * discarded run produced no input the label could describe, so counting it in
 * the denominator would report a smaller share for the same generator merely
 * because `Assume::that()` rejected more inputs.
 *
 * @api
 */
final readonly class LabelShare
{
    /**
     * @param string $label The label as the property body recorded it.
     * @param int $count Successful checks that recorded it (once per run, however often it was called).
     * @param float $percent That count as a percentage of the successful checks; 0.0 when there were none.
     * @param ?float $required The percentage `Classify::cover()` demanded, or null when the label
     *        was only classified. A required label the runs never reached still appears, with a
     *        count of zero — that is the case worth seeing.
     */
    public function __construct(
        public string $label,
        public int $count,
        public float $percent,
        public ?float $required = null,
    ) {}

    /**
     * Whether the recorded share reaches the requirement. True for a label
     * without one — there is nothing to miss.
     *
     * This is arithmetic, not a verdict: whether the engine *enforced* it is
     * {@see DistributionReport::$coverageAssessed}, because a run that gave up
     * or ran out of budget never reached the assessment.
     */
    public function meetsRequirement(): bool
    {
        return $this->required === null || $this->percent >= $this->required;
    }
}
