<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner\Redis;

use Predis\Response\ServerException;
use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusScript;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Tests\Support\RecordingPredisClient;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PredisCorpusClient::class)]
final class PredisCorpusClientTest
{
    public function getReadsTheKeyAndReturnsTheDocument(): void
    {
        $predis = new RecordingPredisClient(['{"format":1}']);

        Assert::same((new PredisCorpusClient($predis))->get('corpus:p'), '{"format":1}');
        Assert::same($predis->commands, [['GET', ['corpus:p']]]);
    }

    public function aMissingKeyIsNull(): void
    {
        $predis = new RecordingPredisClient([null]);

        Assert::null((new PredisCorpusClient($predis))->get('corpus:p'));
    }

    public function compareAndSetRunsTheCachedScriptOnTheOneKey(): void
    {
        $predis = new RecordingPredisClient([1]);

        Assert::true((new PredisCorpusClient($predis))->compareAndSet('corpus:p', 'old', 'new'));
        Assert::same($predis->commands, [['EVALSHA', [CorpusScript::sha(), 1, 'corpus:p', 'old', 'new']]]);
    }

    public function anAbsentExpectationAndARemovalTravelAsEmptyStrings(): void
    {
        $predis = new RecordingPredisClient([1]);

        Assert::true((new PredisCorpusClient($predis))->compareAndSet('corpus:p', null, null));
        Assert::same($predis->commands, [['EVALSHA', [CorpusScript::sha(), 1, 'corpus:p', '', '']]]);
    }

    public function anUnknownScriptIsSentInFull(): void
    {
        $predis = new RecordingPredisClient([new ServerException('NOSCRIPT No matching script. Please use EVAL.'), 1]);

        Assert::true((new PredisCorpusClient($predis))->compareAndSet('corpus:p', 'old', 'new'));
        Assert::same($predis->commands, [
            ['EVALSHA', [CorpusScript::sha(), 1, 'corpus:p', 'old', 'new']],
            ['EVAL', [CorpusScript::CAS, 1, 'corpus:p', 'old', 'new']],
        ]);
    }

    public function theFallbackSendsAnAbsentExpectationAndARemovalAsEmptyStrings(): void
    {
        $predis = new RecordingPredisClient([new ServerException('NOSCRIPT No matching script. Please use EVAL.'), 0]);

        Assert::false((new PredisCorpusClient($predis))->compareAndSet('corpus:p', null, null));
        Assert::same($predis->commands[1], ['EVAL', [CorpusScript::CAS, 1, 'corpus:p', '', '']]);
    }

    public function anyOtherRefusalIsRethrownWithoutAFallback(): void
    {
        $refusal = new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value');
        $predis = new RecordingPredisClient([$refusal, 1]);

        try {
            (new PredisCorpusClient($predis))->compareAndSet('corpus:p', 'old', 'new');

            Assert::fail('expected the refusal to be rethrown');
        } catch (ServerException $e) {
            Assert::same($e, $refusal);
        }

        Assert::same(count($predis->commands), 1);
    }

    #[DataProvider('scriptReplies')]
    public function theScriptReplyDecidesWhetherTheDocumentWasWritten(mixed $reply, bool $written): void
    {
        Assert::same((new PredisCorpusClient(new RecordingPredisClient([$reply])))->compareAndSet('corpus:p', 'old', 'new'), $written);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function scriptReplies(): iterable
    {
        yield 'written' => [1, true];
        yield 'written, as text' => ['1', true];
        yield 'stale expectation' => [0, false];
        yield 'any other number' => [2, false];
        yield 'nothing' => [null, false];
    }
}
