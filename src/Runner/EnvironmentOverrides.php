<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

/**
 * The values of the `PROPERTY_*` environment variables, parsed.
 *
 * The adapters read the environment — the engine never does — and every one
 * of them has to turn the same strings into the same values with the same
 * refusals, or `PROPERTY_RUNS=abc` would mean one thing under Testo and
 * another under PHPUnit. Each parser takes the raw value as `getenv()` hands
 * it over (`false` when unset) and answers with the parsed value, or null
 * when the variable is unset or empty and the caller's own default applies.
 *
 * ```php
 * $runs = EnvironmentOverrides::runs(getenv('PROPERTY_RUNS')) ?? $attribute->runs;
 * ```
 *
 * @api
 */
final class EnvironmentOverrides
{
    /** @var array<string, Phase> */
    private const array PHASES_BY_NAME = [
        'examples' => Phase::Examples,
        'corpus' => Phase::Corpus,
        'random' => Phase::Random,
        'shrink' => Phase::Shrink,
    ];

    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * `PROPERTY_RUNS`: a positive integer.
     *
     * @param string|false $value The variable's value as `getenv()` reports it; `false` when unset.
     *
     * @return ?positive-int
     *
     * @throws \InvalidArgumentException When the value is not a positive integer within range.
     */
    public static function runs(string|false $value): ?int
    {
        if ($value === false || $value === '') {
            return null;
        }

        $runs = self::integer($value);

        if ($runs === null || $runs < 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_RUNS must be a positive integer, got "%s"', $value));
        }

        return $runs;
    }

    /**
     * `PROPERTY_SEED`: an integer. A value past the integer range would
     * saturate to PHP_INT_MAX under a cast, and a replay under "the same"
     * seed would then be a different run.
     *
     * @param string|false $value The variable's value as `getenv()` reports it; `false` when unset.
     *
     * @throws \InvalidArgumentException When the value is not an integer within range.
     */
    public static function seed(string|false $value): ?int
    {
        if ($value === false || $value === '') {
            return null;
        }

        $seed = self::integer($value);

        if ($seed === null) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_SEED must be an integer, got "%s"', $value));
        }

        return $seed;
    }

    /**
     * The integer a string spells, or null when it spells none. The cast has
     * to spell the same string back: that refuses a sign or a space in the
     * wrong place, a float, an exponent — and a number past the integer
     * range, which a digit pattern would accept and the cast would saturate
     * to PHP_INT_MAX silently.
     */
    private static function integer(string $value): ?int
    {
        $integer = (int) $value;

        return (string) $integer === $value ? $integer : null;
    }

    /**
     * `PROPERTY_PHASES`: a comma-separated list of phase names,
     * case-insensitive (`examples,corpus` is the fast pull-request gate). An
     * unknown name is an error rather than a skipped stage — a run that
     * silently performed fewer stages would report green having checked less.
     *
     * @param string|false $value The variable's value as `getenv()` reports it; `false` when unset.
     *
     * @return ?list<Phase>
     *
     * @throws \InvalidArgumentException When a name is not a phase.
     */
    public static function phases(string|false $value): ?array
    {
        if ($value === false || $value === '') {
            return null;
        }

        $phases = [];

        foreach (explode(',', $value) as $name) {
            $trimmed = trim($name);
            $phase = self::PHASES_BY_NAME[strtolower($trimmed)] ?? null;

            if ($phase === null) {
                throw new \InvalidArgumentException(sprintf(
                    'PROPERTY_PHASES must be a comma-separated list of %s, got "%s"',
                    implode(', ', array_keys(self::PHASES_BY_NAME)),
                    $trimmed,
                ));
            }

            $phases[] = $phase;
        }

        return $phases;
    }

    /**
     * `PROPERTY_EDGE_CASES`: `mixin` or `none`, case-insensitive. An unknown
     * value is an error rather than a silent fallback — a suite that quietly
     * kept the bias it was told to drop would spend the discard budget it was
     * trying to save.
     *
     * @param string|false $value The variable's value as `getenv()` reports it; `false` when unset.
     *
     * @throws \InvalidArgumentException When the value is neither `mixin` nor `none`.
     */
    public static function edgeCases(string|false $value): ?EdgeCases
    {
        if ($value === false || $value === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'mixin' => EdgeCases::Mixin,
            'none' => EdgeCases::None,
            default => throw new \InvalidArgumentException(sprintf(
                'PROPERTY_EDGE_CASES must be one of mixin, none, got "%s"',
                trim($value),
            )),
        };
    }

    /**
     * A switch such as `PROPERTY_DERANDOMIZE` or `PROPERTY_VERBOSE`: unset
     * and empty mean "not given" (null), `0` means off, anything else on.
     *
     * @param string|false $value The variable's value as `getenv()` reports it; `false` when unset.
     */
    public static function flag(string|false $value): ?bool
    {
        if ($value === false || $value === '') {
            return null;
        }

        return $value !== '0';
    }

    /**
     * A free-form value such as `PROPERTY_PATH` or `PROPERTY_DB`: the string,
     * or null when unset or empty.
     *
     * @param string|false $value The variable's value as `getenv()` reports it; `false` when unset.
     *
     * @return ?non-empty-string
     */
    public static function string(string|false $value): ?string
    {
        return $value === false || $value === '' ? null : $value;
    }
}
