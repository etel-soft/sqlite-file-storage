<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Internal\EncodingOutcome;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(EncodingOutcome::class)]
final class EncodingOutcomeTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Exposes what was written')]
    public function testExposesValues(): void
    {
        $outcome = new EncodingOutcome(
            hash: 'abc',
            size: 10,
            storedSize: 7,
            mimeType: 'text/plain',
            compression: Compression::Zlib
        );

        $this->assertSame('abc', $outcome->hash);
        $this->assertSame(10, $outcome->size);
        $this->assertSame(7, $outcome->storedSize);
        $this->assertSame('text/plain', $outcome->mimeType);
        $this->assertSame(Compression::Zlib, $outcome->compression);
    }
}
