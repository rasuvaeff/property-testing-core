<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\FailureIdentity;
use Rasuvaeff\PropertyTesting\Tests\Support\EngineStand;
use Rasuvaeff\PropertyTesting\Tests\Support\FailingHelper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Pins the criterion the shrink descent accepts candidates by: the exception
 * class, plus the place in the property's own file — and every way that place
 * can be unknown, where the class is the whole identity again.
 */
#[Test]
#[Covers(FailureIdentity::class)]
final class FailureIdentityTest
{
    /**
     * A path no trace of this test can contain: these cases do not depend on
     * where the engine's own frames begin.
     */
    private const string ELSEWHERE = '/engine/PropertyRunner.php';

    public function withoutAnOriginalFailureAnyOutcomeIsTheSameFailure(): void
    {
        $identity = FailureIdentity::of(self::class . '::property', null, self::ELSEWHERE);

        Assert::true($identity->matches(new \RuntimeException('anything')));
        Assert::true($identity->matches(null));
    }

    public function aCandidateThatFailedWithoutAThrowableMatches(): void
    {
        $identity = FailureIdentity::of(self::class . '::property', new \RuntimeException('original'), self::ELSEWHERE);

        Assert::true($identity->matches(null));
    }

    public function aDifferentExceptionClassIsADifferentFailure(): void
    {
        $identity = FailureIdentity::of(self::class . '::property', new \RuntimeException('original'), self::ELSEWHERE);

        Assert::false($identity->matches(new \LogicException('original')));
    }

    public function theSameLineOfThePropertyFileIsTheSameFailure(): void
    {
        [$original, $candidate] = [new \RuntimeException('a'), new \RuntimeException('b')];

        Assert::true(FailureIdentity::of(self::class . '::property', $original, self::ELSEWHERE)->matches($candidate));
    }

    public function anotherLineOfThePropertyFileIsAnotherFailure(): void
    {
        $original = new \RuntimeException('a');
        $candidate = new \RuntimeException('b');

        Assert::false(FailureIdentity::of(self::class . '::property', $original, self::ELSEWHERE)->matches($candidate));
    }

    public function aFailureRaisedElsewhereIsPlacedAtTheLineThatCalledIn(): void
    {
        $raise = fn(string $message): \Throwable => $this->caught(static fn(): never => FailingHelper::raise($message));
        $original = $raise('a');
        $sameCall = $raise('b');
        $anotherCall = $this->caught(static fn(): never => FailingHelper::raise('c'));

        $identity = FailureIdentity::of(self::class . '::property', $original, self::ELSEWHERE);

        // The throw is the helper's line either way; the property's line is not.
        Assert::true($identity->matches($sameCall));
        Assert::false($identity->matches($anotherCall));
    }

    public function anIdThatNamesNoLoadedClassLeavesTheClassAsTheWholeIdentity(): void
    {
        $original = new \RuntimeException('a');
        $candidate = new \RuntimeException('b');

        foreach (['property', 'Rasuvaeff\\PropertyTesting\\Tests\\Internal\\NoSuchTest::property'] as $id) {
            $identity = FailureIdentity::of($id, $original, self::ELSEWHERE);

            Assert::true($identity->matches($candidate));
            Assert::false($identity->matches(new \LogicException('b')));
        }
    }

    public function theEngineBoundaryEndsTheSearchForThePlace(): void
    {
        // Beyond the engine's own file the stack is the engine calling the body.
        // The line of this file that called in lies there, so it names no place
        // in the property: an adapter that invokes the body from the test file
        // must not report its own call as the site of every failure.
        $original = $this->caught(static fn(): never => EngineStand::raise('a'));
        $candidate = $this->caught(static fn(): never => EngineStand::raise('b'));
        $boundary = (string) (new \ReflectionClass(EngineStand::class))->getFileName();

        Assert::true(FailureIdentity::of(self::class . '::property', $original, $boundary)->matches($candidate));
        // Without the bound, the two calls into the stand are two places.
        Assert::false(FailureIdentity::of(self::class . '::property', $original, self::ELSEWHERE)->matches($candidate));
    }

    public function aCandidateWhosePlaceIsUnknownMatchesOnTheClass(): void
    {
        $original = new \RuntimeException('a');
        $candidate = $this->caught(static fn(): never => EngineStand::raise('b'));
        $boundary = (string) (new \ReflectionClass(EngineStand::class))->getFileName();

        $identity = FailureIdentity::of(self::class . '::property', $original, $boundary);

        Assert::true($identity->matches($candidate));
        Assert::false($identity->matches(new \LogicException('b')));
    }

    private function caught(\Closure $body): \Throwable
    {
        try {
            $body();
        } catch (\Throwable $failure) {
            return $failure;
        }

        throw new \LogicException('The body was expected to throw');
    }
}
