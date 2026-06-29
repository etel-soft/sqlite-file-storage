<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Exception\CompressionNotAvailableException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(CompressionNotAvailableException::class)]
final class CompressionNotAvailableExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method forAlgorithm() names the unavailable compression algorithm')]
    public function testForAlgorithm(): void
    {
        $exception = CompressionNotAvailableException::forAlgorithm(compression: Compression::Brotli);

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(CompressionNotAvailableException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('brotli', $exception->getMessage());
    }
}
