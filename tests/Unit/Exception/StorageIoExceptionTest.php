<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(StorageIoException::class)]
final class StorageIoExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method blobOpenFailed() reports the failure')]
    public function testBlobOpenFailed(): void
    {
        $exception = StorageIoException::blobOpenFailed();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(StorageIoException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('BLOB', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method cannotOpenDatabase() includes the path and wraps the previous throwable')]
    public function testCannotOpenDatabase(): void
    {
        $previous = new RuntimeException(message: 'boom');
        $exception = StorageIoException::cannotOpenDatabase(path: '/var/data/bucket.db', previous: $previous);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('/var/data/bucket.db', $exception->getMessage());
        $this->assertStringContainsString('boom', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    #[TestDox('Method codecFailed() includes the codec name')]
    public function testCodecFailed(): void
    {
        $exception = StorageIoException::codecFailed(codec: 'brotli');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('brotli', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method codecInitFailed() includes the codec name')]
    public function testCodecInitFailed(): void
    {
        $exception = StorageIoException::codecInitFailed(codec: 'deflate');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('deflate', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method databaseNotFound() includes the path')]
    public function testDatabaseNotFound(): void
    {
        $exception = StorageIoException::databaseNotFound(path: '/missing.db');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('/missing.db', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method fromSqlite() includes the underlying message and wraps the previous throwable')]
    public function testFromSqlite(): void
    {
        $previous = new RuntimeException(message: 'disk I/O error');
        $exception = StorageIoException::fromSqlite(previous: $previous);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('disk I/O error', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    #[TestDox('Method queryFailed() reports the failure')]
    public function testQueryFailed(): void
    {
        $exception = StorageIoException::queryFailed();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('query failed', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method readFailed() reports the failure')]
    public function testReadFailed(): void
    {
        $exception = StorageIoException::readFailed();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('read', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method schemaTooNew() includes the version number')]
    public function testSchemaTooNew(): void
    {
        $exception = StorageIoException::schemaTooNew(version: 42);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('42', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method streamOpenFailed() reports the failure')]
    public function testStreamOpenFailed(): void
    {
        $exception = StorageIoException::streamOpenFailed();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('stream', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method unexpectedRowValue() includes the field name')]
    public function testUnexpectedRowValue(): void
    {
        $exception = StorageIoException::unexpectedRowValue(field: 'created_at');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('created_at', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method unknownStorageValue() includes the field name and the value')]
    public function testUnknownStorageValue(): void
    {
        $exception = StorageIoException::unknownStorageValue(field: 'compression', value: 9);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('compression', $exception->getMessage());
        $this->assertStringContainsString('9', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method writeFailed() reports the failure')]
    public function testWriteFailed(): void
    {
        $exception = StorageIoException::writeFailed();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('write', $exception->getMessage());
    }
}
