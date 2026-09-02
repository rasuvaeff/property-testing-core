<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

/**
 * `redis://host[:port][/db][?prefix=key-prefix]` (or `rediss://` for TLS),
 * taken apart.
 *
 * The shape is the one the IANA registration, predis and Symfony agree on:
 * the path is the database index, TLS is the `rediss` scheme, and anything
 * else — the key prefix — is a query parameter. A path that is not a
 * database index is refused rather than reinterpreted, so a DSN means the
 * same thing here as everywhere else it could be pasted into.
 *
 * Parsing lives in its own type because it is the part with answers worth
 * asserting — a default port and a default prefix are decisions, and a
 * resolver that also builds clients hides them behind a connection.
 *
 * @api
 */
final readonly class RedisDsn
{
    public const int DEFAULT_PORT = 6379;

    public const int DEFAULT_DATABASE = 0;

    /** The engine's own default, so a DSN without a prefix behaves like the plain constructor. */
    public const string DEFAULT_PREFIX = 'property-testing:corpus:';

    /**
     * @param non-empty-string $host The host as a client connects to it: an IPv6 literal without
     *        the brackets the URI form wraps it in.
     * @param int $port The TCP port; {@see DEFAULT_PORT} when the DSN names none.
     * @param int<0, max> $database The database index to `SELECT`; {@see DEFAULT_DATABASE} when
     *        the DSN has no path.
     * @param non-empty-string $prefix The key prefix every corpus key starts with;
     *        {@see DEFAULT_PREFIX} when the DSN has no `prefix` query parameter.
     * @param bool $tls Whether the connection is TLS (`rediss://`).
     */
    public function __construct(
        public string $host,
        public int $port,
        public int $database,
        public string $prefix,
        public bool $tls,
    ) {}

    /**
     * The connection parameters predis takes.
     *
     * Here rather than at the call site so the shape is something a test can
     * assert: an array literal built where the client is constructed can only
     * be checked by connecting to a server.
     *
     * @return array{scheme: 'tcp'|'tls', host: non-empty-string, port: int, database: int<0, max>}
     */
    public function toPredisParameters(): array
    {
        return ['scheme' => $this->tls ? 'tls' : 'tcp', 'host' => $this->host, 'port' => $this->port, 'database' => $this->database];
    }

    /**
     * The host as `ext-redis` connects to it: `tls://` in front for TLS,
     * which is how phpredis selects the transport.
     *
     * @return non-empty-string
     */
    public function phpRedisHost(): string
    {
        return $this->tls ? 'tls://' . $this->host : $this->host;
    }

    /**
     * @param string $dsn The DSN, already known to use the `redis` or `rediss` scheme.
     *
     * @throws \InvalidArgumentException When the DSN carries credentials, names no host, has a path
     *         that is not a database index, or has a query parameter other than `prefix`.
     */
    public static function parse(string $dsn): self
    {
        $parts = parse_url($dsn);

        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            // Reject credentials rather than drop them silently: parse_url would
            // discard the userinfo, so the connection would go without AUTH
            // while the operator believes it authenticated. The message never
            // echoes the DSN — it would carry the password into the CI log.
            throw new \InvalidArgumentException(
                'PROPERTY_DB carries credentials in its userinfo, which is not supported; configure Redis AUTH out of band',
            );
        }

        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (!is_string($host) || $host === '') {
            // Safe to quote the DSN: a credentialed one was already rejected
            // above, so whatever reaches here carries no userinfo.
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB="%s" is not a usable Redis DSN; expected redis://host[:port][/db][?prefix=key-prefix]',
                $dsn,
            ));
        }

        // parse_url keeps the brackets of an IPv6 literal; a client wants the address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);

            if ($host === '') {
                throw new \InvalidArgumentException(sprintf('PROPERTY_DB="%s" is not a usable Redis DSN; the IPv6 host is empty', $dsn));
            }
        }

        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $path = is_array($parts) ? ltrim($parts['path'] ?? '', '/') : '';
        $database = self::DEFAULT_DATABASE;

        if ($path !== '') {
            if (preg_match('/^\d+\z/', $path) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'PROPERTY_DB="%s" has a path that is not a database index; the key prefix goes in the query: redis://host[:port][/db]?prefix=%s',
                    $dsn,
                    $path,
                ));
            }

            /** @var int<0, max> $database */
            $database = (int) $path;
        }

        $query = [];

        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        /** @var mixed $prefix */
        $prefix = $query['prefix'] ?? null;

        foreach (array_keys($query) as $parameter) {
            if ($parameter !== 'prefix') {
                throw new \InvalidArgumentException(sprintf(
                    'PROPERTY_DB="%s" has an unknown query parameter "%s"; only prefix= is understood',
                    $dsn,
                    (string) $parameter,
                ));
            }
        }

        return new self(
            host: $host,
            port: is_int($port) ? $port : self::DEFAULT_PORT,
            database: $database,
            prefix: is_string($prefix) && $prefix !== '' ? $prefix : self::DEFAULT_PREFIX,
            tls: $scheme === 'rediss',
        );
    }
}
