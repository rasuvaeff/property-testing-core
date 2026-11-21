<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * The `PROPERTY_*` variables of one process, parsed, and the precedence that
 * turns them and a property's own settings into a {@see PropertyConfig}:
 * **the environment dials the suite, the code pins the property.** The run
 * count, the phase list, derandomization, the boundary mode, exhaustive mode
 * and the search budget are suite knobs, so a variable that is set wins over
 * what the code declares; a seed and a shrink path replay one specific failure,
 * so what the code wrote down wins over the variable.
 *
 * The engine still never reads the environment: an adapter hands the raw
 * values over, and only it calls `getenv()`.
 *
 * ```php
 * $variables = [];
 *
 * foreach (Environment::VARIABLES as $name) {
 *     $variables[$name] = getenv($name);
 * }
 *
 * $environment = Environment::fromVariables($variables);
 * $config = $environment->config(runs: 200, seed: $pinnedSeed);
 * ```
 *
 * Each value means what the {@see EnvironmentOverrides} parser for it says,
 * and an invalid one is refused while parsing, in the order of
 * {@see VARIABLES}.
 *
 * @api
 */
final readonly class Environment
{
    /**
     * Every variable this reads, in the order {@see fromVariables()} parses them.
     *
     * @var list<non-empty-string>
     */
    public const array VARIABLES = [
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
    ];

    /**
     * @param ?int $seed `PROPERTY_SEED`, or null when unset.
     * @param ?non-empty-string $path `PROPERTY_PATH`, or null when unset.
     * @param ?positive-int $runs `PROPERTY_RUNS`, or null when unset.
     * @param ?list<Phase> $phases `PROPERTY_PHASES`, or null when unset.
     * @param ?bool $derandomize `PROPERTY_DERANDOMIZE`, or null when unset.
     * @param ?EdgeCases $edgeCases `PROPERTY_EDGE_CASES`, or null when unset.
     * @param ?bool $exhaustive `PROPERTY_EXHAUSTIVE`, or null when unset.
     * @param ?int<0, max> $searchRuns `PROPERTY_SEARCH_RUNS`, or null when unset.
     * @param bool $verbose `PROPERTY_VERBOSE`: whether the adapter adds its trace listener.
     * @param ?non-empty-string $db `PROPERTY_DB`, the value for {@see CorpusFactory::fromDsn()}, or null when unset.
     */
    public function __construct(
        public ?int $seed = null,
        public ?string $path = null,
        public ?int $runs = null,
        public ?array $phases = null,
        public ?bool $derandomize = null,
        public ?EdgeCases $edgeCases = null,
        public ?bool $exhaustive = null,
        public ?int $searchRuns = null,
        public bool $verbose = false,
        public ?string $db = null,
    ) {}

    /**
     * Parses raw values as `getenv()` reports them, keyed by variable name. A
     * missing key, `false` and `''` all mean unset.
     *
     * @param array<string, string|false> $variables Raw values by variable name, as `getenv()` reports them.
     *
     * @throws \InvalidArgumentException When a value is invalid, naming the variable.
     */
    public static function fromVariables(array $variables): self
    {
        return new self(
            seed: EnvironmentOverrides::seed($variables['PROPERTY_SEED'] ?? false),
            path: EnvironmentOverrides::string($variables['PROPERTY_PATH'] ?? false),
            runs: EnvironmentOverrides::runs($variables['PROPERTY_RUNS'] ?? false),
            phases: EnvironmentOverrides::phases($variables['PROPERTY_PHASES'] ?? false),
            derandomize: EnvironmentOverrides::flag($variables['PROPERTY_DERANDOMIZE'] ?? false),
            edgeCases: EnvironmentOverrides::edgeCases($variables['PROPERTY_EDGE_CASES'] ?? false),
            exhaustive: EnvironmentOverrides::flag($variables['PROPERTY_EXHAUSTIVE'] ?? false),
            searchRuns: EnvironmentOverrides::count('PROPERTY_SEARCH_RUNS', $variables['PROPERTY_SEARCH_RUNS'] ?? false),
            verbose: EnvironmentOverrides::flag($variables['PROPERTY_VERBOSE'] ?? false) ?? false,
            db: EnvironmentOverrides::string($variables['PROPERTY_DB'] ?? false),
        );
    }

    /**
     * The configuration for a property that declares these settings, with the
     * environment applied: a set suite knob replaces the declared value, a set
     * `$seed`/`$path` keeps the declared one. The parameters mirror
     * {@see PropertyConfig::__construct()}, defaults included, and so do the
     * refusals of the configuration it builds.
     *
     * An adapter that draws a random seed for an unseeded property passes it
     * as `$seed` — after it has checked, with its own message, that a path
     * comes with a seed: only it knows the seed is about to be invented.
     *
     * @param int $runs Number of successful checks; `PROPERTY_RUNS` wins.
     * @param ?int $seed The pinned seed; wins over `PROPERTY_SEED`.
     * @param ?int $maxShrinks Cap on accepted shrink steps; no variable.
     * @param ?int $maxDiscards Maximum discarded runs; no variable.
     * @param ?int $timeoutMs Per-run deadline; no variable.
     * @param ?int $budgetMs Random-phase budget; no variable.
     * @param ?ShrinkMode $shrink Shrink mode; no variable.
     * @param ?int $shrinkBudgetMs Shrink budget; no variable.
     * @param ?list<Phase> $phases Stages to perform; `PROPERTY_PHASES` wins.
     * @param bool $derandomize Derive an unset seed from the id; `PROPERTY_DERANDOMIZE` wins.
     * @param ?string $path The shrink path to replay; wins over `PROPERTY_PATH`.
     * @param EdgeCases $edgeCases Boundary mode; `PROPERTY_EDGE_CASES` wins.
     * @param bool $exhaustive Exhaustive mode; `PROPERTY_EXHAUSTIVE` wins.
     * @param int $exhaustiveBudget Largest enumerated domain; no variable.
     * @param int $flakyReplays Replays of the minimised input; no variable.
     * @param int $searchRuns Targeted-search budget; `PROPERTY_SEARCH_RUNS` wins.
     *
     * @throws \InvalidArgumentException When the resulting configuration is invalid.
     */
    public function config(
        int $runs = 100,
        ?int $seed = null,
        ?int $maxShrinks = null,
        ?int $maxDiscards = null,
        ?int $timeoutMs = null,
        ?int $budgetMs = null,
        ?ShrinkMode $shrink = null,
        ?int $shrinkBudgetMs = null,
        ?array $phases = null,
        bool $derandomize = false,
        ?string $path = null,
        EdgeCases $edgeCases = EdgeCases::Mixin,
        bool $exhaustive = false,
        int $exhaustiveBudget = 10_000,
        int $flakyReplays = 2,
        int $searchRuns = 0,
    ): PropertyConfig {
        return new PropertyConfig(
            runs: $this->runs ?? $runs,
            seed: $seed ?? $this->seed,
            maxShrinks: $maxShrinks,
            maxDiscards: $maxDiscards,
            timeoutMs: $timeoutMs,
            budgetMs: $budgetMs,
            shrink: $shrink,
            shrinkBudgetMs: $shrinkBudgetMs,
            phases: $this->phases ?? $phases,
            derandomize: $this->derandomize ?? $derandomize,
            path: $path ?? $this->path,
            edgeCases: $this->edgeCases ?? $edgeCases,
            exhaustive: $this->exhaustive ?? $exhaustive,
            exhaustiveBudget: $exhaustiveBudget,
            flakyReplays: $flakyReplays,
            searchRuns: $this->searchRuns ?? $searchRuns,
        );
    }
}
