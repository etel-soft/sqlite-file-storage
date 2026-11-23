<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\AutoCompression;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Durability;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function str_repeat;

/**
 * @internal
 */
#[CoversClass(StorageOptions::class)]
final class StorageOptionsTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Applies the documented defaults')]
    public function testDefaults(): void
    {
        $options = new StorageOptions();

        $this->assertSame(Compression::Zlib, $options->defaultCompression);
        $this->assertSame(Encryption::None, $options->defaultEncryption);
        $this->assertNull($options->keyProvider);
        $this->assertTrue($options->createIfNotExists);
        $this->assertSame(5000, $options->busyTimeoutMs);
        $this->assertFalse($options->readOnly);
        $this->assertSame(Durability::Buffered, $options->durability);
        $this->assertSame(67_108_864, $options->journalSizeLimitBytes);
        $this->assertNull($options->hashKeyId);
    }

    #[Test]
    #[TestDox('Exposes every explicitly provided value')]
    public function testCustomValues(): void
    {
        $keyProvider = new ArrayKeyProvider(keys: ['main' => str_repeat(string: 'A', times: 32)], currentKeyId: 'main');
        $options = new StorageOptions(
            defaultCompression: Compression::Brotli,
            defaultEncryption: Encryption::XChaCha20Poly1305,
            keyProvider: $keyProvider,
            createIfNotExists: false,
            busyTimeoutMs: 1000,
            readOnly: true,
            durability: Durability::Synced,
            journalSizeLimitBytes: -1,
            hashKeyId: 'hash'
        );

        $this->assertSame('hash', $options->hashKeyId);

        $this->assertSame(Compression::Brotli, $options->defaultCompression);
        $this->assertSame(Encryption::XChaCha20Poly1305, $options->defaultEncryption);
        $this->assertSame($keyProvider, $options->keyProvider);
        $this->assertFalse($options->createIfNotExists);
        $this->assertSame(1000, $options->busyTimeoutMs);
        $this->assertTrue($options->readOnly);
        $this->assertSame(Durability::Synced, $options->durability);
        $this->assertSame(-1, $options->journalSizeLimitBytes);
    }

    #[Test]
    #[TestDox('Takes a request to decide compression per object as its default')]
    public function testAutoCompressionAsDefault(): void
    {
        $auto = new AutoCompression(algorithm: Compression::Gzip);

        $this->assertSame($auto, new StorageOptions(defaultCompression: $auto)->defaultCompression);
    }
}
