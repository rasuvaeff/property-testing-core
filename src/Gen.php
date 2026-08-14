<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Closure;
use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\Arbitrary\ArrayArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\BoolArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\BytesArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\CharsetStringArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\CommandSequenceArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\DateTimeArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\DictionaryArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FilteredArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FlatMappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FloatArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\IntArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\MappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\NullableArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\RecordArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\StringArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\SubsetArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\TupleArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\UniqueArrayArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\UuidArbitrary;
use Rasuvaeff\PropertyTesting\Internal\DrawContext;
use Rasuvaeff\PropertyTesting\Internal\Ipv6Formatter;
use Rasuvaeff\PropertyTesting\Internal\RegexCompiler;

/**
 * Facade with static factories for the built-in {@see ArbitraryInterface}s.
 *
 * Each factory returns a ready-to-use arbitrary; values are never generated
 * directly through Gen — that happens inside the property runner, which threads
 * the seedable {@see Random} through every generator so runs are reproducible.
 * The one exception is {@see sample()}, a debugging aid that eagerly generates
 * values from a given arbitrary.
 *
 * @api
 */
final class Gen
{
    private const string ALPHA = 'abcdefghijklmnopqrstuvwxyz';
    private const string ALNUM = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private function __construct()
    {
        // Static facade; not instantiable.
    }

    /**
     * Integers spanning PHP_INT_MIN..PHP_INT_MAX.
     */
    public static function int(): IntArbitrary
    {
        return new IntArbitrary();
    }

    /**
     * @param int<min, max> $min
     * @param int<min, max> $max
     */
    public static function intBetween(int $min, int $max): IntArbitrary
    {
        return new IntArbitrary($min, $max);
    }

    /**
     * Positive integers (1..PHP_INT_MAX).
     */
    public static function intPositive(): IntArbitrary
    {
        return new IntArbitrary(1, PHP_INT_MAX);
    }

    /**
     * Floats in the half-open range [0.0, 1.0).
     */
    public static function float(): FloatArbitrary
    {
        return new FloatArbitrary(0.0, 1.0);
    }

    public static function floatBetween(float $min, float $max): FloatArbitrary
    {
        return new FloatArbitrary($min, $max);
    }

    public static function bool(): BoolArbitrary
    {
        return new BoolArbitrary();
    }

    /**
     * Unicode strings of length 0..100.
     */
    public static function string(): StringArbitrary
    {
        return new StringArbitrary(0, 100, unicode: true);
    }

    /**
     * Printable ASCII strings of length 0..100.
     */
    public static function stringAscii(): StringArbitrary
    {
        return new StringArbitrary(0, 100, unicode: false);
    }

    /**
     * @param int<0, max> $minLength
     * @param int<1, max> $maxLength
     */
    public static function stringOf(int $minLength, int $maxLength): StringArbitrary
    {
        return new StringArbitrary($minLength, $maxLength, unicode: true);
    }

    /**
     * A single printable ASCII character.
     */
    public static function char(): StringArbitrary
    {
        return new StringArbitrary(1, 1, unicode: false);
    }

    /**
     * Strings whose characters come from a fixed alphabet (split per Unicode
     * codepoint). Shrinks by length toward '', then each character toward the
     * first alphabet character — list simpler characters first.
     */
    public static function stringFrom(string $alphabet, int $minLength = 0, int $maxLength = 100): CharsetStringArbitrary
    {
        return new CharsetStringArbitrary($alphabet, $minLength, $maxLength);
    }

    /**
     * Raw byte strings (every byte 0..255). Shrinks by length toward '', then
     * each byte toward "\x00".
     */
    public static function bytes(int $minLength = 0, int $maxLength = 100): BytesArbitrary
    {
        return new BytesArbitrary($minLength, $maxLength);
    }

    /**
     * Lists whose elements are drawn from $element.
     *
     * @template TElement
     *
     * @param ArbitraryInterface<TElement> $element
     *
     * @return ArrayArbitrary<TElement>
     */
    public static function arrayOf(ArbitraryInterface $element, int $minSize = 0, int $maxSize = 100): ArrayArbitrary
    {
        return new ArrayArbitrary($element, $minSize, $maxSize);
    }

    /**
     * Non-empty lists whose elements are drawn from $element.
     *
     * @template TElement
     *
     * @param ArbitraryInterface<TElement> $element
     *
     * @return ArrayArbitrary<TElement>
     */
    public static function nonEmptyArrayOf(ArbitraryInterface $element, int $maxSize = 100): ArrayArbitrary
    {
        return new ArrayArbitrary($element, 1, $maxSize);
    }

    /**
     * Lists of pairwise-distinct elements (strict comparison) drawn from
     * $element. Element shrinking keeps the list distinct; the result may be
     * smaller than the drawn size when the element space runs out of fresh
     * values, but never below $minSize — an unreachable minimum throws
     * {@see GenerationExhausted}.
     *
     * @template TElement
     *
     * @param ArbitraryInterface<TElement> $element
     *
     * @return UniqueArrayArbitrary<TElement>
     */
    public static function uniqueArrayOf(ArbitraryInterface $element, int $minSize = 0, int $maxSize = 100): UniqueArrayArbitrary
    {
        return new UniqueArrayArbitrary($element, $minSize, $maxSize);
    }

    /**
     * Subsets of a fixed ordered set: every result is a list of distinct
     * elements of $values, in the source order. Duplicates in $values are
     * rejected with an InvalidArgumentException — a set has no duplicate
     * members. The size is drawn uniformly from `[minSize, maxSize]` (null
     * $maxSize means the full source size), then the combination of that size
     * uniformly — small and large subsets appear equally often. Shrinking
     * reduces the size first, then moves the kept elements toward earlier
     * source positions; no filtering, no discards.
     *
     * @template TValue
     *
     * @param list<TValue> $values
     *
     * @return SubsetArbitrary<TValue>
     */
    public static function subset(array $values, int $minSize = 0, ?int $maxSize = null): SubsetArbitrary
    {
        return new SubsetArbitrary($values, $minSize, $maxSize);
    }

    /**
     * Associative arrays (maps) with keys from $key and values from $value.
     * Keys must be int or string; only distinct keys are kept, so the result
     * may be smaller than the drawn size when the key space runs out, but never
     * below $minSize — an unreachable minimum throws {@see GenerationExhausted}.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param ArbitraryInterface<TKey>   $key
     * @param ArbitraryInterface<TValue> $value
     *
     * @return DictionaryArbitrary<TKey, TValue>
     */
    public static function dictOf(ArbitraryInterface $key, ArbitraryInterface $value, int $minSize = 0, int $maxSize = 100): DictionaryArbitrary
    {
        return new DictionaryArbitrary($key, $value, $minSize, $maxSize);
    }

    /**
     * Fixed-shape associative array: each field is generated from its own
     * arbitrary, keyed by field name. The property receives a single string-keyed
     * array; shrinking reduces each field through its arbitrary while keeping the
     * key set fixed.
     *
     * @param array<string, ArbitraryInterface> $shape Field name => arbitrary.
     */
    public static function record(array $shape): RecordArbitrary
    {
        return new RecordArbitrary($shape);
    }

    /**
     * Picks one of the given values at random.
     *
     * @template TValue
     *
     * @param TValue ...$values
     *
     * @return OneOfArbitrary<TValue>
     */
    public static function oneOf(mixed ...$values): OneOfArbitrary
    {
        return new OneOfArbitrary(...$values);
    }

    /**
     * Picks one value at random from an array (the array form of {@see oneOf()}).
     *
     * @template TValue
     *
     * @param array<array-key, TValue> $values Must be non-empty.
     *
     * @return OneOfArbitrary<TValue>
     */
    public static function elements(array $values): OneOfArbitrary
    {
        return new OneOfArbitrary(...array_values($values));
    }

    /**
     * Always produces $value; does not shrink.
     *
     * @template TValue
     *
     * @param TValue $value
     *
     * @return ConstantArbitrary<TValue>
     */
    public static function constant(mixed $value): ConstantArbitrary
    {
        return new ConstantArbitrary($value);
    }

    /**
     * One case of a PHP enum, in declaration order. Shrinks toward
     * earlier-declared cases, so declare simpler cases first.
     *
     * @template TEnum
     *
     * @param class-string<TEnum> $enum
     *
     * @return OneOfArbitrary<TEnum>
     */
    public static function enum(string $enum): OneOfArbitrary
    {
        if (!enum_exists($enum)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an enum', $enum));
        }

        /** @var list<TEnum> $cases */
        $cases = array_map(
            static fn(\ReflectionEnumUnitCase $case): \UnitEnum => $case->getValue(),
            (new \ReflectionEnum($enum))->getCases(),
        );

        return new OneOfArbitrary(...$cases);
    }

    /**
     * Special float values (NaN, ±INF, -0.0 and the representation edges) where
     * float bugs cluster — an opt-in complement to {@see float()}, which stays
     * inside its finite range. Shrinks toward earlier-listed specials.
     *
     * @return OneOfArbitrary<float>
     */
    public static function floatSpecial(): OneOfArbitrary
    {
        // NAN/INF are produced via fdiv(): Psalm crashes on the NAN constant
        // (Psalm\Type::getFloat(NAN)), and the values are identical.
        return new OneOfArbitrary(
            fdiv(0.0, 0.0),   // NAN
            fdiv(1.0, 0.0),   // INF
            fdiv(-1.0, 0.0),  // -INF
            -0.0,
            PHP_FLOAT_EPSILON,
            PHP_FLOAT_MIN,
            PHP_FLOAT_MAX,
        );
    }

    /**
     * Ordered integer pairs `[lo, hi]` with $min <= lo <= hi <= $max — the
     * "range/interval" input without an {@see Assume::that()} discard. Built on
     * {@see flatMap()}, so both bounds shrink while `lo <= hi` always holds.
     *
     * @return FlatMappedArbitrary<int, list<mixed>>
     */
    public static function intRange(int $min, int $max): FlatMappedArbitrary
    {
        return new FlatMappedArbitrary(
            new IntArbitrary($min, $max),
            static function (mixed $lo) use ($max): TupleArbitrary {
                \assert(is_int($lo));

                return new TupleArbitrary(new ConstantArbitrary($lo), new IntArbitrary($lo, $max));
            },
        );
    }

    /**
     * Recursive structures with a bounded depth: $wrap receives the arbitrary
     * for the previous level and returns the next one (e.g. wrap a value in an
     * array). At every level generation picks the leaf or the wrapped branch
     * with equal odds, so nesting is possible but not forced. Keep the branch
     * fan-out small (bounded array sizes) — breadth multiplies per level.
     *
     * @param Closure(ArbitraryInterface): ArbitraryInterface $wrap
     */
    public static function recursive(ArbitraryInterface $leaf, Closure $wrap, int $maxDepth = 3): ArbitraryInterface
    {
        if ($maxDepth < 1) {
            throw new \InvalidArgumentException('Max depth must be greater than or equal to 1');
        }

        $arbitrary = $leaf;

        for ($depth = 0; $depth < $maxDepth; ++$depth) {
            /** @var mixed $wrapped */
            $wrapped = ($wrap)($arbitrary);

            if (!$wrapped instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'recursive() wrap closure must return an ArbitraryInterface, got %s',
                    get_debug_type($wrapped),
                ));
            }

            $arbitrary = new FrequencyArbitrary([[1, $leaf], [1, $wrapped]]);
        }

        return $arbitrary;
    }

    /**
     * Yields null or a value from $inner with roughly even odds.
     */
    public static function nullable(ArbitraryInterface $inner): NullableArbitrary
    {
        return new NullableArbitrary($inner);
    }

    /**
     * Transforms each value produced by $inner through a pure function.
     * Shrinking happens in the source domain and the function is re-applied,
     * so mapped values shrink through the inner arbitrary's tree.
     *
     * @template TInner
     * @template TOutput
     *
     * @param ArbitraryInterface<TInner> $inner
     * @param Closure(TInner): TOutput $map
     *
     * @return MappedArbitrary<TInner, TOutput>
     */
    public static function map(ArbitraryInterface $inner, Closure $map): MappedArbitrary
    {
        return new MappedArbitrary($inner, $map);
    }

    /**
     * Dependent generators (aka `bind`): feeds each value produced by $inner
     * into $flatMap, which returns the arbitrary generating the final value.
     * Use it when one input's domain depends on another (e.g. an array plus a
     * valid index into it) instead of discarding invalid combinations with
     * {@see Assume::that()}.
     *
     * @template TInner
     * @template TOutput
     *
     * @param ArbitraryInterface<TInner> $inner
     * @param Closure(TInner): ArbitraryInterface<TOutput> $flatMap
     *
     * @return FlatMappedArbitrary<TInner, TOutput>
     */
    public static function flatMap(ArbitraryInterface $inner, Closure $flatMap): FlatMappedArbitrary
    {
        return new FlatMappedArbitrary($inner, $flatMap);
    }

    /**
     * Generates values from $inner, retrying until $predicate holds.
     *
     * @template TInner
     *
     * @param ArbitraryInterface<TInner> $inner
     * @param Closure(TInner): bool $predicate
     *
     * @return FilteredArbitrary<TInner>
     */
    public static function filter(ArbitraryInterface $inner, Closure $predicate): FilteredArbitrary
    {
        return new FilteredArbitrary($inner, $predicate);
    }

    /**
     * In-body dependent draw: generates one value from $arbitrary inside the
     * property body — for when several dependent values make nested
     * {@see flatMap()} awkward. The domain may depend on anything already in
     * scope, including previously drawn values:
     *
     * ```php
     * #[Property(runs: 200)]
     * public function sliceIsContained(array $xs): void
     * {
     *     $from = Gen::draw(Gen::intBetween(0, count($xs)));
     *     $to = Gen::draw(Gen::intBetween($from, count($xs)));
     *     // ... assertions on array_slice($xs, $from, $to - $from) ...
     * }
     * ```
     *
     * Drawn values shrink together with the parameters: the runner records
     * every draw on a replay tape, shrinks each recorded draw through its own
     * tree, and re-runs the body with the tape replayed by position. A run
     * that draws past the tape's end (control flow changed under a smaller
     * prefix) generates the extra values anew. Counterexamples report draws
     * as `draw#1`, `draw#2`, ... alongside the named parameters.
     *
     * Only valid while the property runner executes the body; anywhere else
     * it throws.
     *
     * @template TValue
     *
     * @param ArbitraryInterface<TValue> $arbitrary
     *
     * @return TValue
     */
    public static function draw(ArbitraryInterface $arbitrary): mixed
    {
        /** @var TValue $value */
        $value = DrawContext::draw($arbitrary);

        return $value;
    }

    /**
     * Fixed-arity tuple: one value per element arbitrary, in order. The property
     * receives the tuple as a single array argument; shrinking reduces each
     * position through its own arbitrary while keeping the arity fixed.
     */
    public static function tuple(ArbitraryInterface ...$elements): TupleArbitrary
    {
        return new TupleArbitrary(...$elements);
    }

    /**
     * Weighted choice among `[weight, arbitrary]` pairs: a branch is picked with
     * probability proportional to its weight, then produces the value. Shrinking
     * stays within the branch that generated the value.
     *
     * @template TValue
     *
     * @param iterable<array{int, ArbitraryInterface<TValue>}> $pairs Weights must be >= 1.
     *
     * @return FrequencyArbitrary<TValue>
     */
    public static function frequency(iterable $pairs): FrequencyArbitrary
    {
        /** @var FrequencyArbitrary<TValue> $arbitrary */
        $arbitrary = new FrequencyArbitrary($pairs);

        return $arbitrary;
    }

    /**
     * Canonical RFC 4122 version 4 UUID strings. Does not shrink.
     */
    public static function uuid(): UuidArbitrary
    {
        return new UuidArbitrary();
    }

    /**
     * UTC {@see DateTimeImmutable} values with a timestamp in the inclusive range
     * `[$min, $max]` (defaults: 1970-01-01 .. 2100-01-01). Shrinks toward the
     * Unix epoch, clamped to the range.
     */
    public static function datetime(?DateTimeImmutable $min = null, ?DateTimeImmutable $max = null): DateTimeArbitrary
    {
        return new DateTimeArbitrary($min, $max);
    }

    /**
     * IPv4 dotted-quad address strings (`"0.0.0.0"`..`"255.255.255.255"`). Each
     * octet shrinks toward 0 through its own integer tree.
     *
     * @return MappedArbitrary<list<mixed>, string>
     */
    public static function ipv4(): MappedArbitrary
    {
        $octet = new IntArbitrary(0, 255);

        return new MappedArbitrary(
            new TupleArbitrary($octet, $octet, $octet, $octet),
            static function (mixed $octets): string {
                \assert(is_array($octets));

                return implode('.', array_map(static fn(mixed $o): string => (string) $o, $octets));
            },
        );
    }

    /**
     * IPv6 address strings in the canonical text form of RFC 5952: lowercase
     * hex, leading zeros stripped, and the longest run of zero groups
     * compressed to `::` (leftmost on a tie, never a single group).
     *
     * Each of the eight 16-bit groups shrinks toward 0 through its own integer
     * tree, so the descent walks through the shortened forms parsers get wrong
     * — `2001:db8::1`, `fe80::`, `::1` — and terminates at `::`.
     *
     * IPv4-mapped addresses (`::ffff:1.2.3.4`), zone ids (`%eth0`) and the
     * bracketed URL form (`[::1]:8080`) are out of scope; {@see url()} emits no
     * IPv6 host either.
     *
     * @return MappedArbitrary<list<mixed>, non-empty-string>
     */
    public static function ipv6(): MappedArbitrary
    {
        $group = new IntArbitrary(0, 65535);

        return new MappedArbitrary(
            // Spelled out rather than array_fill()ed: the arity is the contract
            // Ipv6Formatter::format() asserts, not a computed length.
            new TupleArbitrary($group, $group, $group, $group, $group, $group, $group, $group),
            static function (mixed $groups): string {
                \assert(is_array($groups));

                /** @var non-empty-list<int> $groups */
                return Ipv6Formatter::format($groups);
            },
        );
    }

    /**
     * Syntactically valid `local@label.tld` email addresses over a lowercase
     * alphanumeric alphabet and a small TLD set. Shrinks toward the shortest
     * local part / label and the first TLD.
     *
     * @return MappedArbitrary<list<mixed>, non-empty-string>
     */
    public static function email(): MappedArbitrary
    {
        return new MappedArbitrary(
            new TupleArbitrary(
                new CharsetStringArbitrary(self::ALNUM, 1, 16),
                new CharsetStringArbitrary(self::ALNUM, 1, 16),
                new OneOfArbitrary('com', 'org', 'net', 'io', 'dev'),
            ),
            static function (mixed $parts): string {
                \assert(is_array($parts));
                [$local, $label, $tld] = $parts;
                \assert(is_string($local) && is_string($label) && is_string($tld));

                return sprintf('%s@%s.%s', $local, $label, $tld);
            },
        );
    }

    /**
     * HTTP/HTTPS URLs `scheme://host.tld[/segment...]` over a lowercase
     * alphanumeric alphabet. Shrinks toward `http://a.com` (no path).
     *
     * @return MappedArbitrary<list<mixed>, non-empty-string>
     */
    public static function url(): MappedArbitrary
    {
        return new MappedArbitrary(
            new TupleArbitrary(
                new OneOfArbitrary('http', 'https'),
                new CharsetStringArbitrary(self::ALNUM, 1, 16),
                new OneOfArbitrary('com', 'org', 'net', 'io', 'dev'),
                new ArrayArbitrary(new CharsetStringArbitrary(self::ALNUM, 1, 8), 0, 3),
            ),
            static function (mixed $parts): string {
                \assert(is_array($parts));
                [$scheme, $host, $tld, $segments] = $parts;
                \assert(is_string($scheme) && is_string($host) && is_string($tld) && is_array($segments));

                $path = $segments === []
                    ? ''
                    : '/' . implode('/', array_map(static fn(mixed $s): string => (string) $s, $segments));

                return sprintf('%s://%s.%s%s', $scheme, $host, $tld, $path);
            },
        );
    }

    /**
     * A JSON-encodable value — null, bool, int, float, string, or nested
     * lists/objects thereof — bounded to $maxDepth levels of nesting. Produces
     * the decoded PHP value; use {@see jsonString()} for the encoded text.
     */
    public static function json(int $maxDepth = 3): ArbitraryInterface
    {
        $leaf = new FrequencyArbitrary([
            [1, new ConstantArbitrary(null)],
            [1, new BoolArbitrary()],
            [1, new IntArbitrary(-1000, 1000)],
            [1, new FloatArbitrary(-1000.0, 1000.0)],
            [1, new CharsetStringArbitrary(self::ALNUM . ' ', 0, 12)],
        ]);

        return self::recursive(
            $leaf,
            static fn(ArbitraryInterface $inner): ArbitraryInterface => new FrequencyArbitrary([
                [1, new ArrayArbitrary($inner, 0, 4)],
                [1, new DictionaryArbitrary(new CharsetStringArbitrary(self::ALPHA, 1, 8), $inner, 0, 4)],
            ]),
            $maxDepth,
        );
    }

    /**
     * The JSON text of {@see json()} (`json_encode` of each generated value),
     * for exercising JSON parsers and decoders.
     *
     * @return MappedArbitrary<mixed, string>
     */
    public static function jsonString(int $maxDepth = 3): MappedArbitrary
    {
        return new MappedArbitrary(
            self::json($maxDepth),
            static fn(mixed $value): string => (string) json_encode($value),
        );
    }

    /**
     * Strings matching a regular-expression subset. The pattern is compiled to
     * ordinary combinators, so matches shrink toward shorter/simpler strings.
     *
     * Supported: literals, `.`, character classes `[...]` (ranges, negation,
     * `\d\w\s` and their negations), the escapes `\d\w\s\D\W\S\t\n\r` plus
     * `\`-escaped metacharacters, quantifiers `* + ? {n} {n,} {n,m}`,
     * alternation `|`, and groups `(...)` / `(?:...)`. A single leading `^` and
     * trailing `$` are accepted as no-ops. Anchors elsewhere, backreferences,
     * lookaround, named/inline groups, and flags throw an
     * {@see \InvalidArgumentException} naming the construct.
     *
     * @param int $maxRepeat Upper bound generation uses for unbounded quantifiers (`*`, `+`, `{n,}`).
     *
     * @return ArbitraryInterface<string>
     */
    public static function regex(string $pattern, int $maxRepeat = 8): ArbitraryInterface
    {
        /** @var ArbitraryInterface<string> $arbitrary */
        $arbitrary = RegexCompiler::compile($pattern, $maxRepeat);

        return $arbitrary;
    }

    /**
     * Alias of {@see regex()} for parity with fast-check/Hypothesis naming.
     *
     * @return ArbitraryInterface<string>
     */
    public static function stringMatching(string $pattern, int $maxRepeat = 8): ArbitraryInterface
    {
        return self::regex($pattern, $maxRepeat);
    }

    /**
     * A valid {@see \Rasuvaeff\PropertyTesting\StateMachine\Command} sequence for
     * stateful / model-based testing. Starting from $initialModel, each step draws
     * a command generator and appends its command when the command's precondition
     * holds in the running model, advancing the model — so the sequence is valid
     * by construction. Shrinking drops individual steps and simplifies each command
     * through its own tree. A sequence shorter than $minLength (no applicable
     * command reached it) throws {@see GenerationExhausted}.
     *
     * Feed the generated {@see \Rasuvaeff\PropertyTesting\StateMachine\CommandSequence}
     * to {@see \Rasuvaeff\PropertyTesting\StateMachine\StateMachine::check()} in the
     * property body, passing a factory that builds a fresh system under test.
     *
     * @param list<ArbitraryInterface> $commandGenerators Each must produce a {@see \Rasuvaeff\PropertyTesting\StateMachine\Command}.
     */
    public static function commands(
        mixed $initialModel,
        array $commandGenerators,
        int $minLength = 0,
        int $maxLength = 100,
    ): CommandSequenceArbitrary {
        return new CommandSequenceArbitrary($initialModel, $commandGenerators, $minLength, $maxLength);
    }

    /**
     * Eagerly generate $count values from $arbitrary using a fixed $seed. A
     * debugging aid for inspecting a generator's output and distribution; unlike
     * the other factories it returns values, not an arbitrary.
     *
     * @template TValue
     *
     * @param ArbitraryInterface<TValue> $arbitrary
     *
     * @return list<TValue>
     */
    public static function sample(ArbitraryInterface $arbitrary, int $count = 10, int $seed = 0): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Count must be greater than or equal to 1');
        }

        $random = new Random($seed);

        /** @var list<TValue> $values */
        $values = array_map(
            static fn(int $i): mixed => $arbitrary->generate($random)->value,
            range(1, $count),
        );

        return $values;
    }

    /**
     * Eagerly generate one value from $arbitrary for a fixed $seed and collect
     * its first direct shrink candidates. A debugging aid for authors of custom
     * {@see ArbitraryInterface}s: eyeball what the shrink tree offers before
     * wiring the arbitrary into a property.
     *
     * @template TValue
     *
     * @param ArbitraryInterface<TValue> $arbitrary
     *
     * @return array{value: TValue, shrinks: list<TValue>}
     */
    public static function sampleShrinks(ArbitraryInterface $arbitrary, int $seed = 0, int $limit = 10): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Limit must be greater than or equal to 1');
        }

        $shrinkable = $arbitrary->generate(new Random($seed));

        /** @var list<Shrinkable<TValue>> $candidates */
        $candidates = [];
        foreach ($shrinkable->shrinks() as $candidate) {
            $candidates[] = $candidate;

            if (count($candidates) >= $limit) {
                break;
            }
        }

        /** @var array{value: TValue, shrinks: list<TValue>} $result */
        $result = [
            'value' => $shrinkable->value,
            'shrinks' => array_map(static fn(Shrinkable $candidate): mixed => $candidate->value, $candidates),
        ];

        return $result;
    }
}
