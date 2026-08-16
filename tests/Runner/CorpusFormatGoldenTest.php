<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Tests\Support\Priority;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Byte-exact characterization of the on-disk corpus format (FORMAT_VERSION 1),
 * against fixtures committed under tests/fixtures/corpus.
 *
 * Two directions, deliberately separate: writing must still produce exactly
 * the committed document, and a committed 2.8-era document must still recall.
 * The second is the compatibility promise of the package split (a corpus
 * recorded by 2.8 keeps replaying after the engine moves to
 * property-testing-core); the first catches an accidental format change before
 * it ships. A deliberate format change bumps FORMAT_VERSION and regenerates
 * the fixtures in the same commit; an OPTIONAL field (readers treat its
 * absence as the previous behaviour, like `runsBeforeFailure`) may be added
 * without a bump, with the pre-field document kept as a legacy fixture.
 */
#[Test]
#[Covers(FilesystemCorpus::class)]
final class CorpusFormatGoldenTest
{
    private const string FIXTURES = __DIR__ . '/../fixtures/corpus';

    private string $dir = '';

    public function writingASimpleValuesEntryProducesTheCommittedDocument(): void
    {
        $this->storage()->remember('X::y', $this->counterExample(['x' => 51], 4242), ['x']);

        Assert::same(
            file_get_contents($this->path('X::y')),
            file_get_contents(self::FIXTURES . '/values-entry.json'),
        );
    }

    /**
     * Every codec envelope shape in one document: a tagged float, tagged
     * binary, a positional array with string/integer key markers, and an enum.
     */
    public function writingARichValuesEntryProducesTheCommittedDocument(): void
    {
        $arguments = [
            'i' => -1,
            'f' => -0.0,
            's' => "\xff\x00raw",
            'a' => ['k' => [1, null, true], 3 => 'gap'],
            'e' => Priority::High,
        ];

        $this->storage()->remember('R::rich', $this->counterExample($arguments, 7), array_keys($arguments));

        Assert::same(
            file_get_contents($this->path('R::rich')),
            file_get_contents(self::FIXTURES . '/rich-values-entry.json'),
        );
    }

    /**
     * `draw#N` pseudo-arguments are unrepresentable as values, so the entry
     * falls back to a bare seed.
     */
    public function writingASeedEntryProducesTheCommittedDocument(): void
    {
        $this->storage()->remember(
            'S::seeded',
            new CounterExample(
                seed: 99,
                runsBeforeFailure: 3,
                originalArguments: ['draw#1' => 5],
                shrunkArguments: ['draw#1' => 4],
            ),
            [],
        );

        Assert::same(
            file_get_contents($this->path('S::seeded')),
            file_get_contents(self::FIXTURES . '/seed-entry.json'),
        );
    }

    public function aCommittedValuesDocumentRecalls(): void
    {
        copy(self::FIXTURES . '/values-entry.json', $this->path('X::y'));

        $entries = $this->storage()->recall('X::y', ['x']);

        Assert::same(count($entries), 1);
        Assert::true($entries[0]->isValues());
        Assert::same($entries[0]->arguments, ['x' => 51]);
        Assert::same($entries[0]->seed, 4242);
    }

    public function aCommittedRichValuesDocumentRecallsLosslessly(): void
    {
        copy(self::FIXTURES . '/rich-values-entry.json', $this->path('R::rich'));

        $entries = $this->storage()->recall('R::rich', ['i', 'f', 's', 'a', 'e']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, [
            'i' => -1,
            'f' => -0.0,
            's' => "\xff\x00raw",
            'a' => ['k' => [1, null, true], 3 => 'gap'],
            'e' => Priority::High,
        ]);
    }

    public function aCommittedSeedDocumentRecalls(): void
    {
        copy(self::FIXTURES . '/seed-entry.json', $this->path('S::seeded'));

        $entries = $this->storage()->recall('S::seeded', []);

        Assert::same(count($entries), 1);
        Assert::false($entries[0]->isValues());
        Assert::same($entries[0]->seed, 99);
        Assert::same($entries[0]->runsBeforeFailure, 3);
    }

    /**
     * A seed document written before `runsBeforeFailure` existed (2.8 through
     * 0.4.x) must keep recalling — the optional field is exactly why
     * FORMAT_VERSION did not change.
     */
    public function aCommittedPreFieldSeedDocumentStillRecalls(): void
    {
        copy(self::FIXTURES . '/legacy-seed-entry.json', $this->path('S::seeded'));

        $entries = $this->storage()->recall('S::seeded', []);

        Assert::same(count($entries), 1);
        Assert::false($entries[0]->isValues());
        Assert::same($entries[0]->seed, 99);
        Assert::null($entries[0]->runsBeforeFailure);
    }

    #[BeforeTest]
    public function setUpDirectory(): void
    {
        $this->dir = sys_get_temp_dir() . '/corpus-golden-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, recursive: true);
    }

    #[AfterTest]
    public function tearDownDirectory(): void
    {
        array_map(unlink(...), array_merge(
            glob($this->dir . '/*.json') ?: [],
            glob($this->dir . '/*.lock') ?: [],
        ));
        rmdir($this->dir);
    }

    private function storage(): FilesystemCorpus
    {
        return new FilesystemCorpus($this->dir);
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . sha1($id) . '.json';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function counterExample(array $arguments, int $seed): CounterExample
    {
        return new CounterExample(
            seed: $seed,
            runsBeforeFailure: 0,
            originalArguments: $arguments,
            shrunkArguments: $arguments,
        );
    }
}
