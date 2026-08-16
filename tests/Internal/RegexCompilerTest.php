<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Arbitrary\ConstantArbitrary;
use Rasuvaeff\PropertyTesting\Arbitrary\OneOfArbitrary;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\RegexCompiler;
use Rasuvaeff\PropertyTesting\Tests\Support\Trees;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RegexCompiler::class)]
final class RegexCompilerTest
{
    #[DataProvider('matchingPatterns')]
    public function everyGeneratedValueMatchesThePattern(string $pattern): void
    {
        $values = Gen::sample(RegexCompiler::compile($pattern), 40, 12345);

        foreach ($values as $value) {
            Assert::true(
                is_string($value) && preg_match('/^(?:' . $pattern . ')$/u', $value) === 1,
                sprintf('Value %s does not match /%s/', var_export($value, return: true), $pattern),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function matchingPatterns(): iterable
    {
        yield 'literal' => ['abc'];
        yield 'digits class' => ['[0-9]+'];
        yield 'word' => ['\\w{3,6}'];
        yield 'alternation' => ['cat|dog|bird'];
        yield 'optional' => ['colou?r'];
        yield 'group repeat' => ['(ab)+'];
        yield 'non-capturing group' => ['(?:xy)*z'];
        yield 'negated class' => ['[^0-9]{2}'];
        yield 'dot star' => ['a.*b'];
        yield 'digit escape' => ['\\d\\d\\d'];
        yield 'exact count' => ['x{4}'];
        yield 'range count' => ['[a-f]{2,4}'];
        yield 'mixed' => ['(foo|bar)_[0-9]{1,3}'];
        yield 'anchored' => ['^[a-z]+$'];
        yield 'escaped meta' => ['a\\.b\\*c'];
        yield 'unbounded lower' => ['a{2,}'];
        yield 'plus' => ['xa+y'];
        yield 'backspace class' => ['[\\b]'];
    }

    #[DataProvider('deterministicPatterns')]
    public function deterministicPatternGeneratesExactString(string $pattern, string $expected): void
    {
        foreach (Gen::sample(RegexCompiler::compile($pattern), 5, 9) as $value) {
            Assert::same($value, $expected);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deterministicPatterns(): iterable
    {
        yield 'plain literals' => ['abc', 'abc'];
        yield 'escaped meta' => ['a\\.b\\*c', 'a.b*c'];
        yield 'escaped brace' => ['a\\{b', 'a{b'];
        yield 'escaped pipe' => ['a\\|b', 'a|b'];
        yield 'capturing group' => ['(ab)c', 'abc'];
        yield 'non-capturing group' => ['(?:xy)z', 'xyz'];
        yield 'escaped tab/newline' => ['\\t\\n', "\t\n"];
        yield 'exact count' => ['a{3}', 'aaa'];
        yield 'zero count' => ['xa{0}y', 'xy'];
        yield 'range count both equal' => ['a{2,2}', 'aa'];
        yield 'nested groups' => ['((a)(b))', 'ab'];
        yield 'single-member class' => ['[q]', 'q'];
        yield 'single-char range' => ['[a-a]', 'a'];
        yield 'dash literal only' => ['[-]', '-'];
        yield 'dot inside class is literal' => ['[.]', '.'];
        yield 'group then literal' => ['(?:ab)cd', 'abcd'];
        yield 'literal then group' => ['xy(?:ab)', 'xyab'];
        yield 'empty group' => ['a()b', 'ab'];
        yield 'escaped dot in class' => ['[\\.]', '.'];
        yield 'backspace in class' => ['[\\b]', "\x08"];
        yield 'single-digit nine count' => ['a{9}', 'aaaaaaaaa'];
        yield 'two-digit count' => ['a{12}', 'aaaaaaaaaaaa'];
    }

    public function alternationReachesEveryBranch(): void
    {
        $values = Gen::sample(RegexCompiler::compile('a|b'), 60, 4);

        Assert::true(in_array('a', $values, strict: true));
        Assert::true(in_array('b', $values, strict: true));
    }

    public function dotProducesVariousCharacters(): void
    {
        $values = Gen::sample(RegexCompiler::compile('.'), 60, 4);

        // `.` is any printable character, not the literal dot.
        Assert::true($this->anyMatches($values, '/[^.]/'));
    }

    public function digitClassCoversBothBounds(): void
    {
        $values = Gen::sample(RegexCompiler::compile('[\\d]'), 200, 4);

        Assert::true(in_array('0', $values, strict: true));
        Assert::true(in_array('9', $values, strict: true));
    }

    public function starCanProduceEmptyAndRepeated(): void
    {
        $values = Gen::sample(RegexCompiler::compile('a*', 6), 80, 4);
        $lengths = array_map(static fn(mixed $v): int => is_string($v) ? strlen($v) : -1, $values);

        // The lower bound is 0 (empty possible) and it repeats up to the cap.
        Assert::true(in_array(0, $lengths, strict: true));
        Assert::true(max($lengths) >= 2);
        Assert::true(max($lengths) <= 6);
    }

    #[DataProvider('classPatterns')]
    public function characterClassGeneratesOnlyItsMembers(string $pattern, string $allowedRegex): void
    {
        foreach (Gen::sample(RegexCompiler::compile($pattern), 50, 4) as $value) {
            Assert::true(is_string($value) && preg_match('/^' . $allowedRegex . '$/', $value) === 1, var_export($value, return: true));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function classPatterns(): iterable
    {
        yield 'digit shorthand' => ['\\d', '[0-9]'];
        yield 'word shorthand' => ['\\w', '[A-Za-z0-9_]'];
        yield 'space shorthand' => ['\\s', '\\s'];
        yield 'negated digit' => ['\\D', '[^0-9]'];
        yield 'explicit range' => ['[a-c]', '[abc]'];
        yield 'negated class' => ['[^0-9]', '[^0-9]'];
        yield 'class with shorthand' => ['[\\d_]', '[0-9_]'];
    }

    #[DataProvider('shorthandPatterns')]
    public function shorthandAtomAndClassMatch(string $pattern, string $allowedRegex): void
    {
        foreach (Gen::sample(RegexCompiler::compile($pattern), 60, 4) as $value) {
            Assert::true(is_string($value) && preg_match('/^' . $allowedRegex . '$/', $value) === 1, var_export($value, return: true));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shorthandPatterns(): iterable
    {
        // Each shorthand, both as an atom and inside a class, pins its own match arm.
        yield '\\d atom' => ['\\d', '[0-9]'];
        yield '\\w atom' => ['\\w', '[A-Za-z0-9_]'];
        yield '\\s atom' => ['\\s', '\\s'];
        yield '\\D atom' => ['\\D', '[^0-9]'];
        yield '\\W atom' => ['\\W', '[^A-Za-z0-9_]'];
        yield '\\S atom' => ['\\S', '\\S'];
        yield '\\d class' => ['[\\d]', '[0-9]'];
        yield '\\w class' => ['[\\w]', '[A-Za-z0-9_]'];
        yield '\\s class' => ['[\\s]', '\\s'];
        yield '\\D class' => ['[\\D]', '[^0-9]'];
        yield '\\W class' => ['[\\W]', '[^A-Za-z0-9_]'];
        yield '\\S class' => ['[\\S]', '\\S'];
    }

    #[DataProvider('controlEscapePatterns')]
    public function controlEscapesAreExact(string $pattern, string $expected): void
    {
        foreach (Gen::sample(RegexCompiler::compile($pattern), 5, 4) as $value) {
            Assert::same($value, $expected);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controlEscapePatterns(): iterable
    {
        yield 'tab atom' => ['\\t', "\t"];
        yield 'newline atom' => ['\\n', "\n"];
        yield 'carriage return atom' => ['\\r', "\r"];
        yield 'tab in class' => ['[\\t]', "\t"];
        yield 'newline in class' => ['[\\n]', "\n"];
        yield 'carriage return in class' => ['[\\r]', "\r"];
    }

    public function wordShorthandCoversEveryCategory(): void
    {
        $values = Gen::sample(RegexCompiler::compile('\\w'), 400, 4);

        // Every character category the builder concatenates must be reachable —
        // dropping a range/item from `word()` would remove one of these.
        Assert::true($this->anyMatches($values, '/[a-z]/'));
        Assert::true($this->anyMatches($values, '/[A-Z]/'));
        Assert::true($this->anyMatches($values, '/\d/'));
        Assert::true(in_array('_', $values, strict: true));
    }

    public function whitespaceShorthandCoversEveryCharacter(): void
    {
        $values = Gen::sample(RegexCompiler::compile('\\s'), 400, 4);

        foreach ([' ', "\t", "\n", "\r"] as $whitespace) {
            Assert::true(in_array($whitespace, $values, strict: true));
        }
    }

    public function digitShorthandCoversBothBounds(): void
    {
        $values = Gen::sample(RegexCompiler::compile('\\d'), 200, 4);

        Assert::true(in_array('0', $values, strict: true));
        Assert::true(in_array('9', $values, strict: true));
    }

    #[DataProvider('multiCharShorthands')]
    public function shorthandProducesMultipleDistinctCharacters(string $pattern): void
    {
        $values = array_filter(Gen::sample(RegexCompiler::compile($pattern), 120, 4), is_string(...));
        $distinct = array_unique($values);

        // A dropped shorthand arm falls back to a single literal character, so
        // "at least two distinct characters" pins every shorthand's real set —
        // including the negated ones whose literal fallback would still match.
        Assert::true(count($distinct) >= 2);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiCharShorthands(): iterable
    {
        foreach (['\\d', '\\w', '\\s', '\\D', '\\W', '\\S'] as $shorthand) {
            yield $shorthand . ' atom' => [$shorthand];
            yield $shorthand . ' class' => ['[' . $shorthand . ']'];
        }
    }

    /**
     * @param list<mixed> $values
     */
    private function anyMatches(array $values, string $regex): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && preg_match($regex, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public function exactCountQuantifierProducesExactLength(): void
    {
        foreach (Gen::sample(RegexCompiler::compile('a{3}'), 10, 4) as $value) {
            Assert::same($value, 'aaa');
        }
    }

    public function optionalQuantifierProducesZeroOrOne(): void
    {
        $values = Gen::sample(RegexCompiler::compile('a?'), 40, 4);

        foreach ($values as $value) {
            Assert::true($value === '' || $value === 'a');
        }

        // Both the empty and the single-character case must be reachable.
        Assert::true(in_array('', $values, strict: true));
        Assert::true(in_array('a', $values, strict: true));
    }

    public function multibyteLiteralsAreGeneratedIntact(): void
    {
        foreach (Gen::sample(RegexCompiler::compile('café'), 5, 4) as $value) {
            Assert::same($value, 'café');
        }
    }

    public function maxRepeatOfOneAllowsASingleRepetition(): void
    {
        foreach (Gen::sample(RegexCompiler::compile('a+', 1), 10, 4) as $value) {
            Assert::same($value, 'a');
        }
    }

    #[DataProvider('errorMessagePatterns')]
    public function errorMessageNamesTheConstruct(string $pattern, string $needle): void
    {
        try {
            RegexCompiler::compile($pattern);
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains($needle);

            return;
        }

        Assert::fail(sprintf('Expected /%s/ to be rejected', $pattern));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorMessagePatterns(): iterable
    {
        yield 'backreference' => ['(a)\\1', 'backreference'];
        yield 'assertion escape' => ['\\bword', 'assertion'];
        yield 'group modifier' => ['(?=a)', 'group modifier'];
        yield 'anchor in middle' => ['a^b', 'anchor'];
        yield 'nothing to repeat' => ['*a', 'nothing to repeat'];
        yield 'unterminated group' => ['(ab', 'Unterminated regex group'];
        yield 'unterminated class' => ['[ab', 'Unterminated regex character class'];
        yield 'reversed quantifier' => ['a{5,2}', 'max < min'];
        yield 'reversed range' => ['[z-a]', 'out of order'];
        yield 'trailing backslash' => ['ab\\', 'Trailing backslash'];
        yield 'malformed quantifier' => ['a{2,x}', 'Malformed regex quantifier'];
        yield 'no comma or brace' => ['a{2x}', 'expected "," or "}"'];
        yield 'junk after upper bound' => ['a{2,3x}', 'expected "}"'];
        yield 'question nothing to repeat' => ['?x', 'nothing to repeat'];
        yield 'brace nothing to repeat' => ['{x', 'nothing to repeat'];
        yield 'no number after brace' => ['a{x}', 'expected a number'];
        yield 'unterminated non-capturing group' => ['(?:', 'Unterminated regex group'];
        yield 'bare group modifier at end' => ['(?', 'group modifiers'];
        yield 'dash at end of unterminated class' => ['[a-', 'Unterminated regex character class'];
        yield 'reversed range in unterminated class' => ['[z-a', 'out of order'];

        foreach (['B', 'A', 'Z', 'z', 'G'] as $assertion) {
            yield 'assertion \\' . $assertion => ['\\' . $assertion, 'assertion'];
        }

        foreach (['2', '3', '4', '5', '6', '7', '8', '9'] as $digit) {
            yield 'backreference \\' . $digit => ['a\\' . $digit, 'backreference'];
        }
    }

    public function anchorsAtBoundariesAreStripped(): void
    {
        $values = Gen::sample(RegexCompiler::compile('^ab$'), 5, 1);

        foreach ($values as $value) {
            Assert::same($value, 'ab');
        }
    }

    public function escapedDollarStaysLiteral(): void
    {
        $values = Gen::sample(RegexCompiler::compile('a\\$'), 5, 1);

        foreach ($values as $value) {
            Assert::same($value, 'a$');
        }
    }

    public function quantifierBoundsAreRespected(): void
    {
        $values = Gen::sample(RegexCompiler::compile('a{2,4}'), 60, 7);

        foreach ($values as $value) {
            Assert::true(is_string($value) && strlen($value) >= 2 && strlen($value) <= 4);
        }
    }

    public function unboundedQuantifierIsCappedByMaxRepeat(): void
    {
        $values = Gen::sample(RegexCompiler::compile('a+', 3), 80, 7);

        foreach ($values as $value) {
            Assert::true(is_string($value) && strlen($value) >= 1 && strlen($value) <= 3);
        }
    }

    #[DataProvider('unsupportedPatterns')]
    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsUnsupportedConstructs(string $pattern): void
    {
        RegexCompiler::compile($pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedPatterns(): iterable
    {
        yield 'backreference' => ['(a)\\1'];
        yield 'lookahead' => ['a(?=b)'];
        yield 'lookbehind' => ['(?<=a)b'];
        yield 'named group' => ['(?<name>a)'];
        yield 'inline flag' => ['(?i)abc'];
        yield 'word boundary' => ['\\bword'];
        yield 'anchor in middle' => ['a^b'];
        yield 'dollar in middle' => ['a$b'];
        yield 'unterminated group' => ['(abc'];
        yield 'unterminated class' => ['[abc'];
        yield 'dangling star' => ['*abc'];
        yield 'dangling plus' => ['+'];
        yield 'nothing before quantifier' => ['(|*)'];
        yield 'malformed quantifier' => ['a{2,x}'];
        yield 'reversed quantifier' => ['a{5,2}'];
        yield 'trailing backslash' => ['abc\\'];
        yield 'reversed range' => ['[z-a]'];
        yield 'unknown escape \h' => ['\\h'];
        yield 'unknown escape \v' => ['\\v'];
        yield 'quote-literal mode \Q...\E' => ['a\\Qb+c\\E'];
        yield 'null escape' => ['\\0'];
        yield 'hex escape' => ['\\x41'];
        yield 'unknown escape in class' => ['[\\h]'];
        yield 'octal escape in class' => ['[\\1]'];
        yield 'lazy star' => ['a*?'];
        yield 'lazy plus' => ['a+?'];
        yield 'lazy question' => ['a??'];
        yield 'lazy brace quantifier' => ['a{2,4}?'];
        yield 'possessive star' => ['a*+'];
        yield 'possessive plus' => ['a++'];
    }

    /**
     * The docblock contract: an unsupported construct throws NAMING the
     * construct, so the message must say which escape or which quantifier
     * flavour was rejected — not a downstream parse error.
     */
    public function namesTheUnknownEscapeInTheRejection(): void
    {
        try {
            RegexCompiler::compile('\\h');
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('"\\h"');

            return;
        }

        Assert::fail('Expected \\h to be rejected');
    }

    public function namesTheLazyQuantifierInTheRejection(): void
    {
        try {
            RegexCompiler::compile('a*?');
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('lazy');

            return;
        }

        Assert::fail('Expected a*? to be rejected');
    }

    public function namesThePossessiveQuantifierInTheRejection(): void
    {
        try {
            RegexCompiler::compile('a*+');
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('possessive');

            return;
        }

        Assert::fail('Expected a*+ to be rejected');
    }

    public function maxRepeatMustBePositive(): void
    {
        try {
            RegexCompiler::compile('a+', 0);
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('maxRepeat');

            return;
        }

        Assert::fail('Expected maxRepeat=0 to be rejected');
    }

    public function bareGroupModifierProbeStaysInBounds(): void
    {
        // "(?": the ':' lookahead must short-circuit on the bounds check and
        // never read past the end of the pattern. An out-of-bounds read emits
        // a PHP warning, which this handler escalates past the catch below.
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, severity: $severity);
        });

        try {
            RegexCompiler::compile('(?');
            Assert::fail('Expected "(?" to be rejected');
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('group modifiers');
        } finally {
            restore_error_handler();
        }
    }

    public function singleBranchAndSinglePartAreNotWrapped(): void
    {
        // A one-branch alternation / one-part concatenation must return the
        // inner arbitrary itself, not a Frequency/Tuple wrapper: a wrapper
        // consumes extra random draws and shifts the seed->value mapping.
        Assert::instanceOf(RegexCompiler::compile('[ab]'), OneOfArbitrary::class);
        Assert::instanceOf(RegexCompiler::compile('a'), ConstantArbitrary::class);
    }

    public function alternationSequenceIsPinnedForSeed(): void
    {
        // Branch weights are exactly 1: changing them changes the total weight,
        // the random draw range, and therefore the sequence for a given seed.
        Assert::same(
            Gen::sample(RegexCompiler::compile('a|b'), 12, 7),
            ['b', 'a', 'b', 'a', 'b', 'b', 'b', 'b', 'a', 'b', 'a', 'b'],
        );
    }

    public function duplicateClassMembersAreDeduplicated(): void
    {
        // [aab] must compile to a two-value set: a kept duplicate widens the
        // index draw range and shifts the sequence for a given seed.
        Assert::same(
            Gen::sample(RegexCompiler::compile('[aab]'), 12, 7),
            ['b', 'a', 'b', 'a', 'b', 'b', 'b', 'b', 'a', 'b', 'a', 'b'],
        );
    }

    public function classShrinksTowardTheLowestCodepoint(): void
    {
        // [b-ca] lists members in order b, c, a — the compiler sorts them so
        // greedy shrinking terminates at 'a', not at the first-listed 'b'
        // (unsorted) or at 'c' (descending sort).
        $node = Trees::generateWhere(
            RegexCompiler::compile('[b-ca]'),
            static fn(mixed $value): bool => $value !== 'a',
        );

        for ($step = 0; ; ++$step) {
            $children = iterator_to_array($node->shrinks(), preserve_keys: false);

            if ($children === []) {
                break;
            }

            if ($step > 100) {
                Assert::fail('the greedy descent did not reach a leaf within 100 steps');
            }

            $node = $children[0];
        }

        Assert::same($node->value, 'a');
    }

    public function negatedClassStillGeneratesCaretAndBracket(): void
    {
        // The '^' marker (and the already-consumed '[') must not leak into the
        // negated member list — both characters stay generatable.
        $values = Gen::sample(RegexCompiler::compile('[^a]'), 200, 4);

        Assert::true(in_array('^', $values, strict: true));
        Assert::true(in_array('[', $values, strict: true));
        Assert::false(in_array('a', $values, strict: true));
    }

    public function classEscapeAppendsToEarlierMembers(): void
    {
        $values = Gen::sample(RegexCompiler::compile('[a\\d]'), 80, 4);

        foreach ($values as $value) {
            Assert::true(is_string($value) && preg_match('/^[a0-9]$/', $value) === 1, var_export($value, return: true));
        }

        // The literal listed before the shorthand must survive the merge.
        Assert::true(in_array('a', $values, strict: true));
    }

    public function rangeAppendsToEarlierMembersAndClassContinuesAfterIt(): void
    {
        // Literal before the range must survive the merge.
        $before = Gen::sample(RegexCompiler::compile('[xa-c]'), 80, 4);
        Assert::true(in_array('x', $before, strict: true));

        // Members after the range must still be parsed inside the class —
        // bailing out early would turn the rest into literal atoms.
        $after = Gen::sample(RegexCompiler::compile('[a-cx]'), 80, 4);
        Assert::true(in_array('x', $after, strict: true));

        foreach ([...$before, ...$after] as $value) {
            Assert::true(is_string($value) && preg_match('/^[xa-c]$/', $value) === 1, var_export($value, return: true));
        }
    }

    public function classWithoutDashNeverBecomesARange(): void
    {
        // [adz] is three literals: misreading a middle member as a range
        // separator would generate characters between 'a' and 'z'.
        $values = Gen::sample(RegexCompiler::compile('[adz]'), 60, 4);

        foreach ($values as $value) {
            Assert::true(in_array($value, ['a', 'd', 'z'], strict: true), var_export($value, return: true));
        }

        Assert::true(in_array('a', $values, strict: true));
        Assert::true(in_array('d', $values, strict: true));
        Assert::true(in_array('z', $values, strict: true));
    }

    public function trailingDashInClassIsLiteral(): void
    {
        // [a-] is {'a', '-'}: treating ']' as a range end would reject the
        // pattern as an out-of-order range.
        $values = Gen::sample(RegexCompiler::compile('[a-]'), 60, 4);

        foreach ($values as $value) {
            Assert::true($value === 'a' || $value === '-', var_export($value, return: true));
        }

        Assert::true(in_array('a', $values, strict: true));
        Assert::true(in_array('-', $values, strict: true));
    }

    public function multibyteRangeGeneratesOnlyItsMembers(): void
    {
        // Splitting the pattern into bytes (or taking the first byte's code)
        // would produce raw bytes outside the Cyrillic range.
        foreach (Gen::sample(RegexCompiler::compile('[а-я]'), 40, 4) as $value) {
            Assert::true(is_string($value) && preg_match('/^[а-я]$/u', $value) === 1, var_export($value, return: true));
        }
    }

    public function invalidUtf8RangeBoundDegradesToNul(): void
    {
        // A lone continuation byte has no codepoint; the compiler falls back
        // to codepoint 0, so the class degrades to a NUL byte instead of
        // crashing or silently producing another character.
        foreach (Gen::sample(RegexCompiler::compile("[\xB0-\xB1]"), 5, 1) as $value) {
            Assert::same($value, "\0");
        }
    }
}
