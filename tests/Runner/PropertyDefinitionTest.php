<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PropertyDefinition::class)]
final class PropertyDefinitionTest
{
    public function throwsWhenAParameterHasNoGenerator(): void
    {
        $thrown = null;

        try {
            new PropertyDefinition(
                id: 'Case::prop',
                name: 'prop',
                generators: ['x' => Gen::int()],
                parameterNames: ['x', 'y'],
            );
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        Assert::instanceOf($thrown, \InvalidArgumentException::class);
        Assert::same($thrown->getMessage(), 'No generator for parameter "y"');
    }

    public function throwsWhenAnExampleTupleLengthMismatchesTheParameters(): void
    {
        $thrown = null;

        try {
            new PropertyDefinition(
                id: 'Case::prop',
                name: 'prop',
                generators: ['x' => Gen::int(), 'y' => Gen::int()],
                parameterNames: ['x', 'y'],
                examples: [[1, 2], [3]],
            );
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        Assert::instanceOf($thrown, \InvalidArgumentException::class);
        Assert::same($thrown->getMessage(), 'Example #1 must contain exactly 2 value(s), got 1');
    }

    public function allowsGeneratorKeysBeyondTheParameterList(): void
    {
        $definition = new PropertyDefinition(
            id: 'Case::prop',
            name: 'prop',
            generators: ['x' => Gen::int(), 'unused' => Gen::bool()],
            parameterNames: ['x'],
        );

        Assert::same($definition->parameterNames, ['x']);
    }

    public function defaultsToNoExamplesAndCorpusReplayOn(): void
    {
        $definition = new PropertyDefinition(
            id: 'Case::prop',
            name: 'prop',
            generators: ['x' => Gen::int()],
            parameterNames: ['x'],
        );

        Assert::same($definition->examples, []);
        Assert::true($definition->replayRegressions);
        Assert::same($definition->config->runs, 100);
    }
}
