<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

/**
 * The compare-and-set both shipped clients run.
 *
 * One script rather than two implementations of the same logic: what the
 * clients differ in is how a script is sent, not what it does.
 *
 * @internal
 */
final class CorpusScript
{
    /**
     * Sets, deletes or refuses, atomically.
     *
     * The empty string is the sentinel for "absent" on both sides — a corpus
     * document is a JSON object and can never be empty, so nothing legitimate
     * collides with it, and the alternative (a second key, or a Lua nil dance
     * across client encodings) buys nothing.
     */
    public const string CAS = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        local expected = ARGV[1]
        local document = ARGV[2]

        if current == false then
            current = ''
        end

        if current ~= expected then
            return 0
        end

        if document == '' then
            redis.call('DEL', KEYS[1])
        else
            redis.call('SET', KEYS[1], document)
        end

        return 1
        LUA;

    private function __construct()
    {
        // Constant holder; not instantiable.
    }
}
