<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\EncodedContent;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(EncodedContent::class)]
final class EncodedContentTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Exposes every constructor argument as a public property')]
    public function testExposesAllProperties(): void
    {
        $encoded = new EncodedContent(
            hash: 'f1d2d2f924e986ac86fdf7b36c94bcdf32beec15',
            size: 1024,
            storedSize: 512,
            mimeType: 'text/plain',
            compression: Compression::Zlib,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionKeyId: 'key-2026',
            encryptionHeader: 'aGVhZGVy',
            chunkSize: 262144
        );

        $this->assertSame('f1d2d2f924e986ac86fdf7b36c94bcdf32beec15', $encoded->hash);
        $this->assertSame(1024, $encoded->size);
        $this->assertSame(512, $encoded->storedSize);
        $this->assertSame('text/plain', $encoded->mimeType);
        $this->assertSame(Compression::Zlib, $encoded->compression);
        $this->assertSame(Encryption::XChaCha20Poly1305, $encoded->encryption);
        $this->assertSame('key-2026', $encoded->encryptionKeyId);
        $this->assertSame('aGVhZGVy', $encoded->encryptionHeader);
        $this->assertSame(262144, $encoded->chunkSize);
    }

    #[Test]
    #[TestDox('Allows null mimeType and encryption context for unencrypted content')]
    public function testAllowsNullableFields(): void
    {
        $encoded = new EncodedContent(
            hash: 'hash',
            size: 0,
            storedSize: 0,
            mimeType: null,
            compression: Compression::None,
            encryption: Encryption::None,
            encryptionKeyId: null,
            encryptionHeader: null,
            chunkSize: 262144
        );

        $this->assertNull($encoded->mimeType);
        $this->assertNull($encoded->encryptionKeyId);
        $this->assertNull($encoded->encryptionHeader);
    }
}
