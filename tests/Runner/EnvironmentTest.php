<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\Environment;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Environment::class)]
final class EnvironmentTest
{
    public function nothingSetIsNothingParsed(): void
    {
        $environment = Environment::fromVariables([]);

        Assert::null($environment->seed);
        Assert::null($environment->path);
        Assert::null($environment->runs);
        Assert::null($environment->phases);
        Assert::null($environment->derandomize);
        Assert::null($environment->edgeCases);
        Assert::null($environment->exhaustive);
        Assert::null($environment->searchRuns);
        Assert::false($environment->verbose);
        Assert::null($environment->db);
    }

    public function everyVariableIsParsed(): void
    {
        $environment = Environment::fromVariables([
            'PROPERTY_SEED' => '-7',
            'PROPERTY_PATH' => 'x:0',
            'PROPERTY_RUNS' => '25',
            'PROPERTY_PHASES' => 'examples, Random',
            'PROPERTY_DERANDOMIZE' => 'yes',
            'PROPERTY_EDGE_CASES' => 'none',
            'PROPERTY_EXHAUSTIVE' => 'off',
            'PROPERTY_SEARCH_RUNS' => '0',
            'PROPERTY_VERBOSE' => '1',
            'PROPERTY_DB' => '/tmp/corpus',
        ]);

        Assert::same($environment->seed, -7);
        Assert::same($environment->path, 'x:0');
        Assert::same($environment->runs, 25);
        Assert::same($environment->phases, [Phase::Examples, Phase::Random]);
        Assert::true($environment->derandomize);
        Assert::same($environment->edgeCases, EdgeCases::None);
        Assert::false($environment->exhaustive);
        Assert::same($environment->searchRuns, 0);
        Assert::true($environment->verbose);
        Assert::same($environment->db, '/tmp/corpus');
    }

    public function falseAndEmptyMeanUnset(): void
    {
        $environment = Environment::fromVariables([
            'PROPERTY_RUNS' => false,
            'PROPERTY_SEED' => '',
            'PROPERTY_VERBOSE' => '',
            'PROPERTY_DB' => false,
        ]);

        Assert::null($environment->runs);
        Assert::null($environment->seed);
        Assert::false($environment->verbose);
        Assert::null($environment->db);
    }

    public function aFalsyVerboseIsOff(): void
    {
        Assert::false(Environment::fromVariables(['PROPERTY_VERBOSE' => 'false'])->verbose);
    }

    /**
     * Several invalid values: the first one in {@see Environment::VARIABLES}
     * order is the one reported.
     *
     * @param array<string, string> $variables
     */
    #[DataProvider('invalidVariables')]
    public function anInvalidValueIsRefusedInVariableOrder(array $variables, string $message): void
    {
        try {
            Environment::fromVariables($variables);

            Assert::fail('expected the environment to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), $message);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidVariables(): iterable
    {
        $all = [
            'PROPERTY_SEED' => 'abc',
            'PROPERTY_RUNS' => '0',
            'PROPERTY_PHASES' => 'warmup',
            'PROPERTY_EDGE_CASES' => 'some',
            'PROPERTY_SEARCH_RUNS' => '-1',
        ];

        yield 'seed first' => [$all, 'PROPERTY_SEED must be an integer, got "abc"'];
        unset($all['PROPERTY_SEED']);
        yield 'then runs' => [$all, 'PROPERTY_RUNS must be a positive integer, got "0"'];
        unset($all['PROPERTY_RUNS']);
        yield 'then phases' => [$all, 'PROPERTY_PHASES must be a comma-separated list of examples, corpus, random, shrink, got "warmup"'];
        unset($all['PROPERTY_PHASES']);
        yield 'then edge cases' => [$all, 'PROPERTY_EDGE_CASES must be one of mixin, none, got "some"'];
        unset($all['PROPERTY_EDGE_CASES']);
        yield 'then search runs' => [$all, 'PROPERTY_SEARCH_RUNS must be a non-negative integer, got "-1"'];
    }

    public function withNothingSetTheDeclaredConfigurationIsKept(): void
    {
        $config = Environment::fromVariables([])->config(
            runs: 7,
            seed: 3,
            maxShrinks: 4,
            maxDiscards: 5,
            timeoutMs: 6,
            budgetMs: 8,
            shrink: ShrinkMode::Off,
            shrinkBudgetMs: 9,
            phases: [Phase::Random],
            derandomize: true,
            edgeCases: EdgeCases::None,
            exhaustive: true,
            exhaustiveBudget: 11,
            flakyReplays: 0,
            searchRuns: 12,
        );

        Assert::same($config->runs, 7);
        Assert::same($config->seed, 3);
        Assert::same($config->maxShrinks, 4);
        Assert::same($config->maxDiscards, 5);
        Assert::same($config->timeoutMs, 6);
        Assert::same($config->budgetMs, 8);
        Assert::same($config->shrink, ShrinkMode::Off);
        Assert::same($config->shrinkBudgetMs, 9);
        Assert::same($config->phases, [Phase::Random]);
        Assert::true($config->derandomize);
        Assert::null($config->path);
        Assert::same($config->edgeCases, EdgeCases::None);
        Assert::true($config->exhaustive);
        Assert::same($config->exhaustiveBudget, 11);
        Assert::same($config->flakyReplays, 0);
        Assert::same($config->searchRuns, 12);
    }

    public function theDefaultsAreTheConfigurationDefaults(): void
    {
        $config = Environment::fromVariables([])->config();

        Assert::same($config->runs, 100);
        Assert::null($config->seed);
        Assert::same($config->phases, Phase::all());
        Assert::false($config->derandomize);
        Assert::same($config->edgeCases, EdgeCases::Mixin);
        Assert::false($config->exhaustive);
        Assert::same($config->exhaustiveBudget, 10_000);
        Assert::same($config->flakyReplays, 2);
        Assert::same($config->searchRuns, 0);
    }

    /**
     * The suite knobs: a set variable wins over what the code declares.
     */
    public function theEnvironmentDialsTheSuite(): void
    {
        $config = Environment::fromVariables([
            'PROPERTY_RUNS' => '9',
            'PROPERTY_PHASES' => 'random',
            'PROPERTY_DERANDOMIZE' => '0',
            'PROPERTY_EDGE_CASES' => 'mixin',
            'PROPERTY_EXHAUSTIVE' => 'no',
            'PROPERTY_SEARCH_RUNS' => '0',
        ])->config(
            runs: 200,
            phases: [Phase::Examples],
            derandomize: true,
            edgeCases: EdgeCases::None,
            exhaustive: true,
            searchRuns: 50,
        );

        Assert::same($config->runs, 9);
        Assert::same($config->phases, [Phase::Random]);
        Assert::false($config->derandomize);
        Assert::same($config->edgeCases, EdgeCases::Mixin);
        Assert::false($config->exhaustive);
        Assert::same($config->searchRuns, 0);
    }

    /**
     * A seed and a path replay one failure: what the code wrote down wins,
     * and the variables fill in only what it left unset.
     */
    public function theCodePinsTheProperty(): void
    {
        $environment = Environment::fromVariables(['PROPERTY_SEED' => '5', 'PROPERTY_PATH' => 'x:1']);

        $pinned = $environment->config(seed: 42, path: 'x:0');
        Assert::same($pinned->seed, 42);
        Assert::same($pinned->path, 'x:0');

        $unpinned = $environment->config();
        Assert::same($unpinned->seed, 5);
        Assert::same($unpinned->path, 'x:1');
    }

    public function variablesListsEveryVariableInParseOrder(): void
    {
        Assert::same(Environment::VARIABLES, [
            'PROPERTY_SEED',
            'PROPERTY_PATH',
            'PROPERTY_RUNS',
            'PROPERTY_PHASES',
            'PROPERTY_DERANDOMIZE',
            'PROPERTY_EDGE_CASES',
            'PROPERTY_EXHAUSTIVE',
            'PROPERTY_SEARCH_RUNS',
            'PROPERTY_VERBOSE',
            'PROPERTY_DB',
        ]);
    }
}
