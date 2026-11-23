<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Crypto;

use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(PassthroughCrypto::class)]
final class PassthroughCryptoTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Encrypting a chunk returns the plaintext unchanged')]
    public function testEncryptChunkReturnsInput(): void
    {
        $crypto = new PassthroughCrypto();

        $this->assertSame('payload', $crypto->encryptChunk(plaintext: 'payload', final: false));
        $this->assertSame('payload', $crypto->encryptChunk(plaintext: 'payload', final: true));
    }

    #[Test]
    #[TestDox('Decrypting a chunk returns the ciphertext unchanged')]
    public function testDecryptChunkReturnsInput(): void
    {
        $crypto = new PassthroughCrypto();

        $this->assertSame('payload', $crypto->decryptChunk(ciphertext: 'payload', final: false));
        $this->assertSame('payload', $crypto->decryptChunk(ciphertext: 'payload', final: true));
    }

    #[Test]
    #[TestDox('Header is empty')]
    public function testHeaderIsEmpty(): void
    {
        $this->assertSame('', new PassthroughCrypto()->header());
    }

    #[Test]
    #[TestDox('Restarted, it is itself: it keeps no state')]
    public function testRestartedIsItself(): void
    {
        $crypto = new PassthroughCrypto();

        $this->assertSame($crypto, $crypto->restarted());
    }
}
