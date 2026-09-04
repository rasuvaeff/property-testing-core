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
    public function parsesTheIanaShape(string $dsn, string $host, int $port, int $database, string $prefix, bool $tls, float $timeout): void
    {
        $parsed = RedisDsn::parse($dsn);

        Assert::same($parsed->host, $host);
        Assert::same($parsed->port, $port);
        Assert::same($parsed->database, $database);
        Assert::same($parsed->prefix, $prefix);
        Assert::same($parsed->tls, $tls);
        Assert::same($parsed->timeout, $timeout);
    }

    /**
     * @return iterable<string, array{string, string, int, int, string, bool, float}>
     */
    public static function dsnProvider(): iterable
    {
        yield 'host only' => ['redis://redis', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'host and port' => ['redis://127.0.0.1:6399', '127.0.0.1', 6399, 0, RedisDsn::DEFAULT_PREFIX, false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'database in the path' => ['redis://redis:6379/2', 'redis', 6379, 2, RedisDsn::DEFAULT_PREFIX, false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'prefix in the query' => ['redis://redis/?prefix=suite-a:', 'redis', RedisDsn::DEFAULT_PORT, 0, 'suite-a:', false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'database and prefix' => ['redis://redis:6379/3?prefix=suite-a:', 'redis', 6379, 3, 'suite-a:', false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'timeout in the query' => ['redis://redis?timeout=0.25', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false, 0.25];
        yield 'tls scheme' => ['rediss://redis:6380/1', 'redis', 6380, 1, RedisDsn::DEFAULT_PREFIX, true, RedisDsn::DEFAULT_TIMEOUT];
        yield 'scheme case does not matter' => ['REDISS://redis', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, true, RedisDsn::DEFAULT_TIMEOUT];
        yield 'ipv6 literal loses its brackets' => ['redis://[::1]:6379/0', '::1', 6379, 0, RedisDsn::DEFAULT_PREFIX, false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'trailing slash is no database' => ['redis://redis/', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false, RedisDsn::DEFAULT_TIMEOUT];
        yield 'empty prefix means the default' => ['redis://redis?prefix=', 'redis', RedisDsn::DEFAULT_PORT, 0, RedisDsn::DEFAULT_PREFIX, false, RedisDsn::DEFAULT_TIMEOUT];
    }

    public function theConnectionParametersAreTheOnesPredisTakes(): void
    {
        Assert::same(
            RedisDsn::parse('redis://redis:6399/4?prefix=x:')->toPredisParameters(),
            ['scheme' => 'tcp', 'host' => 'redis', 'port' => 6399, 'database' => 4, 'timeout' => RedisDsn::DEFAULT_TIMEOUT],
        );
        Assert::same(
            RedisDsn::parse('rediss://redis')->toPredisParameters(),
            ['scheme' => 'tls', 'host' => 'redis', 'port' => RedisDsn::DEFAULT_PORT, 'database' => 0, 'timeout' => RedisDsn::DEFAULT_TIMEOUT],
        );
    }

    public function phpRedisSelectsTlsThroughTheHost(): void
    {
        Assert::same(RedisDsn::parse('rediss://redis')->phpRedisHost(), 'tls://redis');
        Assert::same(RedisDsn::parse('redis://redis')->phpRedisHost(), 'redis');
    }

    #[DataProvider('invalidDsns')]
    public function invalidPortDatabaseAndTimeoutAreRefused(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);

            Assert::fail('expected the DSN to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::true($e instanceof \InvalidArgumentException);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDsns(): iterable
    {
        yield 'zero port' => ['redis://redis:0'];
        yield 'port above maximum' => ['redis://redis:65536'];
        yield 'database overflow' => ['redis://redis/999999999999999999999999'];
        yield 'zero timeout' => ['redis://redis?timeout=0'];
        yield 'negative timeout' => ['redis://redis?timeout=-1'];
        yield 'non numeric timeout' => ['redis://redis?timeout=fast'];
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
        yield 'digits at the end only' => ['redis://redis/b2', 'b2'];
    }

    public function anArrayValuedPrefixIsRefusedRatherThanSilentlyDefaulted(): void
    {
        // `?prefix[]=x` parses into an array. Falling back to the default
        // prefix would point the corpus at another key space without a word,
        // while an array-valued timeout has always thrown.
        try {
            RedisDsn::parse('redis://redis?prefix[]=suite-a:');

            Assert::fail('expected the prefix to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('prefix that is not a single value');
            Assert::string($e->getMessage())->contains('?prefix=key-prefix');
        }
    }

    public function aLeadingZeroDatabaseIndexIsReportedAsAFormatProblem(): void
    {
        // "01" is a spelling mistake, not a number too large to hold: reporting
        // it as a range problem sends the reader looking for a limit that is
        // not the reason.
        try {
            RedisDsn::parse('redis://redis/01');

            Assert::fail('expected the database index to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('leading zero');
            Assert::string($e->getMessage())->contains('write it as 1');
        }
    }

    public function aDatabaseIndexTooLargeForAnIntStillReportsTheRange(): void
    {
        try {
            RedisDsn::parse('redis://redis/999999999999999999999999');

            Assert::fail('expected the database index to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('outside the supported integer range');
        }
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
