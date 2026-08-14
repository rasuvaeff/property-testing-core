<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\LabelShare;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(LabelShare::class)]
final class LabelShareTest
{
    #[DataProvider('requirementProvider')]
    public function aShareMeetsWhatWasDemandedOfIt(?float $required, float $percent, bool $expected): void
    {
        Assert::same((new LabelShare('label', count: 1, percent: $percent, required: $required))->meetsRequirement(), $expected);
    }

    /**
     * @return iterable<string, array{?float, float, bool}>
     */
    public static function requirementProvider(): iterable
    {
        yield 'a label nobody required' => [null, 0.0, true];
        yield 'above the requirement' => [5.0, 12.5, true];
        // `cover()` demands "at least", so the exact threshold passes — the
        // same boundary the coverage verdict uses.
        yield 'exactly at the requirement' => [12.5, 12.5, true];
        yield 'below the requirement' => [30.0, 29.9, false];
        yield 'required but never recorded' => [1.0, 0.0, false];
    }

    public function aShareIsWhatItWasBuiltFrom(): void
    {
        $share = new LabelShare('even', count: 3, percent: 60.0, required: 25.0);

        Assert::same($share->label, 'even');
        Assert::same($share->count, 3);
        Assert::same($share->percent, 60.0);
        Assert::same($share->required, 25.0);
    }

    public function aClassifiedLabelCarriesNoRequirementByDefault(): void
    {
        Assert::null((new LabelShare('odd', count: 1, percent: 10.0))->required);
    }
}
