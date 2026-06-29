<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Exception\StorageReadOnlyException;
use Etel\SqliteFileStorage\StorageFactory;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
final class StorageFactoryTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Read-only storage serves reads and rejects writes')]
    public function testReadOnlyStorageServesReadsAndRejectsWrites(): void
    {
        $writer = $this->openStorage();
        $writer->putObject(key: 'doc.txt', content: 'persisted');
        $writer->close();

        $reader = $this->openStorage(options: new StorageOptions(readOnly: true));

        $this->assertSame('persisted', $reader->getObject(key: 'doc.txt')->getContents());
        $this->assertSame(1, $reader->getStats()->objectCount);

        $this->expectException(StorageReadOnlyException::class);

        $reader->putObject(key: 'new.txt', content: 'nope');
    }

    #[Test]
    #[TestDox('Opening a missing database read-only throws')]
    public function testReadOnlyOpenOfMissingDatabaseThrows(): void
    {
        $this->expectException(StorageIoException::class);

        new StorageFactory()->open(
            path: $this->databasePath(name: 'missing.db'),
            options: new StorageOptions(readOnly: true)
        );
    }

    #[Test]
    #[TestDox('Opening a missing database without create throws')]
    public function testOpenWithoutCreateOfMissingDatabaseThrows(): void
    {
        $this->expectException(StorageIoException::class);

        new StorageFactory()->open(
            path: $this->databasePath(name: 'missing.db'),
            options: new StorageOptions(createIfNotExists: false)
        );
    }

    #[Test]
    #[TestDox('Wraps a failure to open the database file as a storage IO exception')]
    public function testWrapsDatabaseOpenFailure(): void
    {
        // a missing parent directory makes SQLite fail to create the file
        $this->expectException(StorageIoException::class);

        new StorageFactory()->open(path: $this->databasePath(name: 'missing-dir/storage.db'));
    }

    #[Test]
    #[TestDox('Reopening an existing storage keeps the persisted data')]
    public function testReopeningExistingStorageKeepsData(): void
    {
        $first = $this->openStorage();
        $first->putObject(key: 'persist.txt', content: 'survives reopen');
        $first->close();

        $second = $this->openStorage();

        $this->assertSame('survives reopen', $second->getObject(key: 'persist.txt')->getContents());
    }
}
