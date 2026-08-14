<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PropertyConfig::class)]
final class PropertyConfigTest
{
    public function defaultsMatchTheAttributeDefaults(): void
    {
        $config = new PropertyConfig();

        Assert::same($config->runs, 100);
        Assert::null($config->seed);
        Assert::null($config->maxShrinks);
        Assert::null($config->maxDiscards);
        Assert::null($config->timeoutMs);
        Assert::null($config->budgetMs);
        Assert::null($config->shrinkBudgetMs);
        Assert::same($config->shrink, ShrinkMode::Full);
        Assert::same($config->phases, Phase::all());
        Assert::false($config->derandomize);
    }

    public function everyPhaseRunsByDefault(): void
    {
        $config = new PropertyConfig();

        foreach (Phase::cases() as $phase) {
            Assert::true($config->runs($phase));
        }
    }

    public function phasesKeepRunOrder(): void
    {
        // Selecting a subset does not reorder the stages that do run; the enum
        // declaration order is the run order.
        Assert::same(Phase::all(), [Phase::Examples, Phase::Corpus, Phase::Random, Phase::Shrink]);
    }

    public function anExplicitPhaseSubsetExcludesTheRest(): void
    {
        $config = new PropertyConfig(phases: [Phase::Examples, Phase::Corpus]);

        Assert::true($config->runs(Phase::Examples));
        Assert::true($config->runs(Phase::Corpus));
        Assert::false($config->runs(Phase::Random));
        Assert::false($config->runs(Phase::Shrink));
    }

    #[DataProvider('shrinkModeProvider')]
    public function shrinkModeIsResolvedFromEveryKnobThatCanDisableIt(
        ?ShrinkMode $shrink,
        ?int $shrinkBudgetMs,
        ?array $phases,
        ShrinkMode $expected,
    ): void {
        $config = new PropertyConfig(shrink: $shrink, shrinkBudgetMs: $shrinkBudgetMs, phases: $phases);

        Assert::same($config->shrink, $expected);
    }

    /**
     * @return iterable<string, array{?ShrinkMode, ?int, ?list<Phase>, ShrinkMode}>
     */
    public static function shrinkModeProvider(): iterable
    {
        yield 'nothing configured' => [null, null, null, ShrinkMode::Full];
        yield 'explicit full' => [ShrinkMode::Full, null, null, ShrinkMode::Full];
        yield 'explicit off' => [ShrinkMode::Off, null, null, ShrinkMode::Off];
        yield 'a budget implies bounded' => [null, 50, null, ShrinkMode::Bounded];
        yield 'explicit bounded with its budget' => [ShrinkMode::Bounded, 50, null, ShrinkMode::Bounded];
        // The stricter side wins in both directions.
        yield 'off beats a budget' => [ShrinkMode::Off, 50, null, ShrinkMode::Off];
        yield 'a phase set without Shrink is off' => [null, null, [Phase::Random], ShrinkMode::Off];
        yield 'a phase set without Shrink beats a budget' => [null, 50, [Phase::Random], ShrinkMode::Off];
        yield 'a phase set without Shrink beats explicit full' => [ShrinkMode::Full, null, [Phase::Random], ShrinkMode::Off];
        yield 'a phase set with Shrink keeps the budget' => [null, 50, [Phase::Random, Phase::Shrink], ShrinkMode::Bounded];
    }

    public function acceptsEveryBoundaryValue(): void
    {
        $config = new PropertyConfig(
            runs: 1,
            seed: PHP_INT_MIN,
            maxShrinks: 0,
            maxDiscards: 0,
            timeoutMs: 1,
            budgetMs: 1,
            shrinkBudgetMs: 1,
        );

        Assert::same($config->runs, 1);
        Assert::same($config->maxShrinks, 0);
        Assert::same($config->maxDiscards, 0);
        Assert::same($config->timeoutMs, 1);
        Assert::same($config->budgetMs, 1);
        // One millisecond is a budget, not a rejected value: the guard is
        // "< 1", so the smallest accepted budget must survive it.
        Assert::same($config->shrinkBudgetMs, 1);
        Assert::same($config->shrink, ShrinkMode::Bounded);
    }

    #[DataProvider('invalidProvider')]
    public function rejectsOutOfRangeValues(\Closure $construct, string $message): void
    {
        $thrown = null;

        try {
            $construct();
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        Assert::instanceOf($thrown, \InvalidArgumentException::class);
        Assert::same($thrown->getMessage(), $message);
    }

    public static function invalidProvider(): iterable
    {
        yield 'zero runs' => [
            static fn(): PropertyConfig => new PropertyConfig(runs: 0),
            'Runs must be greater than or equal to 1',
        ];

        yield 'negative max shrinks' => [
            static fn(): PropertyConfig => new PropertyConfig(maxShrinks: -1),
            'Max shrinks must be greater than or equal to 0',
        ];

        yield 'negative max discards' => [
            static fn(): PropertyConfig => new PropertyConfig(maxDiscards: -1),
            'Max discards must be greater than or equal to 0',
        ];

        yield 'zero timeout' => [
            static fn(): PropertyConfig => new PropertyConfig(timeoutMs: 0),
            'Timeout must be greater than or equal to 1 millisecond',
        ];

        yield 'zero budget' => [
            static fn(): PropertyConfig => new PropertyConfig(budgetMs: 0),
            'Budget must be greater than or equal to 1 millisecond',
        ];

        yield 'zero shrink budget' => [
            static fn(): PropertyConfig => new PropertyConfig(shrinkBudgetMs: 0),
            'Shrink budget must be greater than or equal to 1 millisecond',
        ];

        yield 'bounded shrinking without a budget' => [
            static fn(): PropertyConfig => new PropertyConfig(shrink: ShrinkMode::Bounded),
            'Bounded shrinking requires a shrink budget',
        ];

        yield 'empty phase set' => [
            static fn(): PropertyConfig => new PropertyConfig(phases: []),
            'Phases must not be empty',
        ];

        yield 'shrink budget past its own deadline' => [
            static fn(): PropertyConfig => new PropertyConfig(shrinkBudgetMs: intdiv(PHP_INT_MAX, 2_000_000) + 1),
            'Shrink budget must be less than or equal to ' . intdiv(PHP_INT_MAX, 2_000_000) . ' milliseconds',
        ];

        // Nothing in the type system stops a configuration file or a command
        // line from reaching the constructor with these.
        yield 'a phase set holding a string' => [
            static fn(): PropertyConfig => new PropertyConfig(phases: ['Random']),
            'Phases must be a list of Phase cases',
        ];

        yield 'a phase set holding null' => [
            static fn(): PropertyConfig => new PropertyConfig(phases: [Phase::Random, null]),
            'Phases must be a list of Phase cases',
        ];

        yield 'a phase map instead of a list' => [
            static fn(): PropertyConfig => new PropertyConfig(phases: ['random' => Phase::Random]),
            'Phases must be a list of Phase cases',
        ];

        // Every rejection below describes a run that would silently search
        // instead of replaying — the one outcome a replay tool must not have,
        // because a fresh descent looks exactly like a followed one.
        yield 'a path without a seed' => [
            static fn(): PropertyConfig => new PropertyConfig(path: 'value:1'),
            'Replaying a shrink path requires an explicit seed',
        ];

        // A derived seed is stable, but a path is always copied from a message
        // that printed the seed beside it: one rule, no special case.
        yield 'a path with a derived seed only' => [
            static fn(): PropertyConfig => new PropertyConfig(derandomize: true, path: 'value:1'),
            'Replaying a shrink path requires an explicit seed',
        ];

        yield 'a path with shrinking switched off' => [
            static fn(): PropertyConfig => new PropertyConfig(seed: 1, shrink: ShrinkMode::Off, path: 'value:1'),
            'Replaying a shrink path requires the shrink phase',
        ];

        yield 'a path without the shrink phase' => [
            static fn(): PropertyConfig => new PropertyConfig(seed: 1, phases: [Phase::Random], path: 'value:1'),
            'Replaying a shrink path requires the shrink phase',
        ];

        // A path is deterministic; a wall-clock budget is deliberately not.
        yield 'a path bounded by a wall clock' => [
            static fn(): PropertyConfig => new PropertyConfig(seed: 1, shrinkBudgetMs: 10, path: 'value:1'),
            'Replaying a shrink path cannot be combined with a shrink budget',
        ];

        yield 'a path longer than the shrink cap' => [
            static fn(): PropertyConfig => new PropertyConfig(seed: 1, maxShrinks: 1, path: 'value:1/value:2'),
            'Shrink path has 2 step(s), more than the max shrinks cap of 1',
        ];

        yield 'a malformed path' => [
            static fn(): PropertyConfig => new PropertyConfig(seed: 1, path: 'value'),
            'Invalid shrink path "value"',
        ];

        yield 'an empty path' => [
            static fn(): PropertyConfig => new PropertyConfig(seed: 1, path: ''),
            'Invalid shrink path ""',
        ];
    }

    public function aPathExactlyAsLongAsTheShrinkCapIsAccepted(): void
    {
        // The guard is "> cap": a path that spends the whole cap is replayable.
        $config = new PropertyConfig(seed: 1, maxShrinks: 2, path: 'value:1/value:2');

        Assert::same($config->path, 'value:1/value:2');
    }

    public function noPathIsTheDefault(): void
    {
        Assert::null((new PropertyConfig())->path);
    }

    public function theLargestUsableShrinkBudgetIsAccepted(): void
    {
        // The guard is "> max", so the largest budget that still converts to a
        // nanosecond deadline must survive it.
        $config = new PropertyConfig(shrinkBudgetMs: intdiv(PHP_INT_MAX, 2_000_000));

        Assert::same($config->shrinkBudgetMs, intdiv(PHP_INT_MAX, 2_000_000));
        Assert::same($config->shrink, ShrinkMode::Bounded);
    }
}
