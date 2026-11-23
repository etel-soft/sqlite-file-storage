<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Codec;

use Etel\SqliteFileStorage\Internal\Codec\PassthroughCodec;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(PassthroughCodec::class)]
final class PassthroughCodecTest extends UnitTestCase
{
    #[Test]
    #[TestDox('add() returns the chunk unchanged')]
    public function testAddReturnsChunkUnchanged(): void
    {
        $codec = new PassthroughCodec();

        $this->assertSame('payload', $codec->add(chunk: 'payload'));
    }

    #[Test]
    #[TestDox('finish() returns an empty string')]
    public function testFinishReturnsEmptyString(): void
    {
        $codec = new PassthroughCodec();

        $this->assertSame('', $codec->finish());
    }

    #[Test]
    #[TestDox('Restarted, it is itself: it keeps no state')]
    public function testRestartedIsItself(): void
    {
        $codec = new PassthroughCodec();

        $this->assertSame($codec, $codec->restarted());
    }
}
