---
title: "Security"
description: "What property-testing is safe to run on: no eval, no network, no filesystem writes outside PROPERTY_DB — and what stays the test author's responsibility."
---

# Security

The engine (`rasuvaeff/property-testing-core`) performs no I/O, SQL, shell, or
network operations itself; the only filesystem writes it makes are the
regression corpus, and only when `PROPERTY_DB` is set. Random values are
generated with PHP's MT19937 engine seeded by the reported seed — do not rely
on them for cryptographic purposes.

Each adapter executes test methods through its own framework's reflection and
pipeline: the Testo adapter's fallback interceptor is `PropertyInterceptor`;
the PHPUnit adapter runs inside an ordinary PHPUnit test method. Neither adds
I/O of its own beyond what the framework itself already does to run a test.

## `PROPERTY_DB` writes generator output verbatim

The [regression corpus](/guide/regression-corpus) persists falsifying
arguments to disk as JSON — this is generator output, not synthetic noise. If
a property's generators can produce credential-shaped or personal data (fed
real fixtures, wrapping a production value space), the corpus directory can
too. Treat it like any other directory holding test data with that shape:
don't make it world-readable, and don't commit it alongside a property whose
inputs carry real sensitivity.
