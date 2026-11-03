<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Enumerable;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Internal\NaughtyStrings;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(NaughtyStrings::class)]
#[Covers(Gen::class)]
final class NaughtyStringsTest
{
    public function theListStartsWithTheEmptyStringAndEndsWithTheLongest(): void
    {
        $all = NaughtyStrings::all();

        Assert::same($all[0], '');
        Assert::same($all[count($all) - 1], str_repeat('a', 65_536));
        Assert::same(count($all), 103);
    }

    public function everyEntryIsDistinct(): void
    {
        $all = NaughtyStrings::all();

        Assert::same(count(array_unique($all)), count($all));
    }

    #[DataProvider('requiredEntryProvider')]
    public function theListCarriesTheEntry(string $entry): void
    {
        Assert::true(in_array($entry, NaughtyStrings::all(), strict: true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredEntryProvider(): iterable
    {
        yield 'trailing line feed' => ["a\n"];
        yield 'NUL' => ["\0"];
        yield 'one past PHP_INT_MAX' => ['9223372036854775808'];
        yield 'null literal' => ['null'];
        yield 'SQL injection' => ["' OR '1'='1"];
        yield 'path traversal' => ['../../../../etc/passwd'];
        yield 'header injection' => ["\r\nSet-Cookie: x=1"];
        yield 'decomposed e acute' => ["e\u{0301}"];
        yield 'right-to-left override' => ["\u{202E}abc"];
        yield 'truncated UTF-8' => ["\xC3\x28"];
        yield 'encoded surrogate' => ["\xED\xA0\x80"];
        yield 'past 256 characters' => [str_repeat('a', 256)];
        yield 'past 1024 characters' => [str_repeat('a', 1_025)];
    }

    public function theListHoldsEntriesThatAreNotValidUtf8(): void
    {
        $invalid = array_values(array_filter(
            NaughtyStrings::all(),
            static fn(string $entry): bool => !mb_check_encoding($entry, 'UTF-8'),
        ));

        Assert::same($invalid, ["\xC3\x28", "\xED\xA0\x80", "\xC0\xAF", "\xFF"]);
    }

    public function theGeneratorEnumeratesEveryEntryInListedOrder(): void
    {
        $arbitrary = Gen::naughtyString();

        Assert::instanceOf($arbitrary, Enumerable::class);
        Assert::same($arbitrary->domainSize(), count(NaughtyStrings::all()));

        $walked = [];
        foreach ($arbitrary->enumerate() as $node) {
            $walked[] = $node->value;
        }

        Assert::same($walked, NaughtyStrings::all());
    }

    public function aValidatorAnchoredWithDollarIsFalsifiedByATrailingLineFeed(): void
    {
        // PCRE `$` matches before a final "\n": the validator accepts "a\n",
        // which the property says it must not. The failure shrinks through
        // the entries listed before "a\n", none of which the regex accepts.
        $result = $this->run(
            static function (string $input): void {
                if (preg_match('/^[a-z]+$/', $input) === 1 && str_contains($input, "\n")) {
                    throw new \RuntimeException('accepted a line feed');
                }
            },
            exhaustive: false,
        );

        Assert::instanceOf($result, Falsified::class);
        Assert::same($result->counterExample()->shrunkArguments, ['input' => "a\n"]);
    }

    public function theSameValidatorAnchoredWithZIsNotFalsified(): void
    {
        $result = $this->run(
            static function (string $input): void {
                if (preg_match('/^[a-z]+\z/', $input) === 1 && str_contains($input, "\n")) {
                    throw new \RuntimeException('accepted a line feed');
                }
            },
            exhaustive: true,
        );

        Assert::instanceOf($result, Passed::class);
        Assert::same($result->statistics->domainSize, count(NaughtyStrings::all()));
        Assert::same($result->statistics->checks, count(NaughtyStrings::all()));
    }

    /**
     * @param \Closure(string): void $body
     */
    private function run(\Closure $body, bool $exhaustive): PropertyResult
    {
        return (new PropertyRunner())->run(
            new PropertyDefinition(
                id: 'naughty::property',
                name: 'property',
                generators: ['input' => Gen::naughtyString()],
                parameterNames: ['input'],
                config: new PropertyConfig(runs: 500, seed: 7, exhaustive: $exhaustive),
            ),
            new CallableTrialExecutor($body),
        );
    }
}
