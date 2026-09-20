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
