---
layout: home
title: property-testing
description: "Property-based testing for PHP 8.3+: generate hundreds of random inputs, find the one that falsifies your property, and shrink it to a minimal counterexample."
hero:
  name: property-testing
  text: Generate hundreds of inputs. Shrink the one that breaks it.
  tagline: One framework-agnostic engine, two framework adapters — Testo and PHPUnit.
  image:
    src: /logo-mark.svg
    alt: property-testing logo
  actions:
    - theme: brand
      text: What is property-testing?
      link: /guide/intro/what-is-property-testing
    - theme: alt
      text: Getting started
      link: /guide/intro/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/rasuvaeff/property-testing-core
features:
  - title: Integrated shrinking
    details: generate() returns value + lazy shrink tree together — transformed generators (map, flatMap) shrink correctly for free.
    link: /guide/shrinking
  - title: Dependent values
    details: Gen::flatMap() and in-body Gen::draw() build valid inputs instead of discarding invalid ones.
    link: /guide/generators/dependent
  - title: Regression corpus
    details: PROPERTY_DB replays every past failure first, so a fixed bug can't silently come back.
    link: /guide/regression-corpus
  - title: Deadlines, not just assertions
    details: timeoutMs and budgetMs turn pathological inputs (catastrophic regex, deep recursion) into reported failures.
    link: /guide/controlling-runs/deadlines
  - title: Stateful / model-based testing
    details: Generate whole sequences of Commands, run them against a model, shrink the sequence to the shortest failing one.
    link: /guide/state-machine/concepts
  - title: Pick your framework
    details: "The same #[Property] surface on Testo, a fluent trait on PHPUnit, or drive the engine directly with no framework at all."
    link: /adapters/testo
---

<div class="vp-doc" style="max-width: 960px; margin: 3rem auto 0; padding: 0 24px;">

## Three packages, one engine

- <img src="/logo-mark.svg" width="20" height="20" alt="" style="display: inline-block; vertical-align: middle; border-radius: 4px; margin-right: 4px;" /> **[`property-testing-core`](https://github.com/rasuvaeff/property-testing-core)** — the framework-agnostic engine: generators, shrinking, the regression corpus, events. Depends on nothing but PHP.
- <img src="/adapters/testo/logo-mark.svg" width="20" height="20" alt="" style="display: inline-block; vertical-align: middle; border-radius: 4px; margin-right: 4px;" /> **[`property-testing-testo`](/adapters/testo)** — the `#[Property]` attribute, self-registering with [Testo](https://php-testo.github.io/)'s interceptor discovery.
- <img src="/adapters/phpunit/logo-mark.svg" width="20" height="20" alt="" style="display: inline-block; vertical-align: middle; border-radius: 4px; margin-right: 4px;" /> **[`property-testing-phpunit`](/adapters/phpunit)** — a fluent `forAll()->check()` trait for PHPUnit test cases.

Install core plus exactly the adapter your test suite already uses; `composer why testo/testo`
stays empty if you never asked for it.

## See it fail, then see it shrink

<div class="terminal-sample">
<pre><code>Property falsified after 246 successful run(s); seed=7382910
  Original: maxAttempts=17, baseSeconds=91, cap=847, attempts=23
  Shrunk:   maxAttempts=1, baseSeconds=848, cap=847, attempts=1 (12 shrink step(s), 41 trial(s))
  Changed:  maxAttempts=17 -&gt; 1, baseSeconds=91 -&gt; 848, attempts=23 -&gt; 1</code></pre>
</div>

Four generated arguments went in; the `Changed:` line tells you only three of
them actually drive the failure — the shrinker found that by searching, you
didn't have to step through a debugger to see it.

See [Cookbook](/cookbook/regex-anchor) for real monorepo incidents property
tests would have caught before they shipped.

</div>

<style>
.terminal-sample {
  background: #0f1c18;
  color: #d7f3e4;
  border-radius: 8px;
  padding: 1rem 1.2rem;
  overflow-x: auto;
  font-size: 0.85rem;
  line-height: 1.6;
}
.terminal-sample pre { margin: 0; }
.terminal-sample code { color: inherit; background: none; }
</style>
