<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\EncryptionMap;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(EncryptionMap::class)]
final class EncryptionMapTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Maps every Encryption case to its stored integer and back')]
    #[DataProvider('provideMapping')]
    public function testRoundtrip(Encryption $encryption, int $stored): void
    {
        $this->assertSame($stored, EncryptionMap::fromDomain(encryption: $encryption));
        $this->assertSame($encryption, EncryptionMap::toDomain(value: $stored));
    }

    /**
     * @return iterable<string, array{Encryption, int}>
     */
    public static function provideMapping(): iterable
    {
        yield 'none' => [Encryption::None, 0];

        yield 'xchacha20poly1305' => [Encryption::XChaCha20Poly1305, 1];
    }

    #[Test]
    #[TestDox('Throws for an unknown stored integer')]
    public function testUnknownStoredValueThrows(): void
    {
        $this->expectException(StorageIoException::class);

        EncryptionMap::toDomain(value: 99);
    }
}
