<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Durability;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Exception\StorageReadOnlyException;
use Etel\SqliteFileStorage\SqliteStorage;
use Etel\SqliteFileStorage\Storage;
use Etel\SqliteFileStorage\StorageFactory;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionProperty;
use SQLite3;

use function clearstatcache;
use function file_put_contents;
use function filesize;
use function random_bytes;
use function str_repeat;

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
    #[TestDox('A file that is no database fails to open as a storage IO exception, read-only too')]
    public function testFileThatIsNoDatabaseFailsAsStorageIoException(): void
    {
        $path = $this->databasePath(name: 'garbage.db');
        file_put_contents(filename: $path, data: str_repeat(string: 'not a database ', times: 512));
        $failures = [];

        foreach ([new StorageOptions(), new StorageOptions(readOnly: true)] as $options) {
            try {
                new StorageFactory()->open(path: $path, options: $options);
                $failures[] = 'opened';
            } catch (StorageIoException $exception) {
                $failures[] = $exception::class;
            }
        }

        $this->assertSame([StorageIoException::class, StorageIoException::class], $failures);
    }

    #[Test]
    #[TestDox('Writes are left to the OS unless Synced is asked for (synchronous NORMAL, else FULL)')]
    public function testDurabilitySetsSynchronous(): void
    {
        $buffered = $this->openStorage(name: 'buffered.db');
        $synced = $this->openStorage(options: new StorageOptions(durability: Durability::Synced), name: 'synced.db');

        // SQLite's codes: 2 is FULL, 1 is NORMAL
        $this->assertSame(
            [2, 1],
            [self::pragma(storage: $synced, name: 'synchronous'), self::pragma(storage: $buffered, name: 'synchronous')]
        );
    }

    #[Test]
    #[TestDox('A write connection bounds the log it leaves behind: 64 MiB, or as asked')]
    public function testJournalSizeLimitIsSet(): void
    {
        $default = $this->openStorage(name: 'default.db');
        $custom = $this->openStorage(options: new StorageOptions(journalSizeLimitBytes: 1_048_576), name: 'custom.db');

        $this->assertSame(
            [StorageOptions::DEFAULT_JOURNAL_SIZE_LIMIT, 1_048_576],
            [
                self::pragma(storage: $default, name: 'journal_size_limit'),
                self::pragma(storage: $custom, name: 'journal_size_limit'),
            ]
        );
    }

    #[Test]
    #[TestDox('After a large write the log is cut back to the limit when the next write starts it over')]
    public function testLargeWriteLeavesNoLargeLog(): void
    {
        $writer = $this->openStorage(options: new StorageOptions(journalSizeLimitBytes: 1_048_576), name: 'wal.db');
        // another connection keeps the database open: the last one to close would remove the log altogether
        $reader = $this->openStorage(options: new StorageOptions(readOnly: true), name: 'wal.db');
        $writer->putObject(key: 'large.bin', content: random_bytes(length: 4_194_304));
        clearstatcache();
        $afterLarge = filesize(filename: $this->databasePath(name: 'wal.db-wal'));

        $writer->putObject(key: 'small.txt', content: 'small');
        $writer->putObject(key: 'small-2.txt', content: 'small');
        clearstatcache();

        $this->assertGreaterThan(4_194_304, $afterLarge);
        $this->assertLessThanOrEqual(1_048_576, filesize(filename: $this->databasePath(name: 'wal.db-wal')));
        $this->assertTrue($reader->hasObject(key: 'large.bin'));
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

    private static function pragma(Storage $storage, string $name): mixed
    {
        $db = new ReflectionProperty(class: SqliteStorage::class, property: 'db')->getValue(object: $storage);
        self::assertInstanceOf(SQLite3::class, $db);

        return $db->querySingle(query: 'PRAGMA ' . $name);
    }
}
