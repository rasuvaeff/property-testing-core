<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner\Redis;

use Rasuvaeff\PropertyTesting\Runner\Redis\RedisDsn;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RedisDsn::class)]
final class RedisDsnTest
{
    #[DataProvider('dsnProvider')]
    public function parsesTheIanaShape(string $dsn, string $host, int $port, int $database, string $prefix, bool $tls): void
    {
        $parsed = RedisDsn::parse($dsn);

        Assert::same($parsed->host, $host);
        Assert::same($parsed->port, $port);
        Assert::same($parsed->database, $database);
        Assert::same($parsed->prefix, $prefix);
        Assert::same($parsed->tls, $tls);
    }

    /**
     * @return iterable<string, array{string, string, int, int, string, bool}>
     */
    public static function dsnProvider(): iterable
    {
        yield 'host only' => ['redis://redis', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false];
        yield 'host and port' => ['redis://127.0.0.1:6399', '127.0.0.1', 6399, 0, RedisDsn::DEFAULT_PREFIX, false];
        yield 'database in the path' => ['redis://redis:6379/2', 'redis', 6379, 2, RedisDsn::DEFAULT_PREFIX, false];
        yield 'prefix in the query' => ['redis://redis/?prefix=suite-a:', 'redis', RedisDsn::DEFAULT_PORT, 0, 'suite-a:', false];
        yield 'database and prefix' => ['redis://redis:6379/3?prefix=suite-a:', 'redis', 6379, 3, 'suite-a:', false];
        yield 'tls scheme' => ['rediss://redis:6380/1', 'redis', 6380, 1, RedisDsn::DEFAULT_PREFIX, true];
        yield 'scheme case does not matter' => ['REDISS://redis', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, true];
        yield 'ipv6 literal loses its brackets' => ['redis://[::1]:6379/0', '::1', 6379, 0, RedisDsn::DEFAULT_PREFIX, false];
        yield 'trailing slash is no database' => ['redis://redis/', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false];
        yield 'empty prefix means the default' => ['redis://redis?prefix=', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false];
    }

    public function theConnectionParametersAreTheOnesPredisTakes(): void
    {
        Assert::same(
            RedisDsn::parse('redis://redis:6399/4?prefix=x:')->toPredisParameters(),
            ['scheme' => 'tcp', 'host' => 'redis', 'port' => 6399, 'database' => 4],
        );
        Assert::same(
            RedisDsn::parse('rediss://redis')->toPredisParameters(),
            ['scheme' => 'tls', 'host' => 'redis', 'port' => RedisDsn::DEFAULT_PORT, 'database' => 0],
        );
    }

    public function phpRedisSelectsTlsThroughTheHost(): void
    {
        Assert::same(RedisDsn::parse('rediss://redis')->phpRedisHost(), 'tls://redis');
        Assert::same(RedisDsn::parse('redis://redis')->phpRedisHost(), 'redis');
    }

    #[DataProvider('pathThatIsNotADatabase')]
    public function aPathThatIsNotADatabaseIndexIsRefusedNotReinterpreted(string $dsn, string $path): void
    {
        // The pre-0.5 form used the path as the key prefix; everywhere else a
        // Redis DSN path is the database index, so the old form is refused
        // with the new spelling in the message rather than silently meaning
        // "database" or silently meaning "prefix".
        try {
            RedisDsn::parse($dsn);

            Assert::fail('expected the path to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not a database index');
            Assert::string($e->getMessage())->contains('?prefix=' . $path);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathThatIsNotADatabase(): iterable
    {
        yield 'legacy prefix path' => ['redis://redis:6379/suite-a:', 'suite-a:'];
        yield 'negative' => ['redis://redis/-1', '-1'];
        yield 'mixed' => ['redis://redis/2b', '2b'];
    }

    public function anUnknownQueryParameterIsRefused(): void
    {
        try {
            RedisDsn::parse('redis://redis?db=2');

            Assert::fail('expected the query parameter to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('unknown query parameter "db"');
        }
    }

    #[DataProvider('credentialedDsns')]
    public function aDsnWithCredentialsIsRejectedWithoutEchoingThePassword(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);

            Assert::fail('expected the credentials to be rejected');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('credentials');
            Assert::false(str_contains($e->getMessage(), 's3cret'));
            Assert::false(str_contains($e->getMessage(), $dsn));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialedDsns(): iterable
    {
        yield 'user and password' => ['redis://user:s3cret@redis'];
        yield 'password only' => ['redis://:s3cret@redis'];
        yield 'user only' => ['redis://user@redis'];
    }

    #[DataProvider('malformedDsns')]
    public function aMalformedDsnIsAConfigurationError(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);

            Assert::fail('expected the DSN to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains($dsn);
            Assert::string($e->getMessage())->contains('not a usable Redis DSN');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDsns(): iterable
    {
        yield 'no host' => ['redis://'];
        yield 'port only' => ['redis://:6379'];
        yield 'empty ipv6' => ['redis://[]:6379'];
        yield 'database without a host' => ['redis:///2'];
    }
}
