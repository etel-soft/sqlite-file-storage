<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ObjectAlreadyExistsException;
use Etel\SqliteFileStorage\Exception\ObjectNotFoundException;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use Etel\SqliteFileStorage\WriteMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fstat;
use function fwrite;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function random_bytes;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * @internal
 */
final class PutGetTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Round-trips content for every compression and encryption pair')]
    #[DataProvider('provideCodecMatrix')]
    public function testRoundtrip(Compression $compression, Encryption $encryption): void
    {
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
        $content = random_bytes(length: 700000);

        $info = $storage->putObject(
            key: 'docs/file.bin',
            content: $content,
            options: new PutOptions(compression: $compression, encryption: $encryption)
        );

        $this->assertSame(strlen(string: $content), $info->size);
        $this->assertSame(hash(algo: 'sha256', data: $content), $info->hash);
        $this->assertSame($compression, $info->compression);
        $this->assertSame($encryption, $info->encryption);

        $object = $storage->getObject(key: 'docs/file.bin');

        $this->assertSame($content, $object->getContents());
    }

    /**
     * @return iterable<string, array{Compression, Encryption}>
     */
    public static function provideCodecMatrix(): iterable
    {
        $compressions = [Compression::None, Compression::Deflate, Compression::Gzip, Compression::Zlib];
        $encryptions = [Encryption::None, Encryption::XChaCha20Poly1305];

        foreach ($compressions as $compression) {
            foreach ($encryptions as $encryption) {
                yield $compression->value . ' + ' . $encryption->value => [$compression, $encryption];
            }
        }
    }

    #[Test]
    #[TestDox('Compressible content reduces the stored size')]
    public function testCompressibleContentReducesStoredSize(): void
    {
        $storage = $this->openStorage();
        $content = str_repeat(string: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', times: 30000);

        $info = $storage->putObject(key: 'texts/lorem.txt', content: $content);

        $this->assertSame(Compression::Zlib, $info->compression);
        $this->assertLessThan($info->size, $info->storedSize);
        $this->assertSame($content, $storage->getObject(key: 'texts/lorem.txt')->getContents());
    }

    #[Test]
    #[TestDox('Large content streams through without corruption')]
    public function testLargeContentStreamsThroughWithoutCorruption(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));

        $source = fopen(filename: $this->databasePath(name: 'large-source.bin'), mode: 'w+b');
        $this->assertNotFalse($source);

        $hash = hash_init(algo: 'sha256');

        for ($i = 0; $i < 20; ++$i) {
            $chunk = random_bytes(length: 262155);
            hash_update(context: $hash, data: $chunk);
            fwrite(stream: $source, data: $chunk);
        }

        $expectedHash = hash_final(context: $hash);
        rewind(stream: $source);

        $info = $storage->putObject(
            key: 'big/object.bin',
            content: $source,
            options: new PutOptions(compression: Compression::Zlib, encryption: Encryption::XChaCha20Poly1305)
        );
        fclose(stream: $source);

        $this->assertSame($expectedHash, $info->hash);
        $this->assertSame(262155 * 20, $info->size);

        $body = $storage->getObject(key: 'big/object.bin')->getContentStream();
        $stat = fstat(stream: $body);
        $this->assertNotFalse($stat);
        $this->assertSame($info->size, $stat['size']);

        $readHash = hash_init(algo: 'sha256');

        while (!feof(stream: $body)) {
            $part = fread(stream: $body, length: 65536);
            $this->assertNotFalse($part);
            hash_update(context: $readHash, data: $part);
        }

        fclose(stream: $body);

        $this->assertSame($expectedHash, hash_final(context: $readHash));
    }

    #[Test]
    #[TestDox('Stores and reads back empty content with and without encryption')]
    public function testEmptyContent(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));

        foreach ([Encryption::None, Encryption::XChaCha20Poly1305] as $i => $encryption) {
            $key = 'empty/' . $i;
            $info = $storage->putObject(key: $key, content: '', options: new PutOptions(encryption: $encryption));

            $this->assertSame(0, $info->size);
            $this->assertSame(hash(algo: 'sha256', data: ''), $info->hash);
            $this->assertSame('', $storage->getObject(key: $key)->getContents());
        }
    }

    #[Test]
    #[TestDox('Applies sensible defaults and honours explicit put options')]
    public function testDefaultsAndExplicitOptions(): void
    {
        $storage = $this->openStorage();

        $defaulted = $storage->putObject(key: 'reports/2026/summary.txt', content: 'plain text content');

        $this->assertSame('summary.txt', $defaulted->name);
        $this->assertSame('text/plain', $defaulted->mimeType);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $defaulted->id
        );
        $this->assertSame([], $defaulted->meta);
        $this->assertNull($defaulted->updatedAt);

        $explicit = $storage->putObject(key: 'reports/2026/raw.bin', content: 'data', options: new PutOptions(
            compression: Compression::None,
            id: '01890a5d-ac96-774b-bcce-b302099a8057',
            name: 'original-name.bin',
            mimeType: 'application/x-custom',
            meta: ['owner' => 'mykola', 'version' => 3]
        ));

        $this->assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $explicit->id);
        $this->assertSame('original-name.bin', $explicit->name);
        $this->assertSame('application/x-custom', $explicit->mimeType);
        $this->assertSame(['owner' => 'mykola', 'version' => 3], $explicit->meta);
        $this->assertSame(Compression::None, $explicit->compression);
        $this->assertSame(4, $explicit->storedSize);
    }

    #[Test]
    #[TestDox('Overwrite keeps the object id and creation timestamp')]
    public function testOverwriteKeepsObjectIdentity(): void
    {
        $storage = $this->openStorage();

        $first = $storage->putObject(key: 'doc.txt', content: 'version 1');
        $second = $storage->putObject(key: 'doc.txt', content: 'version 2 content');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->createdAt->getTimestamp(), $second->createdAt->getTimestamp());
        $this->assertNotNull($second->updatedAt);
        $this->assertSame('version 2 content', $storage->getObject(key: 'doc.txt')->getContents());

        // the replaced content is dereferenced and removed
        $this->assertSame(1, $storage->getStats()->contentCount);
    }

    #[Test]
    #[TestDox('CreateOnly write mode rejects an existing key')]
    public function testCreateOnlyRejectsExistingKey(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'doc.txt', content: 'first');

        $this->expectException(ObjectAlreadyExistsException::class);

        $storage->putObject(key: 'doc.txt', content: 'second', options: new PutOptions(
            writeMode: WriteMode::CreateOnly
        ));
    }

    #[Test]
    #[TestDox('head, has and findByHash report object presence')]
    public function testHeadHasAndFindByHash(): void
    {
        $storage = $this->openStorage();
        $info = $storage->putObject(key: 'a.txt', content: 'find me by hash');

        $this->assertTrue($storage->hasObject(key: 'a.txt'));
        $this->assertFalse($storage->hasObject(key: 'b.txt'));
        $this->assertSame($info->id, $storage->headObject(key: 'a.txt')->id);

        $found = $storage->findByHash(hash: $info->hash);

        $this->assertNotNull($found);
        $this->assertSame('a.txt', $found->key);
        $this->assertNull($storage->findByHash(hash: hash(algo: 'sha256', data: 'something else')));
    }

    #[Test]
    #[TestDox('Getting a missing object throws')]
    public function testGetMissingObjectThrows(): void
    {
        $storage = $this->openStorage();

        $this->expectException(ObjectNotFoundException::class);

        $storage->getObject(key: 'missing.txt');
    }

    #[Test]
    #[TestDox('Updates object metadata and then deletes the object')]
    public function testUpdateObjectMetaAndDelete(): void
    {
        $storage = $this->openStorage();
        $storage->putObject(key: 'a.txt', content: 'content', options: new PutOptions(meta: ['x' => 1]));

        $updated = $storage->updateObjectMeta(key: 'a.txt', meta: ['y' => 2], mimeType: 'text/markdown');

        $this->assertSame(['y' => 2], $updated->meta);
        $this->assertSame('text/markdown', $updated->mimeType);
        $this->assertNotNull($updated->updatedAt);

        $storage->deleteObject(key: 'a.txt');

        $this->assertFalse($storage->hasObject(key: 'a.txt'));
        $this->assertSame(0, $storage->getStats()->contentCount);

        $this->expectException(ObjectNotFoundException::class);

        $storage->deleteObject(key: 'a.txt');
    }
}
