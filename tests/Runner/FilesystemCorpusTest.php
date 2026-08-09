<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Tests\Support\Priority;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FilesystemCorpus::class)]
final class FilesystemCorpusTest
{
    private const string ID = 'X::y';

    private string $dir = '';

    public function recallIsEmptyWithoutARecord(): void
    {
        Assert::same($this->storage()->recall(self::ID, ['x']), []);
    }

    public function remembersTheMinimisedInputAsAValuesEntry(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 4242), ['x']);

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::true($entries[0]->isValues());
        Assert::same($entries[0]->arguments, ['x' => 51]);
        Assert::same($entries[0]->seed, 4242);
    }

    public function remembersRichValuesLosslessly(): void
    {
        $arguments = [
            'i' => -1,
            's' => "\xff\x00raw",
            'f' => -0.0,
            'a' => ['k' => [1, null, true], 3 => 'gap'],
            'e' => Priority::High,
        ];
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample($arguments, 7), array_keys($arguments));

        $entries = $storage->recall(self::ID, array_keys($arguments));

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, $arguments);
    }

    public function fallsBackToASeedEntryWhenAValueIsNotRepresentable(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['d' => new \DateTimeImmutable('2026-01-01')], 99), ['d']);

        $entries = $storage->recall(self::ID, ['d']);

        Assert::same(count($entries), 1);
        Assert::false($entries[0]->isValues());
        Assert::same($entries[0]->seed, 99);
    }

    /**
     * In-body `Gen::draw()` results ride along in `shrunkArguments` as `draw#N`
     * pseudo-arguments. Replaying only the named parameters would let the body
     * draw fresh values, so such a counterexample must be stored as a seed.
     */
    public function fallsBackToASeedEntryWhenDrawPseudoArgumentsArePresent(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 1, 'draw#1' => 5], 33), ['x']);

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::false($entries[0]->isValues());
        Assert::same($entries[0]->seed, 33);
    }

    public function accumulatesSeveralDistinctFailures(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);
        $storage->remember(self::ID, $this->counterExample(['x' => 77], 2), ['x']);

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 2);
        // Newest first.
        Assert::same($entries[0]->arguments, ['x' => 77]);
        Assert::same($entries[1]->arguments, ['x' => 51]);
    }

    public function recordingTheSameInputTwiceKeepsOneEntry(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 2), ['x']);

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        // The newer recording wins, so the reported seed is the latest one.
        Assert::same($entries[0]->seed, 2);
    }

    public function valuesEntriesComeBackBeforeSeedEntries(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => new \stdClass()], 1), ['x']);
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 2), ['x']);

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 2);
        // A values entry costs one run, a seed entry a whole random phase.
        Assert::true($entries[0]->isValues());
        Assert::false($entries[1]->isValues());
    }

    public function capsValuesEntriesAndEvictsTheOldest(): void
    {
        $storage = $this->storage();

        for ($x = 1; $x <= 11; ++$x) {
            $storage->remember(self::ID, $this->counterExample(['x' => $x], $x), ['x']);
        }

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 8);
        Assert::same($entries[0]->arguments, ['x' => 11]);
        Assert::same($entries[7]->arguments, ['x' => 4]);
    }

    public function capsSeedEntriesMoreTightlyThanValuesEntries(): void
    {
        $storage = $this->storage();

        for ($seed = 1; $seed <= 5; ++$seed) {
            $storage->remember(self::ID, $this->counterExample(['x' => new \stdClass()], $seed), ['x']);
        }

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 2);
        Assert::same($entries[0]->seed, 5);
        Assert::same($entries[1]->seed, 4);
    }

    /**
     * The cap skips over-quota entries, it does not stop at the first one: the
     * entries are ordered newest-first with both kinds interleaved, so bailing out
     * would silently drop everything recorded before an over-quota entry.
     */
    public function cappingSeedEntriesDoesNotDropOlderValuesEntries(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        for ($seed = 2; $seed <= 4; ++$seed) {
            $storage->remember(self::ID, $this->counterExample(['x' => new \stdClass()], $seed), ['x']);
        }

        $entries = $storage->recall(self::ID, ['x']);

        // Two seeds (capped from three) plus the values entry recorded first.
        Assert::same(count($entries), 3);
        Assert::true($entries[0]->isValues());
        Assert::same($entries[0]->arguments, ['x' => 51]);
    }

    public function cappingValuesEntriesDoesNotDropAnOlderSeedEntry(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => new \stdClass()], 1), ['x']);

        for ($x = 1; $x <= 9; ++$x) {
            $storage->remember(self::ID, $this->counterExample(['x' => $x], $x + 1), ['x']);
        }

        $entries = $storage->recall(self::ID, ['x']);

        // Eight values (capped from nine) plus the seed entry recorded first.
        Assert::same(count($entries), 9);
        Assert::false($entries[8]->isValues());
        Assert::same($entries[8]->seed, 1);
    }

    /**
     * The corpus is meant to be readable and diffable by hand, and its `entries`
     * field must stay a JSON array — pruning must not leave gapped integer keys
     * that `json_encode()` would turn into an object.
     */
    public function storesAPrettyPrintedDocumentWhoseEntriesStayAJsonList(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);
        $storage->remember(self::ID, $this->counterExample(['x' => 77], 2), ['x']);
        $storage->remember(self::ID, $this->counterExample(['x' => 99], 3), ['x']);

        // Removes the middle entry, leaving keys 0 and 2 behind unless reindexed.
        $storage->prune(self::ID, CorpusEntry::values(['x' => 77], 2));

        $content = (string) file_get_contents($this->file());
        Assert::string($content)->contains("\n");

        /** @var array<string, mixed> $document */
        $document = json_decode($content, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        Assert::true(array_is_list($document['entries']));
    }

    public function pruneRemovesOnlyTheGivenValuesEntry(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);
        $storage->remember(self::ID, $this->counterExample(['x' => 77], 2), ['x']);

        $storage->prune(self::ID, CorpusEntry::values(['x' => 51], 1));
        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, ['x' => 77]);
    }

    public function pruneRemovesASeedEntryByItsSeed(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => new \stdClass()], 99), ['x']);

        $storage->prune(self::ID, CorpusEntry::seed(99));

        Assert::same($storage->recall(self::ID, ['x']), []);
    }

    public function pruningTheLastEntryRemovesTheFile(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        Assert::true(is_file($this->file()));

        $storage->prune(self::ID, CorpusEntry::values(['x' => 51], 1));

        Assert::false(is_file($this->file()));
    }

    public function pruningAnUnknownEntryIsANoOp(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        $storage->prune(self::ID, CorpusEntry::values(['x' => 999], 1));

        Assert::same(count($storage->recall(self::ID, ['x'])), 1);
    }

    /**
     * A renamed, reordered or added parameter makes a stored input a different
     * input — replaying it would silently test something else.
     */
    public function dropsValuesEntriesWhoseArgumentNamesNoLongerMatch(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 51], 1), ['x']);

        Assert::same($storage->recall(self::ID, ['renamed']), []);
        Assert::same($storage->recall(self::ID, ['x', 'extra']), []);
    }

    public function ordersRecalledArgumentsByTheCurrentParameterList(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['a' => 1, 'b' => 2], 1), ['a', 'b']);

        $entries = $storage->recall(self::ID, ['b', 'a']);

        Assert::same(count($entries), 1);
        Assert::same(array_keys((array) $entries[0]->arguments), ['b', 'a']);
    }

    public function dropsSeedEntriesFromASupersededSequenceEpoch(): void
    {
        $this->writeRaw([
            ['kind' => 'seed', 'seed' => 5, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH + 1],
            ['kind' => 'seed', 'seed' => 6, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH],
        ]);

        $entries = $this->storage()->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->seed, 6);
    }

    /**
     * Values entries carry the input itself, so a shifted generation sequence
     * cannot invalidate them.
     */
    public function keepsValuesEntriesRegardlessOfTheSequenceEpoch(): void
    {
        $this->writeRaw([
            ['kind' => 'values', 'seed' => 1, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH + 1, 'args' => ['x' => 51]],
        ]);

        $entries = $this->storage()->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, ['x' => 51]);
    }

    public function ignoresAForeignFormatVersion(): void
    {
        file_put_contents($this->file(), json_encode([
            'format' => FilesystemCorpus::FORMAT_VERSION + 1,
            'property' => self::ID,
            'entries' => [['kind' => 'seed', 'seed' => 5, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH]],
        ], JSON_THROW_ON_ERROR));

        Assert::same($this->storage()->recall(self::ID, ['x']), []);
    }

    public function ignoresCorruptContent(): void
    {
        file_put_contents($this->file(), 'not json at all');

        Assert::same($this->storage()->recall(self::ID, ['x']), []);
    }

    /**
     * Ignoring a corrupt file must be silent: the JsonException path returns
     * early instead of falling through to code that would touch an undefined
     * document variable and emit a warning.
     */
    public function ignoresCorruptContentWithoutDiagnostics(): void
    {
        file_put_contents($this->file(), 'not json at all');
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            Assert::same($this->storage()->recall(self::ID, ['x']), []);
        } finally {
            restore_error_handler();
        }
    }

    public function ignoresAJsonDocumentThatIsNotACorpus(): void
    {
        file_put_contents($this->file(), '"just a string"');

        Assert::same($this->storage()->recall(self::ID, ['x']), []);
    }

    public function ignoresACorpusWithoutAnEntryList(): void
    {
        file_put_contents($this->file(), json_encode([
            'format' => FilesystemCorpus::FORMAT_VERSION,
            'property' => self::ID,
        ], JSON_THROW_ON_ERROR));

        Assert::same($this->storage()->recall(self::ID, ['x']), []);
    }

    public function skipsUnusableEntriesButKeepsTheRest(): void
    {
        $this->writeRaw([
            'not an entry',
            ['kind' => 'values', 'seed' => 'not an int', 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH, 'args' => ['x' => 1]],
            ['kind' => 'nonsense', 'seed' => 1, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH],
            ['kind' => 'values', 'seed' => 1, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH],
            ['kind' => 'values', 'seed' => 2, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH, 'args' => ['x' => ['#' => 'zz']]],
            ['kind' => 'values', 'seed' => 3, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH, 'args' => ['x' => 51]],
        ]);

        $entries = $this->storage()->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->seed, 3);
    }

    public function createsTheCorpusDirectoryWithFullPermissions(): void
    {
        // The corpus is shared between parallel CI jobs and local runs that may
        // execute as different users, so the directory is created 0o777 (before
        // umask). A zero umask exposes the exact requested mode.
        $nested = $this->dir . '/perm/db';
        $previousUmask = umask(0);

        try {
            (new FilesystemCorpus($nested))->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);
        } finally {
            umask($previousUmask);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows has no POSIX mode bits: umask() is a no-op and
            // fileperms() reports a synthetic 0o777 for every directory, so
            // the mode assertion below would hold vacuously.
            Assert::true(is_dir($nested));

            return;
        }

        Assert::same(fileperms($nested) & 0o7777, 0o777);
    }

    /**
     * A short/failed payload write must stop the operation entirely — falling
     * through to rename() would promote the temp path to the corpus location.
     * With the temp path occupied by a directory the write fails, and only the
     * fallthrough would rename that directory onto the corpus path.
     */
    public function shortWriteDoesNotPromoteTheTempPathToACorpus(): void
    {
        $tmp = $this->dir . '/.' . sha1(self::ID) . '.json.' . getmypid() . '.tmp';
        mkdir($tmp);

        $this->storage()->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);

        Assert::false(is_dir($this->file()));
        Assert::same($this->storage()->recall(self::ID, ['x']), []);
    }

    public function rememberCreatesTheDirectory(): void
    {
        $nested = $this->dir . '/nested/db';
        $storage = new FilesystemCorpus($nested);
        $storage->remember(self::ID, $this->counterExample(['x' => 1], 9), ['x']);

        Assert::true(is_dir($nested));
        Assert::same((new FilesystemCorpus($nested))->recall(self::ID, ['x'])[0]->seed, 9);
    }

    public function trailingSlashesInTheDirectoryDoNotSplitTheRecord(): void
    {
        (new FilesystemCorpus($this->dir . '/'))->remember(self::ID, $this->counterExample(['x' => 1], 9), ['x']);

        Assert::same(count($this->storage()->recall(self::ID, ['x'])), 1);
    }

    public function separatePropertiesGetSeparateRecords(): void
    {
        $storage = $this->storage();
        $storage->remember('A::a', $this->counterExample(['x' => 1], 1), ['x']);
        $storage->remember('B::b', $this->counterExample(['x' => 2], 2), ['x']);

        Assert::same($storage->recall('A::a', ['x'])[0]->arguments, ['x' => 1]);
        Assert::same($storage->recall('B::b', ['x'])[0]->arguments, ['x' => 2]);
    }

    public function fromEnvIsNullWhenUnset(): void
    {
        putenv('PROPERTY_DB');

        Assert::null(FilesystemCorpus::fromEnv());
    }

    public function fromEnvIsNullWhenEmpty(): void
    {
        putenv('PROPERTY_DB=');

        try {
            Assert::null(FilesystemCorpus::fromEnv());
        } finally {
            putenv('PROPERTY_DB');
        }
    }

    public function fromEnvBuildsStorageWhenSet(): void
    {
        putenv('PROPERTY_DB=' . $this->dir);

        try {
            $storage = FilesystemCorpus::fromEnv();

            Assert::instanceOf($storage, FilesystemCorpus::class);
            $storage->remember(self::ID, $this->counterExample(['x' => 3], 3), ['x']);

            Assert::same($storage->recall(self::ID, ['x'])[0]->arguments, ['x' => 3]);
        } finally {
            putenv('PROPERTY_DB');
        }
    }

    /**
     * Atomic write via temp + rename must not leave `.tmp` orphan files behind
     * once `remember()` returns.
     */
    public function writeLeavesNoTempFilesBehind(): void
    {
        $this->storage()->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);

        $leftovers = glob($this->dir . '/.*.tmp') ?: [];

        Assert::same($leftovers, []);
    }

    /**
     * The lock file lives next to the corpus file so each property id gets its
     * own lock. After a write it must exist (it is not removed: it is reused
     * across calls and costs nothing on disk).
     */
    public function lockFileLivesNextToTheCorpus(): void
    {
        $this->storage()->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);

        Assert::true(is_file($this->dir . '/' . sha1(self::ID) . '.json.lock'));
    }

    /**
     * A second `remember()` on the same property reuses the existing lock file
     * rather than recreating it — verified by checking the lock file's inode
     * stays the same across calls. (Regression guard for `fopen(…, 'c')`
     * semantics: it must not truncate or recreate.)
     */
    public function lockFileIsReusedAcrossCalls(): void
    {
        $this->storage()->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);
        $lockPath = $this->dir . '/' . sha1(self::ID) . '.json.lock';
        $inodeBefore = fileinode($lockPath);

        $this->storage()->remember(self::ID, $this->counterExample(['x' => 2], 2), ['x']);
        $inodeAfter = fileinode($lockPath);

        Assert::same($inodeAfter, $inodeBefore);
    }

    /**
     * Two property ids get two separate lock files. The corpus path includes
     * the property id hash, so a mangled path (e.g. mutant dropping the id from
     * the path) would have both properties share a single lock and serialise
     * unrelated writes.
     */
    public function lockFilesAreKeyedByPropertyId(): void
    {
        $this->storage()->remember('A::a', $this->counterExample(['x' => 1], 1), ['x']);
        $this->storage()->remember('B::b', $this->counterExample(['x' => 2], 2), ['x']);

        Assert::true(is_file($this->dir . '/' . sha1('A::a') . '.json.lock'));
        Assert::true(is_file($this->dir . '/' . sha1('B::b') . '.json.lock'));
    }

    /**
     * A write that cannot complete must leave the previous document intact.
     * A partially written temp file renamed over a good corpus would be worse
     * than the torn write the temp file exists to prevent — the rename makes
     * the truncation durable.
     */
    public function failedTempWriteLeavesThePreviousCorpusIntact(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);

        // Occupying the deterministic temp path with a directory makes the
        // payload write fail even when the suite runs as root.
        $tmp = $this->dir . '/.' . sha1(self::ID) . '.json.' . getmypid() . '.tmp';
        mkdir($tmp);

        try {
            $storage->remember(self::ID, $this->counterExample(['x' => 2], 2), ['x']);
        } finally {
            rmdir($tmp);
        }

        $entries = $storage->recall(self::ID, ['x']);

        Assert::same(count($entries), 1);
        Assert::same($entries[0]->arguments, ['x' => 1]);
    }

    /**
     * A failed rename must clean its temp file up: leaking one contradicts the
     * "no temp files behind" contract, and the corpus simply keeps its previous
     * state.
     */
    public function failedRenameCleansUpItsTempFile(): void
    {
        // A non-empty directory at the destination path defeats rename() on
        // every platform.
        $file = $this->file();
        mkdir($file);
        file_put_contents($file . '/occupied', 'x');

        $this->storage()->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);

        Assert::same(glob($this->dir . '/.*.tmp') ?: [], []);
    }

    /**
     * remember()'s read-modify-write must block on the cross-process lock and
     * lose neither commit. The parent holds a shared lock — an exclusive
     * acquire blocks against it, while a writer mutated down to LOCK_SH (or to
     * no lock at all) sails through and fails the running-state assertion.
     */
    public function rememberBlocksOnTheCrossProcessLockAndLosesNothing(): void
    {
        $storage = $this->storage();
        $storage->remember(self::ID, $this->counterExample(['x' => 1], 1), ['x']);

        $lock = fopen($this->file() . '.lock', 'c');
        Assert::true(\is_resource($lock));
        Assert::true(flock($lock, LOCK_SH));

        $ready = $this->dir . '/ready';
        $worker = $this->dir . '/worker.php';
        file_put_contents($worker, $this->workerScript());

        $pipes = [];
        $process = proc_open([PHP_BINARY, $worker, $this->dir, self::ID, $ready], [], $pipes);
        Assert::true(\is_resource($process));

        try {
            // The worker signals right before calling remember(); once it has,
            // the only thing between it and completion is the lock.
            $deadline = microtime(as_float: true) + 10.0;

            while (!is_file($ready) && microtime(as_float: true) < $deadline) {
                usleep(10_000);
            }

            Assert::true(is_file($ready));

            usleep(300_000);
            Assert::true(proc_get_status($process)['running']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        Assert::same(proc_close($process), 0);

        Assert::same(count($this->storage()->recall(self::ID, ['x'])), 2);
    }

    private function workerScript(): string
    {
        $autoload = var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', return: true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require {$autoload};

            [, \$dir, \$id, \$ready] = \$argv;

            touch(\$ready);

            (new \\Rasuvaeff\\PropertyTesting\\Runner\\FilesystemCorpus(\$dir))->remember(
                \$id,
                new \\Rasuvaeff\\PropertyTesting\\CounterExample(
                    seed: 2,
                    runsBeforeFailure: 0,
                    originalArguments: ['x' => 2],
                    shrunkArguments: ['x' => 2],
                ),
                ['x'],
            );
            PHP;
    }

    #[BeforeTest]
    public function setUpDirectory(): void
    {
        $this->dir = sys_get_temp_dir() . '/prop-corpus-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, recursive: true);
    }

    #[AfterTest]
    public function tearDownDirectory(): void
    {
        $this->removeRecursively($this->dir);
    }

    private function storage(): FilesystemCorpus
    {
        return new FilesystemCorpus($this->dir);
    }

    private function file(): string
    {
        return $this->dir . '/' . sha1(self::ID) . '.json';
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

    /**
     * @param list<mixed> $entries
     */
    private function writeRaw(array $entries): void
    {
        file_put_contents($this->file(), json_encode([
            'format' => FilesystemCorpus::FORMAT_VERSION,
            'property' => self::ID,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));
    }

    private function removeRecursively(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $child) {
            is_dir($child) ? $this->removeRecursively($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
