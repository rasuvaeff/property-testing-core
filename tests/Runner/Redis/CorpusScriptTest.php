<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests\Runner\Redis;

use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusScript;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CorpusScript::class)]
final class CorpusScriptTest
{
    public function theShaIsTheScriptsSha1AndIsStable(): void
    {
        // EVALSHA addresses the script by exactly this digest of the bytes
        // sent — whatever line endings the checkout gave the constant.
        Assert::same(CorpusScript::sha(), sha1(CorpusScript::CAS));
        Assert::same(CorpusScript::sha(), CorpusScript::sha());
        Assert::same(strlen(CorpusScript::sha()), 40);
    }
}
