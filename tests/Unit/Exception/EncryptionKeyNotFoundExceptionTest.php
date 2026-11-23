<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(EncryptionKeyNotFoundException::class)]
final class EncryptionKeyNotFoundExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method byKeyId() includes the key id')]
    public function testByKeyId(): void
    {
        $exception = EncryptionKeyNotFoundException::byKeyId(keyId: 'key-2026');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(EncryptionKeyNotFoundException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('key-2026', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method noKeyProvider() reports the missing key provider')]
    public function testNoKeyProvider(): void
    {
        $exception = EncryptionKeyNotFoundException::noKeyProvider();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('KeyProvider', $exception->getMessage());
    }

    #[Test]
    #[TestDox('Method noKeyProviderForHashKey() reports the key provider missing for a hash key')]
    public function testNoKeyProviderForHashKey(): void
    {
        $exception = EncryptionKeyNotFoundException::noKeyProviderForHashKey();

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('hash key', $exception->getMessage());
    }
}
