<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(ContentIntegrityException::class)]
final class ContentIntegrityExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method detected() includes the reason')]
    public function testDetected(): void
    {
        $exception = ContentIntegrityException::detected(reason: 'compressed data is corrupted');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(ContentIntegrityException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('compressed data is corrupted', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    #[Test]
    #[TestDox('Method detected() wraps the previous throwable when supplied')]
    public function testDetectedWithPrevious(): void
    {
        $previous = new RuntimeException(message: 'boom');
        $exception = ContentIntegrityException::detected(reason: 'invalid encryption header', previous: $previous);

        $this->assertStringContainsString('invalid encryption header', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
