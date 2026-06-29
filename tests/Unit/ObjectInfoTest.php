<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use DateTimeImmutable;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\ObjectInfo;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(ObjectInfo::class)]
final class ObjectInfoTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Exposes every constructor argument as a public property')]
    public function testExposesAllProperties(): void
    {
        $createdAt = new DateTimeImmutable(datetime: '2026-01-02 03:04:05');
        $updatedAt = new DateTimeImmutable(datetime: '2026-06-07 08:09:10');

        $info = new ObjectInfo(
            id: '01890a5d-ac96-774b-bcce-b302099a8057',
            key: 'invoices/2026/0001.pdf',
            name: '0001.pdf',
            mimeType: 'application/pdf',
            hash: 'f1d2d2f924e986ac86fdf7b36c94bcdf32beec15',
            size: 1024,
            storedSize: 512,
            compression: Compression::Zlib,
            encryption: Encryption::XChaCha20Poly1305,
            meta: ['owner' => 'mykola'],
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );

        $this->assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $info->id);
        $this->assertSame('invoices/2026/0001.pdf', $info->key);
        $this->assertSame('0001.pdf', $info->name);
        $this->assertSame('application/pdf', $info->mimeType);
        $this->assertSame('f1d2d2f924e986ac86fdf7b36c94bcdf32beec15', $info->hash);
        $this->assertSame(1024, $info->size);
        $this->assertSame(512, $info->storedSize);
        $this->assertSame(Compression::Zlib, $info->compression);
        $this->assertSame(Encryption::XChaCha20Poly1305, $info->encryption);
        $this->assertSame(['owner' => 'mykola'], $info->meta);
        $this->assertSame($createdAt, $info->createdAt);
        $this->assertSame($updatedAt, $info->updatedAt);
    }

    #[Test]
    #[TestDox('Allows a null mimeType and updatedAt')]
    public function testAllowsNullableFields(): void
    {
        $info = new ObjectInfo(
            id: 'id',
            key: 'key',
            name: 'name',
            mimeType: null,
            hash: 'hash',
            size: 0,
            storedSize: 0,
            compression: Compression::None,
            encryption: Encryption::None,
            meta: [],
            createdAt: new DateTimeImmutable(datetime: '2026-01-01'),
            updatedAt: null
        );

        $this->assertNull($info->mimeType);
        $this->assertNull($info->updatedAt);
    }
}
