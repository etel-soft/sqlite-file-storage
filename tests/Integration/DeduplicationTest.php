<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function random_bytes;

/**
 * @internal
 */
final class DeduplicationTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Stores identical content once while keeping distinct object identities')]
    public function testIdenticalContentIsStoredOnce(): void
    {
        $storage = $this->openStorage();

        $first = $storage->putObject(key: 'a/one.txt', content: 'shared content');
        $second = $storage->putObject(key: 'b/two.txt', content: 'shared content');

        $this->assertSame($first->hash, $second->hash);
        $this->assertNotSame($first->id, $second->id);

        $stats = $storage->getStats();

        $this->assertSame(2, $stats->objectCount);
        $this->assertSame(1, $stats->contentCount);
        $this->assertSame($first->size * 2, $stats->totalSize);
        $this->assertSame($first->storedSize, $stats->totalStoredSize);
    }

    #[Test]
    #[TestDox('Keeps shared content until its last referencing object is deleted')]
    public function testContentSurvivesUntilLastReferenceIsDeleted(): void
    {
        $storage = $this->openStorage();

        $storage->putObject(key: 'a.txt', content: 'shared content');
        $storage->putObject(key: 'b.txt', content: 'shared content');

        $storage->deleteObject(key: 'a.txt');

        $this->assertSame('shared content', $storage->getObject(key: 'b.txt')->getContents());
        $this->assertSame(1, $storage->getStats()->contentCount);

        $storage->deleteObject(key: 'b.txt');

        $stats = $storage->getStats();

        $this->assertSame(0, $stats->objectCount);
        $this->assertSame(0, $stats->contentCount);
    }

    #[Test]
    #[TestDox('Stores content twice when deduplication is disabled per put')]
    public function testDeduplicationCanBeDisabled(): void
    {
        $storage = $this->openStorage();

        $storage->putObject(key: 'a.txt', content: 'shared content');
        $storage->putObject(key: 'b.txt', content: 'shared content', options: new PutOptions(deduplicate: false));

        $this->assertSame(2, $storage->getStats()->contentCount);
    }

    #[Test]
    #[TestDox('Does not deduplicate encrypted content against its plaintext twin')]
    public function testEncryptedAndPlainContentAreNotDeduplicatedTogether(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));

        $storage->putObject(key: 'plain.txt', content: 'shared content');
        $storage->putObject(
            key: 'secret.txt',
            content: 'shared content',
            options: new PutOptions(encryption: Encryption::XChaCha20Poly1305)
        );

        $this->assertSame(2, $storage->getStats()->contentCount);
        $this->assertSame('shared content', $storage->getObject(key: 'secret.txt')->getContents());
    }

    #[Test]
    #[TestDox('Copy shares the source content and survives the source deletion')]
    public function testCopySharesContent(): void
    {
        $storage = $this->openStorage();

        $source = $storage->putObject(key: 'src.txt', content: 'copy me', options: new PutOptions(meta: ['k' => 'v']));
        $copy = $storage->copyObject(sourceKey: 'src.txt', targetKey: 'dst.txt');

        $this->assertSame($source->hash, $copy->hash);
        $this->assertSame(['k' => 'v'], $copy->meta);
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame(1, $storage->getStats()->contentCount);
        $this->assertSame('copy me', $storage->getObject(key: 'dst.txt')->getContents());

        $storage->deleteObject(key: 'src.txt');

        $this->assertSame('copy me', $storage->getObject(key: 'dst.txt')->getContents());
    }

    #[Test]
    #[TestDox('Copy overwrites an existing target object')]
    public function testCopyOverwritesTarget(): void
    {
        $storage = $this->openStorage();

        $storage->putObject(key: 'src.txt', content: 'new content');
        $storage->putObject(key: 'dst.txt', content: 'old content');

        $storage->copyObject(sourceKey: 'src.txt', targetKey: 'dst.txt');

        $this->assertSame('new content', $storage->getObject(key: 'dst.txt')->getContents());
        $this->assertSame(1, $storage->getStats()->contentCount);
    }

    #[Test]
    #[TestDox('Move renames an object while preserving its identity')]
    public function testMoveRenamesObject(): void
    {
        $storage = $this->openStorage();

        $source = $storage->putObject(key: 'old/path.txt', content: 'movable');
        $moved = $storage->moveObject(sourceKey: 'old/path.txt', targetKey: 'new/path.txt');

        $this->assertSame($source->id, $moved->id);
        $this->assertFalse($storage->hasObject(key: 'old/path.txt'));
        $this->assertSame('movable', $storage->getObject(key: 'new/path.txt')->getContents());
    }

    #[Test]
    #[TestDox('Move overwrites an existing target object')]
    public function testMoveOverwritesTarget(): void
    {
        $storage = $this->openStorage();

        $storage->putObject(key: 'src.txt', content: 'fresh');
        $storage->putObject(key: 'dst.txt', content: 'stale');

        $storage->moveObject(sourceKey: 'src.txt', targetKey: 'dst.txt');

        $stats = $storage->getStats();

        $this->assertSame(1, $stats->objectCount);
        $this->assertSame(1, $stats->contentCount);
        $this->assertSame('fresh', $storage->getObject(key: 'dst.txt')->getContents());
    }

    #[Test]
    #[TestDox('Vacuum reclaims space after deletions')]
    public function testVacuumAfterDeletes(): void
    {
        $storage = $this->openStorage();

        $storage->putObject(key: 'temp.bin', content: random_bytes(length: 1048576), options: new PutOptions(
            compression: Compression::None
        ));
        $storage->deleteObject(key: 'temp.bin');
        $storage->vacuum();

        $this->assertSame(0, $storage->getStats()->objectCount);
    }
}
