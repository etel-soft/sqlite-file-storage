<?php

/* @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Exception\StorageReadOnlyException;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQLite3;

/**
 * @internal
 */
final class DeleteObjectsTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Deletes the objects under the keys given and leaves the others')]
    public function testDeletesTheKeysGiven(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'content of a');
        $storage->putObject(key: 'b.txt', content: 'content of b');
        $storage->putObject(key: 'c.txt', content: 'content of c');

        $this->assertSame(2, $storage->deleteObjects(keys: ['a.txt', 'c.txt']));

        $this->assertFalse($storage->hasObject(key: 'a.txt'));
        $this->assertFalse($storage->hasObject(key: 'c.txt'));
        $this->assertSame('content of b', $storage->getObject(key: 'b.txt')->getContents());

        $stats = $storage->getStats();

        $this->assertSame(1, $stats->objectCount);
        $this->assertSame(1, $stats->contentCount);
    }

    #[Test]
    #[TestDox('Content shared with an object that stays is kept; content whose every object goes is removed')]
    public function testSharedContentIsKeptWhileReferenced(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'shared content');
        $storage->putObject(key: 'b.txt', content: 'shared content');
        $storage->putObject(key: 'c.txt', content: 'shared content');
        $storage->putObject(key: 'd.txt', content: 'gone content');
        $storage->putObject(key: 'e.txt', content: 'gone content');

        $this->assertSame(2, $storage->getStats()->contentCount);
        $this->assertSame(4, $storage->deleteObjects(keys: ['a.txt', 'b.txt', 'd.txt', 'e.txt']));

        $this->assertSame('shared content', $storage->getObject(key: 'c.txt')->getContents());

        $stats = $storage->getStats();

        $this->assertSame(1, $stats->objectCount);
        $this->assertSame(1, $stats->contentCount);

        // the reference count left is exact: the last object takes the content with it
        $this->assertSame(1, $storage->deleteObjects(keys: ['c.txt']));
        $this->assertSame(0, $storage->getStats()->contentCount);
    }

    #[Test]
    #[TestDox('A key with no object, or given twice, is passed over: only what was deleted is counted')]
    public function testAbsentKeysArePassedOver(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'content of a');
        $storage->putObject(key: 'b.txt', content: 'content of b');

        $this->assertSame(1, $storage->deleteObjects(keys: ['missing.txt', 'a.txt', 'a.txt', '']));
        $this->assertSame(0, $storage->deleteObjects(keys: ['missing.txt', 'a.txt']));

        $this->assertSame('content of b', $storage->getObject(key: 'b.txt')->getContents());
        $this->assertSame(1, $storage->getStats()->objectCount);
    }

    #[Test]
    #[TestDox('No keys delete nothing')]
    public function testNoKeysDeleteNothing(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'content of a');

        $this->assertSame(0, $storage->deleteObjects(keys: []));
        $this->assertSame(1, $storage->getStats()->objectCount);
    }

    #[Test]
    #[TestDox('A read-only storage refuses, without keys too')]
    public function testReadOnlyStorageRefuses(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'content of a');
        $storage->close();

        $readOnly = $this->openStorage(options: new StorageOptions(readOnly: true));

        try {
            $readOnly->deleteObjects(keys: []);
            $this->fail('A read-only storage took a batch delete.');
        } catch (StorageReadOnlyException) {
        }

        try {
            $readOnly->deleteObjects(keys: ['a.txt']);
            $this->fail('A read-only storage took a batch delete.');
        } catch (StorageReadOnlyException $exception) {
            $this->assertStringContainsString('deleteObjects', $exception->getMessage());
        }

        $this->assertTrue($readOnly->hasObject(key: 'a.txt'));
    }

    #[Test]
    #[TestDox('A batch that fails on one key deletes none: objects and content of the keys before it are back')]
    public function testFailureRollsTheWholeBatchBack(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'shared content');
        $storage->putObject(key: 'b.txt', content: 'shared content');
        $storage->putObject(key: 'c.txt', content: 'content of c');
        $storage->putObject(key: 'd.txt', content: 'content of d');
        $storage->close();

        // fails the one statement only (ABORT): undoing the deletes before it is left to the storage
        $raw = new SQLite3(filename: $this->databasePath());
        $raw->exec(query: <<<'SQL'
            CREATE TRIGGER refuse_c BEFORE DELETE ON objects WHEN old.key = 'c.txt'
            BEGIN
                SELECT RAISE(ABORT, 'c.txt stays');
            END
            SQL);
        $raw->close();

        $storage = $this->openStorage();

        try {
            $storage->deleteObjects(keys: ['a.txt', 'b.txt', 'c.txt', 'd.txt']);
            $this->fail('A batch with a key that cannot be deleted went through.');
        } catch (StorageException $exception) {
            $this->assertInstanceOf(StorageIoException::class, $exception);
            $this->assertStringContainsString('c.txt stays', $exception->getMessage());
        }

        $stats = $storage->getStats();

        $this->assertSame(4, $stats->objectCount);
        $this->assertSame(3, $stats->contentCount);
        $this->assertSame('shared content', $storage->getObject(key: 'a.txt')->getContents());
        $this->assertSame('shared content', $storage->getObject(key: 'b.txt')->getContents());
        $this->assertSame('content of d', $storage->getObject(key: 'd.txt')->getContents());

        // the storage is out of the failed transaction: the next batch goes through, with exact reference counts
        $this->assertSame(3, $storage->deleteObjects(keys: ['a.txt', 'b.txt', 'd.txt']));
        $this->assertSame(1, $storage->getStats()->contentCount);
    }
}
