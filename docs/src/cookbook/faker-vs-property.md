---
title: Faker vs property
description: "Realistic data and shrinkable data find the same bug; only one of them can tell you the smallest input that triggers it."
---

# Faker vs property

::: tip Not an incident
The other pages here reconstruct real bugs from this monorepo's history. This
one does not: it is a comparison, and the bug in it is the plainest instance of
the difference — byte-wise truncation of a UTF-8 string. The runner output
below is real, as everywhere in this cookbook.
:::

Most PHP test suites already generate data, with
[Faker](https://fakerphp.org). So the honest question is not "should you
replace it" — you should not — but where each one belongs.

## Where Faker is enough

Fixtures, seeders, demo data, screenshots: anywhere the point is that a record
*looks* plausible. Faker is good at that and a property generator is not; a
column full of `Gen::string()` output is unreadable in a way that helps nobody
review a page of search results.

Nor is the difference reproducibility. Faker seeds
(`$faker->seed(1234)`), so a failing run can be replayed exactly. That is
usually where the comparison stops, and it stops one step early.

## Where it is not

The difference is **minimisation**. Faker offers a value; it does not offer a
*smaller* value, because "simpler" is not a relation its data has. Wrapped as
an arbitrary, the only thing left to shrink is the seed it was given — and a
smaller seed produces an unrelated name, not a simpler one.

The bug: a display name cut to a byte limit, straight through the middle of a
multibyte character.

```php
function truncateDisplayName(string $name, int $limit): string
{
    return substr($name, 0, $limit);   // bytes, not characters
}
```

The property is the same in both runs — whatever comes out is still valid
UTF-8:

```php
$staysValidUtf8 = static function (string $name, int $limit): void {
    if (!mb_check_encoding(truncateDisplayName($name, $limit), 'UTF-8')) {
        throw new RuntimeException(/* … */);
    }
};
```

Only the generator differs. First a stand-in with Faker's shape — a seed in, a
realistic name out — then a string over a two-character alphabet:

```php
// Realistic: the seed is the only thing with an ordering, and it means nothing.
'name' => Gen::map(Gen::intBetween(0, 1_000_000), RealisticNames::name(...)),

// Shrinkable: shorter strings and earlier characters are smaller, by construction.
'name' => Gen::stringFrom('aé', 1, 8),
```

## What each one reports

<!-- case-study-output: faker-vs-property -->
```text
Realistic data (a seed mapped to a name):

Property falsified after 10 successful run(s); seed=3
  Original: name="Agnès Dubois-Lévesque PhD", limit=4
  Shrunk:   name="Agnès Sørensen PhD", limit=4 (30 shrink step(s), 228 trial(s))
  Changed:  name="Agnès Dubois-Lévesque PhD" -> "Agnès Sørensen PhD"
  Failure:  truncateDisplayName("Agnès Sørensen PhD", 4) returned bytes that are not valid UTF-8

Shrinkable data (a string over an alphabet):

Property falsified after 14 successful run(s); seed=3
  Original: name="aaééaé", limit=3
  Shrunk:   name="aaé", limit=3 (1 shrink step(s), 7 trial(s))
  Changed:  name="aaééaé" -> "aaé"
  Failure:  truncateDisplayName("aaé", 3) returned bytes that are not valid UTF-8
```

Both found the bug. Read what they say about it.

The realistic run spent **30 shrink steps and 228 trials** and arrived at
`"Agnès Sørensen PhD"` — a different arbitrary name of eighteen characters. The
descent worked: every step really did still fail. It just had nothing to move
toward, because the ordering it walked was over seeds. Nothing in that line
tells you the bug is about the fourth byte, and the name it names is not the
one that was originally reported.

The shrinkable run spent **one step** and printed `name="aaé", limit=3`: a
three-character name and the exact limit that cuts `é` in half. The report is
the diagnosis.

::: info Why not `name="é", limit=1`
The descent is greedy: it accepts a candidate only if the candidate still
fails. Dropping characters from `"aaé"` gives `"é"`, and `substr("é", 0, 3)` is
the whole character — valid UTF-8, so the candidate passes and is rejected.
`("aaé", 3)` is a local minimum where every single change repairs the failure,
which is exactly what a minimal counterexample is supposed to be.
:::

## What to take from it

| | Faker | Property generator |
|---|---|---|
| Realistic-looking records | yes | no |
| Reproducible run | yes (seeded) | yes (seeded) |
| Smaller failing input | no ordering to walk | shorter, earlier, closer to zero |
| What a failure reports | the value that happened to fail | the boundary that fails |

Keep Faker where the data is read by a person. Generate with an arbitrary
where the data is read by a shrinker — and when a domain shape is genuinely
needed inside a property, build it out of arbitraries so the pieces stay
shrinkable (`Gen::email()` is exactly that: local part, label and TLD
generated separately, then joined), or use a domain generator built on this
engine, such as
[`rasuvaeff/property-testing-names`](https://packagist.org/packages/rasuvaeff/property-testing-names)
for person names.
