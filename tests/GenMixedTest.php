<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\CorpusDocument;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Rasuvaeff\PropertyTesting\ValueRenderer;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Gen::class)]
final class GenMixedTest
{
    public function drawsEveryKindOfValue(): void
    {
        $types = [];

        foreach (Gen::sample(Gen::mixed(), 2_000, 3) as $value) {
            $types[get_debug_type($value)] = true;
        }

        ksort($types);

        Assert::same(array_keys($types), [
            'ArrayObject',
            'DateTimeImmutable',
            'array',
            'bool',
            'float',
            'int',
            'null',
            'stdClass',
            'string',
        ]);
    }

    public function drawsTheSpecialFloats(): void
    {
        $seen = ['nan' => false, 'inf' => false, '-inf' => false];

        foreach (Gen::sample(Gen::mixed(maxDepth: 1), 3_000, 4) as $value) {
            if (!is_float($value)) {
                continue;
            }

            $seen['nan'] = $seen['nan'] || is_nan($value);
            $seen['inf'] = $seen['inf'] || $value === INF;
            $seen['-inf'] = $seen['-inf'] || $value === -INF;
        }

        Assert::same($seen, ['nan' => true, 'inf' => true, '-inf' => true]);
    }

    public function neverDrawsAClosureAResourceOrAnUnlistedObject(): void
    {
        foreach (Gen::sample(Gen::mixed(maxDepth: 3), 1_000, 5) as $value) {
            self::assertOnlyListedTypes($value);
        }
    }

    public function nestingStopsAtTheMaximumDepth(): void
    {
        $deepest = 0;

        foreach (Gen::sample(Gen::mixed(maxDepth: 2), 2_000, 6) as $value) {
            $deepest = max($deepest, self::depth($value));
        }

        Assert::same($deepest, 2);

        foreach (Gen::sample(Gen::mixed(maxDepth: 1), 500, 6) as $value) {
            Assert::true(self::depth($value) <= 1);
        }
    }

    public function containersHoldAtMostFourElements(): void
    {
        $fullList = false;

        foreach (Gen::sample(Gen::mixed(maxDepth: 1), 3_000, 7) as $value) {
            if (is_array($value) || $value instanceof \ArrayObject || $value instanceof \stdClass) {
                Assert::true(count((array) $value) <= 4);
            }

            $fullList = $fullList || (is_array($value) && array_is_list($value) && count($value) === 4);
        }

        Assert::true($fullList);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function refusesADepthBelowOne(): void
    {
        Gen::mixed(maxDepth: 0);
    }

    public function aValueShrinksToTheSimplestValuesFirst(): void
    {
        $node = Trees::generateWhere(
            Gen::mixed(),
            static fn(mixed $value): bool => is_array($value) && count($value) > 1,
        );

        Assert::same(array_slice(Trees::childValues($node), 0, 5), [null, false, 0, '', []]);
    }

    public function aNestedElementShrinksToNullFirst(): void
    {
        $node = Trees::generateWhere(
            Gen::mixed(maxDepth: 1),
            static fn(mixed $value): bool => is_array($value) && array_is_list($value) && count($value) === 1 && is_int($value[0]) && $value[0] !== 0,
        );

        Assert::true(in_array([null], Trees::childValues($node), strict: true));
    }

    public function falsifiesANarrowingThatForgetsObjectsAndShrinksToAnEmptyOne(): void
    {
        $result = $this->run(static function (mixed $input): void {
            if (!is_array($input)) {
                Assert::true(is_string((string) $input));
            }
        });

        Assert::instanceOf($result, Falsified::class);
        Assert::instanceOf($result->counterExample()->failure, \Error::class);

        $shrunk = $result->counterExample()->shrunkArguments['input'];
        Assert::true($shrunk instanceof \stdClass || $shrunk instanceof \ArrayObject || $shrunk instanceof \DateTimeImmutable);
        Assert::true(!$shrunk instanceof \stdClass || get_object_vars($shrunk) === []);
        Assert::true(!$shrunk instanceof \ArrayObject || $shrunk->getArrayCopy() === []);
    }

    public function aFalsifiedResultHoldingAnObjectStillSerializes(): void
    {
        // The engine's serialization contract holds with argument-free traces.
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            $result = $this->run(static function (mixed $input): void {
                if (is_object($input)) {
                    throw new \RuntimeException('object');
                }
            });

            Assert::instanceOf($result, Falsified::class);

            $restored = unserialize(serialize($result));

            Assert::instanceOf($restored, Falsified::class);
            Assert::same(
                ValueRenderer::render($restored->counterExample()->shrunkArguments['input']),
                ValueRenderer::render($result->counterExample()->shrunkArguments['input']),
            );
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    public function aCounterexampleHoldingAnObjectIsStoredAsASeed(): void
    {
        $counterExample = new CounterExample(
            seed: 9,
            runsBeforeFailure: 2,
            originalArguments: ['input' => new \stdClass()],
            shrunkArguments: ['input' => new \stdClass()],
        );

        $entry = CorpusDocument::encodeEntry($counterExample, ['input'], 3);

        Assert::same($entry['kind'], 'seed');
        Assert::same($entry['seed'], 9);
    }

    private function run(\Closure $body): PropertyResult
    {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'mixed::property',
                name: 'property',
                generators: ['input' => Gen::mixed()],
                parameterNames: ['input'],
                config: new PropertyConfig(runs: 300, seed: 21),
            ),
            new CallableTrialExecutor($body),
        );
    }

    private static function assertOnlyListedTypes(mixed $value): void
    {
        if (is_array($value) || $value instanceof \ArrayObject || $value instanceof \stdClass) {
            foreach ((array) $value as $item) {
                self::assertOnlyListedTypes($item);
            }

            if (is_array($value)) {
                return;
            }
        }

        Assert::true(
            $value === null
            || is_scalar($value)
            || $value instanceof \DateTimeImmutable
            || $value::class === \stdClass::class
            || $value::class === \ArrayObject::class,
        );
    }

    private static function depth(mixed $value): int
    {
        if (!is_array($value) && !$value instanceof \ArrayObject && !$value instanceof \stdClass) {
            return 0;
        }

        $inner = 0;

        foreach ((array) $value as $item) {
            $inner = max($inner, self::depth($item));
        }

        return 1 + $inner;
    }
}
