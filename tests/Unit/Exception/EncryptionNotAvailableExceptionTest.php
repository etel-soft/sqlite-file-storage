<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\EncryptionNotAvailableException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(EncryptionNotAvailableException::class)]
final class EncryptionNotAvailableExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method forAlgorithm() names the unavailable encryption algorithm')]
    public function testForAlgorithm(): void
    {
        $exception = EncryptionNotAvailableException::forAlgorithm(encryption: Encryption::XChaCha20Poly1305);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(EncryptionNotAvailableException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('xchacha20poly1305', $exception->getMessage());
    }
}
