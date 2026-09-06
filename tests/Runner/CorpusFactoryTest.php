<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner;

use Rasuvaeff\PropertyTesting\Runner\CorpusFactory;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\LazyPhpRedisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(CorpusFactory::class)]
#[Covers(LazyPhpRedisCorpusClient::class)]
final class CorpusFactoryTest
{
    public function aPathIsADirectoryCorpus(): void
    {
        Assert::instanceOf(CorpusFactory::fromDsn(sys_get_temp_dir() . '/property-testing-factory-' . getmypid()), FilesystemCorpus::class);
    }

    public function aWindowsDriveLetterIsAPathNotAScheme(): void
    {
        // `C://` has a one-letter "scheme"; the pattern requires a letter
        // followed by `://`, so this must not be refused as an unknown scheme.
        // A single letter is a valid scheme by the pattern, so the corpus is a
        // directory only when the value carries no `://` at all.
        Assert::instanceOf(CorpusFactory::fromDsn('C:\\corpus'), FilesystemCorpus::class);
    }

    #[DataProvider('redisDsns')]
    public function aRedisDsnIsASharedCorpusAndResolvingItOpensNoSocket(string $dsn): void
    {
        // Port 6399 has no server; a resolver that connected eagerly would throw here.
        $corpus = CorpusFactory::fromDsn($dsn);

        Assert::instanceOf($corpus, RedisCorpus::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redisDsns(): iterable
    {
        yield 'plain' => ['redis://127.0.0.1:6399'];
        yield 'tls' => ['rediss://127.0.0.1:6399'];
        yield 'uppercase scheme' => ['Redis://127.0.0.1:6399/1?prefix=suite:'];
    }

    public function theSameDsnResolvesToTheSameInstance(): void
    {
        $dsn = sys_get_temp_dir() . '/property-testing-factory-memo-' . getmypid();

        Assert::same(CorpusFactory::fromDsn($dsn), CorpusFactory::fromDsn($dsn));
        Assert::true(CorpusFactory::fromDsn($dsn) !== CorpusFactory::fromDsn($dsn . '-other'));
    }

    #[DataProvider('unsupportedSchemes')]
    public function anUnsupportedSchemeIsRefusedInsteadOfBecomingADirectory(string $dsn, string $scheme): void
    {
        try {
            CorpusFactory::fromDsn($dsn);

            Assert::fail('expected the scheme to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(sprintf('unsupported scheme "%s://"', $scheme));
            Assert::false(str_contains($e->getMessage(), 's3cret'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedSchemes(): iterable
    {
        yield 'typo' => ['resis://127.0.0.1:6379', 'resis'];
        yield 'other backend' => ['memcached://127.0.0.1', 'memcached'];
        yield 'with credentials, never echoed' => ['mysql://user:s3cret@db/corpus', 'mysql'];
    }

    public function anUnusableRedisDsnIsAConfigurationError(): void
    {
        try {
            CorpusFactory::fromDsn('redis://');

            Assert::fail('expected the DSN to be refused');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not a usable Redis DSN');
        }
    }

    public function theLazyClientConnectsOnFirstUseOnly(): void
    {
        if (!extension_loaded('redis')) {
            // A bare return would make this the suite's one risky test: no
            // assertion ran, and Testo cannot tell that from a test that forgot
            // to assert. Without the extension there is no verdict to give.
            throw new SkipTest('ext-redis is not loaded');
        }

        $client = new LazyPhpRedisCorpusClient(\Rasuvaeff\PropertyTesting\Runner\Redis\RedisDsn::parse('redis://127.0.0.1:6399'));

        try {
            $client->get('anything');

            Assert::fail('expected the connection to be refused');
        } catch (\RuntimeException|\RedisException $e) {
            Assert::true($e instanceof \Throwable);
        }
    }

    /**
     * Two suites pointed at the same server as different users are two
     * corpora. Keyed by the DSN alone, the second would be handed the first
     * one's connection — and authenticate as the wrong user.
     */
    public function thePasswordIsPartOfTheCacheIdentity(): void
    {
        $directory = sys_get_temp_dir() . '/corpus-identity-' . bin2hex(random_bytes(6));

        Assert::same(CorpusFactory::fromDsn($directory), CorpusFactory::fromDsn($directory));
        Assert::same(CorpusFactory::fromDsn($directory, 'one'), CorpusFactory::fromDsn($directory, 'one'));

        $unauthenticated = CorpusFactory::fromDsn($directory);
        Assert::false($unauthenticated === CorpusFactory::fromDsn($directory, 'one'));
        Assert::false(CorpusFactory::fromDsn($directory, 'one') === CorpusFactory::fromDsn($directory, 'two'));
    }
}
