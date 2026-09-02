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
    public function theShaIsTheScriptsSha1(): void
    {
        // EVALSHA addresses the script by exactly this digest; a stale one
        // would fall back to EVAL on every write and never notice.
        Assert::same(CorpusScript::SHA, sha1(CorpusScript::CAS));
    }
}
