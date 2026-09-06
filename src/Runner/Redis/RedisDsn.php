<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Runner\Redis;

/**
 * `redis://host[:port][/db][?prefix=key-prefix&timeout=seconds]` (or `rediss://` for TLS),
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

    public const float DEFAULT_TIMEOUT = 5.0;

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
     * @param float $timeout The connection timeout in seconds.
     * @param ?non-empty-string $password The `AUTH` password, or null for an unauthenticated
     *        server. Never parsed out of the DSN — see {@see parse()}.
     */
    public function __construct(
        public string $host,
        public int $port,
        public int $database,
        public string $prefix,
        public bool $tls,
        public float $timeout = self::DEFAULT_TIMEOUT,
        public ?string $password = null,
    ) {}

    /**
     * The connection parameters predis takes.
     *
     * Here rather than at the call site so the shape is something a test can
     * assert: an array literal built where the client is constructed can only
     * be checked by connecting to a server.
     *
     * The `password` key is present only when there is one: predis treats a
     * null password as a password and sends `AUTH`.
     *
     * @return array{scheme: 'tcp'|'tls', host: non-empty-string, port: int, database: int<0, max>, timeout: float, password?: non-empty-string}
     */
    public function toPredisParameters(): array
    {
        $parameters = ['scheme' => $this->tls ? 'tls' : 'tcp', 'host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'timeout' => $this->timeout];

        return $this->password === null ? $parameters : $parameters + ['password' => $this->password];
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
     * @param ?string $password The `AUTH` password, supplied out of band (the adapters read
     *        `PROPERTY_DB_PASSWORD`). Kept out of the DSN on purpose: `PROPERTY_DB` is echoed in
     *        the diagnostics below and lands in CI logs, and userinfo is rejected outright. An
     *        empty value means the same as none — an exported-but-empty variable is not a password.
     *
     * @throws \InvalidArgumentException When the DSN carries credentials, names no host, has a path
     *         that is not a database index, has an invalid port/timeout, or has an unknown query parameter.
     */
    public static function parse(string $dsn, ?string $password = null): self
    {
        $parts = parse_url($dsn);

        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            // Reject credentials rather than drop them silently: parse_url would
            // discard the userinfo, so the connection would go without AUTH
            // while the operator believes it authenticated. The message never
            // echoes the DSN — it would carry the password into the CI log.
            throw new \InvalidArgumentException(
                'PROPERTY_DB carries credentials in its userinfo, which is not supported; pass the password in PROPERTY_DB_PASSWORD instead',
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

            $parsedDatabase = (int) $path;

            if ((string) $parsedDatabase !== $path) {
                // Two ways to get here, and they need different advice: "01"
                // round-trips to "1" because of the leading zero, while a run of
                // twenty digits round-trips to PHP_INT_MAX because it does not
                // fit an int. Reporting the first as a range problem sends the
                // reader looking for a limit that is not the reason.
                throw new \InvalidArgumentException(sprintf(
                    str_starts_with($path, '0')
                        ? 'PROPERTY_DB="%1$s" has a database index with a leading zero; write it as %2$s'
                        : 'PROPERTY_DB="%1$s" has a database index outside the supported integer range',
                    $dsn,
                    (string) $parsedDatabase,
                ));
            }

            /** @var int<0, max> $database */
            $database = $parsedDatabase;
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_DB="%s" has a port outside 1..65535', $dsn));
        }

        $query = [];

        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        /** @var mixed $prefix */
        $prefix = $query['prefix'] ?? null;

        if ($prefix !== null && !is_string($prefix)) {
            // `?prefix[]=x` parses into an array. Falling back to the default
            // prefix would silently point the corpus at another key space —
            // this class refuses an unknown value rather than guessing, and an
            // array-valued timeout already throws.
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB="%s" has a prefix that is not a single value; write it as ?prefix=key-prefix',
                $dsn,
            ));
        }

        foreach (array_keys($query) as $parameter) {
            if (!in_array($parameter, ['prefix', 'timeout'], strict: true)) {
                throw new \InvalidArgumentException(sprintf(
                    'PROPERTY_DB="%s" has an unknown query parameter "%s"; only prefix= and timeout= are understood',
                    $dsn,
                    (string) $parameter,
                ));
            }
        }

        /** @var mixed $timeout */
        $timeout = $query['timeout'] ?? null;
        $timeout = $timeout === null || $timeout === '' ? self::DEFAULT_TIMEOUT : self::parseTimeout($timeout, $dsn);

        return new self(
            host: $host,
            port: is_int($port) ? $port : self::DEFAULT_PORT,
            database: $database,
            prefix: is_string($prefix) && $prefix !== '' ? $prefix : self::DEFAULT_PREFIX,
            tls: $scheme === 'rediss',
            timeout: $timeout,
            password: $password === '' ? null : $password,
        );
    }

    private static function parseTimeout(mixed $value, string $dsn): float
    {
        if (!is_string($value) || preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)\z/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_DB="%s" has an invalid timeout; expected a positive number of seconds', $dsn));
        }

        $timeout = (float) $value;

        if (!is_finite($timeout) || $timeout <= 0.0) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_DB="%s" has an invalid timeout; expected a positive number of seconds', $dsn));
        }

        return $timeout;
    }
}
