<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Internal\ValueCodec;

/**
 * Opt-in on-disk corpus of a property's past failures, replayed before the random
 * phase so a fixed bug stays fixed (fast regression replay).
 *
 * A failure is recorded as its minimised input whenever {@see ValueCodec} can
 * represent every argument as data — such an entry replays as a single run and
 * survives changes to the generation sequence. Inputs that cannot be represented
 * (objects, closures, in-body `Gen::draw()` pseudo-arguments) fall back to storing
 * the run's seed, which reproduces the failure only while the generation sequence
 * is unchanged; {@see SEQUENCE_EPOCH} fences those entries off when it is not.
 *
 * Enabled solely when the `PROPERTY_DB` environment variable points at a
 * directory; otherwise storage is off and nothing is written. One file per
 * property (`<sha1(id)>.json`) keeps it gitignore-friendly.
 *
 * @api
 */
final readonly class FilesystemCorpus implements Corpus
{
    /**
     * On-disk layout version. A file written by a different version is ignored
     * wholesale rather than guessed at.
     */
    public const int FORMAT_VERSION = 1;

    /**
     * Generation-sequence epoch. Seed entries reproduce a failure only while the
     * seed→values mapping holds, so bump this in any release that shifts the
     * generated sequence (new boundary bias, changed draw order, a rewritten
     * arbitrary) — older seed entries are then dropped instead of replaying a
     * different input under the guise of a regression. Values entries carry the
     * input itself and are unaffected.
     */
    public const int SEQUENCE_EPOCH = 1;

    /**
     * A values entry costs one run; a seed entry costs a whole random phase
     * (`runs` checks), so the two are capped separately. Oldest entries are
     * evicted first.
     */
    private const int MAX_VALUE_ENTRIES = 8;

    private const int MAX_SEED_ENTRIES = 2;

    public function __construct(
        private string $directory,
    ) {}

    /**
     * The storage configured by `PROPERTY_DB` (a directory path), or null when
     * the variable is unset/empty (storage disabled — no files are written).
     */
    public static function fromEnv(): ?self
    {
        $directory = getenv('PROPERTY_DB');

        if ($directory === false || $directory === '') {
            return null;
        }

        return new self($directory);
    }

    /**
     * The usable entries recorded for $id, cheapest first (values before seeds,
     * most recently recorded first within each kind).
     *
     * Unusable entries are silently skipped: a corrupt file, a foreign format
     * version, a value the codec can no longer decode (renamed enum), a seed from
     * a superseded {@see SEQUENCE_EPOCH}, or a values entry whose argument names
     * no longer match $parameterNames (the property's signature changed, so
     * replaying it would feed the body a different input).
     *
     * @param list<string> $parameterNames The property method's current parameters, in order.
     * @return list<CorpusEntry>
     */
    #[\Override]
    public function recall(string $id, array $parameterNames): array
    {
        $values = [];
        $seeds = [];

        foreach ($this->read($id) as $raw) {
            $entry = $this->hydrate($raw, $parameterNames);

            if (!$entry instanceof CorpusEntry) {
                continue;
            }

            if ($entry->isValues()) {
                $values[] = $entry;
            } else {
                $seeds[] = $entry;
            }
        }

        return [...$values, ...$seeds];
    }

    /**
     * Records $counterExample as the newest entry for $id, preferring its
     * minimised arguments over the bare seed.
     *
     * @param list<string> $parameterNames The property method's current parameters, in order.
     */
    #[\Override]
    public function remember(string $id, CounterExample $counterExample, array $parameterNames): void
    {
        $this->withLock(
            $id,
            function () use ($id, $counterExample, $parameterNames): void {
                $entry = $this->encodeEntry($counterExample, $parameterNames);
                $key = $this->keyOf($entry);

                // Newest first, without the previous copy of the same input.
                $kept = array_filter(
                    $this->read($id),
                    fn(array $raw): bool => $this->keyOf($raw) !== $key,
                );

                $this->write($id, $this->cap([$entry, ...$kept]));
            },
        );
    }

    /**
     * Drops $entry from $id's corpus — the replay no longer fails, so the
     * regression is fixed and the entry has served its purpose.
     */
    #[\Override]
    public function prune(string $id, CorpusEntry $entry): void
    {
        $this->withLock(
            $id,
            function () use ($id, $entry): void {
                // A hydrated entry re-encodes to the very bytes it was read from,
                // so the key identifies the same stored entry.
                $encoded = $entry->isValues()
                    ? $this->valuesEntry($entry->arguments, array_keys($entry->arguments), $entry->seed)
                    : $this->seedEntry($entry->seed);
                $key = $encoded === null ? null : $this->keyOf($encoded);

                $kept = array_values(array_filter(
                    $this->read($id),
                    fn(array $raw): bool => $this->keyOf($raw) !== $key,
                ));

                $this->write($id, $kept);
            },
        );
    }

    /**
     * The stored entries for $id as raw (still encoded) arrays, or an empty list
     * when there is no readable corpus.
     *
     * @return list<array<string, mixed>>
     */
    private function read(string $id): array
    {
        $file = $this->path($id);

        if (!is_file($file)) {
            return [];
        }

        $content = file_get_contents($file);

        if ($content === false) {
            return [];
        }

        try {
            /** @var mixed $document */
            $document = json_decode($content, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($document) || ($document['format'] ?? null) !== self::FORMAT_VERSION || !is_array($document['entries'] ?? null)) {
            return [];
        }

        $entries = [];

        /** @var mixed $raw */
        foreach ($document['entries'] as $raw) {
            if (is_array($raw)) {
                /** @var array<string, mixed> $raw */
                $entries[] = $raw;
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function write(string $id, array $entries): void
    {
        $file = $this->path($id);

        if ($entries === []) {
            if (is_file($file)) {
                // @ suppresses the benign "no such file" warning when a
                // concurrent prune already removed it.
                @unlink($file);
            }

            return;
        }

        $payload = json_encode(
            ['format' => self::FORMAT_VERSION, 'property' => $id, 'entries' => $entries],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        // Temp file + atomic rename: a reader sees either the previous or the
        // new document, never a partial one; a process killed mid-write (OOM,
        // signal, full disk) leaves the previous file intact rather than a
        // truncated JSON document that read() would silently drop.
        $pid = getmypid();

        if ($pid === false) {
            throw new \RuntimeException('Could not determine the current process id');
        }

        $tmp = dirname($file) . '/.' . basename($file) . '.' . $pid . '.tmp';

        // The length check keeps a full disk from shrinking the corpus: a
        // partially written temp file renamed over the previous document would
        // be the very torn write the temp file exists to prevent — only worse,
        // because the rename makes it durable. On any short or failed write the
        // previous document stays untouched.
        if (@file_put_contents($tmp, $payload) !== \strlen($payload)) {
            @unlink($tmp);

            return;
        }

        if (!@rename($tmp, $file)) {
            // A failed rename (e.g. Windows, when a concurrent reader holds the
            // destination open) must not leave the temp file behind; the corpus
            // simply keeps its previous state.
            @unlink($tmp);
        }
    }

    /**
     * Runs $action under an exclusive cross-process lock keyed by $id, so the
     * read-modify-write in {@see remember()} and {@see prune()} stays consistent
     * under `infection --threads=max` and parallel CI jobs sharing a corpus
     * directory. The runner is sequential within a single PHP process, so the
     * lock only mediates inter-process access.
     */
    private function withLock(string $id, \Closure $action): void
    {
        if (!is_dir($this->directory)) {
            // @ suppresses the benign "file exists" warning when a concurrent
            // writer already created the directory.
            @mkdir($this->directory, 0o777, recursive: true);
        }

        $lock = fopen($this->path($id) . '.lock', 'c');

        if ($lock === false) {
            throw new \RuntimeException(sprintf('Could not open lock file for property "%s"', $id));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Could not lock the corpus for property "%s"', $id));
            }

            $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Turns a raw stored entry back into a usable {@see CorpusEntry}, or null when
     * it is corrupt or no longer applicable.
     *
     * @param array<string, mixed> $raw
     * @param list<string> $parameterNames
     */
    private function hydrate(array $raw, array $parameterNames): ?CorpusEntry
    {
        $seed = $raw['seed'] ?? null;

        if (!is_int($seed)) {
            return null;
        }

        if (($raw['kind'] ?? null) === 'seed') {
            return ($raw['epoch'] ?? null) === self::SEQUENCE_EPOCH ? CorpusEntry::seed($seed) : null;
        }

        $encoded = $raw['args'] ?? null;

        if (($raw['kind'] ?? null) !== 'values' || !is_array($encoded)) {
            return null;
        }

        // The signature must still be the one that produced the entry: a renamed,
        // reordered or added parameter makes the stored input a different input.
        $names = array_keys($encoded);
        sort($names);
        $expected = $parameterNames;
        sort($expected);

        if ($names !== $expected) {
            return null;
        }

        $decoded = [];

        foreach ($parameterNames as $name) {
            $value = ValueCodec::decode($encoded[$name]);

            if ($value === null) {
                return null;
            }

            $decoded[] = $value;
        }

        return CorpusEntry::values(
            array_combine($parameterNames, array_map(static fn(array $value): mixed => $value[0], $decoded)),
            $seed,
        );
    }

    /**
     * Encodes a failure for storage: a values entry when every minimised argument
     * is a parameter the codec can represent, a seed entry otherwise.
     *
     * `draw#N` pseudo-arguments from in-body `Gen::draw()` are exactly what makes
     * a counterexample unrepresentable — they are not parameters, and replaying
     * the named arguments alone would let the body draw fresh values.
     *
     * @param list<string> $parameterNames
     * @return array<string, mixed>
     */
    private function encodeEntry(CounterExample $counterExample, array $parameterNames): array
    {
        return $this->valuesEntry($counterExample->shrunkArguments, $parameterNames, $counterExample->seed)
            ?? $this->seedEntry($counterExample->seed);
    }

    /**
     * The values entry for $arguments, or null when they hold anything but exactly
     * the named parameters in codec-representable form.
     *
     * @param array<string, mixed> $arguments
     * @param list<string> $parameterNames
     * @return ?array<string, mixed>
     */
    private function valuesEntry(array $arguments, array $parameterNames, int $seed): ?array
    {
        if (count($arguments) !== count($parameterNames)) {
            return null;
        }

        $encoded = [];

        foreach ($parameterNames as $name) {
            if (!array_key_exists($name, $arguments)) {
                return null;
            }

            $value = ValueCodec::encode($arguments[$name]);

            if ($value === null) {
                return null;
            }

            $encoded[] = $value;
        }

        return [
            'kind' => 'values',
            'seed' => $seed,
            'epoch' => self::SEQUENCE_EPOCH,
            'args' => array_combine($parameterNames, array_map(static fn(array $value): mixed => $value[0], $encoded)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedEntry(int $seed): array
    {
        return ['kind' => 'seed', 'seed' => $seed, 'epoch' => self::SEQUENCE_EPOCH];
    }

    /**
     * Identity of an entry for dedup and pruning: a values entry is its arguments
     * (the same minimal input recorded twice is one regression), a seed entry is
     * its seed.
     *
     * @param array<string, mixed> $raw
     */
    private function keyOf(array $raw): string
    {
        try {
            return ($raw['kind'] ?? null) === 'values'
                ? 'v:' . json_encode($raw['args'] ?? null, JSON_THROW_ON_ERROR)
                : 's:' . json_encode($raw['seed'] ?? null, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'x:' . serialize($raw['kind'] ?? null);
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function cap(array $entries): array
    {
        $values = 0;
        $seeds = 0;
        $kept = [];

        foreach ($entries as $entry) {
            if (($entry['kind'] ?? null) === 'values') {
                if (++$values > self::MAX_VALUE_ENTRIES) {
                    continue;
                }
            } elseif (++$seeds > self::MAX_SEED_ENTRIES) {
                continue;
            }

            $kept[] = $entry;
        }

        return $kept;
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, '/') . '/' . sha1($id) . '.json';
    }
}
