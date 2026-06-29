<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(InvalidEncryptionKeyException::class)]
final class InvalidEncryptionKeyExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method byKeyId() includes the key id and the expected byte length')]
    public function testByKeyId(): void
    {
        $exception = InvalidEncryptionKeyException::byKeyId(keyId: 'main', expectedBytes: 32);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(InvalidEncryptionKeyException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('main', $exception->getMessage());
        $this->assertStringContainsString('32', $exception->getMessage());
    }
}
