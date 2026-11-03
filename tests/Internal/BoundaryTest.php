<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\Boundary;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Boundary::class)]
final class BoundaryTest
{
    public function intsKeepsOnlyInRangeCandidatesInInterestOrder(): void
    {
        Assert::same(Boundary::ints(-100, 100), [0, 1, -1, -100, 100]);
    }

    public function intsExcludesCandidatesOutsideTheRange(): void
    {
        // 0, 1 and -1 fall outside [3, 9]; only the bounds remain.
        Assert::same(Boundary::ints(3, 9), [3, 9]);
    }

    public function intsDeduplicatesWhenBoundsCoincideWithZeroOrOne(): void
    {
        Assert::same(Boundary::ints(0, 1_000_000), [0, 1, 1_000_000]);
    }

    public function intsOfADegenerateRangeYieldsTheSingleValue(): void
    {
        Assert::same(Boundary::ints(5, 5), [5]);
    }

    public function floatsIncludeZeroAndMinWithinTheHalfOpenRange(): void
    {
        Assert::same(Boundary::floats(0.0, 1.0), [0.0]);
    }

    public function floatsUseTheLowerBoundWhenZeroIsOutOfRange(): void
    {
        Assert::same(Boundary::floats(2.0, 10.0), [2.0]);
    }

    public function floatsIncludeBothZeroAndANegativeLowerBound(): void
    {
        Assert::same(Boundary::floats(-5.0, 5.0), [0.0, -5.0]);
    }

    public function floatsExcludeTheDegenerateRange(): void
    {
        // [5.0, 5.0) is empty, so there is no in-range boundary.
        Assert::same(Boundary::floats(5.0, 5.0), []);
    }

    public function unicodeStringsListEveryCandidateInInterestOrder(): void
    {
        Assert::same(Boundary::strings(0, 100, unicode: true), ['', ' ', '0', "\n", "a\n", "e\u{0301}"]);
    }

    public function asciiStringsLeaveOutTheCandidatesOutsidePrintableAscii(): void
    {
        Assert::same(Boundary::strings(0, 100, unicode: false), ['', ' ', '0']);
    }

    public function stringsKeepOnlyCandidatesWithinTheLengthRangeCountedInCharacters(): void
    {
        // "e\u{0301}" is three bytes and two characters: it fits [1, 2] by
        // character count, and the empty string does not.
        Assert::same(Boundary::strings(1, 2, unicode: true), [' ', '0', "\n", "a\n", "e\u{0301}"]);
        Assert::same(Boundary::strings(2, 2, unicode: true), ["a\n", "e\u{0301}"]);
        Assert::same(Boundary::strings(0, 1, unicode: true), ['', ' ', '0', "\n"]);
    }

    public function stringsAreEmptyWhenNoCandidateFitsTheLengthRange(): void
    {
        Assert::same(Boundary::strings(3, 10, unicode: true), []);
        Assert::same(Boundary::strings(2, 5, unicode: false), []);
    }
}
