<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

use Rasuvaeff\PropertyTesting\Runner\TargetDirection;

/**
 * The on-disk shape of a {@see \Rasuvaeff\PropertyTesting\Runner\SearchCorpus}
 * document: one JSON object per property with the best inputs of every
 * target label, arguments encoded through {@see ValueCodec} like a values
 * entry of the regression corpus. Shared by the filesystem and the Redis
 * backend, so a document written through one reads through the other.
 *
 * @psalm-import-type Targets from \Rasuvaeff\PropertyTesting\Runner\SearchCorpus
 *
 * @internal
 */
final class SearchDocument
{
    public const int FORMAT_VERSION = 1;

    private function __construct() {}

    /**
     * @param Targets $targets
     * @param list<string> $parameterNames
     */
    public static function encode(string $id, array $targets, array $parameterNames): ?string
    {
        $stored = [];

        foreach ($targets as $label => $target) {
            $entries = [];

            foreach ($target['entries'] as $entry) {
                $encoded = self::encodeArguments($entry['arguments'], $parameterNames);

                if ($encoded === null) {
                    continue;
                }

                $entries[] = ['score' => $entry['score'], 'args' => $encoded];
            }

            if ($entries === []) {
                continue;
            }

            $stored[$label] = ['direction' => $target['direction']->value, 'entries' => $entries];
        }

        if ($stored === []) {
            return null;
        }

        return json_encode(
            ['id' => $id, 'format' => self::FORMAT_VERSION, 'targets' => $stored],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ) . "\n";
    }

    /**
     * @param list<string> $parameterNames
     *
     * @return Targets
     */
    public static function decode(string $content, array $parameterNames): array
    {
        try {
            $document = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($document) || ($document['format'] ?? null) !== self::FORMAT_VERSION || !is_array($document['targets'] ?? null)) {
            return [];
        }

        $targets = [];

        /** @var mixed $target */
        foreach ($document['targets'] as $label => $target) {
            if (!is_string($label) || !is_array($target) || !is_array($target['entries'] ?? null)) {
                continue;
            }

            /** @var mixed $stored */
            $stored = $target['direction'] ?? null;
            $direction = is_string($stored) ? TargetDirection::tryFrom($stored) : null;

            if (!$direction instanceof TargetDirection) {
                continue;
            }

            $entries = [];

            /** @var mixed $entry */
            foreach ($target['entries'] as $entry) {
                if (!is_array($entry) || !is_numeric($entry['score'] ?? null)) {
                    continue;
                }

                /** @var mixed $args */
                $args = $entry['args'] ?? null;

                if (!is_array($args)) {
                    continue;
                }

                $arguments = self::decodeArguments($args, $parameterNames);

                if ($arguments === null) {
                    continue;
                }

                $entries[] = ['score' => (float) $entry['score'], 'arguments' => $arguments];
            }

            if ($entries !== []) {
                $targets[$label] = ['direction' => $direction, 'entries' => $entries];
            }
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $parameterNames
     *
     * @return ?array<string, mixed>
     */
    private static function encodeArguments(array $arguments, array $parameterNames): ?array
    {
        if (count($arguments) !== count($parameterNames)) {
            return null;
        }

        /** @var list<array{0: mixed}> $encoded */
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

        return array_combine($parameterNames, array_map(static fn(array $value): mixed => $value[0], $encoded));
    }

    /**
     * @param array<array-key, mixed> $encoded
     * @param list<string> $parameterNames
     *
     * @return ?array<string, mixed>
     */
    private static function decodeArguments(array $encoded, array $parameterNames): ?array
    {
        $names = array_keys($encoded);
        sort($names);
        $expected = $parameterNames;
        sort($expected);

        if ($names !== $expected) {
            return null;
        }

        /** @var list<array{0: mixed}> $decoded */
        $decoded = [];

        foreach ($parameterNames as $name) {
            $value = ValueCodec::decode($encoded[$name]);

            if ($value === null) {
                return null;
            }

            $decoded[] = $value;
        }

        return array_combine($parameterNames, array_map(static fn(array $value): mixed => $value[0], $decoded));
    }
}
