<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use DateTimeImmutable;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\ObjectInfo;
use Etel\SqliteFileStorage\StorageObject;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function fopen;
use function fwrite;
use function rewind;

/**
 * @internal
 */
#[CoversClass(StorageObject::class)]
final class StorageObjectTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Returns the content stream resource it was constructed with')]
    public function testGetContentStreamReturnsTheSameResource(): void
    {
        $stream = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($stream);

        $object = new StorageObject(info: $this->info(), stream: $stream);

        $this->assertSame($stream, $object->getContentStream());
    }

    #[Test]
    #[TestDox('Reads the whole stream into a string via getContents')]
    public function testGetContentsReadsTheWholeStream(): void
    {
        $stream = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($stream);

        fwrite(stream: $stream, data: 'hello storage');
        rewind(stream: $stream);

        $object = new StorageObject(info: self::info(), stream: $stream);

        $this->assertSame('hello storage', $object->getContents());
    }

    private function info(): ObjectInfo
    {
        return new ObjectInfo(
            id: 'id',
            key: 'key',
            name: 'name',
            mimeType: null,
            hash: 'hash',
            size: 13,
            storedSize: 13,
            compression: Compression::None,
            encryption: Encryption::None,
            meta: [],
            createdAt: new DateTimeImmutable(datetime: '2026-01-01'),
            updatedAt: null
        );
    }
}
