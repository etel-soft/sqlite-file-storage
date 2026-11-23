<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Throwable;

use function hash;

/**
 * @internal
 */
final class ContentHashKeyTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Without a hash key the hash stays the SHA-256 of the content')]
    public function testWithoutHashKeyTheHashIsSha256(): void
    {
        $storage = $this->openStorage();

        $info = $storage->putObject(key: 'a', content: 'known file');

        $this->assertSame(hash(algo: 'sha256', data: 'known file'), $info->hash);
        $this->assertSame($info->hash, $storage->contentHash(content: 'known file'));
    }

    #[Test]
    #[TestDox('A storage written without a hash key shares content with what is written after it is opened again')]
    public function testExistingStorageKeepsDeduplicating(): void
    {
        $first = $this->openStorage();
        $first->putObject(key: 'a', content: 'shared content');
        $first->close();

        $second = $this->openStorage();
        $info = $second->putObject(key: 'b', content: 'shared content');

        $this->assertSame(1, $second->getStats()->contentCount);
        $this->assertSame('a', $second->findByHash(hash: $info->hash)?->key);
    }

    #[Test]
    #[TestDox('With a hash key the hash is not the SHA-256 of the content, and the content reads back')]
    public function testWithHashKeyTheHashIsKeyed(): void
    {
        $storage = $this->openStorage(options: $this->keyedOptions());

        $info = $storage->putObject(key: 'a', content: 'known file');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $info->hash);
        $this->assertNotSame(hash(algo: 'sha256', data: 'known file'), $info->hash);
        $this->assertSame($info->hash, $storage->headObject(key: 'a')->hash);
        $this->assertSame('known file', $storage->getObject(key: 'a')->getContents());
    }

    #[Test]
    #[TestDox('With a hash key content is found by the hash the storage gives it, not by its SHA-256')]
    public function testFindByHashTakesTheStorageHash(): void
    {
        $storage = $this->openStorage(options: $this->keyedOptions());
        $storage->putObject(key: 'a', content: 'known file');

        $this->assertSame('a', $storage->findByHash(hash: $storage->contentHash(content: 'known file'))?->key);
        /* @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertNull($storage->findByHash(hash: hash(algo: 'sha256', data: 'known file')));
    }

    #[Test]
    #[TestDox('With a hash key identical content is still stored once')]
    public function testDeduplicationWorksWithHashKey(): void
    {
        $storage = $this->openStorage(options: $this->keyedOptions());

        $first = $storage->putObject(key: 'a', content: 'shared content');
        $second = $storage->putObject(key: 'b', content: 'shared content');

        $this->assertSame($first->hash, $second->hash);
        $this->assertSame(1, $storage->getStats()->contentCount);
    }

    #[Test]
    #[TestDox('Another hash key gives the same content another hash')]
    public function testHashDiffersByKey(): void
    {
        $one = $this->openStorage(options: $this->keyedOptions(), name: 'one.db');
        $other = $this->openStorage(options: $this->keyedOptions(hashKeySeed: 'I'), name: 'other.db');

        $this->assertNotSame($one->contentHash(content: 'known file'), $other->contentHash(content: 'known file'));
    }

    #[Test]
    #[TestDox(
        'A hash key set on a storage that holds content: old content keeps its hash and reads, new is not shared'
    )]
    public function testHashKeyAddedToAStorageWithContent(): void
    {
        $plain = $this->openStorage(options: $this->keyedOptions(hashKeyId: null));
        $old = $plain->putObject(key: 'old', content: 'shared content');
        $plain->close();

        $keyed = $this->openStorage(options: $this->keyedOptions());
        $new = $keyed->putObject(key: 'new', content: 'shared content');

        $this->assertSame($old->hash, $keyed->headObject(key: 'old')->hash);
        $this->assertNotSame($old->hash, $new->hash);
        $this->assertSame(2, $keyed->getStats()->contentCount);
        $this->assertSame('shared content', $keyed->getObject(key: 'old')->getContents());
        $this->assertSame('shared content', $keyed->getObject(key: 'new')->getContents());
    }

    #[Test]
    #[TestDox('A hash key without a key provider is refused on write, and nothing is stored under a bare hash')]
    public function testHashKeyWithoutProviderIsRefused(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(hashKeyId: 'hash'));

        $refusal = null;

        try {
            $storage->putObject(key: 'a', content: 'known file');
        } catch (Throwable $exception) {
            $refusal = $exception;
        }

        $this->assertInstanceOf(EncryptionKeyNotFoundException::class, $refusal);
        $this->assertFalse($storage->hasObject(key: 'a'));
        $this->assertSame(0, $storage->getStats()->contentCount);
    }

    private function keyedOptions(?string $hashKeyId = 'hash', string $hashKeySeed = 'H'): StorageOptions
    {
        return new StorageOptions(
            defaultEncryption: Encryption::XChaCha20Poly1305,
            keyProvider: new ArrayKeyProvider(
                keys: ['content' => $this->encryptionKey(), 'hash' => $this->encryptionKey(seed: $hashKeySeed)],
                currentKeyId: 'content'
            ),
            hashKeyId: $hashKeyId
        );
    }
}
