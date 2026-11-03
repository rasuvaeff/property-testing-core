<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Internal;

/**
 * The curated list behind {@see \Rasuvaeff\PropertyTesting\Gen::naughtyString()}:
 * whole string values that break parsers, validators, renderers and storage,
 * in the spirit of big-list-of-naughty-strings and BlackBox's
 * `Set::strings()->unsafe()`.
 *
 * Ordered simplest first, because a failing entry shrinks through the entries
 * listed before it: the reported minimum is the first entry on this list that
 * still fails. Short entries and whole categories that are cheap to reason
 * about come before long or exotic ones.
 *
 * Entries are stored as data, not compared against anything: a value that is
 * not valid UTF-8 is on the list on purpose (`json_encode()`, `preg_*()` with
 * `/u` and `mb_*()` all treat it differently), and the corpus stores it as
 * bytes.
 *
 * @internal
 */
final class NaughtyStrings
{
    /**
     * @return non-empty-list<string>
     */
    public static function all(): array
    {
        return [
            // Empty and whitespace.
            '',
            ' ',
            "\t",
            "\n",
            "\r\n",
            "\u{00A0}",
            "\u{3000}",
            "\u{200B}",
            "\u{FEFF}",

            // Line ends where an anchor does not expect them: PCRE `$` matches
            // before a final "\n", so /^[a-z]+$/ accepts "a\n".
            "a\n",
            "a\r",
            "a\r\n",
            "\na",
            "a\nb",

            // Control bytes.
            "\0",
            "a\0b",
            "\x7F",
            "\x1B[31m",

            // Values that change meaning after a cast or a loose comparison.
            '0',
            '-0',
            '00',
            '0.0',
            '-1',
            ' 1',
            '1 ',
            '0x1A',
            '1e3',
            '1e309',
            '1e-400',
            'NaN',
            'INF',
            '9223372036854775807',
            '9223372036854775808',
            '-9223372036854775809',

            // Words other languages and formats treat as literals.
            'null',
            'NULL',
            'true',
            'false',
            'undefined',
            'None',
            'nil',
            '[]',
            '{}',

            // Quotes, escapes and their literal spellings.
            '"',
            "'",
            '\\',
            '`',
            '""',
            "''",
            '\\n',
            '\\u0000',
            '\\"',

            // Injection shapes: SQL, markup, templates, format strings, shells, headers.
            "' OR '1'='1",
            '1; DROP TABLE users--',
            '<script>alert(1)</script>',
            '"><img src=x onerror=alert(1)>',
            'javascript:alert(1)',
            '<!--',
            ']]>',
            '<?php',
            '{{7*7}}',
            '${7*7}',
            '#{7*7}',
            '%s%s%s%n',
            '%00',
            '$(id)',
            '`id`',
            '; id',
            '| id',
            "\r\nSet-Cookie: x=1",

            // Paths and URLs.
            '/',
            '~',
            '../',
            '../../../../etc/passwd',
            '..\\..\\windows\\win.ini',
            'C:\\',
            'CON',
            'file:///etc/passwd',
            '//example.com',

            // Unicode: normalisation, case mapping, direction, joiners, homoglyphs.
            "\u{00E9}",
            "e\u{0301}",
            "\u{0130}",
            "\u{00DF}",
            "\u{FB01}",
            "\u{2126}",
            "\u{01C5}",
            "\u{0430}dmin",
            "\u{FF53}\u{FF43}\u{FF52}\u{FF49}\u{FF50}\u{FF54}",
            "\u{202E}abc",
            "\u{200F}",
            "\u{200D}",
            "\u{2028}",
            "\u{FFFD}",
            "\u{1F600}",
            "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}",
            "\u{10FFFF}",

            // Not valid UTF-8: a truncated sequence, an encoded surrogate, an
            // overlong "/", a byte that never starts a sequence.
            "\xC3\x28",
            "\xED\xA0\x80",
            "\xC0\xAF",
            "\xFF",

            // Lengths past common limits.
            str_repeat('a', 256),
            str_repeat('a', 1_025),
            str_repeat('a', 65_536),
        ];
    }
}
