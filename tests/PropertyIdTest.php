<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\PropertyId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Both of PHP's closure spellings have to be caught, and the newer one is the
 * trap: `{closure:/app/StackTest.php:19}` looks specific enough to trust while
 * being exactly the id that moves when someone inserts a line above.
 */
#[Test]
#[Covers(PropertyId::class)]
final class PropertyIdTest
{
    #[DataProvider('closureIdProvider')]
    public function aClosureDerivedIdIsReportedAsUnstable(string $id): void
    {
        $warning = PropertyId::unstableWarning($id);

        Assert::string($warning)->contains($id);
        Assert::string($warning)->contains('not stable');
        Assert::string($warning)->contains('pass an explicit property id');
    }

    public static function closureIdProvider(): iterable
    {
        yield 'php 8.3' => ['Rasuvaeff\Tests\StackTest::{closure}'];
        yield 'php 8.4+' => ['Rasuvaeff\Tests\StackTest::{closure:/app/tests/StackTest.php:19}'];
        yield 'nested closure' => ['Rasuvaeff\Tests\StackTest::{closure:{closure:/app/t.php:4}:9}'];
        yield 'bare marker' => ['{closure'];
    }

    #[DataProvider('stableIdProvider')]
    public function aMethodDerivedIdHasNothingToWarnAbout(string $id): void
    {
        Assert::null(PropertyId::unstableWarning($id));
    }

    public static function stableIdProvider(): iterable
    {
        yield 'test method' => ['Rasuvaeff\Tests\StackTest::pushThenPopRestoresTheStack'];
        yield 'explicit id' => ['stack::push-then-pop'];
        yield 'empty' => [''];
        // The marker is matched as written; a method that merely mentions
        // closures by name is not a closure.
        yield 'method named after closures' => ['Rasuvaeff\Tests\ClosureTest::closureIsCalledOnce'];
    }
}
