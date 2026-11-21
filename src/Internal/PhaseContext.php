<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;

/**
 * What every attempt of one phase shares: the property and its executor, the
 * phase's random stream and seed, its counters and target pool, the listeners,
 * the path to follow instead of searching, and the run count and budgets the
 * exits are judged against. One phase, one context — a corpus seed replay is a
 * phase of its own.
 *
 * @internal Driven by the property runner.
 */
final readonly class PhaseContext
{
    /**
     * @param list<PropertyListener> $listeners
     * @param ?string $path The descent to follow instead of searching for one; null searches.
     */
    public function __construct(
        public PropertyDefinition $property,
        public TrialExecutor $executor,
        public Random $random,
        public int $seed,
        public PhaseCounters $counters,
        public SearchPool $pool,
        public array $listeners,
        public ?string $path,
        public int $runs,
        public int $maxDiscards,
        public int $maxSkips,
    ) {}
}
