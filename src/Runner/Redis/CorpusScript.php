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

    /**
     * `sha1(CAS)`, what `EVALSHA` addresses the script by once the server has
     * seen it: the script text then travels once per server, not once per
     * write. A server that does not know it answers `NOSCRIPT`, and the
     * clients fall back to `EVAL` for that call. Computed from the constant
     * rather than written down: a checkout that rewrites line endings would
     * change the script's bytes, and the digest must be of the bytes sent.
     */
    public static function sha(): string
    {
        return sha1(self::CAS);
    }

    private function __construct()
    {
        // Constant holder; not instantiable.
    }
}
