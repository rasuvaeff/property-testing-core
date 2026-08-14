---
title: Regex accept/reject anchoring
description: "Would have caught: `^...$` accepts \"abc\\n\" — an identifier validator that silently allows a trailing newline (ER-001)."
---

# Regex accept/reject anchoring

::: tip Would have caught, not "caught here"
See [Cookbook](/cookbook/) for what that distinction means and how it was
verified.
:::

## The bug

`docs/evolved-rules.md` ER-001 in this monorepo: an identifier validator
whitelisted names with

```php
preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)
```

PCRE's `$` matches at the end of the subject **or immediately before a
trailing `"\n"`** — it is not the same anchor as "end of string". So
`"orders\n"` is accepted as a valid identifier, even though the intent was
to reject anything but `[a-z_][a-z0-9_]*` exactly. The fix is a one-character
anchor swap: `\z`, which matches only the true end of the string.

## Why the unit test stayed green

A conventional test suite for this validator asserts on hand-picked strings:

```php
Assert::true(acceptsIdentifier('user_id'));
Assert::false(acceptsIdentifier('user id'));   // space
Assert::false(acceptsIdentifier('1user'));     // leading digit
Assert::false(acceptsIdentifier(''));          // empty
```

None of these authors thought to type a literal newline into a test string —
`$identifier = "user_id\n"` looks like a copy-paste artifact, not a test
case worth writing by hand. The bug lives exactly in the part of the input
space nobody enumerates manually.

## The property

Generate strings from an alphabet that **includes `"\n"`** — constructing the
interesting input directly instead of hoping a general-purpose string
generator draws it by chance (root `AGENTS.md`, "конструировать, не
фильтровать") — and assert the `$`-anchored and `\z`-anchored versions of the
same pattern agree on every one of them:

```php
function acceptsBuggy(string $identifier): bool
{
    return (bool) preg_match('/^[a-z_][a-z0-9_]*$/', $identifier);
}

function acceptsFixed(string $identifier): bool
{
    return (bool) preg_match('/^[a-z_][a-z0-9_]*\z/', $identifier);
}

$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789_' . "\n";

$agreement = static function (string $identifier): void {
    if (acceptsBuggy($identifier) !== acceptsFixed($identifier)) {
        throw new RuntimeException('anchors disagree on ' . var_export($identifier, true));
    }
};
```

Full runnable script:
[`examples/case-studies/regex-anchor.php`](https://github.com/rasuvaeff/property-testing-core/blob/master/examples/case-studies/regex-anchor.php).

## Runner output

<!-- case-study-output: regex-anchor -->
```
Buggy $-anchored validator falsified:

Property falsified after 40 successful run(s); seed=42
  Original: identifier="quxz5\n"
  Shrunk:   identifier="aaaaa\n" (5 shrink step(s), 18 trial(s))
  Changed:  identifier="quxz5\n" -> "aaaaa\n"
  Failure:  $-anchor and \z-anchor disagree on 'aaaaa
': $ says accept, \z says reject
  Path:     identifier:2/identifier:2/identifier:2/identifier:2/identifier:2
```

Forty passing runs on identifiers without a trailing newline, then the
41st draw includes one — the shrinker strips it down to the shortest
identifier that still reproduces the disagreement, five `a`s plus the
newline.

## The fix

```diff
- preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)
+ preg_match('/^[a-z_][a-z0-9_]*\z/', $identifier)
```

Root `AGENTS.md`'s security table already codifies this: identifier
whitelists are anchored with `\z`, never a bare `$`.
