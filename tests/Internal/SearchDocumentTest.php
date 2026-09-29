<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Internal;

use Rasuvaeff\PropertyTesting\Internal\SearchDocument;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(SearchDocument::class)]
final class SearchDocumentTest
{
    public function roundTripsTargetsWithTheirArguments(): void
    {
        $targets = [
            'delay' => ['direction' => TargetDirection::Maximize, 'entries' => [
                ['score' => 12.5, 'arguments' => ['base' => 3, 'label' => 'x']],
                ['score' => 3.0, 'arguments' => ['base' => 1, 'label' => '']],
            ]],
            'depth' => ['direction' => TargetDirection::Minimize, 'entries' => [
                ['score' => 1.0, 'arguments' => ['base' => 0, 'label' => 'y']],
            ]],
        ];

        $document = SearchDocument::encode('p', $targets, ['base', 'label']);

        Assert::true(is_string($document));
        Assert::json($document)->isObject()->hasKeys('id', 'format', 'targets');
        Assert::same(SearchDocument::decode($document, ['base', 'label']), $targets);
    }

    public function theDocumentIsPrettyPrintedUnescapedAndNewlineTerminated(): void
    {
        $document = (string) SearchDocument::encode('p', ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 1.0, 'arguments' => ['a' => 'x/y ü']]]]], ['a']);

        Assert::true(str_ends_with($document, "}\n"));
        Assert::false(str_starts_with($document, "\n"));
        Assert::string($document)->contains("\n    \"format\": 1");
        Assert::string($document)->contains('x/y ü');
    }

    public function aLabelWithNothingRepresentableIsSkippedAndTheNextIsKept(): void
    {
        $targets = [
            'closures' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 2.0, 'arguments' => ['a' => static fn(): int => 1]]]],
            'ints' => ['direction' => TargetDirection::Minimize, 'entries' => [['score' => 1.0, 'arguments' => ['a' => 1]]]],
        ];

        $decoded = SearchDocument::decode((string) SearchDocument::encode('p', $targets, ['a']), ['a']);

        Assert::same(array_keys($decoded), ['ints']);
        Assert::same($decoded['ints']['direction'], TargetDirection::Minimize);
    }

    public function decodingSkipsEveryMalformedLabelAndEntryAndKeepsTheRest(): void
    {
        $document = json_encode(['id' => 'p', 'format' => 1, 'targets' => [
            '7' => ['direction' => 'maximize', 'entries' => [['score' => 1, 'args' => ['a' => 1]]]],
            'noEntries' => ['direction' => 'maximize'],
            'sideways' => ['direction' => 'sideways', 'entries' => [['score' => 1, 'args' => ['a' => 1]]]],
            'ok' => ['direction' => 'maximize', 'entries' => [
                ['args' => ['a' => 1]],
                ['score' => 1],
                ['score' => 1, 'args' => ['a' => ['~' => 'nope']]],
                ['score' => 3, 'args' => ['a' => 3]],
            ]],
            'alsoOk' => ['direction' => 'minimize', 'entries' => [['score' => 4, 'args' => ['a' => 4]]]],
        ]], JSON_THROW_ON_ERROR);

        Assert::same(SearchDocument::decode($document, ['a']), [
            'ok' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 3.0, 'arguments' => ['a' => 3]]]],
            'alsoOk' => ['direction' => TargetDirection::Minimize, 'entries' => [['score' => 4.0, 'arguments' => ['a' => 4]]]],
        ]);
    }

    public function aForeignFormatWithValidEntriesStillDecodesToNothing(): void
    {
        $document = json_encode(['id' => 'p', 'format' => 99, 'targets' => ['n' => ['direction' => 'maximize', 'entries' => [['score' => 1, 'args' => ['a' => 1]]]]]], JSON_THROW_ON_ERROR);

        Assert::same(SearchDocument::decode($document, ['a']), []);
    }

    public function parameterOrderDoesNotMatterForRecallOnlyTheNamesDo(): void
    {
        $document = (string) SearchDocument::encode('p', ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 1.0, 'arguments' => ['a' => 1, 'b' => 2]]]]], ['b', 'a']);

        Assert::same(SearchDocument::decode($document, ['b', 'a'])['n']['entries'][0]['arguments'], ['b' => 2, 'a' => 1]);
        Assert::same(SearchDocument::decode($document, ['a', 'b'])['n']['entries'][0]['arguments'], ['a' => 1, 'b' => 2]);
    }

    public function anIntegralScoreComesBackAsAFloat(): void
    {
        $document = SearchDocument::encode('p', ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 3.0, 'arguments' => ['a' => 1]]]]], ['a']);

        Assert::same(SearchDocument::decode((string) $document, ['a'])['n']['entries'][0]['score'], 3.0);
    }

    public function anEntryTheCodecCannotRepresentIsLeftOut(): void
    {
        $targets = ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [
            ['score' => 2.0, 'arguments' => ['a' => static fn(): int => 1]],
            ['score' => 1.0, 'arguments' => ['a' => 1]],
        ]]];

        $decoded = SearchDocument::decode((string) SearchDocument::encode('p', $targets, ['a']), ['a']);

        Assert::same($decoded['n']['entries'], [['score' => 1.0, 'arguments' => ['a' => 1]]]);
    }

    public function nothingRepresentableEncodesToNothing(): void
    {
        Assert::null(SearchDocument::encode('p', [], ['a']));
        Assert::null(SearchDocument::encode('p', ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 1.0, 'arguments' => ['b' => 1]]]]], ['a']));
        Assert::null(SearchDocument::encode('p', ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 1.0, 'arguments' => ['a' => 1, 'b' => 2]]]]], ['a']));
    }

    public function anEntryRecordedUnderOtherParametersIsDropped(): void
    {
        $document = (string) SearchDocument::encode('p', ['n' => ['direction' => TargetDirection::Maximize, 'entries' => [['score' => 1.0, 'arguments' => ['a' => 1]]]]], ['a']);

        Assert::same(SearchDocument::decode($document, ['b']), []);
        Assert::same(SearchDocument::decode($document, ['a', 'b']), []);
    }

    #[DataProvider('corruptProvider')]
    public function corruptOrForeignContentDecodesToNothing(string $content): void
    {
        Assert::same(SearchDocument::decode($content, ['a']), []);
    }

    public static function corruptProvider(): iterable
    {
        yield 'not json' => ['{'];
        yield 'not an object' => ['[1, 2]'];
        yield 'foreign format' => ['{"id":"p","format":99,"targets":{}}'];
        yield 'targets not an object' => ['{"id":"p","format":1,"targets":3}'];
        yield 'target without entries' => ['{"id":"p","format":1,"targets":{"n":{"direction":"maximize"}}}'];
        yield 'unknown direction' => ['{"id":"p","format":1,"targets":{"n":{"direction":"sideways","entries":[{"score":1,"args":{"a":1}}]}}}'];
        yield 'entry without a score' => ['{"id":"p","format":1,"targets":{"n":{"direction":"maximize","entries":[{"args":{"a":1}}]}}}'];
        yield 'entry without args' => ['{"id":"p","format":1,"targets":{"n":{"direction":"maximize","entries":[{"score":1}]}}}'];
        yield 'entry with undecodable args' => ['{"id":"p","format":1,"targets":{"n":{"direction":"maximize","entries":[{"score":1,"args":{"a":{"~":"nope"}}}]}}}'];
        yield 'numeric label' => ['{"id":"p","format":1,"targets":{"7":{"direction":"maximize","entries":[{"score":1,"args":{"a":1}}]}}}'];
    }
}
