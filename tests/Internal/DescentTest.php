<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\Descent;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Descent::class)]
final class DescentTest
{
    public function aFreshDescentIsWhereItStarted(): void
    {
        $trees = ['x' => Shrinkable::leaf(5)];
        $descent = new Descent($trees, [], ['n' => 1]);

        Assert::same($descent->trees, $trees);
        Assert::same($descent->tape, []);
        Assert::same($descent->notes, ['n' => 1]);
        Assert::same($descent->steps, 0);
        Assert::same($descent->trials, 0);
        Assert::null($descent->failure);
        Assert::same($descent->path(), '');
        Assert::same($descent->errors(), []);
    }

    public function acceptingMovesTheDescentAndRecordsTheStep(): void
    {
        $descent = new Descent(['x' => Shrinkable::leaf(5)], [], []);
        $smaller = ['x' => Shrinkable::leaf(2)];
        $tape = [Shrinkable::leaf(1)];
        $failure = new \RuntimeException('still failing');

        $descent->accept(['name' => 'x', 'index' => 3], $smaller, $tape, ['n' => 2], $failure);
        $descent->accept(['name' => 'draw#1', 'index' => 0], $smaller, [], [], null);

        Assert::same($descent->trees, $smaller);
        Assert::same($descent->tape, []);
        Assert::same($descent->notes, []);
        Assert::null($descent->failure);
        Assert::same($descent->steps, 2);
        Assert::same($descent->path(), 'x:3/draw#1:0');
    }

    public function errorsAreKeptOnceEachUpToTheCap(): void
    {
        $descent = new Descent([], [], []);

        $descent->recordError(new \LogicException('broke'));
        $descent->recordError(new \LogicException('broke'));
        $descent->recordError(new \DomainException('broke'));

        Assert::same($descent->errors(), ['LogicException: broke', 'DomainException: broke']);

        for ($i = 0; $i < 20; ++$i) {
            $descent->recordError(new \RuntimeException('number ' . $i));
        }

        $errors = $descent->errors();
        Assert::same(count($errors), Descent::MAX_ERRORS);
        Assert::same($errors[Descent::MAX_ERRORS - 1], 'RuntimeException: number 7');
    }
}
