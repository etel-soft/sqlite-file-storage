<?php

/* @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\ObjectNotFoundException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Exception\StorageNotInitializedException;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\SqliteStorage;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use SQLite3;

use const NAN;
use const PHP_INT_MAX;

use function fclose;
use function iterator_to_array;
use function random_bytes;

/**
 * Edge cases of the SQLite storage engine that the happy-path integration tests do not exercise: validation guards,
 * schema/version checks and content rows deliberately corrupted out-of-band.
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

    #[Test]
    #[TestDox('A context that is no JSON fails the read of its object only: metadata reads and writes go on')]
    public function testBrokenEncryptionContextFailsItsReadOnly(): void
    {
        $path = $this->databasePath();
        $options = new PutOptions(encryption: Encryption::XChaCha20Poly1305, associatedData: 'bucket/a');
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
        $info = $storage->putObject(key: 'broken.bin', content: 'classified', options: $options);
        $storage->close();

        $raw = new SQLite3(filename: $path);
        $raw->exec(query: "UPDATE contents SET enc_context = '{broken'");
        $raw->close();

        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));

        $this->assertFalse($storage->headObject(key: 'broken.bin')->boundToAssociatedData);
        $this->assertCount(1, iterator_to_array(iterator: $storage->listObjects(), preserve_keys: false));
        $this->assertSame('broken.bin', $storage->findByHash(hash: $info->hash)?->key);

        // the same content is not shared with the broken record, but written anew, and then shared
        $storage->putObject(key: 'second.bin', content: 'classified', options: $options);
        $storage->putObject(key: 'third.bin', content: 'classified', options: $options);
        $this->assertSame(2, $storage->getStats()->contentCount);
        $third = $storage->getObject(key: 'third.bin', associatedData: 'bucket/a');
        $this->assertSame('classified', $third->getContents());

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('malformed encryption context');

        $storage->getObject(key: 'broken.bin', associatedData: 'bucket/a');
    }

    #[Test]
    #[TestDox('A write SQLite rolls back itself (a full database) fails with its cause, and leaves no trace')]
    public function testWriteRolledBackBySqliteFailsWithItsCause(): void
    {
        $this->openStorage()->close();
        $db = new SQLite3(filename: $this->databasePath());
        $db->enableExceptions(enable: true);
        $storage = new SqliteStorage(db: $db, options: new StorageOptions());
        $storage->putObject(key: 'kept.txt', content: 'kept');
        $db->exec(query: 'PRAGMA max_page_count = 40');

        try {
            $storage->putObject(
                key: 'large.bin',
                content: random_bytes(length: 4 * 1024 * 1024),
                options: new PutOptions(compression: Compression::None)
            );
            $this->fail('A write past the size of the database went through.');
        } catch (StorageIoException $exception) {
            $this->assertStringContainsString('full', $exception->getMessage());
        }

        $this->assertFalse($storage->hasObject(key: 'large.bin'));
        $this->assertSame(1, $storage->getStats()->contentCount);
        $storage->putObject(key: 'small.txt', content: 'small');
        $this->assertSame('small', $storage->getObject(key: 'small.txt')->getContents());
        $storage->close();
    }

    #[Test]
    #[TestDox('A key with a NUL byte is rejected wherever a key is written: SQLite would give it back cut')]
    public function testKeyWithNulByteIsRejected(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a', content: 'data');

        foreach (
            [
                static fn () => $storage->putObject(key: "a\0b", content: 'data'),
                static fn () => $storage->copyObject(sourceKey: 'a', targetKey: "a\0b"),
                static fn () => $storage->moveObject(sourceKey: 'a', targetKey: "a\0b"),
            ] as $write
        ) {
            try {
                $write();
                $this->fail('A key with a NUL byte was written.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('NUL byte', $exception->getMessage());
            }
        }

        $this->assertSame(1, $storage->getStats()->objectCount);
    }

    #[Test]
    #[TestDox('An id, a name and a mime type with a NUL byte are rejected')]
    #[TestWith(['id'])]
    #[TestWith(['name'])]
    #[TestWith(['mimeType'])]
    public function testTextWithNulByteIsRejected(string $field): void
    {
        $storage = $this->openStorage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('NUL byte');

        $storage->putObject(
            key: 'a',
            content: 'data',
            options: match ($field) {
                'id' => new PutOptions(id: "x\0y"),
                'name' => new PutOptions(name: "x\0y"),
                default => new PutOptions(mimeType: "x\0y"),
            }
        );
    }

    #[Test]
    #[TestDox('Meta that cannot be stored is refused before anything is written')]
    public function testMetaThatCannotBeEncodedIsRefused(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'kept', content: 'data', options: new PutOptions(meta: ['a' => 1]));

        foreach (
            [
                static fn () => $storage->putObject(
                    key: 'new',
                    content: 'data',
                    options: new PutOptions(meta: ['a' => "\xFF"])
                ),
                static fn () => $storage->updateObjectMeta(key: 'kept', meta: ['a' => NAN]),
            ] as $write
        ) {
            try {
                $write();
                $this->fail('Meta that cannot be encoded was stored.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('meta', $exception->getMessage());
            }
        }

        $this->assertFalse($storage->hasObject(key: 'new'));
        $this->assertSame(['a' => 1], $storage->headObject(key: 'kept')->meta);
        $this->assertSame(1, $storage->getStats()->contentCount);
    }

    #[Test]
    #[TestDox('A meta that is no JSON reads as none, and fails neither its object nor a listing')]
    public function testBrokenMetaReadsAsNone(): void
    {
        $path = $this->databasePath();
        $storage = $this->openStorage();
        $storage->putObject(key: 'broken.txt', content: 'data', options: new PutOptions(meta: ['a' => 1]));
        $storage->putObject(key: 'whole.txt', content: 'other', options: new PutOptions(meta: ['b' => 2]));
        $storage->close();

        $raw = new SQLite3(filename: $path);
        $raw->exec(query: "UPDATE objects SET meta = '{broken' WHERE key = 'broken.txt'");
        $raw->close();

        $storage = $this->openStorage();
        $listed = iterator_to_array(iterator: $storage->listObjects(), preserve_keys: false);

        $this->assertCount(2, $listed);
        $this->assertSame([], $listed[0]->meta);
        $this->assertSame(['b' => 2], $listed[1]->meta);
        $this->assertSame('data', $storage->getObject(key: 'broken.txt')->getContents());
    }

    #[Test]
    #[TestDox('A chunk size out of range in a record is a malformed context, not a chunk to read')]
    #[TestWith([-17])]
    #[TestWith([0])]
    #[TestWith([1_099_511_627_776])]
    #[TestWith([PHP_INT_MAX])]
    public function testChunkSizeOutOfRangeIsDetected(int $chunkSize): void
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
        $raw->exec(
            query: "UPDATE contents SET enc_context = json_set(enc_context, '$.chunk_size', {$chunkSize})"
        );
        $raw->close();

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('malformed encryption context');

        $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()))
            ->getObject(key: 'secret.bin');
    }

    #[Test]
    #[TestDox('Content cut short in the record does not read as whole, compressed or not')]
    #[TestWith([Compression::None])]
    #[TestWith([Compression::Zlib])]
    public function testContentCutShortIsDetected(Compression $compression): void
    {
        $path = $this->databasePath();
        $storage = $this->openStorage();
        $storage->putObject(
            key: 'cut.bin',
            content: random_bytes(length: 4096),
            options: new PutOptions(compression: $compression)
        );
        $storage->close();

        $raw = new SQLite3(filename: $path);
        $raw->exec(query: 'UPDATE contents SET stored_size = stored_size - 20');
        $raw->close();

        $object = $this->openStorage()->getObject(key: 'cut.bin');

        $this->expectException(ContentIntegrityException::class);

        $object->getContents();
    }

    #[Test]
    #[TestDox('An encrypted record made empty out-of-band fails authentication instead of reading as empty')]
    #[TestWith(['bound to its owner'])]
    #[TestWith([''])]
    public function testEncryptedContentMadeEmptyIsDetected(string $associatedData): void
    {
        $path = $this->databasePath();
        $options = new StorageOptions(keyProvider: $this->keyProvider());
        $storage = $this->openStorage(options: $options);
        $storage->putObject(
            key: 'secret.bin',
            content: 'classified',
            options: new PutOptions(encryption: Encryption::XChaCha20Poly1305, associatedData: $associatedData)
        );
        $storage->close();

        $raw = new SQLite3(filename: $path);
        $raw->exec(query: 'UPDATE contents SET stored_size = 0, size = 0');
        $raw->close();

        $object = $this->openStorage(options: $options)->getObject(key: 'secret.bin', associatedData: $associatedData);

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('the final chunk that authenticates it is missing');

        $object->getContents();
    }

    #[Test]
    #[TestDox('Closing a storage whose content stream is still open fails as a storage error')]
    public function testCloseWithOpenContentStreamFailsAsStorageError(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a', content: 'data');
        $stream = $storage->getObject(key: 'a')->getContentStream();

        try {
            $storage->close();
            $this->fail('A storage with an open content stream was closed.');
        } catch (StorageIoException $exception) {
            $this->assertStringContainsString('SQLite error', $exception->getMessage());
        }

        fclose(stream: $stream);
        $storage->close();
    }
}
