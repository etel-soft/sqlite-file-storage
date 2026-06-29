<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\CompressionMap;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(CompressionMap::class)]
final class CompressionMapTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Maps every Compression case to its stored integer and back')]
    #[DataProvider('provideMapping')]
    public function testRoundtrip(Compression $compression, int $stored): void
    {
        $this->assertSame($stored, CompressionMap::fromDomain(compression: $compression));
        $this->assertSame($compression, CompressionMap::toDomain(value: $stored));
    }

    /**
     * @return iterable<string, array{Compression, int}>
     */
    public static function provideMapping(): iterable
    {
        yield 'none' => [Compression::None, 0];

        yield 'gzip' => [Compression::Gzip, 1];

        yield 'deflate' => [Compression::Deflate, 2];

        yield 'zlib' => [Compression::Zlib, 3];

        yield 'brotli' => [Compression::Brotli, 4];
    }

    #[Test]
    #[TestDox('Throws for an unknown stored integer')]
    public function testUnknownStoredValueThrows(): void
    {
        $this->expectException(StorageIoException::class);

        CompressionMap::toDomain(value: 99);
    }
}
