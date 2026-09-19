<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The three configuration enums are closed sets a consumer switches over; a
 * new case is a minor and a removed one a major, so the case lists are
 * pinned by name and in declaration order.
 */
#[Test]
#[Covers(Phase::class)]
#[Covers(EdgeCases::class)]
#[Covers(ShrinkMode::class)]
final class RunnerEnumsTest
{
    public function phaseAllIsEveryCaseInPipelineOrder(): void
    {
        Assert::same(Phase::all(), [Phase::Examples, Phase::Corpus, Phase::Random, Phase::Shrink]);
        Assert::same(Phase::all(), Phase::cases());
    }

    public function edgeCasesHasTheTwoModes(): void
    {
        Assert::same(array_map(static fn(EdgeCases $c): string => $c->name, EdgeCases::cases()), ['Mixin', 'None']);
    }

    public function shrinkModeHasTheThreeModes(): void
    {
        Assert::same(array_map(static fn(ShrinkMode $c): string => $c->name, ShrinkMode::cases()), ['Off', 'Bounded', 'Full']);
    }
}
