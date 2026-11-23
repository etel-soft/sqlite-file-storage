<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Crypto;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamDecryptor;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamEncryptor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;

use function str_repeat;

/**
 * @internal
 */
#[CoversClass(SecretStreamDecryptor::class)]
final class SecretStreamDecryptorTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Round-trips ciphertext produced by the encryptor with the same key')]
    public function testRoundtrip(): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $encryptor = new SecretStreamEncryptor(key: $key);
        $ciphertext = $encryptor->encryptChunk(plaintext: 'top secret', final: true);

        $decryptor = new SecretStreamDecryptor(key: $key, header: $encryptor->header());

        $this->assertSame('top secret', $decryptor->decryptChunk(ciphertext: $ciphertext, final: true));
    }

    #[Test]
    #[TestDox('Constructing with a malformed header throws ContentIntegrityException')]
    public function testMalformedHeaderThrows(): void
    {
        $this->expectException(ContentIntegrityException::class);

        new SecretStreamDecryptor(key: str_repeat(string: 'A', times: 32), header: 'not-a-valid-header');
    }

    #[Test]
    #[TestDox('Decrypting with the wrong key fails the integrity check')]
    public function testWrongKeyFailsIntegrity(): void
    {
        $encryptor = new SecretStreamEncryptor(key: str_repeat(string: 'A', times: 32));
        $ciphertext = $encryptor->encryptChunk(plaintext: 'top secret', final: true);

        $decryptor = new SecretStreamDecryptor(key: str_repeat(string: 'B', times: 32), header: $encryptor->header());

        $this->expectException(ContentIntegrityException::class);

        $decryptor->decryptChunk(ciphertext: $ciphertext, final: true);
    }

    #[Test]
    #[TestDox('A final-tag mismatch is detected as truncation or trailing data')]
    public function testFinalTagMismatchThrows(): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $encryptor = new SecretStreamEncryptor(key: $key);
        $nonFinal = $encryptor->encryptChunk(plaintext: 'first chunk', final: false);

        $decryptor = new SecretStreamDecryptor(key: $key, header: $encryptor->header());

        $this->expectException(ContentIntegrityException::class);

        $decryptor->decryptChunk(ciphertext: $nonFinal, final: true);
    }

    #[Test]
    #[TestDox('A stream bound to associated data fails its first chunk with other data, or none')]
    #[TestWith(['other/ref'])]
    #[TestWith([''])]
    public function testOtherAssociatedDataFailsIntegrity(string $associatedData): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $encryptor = new SecretStreamEncryptor(key: $key, associatedData: 'bucket/ref');
        $ciphertext = $encryptor->encryptChunk(plaintext: 'top secret', final: true);

        $decryptor = new SecretStreamDecryptor(
            key: $key,
            header: $encryptor->header(),
            associatedData: $associatedData
        );

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('wrong associated data');

        $decryptor->decryptChunk(ciphertext: $ciphertext, final: true);
    }

    #[Test]
    #[TestDox('Restarted, it decrypts the stream from its first chunk again, with the same associated data')]
    public function testRestartedDecryptsFromTheFirstChunk(): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $encryptor = new SecretStreamEncryptor(key: $key, associatedData: 'bucket/a');
        $first = $encryptor->encryptChunk(plaintext: 'first', final: false);
        $second = $encryptor->encryptChunk(plaintext: 'second', final: true);
        $decryptor = new SecretStreamDecryptor(key: $key, header: $encryptor->header(), associatedData: 'bucket/a');
        $decryptor->decryptChunk(ciphertext: $first, final: false);
        $decryptor->decryptChunk(ciphertext: $second, final: true);

        $restarted = $decryptor->restarted();

        $this->assertNotSame($decryptor, $restarted);
        $this->assertSame('first', $restarted->decryptChunk(ciphertext: $first, final: false));
        $this->assertSame('second', $restarted->decryptChunk(ciphertext: $second, final: true));
    }
}
