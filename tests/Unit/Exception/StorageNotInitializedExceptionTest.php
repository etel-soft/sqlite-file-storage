<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Exception\StorageNotInitializedException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(StorageNotInitializedException::class)]
final class StorageNotInitializedExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method atPath() includes the database path')]
    public function testAtPath(): void
    {
        $exception = StorageNotInitializedException::atPath(path: '/var/data/bucket.db');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(StorageNotInitializedException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('/var/data/bucket.db', $exception->getMessage());
    }
}
