<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Arbitrary\ArrayArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\FrequencyArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\MappedArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\TupleArbitrary;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;

/**
 * Compiles a regular-expression subset into ordinary combinators so generated
 * strings shrink through the existing arbitrary trees (no bespoke shrink logic).
 *
 * Grammar (recursive descent): alternation `|` -> {@see FrequencyArbitrary};
 * concatenation -> `tuple(...)` mapped through `implode`; quantifier
 * (`* + ? {n} {n,} {n,m}`) -> `arrayOf(atom, lo, hi)` mapped through `implode`;
 * atom -> group `(...)`/`(?:...)`, class `[...]`, escape `\x`, `.`, or a literal.
 * Unsupported constructs (anchors other than a single leading `^`/trailing `$`,
 * backreferences, lookaround, named/inline groups, flags) throw, naming the
 * construct — a generator that silently ignored them would emit non-matching
 * strings.
 *
 * @internal
 */
final class RegexCompiler
{
    /** @var list<string> */
    private array $chars;

    private int $pos = 0;

    private function __construct(
        string $pattern,
        private readonly int $maxRepeat,
    ) {
        $this->chars = mb_str_split($pattern, 1, 'UTF-8');
    }

    public static function compile(string $pattern, int $maxRepeat = 8): ArbitraryInterface
    {
        if ($maxRepeat < 1) {
            throw new \InvalidArgumentException('Regex maxRepeat must be greater than or equal to 1');
        }

        // A single leading ^ / trailing $ is redundant when the whole string is
        // generated, so accept them as no-ops. An escaped \$ stays literal.
        $body = $pattern;
        if (str_starts_with($body, '^')) {
            $body = substr($body, 1);
        }
        if (str_ends_with($body, '$') && !str_ends_with($body, '\\$')) {
            $body = substr($body, 0, -1);
        }

        $compiler = new self($body, $maxRepeat);
        $arbitrary = $compiler->alternation();

        if (!$compiler->atEnd()) {
            throw new \InvalidArgumentException(sprintf(
                'Unexpected "%s" in regex pattern at position %d',
                $compiler->peek(),
                $compiler->pos,
            ));
        }

        return $arbitrary;
    }

    private function alternation(): ArbitraryInterface
    {
        $branches = [$this->concatenation()];

        while (!$this->atEnd() && $this->peek() === '|') {
            ++$this->pos;
            $branches[] = $this->concatenation();
        }

        if (count($branches) === 1) {
            return $branches[0];
        }

        return new FrequencyArbitrary(array_map(
            static fn(ArbitraryInterface $branch): array => [1, $branch],
            $branches,
        ));
    }

    private function concatenation(): ArbitraryInterface
    {
        $parts = [];

        while (!$this->atEnd() && $this->peek() !== '|' && $this->peek() !== ')') {
            $parts[] = $this->quantified();
        }

        if ($parts === []) {
            return new ConstantArbitrary('');
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return new MappedArbitrary(new TupleArbitrary(...$parts), $this->joiner());
    }

    private function quantified(): ArbitraryInterface
    {
        $atom = $this->atom();
        $bounds = $this->quantifier();

        if ($bounds === null) {
            return $atom;
        }

        [$min, $max] = $bounds;

        // A `{0}` / `{0,0}` quantifier means the atom never appears — an empty
        // string, not a zero-length repetition ArrayArbitrary would reject.
        if ($max === 0) {
            return new ConstantArbitrary('');
        }

        return new MappedArbitrary(new ArrayArbitrary($atom, $min, $max), $this->joiner());
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function quantifier(): ?array
    {
        if ($this->atEnd()) {
            return null;
        }

        $bounds = match ($this->peek()) {
            '*' => [0, $this->maxRepeat],
            '+' => [1, $this->maxRepeat],
            '?' => [0, 1],
            default => null,
        };

        if ($bounds !== null) {
            ++$this->pos;

            return $bounds;
        }

        if ($this->peek() === '{') {
            return $this->braceQuantifier();
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function braceQuantifier(): array
    {
        ++$this->pos; // consume '{'
        $min = $this->readInt();

        if (!$this->atEnd() && $this->peek() === '}') {
            ++$this->pos;

            return [$min, $min];
        }

        if ($this->atEnd() || $this->peek() !== ',') {
            throw new \InvalidArgumentException('Malformed regex quantifier: expected "," or "}"');
        }

        ++$this->pos; // consume ','

        if (!$this->atEnd() && $this->peek() === '}') {
            ++$this->pos;

            return [$min, max($min, $this->maxRepeat)];
        }

        $max = $this->readInt();

        if ($this->atEnd() || $this->peek() !== '}') {
            throw new \InvalidArgumentException('Malformed regex quantifier: expected "}"');
        }
        ++$this->pos;

        if ($max < $min) {
            throw new \InvalidArgumentException(sprintf('Regex quantifier {%d,%d} has max < min', $min, $max));
        }

        return [$min, $max];
    }

    private function atom(): ArbitraryInterface
    {
        $char = $this->peek();

        return match (true) {
            $char === '(' => $this->group(),
            $char === '[' => $this->characterClass(),
            $char === '\\' => $this->escape(),
            $char === '.' => $this->consumeAndReturn($this->oneOf($this->printable())),
            $char === '^', $char === '$' => throw new \InvalidArgumentException(
                'Regex anchors are only supported as a single leading "^" or trailing "$"',
            ),
            $char === '*', $char === '+', $char === '?', $char === '{' => throw new \InvalidArgumentException(
                sprintf('Regex quantifier "%s" has nothing to repeat', $char),
            ),
            default => $this->consumeAndReturn(new ConstantArbitrary($char)),
        };
    }

    private function group(): ArbitraryInterface
    {
        ++$this->pos; // consume '('

        if (!$this->atEnd() && $this->peek() === '?') {
            if ($this->pos + 1 < count($this->chars) && $this->chars[$this->pos + 1] === ':') {
                $this->pos += 2; // non-capturing (?:...) — same as a capturing group for generation
            } else {
                throw new \InvalidArgumentException(
                    'Regex group modifiers (lookaround, named/inline groups, flags) are not supported',
                );
            }
        }

        $inner = $this->alternation();

        if ($this->atEnd() || $this->peek() !== ')') {
            throw new \InvalidArgumentException('Unterminated regex group: expected ")"');
        }
        ++$this->pos;

        return $inner;
    }

    private function characterClass(): ArbitraryInterface
    {
        ++$this->pos; // consume '['
        $negated = false;

        if (!$this->atEnd() && $this->peek() === '^') {
            $negated = true;
            ++$this->pos;
        }

        /** @var list<string> $members */
        $members = [];

        while (!$this->atEnd() && $this->peek() !== ']') {
            if ($this->peek() === '\\') {
                ++$this->pos;
                $members = [...$members, ...$this->classEscape($this->consume())];

                continue;
            }

            $char = $this->consume();

            // A range a-z: '-' not first/last in the class.
            if (!$this->atEnd() && $this->peek() === '-' && $this->pos + 1 < count($this->chars) && $this->chars[$this->pos + 1] !== ']') {
                ++$this->pos; // consume '-'
                $members = [...$members, ...$this->range($char, $this->consume())];

                continue;
            }

            $members[] = $char;
        }

        if ($this->atEnd()) {
            throw new \InvalidArgumentException('Unterminated regex character class: expected "]"');
        }
        ++$this->pos; // consume ']'

        $set = $negated ? $this->complement($members) : $this->unique($members);

        if ($set === []) {
            throw new \InvalidArgumentException('Regex character class matches no characters');
        }

        return $this->oneOf($set);
    }

    private function escape(): ArbitraryInterface
    {
        ++$this->pos; // consume '\'

        if ($this->atEnd()) {
            throw new \InvalidArgumentException('Trailing backslash in regex pattern');
        }

        $char = $this->consume();

        return match ($char) {
            'd', 'w', 's', 'D', 'W', 'S' => $this->oneOf($this->classEscape($char)),
            't' => new ConstantArbitrary("\t"),
            'n' => new ConstantArbitrary("\n"),
            'r' => new ConstantArbitrary("\r"),
            'b', 'B', 'A', 'Z', 'z', 'G' => throw new \InvalidArgumentException(
                sprintf('Regex assertion "\\%s" is not supported', $char),
            ),
            '1', '2', '3', '4', '5', '6', '7', '8', '9' => throw new \InvalidArgumentException(
                sprintf('Regex backreference "\\%s" is not supported', $char),
            ),
            default => new ConstantArbitrary($char),
        };
    }

    /**
     * The concrete character set of a class shorthand (`\d \w \s` and negations).
     *
     * @return list<string>
     */
    private function classEscape(string $char): array
    {
        return match ($char) {
            'd' => $this->digits(),
            'w' => $this->word(),
            's' => $this->whitespace(),
            'D' => $this->without($this->printable(), $this->digits()),
            'W' => $this->without($this->printable(), $this->word()),
            'S' => $this->without($this->printable(), $this->whitespace()),
            't' => ["\t"],
            'n' => ["\n"],
            'r' => ["\r"],
            default => [$char],
        };
    }

    private function readInt(): int
    {
        $digits = '';

        while (!$this->atEnd() && $this->peek() >= '0' && $this->peek() <= '9') {
            $digits .= $this->consume();
        }

        if ($digits === '') {
            throw new \InvalidArgumentException('Malformed regex quantifier: expected a number');
        }

        return (int) $digits;
    }

    private function consumeAndReturn(ArbitraryInterface $arbitrary): ArbitraryInterface
    {
        ++$this->pos;

        return $arbitrary;
    }

    private function consume(): string
    {
        $char = $this->peek();
        ++$this->pos;

        return $char;
    }

    private function peek(): string
    {
        if ($this->atEnd()) {
            throw new \InvalidArgumentException('Unexpected end of regex pattern');
        }

        return $this->chars[$this->pos];
    }

    private function atEnd(): bool
    {
        return $this->pos >= count($this->chars);
    }

    /**
     * @param list<string> $set
     */
    private function oneOf(array $set): OneOfArbitrary
    {
        // Simplest-first (ascending codepoint) so shrinking heads toward the
        // lowest character (e.g. '0' or 'a' rather than a random one).
        $sorted = $this->unique($set);
        usort($sorted, static fn(string $a, string $b): int => self::ord($a) <=> self::ord($b));

        return new OneOfArbitrary(...$sorted);
    }

    /**
     * @return \Closure(mixed): string
     */
    private function joiner(): \Closure
    {
        return static function (mixed $parts): string {
            \assert(is_array($parts));

            return implode('', array_map(static fn(mixed $p): string => is_string($p) ? $p : '', $parts));
        };
    }

    /**
     * @return list<string>
     */
    private function range(string $from, string $to): array
    {
        $lo = self::ord($from);
        $hi = self::ord($to);

        if ($hi < $lo) {
            throw new \InvalidArgumentException(sprintf('Regex character range "%s-%s" is out of order', $from, $to));
        }

        $chars = [];
        for ($code = $lo; $code <= $hi; ++$code) {
            $chars[] = $this->chr($code);
        }

        return $chars;
    }

    /**
     * @param list<string> $members
     * @return list<string>
     */
    private function complement(array $members): array
    {
        return $this->without($this->printable(), $members);
    }

    /**
     * @param list<string> $base
     * @param list<string> $remove
     * @return list<string>
     */
    private function without(array $base, array $remove): array
    {
        $drop = array_fill_keys($remove, value: true);

        return array_values(array_filter($base, static fn(string $char): bool => !isset($drop[$char])));
    }

    /**
     * @param list<string> $members
     * @return list<string>
     */
    private function unique(array $members): array
    {
        return array_values(array_unique($members));
    }

    /**
     * @return list<string>
     */
    private function digits(): array
    {
        return $this->range('0', '9');
    }

    /**
     * @return list<string>
     */
    private function word(): array
    {
        return [...$this->range('a', 'z'), ...$this->range('A', 'Z'), ...$this->digits(), '_'];
    }

    /**
     * @return list<string>
     */
    private function whitespace(): array
    {
        return [' ', "\t", "\n", "\r"];
    }

    /**
     * Printable ASCII (space..tilde) — the universe for `.` and negated classes.
     *
     * @return list<string>
     */
    private function printable(): array
    {
        return $this->range(' ', '~');
    }

    private static function ord(string $char): int
    {
        $code = mb_ord($char, 'UTF-8');

        return $code === false ? 0 : $code;
    }

    private function chr(int $code): string
    {
        $char = mb_chr($code, 'UTF-8');

        return $char === false ? '' : $char;
    }
}
