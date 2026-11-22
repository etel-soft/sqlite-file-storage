<?php

/* @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\ObjectNotFoundException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Exception\StorageNotInitializedException;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQLite3;

/**
 * Edge cases of the SQLite storage engine that the happy-path integration
 * tests do not exercise: validation guards, schema/version checks and content
 * rows deliberately corrupted out-of-band.
 *
 * @internal
 */
final class SqliteStorageEdgeCasesTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Putting an object under an empty key is rejected')]
    public function testEmptyKeyIsRejected(): void
    {
        $storage = $this->openStorage();

        $this->expectException(InvalidArgumentException::class);

        $storage->putObject(key: '', content: 'data');
    }

    #[Test]
    #[TestDox('Updating metadata of a missing object throws')]
    public function testUpdateMetaOfMissingObjectThrows(): void
    {
        $storage = $this->openStorage();

        $this->expectException(ObjectNotFoundException::class);

        $storage->updateObjectMeta(key: 'missing.txt', meta: ['x' => 1]);
    }

    #[Test]
    #[TestDox('Updating metadata without a mime type keeps the existing mime')]
    public function testUpdateMetaWithoutMimeKeepsExistingMime(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'plain text content');

        $updated = $storage->updateObjectMeta(key: 'a.txt', meta: ['y' => 2]);

        $this->assertSame(['y' => 2], $updated->meta);
        $this->assertSame('text/plain', $updated->mimeType);
        $this->assertNotNull($updated->updatedAt);
    }

    #[Test]
    #[TestDox('Moving an object onto its own key is a no-op')]
    public function testMoveOntoSameKeyIsNoop(): void
    {
        $storage = $this->openStorage();
        $original = $storage->putObject(key: 'doc.txt', content: 'stay put');

        $moved = $storage->moveObject(sourceKey: 'doc.txt', targetKey: 'doc.txt');

        $this->assertSame($original->id, $moved->id);
        $this->assertSame('stay put', $storage->getObject(key: 'doc.txt')->getContents());
        $this->assertSame(1, $storage->getStats()->objectCount);
    }

    #[Test]
    #[TestDox('Inserting a second object with a duplicate id fails')]
    public function testDuplicateObjectIdThrows(): void
    {
        $storage = $this->openStorage();
        $id = '01890a5d-ac96-774b-bcce-b302099a8057';
        $storage->putObject(key: 'first.txt', content: 'first', options: new PutOptions(id: $id));

        $this->expectException(StorageIoException::class);

        $storage->putObject(key: 'second.txt', content: 'second', options: new PutOptions(id: $id));
    }

    #[Test]
    #[TestDox('Opening a schema from a newer version is refused')]
    public function testSchemaFromNewerVersionIsRefused(): void
    {
        $path = $this->databasePath();
        $this->openStorage()->close();

        $raw = new SQLite3(filename: $path);
        $raw->exec(query: "UPDATE storage_info SET value = '999' WHERE key = 'schema_version'");
        $raw->close();

        $this->expectException(StorageIoException::class);

        $this->openStorage();
    }

    #[Test]
    #[TestDox('A schema older than the library is checked again under the write lock and opens as it is')]
    public function testOlderSchemaIsCheckedUnderTheWriteLock(): void
    {
        $path = $this->databasePath();
        $storage = $this->openStorage();
        $storage->putObject(key: 'kept.txt', content: 'kept');
        $storage->close();

        $raw = new SQLite3(filename: $path);
        $raw->exec(query: "UPDATE storage_info SET value = '0' WHERE key = 'schema_version'");
        $raw->close();

        $this->assertSame('kept', $this->openStorage()->getObject(key: 'kept.txt')->getContents());
    }

    #[Test]
    #[TestDox('Opening an uninitialized database read-only is refused')]
    public function testReadOnlyOpenOfUninitializedDatabaseThrows(): void
    {
        $path = $this->databasePath(name: 'empty.db');
        new SQLite3(filename: $path)->close();

        $this->expectException(StorageNotInitializedException::class);

        $this->openStorage(options: new StorageOptions(readOnly: true), name: 'empty.db');
    }

    #[Test]
    #[TestDox('A malformed encryption context is detected on read')]
    public function testMalformedEncryptionContextIsDetected(): void
    {
        $path = $this->databasePath();
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
        $storage->putObject(
            key: 'secret.bin',
            content: 'classified',
            options: new PutOptions(encryption: Encryption::XChaCha20Poly1305)
        );
        $storage->close();

        $raw = new SQLite3(filename: $path);
        // valid JSON, but the required header/chunk_size keys are missing
        $raw->exec(query: "UPDATE contents SET enc_context = '{}'");
        $raw->close();

        $this->expectException(ContentIntegrityException::class);

        $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()))
            ->getObject(key: 'secret.bin');
    }

    #[Test]
    #[TestDox('A malformed encryption header is detected on read')]
    public function testMalformedEncryptionHeaderIsDetected(): void
    {
        $path = $this->databasePath();
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
        $storage->putObject(
            key: 'secret.bin',
            content: 'classified',
            options: new PutOptions(encryption: Encryption::XChaCha20Poly1305)
        );
        $storage->close();

        $raw = new SQLite3(filename: $path);
        // structurally valid context, but the header is not strict base64
        $raw->exec(query: 'UPDATE contents SET enc_context = \'{"header":"!!!not base64!!!","chunk_size":262144}\'');
        $raw->close();

        $this->expectException(ContentIntegrityException::class);

        $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()))
            ->getObject(key: 'secret.bin');
    }
}
