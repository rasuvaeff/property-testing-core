<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Runner\CorpusEntry;

/**
 * The on-disk shape of a corpus, without the disk.
 *
 * Everything a {@see \Rasuvaeff\PropertyTesting\Runner\Corpus} does that is not
 * storage lives here: parsing a document, rendering one, turning a stored entry
 * back into a {@see CorpusEntry} (or refusing to), encoding a counterexample,
 * identifying an entry for dedup, and capping how many are kept. A backend is
 * then only the part that is actually different — where the bytes go and how a
 * read-modify-write is made safe.
 *
 * The point of the split is not tidiness: it is that two backends store the
 * *same document*, so a corpus written to a directory and a corpus written to
 * Redis are the same artifact, and moving between them is a copy. The format
 * version and sequence epoch stay public constants on
 * {@see \Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus} — they are the
 * published contract, and they are passed in here rather than duplicated.
 *
 * @internal
 */
final class CorpusDocument
{
    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * The entries of a stored document, or an empty list when it is unreadable
     * — corrupt JSON, a foreign format version, or a shape that is not a
     * document. An unusable corpus is an empty corpus, never an error: it must
     * not fail a test run that would otherwise pass.
     *
     * @param string $content The stored document.
     * @param int $format The format version this reader accepts.
     *
     * @return list<array<string, mixed>>
     */
    public static function decode(string $content, int $format): array
    {
        try {
            /** @var mixed $document */
            $document = json_decode($content, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($document) || ($document['format'] ?? null) !== $format || !is_array($document['entries'] ?? null)) {
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
     * The document to store for a property.
     *
     * @param string $id The property id, stored for a human reading the file.
     * @param list<array<string, mixed>> $entries The entries to keep, newest first.
     * @param int $format The format version to stamp.
     */
    public static function encode(string $id, array $entries, int $format): string
    {
        return json_encode(
            ['format' => $format, 'property' => $id, 'entries' => $entries],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * A raw stored entry as a usable {@see CorpusEntry}, or null when it is
     * corrupt or no longer applicable.
     *
     * @param array<string, mixed> $raw The stored entry.
     * @param list<string> $parameterNames The property's current parameters, in order.
     * @param int $epoch The sequence epoch a seed entry must carry to be replayable.
     */
    public static function hydrate(array $raw, array $parameterNames, int $epoch): ?CorpusEntry
    {
        $seed = $raw['seed'] ?? null;

        if (!is_int($seed)) {
            return null;
        }

        if (($raw['kind'] ?? null) === 'seed') {
            return ($raw['epoch'] ?? null) === $epoch ? CorpusEntry::seed($seed) : null;
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
     * A failure encoded for storage: a values entry when every minimised argument
     * is a parameter the codec can represent, a seed entry otherwise.
     *
     * `draw#N` pseudo-arguments from in-body `Gen::draw()` are exactly what makes
     * a counterexample unrepresentable — they are not parameters, and replaying
     * the named arguments alone would let the body draw fresh values.
     *
     * @param CounterExample $counterExample The failure to record.
     * @param list<string> $parameterNames The property's current parameters, in order.
     * @param int $epoch The sequence epoch to stamp.
     *
     * @return array<string, mixed>
     */
    public static function encodeEntry(CounterExample $counterExample, array $parameterNames, int $epoch): array
    {
        return self::valuesEntry($counterExample->shrunkArguments, $parameterNames, $counterExample->seed, $epoch)
            ?? self::seedEntry($counterExample->seed, $epoch);
    }

    /**
     * The values entry for $arguments, or null when they hold anything but exactly
     * the named parameters in codec-representable form.
     *
     * @param array<string, mixed> $arguments The minimised arguments.
     * @param list<string> $parameterNames The property's current parameters, in order.
     * @param int $seed The seed the failure was found with.
     * @param int $epoch The sequence epoch to stamp.
     *
     * @return ?array<string, mixed>
     */
    public static function valuesEntry(array $arguments, array $parameterNames, int $seed, int $epoch): ?array
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
            'epoch' => $epoch,
            'args' => array_combine($parameterNames, array_map(static fn(array $value): mixed => $value[0], $encoded)),
        ];
    }

    /**
     * @param int $seed The seed to replay the whole random phase with.
     * @param int $epoch The sequence epoch to stamp.
     *
     * @return array<string, mixed>
     */
    public static function seedEntry(int $seed, int $epoch): array
    {
        return ['kind' => 'seed', 'seed' => $seed, 'epoch' => $epoch];
    }

    /**
     * Identity of an entry for dedup and pruning: a values entry is its arguments
     * (the same minimal input recorded twice is one regression), a seed entry is
     * its seed.
     *
     * @param array<string, mixed> $raw The stored entry.
     */
    public static function keyOf(array $raw): string
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
     * The entries to keep, oldest evicted first within each kind.
     *
     * @param list<array<string, mixed>> $entries Candidate entries, newest first.
     * @param int $maxValues How many values entries a property keeps.
     * @param int $maxSeeds How many seed entries a property keeps.
     *
     * @return list<array<string, mixed>>
     */
    public static function cap(array $entries, int $maxValues, int $maxSeeds): array
    {
        $values = 0;
        $seeds = 0;
        $kept = [];

        foreach ($entries as $entry) {
            if (($entry['kind'] ?? null) === 'values') {
                if (++$values > $maxValues) {
                    continue;
                }
            } elseif (++$seeds > $maxSeeds) {
                continue;
            }

            $kept[] = $entry;
        }

        return $kept;
    }
}
