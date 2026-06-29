<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function str_repeat;

/**
 * @internal
 */
#[CoversClass(ArrayKeyProvider::class)]
final class ArrayKeyProviderTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Returns the configured current key id')]
    public function testReturnsCurrentKeyId(): void
    {
        $provider = new ArrayKeyProvider(
            keys: ['key-2026' => str_repeat(string: 'A', times: 32)],
            currentKeyId: 'key-2026'
        );

        $this->assertSame('key-2026', $provider->getCurrentKeyId());
    }

    #[Test]
    #[TestDox('Returns the stored key material for a known id')]
    public function testReturnsStoredKey(): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $provider = new ArrayKeyProvider(keys: ['main' => $key], currentKeyId: 'main');

        $this->assertSame($key, $provider->getKey(keyId: 'main'));
    }

    #[Test]
    #[TestDox('Throws when an unknown key id is requested')]
    public function testGetUnknownKeyThrows(): void
    {
        $provider = new ArrayKeyProvider(keys: ['main' => str_repeat(string: 'A', times: 32)], currentKeyId: 'main');

        $this->expectException(EncryptionKeyNotFoundException::class);

        $provider->getKey(keyId: 'missing');
    }

    #[Test]
    #[TestDox('Throws when the current key id is absent from the key set')]
    public function testCurrentKeyIdMustExist(): void
    {
        $this->expectException(EncryptionKeyNotFoundException::class);

        new ArrayKeyProvider(keys: ['main' => str_repeat(string: 'A', times: 32)], currentKeyId: 'other');
    }
}
