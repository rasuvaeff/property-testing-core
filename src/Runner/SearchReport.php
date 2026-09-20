<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * What the targeted search of a run amounted to, as data: how many bodies
 * the search phase executed and where every label ended up. Built once,
 * when the run finishes, and carried on {@see \Rasuvaeff\PropertyTesting\Event\PropertyFinished}
 * beside the {@see DistributionReport} — only for a run that targeted
 * something, so a property that never calls
 * {@see \Rasuvaeff\PropertyTesting\Target} pays nothing and sees nothing.
 *
 * @api
 */
final readonly class SearchReport
{
    /**
     * @param int $evaluations Bodies executed in the search phase (the random phase's are in the
     *        distribution report).
     * @param array<string, TargetOutcome> $targets By label, in the order the labels were first
     *        reported.
     */
    public function __construct(
        public int $evaluations,
        public array $targets,
    ) {}

    /**
     * @return array{evaluations: int, targets: array<string, array{direction: string, best: ?float, improvements: int, recalled: int}>}
     */
    public function toArray(): array
    {
        return [
            'evaluations' => $this->evaluations,
            'targets' => array_map(
                static fn(TargetOutcome $target): array => [
                    'direction' => $target->direction->value,
                    'best' => $target->best,
                    'improvements' => $target->improvements,
                    'recalled' => $target->recalled,
                ],
                $this->targets,
            ),
        ];
    }
}
