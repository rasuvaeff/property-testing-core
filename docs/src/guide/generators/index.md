---
title: "Generators"
description: "The Gen facade: built-in arbitraries (int, string, array, oneOf, flatMap, regex...) and why generators live in their own <method>Generators() method."
---

# Generators

## Why generators are in a separate method

PHP attribute arguments must be constant expressions, so `#[Given('x', Gen::int())]`
is not expressible. Instead name a method that returns
`array<string, ArbitraryInterface>` keyed by parameter name. When the `generators`
argument is omitted the runner falls back to a method named `<testMethod>Generators`.

Since `property-testing-testo` 0.5 (and `-phpunit`'s fluent API from the start),
`generators` and `examples` also accept a callable, which is how a provider gets
reused between test classes: `[Provider::class, 'method']`, `'Provider::method'`,
or an invokable object (`new Provider()`) — all valid attribute expressions on
PHP 8.3. PHP 8.5 additionally allows an inline `static function (): array { ... }`
and a first-class callable (`Provider::method(...)`). A string still resolves to
a method on the test class first, so a local method named like a global function
(`range`) keeps winning; the convention stays the default.

Declare generators (and examples) methods `public static` — or `public` if the
body needs `$this`. Their only call site is this package's reflection, so
static analysis sees them as unused: Rector's dead-code set deletes private
ones (`RemoveUnusedPrivateMethodRector`). Public methods are safe, and Testo
never treats a non-void-returning method as a test.

## Generators

| Factory | Produces | Shrinks |
|---|---|---|
| `Gen::int()` | `IntArbitrary`, `PHP_INT_MIN..PHP_INT_MAX` | toward `0` |
| `Gen::intBetween($min, $max)` | `IntArbitrary`, `[$min, $max]` | toward `0`, clamped to range |
| `Gen::intPositive()` | `IntArbitrary`, `1..PHP_INT_MAX` | toward `1` |
| `Gen::float()` | `FloatArbitrary`, `[0.0, 1.0)` | toward `0.0` |
| `Gen::floatBetween($min, $max)` | `FloatArbitrary`, `[$min, $max]` | toward `0.0`, clamped to range |
| `Gen::bool()` | `BoolArbitrary`, `true` / `false` | `true` -> `false` |
| `Gen::string()` | `StringArbitrary`, Unicode, length 0..100 | toward `''`, then by length, then each character toward `a` |
| `Gen::stringAscii()` | `StringArbitrary`, printable ASCII, length 0..100 | toward `''`, then by length, then each character toward `a` |
| `Gen::stringOf($min, $max)` | `StringArbitrary`, Unicode, bounded length | toward `''`, then by length, then each character toward `a` |
| `Gen::stringFrom($alphabet, $min, $max)` | `CharsetStringArbitrary`, characters from a fixed alphabet (multibyte OK) | toward `''`, then by length, then each character toward the first alphabet character |
| `Gen::bytes($min, $max)` | `BytesArbitrary`, raw byte strings (bytes 0..255) | toward `''`, then by length, then each byte toward `"\x00"` |
| `Gen::arrayOf($element, $min, $max)` | `ArrayArbitrary`, lists of `$element`, size 0..100 by default | toward `[]`, then by length, then each element |
| `Gen::nonEmptyArrayOf($element, $max)` | `ArrayArbitrary`, non-empty lists | by length (never below 1), then each element |
| `Gen::uniqueArrayOf($element, $min, $max)` | `UniqueArrayArbitrary`, lists of pairwise-distinct elements | like `arrayOf`, but element candidates colliding with another element are skipped |
| `Gen::dictOf($key, $value, $min, $max)` | `DictionaryArbitrary`, maps with distinct keys from `$key` (int/string) and values from `$value`, size 0..100 by default | toward `[]`, then by size, then each value (keys fixed) |
| `Gen::record($shape)` | `RecordArbitrary`, fixed-shape map `['field' => $arb, ...]` | each field via its arbitrary, key set fixed |
| `Gen::elements($array)` | `OneOfArbitrary`, one value from an array (array form of `oneOf`) | toward earlier-listed distinct values |
| `Gen::enum(SomeEnum::class)` | `OneOfArbitrary` over the enum's cases | toward earlier-declared cases (declare simpler cases first) |
| `Gen::constant($value)` | `ConstantArbitrary`, always `$value` | does not shrink |
| `Gen::char()` | `StringArbitrary`, a single printable ASCII character | toward `a` |
| `Gen::uuid()` | `UuidArbitrary`, RFC 4122 v4 UUID strings | does not shrink |
| `Gen::datetime($min, $max)` | `DateTimeArbitrary`, UTC `DateTimeImmutable`, timestamp in `[$min, $max]` | toward the Unix epoch, clamped |
| `Gen::floatSpecial()` | `OneOfArbitrary` over `NAN`, `±INF`, `-0.0` and the float representation edges | toward earlier-listed specials |
| `Gen::intRange($min, $max)` | `FlatMappedArbitrary`, ordered pairs `[lo, hi]` with `lo <= hi` | both bounds shrink, order always holds |
| `Gen::recursive($leaf, $wrap, $maxDepth)` | bounded recursive structures: `$wrap` lifts the previous level's arbitrary | within the branch that generated the value |
| `Gen::oneOf(...$values)` | `OneOfArbitrary`, one of the given values | toward earlier-listed distinct values (put simpler values first) |
| `Gen::nullable($inner)` | `NullableArbitrary`, `null` or an `$inner` value | prefers `null`, then the inner tree |
| `Gen::map($inner, $fn)` | `MappedArbitrary`, `$inner` transformed by `$fn` | through the inner tree, re-applying `$fn` |
| `Gen::flatMap($inner, $fn)` | `FlatMappedArbitrary`, dependent generator returned by `$fn($innerValue)` | source value first (dependent value regenerated), then the dependent tree |
| `Gen::filter($inner, $predicate)` | `FilteredArbitrary`, `$inner` values satisfying `$predicate` (throws `GenerationExhausted` after 100 rejected draws — never yields an out-of-domain value) | inner tree, pruning candidates that fail the predicate |
| `Gen::tuple(...$elements)` | `TupleArbitrary`, fixed-arity tuple, one value per element | each position via its element, arity fixed |
| `Gen::frequency($pairs)` | `FrequencyArbitrary`, weighted choice over `[weight, arbitrary]` pairs | within the branch that generated the value |
| `Gen::ipv4()` | IPv4 dotted-quad strings | each octet toward `0` |
| `Gen::ipv6()` | IPv6 addresses in the canonical RFC 5952 text form (lowercase, no leading zeros, longest zero run compressed to `::`) | each group toward `0`, ending at `::` |
| `Gen::email()` | `local@label.tld` addresses | toward the shortest local/label and first TLD |
| `Gen::url()` | `http(s)://host.tld[/path]` URLs | toward `http://a.com` |
| `Gen::json($maxDepth)` | a JSON-encodable value (null/bool/int/float/string/list/object) | within the generated structure |
| `Gen::jsonString($maxDepth)` | the `json_encode` text of `Gen::json()` | through the value's tree |
| `Gen::regex($pattern)` / `Gen::stringMatching($pattern)` | strings matching a regex subset (compiled to combinators) | shorter/simpler matches (via the compiled trees) |
| `Gen::subset($values, $min, $max)` | `SubsetArbitrary`, subsets of a fixed ordered set — distinct members of `$values` in source order; duplicates in the source are rejected | size first (toward the empty set), then each kept element toward earlier source positions — the minimal subset is a short prefix |
| `Gen::commands($initialModel, $commandGenerators, $min, $max)` | `CommandSequenceArbitrary`, valid command sequences for stateful testing — see [State machine](/guide/state-machine/concepts) | drops command blocks, then simplifies each command |
| `Gen::swarm($choiceGenerator)` | `SwarmArbitrary`, swarm testing: each case may use only a non-empty subset of the wrapped choice generator's variants — see [Swarm testing](/guide/generators/swarm) | inside the subset the case came from, never widening back |

Numeric generators (`int*`, `float*`) are **boundary-biased**: roughly one draw in
five returns an in-range edge value (`0`, `±1`, `min`, `max` for ints; `0.0` or
`min` for floats), where bugs cluster, instead of a uniform one. Shrinking is
unaffected. See [Boundary bias](/guide/generators/boundary-bias).

Sized generators guarantee their **minimum**: `uniqueArrayOf`/`dictOf` (distinct
elements/keys) and `commands` (applicable steps) may fall short of the *drawn*
size when the value space runs out, but never fall below `$min` — an unreachable
minimum throws `GenerationExhausted` rather than hand the property a too-small
value.

All of the above live on `Gen` in `rasuvaeff/property-testing-core` — the
same facade regardless of which adapter's `#[Property]`/`forAll()` calls it.
