<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Crypto;

use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamDecryptor;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamEncryptor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function str_repeat;
use function strlen;

/**
 * @internal
 */
#[CoversClass(SecretStreamEncryptor::class)]
final class SecretStreamEncryptorTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Header is a non-empty string')]
    public function testHeaderIsNonEmpty(): void
    {
        $encryptor = new SecretStreamEncryptor(key: str_repeat(string: 'A', times: 32));

        $this->assertNotSame('', $encryptor->header());
    }

    #[Test]
    #[TestDox('Encrypting a chunk yields ciphertext that hides and outgrows the plaintext')]
    public function testEncryptChunkProducesAuthenticatedCiphertext(): void
    {
        $encryptor = new SecretStreamEncryptor(key: str_repeat(string: 'A', times: 32));
        $plaintext = 'a secret message';

        $ciphertext = $encryptor->encryptChunk(plaintext: $plaintext, final: false);

        $this->assertNotSame($plaintext, $ciphertext);
        $this->assertGreaterThan(strlen(string: $plaintext), strlen(string: $ciphertext));
    }

    #[Test]
    #[TestDox('A produced stream decrypts back to the original plaintext')]
    public function testProducedStreamDecryptsBack(): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $encryptor = new SecretStreamEncryptor(key: $key);

        $first = $encryptor->encryptChunk(plaintext: 'first chunk ', final: false);
        $last = $encryptor->encryptChunk(plaintext: 'last chunk', final: true);

        $decryptor = new SecretStreamDecryptor(key: $key, header: $encryptor->header());

        $this->assertSame('first chunk ', $decryptor->decryptChunk(ciphertext: $first, final: false));
        $this->assertSame('last chunk', $decryptor->decryptChunk(ciphertext: $last, final: true));
    }
}
