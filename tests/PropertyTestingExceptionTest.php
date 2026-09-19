<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\PathViolationException;
use Rasuvaeff\PropertyTesting\PropertyTestingException;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\StateMachine\PostconditionViolationException;
use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * `catch (PropertyTestingException)` catches everything the engine reports
 * — every `@api` exception in `src/`, found by listing rather than by hand,
 * so a new one cannot be added without joining the marker.
 */
#[Test]
#[Covers(PropertyTestingException::class)]
final class PropertyTestingExceptionTest
{
    #[DataProvider('apiExceptions')]
    public function everyReportedExceptionImplementsTheMarker(string $class): void
    {
        Assert::true(is_subclass_of($class, PropertyTestingException::class), $class);
        Assert::true(is_subclass_of($class, \RuntimeException::class), $class);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function apiExceptions(): iterable
    {
        foreach ([
            CoverageViolationException::class,
            DeadlineExceededException::class,
            ExampleViolationException::class,
            GaveUpException::class,
            GenerationExhaustedException::class,
            PathViolationException::class,
            PropertyViolationException::class,
            RegressionViolationException::class,
            TimeBudgetExceededException::class,
            PostconditionViolationException::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    public function theTenAreEveryExceptionClassInSrc(): void
    {
        $found = [];

        foreach ([...glob(__DIR__ . '/../src/*Exception.php') ?: [], ...glob(__DIR__ . '/../src/*/*Exception.php') ?: []] as $file) {
            $name = basename($file, '.php');

            if ($name !== 'PropertyTestingException') {
                $found[] = $name;
            }
        }
        sort($found);

        $expected = array_map(
            static fn(array $case): string => (new \ReflectionClass($case[0]))->getShortName(),
            array_values(iterator_to_array(self::apiExceptions())),
        );
        sort($expected);

        Assert::same($found, $expected);
    }

    public function theMarkerIsAThrowable(): void
    {
        Assert::true(is_subclass_of(PropertyTestingException::class, \Throwable::class));
    }

    /**
     * A discard is not a failure: a body that catches the marker to inspect
     * what the engine reported must not swallow its own `Assume::that()`.
     */
    public function assumptionSkippedStaysOutsideTheMarker(): void
    {
        Assert::false(is_subclass_of(AssumptionSkipped::class, PropertyTestingException::class));

        try {
            Assume::that(condition: false);

            Assert::fail('expected the assumption to discard');
        } catch (PropertyTestingException) {
            Assert::fail('the marker must not catch a discard');
        } catch (AssumptionSkipped $e) {
            Assert::same($e->getMessage(), 'Assumption not satisfied; property run discarded');
        }
    }
}
