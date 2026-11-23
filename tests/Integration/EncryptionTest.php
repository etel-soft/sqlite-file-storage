<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use const SEEK_END;

use function fread;
use function fseek;
use function random_bytes;
use function stream_get_contents;
use function substr;

/**
 * @internal
 */
final class EncryptionTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Throws when encryption is requested without a key provider')]
    public function testPutWithoutKeyProviderThrows(): void
    {
        $storage = $this->openStorage();

        $this->expectException(EncryptionKeyNotFoundException::class);

        $storage->putObject(key: 'secret.txt', content: 'data', options: new PutOptions(
            encryption: Encryption::XChaCha20Poly1305
        ));
    }

    #[Test]
    #[TestDox('Throws when the configured key has an invalid length')]
    public function testInvalidKeyLengthThrows(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(
            keyProvider: new ArrayKeyProvider(keys: ['short' => 'too-short'], currentKeyId: 'short')
        ));

        $this->expectException(InvalidEncryptionKeyException::class);

        $storage->putObject(key: 'secret.txt', content: 'data', options: new PutOptions(
            encryption: Encryption::XChaCha20Poly1305
        ));
    }

    #[Test]
    #[TestDox('Reading with the wrong key fails the integrity check')]
    public function testReadingWithWrongKeyFailsIntegrityCheck(): void
    {
        $writer = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));

        $writer->putObject(key: 'secret.txt', content: 'top secret', options: new PutOptions(
            encryption: Encryption::XChaCha20Poly1305
        ));

        $writer->close();

        $reader = $this->openStorage(options: new StorageOptions(
            keyProvider: new ArrayKeyProvider(keys: ['main' => $this->encryptionKey(seed: 'B')], currentKeyId: 'main')
        ));

        $this->expectException(ContentIntegrityException::class);

        $reader->getObject(key: 'secret.txt')->getContents();
    }

    #[Test]
    #[TestDox('Key rotation keeps objects written with the old key readable')]
    public function testKeyRotationKeepsOldObjectsReadable(): void
    {
        $oldProvider = new ArrayKeyProvider(
            keys: ['key-2025' => $this->encryptionKey(seed: 'O')],
            currentKeyId: 'key-2025'
        );
        $storage = $this->openStorage(options: new StorageOptions(
            defaultEncryption: Encryption::XChaCha20Poly1305,
            keyProvider: $oldProvider
        ));
        $storage->putObject(key: 'old.txt', content: 'written with the old key');
        $storage->close();

        $rotatedProvider = new ArrayKeyProvider(
            keys: ['key-2025' => $this->encryptionKey(seed: 'O'), 'key-2026' => $this->encryptionKey(seed: 'N')],
            currentKeyId: 'key-2026'
        );
        $rotated = $this->openStorage(options: new StorageOptions(
            defaultEncryption: Encryption::XChaCha20Poly1305,
            keyProvider: $rotatedProvider
        ));
        $rotated->putObject(key: 'new.txt', content: 'written with the new key');

        $this->assertSame('written with the old key', $rotated->getObject(key: 'old.txt')->getContents());
        $this->assertSame('written with the new key', $rotated->getObject(key: 'new.txt')->getContents());
    }

    #[Test]
    #[TestDox('Throws when the key that encrypted an object is no longer available')]
    public function testMissingRotatedKeyThrows(): void
    {
        $writer = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));

        $writer->putObject(key: 'secret.txt', content: 'data', options: new PutOptions(
            encryption: Encryption::XChaCha20Poly1305
        ));

        $writer->close();

        $reader = $this->openStorage(options: new StorageOptions(
            keyProvider: new ArrayKeyProvider(keys: ['other' => $this->encryptionKey()], currentKeyId: 'other')
        ));

        $this->expectException(EncryptionKeyNotFoundException::class);

        $reader->getObject(key: 'secret.txt');
    }

    #[Test]
    #[TestDox('Encrypted content seeks forward and back, bound or not, over its stored chunks')]
    public function testEncryptedContentSeeks(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
        $content = random_bytes(length: 900_000);
        $storage->putObject(key: 'secret.bin', content: $content, options: new PutOptions(
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            associatedData: 'bucket/secret'
        ));
        $stream = $storage->getObject(key: 'secret.bin', associatedData: 'bucket/secret')->getContentStream();

        $this->assertSame(0, fseek(stream: $stream, offset: 600_000));
        $this->assertSame(substr(string: $content, offset: 600_000, length: 999), fread(stream: $stream, length: 999));

        $this->assertSame(0, fseek(stream: $stream, offset: 100));
        $this->assertSame(substr(string: $content, offset: 100, length: 1000), fread(stream: $stream, length: 1000));

        $this->assertSame(0, fseek(stream: $stream, offset: -50, whence: SEEK_END));
        $this->assertSame(substr(string: $content, offset: -50), stream_get_contents(stream: $stream));
    }

    #[Test]
    #[TestDox('Content stored as it is seeks straight in its stored bytes')]
    public function testRawContentSeeks(): void
    {
        $storage = $this->openStorage();
        $content = random_bytes(length: 900_000);
        $storage->putObject(key: 'raw.bin', content: $content, options: new PutOptions(compression: Compression::None));
        $stream = $storage->getObject(key: 'raw.bin')->getContentStream();

        $this->assertSame(0, fseek(stream: $stream, offset: 700_000));
        $this->assertSame(substr(string: $content, offset: 700_000, length: 10), fread(stream: $stream, length: 10));
        $this->assertSame(0, fseek(stream: $stream, offset: 5));
        $this->assertSame(substr(string: $content, offset: 5, length: 10), fread(stream: $stream, length: 10));
    }
}
